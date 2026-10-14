<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use Closure;
use FeedWatch\Infrastructure\ChaosRepository;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\IncidentLog;

/**
 * POST /api/incident-note: an admin adds a note to an incident (FR-06).
 * Notes are plain text, stored as typed and always shown as text.
 */
final class IncidentNoteController
{
    public const int MAX_LENGTH = 500;

    /**
     * @param Closure(Request): AdminAuth $auth
     * @param Closure(): IncidentLog $incidents
     * @param Closure(): ChaosRepository $log
     */
    public function __construct(
        private readonly Closure $auth,
        private readonly Closure $incidents,
        private readonly Closure $log,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        [$user, $refusal] = AdminGuard::check(($this->auth)($request), $request, true);
        if ($refusal !== null || $user === null) {
            return $refusal ?? Response::json(['error' => 'Admin login required'], 403);
        }

        $id = $request->form['id'] ?? '';
        $text = trim($request->form['text'] ?? '');
        if (preg_match('/^\d{1,10}$/', $id) !== 1) {
            return self::badRequest('id must be an incident number');
        }
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return self::badRequest('A note must be 1 to ' . self::MAX_LENGTH . ' characters');
        }

        $incidents = ($this->incidents)();
        if ($incidents->find((int) $id) === null) {
            return Response::json(['error' => 'Incident not found'], 404, ['Cache-Control' => 'no-store']);
        }
        $now = (int) $this->clock->now()->format('Uu');
        $incidents->addNote((int) $id, $user, $text, $now);
        ($this->log)()->log($user, 'note', "incident {$id}", mb_substr($text, 0, 100), $now);

        return Response::json(['ok' => true], 200, ['Cache-Control' => 'no-store']);
    }

    private static function badRequest(string $message): Response
    {
        return Response::json(['error' => $message], 400, ['Cache-Control' => 'no-store']);
    }
}
