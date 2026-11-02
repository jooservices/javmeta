<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Contracts\ReportChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HourlyReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_hourly_report_command_is_a_thin_send_once_alias(): void
    {
        config(['javmeta_report.sections' => ['movies']]);
        $channel = new class implements ReportChannel {
            /** @var list<string> */
            public array $messages = [];

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                $this->messages[] = $subject . "\n" . $body;

                return true;
            }
        };
        $this->app->instance(ReportChannel::class, $channel);

        $this->artisan('javmeta:hourly-report')->assertSuccessful();

        self::assertCount(1, $channel->messages);
        self::assertStringContainsString('📊 [XCrawler] Report', $channel->messages[0]);
    }
}
