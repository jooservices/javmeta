<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Database operations for followed-movie notification delivery. */
final class NotificationDeliveryServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function ownerId(): ?int
    {
        $id = DB::table('users')->where('role', 'super_administrator')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    public function sentToday(DateTimeInterface $start): int
    {
        return DB::table('follow_notifications')->where('channel', 'telegram')->where('sent_at', '>=', $start)->count();
    }

    /** @param array{code: string, reason: string} $match */
    public function recordSent(int $owner, array $match): void
    {
        DB::table('follow_notifications')->insertOrIgnore([
            'user_id' => $owner,
            'movie_code' => $match['code'],
            'reason' => $match['reason'],
            'channel' => 'telegram',
            'sent_at' => now(),
        ]);
    }

    /** @return list<array{subject: string, key: string}> */
    public function follows(int $userId): array
    {
        return DB::table('user_marks')->where('user_id', $userId)->where('kind', 'follow')->get(['subject', 'key'])
            ->map(static fn(object $row): array => ['subject' => (string) $row->subject, 'key' => (string) $row->key])
            ->all();
    }

    /** @param list<int> $performers
     *  @param list<string> $makers
     *  @param list<string> $series
     *  @return list<array{id: int, display_code: string, title_en: string|null, title_jp: string|null, cover_url: string|null, maker: string|null, series: string|null}>
     */
    public function candidateMovies(int $userId, CarbonImmutable $since, array $performers, array $makers, array $series): array
    {
        return DB::table('movies')->where('first_seen_at', '>=', $since->utc())->whereNull('delisted_at')
            ->whereNotIn('display_code', DB::table('follow_notifications')->where('user_id', $userId)->where('channel', 'telegram')->select('movie_code'))
            ->where(static function ($query) use ($performers, $makers, $series): void {
                if ($performers !== []) {
                    $query->orWhereIn('id', DB::table('movie_performers')->whereIn('performer_id', $performers)->select('movie_id'));
                }
                if ($makers !== []) {
                    $query->orWhereIn('maker', $makers);
                }
                if ($series !== []) {
                    $query->orWhereIn('series', $series);
                }
            })
            ->orderByDesc('first_seen_at')->limit(200)
            ->get(['id', 'display_code', 'title_en', 'title_jp', 'cover_url', 'maker', 'series'])
            ->map(static fn(object $row): array => [
                'id' => (int) $row->id,
                'display_code' => (string) $row->display_code,
                'title_en' => $row->title_en === null ? null : (string) $row->title_en,
                'title_jp' => $row->title_jp === null ? null : (string) $row->title_jp,
                'cover_url' => $row->cover_url === null ? null : (string) $row->cover_url,
                'maker' => $row->maker === null ? null : (string) $row->maker,
                'series' => $row->series === null ? null : (string) $row->series,
            ])->all();
    }

    /** @param list<int> $performers
     *  @return array<int, string>
     */
    public function performerNames(array $performers): array
    {
        if ($performers === []) {
            return [];
        }

        return DB::table('performers')->whereIn('id', $performers)
            ->pluck(DB::raw('COALESCE(name_romaji, name_kanji)'), 'id')
            ->mapWithKeys(static fn(mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }

    /** @param list<int> $performers
     *  @return list<int>
     */
    public function matchingPerformers(int $movieId, array $performers): array
    {
        if ($performers === []) {
            return [];
        }

        return DB::table('movie_performers')->where('movie_id', $movieId)->whereIn('performer_id', $performers)
            ->pluck('performer_id')->map(static fn(mixed $id): int => (int) $id)->all();
    }
}
