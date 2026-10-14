<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

use FeedWatch\Domain\Feed;
use FeedWatch\Domain\Message;

/**
 * Splits each found gap into line-gap and data-loss runs by checking whether
 * the other feed delivered each missing message (FR-02 AC-2, AC-3).
 */
final class GapClassifier
{
    /**
     * @param list<FoundGap> $found
     * @param array<int, Message> $messages by sequence number, covering every found gap
     * @return list<Gap>
     */
    public static function classify(array $found, array $messages, int $horizonUs): array
    {
        $gaps = [];
        foreach ($found as $gap) {
            $other = $gap->feed === Feed::A ? Feed::B : Feed::A;
            $runStart = $gap->first;
            $runIsLoss = null;

            for ($sequence = $gap->first; $sequence <= $gap->last + 1; $sequence++) {
                $isLoss = null;
                if ($sequence <= $gap->last) {
                    $received = isset($messages[$sequence]) ? $messages[$sequence]->receivedUs($other) : null;
                    $isLoss = $received === null || $received > $horizonUs;
                }
                // Close the current run when the classification changes or the gap ends.
                if ($runIsLoss !== null && $isLoss !== $runIsLoss) {
                    $gaps[] = $runIsLoss
                        ? new Gap('AB', GapClassification::DataLoss, $runStart, $sequence - 1, $gap->detectedUs)
                        : new Gap($gap->feed->value, GapClassification::LineGap, $runStart, $sequence - 1, $gap->detectedUs);
                    $runStart = $sequence;
                }
                $runIsLoss = $isLoss;
            }
        }

        return $gaps;
    }
}
