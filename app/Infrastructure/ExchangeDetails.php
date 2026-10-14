<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

/**
 * The extra figures the dashboard's exchange detail panel shows (plan 006).
 */
interface ExchangeDetails
{
    public function exchangeId(string $code): ?int;

    /**
     * A/B arbitration totals for the seconds [$fromSecond, $toSecond).
     *
     * @return array{accepted: int, a_wins: int, b_wins: int, duplicates: int}
     */
    public function winShare(int $exchangeId, int $fromSecond, int $toSecond): array;

    /**
     * The most recent gaps, newest first.
     *
     * @return list<array{scope: string, classification: string, first: int, last: int, count: int, detected_us: int}>
     */
    public function recentGaps(int $exchangeId, int $limit): array;
}
