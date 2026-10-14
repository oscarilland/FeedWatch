<?php

declare(strict_types=1);

namespace FeedWatch\Http;

/**
 * An immutable HTTP response: status, headers and body.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers, $body);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    /**
     * A 303 "see other", so a form post is followed by a plain GET.
     */
    public static function redirect(string $location): self
    {
        return new self(303, ['Location' => $location, 'Cache-Control' => 'no-store'], '');
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $headers + $this->headers, $this->body);
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(bool $includeBody = true): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($includeBody) {
            echo $this->body;
        }
    }
}
