<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Services\Notifications\NotificationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class NotificationConsoleCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_and_render_commands_are_read_only_and_use_registry_defaults(): void
    {
        $templates = app(NotificationTemplateService::class);
        $before = $templates->count();

        $this->artisan('notifications:list', ['--json' => true])
            ->expectsOutputToContain('crawl.success_rate_low')
            ->assertSuccessful();
        $this->artisan('notifications:render', ['key' => 'crawl.dispatch_delayed'])
            ->expectsOutputToContain('{due_items}')
            ->assertSuccessful();
        $this->artisan('notifications:render', ['key' => 'crawl.dispatch_delayed', '--sample' => true])
            ->expectsOutputToContain('96,740')
            ->assertSuccessful();

        self::assertSame($before, $templates->count());
        self::assertArrayHasKey('obs:import-alerts', Artisan::all());
    }

    public function test_render_command_rejects_unknown_notification_keys(): void
    {
        $this->artisan('notifications:render', ['key' => 'missing.type'])->assertFailed();
    }
}
