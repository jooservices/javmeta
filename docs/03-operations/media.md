# Local media pipeline

JOOXCR-258 / JOOXCR-224. The worker runs on Workstation (WSL), inside the existing shared `media` runtime. The 20 TB library disk is not mounted; validation uses generated media only. No service is deployed by this change.

## Queue and worker

`media_jobs` stores pending/running/done/failed jobs. `media_worker_lock` serializes admission and claims across workers, so `media.max_parallel` is enforced even when two workers poll together. Every claim increments attempts and issues a token. Completion/failure requires the current token. An expired lease is recovered on the next poll; an old worker cannot overwrite a newer attempt. Keep the lease longer than the job timeout. Failure retries up to `media.max_attempts`; the owner can retry a failed job in Media.

`media_scenes` stores scene ranges and images. `media_subtitles` reserves the subtitle storage contract. Library files remain read-only; generated files go under `media.output_folder`, in one subdirectory per job. Signed serving remains controlled separately by `files.enabled`.

```sh
# From the application checkout on Workstation:
/home/joos/Workspaces/scripts/docker/runtime php@8.5 php artisan media:worker-key
/home/joos/Workspaces/scripts/docker/runtime php@8.5 php artisan media:enqueue scenes --file=1
/home/joos/Workspaces/scripts/docker/runtime php@8.5 php artisan media:enqueue thumbnails --movie=ABC-001
/home/joos/Workspaces/scripts/docker/runtime php@8.5 php artisan media:status
/home/joos/Workspaces/scripts/docker/runtime media python tools/media-worker/worker.py --once
```

Set `media.api_url` in Settings **before** creating the key. `storage/app/private/media/worker.key` is JSON with `url` and `key`, created exclusively once with mode 0640; it is never printed. Updating the bootstrap URL in an existing credential file is an operator action. The key has only the `media` scope. The browser cannot claim worker jobs through the GET bridge. Owner actions use authenticated, CSRF-protected `/ui-me/media` endpoints.

The worker reads config on every poll. `GET /api/v1/media/config`, `GET /api/v1/media/jobs/next?worker=id`, and POST `jobs/{id}/complete|fail` require the media scope. Complete/fail bodies contain `claim_token` plus `result` or `error`. Responses and private exception text must never be logged. The systemd unit `deploy/systemd/javmeta-media-worker.service` is installed by the existing deployment script; only QA/owner performs deployment.

## Settings

All media settings appear automatically under **Cài đặt → Xử lý media**. Defaults are in `config/javmeta_media.php`, with no environment overrides: API URL, output folder, max parallel jobs, max attempts, lease seconds, job timeout, poll interval, thumbnail interval, scene threshold and ffmpeg threads. ffmpeg uses `nice -n 10` and bounded decoder/filter threads. Scene boundaries use ffmpeg `select=gt(scene,T),showinfo`; each interval receives its own thumbnail.

## Verification

```sh
/home/joos/Workspaces/scripts/docker/runtime php@8.5 php vendor/bin/phpunit tests/Feature/Media
/home/joos/Workspaces/scripts/docker/runtime media python -m pytest -c tools/media-worker/pytest.ini tools/media-worker/tests
/home/joos/Workspaces/scripts/docker/runtime node node ui-tests/render-smoke.mjs
```

The shared runtime initially lacked pytest. Installed pytest 9.1.1 once with `runtime media python -m pip install pytest`; this is a runtime prerequisite, never a per-job/build installation. Generated red/blue video verifies preview files, a scene cut at 2 seconds and unchanged input bytes. No catalog-wide scan, real library access or live migration is needed for these tests.

## Speech recognition (JOOXCR-227)

Queue `transcribe` using the Library's **Phụ đề** button or `media:enqueue transcribe --file=…`. `media.whisper_model` defaults to `large-v3`. faster-whisper uses CUDA, float16, automatic language detection and VAD. It decodes audio locally and writes SRT, escaped WebVTT and a timed JSON transcript in the job's output directory. The worker returns detected language, segment count, elapsed seconds and real-time factor (wall seconds / source duration). Silence fails clearly rather than producing an empty subtitle track. A local model failure never calls a cloud service.

Each job runs in its own process group, bounded by the smaller of the configured job timeout and lease minus ten seconds. A timeout kills the whole group, including ffmpeg subprocesses. This also releases GPU memory between jobs. The generated-media end-to-end script is `runtime media bash tools/media-worker/e2e.sh`; it prints PASS/FAIL for previews, scene cuts and GPU transcription. Recognition unit tests use a fake engine and verify VAD, language, timing and both file formats.

Measured on Workstation / RTX 4070 Ti SUPER (16,376 MiB), faster-whisper 1.1.1, CUDA float16, `large-v3`, generated English espeak audio:

| Run | Duration | Wall time | RTF | Quality |
|---|---:|---:|---:|---|
| Initial model fetch/load, concurrent validation | 4.642 s | 75.817 s | 16.334 | 0 word errors on the synthetic reference |
| Cached model, sequential fresh worker process | 4.642 s | 4.987 s | 1.074 | 0 word errors; 2 timed segments |
| Generated video E2E, GPU contention | 20 s | 19.798 s | 0.990 | detected English, both subtitle formats |

Sequential GPU memory was 7,476 MiB before loading and 11,448 MiB after recognition (about 3,972 MiB additional). `large-v3` fits this measured workload, so it remains the default, with max parallel jobs = 1. This includes model startup in every fresh worker process; it is not steady-state model throughput. These are synthetic English checks, **not Japanese quality measurements**; no Japanese corpus was supplied and no quality promise is made for Japanese.
