# MyStack Framework

MyStack is a portable, zero-Composer PHP application framework that combines routing and security, prepared database access, component UI, PHP-native utility CSS, a self-contained browser runtime, authentication, payments, couriers, notifications, observability, real-time events, AI/MCP integration, and an intelligent CLI in one deployable codebase.

The framework is designed to run from a domain root or any subdirectory on Apache/LiteSpeed-compatible hosting without hardcoded project paths. This repository also contains a School/College ERP application that exercises the framework, but the framework itself is general-purpose.

## Highlights

- Zero Composer/NPM runtime dependency
- Portable root/subfolder routing via `DIR`, `PHCO`, and `PHRO`
- Fluent HTTP router, middleware, CSRF and layered request guard
- MySQL/MariaDB CRUD, transactions, analytics, schema sync, and unbuffered streaming
- SQLite-backed local state with TTL, counters, tags, bounded locking, and recovery
- PHUI registry with 1,300+ reusable entries and PHML composition
- PHP-native Tailwind-style PHCS utility processing
- Self-contained PHJS SPA/reactivity/directive runtime served as `/app.js`
- Service Worker, page/cache de-duplication, offline and Web Push support
- OAuth/OIDC provider matrix, JWT, encryption, OTP/TOTP and Authenticator lifecycle
- Payment gateway and Bangladesh/international courier facades
- ntfy public/private delivery plus VAPID Web Push through PHFY
- WebSocket, SSE and StreamUI support
- Health/readiness probes, request/trace IDs, JSON logs, metrics and debug dashboards
- AI provider bridge and MCP tools/resources/prompts through PHAI
- Intelligent CLI with generators, doctor, audit, smoke test and transactional updater

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
├── library/                  30 framework libraries + loader
├── app/                      Flat backend/controller/model directory
├── component/                Flat view/component directory
├── src/
│   ├── js/                   PHJS and Service Worker sources/builds
│   └── cache/{css,js,php}/   Named compiled component cache
├── .mystack/                 Private local state and structured logs
├── AGENTS.md                 Agent engineering contract
└── MANUAL_BN.md              Full Bengali manual
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
php mystack make:view PublicHome
```

Or create `app/HomeController.php`:

```php
<?php
final class HomeController
{
    public static function index(): void
    {
        PHML::init();
        echo PHJC::view('PublicHome', ['title' => 'MyStack']);
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

## UI and browser runtime

Render registered UI:

```php
echo PHUI::element('button:primary', [
    'slot' => 'Get notified',
    'class' => 'w-full sm:w-auto',
    'phjs' => ['click' => 'toast "Notifications enabled"'],
]);
```

PHCS processes utility classes without a Node build. PHJS is served by PHRO as `/app.js`; `src/js/PHJS-min.php` is its canonical PHP-aware source. PHJS provides directives, state, components, requests, SPA navigation, forms, keymaps, accessibility, storage, UI helpers, OAuth/2FA/payment bridges, devtools, prefetch, cache and Service Worker integration without requiring Alpine, HTMX, React, or Vue.

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
php mystack get:started
php mystack make:controller User
php mystack make:model Product
php mystack make:middleware Auth
php mystack make:component Alert
php mystack make:view Dashboard
php mystack serve 8000
php mystack cache:clear
php mystack doctor
php mystack doctor --fix
php mystack audit
php mystack smoke
php mystack update --check
php mystack update [path]
php mystack update [path] --yes
```

The updater reads the official GitHub `main` branch rather than Releases, compares SHA-256 and exact bytes, restricts changes to framework allowlisted paths, asks before applying, stages and validates every change, runs smoke tests, and rolls back on failure. It never removes local files merely because they are absent upstream.

## Verification

Before deployment:

```bash
php mystack doctor
php mystack audit
php mystack smoke
```

Also perform environment-specific tests that a local smoke suite cannot guarantee: live database permissions, HTTPS/rewrite behavior, OAuth callbacks, payment/courier sandbox credentials, SMTP, ntfy/Web Push on target browsers, backup restoration, browser regression, and expected load/soak capacity.

## Documentation

- [বাংলা পূর্ণাঙ্গ ম্যানুয়াল](MANUAL_BN.md)
- [AI/Agent engineering contract](AGENTS.md)
- [MIT License](LICENSE)

## License

MyStack is available under the MIT License. See [LICENSE](LICENSE).
