<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * Latency baselines and breaches (FR-04, plan 004). Pure.
 */
final class LatencyRules
{
    /** Minutes of history needed before the learned baseline replaces the expected value. */
    public const int MIN_BASELINE_MINUTES = 5;

    /**
     * A feed's normal latency: the median of its recent per-minute p50s,
     * ignoring minutes that breached, or $expectedUs until there's enough history.
     *
     * @param list<array{p50Us: int, breached: bool}> $recentMinutes
     */
    public static function baselineUs(array $recentMinutes, int $expectedUs): int
    {
        $p50s = [];
        foreach ($recentMinutes as $minute) {
            if (!$minute['breached']) {
                $p50s[] = $minute['p50Us'];
            }
        }
        if (count($p50s) < self::MIN_BASELINE_MINUTES) {
            return $expectedUs;
        }

        return (int) round(Percentiles::median($p50s));
    }

    /**
     * The sustained level: every one of the last N seconds' means above a
     * threshold. A second with no arrivals (null) breaks the run, because a
     * silent feed is a silence, not slowness.
     *
     * @param list<?float> $secondMeansUs the last N complete seconds, oldest first
     */
    public static function level(array $secondMeansUs, int $baselineUs, Thresholds $thresholds): LatencyLevel
    {
        if (count($secondMeansUs) < $thresholds->latencySustainSeconds || $baselineUs <= 0) {
            return LatencyLevel::Normal;
        }

        $recent = array_slice($secondMeansUs, -$thresholds->latencySustainSeconds);
        $above = static function (float $multiple) use ($recent, $baselineUs): bool {
            foreach ($recent as $mean) {
                if ($mean === null || $mean <= $multiple * $baselineUs) {
                    return false;
                }
            }

            return true;
        };

        return match (true) {
            $above($thresholds->latencyRedMultiple) => LatencyLevel::VeryHigh,
            $above($thresholds->latencyAmberMultiple) => LatencyLevel::High,
            default => LatencyLevel::Normal,
        };
    }

    /**
     * Whether a finished minute counts as breached, so the baseline leaves it out.
     */
    public static function minuteBreached(int $p50Us, int $baselineUs, Thresholds $thresholds): bool
    {
        return $baselineUs > 0 && $p50Us > $thresholds->latencyAmberMultiple * $baselineUs;
    }
}
