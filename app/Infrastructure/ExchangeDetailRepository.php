<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use PDO;

final class ExchangeDetailRepository implements ExchangeDetails
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

    public function winShare(int $exchangeId, int $fromSecond, int $toSecond): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(accepted), 0) AS accepted, COALESCE(SUM(a_wins), 0) AS a_wins,'
            . ' COALESCE(SUM(b_wins), 0) AS b_wins, COALESCE(SUM(duplicates), 0) AS duplicates'
            . ' FROM feed_seconds WHERE exchange_id = ? AND second >= ? AND second < ?'
        );
        $statement->execute([$exchangeId, $fromSecond, $toSecond]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return ['accepted' => 0, 'a_wins' => 0, 'b_wins' => 0, 'duplicates' => 0];
        }

        return [
            'accepted' => Row::int($row, 'accepted'),
            'a_wins' => Row::int($row, 'a_wins'),
            'b_wins' => Row::int($row, 'b_wins'),
            'duplicates' => Row::int($row, 'duplicates'),
        ];
    }

    public function recentGaps(int $exchangeId, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT scope, classification, first_sequence, last_sequence, message_count, detected_us FROM gaps'
            . ' WHERE exchange_id = ? ORDER BY detected_us DESC, id DESC LIMIT ?'
        );
        $statement->bindValue(1, $exchangeId, PDO::PARAM_INT);
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->execute();

        $gaps = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $gaps[] = [
                    'scope' => Row::string($row, 'scope'),
                    'classification' => Row::string($row, 'classification'),
                    'first' => Row::int($row, 'first_sequence'),
                    'last' => Row::int($row, 'last_sequence'),
                    'count' => Row::int($row, 'message_count'),
                    'detected_us' => Row::int($row, 'detected_us'),
                ];
            }
        }

        return $gaps;
    }
}
