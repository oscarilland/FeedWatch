<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use FeedWatch\Infrastructure\Clock;

/**
 * The one admin's login, session and CSRF token (NFR-01, ADR-007).
 */
final class AdminAuth
{
    public const int IDLE_US = 30 * 60 * 1_000_000;
    public const int ABSOLUTE_US = 8 * 3600 * 1_000_000;

    public function __construct(
        private readonly SessionStore $session,
        private readonly string $adminUser,
        private readonly string $adminPasswordHash,
        private readonly LoginLimiter $limiter,
        private readonly Clock $clock,
    ) {
    }

    public function configured(): bool
    {
        return $this->adminUser !== '' && $this->adminPasswordHash !== '';
    }

    /**
     * The logged-in admin, or null. Ends a session that has been idle for 30
     * minutes or open for 8 hours. Never starts a session for a visitor.
     */
    public function user(): ?string
    {
        if (!$this->session->exists()) {
            return null;
        }
        $user = $this->session->get('admin_user');
        $loginAt = $this->session->get('login_at');
        $seenAt = $this->session->get('seen_at');
        if (!is_string($user) || !is_int($loginAt) || !is_int($seenAt)) {
            return null;
        }

        $now = $this->nowUs();
        if ($now - $seenAt > self::IDLE_US || $now - $loginAt > self::ABSOLUTE_US) {
            $this->session->destroy();

            return null;
        }
        $this->session->set('seen_at', $now);

        return $user;
    }

    /**
     * This session's CSRF token, creating the session and token if needed.
     */
    public function csrfToken(): string
    {
        $token = $this->session->get('csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf', $token);
        }

        return $token;
    }

    /**
     * Whether the request carries this session's CSRF token, in the
     * X-CSRF-Token header (JavaScript) or the _csrf field (forms).
     */
    public function csrfValid(Request $request): bool
    {
        if (!$this->session->exists()) {
            return false;
        }
        $expected = $this->session->get('csrf');
        $given = $request->header('X-CSRF-Token') ?? $request->form['_csrf'] ?? null;

        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }

    public function login(Request $request, string $user, string $password): LoginResult
    {
        if (!$this->configured()) {
            return LoginResult::NotConfigured;
        }
        // A keyed hash: the stored value can't be reversed into an address
        // without the server's admin hash.
        $ipHash = hash_hmac('sha256', $request->clientIp, $this->adminPasswordHash);
        $now = $this->nowUs();
        if ($this->limiter->isLocked($ipHash, $now)) {
            return LoginResult::Locked;
        }

        // Check both, always, so neither the time taken nor the message
        // reveals which one was wrong.
        $userOk = hash_equals($this->adminUser, $user);
        $passwordOk = password_verify($password, $this->adminPasswordHash);
        if (!$userOk || !$passwordOk) {
            $this->limiter->recordFailure($ipHash, $now);

            return LoginResult::Invalid;
        }

        $this->limiter->clear($ipHash);
        $this->session->regenerate();
        $this->session->set('admin_user', $this->adminUser);
        $this->session->set('login_at', $now);
        $this->session->set('seen_at', $now);
        // A fresh token after login.
        $this->session->set('csrf', bin2hex(random_bytes(32)));

        return LoginResult::Ok;
    }

    public function logout(): void
    {
        if ($this->session->exists()) {
            $this->session->destroy();
        }
    }

    private function nowUs(): int
    {
        return (int) $this->clock->now()->format('Uu');
    }
}
