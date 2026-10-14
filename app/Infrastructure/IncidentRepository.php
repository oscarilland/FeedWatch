<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use PDO;

/**
 * @phpstan-import-type IncidentRow from IncidentLog
 * @phpstan-import-type EventRow from IncidentLog
 */
final class IncidentRepository implements IncidentLog
{
    private const string SELECT = 'SELECT i.id, e.code AS exchange, i.type, i.feed, i.severity, i.opened_us, i.closed_us, i.clearing_since_us, i.details'
        . ' FROM incidents i JOIN exchanges e ON e.id = i.exchange_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(?string $exchangeCode, ?string $type, ?int $fromUs, ?int $toUs, int $limit): array
    {
        // Each filter adds a fixed condition with a placeholder; values are always bound.
        $where = [];
        $params = [];
        if ($exchangeCode !== null) {
            $where[] = 'e.code = ?';
            $params[] = $exchangeCode;
        }
        if ($type !== null) {
            $where[] = 'i.type = ?';
            $params[] = $type;
        }
        if ($fromUs !== null) {
            $where[] = 'i.opened_us >= ?';
            $params[] = $fromUs;
        }
        if ($toUs !== null) {
            $where[] = 'i.opened_us < ?';
            $params[] = $toUs;
        }

        $statement = $this->pdo->prepare(
            self::SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY i.opened_us DESC, i.id DESC LIMIT ?'
        );
        foreach ($params as $i => $value) {
            $statement->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $statement->execute();

        $incidents = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $incidents[] = self::incident($row);
            }
        }

        return $incidents;
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE i.id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        $events = $this->pdo->prepare('SELECT at_us, kind, text FROM incident_events WHERE incident_id = ? ORDER BY id');
        $events->execute([$id]);
        $timeline = [];
        foreach ($events->fetchAll() as $event) {
            if (is_array($event)) {
                $timeline[] = ['at_us' => Row::int($event, 'at_us'), 'kind' => Row::string($event, 'kind'), 'text' => Row::string($event, 'text')];
            }
        }

        $notes = $this->pdo->prepare('SELECT at_us, username, text FROM incident_notes WHERE incident_id = ? ORDER BY id');
        $notes->execute([$id]);
        $noteRows = [];
        foreach ($notes->fetchAll() as $note) {
            if (is_array($note)) {
                $noteRows[] = ['at_us' => Row::int($note, 'at_us'), 'user' => Row::string($note, 'username'), 'text' => Row::string($note, 'text')];
            }
        }

        return ['incident' => self::incident($row), 'events' => $timeline, 'notes' => $noteRows];
    }

    public function addNote(int $incidentId, string $user, string $text, int $atUs): void
    {
        $statement = $this->pdo->prepare('INSERT INTO incident_notes (incident_id, at_us, username, text) VALUES (?, ?, ?, ?)');
        $statement->execute([$incidentId, $atUs, mb_substr($user, 0, 64), mb_substr($text, 0, 500)]);
    }

    /**
     * @param array<array-key, mixed> $row
     * @return IncidentRow
     */
    private static function incident(array $row): array
    {
        return [
            'id' => Row::int($row, 'id'),
            'exchange' => Row::string($row, 'exchange'),
            'type' => Row::string($row, 'type'),
            'feed' => Row::string($row, 'feed'),
            'severity' => Row::string($row, 'severity'),
            'opened_us' => Row::int($row, 'opened_us'),
            'closed_us' => Row::nullableInt($row, 'closed_us'),
            'clearing_since_us' => Row::nullableInt($row, 'clearing_since_us'),
            'details' => IncidentRunner::decodeDetails(Row::string($row, 'details')),
        ];
    }
}
