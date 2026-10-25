# PR #150 review remediation

2026-10-25, existing JOOXCR-222-stream-capture worktree/branch. All eight original CodeRabbit findings confirmed and fixed; none rebutted. No deployment, merge, live-data access or configuration change.

- Enqueue catches validation and HTTP refusals per file and prints queued/skipped counts. Tests cover a refused first row and a file disappearing after batch selection.
- Library/search use function replacements, preserving dollar sequences in escaped paths/descriptions; UI smoke covers both.
- Capture transport rejects nonpublic DNS answers, mixed answers, metadata VIPs, IPv6 transition addresses and unsafe URL syntax; each connection pins its checked sockaddr and preserves HTTP Host/TLS identity. Redirects repeat validation. Temporary per-job relay applies the same transport to ffmpeg segments, including Range requests and DASH templates. No production opt-out; the generated-media test allows only its own fixture server.
- HTML is identified separately from actual HLS/DASH; player CSS/JS (including MPD strings) is excluded from minors/DRM scanning. Discovered manifests remain checked.
- Python policy uses ASCII-alphanumeric lookarounds, including underscore/encoded separator regressions.
- Translation validates object/row shapes before access and retries malformed model output.
- Completion HTTP 422 immediately calls fail with the original claim and a safe fixed message; 409/500 still propagate.

## Verification

All language commands used the shared runtime wrapper from this worktree.

| Command | Result |
| --- | --- |
| `runtime php@8.5 php vendor/bin/phpunit tests/Feature/Media` | 24 tests, 132 assertions, PASS |
| `runtime php@8.5 php vendor/bin/phpunit` | 1040 tests, 8666 assertions, PASS, 63.631 seconds |
| `runtime media python -m pytest -c tools/media-worker/pytest.ini tools/media-worker/tests -q` | 81 passed, 4.02 seconds; includes real ffmpeg HLS, open-key AES-128 and DASH |
| `runtime node node ui-tests/render-smoke.mjs` | 22 views plus media/player/capture/literal-dollar assertions, PASS; width 375 fixtures |
| `docker/ci/run composer lint` | PASS (Pint, PHPCS, PHPMD, PHPStan, CS Fixer), explicitly requested for this review task |
| New regression tests against original modules/classes copied from HEAD under `.tmp/pr150-before` | 11 Python failures and 2 enqueue exceptions reproduced; current code passes |
| `git diff --check` | PASS |

Ruff is unavailable in the existing media runtime; no package/container was installed. No Sonar configuration in this checkout. No new API routes or response shapes. The relay adds bounded streaming I/O only to explicit capture jobs, not catalog endpoints. No staging performance claims or browser playback claims are made by these tests.

## Self-review

Security and performance groups activated by HTTP/resource/error-handling changes. Review of the final diff follows the three-round checklist; existing branch-wide dependency audit is outside this remediation.

| Item | Verdict | Evidence |
| --- | --- | --- |
| A1 | PASS | Eight findings mapped above |
| A2 | PASS | No schema/API changes; public-only capture restriction documented |
| A3 | PASS | Only review-related code, tests, docs/report |
| A4 | PASS | Diff inspected; no debug output |
| F1 | PASS | Success/failure/edge tests above |
| F2 | PASS | Original-code failure reproduction above; dollar regression fixtures |
| F3 | PASS | Command, worker step, translator and real ffmpeg seams |
| F4 | PASS | Synthetic media and deliberate boundary-string fixtures |
| F5 | PASS | Subject-named tests |
| B1 | PASS | Eight confirmed behaviors fixed |
| B2 | PASS | Redirect cap, translation two-attempt loop, enqueue continuation |
| B3 | PASS | Nonobjects/null, encoded separators, mixed DNS, refusal states |
| B4 | PASS | 422 fail path; other HTTP errors propagate |
| B5 | PASS | Full PHP/Python/UI checks pass |
| B6 | PASS | DNS pinned per connection; per-job relay; existing claim token |
| B7 | PASS | Existing queue transaction/retry behavior retained |
| E1 | PASS | Existing English identifiers/module conventions |
| E2 | PASS | Discovery and relay share public transport |
| E3 | PASS | Transport separated from manifest rewriting |
| E4 | PASS | Security rationale comments |
| E5 | PASS | Obsolete redirect handler removed |
| E6 | PASS | Policy/transport/capture boundaries retained |
| E7 | PASS | Relay required to cover ffmpeg redirects/rebinding |
| E8 | PASS | Scoped transport module and regression tests |
| C1 | N/A | No SQL changes |
| C2 | PASS | No source credentials/logged upstream errors |
| C3 | PASS | Anonymous upstream; relay bound to loopback with random route tokens |
| C4 | N/A | Existing owner/worker authorization unchanged |
| C5 | PASS | Existing escaping retained; replacement metacharacters rendered literally |
| C6 | PASS | Every DNS answer and redirect checked, socket pinned |
| C7 | PASS | Safe fixed worker failure message; relay logging suppressed |
| C8 | N/A | Existing private manifest/output paths unchanged |
| C9 | N/A | No dependencies added/updated; no audit claim |
| C10 | PASS | Default TLS verification; random relay route tokens |
| C11 | PASS | DNS/redirect/rebinding and ffmpeg relay regressions |
| D1 | N/A | Existing per-file enqueue queries unchanged |
| D2 | PASS | Existing lazyById batches and capture settings retained |
| D3 | PASS | Connections/sockets closed in finally; relay and temporary directory scoped |
| D4 | PASS | Explicit synchronous media job, not API hot path |
| D5 | PASS | Chunked relay reads bounded by existing capture byte setting |
| D6 | PASS | UI replacement only; no extra requests/renders |
| F6 | PASS | Operations and UI docs updated |

Critical: 0; Required: 0; Optional: 0; Nit: 0. Rounds 1, 2 and 3 PASS for this remediation. QA retains deployment/merge authority.
