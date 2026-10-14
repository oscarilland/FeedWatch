<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use Closure;
use FeedWatch\Infrastructure\ChaosRepository;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\SimulationStatus;
use FeedWatch\Simulation\ChaosFaults;
use Throwable;

/**
 * POST /api/chaos (FR-07) and GET /api/admin-log. Admin only; posts need a
 * CSRF token. Every action is logged with who, what and when (AC-2).
 */
final class ChaosController
{
    /**
     * @param Closure(Request): AdminAuth $auth
     * @param Closure(): ChaosRepository $faults
     * @param Closure(): SimulationStatus $status
     * @param Closure(): int $expiryUs how long a fault lasts (the chaos_expiry_min setting)
     */
    public function __construct(
        private readonly Closure $auth,
        private readonly Closure $faults,
        private readonly Closure $status,
        private readonly Clock $clock,
        private readonly Closure $expiryUs,
    ) {
    }

    public function change(Request $request): Response
    {
        [$user, $refusal] = AdminGuard::check(($this->auth)($request), $request, true);
        if ($refusal !== null || $user === null) {
            return $refusal ?? Response::json(['error' => 'Admin login required'], 403);
        }

        $form = $request->form;
        $now = (int) $this->clock->now()->format('Uu');
        try {
            $faults = ($this->faults)();
            $result = match ($form['action'] ?? '') {
                'preset' => $this->preset($faults, $user, $form['name'] ?? '', $now),
                'set' => $this->set($faults, $user, $form, $now),
                'restore' => $this->restore($faults, $user, $form['exchange'] ?? '', $now),
                'restore_all' => $this->restoreAll($faults, $user, $now),
                default => 'action must be one of: preset, set, restore, restore_all',
            };
            if ($result !== null) {
                return self::badRequest($result);
            }
            // A fault needs the simulation running, whatever idle mode thought.
            ($this->status)()->markViewed($now);

            return Response::json(['faults' => self::publicFaults($faults->active($now))], 200, ['Cache-Control' => 'no-store']);
        } catch (Throwable $e) {
            error_log('FeedWatch: chaos action failed: ' . $e::class . ': ' . $e->getMessage());

            return Response::json(['error' => 'Temporarily unavailable'], 503, ['Cache-Control' => 'no-store']);
        }
    }

    public function log(Request $request): Response
    {
        [, $refusal] = AdminGuard::check(($this->auth)($request), $request, false);
        if ($refusal !== null) {
            return $refusal;
        }

        $entries = ($this->faults)()->recentLog(50);

        return Response::json(['log' => array_map(static fn (array $e): array => [
            'at' => self::isoTime($e['at_us']),
            'user' => $e['username'],
            'action' => $e['action'],
            'target' => $e['target'],
            'details' => $e['details'],
        ], $entries)], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Faults as every visitor may see them: what and until when, not who.
     *
     * @param list<array{exchange_id: int, exchange: string, feed: string, kind: string, value: int, burst_from: ?int, set_by: string, set_us: int, expires_us: int}> $faults
     * @return list<array<string, mixed>>
     */
    public static function publicFaults(array $faults): array
    {
        return array_map(static fn (array $f): array => [
            'exchange' => $f['exchange'],
            'feed' => $f['feed'],
            'kind' => $f['kind'],
            'value' => $f['value'],
            'expires_at' => self::isoTime($f['expires_us']),
        ], $faults);
    }

    private function preset(ChaosRepository $faults, string $user, string $name, int $now): ?string
    {
        if (!isset(ChaosFaults::PRESETS[$name])) {
            return 'name must be one of: ' . implode(', ', array_keys(ChaosFaults::PRESETS));
        }
        [$label, $list] = ChaosFaults::PRESETS[$name];
        foreach ($list as [$code, $feed, $kind, $value]) {
            $exchange = $faults->exchange($code);
            if ($exchange !== null) {
                $faults->set($exchange[0], $feed, $kind, $value, $kind === 'burst' ? $exchange[1] : null, $user, $now, $now + ($this->expiryUs)());
            }
        }
        $faults->log($user, 'preset', $name, $label, $now);

        return null;
    }

    /**
     * @param array<string, string> $form
     */
    private function set(ChaosRepository $faults, string $user, array $form, int $now): ?string
    {
        $code = $form['exchange'] ?? '';
        $feed = $form['feed'] ?? '';
        $kind = $form['kind'] ?? '';
        $raw = $form['value'] ?? '0';
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            return 'exchange must be a three-letter exchange code';
        }
        if (preg_match('/^\d{1,4}$/', $raw) !== 1) {
            return 'value must be a whole number';
        }
        $value = (int) $raw;
        $problem = ChaosFaults::problem($feed, $kind, $value);
        if ($problem !== null) {
            return $problem;
        }
        $exchange = $faults->exchange($code);
        if ($exchange === null) {
            return 'Unknown exchange';
        }
        $faults->set($exchange[0], $feed, $kind, $value, $kind === 'burst' ? $exchange[1] : null, $user, $now, $now + ($this->expiryUs)());
        $faults->log($user, 'set', "{$code} {$feed}", $kind . ($kind === 'silence' ? '' : " {$value}"), $now);

        return null;
    }

    private function restore(ChaosRepository $faults, string $user, string $code, int $now): ?string
    {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            return 'exchange must be a three-letter exchange code';
        }
        $exchange = $faults->exchange($code);
        if ($exchange === null) {
            return 'Unknown exchange';
        }
        $count = $faults->restoreExchange($exchange[0]);
        $faults->log($user, 'restore', $code, "{$count} fault(s) cleared", $now);

        return null;
    }

    private function restoreAll(ChaosRepository $faults, string $user, int $now): null
    {
        $count = $faults->restoreAll();
        $faults->log($user, 'restore_all', 'all', "{$count} fault(s) cleared", $now);

        return null;
    }

    private static function isoTime(int $microseconds): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($microseconds, 1_000_000)) . 'Z';
    }

    private static function badRequest(string $message): Response
    {
        return Response::json(['error' => $message], 400, ['Cache-Control' => 'no-store']);
    }
}
