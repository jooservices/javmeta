<?php

declare(strict_types=1);

namespace Tests\Feature\Nodes;

use App\Events\NodeEnrolled;
use App\Events\NodeEnrollmentCreated;
use App\Models\NodeEnrollment;
use App\Services\Nodes\NodeEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(NodeEnrollmentService::class)]
final class NodeEnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://cc.example.test/',
            'javmeta_nodes.enrollment_ttl_minutes' => 30,
            'javmeta_nodes.release_repo_url' => 'https://git.example.test/xcrawler.git',
            'javmeta_nodes.release_ref' => 'v1.2.3',
        ]);
        $this->travelTo('2026-11-05T10:00:00Z');
    }

    public function test_creating_an_enrollment_stores_only_the_hash_and_returns_the_command_once(): void
    {
        Event::fake([NodeEnrollmentCreated::class]);

        $grant = $this->service()->create('worker-1', 'worker', 7);

        self::assertMatchesRegularExpression('/^jne_[a-f0-9]{64}$/', $grant->token);
        self::assertSame('curl -fsSL https://cc.example.test/node/join.sh | sudo bash -s -- --token ' . $grant->token . ' --name worker-1', $grant->command);
        self::assertSame('2026-11-05T10:30:00+00:00', $grant->expiresAt->toIso8601String());
        $row = DB::table('node_enrollments')->first();
        self::assertSame(hash('sha256', $grant->token), $row->token_hash);
        self::assertNotSame($grant->token, $row->token_hash);
        self::assertSame(7, (int) $row->created_by);
        self::assertStringNotContainsString($grant->token, json_encode(NodeEnrollment::query()->first()->toArray(), JSON_THROW_ON_ERROR), 'The hash is hidden from serialisation.');
        Event::assertDispatched(NodeEnrollmentCreated::class, static fn(NodeEnrollmentCreated $event): bool => $event->nodeName === 'worker-1' && $event->createdBy === 7);
    }

    public function test_an_unsupported_role_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->create('worker-1', 'primary', null);
    }

    public function test_the_ttl_has_a_five_minute_floor(): void
    {
        config(['javmeta_nodes.enrollment_ttl_minutes' => 0]);

        self::assertSame('2026-11-05T10:05:00+00:00', $this->service()->create('worker-1', 'worker', null)->expiresAt->toIso8601String());
    }

    public function test_the_right_node_redeems_once_and_receives_the_bundle(): void
    {
        $grant = $this->service()->create('worker-1', 'worker', null);
        Event::fake([NodeEnrolled::class]);

        $bundle = $this->service()->redeem($grant->token, 'worker-1', '203.0.113.9');

        self::assertNotNull($bundle);
        self::assertSame('worker-1', $bundle->nodeName);
        self::assertSame('worker', $bundle->role);
        self::assertSame('worker', $bundle->answers['NODE_ROLE']);
        self::assertSame('worker-1', $bundle->answers['JAVMETA_NODE_NAME']);
        self::assertSame('https://git.example.test/xcrawler.git', $bundle->answers['REPO_URL']);
        self::assertSame('v1.2.3', $bundle->answers['REF']);
        self::assertNotEmpty($bundle->answers['APP_KEY']);
        self::assertArrayHasKey('DB_HOST', $bundle->answers);
        self::assertArrayHasKey('REDIS_HOST', $bundle->answers);
        self::assertArrayHasKey('ELASTICSEARCH_HOST', $bundle->answers);
        Event::assertDispatched(NodeEnrolled::class, static fn(NodeEnrolled $event): bool => $event->ip === '203.0.113.9');
        $row = DB::table('node_enrollments')->first();
        self::assertNotNull($row->used_at);
        self::assertSame('203.0.113.9', $row->used_from_ip);

        self::assertNull($this->service()->redeem($grant->token, 'worker-1', '203.0.113.9'), 'A token works once.');
    }

    public function test_a_wrong_token_a_wrong_node_and_an_expired_token_all_get_the_same_nothing(): void
    {
        $grant = $this->service()->create('worker-1', 'worker', null);

        self::assertNull($this->service()->redeem('jne_' . str_repeat('0', 64), 'worker-1', '203.0.113.9'));
        self::assertNull($this->service()->redeem($grant->token, 'worker-2', '203.0.113.9'));
        $this->travel(31)->minutes();
        self::assertNull($this->service()->redeem($grant->token, 'worker-1', '203.0.113.9'));
        self::assertNull(DB::table('node_enrollments')->first()->used_at, 'A refused redemption leaves the enrollment untouched.');
    }

    public function test_the_bundle_never_carries_the_operator_only_secrets(): void
    {
        config(['laravel-notifications.channels.telegram.bot_token' => 'telegram-secret', 'sentry.dsn' => 'https://sentry-secret@sentry.example.test/1']);
        $grant = $this->service()->create('worker-1', 'worker', null);

        $encoded = json_encode($this->service()->redeem($grant->token, 'worker-1', '203.0.113.9')?->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('telegram-secret', $encoded);
        self::assertStringNotContainsString('sentry-secret', $encoded);
    }

    public function test_recent_lists_the_status_of_each_enrollment_newest_first(): void
    {
        $used = $this->service()->create('worker-used', 'worker', null);
        $this->service()->redeem($used->token, 'worker-used', '203.0.113.9');
        $this->service()->create('worker-expired', 'worker', null);
        $this->travel(31)->minutes();
        $this->service()->create('worker-pending', 'worker', null);

        $recent = $this->service()->recent();

        self::assertSame(['worker-pending', 'worker-expired', 'worker-used'], $recent->pluck('node_name')->all());
        self::assertSame(['pending', 'expired', 'used'], $recent->pluck('status')->all());
        self::assertNotNull($recent->last()['used_at']);
    }

    private function service(): NodeEnrollmentService
    {
        return app(NodeEnrollmentService::class);
    }
}
