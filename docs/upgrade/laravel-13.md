# Laravel 13 upgrade checklist

Reviewed the [Laravel 13 upgrade guide](https://laravel.com/framework/docs/13.x/upgrade) against this repository. Search results below cover `composer.json`, `app/`, `bootstrap/`, `config/`, `database/`, `routes/`, `resources/`, and `tests/`; matches were inspected in context.

| Upgrade guide item | Result and repository evidence |
| --- | --- |
| Dependencies | Applied: `composer.json` now requires `laravel/framework:^13`, `laravel/tinker:^3`, and `phpunit/phpunit:^12`. `laravel/boost`, `pestphp/pest`, and `laravel/installer` are not project dependencies. |
| Laravel installer | N/A: this application does not install or use the Laravel installer CLI (`rg -n 'laravel/installer' composer.json`). |
| Cache and Redis prefixes; session cookie | N/A: the app defines explicit cache, Redis, and session cookie configuration in `config/cache.php`, `config/database.php`, and `config/session.php`; it does not rely on framework fallback prefixes. |
| Cache `Store` / `Repository` `touch` contract | N/A: no custom cache store or repository implementation exists (`rg -n 'implements.*(Store|Repository)' app`). |
| Cache `serializable_classes` | Applied: `config/cache.php` sets `false`. Cache call sites store timestamps, scalars, and arrays; no application cache object payloads need an allowlist. |
| `Container::call` nullable defaults | N/A: no direct container `call` usage (`rg -n 'Container::call|container\(\)->call|app\([^)]*\)->call' app bootstrap routes tests`). |
| Bus dispatcher, response factory, email verification contracts | N/A: no custom implementations of `Dispatcher`, `ResponseFactory`, or `MustVerifyEmail` exist (`rg -n 'implements.*(Dispatcher|ResponseFactory|MustVerifyEmail)' app`). |
| MySQL / MariaDB `upsert` validation | N/A: the application uses PostgreSQL; its two Eloquent `upsert` calls supply conflict columns (`app/Services/Persist/MoviePersister.php`). |
| MySQL joined `DELETE` behavior | N/A: the application database is PostgreSQL and has no MySQL-specific joined delete queries (`rg -n 'delete.*join|join.*delete' app`). |
| Model booting and nested instantiation | N/A: the only model boot hook is `Performer::booted`; it registers callbacks and does not instantiate the model (`app/Models/Performer.php`). |
| Polymorphic pivot table names | N/A: no polymorphic pivot relationships or custom pivot models exist (`rg -n 'morphToMany|morphedByMany|MorphPivot' app`). |
| Eloquent collection serialization in queued jobs | N/A: queued application jobs carry scalar identifiers and do not depend on restored collection relations (`app/Jobs/`). |
| HTTP response `throw` signatures | N/A: no custom HTTP response class overrides `throw` or `throwIf` (`rg -n 'function (throw|throwIf)' app`). |
| Password reset subject; queued notifications with missing models | N/A: the API uses API-key authentication and defines no password reset or queued notification flow (`app/Models/User.php`, `app/Notifications/`). The phrase in crawler fixtures is captured page content only. |
| `JobAttempted` exception payload; `QueueBusy` property | N/A: no listeners or references to these event properties exist (`rg -n 'JobAttempted|QueueBusy|exceptionOccurred|connectionName' app`). |
| Queue contract additions | N/A: the app uses Laravel's built-in queue drivers and has no custom `Illuminate\\Contracts\\Queue\\Queue` implementation (`rg -n 'implements.*Queue' app`). |
| Domain route precedence | N/A: routes have no explicit domain or catch-all subdomain route (`routes/`). |
| Session serialization | Applied: `config/session.php` explicitly selects JSON serialization. The application uses API keys rather than session login; no PHP session objects are stored. |
| `withScheduling` registration timing | N/A: scheduled commands are registered from `routes/console.php`; there is no `withScheduling()` bootstrap call. |
| CSRF middleware rename and origin check | N/A: the application exposes stateless API routes and has no direct CSRF middleware references or exclusions (`rg -n 'VerifyCsrfToken|ValidateCsrfToken|PreventRequestForgery' app bootstrap routes tests`). |
| Manager `extend` callback binding | N/A: no framework manager `extend` driver registrations exist (`rg -n '::extend|->extend\(' app/Providers app/Services bootstrap`). |
| `Str` factories reset between tests | N/A: tests do not register UUID, ULID, or random-string factories (`rg -n 'createUuidsUsing|createUuidsNormally|createRandomStringsUsing' tests`). |
| `Js::from` Unicode behavior | N/A: the application has no `Js::from` use (`rg -n 'Js::from' app resources tests`). |
| PHP 8.5 array polyfill conflicts | N/A: the project requires PHP `^8.5`, has no `laravel/helpers` dependency, and does not call global `array_first()` / `array_last()` helpers (`composer.json`, `rg -n '\\barray_(first|last)\\s*\\(' app tests`). |
| Pagination Bootstrap view names | N/A: no application code references Laravel's legacy pagination view names (`rg -n 'pagination::(simple-)?default' app resources tests`); API pagination is JSON. |
| Skeleton / miscellaneous changes | Reviewed: no installer, Boost, or starter-kit files are used. The project-specific session and cache security defaults above are the relevant config changes. |

## Verification

- `composer validate --strict`
- `composer audit --no-interaction`
- `composer ci` (lint, PHPUnit coverage, 90% coverage threshold, shell checks)
- `php artisan about` and `php artisan route:list`
- Shared-service smoke checks: `/api/health`, API-key verification, and one fixture-backed `FetchDetailJob` using the isolated `javmeta26_it` namespace (record final results in the PR).
