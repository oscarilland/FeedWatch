<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Integration;

use FeedWatch\Detection\HealthStatus;
use FeedWatch\Domain\ExchangeSnapshot;
use FeedWatch\Infrastructure\ExchangeRepository;
use FeedWatch\Infrastructure\LatencyRepository;
use FeedWatch\Infrastructure\MinuteSummariser;
use FeedWatch\Infrastructure\SettingsRepository;
use FeedWatch\Infrastructure\SimulationRunner;
use FeedWatch\Infrastructure\SimulationStatus;
use FeedWatch\Simulation\ExchangeConditions;
use FeedWatch\Simulation\FeedConditions;

final class LatencyMonitoringTest extends DatabaseTestCase
{
    /** 2026-10-04T12:00:00Z, on a minute boundary. */
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
        $thresholds = (new SettingsRepository($this->pdo))->thresholds();
        (new SimulationRunner($this->pdo, $this->clock, $this->chaos, $thresholds))->catchUp();
    }

    private function runFor(int $seconds, int $step = 2): void
    {
        for ($t = 0; $t < $seconds; $t += $step) {
            $this->catchUp($step);
        }
    }

    private function london(): ExchangeSnapshot
    {
        $thresholds = (new SettingsRepository($this->pdo))->thresholds();

        return (new ExchangeRepository($this->pdo, $thresholds))->latest($this->clock->us)[0];
    }

    private function summariser(): MinuteSummariser
    {
        return new MinuteSummariser($this->pdo, (new SettingsRepository($this->pdo))->thresholds());
    }

    public function test_FR04_AC1_latency_is_stored_per_feed_per_second_for_every_exchange(): void
    {
        $this->runFor(10);

        $rows = $this->row(
            'SELECT COUNT(DISTINCT exchange_id) AS exchanges, SUM(a_count) AS a, SUM(b_count) AS b, MIN(a_max_us) AS min_a_max FROM feed_seconds WHERE accepted > 0'
        );
        self::assertSame(8, $rows['exchanges']);
        self::assertIsNumeric($rows['a']);
        self::assertGreaterThanOrEqual(8 * 49, (int) $rows['a']);
        self::assertSame($rows['a'], $rows['b'], 'both feeds deliver every message without chaos');
        self::assertGreaterThan(1_000, $rows['min_a_max']);

        $london = $this->london();
        self::assertNotNull($london->latency['A']->recentMeanUs);
        self::assertEqualsWithDelta(1_850, $london->latency['A']->recentMeanUs, 300);
        self::assertEqualsWithDelta(3_350, (float) $london->latency['B']->recentMeanUs, 300);
    }

    public function test_FR04_AC1_finished_minutes_get_percentiles_exactly_once(): void
    {
        $this->runFor(130, 10);

        $this->summariser()->run();
        $rows = $this->countRows('feed_minutes');
        $again = $this->summariser()->run();

        self::assertSame(2 * 16, $rows, 'two finished minutes, 16 feeds');
        self::assertSame(0, $again);
        self::assertSame($rows, $this->countRows('feed_minutes'));

        $minute = $this->row("SELECT message_count, p50_us, p95_us, p99_us, max_us FROM feed_minutes WHERE exchange_id = 1 AND feed = 'A' ORDER BY minute LIMIT 1");
        self::assertContains($minute['message_count'], [299, 300, 301]);
        self::assertIsInt($minute['p50_us']);
        self::assertEqualsWithDelta(1_850, $minute['p50_us'], 300);
        self::assertGreaterThanOrEqual($minute['p50_us'], $minute['p95_us']);
        self::assertGreaterThanOrEqual($minute['p95_us'], $minute['p99_us']);
        self::assertGreaterThanOrEqual($minute['p99_us'], $minute['max_us']);
    }

    public function test_the_baseline_is_the_expected_value_until_there_is_history(): void
    {
        $this->runFor(130, 10);
        $this->summariser()->run();

        $baseline = $this->row("SELECT baseline_us FROM detector_state WHERE exchange_id = 1 AND feed = 'A'")['baseline_us'];
        self::assertSame(2_000 - 150, $baseline, 'A base 2 ms plus the London offset');
    }

    public function test_FR04_AC3_sustained_extra_delay_turns_the_exchange_amber_then_recovers(): void
    {
        $this->runFor(10);
        // About 9.9 ms against a 1.85 ms baseline: over 3 times, under 10 times.
        $this->chaos->byCode['THX'] = new ExchangeConditions(new FeedConditions(extraDelayUs: 8_000), FeedConditions::normal());
        $this->runFor(8, 1);

        self::assertSame(HealthStatus::Degraded, $this->london()->health->status);
        self::assertSame('Degraded: high latency on A', $this->london()->health->reason);

        $this->chaos->byCode = [];
        $this->runFor(8, 1);

        self::assertSame(HealthStatus::Healthy, $this->london()->health->status);
    }

    public function test_very_high_latency_is_down(): void
    {
        $this->runFor(10);
        $this->chaos->byCode['THX'] = new ExchangeConditions(new FeedConditions(extraDelayUs: 40_000), FeedConditions::normal());
        $this->runFor(8, 1);

        self::assertSame(HealthStatus::Down, $this->london()->health->status);
        self::assertSame('Down: very high latency on A', $this->london()->health->reason);
    }

    public function test_a_threshold_changed_in_the_settings_table_takes_effect_without_a_deploy(): void
    {
        $this->pdo->exec("UPDATE settings SET value = '1' WHERE name = 'line_gaps_amber'");
        $this->runFor(4);
        $this->chaos->byCode['THX'] = new ExchangeConditions(new FeedConditions(burstFromSequence: 25, burstCount: 2), FeedConditions::normal());
        $this->runFor(8);

        self::assertSame(1, (new SettingsRepository($this->pdo))->thresholds()->lineGapsAmber);
        self::assertSame('Degraded: line gaps on A', $this->london()->health->reason, 'one line gap is now enough');
    }

    public function test_idle_mode_tracks_the_last_viewer_and_writes_at_most_once_a_minute(): void
    {
        $status = new SimulationStatus($this->pdo);
        self::assertNull($status->idleForUs($this->clock->us), 'never viewed');

        $status->markViewed($this->clock->us);
        $status->markViewed($this->clock->us + 30_000_000);
        self::assertSame(self::START_US, $this->row('SELECT last_viewed_us FROM simulation_state')['last_viewed_us'], 'second write skipped');

        $status->markViewed($this->clock->us + 61_000_000);
        self::assertSame(self::START_US + 61_000_000, $this->row('SELECT last_viewed_us FROM simulation_state')['last_viewed_us']);
        self::assertSame(39_000_000, $status->idleForUs(self::START_US + 100_000_000));
    }

    public function test_database_size_is_measured(): void
    {
        self::assertGreaterThan(0, (new SimulationStatus($this->pdo))->databaseBytes());
    }

    public function test_the_latency_repository_returns_seconds_and_minutes_for_the_chart(): void
    {
        $this->runFor(130, 10);
        $this->summariser()->run();
        $repository = new LatencyRepository($this->pdo);
        $nowSecond = intdiv($this->clock->us, 1_000_000);

        self::assertSame(1, $repository->exchangeId('THX'));
        self::assertNull($repository->exchangeId('ZZZ'));
        $seconds = $repository->seconds(1, $nowSecond - 60, $nowSecond);
        self::assertGreaterThanOrEqual(58, count($seconds));
        self::assertSame(5, $seconds[10]['a_count']);
        self::assertCount(4, $repository->minutes(1, intdiv($nowSecond, 60) - 15, intdiv($nowSecond, 60)), 'two minutes, one row per feed');
    }

    public function test_old_minute_summaries_are_pruned(): void
    {
        $this->runFor(130, 10);
        $summariser = $this->summariser();
        $summariser->run();

        $deleted = $summariser->pruneBefore(intdiv(self::START_US, 60_000_000) + 1);

        self::assertSame(16, $deleted, 'the first minute, all 16 feeds');
    }
}
