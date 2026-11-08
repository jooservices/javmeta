# DESIGN DISCUSSION / HANDOVER — javmeta primary DB, merge, field catalog, WIP

Status: **Working notes for the next agent** (not an approved gate doc).  
Date captured: **2026-09-18**.  
Language: Vietnamese (identifiers English).  
Do **not** delete this file. Owner asked to preserve the full discussion so another AI can continue.

**Related canon (approved gates — still source of truth until owner revises):**

| Doc | Path | Gate |
|---|---|---|
| BUSINESS | `projects/javmeta/BUSINESS.md` | Requirements Approved 2026-09-17 |
| ARCHITECTURE | `projects/javmeta/ARCHITECTURE.md` | Architecture Approved 2026-09-17 |
| IMPLEMENTATION | `projects/javmeta/IMPLEMENTATION.md` | Planning Approved 2026-09-17 |
| Overview mirrors | `docs/01-projects/jvmeta/{BUSINESS,ARCHITECTURE}.md` | pointers only |

**This file** records owner pushback + design discussion that **may supersede parts of ADR** (especially field richness + canonical bio + simpler ops mental model). Next AI must get owner confirmation before rewriting BUSINESS/ARCHITECTURE.

**Phạm vi lúc ghi:** chỉ viết kiến thức / hướng thiết kế. **Không implement** trong phiên thảo luận này.

**Nguồn đã dùng:** docs trên + `database/migrations/2026_09_18_000001_create_jvmeta_schema.php` + `app/Services/Merge/MovieMerger.php` + `MovieDraft`/`PerformerDraft` + crawlerx `MovieDto`/`PerformerDto` + WIP 10/15 + audit độ phức tạp + ý owner.

---

## 0. Tóm tắt điều owner muốn

Hệ thống POC nên **đơn giản về vận hành**:

```text
commands / tick theo nguồn
    → buffer queue (DB đơn giản)
    → scheduler nhặt item
    → worker pool chạy crawl thật
    → ghi Postgres (movies + bio)
    → API fetch (+ MCP sau)
```

Phần “não” thật sự cần làm đúng:

1. **Primary DB** lưu movie + bio đủ field hữu ích.  
2. **Detect** cùng movie / cùng người giữa nhiều site.  
3. **Merge** dữ liệu an toàn (ưu tiên không gộp nhầm).

---

## 1. Audit độ phức tạp (đã thống nhất)

### Đúng lõi (giữ)

| Thành phần | Trong plan/code hiện tại |
|---|---|
| Buffer queue | `crawl_queue` |
| Schedule → pool | `crawl:tick` + `queue:work` (DB driver) |
| Postgres primary | `movies` + `performers` (+ quan hệ) |
| Fetch engine | `crawlerx` (không viết lại scraper) |
| API | lookup / search / bulk / performers / keys |

### Nặng hơn mức “POC dễ” (có thể cắt sau, không phải xương sống sai)

- `movie_observations` append-only + ConflictPolicy đầy đủ  
- AIMD + circuit breaker nhiều trạng thái  
- Report admin / go-no-go harness lớn  
- Search indexes kiểu 400k ngay Wave 1  
- Telegram + watchdog (ops)

### Gap so với ý owner

- **MCP:** chưa có trong ADR/IMPLEMENTATION.  
- **Field movie/bio:** schema/draft mỏng hơn catalog hữu ích; crawlerx có field mà javmeta đang drop.  
- **Bio cross-site:** ADR Wave 1 = 1 người / source; owner muốn **1 người = 1 row**.

**Verdict:** kiến trúc hàng đợi không sai; thiếu chủ yếu ở **field catalog + bio identity + MCP**, cộng lớp đo lường/go-no-go làm plan trông phức tạp.

---

## 2. WIP hiện tại (handover)

### Đã xong (~10/15)

W0-001 live-check (20 nguồn) · W0-002 scaffold · W0-003 schema 16 bảng · W0-004 DTO/VO · W1-005 tick/throttle/circuit · W1-006 crawlerx jobs/normalizers · W1-007 merge/persist · W1-009 API keys · W1-010 lookup/bulk · W1-012 performer API.

### Còn lại

| Task | Ghi chú |
|---|---|
| W1-011 search/cursor | critical path — chạy lại |
| W1-008 health + Telegram + watchdog | song song được với W1-013 |
| W1-013 admin reports | |
| W1-014 worker runtime | sau W1-008 |
| W1-015 POC go/no-go harness | cuối |

**Sau code:** reviewer (đặc biệt W1-007) → QA → reporter → owner go/no-go.  
**Commit:** chỉ khi owner bảo; POC đang `main` → cần `develop`/`master` trước commit đầu.

### Hệ quả cho plan DB

Không “đập hết 16 bảng” chỉ vì sunk cost — nhưng **được phép đổi schema có chủ đích** khi field catalog + bio identity đã chốt (additive hoặc refactor có kiểm soát). Quyết định A/B ở mục 8 **sau** khi duyệt field list.

---

## 3. Luồng dữ liệu mục tiêu (đơn giản)

```text
┌─ ingest ──────────────────────────────────────────────┐
│  Artisan per source hoặc crawl:tick                   │
│       ↓ enqueue                                       │
│  crawl_queue   ← BUFFER QUEUE (DB)                    │
│       ↓ claim                                         │
│  queue workers ← POOL thật (crawlerx tryCrawl)        │
│       ↓                                               │
│  SourceNormalizer → MovieDraft / PerformerDraft        │
│       ↓                                               │
│  Identity resolve (movie code / performer canonical)  │
│       ↓                                               │
│  Merge fields + persist transaction                   │
└───────────────────────────┬───────────────────────────┘
                            ▼
┌─ primary Postgres ────────────────────────────────────┐
│  movies (+ codes, genres, media, credits…)             │
│  performers (+ sources, aliases)                      │
│  (ops: sources, crawl_*, api_*)                       │
└───────────────────────────┬───────────────────────────┘
                            ▼
              HTTP API  ·  MCP (phase sau)
```

Ranh giới:

- `crawlerx` = fetch/parse only.  
- javmeta = queue, throttle, normalize, identity, merge, persist, API/MCP.

---

## 4. Primary DB — nhóm bảng (hiện trạng + hướng mở rộng)

### 4.1 Hiện đã có (16 bảng)

**Consumer primary**

- `movies`, `movie_codes`, `genres`, `movie_genres`, `movie_media`, `movie_performers`  
- `performers`, `performer_aliases`

**Provenance / review**

- `movie_observations`, `review_flags`

**Ops / auth**

- `sources`, `crawl_queue`, `crawl_runs`, `crawl_events`  
- `api_keys`, `api_usage_log`

### 4.2 Hướng mở rộng đã thảo luận (chưa implement)

- Denormalize `movies.cover_url` (+ optional thumb)  
- `movies.description`  
- `movies.attrs jsonb` (extended sparse)  
- `movie_credits` hoặc tương đương (directors / actors)  
- Bio: birth/height/size/bio/location/debut/retired (+ `attrs`)  
- `performer_sources` nếu chuyển canonical bio  
- MCP surface — **sau** API ổn

---

## 5. Movie — cấu trúc & merge

### 5.1 Identity (giữ)

- 1 row `movies` = 1 movie (BR-1).  
- Detect cùng phim: **`NormalizedCode` → exact `code_normalized`**.  
- Thêm: source-declared linkage (nếu có).  
- **Không** fuzzy title Wave 1.  
- Prefix giữ nguyên: `FC2-PPV-…` ≠ `FC2-…`.

```text
SSIS-001 / ssis001 / SSIS-1 → SSIS001 → cùng movie
```

### 5.2 Merge fields

1. Resolve `movie_id` (`MovieMerger` hiện tại đúng hướng exact-only).  
2. Ghi provenance (observations hoặc snapshot — có thể đơn giản hóa sau).  
3. Scalar: fill-empty + source priority; null không đè non-null.  
4. Genres: **union**.  
5. Media URLs: upsert theo `(kind, url)` — **không cache bytes (BR-4)**.  
6. Soft-404 / sharp-drop: giữ giá trị cũ, `needs_review`.

### 5.3 Ví dụ

| Case | Kết quả |
|---|---|
| javdb + javdatabase cùng `SSIS-001` | 1 movie, field merge |
| DVD + uncensored mã khác, không linkage | 2 movies (false-split OK Wave 1) |
| Cover từ A, magnet từ B | 1 movie; cover + magnet cùng tồn tại |

---

## 6. Bio — cấu trúc & merge

### 6.1 Hiện tại (ADR / code)

- Identity `(source_slug, external_id)` → **không** gộp cross-site.  
- Trùng tên ≠ cùng người (AC-5.2).  
- `PerformerDraft` chỉ name/alias/urls — **drop** birth/height/size/rawProfile từ crawlerx.

### 6.2 Hướng owner muốn

- **1 người = 1 row canonical.**  
- Bảng link nguồn: `performer_sources (performer_id, source_slug, external_id)`.  
- Detect merge bio chỉ khi tín hiệu mạnh (cùng external id; hoặc alias + birthday; không auto theo tên thuần).  
- False-merge bio = nghiêm trọng hơn false-split.

### 6.3 Ví dụ

| Case | ADR cũ | Hướng mới |
|---|---|---|
| Cùng idol javdb + onejav | 2 rows | 1 canonical + 2 source links (nếu đủ tín hiệu) |
| Trùng tên khác birthday | 2 rows | 2 rows |
| Chỉ trùng romaji | 2 rows | 2 rows (không merge) |

---

## 7. Field catalog hữu ích (chốt trước khi đụng schema)

Nguyên tắc owner: **crawlerx là nguồn tốt; field hữu ích lấy từ bất kỳ đâu được — trước hết liệt kê field, sau mới quyết nguồn.**

### 7.1 Movie — core (nên typed / query được)

| Field | Có hôm nay? | Ghi chú |
|---|---|---|
| code / dvd id / normalized | ✅ | |
| content_id | ❌ | FANZA/DMM sau |
| title_jp / title_en | ✅ | |
| **description** | ❌ (crawlerx có, javmeta drop) | **thêm** |
| release_date | ✅ cột (sample có thể null do map) | fix normalize |
| runtime_minutes (length) | ✅ | |
| maker / label / series | ✅ | |
| directors[] / actors[] | ❌ | credits |
| performers / actresses | ✅ quan hệ | |
| genres (union) | ✅ | |
| censored | ✅ | |
| community_score | ✅ | |
| **cover_url** denormalized | ⚠ chỉ media/observation | **denormalize lên movies** |
| cover_thumb_url | ❌ | media hoặc cột |
| gallery[] (+ thumbnails URL) | ⚠ `movie_media` | API phải expose |
| sample / magnets / hls / pikpak | ⚠ media | URL only |
| crawled_at / updated_at / provenance | ✅ | |

### 7.2 Movie — extended → `attrs` jsonb / media meta

site, service_code, subtitle flags, raw provider keys, trailer variants…

### 7.3 Bio — core

| Field | Có hôm nay? | Ghi chú |
|---|---|---|
| canonical id | ⚠ per-source only | đổi model |
| name romaji/kanji/kana | ✅ | |
| aliases | ✅ | |
| profile_url / image_url | ✅ | |
| **birth_date** | ❌ (crawlerx `birthDateRaw`) | **thêm** |
| **height_cm** | ❌ (`heightRaw`) | **thêm** |
| **bust/waist/hip/cup** | ❌ (`sizeRaw`) | parse + cột/attrs |
| **bio text** | ❌ (`rawProfile`) | **thêm** |
| linked_title_count | derived | |

### 7.4 Bio — extended

location/prefecture, debut/joined, retired, blood_type, hobby/skill, tags…  
→ cột null phổ biến **hoặc** `attrs jsonb`.

### 7.5 Phân loại “thiếu” (để khỏi nhầm schema)

- **A.** Có nhưng nằm quan hệ (cover/gallery) → denormalize / expose API.  
- **B.** Crawlerx có, javmeta drop (description, birth, height, size, bio).  
- **C.** Có cột nhưng data null (release/runtime map).  
- **D.** Wishlist cần nguồn giàu hơn (FANZA/idol DB).  
- **E.** Đổi identity bio (canonical).

**Lưu ý scope:** BUSINESS từng hoãn body metrics sau POC; đưa H/W/C/debut vào POC = **đổi REQ-5** — ghi nhận khi duyệt catalog.

---

## 8. Quyết định schema sau khi duyệt field list

### Hướng A — Additive + canonical bio (khuyến nghị mặc định)

- ALTER thêm cột core + `attrs`.  
- Denormalize cover.  
- Thêm `performer_sources` + migration identity.  
- Sửa Draft / normalizer / persister / API Resource.  
- Giữ queue/API auth/crawl ops.

### Hướng B — Full refactor primary tables

- Thiết kế lại `movies`/`performers` theo catalog “đủ dùng lâu dài”.  
- Có thể cắt bớt bảng phụ nếu muốn đơn giản.  
- Viết lại merge/persist/resources; trì hoãn W1-011…015.

**Chưa chọn A/B cuối** cho đến khi owner duyệt mục 7 (core vs extended) + xác nhận canonical bio ngay.

---

## 9. MCP & API

- Wave hiện tại: REST đã có đường lookup/search/performer.  
- **MCP:** phase riêng sau khi primary read model ổn (tools: lookup code, search, get performer).  
- Contract field API phải khớp catalog đã chốt (null tường minh, URL only cho media).

---

## 10. Việc làm tiếp (chỉ sau khi owner duyệt plan này)

Thứ tự đề xuất:

1. **Owner duyệt** field catalog mục 7 (core bắt buộc / extended / bio metrics có vào POC?).  
2. **Owner chốt** canonical bio ngay vs hoãn.  
3. **Chọn** Hướng A hoặc B (mục 8).  
4. Cập nhật `ARCHITECTURE.md` (+ chỉnh `BUSINESS` nếu đổi REQ-5).  
5. Thêm ticket schema/field/identity vào IMPLEMENTATION; chen trước report nếu cần.  
6. Tiếp WIP còn lại: W1-011 → … → W1-015; reviewer/QA/reporter.  
7. MCP ticket riêng.  
8. Commit/GitHub chỉ khi owner yêu cầu.

---

## 11. Out of scope (bước viết plan)

- Mọi thay đổi code / migration / commit / PR  
- Fuzzy merge movie  
- Cache/rehost media  
- Billing / takedown / webhook  
- Sửa crawlerx trừ khi sau này cần proxy Wave 2  

---

## 12. Open questions còn chờ owner

1. Movie core: bắt buộc có `description`, `cover_url` trên `movies`, `directors`?  
2. Bio POC: birthday / H-W-C / location / debut / retired — vào ngay hay hoãn?  
3. Canonical performer **ngay** (đổi AD-9) hay Wave 2?  
4. Extended → `attrs jsonb` có OK?  
5. Sau list: **A additive** hay **B full refactor**?  
6. MCP nằm trong POC go/no-go hay phase sau?

---

## 13. Timeline thảo luận (session này)

1. Owner: audit plan — cảm giác over-complex; muốn model đơn giản: multi-command / buffer queue / pool / Postgres / API+MCP.  
2. Root audit: xương sống **đã đúng**; phức tạp nằm ở observations/AIMD/reports/ops; **MCP thiếu**.  
3. Owner: hỏi thiết kế primary DB movie/bio + detect/merge cross-site.  
4. Root: giải thích ADR (exact code merge; bio per-source) + model đơn giản hơn + ví dụ.  
5. Owner: muốn DB structure cụ thể + ghép WIP handover (10/15 từ AI khác).  
6. Owner chọn: **giữ 16 bảng** (lúc đó) — chỉ làm rõ ERD/merge.  
7. Owner phản biện: **why not full refactor?** — thiếu nhiều field (desc, release, length, cover, gallery, thumbs; bio birthday, H/C, location, joined/retired…).  
8. Owner: chốt **field list trước**, rồi mới chọn mức refactor; nguồn = crawlerx tốt nhưng lấy thêm từ đâu cũng được nếu hữu ích.  
9. Root: inventory + gap matrix (crawlerx có / javmeta drop) + hướng A/B + canonical bio.  
10. Plan mode abandoned trên UI — owner nói **không xóa plan**, ghi hết knowledge để AI khác hoàn thiện → **file này**.

---

## 14. Key file paths (cho AI tiếp theo)

### Docs

- `projects/javmeta/BUSINESS.md`
- `projects/javmeta/ARCHITECTURE.md`
- `projects/javmeta/IMPLEMENTATION.md`
- `projects/javmeta/DESIGN-DISCUSSION.md` ← **this file**
- `docs/01-projects/jvmeta/` (short pointers)

### Schema / models

- Migration: `projects/jvmeta/database/migrations/2026_09_18_000001_create_jvmeta_schema.php`
- Models: `projects/javmeta/app/Models/{Movie,MovieCode,MovieMedia,MovieObservation,Performer,PerformerAlias,MoviePerformer,CrawlQueue,Source,…}.php`

### Crawl → merge → persist

- `app/Console/Commands/CrawlTickCommand.php`
- `app/Services/Crawl/{CrawlQueueService,SourceThrottle,SourceCircuitBreaker}.php`
- `app/Jobs/{FetchListingJob,FetchDetailJob}.php`
- `app/Services/Crawler/` (CrawlerxClient)
- `app/Services/Normalize/Normalizers/{Default,Catalog,Jable}SourceNormalizer.php`
- `app/Services/Merge/{MovieMerger,ConflictPolicy,SourcePriorityConflictPolicy}.php`
- `app/Services/Persist/{MoviePersister,CompletenessTierCalculator}.php`
- `app/Support/Code/NormalizedCode.php`
- `app/Data/Crawl/{MovieDraft,PerformerDraft}.php`

### API (partial)

- `routes/api.php`
- Controllers: MovieLookup, MovieBulk, MovieSearch, Performer, MetaGenre, Admin ApiKey
- Auth: `app/Services/Auth/ApiKeyService.php` + middlewares

### crawlerx (external lib, path repo)

- `projects/crawlerx/src/Dto/Entity/MovieDto.php` — externalId, title, code, coverUrl, **description**, date, duration, performers[], tags[], screenshots[], metadata{}
- `projects/crawlerx/src/Dto/Entity/PerformerDto.php` — name, nameJapanese, url, profileImageUrl, **birthDateRaw**, **heightRaw**, **sizeRaw**, aliases, tags, **rawProfile**, metadata{}
- 21 JAV adapters under `projects/crawlerx/src/Adapters/`
- **No FANZA/DMM adapter**; **no proxy** wired in crawlerx yet (Wave 2)

---

## 15. Schema columns snapshot (movies / performers) — as of W0-003

### `movies`

`id`, `display_code`, `code_normalized` UNIQUE, `title_jp`, `title_en`, `release_date`, `runtime_minutes`, `censored`, `maker`, `label`, `series`, `community_score`, `completeness_tier`, `search_vector` (PG generated), `delisted_at`, `needs_review`, `first_seen_at`, `updated_at`, `crawled_at`

**Missing vs owner wishlist / crawlerx:** `description`, denormalized `cover_url` / thumb, directors/actors, `attrs`.

### `movie_codes`

`movie_id`, `code`, `code_normalized`, `kind`, `source_slug`, `source_url`, `crawled_at` — UNIQUE `(code_normalized, source_slug)`

### `movie_media`

`movie_id`, `kind` (magnet|pikpak|hls|gallery|sample|…), `url`, `meta` jsonb, `source_slug`, `crawled_at` — UNIQUE `(movie_id, kind, url)`  
Cover often treated via observation field `cover_url` + media — not a first-class `movies.cover_url` column.

### `movie_observations`

Append-only per `(movie_id, field, value, value_hash, source_slug, …, is_primary)`

### `performers`

`id`, `source_slug`, `external_id`, `name_romaji`, `name_kanji`, `name_kana`, `profile_url`, `image_url`, `crawled_at` — UNIQUE `(source_slug, external_id)`

**Missing:** birth, height, B/W/H/cup, bio, location, debut, retired, canonical cross-source, `attrs`.

### `crawl_queue` (= buffer queue)

`source_slug`, `url`, `kind`, `status` (pending|claimed|done|failed|skipped), attempts, max_attempts, next_attempt_at, claimed_at, locked_by, last_error — UNIQUE `(source_slug, url)`

---

## 16. MovieMerger behavior (code truth)

File: `app/Services/Merge/MovieMerger.php`

- Merge **ONLY** on exact `NormalizedCode::from(draft.code)->value()`.
- Lookup `movie_codes.code_normalized`, else `movies.code_normalized`, else create movie.
- No fuzzy title match.
- Source-declared linkage: documented as allowed; Wave 1 normalizers largely do not emit extra linkage codes yet.
- Owner previously accepted exact-only at architecture gate (AD-10); false-split OK, false-merge not.

`NormalizedCode`: upper, strip separators, pad numeric suffix to 3, **keep prefix** (FC2PPV… ≠ FC2…).

---

## 17. Competitor field hints (javinfo.dev — for catalog parity thinking)

From public docs/quickstart samples (not scraped live with a key):

**Movie result-ish fields:** `contentId`, `dvdId`, `titleEn`, `titleJa`, `commentEn`/`commentJa`, `runtimeMins`, `releaseDate`, `makers[]`, `label`, `series`, `categories[]`, `actresses[]`, `actors[]`, `directors[]`, `jacketFullUrl`, `jacketThumbUrl`, `extra.sampleUrl`, `extra.galleryFull[]`, plus provider-specific magnets/streams/PikPak on some sources.

**Filters on /query:** genre, actress, maker, series, director, label, actor, censored, runtimeMin/Max, releaseAfter/Before, availability.

Use as **wishlist reference**, not as license to copy their schema blindly. javmeta differentiator (per BUSINESS): largest coverage + freshness; Wave 1 without FANZA yet.

---

## 18. Approved ADR decisions that still matter

Keep unless owner explicitly overturns:

- AD-1 All-PHP Laravel + crawlerx  
- AD-2 PostgreSQL 17  
- AD-3 FANZA/DMM deferred Wave 2  
- AD-4 Do not modify crawlerx in Wave 1  
- AD-6 DB queue (no Redis POC)  
- BR-4 / D-22: **URL only**, no media cache  
- D-23 / BR-1: one movie row; variants merge when identity allows  
- D-24: genres = **union**, no canonical taxonomy  
- AD-10: exact-only movie merge Wave 1 (may keep even if bio model changes)

**Likely overturn / extend after this discussion:**

- AD-9 performer identity per-source only → owner wants canonical  
- REQ-5 “body metrics deferred” → owner wants birthday/H/C/location/dates in catalog  
- Field surface of `movies` / API resource richer than current 11 core fields  
- MCP as product surface (never in ADR)

---

## 19. Recommended next steps for the continuing AI

1. Read this file + `BUSINESS.md` + `ARCHITECTURE.md` + `IMPLEMENTATION.md`.  
2. Ask owner to answer **§12 open questions** (especially field core list + canonical bio + A vs B).  
3. Produce an updated ADR section (or patch ARCHITECTURE) **only after** those answers.  
4. If Hướng A: write additive migration plan + Draft/DTO/normalizer/persister/API resource tasks; insert into IMPLEMENTATION before or beside remaining W1 tasks.  
5. If Hướng B: freeze WIP feature movie; redesign primary tables; re-verify W1-007/010/012.  
6. Do **not** commit/push/PR without owner ask.  
7. Do **not** weaken BR-4 (no media bytes).  
8. Preserve buffer-queue mental model in docs even if ops layers stay.

---

## 20. Explicit non-goals (unless owner reopens)

- Implementing in the discussion session that produced this file  
- Fuzzy movie title merge in Wave 1  
- Rehosting/caching images or video  
- Billing / crypto / takedown / webhook (REQ-D*)  
- Changing host OS / inventing a second crawl stack instead of crawlerx  

---

## 21. One-page cheat sheet

```text
BUFFER:  crawl_queue
POOL:    queue:work + crawlerx
PRIMARY: Postgres movies + performers
MOVIE ID: exact NormalizedCode (keep)
BIO ID:   today per-source → owner wants canonical + performer_sources
MERGE:    fill-empty + source priority; genres union; media URL upsert; no null overwrite
GAPS:     description, cover denorm, bio body fields (dropped from crawlerx), MCP
WIP:      ~10/15 done; remain 011,008,013,014,015 + gates
NEXT:     approve field catalog → choose A/B → update ADR → then code
```
