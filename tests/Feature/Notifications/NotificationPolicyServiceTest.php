<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Services\Notifications\NotificationPolicyRegistry;
use App\Services\Notifications\NotificationPolicyService;
use App\Services\Notifications\NotificationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NotificationPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registered_notification_has_a_database_policy_row(): void
    {
        $keys = array_keys((new NotificationRegistry())->all());
        $rows = DB::table('notification_templates')->orderBy('key')->pluck('key')->all();

        sort($keys);
        self::assertSame($keys, $rows);
        self::assertGreaterThanOrEqual(70, count($rows));
    }

    public function test_policy_changes_are_revised_and_can_be_restored_or_reset(): void
    {
        $service = app(NotificationPolicyService::class);
        $initial = $service->get('crawl.success_rate_low');
        self::assertNotNull($initial);

        $edited = $service->save('crawl.success_rate_low', [
            ...$initial,
            'enabled' => false,
            'tier' => 'critical',
            'cooldown_minutes' => 240,
            'escalation_minutes' => 0,
            'include_periodic_report' => false,
            'include_daily_report' => true,
            'send_resolved' => false,
        ], 19);
        self::assertSame(2, $edited['version']);
        self::assertFalse($edited['enabled']);

        $revision = $service->revisions('crawl.success_rate_low')[0];
        $restored = $service->restore('crawl.success_rate_low', (int) $revision['id'], 19);
        self::assertSame($initial['tier'], $restored['tier']);
        self::assertSame($initial['cooldown_minutes'], $restored['cooldown_minutes']);

        $service->save('crawl.success_rate_low', [...$initial, 'tier' => 'info'], 19);
        $reset = $service->reset('crawl.success_rate_low', 19);
        self::assertSame($initial['tier'], $reset['tier']);
        $expected = (new NotificationPolicyRegistry(new NotificationRegistry()))->get('crawl.success_rate_low');
        self::assertNotNull($expected);
        self::assertSame($expected, array_intersect_key($reset, $expected));
    }

    public function test_policy_validation_rejects_invalid_tiers_and_ranges(): void
    {
        $service = app(NotificationPolicyService::class);
        $policy = $service->get('crawl.success_rate_low');
        self::assertNotNull($policy);

        try {
            $service->save('crawl.success_rate_low', [...$policy, 'tier' => 'urgent'], null);
            self::fail('Invalid notification tiers must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Choose a supported notification tier.', $exception->getMessage());
        }

        try {
            $service->save('crawl.success_rate_low', [...$policy, 'cooldown_minutes' => 10081], null);
            self::fail('Out-of-range cooldowns must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('between 0 and 10080', $exception->getMessage());
        }
    }
}
