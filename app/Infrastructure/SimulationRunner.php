<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

use FeedWatch\Detection\Thresholds;
use FeedWatch\Simulation\CatchUpPolicy;
use FeedWatch\Simulation\ConditionsSource;
use FeedWatch\Simulation\MessageGenerator;
use FeedWatch\Simulation\SeededRandom;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Brings the simulation up to "now" (ADR-004). Called by API requests and by
 * cron. A named lock means only one caller does the work; the others return
 * straight away and serve whatever is already stored.
 */
final class SimulationRunner
{
    private const string LOCK_NAME = 'feedwatch_simulation';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly ConditionsSource $conditions,
        private readonly ?Thresholds $thresholds = null,
    ) {
    }

    /**
     * @param int $minIntervalUs skip the catch-up if the last one was this recent (the poll cache, ADR-005)
     * @return CatchUpResult|null null if another caller holds the lock, or the data is fresh enough
     */
    public function catchUp(int $minIntervalUs = 0): ?CatchUpResult
    {
        if ($minIntervalUs > 0) {
            // A cheap read, before taking the lock: many viewers polling at
            // once then cost about one catch-up a second.
            $state = $this->pdo->query('SELECT processed_until_us FROM simulation_state WHERE id = 1');
            $row = $state === false ? false : $state->fetch();
            $processedUntil = is_array($row) ? Row::int($row, 'processed_until_us') : 0;
            if ($processedUntil > 0 && (int) $this->clock->now()->format('Uu') - $processedUntil < $minIntervalUs) {
                return null;
            }
        }

        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([self::LOCK_NAME]);
        if ((int) $lock->fetchColumn() !== 1) {
            return null;
        }

        $started = hrtime(true);
        try {
            $this->pdo->beginTransaction();
            try {
                $result = $this->run();
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([self::LOCK_NAME]);
        }

        return new CatchUpResult($result[0], $result[1], $result[2], (hrtime(true) - $started) / 1e9, $result[3]);
    }

    /**
     * @return array{int, int, bool, int} messages written, simulated microseconds, paused, gaps recorded
     */
    private function run(): array
    {
        $state = $this->pdo->query('SELECT seed, processed_until_us, detected_until_us FROM simulation_state WHERE id = 1 FOR UPDATE');
        $row = $state === false ? false : $state->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('simulation_state has no row; have the migrations run?');
        }

        $nowUs = (int) $this->clock->now()->format('Uu');
        $plan = CatchUpPolicy::plan(Row::int($row, 'processed_until_us'), $nowUs);

        if ($plan->pauseFromUs !== null) {
            $pause = $this->pdo->prepare('INSERT INTO simulation_pauses (from_us, to_us) VALUES (?, ?)');
            $pause->execute([$plan->pauseFromUs, $plan->generateFromUs]);
        }

        $written = 0;
        $gapsRecorded = 0;
        if ($plan->hasWindow()) {
            $generator = new MessageGenerator(new SeededRandom(Row::int($row, 'seed')));
            $exchanges = new ExchangeRepository($this->pdo);
            $messages = new MessageRepository($this->pdo);
            $firstSequences = [];

            foreach ($exchanges->allWithNextSequence(forUpdate: true) as ['exchange' => $exchange, 'nextSequence' => $next]) {
                $firstSequences[$exchange->id] = $next;
                $batch = $generator->generate(
                    $exchange,
                    $next,
                    $plan->generateFromUs,
                    $plan->generateToUs,
                    $this->conditions->forExchange($exchange),
                );
                if ($batch === []) {
                    continue;
                }
                $messages->insert($batch);
                $exchanges->setNextSequence($exchange->id, $next + count($batch));
                $written += count($batch);
            }

            // Detect over everything that has arrived up to now (plan 003).
            // On the very first run, or straight after a pause, detection starts
            // at the beginning of this window.
            $previousHorizon = Row::int($row, 'detected_until_us');
            if ($previousHorizon === 0 || $plan->pauseFromUs !== null) {
                $previousHorizon = max($previousHorizon, $plan->generateFromUs);
            }
            $gapsRecorded = (new DetectionRunner($this->pdo, $this->thresholds))->run(
                $previousHorizon,
                $plan->generateToUs,
                $plan->pauseFromUs === null ? null : $plan->generateFromUs,
                $firstSequences,
            );

            // Then bring the incident log in step (plan 005).
            (new IncidentRunner($this->pdo, $this->thresholds ?? Thresholds::defaults()))->run($plan->generateToUs);
        }

        $update = $this->pdo->prepare('UPDATE simulation_state SET processed_until_us = ?, detected_until_us = ? WHERE id = 1');
        $update->execute([$plan->generateToUs, $plan->hasWindow() ? $plan->generateToUs : Row::int($row, 'detected_until_us')]);

        return [$written, $plan->generateToUs - $plan->generateFromUs, $plan->pauseFromUs !== null, $gapsRecorded];
    }
}
