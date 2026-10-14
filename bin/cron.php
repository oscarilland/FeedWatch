<?php

declare(strict_types=1);

// Scheduler entry point, run every minute by cPanel cron:
//   /opt/alt/php84/usr/bin/php <app folder>/bin/cron.php
// Prints nothing on success, because cron emails any output.

use FeedWatch\Infrastructure\Config;
use FeedWatch\Infrastructure\Database;
use FeedWatch\Infrastructure\DetectionRunner;
use FeedWatch\Infrastructure\MessageRepository;
use FeedWatch\Infrastructure\MigrationRunner;
use FeedWatch\Infrastructure\MinuteSummariser;
use FeedWatch\Infrastructure\SettingsRepository;
use FeedWatch\Infrastructure\SimulationRunner;
use FeedWatch\Infrastructure\SimulationStatus;
use FeedWatch\Infrastructure\SystemClock;
use FeedWatch\Infrastructure\ChaosRepository;
use FeedWatch\Infrastructure\LoginThrottle;
use FeedWatch\Infrastructure\StoredChaos;

$root = dirname(__DIR__);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $root . '/storage/logs/cron-error.log');

require $root . '/vendor/autoload.php';

try {
    // Record the command-line PHP's extensions once, so we can check the
    // scheduler has what it needs (the web and command-line PHP can differ).
    $extensionsFile = $root . '/storage/cli-extensions.txt';
    if (!is_file($extensionsFile)) {
        $extensions = get_loaded_extensions();
        sort($extensions);
        file_put_contents($extensionsFile, 'PHP ' . PHP_VERSION . "\n" . implode("\n", $extensions) . "\n");
    }

    $pdo = Database::connect(Config::load($root . '/config'));
    $migrations = new MigrationRunner($pdo, $root . '/database/migrations');
    $appliedNow = $migrations->run();

    $clock = new SystemClock();
    $nowUs = (int) $clock->now()->format('Uu');
    $thresholds = (new SettingsRepository($pdo))->thresholds();
    $status = new SimulationStatus($pdo);

    // Keep the simulation going for a while after someone last looked, then
    // stop, so nothing runs on the shared server for an unwatched demo
    // (ADR-004 as amended; idle mode, plan 004).
    $idleForUs = $status->idleForUs($nowUs);
    $idle = $idleForUs === null || $idleForUs > $thresholds->idleAfterUs;

    // Chaos restores itself (FR-07 AC-4): expired faults are removed and logged.
    $chaos = new ChaosRepository($pdo);
    $expired = $chaos->expire($nowUs);
    foreach ($expired as $fault) {
        $chaos->log('system', 'expired', "{$fault['exchange']} {$fault['feed']}", $fault['kind'], $nowUs);
    }

    $catchUp = $idle ? null : (new SimulationRunner($pdo, $clock, new StoredChaos($chaos, $clock), $thresholds))->catchUp();
    if ($catchUp !== null && $catchUp->durationSeconds > 1.0) {
        error_log(sprintf('FeedWatch cron: warning: catch-up took %.3f s, over the 1 s limit', $catchUp->durationSeconds));
    }

    // Per-minute latency percentiles and baselines (plan 004).
    $summariser = new MinuteSummariser($pdo, $thresholds);
    $summarised = $summariser->run();

    // Retention (ADR-003): raw messages 15 minutes, per-second counts 2 hours,
    // per-minute summaries 7 days.
    $pruned = (new MessageRepository($pdo))->pruneSentBefore($nowUs - 15 * 60 * 1_000_000);
    $prunedSeconds = (new DetectionRunner($pdo, $thresholds))->pruneSecondsBefore(intdiv($nowUs, 1_000_000) - 2 * 3600);
    $prunedMinutes = $summariser->pruneBefore(intdiv($nowUs, 60_000_000) - MinuteSummariser::RETENTION_MINUTES);
    // Failed logins are only needed for the 15-minute lockout; keep a day.
    (new LoginThrottle($pdo))->pruneBefore($nowUs - 86_400_000_000);

    // Storage budget: 100 MB (ADR-003); warn well before it.
    $databaseBytes = $status->databaseBytes();
    if ($databaseBytes > 80 * 1024 * 1024) {
        error_log(sprintf('FeedWatch cron: warning: database is %.1f MB, over 80 MB of the 100 MB budget', $databaseBytes / 1048576));
    }

    // Evidence that the scheduler is running, readable over FTPS but not the web.
    file_put_contents($root . '/storage/cron-last-run.txt', implode("\n", [
        'Last run: ' . gmdate('Y-m-d H:i:s') . ' UTC',
        'Applied this run: ' . ($appliedNow === null ? 'skipped (another run held the lock)' : (implode(', ', $appliedNow) ?: 'none')),
        'Applied in total: ' . (implode(', ', $migrations->appliedVersions()) ?: 'none'),
        'Catch-up: ' . match (true) {
            $idle => $idleForUs === null
                ? 'skipped (idle: never viewed)'
                : sprintf('skipped (idle: no viewer for %d min)', intdiv($idleForUs, 60_000_000)),
            $catchUp === null => 'skipped (a request held the lock)',
            default => sprintf('%d messages, %.1f s simulated%s, %d gaps recorded, took %.3f s', $catchUp->messagesWritten, $catchUp->simulatedUs / 1e6, $catchUp->paused ? ' after a pause' : '', $catchUp->gapsRecorded, $catchUp->durationSeconds),
        },
        'Summarised: ' . $summarised . ' minutes',
        'Chaos: ' . count($chaos->active($nowUs)) . ' active fault(s), ' . count($expired) . ' expired this run',
        'Pruned: ' . $pruned . ' messages older than 15 minutes, ' . $prunedSeconds . ' per-second counts older than 2 hours, ' . $prunedMinutes . ' minute summaries older than 7 days',
        sprintf('Database: %.1f MB (budget 100 MB)', $databaseBytes / 1048576),
    ]) . "\n");

    // Detection runs inside each catch-up (plan 003).
} catch (Throwable $e) {
    error_log('FeedWatch cron: ' . $e::class . ': ' . $e->getMessage());
    fwrite(STDERR, "FeedWatch cron failed; see storage/logs/cron-error.log\n");
    exit(1);
}
