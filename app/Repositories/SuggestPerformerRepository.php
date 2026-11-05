<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Performer;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

final class SuggestPerformerRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(Performer $model)
    {
        parent::__construct($model);
    }

    /** @return list<array{id: int, name: ?string}> */
    public function suggestions(string $pattern, string $operator, int $limit): array
    {
        return DB::table('performers')
            ->where(fn($query) => $query->where('name_romaji', $operator, $pattern)
                ->orWhere('name_kanji', $operator, $pattern)
                ->orWhere('name_kana', $operator, $pattern)
                ->orWhereIn('id', DB::table('performer_aliases')->where('alias', $operator, $pattern)->select('performer_id')))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name_romaji', 'name_kanji'])
            ->map(static fn(object $performer): array => [
                'id' => (int) $performer->id,
                'name' => $performer->name_romaji ?? $performer->name_kanji,
            ])
            ->all();
    }
}
