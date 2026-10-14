<?php

declare(strict_types=1);

namespace FeedWatch\Http;

/**
 * The check in front of every admin endpoint: a logged-in admin, and for
 * anything that changes state, a valid CSRF token (NFR-01 AC-3, FR-07 AC-5).
 * Both are checked before any work is done.
 */
final class AdminGuard
{
    /**
     * @return array{?string, ?Response} the admin's name, or a 403 to send instead
     */
    public static function check(AdminAuth $auth, Request $request, bool $changesState): array
    {
        $user = $auth->user();
        if ($user === null) {
            return [null, Response::json(['error' => 'Admin login required'], 403, ['Cache-Control' => 'no-store'])];
        }
        if ($changesState && !$auth->csrfValid($request)) {
            return [null, Response::json(['error' => 'Invalid or missing CSRF token'], 403, ['Cache-Control' => 'no-store'])];
        }

        return [$user, null];
    }
}
