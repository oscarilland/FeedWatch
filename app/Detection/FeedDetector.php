<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

use FeedWatch\Domain\Feed;

/**
 * Watches one feed's sequence numbers and arrival times (FR-02, FR-03).
 * Pure: state and deliveries in, new state and findings out.
 */
final class FeedDetector
{
    /** How long a skipped number may take to turn up before it's a gap. */
    private readonly int $toleranceUs;

    /** How long without a delivery before a feed counts as silent. */
    private readonly int $silenceUs;

    public function __construct(?Thresholds $thresholds = null)
    {
        $thresholds ??= Thresholds::defaults();
        $this->toleranceUs = $thresholds->gapToleranceUs;
        $this->silenceUs = $thresholds->silenceUs;
    }

    /**
     * @param list<Delivery> $deliveries arrivals after the previous horizon, up to $horizonUs
     */
    public function scan(Feed $feed, FeedState $state, array $deliveries, int $horizonUs): FeedScan
    {
        usort($deliveries, static fn (Delivery $x, Delivery $y): int => [$x->receivedUs, $x->sequence] <=> [$y->receivedUs, $y->sequence]);

        $next = $state->nextExpected;
        $last = $state->lastReceivedUs;
        $silentSince = $state->silentSinceUs;
        $pending = $state->pending;
        $silences = [];
        $outOfOrder = 0;
        $duplicates = 0;
        $latency = [];

        foreach ($deliveries as $delivery) {
            // Latency counts in the second the copy arrived (FR-04).
            if ($delivery->latencyUs !== null) {
                ($latency[intdiv($delivery->receivedUs, 1_000_000)] ??= new LatencyCounts())->add($delivery->latencyUs);
            }

            // A long wait before this arrival was a silence, now over.
            if ($silentSince !== null || ($last !== null && $delivery->receivedUs - $last > $this->silenceUs)) {
                $silences[] = new Silence($feed, $silentSince ?? (int) $last + $this->silenceUs, $delivery->receivedUs);
                $silentSince = null;
            }
            $last = max($last ?? $delivery->receivedUs, $delivery->receivedUs);

            $sequence = $delivery->sequence;
            if ($sequence === $next) {
                $next++;
            } elseif ($sequence > $next) {
                $pending[] = new PendingGap($next, $sequence - 1, $delivery->receivedUs + $this->toleranceUs);
                $next = $sequence + 1;
            } else {
                [$pending, $wasPending] = self::remove($pending, $sequence);
                $wasPending ? $outOfOrder++ : $duplicates++;
            }
        }

        // Still quiet at the horizon: the silence is open.
        if ($silentSince === null && $last !== null && $horizonUs - $last > $this->silenceUs) {
            $silentSince = $last + $this->silenceUs;
        }
        if ($silentSince !== null) {
            $silences[] = new Silence($feed, $silentSince, null);
        }

        // Gaps whose tolerance window has passed are final.
        $gaps = [];
        $stillPending = [];
        foreach ($pending as $gap) {
            if ($gap->deadlineUs <= $horizonUs) {
                $gaps[] = new FoundGap($feed, $gap->first, $gap->last, $gap->deadlineUs);
            } else {
                $stillPending[] = $gap;
            }
        }

        return new FeedScan(
            new FeedState($next, $last, $silentSince, $stillPending),
            $gaps,
            $silences,
            $outOfOrder,
            $duplicates,
            $latency,
        );
    }

    /**
     * @param list<PendingGap> $pending
     * @return array{list<PendingGap>, bool} the pending gaps without $sequence, and whether it was pending
     */
    private static function remove(array $pending, int $sequence): array
    {
        $result = [];
        $found = false;
        foreach ($pending as $gap) {
            if (!$found && $gap->contains($sequence)) {
                array_push($result, ...$gap->without($sequence));
                $found = true;
            } else {
                $result[] = $gap;
            }
        }

        return [$result, $found];
    }
}
