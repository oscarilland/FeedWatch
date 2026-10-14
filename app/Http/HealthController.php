<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use DateTimeInterface;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\DatabaseCheck;

/**
 * GET /api/health: is this deployment running the right code, on PHP 8.4,
 * with a working database? Reveals no versions or error details.
 */
final class HealthController
{
    public function __construct(
        private readonly string $version,
        private readonly DatabaseCheck $database,
        private readonly Clock $clock,
        private readonly int $phpVersionId = PHP_VERSION_ID,
    ) {
    }

    public function __invoke(): Response
    {
        $dbOk = $this->database->isHealthy();

        return Response::json(
            [
                'app' => 'FeedWatch',
                'version' => $this->version,
                'db' => $dbOk ? 'ok' : 'error',
                'runtime_ok' => $this->phpVersionId >= 80400,
                'time' => $this->clock->now()->format(DateTimeInterface::ATOM),
            ],
            $dbOk ? 200 : 503,
            ['Cache-Control' => 'no-store'],
        );
    }
}
