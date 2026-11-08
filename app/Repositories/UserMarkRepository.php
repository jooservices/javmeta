<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\UserMark;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

final class UserMarkRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(UserMark $model)
    {
        parent::__construct($model);
    }

    /** @return Collection<int, \stdClass> */
    public function forOwner(int $userId, ?string $kind, ?string $subject): Collection
    {
        $query = $this->newQuery()->toBase()->where('user_id', $userId)->where('kind', '!=', 'feed_seen');
        if ($kind !== null) {
            $query->where('kind', $kind);
        }
        if ($subject !== null) {
            $query->where('subject', $subject);
        }

        return $query->orderByDesc('created_at')->limit(2000)->get(['kind', 'subject', 'key', 'created_at']);
    }

    /** @return list<string> */
    public function movieCodes(int $userId, string $kind): array
    {
        /** @var list<string> $codes */
        $codes = $this->newQuery()->toBase()
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->where('subject', 'movie')
            ->orderByDesc('created_at')
            ->limit(500)
            ->pluck('key')
            ->all();

        return $codes;
    }

    /** @return Collection<int, \stdClass> */
    public function follows(int $userId): Collection
    {
        return $this->newQuery()->toBase()
            ->where('user_id', $userId)
            ->where('kind', 'follow')
            ->get(['subject', 'key']);
    }

    public function lastFeedVisit(int $userId): ?string
    {
        $value = $this->newQuery()->toBase()
            ->where(['user_id' => $userId, 'kind' => 'feed_seen'])
            ->value('created_at');

        return $value === null ? null : (string) $value;
    }

    /** @param array{user_id: int, kind: string, subject: string, key: string} $row */
    public function toggle(array $row, bool|int|string $on): void
    {
        if ($on) {
            $this->newQuery()->toBase()->insertOrIgnore([...$row, 'created_at' => now()]);

            return;
        }

        $this->newQuery()->toBase()->where($row)->delete();
    }

    public function saveNamedSearch(int $userId, string $subject, string $key, string $name, string $query, bool|int|string $on): void
    {
        DB::transaction(function () use ($userId, $subject, $key, $name, $query, $on): void {
            // Serialize saves for this owner so simultaneous edits cannot duplicate a name.
            DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id']);
            $scope = ['user_id' => $userId, 'kind' => 'saved_search', 'subject' => $subject];
            $existing = $this->newQuery()->toBase()->where($scope)->get(['id', 'key']);
            foreach ($existing as $mark) {
                $stored = json_decode($mark->key, true);
                if (is_array($stored) && ($stored['name'] ?? null) === $name
                    && ($on || ($stored['query'] ?? null) === $query)) {
                    $this->newQuery()->toBase()->where('id', $mark->id)->delete();
                }
            }
            if ($on) {
                $this->newQuery()->toBase()->insertOrIgnore([
                    ...$scope,
                    'key' => $key,
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function markFeedSeen(int $userId): void
    {
        $key = ['user_id' => $userId, 'kind' => 'feed_seen', 'subject' => 'feed', 'key' => 'feed'];
        $this->newQuery()->toBase()->updateOrInsert($key, ['created_at' => now()]);
    }
}
