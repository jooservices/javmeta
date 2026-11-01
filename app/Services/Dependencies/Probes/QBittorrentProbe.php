<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DetailedDependencyProbe;
use App\Services\Dependencies\ProbeResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class QBittorrentProbe implements DetailedDependencyProbe
{
    public function probe(): bool
    {
        return $this->check()->available !== false;
    }

    public function check(): ProbeResult
    {
        if (! (bool) config('javmeta_downloads.enabled', false)) {
            return new ProbeResult(null, 'qBittorrent downloads are disabled.');
        }

        $baseUrl = rtrim((string) config('javmeta_downloads.qbittorrent_url', ''), '/');
        if ($baseUrl === '') {
            return new ProbeResult(null, 'qBittorrent is not configured.');
        }

        $timeout = (int) config('javmeta_health.startup.timeout_seconds', 2);
        $headers = ['Referer' => $baseUrl];

        try {
            $login = Http::withoutRedirecting()->connectTimeout($timeout)->timeout($timeout)->asForm()->withHeaders($headers)
                ->post($baseUrl . '/api/v2/auth/login', [
                    'username' => (string) config('javmeta_downloads.qbittorrent_username', ''),
                    'password' => (string) config('javmeta_downloads.qbittorrent_password', ''),
                ]);
            $available = $login->successful() && trim($login->body()) === 'Ok.';

            return new ProbeResult($available, $available ? 'Authenticated qBittorrent API responded.' : 'qBittorrent authentication failed.');
        } catch (Throwable) {
            return new ProbeResult(false, 'qBittorrent API check failed.');
        }
    }
}
