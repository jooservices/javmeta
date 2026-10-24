# JOOXCR-258 resumed validation

Workstation, 2026-10-25. Existing work and PRs were retained. No deployment, merge, live migration or real-library access.

Final capture stage adds explicit owner-only capture, database Settings (off by default), anonymous HLS/DASH discovery, recursive DRM refusal, open-key AES-128 support, MP4 remuxing and signed output links. Content metadata is checked at enqueue and claim; output stays outside library roots. Safe worker error classifications do not expose URLs, keys or subprocess output. Scene regeneration invalidates obsolete descriptions, and library file controls paginate.

Validation through `/home/joos/Workspaces/scripts/docker/runtime`:

- `php@8.5 php vendor/bin/phpunit`: 1024 tests / 8230 assertions, PASS, 66.951 seconds.
- Subsequently added two regressions for worker capture configuration and known performer age at release. Focused final Media/OpenAPI/MCP suite: 31 tests / 142 assertions, PASS; OpenAPI contract rechecked after schema documentation: 2 tests / 5 assertions, PASS.
- `media python -m pytest -c tools/media-worker/pytest.ini tools/media-worker/tests -q`: 34 passed, 2.30 seconds. Includes generated plain HLS, openly keyed AES-128 HLS and DASH capture via a local HTTP server, output duration and DRM refusal.
- `media bash tools/media-worker/e2e.sh`: PASS thumbnails, scenes, GPU transcribe and translation via local HTTP stub. Transcription wall time 5.858 seconds / 20-second source, RTF 0.293. This synthetic video includes silence; this is not a Japanese quality measurement.
- `node node ui-tests/render-smoke.mjs`: 22 views, all assertions PASS; includes capture enabled/disabled/error/output states and width 375.
- `php@8.5 php vendor/bin/pint --dirty`: PASS, 12 files.
- Scoped PHPStan (Media services/controllers, UiMediaController, MediaWorkerKeyCommand), with dummy APP_KEY and SQLite in-memory supplied inside runtime via `env`: no errors.
- `php@8.5 bash tests/Shell/staging.test.sh`: PASS deployment order, guards, dry run and rollback; no deployment executed.
- Gitleaks existing five pipeline commits: no leaks; staged final diff scanned separately before commit. `git diff --check`: clean.

Review order: #142 framework/224, #144 transcription/227, #146 translation/228, #147 description/search/225+226, #148 player/229, final capture PR/222. Each targets develop and includes predecessor commits; review/merge in order to avoid duplicate changes.

Remaining evidence limits: real Dev VM browser playback E2E and PostgreSQL 26,000-row query benchmarks were blocked in the previous session by SSH configuration and shared database provisioning (see framework report and memory lesson 10303). No real disk is mounted. Local vision quality is tested with fake model responses; the prior real generated-frame Ollama call timed out at 180 seconds. These are QA follow-ups, not claimed passed. No Sonar configuration is present in this checkout.

GitHub CLI identity checks pass as soulevilx. SSH configuration resolution still fails; HTTPS with task-scoped gh credential helper works without changing shared authentication, SSH or global Git configuration.
