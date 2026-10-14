<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use FeedWatch\Infrastructure\LatencyHistory;

/**
 * Canned latency for THX, recording which seconds were asked for.
 */
final class RecordingLatencyHistory implements LatencyHistory
{
    /** @var list<array{int, int, int}> exchange id, from second, to second */
    public array $secondsCalls = [];

    public function exchangeId(string $code): ?int
    {
        return $code === 'THX' ? 1 : null;
    }

    public function seconds(int $exchangeId, int $fromSecond, int $toSecond): array
    {
        $this->secondsCalls[] = [$exchangeId, $fromSecond, $toSecond];

        return [
            ['second' => $toSecond - 1, 'a_count' => 5, 'a_sum_us' => 10_250, 'a_max_us' => 2_300, 'b_count' => 0, 'b_sum_us' => 0, 'b_max_us' => 0],
        ];
    }

    public function minutes(int $exchangeId, int $fromMinute, int $toMinute): array
    {
        return [
            ['minute' => $toMinute - 1, 'feed' => 'A', 'mean_us' => 2_050, 'p50_us' => 2_000, 'p95_us' => 2_250, 'p99_us' => 2_290, 'max_us' => 9_100],
        ];
    }
}
