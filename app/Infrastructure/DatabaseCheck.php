<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

/**
 * Answers "can we reach the database?" for the health endpoint.
 */
interface DatabaseCheck
{
    public function isHealthy(): bool;
}
