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

## Subtitle translation (JOOXCR-228)

Queue `translate` only after a Whisper SRT exists. The worker reads the original SRT from the output folder, translates batches of `media.translate_batch_segments` cues into English and Vietnamese, then writes `.en.srt/.en.vtt` and `.vi.srt/.vi.vtt` under its own output directory. IDs and timestamps come from the original transcript, never from the LLM. Missing/extra/reordered IDs, empty output and malformed JSON are retried once then fail.

`media.translate_model` defaults to `qwen2.5:7b`; `media.ollama_timeout_sec` bounds each request. The worker receives the existing `ai.ollama_url` setting from the config API. Only loopback, RFC1918 and IPv6 local addresses are accepted; HTTP redirects and environment proxies are disabled. Failure never falls back to cloud. The E2E script now includes translation through a loopback HTTP Ollama stub and checks all four output files. This tests the contract and format; translation quality on real content requires an owner-provided quality set.

## Scene descriptions and search (JOOXCR-225, JOOXCR-226)

Queue `describe` after scene extraction. `media.vision_model` defaults to `qwen3-vl:8b` on the configured local Ollama. Each thumbnail produces JSON tags, a sentence and confidence in [0,1]. Malformed JSON retries once, then produces an explicit zero-confidence fallback; an unavailable local model fails rather than contacting cloud.

Only the worker completion endpoint ingests scene rows/descriptions. It validates scene ownership, output paths and bounded fields. Descriptions are embedded with `EmbedderClient`; `ElasticsearchHttpClient` lazily creates `media.scenes_index` with `media.embedding_dimensions` (384 by default) and indexes descriptions. An unavailable vector service leaves `embedding_indexed_at` null. Retry without rerunning vision using `runtime php@8.5 php artisan media:index-scenes`.

`GET /api/v1/scenes/search?q=red%20room&per_page=20` requires the movies scope. It returns movie code, library file, start/end, signed thumbnail (null when file serving is disabled), description and score. Keyword fallback uses `lower(description) LIKE` with escaped wildcards and a PostgreSQL GIN trigram index; minimum query length is three. Searches join library files by primary key and exclude missing files. They do not join or scan movie/performer catalogs. Both keyword and meaning modes in the search UI show the Cảnh section; MCP exposes `search_scenes` with matching query/limit validation.

## Player (JOOXCR-229)

`#/player/:code` loads the owner's `/ui-me/player/:code` endpoint. The movie page shows **Phát** for codes present on disk. The endpoint uses an indexed `(code, missing_at)` branch (or the file primary key), returns signed video/VTT links and ordered scene markers, and validates that the chosen file belongs to the code. It never serves raw paths to the player. Unknown/missing files are refused; disabled serving and unreadable subtitles have explicit UI states.

The player uses native `<video controls>`, original/EN/VI subtitle choices and clickable timeline scene markers. `?file=id&t=seconds` selects a file and seeks after metadata loads. Subtitle serving explicitly returns `text/vtt`. The existing signed file route supports byte ranges (206) and checks the home network and signature on every request, including video and subtitles. Reload the player to renew expired links. Playback depends on the browser's native codec support; transcoding is outside this feature.

## Explicit stream capture (JOOXCR-222)

**Ghi luồng** on `#/media` accepts one page or playlist URL only when `capture.enabled` is on (default **off**). Only the authenticated owner can enqueue it via the CSRF-protected UI route; there is no public capture enqueue API, MCP tool, automatic crawler hook or bulk capture command. Turning the switch off also refuses queued captures at claim time. Every explicit action audits the job ID, never the private URL.

The worker fetches anonymously (no cookies, credentials or environment proxies), finds an HLS/DASH URL on a page when needed, and inspects manifests before ffmpeg starts. It recursively validates HLS variants/audio playlists, rewrites every URI to a checked HTTP(S) resource or private vetted local manifest/key, and rejects loops/oversized inputs. All FairPlay/Widevine/PlayReady/SAMPLE-AES markers, non-identity encryption, unsupported methods and DASH ContentProtection are refused with a clear job error. Plain AES-128 is accepted only when its key is openly fetched as exactly 16 bytes; keys are private temporary files removed on exit. DASH external manifest links are refused; relative BaseURL/template inheritance is resolved before capture. ffmpeg receives a vetted private manifest and remuxes with `-c copy` to one MP4, without transcoding or DRM bypass. Output is available through a signed **Mở tệp đã ghi** link when file serving is enabled.

Settings `capture.max_duration_sec`, `max_bytes`, `max_playlists`, `max_manifest_bytes` and `http_timeout_sec` bound resource use; the existing media job deadline also kills stuck ffmpeg process groups. Capture errors crossing the API are fixed safe classifications, not raw URLs, keys, model responses or subprocess output.

The adopted content policy is enforced before enqueue and again before claim: suspicious library path/code/title/genre metadata, known performer age below 18 at release, and suspicious capture URL/page/manifest metadata are refused. Worker checks independently refuse suspicious metadata and any output folder inside configured read-only library roots. This conservative metadata check cannot establish an unknown person's age from pixels; it never authorizes content that metadata flags.

Generated-media integration tests serve playlists through a loopback Python HTTP server: plain HLS, openly keyed AES-128 HLS and unprotected DASH produce MP4 output; protected fixtures and nested protected variants are refused before output. No GABL integration, real library disk, browser login bypass or cloud API is used.
