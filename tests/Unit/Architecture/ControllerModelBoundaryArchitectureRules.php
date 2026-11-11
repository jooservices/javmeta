<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Http\Controllers\MetaGenreController;
use App\Http\Controllers\MovieBulkController;
use App\Http\Controllers\MovieLookupController;
use App\Http\Controllers\MovieRelatedController;
use App\Http\Controllers\PerformerController;
use App\Http\Controllers\StatusController;
use App\Models\CrawlQueue;
use App\Models\Genre;
use App\Models\Movie;
use App\Models\Performer;
use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ControllerModelBoundaryArchitectureRules
{
    /**
     * @var array<class-string, array<class-string, string>>
     */
    private const EXISTING_EXCEPTIONS = [
        MovieBulkController::class => [
            Movie::class => 'The lookup service returns Eloquent movies and the endpoint checks the returned type before resource serialization.',
        ],
        MovieLookupController::class => [
            Movie::class => 'The lookup service returns an Eloquent movie and the resource contract currently accepts that model.',
        ],
        MovieRelatedController::class => [
            Movie::class => 'Related movie assembly reads Eloquent relationships and attributes in the controller.',
        ],
        PerformerController::class => [
            Performer::class => 'The repository returns an Eloquent performer and the resource contract currently accepts that model.',
        ],
        StatusController::class => [
            CrawlQueue::class => 'The controller uses the model only for queue status constants when shaping the status response.',
        ],
        MetaGenreController::class => [
            Genre::class => 'The endpoint currently builds its distinct genre-label response with a direct Eloquent query.',
        ],
    ];

    #[TestRule]
    public function controllers_do_not_depend_on_application_models(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Http\\Controllers'))
            ->excluding(...$this->existingControllerSelectors())
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('App\\Models'))
            ->because('Controllers must access application data through services and repositories.');
    }

    /**
     * @return iterable<Rule>
     */
    #[TestRule]
    public function existing_controller_model_exceptions_are_limited_to_the_listed_pairs(): iterable
    {
        foreach (self::EXISTING_EXCEPTIONS as $controller => $models) {
            yield PHPat::rule()
                ->classes(Selector::classname($controller))
                ->shouldNot()
                ->dependOn()
                ->classes(Selector::inNamespace('App\\Models'))
                ->excluding(...array_map(
                    static fn(string $model): SelectorInterface => Selector::classname($model),
                    array_keys($models),
                ))
                ->because($this->exceptionReason($controller, $models));
        }
    }

    /**
     * @return list<SelectorInterface>
     */
    private function existingControllerSelectors(): array
    {
        return array_map(
            static fn(string $controller): SelectorInterface => Selector::classname($controller),
            array_keys(self::EXISTING_EXCEPTIONS),
        );
    }

    /**
     * @param array<class-string, string> $models
     */
    private function exceptionReason(string $controller, array $models): string
    {
        $details = [];
        foreach ($models as $model => $reason) {
            $details[] = sprintf('%s -> %s: %s', $controller, $model, $reason);
        }

        return 'Baseline exception; keep limited to this controller/model pair and move the dependency behind a service or repository: '
            . implode('; ', $details);
    }
}
