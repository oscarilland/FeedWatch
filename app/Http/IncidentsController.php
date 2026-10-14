<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use Closure;
use FeedWatch\Detection\Duration;
use FeedWatch\Detection\IncidentType;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\IncidentLog;
use Throwable;

/**
 * GET /api/incidents and GET /api/incident?id=N (FR-06). Read-only; they
 * never trigger a catch-up, so they add little load.
 *
 * @phpstan-import-type IncidentRow from IncidentLog
 */
final class IncidentsController
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    /**
     * @param Closure(): IncidentLog $log
     */
    public function __construct(
        private readonly Closure $log,
        private readonly Clock $clock,
    ) {
    }

    /**
     * ?exchange=THX&type=latency&from=<unix s>&to=<unix s>&limit=50, all optional.
     */
    public function list(Request $request): Response
    {
        $query = $request->query;
        $exchange = $query['exchange'] ?? null;
        if ($exchange !== null && preg_match('/^[A-Z]{3}$/', $exchange) !== 1) {
            return self::badRequest('exchange must be a three-letter exchange code');
        }
        $type = $query['type'] ?? null;
        if ($type !== null && IncidentType::tryFrom($type) === null) {
            return self::badRequest('type must be one of: ' . implode(', ', array_map(static fn (IncidentType $t): string => $t->value, IncidentType::cases())));
        }
        $times = [];
        foreach (['from', 'to'] as $name) {
            $times[$name] = null;
            if (isset($query[$name])) {
                if (preg_match('/^\d{1,12}$/', $query[$name]) !== 1) {
                    return self::badRequest("{$name} must be a Unix time in seconds");
                }
                $times[$name] = (int) $query[$name] * 1_000_000;
            }
        }
        $limit = self::DEFAULT_LIMIT;
        if (isset($query['limit'])) {
            if (preg_match('/^\d{1,3}$/', $query['limit']) !== 1 || (int) $query['limit'] < 1 || (int) $query['limit'] > self::MAX_LIMIT) {
                return self::badRequest('limit must be from 1 to ' . self::MAX_LIMIT);
            }
            $limit = (int) $query['limit'];
        }

        try {
            $incidents = ($this->log)()->search($exchange, $type, $times['from'], $times['to'], $limit);
        } catch (Throwable $e) {
            return self::unavailable($e);
        }

        $nowUs = (int) $this->clock->now()->format('Uu');

        return Response::json(
            ['incidents' => array_map(fn (array $i): array => self::incident($i, $nowUs), $incidents)],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * ?id=N: one incident with its timeline (AC-4).
     */
    public function detail(Request $request): Response
    {
        $query = $request->query;
        $id = $query['id'] ?? '';
        if (preg_match('/^\d{1,10}$/', $id) !== 1) {
            return self::badRequest('id must be an incident number');
        }

        try {
            $found = ($this->log)()->find((int) $id);
        } catch (Throwable $e) {
            return self::unavailable($e);
        }
        if ($found === null) {
            return Response::json(['error' => 'Incident not found'], 404, ['Cache-Control' => 'no-store']);
        }

        $nowUs = (int) $this->clock->now()->format('Uu');

        return Response::json(
            self::incident($found['incident'], $nowUs) + [
                'timeline' => array_map(static fn (array $e): array => [
                    'at' => self::isoTime($e['at_us']),
                    'kind' => $e['kind'],
                    'text' => $e['text'],
                ], $found['events']),
                'notes' => array_map(static fn (array $n): array => [
                    'at' => self::isoTime($n['at_us']),
                    'user' => $n['user'],
                    'text' => $n['text'],
                ], $found['notes']),
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * @param IncidentRow $incident
     * @return array<string, mixed>
     */
    private static function incident(array $incident, int $nowUs): array
    {
        $ongoing = $incident['closed_us'] === null;
        $durationUs = ($incident['closed_us'] ?? $nowUs) - $incident['opened_us'];
        $details = $incident['details'];
        if (isset($details['peak_latency_us'])) {
            $details['peak_latency_ms'] = round($details['peak_latency_us'] / 1000, 2);
            unset($details['peak_latency_us']);
        }

        return [
            'id' => $incident['id'],
            'exchange' => $incident['exchange'],
            'type' => $incident['type'],
            'feed' => $incident['feed'],
            'severity' => $incident['severity'],
            'opened_at' => self::isoTime($incident['opened_us']),
            'closed_at' => $ongoing ? null : self::isoTime((int) $incident['closed_us']),
            'ongoing' => $ongoing,
            'clearing' => $incident['clearing_since_us'] !== null,
            'duration_s' => intdiv(max(0, $durationUs), 1_000_000),
            'duration' => Duration::format($durationUs),
            'details' => (object) $details,
        ];
    }

    private static function isoTime(int $microseconds): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($microseconds, 1_000_000)) . sprintf('.%03dZ', intdiv($microseconds % 1_000_000, 1_000));
    }

    private static function badRequest(string $message): Response
    {
        return Response::json(['error' => $message], 400, ['Cache-Control' => 'no-store']);
    }

    private static function unavailable(Throwable $e): Response
    {
        error_log('FeedWatch: reading incidents failed: ' . $e::class . ': ' . $e->getMessage());

        return Response::json(['error' => 'Temporarily unavailable'], 503, ['Cache-Control' => 'no-store']);
    }
}
