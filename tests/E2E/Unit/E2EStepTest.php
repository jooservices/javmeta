<?php

declare(strict_types=1);

namespace Tests\E2E\Unit;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\E2E\Context;
use Tests\E2E\Scenario;
use Tests\E2E\Step;
use Tests\E2E\Steps\Authenticate;
use Tests\E2E\Steps\BulkLookupMovies;
use Tests\E2E\Scenarios\J9KeyRevocationScenario;
use Tests\E2E\Steps\CallMcp;
use Tests\E2E\Steps\CheckSourceHealth;
use Tests\E2E\Steps\CreateApiKey;
use Tests\E2E\Steps\FindCrawledMovie;
use Tests\E2E\Steps\GetPerformer;
use Tests\E2E\Steps\ListApiKeys;
use Tests\E2E\Steps\LookupMovie;
use Tests\E2E\Steps\McpFindCrawledMovie;
use Tests\E2E\Steps\QuerySearchIndex;
use Tests\E2E\Steps\ResetTestState;
use Tests\E2E\Steps\RevokeApiKey;
use Tests\E2E\Steps\RunCrawlOnce;
use Tests\E2E\Steps\RunCrawlOutage;
use Tests\E2E\Steps\SearchMovies;
use Tests\E2E\Steps\SeedCatalog;
use Tests\E2E\Steps\SeedFixtureSource;
use Tests\E2E\Steps\StartStack;
use Tests\E2E\Steps\WaitForMovie;
use Tests\E2E\Support\ApiKeyCredential;
use Tests\E2E\Support\CatalogSeed;
use Tests\E2E\Support\CrawlResult;
use Tests\E2E\Support\FixtureSeed;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\Redactor;
use Tests\E2E\Support\ScenarioFailure;
use Tests\E2E\Support\StepFailure;
use Tests\TestCase;

final class E2EStepTest extends TestCase
{
    public function test_start_stack_checks_health_contract(): void
    {
        Http::fake(['https://api.test/api/health' => Http::response([
            'status' => 'ok',
            'worker' => ['stale' => false],
            'sources' => [['slug' => 'onejav', 'last_success_at' => '2026-10-16T10:00:00Z']],
        ])]);

        $context = (new StartStack())->run($this->context());

        self::assertSame(200, $context->lastResponse['status']);
    }

    public function test_create_key_keeps_plaintext_only_in_context(): void
    {
        Http::fake(['https://api.test/api/v1/admin/keys' => Http::response(['data' => ['id' => 41, 'plaintext' => 'jvm_generated-key']], 201)]);

        $context = (new CreateApiKey())->run($this->context());

        self::assertSame('jvm_generated-key', $context->key()?->plaintext);
        self::assertSame('owner-secret', $context->lastRequest['headers']['X-Owner-Token'] ?? null);
        self::assertSame('[REDACTED]', (new Redactor())->redact($context->lastRequest, ['owner-secret'])['headers']['X-Owner-Token']);
    }

    public function test_list_keys_does_not_accept_plaintext_fields(): void
    {
        Http::fake(['https://api.test/api/v1/admin/keys' => Http::response(['data' => [['id' => 7, 'status' => 'active']]])]);

        self::assertSame(200, (new ListApiKeys())->run($this->contextWithConsumer())->lastResponse['status']);
    }

    public function test_authenticate_checks_success_and_unauthorized_contracts(): void
    {
        Http::fake(['https://api.test/api/v1/auth/verify' => Http::sequence()
            ->push(['data' => ['status' => 'ok']], 200)
            ->push(['type' => 'unauthorized', 'data' => null], 401)]);
        $context = $this->contextWithConsumer();

        self::assertSame(200, (new Authenticate())->run($context)->lastResponse['status']);
        self::assertSame(401, (new Authenticate(401))->run($context)->lastResponse['status']);
    }

    public function test_revoke_key_requires_no_content_response(): void
    {
        Http::fake(['https://api.test/api/v1/admin/keys/7' => Http::response([], 204)]);

        self::assertSame(204, (new RevokeApiKey())->run($this->contextWithConsumer())->lastResponse['status']);
    }

    public function test_movie_lookup_checks_found_and_not_found_results(): void
    {
        Http::fake(['https://api.test/api/v1/movies/*' => Http::sequence()
            ->push(['data' => ['code' => 'SSIS-001']], 200)
            ->push(['type' => 'movie_not_found', 'data' => null], 404)]);
        $context = $this->contextWithConsumer();

        self::assertSame(200, (new LookupMovie('ssis001', 200, 'SSIS-001'))->run($context)->lastResponse['status']);
        self::assertSame(404, (new LookupMovie('UNKNOWN', 404))->run($context)->lastResponse['status']);
    }

    public function test_bulk_lookup_checks_per_code_results_and_limit_response(): void
    {
        Http::fake(['https://api.test/api/v1/movies:bulk' => Http::sequence()
            ->push(['data' => [['code' => 'SSIS-001', 'status' => 'found']]], 200)
            ->push(['type' => 'bulk_limit_exceeded', 'data' => null], 413)]);
        $context = $this->contextWithConsumer();

        (new BulkLookupMovies(['SSIS-001'], 200, ['SSIS-001' => 'found']))->run($context);
        self::assertSame(413, (new BulkLookupMovies(['SSIS-001'], 413))->run($context)->lastResponse['status']);
    }

    public function test_search_checks_codes_filters_validation_and_stable_pagination(): void
    {
        $calls = 0;
        Http::fake(static function (Request $request) use (&$calls) {
            $calls++;
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path !== '/api/v1/movies') {
                return Http::response([], 404);
            }
            if ($request->data()['q'] ?? null) {
                return Http::response(['data' => [['code' => 'SSIS-001', 'genres' => [['label' => 'Drama']]]]]);
            }
            if ($request->data()['genre'] ?? null) {
                return Http::response(['data' => [['code' => 'SSIS-001', 'genres' => [['label' => 'Drama']]]]]);
            }
            $cursor = $request->data()['cursor'] ?? null;

            return match ($cursor) {
                null => Http::response(['data' => [['code' => 'E2E-001'], ['code' => 'E2E-002']], 'meta' => ['pagination' => ['total' => 3, 'next_cursor' => 'cursor-1', 'has_more' => true]]]),
                'cursor-1' => Http::response(['data' => [['code' => 'SSIS-001']], 'meta' => ['pagination' => ['total' => 3, 'next_cursor' => 'cursor-2', 'has_more' => false]]]),
                default => Http::response(['data' => [], 'meta' => ['pagination' => ['total' => 3, 'next_cursor' => null, 'has_more' => false]]]),
            };
        });
        $context = $this->contextWithConsumer();

        (new SearchMovies(['q' => 'performer'], ['SSIS-001']))->run($context);
        (new SearchMovies(['genre' => 'drama'], ['SSIS-001']))->run($context);
        (new SearchMovies(['sort' => 'release_date', 'per_page' => 2], ['E2E-001', 'E2E-002', 'SSIS-001'], 200, true, true))->run($context);
        self::assertGreaterThanOrEqual(6, $calls);
    }

    public function test_performer_profile_checks_names_alias_links_and_photo_url(): void
    {
        Http::fake(['https://api.test/api/v1/performers/*' => Http::response(['data' => [
            'name_romaji' => 'E2E Performer',
            'name_kanji' => '出演者',
            'aliases' => ['E2E Alias'],
            'linked_title_count' => 1,
            'photos' => ['images' => [['url' => 'https://example.test/profile.jpg']]],
        ]])]);
        $catalog = $this->catalog();
        $context = $this->contextWithConsumer()->withCatalog($catalog);

        self::assertSame(200, (new GetPerformer())->run($context)->lastResponse['status']);
    }

    public function test_mcp_step_decodes_tool_result(): void
    {
        Http::fake(['https://api.test/api/v1/mcp' => Http::response(['result' => [
            'content' => [['type' => 'text', 'text' => '{"code":"SSIS-001"}']],
            'isError' => false,
        ]])]);

        self::assertSame(200, (new CallMcp('get_movie', [], ['code' => 'SSIS-001']))->run($this->contextWithMcp())->lastResponse['status']);
    }

    public function test_mcp_step_reports_invalid_results_and_accepts_generic_tools(): void
    {
        Http::fake(['https://api.test/api/v1/mcp' => Http::sequence()
            ->push(['result' => ['isError' => true, 'content' => []]])
            ->push(['result' => ['content' => [['text' => '{"code":"SSIS-001"}']]]])
            ->push(['result' => ['content' => [['text' => '{"code":123}']]]])
            ->push(['result' => ['content' => [['text' => '{"ok":true}']]]])]);
        $context = $this->contextWithMcp();

        foreach ([
            new CallMcp('get_movie'),
            new CallMcp('get_movie', [], ['code' => 'WRONG']),
            new CallMcp('get_movie'),
        ] as $step) {
            try {
                $step->run($context);
                self::fail('An invalid MCP result should fail the step.');
            } catch (StepFailure $failure) {
                self::assertStringContainsString('MCP tool', $failure->getMessage());
            }
        }

        self::assertSame(200, (new CallMcp('custom_tool'))->run($context)->lastResponse['status']);
    }

    public function test_wait_for_movie_returns_when_crawl_result_is_visible(): void
    {
        Http::fake(['https://api.test/api/v1/movies/*' => Http::response(['data' => ['code' => 'SSIS-001']])]);
        $context = $this->contextWithConsumer()->withCrawl(new CrawlResult('onejav', 'https://onejav.test/item', 'SSIS-001', 'fixture'));

        self::assertSame(200, (new WaitForMovie())->run($context)->lastResponse['status']);
    }

    public function test_state_steps_use_their_callbacks(): void
    {
        $catalog = $this->catalog();
        $fixture = new FixtureSeed('onejav', 'detail', 'https://onejav.test/item', '/tmp/fixture', 12);
        $crawl = new CrawlResult('onejav', $fixture->url, 'SSIS-001', 'Fixture title');
        $reset = (new ResetTestState(fn(): array => [$this->credential(), new ApiKeyCredential(8, 'jvm_mcp', 'mcp')]))->run($this->context());
        $seeded = (new SeedCatalog(static fn(): CatalogSeed => $catalog))->run($reset);
        $fixtureContext = (new SeedFixtureSource(static fn(): FixtureSeed => $fixture))->run($seeded);
        $crawled = (new RunCrawlOnce(static fn(FixtureSeed $seed): CrawlResult => $crawl))->run($fixtureContext);

        self::assertSame($catalog, $crawled->catalog);
        self::assertSame($fixture, $crawled->fixture);
        self::assertSame($crawl, $crawled->crawl);
    }

    public function test_scenario_failure_names_step_and_redacts_credentials(): void
    {
        $secret = 'jvm_super-secret';
        $context = $this->contextWithConsumer()->withExchange(['headers' => ['X-Api-Key' => $secret]], ['body' => $secret]);
        $scenario = new class extends Scenario {
            protected function name(): string
            {
                return 'broken journey';
            }

            protected function steps(): array
            {
                return [new BrokenStep()];
            }
        };

        try {
            $scenario->run($context);
            self::fail('A broken step should fail the scenario.');
        } catch (ScenarioFailure $exception) {
            self::assertStringContainsString('Scenario broken journey failed at step BrokenStep', $exception->getMessage());
            self::assertStringNotContainsString($secret, $exception->getMessage());
        }
    }

    public function test_redactor_masks_headers_known_values_and_key_patterns(): void
    {
        $redactor = new Redactor();
        $value = $redactor->redact([
            'headers' => ['X-Api-Key' => 'jvm_private-value', 'X-Owner-Token' => 'owner-secret'],
            'body' => 'the key is jvm_private-value and owner-secret',
        ], ['jvm_private-value', 'owner-secret']);

        self::assertSame('[REDACTED]', $value['headers']['X-Api-Key']);
        self::assertSame('[REDACTED]', $value['headers']['X-Owner-Token']);
        self::assertStringNotContainsString('private-value', json_encode($value) ?: '');
        self::assertStringNotContainsString('owner-secret', json_encode($value) ?: '');
    }

    public function test_redactor_formats_an_exchange_that_was_not_recorded(): void
    {
        self::assertSame('No HTTP exchange recorded.', (new Redactor())->formatExchange(null, null));
    }

    public function test_http_step_wraps_transport_errors_and_checks_context_and_status(): void
    {
        $step = new class extends HttpStep {
            public function run(Context $context): Context
            {
                return $this->exchange($context, 'PUT', '/not-supported')->context;
            }

            public function checkStatus(Context $context): void
            {
                $this->expectStatus($context, new Response(new PsrResponse(500)), 200);
            }

            public function checkCredential(Context $context): void
            {
                $this->credential($context);
            }
        };
        try {
            $step->run($this->context());
            self::fail('An unsupported HTTP method should be wrapped as a step failure.');
        } catch (StepFailure $failure) {
            self::assertStringContainsString('HTTP request failed (InvalidArgumentException)', $failure->getMessage());
        }

        try {
            $step->checkStatus($this->context());
            self::fail('An unexpected HTTP status should fail the step.');
        } catch (StepFailure $failure) {
            self::assertStringContainsString('Expected HTTP 200; received 500.', $failure->getMessage());
        }

        try {
            $step->checkCredential($this->context());
            self::fail('A missing credential should fail the step.');
        } catch (StepFailure $failure) {
            self::assertStringContainsString('No consumer API key is available', $failure->getMessage());
        }
    }

    public function test_find_crawled_movie_checks_lookup_code_filter_and_text_search(): void
    {
        Http::fake(static function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/v1/movies/SSIS-001') {
                return Http::response(['data' => ['code' => 'SSIS-001']]);
            }

            return isset($request->data()['code']) || isset($request->data()['q'])
                ? Http::response(['data' => [['code' => 'SSIS-001']]])
                : Http::response([], 500);
        });
        $context = $this->contextWithConsumer()->withCrawl($this->crawlResult());

        foreach ([FindCrawledMovie::LOOKUP, FindCrawledMovie::CODE, FindCrawledMovie::TEXT] as $mode) {
            self::assertSame(200, (new FindCrawledMovie($mode))->run($context)->lastResponse['status']);
        }
    }

    public function test_find_crawled_movie_fails_when_the_movie_is_missing_or_the_mode_is_unknown(): void
    {
        Http::fake(['https://api.test/api/v1/movies*' => Http::response(['data' => [['code' => 'OTHER-001']]])]);
        $context = $this->contextWithConsumer()->withCrawl($this->crawlResult());

        foreach ([[new FindCrawledMovie(FindCrawledMovie::TEXT), 'not found by text'], [new FindCrawledMovie('nonsense'), 'Unknown movie search mode']] as [$step, $message]) {
            try {
                $step->run($context);
                self::fail('A missing movie or unknown mode should fail the step.');
            } catch (StepFailure $failure) {
                self::assertStringContainsString($message, $failure->getMessage());
            }
        }

        try {
            (new FindCrawledMovie())->run($this->contextWithConsumer());
            self::fail('A journey without a crawl should fail the step.');
        } catch (StepFailure $failure) {
            self::assertStringContainsString('No fixture crawl', $failure->getMessage());
        }
    }

    public function test_find_crawled_movie_expects_rejected_requests_to_carry_no_data(): void
    {
        Http::fake(['https://api.test/api/v1/movies/*' => Http::sequence()
            ->push(['type' => 'unauthorized', 'data' => null], 401)
            ->push(['data' => ['code' => 'SSIS-001']], 401)]);
        $context = $this->contextWithConsumer()->withCrawl($this->crawlResult());

        self::assertSame(401, (new FindCrawledMovie(FindCrawledMovie::LOOKUP, 401))->run($context)->lastResponse['status']);
        $this->expectException(StepFailure::class);
        (new FindCrawledMovie(FindCrawledMovie::LOOKUP, 401))->run($context);
    }

    public function test_mcp_find_crawled_movie_reads_tool_results_and_rejections(): void
    {
        $result = static fn(array $payload): array => ['result' => ['content' => [['type' => 'text', 'text' => json_encode($payload)]], 'isError' => false]];
        Http::fake(['https://api.test/api/v1/mcp' => Http::sequence()
            ->push($result(['code' => 'SSIS-001']))
            ->push($result(['items' => [['code' => 'SSIS-001']]]))
            ->push($result(['items' => [['code' => 'SSIS-001']]]))
            ->push($result(['items' => []]))
            ->push(['result' => ['isError' => true, 'content' => []]])
            ->push(['type' => 'unauthorized', 'data' => null], 401)
            ->push(['result' => ['content' => []]], 401)]);
        $context = $this->contextWithMcp()->withCrawl($this->crawlResult());

        foreach (['get_movie', 'lookup_movies', 'search'] as $tool) {
            self::assertSame(200, (new McpFindCrawledMovie($tool))->run($context)->lastResponse['status']);
        }
        foreach (['search', 'get_movie'] as $tool) {
            try {
                (new McpFindCrawledMovie($tool))->run($context);
                self::fail('An empty or failed MCP result should fail the step.');
            } catch (StepFailure $failure) {
                self::assertStringContainsString('MCP tool', $failure->getMessage());
            }
        }
        self::assertSame(401, (new McpFindCrawledMovie('get_movie', 401))->run($context)->lastResponse['status']);
        try {
            (new McpFindCrawledMovie('get_movie', 401))->run($context);
            self::fail('A rejected MCP call with a tool result should fail the step.');
        } catch (StepFailure $failure) {
            self::assertStringContainsString('rejected MCP call', $failure->getMessage());
        }
    }

    public function test_mcp_find_crawled_movie_needs_a_crawl_and_a_known_tool(): void
    {
        foreach ([[$this->contextWithMcp(), 'get_movie', 'No fixture crawl'], [$this->contextWithMcp()->withCrawl($this->crawlResult()), 'get_counts', 'Unsupported MCP tool']] as [$context, $tool, $message]) {
            try {
                (new McpFindCrawledMovie($tool))->run($context);
                self::fail('The step should reject this context.');
            } catch (StepFailure $failure) {
                self::assertStringContainsString($message, $failure->getMessage());
            }
        }
    }

    public function test_index_search_step_passes_the_mode_and_expects_the_crawled_code(): void
    {
        $calls = [];
        $search = static function (string $mode, string $query) use (&$calls): array {
            $calls[] = [$mode, $query];

            return ($mode === QuerySearchIndex::SEMANTIC && $query === 'free text') || ($mode === QuerySearchIndex::TEXT && $query === 'Fixture title') ? ['SSIS-001'] : [];
        };
        $context = $this->context()->withCrawl($this->crawlResult());

        (new QuerySearchIndex($search, QuerySearchIndex::TEXT))->run($context);
        (new QuerySearchIndex($search, QuerySearchIndex::SEMANTIC, 'free text'))->run($context);
        self::assertSame([['text', 'Fixture title'], ['semantic', 'free text']], $calls);

        foreach ([[new QuerySearchIndex($search, QuerySearchIndex::SEMANTIC, 'other'), $context, 'did not return'], [new QuerySearchIndex($search, QuerySearchIndex::TEXT), $this->context(), 'No fixture crawl']] as [$step, $failing, $message]) {
            try {
                $step->run($failing);
                self::fail('The step should fail.');
            } catch (StepFailure $failure) {
                self::assertStringContainsString($message, $failure->getMessage());
            }
        }
    }

    public function test_outage_step_hands_the_fixture_to_its_callback(): void
    {
        $fixture = new FixtureSeed('onejav', 'detail', 'https://onejav.test/item', '/tmp/fixture', 12);
        $seen = null;
        $step = new RunCrawlOutage(static function (FixtureSeed $seed) use (&$seen): void {
            $seen = $seed;
        });

        $step->run($this->context()->withFixture($fixture));
        self::assertSame($fixture, $seen);

        $this->expectException(StepFailure::class);
        $step->run($this->context());
    }

    public function test_source_health_distinguishes_a_failing_source_from_a_recovered_one(): void
    {
        $error = '2026-10-16T10:00:00+00:00';
        $later = '2026-10-16T10:05:00+00:00';
        $health = static fn(?string $success, ?string $failure): array => ['status' => 'ok', 'sources' => [
            ['slug' => 'other', 'last_success_at' => $later, 'last_error_at' => null],
            ['slug' => 'onejav', 'last_success_at' => $success, 'last_error_at' => $failure],
        ]];
        Http::fake(['https://api.test/api/health' => Http::sequence()
            ->push($health(null, $error))
            ->push($health($later, $error))
            ->push($health($later, $error))
            ->push($health($later, null))
            ->push($health(null, $error))
            ->push($health('2026-10-16T09:00:00+00:00', $error))
            ->push($health(null, null))
            ->push(['status' => 'ok', 'sources' => []])
            ->push($health(null, $error))]);
        $context = $this->context()->withFixture(new FixtureSeed('onejav', 'detail', 'https://onejav.test/item', '/tmp/fixture', 12));

        (new CheckSourceHealth(CheckSourceHealth::DOWN))->run($context);
        $this->expectStepFailure(new CheckSourceHealth(CheckSourceHealth::DOWN), $context, 'as down');
        (new CheckSourceHealth(CheckSourceHealth::RECOVERED))->run($context);
        (new CheckSourceHealth(CheckSourceHealth::RECOVERED))->run($context);
        $this->expectStepFailure(new CheckSourceHealth(CheckSourceHealth::RECOVERED), $context, 'as recovered');
        $this->expectStepFailure(new CheckSourceHealth(CheckSourceHealth::RECOVERED), $context, 'as recovered');
        $this->expectStepFailure(new CheckSourceHealth(CheckSourceHealth::DOWN), $context, 'as down');
        $this->expectStepFailure(new CheckSourceHealth(CheckSourceHealth::DOWN), $context, 'omitted the fixture source');
        $this->expectStepFailure(new CheckSourceHealth('unknown'), $context, 'as unknown');
        $this->expectStepFailure(new CheckSourceHealth(CheckSourceHealth::DOWN), $this->context(), 'No fixture source');
    }

    public function test_revoke_step_targets_the_key_of_the_requested_purpose(): void
    {
        Http::fake(['https://api.test/api/v1/admin/keys/8' => Http::response([], 204)]);

        $context = (new RevokeApiKey('mcp'))->run($this->contextWithConsumer()->withCreatedKey(new ApiKeyCredential(8, 'jvm_mcp', 'mcp')));

        self::assertSame('https://api.test/api/v1/admin/keys/8', $context->lastRequest['url']);
    }

    public function test_a_broken_endpoint_makes_the_revocation_journey_fail(): void
    {
        $revokedStillWorks = Http::sequence()
            ->push(['data' => ['code' => 'SSIS-001']])
            ->push(['data' => ['code' => 'SSIS-001']]);
        Http::fake([
            'https://api.test/api/v1/admin/keys/*' => Http::response([], 204),
            'https://api.test/api/v1/admin/keys' => Http::response(['data' => ['id' => 41, 'plaintext' => 'jvm_generated-key']], 201),
            'https://api.test/api/v1/auth/verify' => Http::response(['type' => 'unauthorized', 'data' => null], 401),
            'https://api.test/api/v1/mcp' => Http::response(['result' => ['content' => [['type' => 'text', 'text' => '{"code":"SSIS-001"}']], 'isError' => false]]),
            'https://api.test/api/v1/movies/*' => $revokedStillWorks,
        ]);
        $fixture = new FixtureSeed('onejav', 'detail', 'https://onejav.test/item', '/tmp/fixture', 12);
        $scenario = new J9KeyRevocationScenario(
            fn(): array => [$this->credential(), new ApiKeyCredential(8, 'jvm_mcp', 'mcp')],
            static fn(): FixtureSeed => $fixture,
            fn(FixtureSeed $seed): CrawlResult => $this->crawlResult(),
        );

        try {
            $scenario->run($this->context());
            self::fail('A revoked key that still reads the catalog must fail the journey.');
        } catch (ScenarioFailure $exception) {
            self::assertStringContainsString('J9 revoked keys', $exception->getMessage());
            self::assertStringContainsString('failed at step FindCrawledMovie: Expected HTTP 401; received 200.', $exception->getMessage());
            self::assertStringNotContainsString('jvm_generated-key', $exception->getMessage());
        }
    }

    private function expectStepFailure(Step $step, Context $context, string $message): void
    {
        try {
            $step->run($context);
            self::fail('The step should have failed.');
        } catch (StepFailure $failure) {
            self::assertStringContainsString($message, $failure->getMessage());
        }
    }

    private function crawlResult(): CrawlResult
    {
        return new CrawlResult('onejav', 'https://onejav.test/item', 'SSIS-001', 'Fixture title');
    }

    private function context(): Context
    {
        return new Context('https://api.test', 'owner-secret');
    }

    private function contextWithConsumer(): Context
    {
        return $this->context()->withCreatedKey($this->credential());
    }

    private function contextWithMcp(): Context
    {
        return $this->context()->withCreatedKey(new ApiKeyCredential(8, 'jvm_mcp', 'mcp'));
    }

    private function credential(): ApiKeyCredential
    {
        return new ApiKeyCredential(7, 'jvm_consumer');
    }

    private function catalog(): CatalogSeed
    {
        return new CatalogSeed('SSIS-001', ['SSIS-001'], ['SSIS-001'], ['SSIS-001'], 1, 'performer-uuid', 'E2E Performer', '出演者', 'E2E Alias', 'drama');
    }
}
