<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Detection;

use FeedWatch\Detection\Delivery;
use FeedWatch\Detection\FeedDetector;
use FeedWatch\Detection\FeedState;
use FeedWatch\Detection\LatencyLevel;
use FeedWatch\Detection\LatencyRules;
use FeedWatch\Detection\Thresholds;
use FeedWatch\Domain\Feed;
use PHPUnit\Framework\TestCase;

final class LatencyRulesTest extends TestCase
{
    private const int BASELINE = 2_000;

    /**
     * @param list<int> $p50s
     * @return list<array{p50Us: int, breached: bool}>
     */
    private static function minutes(array $p50s, bool $breached = false): array
    {
        return array_map(static fn (int $p50): array => ['p50Us' => $p50, 'breached' => $breached], $p50s);
    }

    public function test_baseline_is_the_median_of_recent_p50s(): void
    {
        self::assertSame(2_100, LatencyRules::baselineUs(self::minutes([2_000, 2_100, 2_200, 1_900, 2_150]), 9_999));
    }

    public function test_baseline_ignores_breached_minutes(): void
    {
        $minutes = [...self::minutes([2_000, 2_000, 2_000, 2_000, 2_000]), ...self::minutes([40_000, 40_000, 40_000, 40_000, 40_000, 40_000], true)];

        self::assertSame(2_000, LatencyRules::baselineUs($minutes, 9_999));
    }

    public function test_baseline_uses_the_expected_value_until_there_are_five_minutes(): void
    {
        self::assertSame(1_850, LatencyRules::baselineUs(self::minutes([2_500, 2_500, 2_500, 2_500]), 1_850));
    }

    public function test_FR04_AC3_five_seconds_over_three_times_is_high(): void
    {
        $level = LatencyLevel::High;

        self::assertSame($level, LatencyRules::level([6_100.0, 6_200.0, 7_000.0, 6_500.0, 6_050.0], self::BASELINE, Thresholds::defaults()));
    }

    public function test_four_seconds_is_not_enough(): void
    {
        self::assertSame(LatencyLevel::Normal, LatencyRules::level([2_000.0, 6_200.0, 7_000.0, 6_500.0, 6_050.0], self::BASELINE, Thresholds::defaults()));
    }

    public function test_one_spike_does_not_change_the_level(): void
    {
        // One 20 ms spike among five 2 ms messages lifts that second's mean to about 6 ms.
        self::assertSame(LatencyLevel::Normal, LatencyRules::level([2_000.0, 2_000.0, 6_000.0, 2_000.0, 2_000.0], self::BASELINE, Thresholds::defaults()));
    }

    public function test_over_ten_times_for_five_seconds_is_very_high(): void
    {
        self::assertSame(LatencyLevel::VeryHigh, LatencyRules::level([42_000.0, 41_000.0, 45_000.0, 43_000.0, 42_500.0], self::BASELINE, Thresholds::defaults()));
    }

    public function test_a_second_with_no_arrivals_breaks_the_run(): void
    {
        self::assertSame(LatencyLevel::Normal, LatencyRules::level([42_000.0, 41_000.0, null, 43_000.0, 42_500.0], self::BASELINE, Thresholds::defaults()));
    }

    public function test_the_sustain_time_comes_from_settings(): void
    {
        [$thresholds] = Thresholds::fromSettings(['latency_sustain_s' => '3']);

        self::assertSame(LatencyLevel::High, LatencyRules::level([2_000.0, 2_000.0, 6_500.0, 6_500.0, 6_500.0], self::BASELINE, $thresholds));
    }

    public function test_a_minute_is_breached_when_its_p50_is_over_amber(): void
    {
        self::assertTrue(LatencyRules::minuteBreached(6_100, self::BASELINE, Thresholds::defaults()));
        self::assertFalse(LatencyRules::minuteBreached(5_900, self::BASELINE, Thresholds::defaults()));
    }

    public function test_the_detector_counts_latency_per_second_of_arrival(): void
    {
        $t = 1_791_115_200_000_000;
        $scan = (new FeedDetector())->scan(Feed::A, new FeedState(1, $t), [
            new Delivery(1, $t + 200_000, 2_000),
            new Delivery(2, $t + 400_000, 2_400),
            new Delivery(3, $t + 1_100_000, 2_100),
            new Delivery(4, $t + 1_300_000),
        ], $t + 1_500_000);

        $second = intdiv($t, 1_000_000);
        self::assertSame(2, $scan->latency[$second]->count);
        self::assertSame(4_400, $scan->latency[$second]->sumUs);
        self::assertSame(2_400, $scan->latency[$second]->maxUs);
        self::assertSame(1, $scan->latency[$second + 1]->count, 'a delivery with no known latency is not counted');
        self::assertSame(2_200.0, $scan->latency[$second]->meanUs());
    }
}
