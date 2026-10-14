<?php

declare(strict_types=1);

namespace FeedWatch\Simulation;

use FeedWatch\Domain\Exchange;

final class NoChaos implements ConditionsSource
{
    public function forExchange(Exchange $exchange): ExchangeConditions
    {
        return ExchangeConditions::normal();
    }
}
