<?php

declare(strict_types=1);

namespace FeedWatch\Domain;

/**
 * What a message carries. Only for realism: detection ignores it.
 */
enum MessageType: string
{
    case Order = 'order';
    case Cancel = 'cancel';
    case Trade = 'trade';
}
