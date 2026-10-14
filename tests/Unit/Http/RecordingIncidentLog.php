<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use FeedWatch\Infrastructure\IncidentLog;

/**
 * One ongoing latency incident (id 7) opened at 12:00:00, recording searches.
 */
final class RecordingIncidentLog implements IncidentLog
{
    /** @var list<array{?string, ?string, ?int, ?int, int}> */
    public array $searches = [];

    public function search(?string $exchangeCode, ?string $type, ?int $fromUs, ?int $toUs, int $limit): array
    {
        $this->searches[] = [$exchangeCode, $type, $fromUs, $toUs, $limit];

        return [self::incident()];
    }

    /** @var list<array{int, string, string}> */
    public array $notesAdded = [];

    public function addNote(int $incidentId, string $user, string $text, int $atUs): void
    {
        $this->notesAdded[] = [$incidentId, $user, $text];
    }

    public function find(int $id): ?array
    {
        if ($id !== 7) {
            return null;
        }

        return [
            'incident' => self::incident(),
            'events' => [
                ['at_us' => 1_791_115_200_000_000, 'kind' => 'opened', 'text' => 'High latency on A'],
                ['at_us' => 1_791_115_220_000_000, 'kind' => 'escalated', 'text' => 'Escalated to critical: Very high latency on A'],
            ],
            'notes' => [
                ['at_us' => 1_791_115_230_000_000, 'user' => 'admin', 'text' => 'Chaos test: <b>delay</b> on Tokyo A'],
            ],
        ];
    }

    /**
     * @return array{id: int, exchange: string, type: string, feed: string, severity: string, opened_us: int, closed_us: ?int, clearing_since_us: ?int, details: array<string, int>}
     */
    private static function incident(): array
    {
        return [
            'id' => 7,
            'exchange' => 'THX',
            'type' => 'latency',
            'feed' => 'A',
            'severity' => 'critical',
            'opened_us' => 1_791_115_200_000_000,
            'closed_us' => null,
            'clearing_since_us' => null,
            'details' => ['peak_latency_us' => 43_400],
        ];
    }
}
