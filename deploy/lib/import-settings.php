<?php

declare(strict_types=1);

use App\Services\Settings\SettingsService;
use Illuminate\Contracts\Console\Kernel;
use JOOservices\LaravelConfig\Contracts\ConfigStore;

require getcwd() . '/vendor/autoload.php';
require __DIR__ . '/settings.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
JavMeta\Deploy\importSettings($app->make(SettingsService::class), $manifest, $app->make(ConfigStore::class)->group(SettingsService::GROUP), getenv());
