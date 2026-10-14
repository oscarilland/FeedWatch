<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use PDO;

final class LatencyRepository implements LatencyHistory
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function exchangeId(string $code): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM exchanges WHERE code = ?');
        $statement->execute([$code]);
        $row = $statement->fetch();

        return is_array($row) ? Row::int($row, 'id') : null;
    }

    public function seconds(int $exchangeId, int $fromSecond, int $toSecond): array
    {
        $statement = $this->pdo->prepare(
            'SELECT second, a_count, a_sum_us, a_max_us, b_count, b_sum_us, b_max_us FROM feed_seconds'
            . ' WHERE exchange_id = ? AND second >= ? AND second < ? ORDER BY second'
        );
        $statement->execute([$exchangeId, $fromSecond, $toSecond]);

        $seconds = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $seconds[] = [
                    'second' => Row::int($row, 'second'),
                    'a_count' => Row::int($row, 'a_count'),
                    'a_sum_us' => Row::int($row, 'a_sum_us'),
                    'a_max_us' => Row::int($row, 'a_max_us'),
                    'b_count' => Row::int($row, 'b_count'),
                    'b_sum_us' => Row::int($row, 'b_sum_us'),
                    'b_max_us' => Row::int($row, 'b_max_us'),
                ];
            }
        }

        return $seconds;
    }

    public function minutes(int $exchangeId, int $fromMinute, int $toMinute): array
    {
        $statement = $this->pdo->prepare(
            'SELECT minute, feed, mean_us, p50_us, p95_us, p99_us, max_us FROM feed_minutes'
            . ' WHERE exchange_id = ? AND minute >= ? AND minute < ? ORDER BY minute, feed'
        );
        $statement->execute([$exchangeId, $fromMinute, $toMinute]);

        $minutes = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $minutes[] = [
                    'minute' => Row::int($row, 'minute'),
                    'feed' => Row::string($row, 'feed'),
                    'mean_us' => Row::int($row, 'mean_us'),
                    'p50_us' => Row::int($row, 'p50_us'),
                    'p95_us' => Row::int($row, 'p95_us'),
                    'p99_us' => Row::int($row, 'p99_us'),
                    'max_us' => Row::int($row, 'max_us'),
                ];
            }
        }

        return $minutes;
    }
}
