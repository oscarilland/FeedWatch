<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use RuntimeException;

/**
 * PHP sessions with NFR-01's cookie rules: Secure (over HTTPS), HttpOnly,
 * SameSite=Strict and scoped to FeedWatch's sub-folder. Session files live in
 * FeedWatch's own storage/sessions/, which the web server refuses to serve,
 * rather than the host's shared temporary folder.
 */
final class PhpSessionStore implements SessionStore
{
    public const string COOKIE = 'FWSESSION';

    /** Session data is kept no longer than the 8-hour absolute timeout. */
    private const int MAX_LIFETIME_S = 8 * 3600;

    public function __construct(
        private readonly Request $request,
        private readonly string $savePath,
        private readonly string $cookiePath,
    ) {
    }

    public function exists(): bool
    {
        return isset($this->request->cookies[self::COOKIE]);
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0700, true);
        }
        session_cache_limiter('nocache');
        $started = session_start([
            'name' => self::COOKIE,
            'save_path' => $this->savePath,
            'cookie_lifetime' => 0,
            'cookie_path' => $this->cookiePath,
            'cookie_secure' => $this->request->secure,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'use_trans_sid' => false,
            'gc_maxlifetime' => self::MAX_LIFETIME_S,
        ]);
        if (!$started) {
            throw new RuntimeException('Could not start the session');
        }
    }

    public function get(string $key): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        setcookie(self::COOKIE, '', [
            'expires' => 1,
            'path' => $this->cookiePath,
            'secure' => $this->request->secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_destroy();
    }
}
