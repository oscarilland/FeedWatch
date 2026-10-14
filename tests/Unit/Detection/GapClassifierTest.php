<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Detection;

use FeedWatch\Detection\FoundGap;
use FeedWatch\Detection\GapClassification;
use FeedWatch\Detection\GapClassifier;
use FeedWatch\Domain\Feed;
use FeedWatch\Domain\Message;
use FeedWatch\Domain\MessageType;
use PHPUnit\Framework\TestCase;

final class GapClassifierTest extends TestCase
{
    private const int T = 1_791_115_200_000_000;

    /**
     * Messages 1040-1045 with A missing them all, and B missing those in $lostOnB.
     *
     * @param list<int> $lostOnB
     * @return array<int, Message>
     */
    private static function messages(array $lostOnB, int $bReceivedUs = self::T + 3_500): array
    {
        $messages = [];
        for ($sequence = 1040; $sequence <= 1045; $sequence++) {
            $messages[$sequence] = new Message(1, $sequence, MessageType::Order, self::T, null, in_array($sequence, $lostOnB, true) ? null : $bReceivedUs);
        }

        return $messages;
    }

    public function test_AC2_delivered_on_the_other_feed_is_a_line_gap(): void
    {
        $gaps = GapClassifier::classify([new FoundGap(Feed::A, 1040, 1040, self::T + 2_000_000)], self::messages([]), self::T + 3_000_000);

        self::assertCount(1, $gaps);
        self::assertSame('A', $gaps[0]->scope);
        self::assertSame(GapClassification::LineGap, $gaps[0]->classification);
        self::assertSame(1, $gaps[0]->count());
    }

    public function test_AC3_missing_on_both_feeds_is_data_loss(): void
    {
        $gaps = GapClassifier::classify([new FoundGap(Feed::A, 1040, 1045, self::T)], self::messages([1040, 1041, 1042, 1043, 1044, 1045]), self::T + 3_000_000);

        self::assertCount(1, $gaps);
        self::assertSame('AB', $gaps[0]->scope);
        self::assertSame(GapClassification::DataLoss, $gaps[0]->classification);
        self::assertSame(6, $gaps[0]->count());
    }

    public function test_a_mixed_run_splits_into_line_gap_and_data_loss_parts(): void
    {
        $gaps = GapClassifier::classify([new FoundGap(Feed::A, 1040, 1045, self::T)], self::messages([1042, 1043]), self::T + 3_000_000);

        $summary = array_map(static fn ($g): string => "{$g->scope} {$g->classification->value} {$g->first}-{$g->last}", $gaps);
        self::assertSame(['A line_gap 1040-1041', 'AB data_loss 1042-1043', 'A line_gap 1044-1045'], $summary);
    }

    public function test_both_feeds_finding_the_same_loss_produce_the_same_gap(): void
    {
        $messages = [];
        for ($sequence = 1040; $sequence <= 1042; $sequence++) {
            $messages[$sequence] = new Message(1, $sequence, MessageType::Order, self::T, null, null);
        }

        $fromA = GapClassifier::classify([new FoundGap(Feed::A, 1040, 1042, self::T)], $messages, self::T + 3_000_000);
        $fromB = GapClassifier::classify([new FoundGap(Feed::B, 1040, 1042, self::T)], $messages, self::T + 3_000_000);

        self::assertEquals($fromA, $fromB, 'storage relies on matching scope and first sequence to keep only one');
    }

    public function test_a_delivery_after_the_horizon_has_not_been_seen_yet(): void
    {
        $gaps = GapClassifier::classify([new FoundGap(Feed::A, 1040, 1040, self::T)], self::messages([], self::T + 9_000_000), self::T + 3_000_000);

        self::assertSame(GapClassification::DataLoss, $gaps[0]->classification);
    }
}
