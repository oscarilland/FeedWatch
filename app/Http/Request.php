<?php

declare(strict_types=1);

namespace FeedWatch\Http;

/**
 * An HTTP request, reduced to what FeedWatch uses. Query and form values are
 * strings only: array-valued parameters (?a[]=1) are dropped, so handlers
 * only ever validate strings.
 */
final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $form
     * @param array<string, string> $headers lower-case names
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $query = [],
        public readonly array $form = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly string $clientIp = '',
        public readonly bool $secure = false,
    ) {
    }

    /**
     * A request built from a method and URI, for the front controller's
     * fallback and for tests.
     *
     * @param array<array-key, mixed> $form
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public static function make(string $method, string $uri, array $form = [], array $headers = [], array $cookies = [], string $clientIp = '127.0.0.1', bool $secure = true): self
    {
        $raw = parse_url($uri, PHP_URL_QUERY);
        $query = [];
        if (is_string($raw) && $raw !== '') {
            parse_str($raw, $query);
        }

        return new self(
            strtoupper($method),
            $uri,
            self::strings($query),
            self::strings($form),
            array_change_key_case($headers, CASE_LOWER),
            $cookies,
            $clientIp,
            $secure,
        );
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $headers = [];
        foreach ($server as $name => $value) {
            if (is_string($name) && is_string($value) && str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
            }
        }
        $https = $server['HTTPS'] ?? '';

        return self::make(
            is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET',
            is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/',
            $_POST,
            $headers,
            self::strings($_COOKIE),
            is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '',
            is_string($https) && $https !== '' && strtolower($https) !== 'off',
        );
    }

    public function path(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $name => $value) {
            if (is_string($value)) {
                $strings[(string) $name] = $value;
            }
        }

        return $strings;
    }
}
