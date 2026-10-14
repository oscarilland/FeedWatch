<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use PDO;

/**
 * Stored faults and the admin log (FR-07).
 *
 * @phpstan-type FaultRow array{exchange_id: int, exchange: string, feed: string, kind: string, value: int, burst_from: ?int, set_by: string, set_us: int, expires_us: int}
 */
final class ChaosRepository
{
    private const string SELECT = 'SELECT f.exchange_id, e.code AS exchange, f.feed, f.kind, f.value, f.burst_from_sequence, f.set_by, f.set_us, f.expires_us'
        . ' FROM chaos_faults f JOIN exchanges e ON e.id = f.exchange_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{int, int}|null the exchange's id and next sequence number, or null if there's no such exchange
     */
    public function exchange(string $code): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, next_sequence FROM exchanges WHERE code = ?');
        $statement->execute([$code]);
        $row = $statement->fetch();

        return is_array($row) ? [Row::int($row, 'id'), Row::int($row, 'next_sequence')] : null;
    }

    /**
     * Sets a fault, replacing one of the same kind on the same feed.
     */
    public function set(int $exchangeId, string $feed, string $kind, int $value, ?int $burstFrom, string $user, int $nowUs, int $expiresUs): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO chaos_faults (exchange_id, feed, kind, value, burst_from_sequence, set_by, set_us, expires_us) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE value = VALUES(value), burst_from_sequence = VALUES(burst_from_sequence),'
            . ' set_by = VALUES(set_by), set_us = VALUES(set_us), expires_us = VALUES(expires_us)'
        );
        $statement->execute([$exchangeId, $feed, $kind, $value, $burstFrom, $user, $nowUs, $expiresUs]);
    }

    public function restoreExchange(int $exchangeId): int
    {
        $statement = $this->pdo->prepare('DELETE FROM chaos_faults WHERE exchange_id = ?');
        $statement->execute([$exchangeId]);

        return $statement->rowCount();
    }

    public function restoreAll(): int
    {
        $count = $this->pdo->exec('DELETE FROM chaos_faults');

        return $count === false ? 0 : $count;
    }

    /**
     * @return list<FaultRow>
     */
    public function active(int $nowUs): array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE f.expires_us > ? ORDER BY e.id, f.feed, f.kind');
        $statement->execute([$nowUs]);

        return self::faults($statement->fetchAll());
    }

    /**
     * Deletes expired faults (FR-07 AC-4) and returns them, so cron can log each one.
     *
     * @return list<FaultRow>
     */
    public function expire(int $nowUs): array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE f.expires_us <= ?');
        $statement->execute([$nowUs]);
        $expired = self::faults($statement->fetchAll());
        if ($expired !== []) {
            $delete = $this->pdo->prepare('DELETE FROM chaos_faults WHERE expires_us <= ?');
            $delete->execute([$nowUs]);
        }

        return $expired;
    }

    /**
     * Records an admin action (FR-07 AC-2): who, what, which target, when.
     */
    public function log(string $user, string $action, string $target, string $details, int $nowUs): void
    {
        $statement = $this->pdo->prepare('INSERT INTO admin_log (at_us, username, action, target, details) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$nowUs, mb_substr($user, 0, 64), mb_substr($action, 0, 40), mb_substr($target, 0, 100), mb_substr($details, 0, 500)]);
    }

    /**
     * @return list<array{at_us: int, username: string, action: string, target: string, details: string}>
     */
    public function recentLog(int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT at_us, username, action, target, details FROM admin_log ORDER BY at_us DESC, id DESC LIMIT ?');
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->execute();

        $log = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $log[] = [
                    'at_us' => Row::int($row, 'at_us'),
                    'username' => Row::string($row, 'username'),
                    'action' => Row::string($row, 'action'),
                    'target' => Row::string($row, 'target'),
                    'details' => Row::string($row, 'details'),
                ];
            }
        }

        return $log;
    }

    /**
     * @param array<array-key, mixed> $rows
     * @return list<FaultRow>
     */
    private static function faults(array $rows): array
    {
        $faults = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $faults[] = [
                    'exchange_id' => Row::int($row, 'exchange_id'),
                    'exchange' => Row::string($row, 'exchange'),
                    'feed' => Row::string($row, 'feed'),
                    'kind' => Row::string($row, 'kind'),
                    'value' => Row::int($row, 'value'),
                    'burst_from' => Row::nullableInt($row, 'burst_from_sequence'),
                    'set_by' => Row::string($row, 'set_by'),
                    'set_us' => Row::int($row, 'set_us'),
                    'expires_us' => Row::int($row, 'expires_us'),
                ];
            }
        }

        return $faults;
    }
}
