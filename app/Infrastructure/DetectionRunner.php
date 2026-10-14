<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use FeedWatch\Detection\Delivery;
use FeedWatch\Detection\FeedDetector;
use FeedWatch\Detection\FeedState;
use FeedWatch\Detection\FoundGap;
use FeedWatch\Detection\Gap;
use FeedWatch\Detection\GapClassifier;
use FeedWatch\Detection\LatencyCounts;
use FeedWatch\Detection\PendingGap;
use FeedWatch\Detection\SecondCounts;
use FeedWatch\Detection\Silence;
use FeedWatch\Detection\Thresholds;
use FeedWatch\Detection\WinShare;
use FeedWatch\Domain\Feed;
use FeedWatch\Domain\Message;
use FeedWatch\Domain\MessageType;
use PDO;
use UnexpectedValueException;

/**
 * Runs detection for every exchange between two horizons, loading and saving
 * the detector's state (plan 003). Called by SimulationRunner inside its lock
 * and transaction.
 */
final class DetectionRunner
{
    /**
     * Messages sent up to this long before the previous horizon can still
     * arrive after it. Chaos-added delay must stay below this (FR-07).
     */
    public const int LOOKBACK_US = 5_000_000;

    private const string MESSAGE_COLUMNS = 'exchange_id, sequence, message_type, sent_us, a_received_us, b_received_us';

    private readonly FeedDetector $detector;

    public function __construct(
        private readonly PDO $pdo,
        ?Thresholds $thresholds = null,
    ) {
        $this->detector = new FeedDetector($thresholds);
    }

    /**
     * @param array<int, int> $firstSequences exchange id => first sequence number of this window
     * @param ?int $pauseEndUs set when the simulation has just skipped a pause ending here
     * @return int gaps recorded
     */
    public function run(int $previousHorizonUs, int $horizonUs, ?int $pauseEndUs, array $firstSequences): int
    {
        $states = $this->loadStates();
        $messages = $this->loadMessages($previousHorizonUs - self::LOOKBACK_US, $horizonUs);
        $gapsRecorded = 0;

        foreach ($firstSequences as $exchangeId => $firstSequence) {
            $exchangeMessages = $messages[$exchangeId] ?? [];
            $found = [];
            $latency = [];

            foreach (Feed::cases() as $feed) {
                $state = $states[$exchangeId][$feed->value] ?? new FeedState($firstSequence, $previousHorizonUs);
                // A pause is time nobody watched, not an outage.
                if ($pauseEndUs !== null && ($state->lastReceivedUs ?? 0) < $pauseEndUs) {
                    $state = new FeedState($state->nextExpected, $pauseEndUs, $state->silentSinceUs, $state->pending);
                }

                $deliveries = [];
                foreach ($exchangeMessages as $message) {
                    $received = $message->receivedUs($feed);
                    if ($received !== null && $received > $previousHorizonUs && $received <= $horizonUs) {
                        $deliveries[] = new Delivery($message->sequence, $received, $received - $message->sentUs);
                    }
                }

                $scan = $this->detector->scan($feed, $state, $deliveries, $horizonUs);
                $this->saveSilences($exchangeId, $state->silentSinceUs, $scan->silences);
                $this->saveState($exchangeId, $feed, $scan->state);
                array_push($found, ...$scan->gaps);
                $latency[$feed->value] = $scan->latency;
            }

            if ($found !== []) {
                $bySequence = $this->messagesCovering($exchangeId, $exchangeMessages, $found);
                foreach (GapClassifier::classify($found, $bySequence, $horizonUs) as $gap) {
                    $gapsRecorded += $this->saveGap($exchangeId, $gap);
                }
            }

            $this->saveSeconds(
                $exchangeId,
                WinShare::count($exchangeMessages, $previousHorizonUs, $horizonUs),
                $latency['A'],
                $latency['B'],
            );
        }

        return $gapsRecorded;
    }

    /**
     * Deletes per-second arbitration counts older than $cutoffSecond (ADR-003: kept 2 hours).
     */
    public function pruneSecondsBefore(int $cutoffSecond): int
    {
        $statement = $this->pdo->prepare('DELETE FROM feed_seconds WHERE second < ?');
        $statement->execute([$cutoffSecond]);

        return $statement->rowCount();
    }

    /**
     * @return array<int, array<string, FeedState>> exchange id => feed => state
     */
    private function loadStates(): array
    {
        $statement = $this->pdo->query('SELECT exchange_id, feed, next_expected, last_received_us, silent_since_us, pending_gaps FROM detector_state');
        $states = [];
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $states[Row::int($row, 'exchange_id')][Row::string($row, 'feed')] = new FeedState(
                Row::int($row, 'next_expected'),
                Row::nullableInt($row, 'last_received_us'),
                Row::nullableInt($row, 'silent_since_us'),
                self::decodePending(Row::string($row, 'pending_gaps')),
            );
        }

        return $states;
    }

    /**
     * @return array<int, list<Message>> exchange id => messages in sequence order
     */
    private function loadMessages(int $fromSentUs, int $toSentUs): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::MESSAGE_COLUMNS . ' FROM messages WHERE sent_us >= ? AND sent_us <= ? ORDER BY exchange_id, sequence'
        );
        $statement->execute([$fromSentUs, $toSentUs]);

        $byExchange = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $message = self::message($row);
                $byExchange[$message->exchangeId][] = $message;
            }
        }

        return $byExchange;
    }

    /**
     * Messages by sequence number for every found gap, loading any that fall
     * outside the look-back window (a long silence makes a long gap).
     *
     * @param list<Message> $loaded
     * @param list<FoundGap> $found
     * @return array<int, Message>
     */
    private function messagesCovering(int $exchangeId, array $loaded, array $found): array
    {
        $bySequence = [];
        foreach ($loaded as $message) {
            $bySequence[$message->sequence] = $message;
        }

        $range = $this->pdo->prepare(
            'SELECT ' . self::MESSAGE_COLUMNS . ' FROM messages WHERE exchange_id = ? AND sequence BETWEEN ? AND ?'
        );
        foreach ($found as $gap) {
            if (isset($bySequence[$gap->first], $bySequence[$gap->last])) {
                continue;
            }
            $range->execute([$exchangeId, $gap->first, $gap->last]);
            foreach ($range->fetchAll() as $row) {
                if (is_array($row)) {
                    $message = self::message($row);
                    $bySequence[$message->sequence] = $message;
                }
            }
        }

        return $bySequence;
    }

    /**
     * @return int 1 if stored, 0 if already there (data loss found by both feeds)
     */
    private function saveGap(int $exchangeId, Gap $gap): int
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO gaps (exchange_id, scope, classification, first_sequence, last_sequence, message_count, detected_us)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$exchangeId, $gap->scope, $gap->classification->value, $gap->first, $gap->last, $gap->count(), $gap->detectedUs]);

        return $statement->rowCount();
    }

    /**
     * @param list<Silence> $silences
     */
    private function saveSilences(int $exchangeId, ?int $previouslySilentSinceUs, array $silences): void
    {
        foreach ($silences as $silence) {
            $continuing = $previouslySilentSinceUs !== null && $silence->startedUs === $previouslySilentSinceUs;

            if ($silence->endedUs === null) {
                if (!$continuing) {
                    $insert = $this->pdo->prepare('INSERT INTO feed_silences (exchange_id, feed, started_us, ended_us) VALUES (?, ?, ?, NULL)');
                    $insert->execute([$exchangeId, $silence->feed->value, $silence->startedUs]);
                }
                continue;
            }

            if ($continuing) {
                $close = $this->pdo->prepare(
                    'UPDATE feed_silences SET ended_us = ? WHERE exchange_id = ? AND feed = ? AND started_us = ? AND ended_us IS NULL'
                );
                $close->execute([$silence->endedUs, $exchangeId, $silence->feed->value, $silence->startedUs]);
                if ($close->rowCount() > 0) {
                    continue;
                }
            }
            $insert = $this->pdo->prepare('INSERT INTO feed_silences (exchange_id, feed, started_us, ended_us) VALUES (?, ?, ?, ?)');
            $insert->execute([$exchangeId, $silence->feed->value, $silence->startedUs, $silence->endedUs]);
        }
    }

    private function saveState(int $exchangeId, Feed $feed, FeedState $state): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO detector_state (exchange_id, feed, next_expected, last_received_us, silent_since_us, pending_gaps)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE next_expected = VALUES(next_expected), last_received_us = VALUES(last_received_us),'
            . ' silent_since_us = VALUES(silent_since_us), pending_gaps = VALUES(pending_gaps)'
        );
        $statement->execute([
            $exchangeId,
            $feed->value,
            $state->nextExpected,
            $state->lastReceivedUs,
            $state->silentSinceUs,
            self::encodePending($state->pending),
        ]);
    }

    /**
     * Adds this run's win share and latency to each second's row. Adding,
     * rather than replacing, keeps a second split across two runs correct.
     *
     * @param array<int, SecondCounts> $winShare
     * @param array<int, LatencyCounts> $latencyA
     * @param array<int, LatencyCounts> $latencyB
     */
    private function saveSeconds(int $exchangeId, array $winShare, array $latencyA, array $latencyB): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO feed_seconds (exchange_id, second, accepted, a_wins, b_wins, duplicates,'
            . ' a_count, a_sum_us, a_max_us, b_count, b_sum_us, b_max_us) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE accepted = accepted + VALUES(accepted), a_wins = a_wins + VALUES(a_wins),'
            . ' b_wins = b_wins + VALUES(b_wins), duplicates = duplicates + VALUES(duplicates),'
            . ' a_count = a_count + VALUES(a_count), a_sum_us = a_sum_us + VALUES(a_sum_us), a_max_us = GREATEST(a_max_us, VALUES(a_max_us)),'
            . ' b_count = b_count + VALUES(b_count), b_sum_us = b_sum_us + VALUES(b_sum_us), b_max_us = GREATEST(b_max_us, VALUES(b_max_us))'
        );

        $seconds = array_unique([...array_keys($winShare), ...array_keys($latencyA), ...array_keys($latencyB)]);
        sort($seconds);
        foreach ($seconds as $second) {
            $w = $winShare[$second] ?? new SecondCounts();
            $a = $latencyA[$second] ?? new LatencyCounts();
            $b = $latencyB[$second] ?? new LatencyCounts();
            $statement->execute([
                $exchangeId, $second, $w->accepted, $w->aWins, $w->bWins, $w->duplicates,
                $a->count, $a->sumUs, $a->maxUs, $b->count, $b->sumUs, $b->maxUs,
            ]);
        }
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function message(array $row): Message
    {
        return new Message(
            Row::int($row, 'exchange_id'),
            Row::int($row, 'sequence'),
            MessageType::from(Row::string($row, 'message_type')),
            Row::int($row, 'sent_us'),
            Row::nullableInt($row, 'a_received_us'),
            Row::nullableInt($row, 'b_received_us'),
        );
    }

    /**
     * @param list<PendingGap> $pending
     */
    private static function encodePending(array $pending): string
    {
        return json_encode(
            array_map(static fn (PendingGap $gap): array => [$gap->first, $gap->last, $gap->deadlineUs], $pending),
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<PendingGap>
     */
    private static function decodePending(string $json): array
    {
        $items = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($items)) {
            throw new UnexpectedValueException('pending_gaps is not a list');
        }

        $pending = [];
        foreach ($items as $item) {
            if (!is_array($item) || count($item) !== 3 || !is_int($item[0] ?? null) || !is_int($item[1] ?? null) || !is_int($item[2] ?? null)) {
                throw new UnexpectedValueException('pending_gaps has a malformed entry');
            }
            $pending[] = new PendingGap($item[0], $item[1], $item[2]);
        }

        return $pending;
    }
}
