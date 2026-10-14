<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Integration;

use FeedWatch\Infrastructure\MigrationRunner;

final class MigrationRunnerTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS test_widgets, schema_migrations');

        $this->dir = sys_get_temp_dir() . '/feedwatch-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        copy(dirname(__DIR__, 2) . '/database/migrations/001_create_schema_migrations.sql', $this->dir . '/001_create_schema_migrations.sql');
        file_put_contents($this->dir . '/002_create_test_widgets.sql', "CREATE TABLE test_widgets (id INT PRIMARY KEY);\nINSERT INTO test_widgets VALUES (1);\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS test_widgets, schema_migrations');
        }
    }

    public function test_applies_pending_migrations_in_order(): void
    {
        $applied = (new MigrationRunner($this->pdo, $this->dir))->run();

        self::assertSame(['001_create_schema_migrations', '002_create_test_widgets'], $applied);
        self::assertSame(1, $this->countRows('test_widgets'));
    }

    public function test_running_twice_is_a_no_op(): void
    {
        $runner = new MigrationRunner($this->pdo, $this->dir);
        $runner->run();

        self::assertSame([], $runner->run());
        self::assertSame(1, $this->countRows('test_widgets'));
    }

    public function test_only_new_migrations_run_later(): void
    {
        $runner = new MigrationRunner($this->pdo, $this->dir);
        $runner->run();
        file_put_contents($this->dir . '/003_add_widget.sql', "INSERT INTO test_widgets VALUES (2);\n");

        self::assertSame(['003_add_widget'], $runner->run());
        self::assertSame(
            ['001_create_schema_migrations', '002_create_test_widgets', '003_add_widget'],
            $runner->appliedVersions(),
        );
    }
}
