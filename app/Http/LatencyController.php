<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use Closure;
use DateTimeInterface;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\LatencyHistory;
use Throwable;

/**
 * GET /api/latency?exchange=THX[&since=<unix second>]: the last 15 minutes of
 * per-second latency for both feeds, plus per-minute percentiles, for the
 * dashboard chart (FR-04 AC-2). `since` lets the chart fetch only new
 * seconds on each poll, which keeps polling light on the server.
 */
final class LatencyController
{
    public const int WINDOW_SECONDS = 900;

    /**
     * @param Closure(): LatencyHistory $history
     */
    public function __construct(
        private readonly Closure $history,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $query = $request->query;
        $code = $query['exchange'] ?? '';
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            return self::badRequest('exchange must be a three-letter exchange code');
        }
        $since = null;
        if (isset($query['since'])) {
            if (preg_match('/^\d{1,12}$/', $query['since']) !== 1) {
                return self::badRequest('since must be a Unix time in seconds');
            }
            $since = (int) $query['since'];
        }

        $nowUs = (int) $this->clock->now()->format('Uu');
        $nowSecond = intdiv($nowUs, 1_000_000);
        $nowMinute = intdiv($nowSecond, 60);
        $fromSecond = $nowSecond - self::WINDOW_SECONDS;
        if ($since !== null) {
            $fromSecond = max($fromSecond, $since + 1);
        }

        try {
            $history = ($this->history)();
            $exchangeId = $history->exchangeId($code);
            if ($exchangeId === null) {
                return self::badRequest('Unknown exchange');
            }
            $seconds = $history->seconds($exchangeId, $fromSecond, $nowSecond);
            $minutes = $history->minutes($exchangeId, $nowMinute - 15, $nowMinute);
        } catch (Throwable $e) {
            error_log('FeedWatch: reading latency failed: ' . $e::class . ': ' . $e->getMessage());

            return Response::json(['error' => 'Temporarily unavailable'], 503, ['Cache-Control' => 'no-store']);
        }

        $byMinute = [];
        foreach ($minutes as $m) {
            $byMinute[$m['minute']][$m['feed']] = [
                'mean_ms' => self::ms($m['mean_us']),
                'p50_ms' => self::ms($m['p50_us']),
                'p95_ms' => self::ms($m['p95_us']),
                'p99_ms' => self::ms($m['p99_us']),
                'max_ms' => self::ms($m['max_us']),
            ];
        }

        return Response::json(
            [
                'exchange' => $code,
                'generated_at' => $this->clock->now()->format(DateTimeInterface::RFC3339_EXTENDED),
                'seconds' => array_map(static fn (array $s): array => [
                    't' => $s['second'],
                    'A' => $s['a_count'] === 0 ? null : ['mean_ms' => self::ms($s['a_sum_us'] / $s['a_count']), 'max_ms' => self::ms($s['a_max_us'])],
                    'B' => $s['b_count'] === 0 ? null : ['mean_ms' => self::ms($s['b_sum_us'] / $s['b_count']), 'max_ms' => self::ms($s['b_max_us'])],
                ], $seconds),
                'minutes' => array_map(
                    static fn (int $minute, array $feeds): array => ['t' => $minute * 60, 'A' => $feeds['A'] ?? null, 'B' => $feeds['B'] ?? null],
                    array_keys($byMinute),
                    array_values($byMinute),
                ),
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    private static function ms(int|float $microseconds): float
    {
        return round($microseconds / 1000, 2);
    }

    private static function badRequest(string $message): Response
    {
        return Response::json(['error' => $message], 400, ['Cache-Control' => 'no-store']);
    }
}
