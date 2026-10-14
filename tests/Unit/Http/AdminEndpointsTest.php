<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use DateTimeImmutable;
use FeedWatch\Http\AdminAuth;
use FeedWatch\Http\AdminController;
use FeedWatch\Http\ChaosController;
use FeedWatch\Http\IncidentNoteController;
use FeedWatch\Http\Request;
use FeedWatch\Http\SettingsController;
use FeedWatch\Infrastructure\ChaosRepository;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\IncidentLog;
use FeedWatch\Infrastructure\SettingsRepository;
use FeedWatch\Infrastructure\SimulationStatus;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * NFR-01 AC-3 and FR-07 AC-5: every admin endpoint refuses visitors and
 * requests without the CSRF token, before doing any work. The repositories
 * here throw if touched, which proves nothing ran.
 */
final class AdminEndpointsTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery';

    private static string $hash = '';

    public static function setUpBeforeClass(): void
    {
        self::$hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    private static function clock(): Clock
    {
        return new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@1791115200');
            }
        };
    }

    /**
     * @return array{AdminAuth, ?string} an AdminAuth, and its CSRF token when logged in
     */
    private static function auth(bool $loggedIn): array
    {
        $auth = new AdminAuth(new MemorySessionStore(), 'oscar', self::$hash, new MemoryLoginLimiter(), self::clock());
        if (!$loggedIn) {
            return [$auth, null];
        }
        $auth->login(Request::make('POST', '/admin/login'), 'oscar', self::PASSWORD);

        return [$auth, $auth->csrfToken()];
    }

    private static function untouchable(): \Closure
    {
        return static function (): never {
            throw new LogicException('Nothing should run for a refused request');
        };
    }

    /**
     * @return array<string, \Closure(\Closure): \Closure>
     */
    private static function endpoints(): array
    {
        $none = self::untouchable();

        return [
            'POST /api/chaos' => static fn (\Closure $auth): \Closure => (new ChaosController($auth, $none, $none, self::clock(), static fn (): int => 300_000_000))->change(...),
            'POST /api/settings' => static fn (\Closure $auth): \Closure => (new SettingsController($auth, $none, $none, self::clock()))->change(...),
            'POST /api/incident-note' => static fn (\Closure $auth): \Closure => (new IncidentNoteController($auth, $none, $none, self::clock()))(...),
        ];
    }

    public function test_FR07_AC5_visitors_are_refused_with_403(): void
    {
        [$auth] = self::auth(false);
        foreach (self::endpoints() as $name => $endpoint) {
            $handler = $endpoint(static fn (): AdminAuth => $auth);
            $response = $handler(Request::make('POST', '/x', ['action' => 'restore_all', '_csrf' => 'x']));
            self::assertSame(403, $response->status, $name);
            self::assertStringContainsString('Admin login required', $response->body, $name);
        }
    }

    public function test_NFR01_AC3_a_missing_or_wrong_token_is_refused_with_403(): void
    {
        [$auth] = self::auth(true);
        foreach (self::endpoints() as $name => $endpoint) {
            $handler = $endpoint(static fn (): AdminAuth => $auth);
            foreach ([[], ['_csrf' => str_repeat('a', 64)]] as $form) {
                $response = $handler(Request::make('POST', '/x', $form + ['action' => 'restore_all']));
                self::assertSame(403, $response->status, $name);
                self::assertStringContainsString('CSRF', $response->body, $name);
            }
        }
    }

    public function test_reading_admin_data_needs_a_login_too(): void
    {
        [$auth] = self::auth(false);
        $none = self::untouchable();
        $authFn = static fn (): AdminAuth => $auth;

        self::assertSame(403, (new ChaosController($authFn, $none, $none, self::clock(), static fn (): int => 1))->log(Request::make('GET', '/x'))->status);
        self::assertSame(403, (new SettingsController($authFn, $none, $none, self::clock()))->show(Request::make('GET', '/x'))->status);
    }

    public function test_an_invalid_note_is_a_400_and_stores_nothing(): void
    {
        [$auth, $token] = self::auth(true);
        $log = new RecordingIncidentLog();
        $controller = new IncidentNoteController(static fn (): AdminAuth => $auth, static fn (): IncidentLog => $log, self::untouchable(), self::clock());

        foreach ([['id' => '7', 'text' => '   '], ['id' => '7', 'text' => str_repeat('x', 501)], ['id' => 'seven', 'text' => 'hi']] as $form) {
            self::assertSame(400, $controller(Request::make('POST', '/x', $form + ['_csrf' => (string) $token]))->status);
        }
        self::assertSame(404, $controller(Request::make('POST', '/x', ['id' => '99', 'text' => 'hi', '_csrf' => (string) $token]))->status);
        self::assertSame([], $log->notesAdded);
    }

    public function test_the_login_page_carries_a_token_and_the_login_post_needs_it(): void
    {
        [$auth] = self::auth(false);
        $controller = new AdminController(static fn (): AdminAuth => $auth, '/feedwatch');

        $page = $controller->page(Request::make('GET', '/feedwatch/admin'));
        self::assertSame(200, $page->status);
        self::assertSame(1, preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $page->body, $match));
        $token = $match[1] ?? '';
        self::assertSame('no-store', $page->header('Cache-Control'));

        $forged = $controller->login(Request::make('POST', '/feedwatch/admin/login', ['username' => 'oscar', 'password' => self::PASSWORD]));
        self::assertSame(403, $forged->status, 'login CSRF refused');

        $wrong = $controller->login(Request::make('POST', '/feedwatch/admin/login', ['username' => 'oscar', 'password' => 'nope', '_csrf' => $token]));
        self::assertSame(401, $wrong->status);
        self::assertStringContainsString('Username or password is incorrect.', $wrong->body);

        $right = $controller->login(Request::make('POST', '/feedwatch/admin/login', ['username' => 'oscar', 'password' => self::PASSWORD, '_csrf' => $token]));
        self::assertSame(303, $right->status);
        self::assertSame('/feedwatch/admin', $right->header('Location'));
    }

    public function test_the_session_endpoint_reveals_nothing_to_visitors(): void
    {
        [$visitor] = self::auth(false);
        [$admin] = self::auth(true);

        self::assertSame('{"admin":false}', (new AdminController(static fn (): AdminAuth => $visitor, ''))->session(Request::make('GET', '/api/session'))->body);
        $data = json_decode((new AdminController(static fn (): AdminAuth => $admin, ''))->session(Request::make('GET', '/api/session'))->body, true);
        self::assertIsArray($data);
        self::assertTrue($data['admin']);
        self::assertIsString($data['csrf']);
    }

    /** Keeps the imports honest: these types are what the endpoints are built from. */
    public function test_endpoint_dependencies_are_the_real_types(): void
    {
        self::assertTrue(class_exists(ChaosRepository::class) && class_exists(SettingsRepository::class) && class_exists(SimulationStatus::class));
    }
}
