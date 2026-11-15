# Decision and implementation audit: node enrollment (JOOXCR-383, JOOXCR-382)

Status: **Design proposal retained; implementation evidence is partial.** The source audit below was recorded on 2026-11-12. It does not certify a clean installation or a completed security review.

## Implementation evidence audit (2026-11-12)

This is a local source-and-test review. No VM was enrolled, no production environment was accessed, and no full clean installation or security assessment was performed.

| Acceptance area | Evidence | Assessment |
| --- | --- | --- |
| Guided add flow and generated command | `resources/views/operations/dashboard.blade.php:114-129, 223-265`; `app/Http/Controllers/Operations/NodeController.php:14-28`; `app/Services/Nodes/NodeEnrollmentService.php:27-46`; `tests/Feature/Nodes/NodeEnrollmentEndpointsTest.php:183-193` | The super-admin Command Center has a name/role form, brief steps, expiry text, a generated one-time command, and copy support. The feature test checks that the form is rendered. This is source/test evidence, not a browser walkthrough. |
| Access control and validation | `routes/web.php:70-74`; `app/Http/Requests/Nodes/CreateNodeEnrollmentRequest.php:10-30`; `tests/Feature/Nodes/NodeEnrollmentEndpointsTest.php:25-67` | Create/list routes are inside the authenticated super-administrator group. Node names and roles are validated; tests cover unauthenticated, non-owner, and invalid-input responses. The redeem endpoint is intentionally public and uses the token as its credential. |
| Token generation, storage, expiry, and single use | `app/Services/Nodes/NodeEnrollmentService.php:27-58, 76-79`; `app/Repositories/NodeEnrollmentRepository.php:47-66`; `app/Models/NodeEnrollment.php:9-18, 31-49`; `tests/Feature/Nodes/NodeEnrollmentServiceTest.php:35-49, 59-64, 66-90` | Source generates 32 random bytes, stores only a SHA-256 hash, applies the configured expiry (30 minutes by default, with a five-minute floor), and redeems with one conditional update. Tests corroborate hash-only storage, expiry, and one successful redemption. |
| Expired, reused, wrong, and malformed token responses | `app/Http/Requests/Nodes/RedeemNodeEnrollmentRequest.php:18-35`; `app/Http/Controllers/Nodes/NodeEnrollmentController.php:25-38`; `tests/Feature/Nodes/NodeEnrollmentServiceTest.php:106-114`; `tests/Feature/Nodes/NodeEnrollmentEndpointsTest.php:115-157` | Missing or malformed token returns 401. Unknown, wrong-name, used, or expired redemption reaches the same generic 404 response; invalid node-name input returns 422; the route is limited to six requests per minute. Reuse is asserted at the HTTP endpoint. Expiry is asserted at the service layer, but there is no direct expired-token HTTP test. |
| Install command and complete installation | `deploy/join-node.sh:40-58, 66-100`; `tests/Shell/join-node.test.sh:14-43`; Jira [JOOXCR-386](https://jooservices.atlassian.net/browse/JOOXCR-386) | The script checks token/name format, HTTPS, root, supported Ubuntu, and a non-redemptive plan mode, then redeems and invokes the normal installer. Its shell test covers refusals and plan mode, not a successful install. JOOXCR-386 is In Progress as checked on 2026-11-12; its description records the rehearsal stopping in the installer because MongoDB/Redis/Elasticsearch credentials and browser-service URLs were missing. Its acceptance still owns a clean Ubuntu 24.04 rehearsal through migration and worker startup. A full install is therefore unproven and remains in JOOXCR-386. |
| Joined versus online state | `app/Services/Nodes/NodeEnrollmentService.php:61-73`; `app/Http/Controllers/Operations/NodeController.php:14-17`; `resources/views/operations/dashboard.blade.php:224-240`; `lang/en/operations.php:117-129`; `lang/vi/operations.php:117-129` | The `/ops/nodes` response lists enrollment records and their `pending`, `used`, or `expired` status. `used` is set when the token is redeemed, and the UI displays its `used_at`; neither means installation finished or the node is online. The UI's next-step text explicitly uses the `used` label. Separately, `app/Services/Workers/HorizonLayoutSnapshot.php:21-24, 57-69` writes a worker-layout snapshot with a 180-second key TTL, and `app/Services/Crawl/WorkerHeartbeat.php:12-17, 30-61` tracks worker-process heartbeats. The enrollment list does not read either signal or associate them with an enrollment. No online/offline state is currently evidenced in this UI. |
| Observability and secret handling evidence | `app/Listeners/Nodes/NodeEnrollmentSubscriber.php:12-33`; `app/Http/Controllers/Nodes/NodeEnrollmentController.php:32-38`; `deploy/join-node.sh:66-89`; `tests/Feature/Nodes/NodeEnrollmentEndpointsTest.php:168-181` | Enrollment events log identifiers, node/role and IP, not the token or bundle. Redeem responses use `no-store`; the script uses a private temporary directory, restricts the answers file, and removes the response file. A feature test checks that the token and `APP_KEY` are absent from application logs. This does not prove that reverse-proxy, shell, process-list, or host logs are clear. |
| Localization and compatibility | `resources/views/operations/dashboard.blade.php:115-129, 136-139`; `lang/en/operations.php:115-132`; `lang/vi/operations.php:115-132`; `tests/Feature/Nodes/NodeEnrollmentEndpointsTest.php:69-113` | The page uses the existing English/Vietnamese operation strings. Locale tests cover validation messages, not every node-flow string. This audit changes documentation only; there is no migration or runtime behavior change. |

### Security review gaps

Source evidence shows useful controls, but this ticket did not complete a security review and makes no “secure” or “reviewed” claim.

- The enrollment token is a bearer credential returned inside the generated shell command. Review its exposure through terminal history, process arguments, clipboard retention, and support captures; existing tests do not cover those surfaces (`NodeEnrollmentService.php:39-45`; `dashboard.blade.php:246-263`).
- The join bundle contains application and service credentials (`app/Services/Nodes/JoinBundleFactory.php:14-32, 35-105`). The script refuses plain HTTP, but source inspection does not establish that the public redeem route is HTTPS-only at the server/proxy boundary or that intermediary access/error logs redact bodies (`NodeEnrollmentController.php:25-38`; `deploy/join-node.sh:42-45`). Verify those deployment controls before treating transport and logging as reviewed.
- The installer clones the configured repository and checks out the configured release ref without a signature or immutable commit check in this script (`deploy/join-node.sh:91-100`). Review release-ref integrity and the cleanup/rollback behavior after a partial install.
- Existing feature and shell tests do not establish a successful install, browser behavior, or an end-to-end security result. Do not infer those outcomes from the source audit.

These are review items, not confirmed exploit findings. This document assigns no severity because exploitability and deployment controls were not assessed.

## Bounded follow-ups

1. Keep the clean authenticated-service installation rehearsal, including migration and worker startup, in JOOXCR-386. Do not duplicate that rehearsal in a new ticket.
2. Close JOOXCR-383's online-state acceptance separately from token redemption: define the node-level liveness signal and freshness boundary, connect it to the enrollment/node identity, and show distinct enrollment, joined, online, and stale/offline states. Add an offline-after-redemption test and a browser-flow test. The current 180-second Horizon snapshot TTL and worker-heartbeat threshold are implementation signals, not an approved product status contract.
3. Complete a focused security review of bearer-command exposure, HTTPS enforcement and intermediary logging, release-ref integrity, and partial-install cleanup. Record deployment evidence and any confirmed findings before claiming the security acceptance criterion is met.

## Original problem statement (2026-11-05)

Before this proposal, installing a worker required `deploy/install.sh` with a private JSON file containing database, Redis, MongoDB, Elasticsearch, OpenObserve, application-key, release-ref, and unit settings. The Command Center did not expose a guided enrollment page. The implementation audit above supersedes those statements about the current UI; it does not establish a completed install or online state.

## Proposed enrollment flow and invariants

1. A super administrator creates a named worker enrollment. The token is random, single-use, expires after 30 minutes by default, and is shown only in the returned command.
2. On the new VM, the user runs the HTTPS join command. The served script can be inspected before execution.
3. The script redeems the token over HTTPS. The service checks its hash, node name, expiry and unused state atomically, marks it used, and returns the installer bundle once. Unknown, wrong-node, used and expired tokens share a generic not-found response.
4. The script writes the response under a private temporary directory, restricts the answers file, and invokes the existing worker installer.
5. Token redemption is recorded as enrollment use. It is not evidence of successful installation or online status; those require separate, verified signals.

Security and operations rules retained from the proposal:

- Only a super administrator may create and list enrollments. Redemption requires the bearer token and is rate limited.
- The worker bundle must not contain owner-only credentials, the Telegram token, or the Sentry DSN.
- Tokens, bundles, and the application key must not be logged, placed in URLs, or returned more than once.
- The join script requires HTTPS, root privileges, and Ubuntu 24.04 or newer; plan mode must not redeem a token.
- Removing a node is a separate operational action and must not delete application data.

## Architecture and existing tests

The implemented enrollment path follows FormRequest → controller → `NodeEnrollmentService` → `NodeEnrollmentRepository` → `NodeEnrollment`, with `EnrollmentGrant` and `JoinBundle` data objects. Enrollment events are handled by one `NodeEnrollmentSubscriber` (`app/Services/Nodes/NodeEnrollmentService.php:18-25`; `app/Repositories/NodeEnrollmentRepository.php:19-31`; `app/Listeners/Nodes/NodeEnrollmentSubscriber.php:12-41`).

Existing evidence includes service and endpoint feature tests (`tests/Feature/Nodes/NodeEnrollmentServiceTest.php`, `tests/Feature/Nodes/NodeEnrollmentEndpointsTest.php`) and shell refusals/plan tests (`tests/Shell/join-node.test.sh`). They do not replace the clean install rehearsal owned by JOOXCR-386 or the security review listed above.
