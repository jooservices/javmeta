<?php

declare(strict_types=1);

namespace Tests\Feature\Nodes;

use App\Models\User;
use App\Services\Nodes\NodeEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NodeEnrollmentEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://cc.example.test', 'javmeta_nodes.release_repo_url' => 'https://git.example.test/x.git', 'javmeta_nodes.release_ref' => 'main']);
    }

    public function test_only_a_super_administrator_creates_and_lists_enrollments(): void
    {
        $this->postJson('/ops/nodes', ['node_name' => 'worker-1', 'role' => 'worker'])->assertUnauthorized();
        $this->getJson('/ops/nodes')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $this->postJson('/ops/nodes', ['node_name' => 'worker-1', 'role' => 'worker'])->assertForbidden();
        $this->getJson('/ops/nodes')->assertForbidden();
    }

    public function test_the_owner_creates_an_enrollment_and_sees_it_listed_without_its_token(): void
    {
        $owner = User::factory()->create(['role' => 'super_administrator']);

        $created = $this->actingAs($owner)->postJson('/ops/nodes', ['node_name' => 'worker-1', 'role' => 'worker'])
            ->assertCreated()->assertHeader('Cache-Control')->json('data');

        self::assertMatchesRegularExpression('/^jne_[a-f0-9]{64}$/', $created['token']);
        self::assertStringContainsString('--token ' . $created['token'], $created['command']);
        $listed = $this->actingAs($owner)->getJson('/ops/nodes')->assertOk()->json('data');
        self::assertSame('worker-1', $listed[0]['node_name']);
        self::assertSame('pending', $listed[0]['status']);
        self::assertStringNotContainsString($created['token'], json_encode($listed, JSON_THROW_ON_ERROR));
        self::assertSame($owner->id, (int) DB::table('node_enrollments')->value('created_by'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badCreateInput(): iterable
    {
        yield 'no name' => [['role' => 'worker']];
        yield 'uppercase' => [['node_name' => 'Worker-1', 'role' => 'worker']];
        yield 'starts with a digit' => [['node_name' => '1worker', 'role' => 'worker']];
        yield 'too short' => [['node_name' => 'ab', 'role' => 'worker']];
        yield 'shell characters' => [['node_name' => 'w; rm -rf /', 'role' => 'worker']];
        yield 'unsupported role' => [['node_name' => 'worker-1', 'role' => 'primary']];
    }

    /** @param array<string, mixed> $input */
    #[\PHPUnit\Framework\Attributes\DataProvider('badCreateInput')]
    public function test_a_bad_node_name_or_role_is_rejected(array $input): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']))->postJson('/ops/nodes', $input)->assertUnprocessable();
        self::assertSame(0, DB::table('node_enrollments')->count());
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function dashboardLocales(): iterable
    {
        yield 'English dashboard with Vietnamese browser' => [
            'en',
            'vi',
            'The node name may only use lowercase letters, digits and hyphens, and must start with a letter.',
            'at least 3 characters',
        ];
        yield 'Vietnamese dashboard with English browser' => [
            'vi',
            'en',
            'Tên nút chỉ được dùng chữ thường, chữ số và dấu gạch nối, đồng thời phải bắt đầu bằng một chữ cái.',
            'ít nhất 3 ký tự',
        ];
    }

    #[DataProvider('dashboardLocales')]
    public function test_node_name_validation_errors_follow_the_selected_dashboard_language(
        string $selectedLocale,
        string $browserLocale,
        string $expectedRegexMessage,
        string $expectedMinMessage,
    ): void {
        $owner = User::factory()->create(['role' => 'super_administrator']);

        $this->actingAs($owner)
            ->withHeader('Accept-Language', $browserLocale)
            ->get('/ops?lang=' . $selectedLocale)
            ->assertOk()
            ->assertSee('<html lang="' . $selectedLocale . '">', false);

        $this->withHeader('Accept-Language', $selectedLocale)
            ->postJson('/ops/nodes', ['node_name' => 'Invalid_name', 'role' => 'worker'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.node_name.0', $expectedRegexMessage);

        $trimmedShortName = $this->withHeader('Accept-Language', $selectedLocale)
            ->postJson('/ops/nodes', ['node_name' => ' ab ', 'role' => 'worker'])
            ->assertUnprocessable();

        self::assertStringContainsString($expectedMinMessage, $trimmedShortName->json('errors.node_name.0'));

        self::assertSame(0, DB::table('node_enrollments')->count());
    }

    public function test_a_node_redeems_its_token_once_without_a_session(): void
    {
        $grant = app(NodeEnrollmentService::class)->create('worker-1', 'worker', null);

        $first = $this->postJson('/node/enroll', ['token' => $grant->token, 'node_name' => 'worker-1'])
            ->assertOk()->assertHeader('Cache-Control')->json('data');
        self::assertSame('worker-1', $first['node_name']);
        self::assertSame('worker', $first['answers']['NODE_ROLE']);
        self::assertSame('https://git.example.test/x.git', $first['answers']['REPO_URL']);

        $this->postJson('/node/enroll', ['token' => $grant->token, 'node_name' => 'worker-1'])
            ->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }

    public function test_the_same_nothing_is_returned_for_a_wrong_token_and_a_wrong_name(): void
    {
        $grant = app(NodeEnrollmentService::class)->create('worker-1', 'worker', null);

        $wrongToken = $this->postJson('/node/enroll', ['token' => 'jne_' . str_repeat('1', 64), 'node_name' => 'worker-1']);
        $wrongName = $this->postJson('/node/enroll', ['token' => $grant->token, 'node_name' => 'worker-2']);

        $wrongToken->assertNotFound();
        $wrongName->assertNotFound();
        self::assertSame($wrongToken->getContent(), $wrongName->getContent());
    }

    public function test_a_missing_or_malformed_token_is_unauthorised_and_a_bad_node_name_is_a_validation_error(): void
    {
        $this->postJson('/node/enroll', ['token' => 'not-a-token', 'node_name' => 'worker-1'])->assertUnauthorized();
        $this->postJson('/node/enroll', ['node_name' => 'worker-1'])->assertUnauthorized();
        $this->postJson('/node/enroll', [])->assertUnauthorized();
        $this->postJson('/node/enroll', ['token' => 'jne_' . str_repeat('a', 64)])->assertUnprocessable();
        $this->postJson('/node/enroll', ['token' => 'jne_' . str_repeat('a', 64), 'node_name' => 'Bad Name'])->assertUnprocessable();
    }

    public function test_redeeming_is_rate_limited_per_address(): void
    {
        foreach (range(1, 6) as $attempt) {
            $this->postJson('/node/enroll', ['token' => 'jne_' . str_repeat('2', 64), 'node_name' => 'worker-1'])->assertNotFound();
        }

        $this->postJson('/node/enroll', ['token' => 'jne_' . str_repeat('2', 64), 'node_name' => 'worker-1'])->assertStatus(429);
    }

    public function test_the_join_script_is_served_with_the_server_address_filled_in(): void
    {
        $response = $this->get('/node/join.sh')->assertOk();

        self::assertStringContainsString('server=\'https://cc.example.test\'', $response->getContent());
        self::assertStringNotContainsString('@SERVER_URL@', str_replace("'@SERVER_URL@'", '', $response->getContent()));
        self::assertStringStartsWith('text/x-shellscript', (string) $response->headers->get('Content-Type'));
    }

    public function test_neither_the_token_nor_the_bundle_reaches_the_log(): void
    {
        $messages = [];
        Log::listen(static function ($event) use (&$messages): void {
            $messages[] = $event->message . json_encode($event->context);
        });
        $grant = app(NodeEnrollmentService::class)->create('worker-1', 'worker', null);
        $this->postJson('/node/enroll', ['token' => $grant->token, 'node_name' => 'worker-1'])->assertOk();

        $logged = implode("\n", $messages);
        self::assertStringContainsString('javmeta.node_enrolled', $logged);
        self::assertStringNotContainsString($grant->token, $logged);
        self::assertStringNotContainsString('APP_KEY', $logged);
    }

    public function test_the_command_center_page_offers_the_add_node_form_to_the_owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']))
            ->get('/ops')
            ->assertOk()
            ->assertSee('id="node-form"', false)
            ->assertSee('Create join command')
            ->assertSee('Ubuntu 24.04')
            ->assertSee(str_replace('/', '\\/', route('operations.nodes.index')), false);
    }
}
