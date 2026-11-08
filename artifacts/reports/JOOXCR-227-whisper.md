# Local Whisper verification

Machine: Workstation (WSL), RTX 4070 Ti SUPER / 16,376 MiB, faster-whisper 1.1.1, CUDA float16, large-v3. Only espeak-generated speech and ffmpeg-generated video; no library disk or real content.

`runtime media python .tmp/measure-whisper.py` generated-reference recognition: “The red square moves across the screen. This is a local speech recognition test.” Output exactly matches the reference ignoring punctuation/case: WER 0, detected en, 2 timed segments. Audio duration 4.642 seconds. Cached sequential fresh-process recognition took 4.987 s (RTF 1.074); GPU usage 7476 -> 11448 MiB. Initial concurrent download/load run took 75.817 s (RTF 16.334), not a throughput estimate.

`runtime media bash tools/media-worker/e2e.sh`: PASS thumbnails (4), PASS scenes (2, boundary 10 s), PASS GPU transcribe on generated 20 s video. Recognition 19.798 s / RTF 0.990 under shared GPU contention. Model fits with max_parallel=1. No Japanese quality claim: synthetic English cannot establish Japanese accuracy.

`runtime media python -m pytest -c tools/media-worker/pytest.ini tools/media-worker/tests -q`: 10 PASS. `runtime php@8.5 php vendor/bin/phpunit tests/Feature/Media`: 8 tests / 42 assertions PASS. UI render smoke PASS. No merge/deploy.
