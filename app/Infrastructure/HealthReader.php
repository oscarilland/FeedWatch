<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use FeedWatch\Detection\FeedLatency;
use FeedWatch\Detection\HealthData;
use FeedWatch\Detection\HealthInputs;
use FeedWatch\Detection\LatencyCounts;
use FeedWatch\Detection\LatencyRules;
use FeedWatch\Detection\Thresholds;
use FeedWatch\Domain\Feed;
use PDO;
use RuntimeException;

/**
 * Reads every exchange's current health (plans 003 to 005): silent feeds,
 * recent data loss and line gaps, and latency against the baseline. The
 * status shown in the API and the incident log both use this, so they
 * always agree. A handful of small, indexed reads.
 */
final class HealthReader
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Thresholds $thresholds,
    ) {
    }

    /**
     * @return array<int, HealthData> exchange id => health
     */
    public function read(int $nowUs): array
    {
        $offsets = [];
        foreach ($this->rows($this->pdo->query('SELECT id, latency_offset_us FROM exchanges ORDER BY id')) as $row) {
            $offsets[Row::int($row, 'id')] = Row::int($row, 'latency_offset_us');
        }

        $silentSince = [];
        $baselines = [];
        foreach ($this->rows($this->pdo->query('SELECT exchange_id, feed, silent_since_us, baseline_us FROM detector_state')) as $row) {
            $id = Row::int($row, 'exchange_id');
            $feed = Row::string($row, 'feed');
            if (Row::nullableInt($row, 'silent_since_us') !== null) {
                $silentSince[$id][$feed] = Row::int($row, 'silent_since_us');
            }
            if (Row::nullableInt($row, 'baseline_us') !== null) {
                $baselines[$id][$feed] = Row::int($row, 'baseline_us');
            }
        }

        // The last N complete seconds; the current second is still filling.
        $nowSecond = intdiv($nowUs, 1_000_000);
        $firstSecond = $nowSecond - $this->thresholds->latencySustainSeconds;
        $recent = [];
        $statement = $this->pdo->prepare(
            'SELECT exchange_id, second, a_count, a_sum_us, b_count, b_sum_us FROM feed_seconds WHERE second >= ? AND second < ?'
        );
        $statement->execute([$firstSecond, $nowSecond]);
        foreach ($this->rows($statement) as $row) {
            $recent[Row::int($row, 'exchange_id')][Row::int($row, 'second')] = [
                'A' => new LatencyCounts(Row::int($row, 'a_count'), Row::int($row, 'a_sum_us')),
                'B' => new LatencyCounts(Row::int($row, 'b_count'), Row::int($row, 'b_sum_us')),
            ];
        }

        $lineGaps = [];
        $statement = $this->pdo->prepare(
            "SELECT exchange_id, scope, COUNT(*) AS n FROM gaps WHERE classification = 'line_gap' AND detected_us >= ? GROUP BY exchange_id, scope"
        );
        $statement->execute([$nowUs - $this->thresholds->lineGapWindowUs]);
        foreach ($this->rows($statement) as $row) {
            $lineGaps[Row::int($row, 'exchange_id')][Row::string($row, 'scope')] = Row::int($row, 'n');
        }

        $lost = [];
        $statement = $this->pdo->prepare(
            "SELECT exchange_id, SUM(message_count) AS n, MIN(detected_us) AS first_us, MAX(detected_us) AS last_us FROM gaps"
            . " WHERE classification = 'data_loss' AND detected_us >= ? GROUP BY exchange_id"
        );
        $statement->execute([$nowUs - $this->thresholds->dataLossRedUs]);
        foreach ($this->rows($statement) as $row) {
            $lost[Row::int($row, 'exchange_id')] = [Row::int($row, 'n'), Row::int($row, 'first_us'), Row::int($row, 'last_us')];
        }

        $health = [];
        foreach ($offsets as $id => $offset) {
            $latency = [];
            foreach (Feed::cases() as $feed) {
                $means = [];
                $total = new LatencyCounts();
                for ($second = $firstSecond; $second < $nowSecond; $second++) {
                    $counts = $recent[$id][$second][$feed->value] ?? new LatencyCounts();
                    $means[] = $counts->meanUs();
                    $total->count += $counts->count;
                    $total->sumUs += $counts->sumUs;
                }
                $baseline = $baselines[$id][$feed->value] ?? ExpectedLatency::us($feed, $offset);
                $latency[$feed->value] = new FeedLatency($total->meanUs(), $baseline, LatencyRules::level($means, $baseline, $this->thresholds));
            }

            $gaps = ['A' => $lineGaps[$id]['A'] ?? 0, 'B' => $lineGaps[$id]['B'] ?? 0];
            [$messagesLost, $firstLoss, $lastLoss] = $lost[$id] ?? [0, null, null];
            $health[$id] = new HealthData(
                new HealthInputs(
                    isset($silentSince[$id]['A']),
                    isset($silentSince[$id]['B']),
                    $messagesLost,
                    $gaps['A'],
                    $gaps['B'],
                    $latency['A']->level,
                    $latency['B']->level,
                ),
                $latency,
                $silentSince[$id] ?? [],
                $firstLoss,
                $lastLoss,
                $gaps,
            );
        }

        return $health;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(\PDOStatement|false $statement): array
    {
        if ($statement === false) {
            throw new RuntimeException('Query failed');
        }

        return array_values(array_filter($statement->fetchAll(), 'is_array'));
    }
}
