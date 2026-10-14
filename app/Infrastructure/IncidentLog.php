<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

/**
 * Read access to the incident log, for the API (FR-06 AC-3, AC-4).
 *
 * @phpstan-type IncidentRow array{id: int, exchange: string, type: string, feed: string, severity: string, opened_us: int, closed_us: ?int, clearing_since_us: ?int, details: array<string, int>}
 * @phpstan-type EventRow array{at_us: int, kind: string, text: string}
 * @phpstan-type NoteRow array{at_us: int, user: string, text: string}
 */
interface IncidentLog
{
    /**
     * Adds an admin's note to an incident (FR-06).
     */
    public function addNote(int $incidentId, string $user, string $text, int $atUs): void;

    /**
     * Newest first. Every filter is optional.
     *
     * @return list<IncidentRow>
     */
    public function search(?string $exchangeCode, ?string $type, ?int $fromUs, ?int $toUs, int $limit): array;

    /**
     * @return array{incident: IncidentRow, events: list<EventRow>, notes: list<NoteRow>}|null
     */
    public function find(int $id): ?array;
}
