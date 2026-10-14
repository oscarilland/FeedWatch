<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Simulation;

use FeedWatch\Simulation\FeedConditions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FeedConditionsTest extends TestCase
{
    public function test_drop_percent_above_100_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeedConditions(dropPercent: 101.0);
    }

    public function test_negative_drop_percent_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeedConditions(dropPercent: -1.0);
    }

    public function test_negative_delay_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeedConditions(extraDelayUs: -1);
    }

    public function test_burst_covers_exactly_its_range(): void
    {
        $conditions = new FeedConditions(burstFromSequence: 100, burstCount: 3);

        self::assertFalse($conditions->inBurst(99));
        self::assertTrue($conditions->inBurst(100));
        self::assertTrue($conditions->inBurst(102));
        self::assertFalse($conditions->inBurst(103));
        self::assertFalse(FeedConditions::normal()->inBurst(100));
    }
}
