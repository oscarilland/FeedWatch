<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Detection;

use FeedWatch\Detection\HealthInputs;
use FeedWatch\Detection\HealthStatus;
use FeedWatch\Detection\LatencyLevel;
use FeedWatch\Detection\StatusRules;
use FeedWatch\Detection\Thresholds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusRulesTest extends TestCase
{
    /**
     * @return array<string, array{HealthInputs, HealthStatus, string}>
     */
    public static function cases(): array
    {
        $high = LatencyLevel::High;
        $veryHigh = LatencyLevel::VeryHigh;

        return [
            'all quiet' => [new HealthInputs(), HealthStatus::Healthy, 'Healthy'],
            'FR-03 AC-5 both feeds silent' => [new HealthInputs(aSilent: true, bSilent: true), HealthStatus::Down, 'Down: feed lost'],
            'recent data loss' => [new HealthInputs(messagesLostRecently: 6), HealthStatus::Down, 'Down: data lost (6 messages)'],
            'one message lost' => [new HealthInputs(messagesLostRecently: 1), HealthStatus::Down, 'Down: data lost (1 message)'],
            'FR-04 very high latency on A' => [new HealthInputs(latencyA: $veryHigh), HealthStatus::Down, 'Down: very high latency on A'],
            'FR-03 AC-3 A silent' => [new HealthInputs(aSilent: true), HealthStatus::Degraded, 'Degraded: running on B only'],
            'B silent' => [new HealthInputs(bSilent: true), HealthStatus::Degraded, 'Degraded: running on A only'],
            'FR-04 AC-3 high latency on A' => [new HealthInputs(latencyA: $high), HealthStatus::Degraded, 'Degraded: high latency on A'],
            'high latency on both' => [new HealthInputs(latencyA: $high, latencyB: $veryHigh), HealthStatus::Down, 'Down: very high latency on B'],
            'two line gaps stay healthy' => [new HealthInputs(lineGapsA: 2), HealthStatus::Healthy, 'Healthy'],
            'FR-02 three line gaps on A' => [new HealthInputs(lineGapsA: 3), HealthStatus::Degraded, 'Degraded: line gaps on A'],
            'line gaps on both' => [new HealthInputs(lineGapsA: 3, lineGapsB: 4), HealthStatus::Degraded, 'Degraded: line gaps on A and B'],
            'feed lost beats data loss' => [new HealthInputs(aSilent: true, bSilent: true, messagesLostRecently: 6), HealthStatus::Down, 'Down: feed lost'],
            'data loss beats very high latency' => [new HealthInputs(messagesLostRecently: 2, latencyA: $veryHigh), HealthStatus::Down, 'Down: data lost (2 messages)'],
            'very high latency beats a silent feed' => [new HealthInputs(bSilent: true, latencyA: $veryHigh), HealthStatus::Down, 'Down: very high latency on A'],
            'a silent feed beats high latency' => [new HealthInputs(aSilent: true, latencyB: $high), HealthStatus::Degraded, 'Degraded: running on B only'],
            'high latency beats line gaps' => [new HealthInputs(lineGapsA: 5, latencyB: $high), HealthStatus::Degraded, 'Degraded: high latency on B'],
        ];
    }

    #[DataProvider('cases')]
    public function test_status_and_reason(HealthInputs $inputs, HealthStatus $status, string $reason): void
    {
        $health = StatusRules::evaluate($inputs, Thresholds::defaults());

        self::assertSame($status, $health->status);
        self::assertSame($reason, $health->reason);
    }

    public function test_the_line_gap_threshold_comes_from_settings(): void
    {
        [$thresholds] = Thresholds::fromSettings(['line_gaps_amber' => '5']);

        self::assertSame(HealthStatus::Healthy, StatusRules::evaluate(new HealthInputs(lineGapsA: 4), $thresholds)->status);
        self::assertSame(HealthStatus::Degraded, StatusRules::evaluate(new HealthInputs(lineGapsA: 5), $thresholds)->status);
    }
}
