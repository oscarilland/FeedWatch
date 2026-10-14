<?php

declare(strict_types=1);

namespace FeedWatch\Simulation;

/**
 * What one catch-up should do: optionally record a pause from $pauseFromUs to
 * $generateFromUs, then generate the window [$generateFromUs, $generateToUs).
 * The window may be empty.
 */
final class CatchUpPlan
{
    public function __construct(
        public readonly ?int $pauseFromUs,
        public readonly int $generateFromUs,
        public readonly int $generateToUs,
    ) {
    }

    public function hasWindow(): bool
    {
        return $this->generateToUs > $this->generateFromUs;
    }
}
