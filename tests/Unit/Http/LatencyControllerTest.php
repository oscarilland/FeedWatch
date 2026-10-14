<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use DateTimeImmutable;
use FeedWatch\Http\LatencyController;
use FeedWatch\Http\Request;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\LatencyHistory;
use PHPUnit\Framework\TestCase;

final class LatencyControllerTest extends TestCase
{
    /** 2026-10-04T12:00:00Z */
    private const int NOW_SECOND = 1_791_115_200;

    private RecordingLatencyHistory $history;

    protected function setUp(): void
    {
        $this->history = new RecordingLatencyHistory();
    }

    private function controller(): LatencyController
    {
        $history = $this->history;
        $clock = new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@1791115200.500');
            }
        };

        return new LatencyController(static fn (): LatencyHistory => $history, $clock);
    }

    /**
     * @return list<array{int, int, int}>
     */
    private function secondsCalls(): array
    {
        return $this->history->secondsCalls;
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

    public function test_returns_the_last_fifteen_minutes_in_milliseconds(): void
    {
        $response = ($this->controller())(new Request('GET', '/', ['exchange' => 'THX']));
        $data = self::decode($response->body);

        self::assertSame(200, $response->status);
        self::assertSame([[1, self::NOW_SECOND - 900, self::NOW_SECOND]], $this->secondsCalls());
        self::assertSame([['t' => self::NOW_SECOND - 1, 'A' => ['mean_ms' => 2.05, 'max_ms' => 2.3], 'B' => null]], $data['seconds']);
        self::assertSame([[
            't' => (intdiv(self::NOW_SECOND, 60) - 1) * 60,
            'A' => ['mean_ms' => 2.05, 'p50_ms' => 2.0, 'p95_ms' => 2.25, 'p99_ms' => 2.29, 'max_ms' => 9.1],
            'B' => null,
        ]], $data['minutes']);
    }

    public function test_since_fetches_only_newer_seconds(): void
    {
        ($this->controller())(new Request('GET', '/', ['exchange' => 'THX', 'since' => (string) (self::NOW_SECOND - 10)]));

        self::assertSame([[1, self::NOW_SECOND - 9, self::NOW_SECOND]], $this->secondsCalls());
    }

    public function test_bad_input_is_a_400_that_does_not_echo_the_input(): void
    {
        foreach ([[], ['exchange' => 'thx'], ['exchange' => "THX'--"], ['exchange' => 'THX', 'since' => 'yesterday']] as $query) {
            $response = ($this->controller())(new Request('GET', '/', $query));
            self::assertSame(400, $response->status, json_encode($query, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString("'--", $response->body);
        }
        self::assertSame([], $this->secondsCalls(), 'nothing read for bad input');
    }

    public function test_an_unknown_exchange_is_a_400(): void
    {
        $response = ($this->controller())(new Request('GET', '/', ['exchange' => 'ZZZ']));

        self::assertSame(400, $response->status);
        self::assertSame('{"error":"Unknown exchange"}', $response->body);
    }
}
