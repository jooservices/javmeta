<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Auth\ApiKeyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class MediaWorkerKeyCommand extends Command
{
    protected $signature = 'media:worker-key';
    protected $description = 'Create the private, media-only worker credentials once';

    public function handle(ApiKeyService $keys): int
    {
        $path = storage_path('app/private/media/worker.key');
        if (is_file($path)) {
            $existing = json_decode((string) file_get_contents($path), true);
            if (! is_array($existing) || ! isset($existing['url'], $existing['key'])) {
                $this->error('Existing worker credential file is invalid; repair it before starting the worker.');
                return self::FAILURE;
            }
            $this->info('Worker key already exists; left unchanged.');
            return self::SUCCESS;
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $handle = fopen($path, 'x');
        if ($handle === false) {
            $this->error('Cannot create private worker key.');
            return self::FAILURE;
        }
        chmod($path, 0640);
        try {
            DB::transaction(static function () use ($keys, $handle): void {
                $key = $keys->create('Local media worker', 120, ['media']);
                $json = json_encode(['url' => config('javmeta_media.api_url'), 'key' => $key->plaintext], JSON_THROW_ON_ERROR);
                if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                    throw new RuntimeException('Cannot write credentials.');
                }
            });
        } catch (Throwable) {
            unlink($path);
            $this->error('Worker credentials could not be saved. No key was retained.');
            return self::FAILURE;
        } finally {
            fclose($handle);
        }
        $this->info('Private worker credentials created.');
        return self::SUCCESS;
    }
}
