<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Api\ResponseCacheEpoch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ResponseCacheEpochLockTest extends IntegrationTestCase
{
    public function test_a_persister_transaction_does_not_hold_the_epoch_row_while_another_worker_bumps_it(): void
    {
        config(['database.connections.pgsql_other_worker' => config('database.connections.pgsql')]);
        $epoch = app(ResponseCacheEpoch::class);
        $initial = $epoch->current();
        $other = DB::connection('pgsql_other_worker');
        $other->statement("SET lock_timeout = '2s'");

        DB::beginTransaction();
        try {
            $epoch->bump();

            try {
                $other->table('cache')->upsert(
                    [['key' => config('cache.prefix') . ResponseCacheEpoch::KEY, 'value' => serialize('other-worker'), 'expiration' => time() + 3600]],
                    ['key'],
                    ['value', 'expiration'],
                );
            } catch (QueryException $exception) {
                self::fail('The other worker waited for the epoch row held by an open persister transaction: ' . $exception->getMessage());
            }
        } finally {
            DB::commit();
        }

        $after = $epoch->current();
        self::assertNotSame($initial, $after);
        self::assertNotSame('other-worker', $after, 'The persister epoch is written after its commit, so it is the newest value.');
    }
}
