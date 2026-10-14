<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * NFR-01 AC-1: no SQL is built by joining or interpolating variables. Queries
 * may join constants (column lists, fixed WHERE fragments), but a variable's
 * value must only ever reach the database as a bound parameter.
 */
final class NoSqlJoiningTest extends TestCase
{
    public function test_NFR01_AC1_no_query_joins_or_interpolates_a_variable(): void
    {
        $offences = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/app'));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            // Each query(), exec() or prepare() call's argument, up to its closing ");".
            preg_match_all('/->(?:query|exec|prepare)\((.*?)\);/s', $code, $calls);
            foreach ($calls[1] as $argument) {
                $joinsVariable = preg_match('/\.\s*\$[a-zA-Z_]|\$[a-zA-Z_]\w*\s*\./', $argument) === 1;
                $interpolates = preg_match('/"[^"]*\$[a-zA-Z_{][^"]*"/', $argument) === 1;
                if ($joinsVariable || $interpolates) {
                    $offences[] = basename($file->getPathname()) . ': ' . trim(substr($argument, 0, 100));
                }
            }
        }

        self::assertSame([], $offences);
    }
}
