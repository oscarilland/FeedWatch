<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use DateTimeImmutable;

/**
 * The current time, injected so code that uses it can be tested.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
