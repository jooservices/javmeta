<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Data\Crawl\MovieDraft;
use App\Data\Crawl\PerformerDraft;
use App\Models\Movie;
use App\Models\Performer;
use App\Models\PerformerIdentityEvent;
use App\Models\PerformerSource;
use App\Services\Identity\PerformerCoCreditRule;
use App\Services\Identity\PerformerIdentityService;
use App\Services\Persist\MoviePersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use RuntimeException;

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

    public function test_sources_without_movie_credits_skip_the_name_candidate_lookup(): void
    {
        $left = $this->performer('left', 'Aoi Sakura');
        $right = $this->performer('right', 'Sakura Aoi');
        $identities = app(PerformerIdentityService::class);
        foreach ([$left->id, $right->id] as $performerId) {
            $identities->refreshNameKeys(PerformerSource::query()
                ->where('source_performer_id', $performerId)->firstOrFail());
        }
        $source = PerformerSource::query()->where('source_performer_id', $left->id)->firstOrFail();

        $candidateLookups = [];
        DB::listen(static function ($query) use (&$candidateLookups): void {
            if (str_contains($query->sql, 'performer_name_keys')) {
                $candidateLookups[] = $query->sql;
            }
        });
        $evaluation = app(PerformerCoCreditRule::class)->evaluate($source);

        self::assertSame('review', $evaluation['decision']);
        self::assertSame([], $candidateLookups);
    }

    public function test_same_site_name_candidates_skip_the_movie_candidate_lookup(): void
    {
        $left = $this->performer('same-site', 'Aoi Sakura');
        $right = $this->performer('same-site', 'Sakura Aoi');
        $movie = Movie::factory()->create();
        $this->credit($left, $movie);
        $this->credit($right, $movie);
        $identities = app(PerformerIdentityService::class);
        foreach ([$left->id, $right->id] as $performerId) {
            $identities->refreshNameKeys(PerformerSource::query()
                ->where('source_performer_id', $performerId)->firstOrFail());
        }
        $source = PerformerSource::query()->where('source_performer_id', $left->id)->firstOrFail();

        $candidateLookups = [];
        DB::listen(static function ($query) use (&$candidateLookups): void {
            if (str_contains($query->sql, 'performer_name_keys') && str_contains($query->sql, 'movie_performers')) {
                $candidateLookups[] = $query->sql;
            }
        });
        $evaluation = app(PerformerCoCreditRule::class)->evaluate($source);

        self::assertSame('review', $evaluation['decision']);
        self::assertSame([], $candidateLookups);
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

    public function test_merging_a_third_source_closes_group_reviews_and_unmerge_restores_them(): void
    {
        $root = $this->performer('left', 'Earlier StageName');
        $member = $this->performer('middle', 'Aoi Sakura');
        $incoming = $this->performer('right', 'Sakura Aoi');
        $member->update(['canonical_performer_id' => $root->id]);
        PerformerSource::query()->where('source_performer_id', $member->id)->update(['performer_id' => $root->id]);
        $movie = Movie::factory()->create();
        $this->credit($member, $movie);
        $this->credit($incoming, $movie);
        $this->review($root, $incoming);
        $this->review($member, $incoming);
        self::assertSame(0, Artisan::call('performers:backfill-identities', ['--rule' => 'co_credit']));
        self::assertStringContainsString('reviews_closed: 2', Artisan::output());
        self::assertSame(0, DB::table('performer_identity_reviews')->where('status', 'open')->count());
        $merge = PerformerIdentityEvent::query()->sole();
        $this->artisan('performers:identity-unmerge', ['event' => $merge->id])->assertSuccessful();
        self::assertSame(2, DB::table('performer_identity_reviews')->where('status', 'open')->count());
        self::assertSame((int) $root->id, (int) $member->fresh()->canonical_performer_id);
        self::assertNull($incoming->fresh()->canonical_performer_id);
    }

    public function test_conflict_note_is_preserved_even_when_other_candidates_are_ambiguous(): void
    {
        [$left, , $movie] = $this->pair('Aoi Sakura', ['height_cm' => 160]);
        $third = $this->performer('third', 'Aoi Sakura');
        $fourth = $this->performer('fourth', 'Sakura Aoi', ['height_cm' => 170]);
        $this->credit($third, $movie);
        $this->credit($fourth, $movie);
        $this->artisan('performers:backfill-identities', ['--rule' => 'co_credit'])->assertSuccessful();
        self::assertDatabaseCount('performer_identity_events', 0);
        self::assertDatabaseHas('performer_identity_reviews', ['source_performer_id' => $left->id,
            'candidate_performer_id' => $fourth->id, 'status' => 'open', 'reason' => 'co_credit_conflicting_height_cm']);
    }

    public function test_unmerge_rejects_mistyped_or_overflowing_ids_without_reversing_a_merge(): void
    {
        [, $right] = $this->pair();
        $this->artisan('performers:backfill-identities', ['--rule' => 'co_credit'])->assertSuccessful();
        $event = PerformerIdentityEvent::query()->sole();
        foreach ([$event->id . 'oops', $event->id . 'e0', (string) PHP_INT_MAX . '0', '0', '-1'] as $invalid) {
            $this->artisan('performers:identity-unmerge', ['event' => $invalid])->assertFailed();
            self::assertNull($event->fresh()->reversed_at);
            self::assertNotNull($right->fresh()->canonical_performer_id);
        }
    }

    public function test_crawl_refuses_a_movie_with_an_unkeyed_cast_member(): void
    {
        [$left, $right, $movie] = $this->pair();
        $identity = app(PerformerIdentityService::class);
        foreach (PerformerSource::query()->get() as $source) {
            $identity->refreshNameKeys($source);
        }
        $extra = $this->performer('right', 'Sakura Aoi');
        $this->credit($extra, $movie);
        $source = PerformerSource::query()->where('source_performer_id', $left->id)->firstOrFail();
        $identity->reconcileSource($source);
        self::assertDatabaseCount('performer_identity_events', 0);
        self::assertNull($right->fresh()->canonical_performer_id);
    }

    public function test_merge_rolls_back_when_shared_movie_evidence_disappears_after_validation(): void
    {
        [$left, $right] = $this->pair();
        $removed = false;
        DB::listen(static function ($query) use (&$removed, $right): void {
            if (! $removed && str_starts_with($query->sql, 'insert into "performer_identity_events"')) {
                $removed = true;
                DB::table('movie_performers')->where('performer_id', $right->id)->delete();
            }
        });
        try {
            app(PerformerIdentityService::class)->streamBackfill(false, static function (): void {}, 'co_credit');
            self::fail('A co-credit merge must retain movie evidence.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('evidence', $exception->getMessage());
        }
        self::assertTrue($removed);
        self::assertDatabaseCount('performer_identity_events', 0);
        self::assertDatabaseCount('movie_performers', 2);
        self::assertNull($left->fresh()->canonical_performer_id);
        self::assertNull($right->fresh()->canonical_performer_id);
    }

    public function test_undo_refuses_to_overwrite_a_review_changed_after_split(): void
    {
        [$left, $right] = $this->pair();
        $this->review($left, $right);
        $this->artisan('performers:backfill-identities', ['--rule' => 'co_credit'])->assertSuccessful();
        $identity = app(PerformerIdentityService::class);
        $split = $identity->split((int) PerformerIdentityEvent::query()->sole()->id);
        DB::table('performer_identity_reviews')->update(['reason' => 'co_credit_conflicting_height_cm']);
        try {
            $identity->undoSplit((int) $split->id);
            self::fail('Undo must not discard a newer review decision.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('review changed', $exception->getMessage());
        }
        self::assertDatabaseHas('performer_identity_reviews', ['status' => 'open', 'reason' => 'co_credit_conflicting_height_cm']);
        self::assertNull($right->fresh()->canonical_performer_id);
        self::assertNull($split->fresh()->reversed_at);
    }

    public function test_co_credit_does_not_override_multiple_legacy_strong_matches(): void
    {
        [$left, $right] = $this->pair('Aoi Sakura', ['birth_date' => '1990-01-01'], ['birth_date' => '1990-01-01']);
        $this->performer('third', 'Aoi Sakura', ['birth_date' => '1990-01-01']);
        $this->artisan('performers:backfill-identities')->assertSuccessful();
        self::assertDatabaseCount('performer_identity_events', 0);
        self::assertNull($left->fresh()->canonical_performer_id);
        self::assertNull($right->fresh()->canonical_performer_id);
        self::assertDatabaseHas('performer_identity_reviews', ['reason' => 'multiple_strong_matches', 'status' => 'open']);
    }

    #[DataProvider('crawlMeasurements')]
    public function test_movie_crawl_retains_measurements_before_checking_co_credit(?int $leftHeight, ?int $rightHeight, ?string $leftBlood, ?string $rightBlood, string $reason): void
    {
        $this->crawl('left', [PerformerDraft::from('left', 'a', 'Aoi Sakura', heightCm: $leftHeight, bloodType: $leftBlood)]);
        $this->crawl('right', [PerformerDraft::from('right', 'b', 'Sakura Aoi', heightCm: $rightHeight, bloodType: $rightBlood)]);
        self::assertSame(2, Performer::query()->whereNull('canonical_performer_id')->count());
        self::assertDatabaseCount('performer_identity_events', 0);
        self::assertDatabaseHas('performer_identity_reviews', ['status' => 'open', 'reason' => $reason]);
    }

    public static function crawlMeasurements(): array
    {
        return [
            [160, 170, null, null, 'co_credit_conflicting_height_cm'],
            [null, null, 'A', 'B', 'co_credit_conflicting_blood_type'],
        ];
    }

    public function test_movie_recrawl_does_not_erase_previously_known_measurements(): void
    {
        $this->crawl('left', [PerformerDraft::from('left', 'a', 'Aoi Sakura', heightCm: 160, bloodType: 'A')]);
        $this->crawl('left', [PerformerDraft::from('left', 'a', 'Aoi Sakura')]);
        $performer = Performer::query()->sole();
        self::assertSame(160, $performer->height_cm);
        self::assertSame('A', $performer->blood_type);
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
