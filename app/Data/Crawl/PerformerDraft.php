<?php

declare(strict_types=1);

namespace App\Data\Crawl;

use InvalidArgumentException;

final readonly class PerformerDraft
{
    /**
     * @param  list<string>  $aliases
     * @param  array<string, mixed>  $attrs
     */
    public function __construct(
        public string $sourceSlug,
        public string $externalId,
        public ?string $nameRomaji = null,
        public ?string $nameKanji = null,
        public ?string $nameKana = null,
        public array $aliases = [],
        public ?string $profileUrl = null,
        public ?string $imageUrl = null,
        public ?string $birthDate = null,
        public ?int $heightCm = null,
        public ?int $bust = null,
        public ?int $waist = null,
        public ?int $hip = null,
        public ?string $cup = null,
        public ?string $bloodType = null,
        public ?string $bioText = null,
        public ?string $debutDate = null,
        public ?string $location = null,
        public array $attrs = [],
        public ?string $crossLinkSource = null,
        public ?string $crossLinkId = null,
    ) {
        if (trim($this->sourceSlug) === '') {
            throw new InvalidArgumentException('Performer source slug cannot be empty.');
        }

        if (trim($this->externalId) === '') {
            throw new InvalidArgumentException('Performer external ID cannot be empty.');
        }

        foreach ($this->aliases as $alias) {
            if (trim($alias) === '') {
                throw new InvalidArgumentException('Performer aliases must be non-empty strings.');
            }
        }
    }

    /**
     * @param  list<string>  $aliases
     * @param  array<string, mixed>  $attrs
     */
    public static function from(
        string $sourceSlug,
        string $externalId,
        ?string $nameRomaji = null,
        ?string $nameKanji = null,
        ?string $nameKana = null,
        array $aliases = [],
        ?string $profileUrl = null,
        ?string $imageUrl = null,
        ?string $birthDate = null,
        ?int $heightCm = null,
        ?int $bust = null,
        ?int $waist = null,
        ?int $hip = null,
        ?string $cup = null,
        ?string $bloodType = null,
        ?string $bioText = null,
        ?string $debutDate = null,
        ?string $location = null,
        array $attrs = [],
        ?string $crossLinkSource = null,
        ?string $crossLinkId = null,
    ): self {
        return new self(
            $sourceSlug,
            $externalId,
            $nameRomaji,
            $nameKanji,
            $nameKana,
            $aliases,
            $profileUrl,
            $imageUrl,
            $birthDate,
            $heightCm,
            $bust,
            $waist,
            $hip,
            $cup,
            $bloodType,
            $bioText,
            $debutDate,
            $location,
            $attrs,
            $crossLinkSource,
            $crossLinkId,
        );
    }
}
