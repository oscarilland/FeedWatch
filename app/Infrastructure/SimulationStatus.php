<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use PDO;

/**
 * Idle mode and storage checks (plan 004), both there to keep FeedWatch's
 * load and footprint on the shared server small.
 */
final class SimulationStatus
{
    /** Write the "last viewed" time at most this often, so polling stays cheap. */
    private const int VIEW_WRITE_INTERVAL_US = 60_000_000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Records that someone is looking. A no-op unless the stored time is over
     * a minute old, so most polls change nothing.
     */
    public function markViewed(int $nowUs): void
    {
        $statement = $this->pdo->prepare('UPDATE simulation_state SET last_viewed_us = ? WHERE id = 1 AND last_viewed_us < ?');
        $statement->execute([$nowUs, $nowUs - self::VIEW_WRITE_INTERVAL_US]);
    }

    /**
     * @return int|null microseconds since the data was last viewed, or null if it never has been
     */
    public function idleForUs(int $nowUs): ?int
    {
        $statement = $this->pdo->query('SELECT last_viewed_us FROM simulation_state WHERE id = 1');
        $row = $statement === false ? false : $statement->fetch();
        $lastViewed = is_array($row) ? Row::int($row, 'last_viewed_us') : 0;

        return $lastViewed === 0 ? null : $nowUs - $lastViewed;
    }

    /**
     * Total size of this database's tables and indexes, in bytes.
     */
    public function databaseBytes(): int
    {
        $statement = $this->pdo->query(
            'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
        $row = $statement === false ? false : $statement->fetch();

        return is_array($row) ? Row::int($row, 'bytes') : 0;
    }
}
