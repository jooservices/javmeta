<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Persist;

use App\Services\Persist\PerformerPersister;
use ReflectionMethod;
use Tests\TestCase;

final class PerformerPersisterAttrsTest extends TestCase
{
    public function test_merged_attributes_always_encode_to_json_and_drop_empty_values(): void
    {
        $persister = $this->app->make(PerformerPersister::class);
        $method = new ReflectionMethod($persister, 'mergeAttrs');

        $merged = $method->invoke(
            $persister,
            ['kept' => 'a', 'old_empty' => ''],
            ['bad' => "broken \xB1\x31 text", 'none' => null, 'list' => [], 'n' => 3],
        );

        $this->assertSame(['kept', 'bad', 'n'], array_keys($merged));
        $this->assertIsString(json_encode($merged, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('broken', $merged['bad']);
    }
}
