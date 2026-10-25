<?php

declare(strict_types=1);
putenv('CACHE_STORE=array');
$_ENV['CACHE_STORE'] = $_SERVER['CACHE_STORE'] = 'array';
putenv('PGOPTIONS=-c default_transaction_read_only=on');
require getcwd() . '/vendor/autoload.php';
if (isset($argv[2])) {
    foreach (explode(',', $argv[4] ?? 'app/Repositories/PerformerRepository.php') as $file) {
        require $argv[2] . '/' . $file;
    }
}
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement('SET default_transaction_read_only = on');
$slow = [];
Illuminate\Support\Facades\DB::listen(static function ($query) use (&$slow): void {
    if ($query->time > 50 && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
        echo json_encode(['slow_query' => $query->sql, 'ms' => $query->time]) . PHP_EOL;
        $slow[$query->sql] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'ms' => $query->time];
    }
});
$jobs = [
    'performers' => fn() => $app->make(App\Repositories\PerformerRepository::class)->search([], 'name', null, 24),
    'performers-aiko' => fn() => $app->make(App\Repositories\PerformerRepository::class)->search(['q' => 'aiko'], 'name', null, 24),
    'status' => fn() => $app->make(App\Services\Crawl\CrawlStatusService::class)->status(),
    'facets' => fn() => $app->make(App\Repositories\MovieRepository::class)->facets([]),
    'suggest' => fn() => $app->make(App\Http\Controllers\SuggestController::class)->index(Illuminate\Http\Request::create('/', 'GET', ['q' => 'mi'])),
    'search' => fn() => $app->make(App\Services\Search\HybridSearchService::class)->search('beautiful office drama 2025'),
];
echo json_encode(['machine' => gethostname(), 'counts' => $app->make(App\Services\Crawl\CrawlStatusService::class)->counts()]) . PHP_EOL;
foreach ($jobs as $name => $job) {
    if (isset($argv[3]) && ! in_array($name, explode(',', $argv[3]), true)) {
        continue;
    }
    $times = [];
    for ($i = 0; $i < (int) ($argv[1] ?? 30); $i++) {
        $start = hrtime(true);
        try {
            $job();
        } catch (Throwable $e) {
            echo json_encode(['endpoint' => $name, 'error_class' => $e::class]) . PHP_EOL;
            break;
        }
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    if ($times !== []) {
        $cold = $times[0];
        sort($times);
        echo json_encode(['endpoint' => $name, 'calls' => count($times), 'first_ms' => $cold, 'p50_ms' => $times[(int) ceil(count($times) * .5) - 1], 'p95_ms' => $times[(int) ceil(count($times) * .95) - 1]]) . PHP_EOL;
    }
}
foreach ($slow as $query) {
    echo json_encode(['explain_sql' => $query['sql'], 'last_ms' => $query['ms']]) . PHP_EOL;
    $plan = Illuminate\Support\Facades\DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' . $query['sql'], $query['bindings']);
    echo json_encode(['plan' => $plan]) . PHP_EOL;
}
