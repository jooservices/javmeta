# crawlerx fixtures

Live-captured pages copied from `jooservices/crawlerx` (`tests/Fixtures`), one
folder per javmeta source slug. Each body has a `.meta.json` with the source
URL, capture time and page type. `SOURCE.json` records the crawlerx version.

Used by `tests/Feature/Contract`: the real crawlerx stack parses them while
only the HTTP transport is faked (`ClientBuilder::fake()`).

Refresh after upgrading crawlerx:

```bash
docker/ci/run php tools/sync-crawlerx-fixtures.php
```
