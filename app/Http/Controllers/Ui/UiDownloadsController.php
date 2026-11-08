<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Observability\ObservabilityEmitter;
use App\Services\Downloads\DownloadService;
use App\Services\Downloads\FileLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** The downloads page: start, list, pause, resume, remove, and links to finished files. Owner session only. */
final class UiDownloadsController
{
    public function status(DownloadService $downloads, FileLinkService $files): JsonResponse
    {
        return response()->json(['data' => ['enabled' => $downloads->enabled(), 'files_enabled' => $files->enabled()]]);
    }

    public function index(DownloadService $downloads): JsonResponse
    {
        return $this->guard(fn() => ['items' => $downloads->list()]);
    }

    public function store(Request $request, DownloadService $downloads, ObservabilityEmitter $observability): JsonResponse
    {
        $code = trim((string) $request->input('code'));

        return $this->guard(function () use ($downloads, $observability, $code): array {
            $added = $downloads->add($code);
            $observability->emitOps('download_started', ['movie_code' => $added['movie_code']]);

            return $added;
        });
    }

    public function pause(string $hash, DownloadService $downloads): JsonResponse
    {
        return $this->guard(function () use ($downloads, $hash): array {
            $downloads->pause($hash);

            return ['hash' => $hash];
        });
    }

    public function resume(string $hash, DownloadService $downloads): JsonResponse
    {
        return $this->guard(function () use ($downloads, $hash): array {
            $downloads->resume($hash);

            return ['hash' => $hash];
        });
    }

    public function destroy(Request $request, string $hash, DownloadService $downloads, ObservabilityEmitter $observability): JsonResponse
    {
        return $this->guard(function () use ($downloads, $observability, $hash, $request): array {
            $downloads->remove($hash, $request->boolean('files'));
            $observability->emitOps('download_removed', ['with_files' => $request->boolean('files')]);

            return ['hash' => $hash];
        });
    }

    public function links(string $hash, DownloadService $downloads, FileLinkService $files, ObservabilityEmitter $observability): JsonResponse
    {
        return $this->guard(function () use ($downloads, $files, $observability, $hash): array {
            $links = [];
            foreach ($downloads->filePaths($hash) as $path) {
                if ($files->resolve($path) !== null) {
                    $links[] = ['name' => basename($path), 'url' => $files->url($path)];
                }
            }
            $observability->emitOps('file_links_made', ['count' => count($links)]);

            return ['links' => $links];
        });
    }

    /** @param callable(): array<string, mixed> $do */
    private function guard(callable $do): JsonResponse
    {
        try {
            return response()->json(['data' => $do()]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
