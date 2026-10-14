<?php

declare(strict_types=1);

namespace FeedWatch\Infrastructure;

/**
 * Application settings, read from environment variables locally and from
 * config/config.local.php on the server. That file is generated at deploy
 * time and never committed.
 */
final class Config
{
    /** Only these names are read, so the rest of the environment is ignored. */
    public const array KNOWN_SETTINGS = [
        'APP_BASE_PATH',
        'APP_VERSION',
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'DB_CHARSET',
        'ADMIN_USER',
        'ADMIN_PASSWORD_HASH',
    ];

    /** @var array<string, string> */
    private array $values = [];

    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(array $values)
    {
        foreach (self::KNOWN_SETTINGS as $name) {
            $value = $values[$name] ?? null;
            if (is_string($value) && $value !== '') {
                $this->values[$name] = $value;
            }
        }
    }

    /**
     * Uses the generated config file when it exists, otherwise the environment.
     */
    public static function load(string $configDir): self
    {
        $file = $configDir . '/config.local.php';
        if (is_file($file)) {
            $values = require $file;
            if (!is_array($values)) {
                throw new ConfigException('config.local.php must return an array');
            }

            return new self($values);
        }

        return new self(getenv());
    }

    public function get(string $name, string $default = ''): string
    {
        return $this->values[$name] ?? $default;
    }

    public function require(string $name): string
    {
        if (!isset($this->values[$name])) {
            throw new ConfigException("Missing required setting {$name}");
        }

        return $this->values[$name];
    }

    public function requireInt(string $name, int $default): int
    {
        if (!isset($this->values[$name])) {
            return $default;
        }

        $value = filter_var($this->values[$name], FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new ConfigException("Setting {$name} must be a whole number");
        }

        return $value;
    }

    /**
     * The sub-folder the app is served from: '' at the domain root, or
     * '/feedwatch' style, with a leading slash and no trailing slash.
     */
    public function basePath(): string
    {
        $path = trim($this->get('APP_BASE_PATH'), '/');
        if ($path === '') {
            return '';
        }
        if (preg_match('#^[A-Za-z0-9_\-/]+$#', $path) !== 1) {
            throw new ConfigException('Setting APP_BASE_PATH may only contain letters, digits, "-", "_" and "/"');
        }

        return '/' . $path;
    }

    public function version(): string
    {
        return $this->get('APP_VERSION', 'dev');
    }
}
