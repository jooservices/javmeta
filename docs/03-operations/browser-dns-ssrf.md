# Browser DNS and SSRF policy

## Symptom

Sentry groups such as `Fetch blocked by SSRF policy for URL [https://en.jable.tv/...]`
and `[https://missav.ws/...]` with fingerprint `['ssrf_blocked', '<source>']`.

## Cause

The CrawlerX browser sidecar resolves the target, then refuses to dial blocked
addresses (loopback, RFC1918, link-local, CGNAT, benchmark `198.18.0.0/15`,
metadata, ULA, and related special-use ranges).

Two production patterns were observed:

1. **Decoy DNS answers** next to real Cloudflare A records
   (`127.0.0.1`, `0.0.0.0`, plus `104.20.*` / `172.66.*`). Older guards treated
   "any private answer" as a hard block. CrawlerX now drops decoys and pins only
   the remaining public addresses (JOOXCR-399 / JOOXCR-363).
2. **Sinkhole-only answers** on the Playwright host (`192.168.10.19`) when DHCP
   DNS (`116.97.90.124` / `116.97.90.127`) or on-path filtering returns only
   loopback/unspecified for adult hosts. Worker nodes already ship
   `/etc/systemd/resolved.conf.d/xcrawler-dns.conf` forcing `1.1.1.1` /
   `1.0.0.1` with `Domains=~.`; **vm-tools did not**. A host with no public pin
   remains blocked after the decoy fix — that is intentional.

## Local check

On the browser host (and after deploy, on app nodes):

```bash
php artisan crawler:dns-ssrf-check
php artisan crawler:dns-ssrf-check --host=en.jable.tv --host=missav.ws
```

`Safe for SSRF guard = no` with reason `no_public_address` means the resolver
still returns only blocked addresses for that host.

## Production remediation (do not apply from this ticket)

Minimal change on `192.168.10.19` (vm-tools / `crawlerx-fetch`):

1. Install the same resolved drop-in used on xcrawler workers:

   ```ini
   # /etc/systemd/resolved.conf.d/xcrawler-dns.conf
   [Resolve]
   DNS=1.1.1.1 1.0.0.1
   FallbackDNS=8.8.8.8
   Domains=~.
   ```

2. `sudo systemctl restart systemd-resolved` then flush the stub cache
   (`resolvectl flush-caches`).
3. Verify with `resolvectl query en.jable.tv` / `missav.ws` and
   `node -e "…dns.lookup…"`, expecting at least one public Cloudflare address.
4. Deploy the CrawlerX `scripts/ssrf-guard.mjs` (and related fetch scripts) that
   drop decoys and emit `ssrfReason` / `ssrfAddress`, then restart
   `crawlerx-fetch` only after the DNS check passes.
5. Re-run `php artisan crawler:dns-ssrf-check` from a node that uses that
   browser host; requeue terminal `ssrf_blocked` rows only after both checks
   pass.

### Rollback

1. Remove `/etc/systemd/resolved.conf.d/xcrawler-dns.conf` on vm-tools.
2. `sudo systemctl restart systemd-resolved` and flush caches.
3. Restore the previous `crawlerx-fetch` scripts from the prior release tag and
   restart `crawlerx-fetch`.

If UDP/53 to public resolvers is still rewritten for specific hosts after the
drop-in, escalate to router/ISP DNS filtering (DoH/DoT or a non-intercepted
resolver path). Do not weaken the SSRF guard to dial private addresses.
