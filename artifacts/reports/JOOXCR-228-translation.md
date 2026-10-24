# Translation validation

Machine: Workstation (WSL). `runtime media python -m pytest -c tools/media-worker/pytest.ini tools/media-worker/tests -q`: 17 PASS. Includes cue batching/timestamps, malformed JSON retries, public URL refusal and real loopback HTTP stub writing all four EN/VI subtitle files.

`runtime php@8.5 php vendor/bin/phpunit tests/Feature/Media`: 9 tests / 45 assertions PASS, including whisper and translation ingestion plus invalid path refusal. Pint and UI smoke PASS.

`runtime media bash tools/media-worker/e2e.sh`: PASS thumbnails, PASS scenes, PASS transcribe (GPU, 5.409 seconds on generated 20 s video, RTF 0.270), PASS translate (local HTTP stub). This checks formats/contracts, not real translation quality. All media is generated; no live data, deployment or merge.
