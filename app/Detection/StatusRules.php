<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * Turns an exchange's current findings into one status and reason, worst
 * first (plans 003 and 004). Thresholds come from the settings table.
 */
final class StatusRules
{
    public static function evaluate(HealthInputs $in, Thresholds $thresholds): ExchangeHealth
    {
        if ($in->aSilent && $in->bSilent) {
            return new ExchangeHealth(HealthStatus::Down, 'Down: feed lost');
        }
        if ($in->messagesLostRecently > 0) {
            $noun = $in->messagesLostRecently === 1 ? 'message' : 'messages';

            return new ExchangeHealth(HealthStatus::Down, "Down: data lost ({$in->messagesLostRecently} {$noun})");
        }
        $veryHigh = self::feeds($in->latencyA === LatencyLevel::VeryHigh, $in->latencyB === LatencyLevel::VeryHigh);
        if ($veryHigh !== null) {
            return new ExchangeHealth(HealthStatus::Down, "Down: very high latency on {$veryHigh}");
        }
        if ($in->aSilent) {
            return new ExchangeHealth(HealthStatus::Degraded, 'Degraded: running on B only');
        }
        if ($in->bSilent) {
            return new ExchangeHealth(HealthStatus::Degraded, 'Degraded: running on A only');
        }
        $high = self::feeds($in->latencyA !== LatencyLevel::Normal, $in->latencyB !== LatencyLevel::Normal);
        if ($high !== null) {
            return new ExchangeHealth(HealthStatus::Degraded, "Degraded: high latency on {$high}");
        }
        $lineGaps = self::feeds($in->lineGapsA >= $thresholds->lineGapsAmber, $in->lineGapsB >= $thresholds->lineGapsAmber);
        if ($lineGaps !== null) {
            return new ExchangeHealth(HealthStatus::Degraded, "Degraded: line gaps on {$lineGaps}");
        }

        return new ExchangeHealth(HealthStatus::Healthy, 'Healthy');
    }

    /**
     * "A", "B", "A and B", or null if neither.
     */
    private static function feeds(bool $a, bool $b): ?string
    {
        return match (true) {
            $a && $b => 'A and B',
            $a => 'A',
            $b => 'B',
            default => null,
        };
    }
}
