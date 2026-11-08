<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Performer;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Catalog lookups used to validate natural-language search filters. */
final class QueryInterpreterServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(Performer $model)
    {
        parent::__construct($model);
    }

    public function knownMovieValue(string $field, string $value): ?string
    {
        $column = match ($field) {
            'maker' => 'maker',
            'series' => 'series',
            default => null,
        };
        $needle = mb_strtolower($value);
        if ($column !== null) {
            $known = DB::table('movies')->whereNotNull($column)->whereRaw('LOWER(' . $column . ') = ?', [$needle])->value($column);

            return is_string($known) ? $known : null;
        }
        if ($field !== 'genre') {
            return null;
        }

        $known = DB::table('genres')->selectRaw('COALESCE(label_raw, label_normalized) AS value')
            ->whereRaw('LOWER(COALESCE(label_raw, label_normalized)) = ?', [$needle])->value('value');

        return is_string($known) ? $known : null;
    }

    public function knownPerformer(string $value): ?string
    {
        $needle = mb_strtolower($value);
        $row = DB::table('performers')->whereNull('canonical_performer_id')
            ->where(static function ($query) use ($needle): void {
                foreach (['name_romaji', 'name_kanji', 'name_kana'] as $column) {
                    $query->orWhereRaw('LOWER(' . $column . ') = ?', [$needle]);
                }
            })->first(['name_romaji', 'name_kanji', 'name_kana']);
        if ($row === null) {
            return null;
        }

        foreach (['name_romaji', 'name_kanji', 'name_kana'] as $column) {
            if (is_string($row->{$column}) && $row->{$column} !== '') {
                return $row->{$column};
            }
        }

        return null;
    }

    public function knownFacet(string $field, string $value): ?string
    {
        if (! in_array($field, ['blood_type', 'location', 'cup'], true)) {
            return null;
        }
        $known = DB::table('performers')->whereNotNull($field)->where($field, '<>', '')
            ->whereRaw('LOWER(' . $field . ') = ?', [mb_strtolower(trim($value))])->value($field);

        return is_string($known) ? $known : null;
    }

    /** @return list<string> */
    public function facetNames(string $field): array
    {
        if (! in_array($field, ['blood_type', 'location', 'cup'], true)) {
            return [];
        }

        return DB::table('performers')->whereNotNull($field)->where($field, '<>', '')
            ->distinct()->orderBy($field)->pluck($field)->map(static fn(mixed $value): string => (string) $value)->all();
    }

    /** @return list<string> */
    public function catalogNames(string $list): array
    {
        $names = match ($list) {
            'makers' => DB::table('movies')->whereNotNull('maker')->where('maker', '<>', '')->distinct()->pluck('maker')->all(),
            'series' => DB::table('movies')->whereNotNull('series')->where('series', '<>', '')->distinct()->pluck('series')->all(),
            'genres' => DB::table('genres')->selectRaw('COALESCE(label_raw, label_normalized) AS n')->pluck('n')->all(),
            default => [],
        };

        return array_values(array_filter(array_map('strval', $names)));
    }

    /** @param list<string> $candidates */
    public function performerNameIn(array $candidates): ?string
    {
        $name = DB::table('performers')->whereNull('canonical_performer_id')
            ->whereIn(DB::raw('LOWER(name_romaji)'), $candidates)
            ->orderByRaw('LENGTH(name_romaji) DESC')->value('name_romaji');

        return is_string($name) ? $name : null;
    }
}
