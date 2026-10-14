<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use DateTimeImmutable;
use FeedWatch\Http\IncidentsController;
use FeedWatch\Http\Request;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\IncidentLog;
use PHPUnit\Framework\TestCase;

final class IncidentsControllerTest extends TestCase
{
    private RecordingIncidentLog $log;

    protected function setUp(): void
    {
        $this->log = new RecordingIncidentLog();
    }

    private function controller(): IncidentsController
    {
        $log = $this->log;
        $clock = new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@1791115260');
            }
        };

        return new IncidentsController(static fn (): IncidentLog => $log, $clock);
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

    public function test_list_passes_validated_filters_and_shapes_each_incident(): void
    {
        $response = $this->controller()->list(new Request('GET', '/', ['exchange' => 'THX', 'type' => 'latency', 'from' => '1791115000', 'limit' => '10']));
        $data = self::decode($response->body);

        self::assertSame(200, $response->status);
        self::assertSame([['THX', 'latency', 1_791_115_000_000_000, null, 10]], $this->log->searches);
        self::assertIsArray($data['incidents']);
        self::assertSame([
            'id' => 7,
            'exchange' => 'THX',
            'type' => 'latency',
            'feed' => 'A',
            'severity' => 'critical',
            'opened_at' => '2026-10-04T12:00:00.000Z',
            'closed_at' => null,
            'ongoing' => true,
            'clearing' => false,
            'duration_s' => 60,
            'duration' => '1 min 0 s',
            'details' => ['peak_latency_ms' => 43.4],
        ], $data['incidents'][0]);
    }

    public function test_list_without_filters_uses_the_default_limit(): void
    {
        $this->controller()->list(new Request('GET', '/'));

        self::assertSame([[null, null, null, null, IncidentsController::DEFAULT_LIMIT]], $this->log->searches);
    }

    public function test_bad_filters_are_400s(): void
    {
        foreach ([['exchange' => 'thx'], ['type' => 'meltdown'], ['from' => 'today'], ['limit' => '0'], ['limit' => '500']] as $query) {
            self::assertSame(400, $this->controller()->list(new Request('GET', '/', $query))->status, json_encode($query, JSON_THROW_ON_ERROR));
        }
        self::assertSame([], $this->log->searches);
    }

    public function test_detail_includes_the_timeline_in_order(): void
    {
        $data = self::decode($this->controller()->detail(new Request('GET', '/', ['id' => '7']))->body);

        self::assertSame(7, $data['id']);
        self::assertSame([
            ['at' => '2026-10-04T12:00:00.000Z', 'kind' => 'opened', 'text' => 'High latency on A'],
            ['at' => '2026-10-04T12:00:20.000Z', 'kind' => 'escalated', 'text' => 'Escalated to critical: Very high latency on A'],
        ], $data['timeline']);
    }

    public function test_detail_of_a_missing_incident_is_404_and_a_bad_id_is_400(): void
    {
        self::assertSame(404, $this->controller()->detail(new Request('GET', '/', ['id' => '0']))->status);
        self::assertSame(400, $this->controller()->detail(new Request('GET', '/', ['id' => '7; DROP TABLE incidents']))->status);
        self::assertSame(400, $this->controller()->detail(new Request('GET', '/', []))->status);
    }
}
