<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Simulation;

use FeedWatch\Domain\Exchange;

/**
 * The eight seeded exchanges, as in migration 002, for unit tests.
 */
final class Exchanges
{
    /**
     * @return list<Exchange>
     */
    public static function all(): array
    {
        $rows = [
            ['THX', 'Thames Exchange', 'London', 51.5074, -0.1278, 'Europe/London', -150],
            ['RHX', 'Rhine Exchange', 'Frankfurt', 50.1109, 8.6821, 'Europe/Berlin', -100],
            ['HDX', 'Hudson Exchange', 'New York', 40.7128, -74.006, 'America/New_York', -50],
            ['LKX', 'Lakeshore Exchange', 'Chicago', 41.8781, -87.6298, 'America/Chicago', 0],
            ['PLX', 'Paulista Exchange', 'São Paulo', -23.5505, -46.6333, 'America/Sao_Paulo', 50],
            ['KTX', 'Kanto Exchange', 'Tokyo', 35.6762, 139.6503, 'Asia/Tokyo', 100],
            ['PRX', 'Pearl River Exchange', 'Hong Kong', 22.3193, 114.1694, 'Asia/Hong_Kong', 150],
            ['HBX', 'Harbour Exchange', 'Sydney', -33.8688, 151.2093, 'Australia/Sydney', 200],
        ];

        $exchanges = [];
        foreach ($rows as $i => $r) {
            $exchanges[] = new Exchange($i + 1, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6]);
        }

        return $exchanges;
    }

    public static function london(): Exchange
    {
        return self::all()[0];
    }
}
