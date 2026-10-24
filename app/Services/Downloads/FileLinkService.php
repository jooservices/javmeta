<?php

declare(strict_types=1);

namespace App\Services\Downloads;

use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Expiring, signed links to finished files. A link carries the encrypted path and a signature, so it cannot be changed;
 * the file server still re-checks that the path is inside a configured folder and that the caller is on the home network.
 */
final class FileLinkService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('files.enabled');
    }

    /** @return list<string> real paths of the folders files may be served from */
    public function roots(): array
    {
        $configured = array_merge((array) $this->settings->get('files.root_folders'), [(string) $this->settings->get('downloads.save_path'), (string) $this->settings->get('media.output_folder')]);
        $roots = [];
        foreach ($configured as $folder) {
            $real = is_string($folder) && $folder !== '' ? realpath($folder) : false;
            if ($real !== false) {
                $roots[] = rtrim($real, '/');
            }
        }

        return array_values(array_unique($roots));
    }

    public function url(string $path): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('The file server is switched off in Settings.');
        }
        $real = $this->resolve($path) ?? throw new RuntimeException('The file is not in a served folder.');

        return URL::temporarySignedRoute('files.download', now()->addMinutes(max(1, (int) $this->settings->get('files.link_ttl_minutes'))), ['p' => Crypt::encryptString($real)]);
    }

    /** The real path when it is a file inside a served folder, otherwise null (also stops ../ and symlink escapes). */
    public function resolve(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false || ! is_file($real)) {
            return null;
        }
        foreach ($this->roots() as $root) {
            if (str_starts_with($real, $root . '/')) {
                return $real;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function allowedNetworks(): array
    {
        return array_values(array_filter((array) $this->settings->get('files.allowed_networks')));
    }
}
