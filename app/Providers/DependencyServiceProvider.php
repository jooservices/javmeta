<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Dependencies\Probes\BrowserProbe;
use App\Services\Dependencies\Probes\ElasticsearchProbe;
use App\Services\Dependencies\Probes\EmbedderProbe;
use App\Services\Dependencies\Probes\MongoProbe;
use App\Services\Dependencies\Probes\OllamaProbe;
use App\Services\Dependencies\Probes\ObservabilityProbe;
use App\Services\Dependencies\Probes\PostgresProbe;
use App\Services\Dependencies\Probes\QBittorrentProbe;
use App\Services\Dependencies\Probes\RedisProbe;
use App\Services\Dependencies\Probes\SentryProbe;
use App\Services\Dependencies\Probes\TelegramProbe;
use App\Listeners\PauseWorkerWhenHardDependencyUnavailable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider;

final class DependencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DependencyMonitor::class, function (Application $app): DependencyMonitor {
            return new DependencyMonitor([
                Dependency::Postgres->value => $app->make(PostgresProbe::class),
                Dependency::Redis->value => $app->make(RedisProbe::class),
                Dependency::Elasticsearch->value => $app->make(ElasticsearchProbe::class),
                Dependency::Mongo->value => $app->make(MongoProbe::class),
                Dependency::Embedder->value => $app->make(EmbedderProbe::class),
                Dependency::Observability->value => $app->make(ObservabilityProbe::class),
                Dependency::Sentry->value => $app->make(SentryProbe::class),
                Dependency::Browser->value => $app->make(BrowserProbe::class),
                Dependency::Telegram->value => $app->make(TelegramProbe::class),
                Dependency::Ollama->value => $app->make(OllamaProbe::class),
                Dependency::QBittorrent->value => $app->make(QBittorrentProbe::class),
            ]);
        });
    }

    public function boot(): void
    {
        $this->app['events']->listen(Looping::class, PauseWorkerWhenHardDependencyUnavailable::class);
    }
}
