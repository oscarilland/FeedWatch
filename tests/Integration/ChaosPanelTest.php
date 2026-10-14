<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Integration;

use FeedWatch\Http\AdminAuth;
use FeedWatch\Http\ChaosController;
use FeedWatch\Http\IncidentNoteController;
use FeedWatch\Http\Request;
use FeedWatch\Http\SettingsController;
use FeedWatch\Infrastructure\ChaosRepository;
use FeedWatch\Infrastructure\IncidentLog;
use FeedWatch\Infrastructure\IncidentRepository;
use FeedWatch\Infrastructure\LoginThrottle;
use FeedWatch\Infrastructure\SettingsRepository;
use FeedWatch\Infrastructure\SimulationRunner;
use FeedWatch\Infrastructure\SimulationStatus;
use FeedWatch\Infrastructure\StoredChaos;
use FeedWatch\Tests\Unit\Http\MemoryLoginLimiter;
use FeedWatch\Tests\Unit\Http\MemorySessionStore;

final class ChaosPanelTest extends DatabaseTestCase
{
    private const int START_US = 1_791_115_200_000_000;
    private const string PASSWORD = 'correct horse battery';

    private SettableClock $clock;
    private AdminAuth $auth;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshSchema();
        $this->clock = new SettableClock(self::START_US);
        $this->auth = new AdminAuth(new MemorySessionStore(), 'oscar', password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]), new MemoryLoginLimiter(), $this->clock);
        $this->auth->login(Request::make('POST', '/admin/login'), 'oscar', self::PASSWORD);
        $this->token = $this->auth->csrfToken();
        $this->catchUp();
        $this->runFor(4);
    }

    private function catchUp(int $afterSeconds = 0): void
    {
        $this->clock->advanceSeconds($afterSeconds);
        $chaos = new StoredChaos(new ChaosRepository($this->pdo), $this->clock);
        (new SimulationRunner($this->pdo, $this->clock, $chaos, (new SettingsRepository($this->pdo))->thresholds()))->catchUp();
    }

    private function runFor(int $seconds): void
    {
        for ($t = 0; $t < $seconds; $t++) {
            $this->catchUp(1);
        }
    }

    /**
     * @param array<string, string> $form
     * @return array{int, array<string, mixed>}
     */
    private function chaos(array $form): array
    {
        $controller = new ChaosController(
            fn (): AdminAuth => $this->auth,
            fn (): ChaosRepository => new ChaosRepository($this->pdo),
            fn (): SimulationStatus => new SimulationStatus($this->pdo),
            $this->clock,
            static fn (): int => 5 * 60_000_000,
        );
        $response = $controller->change(Request::make('POST', '/api/chaos', $form + ['_csrf' => $this->token]));
        $data = json_decode($response->body, true);

        return [$response->status, is_array($data) ? $data : []];
    }

    /**
     * Tokyo's (exchange 6) deliveries on one feed since a time. Fixed queries,
     * so no SQL is built from a value.
     */
    private function thxCount(string $column, int $sinceUs): int
    {
        $sql = $column === 'a_received_us'
            ? 'SELECT COUNT(a_received_us) AS n FROM messages WHERE exchange_id = 6 AND sent_us >= ?'
            : 'SELECT COUNT(b_received_us) AS n FROM messages WHERE exchange_id = 6 AND sent_us >= ?';
        $count = $this->row($sql, [$sinceUs])['n'];
        self::assertIsInt($count);

        return $count;
    }

    public function test_FR07_AC1_silencing_tokyo_A_takes_effect_from_the_next_step_while_B_carries_on(): void
    {
        [$status] = $this->chaos(['action' => 'preset', 'name' => 'break_tokyo_a']);
        self::assertSame(200, $status);
        $from = $this->clock->us;
        $this->runFor(5);

        self::assertSame(0, $this->thxCount('a_received_us', $from), 'no A deliveries for Tokyo');
        self::assertGreaterThan(20, $this->thxCount('b_received_us', $from), 'B carries on');
        self::assertSame(1, $this->row("SELECT COUNT(*) AS n FROM incidents WHERE type = 'failover' AND exchange_id = 6 AND feed = 'A'")['n']);
    }

    public function test_FR07_AC2_every_action_is_logged_with_who_what_and_when(): void
    {
        $this->chaos(['action' => 'set', 'exchange' => 'RHX', 'feed' => 'B', 'kind' => 'delay', 'value' => '40']);
        $this->chaos(['action' => 'restore_all']);

        $log = (new ChaosRepository($this->pdo))->recentLog(10);
        self::assertSame([['restore_all', 'all'], ['set', 'RHX B']], array_map(static fn (array $e): array => [$e['action'], $e['target']], $log));
        self::assertSame('oscar', $log[0]['username']);
        self::assertSame(self::START_US + 4_000_000, $log[0]['at_us']);
        self::assertSame('delay 40', $log[1]['details']);
    }

    public function test_FR07_AC3_restore_one_or_all(): void
    {
        $this->chaos(['action' => 'preset', 'name' => 'fibre_cut_asia']);
        self::assertSame(3, $this->countRows('chaos_faults'));

        [, $data] = $this->chaos(['action' => 'restore', 'exchange' => 'PRX']);
        self::assertIsArray($data['faults']);
        self::assertSame(['KTX', 'HBX'], array_column($data['faults'], 'exchange'));

        $this->chaos(['action' => 'restore_all']);
        self::assertSame(0, $this->countRows('chaos_faults'));
    }

    public function test_FR07_AC4_faults_are_ignored_once_expired_and_then_removed(): void
    {
        $this->chaos(['action' => 'preset', 'name' => 'break_tokyo_a']);
        $this->clock->advanceSeconds(5 * 60 + 1);

        $repository = new ChaosRepository($this->pdo);
        self::assertSame([], $repository->active($this->clock->us), 'expired faults no longer apply');
        $expired = $repository->expire($this->clock->us);
        self::assertSame(['KTX'], array_map(static fn (array $f): string => $f['exchange'], $expired));
        self::assertSame(0, $this->countRows('chaos_faults'));
    }

    public function test_the_data_loss_preset_gives_one_data_loss_incident(): void
    {
        $this->chaos(['action' => 'preset', 'name' => 'data_loss_tokyo']);
        $this->runFor(8);

        $incident = $this->row("SELECT type, severity, details FROM incidents WHERE exchange_id = 6");
        self::assertSame(['data_loss', 'critical'], [$incident['type'], $incident['severity']]);
        self::assertIsString($incident['details']);
        self::assertSame(['messages_lost' => 6], json_decode($incident['details'], true));
    }

    public function test_invalid_chaos_requests_are_400s_and_change_nothing(): void
    {
        foreach ([
            ['action' => 'explode'],
            ['action' => 'preset', 'name' => 'nope'],
            ['action' => 'set', 'exchange' => 'ZZZ', 'feed' => 'A', 'kind' => 'silence', 'value' => '0'],
            ['action' => 'set', 'exchange' => 'KTX', 'feed' => 'C', 'kind' => 'silence', 'value' => '0'],
            ['action' => 'set', 'exchange' => 'KTX', 'feed' => 'A', 'kind' => 'delay', 'value' => '5000'],
            ['action' => 'set', 'exchange' => 'KTX', 'feed' => 'A', 'kind' => 'drop', 'value' => '101'],
            ['action' => 'set', 'exchange' => 'KTX', 'feed' => 'A', 'kind' => 'drop', 'value' => '-5'],
        ] as $form) {
            [$status] = $this->chaos($form);
            self::assertSame(400, $status, json_encode($form, JSON_THROW_ON_ERROR));
        }
        self::assertSame(0, $this->countRows('chaos_faults'));
        self::assertSame(0, $this->countRows('admin_log'));
    }

    public function test_a_fault_wakes_the_simulator_from_idle_mode(): void
    {
        $this->chaos(['action' => 'preset', 'name' => 'slow_frankfurt_a']);

        self::assertSame($this->clock->us, $this->row('SELECT last_viewed_us FROM simulation_state')['last_viewed_us']);
    }

    public function test_NFR01_AC4_the_database_throttle_locks_after_five_failures_and_lifts_after_fifteen_minutes(): void
    {
        $throttle = new LoginThrottle($this->pdo);
        $ip = str_repeat('a', 64);
        for ($i = 0; $i < 4; $i++) {
            $throttle->recordFailure($ip, self::START_US + $i);
        }
        self::assertFalse($throttle->isLocked($ip, self::START_US + 10));
        $throttle->recordFailure($ip, self::START_US + 5);
        self::assertTrue($throttle->isLocked($ip, self::START_US + 10));
        self::assertFalse($throttle->isLocked(str_repeat('b', 64), self::START_US + 10), 'other addresses are fine');
        self::assertFalse($throttle->isLocked($ip, self::START_US + LoginThrottle::WINDOW_US + 10), 'lifts after 15 minutes');

        $throttle->clear($ip);
        self::assertFalse($throttle->isLocked($ip, self::START_US + 10));
    }

    public function test_the_overall_limit_stops_guessing_from_many_addresses(): void
    {
        $throttle = new LoginThrottle($this->pdo);
        for ($i = 0; $i < 20; $i++) {
            $throttle->recordFailure(hash('sha256', (string) $i), self::START_US);
        }

        self::assertTrue($throttle->isLocked(str_repeat('c', 64), self::START_US + 1));
    }

    public function test_settings_change_takes_effect_and_is_logged_but_bad_values_are_refused(): void
    {
        $controller = new SettingsController(fn (): AdminAuth => $this->auth, fn (): SettingsRepository => new SettingsRepository($this->pdo), fn (): ChaosRepository => new ChaosRepository($this->pdo), $this->clock);
        $post = fn (string $name, string $value): int => $controller->change(Request::make('POST', '/api/settings', ['name' => $name, 'value' => $value, '_csrf' => $this->token]))->status;

        self::assertSame(200, $post('chaos_expiry_min', '10'));
        self::assertSame(600_000_000, (new SettingsRepository($this->pdo))->thresholds()->chaosExpiryUs);
        self::assertSame(400, $post('chaos_expiry_min', '90'), 'out of range');
        self::assertSame(400, $post('latency_red_multiple', '2'), 'red must stay above amber');
        self::assertSame(400, $post('no_such_setting', '1'));
        self::assertSame(400, $post('silence_ms', '2000; DROP TABLE settings'));
        self::assertSame(1, $this->row("SELECT COUNT(*) AS n FROM admin_log WHERE action = 'setting'")['n']);
    }

    public function test_a_note_is_stored_shown_with_the_incident_and_logged(): void
    {
        $this->chaos(['action' => 'preset', 'name' => 'break_tokyo_a']);
        $this->runFor(5);
        $id = $this->row('SELECT id FROM incidents')['id'];
        self::assertIsInt($id);

        $controller = new IncidentNoteController(fn (): AdminAuth => $this->auth, fn (): IncidentLog => new IncidentRepository($this->pdo), fn (): ChaosRepository => new ChaosRepository($this->pdo), $this->clock);
        $response = $controller(Request::make('POST', '/api/incident-note', ['id' => (string) $id, 'text' => 'Demo: cut Tokyo A <on purpose>', '_csrf' => $this->token]));

        self::assertSame(200, $response->status);
        $found = (new IncidentRepository($this->pdo))->find($id);
        self::assertNotNull($found);
        self::assertSame([['user' => 'oscar', 'text' => 'Demo: cut Tokyo A <on purpose>']], array_map(static fn (array $n): array => ['user' => $n['user'], 'text' => $n['text']], $found['notes']));
        self::assertSame(1, $this->row("SELECT COUNT(*) AS n FROM admin_log WHERE action = 'note'")['n']);
    }

    public function test_the_public_banner_data_never_names_the_admin(): void
    {
        $this->chaos(['action' => 'preset', 'name' => 'break_tokyo_a']);

        $public = ChaosController::publicFaults((new ChaosRepository($this->pdo))->active($this->clock->us));
        self::assertSame([['exchange' => 'KTX', 'feed' => 'A', 'kind' => 'silence', 'value' => 0, 'expires_at' => gmdate('Y-m-d\TH:i:s', intdiv($this->clock->us, 1_000_000) + 300) . 'Z']], $public);
        self::assertStringNotContainsString('oscar', (string) json_encode($public));
    }
}
