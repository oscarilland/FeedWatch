<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

enum GapClassification: string
{
    /** Lost on one feed, delivered on the other: redundancy lost, no data lost. */
    case LineGap = 'line_gap';

    /** Lost on both feeds: the messages are gone. Critical. */
    case DataLoss = 'data_loss';
}
