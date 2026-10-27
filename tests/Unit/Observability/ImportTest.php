<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use JOOservices\Client\Client\ClientBuilder;
use JOOservices\Client\Testing\TestResponse;
use JOOservices\Client\Testing\TestResponseSequence;
use Tests\TestCase;

final class ImportTest extends TestCase
{
    protected function tearDown(): void
    {
        ClientBuilder::clearFake();

        parent::tearDown();
    }

    public function test_it_creates_then_updates_dashboards_alerts_and_telegram_destination_without_duplicates(): void
    {
        $this->configureImporter();

        $this->respond('GET', '/api/javmeta_it/alerts/templates', [
            [],
            [['name' => 'javmeta_telegram']],
        ]);
        $this->respond('GET', '/api/javmeta_it/alerts/destinations', [
            [],
            [['name' => 'javmeta_telegram', 'type' => 'http']],
        ]);
        $this->respond('GET', '/api/javmeta_it/dashboards', [
            ['dashboards' => []],
            ['dashboards' => [
                ['dashboard_id' => 'operations-id', 'title' => 'JAVMETA Operations', 'hash' => 'operations-hash', 'folder_id' => 'default'],
                ['dashboard_id' => 'quality-id', 'title' => 'JAVMETA Data Quality', 'hash' => 'quality-hash', 'folder_id' => 'default'],
                ['dashboard_id' => 'crawl-id', 'title' => 'JAVMETA Crawl debug', 'hash' => 'crawl-hash', 'folder_id' => 'default'],
                ['dashboard_id' => 'search-parse-id', 'title' => 'JAVMETA Search parse', 'hash' => 'search-parse-hash', 'folder_id' => 'default'],
            ]],
        ]);
        $this->respond('GET', '/api/v2/javmeta_it/alerts', [
            ['list' => []],
            ['list' => [
                ['alert_id' => 'stale-id', 'name' => 'javmeta_source_stale'],
                ['alert_id' => 'error-rate-id', 'name' => 'javmeta_api_error_rate_high'],
                ['alert_id' => 'failure-rate-id', 'name' => 'javmeta_source_failure_rate'],
                ['alert_id' => 'claimed-age-id', 'name' => 'javmeta_queue_claimed_age_high'],
                ['alert_id' => 'dependency-id', 'name' => 'javmeta_dependency_down'],
                ['alert_id' => 'p95-id', 'name' => 'javmeta_api_p95_high'],
                ['alert_id' => 'pg-connections-id', 'name' => 'javmeta_postgres_connection_utilization_high'],
                ['alert_id' => 'search-parse-alert-id', 'name' => 'javmeta_search_parse_degraded'],
            ]],
        ]);
        foreach ([
            ['POST', '/api/javmeta_it/alerts/templates'],
            ['PUT', '/api/javmeta_it/alerts/templates/javmeta_telegram'],
            ['POST', '/api/javmeta_it/alerts/destinations'],
            ['PUT', '/api/javmeta_it/alerts/destinations/javmeta_telegram'],
            ['PUT', '/api/javmeta_it/dashboards/operations-id'],
            ['PUT', '/api/javmeta_it/dashboards/quality-id'],
            ['PUT', '/api/javmeta_it/dashboards/crawl-id'],
            ['PUT', '/api/javmeta_it/dashboards/search-parse-id'],
            ['PUT', '/api/v2/javmeta_it/alerts/stale-id'],
            ['PUT', '/api/v2/javmeta_it/alerts/error-rate-id'],
            ['PUT', '/api/v2/javmeta_it/alerts/failure-rate-id'],
            ['PUT', '/api/v2/javmeta_it/alerts/claimed-age-id'],
            ['PUT', '/api/v2/javmeta_it/alerts/dependency-id'],
            ['PUT', '/api/v2/javmeta_it/alerts/p95-id'],
            ['PUT', '/api/v2/javmeta_it/alerts/pg-connections-id'],
            ['PUT', '/api/v2/javmeta_it/alerts/search-parse-alert-id'],
        ] as [$method, $path]) {
            $this->respond($method, $path, [[]]);
        }
        $this->respond('POST', '/api/javmeta_it/dashboards', [[], [], [], []]);
        $this->respond('POST', '/api/v2/javmeta_it/alerts', array_fill(0, 8, []));

        $this->artisan('observability:import')
            ->expectsOutputToContain('Imported 4 dashboards and 8 alerts')
            ->assertSuccessful();
        $this->artisan('observability:import')
            ->expectsOutputToContain('Imported 4 dashboards and 8 alerts')
            ->assertSuccessful();

        $counts = [];
        foreach (ClientBuilder::recorded() as $record) {
            $key = $record->request->getMethod() . ' ' . $record->request->getUri()->getPath();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        self::assertSame(1, $counts['POST /api/javmeta_it/alerts/templates']);
        self::assertSame(1, $counts['PUT /api/javmeta_it/alerts/templates/javmeta_telegram']);
        self::assertSame(1, $counts['POST /api/javmeta_it/alerts/destinations']);
        self::assertSame(1, $counts['PUT /api/javmeta_it/alerts/destinations/javmeta_telegram']);
        self::assertSame(4, $counts['POST /api/javmeta_it/dashboards']);
        self::assertSame(1, $counts['PUT /api/javmeta_it/dashboards/operations-id']);
        self::assertSame(1, $counts['PUT /api/javmeta_it/dashboards/quality-id']);
        self::assertSame(1, $counts['PUT /api/javmeta_it/dashboards/crawl-id']);
        self::assertSame(1, $counts['PUT /api/javmeta_it/dashboards/search-parse-id']);
        self::assertSame(8, $counts['POST /api/v2/javmeta_it/alerts']);
        self::assertSame(1, $counts['PUT /api/v2/javmeta_it/alerts/stale-id']);
        self::assertSame(1, $counts['PUT /api/v2/javmeta_it/alerts/error-rate-id']);
        self::assertSame(1, $counts['PUT /api/v2/javmeta_it/alerts/failure-rate-id']);
        self::assertSame(1, $counts['PUT /api/v2/javmeta_it/alerts/claimed-age-id']);
        self::assertSame(1, $counts['PUT /api/v2/javmeta_it/alerts/dependency-id']);
        self::assertSame(1, $counts['PUT /api/v2/javmeta_it/alerts/p95-id']);
        self::assertSame(1, $counts['PUT /api/v2/javmeta_it/alerts/pg-connections-id']);
        self::assertSame(1, $counts['PUT /api/v2/javmeta_it/alerts/search-parse-alert-id']);

        $dashboardUpdate = collect(ClientBuilder::recorded())->first(
            static fn($record): bool => $record->request->getMethod() === 'PUT'
                && $record->request->getUri()->getPath() === '/api/javmeta_it/dashboards/operations-id',
        );
        self::assertNotNull($dashboardUpdate);
        parse_str($dashboardUpdate->request->getUri()->getQuery(), $query);
        self::assertSame(['folder' => 'default', 'hash' => 'operations-hash'], $query);

        $destination = $this->requestBody('POST', '/api/javmeta_it/alerts/destinations');
        self::assertSame('javmeta_telegram', $destination['name']);
        self::assertSame('https://api.telegram.org/botunit-test-token/sendMessage', $destination['url']);
        self::assertSame('javmeta_telegram', $destination['template']);
        $template = $this->requestBody('POST', '/api/javmeta_it/alerts/templates');
        $templateMessage = json_decode($template['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('[javmeta:testing] [ALERT][WARNING][OPEN] {alert_name} triggered for {stream_name}. {rows}', $templateMessage['text']);

        $dashboard = $this->requestBody('POST', '/api/javmeta_it/dashboards', 'JAVMETA Operations');
        self::assertSame('JAVMETA Operations', $dashboard['title']);
        $panels = collect($dashboard['tabs'][0]['panels'] ?? [])->keyBy('id');
        foreach ([
            'javmeta-crawl-queue',
            'javmeta-errors-by-source-and-code',
            'javmeta-source-freshness',
            'javmeta-crawl-rate-by-source',
        ] as $panelId) {
            self::assertTrue($panels->has($panelId), "Expected operations dashboard panel {$panelId}.");
        }

        self::assertSame('javmeta_crawl_queue_depth', $panels->get('javmeta-crawl-queue')['queries'][0]['fields']['stream']);
        self::assertSame('javmeta_source_last_success_age_seconds', $panels->get('javmeta-source-freshness')['queries'][0]['fields']['stream']);

        $errorQuery = $panels->get('javmeta-errors-by-source-and-code')['queries'][0];
        self::assertSame('javmeta_logs', $errorQuery['fields']['stream']);
        self::assertSame('logs', $errorQuery['fields']['stream_type']);
        self::assertStringContainsString('source_slug', $errorQuery['query']);
        self::assertStringContainsString('error_code', $errorQuery['query']);

        $rateQuery = $panels->get('javmeta-crawl-rate-by-source')['queries'][0];
        self::assertSame('javmeta_crawl_run_pages_fetched_1h', $rateQuery['fields']['stream']);
        self::assertStringContainsString('source_slug', $rateQuery['query']);

        $searchDashboard = $this->requestBody('POST', '/api/javmeta_it/dashboards', 'JAVMETA Search parse');
        $searchPanels = collect($searchDashboard['tabs'][0]['panels'] ?? [])->keyBy('id');
        foreach ([
            'search-parse-request-rate',
            'search-parse-cache-hit-ratio',
            'search-parse-p95-by-cache',
            'search-parse-backend-count',
            'search-parse-ollama-outcomes',
            'search-parse-ollama-p95',
            'search-parse-fallback-reasons',
        ] as $panelId) {
            self::assertTrue($searchPanels->has($panelId), "Expected search parse dashboard panel {$panelId}.");
            self::assertSame('javmeta_logs', $searchPanels->get($panelId)['queries'][0]['fields']['stream']);
            self::assertSame('logs', $searchPanels->get($panelId)['queries'][0]['fields']['stream_type']);
            self::assertStringContainsString("endpoint = '/api/v1/search/parse'", $searchPanels->get($panelId)['queries'][0]['query']);
        }

        $cacheRatioQuery = $searchPanels->get('search-parse-cache-hit-ratio')['queries'][0]['query'];
        self::assertStringContainsString("parse_cache_status = 'hit'", $cacheRatioQuery);
        self::assertStringContainsString('sum(CASE WHEN parse_cache_status', $cacheRatioQuery);

        $searchAlert = $this->requestBody('POST', '/api/v2/javmeta_it/alerts', 'javmeta_search_parse_degraded');
        self::assertSame('logs', $searchAlert['stream_type']);
        self::assertSame('javmeta_logs', $searchAlert['stream_name']);
        self::assertStringContainsString('parse_duration_ms', $searchAlert['query_condition']['sql']);
        self::assertStringContainsString('HAVING count(*) >= 3', $searchAlert['query_condition']['sql']);
    }

    public function test_alerts_for_streams_that_do_not_exist_yet_are_reported_pending_and_strict_fails(): void
    {
        $this->configureImporter();
        $this->respond('GET', '/api/javmeta_it/alerts/templates', [[['name' => 'javmeta_telegram']], [['name' => 'javmeta_telegram']]]);
        $this->respond('GET', '/api/javmeta_it/alerts/destinations', [[['name' => 'javmeta_telegram', 'type' => 'http']], [['name' => 'javmeta_telegram', 'type' => 'http']]]);
        $this->respond('GET', '/api/javmeta_it/dashboards', [['dashboards' => []], ['dashboards' => []]]);
        $this->respond('GET', '/api/v2/javmeta_it/alerts', [['list' => []], ['list' => []]]);
        $this->respond('PUT', '/api/javmeta_it/alerts/templates/javmeta_telegram', [[], []]);
        $this->respond('PUT', '/api/javmeta_it/alerts/destinations/javmeta_telegram', [[], []]);
        $this->respond('POST', '/api/javmeta_it/dashboards', array_fill(0, 8, []));
        // Every alert creation answers 404 as OpenObserve does for a stream without data.
        $this->respond('POST', '/api/v2/javmeta_it/alerts', array_fill(0, 16, ['code' => 404, 'message' => 'Stream not found']), 404);

        $this->artisan('observability:import')
            ->expectsOutputToContain('Alerts pending until their metric stream receives data')
            ->assertSuccessful();
        $this->artisan('observability:import', ['--strict' => true])
            ->expectsOutputToContain('javmeta_source_stale')
            ->assertFailed();
    }

    public function test_dry_run_does_not_contact_openobserve_or_require_credentials(): void
    {
        ClientBuilder::fake();

        $this->artisan('observability:import', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        self::assertSame([], ClientBuilder::recorded());
    }

    public function test_every_alert_sql_uses_a_configured_telemetry_stream(): void
    {
        $catalogue = file_get_contents(base_path('docs/03-operations/telemetry.md'));
        self::assertIsString($catalogue);
        preg_match_all('/^\|\s*`(javmeta_[a-z0-9_]+)`\s*\|/m', $catalogue, $matches);
        $streams = $matches[1];
        self::assertNotEmpty($streams);

        foreach (glob(base_path('deploy/observability/alerts/*.json')) ?: [] as $path) {
            $alert = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $sql = $alert['query_condition']['sql'] ?? '';
            self::assertSame('sql', $alert['query_condition']['type'] ?? null, basename($path));
            self::assertMatchesRegularExpression('/^\s*SELECT\b/i', $sql, basename($path));
            self::assertMatchesRegularExpression('/\bFROM\s+"([a-z0-9_]+)"/i', $sql, basename($path));
            preg_match('/\bFROM\s+"([a-z0-9_]+)"/i', $sql, $from);
            if (($alert['stream_type'] ?? null) === 'logs') {
                self::assertSame('javmeta_logs', $from[1], basename($path));
            } else {
                self::assertContains($from[1], $streams, basename($path));
            }
            self::assertSame($from[1], $alert['stream_name'] ?? null, basename($path));
        }
    }

    /**
     * The PostgreSQL capacity alert reads the newest sample and holds for five full minutes.
     */
    public function test_postgres_utilization_alert_uses_current_sample_and_five_minute_hold(): void
    {
        $alert = json_decode(
            (string) file_get_contents(base_path('deploy/observability/alerts/javmeta_postgres_connection_utilization_high.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $sql = $alert['query_condition']['sql'] ?? '';

        self::assertStringContainsString('FROM (SELECT _timestamp, value FROM "javmeta_postgres_connection_utilization"', $sql);
        self::assertStringContainsString('ORDER BY _timestamp DESC LIMIT 1) AS latest WHERE value >= 0.85', $sql);
        self::assertSame(300, $alert['pending_period_sec'] ?? null);
        self::assertSame(1, $alert['trigger_condition']['frequency'] ?? null);
        self::assertSame(5, $alert['trigger_condition']['period'] ?? null);
    }

    public function test_it_fails_before_writing_when_telegram_credentials_are_missing(): void
    {
        $this->configureImporter();
        config()->set([
            'laravel-notifications.channels.telegram.bot_token' => '',
            'laravel-notifications.channels.telegram.chat_id' => '',
            'javmeta_alerts.telegram.bot_token' => '',
            'javmeta_alerts.telegram.chat_id' => '',
        ]);
        ClientBuilder::fake();

        $this->artisan('observability:import')->assertExitCode(1);

        self::assertSame([], ClientBuilder::recorded());
    }

    public function test_it_fails_without_exposing_response_body_when_openobserve_rejects_a_request(): void
    {
        $this->configureImporter();
        $this->respond('GET', '/api/javmeta_it/alerts/templates', [['message' => 'authorization rejected']], 401);

        $this->artisan('observability:import')
            ->expectsOutputToContain('HTTP 401')
            ->assertExitCode(1);

        self::assertCount(1, ClientBuilder::recorded());
    }

    /** @param list<array<array-key, mixed>> $responses */
    private function respond(string $method, string $path, array $responses, int $status = 200): void
    {
        $sequence = new TestResponseSequence();
        foreach ($responses as $response) {
            $sequence->push(TestResponse::json($response, $status));
        }

        ClientBuilder::respond($method, $path, $sequence);
    }

    /** @return array<string, mixed> */
    private function requestBody(string $method, string $path, ?string $title = null): array
    {
        foreach (ClientBuilder::recorded() as $record) {
            if ($record->request->getMethod() === $method && $record->request->getUri()->getPath() === $path) {
                $body = json_decode((string) $record->request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                if ($title === null || ($body['title'] ?? $body['name'] ?? null) === $title) {
                    return $body;
                }
            }
        }

        self::fail("No recorded request matched {$method} {$path} with title " . ($title ?? '(any)') . '.');
    }

    private function configureImporter(): void
    {
        config()->set([
            'openobserve.url' => 'https://openobserve.test',
            'openobserve.org' => 'javmeta_it',
            'openobserve.email' => 'unit@example.test',
            'openobserve.password' => 'unit-test-password',
            'openobserve.timeout' => 1,
            'laravel-notifications.channels.telegram.bot_token' => 'unit-test-token',
            'laravel-notifications.channels.telegram.chat_id' => 'unit-test-chat',
            'laravel-notifications.channels.telegram.api_base' => 'https://api.telegram.org',
            'javmeta_alerts.telegram.bot_token' => '',
            'javmeta_alerts.telegram.chat_id' => '',
            'javmeta_alerts.telegram.api_base' => 'https://api.telegram.org',
        ]);
        ClientBuilder::fake();
    }
}
