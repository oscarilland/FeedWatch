<?php

declare(strict_types=1);

namespace FeedWatch\Domain;

use FeedWatch\Detection\ExchangeHealth;
use FeedWatch\Detection\FeedLatency;

/**
 * An exchange, its most recent message and its current health, for the
 * read-only API.
 */
final class ExchangeSnapshot
{
    /**
     * @param list<Feed> $silentFeeds feeds currently silent
     * @param array<string, FeedLatency> $latency feed ("A" or "B") => recent latency against baseline
     */
    public function __construct(
        public readonly Exchange $exchange,
        public readonly ?Message $lastMessage,
        public readonly ExchangeHealth $health,
        public readonly int $lineGapsLastFiveMinutes = 0,
        public readonly array $silentFeeds = [],
        public readonly array $latency = [],
        public readonly int $openIncidents = 0,
    ) {
    }
}
