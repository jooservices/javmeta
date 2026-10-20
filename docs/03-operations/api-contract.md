# API contract

## Public endpoint contract

`tests/Feature/Api/PublicApiContractTest.php` exercises the public routes and the success and error behavior below.
Except for `GET /api/health`, the routes require an active key in the `X-API-Key` header. Missing, invalid, and revoked
keys return HTTP 401 with problem type `unauthorized`. Every protected route uses the key's requests-per-minute limit;
an exceeded limit returns HTTP 429 with type `rate_limited` and a `Retry-After` header.

Problem responses use `application/problem+json` and include `type`, `title`, `status`, and a human-readable `detail`.
Invalid filters return HTTP 400 with type `invalid_filter`, a `detail` message, and an `errors` map.

| Route | Success contract | Documented errors |
|---|---|---|
| `GET /api/v1/movies/{code}` | Returns one movie resource in `data`. Codes are normalized before lookup; lowercase, omitted hyphens, surrounding spaces, and omitted leading zeros resolve to the stored display code. | `401 unauthorized`, `404 movie_not_found`, `429 rate_limited` |
| `GET /api/v1/movies` | Returns movie summaries in `data` and pagination metadata in `meta.pagination`. Keyword search is the default; `q` matches code, Japanese or English title, and performer name. Add `semantic=true` with `q` to search by meaning through the embedder and Elasticsearch. Semantic mode accepts only `q` and `per_page` and does not use cursors. If the embedder is unavailable, semantic search falls back to keyword search. If search is switched off, the database search remains available. | `400 invalid_filter` for invalid filters, `401 unauthorized`, `429 rate_limited`, `503 dependency_unavailable` when Elasticsearch is unavailable |
| `POST /api/v1/movies:bulk` | Accepts a `codes` array and returns one `found` or `not_found` entry for each unique normalized code, with the movie resource or `null`. | `400 invalid_filter` for an invalid `codes` value, `401 unauthorized`, `413 bulk_limit_exceeded` above 100 codes, `429 rate_limited` |
| `GET /api/v1/performers` | Returns performer summaries in `data` and pagination metadata in `meta.pagination`. | `400 invalid_filter` for invalid filters, `401 unauthorized`, `429 rate_limited` |
| `GET /api/v1/performers/{id}` | Returns one performer resource in `data`; `id` accepts the numeric identifier or UUID. | `401 unauthorized`, `404 performer_not_found`, `429 rate_limited` |
| `GET /api/v1/meta/genres` | Returns a sorted, deduplicated array containing the union of raw genre labels, falling back to normalized labels when no raw label exists. | `401 unauthorized`, `429 rate_limited` |
| `GET /api/v1/auth/verify` | Returns `data.status: "ok"` for an active key. | `401 unauthorized`, `429 rate_limited` |
| `GET /api/health` | Public health payload reports `ok`, `degraded`, or `down`; `ok` and `degraded` return HTTP 200, while `down` returns HTTP 503. | None |

`GET /api/v1/movies/{code}`, `GET /api/v1/performers/{id}` and the MCP tools `get_movie` and `get_performer`
return the same resources, so the field sets below apply to REST and MCP alike. Missing data is `null` (or an
empty array); keys are never omitted. Changes are additive: existing keys keep their name and type.
`tests/Feature/Api/FieldCatalogContractTest.php` fails when this document and the responses differ.

## Movie detail

| Field | Type | Meaning |
|---|---|---|
| `uuid` | string | Stable movie identifier |
| `code` | string | Display code, for example `ABC-123` |
| `codes` | array | Every known code with its `kind` |
| `title_jp`, `title_en` | string or null | Titles |
| `description` | string or null | Synopsis |
| `performers` | array | Performer summaries |
| `directors` | array of string | Directors from the credits |
| `cover_url`, `cover_thumb_url` | string or null | Cover image and thumbnail |
| `release_date` | date or null | `YYYY-MM-DD` |
| `runtime_minutes` | integer or null | Runtime |
| `maker`, `label`, `series` | string or null | Publisher data |
| `genres` | array of string | Genre labels |
| `censored` | boolean or null | Censorship flag |
| `community_score` | number or null | Community score |
| `completeness_tier` | string or null | How complete the record is |
| `attrs` | object | Sparse source-specific extras |
| `magnets`, `hls_stream_urls`, `gallery` | array | Media links |
| `photos` | object | Images and galleries |
| `crawled_at`, `delisted_at` | datetime or null | ISO 8601 |
| `needs_review` | boolean | Flagged for manual review |

## Performer detail

| Field | Type | Meaning |
|---|---|---|
| `uuid`, `id` | string, integer | Identifiers |
| `name_romaji`, `name_kanji`, `name_kana` | string or null | Names |
| `aliases` | array of string | Alternative names |
| `sources` | array of objects | Source records linked to this canonical performer; each object includes source slug, external ID, names, profile URL, birth date, and active state |
| `birth_date` | date or null | `YYYY-MM-DD` |
| `height_cm` | integer or null | Height |
| `bust`, `waist`, `hip` | integer or null | Measurements in cm |
| `cup` | string or null | Cup size |
| `blood_type` | string or null | Blood type |
| `bio_text` | string or null | Biography |
| `debut_date` | date or null | `YYYY-MM-DD` |
| `retired_date` | date or null | `YYYY-MM-DD`; no CrawlerX source provides it yet, so it stays `null` until one does |
| `location` | string or null | Free-text location |
| `attrs` | object | Sparse source-specific extras |
| `linked_title_count` | integer or null | Number of linked movies, when counted |
| `profile_url`, `image_url` | string or null | Source profile and image |
| `photos` | object | Images and galleries |
