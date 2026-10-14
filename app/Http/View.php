<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use InvalidArgumentException;
use Throwable;

/**
 * Renders the PHP templates in app/Http/Views, and holds the shared HTML
 * escaping helper every template uses for output.
 */
final class View
{
    /**
     * @param array<string, mixed> $vars
     */
    public static function render(string $name, array $vars): string
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid view name');
        }

        $file = __DIR__ . '/Views/' . $name . '.php';
        ob_start();
        try {
            (static function (string $__file, array $vars): void {
                extract($vars, EXTR_SKIP);
                require $__file;
            })($file, $vars);
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Escapes text for HTML element content and quoted attribute values.
     */
    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
