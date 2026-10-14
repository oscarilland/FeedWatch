<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

final class CatchUpResult
{
    public function __construct(
        public readonly int $messagesWritten,
        public readonly int $simulatedUs,
        public readonly bool $paused,
        public readonly float $durationSeconds,
        public readonly int $gapsRecorded = 0,
    ) {
    }
}
