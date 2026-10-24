# Sample dataset

A repeatable fake collection for tests and UAT, made without crawling anything: no scraped text, no images, no real people.

| Command | What it does |
|---|---|
| `php artisan dataset:load sample` | Loads 520 movies (`SMP-0001` to `SMP-0520`), 210 performers (source `sample`), 30 genres (`sample-01`...), 20 makers, 10 series, and the links between them. Safe to run twice: the second run changes nothing. |
| `php artisan dataset:load sample --index` | Same, and indexes the rows in Elasticsearch. |
| `php artisan dataset:wipe sample` | Removes exactly the rows the load created and nothing else. |

Edge cases inside it: a delisted movie (`SMP-0001`), movies known by two sources (`SMP-0002` to `SMP-0011`), performers with almost no bio (every seventh), non-Latin names and titles, one performer with three aliases (`p0001`), movies without a title, runtime, score or series.

Do not load it into the staging database that holds the real crawl: the sample movies would show up in lists, counts and the hourly report. Use it in tests (the feature tests load it into SQLite) or in the UAT environment.
