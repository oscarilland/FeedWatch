<?php

declare(strict_types=1);

namespace FeedWatch\Http;

/**
 * Maps a method and path to a handler. Paths are matched exactly, after the
 * configured base path (the sub-folder the app is served from) is removed.
 */
final class Router
{
    /** @var array<string, array<string, callable(Request): Response>> path => method => handler */
    private array $routes = [];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param callable(Request): Response $handler
     */
    public function get(string $path, callable $handler): void
    {
        $this->routes[$path]['GET'] = $handler;
    }

    /**
     * @param callable(Request): Response $handler
     */
    public function post(string $path, callable $handler): void
    {
        $this->routes[$path]['POST'] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $path = $this->appPath($request->path());
        if ($path === null || !isset($this->routes[$path])) {
            return $this->notFound($path ?? '');
        }

        $handlers = $this->routes[$path];
        // HEAD is a GET without the body; the front controller drops the body.
        $lookup = $request->method === 'HEAD' ? 'GET' : $request->method;
        if (!isset($handlers[$lookup])) {
            $allowed = array_keys($handlers);
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }

            return Response::json(['error' => 'Method not allowed'], 405, ['Allow' => implode(', ', $allowed)]);
        }

        return ($handlers[$lookup])($request);
    }

    /**
     * The path inside the app, or null if the URI is outside the base path.
     */
    private function appPath(string $path): ?string
    {
        if ($this->basePath === '') {
            return $path;
        }
        if ($path === $this->basePath) {
            return '/';
        }
        if (str_starts_with($path, $this->basePath . '/')) {
            return substr($path, strlen($this->basePath));
        }

        return null;
    }

    private function notFound(string $path): Response
    {
        if (str_starts_with($path, '/api/')) {
            return Response::json(['error' => 'Not found'], 404);
        }

        return Response::html(View::render('error', ['title' => 'Page not found', 'basePath' => $this->basePath]), 404);
    }
}
