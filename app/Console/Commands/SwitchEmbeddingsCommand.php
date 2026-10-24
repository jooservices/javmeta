<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Movie;
use App\Models\Performer;
use App\Services\Embedding\EmbedderClient;
use App\Services\Search\ElasticsearchHttpClient;
use App\Services\Search\ElasticsearchIndexer;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Move search to another embedding provider without a gap: build NEW indices with the new vector size, fill them, and only
 * when every document has its vector switch the settings to them. The old indices are never touched, so going back is one
 * setting change. A failure leaves the live indices and settings as they were.
 */
final class SwitchEmbeddingsCommand extends Command
{
    protected $signature = 'search:switch-embeddings {provider : embedder or ollama} {--dry-run : Only show the plan} {--limit=0 : Index only this many of each kind (a trial run; settings are not switched)}';

    protected $description = 'Re-embed everything with another provider into new indices, then switch the settings to them (the old indices stay for rollback).';

    public function handle(SettingsService $settings, ElasticsearchHttpClient $elasticsearch, ElasticsearchIndexer $indexer, EmbedderClient $embedder): int
    {
        $provider = (string) $this->argument('provider');
        if (! in_array($provider, ['embedder', 'ollama'], true)) {
            $this->error('The provider is embedder or ollama.');

            return self::FAILURE;
        }
        $limit = max(0, (int) $this->option('limit'));
        $previous = ['provider' => config('javmeta_ai.embedding_provider'), 'dim' => config('elasticsearch.embed_dim'), 'movies' => config('elasticsearch.movies_index'), 'performers' => config('elasticsearch.performers_index')];

        config(['javmeta_ai.embedding_provider' => $provider]);
        $probe = $embedder->embedOne('dimension probe');
        if ($probe === null) {
            $this->error("The {$provider} provider did not answer; nothing was changed.");
            $this->restore($previous);

            return self::FAILURE;
        }
        $dim = count($probe);
        $suffix = "_{$dim}d_{$provider}";
        $base = fn(string $name): string => preg_replace('/_\d+d_[a-z]+$/', '', $name) ?? $name;
        $movies = $base((string) $previous['movies']) . $suffix;
        $performers = $base((string) $previous['performers']) . $suffix;
        $this->line(sprintf('Plan: provider %s, %d dimensions, new indices %s and %s (live: %s, %s).', $provider, $dim, $movies, $performers, $previous['movies'], $previous['performers']));
        if ((bool) $this->option('dry-run')) {
            $this->restore($previous);

            return self::SUCCESS;
        }

        try {
            foreach ([[$movies, 'embedding_movie'], [$performers, 'embedding_performer']] as [$index, $field]) {
                $response = $elasticsearch->put('switch', "/{$index}", [
                    'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0],
                    'mappings' => ['properties' => ['uuid' => ['type' => 'keyword'], $field => ['type' => 'dense_vector', 'dims' => $dim, 'index' => true, 'similarity' => 'cosine']]],
                ], 30);
                if (! $response->successful() && $response->status() !== 400) {
                    throw new \RuntimeException("Could not create {$index}.");
                }
            }
            config(['elasticsearch.embed_dim' => $dim, 'elasticsearch.movies_index' => $movies, 'elasticsearch.performers_index' => $performers]);

            $failed = 0;
            $done = 0;
            $movieQuery = Movie::query()->with(['codes', 'performers', 'genres']);
            $performerQuery = Performer::query()->whereNull('canonical_performer_id');
            if ($limit > 0) {
                $movieQuery->limit($limit);
                $performerQuery->limit($limit);
            }
            foreach ($movieQuery->cursor() as $movie) {
                $indexer->indexMovie($movie, true) ? $done++ : $failed++;
            }
            foreach ($performerQuery->cursor() as $performer) {
                $indexer->indexPerformer($performer, true) ? $done++ : $failed++;
            }
            $this->line("Indexed {$done}, failed {$failed}.");
            if ($failed > 0 || $limit > 0) {
                $this->warn($failed > 0 ? 'Some documents failed; the live settings were not switched.' : 'Trial run; the live settings were not switched.');
                $this->restore($previous);

                return $failed > 0 ? self::FAILURE : self::SUCCESS;
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage() . ' Nothing was switched.');
            $this->restore($previous);

            return self::FAILURE;
        }

        $settings->set('ai.embedding_provider', $provider);
        $settings->set('search.embedding_dimensions', $dim);
        $settings->set('search.movies_index', $movies);
        $settings->set('search.performers_index', $performers);
        $this->info("Switched to {$provider}. The old indices {$previous['movies']} and {$previous['performers']} are kept; switch the settings back to return.");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $previous */
    private function restore(array $previous): void
    {
        config(['javmeta_ai.embedding_provider' => $previous['provider'], 'elasticsearch.embed_dim' => $previous['dim'], 'elasticsearch.movies_index' => $previous['movies'], 'elasticsearch.performers_index' => $previous['performers']]);
    }
}
