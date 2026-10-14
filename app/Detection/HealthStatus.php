<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * An exchange's overall state, as the dashboard shows it.
 */
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Down = 'down';
}
