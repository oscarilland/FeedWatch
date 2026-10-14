<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Detection;

use FeedWatch\Detection\Delivery;
use FeedWatch\Detection\FeedDetector;
use FeedWatch\Detection\FeedState;
use FeedWatch\Domain\Feed;
use PHPUnit\Framework\TestCase;

final class FeedDetectorTest extends TestCase
{
    private const int T = 1_791_115_200_000_000;

    private static function ms(int $milliseconds): int
    {
        return self::T + $milliseconds * 1000;
    }

    private static function at(int $sequence, int $milliseconds): Delivery
    {
        return new Delivery($sequence, self::ms($milliseconds));
    }

    private function detector(): FeedDetector
    {
        return new FeedDetector();
    }

    public function test_in_order_deliveries_advance_the_expected_number_and_find_nothing(): void
    {
        $scan = $this->detector()->scan(Feed::A, new FeedState(1039, self::T), [self::at(1039, 200), self::at(1040, 400), self::at(1041, 600)], self::ms(1000));

        self::assertSame(1042, $scan->state->nextExpected);
        self::assertSame([], $scan->gaps);
        self::assertSame([], $scan->silences);
        self::assertSame(self::ms(600), $scan->state->lastReceivedUs);
    }

    public function test_AC1_a_skipped_number_becomes_a_gap_once_the_tolerance_window_passes(): void
    {
        $deliveries = [self::at(1039, 200), self::at(1041, 600)];

        $early = $this->detector()->scan(Feed::A, new FeedState(1039, self::T), $deliveries, self::ms(2500));
        self::assertSame([], $early->gaps, 'still inside the 2-second window');
        self::assertCount(1, $early->state->pending);

        $late = $this->detector()->scan(Feed::A, new FeedState(1039, self::T), $deliveries, self::ms(2700));
        self::assertCount(1, $late->gaps);
        self::assertSame(Feed::A, $late->gaps[0]->feed);
        self::assertSame(1040, $late->gaps[0]->first);
        self::assertSame(1040, $late->gaps[0]->last);
        self::assertSame(self::ms(2600), $late->gaps[0]->detectedUs);
        self::assertSame([], $late->state->pending);
    }

    public function test_AC4_a_late_arrival_inside_the_window_is_out_of_order_not_a_gap(): void
    {
        $scan = $this->detector()->scan(Feed::A, new FeedState(1039, self::T), [self::at(1039, 200), self::at(1041, 600), self::at(1040, 2500)], self::ms(5000));

        self::assertSame([], $scan->gaps);
        self::assertSame(1, $scan->outOfOrder);
        self::assertSame(1042, $scan->state->nextExpected);
    }

    public function test_AC4_an_arrival_after_the_window_does_not_undo_the_gap(): void
    {
        $first = $this->detector()->scan(Feed::A, new FeedState(1039, self::T), [self::at(1039, 200), self::at(1041, 600)], self::ms(2650));
        self::assertCount(1, $first->gaps);

        $second = $this->detector()->scan(Feed::A, $first->state, [self::at(1040, 2700)], self::ms(3000));
        self::assertSame([], $second->gaps);
        self::assertSame(1, $second->duplicates, 'too late to fill the gap');
    }

    public function test_AC5_a_missing_run_is_one_gap(): void
    {
        $scan = $this->detector()->scan(Feed::A, new FeedState(1039, self::T), [self::at(1039, 200), self::at(1046, 400)], self::ms(5000));

        self::assertCount(1, $scan->gaps);
        self::assertSame(1040, $scan->gaps[0]->first);
        self::assertSame(1045, $scan->gaps[0]->last);
    }

    public function test_AC6_a_duplicate_on_the_same_feed_creates_no_gap_and_is_counted_once(): void
    {
        $scan = $this->detector()->scan(Feed::A, new FeedState(1039, self::T), [self::at(1039, 200), self::at(1039, 201), self::at(1040, 400)], self::ms(5000));

        self::assertSame([], $scan->gaps);
        self::assertSame(1, $scan->duplicates);
        self::assertSame(1041, $scan->state->nextExpected);
    }

    public function test_a_late_arrival_in_the_middle_splits_a_pending_gap(): void
    {
        $scan = $this->detector()->scan(Feed::A, new FeedState(1039, self::T), [self::at(1039, 200), self::at(1046, 400), self::at(1042, 900)], self::ms(5000));

        self::assertCount(2, $scan->gaps);
        self::assertSame([1040, 1041], [$scan->gaps[0]->first, $scan->gaps[0]->last]);
        self::assertSame([1043, 1045], [$scan->gaps[1]->first, $scan->gaps[1]->last]);
    }

    public function test_a_pending_gap_carries_across_scans_and_a_late_arrival_closes_it(): void
    {
        $first = $this->detector()->scan(Feed::A, new FeedState(1039, self::T), [self::at(1039, 200), self::at(1041, 600)], self::ms(1000));
        self::assertCount(1, $first->state->pending);

        $second = $this->detector()->scan(Feed::A, $first->state, [self::at(1040, 1900), self::at(1042, 2000)], self::ms(4000));

        self::assertSame([], $second->gaps);
        self::assertSame([], $second->state->pending);
        self::assertSame(1, $second->outOfOrder);
    }

    public function test_deliveries_are_processed_in_arrival_order_whatever_order_they_come_in(): void
    {
        $scan = $this->detector()->scan(Feed::A, new FeedState(1, self::T), [self::at(3, 600), self::at(1, 200), self::at(2, 400)], self::ms(1000));

        self::assertSame([], $scan->gaps);
        self::assertSame(4, $scan->state->nextExpected);
    }

    public function test_quiet_at_the_horizon_opens_a_silence_two_seconds_after_the_last_arrival(): void
    {
        $scan = $this->detector()->scan(Feed::A, new FeedState(1, self::T), [self::at(1, 200)], self::ms(3000));

        self::assertTrue($scan->state->isSilent());
        self::assertSame(self::ms(2200), $scan->state->silentSinceUs);
        self::assertCount(1, $scan->silences);
        self::assertNull($scan->silences[0]->endedUs);
    }

    public function test_quiet_for_under_two_seconds_is_not_a_silence(): void
    {
        $scan = $this->detector()->scan(Feed::A, new FeedState(1, self::T), [self::at(1, 200)], self::ms(2100));

        self::assertFalse($scan->state->isSilent());
        self::assertSame([], $scan->silences);
    }

    public function test_an_open_silence_closes_when_the_feed_delivers_again(): void
    {
        $first = $this->detector()->scan(Feed::A, new FeedState(1, self::T), [self::at(1, 200)], self::ms(3000));
        $second = $this->detector()->scan(Feed::A, $first->state, [self::at(2, 5000)], self::ms(5500));

        self::assertFalse($second->state->isSilent());
        self::assertCount(1, $second->silences);
        self::assertSame(self::ms(2200), $second->silences[0]->startedUs);
        self::assertSame(self::ms(5000), $second->silences[0]->endedUs);
    }

    public function test_a_silence_entirely_inside_one_batch_has_a_start_and_an_end(): void
    {
        $scan = $this->detector()->scan(Feed::A, new FeedState(1, self::T), [self::at(1, 200), self::at(2, 3500)], self::ms(3600));

        self::assertCount(1, $scan->silences);
        self::assertSame(self::ms(2200), $scan->silences[0]->startedUs);
        self::assertSame(self::ms(3500), $scan->silences[0]->endedUs);
        self::assertFalse($scan->state->isSilent());
    }
}
