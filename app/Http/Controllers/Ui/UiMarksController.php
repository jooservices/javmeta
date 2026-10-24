<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Http\Resources\MovieSummaryResource;
use App\Models\Movie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The owner's personal marks (favourites, watch later, seen), follows (performers, makers, series) and the feed of new
 * movies from the follows. They live in the database so every device of the owner sees the same lists.
 */
final class UiMarksController
{
    private const KINDS = [
        'favourite' => ['movie'],
        'watch_later' => ['movie'],
        'seen' => ['movie'],
        'follow' => ['performer', 'maker', 'series'],
    ];

    /** Which marks the signed-in owner has set (kind, subject, key), for the buttons on a page. */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('user_marks')->where('user_id', $request->user()->id)->where('kind', '!=', 'feed_seen');
        if (is_string($request->query('kind'))) {
            $query->where('kind', $request->query('kind'));
        }
        if (is_string($request->query('subject'))) {
            $query->where('subject', $request->query('subject'));
        }

        return response()->json(['data' => $query->orderByDesc('created_at')->limit(2000)->get(['kind', 'subject', 'key', 'created_at'])->all()]);
    }

    /** Switch one mark on or off. */
    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'subject' => ['required', 'string'],
            'key' => ['required', 'string', 'max:300'],
            'on' => ['required', 'boolean'],
        ]);
        if (! in_array($data['subject'], self::KINDS[$data['kind']], true)) {
            return response()->json(['message' => 'This mark does not apply to that subject.'], 422);
        }

        $row = ['user_id' => $request->user()->id, 'kind' => $data['kind'], 'subject' => $data['subject'], 'key' => $data['key']];
        if ($data['on']) {
            DB::table('user_marks')->insertOrIgnore([...$row, 'created_at' => now()]);
        } else {
            DB::table('user_marks')->where($row)->delete();
        }

        return response()->json(['data' => [...$row, 'on' => $data['on']]]);
    }

    /** The movies marked favourite, watch_later or seen, newest mark first. */
    public function movies(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['favourite', 'watch_later', 'seen'], true), 404);

        $codes = DB::table('user_marks')->where('user_id', $request->user()->id)->where('kind', $kind)->where('subject', 'movie')
            ->orderByDesc('created_at')->limit(500)->pluck('key')->all();

        return response()->json(['data' => $this->summaries($codes), 'meta' => ['total' => count($codes)]]);
    }

    /** New movies (first seen in the last 30 days) of the followed performers, makers and series. */
    public function feed(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $follows = DB::table('user_marks')->where('user_id', $userId)->where('kind', 'follow')->get(['subject', 'key']);
        $lastVisit = DB::table('user_marks')->where(['user_id' => $userId, 'kind' => 'feed_seen'])->value('created_at');

        $ids = [];
        if ($follows->isNotEmpty()) {
            $since = now()->subDays(30);
            $performers = $follows->where('subject', 'performer')->pluck('key')->map(static fn($k): int => (int) $k)->all();
            $makers = $follows->where('subject', 'maker')->pluck('key')->all();
            $series = $follows->where('subject', 'series')->pluck('key')->all();
            $ids = DB::table('movies')->where('first_seen_at', '>=', $since)->whereNull('delisted_at')
                ->where(function ($w) use ($performers, $makers, $series): void {
                    if ($performers !== []) {
                        $w->orWhereIn('id', DB::table('movie_performers')->whereIn('performer_id', $performers)->select('movie_id'));
                    }
                    if ($makers !== []) {
                        $w->orWhereIn('maker', $makers);
                    }
                    if ($series !== []) {
                        $w->orWhereIn('series', $series);
                    }
                })
                ->orderByDesc('first_seen_at')->limit(60)->pluck('display_code')->all();
        }

        $items = $this->summaries($ids);
        $new = 0;
        if ($lastVisit !== null) {
            $new = DB::table('movies')->whereIn('display_code', $ids)->where('first_seen_at', '>', $lastVisit)->count();
        } else {
            $new = count($items);
        }

        return response()->json(['data' => $items, 'meta' => ['follows' => $follows->count(), 'new_since_last_visit' => $new, 'last_visit' => $lastVisit]]);
    }

    /** Remember that the owner has looked at the feed now. */
    public function feedSeen(Request $request): JsonResponse
    {
        $key = ['user_id' => $request->user()->id, 'kind' => 'feed_seen', 'subject' => 'feed', 'key' => 'feed'];
        DB::table('user_marks')->updateOrInsert($key, ['created_at' => now()]);

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * @param list<string> $codes
     * @return list<array<string, mixed>>
     */
    private function summaries(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $movies = Movie::query()->with(['codes', 'performers.aliases', 'performers.sources', 'genres', 'media', 'credits'])
            ->whereIn('display_code', $codes)->get()->keyBy('display_code');
        $ordered = [];
        foreach ($codes as $code) {
            if ($movies->has($code)) {
                $ordered[] = $movies->get($code);
            }
        }

        return MovieSummaryResource::collection(collect($ordered))->resolve(Request::create('/'));
    }
}
