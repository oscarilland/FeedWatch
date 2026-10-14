<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use FeedWatch\Http\Request;
use FeedWatch\Http\Response;
use FeedWatch\Http\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(string $basePath = '/feedwatch'): Router
    {
        $router = new Router($basePath);
        $router->get('/', static fn (): Response => Response::html('home'));
        $router->get('/api/health', static fn (): Response => Response::json(['ok' => true]));
        $router->post('/api/chaos', static fn (): Response => Response::json(['posted' => true]));

        return $router;
    }

    public function test_known_route_runs_its_handler(): void
    {
        $response = $this->router()->dispatch(Request::make('GET', '/feedwatch/api/health'));

        self::assertSame(200, $response->status);
        self::assertSame('{"ok":true}', $response->body);
    }

    public function test_query_string_is_ignored_when_matching(): void
    {
        self::assertSame(200, $this->router()->dispatch(Request::make('GET', '/feedwatch/api/health?x=1'))->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function homeUris(): array
    {
        return [
            'base path alone' => ['/feedwatch'],
            'base path with slash' => ['/feedwatch/'],
        ];
    }

    #[DataProvider('homeUris')]
    public function test_base_path_maps_to_home(string $uri): void
    {
        self::assertSame('home', $this->router()->dispatch(Request::make('GET', $uri))->body);
    }

    public function test_unknown_api_route_is_json_404(): void
    {
        $response = $this->router()->dispatch(Request::make('GET', '/feedwatch/api/nope'));

        self::assertSame(404, $response->status);
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
    }

    public function test_unknown_page_is_html_404(): void
    {
        $response = $this->router()->dispatch(Request::make('GET', '/feedwatch/nope'));

        self::assertSame(404, $response->status);
        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
        self::assertStringContainsString('href="/feedwatch/assets/css/app.css"', $response->body);
    }

    public function test_path_outside_base_path_is_404(): void
    {
        self::assertSame(404, $this->router()->dispatch(Request::make('GET', '/api/health'))->status);
        self::assertSame(404, $this->router()->dispatch(Request::make('GET', '/feedwatchx/api/health'))->status);
    }

    public function test_wrong_method_is_405_with_allow_header(): void
    {
        $response = $this->router()->dispatch(Request::make('POST', '/feedwatch/api/health'));

        self::assertSame(405, $response->status);
        self::assertSame('GET, HEAD', $response->header('Allow'));
    }

    public function test_get_on_post_only_route_is_405(): void
    {
        $response = $this->router()->dispatch(Request::make('GET', '/feedwatch/api/chaos'));

        self::assertSame(405, $response->status);
        self::assertSame('POST', $response->header('Allow'));
    }

    public function test_head_is_answered_by_the_get_handler(): void
    {
        self::assertSame(200, $this->router()->dispatch(Request::make('HEAD', '/feedwatch/api/health'))->status);
    }

    public function test_handlers_receive_string_query_parameters_only(): void
    {
        $received = null;
        $router = new Router('/feedwatch');
        $router->get('/api/latency', static function (Request $request) use (&$received): Response {
            $received = $request->query;

            return Response::json([]);
        });

        $router->dispatch(Request::make('GET', '/feedwatch/api/latency?exchange=THX&since=5&list[]=1'));

        self::assertSame(['exchange' => 'THX', 'since' => '5'], $received);
    }

    public function test_empty_base_path_serves_from_the_root(): void
    {
        self::assertSame(200, $this->router('')->dispatch(Request::make('GET', '/api/health'))->status);
    }
}
