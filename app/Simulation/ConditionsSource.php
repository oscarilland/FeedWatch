<?php

declare(strict_types=1);

namespace FeedWatch\Simulation;

use FeedWatch\Domain\Exchange;

/**
 * Where the current chaos conditions come from. Plan 007 adds one backed by
 * the database; until then every feed runs normally.
 */
interface ConditionsSource
{
    public function forExchange(Exchange $exchange): ExchangeConditions;
}
