<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use FeedWatch\Detection\LatencyRules;
use FeedWatch\Detection\Percentiles;
use FeedWatch\Detection\Thresholds;
use FeedWatch\Domain\Feed;
use PDO;
use RuntimeException;

/**
 * Run by cron (plan 004): summarises each finished minute's latency into
 * p50, p95 and p99 per feed, updates each feed's learned baseline, and prunes
 * summaries older than 7 days.
 */
final class MinuteSummariser
{
    public const int RETENTION_MINUTES = 7 * 24 * 60;

    /** Raw messages are kept 15 minutes, so older minutes can't be summarised. */
    private const int RAW_MINUTES = 15;

    private const int MINUTE_US = 60_000_000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Thresholds $thresholds,
    ) {
    }

    /**
     * @return int minutes summarised
     */
    public function run(): int
    {
        $state = $this->pdo->query('SELECT detected_until_us, summarised_until_minute FROM simulation_state WHERE id = 1');
        $row = $state === false ? false : $state->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('simulation_state has no row');
        }
        $detectedUntil = Row::int($row, 'detected_until_us');
        if ($detectedUntil === 0) {
            return 0;
        }

        // A minute is finished once the simulation has run past its end: every
        // arrival in it has then been generated.
        $lastFinished = intdiv($detectedUntil, self::MINUTE_US) - 1;
        $first = max(Row::int($row, 'summarised_until_minute') + 1, $lastFinished - self::RAW_MINUTES + 1);
        if ($first > $lastFinished) {
            return 0;
        }

        $offsets = $this->exchangeOffsets();
        $baselines = $this->baselines($offsets);
        $insert = $this->pdo->prepare(
            'INSERT INTO feed_minutes (exchange_id, feed, minute, message_count, mean_us, p50_us, p95_us, p99_us, max_us, breached)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE message_count = VALUES(message_count), mean_us = VALUES(mean_us), p50_us = VALUES(p50_us),'
            . ' p95_us = VALUES(p95_us), p99_us = VALUES(p99_us), max_us = VALUES(max_us), breached = VALUES(breached)'
        );

        for ($minute = $first; $minute <= $lastFinished; $minute++) {
            foreach ($this->latenciesArrivingIn($minute) as $exchangeId => $byFeed) {
                foreach ($byFeed as $feed => $latencies) {
                    $p50 = Percentiles::of($latencies, 50);
                    $baseline = $baselines[$exchangeId][$feed] ?? 0;
                    $insert->execute([
                        $exchangeId,
                        $feed,
                        $minute,
                        count($latencies),
                        (int) round(array_sum($latencies) / count($latencies)),
                        $p50,
                        Percentiles::of($latencies, 95),
                        Percentiles::of($latencies, 99),
                        Percentiles::of($latencies, 100),
                        (int) LatencyRules::minuteBreached($p50, $baseline, $this->thresholds),
                    ]);
                }
            }
        }

        $done = $this->pdo->prepare('UPDATE simulation_state SET summarised_until_minute = ? WHERE id = 1');
        $done->execute([$lastFinished]);
        $this->updateBaselines($offsets, $lastFinished);

        return $lastFinished - $first + 1;
    }

    public function pruneBefore(int $minute): int
    {
        $statement = $this->pdo->prepare('DELETE FROM feed_minutes WHERE minute < ?');
        $statement->execute([$minute]);

        return $statement->rowCount();
    }

    /**
     * @return array<int, array<string, list<int>>> exchange id => feed => latencies
     */
    private function latenciesArrivingIn(int $minute): array
    {
        $startUs = $minute * self::MINUTE_US;
        $endUs = $startUs + self::MINUTE_US;
        $statement = $this->pdo->prepare(
            'SELECT exchange_id, sent_us, a_received_us, b_received_us FROM messages WHERE sent_us >= ? AND sent_us < ?'
        );
        $statement->execute([$startUs - DetectionRunner::LOOKBACK_US, $endUs]);

        $latencies = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sent = Row::int($row, 'sent_us');
            foreach (['A' => 'a_received_us', 'B' => 'b_received_us'] as $feed => $column) {
                $received = Row::nullableInt($row, $column);
                if ($received !== null && $received >= $startUs && $received < $endUs) {
                    $latencies[Row::int($row, 'exchange_id')][$feed][] = $received - $sent;
                }
            }
        }

        return $latencies;
    }

    /**
     * @return array<int, int> exchange id => latency offset
     */
    private function exchangeOffsets(): array
    {
        $statement = $this->pdo->query('SELECT id, latency_offset_us FROM exchanges');
        $offsets = [];
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            if (is_array($row)) {
                $offsets[Row::int($row, 'id')] = Row::int($row, 'latency_offset_us');
            }
        }

        return $offsets;
    }

    /**
     * The current baseline for every exchange and feed, stored or expected.
     *
     * @param array<int, int> $offsets
     * @return array<int, array<string, int>>
     */
    private function baselines(array $offsets): array
    {
        $stored = [];
        $statement = $this->pdo->query('SELECT exchange_id, feed, baseline_us FROM detector_state');
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            if (is_array($row) && Row::nullableInt($row, 'baseline_us') !== null) {
                $stored[Row::int($row, 'exchange_id')][Row::string($row, 'feed')] = Row::int($row, 'baseline_us');
            }
        }

        $baselines = [];
        foreach ($offsets as $exchangeId => $offset) {
            foreach (Feed::cases() as $feed) {
                $baselines[$exchangeId][$feed->value] = $stored[$exchangeId][$feed->value] ?? ExpectedLatency::us($feed, $offset);
            }
        }

        return $baselines;
    }

    /**
     * @param array<int, int> $offsets
     */
    private function updateBaselines(array $offsets, int $lastMinute): void
    {
        $recent = $this->pdo->prepare(
            'SELECT p50_us, breached FROM feed_minutes WHERE exchange_id = ? AND feed = ? AND minute > ? AND minute <= ?'
        );
        $update = $this->pdo->prepare('UPDATE detector_state SET baseline_us = ? WHERE exchange_id = ? AND feed = ?');

        foreach ($offsets as $exchangeId => $offset) {
            foreach (Feed::cases() as $feed) {
                $recent->execute([$exchangeId, $feed->value, $lastMinute - $this->thresholds->baselineWindowMinutes, $lastMinute]);
                $minutes = [];
                foreach ($recent->fetchAll() as $row) {
                    if (is_array($row)) {
                        $minutes[] = ['p50Us' => Row::int($row, 'p50_us'), 'breached' => Row::int($row, 'breached') === 1];
                    }
                }
                $update->execute([LatencyRules::baselineUs($minutes, ExpectedLatency::us($feed, $offset)), $exchangeId, $feed->value]);
            }
        }
    }
}
