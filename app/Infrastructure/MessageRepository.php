<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use FeedWatch\Domain\Message;
use PDO;
use PDOStatement;

final class MessageRepository
{
    /** Rows per multi-row INSERT. */
    private const int BATCH = 200;

    private const string INSERT = 'INSERT INTO messages (exchange_id, sequence, message_type, sent_us, a_received_us, b_received_us) VALUES ';
    private const string ROW = '(?, ?, ?, ?, ?, ?)';

    private ?PDOStatement $batchInsert = null;
    private ?PDOStatement $singleInsert = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<Message> $messages
     */
    public function insert(array $messages): void
    {
        foreach (array_chunk($messages, self::BATCH) as $chunk) {
            if (count($chunk) === self::BATCH) {
                $this->batchStatement()->execute(self::values($chunk));
                continue;
            }
            foreach ($chunk as $message) {
                $this->singleStatement()->execute(self::values([$message]));
            }
        }
    }

    /**
     * Deletes messages sent before $cutoffUs, in batches so no single delete
     * holds locks for long.
     *
     * @return int rows deleted
     */
    public function pruneSentBefore(int $cutoffUs, int $batchSize = 5000): int
    {
        $statement = $this->pdo->prepare('DELETE FROM messages WHERE sent_us < ? ORDER BY sent_us LIMIT ?');
        $total = 0;
        do {
            $statement->bindValue(1, $cutoffUs, PDO::PARAM_INT);
            $statement->bindValue(2, $batchSize, PDO::PARAM_INT);
            $statement->execute();
            $deleted = $statement->rowCount();
            $total += $deleted;
        } while ($deleted === $batchSize);

        return $total;
    }

    /**
     * The SQL text is fixed: a constant number of "(?, ?, ...)" groups, built
     * from constants, never from data. Every value is bound.
     */
    private function batchStatement(): PDOStatement
    {
        return $this->batchInsert ??= $this->pdo->prepare(
            self::INSERT . implode(', ', array_fill(0, self::BATCH, self::ROW))
        );
    }

    private function singleStatement(): PDOStatement
    {
        return $this->singleInsert ??= $this->pdo->prepare(self::INSERT . self::ROW);
    }

    /**
     * @param list<Message> $messages
     * @return list<int|string|null>
     */
    private static function values(array $messages): array
    {
        $values = [];
        foreach ($messages as $m) {
            array_push($values, $m->exchangeId, $m->sequence, $m->type->value, $m->sentUs, $m->aReceivedUs, $m->bReceivedUs);
        }

        return $values;
    }
}
