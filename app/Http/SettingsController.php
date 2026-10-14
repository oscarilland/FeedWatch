<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use Closure;
use FeedWatch\Detection\Thresholds;
use FeedWatch\Infrastructure\ChaosRepository;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\SettingsRepository;

/**
 * GET and POST /api/settings: the thresholds screen (plan 004's settings,
 * edited here). Admin only. A value is checked with the same rules
 * Thresholds applies when reading it, and rejected if it would be ignored.
 */
final class SettingsController
{
    /**
     * @param Closure(Request): AdminAuth $auth
     * @param Closure(): SettingsRepository $settings
     * @param Closure(): ChaosRepository $log
     */
    public function __construct(
        private readonly Closure $auth,
        private readonly Closure $settings,
        private readonly Closure $log,
        private readonly Clock $clock,
    ) {
    }

    public function show(Request $request): Response
    {
        [, $refusal] = AdminGuard::check(($this->auth)($request), $request, false);
        if ($refusal !== null) {
            return $refusal;
        }

        return Response::json(['settings' => self::describe(($this->settings)()->values())], 200, ['Cache-Control' => 'no-store']);
    }

    public function change(Request $request): Response
    {
        [$user, $refusal] = AdminGuard::check(($this->auth)($request), $request, true);
        if ($refusal !== null || $user === null) {
            return $refusal ?? Response::json(['error' => 'Admin login required'], 403);
        }

        $name = $request->form['name'] ?? '';
        $value = trim($request->form['value'] ?? '');
        if (!isset(Thresholds::SETTINGS[$name])) {
            return Response::json(['error' => 'Unknown setting'], 400, ['Cache-Control' => 'no-store']);
        }
        $repository = ($this->settings)();
        $current = $repository->values();
        // Check the value in context: red must stay above amber, for example.
        [, $warnings] = Thresholds::fromSettings([$name => $value] + $current);
        if (preg_match('/^\d{1,5}(\.\d{1,2})?$/', $value) !== 1 || $warnings !== []) {
            [, $min, $max] = Thresholds::SETTINGS[$name];
            $reason = $warnings[0] ?? "Setting {$name} must be a number from {$min} to {$max}";

            return Response::json(['error' => $reason], 400, ['Cache-Control' => 'no-store']);
        }

        $repository->update($name, $value);
        ($this->log)()->log($user, 'setting', $name, ($current[$name] ?? 'default') . " -> {$value}", (int) $this->clock->now()->format('Uu'));

        return Response::json(['settings' => self::describe($repository->values())], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * @param array<string, string> $values
     * @return list<array{name: string, value: string, default: float, min: float, max: float}>
     */
    private static function describe(array $values): array
    {
        $described = [];
        foreach (Thresholds::SETTINGS as $name => [$default, $min, $max]) {
            $described[] = ['name' => $name, 'value' => $values[$name] ?? (string) $default, 'default' => $default, 'min' => $min, 'max' => $max];
        }

        return $described;
    }
}
