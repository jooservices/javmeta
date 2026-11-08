<?php

declare(strict_types=1);

namespace App\Services\Suggest;

use App\Data\Suggest\SuggestQuery;
use App\Data\Suggest\SuggestSnapshot;
use App\Repositories\SuggestMovieRepository;
use App\Repositories\SuggestPerformerRepository;

/** Quick search suggestions across movies, performers and catalog facets. */
final class SuggestService
{
    public function __construct(
        private readonly SuggestMovieRepository $movies,
        private readonly SuggestPerformerRepository $performers,
    ) {}

    public function suggest(SuggestQuery $query): SuggestSnapshot
    {
        $operator = $this->movies->likeOperator();
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query->query) . '%';
        $prefixPattern = mb_strtolower($query->query) . '%';

        return new SuggestSnapshot(
            $this->movies->suggestions($pattern, $prefixPattern, $operator, $query->limit),
            $this->performers->suggestions($pattern, $operator, $query->limit),
            $this->movies->genres($pattern, $operator, $query->limit),
            $this->movies->facet('maker', $pattern, $operator, $query->limit),
            $this->movies->facet('series', $pattern, $operator, $query->limit),
        );
    }
}
