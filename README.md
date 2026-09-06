# MyStack Framework

MyStack is a portable, zero-Composer PHP application framework that combines routing and security, prepared database access, component UI, PHP-native utility CSS, a self-contained browser runtime, authentication, payments, couriers, notifications, observability, real-time events, AI/MCP integration, and an intelligent CLI in one deployable codebase.

The framework is designed to run from a domain root or any subdirectory on Apache/LiteSpeed-compatible hosting without hardcoded project paths. This documentation describes only the reusable MyStack framework and its public contracts.

## Highlights

- Zero Composer/NPM runtime dependency
- Portable root/subfolder routing via `DIR`, `PHCO`, and `PHRO`
- Fluent HTTP router, middleware, CSRF and layered request guard
- MySQL/MariaDB CRUD, transactions, analytics, schema sync, and unbuffered streaming, with opt-in `PHDB::$driver` (`sqlite`, `pgsql`) — default mysqli path unchanged
- SQLite-backed local state with TTL, counters, tags, bounded locking, and recovery
- PHUI registry with 100+ theme-aware components (1,300+ entries) and PHML composition; render via `PHUI::ui()`, `@component/@slot`, or `<x-ui-*>` — e.g. `ui:calendar`, `ui:skeleton`, `ui:empty-state`, `ui:carousel`, `ui:command-palette`
- PHP-native Tailwind-style PHCS utility processing with an extensible handler registry and arbitrary `[prop:value]` fallback
- Self-contained PHJS SPA/reactivity/directive runtime served as `/app.js`, with declarative actions (`toggle/open/toast/navigate/copy/…`) and PHP browser-convenience emitters (`confirm`, `debounce`, `throttle`, `matchMedia`, `geolocation`, …)
- PHJC Blade-style views: `@extends/@section/@yield`, `@component/@slot`, `@class`, `@verbatim`, `@forelse`, `@each`, `@once`, form-attribute directives, and `PHML::init()` auto asset pipeline (`autoAssets` toggles the injected `/app.js`)
- Extended PHVD validation (IBAN, BIC, ISBN, IMEI, JWT, UTF-8/`utf8`/Bengali, numeric sign/multiple/decimal, time/timestamp/date_equals, and more)
- PHEM mail with an additive `PHEM::queue()`/`queueSend()` bridge to the local console queue
- Service Worker, page/cache de-duplication, offline and Web Push support
- OAuth/OIDC provider matrix, JWT, encryption, OTP/TOTP and Authenticator lifecycle
- Payment gateway and Bangladesh/international courier facades
- ntfy public/private delivery plus VAPID Web Push through PHFY
- WebSocket, SSE and StreamUI support
- Health/readiness probes, request/trace IDs, JSON logs, metrics and debug dashboards
- AI provider bridge and MCP tools/resources/prompts through PHAI
- Extensible console kernel with generators, inspection, migrations, local queue/scheduler, service checks, doctor, audit, smoke test and transactional updater

## Requirements

Minimum:

- PHP 8.1+
- `json`, `openssl`, `PDO`, and `pdo_sqlite`
- `mysqli` for PHDB/MySQL operations
- Writable `.mystack/` and `src/cache/{css,js,php}/` directories

Recommended/feature-specific:

- `curl` for remote HTTP, OAuth, AI, payment, courier and notification integrations
- `mbstring` for complete Unicode handling
- `zip`/`ZipArchive` for the MyStack updater and ZIP/package workflows
- `sockets` for native WebSocket serving
- HTTPS for secure cookies, OAuth, payment callbacks, Service Workers and Web Push
- Apache or LiteSpeed with rewrite/header support; PHP's built-in server works for development

No Composer or NPM installation is required.

## Project map

```text
/
├── index.php                 Application bootstrap and routes
├── mystack                   CLI, diagnostics and updater
├── mystack.cmd               Windows CMD/PowerShell launcher; `php mystack` remains supported
├── mystack.sh                Linux/macOS shell launcher (works without the executable bit)
├── library/                  30 framework libraries + loader
├── app/                      Flat backend/controller/model directory
├── component/                Flat view/component directory
├── src/
│   ├── js/                   PHJS and Service Worker sources/builds
│   └── cache/{css,js,php}/   Named compiled component cache
├── .mystack/                 Private local state and structured logs
├── .github/CODEOWNERS        Official review ownership
├── AGENTS.md                 Agent engineering contract
├── CONTRIBUTING.md           Public request and contribution workflow
├── NOTICE                    Ownership, attribution and official-brand notice
├── LICENSE                   Apache License 2.0
├── MANUAL_BN.md              Full Bengali manual
└── MANUAL_EN.md              Full English manual
```

Do not create subdirectories inside `app/` or `component/`. Use framework path resolution instead of hardcoded hosting paths.

## Quick start

1. Copy/clone the project to a web-accessible directory.
2. Confirm PHP and folder health:

```bash
php mystack doctor
php mystack smoke
```

3. Configure `index.php`:

```php
<?php
require_once 'library/library.php';

PHDE::debug(true);                 // false in production
PHDE::memory('512M');
PHTM::setZone('Asia/Dhaka');

PHMO::configure(['enabled' => true]);

PHRO::guard();
PHRO::key('replace-with-a-long-application-secret', false);
PHRO::track(false);

PHJT::key('replace-with-a-separate-jwt-secret');
PHJT::algorithm('HS512');

PHDB::$host = 'localhost';
PHDB::$username = 'root';
PHDB::$password = 'secret';
PHDB::$dbname = 'mystack_app_db';

import('app:HomeController');

PHRO::get('/', [HomeController::class, 'index']);
PHRO::listen();
```

MyStack does not automatically depend on `.env`, `APP_ENV`, or `APP_DEBUG`. If deployment secrets come from environment variables, read them explicitly in project bootstrap code and pass them to the appropriate MyStack APIs.

4. Run locally:

```bash
php mystack serve 8000
```

Open `http://127.0.0.1:8000`.

## Minimal controller and view

Generate files:

```bash
php mystack make:controller Home
php mystack make:view HomeView
```

Or create `app/HomeController.php`:

```php
<?php
final class HomeController
{
    public static function index(): void
    {
        PHML::init();
        echo PHJC::view('HomeView', ['title' => 'MyStack']);
    }
}
```

Then import and route it from `index.php`:

```php
import('app:HomeController');

PHRO::get('/', [HomeController::class, 'index'])
    ->name('home')
    ->header('Cache-Control', 'private, max-age=300')
    ->allow()
    ->sitemap(['priority' => '1.0', 'changefreq' => 'weekly']);
```

## Database

PHDB uses prepared operations:

```php
PHDB::createTable('users', [
    'id' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
    'name' => 'VARCHAR(120) NOT NULL',
    'email' => 'VARCHAR(190) NOT NULL UNIQUE',
    'status' => "VARCHAR(30) NOT NULL DEFAULT 'active'",
    'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
]);

$id = PHDB::insert('users', [
    'name' => 'Sakib',
    'email' => 'sakib@example.com',
]);

$user = PHDB::find('users', $id);
```

Stream very large result sets without buffering all rows:

```php
foreach (PHDB::fast('SELECT id, email FROM users WHERE status = ?', ['active']) as $row) {
    // Consume one row at a time. Do not issue another query until iteration ends.
}
```

Schema synchronization can add, modify, and remove columns to match the declaration. Review destructive schema changes before deploying them to production and maintain verified backups.

`PHDB::$driver` is opt-in and defaults to `mysqli`; unset/empty also uses the unchanged legacy path. `sqlite` and `pgsql` are available in the core non-mysqli path (atomic upsert, cursor streaming on pgsql); `clean()`, FULLTEXT `MATCH...AGAINST`, and schema-sync reorder/type-change stay mysqli-only and fail loudly elsewhere.

## UI and browser runtime

Render registered UI:

```php
echo PHUI::element('button:primary', [
    'slot' => 'Get notified',
    'class' => 'w-full sm:w-auto',
    'phjs' => ['click' => 'toast "Notifications enabled"'],
]);
```

Components render three equivalent ways — `PHUI::ui('ui:button-primary', ['slot' => 'Save'])`, `@component('ui:button-primary')Save@endcomponent`, or `<x-ui-button-primary>Save</x-ui-button-primary>`. Slugs follow `type:name` (`ui:`, `form:`, `data:`, `section:`, `shell:`, `auth:`, `payment:`, `courier:`) with tone variants (`ui:badge-success`, `ui:alert-danger`, …). All variants use semantic theme tokens (`bg-primary`, `bg-success`, `text-muted-foreground`, `border-border`), so they follow `data-theme="light|dark"` or a custom theme automatically. Missing values render the key name as an inline hint (`{{key|key}}`).

PHCS processes utility classes without a Node build. PHJS is served by PHRO as `/app.js`; `src/js/PHJS-min.php` is its canonical PHP-aware source. PHJS provides directives, state, components, requests, SPA navigation, forms, keymaps, accessibility, storage, UI helpers, OAuth/2FA/payment bridges, devtools, prefetch, cache and Service Worker integration without requiring Alpine, HTMX, React, or Vue.

For full PHJS/PHUI usage patterns and examples, see the *PHJS usage guide* and *PHUI usage guide* in `MANUAL_BN.md`.

When changing PHJS runtime behavior, synchronize all related builds and run `php mystack smoke`.

## Security and production

Production baseline:

```php
PHDE::debug(false);
PHRO::guard();
PHRQ::cross(true); // self-aware restrictive policy; pass explicit origins for cross-site access

PHMO::configure(['enabled' => true]);
PHMO::registerRoutes();
```

- Use separate strong secrets for PHRO, PHJT, PHED, OAuth, payments and VAPID.
- Keep debug dashboards and detailed errors disabled in production.
- Never trust forwarded client-IP headers unless the immediate proxy is explicitly trusted.
- Use HTTPS and `no-store, private` on auth/payment/account routes.
- Verify OAuth state/PKCE/nonce, payment webhooks and idempotency at every external boundary.
- `.mystack/` is private runtime state; retain its access guard and exclude its data from source control.

`PHMO::registerRoutes()` exposes `/health` and `/ready` by default. The readiness endpoint checks PHLS/database dependencies and may return HTTP 503. `PHMO::dashboard('/monitor')`, `PHDE::apibar('/apibar')`, and `PHRQ::livemap('/livemap', ...)` remain debug-only interfaces.

## Notifications

Enable the automatic PHFY configuration:

```php
PHFY::configure(['enabled' => true]);
PHFY::registerRoutes();

PHFY::public('A public update is available');
PHFY::private('Your report is ready', ['users' => ['user@example.com']]);
```

PHFY supports ntfy public/private delivery, user/permission/keyword filtering, PHLS subscription storage, browser permission, Service Worker delivery and self-contained VAPID/Web Push capability detection. Browser-closed delivery still depends on platform/browser push support.

## CLI

```text
php mystack help
php mystack cli:status
php mystack cli:install
php mystack list
php mystack about --json
php mystack get:started
php mystack make:controller User
php mystack make:model Product
php mystack make:middleware Auth
php mystack make:component Alert
php mystack make:view Dashboard
php mystack make:command Report
php mystack make:api Product
php mystack make:service Billing
php mystack make:job SendReceipt
php mystack make:request StoreProduct
php mystack make:resource Product
php mystack make:factory Product
php mystack make:migration CreateProducts
php mystack make:seeder Product
php mystack make:crud Product
php mystack serve 8000
php mystack cache:clear
php mystack cache:status
php mystack build:check
php mystack route:list
php mystack library:check PHDB
php mystack ui:search commerce
php mystack db:check
php mystack migrate:status
php mystack migrate
php mystack db:seed all
php mystack queue:push SendReceiptJob --payload='{"order_id":42}'
php mystack queue:work
php mystack schedule:add hourly-report hourly "report:hourly"
php mystack schedule:run
php mystack monitor:status
php mystack doctor
php mystack doctor --fix
php mystack audit
php mystack smoke
php mystack test:app
php mystack extension:check
php mystack update --check
php mystack update [path]
php mystack update [path] --yes
```

Application commands are flat `app/*Command.php` classes generated by `make:command` and discovered automatically. Migrations, seeders, jobs and schedules are opt-in: no web request starts a blocking worker. Queue/scheduler state lives privately in `.mystack/console.sqlite`, uses WAL and atomic reservation, and is intended for one application host. Run daemon workers through the hosting platform's process supervisor. Destructive rollback, pruning and flush operations require `--yes` or interactive confirmation.

Both invocation styles are supported: `php mystack ...` always works when PHP is available, while `mystack ...` works directly on Unix/macOS through the executable shebang or `mystack.sh` (which invokes `php` directly, so it also works where the executable bit is unavailable, such as SMB/NFS mounts), and on Windows CMD/PowerShell through `mystack.cmd` without PowerShell execution-policy dependency. Run `php mystack cli:install` once to create a user-level launcher; if its directory is not already in `PATH`, the command prints the exact directory to add. `--json` keeps machine output free from TUI decoration; `NO_COLOR=1` or `--no-ansi` disables terminal colors.

Interactive output uses the adaptive MyStack TUI: ASCII brand header, semantic status badges, framed comparisons and real operation-stage progress. `update --check` focuses on changed files and summarizes unchanged files; add `--verbose` to display the complete comparison.

The updater reads the rolling official GitHub `main` branch rather than Releases or versions, compares SHA-256 and exact bytes, and allows only `library/*`, `src/js/*`, `docs/*`, `mystack-extension-main/*`, the two `llms` files, framework governance/documentation files, `.htaccess`, the Pages workflow, and CLI launchers. VSIX files receive nested ZIP/path/manifest validation. It asks before applying, stages and validates every change, runs smoke tests, and rolls back on failure. The root access guard explicitly denies HTTP access to private framework PHP/metadata, extension development files and both CLI files. The updater never removes unmatched local files merely because they are absent upstream.

## Verification

Before deployment:

```bash
php mystack doctor
php mystack audit
php mystack smoke
```

Also perform environment-specific tests that a local smoke suite cannot guarantee: live database permissions, HTTPS/rewrite behavior, OAuth callbacks, payment/courier sandbox credentials, SMTP, ntfy/Web Push on target browsers, backup restoration, browser regression, and expected load/soak capacity.

## Documentation

- [Searchable documentation portal](docs/index.html)
- [Source-generated API index](docs/index.md)
- [Machine-readable API catalog](docs/api.json)
- [Concise AI context](llms.txt)
- [Complete AI context](llms-full.txt)

- [বাংলা পূর্ণাঙ্গ ম্যানুয়াল](MANUAL_BN.md)
- [Complete English manual](MANUAL_EN.md)
- [AI/Agent engineering contract](AGENTS.md)
- [Apache License 2.0](LICENSE)
- [Ownership and attribution notice](NOTICE)
- [Contribution policy](CONTRIBUTING.md)

## Documentation workflow

Regenerate and verify source-linked documentation whenever a public framework API changes:

```bash
php mystack docs:build
php mystack docs:check
php mystack extension:check
```

The builder scans `library/*.php` without executing library code, creates one reference page per source file, and refreshes the searchable portal, API JSON and both `llms` discovery files. Executable code remains authoritative for runtime behavior. MyStack uses a rolling `main` branch and does not declare a fixed framework version.

`.github/workflows/docs.yml` publishes only the generated `docs/` artifact to GitHub Pages. Enable GitHub Pages with **GitHub Actions** as its source once; subsequent pushes to `main` publish documentation automatically without exposing executable PHP source through the documentation site.

## License

MyStack is licensed under the Apache License 2.0. Copyright © 2026 Sakibur Rahman (`sakibweb`). You may use, copy, modify and distribute the code under the License, but must preserve the License, applicable copyright/attribution notices and the [NOTICE](NOTICE), and must identify modified files. The license grants no right to claim authorship of the original framework or present an unofficial modified distribution as the official MyStack Framework.

Official organization: [mystack-framework](https://github.com/mystack-framework)  
Official repository: [mystack-framework/mystack](https://github.com/mystack-framework/mystack)

Public issues, requests and pull requests are welcome under [CONTRIBUTING.md](CONTRIBUTING.md). Direct write, merge, release and official-update authority remains with Sakibur Rahman and explicitly authorized maintainers.
