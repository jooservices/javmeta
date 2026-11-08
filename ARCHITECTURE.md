# ARCHITECTURE DECISION RECORD — "JAV Metadata API" (codename `javmeta`) POC

Status: **Approved** (architecture gate closed, 2026-09-17). Source of truth;
decision log ở mục 10. Root overview:
[`docs/01-projects/jvmeta/ARCHITECTURE.md`](../../docs/01-projects/jvmeta/ARCHITECTURE.md).

**Phase:** architecture (`joo-sa`) · **Tác giả:** `joo-sa` · bản ghi do
`joo-doc-writer` từ nội dung root đã duyệt.
**Nguồn yêu cầu:** [`projects/javmeta/BUSINESS.md`](./BUSINESS.md)
(requirements gate đóng 2026-09-17).
**Phạm vi:** POC — crawl → normalize → API lookup/search, 1.000–5.000 movie,
1 server, báo cáo go/no-go. **Billing NGOÀI phạm vi.**
Tên sản phẩm cuối: TBD bởi owner (OQ-13) — dùng codename `javmeta`.

## Quyết định đã chốt với owner

| # | Quyết định | Owner chọn |
|---|---|---|
| AD-1 | Stack | **All-PHP**: Laravel API + PHP crawl worker, tái dùng `crawlerx` |
| AD-2 | Database | **PostgreSQL 17** (Docker), một instance duy nhất |
| AD-3 | FANZA/DMM | **Hoãn sang Wave 2** — Wave 1 chạy nguồn `crawlerx` đã có |
| AD-4 | `crawlerx` | **Wave 1 KHÔNG sửa gì** — dùng nguyên trạng |
| AD-5 | Deployment | **Hoãn** ("just finish POC, deploy later") → thiết kế Docker Compose portable |

AD-6..AD-18 đã owner **xác nhận tại architecture gate 2026-09-17**. Ba open
items cuối cùng chốt **tại gate** (chi tiết ở mục 10–11):

- **OQ-11 resolved** — kênh cảnh báo AC-9.2: **Telegram + log** (AD-18).
- **AD-10 hệ quả resolved** — owner **chấp nhận exact-only merge** ở Wave 1
  (không fuzzy match); fuzzy merge re-evaluate ở Wave 2 bằng số liệu REQ-8.
- **OQ-12 resolved** — defaults `abuse_rpm=60`, `max_attempts=3`,
  `N consecutive failures=3`; **owner cấu hình được** qua `sources` /
  `api_keys`, không hard-code.

---

## 1. Requirement traceability (REQ → design element)

| REQ | Design element |
|---|---|
| **REQ-1** Chuẩn hoá về 1 bản ghi movie | `movies` (1 row/movie) + `movie_codes` (nhiều code → 1 movie, BR-1/D-23) + `movie_observations` (append-only provenance, AC-1.2) + `movie_genres`/`genres` (union, AC-1.6/D-24) + `movie_media` (URL-only, BR-4) + `SourceNormalizer` (Adapter pattern) + `ConflictPolicy` (Strategy, BR-2) + `completeness_tier` (AC-1.5) |
| **REQ-2** Crawl nền 24/7 | `sources` (config + AIMD throttle + circuit breaker) + `crawl_queue` (resume, edge case 29) + `crawl:tick` scheduler + `FetchListingJob`/`FetchDetailJob` (queue worker) + `crawlerx` làm fetch/parse engine + `crawl_runs` (AC-2.5/G-4) + `crawl_events` (AC-2.4) |
| **REQ-3** Lookup theo code | `GET /api/v1/movies/{code}` + `NormalizedCode` VO (BR-9) + `movie_codes.code_normalized` UNIQUE index + 404 `movie_not_found` / 401 (AC-3.2/3.3/3.4) |
| **REQ-4** Search + filter + sort + pagination | `GET /api/v1/movies` + `laravel-repository` v4 (filter/order/request-query allowlist) + `tsvector` GIN + `pg_trgm` GIN + composite sort indexes + **keyset/cursor pagination** (AC-4.3/4.4, edge case 28) |
| **REQ-5** Performer catalog | `performers` (canonical record, one per real person) + `performer_sources` (links each source record to one canonical performer; merges only on strong signals, reversible via `performer_identity_events`, JAVM-84) + `performer_aliases` (AC-5.3) + `movie_performers` (AC-5.1 count, AC-5.5 reverse link) + `GET /performers`, `/performers/{id}` |
| **REQ-6** Bulk lookup | `POST /api/v1/movies:bulk` ≤100, `WHERE code_normalized = ANY(...)` 1 round-trip, per-code status (AC-6.1/6.3), 413 khi vượt (AC-6.2) |
| **REQ-7** API key + usage log | `api_keys` (prefix + **hash only**, AC-7.1) + revoke không cache → 401 ngay (AC-7.2) + `api_usage_log` (AC-7.3) + Laravel rate limiter theo `abuse_rpm` (default 60, per-key — OQ-12 resolved) → 429 (AC-7.4) + `/admin/keys` |
| **REQ-8** Báo cáo chất lượng & chi phí | `movie_observations` (nguồn số liệu conflict G-5) + `crawl_runs` (proxy requests/giờ/lỗi G-4) + `GenerateReportJob` + `/admin/report/{quality,cost,sample-verification}` (AC-8.1..8.4) + **merge statistics** định lượng rủi ro exact-only merge (AD-10) |
| **REQ-9** Health + giám sát | `GET /health` **không cần key** (AC-9.4) qua `StatusHealthCheck` probes + `sources.last_success_at` (AC-9.1) + `crawl_events` → alert ≤24h **Telegram + log** (AC-9.2, OQ-11 resolved) + `review_flags` + `movies.needs_review` (AC-9.3, default N=3 — OQ-12 resolved) |
| **NFR perf** | MVCC (crawl ghi không chặn API đọc) + keyset pagination + GIN/GiST indexes + bulk `ANY()` + `HasCache` cho performer/genre |
| **NFR scale 5k→400k** | Schema đã đúng ở POC; contract `/api/v1` cố định; evolution path mục 8 |
| **NFR reliability** | Circuit breaker per-source + append-only observations + null-overwrite guard + sharp-drop guard + DB queue bền qua restart |
| **NFR security / BR-4** | `movie_media.url` là cột **duy nhất** dạng media — không có đường nào ghi bytes xuống disk (enforce bằng schema) |
| **NFR ops / C-4** | Đổi domain = sửa `sources.base_url` (config), không sửa nghiệp vụ crawl |

---

## 2. Kiến trúc đề xuất

### 2.1 Components & boundaries

**Một Laravel app, hai runtime role, một PostgreSQL.** Không microservice (KISS — 1 server, 1 team).

```text
┌──────────────────────── javmeta (Laravel 12/13, PHP 8.5) ────────────────────────┐
│                                                                                  │
│  ROLE: api  (php-fpm/Octane)          ROLE: worker (queue:work, 24/7)            │
│  ┌────────────────────────────┐       ┌──────────────────────────────────────┐   │
│  │ Route → FormRequest        │       │ Scheduler: crawl:tick (mỗi phút)     │   │
│  │  → Controller              │       │  → per-source loop (isolated)        │   │
│  │    (BaseApiController v4)  │       │  → AIMD throttle + circuit breaker   │   │
│  │  → Service                 │       │  → enqueue FetchListing/DetailJob    │   │
│  │  → Repository (v4 traits)  │       │                                      │   │
│  │  → Resource → envelope     │       │ Jobs:                                │   │
│  └─────────────┬──────────────┘       │  crawlerx::url()->tryCrawl()         │   │
│                │                      │   → SourceNormalizer (Adapter)       │   │
│                │                      │   → MovieMerger (BR-1)                │   │
│                │                      │   → ConflictPolicy (Strategy, BR-2)  │   │
│                │                      │   → Persist (transaction)            │   │
│                │   READ                              │  WRITE                   │
└────────────────┼─────────────────────────────────────┼──────────────────────────┘
                  ▼                                     ▼
         ┌──────────────────────────────────────────────────────┐
         │  PostgreSQL 17  (MVCC: writer không block reader)     │
         └──────────────────────────────────────────────────────┘

   Sidecar (compose profile "fetch", chỉ bật khi cần):
     node + Playwright Chromium  ·  FlareSolverr   ← crawlerx browser tier
```

**Boundary rules (SRP/DIP):**

| Layer | Trách nhiệm | KHÔNG được |
|---|---|---|
| `crawlerx` (external lib) | Fetch + parse HTML/JSON → `CrawlItemResultDto`/`CrawlListResultDto` | Không biết schema javmeta, không persist, không queue, không throttle |
| `SourceNormalizer` (per source) | Map `MovieDto` + `metadata{}` không typed → `MovieDraft` chuẩn | Không fetch, không ghi DB, không quyết conflict |
| `MovieMerger` | Resolve `movie_id` từ `code_normalized` + linkage nguồn khai báo | Không fuzzy match (R-5, AD-10 — owner chấp nhận exact-only cho Wave 1) |
| `ConflictPolicy` (Strategy) | Chọn giá trị primary từ observations (BR-2) | Không persist |
| `Repository` | Query/paginate/cache | Không chứa nghiệp vụ normalize |
| `Controller` | HTTP boundary, envelope, error semantics | Không query trực tiếp |

### 2.2 Data flow: crawl → normalize → store → serve

**Bước 1 — `crawl:tick` (scheduler, mỗi phút, per-source isolated):**

```text
for each source WHERE enabled AND circuit_state != 'open':
    gap   = source.gap_seconds_current          # AIMD state
    batch = f(gap, source.priority, queue_depth)
    enqueue batch × FetchJob with delay = gap
    # nguồn chết → circuit open → vòng lặp BỎ QUA nguồn đó, các nguồn khác vẫn chạy (AC-2.4)
```

Throttle defaults **đọc từ manifest `crawlerx`** (`AdapterManifestDto::$defaultThrottle`, vd javdb `min 20 / max 60s`) — không bịa số mới (DRY). `crawlerx` khai báo nhưng **không enforce** → javmeta thi hành.

**Bước 2 — `FetchDetailJob` (worker):**

```text
$outcome = CrawlerX::url($url)->options($perSourceOptions)->tryCrawl();   # non-throwing
if ($outcome->failed()) {
    # error codes: blocked | parse_failed | unsupported_url | ambiguous_url | adapter_not_found
    record crawl_events(kind: blocked|challenge|parse_drift);
    source.consecutive_failures++;  AIMD multiplicative-decrease;  maybe circuit open;
    return;                                    # ← KHÔNG ghi movie rỗng (edge case 11)
}
$dto = $outcome->result();                      # CrawlItemResultDto: meta.movie | meta.performer
```

**Bước 3 — `SourceNormalizer` (Adapter, mỗi nguồn 1 class):**

```text
MovieDto (typed: externalId, title, code, coverUrl, description, date, duration,
          performers[], tags[], screenshots[])
  + metadata{} (KHÔNG typed, tên key KHÁC NHAU theo nguồn —
                vd OnePondo/Types/Detail.php:59 'maker', :64 'series', :65 'rating')
        ↓  per-source key map
MovieDraft { code, title_jp, title_en, release_date, runtime_minutes, maker, label,
            series, genres[], performers[], cover_url, extras{magnet|hls|gallery|score},
            censored, source_slug, source_url, crawled_at }
```

Đây chính là **REQ-1a/1b** — công việc chuẩn hoá thật, và nó thuộc javmeta (crawlerx không nên biết canonical schema của javmeta → OCP).

**Bước 4 — `MovieMerger` (BR-1/D-23):**

```text
code_normalized = NormalizedCode::from(raw)->value      # BR-9: upper, bỏ space, pad số, GIỮ prefix
movie_id = movie_codes[code_normalized] ?? create movie
# Merge ONLY on: (1) exact code_normalized match, (2) linkage nguồn khai báo tường minh
# KHÔNG fuzzy match title → false-split phục hồi được, false-merge thì KHÔNG (R-5)
# (owner chấp nhận giới hạn exact-only cho Wave 1 — AD-10 resolved tại gate 2026-09-17)
```

**Bước 5 — `Persist` (1 transaction, append-only):**

```text
1. INSERT movie_observations (movie_id, field, value, value_hash, source_slug, crawled_at)
   ← NEVER UPDATE/DELETE. Đây là "crawled data never lost" (NFR) + nguồn số liệu G-5.
2. ConflictPolicy::pick(field, observations) → giá trị primary (BR-2: priority theo D-7)
3. UPDATE movies SET <field> = primary
   ⚠ NULL-OVERWRITE GUARD: không bao giờ set null khi đã có observation non-null
   ⚠ SHARP-DROP GUARD: nếu số core field non-null giảm ≥ N so với lần trước
                       → needs_review = true, GIỮ giá trị cũ (edge case 11/15)
4. UPSERT movie_codes / movie_genres / movie_performers / movie_media
5. Recompute completeness_tier (AC-1.5), search_vector, crawled_at (BR-7)
6. Bump crawl_runs counters (proxy_requests, browser_fetches, failures) ← AC-2.5/G-4
```

**Bước 6 — Serve:** API đọc `movies` + join. MVCC → crawl ghi không chặn API đọc.

### 2.3 Concurrency model — crawl 24/7 và API trên MỘT server

| Vấn đề | Giải pháp |
|---|---|
| Crawl ghi liên tục vs API đọc p95 | **PostgreSQL MVCC** — reader không block writer. Đây là lý do chính chọn PG thay SQLite (SQLite single-writer là rủi ro thật khi crawl 24/7 + API đồng thời) |
| Worker giành CPU của API | Compose service / systemd unit riêng, `CPUWeight` + `MemoryMax` cứng trên worker |
| Politeness (BR-6) | **Per-source delay**, không phải parallelism. Global cap ~4 job, per-source semaphore 1–2 |
| Singleton `CrawlerXFactory` + cookie in-memory | `CrawlerXFactory::reset()` giữa các batch (README ghi rõ dành cho long-running worker) |
| Memory leak / state tích tụ trong 72h (G-6) | `queue:work --max-time=3600 --max-jobs=500` + supervisor `Restart=always` → restart sạch định kỳ |
| Restart/mất điện giữa crawl (edge case 29) | **Queue driver = database** → job bền; `crawl_queue.status` `claimed` → `pending` khi `next_attempt_at` quá hạn. Không cần Redis cho POC (KISS) |
| Graceful shutdown | SIGTERM → job hiện tại xong/timeout → về queue |

---

## 3. Technology decisions + rationale

| Quyết định | Chọn | Lý do | Loại |
|---|---|---|---|
| **Ngôn ngữ** | PHP 8.5 | `crawlerx` (C-3) là PHP và đã có **21 adapter JAV**; toàn bộ stack tái dùng (`client` v4, `dto` v3, `exceptions` v4, `laravel-controller` v4, `laravel-repository` v4) là PHP → 1 ngôn ngữ, DRY tối đa | Python: phải viết lại 21 adapter, vi phạm C-3; CF bypass của `bds-crawler` **chỉ chạy macOS** (README:16, docs/02-concepts/architecture.md:14-18) → không chạy trên server Linux. Go: `go-crawlerx` chỉ có title+downloadables, HTTP-only, không metadata/performer |
| **Framework API** | Laravel 12/13 | `laravel-controller` v4 cho sẵn envelope + RFC 7807 + `meta.pagination` (length-aware/simple/**cursor**) + trace ID + `StatusHealthCheck` → khớp REQ-4/REQ-9. `laravel-repository` v4 cho sẵn filter (`exact`/`partial`/`before`/`after`/`date`/`jsonContains`) + order + request-query allowlist + cursor pagination + `HasCache` → khớp REQ-4 gần 1:1 | Slim/FrankenPHP thuần: viết lại envelope/pagination/filter, mất 2 package có sẵn |
| **Fetch/parse engine** | `crawlerx` nguyên trạng | 21 adapter JAV; fallback chain HTTP → curl-impersonate → Playwright → stealth → Puppeteer → FlareSolverr; `ChallengeDetector`; `tryCrawl()` non-throwing; `assertUsableMovieDetail()` đã chặn trang rỗng/thiếu field | Tự viết: trùng lặp khổng lồ, vi phạm C-3 |
| **Database** | PostgreSQL 17 (Docker, pin tag) | 600k row là **nhỏ** với PG. `jsonb` + GIN cho provider extras; `text[]`/join + GIN cho genre union; **`pg_trgm` GIN** cho partial-code + fuzzy title; **`tsvector`** cho relevance sort; **MVCC** cho crawl-vs-API; keyset pagination O(log n). Laravel + `laravel-repository` hỗ trợ first-class | MySQL: không GIN, FTS yếu, không trigram → genre union filter + partial code phải `LIKE`, khó đạt p95 ≤1.5s ở 400k. SQLite: single-writer, rủi ro crawl 24/7 + API đồng thời, phá NFR scale. MongoDB: filter/sort nhiều chiều + quan hệ performer↔movie yếu, `laravel-repository` (Eloquent) không áp dụng |
| **Queue/scheduler** | Laravel **database** queue + `schedule:run` (cron mỗi phút) | KISS: không thêm container Redis; queue bền qua restart (edge case 29) đủ cho POC 1–5k | Redis/Horizon: YAGNI ở POC — ghi nhận làm evolution |
| **HTTP client / anti-bot** | `crawlerx` fallback chain + `jooservices/client` v4 (PSR-18) | Đã có sẵn, đã test bằng fixture thật | — |
| **Proxy** | **Wave 1: không cần.** Wave 2: PR nhỏ vào `crawlerx` | `HttpOptionsDto:14-19` không có `proxy`; `ClientFactory:29-54` không gọi `withProxy()` dù `ClientBuilder:161` có; grep `proxy` trong `crawlerx/src` = **0 kết quả**. Wave 1 owner đã hoãn FANZA (nguồn cần proxy) → YAGNI | Sửa ngay: xây thứ chưa cần |
| **Soft-404 / chống mất dữ liệu** | **javmeta-side merge policy** | `crawlerx` **đã có** guard cấu trúc: `AbstractType::assertUsableMovieDetail():29-46` throw `CrawlParseException` khi thiếu title/thiếu field; `htmlFromResponse():14-27` throw `CrawlBlockedException` khi ≥400 hoặc CF challenge. Phần còn thiếu (null-overwrite, sharp-drop) vốn là **chính sách toàn vẹn dữ liệu** → đúng tầng ở javmeta, nơi BR-2/provenance đang sống | Thêm hook vào crawlerx: sai tầng, trùng trách nhiệm |
| **Runtime** | Docker Compose: `php:8.5-cli-bookworm` (pin) + `postgres:17` (pin) + profile `fetch` (node/Playwright, FlareSolverr) | Đúng rule runtime workspace (DB + multi-service → Docker/Compose; LTS + pin tag). Owner hoãn deploy → thiết kế portable | Native: lệch rule |
| **Repo/git** | `projects/javmeta`, **local git only**, đánh dấu `Status: POC` | Rule workspace: new project GitHub là opt-in, chỉ tạo khi owner yêu cầu rõ. POC marker cho phép bypass branch model (identity/commits/hooks vẫn bắt buộc) | — |

---

## 4. Data model sketch

```sql
-- REQ-1 / BR-1 / D-23: MỘT row per MOVIE
movies (
  id                bigserial PK,
  display_code      text NOT NULL,             -- code hiển thị cho consumer (SSIS-001)
  code_normalized   text NOT NULL UNIQUE,      -- BR-9 (SSIS001) — khoá tra cứu chính
  title_jp          text,                      -- AC-1.3: null tường minh, KHÔNG ''
  title_en          text,
  release_date      date,
  runtime_minutes   int,
  censored          smallint,                  -- 0|1|null (BR-11: không suy đoán)
  maker             text, label text, series text,
  community_score   numeric(4,2),
  completeness_tier smallint NOT NULL,         -- AC-1.5, suy ra từ số field non-null + class nguồn
  search_vector     tsvector GENERATED,        -- REQ-4 relevance
  delisted_at       timestamptz,               -- BR-10: không tự xoá
  needs_review      boolean NOT NULL DEFAULT false,   -- AC-9.3
  first_seen_at     timestamptz NOT NULL,
  updated_at        timestamptz NOT NULL,      -- sort key "update_date" (REQ-4)
  crawled_at        timestamptz NOT NULL       -- BR-7
)

-- BR-1: nhiều code/biến thể → 1 movie
movie_codes (
  id bigserial PK, movie_id bigint FK→movies,
  code text NOT NULL, code_normalized text NOT NULL,
  kind text NOT NULL,        -- dvd | uncensored | fc2 | re_release | leak | box_set
  source_slug text NOT NULL, source_url text, crawled_at timestamptz NOT NULL,
  UNIQUE (code_normalized, source_slug)
)  -- index: movie_codes(code_normalized) ← REQ-3 lookup

-- AC-1.2 / BR-2 / G-5 / REQ-8: APPEND-ONLY observation log
-- = provenance + nguồn số liệu đo xung đột + "không mất dữ liệu khi re-crawl"
movie_observations (
  id bigserial PK, movie_id bigint FK→movies,
  field text NOT NULL,       -- title_jp|title_en|release_date|runtime|maker|label|series|cover_url|community_score
  value text,                -- đã chuẩn hoá về string để so sánh
  value_hash text NOT NULL,  -- đếm DISTINCT rẻ (G-5)
  source_slug text NOT NULL, source_url text,
  crawled_at timestamptz NOT NULL,
  is_primary boolean NOT NULL DEFAULT false    -- giá trị được chiếu lên movies.*
)  -- KHÔNG có UPDATE/DELETE path

-- G-5 / AC-8.1(c): conflict rate THEO TỪNG FIELD
-- SELECT field,
--        COUNT(*) FILTER (WHERE dv > 1)::numeric / NULLIF(COUNT(*),0) AS conflict_rate
-- FROM (SELECT movie_id, field, COUNT(DISTINCT value_hash) dv
--       FROM movie_observations GROUP BY movie_id, field) t
-- GROUP BY field;

-- AC-1.6 / D-24: genre UNION, KHÔNG canonical taxonomy, provenance per source
genres      ( id serial PK, label_normalized text UNIQUE, label_raw text )
movie_genres ( movie_id, genre_id, source_slug, crawled_at,
              PRIMARY KEY (movie_id, genre_id, source_slug) )

-- REQ-5 / AC-5.2 (JAVM-84, AD-9): one canonical performer per real person across sources.
-- Source records link to it through performer_sources; a name alone never merges.
performers (
  id bigserial PK,                             -- canonical record
  name_romaji text, name_kanji text, name_kana text,
  profile_url text, image_url text,            -- URL reference only (BR-4)
  crawled_at timestamptz NOT NULL
)
performer_sources (
  id, performer_id FK -> performers,           -- canonical performer
  source_performer_id FK -> performers,        -- the per-source performers row this link describes
  site_slug text NOT NULL, mongo_id text NOT NULL, external_id text,
  name_romaji, name_kanji, name_kana, birth_date, -- per-source names kept
  cross_link_source, cross_link_id, removed_at,   -- cross-link signal; soft removal
  UNIQUE (site_slug, mongo_id)                 -- the source key of one source record
)
-- Merge only on same normalised name + birth date, or same namespaced cross-link id.
-- Ambiguous matches stay separate and appear in the review report; merges are recorded
-- (performer_identity_events) and can be split/undone. See docs/03-operations/performer-identity.md.
performer_aliases ( id, performer_id FK, source_performer_id FK, alias text, kind text,
                    UNIQUE(performer_id, alias, source_performer_id) ) -- aliases kept per source record
movie_performers   ( movie_id, performer_id, source_slug, crawled_at,
                    PRIMARY KEY (movie_id, performer_id) )
-- AC-5.1 linked_title_count = COUNT(*) FROM movie_performers WHERE performer_id=?
-- AC-5.5 reverse link đảm bảo bằng join table

-- REQ-1 extras / BR-4 / OQ-8: CHỈ URL tham chiếu, crawled_at riêng từng row
movie_media (
  id bigserial PK, movie_id bigint FK,
  kind text NOT NULL,        -- magnet | pikpak | hls | gallery | sample
  url  text NOT NULL,        -- ← cột DUY NHẤT dạng media. Không có byte nào xuống disk.
  meta jsonb,                -- size, codec, resolution, seq
  source_slug text NOT NULL,
  crawled_at timestamptz NOT NULL,             -- OQ-8: HLS có freshness riêng
  UNIQUE (movie_id, kind, url)
)

-- REQ-2 / REQ-9 / C-4: source = CONFIG, đổi domain không sửa code
sources (
  slug text PK, name text NOT NULL,
  base_url text NOT NULL,                      -- C-4: đổi domain ở đây
  enabled boolean NOT NULL,
  priority smallint NOT NULL,                  -- thứ tự D-7 → authority cho BR-2
  needs_proxy boolean NOT NULL DEFAULT false,
  gap_seconds_default numeric NOT NULL,        -- seed từ manifest crawlerx defaultThrottle
  gap_seconds_min numeric, gap_seconds_max numeric,
  gap_seconds_current numeric NOT NULL,        -- AIMD state
  consecutive_failures int NOT NULL DEFAULT 0,
  circuit_state text NOT NULL DEFAULT 'closed',-- closed | open | half_open  (AC-2.4)
  circuit_opened_at timestamptz,
  last_success_at timestamptz,                 -- AC-9.1
  last_error_at timestamptz, last_error text,
  soft404_markers jsonb                        -- signature not-found theo nguồn (javmeta-side)
)

-- REQ-2 resume (edge case 29) + per-source isolation
crawl_queue (
  id bigserial PK, source_slug text NOT NULL, url text NOT NULL,
  kind text NOT NULL,        -- listing | detail | performer_listing | performer_detail
  status text NOT NULL DEFAULT 'pending',  -- pending|claimed|done|failed|skipped
  attempts int DEFAULT 0,
  max_attempts int DEFAULT 3,              -- OQ-12 resolved: default 3, owner cấu hình per source
  next_attempt_at timestamptz, claimed_at timestamptz, locked_by text, last_error text,
  UNIQUE (source_slug, url)
)

-- AC-2.5 / G-4 / REQ-8: đo chi phí THEO NGUỒN
crawl_runs (
  id bigserial PK, source_slug text NOT NULL,
  started_at timestamptz NOT NULL, finished_at timestamptz, status text NOT NULL,
  pages_fetched int DEFAULT 0, movies_new int DEFAULT 0, movies_updated int DEFAULT 0,
  failures int DEFAULT 0,
  proxy_requests int DEFAULT 0, proxy_bytes bigint DEFAULT 0,
  browser_fetches int DEFAULT 0                -- tier Playwright/FlareSolverr = đắt
)

-- AC-2.4 / WF-3 / AC-9.2: event → alerting (Telegram + log — OQ-11 resolved)
crawl_events (
  id bigserial PK, source_slug text NOT NULL,
  kind text NOT NULL,   -- blocked|challenge|soft404|rate_limited|domain_change|proxy_exhausted|parse_drift
  url text, detail jsonb, created_at timestamptz NOT NULL
)

-- AC-9.3: needs-review ở mức TITLE (không chỉ mức nguồn).
-- Flag khi consecutive_failures ≥ N (default 3, owner cấu hình — OQ-12 resolved)
review_flags (
  id bigserial PK, movie_id bigint, code_normalized text,
  source_slug text NOT NULL, consecutive_failures int NOT NULL,
  reason text NOT NULL, flagged_at timestamptz NOT NULL, resolved_at timestamptz
)

-- REQ-7
api_keys (
  id bigserial PK,
  prefix text NOT NULL,                        -- AC-7.1 (jvm_...)
  key_hash text NOT NULL UNIQUE,               -- CHỈ hash, không plaintext (NFR Security)
  label text NOT NULL,
  status text NOT NULL DEFAULT 'active',       -- active | revoked
  revoked_at timestamptz,
  abuse_rpm int NOT NULL DEFAULT 60,           -- AC-7.4 / OQ-12 resolved: default 60, owner cấu hình per key
  created_at timestamptz NOT NULL
)
api_usage_log (                                -- AC-7.3
  id bigserial PK, api_key_id bigint,
  endpoint text NOT NULL, method text NOT NULL,
  status_code smallint NOT NULL, response_ms int, ip_hash text,
  created_at timestamptz NOT NULL
)  -- index (api_key_id, created_at); trim theo lịch
```

**AC-7.2 "thu hồi hiệu lực ngay":** không cache key lookup (hoặc TTL ≤5s + invalidate khi revoke). 1 indexed lookup trên `key_hash` ≈ sub-ms → truly immediate. KISS.

---

## 5. API contract sketch

Base `/api/v1` · Auth header `X-API-Key: jvm_...` (REQ-7) · Envelope + RFC 7807 từ `laravel-controller` v4.

| Method | Path | REQ | Auth | Ghi chú |
|---|---|---|---|---|
| GET | `/health` | REQ-9 | **Không** (AC-9.4) | System alive + `last_success_at` per source + circuit state. **Không** lộ title/performer |
| GET | `/movies/{code}` | REQ-3 | Key | Normalize code (BR-9) → 200 / 404 / 401 |
| POST | `/movies:bulk` | REQ-6 | Key | `{codes:[...]}` ≤100 → 200 per-code status; >100 → **413**, không xử lý một phần (AC-6.2) |
| GET | `/movies` | REQ-4 | Key | `q`, `genre`, `actress`, `maker`, `series`, `label`, `released_from/to`, `runtime_min/max`, `censored`, `sort`, `cursor`, `per_page` |
| GET | `/performers` | REQ-5 | Key | `q` (romaji/kanji/kana/alias — AC-5.3) |
| GET | `/performers/{id}` | REQ-5 | Key | Name variants + `linked_title_count` (AC-5.1) |
| GET | `/meta/genres` | REQ-4 | Key | Danh sách nhãn genre union (consumer dựng filter) |
| GET/POST/DELETE | `/admin/keys[/{id}]` | REQ-7 | **Owner guard riêng** | AC-7.1 list, AC-7.2 revoke ngay |
| GET | `/admin/report/quality` | REQ-8 | Owner | AC-8.1 (a)(b)(c)(d) + merge statistics (AD-10) |
| GET | `/admin/report/cost` | REQ-8 | Owner | G-4 + ngoại suy 100k/400k (AC-8.3) |
| GET | `/admin/report/sample-verification` | REQ-8 | Owner | AC-8.2 mẫu 200 code |

**Error semantics** (RFC 7807 `type` phân biệt được):

| Status | `type` | Khi nào |
|---|---|---|
| 401 | `unauthorized` | Key sai/thiếu/đã thu hồi — **body không chứa dữ liệu** (AC-3.4) |
| 404 | `movie_not_found` | Code normalize OK nhưng không có trong DB — **khác** 404 route (AC-3.3) |
| 400 | `invalid_filter` | `released_to < released_from`, `q` rỗng/chỉ ký tự đặc biệt (AC-4.5). Nêu rõ param nào |
| 413 | `bulk_limit_exceeded` | >100 code (AC-6.2) |
| 429 | `rate_limited` | Vượt `abuse_rpm`, kèm `Retry-After` + lý do (AC-7.4) |
| 500 | — | **Không bao giờ** do input người dùng |

**Movie resource** (đây là *data contract* — NFR yêu cầu không đổi khi scale):

```json
{
  "code": "SSIS-001",
  "codes": [{"code":"SSIS-001","kind":"dvd","source":"javdb"}],
  "title_jp": "...", "title_en": "...",
  "performers": [{"uuid":"...","id":1,"name_romaji":"...","name_kanji":"...","name_kana":"...","aliases":["..."],"image_url":"...","profile_url":"..."}],
  "cover_url": "https://third-party/...", "cover_thumb_url": "https://third-party/...",
  "description": "...",
  "release_date": "2021-01-01", "runtime_minutes": 120,
  "maker": "...", "label": "...", "series": "...",
  "genres": ["X","Y","Z","D","C","B"],
  "censored": true, "community_score": 8.4, "completeness_tier": 2,
  "magnets":         [{"url":"magnet:?...","source":"onejav","crawled_at":"..."}],
  "hls_stream_urls": [{"url":"https://...","source":"missav","crawled_at":"..."}],
  "gallery":         [{"url":"https://...","source":"...","crawled_at":"..."}],
  "provenance": {"title_jp":{"source":"javdb","crawled_at":"..."},
                 "release_date":{"source":"javdatabase","crawled_at":"..."}},
  "crawled_at": "...", "delisted_at": null, "needs_review": false
}
```

- `null` tường minh, **không** `""`, **không** giá trị suy đoán (AC-1.3, BR-11).
- `genres` = union khử trùng lặp nhãn, **không** map taxonomy (AC-1.6, D-24).
- Mọi media là **URL tham chiếu** (BR-4, D-22).
- `hls_stream_urls[].crawled_at` riêng (OQ-8) + docs cảnh báo URL có TTL.

---

## 6. Performance design (POC scale, NFR)

| NFR | Thiết kế | Dự kiến thực tế |
|---|---|---|
| **Lookup p95 ≤500ms** | `movie_codes.code_normalized` UNIQUE → 1 index hit; `movies` PK → 1 hit; extras qua 3 join có index. Ở 5k row toàn bộ nằm trong `shared_buffers`. Thêm `ETag`/`Cache-Control` | <20ms |
| **Search p95 ≤1.5s** | `movies.search_vector` **GIN** (tsvector, config `simple` — romaji/kanji không cần stemming) phủ title_jp/title_en/code · `pg_trgm` **GIN** trên `title_jp`, `title_en`, `movie_codes.code` → partial code (`SSIS`) + fuzzy (AC-4.1) · `movie_genres(genre_id)` semi-join · `movie_performers(performer_id)` · composite `(release_date DESC, id DESC)`, `(updated_at DESC, id DESC)`, `(community_score DESC NULLS LAST, id DESC)` | <300ms ở 5k |
| **Phân trang ổn định** (AC-4.3/4.4, edge case 28) | **Keyset/cursor**, KHÔNG OFFSET: `WHERE (sort_key,id) < (:last_sort,:last_id) ORDER BY sort_key DESC, id DESC LIMIT n`. Tie-break `id` → ổn định giữa 2 call; miễn nhiễm với insert đồng thời của crawl; O(log n) ở 400k. `laravel-repository` v4 hỗ trợ cursor | — |
| **Bulk 100 ≤3s** | `WHERE code_normalized = ANY(:codes)` 1 round-trip, rồi batch-load extras `WHERE movie_id = ANY(:ids)`. **Không N+1** | <150ms |
| **Chống query rộng** (edge case 27) | `per_page ≤ 100` cứng; `q` tối thiểu 2 ký tự → 400, không table-scan | — |
| **Caching** | `HasCache` (laravel-repository) cho performer profile + genre list (đọc nhiều, đổi chậm). **Không** cache search result ở POC (invalidation phức tạp, YAGNI). Cache driver = database/file, không Redis | — |
| **Count** | `meta.pagination.total` = exact `COUNT(*)` — ổn ở 5k. Đã dự trù swap sang estimated ở 400k: **field giữ nguyên**, chỉ đổi implementation (xem mục 8) | — |
| **Planner stats** | `ANALYZE movies` sau initial full crawl, trước khi đo perf | — |

---

## 7. Risks + mitigations

| # | Risk | Sev | Mitigation |
|---|---|---|---|
| **R-1** | **`crawlerx` chưa đạt DoD của chính nó**: `make ci` đỏ (coverage 69.87% Unit / 62.27% Feature vs sàn 85% — handover §5, §7 P0). Fixture javbus/javlibrary/missav là **CF wall**, fetch production **chưa được chứng minh** (handover §6.4, P1) | 🔴 HIGH | **Wave 1 source list xác minh thực nghiệm TRƯỚC**: chạy `make live-check` / `tools/live-check.php` từng adapter → phân loại `working` / `walled` / `broken`. Chỉ nguồn `working` vào crawl set. Nguồn walled → báo cáo **chưa phủ** theo AC-2.6, không bỏ âm thầm. **Không gate javmeta trên coverage crawlerx** (nợ repo đó) |
| **R-2** | **Không có FANZA/DMM** = nguồn giàu nhất thiếu → completeness tier + đo xung đột G-5 kém thuyết phục | 🔴 HIGH | Owner đã chấp nhận (AD-3, Wave 2). AC-2.6 ghi rõ gap. G-5 vẫn đo được trên ≥2 nguồn cộng đồng. Wave 2 đã nhận diện 2 đường: Affiliate API (JSON, rẻ hơn) hoặc HTML adapter + proxy Nhật |
| **R-3** | **`crawlerx` không có proxy** (`HttpOptionsDto:14-19`, `ClientFactory:29-54`, grep = 0 match) → nguồn geo-block/IP-ban không tới được | 🟡 MED | Wave 1 chọn nguồn không geo-block. Nguồn nào hoá ra geo-block → set `needs_proxy=true`, loại, báo cáo (AC-2.6). PR Wave-2 đã định hình: ~30 dòng (`HttpOptionsDto.proxy` + `ClientFactory::withProxy` + thread vào browser/FlareSolverr handler) |
| **R-4** | **HTML drift âm thầm** (edge case 15) — không exception, chỉ sai giá trị. **Nguy hiểm nhất** | 🔴 HIGH | 3 lớp: (a) `assertUsableMovieDetail()` throw khi trang thiếu title/field; (b) **sharp-drop guard** — số core field non-null giảm ≥N → `needs_review`, giữ giá trị cũ; (c) `parse_drift` event khi field-yield rate của nguồn tụt dưới ngưỡng → alert **Telegram + log** (AC-9.2, OQ-11 resolved). `movie_observations` append-only nên drift **truy vết được** trong lịch sử |
| **R-5** | **Gộp nhầm / tách nhầm** biến thể movie (BR-1, D-23, edge case 4–5) | 🔴 HIGH | Merge **bảo thủ, rule-based**: chỉ exact `code_normalized` + linkage nguồn khai báo tường minh. **Không fuzzy match** (YAGNI + tránh false merge) — **owner chấp nhận exact-only cho Wave 1** (AD-10 resolved tại gate); fuzzy merge re-evaluate ở Wave 2 bằng số liệu. Prefix-collision guard (edge case 3): `code_normalized` **giữ prefix** → `FC2PPV1234567` ≠ `FC21234567`. Mọi merge ghi vào `movie_codes` kèm source → audit được. REQ-8 report có **merge statistics** (phân phối codes/movie, số movie >1 code) để owner thấy rủi ro đã định lượng |
| **R-6** | **POC scope creep** — owner muốn "as much as possible" nguồn (BA R-1) | 🔴 HIGH | Gate cứng: POC xong khi **1–5k movie** (G-2) + **72h unattended** (G-6) + report (REQ-8). **Số nguồn KHÔNG phải tiêu chí hoàn thành.** Wave 1 source list **đóng băng ở planning**; thêm nguồn = task mới |
| **R-7** | **72h unattended** (G-6) với PHP worker dài hạn: memory leak, singleton state, cookie store phình | 🟡 MED | `queue:work --max-time=3600 --max-jobs=500` + supervisor `Restart=always` → restart sạch định kỳ. `CrawlerXFactory::reset()` giữa batch. DB queue → restart không mất gì (edge case 29). Watchdog: scheduler check worker heartbeat, alert **Telegram + log** nếu stale (AC-9.2, OQ-11 resolved) |
| **R-8** | **Proxy JP hết dung lượng giữa crawl** (edge case 13) | 🟡 MED | Wave 1 không cần proxy. Khi thêm: `proxy_exhausted` event → circuit open **chỉ** cho nguồn `needs_proxy`; nguồn khác vẫn chạy (AC-2.4 isolation) |
| **R-9** | **HLS URL có TTL** (OQ-8, edge case 21) → consumer nhận URL chết | 🟡 MED | `movie_media.crawled_at` per row; chu kỳ re-crawl **ngắn hơn** cho `kind='hls'` (giờ, không phải ngày); response kèm `crawled_at` để consumer tự đánh giá tuổi. Ghi rõ limitation trong docs |
| **R-10** | **`api_usage_log` phình** (AC-7.3 log mọi call) | 🟢 LOW | POC: bảng thường + index `(api_key_id, created_at)` + trim theo lịch (giữ N ngày). Evolution: partition theo tháng. Contract không ảnh hưởng |
| **R-11** | **Pháp lý** (C-5, BA R-2): bán magnet + HLS URL là lập trường rủi ro cao nhất | 📌 NOTE | Kiến trúc enforce BR-4 **bằng schema**: `movie_media.url` là cột duy nhất dạng media — không có đường nào ghi bytes xuống disk. Takedown (REQ-D7) hoãn nhưng schema đã đỡ được (`movies.delisted_at`, BR-10) |
| **R-12** | **`movie_observations` phình** ở 400k (600k movie × 11 field × ~5 nguồn × re-crawl) | 🟡 MED | POC 5k → không vấn đề. Evolution path mục 8.2: partition theo `crawled_at` + rollup hot/archive. Contract không đổi |

### OQ-1 — RESOLVED

> **Câu hỏi:** `crawlerx` sẵn có tới đâu với các nguồn JAV? Có adapter FANZA/javdb/missav chưa? Xử lý proxy + anti-bot tới đâu? Là library, service, hay dự án độc lập?

**Trả lời (bằng chứng code):**

| Câu hỏi con | Kết luận | Bằng chứng |
|---|---|---|
| Library / service? | **Library PHP 8.5 thuần**, framework-agnostic, single synchronous call. **Không** persistence/queue/scheduler | `composer.json:4` `"type": "library"`; handover §4.4 ghi rõ out of scope; README:354-363 |
| Site JAV hỗ trợ? | **21 adapter, JAV-specific thật**: javdb ✅, javdatabase ✅, javlibrary ✅, javbus ✅, missav ✅, fc2 ✅, onejav ✅, javbtc ✅, 141jav ✅, ffjav ✅, avfan ✅, jable ✅, xcity ✅, warashi ✅, minnanoav ✅, onepondo ✅, caribbeancom ✅, heyzo ✅, tokyohot ✅, duga ✅, eporner ✅ | `src/Adapters/` (21 thư mục site); README:180-202 |
| **FANZA/DMM?** | ❌ **KHÔNG có** — dù là nguồn ưu tiên **số 1** (D-7). Cũng thiếu: sextb, mgstage, DLsite | `src/Adapters/` không có thư mục Fanza/Dmm |
| **Proxy?** | ❌ **Không expose**. `client` v4 **có** (`ClientBuilder::withProxy`) nhưng crawlerx không nối dây | `HttpOptionsDto.php:14-19` (chỉ timeout/verifySsl/headers); `ClientFactory.php:29-54` (không gọi `withProxy`); `client/src/Client/ClientBuilder.php:161`; grep `proxy` trong `crawlerx/src` = **0** |
| Anti-bot? | ✅ **Tốt**: fallback chain HTTP → curl-impersonate → Playwright → playwright_stealth → chrome_stealth → puppeteer_stealth → FlareSolverr. `ChallengeDetector` (CF `Just a moment`, `cf-mitigated`, `cf-browser-verification`, JavBus age-wall). `isUsableBody` | `CrawlerXFactory.php:67-88`; `Fetch/ChallengeDetector.php:12-62`; handover §3.1-3.2 |
| Soft-404? | ⚠️ **Có guard cấu trúc**, không có signature ngữ nghĩa per-source. `assertUsableMovieDetail()` throw khi thiếu title/field; `htmlFromResponse()` throw khi ≥400 hoặc CF challenge | `AbstractType.php:29-46`, `:14-27`, `:87-97` |
| Trạng thái repo? | ⚠️ **Chưa đạt DoD**: coverage 69.87%/62.27% vs sàn 85% → `make ci` đỏ. javbus/javlibrary/missav fixture là CF wall, production fetch chưa chứng minh | handover §5 "Not done today: items 1, 5, 6", §6.4, §7 P0/P1 |
| Field trả về? | `MovieDto`: externalId, title, code, coverUrl, description, date, duration, performers[], tags[], screenshots[], **metadata{}**. `maker`/`label`/`series`/`rating` rơi vào **`metadata{}` không typed, tên key khác nhau theo nguồn** | `Dto/Entity/MovieDto.php:19-34`, `$known:40-43`; `Adapters/OnePondo/Types/Detail.php:59,64-65` |
| Throttle? | Manifest khai báo `defaultThrottle` (javdb 20–60s), đọc được qua `AdapterManifestDto:33` — nhưng **không enforce** trong library | `Registry/FileAdapterManifestRegistry.php:117,145`; grep không có limiter class |
| Pagination listing? | ✅ `CrawlPaginationDto`: currentPage/lastPage/nextPage/nextUrl/hasNextPage | `Dto/CrawlListResultDto.php:21`; README:123-131 |
| Long-running worker? | ⚠️ `CrawlerXFactory` là **singleton**; cookie handoff **in-memory only**. Có `reset()` public, README ghi rõ dành cho long-running worker | `CrawlerXFactory.php:29,61-65`; handover §9 |

**Khuyến nghị (đã được owner duyệt — AD-3, AD-4):**

1. **Tái dùng `crawlerx` làm fetch/parse engine** — KHÔNG viết lại bằng Go/Python. Nó là tài sản khớp yêu cầu nhất: 21 adapter JAV + anti-bot chain hoàn chỉnh.
2. **Wave 1 dùng nguyên trạng, zero thay đổi.** javmeta sở hữu toàn bộ phần 24/7 (queue, scheduler, throttle, backoff, circuit breaker, resume, normalize, merge, persist, report) — đúng ranh giới `crawlerx` tự khai báo.
3. **Xác minh thực nghiệm trước khi đóng băng source list** (R-1): chạy `live-check` từng adapter, chỉ lấy nguồn `working`.
4. **PR proxy → Wave 2**, chỉ khi owner thật sự mua proxy Nhật (YAGNI).
5. **Soft-404 chống mất dữ liệu → javmeta merge policy** (null-overwrite guard + sharp-drop guard), không phải crawlerx.
6. **Coverage crawlerx = task bảo trì độc lập**, không gate javmeta.

---

## 8. Migration / evolution POC → production (400k), giữ contract ổn định

### 8.1 Nguyên tắc bất biến

- **Contract `/api/v1` cố định**: tên field + ý nghĩa không đổi (NFR Scalability). Thêm nguồn = thêm **row**, không đổi nghĩa field cũ (NFR Ops).
- **Thêm nguồn = OCP**: insert `sources` row + 1 class `SourceNormalizer` mới. Không sửa code cũ.
- **`movie_observations` append-only** → schema không cần migrate khi thêm nguồn/field.

### 8.2 Đường evolution (không đổi contract)

| Hạng mục | POC (5k) | Production (400–600k) | Contract |
|---|---|---|---|
| `movies` | 5k row, PG thoải mái | 600k row vẫn **nhỏ** với PG. Chỉ cần `ANALYZE` + vacuum định kỳ | Không đổi |
| `movie_observations` | ~5k×11×3 ≈ 165k row | Rủi ro phình chính (R-12). Path: **partition theo `crawled_at`** (tháng) + **rollup**: hot table giữ observation mới nhất per (movie, field, source), archive phần còn lại. G-5 tính trên hot set; drift analysis trên archive | Không đổi (internal) |
| Pagination | Keyset/cursor | **Đã đúng** — O(log n), không cần sửa | Không đổi |
| `meta.pagination.total` | exact `COUNT(*)` | Swap sang **estimated** (EXPLAIN rows / reltuples). **Field giữ nguyên**, chỉ đổi implementation — đây là swap nội bộ duy nhất đã dự trù | **Không đổi** |
| Search | PG `tsvector` + `pg_trgm` | Đủ tới ~600k. Nếu relevance/latency xuống cấp → thêm **Meilisearch/Typesense** làm read-replica index, feed từ cùng `movies`. Repository interface che engine (DIP) | Không đổi |
| Queue | Laravel **database** queue | **Redis + Horizon** khi throughput cần. Job class giữ nguyên | Không đổi |
| Crawler | Wave 1: nguồn `crawlerx` đã có | Wave 2: FANZA (Affiliate API **hoặc** HTML adapter + proxy Nhật) + PR proxy crawlerx. Wave 3: sextb/mgstage/DLsite = adapter `crawlerx` mới | Không đổi |
| Performer | Canonical `performers` row plus per-source `performer_sources` rows linked by strong-signal matching (AD-9); merges are recorded and reversible | Review report lists ambiguous matches; the backfill command (dry-run supported) is safe to re-run. API resource exposes the canonical `id`, name variants and sources | Per-source rows are kept, so a split restores them |
| Billing (REQ-D1) | Không có | `api_keys` đã có `abuse_rpm`; thêm `plans`, `quota_ledger`. `api_usage_log` **đã capture đủ** cho pricing (AC-7.3 là mitigation BA cố ý đặt cho BA R-3) | Không đổi |
| Infra | 1 server, Compose | Đọc replica / tách worker sang máy riêng. Compose file đã tách service → tách máy được | Không đổi |

### 8.3 Wave plan

| Wave | Nội dung | Gate |
|---|---|---|
| **Wave 0** | Scaffold `projects/javmeta` (local git, POC marker) + Compose (php/postgres/fetch profile) + schema migration + `live-check` xác minh source list | Source list đóng băng |
| **Wave 1** | Crawl nguồn `crawlerx` đã có → normalize → merge (exact-only, AD-10) → persist → API lookup/search/bulk/performer → key + usage log → health → alerting (Telegram + log) → report | **G-1..G-7** (1–5k movie, 72h, report) → **go/no-go** |
| **Wave 2** *(chỉ nếu go)* | FANZA (Affiliate API hoặc adapter + proxy Nhật) + PR proxy crawlerx + field-level authority (REQ-D4, quyết bằng số liệu G-5) + **re-evaluate fuzzy merge (AD-10) bằng số liệu REQ-8** | Owner duyệt ngân sách proxy (OQ-4) |
| **Wave 3+** *(ngoài POC)* | sextb/mgstage/DLsite adapters · billing (REQ-D1) · takedown (REQ-D7) · webhook (REQ-D5) · search engine · scale 400k | — |

---

## 9. Design patterns (chỉ cho nhu cầu hiện tại)

| Pattern | Ở đâu | Nhu cầu hiện tại |
|---|---|---|
| **Adapter** | `SourceNormalizer` per source | REQ-1: `metadata{}` key khác nhau theo nguồn. Thêm nguồn = thêm class (OCP) |
| **Strategy** | `ConflictPolicy` | BR-2 tạm dùng source-priority; REQ-D4 sẽ đổi sang field-level authority **sau khi có G-5** → policy phải swap được mà không sửa merge code |
| **Repository** | `laravel-repository` v4 traits | REQ-4 filter/order/pagination; che PG vs search engine tương lai (DIP) |
| **Value Object** | `NormalizedCode` (BR-9), `GenreLabel` (D-24) | BR-9 cấm gộp nhầm 2 code khác nhau → invariant nằm 1 chỗ |
| **Circuit Breaker** | `sources.circuit_state` + per-source tick isolation | AC-2.4, WF-3, NFR "một nguồn chết không giết pipeline" |
| **Observer / Event** | `crawl_events` + Laravel events → **Notifier (Strategy): Telegram + log** (OQ-11 resolved) | AC-9.2 alert ≤24h, tách khỏi crawl loop; thêm channel = thêm 1 class |
| **Command / Job** | `FetchListingJob`, `FetchDetailJob`, `GenerateReportJob` | REQ-2 queue + REQ-8 report |
| **DTO / Data** | `jooservices/dto` v3: `MovieDraft`, `PerformerDraft`, request/response | Workspace standard; crawlerx đã trả DTO |
| **Builder / Fluent** | reuse `CrawlerX::url()->site()->type()->options()` | REQ-2 per-source fetch config |

**Từ chối rõ ràng (YAGNI/KISS):** không CQRS/event-sourcing (`movie_observations` đã cho audit mà không cần machinery) · không microservice (1 server, 1 app) · không message broker (DB queue) · không search engine cluster · **không canonical genre taxonomy** (D-24 cấm) · **không fuzzy-match merging ở Wave 1** (R-5; owner chấp nhận tại gate — AD-10 resolved) · **không media cache** (BR-4) · không Redis ở POC.

---

## 10. Decision log

**Owner đã duyệt toàn bộ AD-1..AD-18 tại architecture gate 2026-09-17.**
AD-18 chốt **Telegram + log** (OQ-11 resolved); hệ quả AD-10 được owner chấp
nhận; ngưỡng OQ-12 chốt default owner-cấu hình.

| # | Câu hỏi | Lựa chọn | Quyết định | Lý do chọn / loại | REQ |
|---|---|---|---|---|---|
| **AD-1** | Ngôn ngữ | All-PHP / Python+PHP / Go+PHP / PHP không Laravel | **All-PHP: Laravel API + PHP worker, reuse crawlerx** | Chọn: `crawlerx` (C-3) là PHP + đã có 21 adapter JAV; toàn bộ stack reuse là PHP → 1 ngôn ngữ, DRY tối đa. **Loại** Python: viết lại 21 adapter, vi phạm C-3; CF bypass `bds-crawler` **chỉ chạy macOS** → chết trên server Linux. **Loại** Go: `go-crawlerx` chỉ title+downloadables, HTTP-only. **Loại** non-Laravel: mất `laravel-controller`+`laravel-repository` đã khớp REQ-4/9 | REQ-1..9, C-3 |
| **AD-2** | Database | PostgreSQL / MySQL / SQLite / MongoDB | **PostgreSQL 17 (Docker, 1 instance)** | Chọn: 600k row nhỏ với PG; `jsonb`+GIN, `pg_trgm`, `tsvector`, **MVCC** (crawl ghi không chặn API đọc — đúng NFR p95), keyset pagination. **Loại** MySQL: không GIN/trigram, FTS yếu → khó đạt search p95 ở 400k. **Loại** SQLite: single-writer, rủi ro crawl 24/7 + API đồng thời, phá NFR scale. **Loại** MongoDB: filter/sort nhiều chiều + performer↔movie yếu, `laravel-repository` không áp dụng | REQ-1, REQ-4, NFR perf/scale |
| **AD-3** | FANZA/DMM (nguồn #1, không adapter, geo-block) | Hoãn Wave 2 / làm trước / Affiliate API / bỏ hẳn | **Hoãn sang Wave 2 — Wave 1 chạy nguồn `crawlerx` đã có** | Chọn: POC không đứng yên chờ 2 thứ chưa có (adapter mới + proxy chưa mua). AC-2.6 bắt buộc báo cáo ghi rõ "FANZA chưa phủ" → minh bạch. **Loại** "làm trước": đúng R-1 BA cảnh báo (POC không bao giờ xong). **Loại** "bỏ hẳn": mất nguồn giàu nhất, ngược D-7. Affiliate API giữ làm **option Wave 2** (rẻ hơn viết adapter HTML) | REQ-2, G-1/G-2, AC-2.6 |
| **AD-4** | Sửa `crawlerx`? | Sửa proxy+soft-404 / chỉ proxy / không sửa / sửa luôn coverage | **Wave 1 KHÔNG sửa gì. Proxy PR → Wave 2. Soft-404 → javmeta. Coverage → task bảo trì độc lập** | Chọn (sau khi owner chất vấn "why change instead use?"): đọc kỹ code thấy `crawlerx` **đã có** soft-404 guard cấu trúc (`AbstractType.php:29-46`) + block guard (`:14-27`, `:87-97`) → không cần hook. Proxy là gap thật (`HttpOptionsDto:14-19`, grep=0) nhưng **Wave 1 không cần** (FANZA đã hoãn) → YAGNI. Chống mất dữ liệu đặt ở javmeta merge policy = đúng tầng (BR-2/provenance sống ở đó). **Loại** "sửa ngay": xây thứ chưa cần. **Loại** "không bao giờ sửa": Wave 2 sẽ cần proxy. **Loại** "sửa coverage": nợ repo crawlerx, làm phình scope POC | REQ-2, C-3, NFR reliability |
| **AD-5** | Deployment | Compose portable / chờ spec server / native | **Docker Compose portable, không chặn ở spec server** | Owner: "just finish POC, deploy later". Compose theo đúng rule runtime workspace (pin tag `php:8.5-cli-bookworm`, `postgres:17`). Resource isolation (`CPUWeight`/`MemoryMax`) thiết kế sẵn, áp khi deploy | C-1, NFR ops |
| **AD-6** | Queue driver | Redis+Horizon / **database** / beanstalkd | **Laravel database queue** | Chọn: KISS — không thêm container; bền qua restart → edge case 29 resume miễn phí; đủ cho POC 1–5k. **Loại** Redis: YAGNI ở POC, ghi nhận làm evolution | REQ-2, AC-2.2, edge case 29 |
| **AD-7** | Provenance & đo xung đột | JSONB column trên `movies` / **bảng `movie_observations` append-only** / event store | **Bảng `movie_observations` append-only** | Chọn: AC-1.2 yêu cầu giá trị bị loại **vẫn truy vết được**; G-5/AC-8.1(c) yêu cầu conflict rate **theo từng field** → aggregate SQL trên bảng hẹp (6 cột) rẻ và index được. Đồng thời thoả NFR "crawled data never lost on re-crawl" by construction. **Loại** JSONB column: aggregate xung đột thành query JSONB chậm, khó index. **Loại** event store/CQRS: over-engineering cho POC | REQ-1, REQ-8, G-5, AC-1.2 |
| **AD-8** | Genre model | `text[]` + GIN trên `movies` / **join table `movie_genres`** / canonical taxonomy | **Join table + `genres(label_normalized)`** | Chọn: AC-1.6 yêu cầu genre union **truy vết được về từng nguồn** → cần `source_slug` per row, `text[]` không làm được. **Loại** canonical taxonomy: owner **cấm** ở D-24 | REQ-1, REQ-4, AC-1.6, D-24 |
| **AD-9** | Performer identity | Canonical per name / per `(source, external_id)` only / strong-signal canonical merge | **Canonical performer across sources, merged only on strong signals** (JAVM-84) | Each source record stays in `performer_sources` keyed by `UNIQUE(site_slug, mongo_id)` and links to one canonical `performers` row, so the API, MCP and search return one record per person with its sources. Records merge only on the same normalised name plus the same birth date, or on a shared namespaced cross-link id; a name alone never merges (AC-5.2 still holds). Ambiguous matches stay separate and go to a review report. Merges are recorded and reversible (split/undo), and aliases and per-source names are kept. Thresholds live in `config/performer_identity.php`; see `docs/03-operations/performer-identity.md`. **Rejected:** canonical-per-name (merges different people who share a name), unconditional fuzzy merge (R-5) | REQ-5, AC-5.1..5.5 |
| **AD-10** | Variant merge mechanism (BR-1/D-23) | Exact code + source-declared linkage / **fuzzy title match** / manual | **Exact `code_normalized` + linkage nguồn khai báo tường minh. KHÔNG fuzzy** — **hệ quả được owner CHẤP NHẬN tại gate 2026-09-17 (resolved)** | Chọn: false-split **phục hồi được** (merge sau), false-merge **phá huỷ identity** và khó hoàn tác. Prefix-collision guard (edge case 3): giữ prefix → `FC2PPV1234567` ≠ `FC21234567`. REQ-8 report có merge statistics để owner thấy rủi ro định lượng. ⚠️ **Hệ quả — owner đã chấp nhận ở gate**: biến thể có code **khác nhau** mà nguồn không khai báo linkage sẽ **chưa** gộp ở Wave 1 → BR-1 thoả **một phần** ở POC; **fuzzy merge re-evaluate ở Wave 2 bằng số liệu REQ-8** | REQ-1, BR-1, AC-1.1, edge case 3/4/5 |
| **AD-11** | Pagination | OFFSET / **keyset-cursor** | **Keyset/cursor** | Chọn: AC-4.3 (không trùng không sót) + edge case 28 (phân trang trong khi crawl đồng thời) → OFFSET **vỡ** khi có insert. Keyset miễn nhiễm + O(log n) ở 400k → thoả NFR scale. `laravel-repository` v4 hỗ trợ sẵn | REQ-4, AC-4.3/4.4, NFR scale |
| **AD-12** | API key revocation (AC-7.2 "ngay lập tức") | Cache TTL dài / **không cache, query DB** | **Không cache key lookup** | Chọn: 1 indexed lookup trên `key_hash` ≈ sub-ms → truly immediate, KISS. **Loại** cache TTL: tạo độ trễ hiệu lực, vi phạm AC-7.2 | REQ-7, AC-7.2 |
| **AD-13** | Key storage | Plaintext / **hash only** | **Hash only** (`key_hash`), `prefix` để nhận diện | NFR Security + edge case 26 (key lộ/commit lên repo công khai). AC-7.1 vẫn thoả nhờ `prefix` | REQ-7, AC-7.1, NFR security |
| **AD-14** | Throttle numbers | Tự đặt / **đọc từ manifest `crawlerx`** | **Đọc `defaultThrottle` từ manifest** (javdb 20–60s, duga/fc2/heyzo 10–60s...) | DRY: `crawlerx` đã khai báo politeness per site, chỉ **không enforce** → javmeta thi hành. Không bịa số mới | REQ-2, BR-6, AC-2.4 |
| **AD-15** | Backoff strategy | Fixed retry / **AIMD + circuit breaker per source** | **AIMD** (additive increase, multiplicative decrease) trên `gap_seconds_current` + circuit `closed/open/half_open` | Chọn: BR-6 yêu cầu "giảm tốc/tạm dừng **riêng nguồn đó**"; AC-2.4 yêu cầu nguồn khác vẫn chạy → isolation phải **cấu trúc**, không phải convention. **Loại** fixed retry: không thích ứng, đốt proxy (edge case 12) | REQ-2, BR-6, AC-2.4, WF-3 |
| **AD-16** | BR-4 enforcement | Policy/doc / **schema-level** | **Schema-level**: `movie_media.url` là cột duy nhất dạng media | Chọn: không có đường nào ghi bytes xuống disk → bất biến enforce được bằng code review + schema, không dựa vào kỷ luật thủ công. **Loại** policy-only: dễ trôi | BR-4, D-22, NFR security, R-11 |
| **AD-17** | Repo/git | GitHub ngay / **local git + POC marker** | **`projects/javmeta`, local git only, `Status: POC`** | Rule workspace: new project GitHub là **opt-in**, chỉ tạo khi owner yêu cầu rõ. POC marker cho phép bypass branch model; identity (`Viet Vu <jooservices@gmail.com>`), Conventional Commits, hooks, description+topics **vẫn bắt buộc** | Policy |
| **AD-18** | Alert channel (AC-9.2) | Email / **Telegram + laravel-logging** / log-only | **Telegram + laravel-logging (`alert.sent`)** — evolved from gate “Telegram + log” | Chọn: Telegram tới điện thoại; audit alert durable qua `jooservices/laravel-logging` (thay `Log::warning`). Side effects qua Laravel events/listeners (`CrawlIncidentOccurred`, `WorkerHeartbeatStale`, …). Telegram không thay bằng OO alerts trong POC. | REQ-9, AC-9.2, OQ-11 |
| **AD-19** | Observability sink | External SaaS / Vector sidecar / **OpenObserve Compose** / log-only | **OpenObserve OSS (1 service) trong Compose** + app push trực tiếp (JSON logs + OTLP HTTP metrics/traces); Postgres vẫn SoR cho `crawl_events`/`api_usage_log` | Chọn: một binary, OTLP-native, đủ logs/metrics/traces cho REQ-9 ops + API + quality aggregates (G-5 qua `value_hash` only). **Không** gửi movie/performer/observation values/URLs/HTML/secrets. Fail-open ingest. Telegram (AD-18) vẫn là kênh điện thoại — không thay bằng OO alerts trong POC. **Loại** Vector sidecar: thêm service trái “1 OBS service”. **Loại** full OTEL PHP SDK: YAGNI — raw OTLP JSON qua Laravel Http (cùng pattern ES). | REQ-9, REQ-7, REQ-8 |

---

## 11. Open items — trạng thái sau architecture gate 2026-09-17

| # | Việc | Trạng thái | Chi tiết / hành động |
|---|---|---|---|
| **OQ-11** | Kênh cảnh báo AC-9.2 (email / Telegram / log-only)? | ✅ **RESOLVED tại gate** | Chốt: **Telegram + log**. Notifier qua Laravel event → Strategy; thêm channel = thêm 1 class, không sửa crawl loop (AD-18). Việc của planning: đưa vào task REQ-9. |
| **AD-10 hệ quả** | BR-1 thoả **một phần** ở Wave 1 (exact-only merge, không fuzzy) | ✅ **RESOLVED tại gate** | Owner **chấp nhận exact-only cho Wave 1**. REQ-8 report **định lượng** rủi ro merge (phân phối codes/movie, số movie >1 code) → fuzzy merge **re-evaluate ở Wave 2 bằng số liệu**, đúng tinh thần G-5. |
| **OQ-12** | Ngưỡng `abuse_rpm` (AC-7.4) + `max_attempts` / N consecutive failures (AC-9.3)? | ✅ **RESOLVED tại gate** | Defaults chốt: `abuse_rpm=60`, `max_attempts=3`, `N=3`. **Owner cấu hình được** qua `sources` / `api_keys` — không hard-code. Planning chỉ cần hiện thực hoá config surface. |
| **Wave 1 source list** | Đóng băng danh sách nguồn sau `live-check` thực nghiệm (R-1) | 🔶 **Chốt ở planning** | Chạy `make live-check` từng adapter → phân loại `working`/`walled`/`broken`. Chỉ nguồn `working` vào crawl set. Nguồn walled (javbus/javlibrary/missav có thể) → báo cáo **chưa phủ** theo AC-2.6, **không** bỏ âm thầm. |
| **OQ-4** | Trần ngân sách proxy Nhật | 🔶 **Sau báo cáo POC** | Không cần cho Wave 1. G-4/AC-8.3 cho số đo proxy requests + giờ crawl + lỗi / 1.000 title → ngoại suy 100k/400k → owner đặt trần **bằng số liệu**. |
| **OQ-13** | Tên sản phẩm | 🔶 **Mở (không chặn kỹ thuật)** | Giữ codename `javmeta` cho repo + route prefix. Đổi tên sau = đổi `display` metadata, không đổi contract. |

---

## Handoff envelope

**1. Scope completed:** Khảo sát current-state (`crawlerx`, `bds-crawler`, `go-crawlerx`, `client`, `laravel-controller`, `laravel-repository`) → **giải OQ-1 (blocking)** bằng bằng chứng code. Brainstorm 3 vòng với owner qua `question` tool → chốt AD-1..AD-5. Sản xuất ADR đầy đủ theo yêu cầu. **Owner duyệt tại architecture gate 2026-09-17**: xác nhận AD-1..AD-18, chốt OQ-11 (Telegram + log), chấp nhận hệ quả AD-10 (exact-only ở Wave 1), chốt ngưỡng OQ-12 (defaults owner-cấu hình).

**2. Evidence inspected:**

- `projects/javmeta/BUSINESS.md` (463 dòng, đọc hết)
- `projects/crawlerx/`: `README.md`, `handover.md`, `composer.json`, `src/CrawlerXFactory.php`, `src/Dto/{HttpOptionsDto,FetchOptionsDto,CrawlOptionsDto,CrawlListResultDto,StreamResultDto}.php`, `src/Dto/Entity/{MovieDto,PerformerDto}.php`, `src/Services/ClientFactory.php`, `src/Fetch/{FetchRuntimeConfig,ChallengeDetector}.php`, `src/Adapters/AbstractType.php`, `src/Adapters/{JavDb/manifest.json,OnePondo/Types/Detail.php}`, `src/Adapters/` (21 site dirs)
- `projects/client/src/Client/ClientBuilder.php:161`, `src/Transport/Curl/CurlProxy.php`
- `projects/bds-crawler/`: `README.md`, `schema.sql`, `AGENTS.md`, `docs/02-concepts/architecture.md:14-18`
- `projects/go-crawlerx/ARCHITECTURE.md`, `AGENTS.md`
- `projects/laravel-controller/{README.md,AGENTS.md}`, `projects/laravel-repository/{README.md,AGENTS.md}`
- `PROJECTS.md`, `.ai/guides/subagent-delivery.md`, `context-collection` skill

**3. Decisions & rationale:** AD-1..AD-18 (mục 10), mỗi decision map tới REQ/AC/BR/NFR cụ thể. Ba mục tiêu chốt tại gate: OQ-11, hệ quả AD-10, OQ-12 (mục 11).

**4. Open questions / blockers:** **Không còn blocker kiến trúc.** OQ-1 resolved; OQ-11 / AD-10 hệ quả / OQ-12 resolved tại gate. Còn mở nhưng **không chặn design**: Wave 1 source list (chốt ở planning bằng `live-check`), OQ-4 (sau báo cáo POC), OQ-13 (tên sản phẩm, không chặn kỹ thuật).

**5. Policy / quality failures:** Không có. Bản ghi này do `joo-doc-writer` chép từ ADR đã duyệt + 3 quyết định owner-approved do root cung cấp; không phát sinh nội dung mới.

**6. Recommended next gate:** **Planning** (`joo-team-lead`, input: ADR này + `BUSINESS.md`). Việc đầu tiên của planning nên là **Wave 0 + `live-check` thực nghiệm** để đóng băng source list (R-1) trước khi breakdown REQ-2a.

**7. Confirmation:** Không có side effect ngoài phạm vi ghi doc. Không sửa code, không commit, không push, không mở PR, không tạo repo, không đổi cấu hình.
