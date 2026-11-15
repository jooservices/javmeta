<?php

declare(strict_types=1);

namespace App\Console\Commands\Observability;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class ExpireLegacyEgressMetadataCommand extends Command
{
    private const MAX_BATCH_SIZE = 200;

    private const METADATA_TTL_DAYS = 30;

    protected $signature = 'observability:expire-legacy-egress-meta {identities* : Explicit legacy identities as node:<id> or browser:<host>}';

    protected $description = 'Apply the bounded egress metadata lifetime to explicit legacy cache identities.';

    public function handle(): int
    {
        $identities = array_values(array_unique(array_map('trim', $this->argument('identities'))));
        if ($identities === [] || count($identities) > self::MAX_BATCH_SIZE) {
            $this->error('Provide between 1 and ' . self::MAX_BATCH_SIZE . ' unique legacy identities per run.');

            return self::FAILURE;
        }

        $keys = [];
        foreach ($identities as $identity) {
            $parts = explode(':', $identity, 2);
            $kind = $parts[0];
            $value = $parts[1] ?? '';
            if (! $this->validIdentity($kind, $value)) {
                $this->error('Invalid legacy identity: ' . $identity);

                return self::FAILURE;
            }

            $keys[] = $kind === 'node'
                ? 'javmeta:egress-meta:' . $value
                : 'javmeta:browser-egress-meta:' . strtolower($value);
        }

        $updated = 0;
        foreach ($keys as $key) {
            $metadata = Cache::get($key);
            if (! is_array($metadata)) {
                continue;
            }

            Cache::put($key, $metadata, CarbonImmutable::now()->addDays(self::METADATA_TTL_DAYS));
            $updated++;
        }

        $this->info('Applied a 30-day expiry to ' . $updated . ' legacy egress metadata entries.');

        return self::SUCCESS;
    }

    private function validIdentity(string $kind, string $value): bool
    {
        if ($kind === 'node') {
            return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,253}$/', $value) === 1;
        }

        if ($kind !== 'browser') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/', $value) === 1;
    }
}
