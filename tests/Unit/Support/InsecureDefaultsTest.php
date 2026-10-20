<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\InsecureDefaults;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InsecureDefaultsTest extends TestCase
{
    /** @var array{environments: list<string>, settings: list<string>, known_defaults: list<string>} */
    private array $rules;

    protected function setUp(): void
    {
        $this->rules = require dirname(__DIR__, 3) . '/config/insecure_defaults.php';
    }

    public function test_staging_refuses_a_sample_password_without_printing_it(): void
    {
        try {
            InsecureDefaults::assertSafe('staging', $this->rules, ['DB_PASSWORD' => 'Complexpass#123']);
            self::fail('A sample password must stop the boot.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('DB_PASSWORD', $exception->getMessage());
            self::assertStringNotContainsString('Complexpass#123', $exception->getMessage());
        }
    }

    public function test_production_accepts_a_real_secret_and_unset_values(): void
    {
        InsecureDefaults::assertSafe('production', $this->rules, ['DB_PASSWORD' => 'a-real-generated-secret', 'REDIS_PASSWORD' => null]);

        $this->addToAssertionCount(1);
    }

    public function test_local_development_may_use_a_sample_password(): void
    {
        InsecureDefaults::assertSafe('local', $this->rules, ['DB_PASSWORD' => 'jvmeta']);

        $this->addToAssertionCount(1);
    }
}
