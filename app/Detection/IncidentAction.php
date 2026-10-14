<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * One change the tracker wants made to the incident log.
 *
 * - open: $condition is new; record it from $atUs.
 * - update: $incidentId's condition is still active; refresh its details.
 * - escalate: raise $incidentId to $condition's severity.
 * - clear: $incidentId's condition has gone, as of $atUs.
 * - return: $incidentId's condition came back within the merge window.
 * - close: $incidentId ends at $atUs.
 */
final class IncidentAction
{
    public function __construct(
        public readonly string $kind,
        public readonly int $atUs,
        public readonly ?int $incidentId = null,
        public readonly ?Condition $condition = null,
        public readonly ?int $openedUs = null,
    ) {
    }
}
