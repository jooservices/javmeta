<?php

declare(strict_types=1);

// Seeds a small, deterministic catalogue and two users into the database named by the environment, then rebuilds the read models.
// Used only by the browser end-to-end run (ui/e2e/run.sh) against a throwaway local database.

use App\Models\Genre;
use App\Models\Movie;
use App\Models\MoviePerformer;
use App\Models\Performer;
use App\Models\Source;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// The browser run has no Elasticsearch, so the startup health gate that waits for it is switched off in this throwaway store.
app(SettingsService::class)->set('health.startup.enabled', false);
// The guest journeys need the public catalogue, so the throwaway installation runs with guest mode on (login_required off).
app(SettingsService::class)->set('ui_security.login_required', false);

User::factory()->create(['name' => 'Admin', 'email' => 'admin@e2e.test', 'password' => 'e2e-password-1', 'role' => 'admin']);
User::factory()->create(['name' => 'Viewer', 'email' => 'viewer@e2e.test', 'password' => 'e2e-password-1']);

$source = Source::factory()->create(['slug' => 'e2e-source', 'enabled' => true]);
$genres = array_map(static fn(string $name) => Genre::factory()->create(['label_normalized' => strtolower($name), 'label_raw' => $name]), ['Drama', 'Comedy', 'Romance']);

$performers = [];
foreach (['Aoi Test', 'Mio Sample', 'Rin Example'] as $name) {
    $performers[] = Performer::factory()->create(['name_romaji' => $name, 'birth_date' => '1998-04-01', 'height_cm' => 160]);
}

for ($i = 1; $i <= 30; $i++) {
    $movie = Movie::factory()->create([
        'display_code' => sprintf('E2E-%03d', $i),
        'title_en' => "E2E movie {$i}",
        'release_date' => sprintf('2025-%02d-%02d', ($i % 12) + 1, ($i % 27) + 1),
        'maker' => $i % 2 === 0 ? 'Maker Even' : 'Maker Odd',
        'community_score' => 5 + ($i % 5),
        'delisted_at' => null,
    ]);
    MoviePerformer::factory()->create(['movie_id' => $movie->id, 'performer_id' => $performers[$i % 3]->id, 'source_slug' => $source->slug]);
}

Artisan::call('xcrawler:read-models:refresh');
echo "seeded\n";
