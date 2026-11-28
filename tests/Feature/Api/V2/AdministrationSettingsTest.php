<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Services\Settings\SettingsService;
use App\Services\Settings\SettingsTranslator;
use Illuminate\Support\Facades\DB;

final class AdministrationSettingsTest extends AdministrationTestCase
{
    private function reportGroup(): string
    {
        return rawurlencode($this->reportLabel());
    }

    private function reportLabel(): string
    {
        return app(SettingsTranslator::class)->group('report', 'Báo cáo');
    }

    /** @return array<string, mixed> */
    private function item(array $group, string $key): array
    {
        foreach ($group['items'] as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        $this->fail("No item {$key}");
    }

    public function test_all_groups_are_listed_with_an_etag_and_the_item_shape_of_the_contract(): void
    {
        $this->admin();

        $response = $this->getJson('/api/v2/settings')->assertOk();
        $this->assertNotEmpty($response->headers->get('ETag'));
        $groups = $response->json('data');
        $this->assertGreaterThan(10, count($groups));
        $report = collect($groups)->firstWhere('group', $this->reportLabel());
        $item = $this->item($report, 'report.history_days');
        $this->assertSame('int', $item['type']);
        $this->assertSame(1, $item['min']);
        $this->assertSame(365, $item['max']);
        $this->assertFalse($item['secret']);
        $this->assertSame(1, $item['revision']);
        $this->assertNull($item['updated_at']);
        $this->assertFalse($item['restart_required']);
        $this->assertArrayHasKey('default', $item);
    }

    public function test_a_group_is_found_by_its_name_or_its_id_and_an_unknown_one_is_404(): void
    {
        $this->admin();

        $byName = $this->getJson('/api/v2/settings/' . $this->reportGroup())->assertOk()->assertJsonPath('data.group', $this->reportLabel());
        $this->assertNotEmpty($byName->headers->get('ETag'));
        $this->getJson('/api/v2/settings/report')->assertOk()->assertJsonPath('data.group', $this->reportLabel());
        $this->getJson('/api/v2/settings/nope')->assertNotFound()->assertJsonPath('code', 'settings_group_not_found');
    }

    public function test_secrets_are_never_returned(): void
    {
        $this->admin();
        $secrets = collect(app(SettingsService::class)->definitions())->filter(static fn(array $d): bool => $d['type'] === 'secret');
        $this->assertNotEmpty($secrets);
        $key = (string) $secrets->keys()->first();
        $group = (string) $secrets->first()['group'];

        $items = $this->getJson("/api/v2/settings/{$group}")->assertOk()->json('data.items');
        $item = collect($items)->firstWhere('key', $key);
        $this->assertTrue($item['secret']);
        $this->assertNull($item['value']);
        $this->assertNull($item['default']);
    }

    public function test_a_change_needs_if_match_and_is_applied_audited_and_bumps_the_revision(): void
    {
        [, $csrf] = $this->admin();
        $etag = (string) $this->getJson('/api/v2/settings/report')->headers->get('ETag');

        $this->putJson('/api/v2/settings/report', ['values' => ['report.history_days' => 14]], $csrf)->assertStatus(428)->assertJsonPath('code', 'precondition_required');
        $this->putJson('/api/v2/settings/report', ['values' => ['report.history_days' => 14]], $csrf + ['If-Match' => '"old"'])->assertStatus(412)->assertJsonPath('code', 'precondition_failed');

        $response = $this->putJson('/api/v2/settings/report', ['values' => ['report.history_days' => 14]], $csrf + ['If-Match' => $etag])->assertOk();
        $response->assertJsonPath('data.applied.0.key', 'report.history_days')->assertJsonPath('data.applied.0.restart_required', false);
        $this->assertNotEmpty($response->headers->get('ETag'));
        $this->assertNotSame($etag, $response->headers->get('ETag'));
        $item = $this->item($response->json('data'), 'report.history_days');
        $this->assertSame(14, $item['value']);
        $this->assertSame(2, $item['revision']);
        $this->assertNotNull($item['updated_at']);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'settings.update')->count());

        $this->putJson('/api/v2/settings/report', ['values' => ['report.history_days' => 14]], $csrf + ['If-Match' => $etag])->assertStatus(412);
    }

    public function test_an_unchanged_value_does_not_bump_the_revision(): void
    {
        [, $csrf] = $this->admin();
        $group = $this->getJson('/api/v2/settings/report');
        $current = $this->item($group->json('data'), 'report.history_days')['value'];

        $response = $this->putJson('/api/v2/settings/report', ['values' => ['report.history_days' => $current]], $csrf + ['If-Match' => (string) $group->headers->get('ETag')])->assertOk();

        $this->assertSame(1, $this->item($response->json('data'), 'report.history_days')['revision']);
    }

    public function test_wrong_values_are_rejected_together_with_a_code_each_and_nothing_is_saved(): void
    {
        [, $csrf] = $this->admin();
        $group = $this->getJson('/api/v2/settings/report');
        $headers = $csrf + ['If-Match' => (string) $group->headers->get('ETag')];
        $secret = collect(app(SettingsService::class)->definitions())->filter(static fn(array $d): bool => $d['type'] === 'secret' && $d['group'] === 'report')->keys()->first();

        $values = ['report.history_days' => 9999, 'report.enabled' => 'yes', 'report.nope' => 1, 'report.top_sources_limit' => 'many', 'report.channels' => ['sms']];
        if ($secret !== null) {
            $values[$secret] = 'x';
        }
        $response = $this->putJson('/api/v2/settings/report', ['values' => $values], $headers)->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $response->assertJsonFragment(['field' => 'report.history_days', 'code' => 'out_of_range', 'min' => 1, 'max' => 365])
            ->assertJsonFragment(['field' => 'report.enabled', 'code' => 'not_boolean'])
            ->assertJsonFragment(['field' => 'report.nope', 'code' => 'unknown_key'])
            ->assertJsonFragment(['field' => 'report.top_sources_limit', 'code' => 'invalid'])
            ->assertJsonFragment(['field' => 'report.channels', 'code' => 'invalid']);
        $this->assertSame(0, DB::table('setting_revisions')->count());
    }

    public function test_a_secret_key_is_not_editable(): void
    {
        [, $csrf] = $this->admin();
        $definition = collect(app(SettingsService::class)->definitions())->filter(static fn(array $d): bool => $d['type'] === 'secret')->first();
        $key = (string) collect(app(SettingsService::class)->definitions())->filter(static fn(array $d): bool => $d['type'] === 'secret')->keys()->first();
        $group = (string) $definition['group'];
        $etag = (string) $this->getJson("/api/v2/settings/{$group}")->headers->get('ETag');

        $this->putJson("/api/v2/settings/{$group}", ['values' => [$key => 'x']], $csrf + ['If-Match' => $etag])->assertStatus(422)->assertJsonFragment(['field' => $key, 'code' => 'secret_not_editable']);
    }

    public function test_the_body_is_validated_and_an_unknown_group_is_404_on_put(): void
    {
        [, $csrf] = $this->admin();

        $this->putJson('/api/v2/settings/report', [], $csrf + ['If-Match' => '"x"'])->assertStatus(422)->assertJsonFragment(['field' => 'values', 'code' => 'required']);
        $this->putJson('/api/v2/settings/nope', ['values' => ['a' => 1]], $csrf + ['If-Match' => '"x"'])->assertNotFound();
    }

    public function test_a_setting_that_needs_a_restart_says_so(): void
    {
        [, $csrf] = $this->admin();
        $definitions = collect(app(SettingsService::class)->definitions())->filter(static fn(array $d): bool => ! empty($d['restart']) && in_array($d['type'], ['int', 'bool'], true) && empty($d['bootstrap_only']));
        $this->assertNotEmpty($definitions);
        $key = (string) $definitions->keys()->first();
        $definition = $definitions->first();
        $group = (string) $definition['group'];
        $response = $this->getJson("/api/v2/settings/{$group}")->assertOk();
        $current = $this->item($response->json('data'), $key);
        $this->assertTrue($current['restart_required']);

        $applied = $this->putJson("/api/v2/settings/{$group}", ['values' => [$key => $current['value']]], $csrf + ['If-Match' => (string) $response->headers->get('ETag')])->assertOk();
        $applied->assertJsonPath('data.applied.0.restart_required', true)->assertJsonPath('data.applied.0.apply_after_seconds', null);
    }
}
