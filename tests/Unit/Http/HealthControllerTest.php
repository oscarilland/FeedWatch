<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use DateTimeImmutable;
use FeedWatch\Http\HealthController;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\DatabaseCheck;
use FeedWatch\Infrastructure\PdoDatabaseCheck;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    private function clock(): Clock
    {
        return new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-04T12:00:00+00:00');
            }
        };
    }

    private function database(bool $healthy): DatabaseCheck
    {
        return new class ($healthy) implements DatabaseCheck {
            public function __construct(private readonly bool $healthy)
            {
            }

            public function isHealthy(): bool
            {
                return $this->healthy;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function test_reports_ok_with_a_working_database(): void
    {
        $response = (new HealthController('abc1234', $this->database(true), $this->clock(), 80426))();

        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame([
            'app' => 'FeedWatch',
            'version' => 'abc1234',
            'db' => 'ok',
            'runtime_ok' => true,
            'time' => '2026-10-04T12:00:00+00:00',
        ], self::decode($response->body));
    }

    public function test_reports_error_and_503_without_a_database(): void
    {
        $response = (new HealthController('abc1234', $this->database(false), $this->clock(), 80426))();

        self::assertSame(503, $response->status);
        self::assertSame('error', self::decode($response->body)['db']);
    }

    public function test_runtime_not_ok_below_php_8_4_and_version_not_revealed(): void
    {
        $response = (new HealthController('abc1234', $this->database(true), $this->clock(), 70433))();

        self::assertFalse(self::decode($response->body)['runtime_ok']);
        self::assertStringNotContainsString('7.4', $response->body);
    }

    public function test_database_error_detail_is_not_leaked(): void
    {
        $failing = new PdoDatabaseCheck(static function (): PDO {
            throw new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'feedwatch_user'@'localhost'");
        });

        $log = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . '/feedwatch-test-error.log');
        try {
            $response = (new HealthController('abc1234', $failing, $this->clock(), 80426))();
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
        }

        self::assertSame('error', self::decode($response->body)['db']);
        self::assertStringNotContainsString('feedwatch_user', $response->body);
        self::assertStringNotContainsString('SQLSTATE', $response->body);
    }
}
