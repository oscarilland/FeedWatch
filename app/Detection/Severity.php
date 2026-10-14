<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * Incident severity (FR-06): three levels that read well across a room.
 */
enum Severity: string
{
    case Warning = 'warning';
    case Major = 'major';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Warning => 1,
            self::Major => 2,
            self::Critical => 3,
        };
    }
}
