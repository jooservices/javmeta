<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\PerformerRepository;
use App\Jobs\IndexPerformerJob;
use App\Services\Normalize\NormalizationRejectionCounter;
use App\Services\Normalize\PerformerAttributeNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use stdClass;

/** Rewrites historical performer profile values in bounded, resumable ID chunks. */
final class BackfillPerformerNormalizationCommand extends Command
{
    protected $signature = 'performers:backfill-normalization
        {--apply : Persist canonical values and queue Elasticsearch reindexing}
        {--chunk= : Number of performer rows per chunk (defaults to the database setting)}
        {--after=0 : Resume after this performer ID}';

    protected $description = 'Preview or apply canonical performer profile normalization in resumable chunks.';

    private const FIELDS = [
        'birth_date', 'height_cm', 'bust', 'waist', 'hip', 'cup', 'blood_type', 'blood_rh', 'debut_date', 'location',
    ];

    public function handle(PerformerAttributeNormalizer $normalizer, NormalizationRejectionCounter $rejections): int
    {
        $chunkSize = $this->validatedChunkSize();
        $cursor = filter_var($this->option('after'), FILTER_VALIDATE_INT);
        if ($cursor === false || $cursor < 0) {
            throw new InvalidArgumentException('Chunk must be 1–5000 and after must be a non-negative performer ID.');
        }

        $apply = (bool) $this->option('apply');
        $totals = $this->emptyTotals();
        $sourceBirthDateTotals = ['before' => 0, 'changed' => 0, 'after' => 0];
        $scanned = 0;
        $changedRows = 0;
        $startAfter = $cursor;

        $this->line($apply ? 'Mode: apply' : 'Mode: dry-run (no rows will be changed)');
        while (true) {
            $rows = app(PerformerRepository::class)->tableQuery('performers')->where('id', '>', $cursor)->orderBy('id')->limit($chunkSize)
                ->get(['id', 'canonical_performer_id', 'source_slug', ...self::FIELDS]);
            if ($rows->isEmpty()) {
                break;
            }

            $chunkCursor = (int) $rows->last()->id;
            $plan = $this->prepareChunk($rows, $normalizer, $apply, $totals, $sourceBirthDateTotals);
            $totals = $plan['totals'];
            $sourceBirthDateTotals = $plan['source_birth_date_totals'];
            if ($apply && ($plan['performers'] !== [] || $plan['sources'] !== [])) {
                $this->persistChunk($plan, $rejections);
            }

            $scanned += $rows->count();
            $changedRows += $plan['changed_rows'];
            $cursor = $chunkCursor;
            $this->line(sprintf('Checkpoint: --after=%d', $cursor));
        }

        $this->renderReport($totals, $sourceBirthDateTotals);
        $this->line(sprintf('Scanned %d performer rows after ID %d; %d database rows contain changes.', $scanned, $startAfter, $changedRows));
        $this->line(sprintf('Resume from performer ID %d with --after=%d.', $cursor, $cursor));

        return self::SUCCESS;
    }

    private function validatedChunkSize(): int
    {
        $configured = config('javmeta_normalization.backfill_chunk_size', 500);
        $option = $this->option('chunk');
        $chunkSize = filter_var($option === null || $option === '' ? $configured : $option, FILTER_VALIDATE_INT);
        if ($chunkSize === false || $chunkSize < 1 || $chunkSize > 5000) {
            throw new InvalidArgumentException('Chunk must be between 1 and 5000.');
        }

        return $chunkSize;
    }

    /** @return array<string, array{before: int, changed: int, after: int}> */
    private function emptyTotals(): array
    {
        $totals = [];
        foreach (self::FIELDS as $field) {
            $totals[$field] = ['before' => 0, 'changed' => 0, 'after' => 0];
        }

        return $totals;
    }

    /**
     * @param Collection<int, stdClass> $rows
     * @param array<string, array{before: int, changed: int, after: int}> $totals
     * @param array{before: int, changed: int, after: int} $sourceBirthDateTotals
     * @return array{
     *   performers: array<int, array<string, mixed>>,
     *   sources: array<int, ?string>,
     *   index_ids: array<int, true>,
     *   rejected: list<array{field: string, source: string}>,
     *   changed_rows: int,
     *   totals: array<string, array{before: int, changed: int, after: int}>,
     *   source_birth_date_totals: array{before: int, changed: int, after: int}
     * }
     */
    private function prepareChunk(
        Collection $rows,
        PerformerAttributeNormalizer $normalizer,
        bool $apply,
        array $totals,
        array $sourceBirthDateTotals,
    ): array {
        $updatedTotals = $totals;
        $updatedSourceBirthDateTotals = $sourceBirthDateTotals;
        $plan = ['performers' => [], 'sources' => [], 'index_ids' => [], 'rejected' => [], 'changed_rows' => 0];
        foreach ($rows as $row) {
            $source = (string) $row->source_slug;
            $original = [];
            foreach (self::FIELDS as $field) {
                $original[$field] = $row->{$field};
                $updatedTotals[$field]['before'] += $this->isNonEmpty($row->{$field}) ? 1 : 0;
            }
            $normalized = $normalizer->normalizeStoredAttributes($original, $source, false);
            $hasChanges = false;
            foreach (self::FIELDS as $field) {
                $value = $normalized[$field];
                $updatedTotals[$field]['after'] += $this->isNonEmpty($value) ? 1 : 0;
                if ($this->sameValue($original[$field], $value)) {
                    continue;
                }
                if ($apply && $value === null && $this->isNonEmpty($original[$field])) {
                    $plan['rejected'][] = ['field' => $field, 'source' => $source];
                }
                $hasChanges = true;
                $updatedTotals[$field]['changed']++;
            }
            if ($hasChanges) {
                $plan['changed_rows']++;
                $plan['performers'][(int) $row->id] = $normalized;
                $plan['index_ids'][(int) ($row->canonical_performer_id ?? $row->id)] = true;
            }
        }

        $sourceRows = app(PerformerRepository::class)->tableQuery('performer_sources')->whereIn('performer_id', $rows->pluck('id'))
            ->orderBy('id')->get(['id', 'performer_id', 'site_slug', 'birth_date']);
        foreach ($sourceRows as $sourceRow) {
            $updatedSourceBirthDateTotals['before'] += $this->isNonEmpty($sourceRow->birth_date) ? 1 : 0;
            $normalizedDate = $normalizer->date('birth_date', $sourceRow->birth_date, (string) $sourceRow->site_slug, false);
            $updatedSourceBirthDateTotals['after'] += $this->isNonEmpty($normalizedDate) ? 1 : 0;
            if ($this->sameValue($sourceRow->birth_date, $normalizedDate)) {
                continue;
            }
            $plan['sources'][(int) $sourceRow->id] = $normalizedDate;
            $plan['index_ids'][(int) $sourceRow->performer_id] = true;
            $plan['changed_rows']++;
            $updatedSourceBirthDateTotals['changed']++;
            if ($apply && $normalizedDate === null && $this->isNonEmpty($sourceRow->birth_date)) {
                $plan['rejected'][] = ['field' => 'birth_date', 'source' => (string) $sourceRow->site_slug];
            }
        }

        return [
            ...$plan,
            'totals' => $updatedTotals,
            'source_birth_date_totals' => $updatedSourceBirthDateTotals,
        ];
    }

    /**
     * @param array{
     *   performers: array<int, array<string, mixed>>,
     *   sources: array<int, ?string>,
     *   index_ids: array<int, true>,
     *   rejected: list<array{field: string, source: string}>,
     *   changed_rows: int
     * } $plan
     */
    private function persistChunk(array $plan, NormalizationRejectionCounter $rejections): void
    {
        app(PerformerRepository::class)->transaction(function () use ($plan, $rejections): void {
            $updatedAt = now();
            foreach ($plan['performers'] as $id => $attributes) {
                app(PerformerRepository::class)->tableQuery('performers')->where('id', $id)->update([...$attributes, 'updated_at' => $updatedAt]);
            }
            foreach ($plan['sources'] as $id => $birthDate) {
                app(PerformerRepository::class)->tableQuery('performer_sources')->where('id', $id)->update(['birth_date' => $birthDate]);
            }
            foreach ($plan['rejected'] as $item) {
                $rejections->increment('performer', $item['field'], $item['source']);
            }
            if ((bool) config('elasticsearch.enabled', true)) {
                foreach (array_keys($plan['index_ids']) as $performerId) {
                    IndexPerformerJob::dispatch((int) $performerId)
                        ->onQueue((string) config('javmeta_queues.search_queue', 'search'))
                        ->afterCommit();
                }
            }
        });
    }

    /** @param array<string, array{before: int, changed: int, after: int}> $totals
     *  @param array{before: int, changed: int, after: int} $sourceBirthDateTotals
     */
    private function renderReport(array $totals, array $sourceBirthDateTotals): void
    {
        $rows = array_map(static fn(string $field): array => [
            $field,
            $totals[$field]['before'],
            $totals[$field]['changed'],
            $totals[$field]['after'],
        ], self::FIELDS);
        $rows[] = [
            'performer_sources.birth_date',
            $sourceBirthDateTotals['before'],
            $sourceBirthDateTotals['changed'],
            $sourceBirthDateTotals['after'],
        ];
        $this->table(['Field', 'Before non-empty', 'Will change', 'After non-empty'], $rows);
    }

    private function isNonEmpty(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function sameValue(mixed $original, mixed $normalized): bool
    {
        if ($original instanceof \DateTimeInterface) {
            $original = $original->format('Y-m-d');
        } elseif (is_string($original) && preg_match('/^(\d{4}-\d{2}-\d{2})(?: 00:00:00)?$/', $original, $match) === 1) {
            $original = $match[1];
        }

        return $original === $normalized || ($original === null && $normalized === null);
    }
}
