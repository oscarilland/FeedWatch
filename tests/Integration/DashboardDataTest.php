<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Integration;

use FeedWatch\Infrastructure\ExchangeDetailRepository;
use FeedWatch\Infrastructure\ExchangeRepository;
use FeedWatch\Infrastructure\SimulationRunner;
use FeedWatch\Simulation\ExchangeConditions;
use FeedWatch\Simulation\FeedConditions;

final class DashboardDataTest extends DatabaseTestCase
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
        $this->runner()->catchUp();
    }

    private function runner(): SimulationRunner
    {
        return new SimulationRunner($this->pdo, $this->clock, $this->chaos);
    }

    private function runFor(int $seconds): void
    {
        for ($t = 0; $t < $seconds; $t++) {
            $this->clock->advanceSeconds(1);
            $this->runner()->catchUp();
        }
    }

    public function test_ADR005_a_poll_within_a_second_of_the_last_catch_up_serves_stored_data(): void
    {
        $this->runFor(5);

        $this->clock->us += 500_000;
        self::assertNull($this->runner()->catchUp(1_000_000), 'half a second later: no catch-up');

        $this->clock->us += 600_000;
        self::assertNotNull($this->runner()->catchUp(1_000_000), 'over a second later: catches up');
    }

    public function test_the_summary_counts_the_last_second_and_todays_losses(): void
    {
        $this->runFor(5);
        $burst = new FeedConditions(burstFromSequence: 30, burstCount: 3);
        $this->chaos->byCode['THX'] = new ExchangeConditions($burst, $burst);
        $this->runFor(8);

        $summary = (new ExchangeRepository($this->pdo))->summary($this->clock->us);

        self::assertContains($summary['messages_per_second'], [39, 40, 41], '8 exchanges at 5 a second');
        self::assertSame(3, $summary['messages_lost_today']);
    }

    public function test_detail_gives_win_share_and_recent_gaps(): void
    {
        $this->runFor(5);
        $this->chaos->byCode['THX'] = new ExchangeConditions(new FeedConditions(burstFromSequence: 30, burstCount: 2), FeedConditions::normal());
        $this->runFor(8);

        $details = new ExchangeDetailRepository($this->pdo);
        $id = $details->exchangeId('THX');
        self::assertSame(1, $id);
        self::assertNull($details->exchangeId('ZZZ'));

        $nowSecond = intdiv($this->clock->us, 1_000_000);
        $win = $details->winShare(1, $nowSecond - 60, $nowSecond);
        self::assertGreaterThan(50, $win['accepted']);
        self::assertSame($win['accepted'], $win['a_wins'] + $win['b_wins']);
        self::assertGreaterThan($win['b_wins'], $win['a_wins'], 'A is the faster route');

        $gaps = $details->recentGaps(1, 10);
        self::assertSame([['scope' => 'A', 'classification' => 'line_gap', 'first' => 30, 'last' => 31, 'count' => 2]], array_map(
            static fn (array $g): array => array_diff_key($g, ['detected_us' => true]),
            $gaps,
        ));
    }
}
