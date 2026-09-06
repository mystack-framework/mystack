# MyStack Framework — Complete English Manual

This manual mirrors `MANUAL_BN.md` one-to-one for English readers. It was verified against the current executable codebase: the 30 framework libraries plus the `library.php` loader, the canonical PHJS runtime in `src/js/PHJS-min.php`, and the root `mystack` CLI. It describes only the reusable MyStack framework and its public contracts.

> Important: the framework's real code is the final source of truth. Before using any live payment, courier, OAuth, mail, AI or push provider, end-to-end test with that provider's current credentials, sandbox and production approval.

## Table of contents

1. [Introduction and capabilities](#1-introduction-and-capabilities)
2. [Required environment and installation](#2-required-environment-and-installation)
3. [Project structure and portable paths](#3-project-structure-and-portable-paths)
4. [Bootstrap and configuration order](#4-bootstrap-and-configuration-order)
5. [MyStack CLI](#5-mystack-cli)
6. [DIR and the dynamic importer](#6-dir-and-the-dynamic-importer)
7. [PHRO routing, middleware and security](#7-phro-routing-middleware-and-security)
8. [PHDB database and PHLS local state](#8-phdb-database-and-phls-local-state)
9. [PHML, PHJC, PHUI and PHCS frontend](#9-phml-phjc-phui-and-phcs-frontend)
10. [PHJS self-contained browser runtime](#10-phjs-self-contained-browser-runtime)
11. [Authentication, JWT, encryption and 2FA](#11-authentication-jwt-encryption-and-2fa)
12. [HTTP, API, mail, realtime and translation](#12-http-api-mail-realtime-and-translation)
13. [Payment and courier](#13-payment-and-courier)
14. [PHFY notifications and Web Push](#14-phfy-notifications-and-web-push)
15. [PHAI and MCP](#15-phai-and-mcp)
16. [PHMO monitoring and debug UIs](#16-phmo-monitoring-and-debug-uis)
17. [Full library catalog](#17-full-library-catalog)
18. [Deployment, cache and production checklist](#18-deployment-cache-and-production-checklist)
19. [Troubleshooting and verification](#19-troubleshooting-and-verification)
20. [AI discovery and automatic API documentation](#20-ai-discovery-and-automatic-api-documentation)

## 1. Introduction and capabilities

MyStack is a zero-Composer, zero-NPM-runtime PHP framework. Its main strengths:

- portable routing at a domain root, hosting root, subdomain or nested subfolder;
- fluent router, middleware, CSRF, rate limiting and layered shields (SQLi, XSS, open redirect, upload, header, attempt, honeypot);
- prepared database CRUD, analytics, transactions, schema synchronization and unbuffered streaming;
- SQLite-backed local state with TTL, atomic counters, tags, bounded locking and corruption recovery;
- a PHP component/markup system, a 3,500+ entry PHUI registry and PHP-native utility CSS;
- a self-contained PHJS reactivity, directive, SPA and request runtime without Alpine, HTMX, React or Vue;
- OAuth/OIDC, JWT, authenticated encryption, OTP/TOTP and a full Authenticator lifecycle;
- payment gateway and courier facades (10 Bangladesh + 10 international courier profiles);
- ntfy public/private notifications and VAPID Web Push through PHFY;
- WebSocket, SSE and StreamUI support;
- request/trace IDs, JSON logs, health/readiness probes, metrics, alerts and a debug dashboard;
- an extensible console kernel with generators, inspection, migrations, a local queue/scheduler, doctor, audit, smoke and a rollback-capable transactional updater.

## 2. Required environment and installation

### Required

- PHP 8.1 or newer
- `json`, `openssl`, `PDO`, `pdo_sqlite`
- `mysqli` when using PHDB with MySQL/MariaDB
- writable `.mystack/` and `src/cache/css`, `src/cache/js`, `src/cache/php`

### Feature-specific

- `curl`: OAuth, payment, courier, AI, mail-adjacent HTTP, ntfy and remote APIs
- `mbstring`: complete Unicode/Bengali handling
- `zip`/`ZipArchive`: the MyStack updater and PHCD/ZIP workflows
- `sockets`: native PHEV WebSocket serving
- HTTPS: secure cookies, OAuth, payment callbacks, Service Workers and Web Push

No Composer or NPM installation is required. PHCD installs browser-side packages optionally; it is not a server runtime dependency.

### First verification

```bash
php mystack doctor
php mystack smoke
php mystack serve 8000
```

`doctor` is read-only. Apply safe auto-fixes explicitly:

```bash
php mystack doctor --fix
```

## 3. Project structure and portable paths

```text
/
├── index.php                  bootstrap, config, import, route, listen
├── mystack                    intelligent CLI
├── mystack.cmd                Windows launcher (php "%~dp0mystack" %*)
├── mystack.sh                 Linux/macOS launcher (php "$(dirname "$0")/mystack" "$@")
├── library/
│   ├── library.php            DIR, Importer, import(), 30 library loader
│   └── PH*.php                framework modules
├── app/                       flat backend files
├── component/                 flat UI/view files
├── src/
│   ├── js/                    PHJS/SW sources and synchronized builds
│   └── cache/{css,js,php}/    readable-name compiled cache
├── .mystack/                  private PHLS/PHMO state and logs
├── README.md
├── MANUAL_BN.md / MANUAL_EN.md
├── AGENTS.md / CONTRIBUTING.md / NOTICE / LICENSE
└── .github/CODEOWNERS
```

Do not create subdirectories inside `app/` or `component/`. Never hardcode hosting paths:

```php
$component = DIR::path('component:HomeView');
$assetUrl  = DIR::link('js:custom.js', true);
$basePath  = PHCO::path(); // e.g. /projects/shop, or '' at root
$prefix    = PHCO::pre();  // e.g. shop_
```

This path/prefix pair is injected from PHP into PHJS, so cookies, storage, cache, `/app.js`, `/sw.js`, PHFY and SPA navigation all follow one project namespace.

## 4. Bootstrap and configuration order

Recommended `index.php` skeleton:

```php
<?php
require_once 'library/library.php';

PHDE::debug(true);              // false in production
PHDE::memory('512M');
PHTM::setZone('Asia/Dhaka');

PHMO::configure(['enabled' => true]);

PHRO::guard();
PHRO::key('replace-with-long-application-secret', false);
PHRO::track(false);

PHJT::key('replace-with-separate-jwt-secret');
PHJT::algorithm('HS512');

PHRQ::cross(true);

PHDB::$host = 'localhost';
PHDB::$username = 'root';
PHDB::$password = 'secret';
PHDB::$dbname = 'mystack_app_db';

PHFY::configure(['enabled' => true]);
PHFY::registerRoutes();

PHMO::registerRoutes();
PHMO::dashboard('/monitor');    // route is not registered when debug is false

import('app:HomeController', 'app:AuthMiddleware');

PHML::init();

PHRO::get('/', [HomeController::class, 'index'])->name('home');

PHRO::listen(function (int $code, string $message, string $at): void {
    http_response_code($code);
    if ($code >= 500 && PHDE::isDebug()) {
        echo htmlspecialchars($message . ' at ' . $at, ENT_QUOTES, 'UTF-8');
    }
});
```

The framework does not parse or depend on `.env`, `APP_ENV` or `APP_DEBUG`. If deployment secrets come from environment variables, read them explicitly in bootstrap code and pass them to the matching MyStack APIs. The only debug source of truth is:

```php
PHDE::debug(false);
$debug = PHDE::isDebug();
```

Order rules: configure debug, timezone, guards, keys, cross-origin behavior, storage and optional services before registering application routes; call `PHRO::guard()` before protected request processing; register `PHFY`, `PHMO`, PHCD, debug UIs and application routes before `PHRO::listen()`; call `PHML::init()` once before `PHRO::listen()`; keep `PHRO::listen()` last. Debug-only UIs must remain unavailable when `PHDE::isDebug()` is false.

## 5. MyStack CLI

| Command | Purpose |
| --- | --- |
| `php mystack help [command]` / `list` | all built-in and auto-discovered application commands by category |
| `cli:status` / `cli:install [--target=path]` | inspect OS launcher/PATH state and atomically install the user-level `mystack` command |
| `php mystack about --json` | framework, PHP, extension, path and folder capability |
| `php mystack get:started` | starter controller/route/view |
| `php mystack make:controller User` | controller in `app/` |
| `php mystack make:model Product` | model with PHDB demo queries |
| `php mystack make:middleware Auth` | middleware |
| `php mystack make:component Alert` | reusable component |
| `php mystack make:view Dashboard` | full page view |
| `make:command`, `make:api`, `make:service`, `make:job` | custom console command / API / service / queue job |
| `make:event`, `make:listener`, `make:mail`, `make:notification`, `make:policy`, `make:test` | application integration scaffolds |
| `make:request`, `make:resource`, `make:factory` | PHVD request, API transformer and test-data factory |
| `make:migration`, `make:seeder`, `make:crud`, `make:module` | schema/data scaffold or atomic multi-file feature scaffold |
| `php mystack serve 8000` | local dev server (port must be an integer 1024–65535) |
| `cache:status`, `cache:warm`, `cache:clear`, `cache:prune` | inspect, prepare or explicitly clean the named CSS/JS/PHP cache |
| `build:check`, `phjs:check`, `optimize` | synchronized build and safe local readiness checks |
| `route:list`, `route:show`, `config:show` | route and safe literal config inspection without executing the app |
| `library:list`, `library:check`, `ui:list`, `ui:search` | framework and PHUI registry inspection |
| `db:check`, `db:tables`, `schema:status` | read-only PHDB readiness and schema inspection |
| `migrate`, `migrate:status`, `migrate:rollback --yes`, `db:seed` | tracked opt-in migration/seeding; rollback is explicit |
| `queue:push`, `queue:work`, `queue:status`, `queue:failed/retry/forget/flush` | local durable job lifecycle with retry/backoff and atomic reservation |
| `schedule:add/list/remove/run/work` | isolated CLI schedule (daemon needs a supervisor) |
| `websocket:start/status`, `mail:check`, `oauth:list`, `payment:list`, `courier:list` | service/provider capability inspection |
| `phfy:check`, `vapid:check`, `health:check`, `monitor:status`, `logs:tail/report` | notification, crypto, dependency and observability checks |
| `php mystack doctor` | read-only structure/syntax/permission scan and folder repair check |
| `php mystack doctor --fix` | bounded safe fix |
| `php mystack audit` | production-oriented read-only audit |
| `php mystack smoke` / `test` | full framework regression suite |
| `php mystack test:app` | discover and run flat `app/*Test.php` application tests |
| `php mystack extension:check` | verify AI-discoverable documentation and VS Code extension artifacts |
| `php mystack docs:build` / `docs:check` | regenerate / verify source-linked documentation |
| `php mystack update --check` | GitHub `main` SHA/byte diff, no changes |
| `php mystack update [path]` | compare, confirm and apply allowlisted paths |
| `php mystack update [path] --yes` | verified apply without prompts |

`make:command Report` creates a flat `app/ReportCommand.php`; with `commandName()`, `description()` and `handle()` present the command is auto-discovered on the next invocation. Discovery reads source tokens only — no application code executes during `list`, `help`, `doctor`, `audit` or dispatch lookup, so `commandName()`/`description()` must return literal strings. Queue/scheduler metadata lives in `.mystack/console.sqlite` in WAL mode and is single-host application state, not a multi-server shared queue. `queue:work` first reclaims `running` reservations older than `--timeout` (default 900 seconds) back to `pending` (crashed-worker recovery). `queue:work --daemon`, `schedule:work --daemon` and `websocket:start` must not run inside ordinary HTTP requests; run them from a hosting supervisor or system service. Cleanup, rollback, forget and flush operations require interactive confirmation or `--yes`. A migration file runs its `up()` successfully first and only then writes the local applied registry; rollback without `down()` is refused. A database backup/restore drill is mandatory before production schema changes.

`php mystack` is the universal fallback on every OS. Unix/macOS use the executable shebang or the `mystack.sh` launcher (it invokes `php` directly, so it also works where the executable bit is unavailable, such as SMB/NFS mounts); Windows CMD/PowerShell use the policy-independent `mystack.cmd`. `php mystack cli:install` creates a user-level launcher (`~/.local/bin/mystack` on Unix/macOS, `%LOCALAPPDATA%\MyStack\bin\mystack.cmd` on Windows) and prints the exact directory to add to `PATH` if needed; it never silently mutates system PATH. On Unix/macOS `cli:install` also repairs the project `mystack` executable bit, and `cli:status` reports it as `project_direct_executable`. `--json` keeps machine-readable output unchanged; `NO_COLOR=1` or `--no-ansi` disables color/TUI escape codes.

Interactive output uses the adaptive MyStack TUI: an ASCII brand header, semantic INFO/SUCCESS/WARNING/ERROR badges, framed comparisons and real operation-stage progress. `update --check` focuses on changed files and summarizes unchanged ones; add `--verbose` for the complete comparison.

The updater uses no releases or versions. It fetches the official rolling `main` snapshot over pinned HTTPS, compares SHA-256 and exact bytes, and applies only `library/*`, `src/js/*`, `docs/*`, `mystack-extension-main/*`, `.htaccess`, `.github/CODEOWNERS`, `.github/workflows/docs.yml`, `AGENTS.md`, `CONTRIBUTING.md`, `LICENSE`, `MANUAL_BN.md`, `MANUAL_EN.md`, `NOTICE`, `README.md`, `llms.txt`, `llms-full.txt`, `mystack`, `mystack.cmd` and `mystack.sh`. VSIX files get nested ZIP/path/manifest validation. The root `.htaccess` explicitly denies HTTP access to private framework metadata, extension development files and both CLI launchers. Every change is staged, hash-verified, validated (text/PHP/JS/VSIX) and smoke-gated; failures roll back, and if a rollback itself cannot restore a file the staged backups are retained on disk instead of deleted. Files absent upstream are never deleted locally.

### License, ownership and contribution

MyStack is published under the Apache License 2.0. Copyright © 2026 Sakibur Rahman (`sakibweb`). You may use, copy, modify and distribute the code, but must preserve `LICENSE`, applicable copyright/attribution notices and `NOTICE`, and clearly identify modified files. You may claim authorship of your own modifications, but must not claim the original MyStack framework as your creation, remove owner attribution, or present an unofficial modified distribution as the official MyStack Framework.

Official organization: https://github.com/mystack-framework
Official repository: https://github.com/mystack-framework/mystack

Public issues, discussions and pull requests are welcome. Direct write, merge, release and official-update authority stays with Sakibur Rahman and explicitly authorized maintainers. Details live in `NOTICE`, `CONTRIBUTING.md` and `.github/CODEOWNERS`. A public repository does not grant write access; keep GitHub protected branches/rulesets enabled separately.

## 6. DIR and the dynamic importer

One or many imports:

```php
import('app:UserController');
import('component:HomeView', 'js:custom.js', 'css:app.css');
import('app:*');
```

Common path keys:

```php
DIR::path('library:PHDB');
DIR::path('app:UserController');
DIR::path('component:HomeView');
DIR::link('img:logo.svg');
DIR::raw('js:custom.js');
```

Use `DIR::initialize()` only when custom hosting root detection is required. Never pass user input as an import/path key.

## 7. PHRO routing, middleware and security

### Basic and fluent routes

```php
PHRO::get('/products', [ProductController::class, 'index'])
    ->name('products.index')
    ->header('Cache-Control', 'private, max-age=300')
    ->allow()
    ->sitemap(['priority' => '0.8', 'changefreq' => 'daily']);

PHRO::post('/products', [ProductController::class, 'store'])
    ->name('products.store')
    ->middleware(AuthMiddleware::requireRole(['admin']))
    ->header('Cache-Control', 'no-store, private')
    ->disallow();
```

Supported methods: `get`, `post`, `put`, `patch`, `delete`, `head`, `options`, generic `add`. Route composition helpers: `group`, `crud`, `gap`, `sgap`.

### Route input and CSRF

```php
echo '<form method="post" action="/profile">';
echo PHRO::csrfField();
echo '</form>';

$data = PHRO::gatherRequestData();
```

With the guard enabled, secure session defaults, a project-scoped session name, CSRF and the configured shields work:

```php
PHRO::guard([
    'rate_limit' => ['enabled' => true],
]);
```

The default guard layers include content-type, SQLi, XSS, rate-limit, attempt, upload, header-inspection, honeypot, open-redirect and CSRF protection. To raise a limit, override the specific key within safe bounds; do not disable an entire shield.

### Proxy/Cloudflare

`REMOTE_ADDR` is the default source. `X-Forwarded-For`, `CF-Connecting-IP` and `X-Real-IP` are usable fallbacks only after trusted-proxy validation:

```php
PHRO::trustProxies(['173.245.48.0/20', '103.21.244.0/22']);
$ip = PHRO::getUserIP();
```

Supply the current official CIDR list of the reverse proxy/CDN you use. Never blindly trust client-supplied forwarding headers.

### CORS/CSP and SEO

```php
PHRQ::cross(true); // self-aware restrictive policy
// or explicit origins:
PHRQ::cross(true, ['https://app.example.com'], true);

PHRO::manifest(['name' => 'My App', 'short_name' => 'App']);
PHRO::addSitemapEntry('/docs', ['priority' => '0.7']);
PHRO::addRobotsRule('Disallow: /private');
```

`PHRO::listen()` finishes dispatching asset/system routes (`/app.js`, `/sw.js`, sitemap/robots/manifest) and application routes.

## 8. PHDB database and PHLS local state

### PHDB connection

```php
PHDB::$host = 'localhost';
PHDB::$username = 'root';
PHDB::$password = 'secret';
PHDB::$dbname = 'shop';

$status = PHDB::checker();
```

### CRUD

```php
$id = PHDB::insert('products', [
    'name' => 'Keyboard',
    'price' => 2500,
    'status' => 'active',
]);

$product = PHDB::find('products', $id);
$active = PHDB::select('products', '*', ['status' => 'active'], 20, 0, 'id DESC');
PHDB::update('products', ['price' => 2400], ['id' => $id]);
PHDB::delete('products', ['id' => $id]);
```

Raw SQL must use placeholders:

```php
$rows = PHDB::query(
    'SELECT * FROM products WHERE price >= ? AND status = ?',
    [1000, 'active']
);
```

### Streaming very large result sets

```php
foreach (PHDB::fast('SELECT id, email FROM users WHERE status = ?', ['active']) as $row) {
    // one row at a time in memory
}
```

Do not issue another query on the same connection until iteration ends.

### Transactions, analytics and pagination

```php
PHDB::transaction(function (): void {
    PHDB::insert('orders', ['total' => 500]);
    PHDB::update('stock', ['quantity' => 9], ['product_id' => 1]);
});

$page = PHDB::paginate('products', '*', ['status' => 'active'], 1, 25);
$total = PHDB::sum('orders', 'total', ['status' => 'paid']);
$count = PHDB::count('users', null, ['status' => 'active']);
```

### Schema synchronization

```php
PHDB::createTable('products', [
    'id' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
    'name' => 'VARCHAR(190) NOT NULL',
    'price' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
]);
```

Default synchronization can add, modify and remove columns to match the declaration and skips unchanged schema quickly. Removing columns or changing types can be destructive in production — never deploy without verified backups.

### PHLS

```php
// Default storage automatically uses the .mystack namespace.
// For a custom file, set it before any other PHLS call:
// PHLS::setFile(__DIR__ . '/.mystack/custom-state.sqlite');
PHLS::add('token', ['value' => 1], 15, ['auth']);
$value = PHLS::get('token');

$value = PHLS::remember('catalog', 300, fn() => loadCatalog());
$attempts = PHLS::increment('login:' . $ip, 1, 300);
PHLS::flushByTag('auth');
```

PHLS uses bounded SQLite retry/WAL, atomic transactions, a checker and malformed-storage recovery. It is single-server local state; multiple servers need a shared database/cache. Keep PHLS calls non-fatal at request-protection boundaries and preserve its recovery and bounded retry behavior.

## 9. PHML, PHJC, PHUI and PHCS frontend

### PHML DSL

```php
PHML::init();

echo phml(<<<'PHML'
main {
    class: "max-w-6xl mx-auto p-6";
    h1 { class: "text-3xl font-bold"; "Welcome" }
    p { class: "text-slate-600"; "Built with MyStack" }
}
PHML);
```

PHML supports layouts, partials, blocks/yield, registered components, shared data, head/body/css/js and compiled processing.

### PHJC views

```php
echo PHJC::view('HomeView', ['title' => 'Home']);
PHJC::share('currentUser', $user);
PHJC::clearCache();
```

PHJC compiles component PHP into `src/cache/php`, CSS into `src/cache/css` and JS into `src/cache/js`, keeping the same readable component name. Missing paths are auto-created.

### PHUI

```php
echo PHUI::element('button:primary', [
    'slot' => 'Save',
    'class' => 'w-full sm:w-auto',
    'phjs' => ['click' => 'toast "Saved"'],
]);

echo PHUI::section('section:hero', [
    'hero_title' => 'Next-generation commerce',
    'hero_desc' => 'Portable and fast.',
    'hero_buttons' => PHUI::element('button:primary', ['slot' => 'Get started']),
]);
```

API family: `element`, `section`, `layout`, `page`, generic `ui`/`render`, `register`, `registerMany`, `alias`, `exists`, `search`, `categories`, `catalog`, `check`. Placeholders use `{{title|Default title}}`. Do not pass untrusted raw HTML directly; use `PHUI::check()`/guarded rendering.

### PHCS

```php
PHCS::config(['darkMode' => 'class']);
PHCS::HTML('<button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Save</button>');
$css = PHCS::build();
```

Responsive, state, dark-mode, layout, color, typography, animation and advanced utilities are processed by the PHCS runtime/build. No Node build step is needed.

## 10. PHJS self-contained browser runtime

`/app.js` is served by PHRO directly from the canonical `src/js/PHJS-min.php`. The runtime initializes once and keeps in-flight request and page-cache de-duplication so the same page/request is not fetched again unnecessarily.

### PHP to JavaScript

```php
echo PHJS::gen('toast "Saved"');
echo PHJS::const('userId', 42);
echo PHJS::fetch('/api/users', ['method' => 'GET']);

$module = PHJS::module([
    ['type' => 'const', 'name' => 'now', 'value' => PHJS::expr('Date.now()')],
    ['type' => 'export', 'names' => ['now']],
]);
```

### HTML-only directive layer

Following PHJS's native directive prefix/config, these are declarative: state, show/hide, text/html/value/class/style/attribute binding, event, model, loop, conditional, transition, teleport, ref, request, swap, trigger, component, prop, slot, action, context, form, lazy mount, route mount, error/loading/empty fallback, keymap and hotkey. Inspect and follow the project's existing syntax/aliases; do not create new dependencies on `hx-*` or `Alpine.*` runtimes.

### Request and navigation behavior

- only same-origin HTML document links receive SPA navigation;
- `mailto:`, `tel:`, download, target, external and non-HTML assets stay native;
- required CSS is prepared/activated before the destination DOM swap;
- hover-intent and touchstart prefetch are supported;
- a second request to the same URL is not sent while cached/in-flight;
- back/forward, error responses and full-document state restore safely;
- toasts always live in a body-level fixed layer;
- listeners/effects/timers of removed DOM are cleaned up.

### Major runtime systems

State/store, component lifecycle, async components, props/slots/context, forms, validation, router, request/upload, offline queue, storage/DB sync, theme, i18n, SEO, accessibility/focus trap, keyboard/keymap, PWA/SW, PHFY, OAuth/2FA/payment helpers, chart/media/file systems, AI/XR, command palette, devtools, virtual list/table, data table, dashboard, mega menu, eCommerce UI, rich editor, file manager, Kanban, calendar and workflow helpers are included.

In debug mode, `Ctrl+Shift+D` opens PHJS DevTools and `Ctrl+K` opens the command palette.

### Synchronization

When changing PHJS behavior, keep at least these equivalent:

- `src/js/PHJS-min.php` — main/canonical
- `src/js/PHJS.php`
- `src/js/phjs.js`
- `src/js/phjs.min.js`
- the related runtime copy of the changed section

Do not copy PHP interpolation verbatim into plain JS; keep the resolved equivalent. Finally pass the synchronized-build and Bengali UTF-8 tests of `php mystack smoke`. The canonical Service Worker source is `src/js/SW.php`; keep `sw.js` synchronized when Service Worker behavior changes.

## 11. Authentication, JWT, encryption and 2FA

### PHAU identity and verification

```php
$created = PHAU::make('users', $dbMap, $input, $options);
$identity = PHAU::check('users', 'token', $token, 'email');

PHAU::identityLib('/identity-lib'); // built-in UI/routes per debug/config
```

OAuth/OIDC:

```php
$providers = PHAU::socialProviders();
$login = PHAU::socialUrl('google', $googleConfig);

PHAU::listenCallback('/oauth/callback', [
    'google' => $googleConfig,
], function (array $identity): void {
    // store only the normalized fields your application needs
});
```

Maintain state, PKCE/nonce and callback validation per provider config. Store only the provider fields needed; do not persist or log raw provider responses or access tokens. Supply your own minimal `scope` in provider config.

### JWT

```php
PHJT::key('long-separate-jwt-secret');
PHJT::algorithm('HS512');
$created = PHJT::create(['sub' => 42, 'role' => 'admin'], 3600);
if ($created['status']) {
    $verified = PHJT::verify($created['data']);
}
```

`PHJT::rotate()` supports key rotation. Keep algorithm and key server-controlled.

### Encryption

```php
PHED::key('long-encryption-secret');
$encrypted = PHED::make($secretData, 'encrypt');
$plain = PHED::make($encrypted, 'decrypt');
```

Keep the encryption key separate from the JWT key and the router key. Do not change format/key without migration tests for existing encrypted data.

### OTP/TOTP Authenticator

```php
PHTP::configure();
$enrollment = PHTP::enroll($userId, ['issuer' => 'My App']);
$confirmed = PHTP::confirm($userId, $code);
$result = PHTP::authenticate($userId, $codeOrRecoveryCode);
$status = PHTP::status($userId);
```

`recovery()` rotates recovery codes after verification and `disable()` turns 2FA off. Enrollment is not active until confirmed; TOTP replay and recovery-code reuse are prevented. Generate the enrollment QR with:

```php
$dataUri = PHQR::make($enrollment['url']);
```

## 12. HTTP, API, mail, realtime and translation

### PHRQ

```php
$response = PHRQ::php('GET', 'https://api.example.com/items', [
    'Accept' => 'application/json',
]);

$browserCode = PHRQ::js('POST', '/api/items', [], ['name' => 'Book']);
```

Never disable TLS verification. Set timeouts, allowed URLs, response type and size limits. `livemap()` is a debug-only request visualization UI, `stream()` a streaming response, `file()` bounded output and `status()` an HTTP status helper.

### PHAP compact API

```php
PHAP::api(
    'POST /api/users',
    'auth',
    ['email' => 'required|email'],
    fn(array $data) => PHDB::insert('users', $data),
    'User created'
);
```

`all/get/add/up/rm` run smart CRUD with standardized responses; `valid`, `auth`, `page`, `resource/item/collection/clean`, `ok/fail/send` provide validation, identity, pagination, transformation and consistent JSON envelopes.

### PHEM

```php
PHEM::smtp('smtp.example.com', 465, 'ssl');
PHEM::smtpLogin('sender@example.com', 'password');
$sent = PHEM::smtpSend(
    'sender@example.com', 'My App', 'user@example.com', '', '',
    'Welcome', '<p>Your account is ready.</p>'
);
```

IMAP/POP also have configuration/login/get/send APIs. Do not expose credentials or message secrets in debug logs.

To deliver mail asynchronously without blocking the request on slow or busy SMTP servers, use `queue()`. It captures the current SMTP config and message into a private `.mystack/console.sqlite` job row; the existing `queue:work` worker resolves `PHEM_MailQueueHandler` to restore and deliver it, and on failure the queue's built-in retry/backoff/failed handling applies — no queue system code changes needed.

```php
PHEM::smtp('smtp.example.com', 465, 'ssl');
PHEM::smtpLogin('sender@example.com', 'password');
$queued = PHEM::queue(
    'sender@example.com', 'My App', 'user@example.com', '', '',
    'Welcome', '<p>Your account is ready.</p>',
    $delay = 0, $tries = 3
);
```

`queueSend($payload)` only restores the queued payload in worker context and runs `smtpSend()`; ordinary application code does not call it directly. Note: the job payload keeps SMTP credentials and message content in the private `.mystack` store — keep its HTTP access guard enabled and treat queue state as single-host.

### PHEV

```php
PHEV::initialize('/websocket', '0.0.0.0', 8000);
PHEV::handler('/chat', 'message', function ($clientId, $message): void {
    PHEV::broadcast($message);
});
PHEV::start();
```

Do not run the WebSocket loop inside web requests; use a CLI/service supervisor. SSE: `sendSE`, `stream`, `setRetry`; live UI: `streamUI`.

### PHTR

```php
$translated = PHTR::auto('স্বাগতম', 'en');
```

Remote translation provider availability/terms change; provide timeout/failure fallbacks.

## 13. Payment and courier

### Payment

```php
$gateway = PHPA::bkash()
    ->setKeys('APP_KEY', 'APP_SECRET', 'USERNAME', 'PASSWORD')
    ->sandbox(true);

$payment = $gateway->charge(500.00, 'BDT', 'ORDER-1001');
if (!empty($payment['success'])) {
    $verified = $gateway->verify($payment['transaction_id']);
}
```

Check capabilities first:

```php
$available = PHPA::available();
$capability = PHPA::gatewayCapabilities('bkash');
```

`charge`, `verify`, `execute`, `refund`, `webhook` support differs per gateway. Without a live merchant account, keys, approval, callback URL and the official provider contract, "an adapter exists" does not mean live payment is confirmed. Verify webhook signatures and keep order fulfillment idempotent. Private gateway overrides:

```php
PHPA::extend('privatepay', fn() => $customGateway);
```

### Courier

```php
$profile = PHPA::courierProfile('pathao');
$courier = PHPA::courier('pathao')
    ->configure(['token' => 'merchant-token'])
    ->sandbox(true);

$result = $courier->track('CONSIGNMENT-ID');
```

The facade carries 10 Bangladesh and 10 international courier profiles. Check `courierAvailable()` and `courierProfile()` before the exact operation/profile. Merchant-specific contracts can be injected via `extendCourier()`. The tracking UI uses the same-origin secure helper in PHUI/PHJS.

## 14. PHFY notifications and Web Push

All defaults exist in PHFY; minimal enable:

```php
PHFY::configure(['enabled' => true]);
PHFY::registerRoutes();
```

Send:

```php
PHFY::public('A new product update is available', [
    'title' => 'Application update',
    'keywords' => ['product-update'],
]);

PHFY::private('Your export is ready', [
    'users' => ['account@example.com'],
    'permissions' => ['member'],
    'data' => ['export_id' => 123],
]);
```

Public topics come from the ntfy SSE into PHJS. Without a private token, authorized private payloads stay in PHLS and same-origin private endpoints are polled. `users`, `permissions` and `keywords` filter by client context.

From the browser permission UI, PHJS creates Service Worker/PushManager subscriptions. With `webpush_auto` true, OpenSSL EC/VAPID keys are auto-managed. Hosting capability:

```php
$crypto = PHFY::cryptoCapability();
$push = PHFY::webPushCapability();
```

Expected behavior:

- active tab: notification event/browser notification;
- other page/tab or minimized: notification when browser permission exists;
- tab closed: notification when Service Worker Web Push is supported;
- browser fully closed: depends on OS/browser/platform policy;
- native Android/iOS app: the same PHP events can be forwarded to an app transport, but the app needs its own push token/transport adapter — a Web Push subscription is not a native FCM/APNs token.

Never put secrets or personal data in public topics.

## 15. PHAI and MCP

```php
PHAI::setAccounts([
    'openai' => ['key' => '...'],
    'gemini' => ['key' => '...'],
]);
PHAI::setPriority(['gemini', 'openai']);
PHAI::setTimeout(30);

$result = PHAI::cluster('Summarize this text', ['provider' => 'gemini']);
```

MCP:

```php
PHAI::tool(
    'get_user',
    'Get one user by ID',
    ['id' => ['type' => 'integer']],
    fn(int $id) => PHDB::find('users', $id)
);

PHAI::prompt('welcome', 'Welcome prompt', ['name' => 'string'], fn($name) => "Welcome {$name}");
PHAI::resource('mystack://status', 'Status', 'Framework status', fn() => PHMO::health(true));
PHAI::routes('/mcp');
```

`serve`, `clusterAPI`, `bridge`, resource templates, middleware and aliases are supported. Keep remote bridge/URL access allowlisted, TLS-verified, timeout- and size-bounded with private-network rejection (SSRF protection). Validate AI output as untrusted data. Set an API key for `serve`/`clusterAPI` — a null key means an open endpoint — and put MCP routes behind application authorization.

## 16. PHMO monitoring and debug UIs

```php
PHMO::configure(['enabled' => true]);
PHMO::registerRoutes();
PHMO::dashboard('/monitor');
```

- `/health`: lightweight liveness;
- `/ready`: PHLS/database dependency readiness, HTTP 503 on failure;
- `requestId()` and `traceId()`: request correlation;
- `log()`: structured JSON log;
- `metrics()`: request count, error rate, latency average/p95;
- `report()`: bounded problem/detail report;
- `.mystack/`: direct private storage, 90-day log retention and size-bounded rotation;
- `/monitor`: responsive read-only UI, route exists only while debug is true.

```php
PHMO::log('info', 'Order paid', ['order_id' => 1001]);
$health = PHMO::health(true);
$metrics = PHMO::metrics();
```

Debug tools:

```php
PHDE::apibar('/apibar');
PHRQ::livemap('/livemap', ['POST' => '/livemap'], 5, 60 * 24);
PHCD::initialize('/cdn', DIR::path('css'), DIR::path('js'));
```

These must not remain enabled as public routes/UIs when `PHDE::debug(false)`. PHCD remote install/update requires an application authorization callback.

## 17. Full library catalog

Below is the primary public API inventory. Dynamic gateway subclasses, QR internals, shield internals and magic-generated PHJS/PHJC chain methods are not listed individually; they follow the parent/facade contract.

| Library | Responsibility | Primary public API |
| --- | --- | --- |
| `DIR` | path/URL resolver | `initialize`, `path`, `link`, `raw`, `secureRequire`, `getRootDir`, `getBaseUrl` |
| `Importer` / `import()` | contextual dynamic loader | `getInstance`, `setContext`, `clearContext`, `load`, global `import` |
| `PHAI` | AI/MCP | `setAccounts`, `setPriority`, `setModels`, `setTimeout`, `getModels`, `cleanup`, `serve`, `clusterAPI`, `cluster`, `routes`, `tool`, `prompt`, `resource`, `resourceTemplate`, `alias`, `middleware`, `bridge` |
| `PHAP` | compact REST API | `api`, `all`, `get`, `add`, `up`, `rm`, `run`, `input`, `resource`, `ok`, `fail`, `valid`, `page`, `auth`, `send`, `item`, `collection`, `clean` |
| `PHAU` | identity/OAuth | `identityLib`, `make`, `check`, `verifyMake`, `verifyCheck`, `socialProviders`, `socialUrl`, `listenCallback` |
| `PHCD` | browser package manager | `initialize`, `handleRequest`, `get`, `use` |
| `PHCO` | secure cookies/project namespace | `isSecure`, `path`, `pre`, `add`, `update`, `remove`, `get`, `exists`, `expired`, `active`, `getExpiredDetails`, `makeExpired`, `getAll` |
| `PHCS` | utility CSS engine | `config`, `HTML`, `addHtml`, `CSS`, `addCss`, `process`, `build`, `registerUtilityHandler`, `processHtml`, `processCss`, `generateCss`, `buildCss` |
| `PHDB` | database/ORM | `error`, `id`, `affected`, `checker`, `connect`, `disconnect`, `query`, `fast`, `first`, `scalar`, `save`, `insert`, `batchInsert`, `update`, `delete`, `select`, `find`, `specificSelect`, `getValue`, `getSpecificValue`, `addDB`, `createTable`, `dropTable`, `alterTable`, `truncateTable`, `findBy`, `search`, `columns`, `deleteBy`, `paginate`, `sum`, `avg`, `max`, `min`, `count`, `exists`, `api`, `transaction`, `clean`, `array`, `array_get`, `array_set`, `close` |
| `PHDE` | debug/error | `enableErrorReporting`, `disableErrorReporting`, `customErrorHandler`, `debug`, `isDebug`, `errors`, `errorJSON`, `getType`, `displayErrors`, `api`, `apibar`, `file`, `memory` |
| `PHED` | encryption | `hide`, `make`, `score`, `key` |
| `PHEM` | SMTP/IMAP/POP3 | `smtp`, `imap`, `pop`, `smtpLogin`, `imapLogin`, `popLogin`, `smtpGet`, `imapSend`, `popSend`, `imapGet`, `popGet`, `smtpSend`, `showLog`, `queue`, `queueSend` |
| `PHEV` | WebSocket/SSE/StreamUI | `allowWebWorker`, `initialize`, `start`, `restart`, `stop`, `running`, `clients`, `debugClients`, `getHandler`, `handler`, `message`, `broadcast`, `disconnect`, `initHeaders`, `sendSE`, `setRetry`, `stream`, `streamUInew`, `streamUI` |
| `PHFY` | ntfy/Web Push | `configure`, `config`, `public`, `private`, `send`, `clientConfig`, `webPushCapability`, `cryptoCapability`, `registerRoutes`, `privateFeed` |
| `PHJC` | component/view compiler | `fastUI`, `ui`, `icon`, `slot`, `layout`, `clearCache`, `view`, `includeView`, loop helpers, `share`, `directive`, `minify`, `metaPreset`, `breadcrumb`, `head`, `buildHead`, `newHTML`, `singleHTML`, `mergeHTML`, `p2j`, `h2p`, `css`, `generateId`, `import`, `header`, `body`, `streamJS`, `newJS`, `phjs`, `use`, `render`, builder magic methods |
| `PHJS` | PHP→JS/PHJS bridge | typed values (`expr`, `value`, `arrayValue`, `object`, `template`), declarations, DOM, events, storage, network, application helpers, OAuth/2FA/payment helpers, control flow, function/class/module builders, `script`, `moduleScript`, `gen` |
| `PHJT` | JWT | `key`, `rotate`, `algorithm`, `create`, `verify` |
| `PHLS` | local SQLite state | `disconnect`, `setFile`, `checker`, `add`, `addIfAbsent`, `update`, `remove`, `expire`, `expireAllExpired`, `isExpired`, `getExpiredDetails`, `getActiveDetails`, `limitizer`, `get`, `getAll`, `remember`, `increment`, `decrement`, `flushByTag`, `removeAll` |
| `PHML` | markup/composition | `share`, `partial`, `layout`, `block`, `yieldBlock`, `component`, `hasComponent`, `render`, `init`, `autoAssets`, `use`, `meta`, `title`, `js`, `css`, `uiConfig`, `head`, `footer`, `html`, `body`, `clearCache`, `process` |
| `PHMO` | observability | `configure`, `config`, `requestId`, `traceId`, `isProbeRequest`, `registerRoutes`, `dashboard`, `report`, `health`, `metrics`, `log`, `finishRequest` |
| `PHOB` | code-protection bridge | `capability`, `build`, `use`, `deviceID` |
| `PHOP` | file/media operations | `img`, `video`, `zip`, `text` |
| `PHPA` | payment/courier facade | `courier`, `extendCourier`, `courierAvailable`, `courierProfile`, `extend`, `available`, `gatewayCapabilities`, dynamic gateway factories |
| `PHQR` | QR | `make` |
| `PHRO` | router/WAF | `initialize`, `guard`, `secure`, CSRF helpers, `trustProxies`, HTTP methods, `group`, `crud`, `gap`, `sgap`, `add`, fluent `name/middleware/header/mcp`, attempts, tasks, stream/channel/publish, route lookup, IP/device/tracking helpers, sitemap/robots/manifest, `listen` |
| `PHRQ` | request/CORS/CSP/stream | `php`, `js`, `header`, `cross`, `status`, `file`, `livemap`, `stream` |
| `PHSE` | session | `start`, `add`, `update`, `remove`, `get`, `isActive`, `expireAll`, `removeAll`, `regenerateId`, `getAll`, `getExpiryTime` |
| `PHTM` | date/time | `setZone`, `getZone`, `getTime`, `setTime`, `calculate`, `modify`, `format`, `to12h`, `to24h` |
| `PHTP` | OTP/Authenticator | `configure`, `key`, `code`, `verify`, `url`, `enroll`, `confirm`, `authenticate`, `status`, `recovery`, `disable` |
| `PHTR` | translation | `translate`, `auto`, `buildUrl`, `parseResponse` |
| `PHUI` | UI registry | `ui`, `element`, `section`, `layout`, `page`, `exists`, `register`, `registerMany`, `alias`, `search`, `categories`, `count`, `attributes`, `check`, `render`, `boot`, `catalog` |
| `PHVD` | validation | `check`; database rule helpers `PhvdRule::unique`, `PhvdRule::exists` |

### 17.1 Exact public method index of the large facades

Use this list to quickly find currently declared public entry points beyond magic/dynamic calls. Argument/return signatures can change; inspect the source method before use.

**PHJC**

```text
fastUI, ui, icon, slot, layout, clearCache, view, includeView,
startLoop, currentLoop, endLoop, share, directive, minify, metaPreset,
breadcrumb, reset, head, buildHead, newHTML, singleHTML, mergeHTML,
p2j, h2p, css, countElements, generateId, import, header, body,
streamJS, newJS, phjs, use, render_h, render_c, render_b, render_j,
app, render, __call, __callStatic, set, op, get, endFun, endCod
```

**PHJS — bridge, DOM, storage and application helpers**

```text
assets, js, __callStatic, render, parse, alpineData, alpineStore,
alpineBind, el, refs, store, watch, dispatch, nextTick, root, data,
id, state_magic, params_magic, route_magic, ui_magic, os_magic,
t_magic, router_magic, clipboard_magic, hxProcess, hxTrigger, hxAjax,
hxRemove, hxAddClass, hxRemoveClass, hxToggleClass, hxConfig,
const, let, var, log, error, warn, table, localSet, localGet,
localRemove, sessionSet, sessionGet, cookieSet, html, text, val,
addClass, removeClass, toggleClass, css, attr, remove, event, onReady,
redirect, reload, alert, fetch, raw, appReady, appNavigate, appLink,
appApi, appRoutePath, appToast, appModal, appProgress, appTheme,
appThemeToggle, appValidate, appCheck, appSeo, appI18n, appStoreGet,
appStoreSet, appStoreDispatch, appDbStorageSet, appDbStorageGet,
appDbStorageDel, appDbSync, appRequest, appUpload, appSearch,
appSearchIndex, appHardware, appDrmProtect, appFsRead, appFsSave,
appMediaInit, appChartInit, appWorker, appInspector, appPalette,
appA11yTrap, appDesignSet, appDesignGet, appTimeFormat, appTimeAgo,
appAuthTotp, appOAuthStart, appOAuthCallback, appTwoFactorSubmit,
appPaymentStart, appPaymentStatus, appHeroUpdate, appAnimateTo,
appAnimateSpring, appFontLoad, appAi, appXrInit, appPwaEnable,
appHydrate
```

The `alpine*` and `hx*` names are compatibility bridges; they do not make external Alpine/HTMX runtimes mandatory. Prefer PHJS-native directive/style in new application code.

**PHJS — typed JavaScript/compiler API**

```text
expr, value, translate, arrayValue, object, template, statement,
program, compile, module, build, arrow, functionDef, assign,
returnValue, throwValue, awaitValue, invoke, construct, dynamicImport,
ternary, ifBlock, forOf, whileBlock, doWhileBlock, forBlock,
switchBlock, tryCatch, classDef, importModule, exportDefault,
exportNamed, call, script, moduleScript, gen
```

**PHRO**

```text
initialize, guard, secure, getToken, csrfField, regenerateToken,
trustProxies, root, getCallbackContext, get, post, put, patch, delete,
head, options, group, crud, gap, sgap, add, name, middleware, header,
mcp, gatherRequestData, attempt, resetAttempt, task, stream, channel,
publish, routes, route, source, getUserIP, gatherHeaders,
getGeolocationData, extractIdentityFromCookie, netKey, deviceKey,
decrypt, key, track, footprint, setIdentityCookie, userAgentInfo,
createSlug, sitemap, disallow, allow, getSitemapRoutes, manifest,
addSitemapEntry, addRobotsRule, listen
```

**PHDB**

```text
error, id, affected, checker, connect, disconnect, query, fast, first,
scalar, save, insert, batchInsert, update, delete, select, find,
specificSelect, getValue, getSpecificValue, addDB, createTable,
dropTable, alterTable, truncateTable, findBy, search, columns,
deleteBy, paginate, sum, avg, max, min, count, exists, api,
transaction, clean, array, array_get, array_set, close
```

**PHAI / PHPA / PHFY / PHMO**

```text
PHAI: setAccounts, setPriority, setModels, setTimeout, getModels,
registerBridgeProcess, getBridgeProcess, cleanup, serve, clusterAPI,
cluster, getInstance, routes, tool, prompt, resource, resourceTemplate,
alias, middleware, handleRequest, bridge

PHPA: courier, extendCourier, courierAvailable, courierProfile, extend,
available, gatewayCapabilities, __callStatic

PHFY: configure, config, public, private, send, clientConfig,
webPushCapability, cryptoCapability, registerRoutes, privateFeed

PHMO: configure, config, requestId, traceId, isProbeRequest,
registerRoutes, dashboard, report, health, metrics, log, finishRequest
```

### 17.2 Library-by-library detailed API reference

In the list below, "no variables" means the class configuration is private and must be controlled through methods. Before directly mutating a public array/map, check the source contract; prefer documented setter/register methods.

#### 17.2.1 `DIR` and `Importer`

**Work:** resolves project root, base URL, typed directory aliases and safe dynamic imports. No public configuration variables.

| Method | Work and result |
| --- | --- |
| `DIR::initialize(array $options = [])` | Initializes a custom root/directory map; the entry point to override portable hosting detection. |
| `DIR::path($key)` | Resolves `app:Name`, `component:Name`, `library:PHDB`, `js:file.js` to a local filesystem path. |
| `DIR::link($key, $cacheBust = false)` | Builds the public URL for the same key; the second argument true adds a cache-busting query. |
| `DIR::raw($key)` | Reads the raw content of the resolved file; the caller must escape according to output context. |
| `DIR::secureRequire($key, array $data = [])` | Safely executes a resolved PHP file with scoped data and returns the result. |
| `DIR::getRootDir()` / `getBaseUrl()` | Return the current detected root directory and base URL. |
| `Importer::getInstance()` | Returns the shared importer instance. |
| `setContext()` / `clearContext()` | Set/clear temporary context passed into imported PHP files. |
| `load(...$args)` / global `import(...$args)` | Resolve/emit multiple typed imports, wildcards, PHP requires, CSS links, JS scripts or asset URLs. |

#### 17.2.2 `PHDE`

**Work:** debug state, PHP error capture/display, API error envelope, memory/file response and the API Bar. No public variables; `debug()`/`isDebug()` are the source of truth.

| Method | Work and result |
| --- | --- |
| `enableErrorReporting()` / `disableErrorReporting()` | Enable/disable PHP error reporting and the framework handler. Keep detailed display off in production. |
| `customErrorHandler(...)` | Routes PHP warnings/errors into the framework error pipeline; normally not called directly. |
| `debug($state = true)` | Sets the runtime debug boolean; chain/config bootstrap entry point. |
| `isDebug(): bool` | Returns the current debug state. Debug-only routes/UIs follow this value. |
| `errors($state = true)` / `displayErrors($state = true)` | Apply the error capture/display policy. |
| `errorJSON()` | Returns/emits captured errors as an API-ready JSON response. |
| `getType()` | Determines the current error/request output type. |
| `api($method = 'application/json')` | Sets the API content type on the error/output pipeline. |
| `apibar($url = '/apibar')` | Registers the self-contained route/request/debug UI; must be unavailable when debug is false. |
| `file($name, $length)` | Prepares download/stream response headers. |
| `memory($limit)` | Sets the PHP memory limit, e.g. `512M`. Cannot exceed the hosting hard limit. |

#### 17.2.3 `PHRO`

**Work:** router, route builder, middleware, WAF, CSRF, proxy/IP, request identity, rate/attempt protection, channels, SEO endpoints and the final dispatcher. No public configuration variables.

| Method group | Methods and work |
| --- | --- |
| Bootstrap | `initialize($basePath)` sets a custom route base; `guard($config)` enables session/security shields; `listen($errorHandler)` dispatches asset/system/application routes and ends the request. |
| HTTP routes | `get`, `post`, `put`, `patch`, `delete`, `head`, `options`, generic `add` register routes; each returns a fluent route object. |
| Route composition | `group` prefix scope, `crud` standard CRUD, `gap` generated API pattern, `sgap` secure/generated API pattern. |
| Fluent metadata | `name` route name, `middleware` authorization/transform chain, `header` response header, `mcp` gives the route MCP metadata. |
| CSRF/security | `secure()` whether the request is secure; `getToken()` returns/creates the token; `csrfField()` hidden input; `regenerateToken()` rotates the token. |
| Proxy/IP | `trustProxies(array)` sets trusted proxies/CIDRs; `getUserIP()` validated client IP; `gatherHeaders()` normalized headers; `getGeolocationData()` available network/location context. |
| Request context | `root()` base URL/path, `getCallbackContext()` active callback data, `gatherRequestData()` merged query/body/files, `routes()` route list, `route()` named/identifier lookup, `source()` route source metadata. |
| Abuse control | `attempt()` event/user/IP attempt check and increment; `resetAttempt()` resets the counter after success. The guard's own rate limiting is PHLS-backed. |
| Task/stream/channel | `task(...$tasks)` background-compatible task dispatch; `stream($provider)` streaming response; `channel($id)` worker channel; `publish($id,$command,$data)` channel message. |
| Identity/tracking | `netKey`, `deviceKey`, `extractIdentityFromCookie`, `setIdentityCookie`, `userAgentInfo`, `track`, `footprint`, `decrypt`, `key` manage device/network identity and the encrypted footprint. |
| SEO/PWA | `createSlug`, fluent `sitemap`, `allow`, `disallow`, `getSitemapRoutes`, `manifest`, `addSitemapEntry`, `addRobotsRule`. |

`name`, `middleware`, `header`, `mcp`, `sitemap`, `allow`, `disallow` are chained instance methods after route registration. Forwarded headers are trusted only after `trustProxies()` validation.

#### 17.2.4 `PHOB`

**Work:** bridges the optional proprietary `phob` PHP extension for protected code packaging and a stable server device identifier. No public variables.

| Method | Work |
| --- | --- |
| `capability(...)` | Detects/normalizes the extension capability map. |
| `build(...)` | Builds protected/obfuscated output from PHP sources with key/pass/license/device/expiry config. |
| `use(...)` | License-checked runtime loading of protected files. |
| `deviceID(...)` | Server machine identifier for license binding; not an authentication substitute. |

#### 17.2.5 `PHEV`

**Public variable:** `PHEV::$retry = 1000` — SSE retry delay (milliseconds). Prefer `setRetry()`.

| Method group | Work |
| --- | --- |
| WebSocket lifecycle | `initialize($path,$address,$port)`, `start`, `restart`, `stop`, `running` server lifecycle. Long-running CLI/service supervisor required. |
| Worker policy | `allowWebWorker(bool)` enables/disables web-worker compatible behavior. |
| Clients | `clients`, `debugClients`, `disconnect($clientId)`, `message($clientId,$message)`, `broadcast($message)`. |
| Handlers | `handler($requestPath,$action,$handler)` binds event/route actions; `getHandler($message)` resolves the matching handler. |
| SSE | `initHeaders`, `sendSE($data,$event,$id)`, `setRetry($ms)`, `stream($callback,$interval)`. |
| StreamUI | `streamUInew($key,$interval)` and `streamUI($name,$interval)` register live UI endpoints/bridges. |

#### 17.2.6 `PHEM`

**Work:** dependency-free SMTP send and IMAP/POP3 mailbox access. No public variables.

| Method group | Work |
| --- | --- |
| Server config | `smtp(host,port,secure)`, `imap(host,port,secure,folder)`, `pop(host,port,secure,folder)` set connection profiles. |
| Login | `smtpLogin`, `imapLogin`, `popLogin` set the respective username/password. Do not log secrets. |
| Read | `smtpGet`, `imapGet`, `popGet` collect messages/results by filter/limit. |
| Send | `smtpSend`, `imapSend`, `popSend` run send operations with from/name/to/cc/bcc/subject/message. |
| Queue bridge | `queue(...)` enqueues the current SMTP config + message into the private console queue; `queueSend($payload)` restores and delivers in worker context. |
| Diagnostics | `showLog()` returns the protocol transaction log; do not expose sensitive content in production output. |

#### 17.2.7 `PHML`

**Public variables:** `$flatAttrMap`, `$components`, `$sharedData`, `$treeCache`, `$tagAliases`, `$attrAliases`, `$unsafeKeywords`. These are parser/registry state; use methods instead of direct mutation. `$unsafeKeywords` is security-sensitive.

| Method group | Work |
| --- | --- |
| Shared composition | `share`, `partial`, `layout`, `block`, `yieldBlock` manage shared data, partials/layouts and named content blocks. |
| Components | `component`, `hasComponent`, `render` register/check/render components. |
| Parser/bootstrap | `init`, `process`, magic `__callStatic`, `__toString` parse the PHML DSL and render HTML. |
| Document/assets | `autoAssets`, `use`, `meta`, `title`, `js`, `css`, `uiConfig`, `head`, `footer`, `html`, `body` compose the document and attach assets. |
| Cache/map | `getFlatAttrMap()` returns the normalized attribute map; `clearCache()` clears parser/tree caches. |

**`PHML::init()` — bootstrap and auto-asset pipeline:** `index.php` must call `PHML::init()` **once** before `PHRO::listen()`. It sets meta defaults and opens an output buffer whose callback is `PHML::process()`. At request end `process()` takes the buffered HTML and: (1) runs PHCS — builds the per-route CSS cache file (`src/cache/css/<route>.css`) from used utility classes, (2) runs PHJS — builds the per-route JS cache file from `data-phjs`/`x-on` directives, (3) injects SEO `meta` tags, `<link>` (CSS) and, when `autoAssets` is on, `<script src="/app.js">` (the PHJS runtime). If the content already contains `<html>…</head>…</body>`, injection happens inside them; otherwise the shell is built.

- To serve the runtime, the `/app.js` route must be registered separately (`PHJC::app('/app.js', producer)` returning canonical `src/js/PHJS-min.php`).
- `PHML::autoAssets(false)` disables the auto `<script src="/app.js">` tag (to place your own script).
- Under `php -S` (without a router), extension-less `/app.js` is treated as a static file and 404s; Apache/nginx rewrite routes it correctly. CSS cache files are real files and serve everywhere.
- Calling `init()` again in the same request does not duplicate the buffer (`$bufferStarted` guard).

#### 17.2.8 `PHCS`

**Work:** PHP-native Tailwind-style utility scanner/compiler. No public configuration variables. Both instance and static APIs exist.

| Method | Work |
| --- | --- |
| `config(array)` | Merges theme, utilities, variants, colors and build configuration. |
| `HTML(string)` / instance `addHtml(string)` | Adds HTML/PHML content for class scanning. |
| `CSS(string)` / instance `addCss(string)` | Adds raw/custom CSS source. |
| `process($content,$type='html')` | Parses/processes HTML/CSS input in one step and returns output. |
| `build($modular=false)` / `buildCss(...)` | Produces final CSS from collected sources. |
| `registerUtilityHandler($pattern,$handler,$priority)` | Custom utility parser/handler extension point. |
| `processHtml`, `processCss` | Instance-level parser entry points. |
| `generateCss(array $classes)` | Generates CSS for a specific class list. |

**New utility sets (additive handlers):** `rtl`/`ltr` (direction), `word-spacing-{tighter..widest|3px}`, `object-{center|top|bottom|left|right|left-top|...}` (object-position), `overscroll-{auto|contain|none}` + `overscroll-x/y-*`, `writing-{horizontal-tb|vertical-rl|...}` + `text-orientation-*`, `image-rendering-{auto|pixelated|crisp-edges|smooth}`, `scrollbar-gutter-{auto|stable|both-edges}`, `content-visibility-{auto|visible|hidden}` + `contain-{none|strict|content|layout|paint|style|size|inline-size|block-size}`, `clear-{left|right|both|none|start|end}`, `box-border`/`box-content`, `tab-{n}`, `field-sizing-{content|fixed}`. Like all utilities they support responsive/variant (`md:`, `hover:`) prefixes and the arbitrary `[prop:value]` fallback.

#### 17.2.9 `PHJS`

**Public variable:** `PHJS::$debug` — PHP-side generator diagnostics. The browser runtime debug source is the `PHDE::isDebug()`-injected config; do not confuse the two.

| API group | Methods and work |
| --- | --- |
| Entry/parser | `assets`, `js`, `render`, `parse`, `gen`, magic `__callStatic` produce JS or attribute output from natural/DSL input. |
| Compatibility bridge | `alpineData`, `alpineStore`, `alpineBind`, `hxProcess`, `hxTrigger`, `hxAjax`, `hxRemove`, class helpers, `hxConfig` give compatible behavior/output without external runtimes. |
| Reactive magic | `el`, `refs`, `store`, `watch`, `dispatch`, `nextTick`, `root`, `data`, `id`, `state_magic`, `params_magic`, `route_magic`, `ui_magic`, `os_magic`, `t_magic`, `router_magic`, `clipboard_magic` create runtime context expressions. |
| JS basics | `const`, `let`, `var`, `log`, `error`, `warn`, `table`, `raw`, `alert`, `redirect`, `reload`. |
| Storage | `localSet/Get/Remove`, `sessionSet/Get`, `cookieSet` generate local/session/cookie operations. |
| DOM | `html`, `text`, `val`, `addClass`, `removeClass`, `toggleClass`, `css`, `attr`, `remove`, `event`, `onReady`. |
| Network/app | `fetch`, `appReady`, `appNavigate`, `appLink`, `appApi`, `appRoutePath`, `appRequest`, `appUpload` bridge the PHJS request/router. |
| UI/service helpers | `appToast`, `appModal`, `appProgress`, theme, validation, SEO, i18n, store/DB sync, search/index, hardware/DRM, filesystem, media/chart, worker, inspector, palette, accessibility trap, design, time, animation, font, AI, XR, PWA, hydrate. |
| Auth/payment helpers | `appAuthTotp`, `appOAuthStart`, `appOAuthCallback`, `appTwoFactorSubmit`, `appPaymentStart`, `appPaymentStatus` generate same-origin/CSRF/idempotent UI flows; they do not replace server verification. |
| Typed compiler | `expr`, `value`, `translate`, `arrayValue`, `object`, `template`, `statement`, `program`, `compile`, `module`, `build` turn PHP values/AST into safe JS. |
| Flow/function/class | `arrow`, `functionDef`, `assign`, `returnValue`, `throwValue`, `awaitValue`, `invoke`, `construct`, `dynamicImport`, `ternary`, `ifBlock`, loops, `switchBlock`, `tryCatch`, `classDef`. |
| Modules/scripts | `importModule`, `exportDefault`, `exportNamed`, `call`, `script`, `moduleScript` build ES module/script output. |
| Browser conveniences | `confirm`, `prompt`, `open`, `back`, `print`, `focus`, `blur`, `scrollTo`, `copy`, `stopPropagation`, `matchMedia`, `raf`, `geolocation`, `debounce`, `throttle` — pure browser-JS emitters (no runtime subsystem needed); companions to `alert`/`redirect`/`reload`. |

The browser-side `APP` runtime subsystems — state, directive compiler, component lifecycle, request de-duplication, SPA, CSS preparation, page cache, keymap, accessibility, PWA, PHFY, devtools — are described in section 10. The canonical source is always `src/js/PHJS-min.php`.

**PHJS usage guide:**

*1) Runtime wiring:* calling `PHML::init()` in `index.php` auto-injects `<script src="/app.js">` into every HTML response; PHRO itself serves the `/app.js` route (`src/js/PHJS-min.php`). Nothing else must be added.

*2) Declarative actions (HTML attribute):* put `phjs="event: instruction"` or `x-on:event="instruction"` on an element and the runtime executes it. Supported verbs: `toggle <sel>`, `open/close <sel>`, `show/hide <sel>`, `addClass/removeClass/toggleClass`, `toast "msg"`, `navigate <url>`, `redirect <url>`, `scroll <sel|top|bottom>`, `copy <text>`, `confirm <msg>`, `store <key> <value>`, `emit <event>`, `fetch <url>`, `submit`, `set`, `theme <name>`, `switch`, `tab`, `modal`.
```php
// Build the attribute value from PHP:
PHJS::gen('click: toast "Saved"');           // -> compiled JS string
// Directly in HTML:
// <button x-on:click="toggle #menu">Menu</button>
```

*3) PHP builder (JS emit):* `PHJS::gen($instruction)` declarative→JS; `PHJS::app*()` helpers emit runtime `APP.*` calls; browser conveniences emit standard JS.
```php
PHJS::appToast('Saved', 'success');   // APP.ui.toast("Saved","success");
PHJS::appNavigate('/dashboard');      // APP.navigate("/dashboard");
PHJS::confirm('Delete?');             // window.confirm("Delete?")
PHJS::debounce(PHJS::expr('save'), 300);
```

*4) Passing behavior into PHUI components:* give any `PHUI::ui()`/`@component` a `phjs` key — PHUI maps it to `x-on:` attributes.
```php
PHUI::ui('ui:button-primary', ['slot' => 'Save', 'phjs' => ['click' => 'toast "Saved"']]);
```
```blade
<x-ui-button-primary phjs-click='toast "Saved"'>Save</x-ui-button-primary>
```

#### 17.2.10 `PHJC`

**Public variables:** `$loops` active loop context; `$tagMap` builder method→HTML tag map; `$attributeMap` attribute alias map. No direct mutation without an extension need.

| Method group | Work |
| --- | --- |
| View/UI | `fastUI`, `ui`, `icon`, `slot`, `layout`, `view`, `includeView`, `use`, `render` load and render components/views. |
| Cache | `clearCache()` clears compiled CSS/JS/PHP view cache. |
| Loop/state | `startLoop`, `currentLoop`, `endLoop`, `share`, `set`, `get` manage render context and iteration state. |
| Render helpers | `once` (@once renders once), `classes` (@class conditional class merge) — called from compiled templates. |
| Extensibility | `directive` custom directive; `metaPreset` metadata preset; `op` builder operation. |
| Document | `breadcrumb`, `reset`, `head`, `buildHead`, `header`, `body`, `css` compose HTML head/body/meta. |
| Conversion | `newHTML`, `singleHTML`, `mergeHTML`, `p2j`, `h2p`, `countElements`, `generateId`, `import` HTML/PHP/JSON conversion and helpers. |
| JS builder | `streamJS`, `newJS`, `phjs`, `app`, `render_h/c/b/j`, `endFun`, `endCod` compiled JS/HTML builder chain. |
| Template directives | Blade-style: layout `@extends/@section/@yield/@push/@prepend/@stack/@hasSection/@sectionMissing/@fragment`; control `@if/@unless/@foreach/@forelse/@each/@switch/@once/@verbatim/@continue`; form `@csrf/@method/@old/@checked/@selected/@disabled/@readonly/@required`; output `@js/@json/@asset/@class`; component `@component(...)...@endcomponent` + `@slot(name)...@endslot` — aliases of `<x-*>`/`PHJC::slot()` (default `{{ $slot }}`, named slot `PHJC::slot('name')`). |
| Magic API | `__call`, `__callStatic` resolve dynamic elements/operations per the `tagMap`/builder grammar. |

#### 17.2.11 `PHCO`

**Work:** secure project-scoped cookies and the portable project prefix/base path. No public variables.

| Method | Work |
| --- | --- |
| `isSecure()` | Detects HTTPS/secure-cookie context. |
| `path()` | Returns the application base path; injected into PHJS/PHFY/SW. |
| `pre()` | Returns the normalized project prefix, e.g. `shop_`; prevents cookie/storage collisions. |
| `add`, `update`, `remove` | Create/change/delete cookies; expiry in minutes and secure defaults apply. |
| `get`, `exists`, `getAll` | Collect cookie values/status. |
| `expired`, `active`, `getExpiredDetails`, `makeExpired` | Inspect/force-expire framework-managed expiry metadata. |

#### 17.2.12 `PHSE`

**Work:** secure PHP session lifecycle and expiring session values. No public variables.

| Method | Work |
| --- | --- |
| `start()` | Starts the session with strict/session-cookie defaults. The PHRO guard usually manages the lifecycle. |
| `add($key,$value,$expiry)` / `update` | Set/change session values with optional expiry. |
| `get($key,$default)` / `getAll()` | Read active session values. |
| `remove` / `removeAll` | Remove one or all values. |
| `isActive`, `getExpiryTime`, `expireAll` | Expiry checks and expired-value cleanup. |
| `regenerateId()` | Rotates the session ID to prevent fixation; use after login/privilege change. |

#### 17.2.13 `PHLS`

**Work:** local SQLite state/cache/rate-limit/subscription store. No public variables.

| Method group | Work |
| --- | --- |
| Storage lifecycle | `setFile($path)` custom DB path before the first connection; `disconnect()` releases the connection; `checker()` integrity/WAL/write health report. |
| CRUD/TTL | `add`, `addIfAbsent`, `update`, `remove`, `get`, `getAll`, `expire`, `isExpired`, expiry detail methods. |
| Cleanup | `expireAllExpired()` removes expired rows; `removeAll()` removes selected/all local data. Scope carefully. |
| Rate/atomic | `limitizer(...)` rate window/block state; `increment`/`decrement` atomic counters. |
| Cache | `remember($key,$ttl,$callback,$tags)` cache-aside; `flushByTag($tag)` invalidates related cache. |

The default path is private storage under `.mystack`. When lock retries are exhausted or the DB is malformed, preserve the caller-facing non-fatal fallback and the checker/recovery behavior.

#### 17.2.14 `PHDB`

**Public variables:**

| Variable | Meaning |
| --- | --- |
| `$host`, `$username`, `$password`, `$dbname` | MySQL/MariaDB connection settings. Never output/log the password. |
| `$charset = 'utf8mb4'` | Connection charset; keep the default for Unicode. |
| `$error = true` | Database error behavior/detail policy. Keep production disclosure limited together with `PHDE::debug(false)`. |
| `$driver = 'mysqli'` | Opt-in DB driver; default/empty auto-uses `mysqli` (the legacy path stays intact). `'sqlite'` and `'pgsql'` are available in the core API; other engines come later. |
| `$port = null` | Optional TCP driver port (mysqli otherwise takes host/php.ini). |
| `$options = []` | Driver-specific connection options; e.g. pgsql `['sslmode'=>'require','channel_binding'=>'require']`. |

| Method group | Work |
| --- | --- |
| Status/connection | `connect`, `disconnect`, `close`, `checker`, `error`, `id`, `affected` connection and last-operation state. |
| Query | `query($sql,$params,$single)`, `specificSelect`, `first`, `scalar`, value helpers — always use placeholders in raw SQL. |
| Streaming | `fast($sql,$params,$columns): Generator` unbuffered row stream; no query on the same connection during iteration. |
| Write | `save` upsert-like save, `insert`, `batchInsert`, `update`, `delete`, `deleteBy` prepared writes. Respect the empty-where/all-delete guard. |
| Read | `select`, `find`, `findBy`, `search`, `columns`, `exists`, `paginate` filtered/prepared reads. |
| Analytics | `sum`, `avg`, `max`, `min`, `count` aggregate results. |
| Database/schema | `addDB`, `createTable`, `alterTable`, `dropTable`, `truncateTable` database/schema lifecycle. Destructive operations need explicit approval and backups. |
| API/transaction | `api` table result API envelope; `transaction(callable)` atomic commit/rollback. |
| Maintenance | `clean($table,$options)` bounded cleanup/maintenance. |
| JSON/array columns | `array($action,...)`, `array_get`, `array_set` safely read/write nested values in encoded columns. |

**Multi-driver (opt-in):** `PHDB::$driver` is only `'mysqli'` (default), `'sqlite'` or `'pgsql'`. The mysqli path is untouched line by line; `'sqlite'` and `'pgsql'` give identical return shapes for connect/query/fast/CRUD/transaction/createTable/checker (SQLite is backtick and `?` placeholder compatible). Limitations: `clean()`, FULLTEXT `MATCH...AGAINST` and schema-sync reorder/type-change are mysqli-only; on other drivers they return clear errors/no-ops and never silently wrong results. `createTable()` on sqlite creates plus adds missing columns. pgsql details below.

**pgsql driver:** `PHDB::$driver = 'pgsql'` plus `$host/$port/$dbname/$username/$password` and, when needed, `$options = ['sslmode'=>'require','channel_binding'=>'require']`. The pgsql driver translates MySQL backtick identifiers to double quotes, keeps `?` placeholders, and uses emulated prepares for transaction-pooler (Neon/PgBouncer) compatibility. `createTable()` maps MySQL type shortcodes to pg types (id→BIGSERIAL, int→INTEGER, decimal→NUMERIC, json→JSONB, blob→BYTEA, datetime→TIMESTAMP). Verified with a 43-check live round-trip on Neon (CRUD/select operators/IN/LIKE/BETWEEN/streaming/transaction commit+rollback/save/batchInsert/api/columns/JSON array helpers). On non-mysqli, `createTable()` sync adds and drops columns (data preserved); column reorder and type-change are not auto-applied (to avoid destructive rebuilds). `insert()`/`batchInsert()` overwrite mode uses atomic `ON CONFLICT` upsert (introspecting the PK/UNIQUE target). pgsql `fast()` streams truly unbuffered via a server-side cursor. `clean()` and FULLTEXT `MATCH...AGAINST` remain mysqli-only — on other drivers they return clear errors/no-ops, never silently wrong results.

#### 17.2.15 `PHRQ`

**Work:** server-side cURL requests, generated browser requests, headers, CORS/CSP, status, file, streaming and Live Map. No public variables.

| Method | Work |
| --- | --- |
| `php($method,$url,$headers,$body,$options)` | TLS-verified server HTTP request; parsed/raw response contract depends on options. |
| `js(...)` | Produces browser-side JS output for the same request. Allowlist untrusted URLs. |
| `header($method,$origin,$contentType,$additional)` | Sets response/CORS headers. |
| `cross($enable,$origin,$credentials)` | Activates the framework CSP/CORS policy; true is self-aware, an array is an explicit allowlist. |
| `status($code,$msg)` | Sets/emits the HTTP status and an optional message. |
| `file($name,$length)` | File response headers. |
| `livemap($url,$skipList,$limit,$time)` | Request/network visualization route/UI; debug-only. |
| `stream($sleep,$type,$callback)` | Incremental response stream. |

#### 17.2.16 `PHQR`

**Work:** memory-safe QR generation. No public variables.

`PHQR::make($data, int $size = 8, int $margin = 4): string` returns a PNG data URI from input. Size is pixels per QR module, not the final image width. Show enrollment QRs containing secrets only on authenticated pages. Internal encoder classes (`QRCode`, `QRUtil`, …) are not application APIs.

#### 17.2.17 `PHED`

**Work:** authenticated application encryption/decryption and key strength. No public variables.

| Method | Work |
| --- | --- |
| `key($newKey)` | Sets the encryption key. Keep it separate from the router/JWT keys. |
| `make($string,$action)` | High-level encrypt/decrypt envelope. Handle failures per the source contract. |
| `hide($string,$key,$action)` | Lower-level encrypt/decrypt path with an explicit key. |
| `score()` | Returns the current key/capability strength score/status. |

#### 17.2.18 `PHTP`

**Work:** HOTP/TOTP primitives plus the PHLS/PHED-backed account Authenticator lifecycle. No public variables.

| Method | Work |
| --- | --- |
| `configure(array)` | Configures issuer, digits, period, algorithm, storage/encryption policy. |
| `key($length,$mode)` | Creates an OTP secret. |
| `code($secret,$mode,$digits,$time,$offset,$algo)` | Generates the code for a given secret/time. |
| `verify($otp,$secret,...)` | Primitive OTP verification per window/algorithm. |
| `url($account,$secret,...)` | Builds the `otpauth://` enrollment URL. |
| `enroll($account,$options)` | Starts a pending encrypted enrollment with recovery material. |
| `confirm($account,$code)` | Activates enrollment with the first valid code. |
| `authenticate($account,$code)` | Verifies a TOTP or recovery code and prevents replay/reuse. |
| `status($account)` | Returns enabled/pending/recovery status; must not expose the secret. |
| `recovery($account,$currentCode)` | Rotates recovery codes after verification. |
| `disable($account,$code,$force)` | Verified or explicitly forced 2FA disable. Force is not a substitute for admin authorization. |

#### 17.2.19 `PHTM`

**Work:** timezone-aware time reading, parsing, difference, modification and formatting. No public variables.

`setZone` sets the timezone, `getZone` returns the current zone, `getTime` now in format, `setTime` formats a timestamp, `calculate` the difference between two times, `modify` relative modifiers, `format` output conversion, `to12h`/`to24h` clock conversion. The caller must validate invalid timezone/date input.

#### 17.2.20 `PHVD`

**Work:** declarative validation plus database-aware uniqueness/existence. No public variables.

`PHVD::check(array $rules, array|bool|null $data = null, bool $debug = false): array` returns normalized validation result/errors/data per rules. `PhvdRule::unique($table,$column,$except)` and `exists($table,$column)` build rule strings. Server-side PHVD is mandatory even when client validation exists.

**New rule sets (additive):** presence — `prohibited`; text — `lowercase`, `uppercase`, `contains:a,b`, `ascii`, `utf8`, `hex`, `name`, `bengali`; numeric — `positive`, `negative`, `non_negative`, `multiple_of:n`, `even`, `odd`, `decimal:d` or `decimal:min,max`; date/time — `time`, `timestamp`, `date_equals:Y-m-d`; identity/format (standard checksum) — `iban` (ISO 13616 mod-97), `bic`, `isbn` (10/13), `imei` (Luhn), `jwt`, `data_uri`, `csv`, `url_https`. These are pure format/validation checks — no external service or DB calls — and combine with `nullable`/`required`.

#### 17.2.21 `PHCD`

**Work:** browser package search/install/update/use plus the responsive manager UI. No public variables.

| Method | Work |
| --- | --- |
| `initialize($state='/cdn',$css,$js,?callable $authorize)` | Registers the manager route, storage paths and authorization. Provide an authorizer for the remote UI. |
| `handleRequest()` | Validates and dispatches search/install/update/remove requests; normally reached from the initialize route. |
| `get($package,$type,$skipPKG,$skipFILE)` | Collects installed/remote package metadata or selected assets (returns an array). |
| `use($package,$type,$skipPKG,$skipFILE,$defer)` | Produces installed CSS/JS tags/output. |

Install/update keeps staging, safe filename/path filters, atomic activation and rollback. External packages still need their own license/CSP/security review.

#### 17.2.22 `PHJT`

**Work:** HMAC JWT creation/verification and key rotation. No public variables.

| Method | Work and return |
| --- | --- |
| `key($newKey)` | Sets the signing secret; returns a result envelope. Keep minimum strength. |
| `rotate($newKey,...)` | Manages key rotation/previous-key transition. Check the source signature for exact options. |
| `algorithm($newAlgorithm)` | Sets the supported HMAC algorithm (`HS256/384/512`); status envelope. |
| `create($payload,$expiresIn,$algorithm): array` | `status/message/data` envelope; `data` holds the signed token with `iat`, `exp`, `jti` added to the payload. |
| `verify($jwt,$algorithm): array` | Verifies signature, header algorithm, payload JSON and expiry; returns `status/message/data`. |

#### 17.2.23 `PHTR`

**Work:** URL, request and response normalization for configured remote translation providers. No public variables.

`translate($input,$serverName,$source,$target)` uses the selected provider, `auto($input,$targetLanguage)` automatic provider/source, `buildUrl(...)` the encoded endpoint URL, `parseResponse(...)` provider-specific normalization. Provide fallbacks for remote failure/terms/rate limits.

#### 17.2.24 `PHAU`

**Work:** identity creation/check, verification tokens/OTP, the OAuth/OIDC provider catalog and callbacks. No public variables.

| Method | Work |
| --- | --- |
| `identityLib($url,$options)` | Registers built-in identity UI/routes; review authorization/debug exposure. |
| `make($table,$dbMap,$inputData,$options): array` | Creates a validated identity/account record and token flow. Preserve password hashing rules. |
| `check($table,$tokenCol,$inputToken,$identityCol): array` | Token/identity lookup and authentication result. |
| `verifyMake(...)` | Creates the OTP/token verification challenge and runs configured mail/delivery. |
| `verifyCheck(...)` | Verifies the submitted code/secret and optionally updates account data. |
| `socialProviders(): array` | Returns the built-in OAuth/OIDC provider/mode catalog. |
| `socialUrl($provider,$config): array` | Builds the state/PKCE/nonce-aware authorization URL/context. |
| `listenCallback($route,$configs,$onSuccess)` | Registers the provider callback route, validates/normalizes state/code/token/userinfo and runs the success callback. |

#### 17.2.25 `PHOP`

**Work:** option parser/processor for image, video, ZIP and text operations. No public variables.

`img(...)` image transform/output options, `video(...)` media operation options, `zip(...)` archive create/list flow, `text(...)` text/file transformation. The exact positional/option contract varies per operation — read the source method. Validate path traversal, decompression bombs, executable uploads and memory limits at your trust boundary.

Note: `video(...)` requires host `exec()` enabled and an `ffmpeg` binary on PATH; where those are absent (typical shared hosting) the operation fails — a hosting dependency, not a framework guarantee.

#### 17.2.26 `PHAI`

**Work:** multi-provider AI facade, cluster/fallback, remote bridge and MCP server. No public variables.

| Method group | Work |
| --- | --- |
| Provider config | `setAccounts`, `setPriority`, `setModels`, `setTimeout`, `getModels` configure/read credentials/model/fallback order. |
| Process lifecycle | `registerBridgeProcess`, `getBridgeProcess`, `cleanup` track and release external bridge processes/pipes. |
| AI endpoints | `serve($prefix,$apiKey)`, `clusterAPI($path,$apiKey)` compatible API routes; `cluster($input,$options)` provider selection/fallback execution. |
| MCP | `routes`, `tool`, `prompt`, `resource`, `resourceTemplate`, `alias`, `middleware`, `handleRequest` register/dispatch MCP capabilities. The builder's `middleware()` and `retries()` give per-item policy. |
| Bridge | `bridge($target,$method,$params,$options)` guarded local/remote bridge call. Keep URL allowlist, TLS, timeout, response size and SSRF boundaries. |
| Instance | `getInstance()` returns the shared PHAI service. |

#### 17.2.27 `PHAP`

**Work:** REST routes, validation, auth, CRUD, pagination and JSON responses in very small syntax. No public variables.

| Method group | Work |
| --- | --- |
| Master route | `api('METHOD /path',$middleware,$rules,$logic,$message)` route+validation+logic+response in one call. |
| Smart CRUD | `all`, `get`, `add`, `up`, `rm` run table operations with standardized responses. |
| Custom action | `run($logic,$rules,$successMsg)` an arbitrary validated callback. |
| Input/validation/auth | `input`, `valid`, `auth` return normalized request data, PHVD validation and the authenticated identity. |
| Pagination | `page($table,$where,$limit,$callback)` paginated resource responses. |
| Resource transform | `resource`, `item`, `collection`, `clean` output field/filter/transform. |
| Response | `ok`, `fail`, `send` consistent status/message/data HTTP JSON envelopes. |

#### 17.2.28 `PHUI`

**Work:** a searchable reusable UI registry — element, section, layout and page renderers. No public variables; read the internal registry via `catalog()`.

| Method | Work |
| --- | --- |
| `ui($slug,$data)` / `render` | Generic slug render. Processes placeholders, attributes, slots and PHJS mapping into HTML. |
| `element`, `section`, `layout`, `page` | Type-constrained renders; wrong category/slug is caught quickly. |
| `exists($slug)` | Whether a component/alias exists. |
| `register($slug,$template,$meta,$replace)` | Registers a string/callable template; replacing existing needs the explicit flag. |
| `registerMany($components,$replace)` | Bulk registration; returns the registered count. |
| `alias($alias,$target,$replace)` | Maps an alternate slug. |
| `search($query,$group,$limit)` | Catalog search results by title/meta/slug. |
| `categories`, `count`, `catalog` | Registry groups, totals and complete metadata reads. |
| `attributes($attributes)` | Safe HTML attribute output from an array/string. |
| `check($value)` | Raw/template HTML risk checker result; run before rendering untrusted markup. |
| `boot()` | Loads the built-in semantic registry once. Usually automatic. |

**New built-in components (additive):** `ui:calendar`, `ui:skeleton`, `ui:empty-state`, `ui:carousel`, `ui:countdown`, `ui:input-otp`, `ui:color-picker`, `ui:combobox`, `ui:command-palette`, `ui:chart`, `ui:cookie-consent`, `ui:language-switcher`, `ui:theme-toggle`, `ui:back-to-top`, `ui:scroll-area`, `ui:hover-card`, `ui:context-menu`, `ui:tree`, `ui:resizable`, `ui:signature-pad`, `ui:dropzone`, `ui:mention-input` — all use shadcn-style theme classes, `{{class}}/{{style_attr}}/{{attr}}`, `@slot` and the placeholder convention; render with `PHUI::ui('ui:calendar', [...])`.

**More widgets and marketing sections (additive):** `ui:masonry`, `ui:speed-dial`, `ui:data-maps`, `ui:form-wizard`, `ui:scrollspy`, `ui:widget`, `ui:ticker` and `section:about`, `section:awards`, `section:careers`, `section:downloads`, `section:integrations`, `section:portfolio`, `section:projects`, `section:work`, `section:utilities`, `section:page-examples`.

**PHUI usage guide:**

*1) Three render paths (all equivalent):*
```php
PHUI::ui('ui:button-primary', ['slot' => 'Save']);                 // PHP
```
```blade
@component('ui:button-primary')Save@endcomponent                    // PHJC view
<x-ui-button-primary>Save</x-ui-button-primary>                     // component tag
```

*2) Slug convention:* `type:name` — `ui:` (widget), `form:` (input/select/switch…), `data:` (card/table/stat/gallery), `section:`/`sect:` (hero/pricing/team…), `shell:` (navbar/sidebar/dashboard), `auth:`/`payment:`/`courier:` (integration panels). Each widget has tone variants: `ui:button-primary`, `ui:badge-success`, `ui:alert-danger`, etc.

*3) Data/props + defaults:* pass an associative array; in templates `{{key|Default}}` — when no value is given it shows Default (or the key name). `class` merges; `attr`/`phjs`/`state`/`on` are separate keys.
```php
PHUI::ui('ui:alert-info', ['title' => 'Heads up', 'slot' => 'Body text', 'class' => 'mt-4']);
```

*4) Slots:* the default slot is inner content (`{{ $slot }}`); named slots `@slot(name)…@endslot` are read with `PHJC::slot('name')`.
```blade
@component('ui:widget', ['title' => 'Reports'])
  @slot('actions')<button>Edit</button>@endslot
  main body…
@endcomponent
```

*5) Theme-aware:* every variant uses semantic tokens (`bg-primary`, `bg-success`, `text-muted-foreground`, `border-border`) — colors follow `data-theme="dark"` or a custom theme automatically; never hardcoded colors.

*6) Registry introspection:* `PHUI::exists($slug)`, `PHUI::search('button')`, `PHUI::categories()`, `PHUI::count()`, `PHUI::catalog()`; add custom ones with `PHUI::register($slug, $template, $meta)` / `registerMany()` / `alias()`.

#### 17.2.29 `PHPA`

**Work:** a payment gateway factory/capability layer and a courier adapter registry. The facade has no public variables; returned gateway/courier objects provide fluent methods.

| Facade method | Work |
| --- | --- |
| Dynamic `PHPA::gatewayName()` | Returns a registered gateway adapter instance, e.g. `stripe`, `paypal`, `bkash`. Handle unknown names. |
| `available()` | Registered payment gateway list. |
| `gatewayCapabilities($name)` | Whether charge/verify/refund/webhook/sandbox etc. are supported. |
| `extend($name,$factory)` | Registers/overrides a custom/private payment adapter. |
| `courier($name)` | Returns a `PHPACourierInterface` adapter. |
| `courierAvailable()` / `courierProfile($name)` | Courier list and official/config profile metadata. |
| `extendCourier($name,$profileOrFactory)` | Adds/overrides a custom carrier contract. |

**Payment adapter contract:** `setKeys`, `setLogic`, `setRefundLogic`, `setWebhookLogic`, `setTransport`, `sandbox`, `charge`, `verify`, and per adapter `execute`, `refund`, `webhook`. Not every adapter supports every operation.

**Courier contract:** `setKeys`, `configure`, `sandbox`, `setTransport`, `create`, `track`, `rate`, `cancel`, `label`, `pickup`, generic `call`, `capabilities`. Provider profiles give default endpoint/auth mapping; use `configure`/a custom adapter when the merchant contract differs.

#### 17.2.30 `PHFY`

**Work:** ntfy public/private notification, user/permission/keyword filtering, the local private feed, PHLS push subscriptions and a self-contained VAPID sender. No public variables.

| Method | Work and return |
| --- | --- |
| `configure(array $options = []): array` | Merges defaults. Configures `enabled`, server/topics/tokens, VAPID, user/permissions/keywords, authorizer and poll interval. |
| `config(): array` | The effective normalized config; builds disabled defaults when unconfigured. |
| `public($message,$options)` / `private(...)` | Set the type and run the unified `send`. |
| `send($message,$options): array` | Builds payload ID/type/filter/data and attempts ntfy/local transport plus Web Push; returns status, code, transport/topic/web_push results. |
| `clientConfig($context): array` | Gives PHJS the base path, topic SSE, authorized private endpoint, user/filter, CSRF and VAPID capability. |
| `webPushCapability(): array` | Reports enabled/mode (`webpush` or `ntfy`) and key/crypto readiness. |
| `cryptoCapability(): array` | In-memory test results of the host's EC key, derive, sign and AES-GCM support. |
| `registerRoutes()` | Registers config, private feed and push subscribe/unsubscribe/send related same-origin routes once. |
| `privateFeed()` | Returns the authorized private poll response; normally not called outside the route callback. |

#### 17.2.31 `PHMO`

**Work:** zero-dependency observability — request/trace IDs, JSON logs, health/readiness, metrics, alerts, retention and the debug dashboard. No public variables.

| Method | Work |
| --- | --- |
| `configure($options): array` | Configures enabled, health/ready routes, request logging, file size and the `.mystack` log directory; retention keeps the last 90 days. |
| `config(): array` | Effective config. |
| `requestId()` / `traceId()` | Current request correlation IDs; generated/incoming trace context as applicable. |
| `isProbeRequest(): bool` | Whether the current path is a health/ready probe; reduces normal-request metric noise. |
| `registerRoutes()` | Registers `/health` and `/ready` (or configured paths). |
| `dashboard($url)` | Responsive read-only log/problem UI; the route is not registered when debug is false. |
| `report($date,$limit,$level,$search)` | Bounded log entries, groups, files/lines/details report. |
| `health($withDependencies)` | Liveness or PHLS/PHDB readiness array. Dependency failure means ready=false. |
| `metrics($date)` | Request count, error rate, latency average/p95 and status summary. |
| `log($level,$event,$context)` | Atomically appends/rotates a structured JSON line. Do not pass secret context. |
| `finishRequest()` | Final latency/status/memory/request metadata log at shutdown; may be registered automatically. |

## 18. Deployment, cache and production checklist

### Production bootstrap

```php
PHDE::debug(false);
PHRO::guard();
PHRQ::cross(true);
PHMO::configure(['enabled' => true]);
PHMO::registerRoutes();
```

Checklist:

- [ ] PHP 8.1+, required extensions and writable folders verified
- [ ] `php mystack doctor`, `audit`, `smoke` pass
- [ ] router, JWT, encryption, OAuth, payment, mail, ntfy/VAPID secrets are separate
- [ ] HTTPS, rewrite, base path, `/app.js`, `/sw.js` verified
- [ ] debug false; API Bar/Live Map/PHCD/monitor not unauthorized-reachable
- [ ] least-privilege DB user and schema-change backups exist
- [ ] backup restore actually tested
- [ ] Cloudflare/reverse-proxy CIDRs explicitly trusted
- [ ] CSP/CORS origins exact; no unnecessary camera/microphone/geolocation access
- [ ] auth/payment/account responses `no-store, private`
- [ ] OAuth callbacks, webhook signatures and idempotency tested
- [ ] PHFY permission and private authorization tested
- [ ] Android/iOS/Desktop target-browser push tested
- [ ] automated browser regression, synthetic uptime, stress/soak testing
- [ ] PHMO log retention/rotation and external alert delivery verified

### Cache

Component cache lives in three parts: `src/cache/css`, `src/cache/js`, `src/cache/php`. Missing paths auto-create; generated files keep readable component names. PHRO manages the `/app.js` long-cache/ETag and `/sw.js` revalidation policy. Verify CDN/origin cache does not wrongly mix debug/release or dynamic configuration.

## 19. Troubleshooting and verification

### First commands

```bash
php -l index.php
php mystack doctor
php mystack audit
php mystack smoke
```

### Common problems

**PHLS locked/malformed**

- check `.mystack` is writable;
- run `PHLS::checker()`;
- do not share one storage file across many hosts on a network filesystem;
- framework recovery attempts to salvage/rebuild data itself — do not manually delete a live DB file.

**Header/footer lost during SPA navigation**

- whether fetched responses are complete valid HTML;
- whether error responses/DB stack traces are being swapped as HTML documents;
- destination layout markers and CSS preparation;
- whether the PHJS synchronized-build smoke tests pass.

**White first paint / late CSS**

- component cache writable;
- destination CSS URL valid;
- CSS response content-type/status;
- the PHJS CSS-ready navigation smoke test;
- CDN/Cloudflare stale HTML and missing stylesheet mismatch.

**PHFY private 401/403**

- user session/authorizer/private permission;
- same-origin credentials and CSRF;
- whether the private endpoint arrived in client config while authorized. An unauthorized client stopping private polling is expected.

**Web Push not working**

```php
var_dump(PHFY::cryptoCapability());
var_dump(PHFY::webPushCapability());
```

Then check HTTPS, browser permission, Service Worker registration, PushManager subscription and platform restrictions.

**Live Map script blocked by CSP**

The current Live Map uses self-hosted/local compatible assets. Adding external scripts needs an explicit trusted source in CSP; ad-blocker Cloudflare beacon errors are not framework failures.

## 20. AI discovery and automatic API documentation

MyStack's AI-readable documentation is built from the current `library/*.php` source. Public classes, methods, parameter, return declarations, source hashes and doc summaries are indexed without executing any library code.

```bash
php mystack docs:build
php mystack docs:check
php mystack extension:check
```

Generated resources:

- `docs/index.html` — responsive and searchable static documentation portal;
- `docs/index.md` and `docs/api/*.md` — human-readable source references;
- `docs/api.json` — machine-readable complete API catalog;
- `docs/manifest.json` — repository, license, build totals and source hashes;
- `llms.txt` — concise framework map for AI;
- `llms-full.txt` — full context with indexed public signatures and canonical documentation.

Both `llms` files are kept at the root and in `docs/`: AI discovery works through both the GitHub raw repository and the published Pages site paths. Static `robots.txt` and `sitemap.xml` are never generated; the PHRO router provides them at runtime. `.github/workflows/docs.yml` deploys only the generated `docs/` artifact to GitHub Pages; executable PHP source is not published through the documentation site. GitHub Pages must be enabled once with **GitHub Actions** as its source; later pushes to `main` publish documentation automatically. MyStack follows the rolling `main` branch and declares no fixed framework version.

After changing a public API, `docs:build` must run. `docs:check` detects stale documentation by comparing source hashes and public-method counts, and `php mystack extension:check` separately verifies AI-discoverable documentation plus the official VS Code extension's stubs, snippets, trusted-workspace/traversal guards and packaged VSIX; these artifact checks are kept outside `php mystack smoke`. Never hand-edit generated API pages; the executable source is the final truth.

### Guarantee boundary

Smoke tests verify code paths, synchronization and local capability. They do not prove live merchant account approval, third-party uptime, production DNS/TLS, browser vendor policy, real traffic scale or backup-restore success — target-environment end-to-end testing is mandatory for those. Likewise, host-dependent items such as PHOP `video(...)` needing host FFmpeg/`exec()`, PHEM delivery needing a reachable SMTP server, and `PHEM::queue()` keeping its SMTP credential and message content at rest inside the private `.mystack/console.sqlite` are deployment-level responsibilities — keep the `.mystack` and root `.env` (PHLS store) guards active and treat queue state as single-host.

---

Use this manual together with `README.md` (quick start) and `AGENTS.md` (engineering contract). When public behavior changes, update the code, smoke coverage and all documentation in the same change.
