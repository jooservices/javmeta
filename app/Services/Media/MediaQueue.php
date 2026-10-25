<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MediaQueue
{
    public const KINDS = ['thumbnails', 'scenes', 'transcribe', 'translate', 'describe'];

    public function enqueue(string $kind, int $file): int
    {
        abort_unless(in_array($kind, self::KINDS, true), 422, 'Loại xử lý không hợp lệ.');
        $row = DB::table('library_files')->where('id', $file)->whereNull('missing_at')->first();
        abort_if($row === null, 422, 'Tệp không còn trong thư viện.');

        app(MediaPolicy::class)->assertFile($row);

        return DB::transaction(function () use ($kind, $file): int {
            DB::table('media_worker_lock')->where('id', 1)->lockForUpdate()->first();
            $existing = DB::table('media_jobs')->where('library_file_id', $file)->where('kind', $kind)->whereIn('status', ['pending', 'running'])->value('id');
            return $existing !== null ? (int) $existing : (int) DB::table('media_jobs')->insertGetId([
                'kind' => $kind, 'library_file_id' => $file, 'params' => '{}', 'status' => 'pending', 'created_at' => now(),
            ]);
        });
    }

    public function capture(string $url): int
    {
        abort_unless((bool) config('javmeta_capture.enabled'), 422, 'Ghi luồng đang tắt trong Cài đặt.');
        $parts = parse_url($url);
        abort_unless(is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && ! isset($parts['user']) && ! isset($parts['pass']), 422, 'Chỉ nhận URL HTTP(S) công khai, không có tài khoản.');
        app(MediaPolicy::class)->assertText($url);
        $id = (int) DB::table('media_jobs')->insertGetId(['kind' => 'capture', 'source_url' => $url, 'params' => '{}', 'status' => 'pending', 'created_at' => now()]);
        \Illuminate\Support\Facades\Log::info('javmeta.capture_requested', ['job_id' => $id]);
        return $id;
    }

    public function claim(string $worker): ?array
    {
        return DB::transaction(function () use ($worker): ?array {
            DB::table('media_worker_lock')->where('id', 1)->lockForUpdate()->first();
            $stale = DB::table('media_jobs')->where('status', 'running')->where('started_at', '<', now()->subSeconds((int) config('javmeta_media.lease_seconds')));
            (clone $stale)->where('attempts', '>=', config('javmeta_media.max_attempts'))->update(['status' => 'failed', 'error' => 'Worker lease expired.', 'finished_at' => now(), 'claim_token' => null]);
            $stale->update(['status' => 'pending', 'error' => 'Worker lease expired.', 'claim_token' => null]);
            if (DB::table('media_jobs')->where('status', 'running')->count() >= (int) config('javmeta_media.max_parallel')) {
                return null;
            }
            $job = DB::table('media_jobs')->where('status', 'pending')->orderBy('id')->lockForUpdate()->first();
            if ($job === null) {
                return null;
            }
            if ($job->kind === 'capture' && ! (bool) config('javmeta_capture.enabled')) {
                DB::table('media_jobs')->where('id', $job->id)->update(['status' => 'failed', 'error' => 'Capture disabled in Settings.', 'finished_at' => now()]);
                return null;
            }
            if ($job->library_file_id !== null) {
                $file = DB::table('library_files')->where('id', $job->library_file_id)->first();
                try {
                    if ($file === null || $file->missing_at !== null) {
                        throw ValidationException::withMessages(['media' => 'Library file is missing.']);
                    }
                    app(MediaPolicy::class)->assertFile($file);
                } catch (ValidationException) {
                    DB::table('media_jobs')->where('id', $job->id)->update(['status' => 'failed', 'error' => 'File missing or refused by content policy.', 'finished_at' => now()]);
                    return null;
                }
            }
            $token = (string) Str::uuid();
            DB::table('media_jobs')->where('id', $job->id)->update(['status' => 'running', 'attempts' => $job->attempts + 1, 'locked_by' => $worker, 'claim_token' => $token, 'started_at' => now(), 'finished_at' => null]);
            $data = (array) DB::table('media_jobs')->where('id', $job->id)->first();
            $data['params'] = json_decode($data['params'], true);
            $data['scenes'] = DB::table('media_scenes')->where('library_file_id', $job->library_file_id)->orderBy('start_sec')->get()->map(static fn($row): array => (array) $row)->all();
            $data['subtitles'] = DB::table('media_subtitles')->where('library_file_id', $job->library_file_id)->get()->map(static fn($row): array => (array) $row)->all();
            $data['folder'] = DB::table('library_files')->where('id', $job->library_file_id)->value('folder');
            $data['path'] = DB::table('library_files')->where('id', $job->library_file_id)->value('path');
            return $data;
        });
    }

    public function finish(int $id, string $token, ?array $result, ?string $error): void
    {
        DB::transaction(function () use ($id, $token, $result, $error): void {
            $job = DB::table('media_jobs')->where('id', $id)->lockForUpdate()->first();
            abort_unless($job !== null && $job->status === 'running' && hash_equals((string) $job->claim_token, $token), 409, 'Lượt xử lý đã hết hạn.');
            if ($error === null) {
                if ($job->kind === 'capture') {
                    abort_unless(isset($result['capture_path']), 422, 'Capture result missing.');
                    $this->outputPath($result['capture_path']);
                }
                $this->storeScenes($job, $result['scenes'] ?? []);
                $this->storeSubtitles($job, $result['subtitles'] ?? []);
                if ($job->kind === 'describe') {
                    foreach ($result['descriptions'] ?? [] as $row) {
                        abort_unless(DB::table('media_scenes')->where('id', $row['id'])->where('library_file_id', $job->library_file_id)->exists(), 422, 'Cảnh không thuộc tệp của việc này.');
                        DB::table('media_scenes')->where('id', $row['id'])->update(['description' => $row['description'], 'tags' => json_encode($row['tags'], JSON_THROW_ON_ERROR), 'confidence' => $row['confidence'], 'embedding_indexed_at' => null]);
                    }
                }
            }
            $status = $error === null ? 'done' : ($job->attempts < (int) config('javmeta_media.max_attempts') ? 'pending' : 'failed');
            DB::table('media_jobs')->where('id', $id)->update(['status' => $status, 'result' => $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR), 'error' => $error, 'claim_token' => null, 'finished_at' => $status === 'pending' ? null : now()]);
        });
    }

    public function retry(int $id): void
    {
        abort_unless(DB::table('media_jobs')->where('id', $id)->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'error' => null, 'finished_at' => null]) === 1, 409, 'Chỉ thử lại việc đã thất bại.');
    }

    private function storeSubtitles(object $job, array $subtitles): void
    {
        if (! in_array($job->kind, ['transcribe', 'translate'], true)) {
            return;
        }
        foreach ($subtitles as $subtitle) {
            $path = $this->outputPath($subtitle['path']);
            DB::table('media_subtitles')->updateOrInsert([
                'library_file_id' => $job->library_file_id, 'language' => $subtitle['language'],
                'format' => $subtitle['format'], 'source' => $job->kind === 'transcribe' ? 'whisper' : 'translation',
            ], ['path' => $path, 'created_at' => now()]);
        }
    }

    private function outputPath(string $path): string
    {
        $root = realpath((string) config('javmeta_media.output_folder'));
        $real = realpath($path);
        if ($root === false || $real === false || ! is_file($real) || ! str_starts_with($real, $root . '/')) {
            throw ValidationException::withMessages(['result' => 'Tệp phải nằm trong thư mục output media.']);
        }
        return $real;
    }

    private function storeScenes(object $job, array $scenes): void
    {
        if ($job->kind !== 'scenes' || $scenes === []) {
            return;
        }
        $movie = DB::table('library_files')->where('id', $job->library_file_id)->value('movie_id');
        DB::table('media_scenes')->where('library_file_id', $job->library_file_id)->whereNotIn('start_sec', array_column($scenes, 'start_sec'))->delete();
        foreach ($scenes as $scene) {
            $root = realpath((string) config('javmeta_media.output_folder'));
            $path = realpath($scene['thumb_path']);
            if ($root === false || $path === false || ! is_file($path) || ! str_starts_with($path, $root . '/')) {
                throw ValidationException::withMessages(['result.scenes' => 'Ảnh phải nằm trong thư mục output media.']);
            }
            DB::table('media_scenes')->updateOrInsert(['library_file_id' => $job->library_file_id, 'start_sec' => $scene['start_sec']], [
                'movie_id' => $movie, 'end_sec' => $scene['end_sec'], 'thumb_path' => $path, 'description' => null, 'tags' => null, 'confidence' => null, 'embedding_indexed_at' => null,
            ]);
        }
    }
}
