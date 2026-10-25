<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Services\Downloads\FileLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UiPlayerController
{
    public function show(string $code, Request $request, FileLinkService $links): JsonResponse
    {
        $v = $request->validate(['file' => 'sometimes|integer|min:1']);
        $query = DB::table('library_files')->whereNull('missing_at');
        if (preg_match('/^file-(\d+)$/D', $code, $match) === 1) {
            $query->where('id', (int) $match[1]);
        } else {
            $query->where('code', strtoupper($code));
        }
        $files = $query->orderBy('id')->get(['id', 'code', 'path']);
        abort_if($files->isEmpty(), 404, 'Không có tệp trên đĩa.');
        $file = isset($v['file']) ? $files->firstWhere('id', (int) $v['file']) : $files->first();
        abort_if($file === null, 404, 'Tệp không thuộc phim này.');
        $enabled = $links->enabled();
        $url = null;
        $error = null;
        if ($enabled) {
            try {
                $url = $links->url($file->path);
            } catch (Throwable) {
                $error = 'Không thể đọc tệp trong thư mục được phép phục vụ.';
            }
        }
        $subtitles = [];
        if ($url !== null) {
            foreach (DB::table('media_subtitles')->where('library_file_id', $file->id)->where('format', 'vtt')->orderBy('id')->get() as $subtitle) {
                try {
                    $subtitles[] = ['id' => (int) $subtitle->id, 'language' => $subtitle->language, 'source' => $subtitle->source, 'url' => $links->url($subtitle->path)];
                } catch (Throwable) {
                    $error = 'Một số phụ đề không còn đọc được. Hãy tạo lại phụ đề.';
                }
            }
        }
        return response()->json(['data' => [
            'enabled' => $enabled, 'url' => $url, 'error' => $error, 'library_file_id' => (int) $file->id,
            'files' => $files->map(static fn($f): array => ['id' => (int) $f->id, 'name' => basename($f->path)])->all(),
            'subtitles' => $subtitles,
            'scenes' => DB::table('media_scenes')->where('library_file_id', $file->id)->orderBy('start_sec')->get(['id', 'start_sec', 'end_sec', 'description'])->all(),
        ]])->header('Cache-Control', 'no-store');
    }
}
