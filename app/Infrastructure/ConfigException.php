<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use RuntimeException;

/**
 * A setting is missing or invalid. Messages name the setting, never its value.
 */
final class ConfigException extends RuntimeException
{
}
