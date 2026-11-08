# Personal library

The scanner finds out which movies are already on disk. It reads file names and sizes under the folders you choose, recognises a movie code in each name (`SSIS-123`, `ssis123`, `[x] ABP_045 1080p`), matches it to a JavMeta movie and records path, size and a quick hash (size plus the first and last kilobytes). It uploads nothing and never changes or deletes a file. A file that vanishes is marked missing; unmatched files are listed on the Library page for review.

Everything is configured in the UI (**Cài đặt → Thư viện trên đĩa**), stored in the database: the switch, the folders (one path per line), the video extensions, the hour of the daily scan and the number of kilobytes hashed. `php artisan library:scan --force` scans now; the scheduler runs `library:scan` every hour and it scans only at the configured hour.

The folders must be visible to the PHP runtime that runs the scheduler (the shared `workspaces-cli-php-8.5` container on WSL). The 20 TB disk is on Windows and is not mounted in WSL yet, so the library stays empty until that disk (or a share of it) is mounted and mapped into the runtime; the scanner reports an unreadable folder instead of failing.

In the UI: the **Thư viện** page (totals, disk use by folder, the movies on disk, unmatched files), an "Trên máy" badge on every movie card, and the "Trên máy" filter of the movie list (`/api/v1/movies?on_disk=1` or `0`).
