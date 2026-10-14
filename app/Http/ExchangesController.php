<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use Closure;
use DateTimeInterface;
use FeedWatch\Detection\HealthStatus;
use FeedWatch\Domain\ExchangeSnapshot;
use FeedWatch\Domain\Feed;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\ExchangeSnapshots;
use Throwable;

/**
 * GET /api/exchanges: brings the simulation up to date, then returns each
 * exchange with its latest message. Read-only.
 */
final class ExchangesController
{
    /**
     * @param Closure(): void $catchUp runs a catch-up; returns quietly if another request holds the lock
     * @param Closure(): ExchangeSnapshots $snapshots
     */
    /** @var Closure(): list<array<string, mixed>> */
    private readonly Closure $faults;

    /**
     * @param ?Closure(): list<array<string, mixed>> $faults active chaos faults, for the banner (plan 007)
     */
    public function __construct(
        private readonly Closure $catchUp,
        private readonly Closure $snapshots,
        private readonly Clock $clock,
        ?Closure $faults = null,
    ) {
        $this->faults = $faults ?? static fn (): array => [];
    }

    public function __invoke(): Response
    {
        try {
            ($this->catchUp)();
        } catch (Throwable $e) {
            // Serving slightly stale data beats serving nothing.
            error_log('FeedWatch: catch-up failed: ' . $e::class . ': ' . $e->getMessage());
        }

        $nowUs = (int) $this->clock->now()->format('Uu');
        try {
            $source = ($this->snapshots)();
            $snapshots = $source->latest($nowUs);
            $figures = $source->summary($nowUs);
            $faults = ($this->faults)();
        } catch (Throwable $e) {
            error_log('FeedWatch: reading exchanges failed: ' . $e::class . ': ' . $e->getMessage());

            return Response::json(['error' => 'Temporarily unavailable'], 503, ['Cache-Control' => 'no-store']);
        }

        return Response::json(
            [
                'generated_at' => $this->clock->now()->format(DateTimeInterface::RFC3339_EXTENDED),
                // The dashboard's summary tiles (plan 006).
                'summary' => [
                    'exchanges' => count($snapshots),
                    'healthy' => count(array_filter($snapshots, static fn (ExchangeSnapshot $s): bool => $s->health->status === HealthStatus::Healthy)),
                    'open_incidents' => array_sum(array_map(static fn (ExchangeSnapshot $s): int => $s->openIncidents, $snapshots)),
                    'messages_per_second' => $figures['messages_per_second'],
                    'messages_lost_today' => $figures['messages_lost_today'],
                ],
                // "Simulated fault in progress" for every visitor (ADR-007).
                'faults' => $faults,
                'exchanges' => array_map(self::exchange(...), $snapshots),
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function exchange(ExchangeSnapshot $snapshot): array
    {
        $e = $snapshot->exchange;
        $m = $snapshot->lastMessage;
        $latencyMs = static fn (Feed $feed): ?float => $m?->latencyUs($feed) === null ? null : round($m->latencyUs($feed) / 1000, 3);

        return [
            'code' => $e->code,
            'name' => $e->name,
            'city' => $e->city,
            'latitude' => $e->latitude,
            'longitude' => $e->longitude,
            'time_zone' => $e->timeZone,
            'last_sequence' => $m?->sequence,
            'last_sent_at' => $m === null ? null : self::isoTime($m->sentUs),
            'latency_ms' => ['A' => $latencyMs(Feed::A), 'B' => $latencyMs(Feed::B)],
            'status' => $snapshot->health->status->value,
            'status_reason' => $snapshot->health->reason,
            'line_gaps_5m' => $snapshot->lineGapsLastFiveMinutes,
            'silent_feeds' => array_map(static fn (Feed $feed): string => $feed->value, $snapshot->silentFeeds),
            'open_incidents' => $snapshot->openIncidents,
            // The mean of the last few complete seconds, against each feed's learned normal level.
            'latency_5s_ms' => [
                'A' => self::ms($snapshot->latency['A']->recentMeanUs ?? null),
                'B' => self::ms($snapshot->latency['B']->recentMeanUs ?? null),
            ],
            'baseline_ms' => [
                'A' => self::ms($snapshot->latency['A']->baselineUs ?? null),
                'B' => self::ms($snapshot->latency['B']->baselineUs ?? null),
            ],
        ];
    }

    private static function ms(int|float|null $microseconds): ?float
    {
        return $microseconds === null ? null : round($microseconds / 1000, 2);
    }

    private static function isoTime(int $microseconds): string
    {
        $seconds = intdiv($microseconds, 1_000_000);
        $millis = intdiv($microseconds % 1_000_000, 1_000);

        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', $millis);
    }
}
