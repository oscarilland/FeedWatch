<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * Durations for people: "under 1 s", "45 s", "2 min 14 s", "1 h 3 min".
 */
final class Duration
{
    public static function format(int $microseconds): string
    {
        $seconds = intdiv(max(0, $microseconds), 1_000_000);
        if ($seconds < 1) {
            return 'under 1 s';
        }
        if ($seconds < 60) {
            return "{$seconds} s";
        }
        if ($seconds < 3600) {
            return sprintf('%d min %d s', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%d h %d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
