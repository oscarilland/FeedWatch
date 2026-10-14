<?php

declare(strict_types=1);

namespace FeedWatch\Detection;

/**
 * Turns one exchange's active conditions into incident changes (FR-06).
 * Pure: open incidents and conditions in, actions out.
 *
 * A condition keeps one incident open (AC-1). When it goes, the incident is
 * "clearing"; if the condition returns within the merge window it carries
 * on, otherwise it closes at the moment it first cleared (AC-2), so a flap
 * doesn't pad the duration.
 */
final class IncidentTracker
{
    /**
     * @param list<OpenIncident> $open this exchange's open incidents
     * @param array<string, Condition> $active this exchange's conditions, by key
     * @return list<IncidentAction>
     */
    public static function track(array $open, array $active, int $nowUs, int $mergeUs): array
    {
        $actions = [];
        $openByKey = [];
        foreach ($open as $incident) {
            $openByKey[$incident->key()] = $incident;
        }

        foreach ($active as $key => $condition) {
            $incident = $openByKey[$key] ?? null;
            if ($incident === null) {
                $actions[] = new IncidentAction('open', min($condition->startedUs, $nowUs), null, $condition);
                continue;
            }
            if ($incident->clearingSinceUs !== null) {
                $actions[] = new IncidentAction('return', $nowUs, $incident->id, $condition);
            }
            if ($condition->severity->rank() > $incident->severity->rank()) {
                $actions[] = new IncidentAction('escalate', $nowUs, $incident->id, $condition);
            }
            $actions[] = new IncidentAction('update', $nowUs, $incident->id, $condition);
        }

        foreach ($openByKey as $key => $incident) {
            if (isset($active[$key])) {
                continue;
            }
            if ($incident->clearingSinceUs === null) {
                $actions[] = new IncidentAction('clear', $nowUs, $incident->id);
                if ($mergeUs === 0) {
                    $actions[] = new IncidentAction('close', self::endUs($incident, $nowUs), $incident->id, null, $incident->openedUs);
                }
            } elseif ($nowUs - $incident->clearingSinceUs >= $mergeUs) {
                $actions[] = new IncidentAction('close', self::endUs($incident, $incident->clearingSinceUs), $incident->id, null, $incident->openedUs);
            }
        }

        return $actions;
    }

    /**
     * When an incident really ended: its last event (for data loss), or when
     * it was first seen to clear. Never before it opened.
     */
    private static function endUs(OpenIncident $incident, int $clearedUs): int
    {
        return max($incident->openedUs, $incident->endHintUs ?? $clearedUs);
    }
}
