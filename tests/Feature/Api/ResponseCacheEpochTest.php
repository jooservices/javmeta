<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Services\Api\ResponseCacheEpoch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ResponseCacheEpochTest extends TestCase
{
    use RefreshDatabase;

    public function test_ac1_inside_a_transaction_the_epoch_changes_only_after_the_commit(): void
    {
        $epoch = app(ResponseCacheEpoch::class);
        $before = $epoch->current();

        DB::transaction(function () use ($epoch, $before): void {
            $epoch->bump();

            $this->assertSame($before, $epoch->current(), 'The epoch row must not be written while the data transaction is open.');
        });

        $this->assertNotSame($before, $epoch->current());
    }

    public function test_ac2_without_a_transaction_the_epoch_changes_at_once(): void
    {
        $epoch = app(ResponseCacheEpoch::class);
        $before = $epoch->current();

        $epoch->bump();

        $this->assertNotSame($before, $epoch->current());
    }

    public function test_ac3_a_rolled_back_transaction_leaves_the_epoch_unchanged(): void
    {
        $epoch = app(ResponseCacheEpoch::class);
        $before = $epoch->current();

        try {
            DB::transaction(function () use ($epoch): void {
                $epoch->bump();

                throw new RuntimeException('the write failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame($before, $epoch->current());
    }
}
