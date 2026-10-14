<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Integration;

use FeedWatch\Infrastructure\Config;
use FeedWatch\Infrastructure\Database;
use FeedWatch\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against a real, disposable MariaDB 10.5 database, set
 * by DB_* environment variables plus FEEDWATCH_TEST_DB=1 as an explicit
 * opt-in. CI provides one; locally they are skipped.
 * Never point these at the live database: they drop tables.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $config = new Config(getenv());
        if (getenv('FEEDWATCH_TEST_DB') !== '1' || $config->get('DB_NAME') === '') {
            self::markTestSkipped('No test database configured (FEEDWATCH_TEST_DB=1 and DB_* not set).');
        }
        $this->pdo = Database::connect($config);
    }

    /**
     * Drops every FeedWatch table and applies the real migrations, so each
     * test starts from the schema production has.
     */
    protected function freshSchema(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS incident_notes, login_attempts, admin_log, chaos_faults, incident_events, incidents, settings, feed_minutes, feed_seconds, feed_silences, gaps, detector_state, messages, exchanges, simulation_state, simulation_pauses, schema_migrations, test_widgets');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        (new MigrationRunner($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
    }

    /**
     * @param list<int|string> $params bound to the query's placeholders
     * @return array<string, mixed>
     */
    protected function row(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        self::assertIsArray($row);

        /** @var array<string, mixed> $row */
        return $row;
    }

    protected function countRows(string $table): int
    {
        // $table comes from test code, never from input; check it's a plain name anyway.
        self::assertMatchesRegularExpression('/^[a-z_]+$/', $table);
        $count = $this->row('SELECT COUNT(*) AS n FROM ' . $table)['n'];
        self::assertIsInt($count);

        return $count;
    }
}
