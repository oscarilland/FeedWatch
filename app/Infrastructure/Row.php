<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use UnexpectedValueException;

/**
 * Typed reads from a database row, failing loudly if a column is missing or
 * has the wrong type rather than letting a bad value travel further.
 */
final class Row
{
    /**
     * @param array<array-key, mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new UnexpectedValueException("Column {$column} is not an integer");
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function nullableInt(array $row, string $column): ?int
    {
        return ($row[$column] ?? null) === null ? null : self::int($row, $column);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException("Column {$column} is not a string");
        }

        return $value;
    }

    /**
     * DECIMAL columns arrive as strings.
     *
     * @param array<array-key, mixed> $row
     */
    public static function float(array $row, string $column): float
    {
        $value = $row[$column] ?? null;
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new UnexpectedValueException("Column {$column} is not a number");
    }
}
