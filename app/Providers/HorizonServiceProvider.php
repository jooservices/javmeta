<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\OwnerAdminGuard;
use App\Services\Settings\SettingsService;
use App\Services\Workers\HorizonLayoutSnapshot;
use App\Services\Workers\HorizonWorkerLayout;
use Illuminate\Support\Facades\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Laravel\Horizon\MasterSupervisor;
use Illuminate\Support\Str;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningUnitTests() && ! empty(config('app.key'))) {
            app(SettingsService::class)->apply();
            $snapshot = app(HorizonWorkerLayout::class)->apply();
            MasterSupervisor::determineNameUsing(static fn(): string => 'javmeta-horizon-' . Str::slug((string) $snapshot['node']));
            Event::listen(MasterSupervisorLooped::class, HorizonLayoutSnapshot::class);
        }

        parent::boot();
        Horizon::auth(static fn(Request $request): bool => app(OwnerAdminGuard::class)->allows($request));

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', static fn($user = null): bool => app(OwnerAdminGuard::class)->allows(request()));
    }
}
