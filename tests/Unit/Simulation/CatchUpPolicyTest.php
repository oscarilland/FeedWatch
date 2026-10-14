<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Simulation;

use FeedWatch\Simulation\CatchUpPolicy;
use PHPUnit\Framework\TestCase;

final class CatchUpPolicyTest extends TestCase
{
    private const int NOW = 1_791_115_200_000_000;
    private const int SECOND = 1_000_000;

    public function test_first_run_starts_now_without_inventing_history(): void
    {
        $plan = CatchUpPolicy::plan(0, self::NOW);

        self::assertNull($plan->pauseFromUs);
        self::assertFalse($plan->hasWindow());
        self::assertSame(self::NOW, $plan->generateToUs);
    }

    public function test_nothing_to_do_when_already_up_to_date(): void
    {
        $plan = CatchUpPolicy::plan(self::NOW, self::NOW);

        self::assertFalse($plan->hasWindow());
        self::assertNull($plan->pauseFromUs);
    }

    public function test_clock_going_backwards_does_nothing(): void
    {
        $plan = CatchUpPolicy::plan(self::NOW, self::NOW - 5 * self::SECOND);

        self::assertFalse($plan->hasWindow());
        self::assertSame(self::NOW, $plan->generateToUs, 'processed time never moves backwards');
    }

    public function test_a_normal_window_covers_everything_since_last_time(): void
    {
        $plan = CatchUpPolicy::plan(self::NOW - 2 * self::SECOND, self::NOW);

        self::assertNull($plan->pauseFromUs);
        self::assertSame(self::NOW - 2 * self::SECOND, $plan->generateFromUs);
        self::assertSame(self::NOW, $plan->generateToUs);
    }

    public function test_exactly_sixty_seconds_is_generated_without_a_pause(): void
    {
        $plan = CatchUpPolicy::plan(self::NOW - 60 * self::SECOND, self::NOW);

        self::assertNull($plan->pauseFromUs);
        self::assertSame(60 * self::SECOND, $plan->generateToUs - $plan->generateFromUs);
    }

    public function test_just_over_sixty_seconds_pauses_the_excess_and_generates_the_last_minute(): void
    {
        $plan = CatchUpPolicy::plan(self::NOW - 61 * self::SECOND, self::NOW);

        self::assertSame(self::NOW - 61 * self::SECOND, $plan->pauseFromUs);
        self::assertSame(self::NOW - 60 * self::SECOND, $plan->generateFromUs);
        self::assertSame(self::NOW, $plan->generateToUs);
    }

    public function test_an_hour_away_is_mostly_a_pause(): void
    {
        $plan = CatchUpPolicy::plan(self::NOW - 3600 * self::SECOND, self::NOW);

        self::assertSame(self::NOW - 3600 * self::SECOND, $plan->pauseFromUs);
        self::assertSame(CatchUpPolicy::MAX_WINDOW_US, $plan->generateToUs - $plan->generateFromUs);
    }
}
