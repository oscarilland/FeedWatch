<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

use FeedWatch\Domain\Message;

/**
 * First copy wins (FR-03 AC-1): for each message, the earlier delivery is
 * accepted and the later one is a duplicate. Counted per second of first
 * arrival, only for arrivals between the two horizons, so no arrival is
 * counted twice across catch-ups.
 */
final class WinShare
{
    /**
     * @param list<Message> $messages
     * @return array<int, SecondCounts> keyed by Unix second of first arrival
     */
    public static function count(array $messages, int $previousHorizonUs, int $horizonUs): array
    {
        $seconds = [];
        foreach ($messages as $message) {
            $a = $message->aReceivedUs;
            $b = $message->bReceivedUs;
            if ($a === null && $b === null) {
                continue;
            }
            // Ties go to A.
            $aFirst = $b === null || ($a !== null && $a <= $b);
            $first = (int) ($aFirst ? $a : $b);
            $second = $aFirst ? $b : $a;
            $bucket = intdiv($first, 1_000_000);

            if ($first > $previousHorizonUs && $first <= $horizonUs) {
                $counts = $seconds[$bucket] ??= new SecondCounts();
                $counts->accepted++;
                $aFirst ? $counts->aWins++ : $counts->bWins++;
            }
            if ($second !== null && $second > $previousHorizonUs && $second <= $horizonUs) {
                $counts = $seconds[$bucket] ??= new SecondCounts();
                $counts->duplicates++;
            }
        }
        ksort($seconds);

        return $seconds;
    }
}
