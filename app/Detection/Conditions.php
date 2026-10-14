<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * Works out which alert conditions are active for one exchange (plan 005).
 * Pure.
 */
final class Conditions
{
    /**
     * @return array<string, Condition> by key
     */
    public static function from(HealthData $health, int $nowUs, Thresholds $thresholds): array
    {
        $in = $health->inputs;
        $conditions = [];

        if ($in->aSilent && $in->bSilent) {
            $since = max($health->silentSinceUs['A'] ?? $nowUs, $health->silentSinceUs['B'] ?? $nowUs);
            $conditions[] = new Condition(IncidentType::FeedDown, 'AB', Severity::Critical, $since, 'Both feeds silent: feed lost');
        } else {
            foreach (['A' => $in->aSilent, 'B' => $in->bSilent] as $feed => $silent) {
                if ($silent) {
                    $other = $feed === 'A' ? 'B' : 'A';
                    $conditions[] = new Condition(
                        IncidentType::Failover,
                        $feed,
                        Severity::Major,
                        $health->silentSinceUs[$feed] ?? $nowUs,
                        "Feed {$feed} silent: running on {$other} only",
                    );
                }
            }
        }

        if ($in->messagesLostRecently > 0) {
            $last = $health->lastDataLossUs ?? $nowUs;
            $conditions[] = new Condition(
                IncidentType::DataLoss,
                'AB',
                Severity::Critical,
                $health->firstDataLossUs ?? $last,
                'Data lost on both feeds',
                $last,
            );
        }

        foreach (['A' => $in->latencyA, 'B' => $in->latencyB] as $feed => $level) {
            if ($level === LatencyLevel::Normal) {
                continue;
            }
            $veryHigh = $level === LatencyLevel::VeryHigh;
            $recent = $health->latency[$feed]->recentMeanUs ?? null;
            $conditions[] = new Condition(
                IncidentType::Latency,
                $feed,
                $veryHigh ? Severity::Critical : Severity::Warning,
                $nowUs - $thresholds->latencySustainSeconds * 1_000_000,
                ($veryHigh ? 'Very high' : 'High') . " latency on {$feed}",
                null,
                $recent === null ? [] : ['peak_latency_us' => (int) round($recent)],
            );
        }

        foreach (['A' => $in->lineGapsA, 'B' => $in->lineGapsB] as $feed => $count) {
            if ($count >= $thresholds->lineGapsAmber) {
                $conditions[] = new Condition(
                    IncidentType::LineGaps,
                    $feed,
                    Severity::Warning,
                    $nowUs,
                    "{$count} line gaps on {$feed}",
                    null,
                    ['line_gaps' => $count],
                );
            }
        }

        $byKey = [];
        foreach ($conditions as $condition) {
            $byKey[$condition->key()] = $condition;
        }

        return $byKey;
    }
}
