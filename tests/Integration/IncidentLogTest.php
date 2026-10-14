<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Integration;

use FeedWatch\Infrastructure\IncidentRepository;
use FeedWatch\Infrastructure\SettingsRepository;
use FeedWatch\Infrastructure\SimulationRunner;
use FeedWatch\Simulation\ExchangeConditions;
use FeedWatch\Simulation\FeedConditions;

final class IncidentLogTest extends DatabaseTestCase
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
        $this->runFor(6);
    }

    private function catchUp(int $afterSeconds = 0): void
    {
        $this->clock->advanceSeconds($afterSeconds);
        $thresholds = (new SettingsRepository($this->pdo))->thresholds();
        (new SimulationRunner($this->pdo, $this->clock, $this->chaos, $thresholds))->catchUp();
    }

    private function runFor(int $seconds, int $step = 1): void
    {
        for ($t = 0; $t < $seconds; $t += $step) {
            $this->catchUp($step);
        }
    }

    private function chaosOnLondon(FeedConditions $a, ?FeedConditions $b = null): void
    {
        $this->chaos->byCode['THX'] = new ExchangeConditions($a, $b ?? FeedConditions::normal());
    }

    /**
     * @return list<string>
     */
    private function timeline(int $incidentId): array
    {
        $found = (new IncidentRepository($this->pdo))->find($incidentId);
        self::assertNotNull($found);

        return array_map(static fn (array $e): string => $e['kind'], $found['events']);
    }

    public function test_a_healthy_system_has_no_incidents(): void
    {
        $this->runFor(20, 2);

        self::assertSame(0, $this->countRows('incidents'));
    }

    public function test_FR03_AC4_a_silent_feed_is_one_failover_incident_that_records_how_long_it_lasted(): void
    {
        $this->chaosOnLondon(new FeedConditions(silenced: true));
        $this->runFor(12);

        self::assertSame(1, $this->countRows('incidents'), 'AC-1: one incident while it continues');
        $open = $this->row('SELECT id, type, feed, severity, closed_us FROM incidents');
        self::assertSame(['failover', 'A', 'major', null], [$open['type'], $open['feed'], $open['severity'], $open['closed_us']]);

        $this->chaos->byCode = [];
        $this->runFor(40, 2);

        $closed = $this->row('SELECT id, opened_us, closed_us FROM incidents');
        self::assertIsInt($closed['id']);
        self::assertIsInt($closed['opened_us']);
        self::assertIsInt($closed['closed_us'], 'AC-2: closed once clear for 30 s');
        // Silent from about 2 s after the last delivery until A came back: about 10 s.
        self::assertEqualsWithDelta(10, ($closed['closed_us'] - $closed['opened_us']) / 1e6, 3);
        self::assertSame(['opened', 'cleared', 'closed'], $this->timeline($closed['id']));
    }

    public function test_FR02_data_loss_is_one_critical_incident_with_the_messages_lost(): void
    {
        $burst = new FeedConditions(burstFromSequence: 60, burstCount: 4);
        $this->chaosOnLondon($burst, $burst);
        $this->runFor(10);
        $this->chaos->byCode = [];
        $this->runFor(100, 5);

        $incident = $this->row('SELECT type, severity, details, opened_us, closed_us FROM incidents');
        self::assertSame(['data_loss', 'critical'], [$incident['type'], $incident['severity']]);
        self::assertIsString($incident['details']);
        self::assertSame(['messages_lost' => 4], json_decode($incident['details'], true));
        self::assertIsInt($incident['closed_us']);
        self::assertSame($incident['opened_us'], $incident['closed_us'], 'one burst: it ends at its last (and only) loss');
    }

    public function test_FR04_latency_escalates_one_incident_from_warning_to_critical(): void
    {
        $this->chaosOnLondon(new FeedConditions(extraDelayUs: 8_000));
        $this->runFor(8);
        $this->chaosOnLondon(new FeedConditions(extraDelayUs: 40_000));
        $this->runFor(8);
        $this->chaos->byCode = [];
        $this->runFor(40, 2);

        self::assertSame(1, $this->countRows('incidents'));
        $incident = $this->row('SELECT id, type, feed, severity, details, closed_us FROM incidents');
        self::assertSame(['latency', 'A', 'critical'], [$incident['type'], $incident['feed'], $incident['severity']], 'peak severity kept');
        self::assertIsString($incident['details']);
        $details = json_decode($incident['details'], true);
        self::assertIsArray($details);
        self::assertGreaterThan(40_000, $details['peak_latency_us']);
        self::assertNotNull($incident['closed_us']);
        self::assertIsInt($incident['id']);
        self::assertSame(['opened', 'escalated', 'cleared', 'closed'], $this->timeline($incident['id']));
    }

    public function test_a_flap_within_thirty_seconds_stays_one_incident(): void
    {
        $this->chaosOnLondon(new FeedConditions(silenced: true));
        $this->runFor(6);
        $this->chaos->byCode = [];
        $this->runFor(10);
        $this->chaosOnLondon(new FeedConditions(silenced: true));
        $this->runFor(6);
        $this->chaos->byCode = [];
        $this->runFor(40, 2);

        self::assertSame(1, $this->countRows('incidents'));
        $id = $this->row('SELECT id FROM incidents')['id'];
        self::assertIsInt($id);
        self::assertSame(['opened', 'cleared', 'returned', 'cleared', 'closed'], $this->timeline($id));
    }

    public function test_FR06_AC3_the_log_filters_by_exchange_type_and_time_newest_first(): void
    {
        $this->chaosOnLondon(new FeedConditions(silenced: true));
        $this->runFor(6);
        $this->chaos->byCode = ['KTX' => new ExchangeConditions(FeedConditions::normal(), new FeedConditions(silenced: true))];
        $this->runFor(6);

        $log = new IncidentRepository($this->pdo);
        $all = $log->search(null, null, null, null, 50);

        self::assertCount(2, $all);
        self::assertSame(['KTX', 'THX'], [$all[0]['exchange'], $all[1]['exchange']], 'newest first');
        self::assertCount(1, $log->search('THX', null, null, null, 50));
        self::assertCount(2, $log->search(null, 'failover', null, null, 50));
        self::assertCount(0, $log->search(null, 'latency', null, null, 50));
        self::assertCount(1, $log->search(null, null, null, null, 1));
        self::assertCount(0, $log->search(null, null, $this->clock->us + 1, null, 50));
    }

    public function test_FR06_AC5_nothing_prunes_incidents(): void
    {
        $this->chaosOnLondon(new FeedConditions(silenced: true));
        $this->runFor(6);

        $before = $this->countRows('incidents');
        (new \FeedWatch\Infrastructure\MinuteSummariser($this->pdo, (new SettingsRepository($this->pdo))->thresholds()))->pruneBefore(PHP_INT_MAX >> 8);
        (new \FeedWatch\Infrastructure\MessageRepository($this->pdo))->pruneSentBefore(PHP_INT_MAX);

        self::assertSame($before, $this->countRows('incidents'));
        self::assertGreaterThan(0, $this->countRows('incident_events'));
    }
}
