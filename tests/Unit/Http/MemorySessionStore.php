<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use FeedWatch\Http\SessionStore;

/**
 * A session in memory, for tests. $cookie says whether the browser sent one.
 */
final class MemorySessionStore implements SessionStore
{
    /** @var array<string, mixed> */
    public array $data = [];
    public int $regenerations = 0;
    public bool $destroyed = false;
    public bool $started = false;

    public function __construct(public bool $cookie = false)
    {
    }

    public function exists(): bool
    {
        return $this->cookie;
    }

    public function start(): void
    {
        $this->started = true;
        $this->cookie = true;
    }

    public function get(string $key): mixed
    {
        $this->start();

        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $this->data[$key] = $value;
    }

    public function regenerate(): void
    {
        $this->regenerations++;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->destroyed = true;
        $this->cookie = false;
    }
}
