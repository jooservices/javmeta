# Decision: adding a node from the Command Center (JOOXCR-383, JOOXCR-382)

Status: **PROPOSED** (2026-11-05, owner mandate "a non-technical user can add a node, they only need a VM").

## Problem

Installing a node today needs `deploy/install.sh` with a private JSON file holding about 70 answers (database, Redis, Mongo,
Elasticsearch and OpenObserve credentials, `APP_KEY`, the release ref, unit names). Only an operator can fill it in. The
Command Center (`/ops`) has no node page at all.

## Flow

1. **Create** — a super administrator opens *Command Center → Nodes → Add node*, picks a name and the role (`worker`), and gets a
   one-line command and an expiry time. The Command Center stores only the SHA-256 of a 256-bit random token (single use,
   30 minutes). The token is shown once.
2. **Run** — on a fresh Ubuntu 24.04 VM the user pastes `curl -fsSL https://<cc>/node/join.sh | sudo bash -s -- --token <token>`.
   The page also offers the script as a download so it can be read first. The script needs nothing but `curl`, `sudo` and `jq`.
3. **Redeem** — the script sends the token and the chosen node name to `POST /node/enroll` over HTTPS. The Command Center checks
   the hash, expiry and unused state in one atomic update, marks the token used, writes an audit event and answers with the join
   bundle (the installer answers for a worker: service endpoints and credentials, `APP_KEY`, release ref). Any later use of the
   token answers 404 with no detail.
4. **Install** — the script writes the bundle to a `0600` file and runs the existing wizard (`deploy/install.sh --role worker
   --non-interactive --apply`), so there is one install path, not two.
5. **Appear** — the node registers in the Horizon layout registry; the Nodes page shows it as *joined* with its release,
   workers and last sample.

## Rules

- Only super administrators create tokens. Redeeming needs only the token and is rate limited (6 per minute per IP).
- The bundle never contains the owner's credentials, the Telegram token or the Sentry DSN; it contains what a worker needs.
- Tokens, bundles and the `APP_KEY` are never logged, never put in URLs and never returned twice.
- The script refuses plain HTTP, refuses to run as a non-root user without `sudo`, and refuses a non-Ubuntu 24.04 host.
- Removing a node is a separate step: the page shows how to stop its units; it does not delete data.

## Layers (owner's convention)

FormRequest → controller → `NodeEnrollmentService` → `NodeEnrollmentRepository` (jooservices/laravel-repository) →
`NodeEnrollment` model. The service returns `EnrollmentGrant` and `JoinBundle` DTOs. Events (`NodeEnrollmentCreated`,
`NodeEnrolled`) are dispatched from the service and handled by one `NodeEnrollmentSubscriber`.

## Tests

Unit tests for the service (expiry, single use, hash only, bundle contents without forbidden keys); feature tests for both
endpoints (auth, validation, throttle, 404 on reuse); a shell test for the script's refusals; a rehearsal that installs a worker
into a clean Ubuntu 24.04 container on WSL (never attached to ESXi) against the QA service stack (`docker/qa`).
