<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use FeedWatch\Detection\Thresholds;
use PDO;
use Throwable;

final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The current thresholds. Rejected values are logged and replaced by
     * their defaults; if the table can't be read at all, the defaults are used.
     */
    /**
     * The stored values, as text, by name.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        $statement = $this->pdo->query('SELECT name, value FROM settings ORDER BY name');
        $values = [];
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            if (is_array($row)) {
                $values[Row::string($row, 'name')] = Row::string($row, 'value');
            }
        }

        return $values;
    }

    /**
     * Stores one value. The caller has already checked it against Thresholds' rules.
     */
    public function update(string $name, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $statement->execute([$name, $value]);
    }

    public function thresholds(): Thresholds
    {
        try {
            $statement = $this->pdo->query('SELECT name, value FROM settings');
            $settings = [];
            foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
                if (is_array($row)) {
                    $settings[Row::string($row, 'name')] = Row::string($row, 'value');
                }
            }
        } catch (Throwable $e) {
            error_log('FeedWatch: could not read settings, using defaults: ' . $e::class . ': ' . $e->getMessage());

            return Thresholds::defaults();
        }

        [$thresholds, $warnings] = Thresholds::fromSettings($settings);
        foreach ($warnings as $warning) {
            error_log('FeedWatch: ' . $warning);
        }

        return $thresholds;
    }
}
