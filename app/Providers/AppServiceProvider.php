<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AlertNotifier;
use App\Logging\ArrayLogStore;
use App\Notifications\ActivityLogAlertNotifier;
use App\Notifications\CompositeAlertNotifier;
use App\Notifications\TelegramAlertNotifier;
use App\Observability\ObservabilityEmitter;
use App\Observability\OpenObserveClient;
use App\Observability\TelemetrySanitizer;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Crawl\MovieDraftSink;
use App\Services\Crawl\PerformerDraftSink;
use App\Services\Merge\ConflictPolicy;
use App\Services\Merge\SourcePriorityConflictPolicy;
use App\Services\Normalize\Normalizers\DefaultSourceNormalizer;
use App\Services\Normalize\Normalizers\JableSourceNormalizer;
use App\Services\Normalize\SourceNormalizerRegistry;
use App\Services\Persist\MoviePersister;
use App\Services\Persist\PerformerPersister;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use JOOservices\LaravelLogging\Contracts\LogStoreInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SourceNormalizerRegistry::class, function (Application $app): SourceNormalizerRegistry {
            return new SourceNormalizerRegistry([
                $app->make(JableSourceNormalizer::class),
                $app->make(DefaultSourceNormalizer::class),
            ]);
        });

        // BR-2 conflict strategy is swap-able via the interface (REQ-D4).
        $this->app->singleton(ConflictPolicy::class, SourcePriorityConflictPolicy::class);

        // Persistence seams consumed by fetch jobs.
        $this->app->singleton(MovieDraftSink::class, MoviePersister::class);
        $this->app->singleton(PerformerDraftSink::class, PerformerPersister::class);

        $this->app->singleton(TelemetrySanitizer::class);
        $this->app->singleton(OpenObserveClient::class);
        $this->app->singleton(ObservabilityEmitter::class);

        if ($this->app->environment('testing')) {
            $this->app->singleton(LogStoreInterface::class, ArrayLogStore::class);
        }

        $this->app->singleton(AlertNotifier::class, function (Application $app): AlertNotifier {
            return new CompositeAlertNotifier([
                $app->make(ActivityLogAlertNotifier::class),
                $app->make(TelegramAlertNotifier::class),
            ]);
        });
        $this->app->singleton(AlertDispatcher::class);
    }
}
