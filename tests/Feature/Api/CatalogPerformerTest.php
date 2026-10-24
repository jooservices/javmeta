<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Performer;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogPerformerTest extends TestCase
{
    use RefreshDatabase;

    public function test_performer_catalogs_count_performers_by_cup_blood_type_and_location(): void
    {
        $headers = ['X-API-Key' => app(ApiKeyService::class)->create('catalog-performers')->plaintext];
        Performer::factory()->count(3)->create(['cup' => 'D', 'blood_type' => 'O', 'location' => 'Tokyo']);
        Performer::factory()->create(['cup' => 'E', 'blood_type' => null, 'location' => '']);

        $this->assertSame([['name' => 'D', 'performer_count' => 3], ['name' => 'E', 'performer_count' => 1]], $this->getJson('/api/v1/meta/catalog/cups', $headers)->assertOk()->json('data'));
        $this->assertSame([['name' => 'O', 'performer_count' => 3]], $this->getJson('/api/v1/meta/catalog/blood_types', $headers)->json('data'));
        $this->assertSame([['name' => 'Tokyo', 'performer_count' => 3]], $this->getJson('/api/v1/meta/catalog/locations?q=tok', $headers)->json('data'));
        $this->assertSame(['D', 'E'], array_column($this->getJson('/api/v1/meta/catalog/cups?sort=name', $headers)->json('data'), 'name'));
    }
}
