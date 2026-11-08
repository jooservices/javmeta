<?php

declare(strict_types=1);

namespace App\Data\Search;

/** The understood form of a natural-language question: keywords, filters, a label for each filter and who understood it. */
final readonly class SearchParseResult
{
    /**
     * @param array<string, mixed> $filters
     * @param array<string, string> $explanations
     */
    public function __construct(public string $keywords, public array $filters, public array $explanations, public string $by) {}

    /** @return array{keywords: string, filters: array<string, mixed>, explanations: array<string, string>, by: string} */
    public function toArray(): array
    {
        return ['keywords' => $this->keywords, 'filters' => $this->filters, 'explanations' => $this->explanations, 'by' => $this->by];
    }
}
