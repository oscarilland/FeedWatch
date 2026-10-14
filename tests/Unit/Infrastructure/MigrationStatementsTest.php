<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Infrastructure;

use FeedWatch\Infrastructure\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class MigrationStatementsTest extends TestCase
{
    public function test_splits_on_semicolons_at_line_ends_and_drops_comments(): void
    {
        $sql = "-- a comment\nCREATE TABLE a (x INT);\n\n  -- another\nINSERT INTO a VALUES (1);\r\nSELECT 'a;b'";

        self::assertSame(
            ['CREATE TABLE a (x INT)', 'INSERT INTO a VALUES (1)', "SELECT 'a;b'"],
            MigrationRunner::statements($sql),
        );
    }

    public function test_empty_file_has_no_statements(): void
    {
        self::assertSame([], MigrationRunner::statements("-- nothing here\n\n"));
    }
}
