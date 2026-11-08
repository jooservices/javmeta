<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Services\Notifications\NotificationRegistry;
use PHPUnit\Framework\TestCase;

final class NotificationRegistryTest extends TestCase
{
    public function test_every_notification_has_defaults_and_samples_for_every_placeholder(): void
    {
        $registry = new NotificationRegistry();
        $definitions = $registry->all();

        self::assertNotEmpty($definitions);

        foreach ($definitions as $key => $definition) {
            self::assertNotSame('', $definition['name'], $key);
            self::assertNotSame('', $definition['usage'], $key);
            self::assertContains($definition['channel'], ['telegram', 'webhook', 'openobserve'], $key);
            self::assertNotSame('', $definition['title_template'], $key);
            self::assertNotSame('', $definition['body_template'], $key);
            self::assertNotEmpty($definition['variables'], $key);

            $samples = [];
            foreach ($definition['variables'] as $variable) {
                self::assertArrayHasKey('name', $variable, $key);
                self::assertArrayHasKey('sample', $variable, $key);
                $samples[$variable['name']] = $variable['sample'];
            }

            preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $definition['title_template'] . $definition['body_template'], $matches);
            self::assertSame([], array_values(array_diff(array_unique($matches[1]), array_keys($samples))), $key);
        }
    }
}
