<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class QueryInterpreterUsesRepositoryTest extends TestCase
{
    public function test_query_interpreter_does_not_query_the_database_directly(): void
    {
        $file = dirname(__DIR__, 3) . '/app/Services/Search/QueryInterpreter.php';
        $source = file_get_contents($file);
        self::assertIsString($source);
        self::assertSame(0, preg_match('/\bDB::(?:table|select(?:One)?)\s*\(/', $source), 'QueryInterpreter must get catalog values from a repository.');
        self::assertSame(0, preg_match('/\b[A-Z][A-Za-z0-9_]*::(?:query|where|find|create|firstOrCreate|updateOrCreate)\s*\(/', $source), 'QueryInterpreter must not query models.');
    }
}
