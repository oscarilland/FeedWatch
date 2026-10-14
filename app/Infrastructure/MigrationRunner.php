<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use PDO;
use RuntimeException;

/**
 * Applies database/migrations/NNN_name.sql files that haven't run yet.
 *
 * A named database lock stops two cron runs applying the same migration.
 * MariaDB commits DDL immediately, so a failed migration is not rolled back:
 * fix it with a new migration, never by editing a deployed one.
 */
final class MigrationRunner
{
    private const string LOCK_NAME = 'feedwatch_migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {
    }

    /**
     * @return list<string>|null the versions applied, or null if another run holds the lock
     */
    public function run(): ?array
    {
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([self::LOCK_NAME]);
        if ((int) $lock->fetchColumn() !== 1) {
            return null;
        }

        try {
            $applied = $this->appliedVersions();
            $done = [];
            foreach ($this->migrationFiles() as $version => $file) {
                if (in_array($version, $applied, true)) {
                    continue;
                }
                foreach (self::statements($this->read($file)) as $statement) {
                    $this->pdo->exec($statement);
                }
                $record = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())'
                );
                $record->execute([$version]);
                $done[] = $version;
            }

            return $done;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([self::LOCK_NAME]);
        }
    }

    /**
     * Splits a migration file into statements: each ends with ";" at the end
     * of a line. Lines starting with "--" are comments.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $code = implode("\n", array_filter($lines, static fn (string $line): bool => !str_starts_with(ltrim($line), '--')));

        $parts = preg_split('/;\s*(?:\n|$)/', $code) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
    }

    /**
     * Every migration version recorded as applied, in order.
     *
     * @return list<string>
     */
    public function appliedVersions(): array
    {
        $exists = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $exists->execute(['schema_migrations']);
        if ((int) $exists->fetchColumn() === 0) {
            return [];
        }

        $versions = $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version');
        if ($versions === false) {
            throw new RuntimeException('Could not read schema_migrations');
        }

        return array_values(array_map('strval', $versions->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @return array<string, string> version => path, in order
     */
    private function migrationFiles(): array
    {
        $files = glob($this->migrationsDir . '/[0-9][0-9][0-9]_*.sql') ?: [];
        sort($files, SORT_STRING);

        $byVersion = [];
        foreach ($files as $file) {
            $byVersion[basename($file, '.sql')] = $file;
        }

        return $byVersion;
    }

    private function read(string $file): string
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Could not read migration ' . basename($file));
        }

        return $sql;
    }
}
