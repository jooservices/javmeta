# Settings (all in the database)

Every feature switch and operational value is stored in the database (the `jooservices/laravel-config` store, group `settings`, MongoDB) and edited on the **Cài đặt** page of the UI (`/app#/settings`, section "Cấu hình hệ thống"). Use the UI for feature configuration; legacy config defaults can still read env until overridden in the store. New feature defaults must be in `config/javmeta_*.php` without `env()`. Bootstrap database connectivity and `APP_KEY` remain deployment concerns.

- **Where the list is:** `config/javmeta_settings.php` defines every setting: group, label, type (`int`, `float`, `bool`, `string`, `secret`, `int_list`, `string_list`), limits and the Laravel config keys it drives. Add a new setting there and it appears on the page.
- **How a value takes effect:** `SettingsServiceProvider` pushes the saved values into the Laravel config when the app boots, before every queue job and before every console command, so saved values are re-applied at those boundaries without a deploy. The effective delay depends on the next command/job. A setting labelled "khởi động lại" (worker count, job timeout) is read once by Horizon: restart `javmeta-horizon` after changing it.
- **Secrets** (the Telegram bot token) are stored encrypted, are write-only on the page (it only shows whether one is set) and never appear in the telemetry of a change.
- **Before a value is saved** the config default applies (which may still come from env). `php artisan settings:import-env` copies those current values into the database once (it skips what is already there and empty secrets), after which the env entry for that key is ignored and can be deleted.
- **Audit:** every change is sent to OpenObserve as the ops event `setting_changed` with the key, the signed-in user and the old and new value (not for secrets).
- **API:** `GET /ui-admin/settings` and `PUT /ui-admin/settings` with `{"settings": {"queue.feed_pool_limit": 300}}`, behind the owner login. Invalid values answer 422 with a message per key.

New features (Telegram follow notifications and bot, the library scanner) read their configuration only from here.

## User and developer references

[Cài đặt user instructions](../user-guide/settings.md) explain saving and troubleshooting. [The complete reference](../user-guide/settings-reference.md) is generated from the definition labels/descriptions by `tools/docs/settings-reference.php`; `SettingsReferenceTest` fails on drift. The [developer checklist](../developer-guide/extending.md) covers validation, defaults, mapped config keys and secret handling; [testing](../developer-guide/testing.md) contains the exercised regeneration command. The generator never reads stored values.

## Performance warming

`perf.warm_enabled` (default `true`, group Tìm kiếm) controls the scheduled `perf:warm` command. It appears automatically on Cài đặt and is applied from the database to `javmeta_perf.warm_enabled`. Turning it off stops proactive refreshes; request-driven caches still work. No environment variable is needed. See [performance](performance.md) for TTLs, subminute scheduling and verification limits.

## Hybrid search HTTP budget

`search.budget_ms` (default 300, range 25–1500, group Tìm kiếm) maps to `javmeta_search.budget_ms` through the database settings service. It limits optional HTTP work within one hybrid search. SQL and response serialization can exceed that budget. A timeout falls back to keyword results; the existing response keys remain unchanged. No environment variable is needed. See [performance](performance.md) for measurements and acceptance limits.

Media processing settings (`media.*`) are defined in `config/javmeta_media.php` and registered in `config/javmeta_settings.php`. They are database/UI settings, never environment variables. See [media.md](media.md) for queue limits and bootstrap credentials.

Stream capture settings (`capture.*`) default in `config/javmeta_capture.php`. Capture is off until the owner enables it on the Settings page; duration, output size, manifest count/size and HTTP timeout limits are editable there. Enabling capture never disables the DRM/content policy checks.

## Outbound base URLs and Telegram groups

All service URL settings (`media.api_url`, `search.embedder_url`, `ai.ollama_url`, `downloads.qbittorrent_url`, `telegram.api_base`) require a host and an HTTP(S) scheme. Telegram requires HTTPS; HTTP is allowed only for the exact hosts `localhost` and `127.0.0.1` for local tests. Base URLs reject userinfo, query strings and fragments, including empty `?` or `#`. Empty URLs may disable optional integrations. Keep credentials in their dedicated secret fields. Private network qBittorrent addresses remain supported.

A previously saved invalid URL is ignored during application boot and direct settings reads. A valid config default applies (otherwise the address stays empty), and the Settings page displays a warning without returning the unsafe value; save a valid address to clear it. URL credentials, queries and fragments are stripped from `setting_changed` events and URL text in local/remote logs.

`telegram.owner_user_id` is a positive integer, empty by default. For group/supergroup chats both `telegram.chat_id` and the sender's user ID must match; an unset owner refuses all group commands and download callbacks. Callback authorization uses `callback_query.from.id`, not the sender of the bot's original message. Private chats retain chat-ID authorization and do not require this setting.

`observability.fetch_attempts_retention_days` defaults to 14 in
`config/javmeta_observability.php` and maps to
`javmeta_observability.fetch_attempts_retention_days`. The daily
`crawler:prune-fetch-attempts` command applies the current database value.
See [fetch diagnostics](telemetry.md#fetch-attempts).
