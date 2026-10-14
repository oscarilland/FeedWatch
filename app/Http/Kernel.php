<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use FeedWatch\Detection\Thresholds;
use FeedWatch\Infrastructure\Config;
use FeedWatch\Infrastructure\Database;
use FeedWatch\Infrastructure\ExchangeRepository;
use FeedWatch\Infrastructure\ExchangeDetailRepository;
use FeedWatch\Infrastructure\ExchangeDetails;
use FeedWatch\Infrastructure\ExchangeSnapshots;
use FeedWatch\Infrastructure\IncidentLog;
use FeedWatch\Infrastructure\IncidentRepository;
use FeedWatch\Infrastructure\LatencyHistory;
use FeedWatch\Infrastructure\LatencyRepository;
use FeedWatch\Infrastructure\PdoDatabaseCheck;
use FeedWatch\Infrastructure\SettingsRepository;
use FeedWatch\Infrastructure\SimulationRunner;
use FeedWatch\Infrastructure\SimulationStatus;
use FeedWatch\Infrastructure\SystemClock;
use FeedWatch\Infrastructure\ChaosRepository;
use FeedWatch\Infrastructure\LoginThrottle;
use FeedWatch\Infrastructure\StoredChaos;
use PDO;

/**
 * Wires the routes to their handlers. The front controller's only job is to
 * call handle() and send the result.
 */
final class Kernel
{
    /** A poll within this long of the last catch-up serves stored data (ADR-005). */
    public const int POLL_CACHE_US = 1_000_000;

    public function __construct(private readonly Router $router)
    {
    }

    /**
     * @param string $storageDir where sessions are kept (storage/, blocked from the web)
     */
    public static function create(Config $config, string $storageDir = ''): self
    {
        $basePath = $config->basePath();
        $router = new Router($basePath);
        $clock = new SystemClock();
        $storageDir = $storageDir !== '' ? $storageDir : sys_get_temp_dir() . '/feedwatch-storage';

        // One database connection per request, opened only if a handler needs it.
        $pdo = null;
        $connect = static function () use ($config, &$pdo): PDO {
            return $pdo ??= Database::connect($config);
        };
        // Thresholds are read from the settings table at most once per request.
        $thresholds = null;
        $loadThresholds = static function () use ($connect, &$thresholds): Thresholds {
            return $thresholds ??= (new SettingsRepository($connect()))->thresholds();
        };

        // Pages: server-rendered shells that the JavaScript modules fill in.
        $router->get('/', static fn (): Response => Response::html(View::render('dashboard', ['basePath' => $basePath])));
        $router->get('/incidents', static fn (): Response => Response::html(View::render('incidents', ['basePath' => $basePath])));
        // Static, so it needs no database and works even if the database is down (plan 008).
        $router->get('/about', static fn (): Response => Response::html(View::render('about', ['basePath' => $basePath])));

        $router->get('/api/health', new HealthController($config->version(), new PdoDatabaseCheck($connect), $clock));

        $chaos = static fn (): ChaosRepository => new ChaosRepository($connect());

        $router->get('/api/exchanges', new ExchangesController(
            static function () use ($connect, $clock, $loadThresholds, $chaos): void {
                // Someone is watching: keep cron simulating (idle mode, plan 004).
                (new SimulationStatus($connect()))->markViewed((int) $clock->now()->format('Uu'));
                (new SimulationRunner($connect(), $clock, new StoredChaos($chaos(), $clock), $loadThresholds()))->catchUp(self::POLL_CACHE_US);
            },
            static fn (): ExchangeSnapshots => new ExchangeRepository($connect(), $loadThresholds()),
            $clock,
            static fn (): array => ChaosController::publicFaults($chaos()->active((int) $clock->now()->format('Uu'))),
        ));

        $router->get('/api/latency', new LatencyController(
            static fn (): LatencyHistory => new LatencyRepository($connect()),
            $clock,
        ));

        $router->get('/api/exchange', new ExchangeDetailController(
            static fn (): ExchangeDetails => new ExchangeDetailRepository($connect()),
            $clock,
        ));

        $incidentLog = static fn (): IncidentLog => new IncidentRepository($connect());
        $incidents = new IncidentsController($incidentLog, $clock);
        $router->get('/api/incidents', $incidents->list(...));
        $router->get('/api/incident', $incidents->detail(...));

        // The admin (plan 007, ADR-007). A session only starts for the login
        // page or a logged-in admin; visitors never get a cookie.
        $auth = static fn (Request $request): AdminAuth => new AdminAuth(
            new PhpSessionStore($request, $storageDir . '/sessions', $basePath === '' ? '/' : $basePath),
            $config->get('ADMIN_USER'),
            $config->get('ADMIN_PASSWORD_HASH'),
            new LoginThrottle($connect()),
            $clock,
        );

        $admin = new AdminController($auth, $basePath);
        $router->get('/admin', $admin->page(...));
        $router->post('/admin/login', $admin->login(...));
        $router->post('/admin/logout', $admin->logout(...));
        $router->get('/api/session', $admin->session(...));

        $chaosController = new ChaosController(
            $auth,
            $chaos,
            static fn (): SimulationStatus => new SimulationStatus($connect()),
            $clock,
            static fn (): int => $loadThresholds()->chaosExpiryUs,
        );
        $router->post('/api/chaos', $chaosController->change(...));
        $router->get('/api/admin-log', $chaosController->log(...));

        $settings = new SettingsController($auth, static fn (): SettingsRepository => new SettingsRepository($connect()), $chaos, $clock);
        $router->get('/api/settings', $settings->show(...));
        $router->post('/api/settings', $settings->change(...));

        $router->post('/api/incident-note', new IncidentNoteController($auth, $incidentLog, $chaos, $clock));

        return new self($router);
    }

    public function handle(Request $request): Response
    {
        return SecurityHeaders::apply($this->router->dispatch($request));
    }
}
