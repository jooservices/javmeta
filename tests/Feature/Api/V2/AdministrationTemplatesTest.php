<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Contracts\ReportChannel;
use Illuminate\Support\Facades\DB;

final class AdministrationTemplatesTest extends AdministrationTestCase
{
    private const KEY = 'backup.stale';

    private const URL = '/api/v2/notification-templates/backup.stale';

    /** @return array<string, string> headers of an unsafe request with the template's current ETag */
    private function withEtag(array $csrf): array
    {
        return $csrf + ['If-Match' => (string) $this->getJson(self::URL)->headers->get('ETag')];
    }

    private function channel(bool $accepts): object
    {
        $channel = new class ($accepts) implements ReportChannel {
            /** @var list<array{string, string}> */
            public array $sent = [];

            public function __construct(private readonly bool $accepts) {}

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                $this->sent[] = [$subject, $body];

                return $this->accepts;
            }
        };
        $this->app->instance(ReportChannel::class, $channel);

        return $channel;
    }

    public function test_the_list_and_one_template_have_the_contract_shape(): void
    {
        $this->admin();

        $list = $this->getJson('/api/v2/notification-templates')->assertOk();
        $this->assertGreaterThan(5, count($list->json('data')));
        $one = $this->getJson(self::URL)->assertOk();
        $this->assertNotEmpty($one->headers->get('ETag'));
        $one->assertJsonPath('data.key', self::KEY)->assertJsonPath('data.channel', 'telegram')->assertJsonPath('data.version', 1)->assertJsonPath('data.sent_count', 0)->assertJsonPath('data.last_sent_at', null);
        $this->assertContains('level', $one->json('data.allowed_variables'));
        $this->getJson('/api/v2/notification-templates/nope.nope')->assertNotFound()->assertJsonPath('code', 'template_not_found');
    }

    public function test_a_save_needs_if_match_writes_a_revision_and_raises_the_version(): void
    {
        [, $csrf] = $this->admin();
        $etag = (string) $this->getJson(self::URL)->headers->get('ETag');
        $body = ['title_template' => '<b>{level} Backup is old</b>', 'cooldown_minutes' => 30, 'escalation_minutes' => 60, 'tier' => 'critical', 'enabled' => false];

        $this->putJson(self::URL, $body, $csrf)->assertStatus(428)->assertJsonPath('code', 'precondition_required');
        $this->putJson(self::URL, $body, $csrf + ['If-Match' => '"old"'])->assertStatus(412)->assertJsonPath('code', 'precondition_failed');

        $saved = $this->putJson(self::URL, $body, $csrf + ['If-Match' => $etag])->assertOk();
        $saved->assertJsonPath('data.version', 2)->assertJsonPath('data.title_template', '<b>{level} Backup is old</b>')->assertJsonPath('data.cooldown_minutes', 30)->assertJsonPath('data.escalation_minutes', 60)->assertJsonPath('data.tier', 'critical')->assertJsonPath('data.enabled', false);
        $this->assertNotSame($etag, $saved->headers->get('ETag'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'notification_template.update')->count());

        $revisions = $this->getJson(self::URL . '/revisions')->assertOk();
        $revisions->assertJsonPath('data.0.version', 1)->assertJsonStructure(['data' => [['id', 'version', 'title_template', 'body_template', 'enabled', 'severity', 'tier', 'cooldown_minutes', 'created_at', 'updated_by']]]);
    }

    public function test_unknown_variables_and_wrong_fields_are_422(): void
    {
        [, $csrf] = $this->admin();
        $headers = fn(): array => $this->withEtag($csrf);

        $this->putJson(self::URL, ['title_template' => 'Hi {nonsense}'], $headers())->assertStatus(422)->assertJsonFragment(['field' => 'title_template', 'code' => 'unknown_variable']);
        $this->putJson(self::URL, ['body_template' => 'Hi {nonsense}'], $headers())->assertStatus(422)->assertJsonFragment(['field' => 'body_template', 'code' => 'unknown_variable']);
        $this->putJson(self::URL, ['tier' => 'huge', 'cooldown_minutes' => -1], $headers())->assertStatus(422)->assertJsonFragment(['field' => 'tier', 'code' => 'in'])->assertJsonFragment(['field' => 'cooldown_minutes', 'code' => 'min']);
        $this->putJson(self::URL, ['body_template' => '<script>x</script>'], $headers())->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->putJson('/api/v2/notification-templates/nope.nope', ['enabled' => true], $csrf + ['If-Match' => '"x"'])->assertNotFound();
    }

    public function test_a_revision_can_be_restored_as_a_new_one(): void
    {
        [, $csrf] = $this->admin();
        $original = $this->getJson(self::URL)->json('data.title_template');
        $this->putJson(self::URL, ['title_template' => 'Changed {level}'], $this->withEtag($csrf))->assertOk();
        $revisionId = (int) $this->getJson(self::URL . '/revisions')->json('data.0.id');

        $restored = $this->postJson(self::URL . "/revisions/{$revisionId}/restorations", [], $csrf)->assertCreated();
        $restored->assertHeader('Location', self::URL)->assertJsonPath('data.title_template', $original)->assertJsonPath('data.version', 3);
        $this->assertCount(2, $this->getJson(self::URL . '/revisions')->json('data'));

        $this->postJson(self::URL . '/revisions/999999/restorations', [], $csrf)->assertNotFound()->assertJsonPath('code', 'revision_not_found');
        $this->postJson('/api/v2/notification-templates/nope.nope/revisions/1/restorations', [], $csrf)->assertNotFound()->assertJsonPath('code', 'template_not_found');
        $this->getJson('/api/v2/notification-templates/nope.nope/revisions')->assertNotFound();
    }

    public function test_a_preview_renders_with_sample_values_and_lists_unknown_variables(): void
    {
        [, $csrf] = $this->admin();

        $stored = $this->postJson(self::URL . '/previews', [], $csrf)->assertOk();
        $this->assertSame([], $stored->json('data.unknown_variables'));
        $this->assertStringNotContainsString('{level}', $stored->json('data.title'));

        $custom = $this->postJson(self::URL . '/previews', ['title_template' => 'T {level} {ghost}', 'body_template' => 'B {level}', 'variables' => ['level' => 'LOUD', 'ghost' => 'x']], $csrf)->assertOk();
        $custom->assertJsonPath('data.unknown_variables', ['ghost'])->assertJsonPath('data.variables.level', 'LOUD');
        $this->assertStringContainsString('[ghost]', $custom->json('data.title'));
        $this->assertArrayNotHasKey('ghost', $custom->json('data.variables'));

        $this->postJson(self::URL . '/previews', ['body_template' => '<script>x</script>'], $csrf)->assertStatus(422);
        $this->postJson('/api/v2/notification-templates/nope.nope/previews', [], $csrf)->assertNotFound();
    }

    public function test_a_test_delivery_sends_one_message_and_counts_it(): void
    {
        [, $csrf] = $this->admin();
        $channel = $this->channel(true);

        $response = $this->postJson(self::URL . '/test-deliveries', [], $csrf)->assertStatus(202)->assertJsonPath('data.state', 'queued');
        $this->assertCount(1, $channel->sent);
        $this->assertSame($response->json('data.preview.title'), $channel->sent[0][0]);
        $this->assertSame(1, $this->getJson(self::URL)->json('data.sent_count'));
        $this->postJson('/api/v2/notification-templates/nope.nope/test-deliveries', [], $csrf)->assertNotFound();
    }

    public function test_a_refused_test_delivery_is_an_error_and_is_not_counted(): void
    {
        [, $csrf] = $this->admin();
        $this->channel(false);

        $this->postJson(self::URL . '/test-deliveries', [], $csrf)->assertStatus(409)->assertJsonPath('code', 'delivery_failed');
        $this->assertSame(0, $this->getJson(self::URL)->json('data.sent_count'));
    }

    public function test_the_override_is_removed_by_writing_the_first_version_as_a_new_revision(): void
    {
        [, $csrf] = $this->admin();
        $original = $this->getJson(self::URL)->json('data.body_template');
        $this->putJson(self::URL, ['body_template' => 'Edited {level}', 'enabled' => false], $this->withEtag($csrf))->assertOk();

        $this->deleteJson(self::URL . '/overrides', [], $csrf)->assertNoContent();

        $after = $this->getJson(self::URL)->assertOk();
        $after->assertJsonPath('data.body_template', $original)->assertJsonPath('data.enabled', true)->assertJsonPath('data.version', 3);
        $this->deleteJson('/api/v2/notification-templates/nope.nope/overrides', [], $csrf)->assertNotFound();
    }
}
