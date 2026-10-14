<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use FeedWatch\Http\AdminAuth;
use FeedWatch\Http\LoginResult;
use FeedWatch\Http\Request;
use FeedWatch\Tests\Integration\SettableClock;
use PHPUnit\Framework\TestCase;

final class AdminAuthTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery';

    private static string $hash = '';
    private MemorySessionStore $session;
    private MemoryLoginLimiter $limiter;
    private SettableClock $clock;

    public static function setUpBeforeClass(): void
    {
        // A low cost keeps the tests quick; the real script uses cost 12.
        self::$hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    protected function setUp(): void
    {
        $this->session = new MemorySessionStore();
        $this->limiter = new MemoryLoginLimiter();
        $this->clock = new SettableClock(1_791_115_200_000_000);
    }

    private function auth(string $user = 'oscar', ?string $hash = null): AdminAuth
    {
        return new AdminAuth($this->session, $user, $hash ?? self::$hash, $this->limiter, $this->clock);
    }

    private function advanceMinutes(int $minutes): void
    {
        $this->clock->us += $minutes * 60_000_000;
    }

    private static function request(string $ip = '203.0.113.7'): Request
    {
        return Request::make('POST', '/feedwatch/admin/login', [], [], [], $ip);
    }

    public function test_the_right_details_log_in_with_a_new_session_id(): void
    {
        $auth = $this->auth();

        self::assertSame(LoginResult::Ok, $auth->login(self::request(), 'oscar', self::PASSWORD));
        self::assertSame('oscar', $auth->user());
        self::assertSame(1, $this->session->regenerations, 'session fixation: a new ID at login');
    }

    public function test_a_wrong_password_or_username_gives_the_same_answer_and_counts_as_a_failure(): void
    {
        $auth = $this->auth();

        self::assertSame(LoginResult::Invalid, $auth->login(self::request(), 'oscar', 'wrong password'));
        self::assertSame(LoginResult::Invalid, $auth->login(self::request(), 'someone', self::PASSWORD));
        self::assertNull($auth->user());
        self::assertSame([2], array_values($this->limiter->failures));
    }

    public function test_NFR01_AC4_five_failures_lock_even_the_right_password_out(): void
    {
        $auth = $this->auth();
        for ($i = 0; $i < 5; $i++) {
            $auth->login(self::request(), 'oscar', 'guess ' . $i);
        }

        self::assertSame(LoginResult::Locked, $auth->login(self::request(), 'oscar', self::PASSWORD));
        self::assertSame(LoginResult::Ok, $auth->login(self::request('198.51.100.1'), 'oscar', self::PASSWORD), 'another address is not locked');
    }

    public function test_only_a_keyed_hash_of_the_address_is_kept(): void
    {
        $this->auth()->login(self::request('203.0.113.7'), 'oscar', 'wrong');

        $key = array_key_first($this->limiter->failures);
        self::assertIsString($key);
        self::assertSame(64, strlen($key));
        self::assertStringNotContainsString('203.0.113.7', $key);
        self::assertNotSame(hash('sha256', '203.0.113.7'), $key, 'not a plain hash anyone could reverse');
    }

    public function test_success_clears_the_failure_count(): void
    {
        $auth = $this->auth();
        $auth->login(self::request(), 'oscar', 'wrong');
        $auth->login(self::request(), 'oscar', self::PASSWORD);

        self::assertSame([], $this->limiter->failures);
    }

    public function test_no_admin_configured_means_no_login(): void
    {
        self::assertSame(LoginResult::NotConfigured, $this->auth('', '')->login(self::request(), '', ''));
    }

    public function test_a_visitor_without_a_cookie_gets_no_session(): void
    {
        self::assertNull($this->auth()->user());
        self::assertFalse($this->session->started);
    }

    public function test_sessions_end_after_30_idle_minutes(): void
    {
        $auth = $this->auth();
        $auth->login(self::request(), 'oscar', self::PASSWORD);

        $this->advanceMinutes(29);
        self::assertSame('oscar', $auth->user(), 'activity at 29 minutes keeps it alive');
        $this->advanceMinutes(31);
        self::assertNull($auth->user());
        self::assertTrue($this->session->destroyed);
    }

    public function test_sessions_end_after_8_hours_however_active(): void
    {
        $auth = $this->auth();
        $auth->login(self::request(), 'oscar', self::PASSWORD);
        for ($i = 0; $i < 16; $i++) {
            $this->advanceMinutes(29);
            $auth->user();
        }
        $this->advanceMinutes(20);

        self::assertNull($auth->user());
    }

    public function test_NFR01_AC3_csrf_token_must_match_this_session(): void
    {
        $auth = $this->auth();
        $token = $auth->csrfToken();

        self::assertTrue($auth->csrfValid(Request::make('POST', '/x', [], ['X-CSRF-Token' => $token])), 'header');
        self::assertTrue($auth->csrfValid(Request::make('POST', '/x', ['_csrf' => $token])), 'form field');
        self::assertFalse($auth->csrfValid(Request::make('POST', '/x')), 'missing');
        self::assertFalse($auth->csrfValid(Request::make('POST', '/x', ['_csrf' => str_repeat('0', 64)])), 'wrong');
        self::assertFalse($auth->csrfValid(Request::make('POST', '/x', ['_csrf' => bin2hex(random_bytes(32))])), "another session's");
    }

    public function test_no_session_means_no_valid_csrf(): void
    {
        self::assertFalse($this->auth()->csrfValid(Request::make('POST', '/x', ['_csrf' => 'anything'])));
    }

    public function test_logging_in_replaces_the_csrf_token(): void
    {
        $auth = $this->auth();
        $before = $auth->csrfToken();
        $auth->login(self::request(), 'oscar', self::PASSWORD);

        self::assertNotSame($before, $auth->csrfToken());
    }

    public function test_logout_ends_the_session(): void
    {
        $auth = $this->auth();
        $auth->login(self::request(), 'oscar', self::PASSWORD);
        $auth->logout();

        self::assertNull($auth->user());
        self::assertTrue($this->session->destroyed);
    }
}
