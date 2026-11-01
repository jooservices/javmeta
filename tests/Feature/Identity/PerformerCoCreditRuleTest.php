<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Data\Crawl\MovieDraft;
use App\Data\Crawl\PerformerDraft;
use App\Models\Movie;
use App\Models\Performer;
use App\Models\PerformerIdentityEvent;
use App\Models\PerformerSource;
use App\Services\Identity\PerformerIdentityService;
use App\Services\Persist\MoviePersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PerformerCoCreditRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('elasticsearch.enabled', false);
    }

    public function test_merge_has_source_pair_movie_evidence_and_closes_only_its_review(): void
    {
        [$left, $right, $movie] = $this->pair();
        $other = $this->performer('third', 'Unrelated Person');
        $this->review($left, $right);
        $this->review($left, $other);
        $this->artisan('performers:backfill-identities', ['--rule' => 'co_credit'])->assertSuccessful();
        $event = PerformerIdentityEvent::query()->sole();
        self::assertSame('co_credit', $event->details['reason']);
        self::assertSame((int) $left->id, (int) $right->fresh()->canonical_performer_id);
        self::assertDatabaseHas('performer_identity_event_movie_links', [
            'event_id' => $event->id, 'movie_id' => $movie->id,
            'evidence_source_performer_id' => $left->id, 'evidence_candidate_performer_id' => $right->id,
        ]);
        self::assertDatabaseHas('performer_identity_reviews', ['candidate_performer_id' => $right->id, 'status' => 'resolved_co_credit', 'reason' => 'co_credit']);
        self::assertDatabaseHas('performer_identity_reviews', ['candidate_performer_id' => $other->id, 'status' => 'open']);
    }

    public function test_dry_run_rolls_back_and_reports_sample_and_rule_counts(): void
    {
        [$left, $right] = $this->pair();
        $this->review($left, $right);
        self::assertSame(0, Artisan::call('performers:backfill-identities', ['--dry-run' => true, '--rule' => 'co_credit']));
        $output = Artisan::output();
        self::assertStringContainsString('co_credit: 1', $output);
        self::assertStringContainsString('reviews_closed: 1', $output);
        self::assertStringContainsString('Co-credit sample: Aoi Sakura [left] + Sakura Aoi [right] — TEST-001', $output);
        self::assertDatabaseCount('performer_identity_events', 0);
        self::assertDatabaseCount('performer_identity_event_movie_links', 0);
        self::assertDatabaseCount('performer_name_keys', 0);
        self::assertNull($right->fresh()->canonical_performer_id);
        self::assertDatabaseHas('performer_identity_reviews', ['status' => 'open']);
    }

    #[DataProvider('rejectedPairs')]
    public function test_rejects_unsafe_pairs(string $name, array $leftAttrs, array $rightAttrs, bool $duplicate, string $reason): void
    {
        [$left, $right, $movie] = $this->pair($name, $leftAttrs, $rightAttrs);
        $this->review($right, $left);
        if ($duplicate) {
            $extra = $this->performer('right', $name);
            $this->credit($extra, $movie);
        }
        $this->artisan('performers:backfill-identities', ['--rule' => 'co_credit'])->assertSuccessful();
        self::assertDatabaseCount('performer_identity_events', 0);
        self::assertNull($right->fresh()->canonical_performer_id);
        self::assertDatabaseHas('performer_identity_reviews', ['status' => 'open', 'reason' => $reason]);
    }

    public static function rejectedPairs(): array
    {
        return [
            'single token' => ['Aika', [], [], false, 'birth_date_missing_or_conflicting'],
            'birth conflict' => ['Aoi Sakura', ['birth_date' => '1990-01-01'], ['birth_date' => '1991-01-01'], false, 'co_credit_conflicting_birth_date'],
            'blood conflict' => ['Aoi Sakura', ['blood_type' => 'A'], ['blood_type' => 'B'], false, 'co_credit_conflicting_blood_type'],
            'height conflict' => ['Aoi Sakura', ['height_cm' => 160], ['height_cm' => 170], false, 'co_credit_conflicting_height_cm'],
            'two same named cast members' => ['Aoi Sakura', [], [], true, 'birth_date_missing_or_conflicting'],
        ];
    }

    public function test_different_movies_and_same_source_do_not_supply_evidence(): void
    {
        [$left, $right] = $this->pair();
        DB::table('movie_performers')->where('performer_id', $right->id)->delete();
        $this->credit($right, Movie::factory()->create());
        $this->artisan('performers:backfill-identities', ['--rule' => 'co_credit'])->assertSuccessful();
        self::assertDatabaseCount('performer_identity_events', 0);
        self::assertNull($left->fresh()->canonical_performer_id);
    }

    public function test_two_strong_co_credit_candidates_remain_ambiguous(): void
    {
        [$left, , $movie] = $this->pair();
        $third = $this->performer('third', 'Aoi Sakura');
        $this->credit($third, $movie);
        $this->artisan('performers:backfill-identities', ['--rule' => 'co_credit'])->assertSuccessful();
        self::assertDatabaseCount('performer_identity_events', 0);
        self::assertDatabaseHas('performer_identity_reviews', ['source_performer_id' => $left->id, 'reason' => 'co_credit_ambiguous']);
    }

    public function test_merge_unmerge_is_lossless_for_mapping_relations_and_reviews(): void
    {
        [$left, $right] = $this->pair();
        $this->review($left, $right);
        $tables = ['performer_sources', 'movie_performers', 'performer_aliases', 'performer_media', 'performer_identity_reviews'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->map(static fn(object $row): array => (array) $row)->all();
        }
        $this->artisan('performers:backfill-identities', ['--rule' => 'co_credit'])->assertSuccessful();
        $event = PerformerIdentityEvent::query()->sole();
        $this->artisan('performers:identity-unmerge', ['event' => $event->id])->assertSuccessful();
        foreach ($tables as $table) {
            self::assertEquals($before[$table], DB::table($table)->get()->map(static fn(object $row): array => (array) $row)->all(), $table);
        }
        self::assertNull($left->fresh()->canonical_performer_id);
        self::assertNull($right->fresh()->canonical_performer_id);
    }

    public function test_second_run_changes_nothing_and_limit_caps_merges(): void
    {
        $this->pair();
        $this->artisan('performers:backfill-identities', ['--rule' => 'co_credit', '--limit' => 1])->assertSuccessful();
        self::assertSame(0, Artisan::call('performers:backfill-identities', ['--rule' => 'co_credit']));
        self::assertStringContainsString('co_credit: 0', Artisan::output());
        self::assertDatabaseCount('performer_identity_events', 1);
    }

    public function test_movie_crawl_merges_after_saving_the_full_cast(): void
    {
        $this->crawl('left', [PerformerDraft::from('left', 'a', 'Aoi Sakura')]);
        $this->crawl('right', [PerformerDraft::from('right', 'b', 'Sakura Aoi')]);
        self::assertSame(1, Performer::query()->whereNull('canonical_performer_id')->count());
        self::assertSame('co_credit', PerformerIdentityEvent::query()->sole()->details['reason']);
    }

    public function test_movie_crawl_does_not_prematurely_merge_the_first_of_two_same_names(): void
    {
        $this->crawl('left', [PerformerDraft::from('left', 'a', 'Aoi Sakura')]);
        $this->crawl('right', [PerformerDraft::from('right', 'b', 'Sakura Aoi'), PerformerDraft::from('right', 'c', 'Aoi Sakura')]);
        self::assertSame(3, Performer::query()->whereNull('canonical_performer_id')->count());
        self::assertDatabaseCount('performer_identity_events', 0);
    }

    public function test_synthetic_canonical_credit_does_not_establish_identity(): void
    {
        [, $right] = $this->pair();
        DB::table('movie_performers')->where('performer_id', $right->id)->update(['source_slug' => 'another-site']);
        $this->artisan('performers:backfill-identities', ['--rule' => 'co_credit'])->assertSuccessful();
        self::assertDatabaseCount('performer_identity_events', 0);
    }

    public function test_evidence_includes_all_shared_movies_and_undo_recloses_reviews(): void
    {
        [$left, $right] = $this->pair();
        $movie = Movie::factory()->create();
        $this->credit($left, $movie);
        $this->credit($right, $movie);
        $this->review($left, $right);
        $this->artisan('performers:backfill-identities', ['--rule' => 'co_credit'])->assertSuccessful();
        $merge = PerformerIdentityEvent::query()->sole();
        self::assertSame(2, DB::table('performer_identity_event_movie_links')->whereNotNull('evidence_source_performer_id')->count());
        $identity = app(PerformerIdentityService::class);
        $split = $identity->split((int) $merge->id);
        self::assertDatabaseHas('performer_identity_reviews', ['status' => 'open']);
        $identity->undoSplit((int) $split->id);
        self::assertDatabaseHas('performer_identity_reviews', ['status' => 'resolved_co_credit']);
        self::assertSame(4, DB::table('movie_performers')->count());
    }

    public function test_report_samples_at_most_twenty_merges(): void
    {
        foreach (range(1, 21) as $number) {
            $left = $this->performer('left', 'Person Name' . $number);
            $right = $this->performer('right', 'Name' . $number . ' Person');
            $movie = Movie::factory()->create();
            $this->credit($left, $movie);
            $this->credit($right, $movie);
        }
        self::assertSame(0, Artisan::call('performers:backfill-identities', ['--dry-run' => true, '--rule' => 'co_credit']));
        $output = Artisan::output();
        self::assertSame(20, substr_count($output, 'Co-credit sample:'));
        self::assertStringContainsString('co_credit: 21', $output);
        self::assertDatabaseCount('performer_identity_events', 0);
    }

    public function test_invalid_operator_options_are_rejected(): void
    {
        $this->artisan('performers:backfill-identities', ['--rule' => 'fuzzy'])->assertFailed();
        $this->artisan('performers:backfill-identities', ['--limit' => 0])->assertFailed();
        $this->artisan('performers:identity-unmerge', ['event' => 999])->assertFailed();
    }

    private function pair(string $name = 'Aoi Sakura', array $leftAttrs = [], array $rightAttrs = []): array
    {
        $left = $this->performer('left', $name, $leftAttrs);
        $right = $this->performer('right', $name === 'Aoi Sakura' ? 'Sakura Aoi' : $name, $rightAttrs);
        $movie = Movie::factory()->create(['display_code' => 'TEST-001', 'code_normalized' => 'test1']);
        $this->credit($left, $movie);
        $this->credit($right, $movie);

        return [$left, $right, $movie];
    }

    private function performer(string $source, string $name, array $attrs = []): Performer
    {
        $performer = Performer::factory()->create(['source_slug' => $source, 'name_romaji' => $name,
            'birth_date' => null, 'blood_type' => null, 'height_cm' => null, ...$attrs]);
        PerformerSource::query()->create(['performer_id' => $performer->id, 'source_performer_id' => $performer->id,
            'site_slug' => $source, 'external_id' => $performer->external_id, 'mongo_id' => 'local:' . $performer->id,
            'name_romaji' => $name, 'birth_date' => $attrs['birth_date'] ?? null, 'crawled_at' => now()]);

        return $performer;
    }

    private function credit(Performer $performer, Movie $movie): void
    {
        DB::table('movie_performers')->insert(['movie_id' => $movie->id, 'performer_id' => $performer->id,
            'source_slug' => $performer->source_slug, 'crawled_at' => now()]);
    }

    private function review(Performer $left, Performer $right): void
    {
        DB::table('performer_identity_reviews')->insert(['source_performer_id' => $left->id,
            'candidate_performer_id' => $right->id, 'reason' => 'birth_date_missing_or_conflicting',
            'similarity' => 1, 'status' => 'open', 'created_at' => now()]);
    }

    private function crawl(string $source, array $cast): void
    {
        app(MoviePersister::class)->persist(MovieDraft::from($source, 'TEST-001', 'TEST-001', performers: $cast));
    }
}
