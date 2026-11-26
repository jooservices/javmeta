<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** UI preferences and view history of a user, and the download request counter of the profile. */
final class MeSettingsRepository
{
    /** @return array<string, mixed> */
    public function preferences(int $userId): array
    {
        $out = [];
        foreach (DB::table('ui_preferences')->where('user_id', $userId)->orderBy('key')->get(['key', 'value']) as $row) {
            $out[(string) $row->key] = json_decode((string) $row->value, true);
        }

        return $out;
    }

    /** @param array<string, mixed> $values */
    public function savePreferences(int $userId, array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('ui_preferences')->updateOrInsert(['user_id' => $userId, 'key' => $key], ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }
    }

    /** @return array{rows: list<object>, total: int} */
    public function history(int $userId, ?string $kind, int $limit, int $offset): array
    {
        $query = DB::table('view_history')->where('user_id', $userId);
        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        return [
            'rows' => $query->clone()->orderByDesc('viewed_at')->orderByDesc('id')->limit($limit)->offset($offset)->get(['id', 'kind', 'ref', 'label', 'viewed_at'])->all(),
            'total' => $query->count(),
        ];
    }

    public function recordView(int $userId, string $kind, string $ref, ?string $label): int
    {
        return (int) DB::table('view_history')->insertGetId(['user_id' => $userId, 'kind' => $kind, 'ref' => $ref, 'label' => $label, 'viewed_at' => now()]);
    }

    public function clearHistory(int $userId): void
    {
        DB::table('view_history')->where('user_id', $userId)->delete();
    }

    public function downloadRequests(int $userId): int
    {
        if (! Schema::hasTable('download_requests') || ! Schema::hasColumn('download_requests', 'requester')) {
            return 0;
        }

        return DB::table('download_requests')->where('requester', "user:{$userId}")->count();
    }
}
