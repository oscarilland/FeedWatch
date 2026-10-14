<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Detection;

use FeedWatch\Detection\Thresholds;
use PHPUnit\Framework\TestCase;

final class ThresholdsTest extends TestCase
{
    public function test_defaults_match_the_agreed_values(): void
    {
        $t = Thresholds::defaults();

        self::assertSame(2_000_000, $t->gapToleranceUs);
        self::assertSame(2_000_000, $t->silenceUs);
        self::assertSame(3, $t->lineGapsAmber);
        self::assertSame(300_000_000, $t->lineGapWindowUs);
        self::assertSame(60_000_000, $t->dataLossRedUs);
        self::assertSame(3.0, $t->latencyAmberMultiple);
        self::assertSame(10.0, $t->latencyRedMultiple);
        self::assertSame(5, $t->latencySustainSeconds);
        self::assertSame(30, $t->baselineWindowMinutes);
        self::assertSame(1_800_000_000, $t->idleAfterUs);
    }

    public function test_valid_settings_replace_the_defaults(): void
    {
        [$t, $warnings] = Thresholds::fromSettings(['gap_tolerance_ms' => '1500', 'latency_amber_multiple' => '2.5', 'idle_after_min' => '60']);

        self::assertSame([], $warnings);
        self::assertSame(1_500_000, $t->gapToleranceUs);
        self::assertSame(2.5, $t->latencyAmberMultiple);
        self::assertSame(3_600_000_000, $t->idleAfterUs);
    }

    public function test_a_value_out_of_range_falls_back_to_its_default_with_a_warning(): void
    {
        [$t, $warnings] = Thresholds::fromSettings(['gap_tolerance_ms' => '9000', 'silence_ms' => 'soon']);

        self::assertSame(2_000_000, $t->gapToleranceUs, 'above the 5 s look-back would hide deliveries');
        self::assertSame(2_000_000, $t->silenceUs);
        self::assertCount(2, $warnings);
        self::assertStringContainsString('gap_tolerance_ms', $warnings[0]);
    }

    public function test_red_not_above_amber_resets_both(): void
    {
        [$t, $warnings] = Thresholds::fromSettings(['latency_amber_multiple' => '8', 'latency_red_multiple' => '6']);

        self::assertSame(3.0, $t->latencyAmberMultiple);
        self::assertSame(10.0, $t->latencyRedMultiple);
        self::assertCount(1, $warnings);
    }

    public function test_unknown_settings_are_ignored(): void
    {
        [, $warnings] = Thresholds::fromSettings(['something_else' => '1']);

        self::assertSame([], $warnings);
    }
}
