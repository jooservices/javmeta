<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Auth\ApiKeyService;
use Illuminate\Console\Command;

final class MediaWorkerKeyCommand extends Command
{
    protected $signature = 'media:worker-key';
    protected $description = 'Create the private, media-only worker credentials once';

    public function handle(ApiKeyService $keys): int
    {
        $path = storage_path('app/private/media/worker.key');
        if (is_file($path)) {
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
            $key = $keys->create('Local media worker', 120, ['media']);
            fwrite($handle, json_encode(['url' => config('javmeta_media.api_url'), 'key' => $key->plaintext], JSON_THROW_ON_ERROR));
        } finally {
            fclose($handle);
        }
        $this->info('Private worker credentials created.');
        return self::SUCCESS;
    }
}
