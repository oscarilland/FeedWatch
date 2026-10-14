<?php

declare(strict_types=1);

namespace FeedWatch\Http;

enum LoginResult
{
    case Ok;
    case Invalid;
    case Locked;
    case NotConfigured;
}
