<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * An incident that hasn't closed yet, as the tracker sees it.
 */
final class OpenIncident
{
    public function __construct(
        public readonly int $id,
        public readonly IncidentType $type,
        public readonly string $feed,
        public readonly Severity $severity,
        public readonly int $openedUs,
        public readonly ?int $clearingSinceUs = null,
        public readonly ?int $endHintUs = null,
    ) {
    }

    public function key(): string
    {
        return $this->type->value . ':' . $this->feed;
    }
}
