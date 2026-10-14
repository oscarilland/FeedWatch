<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

/**
 * Recent latency for one exchange, for the dashboard chart (FR-04 AC-2).
 */
interface LatencyHistory
{
    public function exchangeId(string $code): ?int;

    /**
     * Per-second latency, oldest first.
     *
     * @return list<array{second: int, a_count: int, a_sum_us: int, a_max_us: int, b_count: int, b_sum_us: int, b_max_us: int}>
     */
    public function seconds(int $exchangeId, int $fromSecond, int $toSecond): array;

    /**
     * Per-minute summaries, oldest first.
     *
     * @return list<array{minute: int, feed: string, mean_us: int, p50_us: int, p95_us: int, p99_us: int, max_us: int}>
     */
    public function minutes(int $exchangeId, int $fromMinute, int $toMinute): array;
}
