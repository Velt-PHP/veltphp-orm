<?php

declare(strict_types=1);

namespace Velt\Orm\Tests;

trait RequiresSqlite
{
    protected function requireSqlite(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is required for this test.');
        }
    }
}
