# API field catalog

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
