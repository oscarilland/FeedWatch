<?php

declare(strict_types=1);

namespace FeedWatch\Http;

/**
 * Where a logged-in admin's session lives. PHP's own sessions in production;
 * an in-memory version in tests.
 */
interface SessionStore
{
    /** Whether the browser sent a session cookie. Ordinary visitors never have one. */
    public function exists(): bool;

    /** Starts or resumes the session, sending the cookie if it's new. */
    public function start(): void;

    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    /** A new session ID, keeping the data: done at login, against session fixation. */
    public function regenerate(): void;

    /** Ends the session and expires its cookie. */
    public function destroy(): void;
}
