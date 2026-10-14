<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * An alert condition active right now on one exchange. Its key (type and
 * feed) is what makes it "the same" incident from one catch-up to the next.
 */
final class Condition
{
    /**
     * @param string $feed "A", "B" or "AB"
     * @param ?int $endHintUs when the condition really ended, if that's an event (the last message lost)
     * @param array<string, int> $details for example messages_lost or peak_latency_us
     */
    public function __construct(
        public readonly IncidentType $type,
        public readonly string $feed,
        public readonly Severity $severity,
        public readonly int $startedUs,
        public readonly string $description,
        public readonly ?int $endHintUs = null,
        public readonly array $details = [],
    ) {
    }

    public function key(): string
    {
        return $this->type->value . ':' . $this->feed;
    }
}
