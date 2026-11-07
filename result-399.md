# JOOXCR-399 results — Fetch blocked by SSRF policy

Machine: WSL workstation. Read-only probes on Sentry `192.168.10.17`, xcrawler nodes `.20/.21/.22`, and vm-tools `.19`. No production changes, restarts, or pushes.

Related product ticket: **JOOXCR-363** (decoy DNS). Commit key for readiness: **JOOXCR-399**.

## Root cause (verified)

1. **Decoy DNS answers (code).** Authoritative / on-path answers for `en.jable.tv` often include `127.0.0.1` and `0.0.0.0` next to real Cloudflare A records (`104.20.*`, `172.66.*`). The CrawlerX browser SSRF guard used `addresses.some(isBlocked)` and rejected the whole host if any answer was non-public. The egress proxy already pinned resolved addresses; the guard never filtered decoys before pinning.
2. **Sinkhole DNS on the browser host (environment).** On `192.168.10.19` (`crawlerx-fetch`), Node `dns.lookup(..., { all: true })` for `en.jable.tv` / `missav.ws` returned only loopback / unspecified (`127.0.0.1`, `::1`, `0.0.0.0`, `7f00:1::`). Link DNS is DHCP `116.97.90.124` / `116.97.90.127`. Worker nodes ship `/etc/systemd/resolved.conf.d/xcrawler-dns.conf` (`1.1.1.1` / `1.0.0.1`, `Domains=~.`); **vm-tools does not**. With only sinkhole answers, the guard correctly stays closed even after the decoy fix.

Sentry (project `xcrawler`, groups 58 / 62): exception thrown in `FetchFallbackChain` via `HandlesCrawlQueueRow::markFailed`; fingerprint already `['ssrf_blocked', '<source>']` (JOOXCR-390). Extra/breadcrumbs did not include the blocked address — that gap is addressed in-repo.

## What was fixed

### CrawlerX (`worktree/JOOXCR-399-crawlerx-ssrf`, branch `JOOXCR-399-ssrf`)

- `scripts/ssrf-guard.mjs`: drop blocked decoys; require ≥1 public pin; enrich `SsrfBlockedError` with `reason` / `address` / `hostname`.
- Propagate `ssrfReason` / `ssrfAddress` through fetch-server / playwright / puppeteer JSON and into `CrawlFetchException` message via `FetchResultDto`.
- Docs/CHANGELOG updated.

**Tests:**  
`SSRF guard allows public Cloudflare addresses mixed with loopback decoy records`,  
`SSRF guard allows missav-style public answers and still drops unspecified decoys`,  
`SSRF guard blocks hosts whose DNS answers are only private or special-use`,  
`SSRF guard re-checks a second resolution in the DNS-rebinding style`,  
plus existing loopback/link-local/metadata/IPv4-mapped/proxy redirect coverage;  
`FetchStrategyContractTest::test_tc_ssrf_blocked_is_terminal_and_non_retryable` asserts reason/address in the message.

### Javmeta (`worktree/JOOXCR-399-ssrf`)

- `CrawlFailureReporter`: Sentry context `ssrf` + tag `ssrf_reason` from exception message; fingerprint unchanged (`error_code` + `source`).
- `SsrfDnsAnswerClassifier` + `BrowserDnsHealthProbe` + `crawler:dns-ssrf-check`.
- Ops doc: `docs/03-operations/browser-dns-ssrf.md`.

**Tests:**  
`SsrfDnsAnswerClassifierTest::*` (decoy jable/missav, sinkhole-only, 127/8, 10/8, 172.16/12, 192.168/16, 169.254/16 incl. metadata, CGNAT, 198.18/15, ::1, ULA, IPv4-mapped),  
`BrowserDnsHealthProbeTest::*`,  
`CrawlFailureReporterTest::test_ssrf_failures_attach_reason_and_address_context_without_changing_fingerprint`.

## What remains (production — proposal only)

1. Publish/release CrawlerX with the SSRF guard fix; bump `jooservices/crawlerx` in javmeta.
2. On **vm-tools `.19`**: install `xcrawler-dns.conf` (same as workers), restart `systemd-resolved`, flush caches; verify public pins with `resolvectl` / Node lookup; deploy new `crawlerx-fetch` scripts; restart `crawlerx-fetch` only after DNS is healthy.
3. Run `php artisan crawler:dns-ssrf-check` against the browser path; requeue terminal `ssrf_blocked` rows (JOOXCR-363 AC) only after checks pass.
4. Rollback: remove the resolved drop-in, restore previous fetch scripts, restart services (details in `docs/03-operations/browser-dns-ssrf.md`).

Do **not** weaken the guard to dial private addresses when no public pin exists.
