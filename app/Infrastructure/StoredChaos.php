<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use FeedWatch\Domain\Exchange;
use FeedWatch\Simulation\ChaosFaults;
use FeedWatch\Simulation\ConditionsSource;
use FeedWatch\Simulation\ExchangeConditions;
use FeedWatch\Simulation\FeedConditions;

/**
 * The active faults, as the simulator's conditions (FR-07 AC-1). Read once
 * per catch-up; expired faults are already ignored here, before cron
 * deletes them.
 */
final class StoredChaos implements ConditionsSource
{
    /** @var array<int, array<string, list<array{kind: string, value: int, burst_from: ?int}>>>|null */
    private ?array $byExchange = null;

    public function __construct(
        private readonly ChaosRepository $faults,
        private readonly Clock $clock,
    ) {
    }

    public function forExchange(Exchange $exchange): ExchangeConditions
    {
        if ($this->byExchange === null) {
            $this->byExchange = [];
            foreach ($this->faults->active((int) $this->clock->now()->format('Uu')) as $fault) {
                $this->byExchange[$fault['exchange_id']][$fault['feed']][] = [
                    'kind' => $fault['kind'],
                    'value' => $fault['value'],
                    'burst_from' => $fault['burst_from'],
                ];
            }
        }

        $faults = $this->byExchange[$exchange->id] ?? [];
        if ($faults === []) {
            return ExchangeConditions::normal();
        }

        return new ExchangeConditions(
            isset($faults['A']) ? ChaosFaults::conditions($faults['A']) : FeedConditions::normal(),
            isset($faults['B']) ? ChaosFaults::conditions($faults['B']) : FeedConditions::normal(),
        );
    }
}
