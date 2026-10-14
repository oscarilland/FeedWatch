<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

enum IncidentType: string
{
    case FeedDown = 'feed_down';
    case Failover = 'failover';
    case DataLoss = 'data_loss';
    case Latency = 'latency';
    case LineGaps = 'line_gaps';
}
