<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Reads and writes the administration tables of the v2 API: API keys and their usage, setting revisions, report history. */
final class AdministrationRepository
{
    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function apiKeys(?string $status, int $page, int $perPage): array
    {
        $query = DB::table('api_keys');
        if ($status !== null) {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $perPage)
            ->get(['id', 'prefix', 'label', 'status', 'abuse_rpm', 'created_at', 'revoked_at', 'scopes'])
            ->map(static fn(object $row): array => (array) $row)->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function apiKeyExists(int $id): bool
    {
        return DB::table('api_keys')->where('id', $id)->exists();
    }

    /** @return array{calls_24h: int, errors_24h: int, rpm: int, last_used: string|null, top_endpoints: list<array{endpoint: string, n: int}>} */
    public function apiKeyUsage(int $id): array
    {
        $now = CarbonImmutable::now();
        $day = DB::table('api_usage_log')->where('api_key_id', $id)->where('created_at', '>=', $now->subDay());
        $top = (clone $day)->selectRaw('endpoint, COUNT(*) AS n')->groupBy('endpoint')->orderByDesc('n')->limit(10)->get();

        return [
            'calls_24h' => (clone $day)->count(),
            'errors_24h' => (clone $day)->where('status_code', '>=', 400)->count(),
            'rpm' => DB::table('api_usage_log')->where('api_key_id', $id)->where('created_at', '>=', $now->subMinute())->count(),
            'last_used' => ($last = DB::table('api_usage_log')->where('api_key_id', $id)->max('created_at')) === null ? null : (string) $last,
            'top_endpoints' => $top->map(static fn(object $row): array => ['endpoint' => (string) $row->endpoint, 'n' => (int) $row->n])->all(),
        ];
    }

    /**
     * @param list<string> $keys
     * @return array<string, array{revision: int, updated_at: string|null}>
     */
    public function settingRevisions(array $keys): array
    {
        $out = [];
        foreach (DB::table('setting_revisions')->whereIn('key', $keys)->get() as $row) {
            $out[(string) $row->key] = ['revision' => (int) $row->revision, 'updated_at' => $row->updated_at === null ? null : (string) $row->updated_at];
        }

        return $out;
    }

    public function bumpSettingRevision(string $key): void
    {
        $updated = DB::table('setting_revisions')->where('key', $key)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);

        if ($updated === 0) {
            DB::table('setting_revisions')->insert(['key' => $key, 'revision' => 2, 'updated_at' => now()]);
        }
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function reportDeliveries(int $page, int $perPage): array
    {
        $query = DB::table('report_snapshots');
        $total = $query->count();
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)
            ->get(['id', 'scheduled_for', 'result', 'channel', 'rendered_text', 'created_at'])
            ->map(static fn(object $row): array => (array) $row)->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function latestReportDelivery(): ?array
    {
        $row = DB::table('report_snapshots')->orderByDesc('id')->first(['id', 'scheduled_for', 'result', 'channel', 'rendered_text', 'created_at']);

        return $row === null ? null : (array) $row;
    }
}
