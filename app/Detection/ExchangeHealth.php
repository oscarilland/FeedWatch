<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

final class ExchangeHealth
{
    public function __construct(
        public readonly HealthStatus $status,
        public readonly string $reason,
    ) {
    }
}
