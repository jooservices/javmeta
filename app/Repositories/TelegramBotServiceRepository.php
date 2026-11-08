<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Persistence operations used by the Telegram bot. */
final class TelegramBotServiceRepository extends EloquentRepository implements RepositoryInterface
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

    public function offset(): int
    {
        return (int) DB::table('user_marks')->where(['user_id' => 0, 'kind' => 'tg_offset'])->value('key');
    }

    public function saveOffset(int $id): void
    {
        DB::table('user_marks')->updateOrInsert(
            ['user_id' => 0, 'kind' => 'tg_offset', 'subject' => 'telegram'],
            ['key' => (string) $id, 'created_at' => now()],
        );
    }

    /** @return array<string, mixed>|null */
    public function movie(string $code): ?array
    {
        $row = DB::table('movies')->whereRaw('UPPER(display_code) = ?', [strtoupper($code)])
            ->first(['display_code', 'title_en', 'title_jp', 'maker', 'series', 'release_date', 'runtime_minutes', 'community_score']);

        return $row === null ? null : (array) $row;
    }

    public function ownerId(): ?int
    {
        $id = DB::table('users')->where('role', 'super_administrator')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    public function performerId(string $name): ?int
    {
        $id = DB::table('performers')
            ->whereRaw('LOWER(name_romaji) = ?', [mb_strtolower($name)])
            ->orWhereRaw('LOWER(name_kanji) = ?', [mb_strtolower($name)])
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @param array{user_id: int, kind: string, subject: string, key: string} $follow */
    public function addFollow(array $follow): void
    {
        DB::table('user_marks')->insertOrIgnore([...$follow, 'created_at' => now()]);
    }

    /** @param array{user_id: int, kind: string, subject: string, key: string} $follow */
    public function removeFollow(array $follow): void
    {
        DB::table('user_marks')->where($follow)->delete();
    }

    /** @return list<array{subject: string, key: string}> */
    public function follows(?int $owner): array
    {
        return DB::table('user_marks')->where('user_id', $owner)->where('kind', 'follow')->orderBy('subject')->get(['subject', 'key'])
            ->map(static fn(object $row): array => ['subject' => (string) $row->subject, 'key' => (string) $row->key])
            ->all();
    }

    /** @param list<int> $performerIds
     *  @return array<int, string>
     */
    public function performerNames(array $performerIds): array
    {
        return DB::table('performers')->whereIn('id', $performerIds)
            ->pluck(DB::raw('COALESCE(name_romaji, name_kanji)'), 'id')
            ->mapWithKeys(static fn(mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }
}
