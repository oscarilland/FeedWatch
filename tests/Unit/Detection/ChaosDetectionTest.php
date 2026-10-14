<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Detection;

use FeedWatch\Detection\Delivery;
use FeedWatch\Detection\FeedDetector;
use FeedWatch\Detection\FeedScan;
use FeedWatch\Detection\FeedState;
use FeedWatch\Detection\Gap;
use FeedWatch\Detection\GapClassifier;
use FeedWatch\Domain\Feed;
use FeedWatch\Domain\Message;
use FeedWatch\Simulation\ExchangeConditions;
use FeedWatch\Simulation\FeedConditions;
use FeedWatch\Simulation\MessageGenerator;
use FeedWatch\Simulation\SeededRandom;
use FeedWatch\Tests\Unit\Simulation\Exchanges;
use PHPUnit\Framework\TestCase;

/**
 * End to end through the pure parts: chaos in the generator, then detection
 * and classification, as the runner does it.
 */
final class ChaosDetectionTest extends TestCase
{
    private const int START_US = 1_791_115_200_000_000;

    /**
     * @return array{list<Message>, array<string, FeedScan>, list<Gap>}
     */
    private function simulate(ExchangeConditions $conditions, int $seconds = 10): array
    {
        $end = self::START_US + $seconds * 1_000_000;
        $messages = (new MessageGenerator(new SeededRandom(7)))->generate(Exchanges::london(), 1, self::START_US, $end, $conditions);
        $horizon = $end;

        $scans = [];
        $found = [];
        foreach (Feed::cases() as $feed) {
            $deliveries = [];
            foreach ($messages as $message) {
                $received = $message->receivedUs($feed);
                if ($received !== null) {
                    $deliveries[] = new Delivery($message->sequence, $received);
                }
            }
            $scans[$feed->value] = (new FeedDetector())->scan($feed, new FeedState(1, self::START_US), $deliveries, $horizon);
            array_push($found, ...$scans[$feed->value]->gaps);
        }

        $bySequence = [];
        foreach ($messages as $message) {
            $bySequence[$message->sequence] = $message;
        }

        return [$messages, $scans, GapClassifier::classify($found, $bySequence, $horizon)];
    }

    /**
     * @param list<Gap> $gaps
     * @return list<string>
     */
    private static function summary(array $gaps): array
    {
        return array_values(array_unique(array_map(static fn (Gap $g): string => "{$g->scope} {$g->classification->value} {$g->first}-{$g->last}", $gaps)));
    }

    public function test_no_chaos_finds_nothing(): void
    {
        [, $scans, $gaps] = $this->simulate(ExchangeConditions::normal());

        self::assertSame([], $gaps);
        self::assertSame([], $scans['A']->silences);
        self::assertSame([], $scans['B']->silences);
    }

    public function test_a_burst_on_A_is_a_line_gap(): void
    {
        [, , $gaps] = $this->simulate(new ExchangeConditions(new FeedConditions(burstFromSequence: 10, burstCount: 5), FeedConditions::normal()));

        self::assertSame(['A line_gap 10-14'], self::summary($gaps));
    }

    public function test_the_same_burst_on_both_feeds_is_one_data_loss(): void
    {
        $burst = new FeedConditions(burstFromSequence: 10, burstCount: 5);
        [, , $gaps] = $this->simulate(new ExchangeConditions($burst, $burst));

        self::assertCount(2, $gaps, 'found once by each feed');
        self::assertSame(['AB data_loss 10-14'], self::summary($gaps), 'identical, so stored once');
    }

    public function test_silencing_A_opens_a_silence_while_B_carries_on(): void
    {
        [, $scans] = $this->simulate(new ExchangeConditions(new FeedConditions(silenced: true), FeedConditions::normal()));

        // A delivered nothing at all, so it has no last arrival to measure from:
        // the starting state's time is used.
        self::assertTrue($scans['A']->state->isSilent());
        self::assertFalse($scans['B']->state->isSilent());
    }
}
