<?php

declare(strict_types=1);

namespace FeedWatch\Simulation;

/**
 * Decides how much simulated time a catch-up covers (ADR-004).
 */
final class CatchUpPolicy
{
    /** At most 60 seconds of simulated time per catch-up. */
    public const int MAX_WINDOW_US = 60_000_000;

    public static function plan(int $processedUntilUs, int $nowUs): CatchUpPlan
    {
        // First run: start the clock now rather than inventing history.
        if ($processedUntilUs === 0) {
            return new CatchUpPlan(null, $nowUs, $nowUs);
        }

        // Nothing new, or the clock went backwards: do nothing.
        if ($nowUs <= $processedUntilUs) {
            return new CatchUpPlan(null, $processedUntilUs, $processedUntilUs);
        }

        if ($nowUs - $processedUntilUs <= self::MAX_WINDOW_US) {
            return new CatchUpPlan(null, $processedUntilUs, $nowUs);
        }

        // Too long since the last catch-up: record the older part as a pause
        // and generate only the most recent 60 seconds. Cron runs about every
        // 60 seconds, so a slightly late run still keeps history going.
        return new CatchUpPlan($processedUntilUs, $nowUs - self::MAX_WINDOW_US, $nowUs);
    }
}
