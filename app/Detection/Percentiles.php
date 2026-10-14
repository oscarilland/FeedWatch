<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

use InvalidArgumentException;

/**
 * Nearest-rank percentiles: the smallest value with at least p% of the
 * values at or below it. Simple to explain, and exact for the dataset.
 */
final class Percentiles
{
    /**
     * @param list<int> $values
     */
    public static function of(array $values, float $percent): int
    {
        if ($values === []) {
            throw new InvalidArgumentException('No values');
        }
        if ($percent <= 0 || $percent > 100) {
            throw new InvalidArgumentException('Percent must be above 0 and at most 100');
        }
        sort($values);
        $rank = (int) ceil($percent / 100 * count($values));

        return $values[$rank - 1];
    }

    /**
     * The median of a list of numbers (the mean of the middle two for an even count).
     *
     * @param list<int> $values
     */
    public static function median(array $values): float
    {
        if ($values === []) {
            throw new InvalidArgumentException('No values');
        }
        sort($values);
        $n = count($values);
        $middle = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
