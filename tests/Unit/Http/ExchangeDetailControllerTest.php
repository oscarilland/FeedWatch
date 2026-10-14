<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Http;

use DateTimeImmutable;
use FeedWatch\Http\ExchangeDetailController;
use FeedWatch\Http\Request;
use FeedWatch\Infrastructure\Clock;
use FeedWatch\Infrastructure\ExchangeDetails;
use PHPUnit\Framework\TestCase;

final class ExchangeDetailControllerTest extends TestCase
{
    private function controller(int $accepted = 300): ExchangeDetailController
    {
        $details = new class ($accepted) implements ExchangeDetails {
            public function __construct(private readonly int $accepted)
            {
            }

            public function exchangeId(string $code): ?int
            {
                return $code === 'THX' ? 1 : null;
            }

            public function winShare(int $exchangeId, int $fromSecond, int $toSecond): array
            {
                return ['accepted' => $this->accepted, 'a_wins' => (int) round($this->accepted * 0.97), 'b_wins' => $this->accepted - (int) round($this->accepted * 0.97), 'duplicates' => $this->accepted];
            }

            public function recentGaps(int $exchangeId, int $limit): array
            {
                return [['scope' => 'A', 'classification' => 'line_gap', 'first' => 25, 'last' => 28, 'count' => 4, 'detected_us' => 1_791_115_200_000_000]];
            }
        };
        $clock = new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@1791115260');
            }
        };

        return new ExchangeDetailController(static fn (): ExchangeDetails => $details, $clock);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function test_FR03_AC2_win_share_over_the_last_minute_and_recent_gaps(): void
    {
        $response = ($this->controller())(new Request('GET', '/', ['code' => 'THX']));
        $data = self::decode($response->body);

        self::assertSame(200, $response->status);
        self::assertSame(['seconds' => 60, 'accepted' => 300, 'a_wins' => 291, 'b_wins' => 9, 'duplicates' => 300, 'a_percent' => 97.0, 'b_percent' => 3.0], $data['win_share']);
        self::assertSame([['scope' => 'A', 'classification' => 'line_gap', 'first' => 25, 'last' => 28, 'count' => 4, 'detected_at' => '2026-10-04T12:00:00Z']], $data['recent_gaps']);
    }

    public function test_no_arrivals_gives_no_percentages(): void
    {
        $data = self::decode(($this->controller(0))(new Request('GET', '/', ['code' => 'THX']))->body);

        self::assertIsArray($data['win_share']);
        self::assertNull($data['win_share']['a_percent']);
    }

    public function test_bad_or_unknown_codes_are_400s(): void
    {
        self::assertSame(400, ($this->controller())(new Request('GET', '/', []))->status);
        self::assertSame(400, ($this->controller())(new Request('GET', '/', ['code' => 'thx']))->status);
        self::assertSame(400, ($this->controller())(new Request('GET', '/', ['code' => 'ZZZ']))->status);
    }
}
