<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Services\Observability\NodeEgressProbe;
use App\Services\Report\NodeSampleStore;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JOOservices\Client\Client\ClientBuilder;
use JOOservices\Client\Testing\FakeTransport;
use Nyholm\Psr7\Response;
use RuntimeException;
use Tests\TestCase;

/** Gap coverage for node:egress-probe and node:sample. */
final class NodeCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'cache.default' => 'array',
            'openobserve.enabled' => false,
            'javmeta_report.node_volume_path' => '',
            'sentry.node' => 'xcrawler-test-node',
        ]);
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Http::fake();
    }

    public function test_egress_probe_refreshes_the_node_ip_through_the_configured_echo_url(): void
    {
        config()->set([
            'javmeta_observability.egress_probe_urls' => ['https://echo.test'],
            'javmeta_observability.egress_probe_interval_seconds' => 120,
        ]);
        $transport = new FakeTransport();
        $transport->push(new Response(200, [], "198.51.100.10\n"));
        App::forgetInstance(NodeEgressProbe::class);
        App::instance(ClientBuilder::class, ClientBuilder::create()->withTransport($transport));

        $this->artisan('node:egress-probe')->assertSuccessful();

        self::assertSame('198.51.100.10', app(NodeEgressProbe::class)->currentNodeIp());
        self::assertCount(1, $transport->recorded());

        // The cached probe is not due yet, so a second run must not call the echo URL again.
        $this->artisan('node:egress-probe')->assertSuccessful();
        self::assertCount(1, $transport->recorded());
    }

    public function test_node_sample_stores_a_sample_and_prints_it_as_json(): void
    {
        self::assertSame(0, Artisan::call('node:sample'));

        $output = Artisan::output();
        self::assertStringContainsString('Stored node sample for xcrawler-test-node.', $output);

        $sample = app(NodeSampleStore::class)->samples('xcrawler-test-node')[0];
        self::assertSame('xcrawler-test-node', $sample['node']);
        self::assertStringContainsString((string) json_encode($sample, JSON_UNESCAPED_SLASHES), $output);
    }

    public function test_node_sample_fails_when_the_sample_cannot_be_stored(): void
    {
        Artisan::all(); // boot the console application before the cache is replaced
        Cache::partialMock()->shouldReceive('get')->andThrow(new RuntimeException('cache is down'));

        $this->artisan('node:sample')
            ->expectsOutputToContain('This node sample could not be stored.')
            ->assertFailed();
    }
}
