# Settings (all in the database)

Every feature switch and operational value is stored in the database (the `jooservices/laravel-config` store, group `settings`, MongoDB) and edited on the **Cài đặt** page of the UI (`/app#/settings`, section "Cấu hình hệ thống"). The env file keeps only what is needed to reach the databases themselves (hosts, passwords, `APP_KEY`).

- **Where the list is:** `config/javmeta_settings.php` defines every setting: group, label, type (`int`, `float`, `bool`, `string`, `secret`, `int_list`), limits and the Laravel config keys it drives. Add a new setting there and it appears on the page.
- **How a value takes effect:** `SettingsServiceProvider` pushes the saved values into the Laravel config when the app boots, before every queue job and before every console command, so a change reaches Horizon workers and the scheduler within seconds, without a deploy. A setting labelled "khởi động lại" (worker count, job timeout) is read once by Horizon: restart `javmeta-horizon` after changing it.
- **Secrets** (the Telegram bot token) are stored encrypted, are write-only on the page (it only shows whether one is set) and never appear in the telemetry of a change.
- **Before a value is saved** the config default applies (which may still come from env). `php artisan settings:import-env` copies those current values into the database once (it skips what is already there and empty secrets), after which the env entry for that key is ignored and can be deleted.
- **Audit:** every change is sent to OpenObserve as the ops event `setting_changed` with the key, the signed-in user and the old and new value (not for secrets).
- **API:** `GET /ui-admin/settings` and `PUT /ui-admin/settings` with `{"settings": {"queue.feed_pool_limit": 300}}`, behind the owner login. Invalid values answer 422 with a message per key.

New features (Telegram follow notifications and bot, the library scanner) read their configuration only from here.

## Hybrid search HTTP budget

`search.budget_ms` (default 300, range 25–1500, group Tìm kiếm) maps to `javmeta_search.budget_ms` through the database settings service. It limits optional HTTP work within one hybrid search. SQL and response serialization can exceed that budget. A timeout falls back to keyword results; the existing response keys remain unchanged. No environment variable is needed. See [performance](performance.md) for measurements and acceptance limits.
