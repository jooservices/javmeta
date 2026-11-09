<?php

declare(strict_types=1);

use App\Services\Dataset\SampleDataset;
use App\Services\Search\HybridSearchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
if ($connection->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "Search recall evaluation requires a PostgreSQL QA database.\n");
    exit(2);
}

$database = $connection->getDatabaseName();
$role = (string) config('database.connections.' . $connection->getName() . '.username', '');
if (preg_match('/(?:_test|_wt\d+)$/', $database) !== 1 || preg_match('/(?:_test|_wt\d+)$/', $role) !== 1) {
    fwrite(STDERR, "Refusing evaluation: both the database and role must use the _test or _wt<id> suffix.\n");
    exit(2);
}
$identity = $connection->selectOne('SELECT current_user AS role_name');
if (! is_object($identity) || (string) $identity->role_name !== $role) {
    fwrite(STDERR, "Refusing evaluation: the connected PostgreSQL role does not match the configured test role.\n");
    exit(2);
}

$datasetCounts = app(SampleDataset::class)->counts();
if ($datasetCounts['movies'] !== SampleDataset::MOVIES
    || $datasetCounts['performers'] !== SampleDataset::PERFORMERS
    || $datasetCounts['genres'] !== SampleDataset::GENRES) {
    fwrite(STDERR, "The QA sample dataset is incomplete; run dataset:load sample --force on this test database first.\n");
    exit(2);
}

$fixturePath = $root . '/tests/Fixtures/search-eval.json';
$queries = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$languageCounts = array_count_values(array_column($queries, 'lang'));
if (count($queries) !== 50 || $languageCounts !== ['en' => 20, 'vi' => 15, 'ja' => 15]) {
    fwrite(STDERR, "The search evaluation fixture must contain 20 English, 15 Vietnamese and 15 Japanese queries.\n");
    exit(2);
}

config([
    'elasticsearch.enabled' => false,
    'javmeta_ai.nl_enabled' => false,
    'javmeta_search.budget_ms' => 8000,
]);

$started = hrtime(true);
$scores = [];
foreach ($queries as $example) {
    $relevant = relevantMovieUuids($example);
    $denominator = min(10, count($relevant));
    if ($denominator === 0) {
        fwrite(STDERR, "No relevant QA movies for query: {$example['q']}\n");
        exit(1);
    }

    $result = app(HybridSearchService::class)->search($example['q'], 10, true);
    $top = $result['movies']->pluck('uuid')->all();
    $hits = count(array_intersect($top, $relevant));
    $scores[$example['lang']]['hits'] = ($scores[$example['lang']]['hits'] ?? 0) + $hits;
    $scores[$example['lang']]['possible'] = ($scores[$example['lang']]['possible'] ?? 0) + $denominator;
    $scores[$example['lang']]['queries'] = ($scores[$example['lang']]['queries'] ?? 0) + 1;
}

$report = [
    'fixture_sha256' => hash_file('sha256', $fixturePath),
    'dataset' => $datasetCounts,
    'duration_ms' => max(0, (int) round((hrtime(true) - $started) / 1_000_000)),
    'recall_at_10' => [],
];
foreach (['en', 'vi', 'ja'] as $language) {
    $row = $scores[$language];
    $report['recall_at_10'][$language] = [
        'recall' => round($row['hits'] / $row['possible'], 4),
        'hits' => $row['hits'],
        'possible' => $row['possible'],
        'queries' => $row['queries'],
    ];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

if (in_array('--write-baseline', $argv, true)) {
    $baselinePath = $root . '/tests/Fixtures/search-eval-baseline.json';
    file_put_contents($baselinePath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    fwrite(STDERR, "Wrote measured QA baseline to tests/Fixtures/search-eval-baseline.json.\n");
}

if ($report['duration_ms'] >= 60_000) {
    fwrite(STDERR, "Search recall evaluation exceeded 60 seconds.\n");
    exit(1);
}

/** @param array{q: string, genres: list<string>, performers: list<string>, codes?: list<string>} $example
 * @return list<string>
 */
function relevantMovieUuids(array $example): array
{
    $query = DB::table('movies')
        ->where(static function (Builder $titles): void {
            $titles->where(static function (Builder $english): void {
                $english->whereNotNull('title_en')->where('title_en', '<>', '');
            })->orWhere(static function (Builder $japanese): void {
                $japanese->whereNotNull('title_jp')->where('title_jp', '<>', '');
            });
        });

    foreach ($example['genres'] as $genre) {
        $query->whereExists(static function (Builder $matching) use ($genre): void {
            $matching->selectRaw('1')->from('movie_genres')
                ->join('genres', 'genres.id', '=', 'movie_genres.genre_id')
                ->whereColumn('movie_genres.movie_id', 'movies.id')
                ->whereRaw('LOWER(COALESCE(genres.label_raw, genres.label_normalized)) = ?', [mb_strtolower($genre)]);
        });
    }

    foreach ($example['performers'] as $performer) {
        $query->whereExists(static function (Builder $matching) use ($performer): void {
            $matching->selectRaw('1')->from('movie_performers')
                ->join('performers', 'performers.id', '=', 'movie_performers.performer_id')
                ->whereColumn('movie_performers.movie_id', 'movies.id')
                ->whereNull('performers.canonical_performer_id')
                ->whereRaw('LOWER(performers.name_romaji) = ?', [mb_strtolower($performer)]);
        });
    }

    foreach ($example['codes'] ?? [] as $code) {
        $query->whereExists(static function (Builder $matching) use ($code): void {
            $matching->selectRaw('1')->from('movie_codes')
                ->whereColumn('movie_codes.movie_id', 'movies.id')
                ->whereRaw('LOWER(movie_codes.code) = ?', [mb_strtolower($code)]);
        });
    }

    return $query->distinct()->pluck('movies.uuid')->all();
}
