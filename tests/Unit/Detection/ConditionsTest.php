<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Detection;

use FeedWatch\Detection\Conditions;
use FeedWatch\Detection\Duration;
use FeedWatch\Detection\FeedLatency;
use FeedWatch\Detection\HealthData;
use FeedWatch\Detection\HealthInputs;
use FeedWatch\Detection\LatencyLevel;
use FeedWatch\Detection\Severity;
use FeedWatch\Detection\Thresholds;
use PHPUnit\Framework\TestCase;

final class ConditionsTest extends TestCase
{
    private const int T = 1_791_115_200_000_000;

    /**
     * @return array<string, array{string, string}> key => [severity, description]
     */
    private static function summary(HealthData $health): array
    {
        $summary = [];
        foreach (Conditions::from($health, self::T, Thresholds::defaults()) as $key => $condition) {
            $summary[$key] = [$condition->severity->value, $condition->description];
        }

        return $summary;
    }

    public function test_healthy_has_no_conditions(): void
    {
        self::assertSame([], self::summary(new HealthData(new HealthInputs())));
    }

    public function test_one_silent_feed_is_a_major_failover_from_when_it_went_silent(): void
    {
        $health = new HealthData(new HealthInputs(aSilent: true), [], ['A' => self::T - 5_000_000]);
        $conditions = Conditions::from($health, self::T, Thresholds::defaults());

        self::assertSame(['failover:A' => ['major', 'Feed A silent: running on B only']], self::summary($health));
        self::assertSame(self::T - 5_000_000, $conditions['failover:A']->startedUs);
    }

    public function test_both_silent_is_one_feed_down_not_two_failovers(): void
    {
        $health = new HealthData(new HealthInputs(aSilent: true, bSilent: true), [], ['A' => self::T - 9_000_000, 'B' => self::T - 4_000_000]);

        self::assertSame(['feed_down:AB' => ['critical', 'Both feeds silent: feed lost']], self::summary($health));
        self::assertSame(self::T - 4_000_000, Conditions::from($health, self::T, Thresholds::defaults())['feed_down:AB']->startedUs, 'from when the second feed went');
    }

    public function test_data_loss_is_critical_from_the_first_loss_with_the_last_as_its_end(): void
    {
        $health = new HealthData(new HealthInputs(messagesLostRecently: 4), [], [], self::T - 20_000_000, self::T - 8_000_000);
        $condition = Conditions::from($health, self::T, Thresholds::defaults())['data_loss:AB'];

        self::assertSame(Severity::Critical, $condition->severity);
        self::assertSame(self::T - 20_000_000, $condition->startedUs);
        self::assertSame(self::T - 8_000_000, $condition->endHintUs);
    }

    public function test_latency_is_a_warning_when_high_and_critical_when_very_high_with_its_peak(): void
    {
        $health = new HealthData(
            new HealthInputs(latencyA: LatencyLevel::High, latencyB: LatencyLevel::VeryHigh),
            ['A' => new FeedLatency(9_900.0, 1_850, LatencyLevel::High), 'B' => new FeedLatency(43_400.4, 3_350, LatencyLevel::VeryHigh)],
        );
        $conditions = Conditions::from($health, self::T, Thresholds::defaults());

        self::assertSame(['latency:A' => ['warning', 'High latency on A'], 'latency:B' => ['critical', 'Very high latency on B']], self::summary($health));
        self::assertSame(['peak_latency_us' => 43_400], $conditions['latency:B']->details);
    }

    public function test_line_gaps_at_the_threshold_are_a_warning(): void
    {
        self::assertSame([], self::summary(new HealthData(new HealthInputs(lineGapsA: 2))));
        self::assertSame(['line_gaps:B' => ['warning', '3 line gaps on B']], self::summary(new HealthData(new HealthInputs(lineGapsB: 3))));
    }

    public function test_durations_read_naturally(): void
    {
        self::assertSame('under 1 s', Duration::format(400_000));
        self::assertSame('45 s', Duration::format(45_000_000));
        self::assertSame('2 min 14 s', Duration::format(134_000_000));
        self::assertSame('1 h 3 min', Duration::format(3_780_000_000));
    }
}
