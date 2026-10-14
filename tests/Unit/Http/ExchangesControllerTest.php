<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use DateTimeImmutable;
use FeedWatch\Detection\ExchangeHealth;
use FeedWatch\Detection\FeedLatency;
use FeedWatch\Detection\HealthStatus;
use FeedWatch\Detection\LatencyLevel;
use FeedWatch\Domain\ExchangeSnapshot;
use FeedWatch\Domain\Feed;
use FeedWatch\Domain\Message;
use FeedWatch\Domain\MessageType;
use FeedWatch\Http\ExchangesController;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\ExchangeSnapshots;
use FeedWatch\Tests\Unit\Simulation\Exchanges;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExchangesControllerTest extends TestCase
{
    private function clock(): Clock
    {
        return new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-04T12:00:00.250+00:00');
            }
        };
    }

    private static function healthy(): ExchangeHealth
    {
        return new ExchangeHealth(HealthStatus::Healthy, 'Healthy');
    }

    /**
     * @param list<ExchangeSnapshot> $snapshots
     */
    private static function source(array $snapshots): ExchangeSnapshots
    {
        return new class ($snapshots) implements ExchangeSnapshots {
            /** @param list<ExchangeSnapshot> $snapshots */
            public function __construct(private readonly array $snapshots)
            {
            }

            public function latest(int $nowUs): array
            {
                return $this->snapshots;
            }

            public function summary(int $nowUs): array
            {
                return ['messages_per_second' => 40, 'messages_lost_today' => 4];
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

    public function test_catches_up_then_returns_each_exchange_with_its_latest_message(): void
    {
        $caughtUp = false;
        $london = Exchanges::london();
        // 2026-10-04T12:00:00.200Z, delivered after 1.85 ms on A; not delivered on B.
        $message = new Message($london->id, 42, MessageType::Trade, 1_791_115_200_200_000, 1_791_115_200_201_850, null);
        $controller = new ExchangesController(
            static function () use (&$caughtUp): void {
                $caughtUp = true;
            },
            static fn (): ExchangeSnapshots => self::source([new ExchangeSnapshot($london, $message, self::healthy(), 0, [], ['A' => new FeedLatency(1_853.4, 1_850, LatencyLevel::Normal), 'B' => new FeedLatency(null, 3_350, LatencyLevel::Normal)]), new ExchangeSnapshot(Exchanges::all()[1], null, new ExchangeHealth(HealthStatus::Degraded, 'Degraded: running on B only'), 0, [Feed::A])]),
            $this->clock(),
        );

        $response = $controller();
        $data = self::decode($response->body);

        self::assertTrue($caughtUp);
        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame('2026-10-04T12:00:00.250+00:00', $data['generated_at']);
        self::assertSame(
            ['exchanges' => 2, 'healthy' => 1, 'open_incidents' => 0, 'messages_per_second' => 40, 'messages_lost_today' => 4],
            $data['summary'],
            'one healthy and one degraded exchange',
        );
        self::assertIsArray($data['exchanges']);
        self::assertSame([
            'code' => 'THX',
            'name' => 'Thames Exchange',
            'city' => 'London',
            'latitude' => 51.5074,
            'longitude' => -0.1278,
            'time_zone' => 'Europe/London',
            'last_sequence' => 42,
            'last_sent_at' => '2026-10-04T12:00:00.200Z',
            'latency_ms' => ['A' => 1.85, 'B' => null],
            'status' => 'healthy',
            'status_reason' => 'Healthy',
            'line_gaps_5m' => 0,
            'silent_feeds' => [],
            'open_incidents' => 0,
            'latency_5s_ms' => ['A' => 1.85, 'B' => null],
            'baseline_ms' => ['A' => 1.85, 'B' => 3.35],
        ], $data['exchanges'][0]);
        self::assertIsArray($data['exchanges'][1]);
        self::assertNull($data['exchanges'][1]['last_sequence']);
        self::assertSame('degraded', $data['exchanges'][1]['status']);
        self::assertSame('Degraded: running on B only', $data['exchanges'][1]['status_reason']);
        self::assertSame(['A'], $data['exchanges'][1]['silent_feeds']);
    }

    public function test_a_failed_catch_up_still_serves_stored_data(): void
    {
        $controller = new ExchangesController(
            static function (): void {
                throw new RuntimeException('lock wait');
            },
            static fn (): ExchangeSnapshots => self::source([new ExchangeSnapshot(Exchanges::london(), null, self::healthy())]),
            $this->clock(),
        );

        $log = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . '/feedwatch-test-error.log');
        try {
            $response = $controller();
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
        }

        self::assertSame(200, $response->status);
    }

    public function test_a_database_failure_is_a_generic_503(): void
    {
        $controller = new ExchangesController(
            static function (): void {
            },
            static function (): ExchangeSnapshots {
                throw new RuntimeException("SQLSTATE[HY000] Access denied for user 'feedwatch_user'");
            },
            $this->clock(),
        );

        $log = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . '/feedwatch-test-error.log');
        try {
            $response = $controller();
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
        }

        self::assertSame(503, $response->status);
        self::assertStringNotContainsString('SQLSTATE', $response->body);
        self::assertStringNotContainsString('feedwatch_user', $response->body);
    }
}
