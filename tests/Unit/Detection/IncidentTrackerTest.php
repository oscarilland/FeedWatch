<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Detection;

use FeedWatch\Detection\Condition;
use FeedWatch\Detection\IncidentAction;
use FeedWatch\Detection\IncidentTracker;
use FeedWatch\Detection\IncidentType;
use FeedWatch\Detection\OpenIncident;
use FeedWatch\Detection\Severity;
use PHPUnit\Framework\TestCase;

final class IncidentTrackerTest extends TestCase
{
    private const int T = 1_791_115_200_000_000;
    private const int S = 1_000_000;
    private const int MERGE = 30 * self::S;

    private static function failoverA(Severity $severity = Severity::Major): Condition
    {
        return new Condition(IncidentType::Failover, 'A', $severity, self::T - 3 * self::S, 'Feed A silent: running on B only');
    }

    /**
     * @param list<IncidentAction> $actions
     * @return list<string>
     */
    private static function kinds(array $actions): array
    {
        return array_map(static fn (IncidentAction $a): string => $a->kind, $actions);
    }

    public function test_a_new_condition_opens_an_incident_from_when_it_started(): void
    {
        $actions = IncidentTracker::track([], ['failover:A' => self::failoverA()], self::T, self::MERGE);

        self::assertSame(['open'], self::kinds($actions));
        self::assertSame(self::T - 3 * self::S, $actions[0]->atUs);
    }

    public function test_AC1_a_continuing_condition_keeps_the_same_incident(): void
    {
        $open = [new OpenIncident(7, IncidentType::Failover, 'A', Severity::Major, self::T - 3 * self::S)];

        $actions = IncidentTracker::track($open, ['failover:A' => self::failoverA()], self::T, self::MERGE);

        self::assertSame(['update'], self::kinds($actions));
        self::assertSame(7, $actions[0]->incidentId);
    }

    public function test_a_higher_severity_escalates_and_keeps_the_peak(): void
    {
        $open = [new OpenIncident(7, IncidentType::Latency, 'A', Severity::Warning, self::T - 10 * self::S)];
        $critical = new Condition(IncidentType::Latency, 'A', Severity::Critical, self::T, 'Very high latency on A');
        $warning = new Condition(IncidentType::Latency, 'A', Severity::Warning, self::T, 'High latency on A');

        self::assertSame(['escalate', 'update'], self::kinds(IncidentTracker::track($open, ['latency:A' => $critical], self::T, self::MERGE)));

        $peak = [new OpenIncident(7, IncidentType::Latency, 'A', Severity::Critical, self::T - 10 * self::S)];
        self::assertSame(['update'], self::kinds(IncidentTracker::track($peak, ['latency:A' => $warning], self::T, self::MERGE)), 'never de-escalates');
    }

    public function test_AC2_a_cleared_condition_closes_after_the_merge_window_ending_when_it_cleared(): void
    {
        $open = [new OpenIncident(7, IncidentType::Failover, 'A', Severity::Major, self::T - 60 * self::S)];

        $cleared = IncidentTracker::track($open, [], self::T, self::MERGE);
        self::assertSame(['clear'], self::kinds($cleared));

        $clearing = [new OpenIncident(7, IncidentType::Failover, 'A', Severity::Major, self::T - 60 * self::S, self::T)];
        self::assertSame([], IncidentTracker::track($clearing, [], self::T + 29 * self::S, self::MERGE), 'still inside the window');

        $closed = IncidentTracker::track($clearing, [], self::T + 30 * self::S, self::MERGE);
        self::assertSame(['close'], self::kinds($closed));
        self::assertSame(self::T, $closed[0]->atUs, 'ends when it cleared, not when the window ran out');
    }

    public function test_a_condition_returning_within_the_window_is_the_same_incident(): void
    {
        $clearing = [new OpenIncident(7, IncidentType::Failover, 'A', Severity::Major, self::T - 60 * self::S, self::T)];

        $actions = IncidentTracker::track($clearing, ['failover:A' => self::failoverA()], self::T + 10 * self::S, self::MERGE);

        self::assertSame(['return', 'update'], self::kinds($actions));
    }

    public function test_a_merge_window_of_zero_closes_straight_away(): void
    {
        $open = [new OpenIncident(7, IncidentType::Failover, 'A', Severity::Major, self::T - 60 * self::S)];

        self::assertSame(['clear', 'close'], self::kinds(IncidentTracker::track($open, [], self::T, 0)));
    }

    public function test_data_loss_ends_at_its_last_loss(): void
    {
        $clearing = [new OpenIncident(7, IncidentType::DataLoss, 'AB', Severity::Critical, self::T - 70 * self::S, self::T, self::T - 62 * self::S)];

        $closed = IncidentTracker::track($clearing, [], self::T + 30 * self::S, self::MERGE);

        self::assertSame(self::T - 62 * self::S, $closed[0]->atUs);
    }

    public function test_different_feeds_and_types_are_separate_incidents(): void
    {
        $open = [new OpenIncident(7, IncidentType::Failover, 'A', Severity::Major, self::T)];
        $conditions = [
            'failover:A' => self::failoverA(),
            'latency:B' => new Condition(IncidentType::Latency, 'B', Severity::Warning, self::T, 'High latency on B'),
        ];

        self::assertSame(['update', 'open'], self::kinds(IncidentTracker::track($open, $conditions, self::T, self::MERGE)));
    }
}
