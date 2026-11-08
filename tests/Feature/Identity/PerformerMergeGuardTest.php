<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\Performer;
use App\Models\PerformerIdentityEvent;
use App\Services\Identity\PerformerMergeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(PerformerMergeGuard::class)]
final class PerformerMergeGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function details(array $overrides = []): array
    {
        return $overrides + [
            'source_performer_ids' => [],
            'source_link_ids' => [],
            'alias_owners' => [['id' => 5, 'performer_id' => 2]],
            'media_owners' => [['id' => '6', 'performer_id' => '3']],
            'created_movie_links' => [['movie_id' => '9', 'source_slug' => 'javdb', 'crawled_at' => '2026-11-01T00:00:00Z']],
        ];
    }

    private function event(?array $details, ?Performer $from = null, ?Performer $to = null, bool $reversed = false): PerformerIdentityEvent
    {
        $from ??= Performer::factory()->create();
        $to ??= Performer::factory()->create();
        $event = PerformerIdentityEvent::query()->create([
            'event_type' => 'merge',
            'from_performer_id' => $from->id,
            'to_performer_id' => $to->id,
            'details' => $details ?? [],
            'created_at' => now(),
            'reversed_at' => $reversed ? now() : null,
        ]);
        if ($details === null) {
            DB::table('performer_identity_events')->where('id', $event->id)->update(['details' => 'null']);
            $event->refresh();
        }

        return $event;
    }

    public function test_complete_merge_details_are_normalised_to_integers(): void
    {
        $parsed = (new PerformerMergeGuard())->mergeDetails($this->event($this->details([
            'source_performer_ids' => [1, '2'],
            'source_link_ids' => ['7'],
        ])));

        self::assertSame([1, 2], $parsed['source_performer_ids']);
        self::assertSame([7], $parsed['source_link_ids']);
        self::assertSame([['id' => 5, 'performer_id' => 2]], $parsed['alias_owners']);
        self::assertSame([['id' => 6, 'performer_id' => 3]], $parsed['media_owners']);
        self::assertSame([['movie_id' => 9, 'source_slug' => 'javdb', 'crawled_at' => '2026-11-01T00:00:00Z']], $parsed['created_movie_links']);
    }

    public function test_legacy_events_without_inline_movie_links_are_still_valid(): void
    {
        $details = $this->details();
        unset($details['created_movie_links']);

        self::assertSame([], (new PerformerMergeGuard())->mergeDetails($this->event($details))['created_movie_links']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function incompleteDetails(): iterable
    {
        yield 'source ids not a list' => [['source_performer_ids' => ['a' => 1]]];
        yield 'source id not numeric' => [['source_performer_ids' => ['x']]];
        yield 'source id a float' => [['source_performer_ids' => [1.5]]];
        yield 'link ids missing' => [['source_link_ids' => null]];
        yield 'alias owner without a performer' => [['alias_owners' => [['id' => 1]]]];
        yield 'alias owners not a list' => [['alias_owners' => 'x']];
        yield 'media owner not an array' => [['media_owners' => [5]]];
        yield 'movie link without a slug' => [['created_movie_links' => [['movie_id' => 1, 'crawled_at' => 'x']]]];
        yield 'movie links not a list' => [['created_movie_links' => ['a' => []]]];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('incompleteDetails')]
    public function test_incomplete_merge_details_are_rejected(array $override): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The merge event details are incomplete.');

        (new PerformerMergeGuard())->mergeDetails($this->event($this->details($override)));
    }

    public function test_unreadable_event_details_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The merge event details are invalid.');

        (new PerformerMergeGuard())->mergeDetails($this->event(null));
    }

    public function test_a_merge_can_be_split_while_the_recorded_group_is_intact(): void
    {
        $canonical = Performer::factory()->create();
        $member = Performer::factory()->create(['canonical_performer_id' => $canonical->id]);
        $event = $this->event([], $member, $canonical);

        (new PerformerMergeGuard())->assertMergeCanBeSplit($event, $this->details(['source_performer_ids' => [$member->id]]));

        $this->addToAssertionCount(1);
    }

    public function test_a_merge_cannot_be_split_after_the_canonical_performer_changed(): void
    {
        $newTop = Performer::factory()->create();
        $canonical = Performer::factory()->create(['canonical_performer_id' => $newTop->id]);
        $member = Performer::factory()->create(['canonical_performer_id' => $canonical->id]);

        $this->expectExceptionMessage('the recorded canonical performer has changed');
        (new PerformerMergeGuard())->assertMergeCanBeSplit($this->event([], $member, $canonical), $this->details(['source_performer_ids' => [$member->id]]));
    }

    public function test_a_merge_cannot_be_split_after_a_member_was_moved(): void
    {
        $canonical = Performer::factory()->create();
        $elsewhere = Performer::factory()->create();
        $member = Performer::factory()->create(['canonical_performer_id' => $elsewhere->id]);

        $this->expectExceptionMessage('the recorded performer group has changed');
        (new PerformerMergeGuard())->assertMergeCanBeSplit($this->event([], $member, $canonical), $this->details(['source_performer_ids' => [$member->id]]));
    }

    public function test_a_merge_cannot_be_split_when_a_source_link_moved_to_another_performer(): void
    {
        $canonical = Performer::factory()->create();
        $other = Performer::factory()->create();
        $member = Performer::factory()->create(['canonical_performer_id' => $canonical->id]);
        $linkId = DB::table('performer_sources')->insertGetId($this->sourceLink($other->id));

        $this->expectExceptionMessage('the recorded source links have changed');
        (new PerformerMergeGuard())->assertMergeCanBeSplit(
            $this->event([], $member, $canonical),
            $this->details(['source_performer_ids' => [$member->id], 'source_link_ids' => [$linkId]]),
        );
    }

    public function test_a_split_can_be_undone_only_when_the_original_state_is_still_in_place(): void
    {
        $from = Performer::factory()->create();
        $to = Performer::factory()->create();
        $child = Performer::factory()->create(['canonical_performer_id' => $from->id]);
        $event = $this->event([], $from, $to, reversed: true);
        $details = $this->details(['source_performer_ids' => [$from->id, $child->id]]);

        (new PerformerMergeGuard())->assertSplitCanBeUndone($event, $details);

        $this->addToAssertionCount(1);
    }

    public function test_a_merge_that_is_not_split_cannot_be_undone(): void
    {
        $this->expectExceptionMessage('The merge event is not currently split.');

        (new PerformerMergeGuard())->assertSplitCanBeUndone($this->event([]), $this->details());
    }

    public function test_undoing_a_split_is_refused_when_either_side_has_been_merged_again(): void
    {
        $top = Performer::factory()->create();
        $from = Performer::factory()->create(['canonical_performer_id' => $top->id]);
        $to = Performer::factory()->create();

        $this->expectExceptionMessage('Restore later identity changes before undoing this split.');
        (new PerformerMergeGuard())->assertSplitCanBeUndone($this->event([], $from, $to, reversed: true), $this->details(['source_performer_ids' => [$from->id]]));
    }

    public function test_undoing_a_split_is_refused_when_the_original_source_is_not_recorded(): void
    {
        $from = Performer::factory()->create();
        $to = Performer::factory()->create();

        $this->expectExceptionMessage('The split event does not contain its original source performer.');
        (new PerformerMergeGuard())->assertSplitCanBeUndone($this->event([], $from, $to, reversed: true), $this->details(['source_performer_ids' => [$to->id]]));
    }

    public function test_undoing_a_split_is_refused_when_a_recorded_child_left_or_a_new_child_joined(): void
    {
        $from = Performer::factory()->create();
        $to = Performer::factory()->create();
        $left = Performer::factory()->create();
        $guard = new PerformerMergeGuard();
        $event = $this->event([], $from, $to, reversed: true);

        try {
            $guard->assertSplitCanBeUndone($event, $this->details(['source_performer_ids' => [$from->id, $left->id]]));
            self::fail('A child that is no longer linked must block the undo.');
        } catch (RuntimeException $error) {
            self::assertSame('Restore later identity changes before undoing this split.', $error->getMessage());
        }

        Performer::factory()->create(['canonical_performer_id' => $from->id]);
        $this->expectExceptionMessage('Restore later identity changes before undoing this split.');
        $guard->assertSplitCanBeUndone($event, $this->details(['source_performer_ids' => [$from->id]]));
    }

    public function test_undoing_a_split_is_refused_when_a_source_link_belongs_to_someone_else(): void
    {
        $from = Performer::factory()->create();
        $to = Performer::factory()->create();
        $linkId = DB::table('performer_sources')->insertGetId($this->sourceLink($to->id));

        $this->expectExceptionMessage('Restore later identity changes before undoing this split.');
        (new PerformerMergeGuard())->assertSplitCanBeUndone(
            $this->event([], $from, $to, reversed: true),
            $this->details(['source_performer_ids' => [$from->id], 'source_link_ids' => [$linkId]]),
        );
    }

    /** @return array<string, mixed> */
    private function sourceLink(int $performerId): array
    {
        return [
            'performer_id' => $performerId,
            'site_slug' => 'javdb',
            'mongo_id' => 'm' . $performerId . bin2hex(random_bytes(4)),
            'crawled_at' => now(),
        ];
    }
}
