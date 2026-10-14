<?php

declare(strict_types=1);

namespace FeedWatch\Simulation;

/**
 * The faults an admin can inject (FR-07), their limits, and the one-click
 * presets. Pure: no database.
 */
final class ChaosFaults
{
    /**
     * kind => [minimum, maximum] for its value. Silence has no value.
     * Delay is capped at 2 s, below detection's 5-second look-back (plan 003).
     *
     * @var array<string, array{int, int}>
     */
    public const array KINDS = [
        'silence' => [0, 0],
        'drop' => [1, 100],      // percent of messages lost at random
        'delay' => [1, 2000],    // milliseconds added to every delivery
        'burst' => [1, 500],     // the next N messages lost
    ];

    /**
     * name => [label, list of [exchange code, feed, kind, value]]
     *
     * @var array<string, array{string, list<array{string, string, string, int}>}>
     */
    public const array PRESETS = [
        'break_tokyo_a' => ['Break Tokyo A', [['KTX', 'A', 'silence', 0]]],
        'data_loss_tokyo' => ['Data loss on Tokyo', [['KTX', 'A', 'burst', 6], ['KTX', 'B', 'burst', 6]]],
        'slow_frankfurt_a' => ['Slow Frankfurt A', [['RHX', 'A', 'delay', 40]]],
        'fibre_cut_asia' => ['Fibre cut in Asia', [['KTX', 'A', 'silence', 0], ['PRX', 'A', 'silence', 0], ['HBX', 'A', 'silence', 0]]],
    ];

    /**
     * Why a fault is invalid, or null if it's fine. Values are rejected, never corrected.
     */
    public static function problem(string $feed, string $kind, int $value): ?string
    {
        if ($feed !== 'A' && $feed !== 'B') {
            return 'feed must be A or B';
        }
        if (!isset(self::KINDS[$kind])) {
            return 'kind must be one of: ' . implode(', ', array_keys(self::KINDS));
        }
        [$min, $max] = self::KINDS[$kind];
        if ($value < $min || $value > $max) {
            return $kind === 'silence' ? 'silence takes no value' : "{$kind} must be from {$min} to {$max}";
        }

        return null;
    }

    /**
     * A fault as stored: one feed's conditions. Several faults on the same
     * feed combine (silence wins; the strongest drop and delay apply).
     *
     * @param list<array{kind: string, value: int, burst_from: ?int}> $faults
     */
    public static function conditions(array $faults): FeedConditions
    {
        $silenced = false;
        $drop = 0.0;
        $delayUs = 0;
        $burstFrom = null;
        $burstCount = 0;
        foreach ($faults as $fault) {
            match ($fault['kind']) {
                'silence' => $silenced = true,
                'drop' => $drop = max($drop, (float) $fault['value']),
                'delay' => $delayUs = max($delayUs, $fault['value'] * 1000),
                'burst' => [$burstFrom, $burstCount] = [$fault['burst_from'], $fault['value']],
                default => null,
            };
        }

        return new FeedConditions($silenced, $drop, $delayUs, $burstFrom, $burstCount);
    }
}
