<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->alterWithLockRetry(<<<'SQL'
                ALTER TABLE crawl_fetch_attempts
                    ADD COLUMN IF NOT EXISTS egress_ip varchar(45) NULL,
                    ADD COLUMN IF NOT EXISTS node varchar(64) NULL,
                    ADD COLUMN IF NOT EXISTS egress_kind varchar(16) NULL
            SQL);

            return;
        }

        $addEgressIp = ! Schema::hasColumn('crawl_fetch_attempts', 'egress_ip');
        $addNode = ! Schema::hasColumn('crawl_fetch_attempts', 'node');
        $addEgressKind = ! Schema::hasColumn('crawl_fetch_attempts', 'egress_kind');
        if (! $addEgressIp && ! $addNode && ! $addEgressKind) {
            return;
        }

        Schema::table('crawl_fetch_attempts', static function (Blueprint $table) use ($addEgressIp, $addNode, $addEgressKind): void {
            if ($addEgressIp) {
                $table->string('egress_ip', 45)->nullable();
            }
            if ($addNode) {
                $table->string('node', 64)->nullable();
            }
            if ($addEgressKind) {
                $table->string('egress_kind', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->alterWithLockRetry(<<<'SQL'
                ALTER TABLE crawl_fetch_attempts
                    DROP COLUMN IF EXISTS egress_kind,
                    DROP COLUMN IF EXISTS node,
                    DROP COLUMN IF EXISTS egress_ip
            SQL);

            return;
        }

        $columns = array_values(array_filter(
            ['egress_ip', 'node', 'egress_kind'],
            static fn(string $column): bool => Schema::hasColumn('crawl_fetch_attempts', $column),
        ));
        if ($columns !== []) {
            Schema::table('crawl_fetch_attempts', static function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function alterWithLockRetry(string $statement): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            DB::statement("SET lock_timeout = '1s'");
            try {
                DB::statement($statement);

                return;
            } catch (QueryException $exception) {
                if (! in_array($this->sqlState($exception), ['55P03', '57014'], true) || $attempt === 5) {
                    throw $exception;
                }

                usleep(250_000 * $attempt);
            } finally {
                DB::statement('RESET lock_timeout');
            }
        }
    }

    private function sqlState(QueryException $exception): string
    {
        return (string) ($exception->errorInfo[0] ?? $exception->getCode());
    }
};
