<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Performer;
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
        $this->headers = ['X-API-Key' => app(ApiKeyService::class)->create('pf', 60, ['performers'])->plaintext];
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

    public function test_performer_facets_key_is_deterministic_and_differs_for_different_filters(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        $a = $this->getJson('/api/v1/performers/facets', $this->headers)->assertOk()->json('data');
        $filtersA = ['include_unknown' => false];
        $keyA = 'javmeta:pfacets:' . hash('sha256', json_encode($filtersA, JSON_THROW_ON_ERROR));
        $this->assertSame($a, \Illuminate\Support\Facades\Cache::get($keyA));
        $this->assertSame($a, $this->getJson('/api/v1/performers/facets', $this->headers)->assertOk()->json('data'));

        $b = $this->getJson('/api/v1/performers/facets?cup=D', $this->headers)->assertOk()->json('data');
        $filtersB = ['cup' => 'D', 'include_unknown' => false];
        $keyB = 'javmeta:pfacets:' . hash('sha256', json_encode($filtersB, JSON_THROW_ON_ERROR));
        $this->assertNotSame($keyA, $keyB);
        $this->assertSame($b, \Illuminate\Support\Facades\Cache::get($keyB));
        $this->assertSame($a, \Illuminate\Support\Facades\Cache::get($keyA));
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

    public function test_blood_type_facets_merge_aliases_and_hide_unknown_by_default(): void
    {
        $canonicalCount = DB::table('performers')->whereNull('canonical_performer_id')->where('blood_type', 'A')->count();
        $emptyBloodTypes = DB::table('performers')->whereNull('canonical_performer_id')->whereNull('blood_type')->count();
        Performer::factory()->create(['source_slug' => 'facet-test', 'blood_type' => 'A型']);
        Performer::factory()->create(['source_slug' => 'facet-test', 'blood_type' => 'Trans']);

        $facets = $this->getJson('/api/v1/performers/facets', $this->headers)->assertOk()->json('data');
        $bloodTypes = collect($facets['blood_types'])->pluck('count', 'name')->all();
        $this->assertSame($canonicalCount + 1, $bloodTypes['A']);
        $this->assertArrayNotHasKey('Unknown', $bloodTypes);
        $this->assertArrayNotHasKey('Trans', $bloodTypes);

        $withUnknown = $this->getJson('/api/v1/performers/facets?include_unknown=1', $this->headers)->assertOk()->json('data');
        $this->assertSame($emptyBloodTypes + 1, collect($withUnknown['blood_types'])->firstWhere('name', 'Unknown')['count']);
    }

    public function test_canonical_profile_filters_match_merged_facets_without_overmatching(): void
    {
        Performer::factory()->create(['source_slug' => 'facet-test', 'cup' => 'Ｄ', 'blood_type' => 'Ａ型']);
        Performer::factory()->create(['source_slug' => 'facet-test', 'cup' => 'D', 'blood_type' => 'AB型']);
        Performer::factory()->create(['source_slug' => 'facet-test', 'cup' => 'Q', 'blood_type' => 'Trans']);
        Performer::factory()->create(['source_slug' => 'facet-test', 'cup' => null, 'blood_type' => null]);

        $facets = $this->getJson('/api/v1/performers/facets?include_unknown=1', $this->headers)->assertOk()->json('data');
        $bloodCounts = collect($facets['blood_types'])->pluck('count', 'name');
        $cupCounts = collect($facets['cups'])->pluck('count', 'name');

        $this->assertSame($bloodCounts['A'], $this->total('blood_type=A'));
        $this->assertSame($bloodCounts['AB'], $this->total('blood_type=AB'));
        $this->assertSame($bloodCounts['Unknown'], $this->total('blood_type=Unknown'));
        $this->assertSame($cupCounts['D'], $this->total('cup=D'));

        $tokyoBefore = $this->total('location=' . urlencode('東京都'));
        Performer::factory()->create(['source_slug' => 'facet-test', 'location' => 'Tokyo']);
        $this->assertSame($tokyoBefore + 1, $this->total('location=' . urlencode('東京都')));
    }
}
