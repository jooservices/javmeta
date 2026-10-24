<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Services\Auth\ApiKeyService;
use App\Services\Dataset\SampleDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PerformerFiltersTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        app(SampleDataset::class)->load();
        $this->headers = ['X-API-Key' => app(ApiKeyService::class)->create('pf')->plaintext];
        DB::table('performers')->where('external_id', 'p0003')->update(['retired_date' => '2020-01-01', 'image_url' => 'https://img.test/a.jpg']);
    }

    private function total(string $query): int
    {
        return (int) $this->getJson('/api/v1/performers?per_page=1&' . $query, $this->headers)->assertOk()->json('meta.pagination.total');
    }

    public function test_the_new_performer_filters_narrow_the_list(): void
    {
        $all = $this->total('');
        $this->assertSame(210, $all);

        $this->assertSame(1, $this->total('has_image=1'));
        $this->assertSame($all - 1, $this->total('has_image=0'));
        $this->assertSame(1, $this->total('status=retired'));
        $this->assertSame($all - 1, $this->total('status=active'));
        $this->assertLessThan($all, $this->total('has_bio=1'));
        $this->assertGreaterThan(0, $this->total('movies_min=1'));
        $this->assertSame(0, $this->total('movies_min=100000'));
        $this->assertGreaterThan(0, $this->total('debut_from=2010&debut_to=2020'));
        $this->assertSame(0, $this->total('debut_from=2099'));
        $this->assertSame($all, $this->total('source=sample'));
        $this->assertSame(0, $this->total('source=nonexistent'));
    }

    public function test_several_cups_or_blood_types_mean_any_of_them(): void
    {
        $d = $this->total('cup=D');
        $e = $this->total('cup=E');
        $this->assertSame($d + $e, $this->total('cup=' . urlencode('D,E')));
        $this->assertGreaterThan(0, $this->total('blood_type=' . urlencode('A,B')));
        $this->getJson('/api/v1/performers?status=maybe', $this->headers)->assertStatus(400);
    }

    public function test_performer_facets_count_inside_the_current_result(): void
    {
        $f = $this->getJson('/api/v1/performers/facets', $this->headers)->assertOk()->json('data');
        $this->assertSame(210, $f['total']);
        $this->assertNotEmpty($f['cups']);
        $this->assertNotEmpty($f['blood_types']);
        $this->assertNotEmpty($f['locations']);
        $this->assertNotEmpty($f['birth_decades']);
        $this->assertSame('sample', $f['sources'][0]['name']);
        $this->assertSame(['active' => 209, 'retired' => 1], collect($f['status'])->pluck('count', 'name')->all());

        $narrow = $this->getJson('/api/v1/performers/facets?cup=D', $this->headers)->json('data');
        $this->assertSame(['D'], array_column($narrow['cups'], 'name'));
        $this->getJson('/api/v1/performers/facets')->assertUnauthorized();
        $this->getJson('/api/v1/performers/1', $this->headers)->assertOk();
    }
}
