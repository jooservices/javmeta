<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Health\StartupHealthService;
use App\Services\Settings\SettingsService;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;

final class StartupHealthTestFixture
{
    /** @param array<string, bool|list<bool>> $availability @return array{health: StartupHealthService, store: FakeConfigStore, probes: array<string, MutableDependencyProbe>} */
    public static function bind(array $availability = []): array
    {
        SettingsService::forgetDefaults();
        config(['openobserve.enabled' => false]);
        $store = new FakeConfigStore();
        app()->instance(ConfigStore::class, $store);
        app()->forgetInstance(SettingsService::class);

        $probes = [];
        foreach (Dependency::cases() as $dependency) {
            $probes[$dependency->value] = new MutableDependencyProbe($availability[$dependency->value] ?? true);
        }

        app()->instance(DependencyMonitor::class, new DependencyMonitor($probes));
        app()->forgetInstance(StartupHealthService::class);

        return [
            'health' => app(StartupHealthService::class),
            'store' => $store,
            'probes' => $probes,
        ];
    }
}
