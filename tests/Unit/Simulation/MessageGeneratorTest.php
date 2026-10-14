<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Simulation;

use FeedWatch\Domain\Feed;
use FeedWatch\Domain\Message;
use FeedWatch\Domain\MessageType;
use FeedWatch\Simulation\ExchangeConditions;
use FeedWatch\Simulation\FeedConditions;
use FeedWatch\Simulation\LatencyModel;
use FeedWatch\Simulation\MessageGenerator;
use FeedWatch\Simulation\SeededRandom;
use PHPUnit\Framework\TestCase;

final class MessageGeneratorTest extends TestCase
{
    /** 4 October 2026 12:00:00 UTC, in microseconds. */
    private const int START_US = 1_791_115_200_000_000;
    private const int SECOND_US = 1_000_000;

    private function generator(int $seed = 20261004): MessageGenerator
    {
        return new MessageGenerator(new SeededRandom($seed));
    }

    /**
     * @return list<Message>
     */
    private function simulate(int $seconds, ?ExchangeConditions $conditions = null, int $exchangeIndex = 0): array
    {
        return $this->generator()->generate(
            Exchanges::all()[$exchangeIndex],
            1,
            self::START_US,
            self::START_US + $seconds * self::SECOND_US,
            $conditions ?? ExchangeConditions::normal(),
        );
    }

    /**
     * @param list<int> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    public function test_five_messages_a_second_on_a_200ms_grid(): void
    {
        $messages = $this->simulate(10);

        self::assertCount(50, $messages);
        for ($i = 1, $n = count($messages); $i < $n; $i++) {
            self::assertSame(MessageGenerator::INTERVAL_US, $messages[$i]->sentUs - $messages[$i - 1]->sentUs);
        }
        self::assertGreaterThanOrEqual(self::START_US, $messages[0]->sentUs);
        self::assertLessThan(self::START_US + MessageGenerator::INTERVAL_US, $messages[0]->sentUs);
    }

    public function test_AC2_sequence_numbers_rise_by_exactly_one_from_the_first(): void
    {
        $messages = $this->generator()->generate(Exchanges::london(), 500, self::START_US, self::START_US + 60 * self::SECOND_US, ExchangeConditions::normal());

        foreach ($messages as $i => $message) {
            self::assertSame(500 + $i, $message->sequence);
        }
    }

    public function test_AC2_every_message_goes_out_on_both_feeds_with_the_same_number(): void
    {
        foreach ($this->simulate(60) as $message) {
            self::assertNotNull($message->aReceivedUs, 'A delivered ' . $message->sequence);
            self::assertNotNull($message->bReceivedUs, 'B delivered ' . $message->sequence);
            self::assertGreaterThan($message->sentUs, $message->aReceivedUs);
        }
    }

    public function test_AC3_ten_minutes_without_chaos_has_no_gaps_and_A_is_faster_on_every_exchange(): void
    {
        foreach (Exchanges::all() as $index => $exchange) {
            $messages = $this->simulate(600, null, $index);
            self::assertCount(3000, $messages, $exchange->code);

            $a = [];
            $b = [];
            foreach ($messages as $i => $message) {
                self::assertSame($i + 1, $message->sequence, $exchange->code . ' has no gap');
                $a[] = (int) $message->latencyUs(Feed::A);
                $b[] = (int) $message->latencyUs(Feed::B);
            }
            self::assertLessThan(self::median($b), self::median($a), $exchange->code . ' A median below B');
        }
    }

    public function test_latencies_stay_within_the_model_and_spikes_are_rare(): void
    {
        $spikes = 0;
        $messages = $this->simulate(600);
        foreach ($messages as $message) {
            $latency = (int) $message->latencyUs(Feed::A);
            $offset = Exchanges::london()->latencyOffsetUs;
            $normalMax = LatencyModel::A_BASE_US + $offset + LatencyModel::JITTER_US;
            self::assertGreaterThanOrEqual(LatencyModel::A_BASE_US + $offset - LatencyModel::JITTER_US, $latency);
            if ($latency > $normalMax) {
                $spikes++;
                self::assertLessThanOrEqual($normalMax + LatencyModel::SPIKE_MAX_US, $latency);
            }
        }

        // 0.5% of 3,000 is 15; allow a wide margin.
        self::assertGreaterThan(2, $spikes);
        self::assertLessThan(40, $spikes);
    }

    public function test_all_three_message_types_appear(): void
    {
        $types = array_map(static fn (Message $m): MessageType => $m->type, $this->simulate(120));

        foreach (MessageType::cases() as $type) {
            self::assertContains($type, $types);
        }
    }

    public function test_AC4_same_seed_and_start_give_identical_output(): void
    {
        self::assertEquals($this->simulate(60), $this->simulate(60));
    }

    public function test_AC4_one_window_equals_the_same_time_split_into_twelve(): void
    {
        $whole = $this->simulate(60);

        $pieces = [];
        $next = 1;
        for ($i = 0; $i < 12; $i++) {
            $from = self::START_US + $i * 5 * self::SECOND_US;
            $batch = $this->generator()->generate(Exchanges::london(), $next, $from, $from + 5 * self::SECOND_US, ExchangeConditions::normal());
            $next += count($batch);
            array_push($pieces, ...$batch);
        }

        self::assertEquals($whole, $pieces);
    }

    public function test_a_different_seed_gives_different_timings(): void
    {
        $other = (new MessageGenerator(new SeededRandom(1)))->generate(Exchanges::london(), 1, self::START_US, self::START_US + 10 * self::SECOND_US, ExchangeConditions::normal());

        self::assertNotEquals($this->simulate(10), $other);
    }

    public function test_exchanges_send_at_different_instants(): void
    {
        $london = $this->simulate(1, null, 0)[0]->sentUs;
        $tokyo = $this->simulate(1, null, 5)[0]->sentUs;

        self::assertNotSame($london, $tokyo);
    }

    public function test_a_silenced_feed_delivers_nothing_and_the_other_is_unaffected(): void
    {
        $messages = $this->simulate(10, new ExchangeConditions(new FeedConditions(silenced: true), FeedConditions::normal()));

        foreach ($messages as $message) {
            self::assertNull($message->aReceivedUs);
            self::assertNotNull($message->bReceivedUs);
        }
    }

    public function test_a_drop_rate_drops_about_that_share(): void
    {
        $messages = $this->simulate(600, new ExchangeConditions(new FeedConditions(dropPercent: 50.0), FeedConditions::normal()));
        $dropped = count(array_filter($messages, static fn (Message $m): bool => $m->aReceivedUs === null));

        self::assertGreaterThan(1350, $dropped);
        self::assertLessThan(1650, $dropped);
    }

    public function test_extra_delay_is_added_to_every_delivery(): void
    {
        $normal = $this->simulate(10);
        $delayed = $this->simulate(10, new ExchangeConditions(FeedConditions::normal(), new FeedConditions(extraDelayUs: 40_000)));

        foreach ($normal as $i => $message) {
            self::assertSame((int) $message->bReceivedUs + 40_000, $delayed[$i]->bReceivedUs);
            self::assertSame($message->aReceivedUs, $delayed[$i]->aReceivedUs);
        }
    }

    public function test_a_burst_drops_exactly_those_messages_then_stops(): void
    {
        $messages = $this->simulate(10, new ExchangeConditions(new FeedConditions(burstFromSequence: 10, burstCount: 5), FeedConditions::normal()));

        foreach ($messages as $message) {
            $inBurst = $message->sequence >= 10 && $message->sequence < 15;
            self::assertSame($inBurst, $message->aReceivedUs === null, 'sequence ' . $message->sequence);
        }
    }

    public function test_the_same_fault_on_both_feeds_loses_the_message_entirely(): void
    {
        $burst = new FeedConditions(burstFromSequence: 3, burstCount: 2);
        $messages = $this->simulate(2, new ExchangeConditions($burst, $burst));

        self::assertNull($messages[2]->aReceivedUs);
        self::assertNull($messages[2]->bReceivedUs);
        self::assertNotNull($messages[4]->aReceivedUs);
    }

    public function test_an_empty_window_gives_no_messages(): void
    {
        self::assertSame([], $this->generator()->generate(Exchanges::london(), 1, self::START_US, self::START_US, ExchangeConditions::normal()));
    }
}
