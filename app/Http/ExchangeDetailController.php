<?php

declare(strict_types=1);

namespace FeedWatch\Http;

use Closure;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\ExchangeDetails;
use Throwable;

/**
 * GET /api/exchange?code=THX: win share over the last minute and the most
 * recent gaps, for the dashboard's detail panel (FR-03 AC-2, FR-02).
 * Read-only; it never triggers a catch-up.
 */
final class ExchangeDetailController
{
    public const int WIN_SHARE_SECONDS = 60;
    public const int RECENT_GAPS = 10;

    /**
     * @param Closure(): ExchangeDetails $details
     */
    public function __construct(
        private readonly Closure $details,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $query = $request->query;
        $code = $query['code'] ?? '';
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            return Response::json(['error' => 'code must be a three-letter exchange code'], 400, ['Cache-Control' => 'no-store']);
        }

        $nowSecond = intdiv((int) $this->clock->now()->format('Uu'), 1_000_000);
        try {
            $details = ($this->details)();
            $id = $details->exchangeId($code);
            if ($id === null) {
                return Response::json(['error' => 'Unknown exchange'], 400, ['Cache-Control' => 'no-store']);
            }
            $win = $details->winShare($id, $nowSecond - self::WIN_SHARE_SECONDS, $nowSecond);
            $gaps = $details->recentGaps($id, self::RECENT_GAPS);
        } catch (Throwable $e) {
            error_log('FeedWatch: reading exchange detail failed: ' . $e::class . ': ' . $e->getMessage());

            return Response::json(['error' => 'Temporarily unavailable'], 503, ['Cache-Control' => 'no-store']);
        }

        $accepted = $win['accepted'];

        return Response::json(
            [
                'code' => $code,
                'win_share' => [
                    'seconds' => self::WIN_SHARE_SECONDS,
                    'accepted' => $accepted,
                    'a_wins' => $win['a_wins'],
                    'b_wins' => $win['b_wins'],
                    'duplicates' => $win['duplicates'],
                    'a_percent' => $accepted === 0 ? null : round(100 * $win['a_wins'] / $accepted, 1),
                    'b_percent' => $accepted === 0 ? null : round(100 * $win['b_wins'] / $accepted, 1),
                ],
                'recent_gaps' => array_map(static fn (array $g): array => [
                    'scope' => $g['scope'],
                    'classification' => $g['classification'],
                    'first' => $g['first'],
                    'last' => $g['last'],
                    'count' => $g['count'],
                    'detected_at' => gmdate('Y-m-d\TH:i:s', intdiv($g['detected_us'], 1_000_000)) . 'Z',
                ], $gaps),
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
