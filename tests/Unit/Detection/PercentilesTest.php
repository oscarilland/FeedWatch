<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Detection;

use FeedWatch\Detection\Percentiles;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PercentilesTest extends TestCase
{
    public function test_nearest_rank_on_one_to_a_hundred(): void
    {
        $values = range(100, 1, -1);

        self::assertSame(50, Percentiles::of($values, 50));
        self::assertSame(95, Percentiles::of($values, 95));
        self::assertSame(99, Percentiles::of($values, 99));
        self::assertSame(100, Percentiles::of($values, 100));
        self::assertSame(1, Percentiles::of($values, 0.5));
    }

    public function test_a_single_value_is_every_percentile(): void
    {
        self::assertSame(7, Percentiles::of([7], 50));
        self::assertSame(7, Percentiles::of([7], 99));
    }

    public function test_an_empty_list_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Percentiles::of([], 50);
    }

    public function test_median_of_odd_and_even_counts(): void
    {
        self::assertSame(3.0, Percentiles::median([5, 1, 3]));
        self::assertSame(2.5, Percentiles::median([4, 1, 3, 2]));
    }
}
