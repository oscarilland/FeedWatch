<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use FeedWatch\Domain\ExchangeSnapshot;

/**
 * Each exchange with its latest message and health, for the read-only API.
 */
interface ExchangeSnapshots
{
    /**
     * @param int $nowUs the time health windows (last minute, last 5 minutes) are measured from
     * @return list<ExchangeSnapshot>
     */
    public function latest(int $nowUs): array;

    /**
     * Figures for the dashboard's summary tiles (plan 006).
     *
     * @return array{messages_per_second: int, messages_lost_today: int}
     */
    public function summary(int $nowUs): array;
}
