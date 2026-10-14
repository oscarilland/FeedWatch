<?php

declare(strict_types=1);

namespace FeedWatch\Http;

/**
 * The NFR-01 response headers, added to every response the app sends.
 */
final class SecurityHeaders
{
    /** OpenStreetMap's standard tiles (ADR-006; CARTO's now need an API key). */
    public const string TILE_HOST = 'https://tile.openstreetmap.org';

    public const string CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; "
        // The map tiles (ADR-006) are the only outside content: images, from one host.
        . "img-src 'self' data: " . self::TILE_HOST . "; font-src 'self'; connect-src 'self'; object-src 'none'; "
        . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

    public static function apply(Response $response): Response
    {
        return $response->withHeaders([
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // For older browsers that ignore the CSP frame-ancestors directive.
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ]);
    }
}
