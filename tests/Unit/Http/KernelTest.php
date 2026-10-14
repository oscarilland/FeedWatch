<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use FeedWatch\Http\Kernel;
use FeedWatch\Http\Request;
use FeedWatch\Http\SecurityHeaders;
use FeedWatch\Infrastructure\Config;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    private function kernel(): Kernel
    {
        return Kernel::create(new Config(['APP_BASE_PATH' => '/feedwatch']));
    }

    public function test_the_dashboard_loads_its_vendored_assets_under_the_base_path(): void
    {
        $response = $this->kernel()->handle(Request::make('GET', '/feedwatch/'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('data-base-path="/feedwatch"', $response->body);
        self::assertStringContainsString('href="/feedwatch/assets/css/tokens.css"', $response->body);
        self::assertStringContainsString('src="/feedwatch/assets/vendor/leaflet-1.9.4/leaflet.js"', $response->body);
        self::assertStringContainsString('src="/feedwatch/assets/vendor/chart.js-4.5.1/chart.umd.min.js"', $response->body);
        self::assertStringContainsString('<script type="module" src="/feedwatch/assets/js/dashboard.js"></script>', $response->body);
        self::assertStringContainsString('id="stale-banner"', $response->body);
    }

    public function test_phones_open_the_dashboard_on_the_map(): void
    {
        $body = $this->kernel()->handle(Request::make('GET', '/feedwatch/'))->body;

        // Rendered as the map view, so the list never flashes up first.
        self::assertStringContainsString('<main id="main" class="dashboard" data-view="map">', $body);
        self::assertStringContainsString('id="show-map" aria-pressed="true"', $body);
        self::assertStringContainsString('id="show-list" aria-pressed="false"', $body);
    }

    public function test_the_incident_page_loads_its_module(): void
    {
        $response = $this->kernel()->handle(Request::make('GET', '/feedwatch/incidents'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('src="/feedwatch/assets/js/incidents.js"', $response->body);
        self::assertStringContainsString('aria-current="page">Incidents</a>', $response->body);
    }

    public function test_the_about_page_explains_the_app_with_an_accessible_diagram(): void
    {
        $response = $this->kernel()->handle(Request::make('GET', '/feedwatch/about'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('aria-current="page">About</a>', $response->body);
        foreach (['id="problem"', 'id="how-it-works"', 'id="architecture"', 'id="process"', 'id="limits"'] as $section) {
            self::assertStringContainsString($section, $response->body);
        }
        // The diagram is named and described for screen readers (NFR-02).
        self::assertStringContainsString('role="img" aria-labelledby="diagram-title diagram-desc"', $response->body);
        self::assertMatchesRegularExpression('/<desc id="diagram-desc">[^<]{100,}<\/desc>/', $response->body);
        // A static page: no module, so it works even with JavaScript off.
        self::assertStringNotContainsString('type="module"', $response->body);
    }

    public function test_the_about_page_says_the_data_is_fictional_and_the_idea_borrowed(): void
    {
        $body = $this->kernel()->handle(Request::make('GET', '/feedwatch/about'))->body;

        self::assertStringContainsString('inspired by', $body);
        self::assertStringContainsString('fictional', $body);
    }

    public function test_every_page_links_to_the_about_page(): void
    {
        foreach (['/feedwatch/', '/feedwatch/incidents', '/feedwatch/missing'] as $uri) {
            $body = $this->kernel()->handle(Request::make('GET', $uri))->body;
            self::assertStringContainsString('href="/feedwatch/about"', $body, $uri);
        }
    }

    public function test_pages_have_no_inline_scripts_or_styles_so_the_csp_stays_strict(): void
    {
        foreach (['/feedwatch/', '/feedwatch/incidents', '/feedwatch/about', '/feedwatch/missing'] as $uri) {
            $body = $this->kernel()->handle(Request::make('GET', $uri))->body;
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/', $body, "{$uri} has an inline script");
            self::assertStringNotContainsString('<style', $body, "{$uri} has a style element");
            self::assertDoesNotMatchRegularExpression('/\sstyle="/', $body, "{$uri} has an inline style");
        }
    }

    public function test_NFR01_AC5_every_response_has_the_security_headers(): void
    {
        foreach (['/feedwatch/', '/feedwatch/missing', '/feedwatch/api/missing'] as $uri) {
            $response = $this->kernel()->handle(Request::make('GET', $uri));
            self::assertSame(SecurityHeaders::CONTENT_SECURITY_POLICY, $response->header('Content-Security-Policy'), $uri);
            self::assertStringContainsString("frame-ancestors 'none'", (string) $response->header('Content-Security-Policy'));
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'), $uri);
            self::assertNotNull($response->header('Referrer-Policy'), $uri);
        }
    }

    public function test_the_csp_allows_map_tiles_from_one_host_as_images_only(): void
    {
        $csp = SecurityHeaders::CONTENT_SECURITY_POLICY;

        self::assertStringContainsString("img-src 'self' data: https://tile.openstreetmap.org;", $csp);
        self::assertSame(1, substr_count($csp, 'openstreetmap'), 'the tile host appears nowhere but img-src');
        self::assertStringContainsString("script-src 'self';", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
    }
}
