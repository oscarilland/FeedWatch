<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * How far a feed's latency is above its normal level, sustained (FR-04).
 */
enum LatencyLevel: string
{
    case Normal = 'normal';
    case High = 'high';
    case VeryHigh = 'very_high';
}
