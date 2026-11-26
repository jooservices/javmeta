<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Api\V2\Administration\DatabaseAuditLog;
use App\Services\Api\V2\Contracts\AuditLog;
use Illuminate\Support\ServiceProvider;

/** Binds the audit trail of the v2 administration area. */
final class AdministrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditLog::class, DatabaseAuditLog::class);
    }
}
