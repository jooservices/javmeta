<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Observability\ObservabilityEmitter;
use App\Services\Downloads\FileLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/** Serves a finished file through a signed, expiring link, to the home network only. Supports Range requests for playback. */
final class FileController
{
    public function download(Request $request, FileLinkService $links, ObservabilityEmitter $observability): BinaryFileResponse
    {
        abort_unless($links->enabled(), 404);
        abort_unless(IpUtils::checkIp((string) $request->ip(), $links->allowedNetworks()), 403, 'Files are served to the home network only.');

        try {
            $path = $links->resolve(Crypt::decryptString((string) $request->query('p')));
        } catch (Throwable) {
            $path = null;
        }
        abort_if($path === null, 404);

        $observability->emitOps('file_served', ['file' => basename($path), 'ip_hash' => hash('sha256', (string) $request->ip())]);
        Log::info('javmeta.file_served', ['file' => basename($path)]);

        return response()->file($path, str_ends_with(strtolower($path), '.vtt') ? ['Content-Type' => 'text/vtt; charset=UTF-8'] : []);
    }
}
