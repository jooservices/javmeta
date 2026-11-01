<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AlertNotifier;
use App\Contracts\ReportChannel;
use App\Http\Middleware\EnsureSuperAdministrator;
use App\Logging\ArrayLogStore;
use App\Notifications\ActivityLogAlertNotifier;
use App\Notifications\CompositeAlertNotifier;
use App\Notifications\TelegramAlertNotifier;
use App\Observability\ObservabilityEmitter;
use App\Support\InsecureDefaults;
use App\Observability\OpenObserveClient;
use App\Observability\TelemetrySanitizer;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Crawl\MovieDraftSink;
use App\Services\Crawl\PerformerDraftSink;
use App\Services\Merge\ConflictPolicy;
use App\Services\Merge\SourcePriorityConflictPolicy;
use App\Services\Normalize\Normalizers\DefaultSourceNormalizer;
use App\Services\Normalize\Normalizers\JavbtcSourceNormalizer;
use App\Services\Normalize\Normalizers\JableSourceNormalizer;
use App\Services\Normalize\SourceNormalizerRegistry;
use App\Services\Persist\MoviePersister;
use App\Services\Persist\PerformerPersister;
use App\Services\Ui\UiLoginStateRecorder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use JOOservices\LaravelLogging\Contracts\LogStoreInterface;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(RouteMatched::class, static function (RouteMatched $event): void {
            if (in_array($event->route->getName(), ['storage.local', 'storage.local.upload'], true)) {
                $event->route->middleware(['auth', EnsureSuperAdministrator::class]);
            }
        });

        $this->app->booted(static function (): void {
            if (app()->runningInConsole() || app()->runningUnitTests()) {
                return;
            }

            try {
                app(UiLoginStateRecorder::class)->record();
            } catch (Throwable $exception) {
                // A crawl event must never prevent the UI from booting when the event store is unavailable.
                report($exception);
            }
        });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Services\Search\SearchBudget::class);

        /** @var array{allow_samples: bool, environments: list<string>, settings: list<string>, values: array<string, string|null>, known_defaults: list<string>} $rules */
        $rules = config('insecure_defaults');
        InsecureDefaults::assertSafe($this->app->environment(), $rules, $rules['values']);

        $this->app->singleton(SourceNormalizerRegistry::class, function (Application $app): SourceNormalizerRegistry {
            return new SourceNormalizerRegistry([
                $app->make(JableSourceNormalizer::class),
                $app->make(JavbtcSourceNormalizer::class),
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
        $this->app->bind(ReportChannel::class, TelegramAlertNotifier::class);
    }
}
