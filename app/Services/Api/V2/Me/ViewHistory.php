<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Me;

use App\Http\Api\V2\Envelope;
use App\Repositories\Api\V2\MeSettingsRepository;

/** What the user opened or clicked, newest first. */
final class ViewHistory
{
    public const KINDS = ['movie', 'performer', 'photo', 'download'];

    public function __construct(private readonly MeSettingsRepository $settings) {}

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function page(int $userId, array $query): array
    {
        $page = Page::from($query);
        $result = $this->settings->history($userId, isset($query['kind']) ? (string) $query['kind'] : null, $page->size, $page->offset());

        return [
            'data' => array_map(static fn(object $row): array => ['id' => (int) $row->id, 'kind' => $row->kind, 'ref' => (string) $row->ref, 'label' => $row->label, 'viewed_at' => Timestamp::iso((string) $row->viewed_at)], $result['rows']),
            'meta' => Envelope::pagination($page->number, $page->size, $result['total']),
        ];
    }

    /** @return array<string, mixed> */
    public function record(int $userId, string $kind, string $ref, ?string $label): array
    {
        $id = $this->settings->recordView($userId, $kind, $ref, $label);

        return ['id' => $id, 'kind' => $kind, 'ref' => $ref, 'label' => $label, 'viewed_at' => Timestamp::iso('now')];
    }

    public function clear(int $userId): void
    {
        $this->settings->clearHistory($userId);
    }
}
