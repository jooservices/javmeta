<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Services\Notifications\NotificationRenderer;
use App\Support\NodeIdentity;
use App\Services\Notifications\NotificationTemplateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class NotificationRendererTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(NotificationTemplateService::class)->seed();
    }

    public function test_replaces_allowed_variables_and_escapes_telegram_values(): void
    {
        $rendered = app(NotificationRenderer::class)->render('crawl.dispatch_delayed', [
            'due_items' => '96,740 <script>',
            'dispatch_age_seconds' => 190,
            'threshold_seconds' => 180,
            'worker_count' => '3/3',
        ]);

        self::assertStringContainsString('[XCrawler][WARNING]', $rendered['title']);
        self::assertStringContainsString('96,740 &lt;script&gt;', $rendered['body']);
        self::assertStringNotContainsString('<script>', $rendered['body']);
        self::assertFalse($rendered['used_default']);
    }

    public function test_disabled_or_broken_template_falls_back_to_the_registry_default(): void
    {
        $templates = app(NotificationTemplateService::class);
        $definition = app(\App\Services\Notifications\NotificationRegistry::class)->get('crawl.dispatch_delayed');
        self::assertNotNull($definition);
        $templates->save('crawl.dispatch_delayed', 'Custom title', 'Custom body', false, 'warning', null);

        $rendered = app(NotificationRenderer::class)->render('crawl.dispatch_delayed');

        self::assertTrue($rendered['used_default']);
        self::assertSame($definition['title_template'], $rendered['title_template']);
        self::assertStringNotContainsString('Custom title', $rendered['title']);

        DB::table('notification_templates')->where('key', 'crawl.dispatch_delayed')->update([
            'enabled' => true,
            'title_template' => 'Broken {unknown}',
        ]);
        $rendered = app(NotificationRenderer::class)->render('crawl.dispatch_delayed');
        self::assertTrue($rendered['used_default']);
        self::assertSame($definition['title_template'], $rendered['title_template']);

        DB::table('notification_templates')->where('key', 'crawl.dispatch_delayed')->update([
            'title_template' => '<b>Broken title',
        ]);
        $rendered = app(NotificationRenderer::class)->render('crawl.dispatch_delayed');
        self::assertTrue($rendered['used_default']);
        self::assertSame($definition['title_template'], $rendered['title_template']);
    }

    public function test_template_save_rejects_unknown_variables(): void
    {
        $templates = app(NotificationTemplateService::class);

        $this->expectException(InvalidArgumentException::class);
        $templates->save('crawl.dispatch_delayed', 'Alert {unknown}', 'Body', true, 'critical', null);
    }

    public function test_template_save_rejects_unbalanced_telegram_markup(): void
    {
        $templates = app(NotificationTemplateService::class);

        $this->expectException(InvalidArgumentException::class);
        $templates->save('crawl.dispatch_delayed', '<b>Alert</b>', '<pre>Body', true, 'critical', null);
    }

    public function test_template_save_rejects_token_like_content(): void
    {
        $templates = app(NotificationTemplateService::class);

        $this->expectException(InvalidArgumentException::class);
        $templates->save('crawl.dispatch_delayed', 'Alert', 'Bot credential 123456:' . str_repeat('A', 28), true, 'critical', null);
    }

    public function test_dynamic_values_with_token_like_content_are_redacted_before_rendering(): void
    {
        $rendered = app(NotificationRenderer::class)->render('catalog.lookup_missing', [
            'entity_key' => 'token=abcdefghijklmnopqrstuvwxyz0123456789',
        ]);

        self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz0123456789', $rendered['body']);
        self::assertStringContainsString('[redacted]', $rendered['body']);
    }

    public function test_live_render_does_not_fill_missing_values_with_preview_samples(): void
    {
        $rendered = app(NotificationRenderer::class)->render('crawl.dispatch_delayed');

        self::assertStringNotContainsString('96,740', $rendered['body']);
        self::assertStringNotContainsString('190 seconds', $rendered['body']);
        self::assertStringContainsString('Due items', $rendered['body']);
    }

    public function test_egress_notification_uses_the_configured_node_name(): void
    {
        $hostName = NodeIdentity::hostname();
        $node = $hostName === 'configured-egress-test-node'
            ? 'configured-egress-test-node-2'
            : 'configured-egress-test-node';
        config(['sentry.node' => $node]);

        $rendered = app(NotificationRenderer::class)->render('network.egress_ip_changed', [
            'current_ip' => '203.0.113.12',
            'previous_ip' => '203.0.113.11',
        ]);

        self::assertStringContainsString('Node ' . $node . ' now exits', $rendered['body']);
        self::assertStringNotContainsString('Node ' . $hostName . ' now exits', $rendered['body']);
    }

    public function test_preview_uses_registry_samples_while_live_render_uses_configured_timezone_and_node(): void
    {
        config(['javmeta_alerts.hourly_report.timezone' => 'Asia/Ho_Chi_Minh']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 12:40:00', 'UTC'));

        try {
            $renderer = app(NotificationRenderer::class);
            $templates = app(NotificationTemplateService::class)->get('crawl.dispatch_delayed');
            self::assertNotNull($templates);
            $preview = $renderer->preview('crawl.dispatch_delayed', $templates['title_template'], $templates['body_template']);
            $live = $renderer->render('crawl.dispatch_delayed');

            self::assertStringContainsString('Nov 2, 17:40 (Mexico) · node: xcrawler', $preview['body']);
            self::assertStringContainsString('Nov 2, 19:40 (Asia/Ho_Chi_Minh)', $live['body']);
            self::assertStringNotContainsString('node: xcrawler', $live['body']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
