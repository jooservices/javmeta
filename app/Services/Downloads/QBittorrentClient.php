<?php

declare(strict_types=1);

namespace App\Services\Downloads;

use App\Services\Settings\SettingsService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin wrapper over the qBittorrent Web API (v2). The address, login, category and save folder are settings in the
 * database. It only ever touches torrents in the JavMeta category, so a torrent the owner added by hand is never
 * paused, listed or deleted from here.
 */
class QBittorrentClient
{
    private ?string $sid = null;

    public function __construct(private readonly SettingsService $settings) {}

    /** @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function list(array $options = []): array
    {
        $response = $this->request()->get($this->url('torrents/info'), ['category' => $this->category(), ...$options]);
        $this->guard($response->successful(), 'list');

        return (array) $response->json();
    }

    /** Add a magnet with the movie code as a tag; returns the info hash taken from the magnet. */
    public function addMagnet(string $magnet, string $tag): string
    {
        $hash = $this->hashOf($magnet);
        $form = ['urls' => $magnet, 'category' => $this->category(), 'tags' => $tag];
        $savePath = (string) $this->settings->get('downloads.save_path');
        if ($savePath !== '') {
            $form['savepath'] = $savePath;
        }
        $response = $this->request()->asForm()->post($this->url('torrents/add'), $form);
        $this->guard($response->successful() && stripos((string) $response->body(), 'Fails') === false, 'add');

        return $hash;
    }

    public function pause(string $hash): void
    {
        $this->action('torrents/pause', $hash);
    }

    public function resume(string $hash): void
    {
        $this->action('torrents/resume', $hash);
    }

    public function delete(string $hash, bool $withFiles): void
    {
        $response = $this->request()->asForm()->post($this->url('torrents/delete'), ['hashes' => $hash, 'deleteFiles' => $withFiles ? 'true' : 'false']);
        $this->guard($response->successful(), 'delete');
    }

    /** @return list<array<string, mixed>> */
    public function files(string $hash): array
    {
        $response = $this->request()->get($this->url('torrents/files'), ['hash' => $hash]);
        $this->guard($response->successful(), 'files');

        return (array) $response->json();
    }

    public function category(): string
    {
        return (string) $this->settings->get('downloads.category');
    }

    /** The 40 hex or 32 base32 characters of a magnet's btih, lower-cased. */
    public function hashOf(string $magnet): string
    {
        if (preg_match('/xt=urn:btih:([A-Za-z0-9]{32,40})/i', $magnet, $m) !== 1) {
            throw new RuntimeException('Not a magnet link.');
        }

        return strtolower($m[1]);
    }

    private function action(string $path, string $hash): void
    {
        $response = $this->request()->asForm()->post($this->url($path), ['hashes' => $hash]);
        $this->guard($response->successful(), $path);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->settings->get('downloads.qbittorrent_url'), '/') . '/api/v2/' . $path;
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout(max(1, (int) $this->settings->get('downloads.timeout')))->withHeaders(['Referer' => (string) $this->settings->get('downloads.qbittorrent_url')]);
        if ($this->sid === null) {
            $login = Http::timeout(max(1, (int) $this->settings->get('downloads.timeout')))->asForm()->withHeaders(['Referer' => (string) $this->settings->get('downloads.qbittorrent_url')])
                ->post($this->url('auth/login'), ['username' => (string) $this->settings->get('downloads.qbittorrent_username'), 'password' => (string) $this->settings->get('downloads.qbittorrent_password')]);
            $this->guard($login->successful() && trim($login->body()) !== 'Fails.', 'login');
            $this->sid = (string) ($login->cookies()->getCookieByName('SID')?->getValue() ?? '');
        }

        return $this->sid !== '' ? $request->withHeaders(['Cookie' => 'SID=' . $this->sid]) : $request;
    }

    private function guard(bool $ok, string $what): void
    {
        if (! $ok) {
            throw new RuntimeException("qBittorrent {$what} failed.");
        }
    }
}
