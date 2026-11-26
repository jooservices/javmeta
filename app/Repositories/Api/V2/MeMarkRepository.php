<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Illuminate\Support\Facades\DB;

/** The marks, follows, saved searches and feed visit of a user: rows of user_marks (shared with the v1 UI). */
final class MeMarkRepository
{
    /**
     * @param list<string> $kinds
     * @return array{rows: list<object>, total: int}
     */
    public function page(int $userId, array $kinds, ?string $subject, int $limit, int $offset): array
    {
        $query = DB::table('user_marks')->where('user_id', $userId)->whereIn('kind', $kinds);
        if ($subject !== null) {
            $query->where('subject', $subject);
        }

        return [
            'rows' => $query->clone()->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->offset($offset)->get(['id', 'kind', 'subject', 'key', 'created_at'])->all(),
            'total' => $query->count(),
        ];
    }

    /** @return list<object> */
    public function all(int $userId, string $kind): array
    {
        return DB::table('user_marks')->where(['user_id' => $userId, 'kind' => $kind])->orderByDesc('created_at')->orderByDesc('id')->get(['id', 'subject', 'key', 'created_at'])->all();
    }

    public function exists(int $userId, string $kind, string $subject, string $key): bool
    {
        return DB::table('user_marks')->where(['user_id' => $userId, 'kind' => $kind, 'subject' => $subject, 'key' => $key])->exists();
    }

    public function put(int $userId, string $kind, string $subject, string $key): void
    {
        DB::table('user_marks')->insertOrIgnore(['user_id' => $userId, 'kind' => $kind, 'subject' => $subject, 'key' => $key, 'created_at' => now()]);
    }

    public function remove(int $userId, string $kind, string $subject, string $key): void
    {
        DB::table('user_marks')->where(['user_id' => $userId, 'kind' => $kind, 'subject' => $subject, 'key' => $key])->delete();
    }

    /** @param list<string> $kinds */
    public function count(int $userId, array $kinds): int
    {
        return DB::table('user_marks')->where('user_id', $userId)->whereIn('kind', $kinds)->count();
    }

    public function find(int $userId, int $id, string $kind): ?object
    {
        return DB::table('user_marks')->where(['id' => $id, 'user_id' => $userId, 'kind' => $kind])->first(['id', 'subject', 'key', 'created_at']);
    }

    public function removeById(int $userId, int $id, string $kind): void
    {
        DB::table('user_marks')->where(['id' => $id, 'user_id' => $userId, 'kind' => $kind])->delete();
    }

    public function updateKey(int $id, string $key): void
    {
        DB::table('user_marks')->where('id', $id)->update(['key' => $key]);
    }

    /** Saves a search; one with the same name for the same target is replaced (the v1 rule). Returns the new id. */
    public function replaceSavedSearch(int $userId, string $subject, string $name, string $key): int
    {
        return DB::transaction(function () use ($userId, $subject, $name, $key): int {
            // Serialize saves of this owner so simultaneous requests cannot duplicate a name.
            DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id']);
            foreach ($this->all($userId, 'saved_search') as $row) {
                $stored = json_decode((string) $row->key, true);
                if ($row->subject === $subject && is_array($stored) && ($stored['name'] ?? null) === $name) {
                    $this->removeById($userId, (int) $row->id, 'saved_search');
                }
            }

            return (int) DB::table('user_marks')->insertGetId(['user_id' => $userId, 'kind' => 'saved_search', 'subject' => $subject, 'key' => $key, 'created_at' => now()]);
        });
    }

    public function feedSeenUntil(int $userId): ?string
    {
        $value = DB::table('user_marks')->where(['user_id' => $userId, 'kind' => 'feed_seen'])->value('created_at');

        return $value === null ? null : (string) $value;
    }

    public function setFeedSeenUntil(int $userId, string $at): void
    {
        DB::table('user_marks')->updateOrInsert(['user_id' => $userId, 'kind' => 'feed_seen', 'subject' => 'feed', 'key' => 'feed'], ['created_at' => $at]);
    }
}
