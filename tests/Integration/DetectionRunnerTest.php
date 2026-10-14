<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Integration;

use FeedWatch\Detection\HealthStatus;
use FeedWatch\Domain\Feed;
use FeedWatch\Infrastructure\DetectionRunner;
use FeedWatch\Infrastructure\ExchangeRepository;
use FeedWatch\Infrastructure\SimulationRunner;
use FeedWatch\Simulation\ExchangeConditions;
use FeedWatch\Simulation\FeedConditions;

final class DetectionRunnerTest extends DatabaseTestCase
{
    private const int START_US = 1_791_115_200_000_000;

    private SettableClock $clock;
    private SwitchableChaos $chaos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshSchema();
        $this->clock = new SettableClock(self::START_US);
        $this->chaos = new SwitchableChaos();
        $this->catchUp();
    }

    private function catchUp(int $afterSeconds = 0): void
    {
        $this->clock->advanceSeconds($afterSeconds);
        (new SimulationRunner($this->pdo, $this->clock, $this->chaos))->catchUp();
    }

    /**
     * Steps of $step seconds, so detection runs often, as with live polling.
     */
    private function runFor(int $seconds, int $step = 2): void
    {
        for ($t = 0; $t < $seconds; $t += $step) {
            $this->catchUp($step);
        }
    }

    private function london(): \FeedWatch\Domain\ExchangeSnapshot
    {
        return (new ExchangeRepository($this->pdo))->latest($this->clock->us)[0];
    }

    public function test_normal_running_finds_no_gaps_or_silences_and_counts_every_arrival(): void
    {
        $this->runFor(30);

        self::assertSame(0, $this->countRows('gaps'));
        self::assertSame(0, $this->countRows('feed_silences'));
        self::assertSame(16, $this->countRows('detector_state'));
        $accepted = $this->row('SELECT SUM(accepted) AS n, SUM(duplicates) AS d FROM feed_seconds WHERE exchange_id = 1');
        // 30 s at 5 a second; the last message or two may arrive after the horizon.
        self::assertIsNumeric($accepted['n']);
        self::assertGreaterThanOrEqual(149, (int) $accepted['n']);
        self::assertLessThanOrEqual(150, (int) $accepted['n']);
        self::assertSame(HealthStatus::Healthy, $this->london()->health->status);
    }

    public function test_detection_state_carries_between_catch_ups(): void
    {
        $this->runFor(10);
        $state = $this->row("SELECT next_expected, pending_gaps FROM detector_state WHERE exchange_id = 1 AND feed = 'A'");

        // 50 messages sent; the last may arrive just after the horizon.
        self::assertContains($state['next_expected'], [50, 51]);
        self::assertSame('[]', $state['pending_gaps']);
    }

    public function test_FR02_AC2_a_burst_on_one_feed_is_a_line_gap(): void
    {
        $this->runFor(4);
        $this->chaos->byCode['THX'] = new ExchangeConditions(new FeedConditions(burstFromSequence: 25, burstCount: 4), FeedConditions::normal());
        $this->runFor(10);

        $gap = $this->row('SELECT scope, classification, first_sequence, last_sequence, message_count FROM gaps');
        self::assertSame(['A', 'line_gap', 25, 28, 4], array_values($gap));
        self::assertSame(1, $this->london()->lineGapsLastFiveMinutes);
        self::assertSame(HealthStatus::Healthy, $this->london()->health->status, 'one line gap stays healthy');
    }

    public function test_FR02_AC3_data_loss_is_stored_once_and_turns_the_exchange_red(): void
    {
        $this->runFor(4);
        $burst = new FeedConditions(burstFromSequence: 25, burstCount: 4);
        $this->chaos->byCode['THX'] = new ExchangeConditions($burst, $burst);
        $this->runFor(10);

        self::assertSame(1, $this->countRows('gaps'), 'found by both feeds, stored once');
        $gap = $this->row('SELECT scope, classification, message_count FROM gaps');
        self::assertSame(['AB', 'data_loss', 4], array_values($gap));
        self::assertSame(HealthStatus::Down, $this->london()->health->status);
        self::assertSame('Down: data lost (4 messages)', $this->london()->health->reason);

        // Red for 60 seconds, then healthy again.
        $this->runFor(70, 10);
        self::assertSame(HealthStatus::Healthy, $this->london()->health->status);
    }

    public function test_FR03_AC3_AC4_a_silent_feed_degrades_the_exchange_then_recovers(): void
    {
        $this->runFor(4);
        $this->chaos->byCode['THX'] = new ExchangeConditions(new FeedConditions(silenced: true), FeedConditions::normal());
        $this->runFor(6);

        $london = $this->london();
        self::assertSame(HealthStatus::Degraded, $london->health->status);
        self::assertSame('Degraded: running on B only', $london->health->reason);
        self::assertSame([Feed::A], $london->silentFeeds);
        self::assertSame(1, $this->row('SELECT COUNT(*) AS n FROM feed_silences WHERE ended_us IS NULL')['n']);

        $this->chaos->byCode = [];
        $this->runFor(6);

        self::assertSame(HealthStatus::Healthy, $this->london()->health->status);
        $silence = $this->row('SELECT started_us, ended_us FROM feed_silences');
        self::assertIsInt($silence['started_us']);
        self::assertIsInt($silence['ended_us']);
        self::assertGreaterThan($silence['started_us'], $silence['ended_us'], 'the incident duration is recorded (plan 005 uses it)');
        self::assertSame(1, $this->countRows('feed_silences'));
    }

    public function test_FR03_AC5_both_feeds_silent_is_down(): void
    {
        $this->runFor(4);
        $silent = new FeedConditions(silenced: true);
        $this->chaos->byCode['THX'] = new ExchangeConditions($silent, $silent);
        $this->runFor(6);

        self::assertSame(HealthStatus::Down, $this->london()->health->status);
        self::assertSame('Down: feed lost', $this->london()->health->reason);
    }

    public function test_a_simulation_pause_is_not_a_silence(): void
    {
        $this->runFor(4);
        $this->catchUp(3600);
        $this->runFor(4);

        self::assertSame(0, $this->countRows('feed_silences'));
        self::assertSame(0, $this->countRows('gaps'));
        self::assertSame(HealthStatus::Healthy, $this->london()->health->status);
    }

    public function test_a_sixty_second_catch_up_with_detection_finishes_within_one_second(): void
    {
        $started = hrtime(true);
        $this->catchUp(60);
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertSame(2400, $this->countRows('messages'));
        self::assertLessThan(1.0, $seconds, "took {$seconds} s");
    }

    public function test_per_second_counts_older_than_the_cutoff_are_pruned(): void
    {
        $this->runFor(10);
        $before = $this->countRows('feed_seconds');

        $deleted = (new DetectionRunner($this->pdo))->pruneSecondsBefore(intdiv(self::START_US, 1_000_000) + 5);

        self::assertGreaterThan(0, $deleted);
        self::assertSame($before - $deleted, $this->countRows('feed_seconds'));
        self::assertSame(0, $this->row('SELECT COUNT(*) AS n FROM feed_seconds WHERE second < ?', [intdiv(self::START_US, 1_000_000) + 5])['n']);
    }
}
