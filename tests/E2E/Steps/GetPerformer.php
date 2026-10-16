<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

final class GetPerformer extends HttpStep
{
    public function __construct(
        private readonly int|string|null $performerId = null,
        private readonly ?string $expectedAlias = null,
        private readonly ?int $expectedLinkedTitles = null,
    ) {}

    public function run(Context $context): Context
    {
        $catalog = $context->catalog;
        $performerId = $this->performerId ?? $catalog?->performerUuid;
        $expectedAlias = $this->expectedAlias ?? $catalog?->performerAlias;
        $expectedLinkedTitles = $this->expectedLinkedTitles ?? ($catalog === null ? 0 : count($catalog->movieCodes));
        if ($performerId === null || $expectedAlias === null) {
            throw new StepFailure('The catalog performer was not seeded for this journey.', $context);
        }

        $credential = $this->credential($context);
        $result = $this->exchange($context, 'GET', '/api/v1/performers/' . rawurlencode((string) $performerId), ['X-Api-Key' => $credential->plaintext]);
        $context = $result->context;
        $this->expectStatus($context, $result->response, 200);
        $profile = $result->response->json('data');

        if (! is_array($profile)
            || ! is_string($profile['name_romaji'] ?? null)
            || ! is_string($profile['name_kanji'] ?? null)
            || ! in_array($expectedAlias, (array) ($profile['aliases'] ?? []), true)
            || (int) ($profile['linked_title_count'] ?? -1) !== $expectedLinkedTitles) {
            throw new StepFailure('The performer profile omitted names, aliases, or linked titles.', $context);
        }

        $photos = (array) ($profile['photos']['images'] ?? []);
        if ($photos === [] || ! is_string($photos[0]['url'] ?? null) || ! str_starts_with($photos[0]['url'], 'https://')) {
            throw new StepFailure('The performer profile did not return a photo URL reference.', $context);
        }
        if (array_key_exists('data', $photos[0]) || array_key_exists('content', $photos[0])) {
            throw new StepFailure('The performer profile returned photo content instead of a URL.', $context);
        }

        return $context;
    }
}
