<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use PDO;

/**
 * Opens PDO connections to MariaDB with FeedWatch's session settings.
 */
final class Database
{
    public static function connect(Config $config): PDO
    {
        $charset = $config->get('DB_CHARSET', 'utf8mb4');
        // The charset goes into the DSN, so only accept the one we support.
        if ($charset !== 'utf8mb4') {
            throw new ConfigException('Setting DB_CHARSET must be utf8mb4');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config->require('DB_HOST'),
            $config->requireInt('DB_PORT', 3306),
            $config->require('DB_NAME'),
            $charset,
        );

        $pdo = new PDO($dsn, $config->require('DB_USER'), $config->require('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real server-side prepares: parameters never pass through PHP's own escaping.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // The host defaults to non-strict mode and CEST. Store and compare in UTC,
        // and make bad data an error rather than a silent truncation.
        $pdo->exec("SET SESSION sql_mode = 'TRADITIONAL', time_zone = '+00:00'");

        return $pdo;
    }
}
