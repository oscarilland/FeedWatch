<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use FeedWatch\Detection\Conditions;
use FeedWatch\Detection\Duration;
use FeedWatch\Detection\IncidentAction;
use FeedWatch\Detection\IncidentTracker;
use FeedWatch\Detection\IncidentType;
use FeedWatch\Detection\OpenIncident;
use FeedWatch\Detection\Severity;
use FeedWatch\Detection\Thresholds;
use PDO;
use RuntimeException;

/**
 * Keeps the incident log in step with the current conditions (FR-06). Called
 * by SimulationRunner after detection, inside its lock and transaction.
 */
final class IncidentRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Thresholds $thresholds,
    ) {
    }

    /**
     * @return int incident changes made
     */
    public function run(int $nowUs): int
    {
        $health = (new HealthReader($this->pdo, $this->thresholds))->read($nowUs);
        [$open, $details] = $this->openIncidents();

        $changes = 0;
        foreach ($health as $exchangeId => $data) {
            $actions = IncidentTracker::track(
                $open[$exchangeId] ?? [],
                Conditions::from($data, $nowUs, $this->thresholds),
                $nowUs,
                $this->thresholds->incidentMergeUs,
            );
            $byId = [];
            foreach ($open[$exchangeId] ?? [] as $incident) {
                $byId[$incident->id] = $incident;
            }
            foreach ($actions as $action) {
                $this->apply($exchangeId, $action, $byId, $details);
                $changes++;
            }
        }

        return $changes;
    }

    /**
     * @param array<int, OpenIncident> $byId
     * @param array<int, array<string, int>> $details
     */
    private function apply(int $exchangeId, IncidentAction $action, array $byId, array $details): void
    {
        $condition = $action->condition;
        $id = $action->incidentId;

        switch ($action->kind) {
            case 'open':
                if ($condition === null) {
                    return;
                }
                $openDetails = $condition->details;
                if ($condition->type === IncidentType::DataLoss) {
                    $openDetails['messages_lost'] = $this->messagesLostSince($exchangeId, $action->atUs);
                }
                $insert = $this->pdo->prepare(
                    'INSERT INTO incidents (exchange_id, type, feed, severity, opened_us, end_hint_us, details) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $exchangeId,
                    $condition->type->value,
                    $condition->feed,
                    $condition->severity->value,
                    $action->atUs,
                    $condition->endHintUs,
                    json_encode($openDetails, JSON_THROW_ON_ERROR),
                ]);
                $this->event((int) $this->pdo->lastInsertId(), $action->atUs, 'opened', $condition->description);
                break;

            case 'update':
                if ($condition === null || $id === null || !isset($byId[$id])) {
                    return;
                }
                // Keep the worst value seen, so details show the peak.
                $merged = $details[$id] ?? [];
                foreach ($condition->details as $key => $value) {
                    $merged[$key] = max($merged[$key] ?? $value, $value);
                }
                if ($condition->type === IncidentType::DataLoss) {
                    $merged['messages_lost'] = $this->messagesLostSince($exchangeId, $byId[$id]->openedUs);
                }
                $update = $this->pdo->prepare('UPDATE incidents SET details = ?, end_hint_us = COALESCE(?, end_hint_us) WHERE id = ?');
                $update->execute([json_encode($merged, JSON_THROW_ON_ERROR), $condition->endHintUs, $id]);
                break;

            case 'escalate':
                if ($condition === null || $id === null) {
                    return;
                }
                $escalate = $this->pdo->prepare('UPDATE incidents SET severity = ? WHERE id = ?');
                $escalate->execute([$condition->severity->value, $id]);
                $this->event($id, $action->atUs, 'escalated', "Escalated to {$condition->severity->value}: {$condition->description}");
                break;

            case 'clear':
                if ($id === null) {
                    return;
                }
                $clear = $this->pdo->prepare('UPDATE incidents SET clearing_since_us = ? WHERE id = ?');
                $clear->execute([$action->atUs, $id]);
                $this->event($id, $action->atUs, 'cleared', 'Condition cleared');
                break;

            case 'return':
                if ($id === null) {
                    return;
                }
                $return = $this->pdo->prepare('UPDATE incidents SET clearing_since_us = NULL WHERE id = ?');
                $return->execute([$id]);
                $this->event($id, $action->atUs, 'returned', 'Condition returned within the merge window');
                break;

            case 'close':
                if ($id === null) {
                    return;
                }
                $close = $this->pdo->prepare('UPDATE incidents SET closed_us = ?, clearing_since_us = NULL WHERE id = ?');
                $close->execute([$action->atUs, $id]);
                $this->event($id, $action->atUs, 'closed', 'Closed after ' . Duration::format($action->atUs - (int) $action->openedUs));
                break;

            default:
                throw new RuntimeException("Unknown incident action {$action->kind}");
        }
    }

    /**
     * @return array{array<int, list<OpenIncident>>, array<int, array<string, int>>} open incidents by exchange, and details by incident id
     */
    private function openIncidents(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, exchange_id, type, feed, severity, opened_us, clearing_since_us, end_hint_us, details FROM incidents WHERE closed_us IS NULL ORDER BY id'
        );
        $open = [];
        $details = [];
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = Row::int($row, 'id');
            $open[Row::int($row, 'exchange_id')][] = new OpenIncident(
                $id,
                IncidentType::from(Row::string($row, 'type')),
                Row::string($row, 'feed'),
                Severity::from(Row::string($row, 'severity')),
                Row::int($row, 'opened_us'),
                Row::nullableInt($row, 'clearing_since_us'),
                Row::nullableInt($row, 'end_hint_us'),
            );
            $details[$id] = self::decodeDetails(Row::string($row, 'details'));
        }

        return [$open, $details];
    }

    private function messagesLostSince(int $exchangeId, int $sinceUs): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(message_count), 0) AS n FROM gaps WHERE exchange_id = ? AND classification = 'data_loss' AND detected_us >= ?"
        );
        $statement->execute([$exchangeId, $sinceUs]);
        $row = $statement->fetch();

        return is_array($row) ? Row::int($row, 'n') : 0;
    }

    private function event(int $incidentId, int $atUs, string $kind, string $text): void
    {
        $statement = $this->pdo->prepare('INSERT INTO incident_events (incident_id, at_us, kind, text) VALUES (?, ?, ?, ?)');
        $statement->execute([$incidentId, $atUs, $kind, mb_substr($text, 0, 200)]);
    }

    /**
     * @return array<string, int>
     */
    public static function decodeDetails(string $json): array
    {
        $decoded = json_decode($json, true);
        $details = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key) && is_int($value)) {
                $details[$key] = $value;
            }
        }

        return $details;
    }
}
