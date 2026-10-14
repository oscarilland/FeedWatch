<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Simulation;

use FeedWatch\Simulation\SeededRandom;
use PHPUnit\Framework\TestCase;

final class SeededRandomTest extends TestCase
{
    public function test_same_seed_and_key_give_the_same_number(): void
    {
        self::assertSame((new SeededRandom(42))->float('THX', 7, 'A'), (new SeededRandom(42))->float('THX', 7, 'A'));
    }

    public function test_different_seeds_or_keys_give_different_numbers(): void
    {
        $random = new SeededRandom(42);

        self::assertNotSame($random->float('THX', 7, 'A'), $random->float('THX', 7, 'B'));
        self::assertNotSame($random->float('THX', 7, 'A'), $random->float('THX', 8, 'A'));
        self::assertNotSame($random->float('THX', 7, 'A'), (new SeededRandom(43))->float('THX', 7, 'A'));
    }

    public function test_numbers_are_in_range_and_spread_evenly(): void
    {
        $random = new SeededRandom(1);
        $buckets = array_fill(0, 10, 0);
        for ($i = 0; $i < 10_000; $i++) {
            $value = $random->float('spread', $i);
            self::assertGreaterThanOrEqual(0.0, $value);
            self::assertLessThan(1.0, $value);
            $buckets[(int) floor($value * 10)]++;
        }

        // Each tenth should hold about 1,000 values.
        foreach ($buckets as $count) {
            self::assertGreaterThan(850, $count);
            self::assertLessThan(1150, $count);
        }
    }
}
