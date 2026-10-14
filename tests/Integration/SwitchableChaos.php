<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Integration;

use FeedWatch\Domain\Exchange;
use FeedWatch\Simulation\ConditionsSource;
use FeedWatch\Simulation\ExchangeConditions;

/**
 * Chaos conditions a test can set per exchange code between catch-ups.
 */
final class SwitchableChaos implements ConditionsSource
{
    /** @var array<string, ExchangeConditions> */
    public array $byCode = [];

    public function forExchange(Exchange $exchange): ExchangeConditions
    {
        return $this->byCode[$exchange->code] ?? ExchangeConditions::normal();
    }
}
