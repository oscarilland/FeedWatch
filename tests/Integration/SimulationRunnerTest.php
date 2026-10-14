<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Integration;

use FeedWatch\Infrastructure\Config;
use FeedWatch\Infrastructure\Database;
use FeedWatch\Infrastructure\ExchangeRepository;
use FeedWatch\Infrastructure\MessageRepository;
use FeedWatch\Infrastructure\SimulationRunner;
use FeedWatch\Simulation\NoChaos;

final class SimulationRunnerTest extends DatabaseTestCase
{
    /** 4 October 2026 12:00:00 UTC, in microseconds. */
    private const int START_US = 1_791_115_200_000_000;
    private const int SECOND_US = 1_000_000;

    private SettableClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshSchema();
        $this->clock = new SettableClock(self::START_US);
    }

    private function runner(): SimulationRunner
    {
        return new SimulationRunner($this->pdo, $this->clock, new NoChaos());
    }

    private function advance(int $seconds): void
    {
        $this->clock->advanceSeconds($seconds);
    }

    public function test_AC1_eight_exchanges_are_seeded(): void
    {
        $codes = array_map(
            static fn (array $e): string => $e['exchange']->code,
            (new ExchangeRepository($this->pdo))->allWithNextSequence(),
        );

        self::assertSame(['THX', 'RHX', 'HDX', 'LKX', 'PLX', 'KTX', 'PRX', 'HBX'], $codes);
    }

    public function test_first_catch_up_starts_the_clock_without_writing_history(): void
    {
        $result = $this->runner()->catchUp();

        self::assertNotNull($result);
        self::assertSame(0, $result->messagesWritten);
        self::assertSame(self::START_US, $this->row('SELECT processed_until_us FROM simulation_state')['processed_until_us']);
    }

    public function test_AC5_sixty_seconds_for_eight_exchanges_writes_2400_messages_within_one_second(): void
    {
        $this->runner()->catchUp();
        $this->advance(60);

        $result = $this->runner()->catchUp();

        self::assertNotNull($result);
        self::assertSame(2400, $result->messagesWritten);
        self::assertSame(2400, $this->countRows('messages'));
        self::assertFalse($result->paused);
        self::assertLessThan(1.0, $result->durationSeconds, 'catch-up took ' . $result->durationSeconds . ' s');
    }

    public function test_AC2_repeated_catch_ups_continue_the_sequence_with_no_duplicates_or_gaps(): void
    {
        $this->runner()->catchUp();
        for ($i = 0; $i < 5; $i++) {
            $this->advance(3);
            $this->runner()->catchUp();
        }

        $row = $this->row(
            'SELECT COUNT(*) AS n, COUNT(DISTINCT sequence) AS distinct_n, MIN(sequence) AS first, MAX(sequence) AS last'
            . ' FROM messages WHERE exchange_id = 1'
        );
        self::assertSame(75, $row['n']);
        self::assertSame(75, $row['distinct_n']);
        self::assertSame(1, $row['first']);
        self::assertSame(75, $row['last']);
        self::assertSame(76, $this->row('SELECT next_sequence FROM exchanges WHERE id = 1')['next_sequence']);
    }

    public function test_a_second_caller_skips_while_the_lock_is_held(): void
    {
        $other = Database::connect(new Config(getenv()));
        $lock = $other->query("SELECT GET_LOCK('feedwatch_simulation', 0)");
        self::assertNotFalse($lock);
        self::assertSame(1, (int) $lock->fetchColumn());

        try {
            self::assertNull($this->runner()->catchUp());
        } finally {
            $other->query("SELECT RELEASE_LOCK('feedwatch_simulation')");
        }
    }

    public function test_a_long_absence_records_a_pause_and_sequence_numbers_carry_on(): void
    {
        $this->runner()->catchUp();
        $this->advance(10);
        $this->runner()->catchUp();
        $lastBefore = $this->row('SELECT MAX(sequence) AS s FROM messages WHERE exchange_id = 1')['s'];

        $this->advance(3600);
        $result = $this->runner()->catchUp();

        self::assertNotNull($result);
        self::assertTrue($result->paused);
        self::assertSame(1, $this->countRows('simulation_pauses'));
        $pause = $this->row('SELECT from_us, to_us FROM simulation_pauses');
        self::assertSame(self::START_US + 10 * self::SECOND_US, $pause['from_us']);
        self::assertSame(self::START_US + 3610 * self::SECOND_US - 60 * self::SECOND_US, $pause['to_us']);

        $firstAfter = $this->row(
            'SELECT MIN(sequence) AS s FROM messages WHERE exchange_id = 1 AND sent_us >= ?',
            [self::START_US + 3550 * self::SECOND_US],
        )['s'];
        self::assertIsInt($lastBefore);
        self::assertSame($lastBefore + 1, $firstAfter, 'no gap in sequence numbers across the pause');
    }

    public function test_pruning_removes_only_messages_older_than_the_cutoff(): void
    {
        $this->runner()->catchUp();
        $this->advance(20);
        $this->runner()->catchUp();
        $before = $this->countRows('messages');

        $deleted = (new MessageRepository($this->pdo))->pruneSentBefore(self::START_US + 10 * self::SECOND_US, 50);

        self::assertSame(800, $before, '8 exchanges x 20 seconds x 5 a second');
        self::assertSame(400, $deleted, 'the first 10 seconds, deleted in batches of 50');
        self::assertSame(400, $this->countRows('messages'));
        self::assertSame(0, $this->row('SELECT COUNT(*) AS n FROM messages WHERE sent_us < ?', [self::START_US + 10 * self::SECOND_US])['n']);
    }

    public function test_latest_snapshot_is_each_exchanges_last_message(): void
    {
        $this->runner()->catchUp();
        $this->advance(2);
        $this->runner()->catchUp();

        $snapshots = (new ExchangeRepository($this->pdo))->latest($this->clock->us);

        self::assertCount(8, $snapshots);
        self::assertSame('THX', $snapshots[0]->exchange->code);
        self::assertNotNull($snapshots[0]->lastMessage);
        self::assertSame(10, $snapshots[0]->lastMessage->sequence);
        self::assertNotNull($snapshots[0]->lastMessage->aReceivedUs);
    }
}
