<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use Closure;
use FeedWatch\Simulation\ChaosFaults;

/**
 * The admin pages (ADR-007): the login form, the panel, logging in and out,
 * and whether the current visitor is an admin.
 */
final class AdminController
{
    /**
     * @param Closure(Request): AdminAuth $auth
     */
    public function __construct(
        private readonly Closure $auth,
        private readonly string $basePath,
    ) {
    }

    /** GET /admin */
    public function page(Request $request): Response
    {
        $auth = ($this->auth)($request);
        if ($auth->user() !== null) {
            return self::noStore(Response::html(View::render('admin', [
                'basePath' => $this->basePath,
                'csrf' => $auth->csrfToken(),
                'presets' => array_map(static fn (array $p): string => $p[0], ChaosFaults::PRESETS),
            ])));
        }

        return $this->loginPage($auth, null, 200);
    }

    /** POST /admin/login */
    public function login(Request $request): Response
    {
        $auth = ($this->auth)($request);
        // A login form posted from another site has no valid token (login CSRF).
        if (!$auth->csrfValid($request)) {
            return $this->loginPage($auth, 'Your session expired. Please try again.', 403);
        }

        return match ($auth->login($request, $request->form['username'] ?? '', $request->form['password'] ?? '')) {
            LoginResult::Ok => Response::redirect($this->basePath . '/admin'),
            LoginResult::Invalid => $this->loginPage($auth, 'Username or password is incorrect.', 401),
            LoginResult::Locked => $this->loginPage($auth, 'Too many failed attempts. Try again in 15 minutes.', 429),
            LoginResult::NotConfigured => $this->loginPage($auth, 'The admin login is switched off on this site.', 503),
        };
    }

    /** POST /admin/logout */
    public function logout(Request $request): Response
    {
        $auth = ($this->auth)($request);
        if ($auth->user() !== null && !$auth->csrfValid($request)) {
            return Response::json(['error' => 'Invalid or missing CSRF token'], 403, ['Cache-Control' => 'no-store']);
        }
        $auth->logout();

        return Response::redirect($this->basePath . '/admin');
    }

    /**
     * GET /api/session: whether this visitor is the admin, and if so the CSRF
     * token for the page's JavaScript. Starts no session for a visitor.
     */
    public function session(Request $request): Response
    {
        $auth = ($this->auth)($request);
        $user = $auth->user();

        return Response::json(
            $user === null ? ['admin' => false] : ['admin' => true, 'user' => $user, 'csrf' => $auth->csrfToken()],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    private function loginPage(AdminAuth $auth, ?string $message, int $status): Response
    {
        return self::noStore(Response::html(View::render('admin-login', [
            'basePath' => $this->basePath,
            'csrf' => $auth->csrfToken(),
            'message' => $message,
            'configured' => $auth->configured(),
        ]), $status));
    }

    private static function noStore(Response $response): Response
    {
        return $response->withHeaders(['Cache-Control' => 'no-store']);
    }
}
