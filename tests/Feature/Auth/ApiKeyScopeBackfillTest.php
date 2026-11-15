<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\ApiKey;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ApiKeyScopeBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_keys_without_scopes_receive_the_full_list_and_scoped_keys_are_untouched(): void
    {
        $legacy = app(ApiKeyService::class)->create('legacy', 60, ['movies'])->model;
        $narrow = app(ApiKeyService::class)->create('narrow', 60, ['status'])->model;
        DB::table('api_keys')->where('id', $legacy->getKey())->update(['scopes' => null]);
        $this->assertFalse($legacy->refresh()->allows('mcp'), 'A key without scopes grants nothing before the backfill.');

        $migration = require base_path('database/migrations/2026_11_09_000001_backfill_api_key_scopes.php');
        $migration->up();

        $this->assertSame(ApiKey::SCOPES, $legacy->refresh()->scopes, 'The frozen list must match the scopes known today.');
        $this->assertTrue($legacy->allows('mcp'));
        $this->assertSame(['status'], $narrow->refresh()->scopes);
    }
}
