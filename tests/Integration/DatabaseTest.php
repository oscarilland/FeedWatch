<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Integration;

use FeedWatch\Infrastructure\PdoDatabaseCheck;
use PDO;

final class DatabaseTest extends DatabaseTestCase
{
    public function test_connection_uses_strict_mode_and_utc(): void
    {
        $row = $this->row('SELECT @@SESSION.sql_mode AS mode, @@SESSION.time_zone AS tz');

        self::assertIsString($row['mode']);
        self::assertStringContainsString('STRICT_TRANS_TABLES', $row['mode']);
        self::assertSame('+00:00', $row['tz']);
    }

    public function test_emulated_prepares_are_off(): void
    {
        self::assertFalse($this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    public function test_health_check_reports_a_working_database(): void
    {
        $pdo = $this->pdo;

        self::assertTrue((new PdoDatabaseCheck(static fn (): PDO => $pdo))->isHealthy());
    }
}
