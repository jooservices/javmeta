<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Services\Downloads\FileLinkService;
use App\Services\Embedding\EmbedderClient;
use App\Services\Search\ElasticsearchHttpClient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class SceneSearchService
{
    public function __construct(private readonly EmbedderClient $embedder, private readonly ElasticsearchHttpClient $es, private readonly FileLinkService $links) {}

    public function indexFile(int $file): void
    {
        $scenes = DB::table('media_scenes')->where('library_file_id', $file)->whereNotNull('description')->whereNull('embedding_indexed_at')->get();
        if ($scenes->isEmpty()) {
            return;
        }
        try {
            $index = $this->index();
            foreach ($scenes as $scene) {
                $vector = $this->embedder->embedOne($scene->description);
                if ($vector === null || count($vector) !== (int) config('javmeta_media.embedding_dimensions')) {
                    return;
                }
                if ($this->es->head('scene-index', $index, acceptedStatuses: [404])->status() === 404) {
                    $created = $this->es->put('scene-index-create', $index, ['mappings' => ['properties' => [
                        'description' => ['type' => 'text'],
                        'embedding' => ['type' => 'dense_vector', 'dims' => (int) config('javmeta_media.embedding_dimensions'), 'index' => true, 'similarity' => 'cosine'],
                    ]]]);
                    if (! $created->successful()) {
                        return;
                    }
                }
                $response = $this->es->put('scene-index-document', $index . '/_doc/' . $scene->id, ['description' => $scene->description, 'embedding' => $vector]);
                if ($response->successful()) {
                    DB::table('media_scenes')->where('id', $scene->id)->update(['embedding_indexed_at' => now()]);
                }
            }
        } catch (Throwable) {
            // Rows remain searchable by the indexed PostgreSQL keyword fallback.
        }
    }

    public function search(string $q, int $limit): array
    {
        $ids = [];
        $scores = [];
        try {
            $vector = $this->embedder->embedOne($q, true);
            if ($vector !== null && count($vector) === (int) config('javmeta_media.embedding_dimensions')) {
                $response = $this->es->post('scene-search', $this->index() . '/_search', ['size' => $limit, 'knn' => ['field' => 'embedding', 'query_vector' => $vector, 'k' => $limit, 'num_candidates' => max(100, $limit)]]);
                if ($response->successful()) {
                    foreach ((array) $response->json('hits.hits', []) as $hit) {
                        $ids[] = (int) $hit['_id'];
                        $scores[(int) $hit['_id']] = (float) $hit['_score'];
                    }
                }
            }
        } catch (Throwable) {
            $ids = [];
        }
        $query = DB::table('media_scenes AS s')->join('library_files AS f', 'f.id', '=', 's.library_file_id')->whereNull('f.missing_at');
        if ($ids !== []) {
            $query->whereIn('s.id', $ids);
        } else {
            $query->whereRaw('lower(s.description) LIKE ? ESCAPE \'!\'', ['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)) . '%'])->orderBy('s.id');
        }
        $rows = $query->limit($limit)->get(['s.*', 'f.code']);
        if ($ids !== []) {
            $rows = $rows->sortByDesc(static fn($r): float => $scores[$r->id] ?? 0);
        }
        return $rows->map(function ($scene) use ($scores): array {
            try {
                $thumb = $this->links->url($scene->thumb_path);
            } catch (Throwable) {
                $thumb = null;
            }
            return ['movie_code' => $scene->code, 'library_file_id' => (int) $scene->library_file_id, 'start_sec' => (float) $scene->start_sec, 'end_sec' => (float) $scene->end_sec, 'thumb_url' => $thumb, 'description' => $scene->description, 'score' => $scores[$scene->id] ?? 1.0];
        })->values()->all();
    }

    private function index(): string
    {
        $index = (string) config('javmeta_media.scenes_index');
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,100}$/D', $index) !== 1) {
            throw new InvalidArgumentException('Invalid scene index name.');
        }
        return $index;
    }
}
