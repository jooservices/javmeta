<?php

declare(strict_types=1);

namespace App\Services\Ui;

use App\Data\Ui\UiMarksResult;
use App\Repositories\UiMarksMovieRepository;
use App\Repositories\UserMarkRepository;

/** Owns the owner's marks, follows, saved searches and followed-movie feed. */
final class UiMarksService
{
    public function __construct(
        private readonly UserMarkRepository $marks,
        private readonly UiMarksMovieRepository $movies,
    ) {}

    public function index(int $userId, ?string $kind, ?string $subject): UiMarksResult
    {
        $data = $this->marks->forOwner($userId, $kind, $subject)
            ->map(static fn(\stdClass $mark): array => (array) $mark)
            ->values()
            ->all();

        return new UiMarksResult(['data' => $data]);
    }

    public function toggle(int $userId, string $kind, string $subject, string $key, bool|int|string $on): UiMarksResult
    {
        $row = ['user_id' => $userId, 'kind' => $kind, 'subject' => $subject, 'key' => $key];
        if ($kind === 'saved_search') {
            /** @var array{name: string, query: string} $search */
            $search = json_decode($key, true, flags: JSON_THROW_ON_ERROR);
            $search = ['name' => trim($search['name']), 'query' => $search['query']];
            $row['key'] = json_encode($search, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->marks->saveNamedSearch($userId, $subject, $row['key'], $search['name'], $search['query'], $on);
        } else {
            $this->marks->toggle($row, $on);
        }

        return new UiMarksResult(['data' => [...$row, 'on' => $on]]);
    }

    public function movies(int $userId, string $kind, int $perPage = 50, int $page = 1): UiMarksResult
    {
        $result = $this->marks->movieCodesPage($userId, $kind, $perPage, $page);

        return new UiMarksResult([
            'data' => $this->movies->summaries($result['codes']),
            'meta' => [
                'total' => $result['total'],
                'pagination' => [
                    'current_page' => $result['page'],
                    'per_page' => $result['per_page'],
                    'last_page' => max(1, (int) ceil($result['total'] / $result['per_page'])),
                    'has_more' => $result['has_more'],
                ],
            ],
        ]);
    }

    public function feed(int $userId): UiMarksResult
    {
        $follows = $this->marks->follows($userId);
        $lastVisit = $this->marks->lastFeedVisit($userId);
        $codes = [];
        if ($follows->isNotEmpty()) {
            /** @var list<int> $performerIds */
            $performerIds = $follows->where('subject', 'performer')->pluck('key')->map(static fn(mixed $key): int => (int) $key)->all();
            /** @var list<string> $makers */
            $makers = $follows->where('subject', 'maker')->pluck('key')->map(static fn(mixed $key): string => (string) $key)->all();
            /** @var list<string> $series */
            $series = $follows->where('subject', 'series')->pluck('key')->map(static fn(mixed $key): string => (string) $key)->all();
            $codes = $this->movies->feedCodes(now()->subDays(30), $performerIds, $makers, $series);
        }

        $items = $this->movies->summaries($codes);
        $new = $lastVisit === null ? count($items) : $this->movies->countFirstSeenAfter($codes, $lastVisit);

        return new UiMarksResult([
            'data' => $items,
            'meta' => ['follows' => $follows->count(), 'new_since_last_visit' => $new, 'last_visit' => $lastVisit],
        ]);
    }

    public function feedSeen(int $userId): UiMarksResult
    {
        $this->marks->markFeedSeen($userId);

        return new UiMarksResult(['data' => ['ok' => true]]);
    }
}
