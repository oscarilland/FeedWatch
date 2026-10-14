<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Detection;

use FeedWatch\Detection\SecondCounts;
use FeedWatch\Detection\WinShare;
use FeedWatch\Domain\Message;
use FeedWatch\Domain\MessageType;
use PHPUnit\Framework\TestCase;

final class WinShareTest extends TestCase
{
    /** A whole second, so buckets are easy to read. */
    private const int T = 1_791_115_200_000_000;
    private const int SECOND = 1_791_115_200;

    private static function message(int $sequence, ?int $aAfterUs, ?int $bAfterUs, int $sentUs = self::T): Message
    {
        return new Message(1, $sequence, MessageType::Order, $sentUs, $aAfterUs === null ? null : $sentUs + $aAfterUs, $bAfterUs === null ? null : $sentUs + $bAfterUs);
    }

    public function test_FR03_AC1_first_copy_is_accepted_and_the_second_is_a_duplicate(): void
    {
        $seconds = WinShare::count([
            self::message(1, 2_000, 3_500),
            self::message(2, 5_000, 3_500),
            self::message(3, 2_000, null),
            self::message(4, null, null),
        ], self::T - 1, self::T + 900_000);

        self::assertEquals([self::SECOND => new SecondCounts(accepted: 3, aWins: 2, bWins: 1, duplicates: 2)], $seconds);
    }

    public function test_a_tie_goes_to_A(): void
    {
        $seconds = WinShare::count([self::message(1, 2_000, 2_000)], self::T - 1, self::T + 900_000);

        self::assertSame(1, $seconds[self::SECOND]->aWins);
        self::assertSame(1, $seconds[self::SECOND]->duplicates);
    }

    public function test_split_windows_count_each_arrival_exactly_once(): void
    {
        $messages = [];
        for ($i = 0; $i < 20; $i++) {
            $messages[] = self::message($i + 1, 2_000, 3_500, self::T + $i * 200_000);
        }
        $end = self::T + 4_000_000;

        $whole = WinShare::count($messages, self::T - 1, $end);
        $split = [];
        foreach ([[self::T - 1, self::T + 1_202_500], [self::T + 1_202_500, $end]] as [$from, $to]) {
            foreach (WinShare::count($messages, $from, $to) as $second => $counts) {
                $sum = $split[$second] ??= new SecondCounts();
                $sum->accepted += $counts->accepted;
                $sum->aWins += $counts->aWins;
                $sum->bWins += $counts->bWins;
                $sum->duplicates += $counts->duplicates;
            }
        }
        ksort($split);

        self::assertEquals($whole, $split);
    }
}
