<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use FeedWatch\Detection\StatusRules;
use FeedWatch\Detection\Thresholds;
use FeedWatch\Domain\Exchange;
use FeedWatch\Domain\Feed;
use FeedWatch\Domain\ExchangeSnapshot;
use FeedWatch\Domain\Message;
use FeedWatch\Domain\MessageType;
use PDO;
use RuntimeException;

final class ExchangeRepository implements ExchangeSnapshots
{
    private const string COLUMNS = 'e.id, e.code, e.name, e.city, e.latitude, e.longitude, e.time_zone, e.latency_offset_us, e.next_sequence';

    private readonly Thresholds $thresholds;

    public function __construct(private readonly PDO $pdo, ?Thresholds $thresholds = null)
    {
        $this->thresholds = $thresholds ?? Thresholds::defaults();
    }

    /**
     * Every exchange with the next sequence number it will send, in id order.
     * Call inside a transaction with $forUpdate to lock the rows.
     *
     * @return list<array{exchange: Exchange, nextSequence: int}>
     */
    public function allWithNextSequence(bool $forUpdate = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM exchanges e ORDER BY e.id' . ($forUpdate ? ' FOR UPDATE' : '');
        $result = [];
        foreach ($this->rows($sql) as $row) {
            $result[] = ['exchange' => self::exchange($row), 'nextSequence' => Row::int($row, 'next_sequence')];
        }

        return $result;
    }

    public function setNextSequence(int $exchangeId, int $nextSequence): void
    {
        $statement = $this->pdo->prepare('UPDATE exchanges SET next_sequence = ? WHERE id = ?');
        $statement->execute([$nextSequence, $exchangeId]);
    }

    public function latest(int $nowUs): array
    {
        $health = (new HealthReader($this->pdo, $this->thresholds))->read($nowUs);
        $openIncidents = [];
        foreach ($this->rows('SELECT exchange_id, COUNT(*) AS n FROM incidents WHERE closed_us IS NULL GROUP BY exchange_id') as $row) {
            $openIncidents[Row::int($row, 'exchange_id')] = Row::int($row, 'n');
        }

        $rows = $this->rows(
            'SELECT ' . self::COLUMNS . ', m.sequence, m.message_type, m.sent_us, m.a_received_us, m.b_received_us'
            . ' FROM exchanges e'
            . ' LEFT JOIN messages m ON m.exchange_id = e.id AND m.sequence = e.next_sequence - 1'
            . ' ORDER BY e.id'
        );

        $snapshots = [];
        foreach ($rows as $row) {
            $message = null;
            if (($row['sequence'] ?? null) !== null) {
                $message = new Message(
                    Row::int($row, 'id'),
                    Row::int($row, 'sequence'),
                    MessageType::from(Row::string($row, 'message_type')),
                    Row::int($row, 'sent_us'),
                    Row::nullableInt($row, 'a_received_us'),
                    Row::nullableInt($row, 'b_received_us'),
                );
            }
            $exchange = self::exchange($row);
            $id = $exchange->id;

            $data = $health[$id] ?? null;
            if ($data === null) {
                continue;
            }
            $snapshots[] = new ExchangeSnapshot(
                $exchange,
                $message,
                StatusRules::evaluate($data->inputs, $this->thresholds),
                $data->inputs->lineGapsA + $data->inputs->lineGapsB,
                array_map(static fn (string $feed): Feed => Feed::from($feed), array_keys($data->silentSinceUs)),
                $data->latency,
                $openIncidents[$id] ?? 0,
            );
        }

        return $snapshots;
    }

    public function summary(int $nowUs): array
    {
        // Averaged over the 5 seconds before the last: the latest second may not
        // be fully counted until the next catch-up, which made the tile flicker.
        $lastSecond = intdiv($nowUs, 1_000_000) - 1;
        $rate = $this->pdo->prepare('SELECT COALESCE(SUM(accepted), 0) AS n FROM feed_seconds WHERE second >= ? AND second < ?');
        $rate->execute([$lastSecond - 5, $lastSecond]);
        $rateRow = $rate->fetch();

        $midnightUs = intdiv($nowUs, 86_400_000_000) * 86_400_000_000;
        $lost = $this->pdo->prepare(
            "SELECT COALESCE(SUM(message_count), 0) AS n FROM gaps WHERE classification = 'data_loss' AND detected_us >= ?"
        );
        $lost->execute([$midnightUs]);
        $lostRow = $lost->fetch();

        return [
            'messages_per_second' => is_array($rateRow) ? (int) round(Row::int($rateRow, 'n') / 5) : 0,
            'messages_lost_today' => is_array($lostRow) ? Row::int($lostRow, 'n') : 0,
        ];
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function exchange(array $row): Exchange
    {
        return new Exchange(
            Row::int($row, 'id'),
            Row::string($row, 'code'),
            Row::string($row, 'name'),
            Row::string($row, 'city'),
            Row::float($row, 'latitude'),
            Row::float($row, 'longitude'),
            Row::string($row, 'time_zone'),
            Row::int($row, 'latency_offset_us'),
        );
    }

    /**
     * Runs a fixed query (no parameters) and returns its rows.
     *
     * @return list<array<array-key, mixed>>
     */
    private function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Query failed');
        }

        return array_values(array_filter($statement->fetchAll(), 'is_array'));
    }
}
