<?php

declare(strict_types=1);

namespace FeedWatch\Domain;

/**
 * The two redundant routes every message travels on. A is normally faster.
 */
enum Feed: string
{
    case A = 'A';
    case B = 'B';
}
