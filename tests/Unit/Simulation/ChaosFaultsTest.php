<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Simulation;

use FeedWatch\Simulation\ChaosFaults;
use PHPUnit\Framework\TestCase;

final class ChaosFaultsTest extends TestCase
{
    public function test_valid_faults_pass(): void
    {
        self::assertNull(ChaosFaults::problem('A', 'silence', 0));
        self::assertNull(ChaosFaults::problem('B', 'drop', 100));
        self::assertNull(ChaosFaults::problem('A', 'delay', 2000));
        self::assertNull(ChaosFaults::problem('B', 'burst', 1));
    }

    public function test_invalid_faults_are_rejected_not_corrected(): void
    {
        self::assertNotNull(ChaosFaults::problem('C', 'silence', 0));
        self::assertNotNull(ChaosFaults::problem('A', 'meltdown', 1));
        self::assertNotNull(ChaosFaults::problem('A', 'drop', 0));
        self::assertNotNull(ChaosFaults::problem('A', 'drop', 101));
        self::assertSame('delay must be from 1 to 2000', ChaosFaults::problem('A', 'delay', 2001), 'below the 5 s look-back');
        self::assertNotNull(ChaosFaults::problem('A', 'burst', 501));
        self::assertNotNull(ChaosFaults::problem('A', 'silence', 5));
    }

    public function test_every_preset_is_made_of_valid_faults(): void
    {
        foreach (ChaosFaults::PRESETS as $name => [$label, $faults]) {
            self::assertNotSame('', $label);
            self::assertNotEmpty($faults, $name);
            foreach ($faults as [$code, $feed, $kind, $value]) {
                self::assertMatchesRegularExpression('/^[A-Z]{3}$/', $code);
                self::assertNull(ChaosFaults::problem($feed, $kind, $value), "{$name}: {$code} {$feed} {$kind}");
            }
        }
    }

    public function test_faults_on_one_feed_combine(): void
    {
        $conditions = ChaosFaults::conditions([
            ['kind' => 'drop', 'value' => 20, 'burst_from' => null],
            ['kind' => 'delay', 'value' => 40, 'burst_from' => null],
            ['kind' => 'burst', 'value' => 6, 'burst_from' => 1200],
        ]);

        self::assertFalse($conditions->silenced);
        self::assertSame(20.0, $conditions->dropPercent);
        self::assertSame(40_000, $conditions->extraDelayUs);
        self::assertTrue($conditions->inBurst(1200));
        self::assertTrue($conditions->inBurst(1205));
        self::assertFalse($conditions->inBurst(1206));
    }

    public function test_silence_is_silence(): void
    {
        self::assertTrue(ChaosFaults::conditions([['kind' => 'silence', 'value' => 0, 'burst_from' => null]])->silenced);
    }
}
