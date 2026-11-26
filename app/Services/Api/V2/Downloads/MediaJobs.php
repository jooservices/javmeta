<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Downloads;

use App\Http\Api\V2\Envelope;
use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\LibraryRepository;
use App\Services\Media\MediaQueue;
use LogicException;

/**
 * Media jobs as the UI names them (capture, thumbnail, subtitle) on top of the queue the media worker already drains
 * (capture, thumbnails, transcribe); the worker's own endpoints are not touched.
 */
final class MediaJobs
{
    private const WORKER_KIND = ['capture' => 'capture', 'thumbnail' => 'thumbnails', 'subtitle' => 'transcribe'];

    public function __construct(private readonly LibraryRepository $repository, private readonly MediaQueue $queue) {}

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function page(int $page, int $perPage): array
    {
        return ['data' => array_map($this->view(...), $this->repository->jobs($page, $perPage)), 'meta' => Envelope::pagination($page, $perPage, $this->repository->countJobs())];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(string $kind, ?string $sourceUrl, array $params): array
    {
        $id = $kind === 'capture' ? $this->captureJob($sourceUrl) : $this->fileJob($kind, $params);

        return $this->view($this->repository->job($id) ?? throw new LogicException('Row vanished.'));
    }

    /** @return array<string, mixed> */
    public function retry(int $id): array
    {
        if (! $this->repository->retryFailedJob($id)) {
            throw ProblemException::conflict('not_retryable', 'Only failed jobs can be retried.');
        }

        return $this->view($this->repository->job($id) ?? throw new LogicException('Row vanished.'));
    }

    private function captureJob(?string $url): int
    {
        $parts = $url === null ? false : parse_url($url);

        if ($url === null || $url === '') {
            throw ProblemException::invalid('source_url is required for a capture.', [['field' => 'source_url', 'code' => 'required']]);
        }

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw ProblemException::invalid('source_url must be a public HTTP(S) address without credentials.', [['field' => 'source_url', 'code' => 'invalid']]);
        }

        if (! (bool) config('javmeta_capture.enabled')) {
            throw ProblemException::conflict('capture_disabled', 'Stream capture is switched off in Settings.');
        }

        return $this->queue->capture($url);
    }

    /** @param array<string, mixed> $params */
    private function fileJob(string $kind, array $params): int
    {
        $file = $params['library_file_id'] ?? null;

        if (! is_int($file)) {
            throw ProblemException::invalid('params.library_file_id is required for this kind.', [['field' => 'params.library_file_id', 'code' => 'required']]);
        }

        if (! $this->repository->fileIsPresent($file)) {
            throw ProblemException::invalid('The file is not in the library.', [['field' => 'params.library_file_id', 'code' => 'exists']]);
        }

        return $this->queue->enqueue(self::WORKER_KIND[$kind], $file);
    }

    /** @return array<string, mixed> */
    private function view(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'kind' => (string) (array_search($row->kind, self::WORKER_KIND, true) ?: $row->kind),
            'source_url' => $row->source_url === null ? null : (string) $row->source_url,
            'status' => (string) $row->status,
            'attempts' => (int) $row->attempts,
            'error' => $row->error === null ? null : (string) $row->error,
            'created_at' => Stamp::iso($row->created_at),
            'started_at' => Stamp::iso($row->started_at),
            'finished_at' => Stamp::iso($row->finished_at),
        ];
    }
}
