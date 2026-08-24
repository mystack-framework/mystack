# MyStack Framework — পূর্ণাঙ্গ বাংলা ম্যানুয়াল

এই ম্যানুয়ালটি বর্তমান executable codebase, ৩০টি framework library, `library.php` loader, canonical PHJS runtime এবং `mystack` CLI যাচাই করে লেখা। এটি শুধু reusable MyStack framework, তার public contract এবং framework-level ব্যবহার বর্ণনা করে।

> গুরুত্বপূর্ণ: framework-এর বাস্তব code সর্বশেষ source of truth। কোনো live payment, courier, OAuth, mail, AI বা push provider ব্যবহার করার আগে সংশ্লিষ্ট provider-এর বর্তমান credential, sandbox এবং production approval দিয়ে end-to-end test করতে হবে।

## সূচিপত্র

1. [পরিচিতি ও ক্ষমতা](#1-পরিচিতি-ও-ক্ষমতা)
2. [প্রয়োজনীয় পরিবেশ ও installation](#2-প্রয়োজনীয়-পরিবেশ-ও-installation)
3. [Project structure ও portable path](#3-project-structure-ও-portable-path)
4. [Bootstrap ও configuration order](#4-bootstrap-ও-configuration-order)
5. [MyStack CLI](#5-mystack-cli)
6. [DIR ও dynamic importer](#6-dir-ও-dynamic-importer)
7. [PHRO routing, middleware ও security](#7-phro-routing-middleware-ও-security)
8. [PHDB database ও PHLS local state](#8-phdb-database-ও-phls-local-state)
9. [PHML, PHJC, PHUI ও PHCS frontend](#9-phml-phjc-phui-ও-phcs-frontend)
10. [PHJS self-contained browser runtime](#10-phjs-self-contained-browser-runtime)
11. [Authentication, JWT, encryption ও 2FA](#11-authentication-jwt-encryption-ও-2fa)
12. [HTTP, API, mail, realtime ও translation](#12-http-api-mail-realtime-ও-translation)
13. [Payment ও courier](#13-payment-ও-courier)
14. [PHFY notification ও Web Push](#14-phfy-notification-ও-web-push)
15. [PHAI ও MCP](#15-phai-ও-mcp)
16. [PHMO monitoring ও debug UI](#16-phmo-monitoring-ও-debug-ui)
17. [সব library-র পূর্ণ catalog](#17-সব-library-র-পূর্ণ-catalog)
18. [Deployment, cache ও production checklist](#18-deployment-cache-ও-production-checklist)
19. [Troubleshooting ও verification](#19-troubleshooting-ও-verification)

## 1. পরিচিতি ও ক্ষমতা

MyStack একটি zero-Composer, zero-NPM-runtime PHP framework। এর প্রধান বৈশিষ্ট্য:

- domain root, hosting root, subdomain এবং nested subfolder-এ portable routing;
- fluent router, middleware, CSRF, rate limit, XSS/SQLi/open-redirect/file/header shields;
- prepared database CRUD, analytics, transaction, schema sync এবং unbuffered streaming;
- SQLite local state, TTL, counter, tag, lock retry এবং corruption recovery;
- PHP component/markup system, ১,৩০০+ PHUI registry entry এবং PHP-native utility CSS;
- Alpine/HTMX/React/Vue ছাড়া self-contained PHJS reactivity, directive, SPA ও request runtime;
- OAuth/OIDC, JWT, encryption, OTP/TOTP এবং full Authenticator lifecycle;
- payment gateway ও ১০টি বাংলাদেশি + ১০টি international courier profile/facade;
- ntfy public/private notification এবং VAPID Web Push;
- WebSocket, SSE, StreamUI, AI provider bridge ও MCP server;
- request/trace ID, JSON log, health/ready, metrics, alert এবং debug dashboard;
- generator, folder repair, doctor, audit, smoke এবং rollback-capable updater।

## 2. প্রয়োজনীয় পরিবেশ ও installation

### আবশ্যিক

- PHP 8.1 বা নতুন
- `json`, `openssl`, `PDO`, `pdo_sqlite`
- MySQL/MariaDB ব্যবহার করলে `mysqli`
- `.mystack/` এবং `src/cache/css`, `src/cache/js`, `src/cache/php` writable

### Feature অনুযায়ী প্রয়োজন

- `curl`: OAuth, payment, courier, AI, mail-adjacent HTTP, ntfy এবং remote API
- `mbstring`: সম্পূর্ণ Unicode/Bengali handling
- `zip`/`ZipArchive`: updater, PHCD/ZIP workflow
- `sockets`: native PHEV WebSocket server
- HTTPS: secure cookie, OAuth, payment callback, Service Worker এবং Web Push

Composer বা NPM install করার প্রয়োজন নেই। PHCD দিয়ে browser-side package install করা optional; এটি server runtime dependency নয়।

### প্রথম verification

```bash
php mystack doctor
php mystack smoke
php mystack serve 8000
```

`doctor` read-only। Safe auto-fix চাইলে স্পষ্টভাবে চালান:

```bash
php mystack doctor --fix
```

## 3. Project structure ও portable path

```text
/
├── index.php                  bootstrap, config, import, route, listen
├── mystack                    intelligent CLI
├── library/
│   ├── library.php            DIR, Importer, import(), 30 library loader
│   └── PH*.php                framework modules
├── app/                       flat backend files
├── component/                 flat UI/view files
├── src/
│   ├── js/                    PHJS/SW source ও synchronized builds
│   └── cache/{css,js,php}/    নাম-সংরক্ষিত compiled cache
├── .mystack/                  private PHLS/PHMO state ও logs
├── README.md
├── MANUAL_BN.md
├── .github/CODEOWNERS        official review ownership
├── AGENTS.md
├── CONTRIBUTING.md           contribution policy
├── NOTICE                    ownership/attribution/brand notice
└── LICENSE                   Apache License 2.0
```

`app/` ও `component/`-এর ভেতরে subfolder ব্যবহার করা হয় না। Hosting path hardcode করবেন না:

```php
$component = DIR::path('component:HomeView');
$assetUrl  = DIR::link('js:custom.js', true);
$basePath  = PHCO::path(); // যেমন /projects/shop অথবা root হলে ''
$prefix    = PHCO::pre();  // যেমন shop_
```

এই path/prefix PHP থেকে PHJS-এ inject হয়। ফলে cookie, cache, Service Worker, `/app.js`, `/sw.js`, PHFY এবং SPA navigation একই project namespace অনুসরণ করে।

## 4. Bootstrap ও configuration order

Recommended `index.php` skeleton:

```php
<?php
require_once 'library/library.php';

PHDE::debug(true);              // production-এ false
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
PHMO::dashboard('/monitor');    // debug false হলে route তৈরি হয় না

import('app:HomeController', 'app:AuthMiddleware');

PHRO::get('/', [HomeController::class, 'index'])->name('home');

PHRO::listen(function (int $code, string $message, string $at): void {
    http_response_code($code);
    if ($code >= 500 && PHDE::isDebug()) {
        echo htmlspecialchars($message . ' at ' . $at, ENT_QUOTES, 'UTF-8');
    }
});
```

Framework নিজে `.env`, `APP_ENV` বা `APP_DEBUG` parse/depend করে না। চাইলে deployment environment নিজে read করে value-গুলো MyStack API-তে দিন; debug-এর source of truth হলো:

```php
PHDE::debug(false);
$debug = PHDE::isDebug();
```

## 5. MyStack CLI

| Command | কাজ |
| --- | --- |
| `php mystack help [command]` / `list` | category অনুযায়ী সব built-in ও auto-discovered application command |
| `cli:status` / `cli:install [--target=path]` | OS launcher/PATH অবস্থা দেখা এবং user-level `mystack` command atomically install |
| `php mystack about --json` | framework, PHP, extension, path ও folder capability |
| `php mystack get:started` | starter controller/route/view |
| `php mystack make:controller User` | `app/`-এ controller |
| `php mystack make:model Product` | PHDB demo-সহ model |
| `php mystack make:middleware Auth` | middleware |
| `php mystack make:component Alert` | reusable component |
| `php mystack make:view Dashboard` | full page view |
| `make:command`, `make:api`, `make:service`, `make:job` | custom console command/API/service/queue job |
| `make:event`, `make:listener`, `make:mail`, `make:notification`, `make:policy`, `make:test` | application integration scaffold |
| `make:request`, `make:resource`, `make:factory` | PHVD request, API transformer ও test-data factory |
| `make:migration`, `make:seeder`, `make:crud`, `make:module` | schema/data scaffold বা atomic multi-file feature scaffold |
| `php mystack serve 8000` | local server |
| `cache:status`, `cache:warm`, `cache:clear`, `cache:prune` | named CSS/JS/PHP cache দেখা, প্রস্তুত বা explicit cleanup |
| `build:check`, `phjs:check`, `optimize` | synchronized build এবং safe local readiness check |
| `route:list`, `route:show`, `config:show` | executable source না চালিয়ে route ও safe literal config inspection |
| `library:list`, `library:check`, `ui:list`, `ui:search` | framework/PHUI registry inspection |
| `db:check`, `db:tables`, `schema:status` | read-only PHDB readiness ও schema inspection |
| `migrate`, `migrate:status`, `migrate:rollback --yes`, `db:seed` | tracked opt-in migration/seeding; rollback explicit |
| `queue:push`, `queue:work`, `queue:status`, `queue:failed/retry/forget/flush` | local durable job lifecycle, retry/backoff ও atomic reservation |
| `schedule:add/list/remove/run/work` | isolated CLI schedule; daemon supervisor দিয়ে চালাতে হবে |
| `websocket:start/status`, `mail:check`, `oauth:list`, `payment:list`, `courier:list` | service/provider capability inspection |
| `phfy:check`, `vapid:check`, `health:check`, `monitor:status`, `logs:tail/report` | notification, crypto, dependency ও observability checks |
| `php mystack doctor` | read-only structure/syntax/permission scan ও folder repair check |
| `php mystack doctor --fix` | bounded safe fix |
| `php mystack audit` | production-oriented read-only audit |
| `php mystack smoke` / `test` | full framework regression suite |
| `php mystack update --check` | GitHub `main` SHA/byte diff, কোনো change নয় |
| `php mystack update [path]` | allowlisted path compare, confirm, apply |
| `php mystack update [path] --yes` | prompt ছাড়া verified apply |

`make:command Report` একটি flat `app/ReportCommand.php` বানায়; `commandName()`, `description()` ও `handle()` থাকলে পরের invocation-এ command auto-discover হয়। Queue/scheduler metadata `.mystack/console.sqlite`-এ WAL mode-এ থাকে এবং single-host application state—multi-server shared queue নয়। `queue:work --daemon`, `schedule:work --daemon` এবং `websocket:start` সাধারণ HTTP request-এ নয়, hosting supervisor/system service থেকে চালাতে হবে। Cleanup, rollback, forget ও flush-এ interactive confirmation বা `--yes` প্রয়োজন। Migration file আগে সফল `up()` চালায়, তারপর local applied registry-তে লিখে; `down()` ছাড়া rollback হয় না। Production schema change-এর আগে database backup/restore drill বাধ্যতামূলক।

`php mystack` সব OS-এ মূল fallback। Unix/macOS executable shebang এবং Windows CMD/PowerShell-এর policy-independent `mystack.cmd` launcher দিয়ে `mystack`-ও ব্যবহার করা যায়। `php mystack cli:install` user-level launcher তৈরি করে এবং প্রয়োজন হলে কোন directory `PATH`-এ দিতে হবে তা জানায়; এটি নিজে system PATH silently বদলায় না। `--json` machine-readable output অপরিবর্তিত রাখে; `NO_COLOR=1` বা `--no-ansi` color/TUI escape code বন্ধ করে।

Interactive mode-এ ASCII MyStack header, semantic INFO/SUCCESS/WARNING/ERROR badge, framed comparison এবং বাস্তব operation-stage progress দেখায়। `update --check` defaultভাবে changed file-এ focus করে এবং unchanged count দেখায়; সব file দেখতে `--verbose` দিন।

Updater Release/version ব্যবহার করে না। এটি official rolling `main` snapshot থেকে `library/*`, `src/js/*`, `docs/*`, `mystack-extension-main/*`, `.htaccess`, `.github/CODEOWNERS`, `.github/workflows/docs.yml`, `AGENTS.md`, `CONTRIBUTING.md`, `LICENSE`, `MANUAL_BN.md`, `NOTICE`, `README.md`, `llms.txt`, `llms-full.txt`, `mystack`, `mystack.cmd` file-by-file নেয়। VSIX package-এ ZIP path ও required extension manifest verify করা হয়। Root `.htaccess` private framework metadata, extension development files ও CLI file-এর HTTP access deny করে। Stage, hash, text/PHP/JS/VSIX validation এবং smoke pass না হলে rollback করে; upstream-এ অনুপস্থিত বলে local file delete করে না।

### License, ownership ও contribution

MyStack Apache License 2.0-এর অধীনে প্রকাশিত। Copyright © 2026 Sakibur Rahman (`sakibweb`)। অন্যরা code ব্যবহার, copy, modify এবং distribute করতে পারবে, তবে `LICENSE`, প্রযোজ্য copyright/attribution এবং `NOTICE` রাখতে হবে এবং পরিবর্তিত file স্পষ্টভাবে modified হিসেবে জানাতে হবে। নিজের modification-এর authorship দাবি করা যাবে, কিন্তু original MyStack framework নিজের সৃষ্টি বলে দাবি করা, owner attribution মুছে ফেলা বা unofficial modified distribution-কে official MyStack হিসেবে উপস্থাপন করা যাবে না।

Official organization: https://github.com/mystack-framework  
Official repository: https://github.com/mystack-framework/mystack

Public issue, request এবং pull request দিতে পারবে। Direct write, merge, release ও official update authority Sakibur Rahman এবং explicitly authorized maintainer-এর নিয়ন্ত্রণে থাকবে। বিস্তারিত `NOTICE`, `CONTRIBUTING.md` ও `.github/CODEOWNERS`-এ আছে। Repository public হওয়া direct write permission নয়; GitHub-এ protected branch/ruleset আলাদাভাবে সক্রিয় রাখতে হবে।

## 6. DIR ও dynamic importer

এক বা একাধিক import:

```php
import('app:UserController');
import('component:HomeView', 'js:custom.js', 'css:app.css');
import('app:*');
```

Common path key:

```php
DIR::path('library:PHDB');
DIR::path('app:UserController');
DIR::path('component:HomeView');
DIR::link('img:logo.svg');
DIR::raw('js:custom.js');
```

`DIR::initialize()` custom hosting root প্রয়োজন হলে ব্যবহার করুন। User input সরাসরি path/import key হিসেবে দেবেন না।

## 7. PHRO routing, middleware ও security

### Basic এবং fluent route

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

Supported method: `get`, `post`, `put`, `patch`, `delete`, `head`, `options`, generic `add`। Route group/CRUD helpers: `group`, `crud`, `gap`, `sgap`।

### Route input ও CSRF

```php
echo '<form method="post" action="/profile">';
echo PHRO::csrfField();
echo '</form>';

$data = PHRO::gatherRequestData();
```

Guard চালু করলে secure session defaults, project-scoped session name, CSRF এবং configured shields কাজ করে:

```php
PHRO::guard([
    'rate_limit' => ['enabled' => true],
]);
```

Default guard layer-এর মধ্যে content type, SQLi, XSS, rate limit, attempt, upload, header inspection, honeypot, open redirect ও CSRF protection আছে। Limit বাড়াতে হলে নিরাপদ সীমায় নির্দিষ্ট key override করুন; পুরো shield বন্ধ করবেন না।

### Proxy/Cloudflare

`REMOTE_ADDR` default source। `X-Forwarded-For`, `CF-Connecting-IP`, `X-Real-IP` কেবল trusted proxy validation-এর পরে ব্যবহারযোগ্য fallback:

```php
PHRO::trustProxies(['173.245.48.0/20', '103.21.244.0/22']);
$ip = PHRO::getUserIP();
```

যে reverse proxy/CDN ব্যবহার করছেন তার official current CIDR list দিন। Blind forwarded header trust করবেন না।

### CORS/CSP ও SEO

```php
PHRQ::cross(true); // self-aware restrictive policy
// অথবা নির্দিষ্ট origin:
PHRQ::cross(true, ['https://app.example.com'], true);

PHRO::manifest(['name' => 'My App', 'short_name' => 'App']);
PHRO::addSitemapEntry('/docs', ['priority' => '0.7']);
PHRO::addRobotsRule('Disallow: /private');
```

`PHRO::listen()` `/app.js`, `/sw.js`, sitemap/robots/manifest এবং registered application routes সম্পন্ন করে।

## 8. PHDB database ও PHLS local state

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

Raw SQL লাগলে placeholder বাধ্যতামূলক:

```php
$rows = PHDB::query(
    'SELECT * FROM products WHERE price >= ? AND status = ?',
    [1000, 'active']
);
```

### খুব বড় result streaming

```php
foreach (PHDB::fast('SELECT id, email FROM users WHERE status = ?', ['active']) as $row) {
    // একবারে একটি row memory-তে আসে
}
```

Generator শেষ হওয়ার আগে একই connection-এ অন্য query চালাবেন না।

### Transaction, analytics ও pagination

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

Default sync declaration অনুযায়ী add/modify/remove করতে পারে এবং unchanged schema দ্রুত skip করে। Column বাদ বা type change production data-তে destructive হতে পারে—backup/restore drill ছাড়া deploy করবেন না।

### PHLS

```php
// Default storage স্বয়ংক্রিয়ভাবে .mystack namespace ব্যবহার করে।
// Custom file প্রয়োজন হলে অন্য PHLS call-এর আগেই দিন:
// PHLS::setFile(__DIR__ . '/.mystack/custom-state.sqlite');
PHLS::add('token', ['value' => 1], 15, ['auth']);
$value = PHLS::get('token');

$value = PHLS::remember('catalog', 300, fn() => loadCatalog());
$attempts = PHLS::increment('login:' . $ip, 1, 300);
PHLS::flushByTag('auth');
```

PHLS bounded SQLite retry/WAL, atomic transaction, checker এবং malformed-storage recovery ব্যবহার করে। এটি single-server local state; multiple server হলে shared DB/cache প্রয়োজন।

## 9. PHML, PHJC, PHUI ও PHCS frontend

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

PHML layout, partial, block/yield, registered component, shared data, head/body/css/js এবং compiled processing সমর্থন করে।

### PHJC view

```php
echo PHJC::view('HomeView', ['title' => 'Home']);
PHJC::share('currentUser', $user);
PHJC::clearCache();
```

PHJC component PHP compile করে `src/cache/php`, CSS `src/cache/css`, JS `src/cache/js`-এ একই readable component name দিয়ে রাখে। Path না থাকলে auto-create হয়।

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

API family: `element`, `section`, `layout`, `page`, generic `ui`/`render`, `register`, `registerMany`, `alias`, `exists`, `search`, `categories`, `catalog`, `check`। Placeholder: `{{title|Default title}}`। Untrusted raw HTML সরাসরি দেবেন না; `PHUI::check()`/guarded rendering ব্যবহার করুন।

### PHCS

```php
PHCS::config(['darkMode' => 'class']);
PHCS::HTML('<button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Save</button>');
$css = PHCS::build();
```

Responsive, state, dark mode, layout, color, typography, animation এবং advanced utility PHCS runtime/build-এ process হয়। কোনো Node build দরকার নেই।

## 10. PHJS self-contained browser runtime

`/app.js` সরাসরি `src/js/PHJS-min.php` থেকে PHRO serve করে। এটি canonical PHP-aware source। Runtime নিজে একবার init হয় এবং একই page/request পুনরায় অপ্রয়োজনীয়ভাবে fetch না করার জন্য in-flight request ও page cache de-duplication রাখে।

### PHP থেকে JS

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

PHJS-এর native directive prefix/config অনুসরণ করে state, show/hide, text/html/value/class/style/attribute binding, event, model, loop, conditional, transition, teleport, ref, request, swap, trigger, component, prop, slot, action, context, form, lazy mount, route mount, error/loading/empty fallback, keymap এবং hotkey declaratively ব্যবহার করা যায়। Project-এর existing syntax/aliases inspect করে ব্যবহার করুন; `hx-*` বা `Alpine.*` runtime-এর উপর নতুন dependency তৈরি করবেন না।

### Request ও navigation behavior

- same-origin HTML document link-ই SPA navigation পায়;
- `mailto:`, `tel:`, download, target, external এবং non-HTML asset native থাকে;
- destination DOM swap-এর আগে প্রয়োজনীয় CSS prepare/activate হয়;
- hover intent এবং touchstart prefetch support আছে;
- একই URL cache/in-flight থাকলে দ্বিতীয় request পাঠায় না;
- back/forward, error response এবং full-document state safely restore হয়;
- toast সবসময় body-level fixed layer-এ থাকে;
- removed DOM-এর listener/effect/timer cleanup হয়।

### Major runtime systems

State/store, component lifecycle, async component, props/slots/context, forms, validation, router, request/upload, offline queue, storage/DB sync, theme, i18n, SEO, accessibility/focus trap, keyboard/keymap, PWA/SW, PHFY, OAuth/2FA/payment helpers, chart/media/file system, AI/XR, command palette, devtools, virtual list/table, data table, dashboard, mega menu, eCommerce UI, rich editor, file manager, Kanban, calendar এবং workflow helper অন্তর্ভুক্ত।

Debug mode-এ `Ctrl+Shift+D` দিয়ে PHJS DevTools এবং `Ctrl+K` দিয়ে command palette খোলা যায়।

### Synchronization

PHJS behavior update করলে অন্তত এগুলো equivalent রাখতে হবে:

- `src/js/PHJS-min.php` — main/canonical
- `src/js/PHJS.php`
- `src/js/phjs.js`
- `src/js/phjs.min.js`
- changed behavior-এর related runtime copy

PHP interpolation plain JS-এ verbatim copy করবেন না; resolved equivalent রাখুন। শেষে `php mystack smoke`-এর synchronized build এবং Bengali UTF-8 test pass করান। Service Worker-এর canonical source `src/js/SW.php`; সংশ্লিষ্ট `sw.js` synchronized রাখুন।

## 11. Authentication, JWT, encryption ও 2FA

### PHAU identity ও verification

```php
$created = PHAU::make('users', $dbMap, $input, $options);
$identity = PHAU::check('users', 'token', $token, 'email');

PHAU::identityLib('/identity-lib'); // debug/config অনুযায়ী built-in UI/route
```

OAuth/OIDC:

```php
$providers = PHAU::socialProviders();
$login = PHAU::socialUrl('google', $googleConfig);

PHAU::listenCallback('/oauth/callback', [
    'google' => $googleConfig,
], function (array $identity): void {
    // প্রয়োজনীয় normalized field-ই সংরক্ষণ করুন
});
```

Provider config অনুযায়ী state, PKCE/nonce/callback validation বজায় রাখুন। Raw provider response বা access token অপ্রয়োজনে log/store করবেন না।

### JWT

```php
PHJT::key('long-separate-jwt-secret');
PHJT::algorithm('HS512');
$created = PHJT::create(['sub' => 42, 'role' => 'admin'], 3600);
if ($created['status']) {
    $verified = PHJT::verify($created['data']);
}
```

`PHJT::rotate()` key rotation support করে। Algorithm/key দুটোই server-controlled রাখুন।

### Encryption

```php
PHED::key('long-encryption-secret');
$encrypted = PHED::make($secretData, 'encrypt');
$plain = PHED::make($encrypted, 'decrypt');
```

Encryption key, JWT key ও router key আলাদা রাখুন। Existing encrypted data migration test ছাড়া format/key পরিবর্তন করবেন না।

### OTP/TOTP Authenticator

```php
PHTP::configure();
$enrollment = PHTP::enroll($userId, ['issuer' => 'My App']);
$confirmed = PHTP::confirm($userId, $code);
$result = PHTP::authenticate($userId, $codeOrRecoveryCode);
$status = PHTP::status($userId);
```

`recovery()` recovery code rotate করে এবং `disable()` 2FA বন্ধ করে। Enrollment confirm হওয়ার আগে active নয়; TOTP replay ও recovery code reuse প্রতিরোধ করা হয়। QR তৈরি করতে:

```php
$dataUri = PHQR::make($enrollment['url']);
```

## 12. HTTP, API, mail, realtime ও translation

### PHRQ

```php
$response = PHRQ::php('GET', 'https://api.example.com/items', [
    'Accept' => 'application/json',
]);

$browserCode = PHRQ::js('POST', '/api/items', [], ['name' => 'Book']);
```

TLS verification বন্ধ করবেন না। Timeout, allowed URL, response type এবং size limit নির্ধারণ করুন। `livemap()` debug-only request visualization UI, `stream()` streaming response, `file()` bounded output এবং `status()` HTTP status helper।

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

`all/get/add/up/rm` smart CRUD, `valid`, `auth`, `page`, `resource/item/collection/clean`, `ok/fail/send` standardized response দেয়।

### PHEM

```php
PHEM::smtp('smtp.example.com', 465, 'ssl');
PHEM::smtpLogin('sender@example.com', 'password');
$sent = PHEM::smtpSend(
    'sender@example.com', 'My App', 'user@example.com', '', '',
    'Welcome', '<p>Your account is ready.</p>'
);
```

IMAP/POP configuration/login/get/send APIs-ও আছে। Debug log-এ credential বা message secret প্রকাশ করবেন না।

### PHEV

```php
PHEV::initialize('/websocket', '0.0.0.0', 8000);
PHEV::handler('/chat', 'message', function ($clientId, $message): void {
    PHEV::broadcast($message);
});
PHEV::start();
```

WebSocket loop web request-এর মধ্যে চালাবেন না; CLI/service supervisor ব্যবহার করুন। SSE: `sendSE`, `stream`, `setRetry`; live UI: `streamUI`।

### PHTR

```php
$translated = PHTR::auto('স্বাগতম', 'en');
```

Remote translation provider availability/terms পরিবর্তনশীল; timeout/failure fallback দিন।

## 13. Payment ও courier

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

প্রথমে capability দেখুন:

```php
$available = PHPA::available();
$capability = PHPA::gatewayCapabilities('bkash');
```

Gateway অনুযায়ী `charge`, `verify`, `execute`, `refund`, `webhook` capability ভিন্ন। Live merchant account, keys, approval, callback URL ও official provider contract ছাড়া “adapter আছে” মানে live payment নিশ্চিত নয়। Webhook signature verify এবং order fulfillment idempotent করুন। Private gateway override:

```php
PHPA::extend('privatepay', fn() => $customGateway);
```

### Courier

```php
$profile = PHPA::courierProfile('pathao');
$courier = PHPA::courier('pathao')
    ->configure(['token' => 'merchant-token'])
    ->sandbox(true);

$result = $courier->track('CONSignment-ID');
```

Facade-এ ১০টি Bangladesh এবং ১০টি international courier profile রয়েছে। Exact operation/profile আগে `courierAvailable()` ও `courierProfile()` দিয়ে দেখুন। Merchant-specific contract `extendCourier()` দিয়ে inject করা যায়। Tracking UI PHUI/PHJS-এ same-origin secure helper ব্যবহার করে।

## 14. PHFY notification ও Web Push

সব default PHFY-তে আছে; minimal enable:

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

Public topic ntfy SSE থেকে PHJS নেয়। Private token না থাকলে authorized private payload PHLS-এ থাকে এবং same-origin private endpoint poll করে। `users`, `permissions`, `keywords` client context অনুযায়ী filter হয়।

Browser permission UI থেকে PHJS Service Worker/PushManager subscription তৈরি করে। `webpush_auto` true হলে OpenSSL EC/VAPID key auto-managed হয়। Hosting capability:

```php
$crypto = PHFY::cryptoCapability();
$push = PHFY::webPushCapability();
```

Expected behavior:

- active tab: notification event/browser notification;
- অন্য page/tab বা minimized: browser permission থাকলে notification;
- tab বন্ধ: Service Worker Web Push support থাকলে notification;
- browser পুরো বন্ধ: OS/browser/platform policy-এর উপর নির্ভরশীল;
- native Android/iOS app: একই PHP event থেকে app transport-এ পাঠানো সম্ভব, তবে app-এর push token/transport adapter আলাদা করে integrate করতে হয়—Web Push subscription নিজে native FCM/APNs token নয়।

Public topic-এ secret/ব্যক্তিগত তথ্য দেবেন না।

## 15. PHAI ও MCP

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

`serve`, `clusterAPI`, `bridge`, resource template, middleware এবং alias support আছে। Remote bridge/URL অবশ্যই allowlist, HTTPS, timeout, size limit এবং SSRF protection-এর মধ্যে রাখুন। AI output untrusted data হিসেবে validate করুন।

## 16. PHMO monitoring ও debug UI

```php
PHMO::configure(['enabled' => true]);
PHMO::registerRoutes();
PHMO::dashboard('/monitor');
```

- `/health`: lightweight liveness;
- `/ready`: PHLS/database dependency readiness, failure হলে 503;
- `requestId()` ও `traceId()`: request correlation;
- `log()`: structured JSON log;
- `metrics()`: request, error-rate, latency average/p95;
- `report()`: bounded problem/detail report;
- `.mystack/`: direct private storage, ৯০ দিনের log retention এবং size-bounded rotation;
- `/monitor`: responsive read-only UI, কেবল debug true হলে route থাকে।

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

এগুলো `PHDE::debug(false)` অবস্থায় public route/UI হিসেবে চালু রাখা যাবে না। PHCD remote install/update-এর জন্য application authorization callback ব্যবহার করুন।

## 17. সব library-র পূর্ণ catalog

নিচে primary public API inventory দেওয়া হলো। Dynamic gateway subclasses, QR internals, shield internals এবং magic-generated PHJS/PHJC chain method এখানে আলাদা করে তালিকাভুক্ত নয়; সেগুলো parent/facade contract অনুসরণ করে।

| Library | দায়িত্ব | Primary public API |
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
| `PHEM` | SMTP/IMAP/POP3 | `smtp`, `imap`, `pop`, `smtpLogin`, `imapLogin`, `popLogin`, `smtpGet`, `imapSend`, `popSend`, `imapGet`, `popGet`, `smtpSend`, `showLog` |
| `PHEV` | WebSocket/SSE/StreamUI | `allowWebWorker`, `initialize`, `start`, `restart`, `stop`, `running`, `clients`, `debugClients`, `getHandler`, `handler`, `message`, `broadcast`, `disconnect`, `initHeaders`, `sendSE`, `setRetry`, `stream`, `streamUInew`, `streamUI` |
| `PHFY` | ntfy/Web Push | `configure`, `config`, `public`, `private`, `send`, `clientConfig`, `webPushCapability`, `cryptoCapability`, `registerRoutes`, `privateFeed` |
| `PHJC` | component/view compiler | `fastUI`, `ui`, `icon`, `slot`, `layout`, `clearCache`, `view`, `includeView`, loop helpers, `share`, `directive`, `minify`, `metaPreset`, `breadcrumb`, `head`, `buildHead`, `newHTML`, `singleHTML`, `mergeHTML`, `p2j`, `h2p`, `css`, `generateId`, `import`, `header`, `body`, `streamJS`, `newJS`, `phjs`, `use`, `render`, builder magic methods |
| `PHJS` | PHP→JS/PHJS bridge | typed values (`expr`, `value`, `arrayValue`, `object`, `template`), declarations, DOM, events, storage, network, application helpers, OAuth/2FA/payment helpers, control flow, function/class/module builders, `script`, `moduleScript`, `gen` |
| `PHJT` | JWT | `key`, `rotate`, `algorithm`, `create`, `verify` |
| `PHLS` | local SQLite state | `disconnect`, `setFile`, `checker`, `add`, `addIfAbsent`, `update`, `remove`, `expire`, `expireAllExpired`, `isExpired`, `getExpiredDetails`, `getActiveDetails`, `limitizer`, `get`, `getAll`, `remember`, `increment`, `decrement`, `flushByTag`, `removeAll` |
| `PHML` | markup/composition | `share`, `partial`, `layout`, `block`, `yieldBlock`, `component`, `hasComponent`, `render`, `init`, `autoAssets`, `use`, `meta`, `title`, `js`, `css`, `uiConfig`, `head`, `footer`, `html`, `body`, `clearCache`, `process` |
| `PHMO` | observability | `configure`, `config`, `requestId`, `traceId`, `isProbeRequest`, `registerRoutes`, `dashboard`, `report`, `health`, `metrics`, `log`, `finishRequest` |
| `PHOB` | browser/device capability | `capability`, `build`, `use`, `deviceID` |
| `PHOP` | file/media operation | `img`, `video`, `zip`, `text` |
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

### 17.1 বড় facade-গুলোর exact public method index

এই তালিকা magic/dynamic call-এর বাইরে বর্তমানে ঘোষিত public entry point দ্রুত খুঁজতে ব্যবহার করুন। Method-এর argument/return signature পরিবর্তন হতে পারে, তাই implementation-এর আগে সংশ্লিষ্ট source method দেখুন।

**PHJC**

```text
fastUI, ui, icon, slot, layout, clearCache, view, includeView,
startLoop, currentLoop, endLoop, share, directive, minify, metaPreset,
breadcrumb, reset, head, buildHead, newHTML, singleHTML, mergeHTML,
p2j, h2p, css, countElements, generateId, import, header, body,
streamJS, newJS, phjs, use, render_h, render_c, render_b, render_j,
app, render, __call, __callStatic, set, op, get, endFun, endCod
```

**PHJS — bridge, DOM, storage ও application helper**

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

`alpine*` ও `hx*` নামগুলো compatibility bridge; এগুলো external Alpine/HTMX runtime বাধ্যতামূলক করে না। নতুন application code-এ PHJS-native directive/style অগ্রাধিকার দিন।

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

### 17.2 Library-by-library বিস্তারিত API reference

নিচের তালিকায় “variable নেই” মানে class-এর configuration private এবং method দিয়েই নিয়ন্ত্রণ করতে হবে। Public array/map-কে সরাসরি পরিবর্তনের আগে source contract দেখুন; সম্ভব হলে documented setter/register method ব্যবহার করুন।

#### 17.2.1 `DIR` ও `Importer`

**কাজ:** project root, base URL, typed directory alias এবং নিরাপদ dynamic import পরিচালনা করে। Public configuration variable নেই।

| Method | কাজ ও ফলাফল |
| --- | --- |
| `DIR::initialize(array $options = [])` | Custom root/directory map initialize করে; portable hosting detection override করার entry point। |
| `DIR::path($key)` | `app:Name`, `component:Name`, `library:PHDB`, `js:file.js`-কে local filesystem path-এ resolve করে। |
| `DIR::link($key, $cacheBust = false)` | একই key-এর public URL তৈরি করে; দ্বিতীয় argument true হলে cache-busting query যোগ করে। |
| `DIR::raw($key)` | Resolved file-এর raw content পড়ে; output context অনুযায়ী caller-কে escape করতে হবে। |
| `DIR::secureRequire($key, array $data = [])` | Scoped data-সহ resolved PHP file নিরাপদভাবে execute করে এবং result ফেরায়। |
| `DIR::getRootDir()` / `getBaseUrl()` | বর্তমান detected root directory ও base URL দেয়। |
| `Importer::getInstance()` | Shared importer instance দেয়। |
| `setContext()` / `clearContext()` | Imported PHP file-এ পাঠানো temporary context set/clear করে। |
| `load(...$args)` / global `import(...$args)` | একাধিক typed import, wildcard, PHP require, CSS link, JS script বা asset URL resolve/emit করে। |

#### 17.2.2 `PHDE`

**কাজ:** debug state, PHP error capture/display, API error envelope, memory/file response এবং API Bar। Public variable নেই; `debug()`/`isDebug()` source of truth।

| Method | কাজ ও ফলাফল |
| --- | --- |
| `enableErrorReporting()` / `disableErrorReporting()` | PHP error reporting ও framework handler চালু/বন্ধ করে। Production-এ detailed display বন্ধ রাখুন। |
| `customErrorHandler(...)` | PHP warning/error-কে framework error pipeline-এ নেয়; সাধারণত সরাসরি call করা হয় না। |
| `debug($state = true)` | Runtime debug boolean set করে; chain/config bootstrap entry point। |
| `isDebug(): bool` | বর্তমান debug state ফেরায়। Debug-only route/UI এই value মানে। |
| `errors($state = true)` / `displayErrors($state = true)` | Error capture/display policy প্রয়োগ করে। |
| `errorJSON()` | Captured error-কে API-উপযোগী JSON response হিসেবে দেয়/emit করে। |
| `getType()` | বর্তমান error/request output type নির্ণয় করে। |
| `api($method = 'application/json')` | Error/output pipeline-এর API content type set করে। |
| `apibar($url = '/apibar')` | Route, request ও debug তথ্যের self-contained UI register করে; debug false হলে unavailable থাকতে হবে। |
| `file($name, $length)` | Download/stream response header প্রস্তুত করে। |
| `memory($limit)` | PHP memory limit সেট করে, যেমন `512M`। Hosting hard limit অতিক্রম করতে পারে না। |

#### 17.2.3 `PHRO`

**কাজ:** router, route builder, middleware, WAF, CSRF, proxy/IP, request identity, rate/attempt protection, channel, SEO endpoints এবং final dispatcher। Public configuration variable নেই।

| Method group | Methods ও কাজ |
| --- | --- |
| Bootstrap | `initialize($basePath)` custom route base দেয়; `guard($config)` session/security shields চালু করে; `listen($errorHandler)` asset/system/application route dispatch করে request শেষ করে। |
| HTTP route | `get`, `post`, `put`, `patch`, `delete`, `head`, `options`, generic `add` route register করে; প্রত্যেকটি fluent route object দেয়। |
| Route composition | `group` prefix scope, `crud` standard CRUD, `gap` generated API pattern, `sgap` secure/generated API pattern register করে। |
| Fluent metadata | `name` route name, `middleware` authorization/transform chain, `header` response header, `mcp` route-কে MCP metadata দেয়। |
| CSRF/security | `secure()` request secure কি না; `getToken()` token দেয়/তৈরি করে; `csrfField()` hidden input; `regenerateToken()` token rotate করে। |
| Proxy/IP | `trustProxies(array)` trusted proxy/CIDR set করে; `getUserIP()` validated client IP; `gatherHeaders()` normalized headers; `getGeolocationData()` available network/location context। |
| Request context | `root()` base URL/path, `getCallbackContext()` active callback data, `gatherRequestData()` query/body/files merge, `routes()` route list, `route()` named/identifier lookup, `source()` route source metadata। |
| Abuse control | `attempt()` event/user/IP attempt check ও increment; `resetAttempt()` সফলতার পরে counter reset। Guard-এর rate-limit নিজে PHLS-backed। |
| Task/stream/channel | `task(...$tasks)` background-compatible task dispatch; `stream($provider)` streaming response; `channel($id)` worker channel; `publish($id,$command,$data)` channel message। |
| Identity/tracking | `netKey`, `deviceKey`, `extractIdentityFromCookie`, `setIdentityCookie`, `userAgentInfo`, `track`, `footprint`, `decrypt`, `key` device/network identity ও encrypted footprint পরিচালনা করে। |
| SEO/PWA | `createSlug`, fluent `sitemap`, `allow`, `disallow`, `getSitemapRoutes`, `manifest`, `addSitemapEntry`, `addRobotsRule`। |

`name`, `middleware`, `header`, `mcp`, `sitemap`, `allow`, `disallow` route registration-এর পরে chain করা instance method। Forwarded header কেবল `trustProxies()` validation-এর পরে trusted।

#### 17.2.4 `PHOB`

**কাজ:** browser/device capability ও stable device identity data build করে। Public variable নেই।

| Method | কাজ |
| --- | --- |
| `capability(...)` | Browser/runtime capability map detect বা normalize করে। |
| `build(...)` | Capability/device payload থেকে browser-side executable/config output তৈরি করে। |
| `use(...)` | নির্বাচিত capability feature ব্যবহারের bridge/output দেয়। |
| `deviceID(...)` | Available signals থেকে stable device identifier তৈরি করে; এটি authentication-এর বিকল্প নয়। |

#### 17.2.5 `PHEV`

**Public variable:** `PHEV::$retry = 1000` — SSE retry delay (milliseconds)। সাধারণত `setRetry()` ব্যবহার করুন।

| Method group | কাজ |
| --- | --- |
| WebSocket lifecycle | `initialize($path,$address,$port)`, `start`, `restart`, `stop`, `running` server lifecycle। Long-running CLI/service supervisor প্রয়োজন। |
| Worker policy | `allowWebWorker(bool)` web-worker compatible behavior অনুমোদন/বন্ধ করে। |
| Clients | `clients`, `debugClients`, `disconnect($clientId)`, `message($clientId,$message)`, `broadcast($message)`। |
| Handlers | `handler($requestPath,$action,$handler)` event/route action bind করে; `getHandler($message)` matching handler resolve করে। |
| SSE | `initHeaders`, `sendSE($data,$event,$id)`, `setRetry($ms)`, `stream($callback,$interval)`। |
| StreamUI | `streamUInew($key,$interval)` ও `streamUI($name,$interval)` live UI endpoint/bridge register করে। |

#### 17.2.6 `PHEM`

**কাজ:** dependency-free SMTP send এবং IMAP/POP3 mailbox access। Public variable নেই।

| Method group | কাজ |
| --- | --- |
| Server config | `smtp(host,port,secure)`, `imap(host,port,secure,folder)`, `pop(host,port,secure,folder)` connection profile set করে। |
| Login | `smtpLogin`, `imapLogin`, `popLogin` সংশ্লিষ্ট username/password set করে। Secret log করবেন না। |
| Read | `smtpGet`, `imapGet`, `popGet` filter/limit অনুযায়ী message/result সংগ্রহ করে। |
| Send | `smtpSend`, `imapSend`, `popSend` from/name/to/cc/bcc/subject/message দিয়ে send operation চালায়। |
| Diagnostics | `showLog()` protocol transaction log দেয়; production output-এ sensitive content প্রকাশ করবেন না। |

#### 17.2.7 `PHML`

**Public variables:** `$flatAttrMap`, `$components`, `$sharedData`, `$treeCache`, `$tagAliases`, `$attrAliases`, `$unsafeKeywords`। এগুলো parser/registry state; direct mutation-এর বদলে method ব্যবহার করুন। `$unsafeKeywords` security-sensitive।

| Method group | কাজ |
| --- | --- |
| Shared composition | `share`, `partial`, `layout`, `block`, `yieldBlock` shared data, partial/layout এবং named content block পরিচালনা করে। |
| Components | `component`, `hasComponent`, `render` component register/check/render করে। |
| Parser/bootstrap | `init`, `process`, magic `__callStatic`, `__toString` PHML DSL parse ও HTML render করে। |
| Document/assets | `autoAssets`, `use`, `meta`, `title`, `js`, `css`, `uiConfig`, `head`, `footer`, `html`, `body` document composition ও asset attachment। |
| Cache/map | `getFlatAttrMap()` normalized attribute map দেয়; `clearCache()` parser/tree cache clear করে। |

#### 17.2.8 `PHCS`

**কাজ:** PHP-native Tailwind-style utility scanner/compiler। Public configuration variable নেই। Instance এবং static—দুই API আছে।

| Method | কাজ |
| --- | --- |
| `config(array)` | Theme, utilities, variants, colors ও build configuration merge করে। |
| `HTML(string)` / instance `addHtml(string)` | Class scan করার HTML/PHML content যোগ করে। |
| `CSS(string)` / instance `addCss(string)` | Raw/custom CSS source যোগ করে। |
| `process($content,$type='html')` | এক ধাপে HTML/CSS input parse/process করে output দেয়। |
| `build($modular=false)` / `buildCss(...)` | Collected sources থেকে final CSS তৈরি করে। |
| `registerUtilityHandler($pattern,$handler,$priority)` | Custom utility parser/handler extension point। |
| `processHtml`, `processCss` | Instance-level parser entry point। |
| `generateCss(array $classes)` | নির্দিষ্ট class list-এর CSS generate করে। |

#### 17.2.9 `PHJS`

**Public variable:** `PHJS::$debug` — PHP-side generator diagnostics। Browser runtime debug-এর source `PHDE::isDebug()`-injected config; দুইটি গুলিয়ে ফেলবেন না।

| API group | Methods ও কাজ |
| --- | --- |
| Entry/parser | `assets`, `js`, `render`, `parse`, `gen`, magic `__callStatic` natural/DSL input থেকে JS বা attribute output দেয়। |
| Compatibility bridge | `alpineData`, `alpineStore`, `alpineBind`, `hxProcess`, `hxTrigger`, `hxAjax`, `hxRemove`, class helpers, `hxConfig` external runtime ছাড়াই compatible behavior/output দেয়। |
| Reactive magic | `el`, `refs`, `store`, `watch`, `dispatch`, `nextTick`, `root`, `data`, `id`, `state_magic`, `params_magic`, `route_magic`, `ui_magic`, `os_magic`, `t_magic`, `router_magic`, `clipboard_magic` runtime context expression তৈরি করে। |
| JS basics | `const`, `let`, `var`, `log`, `error`, `warn`, `table`, `raw`, `alert`, `redirect`, `reload`। |
| Storage | `localSet/Get/Remove`, `sessionSet/Get`, `cookieSet` local/session/cookie operation generate করে। |
| DOM | `html`, `text`, `val`, `addClass`, `removeClass`, `toggleClass`, `css`, `attr`, `remove`, `event`, `onReady`। |
| Network/app | `fetch`, `appReady`, `appNavigate`, `appLink`, `appApi`, `appRoutePath`, `appRequest`, `appUpload` PHJS request/router bridge। |
| UI/service helpers | `appToast`, `appModal`, `appProgress`, theme, validation, SEO, i18n, store/DB sync, search/index, hardware/DRM, filesystem, media/chart, worker, inspector, palette, accessibility trap, design, time, animation, font, AI, XR, PWA, hydrate। |
| Auth/payment helpers | `appAuthTotp`, `appOAuthStart`, `appOAuthCallback`, `appTwoFactorSubmit`, `appPaymentStart`, `appPaymentStatus` same-origin/CSRF/idempotent UI flow generate করে; server verification বাদ দেয় না। |
| Typed compiler | `expr`, `value`, `translate`, `arrayValue`, `object`, `template`, `statement`, `program`, `compile`, `module`, `build` PHP value/AST-কে safe JS বানায়। |
| Flow/function/class | `arrow`, `functionDef`, `assign`, `returnValue`, `throwValue`, `awaitValue`, `invoke`, `construct`, `dynamicImport`, `ternary`, `ifBlock`, loops, `switchBlock`, `tryCatch`, `classDef`। |
| Modules/scripts | `importModule`, `exportDefault`, `exportNamed`, `call`, `script`, `moduleScript` ES module/script output তৈরি করে। |

Browser-side `APP` runtime-এর বিস্তারিত subsystem—state, directive compiler, component lifecycle, request de-duplication, SPA, CSS preparation, page cache, keymap, accessibility, PWA, PHFY, devtools—section 10-এ বর্ণিত। Canonical source সবসময় `src/js/PHJS-min.php`।

#### 17.2.10 `PHJC`

**Public variables:** `$loops` active loop context; `$tagMap` builder method→HTML tag map; `$attributeMap` attribute alias map। Extension প্রয়োজন ছাড়া direct mutation নয়।

| Method group | কাজ |
| --- | --- |
| View/UI | `fastUI`, `ui`, `icon`, `slot`, `layout`, `view`, `includeView`, `use`, `render` component/view load ও render করে। |
| Cache | `clearCache()` compiled CSS/JS/PHP view cache clear করে। |
| Loop/state | `startLoop`, `currentLoop`, `endLoop`, `share`, `set`, `get` render context ও iteration state। |
| Extensibility | `directive` custom directive; `metaPreset` metadata preset; `op` builder operation। |
| Document | `breadcrumb`, `reset`, `head`, `buildHead`, `header`, `body`, `css` HTML head/body/meta composition। |
| Conversion | `newHTML`, `singleHTML`, `mergeHTML`, `p2j`, `h2p`, `countElements`, `generateId`, `import` HTML/PHP/JSON conversion ও helper। |
| JS builder | `streamJS`, `newJS`, `phjs`, `app`, `render_h/c/b/j`, `endFun`, `endCod` compiled JS/HTML builder chain। |
| Magic API | `__call`, `__callStatic` `tagMap`/builder grammar অনুযায়ী dynamic element/operation resolve করে। |

#### 17.2.11 `PHCO`

**কাজ:** secure project-scoped cookie এবং portable project prefix/base path। Public variable নেই।

| Method | কাজ |
| --- | --- |
| `isSecure()` | HTTPS/secure-cookie context detect করে। |
| `path()` | application base path দেয়; PHJS/PHFY/SW-তে inject হয়। |
| `pre()` | normalized project prefix দেয়, যেমন `shop_`; cookie/storage collision ঠেকায়। |
| `add`, `update`, `remove` | Cookie create/change/delete করে; expiry minute এবং secure defaults মানে। |
| `get`, `exists`, `getAll` | Cookie value/status সংগ্রহ করে। |
| `expired`, `active`, `getExpiredDetails`, `makeExpired` | Framework-managed expiry metadata inspect/force-expire করে। |

#### 17.2.12 `PHSE`

**কাজ:** secure PHP session lifecycle এবং expiring session value। Public variable নেই।

| Method | কাজ |
| --- | --- |
| `start()` | Strict/session-cookie defaults দিয়ে session শুরু করে। PHRO guard সাধারণত lifecycle manage করে। |
| `add($key,$value,$expiry)` / `update` | Session value ও optional expiry set/change। |
| `get($key,$default)` / `getAll()` | Active session value read করে। |
| `remove` / `removeAll` | একটি বা সব value সরায়। |
| `isActive`, `getExpiryTime`, `expireAll` | Expiry check ও expired value cleanup। |
| `regenerateId()` | Session fixation প্রতিরোধে ID rotate করে; login/privilege change-এর পরে ব্যবহার করুন। |

#### 17.2.13 `PHLS`

**কাজ:** local SQLite state/cache/rate-limit/subscription store। Public variable নেই।

| Method group | কাজ |
| --- | --- |
| Storage lifecycle | `setFile($path)` প্রথম connection-এর আগে custom DB path; `disconnect()` connection release; `checker()` integrity/WAL/write health report। |
| CRUD/TTL | `add`, `addIfAbsent`, `update`, `remove`, `get`, `getAll`, `expire`, `isExpired`, expiry detail methods। |
| Cleanup | `expireAllExpired()` expired row; `removeAll()` selected/all local data সরায়। Scope বুঝে ব্যবহার করুন। |
| Rate/atomic | `limitizer(...)` rate window/block state; `increment`/`decrement` atomic counter। |
| Cache | `remember($key,$ttl,$callback,$tags)` cache-aside; `flushByTag($tag)` related cache invalidate। |

Default path `.mystack`-এর private storage। Lock retry exhausted বা malformed DB হলে caller-facing guard path non-fatal fallback ও checker/recovery behavior সংরক্ষণ করতে হবে।

#### 17.2.14 `PHDB`

**Public variables:**

| Variable | অর্থ |
| --- | --- |
| `$host`, `$username`, `$password`, `$dbname` | MySQL/MariaDB connection settings। Password output/log করবেন না। |
| `$charset = 'utf8mb4'` | Connection charset; Unicode-এর জন্য default রাখুন। |
| `$error = true` | Database error behavior/detail policy। Production disclosure `PHDE::debug(false)`-এর সঙ্গে সীমিত রাখুন। |

| Method group | কাজ |
| --- | --- |
| Status/connection | `connect`, `disconnect`, `close`, `checker`, `error`, `id`, `affected` connection ও last operation state। |
| Query | `query($sql,$params,$single)`, `specificSelect`, `first`, `scalar`, value helpers—সব raw SQL-এ placeholders ব্যবহার করুন। |
| Streaming | `fast($sql,$params,$columns): Generator` unbuffered row stream; iteration চলাকালে একই connection-এ query নয়। |
| Write | `save` upsert-like save, `insert`, `batchInsert`, `update`, `delete`, `deleteBy` prepared writes। Empty where/all-delete guard মানুন। |
| Read | `select`, `find`, `findBy`, `search`, `columns`, `exists`, `paginate` filtered/prepared reads। |
| Analytics | `sum`, `avg`, `max`, `min`, `count` aggregate result। |
| Database/schema | `addDB`, `createTable`, `alterTable`, `dropTable`, `truncateTable` database/schema lifecycle। Destructive operation explicit approval ও backup চায়। |
| API/transaction | `api` table result API envelope; `transaction(callable)` atomic commit/rollback। |
| Maintenance | `clean($table,$options)` bounded cleanup/maintenance। |
| JSON/array column | `array($action,...)`, `array_get`, `array_set` encoded column-এর nested value safely read/write। |

#### 17.2.15 `PHRQ`

**কাজ:** server-side cURL request, generated browser request, headers, CORS/CSP, status, file, streaming এবং Live Map। Public variable নেই।

| Method | কাজ |
| --- | --- |
| `php($method,$url,$headers,$body,$options)` | TLS-verified server HTTP request; parsed/raw response contract options-এর উপর নির্ভরশীল। |
| `js(...)` | একই request-এর browser-side JS output তৈরি করে। Untrusted URL allowlist করুন। |
| `header($method,$origin,$contentType,$additional)` | Response/CORS headers set করে। |
| `cross($enable,$origin,$credentials)` | Framework CSP/CORS policy activate করে; true self-aware, array explicit allowlist। |
| `status($code,$msg)` | HTTP status এবং optional message set/emit করে। |
| `file($name,$length)` | File response headers। |
| `livemap($url,$skipList,$limit,$time)` | Request/network visualization route/UI; debug-only। |
| `stream($sleep,$type,$callback)` | Incremental response stream। |

#### 17.2.16 `PHQR`

**কাজ:** memory-safe QR generation। Public variable নেই।

`PHQR::make($data, int $size = 8, int $margin = 4): string` input থেকে PNG data URI দেয়। Size module pixel; final width নয়। Secret-containing enrollment QR কেবল authenticated page-এ দেখান। `QRCode`, `QRUtil` ইত্যাদি internal encoder class সরাসরি application API নয়।

#### 17.2.17 `PHED`

**কাজ:** authenticated application encryption/decryption এবং key strength। Public variable নেই।

| Method | কাজ |
| --- | --- |
| `key($newKey)` | Encryption key set করে। Router/JWT key থেকে আলাদা রাখুন। |
| `make($string,$action)` | High-level encrypt/decrypt envelope। Failure return handling source অনুযায়ী করুন। |
| `hide($string,$key,$action)` | Explicit key-সহ lower-level encrypt/decrypt path। |
| `score()` | বর্তমান key/capability strength score/status দেয়। |

#### 17.2.18 `PHTP`

**কাজ:** HOTP/TOTP primitives এবং PHLS/PHED-backed account Authenticator lifecycle। Public variable নেই।

| Method | কাজ |
| --- | --- |
| `configure(array)` | Issuer, digits, period, algorithm, storage/encryption policy configure করে। |
| `key($length,$mode)` | OTP secret তৈরি করে। |
| `code($secret,$mode,$digits,$time,$offset,$algo)` | নির্দিষ্ট secret/time-এর code generate করে। |
| `verify($otp,$secret,...)` | Window/algorithm অনুযায়ী primitive OTP verify করে। |
| `url($account,$secret,...)` | `otpauth://` enrollment URL তৈরি করে। |
| `enroll($account,$options)` | Pending encrypted enrollment ও recovery material শুরু করে। |
| `confirm($account,$code)` | প্রথম valid code দিয়ে enrollment activate করে। |
| `authenticate($account,$code)` | TOTP বা recovery code verify, replay/reuse ঠেকায়। |
| `status($account)` | Enabled/pending/recovery status দেয়; secret প্রকাশ করা উচিত নয়। |
| `recovery($account,$currentCode)` | যাচাইয়ের পরে recovery code rotate করে। |
| `disable($account,$code,$force)` | Verified বা explicit forced 2FA disable। Force admin authorization ছাড়া নয়। |

#### 17.2.19 `PHTM`

**কাজ:** timezone-aware time read, parse, difference, modification ও formatting। Public variable নেই।

`setZone` timezone set, `getZone` current zone, `getTime` now format, `setTime` timestamp format, `calculate` দুই সময়ের difference, `modify` relative modifier, `format` output conversion, `to12h`/`to24h` clock conversion। Invalid timezone/date input caller-কে validate করতে হবে।

#### 17.2.20 `PHVD`

**কাজ:** declarative validation এবং database-aware uniqueness/existence। Public variable নেই।

`PHVD::check(array $rules, array|bool|null $data = null, bool $debug = false): array` rules অনুযায়ী normalized validation result/errors/data দেয়। `PhvdRule::unique($table,$column,$except)` ও `exists($table,$column)` rule string তৈরি করে। Client validation থাকলেও server-side PHVD বাধ্যতামূলক।

#### 17.2.21 `PHCD`

**কাজ:** browser package search/install/update/use এবং responsive manager UI। Public variable নেই।

| Method | কাজ |
| --- | --- |
| `initialize($state='/cdn',$css,$js,?callable $authorize)` | Manager route, storage paths এবং authorization register করে। Remote UI-তে authorizer দিন। |
| `handleRequest()` | Search/install/update/remove request validate ও dispatch করে; সাধারণত initialize route থেকে। |
| `get($package,$type,$skipPKG,$skipFILE)` | Installed/remote package metadata বা selected asset সংগ্রহ করে। |
| `use($package,$type,$skipPKG,$skipFILE,$defer)` | Installed CSS/JS tags/output তৈরি করে। |

Install/update staging, safe filename/path filter, atomic activation ও rollback বজায় রাখে। External package-এর নিজস্ব license/CSP/security review প্রয়োজন।

#### 17.2.22 `PHJT`

**কাজ:** HMAC JWT creation/verification ও key rotation। Public variable নেই।

| Method | কাজ ও return |
| --- | --- |
| `key($newKey)` | Signing secret set করে; result envelope দেয়। Minimum strength বজায় রাখুন। |
| `rotate($newKey,...)` | Key rotation policy/previous-key transition পরিচালনা করে। Exact options source signature দেখে দিন। |
| `algorithm($newAlgorithm)` | Supported HMAC algorithm (`HS256/384/512`) set করে; status envelope। |
| `create($payload,$expiresIn,$algorithm): array` | `status/message/data` envelope; `data`-তে signed token, payload-এ `iat`, `exp`, `jti` যোগ হয়। |
| `verify($jwt,$algorithm): array` | Signature, header algorithm, payload JSON এবং expiry যাচাই করে `status/message/data` দেয়। |

#### 17.2.23 `PHTR`

**কাজ:** configured remote translation providers-এর URL, request এবং response normalization। Public variable নেই।

`translate($input,$serverName,$source,$target)` selected provider, `auto($input,$targetLanguage)` automatic provider/source, `buildUrl(...)` encoded endpoint URL, `parseResponse(...)` provider-specific response normalize করে। Remote failure/terms/rate limit-এর fallback দিন।

#### 17.2.24 `PHAU`

**কাজ:** identity creation/check, verification token/OTP, OAuth/OIDC provider catalog ও callback। Public variable নেই।

| Method | কাজ |
| --- | --- |
| `identityLib($url,$options)` | Built-in identity UI/routes register করে; authorization/debug exposure review করুন। |
| `make($table,$dbMap,$inputData,$options): array` | Validated identity/account record ও token flow তৈরি করে। Password hashing rules preserve করুন। |
| `check($table,$tokenCol,$inputToken,$identityCol): array` | Token/identity lookup ও authentication result দেয়। |
| `verifyMake(...)` | OTP/token verification challenge তৈরি এবং configured mail/delivery path চালায়। |
| `verifyCheck(...)` | Submitted code/secret verify করে এবং optional account data update করে। |
| `socialProviders(): array` | Built-in OAuth/OIDC provider/mode catalog দেয়। |
| `socialUrl($provider,$config): array` | State/PKCE/nonce-aware authorization URL/context তৈরি করে। |
| `listenCallback($route,$configs,$onSuccess)` | Provider callback route register, state/code/token/userinfo validate/normalize করে success callback চালায়। |

#### 17.2.25 `PHOP`

**কাজ:** image, video, ZIP ও text operation option parser/processor। Public variable নেই।

`img(...)` image transform/output options, `video(...)` media operation options, `zip(...)` archive create/extract/list flow, `text(...)` text/file transformation চালায়। Exact positional/option contract operationভেদে source method দেখুন। Path traversal, decompression bomb, executable upload ও memory limit validate করুন।

#### 17.2.26 `PHAI`

**কাজ:** multi-provider AI facade, cluster/fallback, remote bridge এবং MCP server। Public variable নেই।

| Method group | কাজ |
| --- | --- |
| Provider config | `setAccounts`, `setPriority`, `setModels`, `setTimeout`, `getModels` credentials/model/fallback order configure/read। |
| Process lifecycle | `registerBridgeProcess`, `getBridgeProcess`, `cleanup` external bridge process/pipes track ও release। |
| AI endpoint | `serve($prefix,$apiKey)`, `clusterAPI($path,$apiKey)` compatible API routes; `cluster($input,$options)` provider selection/fallback execution। |
| MCP | `routes`, `tool`, `prompt`, `resource`, `resourceTemplate`, `alias`, `middleware`, `handleRequest` MCP capabilities register/dispatch। Builder-এর `middleware()` ও `retries()` per-item policy দেয়। |
| Bridge | `bridge($target,$method,$params,$options)` guarded local/remote bridge call। URL allowlist, TLS, timeout, response size ও SSRF boundary রাখুন। |
| Instance | `getInstance()` shared PHAI service দেয়। |

#### 17.2.27 `PHAP`

**কাজ:** খুব ছোট syntax-এ REST route, validation, auth, CRUD, pagination ও JSON resource। Public variable নেই।

| Method group | কাজ |
| --- | --- |
| Master route | `api('METHOD /path',$middleware,$rules,$logic,$message)` এক call-এ route+validation+logic+response। |
| Smart CRUD | `all`, `get`, `add`, `up`, `rm` table operations standardized response-সহ চালায়। |
| Custom action | `run($logic,$rules,$successMsg)` arbitrary validated callback। |
| Input/validation/auth | `input`, `valid`, `auth` normalized request data, PHVD validation ও authenticated identity দেয়। |
| Pagination | `page($table,$where,$limit,$callback)` paginated resource response। |
| Resource transform | `resource`, `item`, `collection`, `clean` output field/filter/transform। |
| Response | `ok`, `fail`, `send` consistent status/message/data HTTP JSON envelope। |

#### 17.2.28 `PHUI`

**কাজ:** searchable reusable UI registry—element, section, layout ও page renderer। Public variable নেই; internal registry `catalog()` দিয়ে read করুন।

| Method | কাজ |
| --- | --- |
| `ui($slug,$data)` / `render` | Generic slug render। Placeholder, attributes, slot, PHJS mapping process করে HTML দেয়। |
| `element`, `section`, `layout`, `page` | Type-constrained render; ভুল category/slug দ্রুত ধরা যায়। |
| `exists($slug)` | Component/alias আছে কি না। |
| `register($slug,$template,$meta,$replace)` | String/callable template register; existing replace explicit flag চায়। |
| `registerMany($components,$replace)` | Bulk registration; registered count দেয়। |
| `alias($alias,$target,$replace)` | Alternate slug map করে। |
| `search($query,$group,$limit)` | Title/meta/slug catalog search result। |
| `categories`, `count`, `catalog` | Registry groups, total এবং complete metadata read। |
| `attributes($attributes)` | Attribute array/string safe HTML attribute output। |
| `check($value)` | Raw/template HTML risk checker result; untrusted markup render-এর আগে ব্যবহার করুন। |
| `boot()` | Built-in semantic registry একবার load করে। সাধারণত automatic। |

#### 17.2.29 `PHPA`

**কাজ:** payment gateway factory/capability এবং courier adapter registry। Facade-এর public variable নেই; returned gateway/courier object fluent methods দেয়।

| Facade method | কাজ |
| --- | --- |
| Dynamic `PHPA::gatewayName()` | Registered gateway adapter instance দেয়, যেমন `stripe`, `paypal`, `bkash`। Unknown name error/failure handling করুন। |
| `available()` | Registered payment gateway list। |
| `gatewayCapabilities($name)` | Charge/verify/refund/webhook/sandbox ইত্যাদি supported কি না। |
| `extend($name,$factory)` | Custom/private payment adapter register বা override। |
| `courier($name)` | `PHPACourierInterface` adapter দেয়। |
| `courierAvailable()` / `courierProfile($name)` | Courier list ও official/config profile metadata। |
| `extendCourier($name,$profileOrFactory)` | Custom carrier contract যোগ/override। |

**Payment adapter contract:** `setKeys`, `setLogic`, `setRefundLogic`, `setWebhookLogic`, `setTransport`, `sandbox`, `charge`, `verify`, adapterভেদে `execute`, `refund`, `webhook`। সব adapter সব operation support করে না।

**Courier contract:** `setKeys`, `configure`, `sandbox`, `setTransport`, `create`, `track`, `rate`, `cancel`, `label`, `pickup`, generic `call`, `capabilities`। Provider profile default endpoint/auth mapping দেয়; merchant contract পরিবর্তিত হলে `configure`/custom adapter ব্যবহার করুন।

#### 17.2.30 `PHFY`

**কাজ:** ntfy public/private notification, user/permission/keyword filtering, local private feed, PHLS push subscription এবং self-contained VAPID sender। Public variable নেই।

| Method | কাজ ও return |
| --- | --- |
| `configure(array $options = []): array` | Defaults merge করে। `enabled`, server/topics/tokens, VAPID, user/permissions/keywords, authorizer, poll interval configure হয়। |
| `config(): array` | Effective normalized config দেয়; configure না হলে disabled defaults তৈরি করে। |
| `public($message,$options)` / `private(...)` | Type set করে unified `send` চালায়। |
| `send($message,$options): array` | Payload ID/type/filter/data বানিয়ে ntfy/local transport এবং Web Push চেষ্টা করে; status, code, transport/topic/web_push result দেয়। |
| `clientConfig($context): array` | PHJS-এর জন্য base path, topic SSE, authorized private endpoint, user/filter, CSRF, VAPID capability দেয়। |
| `webPushCapability(): array` | enabled/mode (`webpush` বা `ntfy`) ও key/crypto readiness জানায়। |
| `cryptoCapability(): array` | Hosting-এর EC key, derive, sign ও AES-GCM in-memory test result। |
| `registerRoutes()` | Config, private feed, push subscribe/unsubscribe/send-related same-origin routes একবার register করে। |
| `privateFeed()` | Authorized private poll response দেয়; সরাসরি route callback ছাড়া সাধারণত call নয়। |

#### 17.2.31 `PHMO`

**কাজ:** zero-dependency observability—request/trace ID, JSON logs, health/readiness, metrics, alerts, retention এবং debug dashboard। Public variable নেই।

| Method | কাজ |
| --- | --- |
| `configure($options): array` | enabled, health/ready route, request logging, file size ও `.mystack` log directory configure করে; retention সর্বশেষ ৯০ দিন। |
| `config(): array` | Effective config। |
| `requestId()` / `traceId()` | Current request correlation ID; generated/incoming trace context অনুযায়ী। |
| `isProbeRequest(): bool` | Current path health/ready probe কি না; normal request metrics noise কমাতে সাহায্য করে। |
| `registerRoutes()` | `/health` ও `/ready` (বা configured path) register করে। |
| `dashboard($url)` | Responsive read-only log/problem UI; debug false হলে route register হয় না। |
| `report($date,$limit,$level,$search)` | Bounded log entries, groups, files/lines/details report। |
| `health($withDependencies)` | Liveness বা PHLS/PHDB readiness array। Dependency failure ready=false। |
| `metrics($date)` | Request count, error rate, latency average/p95 এবং status summary। |
| `log($level,$event,$context)` | Structured JSON line atomically append/rotate করে। Secret context দেবেন না। |
| `finishRequest()` | Shutdown-এ latency/status/memory/request metadata final log; automatic registration থাকতে পারে। |

## 18. Deployment, cache ও production checklist

### Production bootstrap

```php
PHDE::debug(false);
PHRO::guard();
PHRQ::cross(true);
PHMO::configure(['enabled' => true]);
PHMO::registerRoutes();
```

Checklist:

- [ ] PHP 8.1+, required extensions এবং writable folders verified
- [ ] `php mystack doctor`, `audit`, `smoke` pass
- [ ] router, JWT, encryption, OAuth, payment, mail, ntfy/VAPID secret আলাদা
- [ ] HTTPS, rewrite, base path, `/app.js`, `/sw.js` verified
- [ ] debug false; API Bar/Live Map/PHCD/monitor unauthorized নয়
- [ ] DB least-privilege user এবং schema change backup আছে
- [ ] backup restore বাস্তবে test করা
- [ ] Cloudflare/reverse proxy CIDR explicitly trusted
- [ ] CSP/CORS origin exact; unnecessary camera/microphone/geolocation access নেই
- [ ] auth/payment/account response `no-store, private`
- [ ] OAuth callback, webhook signature এবং idempotency tested
- [ ] PHFY permission এবং private authorization tested
- [ ] Android/iOS/Desktop target browser push test
- [ ] automated browser regression, synthetic uptime, stress/soak test
- [ ] PHMO log retention/rotation ও external alert delivery verified

### Cache

Component cache তিন ভাগে থাকে: `src/cache/css`, `src/cache/js`, `src/cache/php`। Missing path auto-create হয়; generated file component-এর readable name ধরে রাখে। `/app.js` long cache/ETag এবং `/sw.js` revalidation policy PHRO manage করে। Cloudflare/origin cache যেন debug/release বা dynamic configuration ভুলভাবে mix না করে তা verify করুন।

## 19. Troubleshooting ও verification

### প্রথম commands

```bash
php -l index.php
php mystack doctor
php mystack audit
php mystack smoke
```

### Common সমস্যা

**PHLS locked/malformed**

- `.mystack` writable কি না দেখুন;
- `PHLS::checker()` চালান;
- একই storage network filesystem-এ বহু host থেকে share করবেন না;
- framework recovery নিজে data salvage/rebuild চেষ্টা করে—ম্যানুয়ালি live DB file delete করবেন না।

**Header/footer SPA navigation-এ হারায়**

- fetched response পূর্ণ valid HTML কি না;
- error response/DB stack trace HTML document হিসেবে swap হচ্ছে কি না;
- destination layout markers ও CSS প্রস্তুত হচ্ছে কি না;
- PHJS synchronized build smoke pass কি না।

**Page প্রথমে সাদা/CSS late**

- component cache writable;
- destination CSS URL valid;
- CSS response content-type/status;
- PHJS CSS-ready navigation smoke test;
- CDN/Cloudflare stale HTML এবং missing stylesheet mismatch।

**PHFY private 401/403**

- user session/authorizer/private permission;
- same-origin credential এবং CSRF;
- private endpoint client config-এ authorized অবস্থায় এসেছে কি না। Unauthorized client private poll বন্ধ করবে—এটাই expected।

**Web Push কাজ করছে না**

```php
var_dump(PHFY::cryptoCapability());
var_dump(PHFY::webPushCapability());
```

তারপর HTTPS, browser permission, Service Worker registration, PushManager subscription এবং platform restriction দেখুন।

**Live Map script CSP-তে block**

বর্তমান Live Map self-hosted/local compatible assets ব্যবহার করবে। External script যোগ করলে CSP-তে explicit trusted source প্রয়োজন; ad blocker-এর Cloudflare beacon error framework failure নয়।

## 19. AI discovery ও স্বয়ংক্রিয় API documentation

MyStack-এর AI-readable documentation বর্তমান `library/*.php` source থেকে তৈরি হয়। কোনো library execute না করেই public class, method, parameter, return declaration, source hash এবং doc summary index করা হয়।

```bash
php mystack docs:build
php mystack docs:check
```

তৈরি হওয়া resource:

- `docs/index.html` — responsive ও searchable static documentation portal;
- `docs/index.md` এবং `docs/api/*.md` — human-readable source reference;
- `docs/api.json` — machine-readable সম্পূর্ণ API catalog;
- `docs/manifest.json` — repository, license, build time, totals ও source hash;
- `llms.txt` — AI-এর জন্য সংক্ষিপ্ত framework map;
- `llms-full.txt` — indexed public signature ও canonical documentation-সহ পূর্ণ context;
Root ও `docs/`—দুই জায়গাতেই `llms.txt` ও `llms-full.txt` রাখা হয়: GitHub raw repository এবং published Pages site উভয় পথেই AI discovery কাজ করে। `robots.txt` ও `sitemap.xml` static file তৈরি করা হয় না; framework-এর PHRO router runtime-এ সেগুলো provide করে। `.github/workflows/docs.yml` কেবল generated `docs/` artifact GitHub Pages-এ deploy করে; executable PHP source documentation site-এ publish করে না। Repository settings-এ একবার Pages source হিসেবে **GitHub Actions** নির্বাচন করতে হবে। MyStack rolling `main` branch অনুসরণ করে এবং কোনো fixed framework version ঘোষণা করে না।

Public API বদলানোর পরে `docs:build` চালাতে হবে। `docs:check` source hash ও public-method count মিলিয়ে stale documentation শনাক্ত করে এবং `php mystack smoke` একই check চালায়। Generated API page হাতে edit করবেন না; executable source সর্বশেষ সত্য।

### Guarantee boundary

Smoke test code path, synchronization ও local capability যাচাই করে। এটি live merchant account approval, third-party uptime, production DNS/TLS, browser vendor policy, real traffic scale বা backup restore সফলতা প্রমাণ করে না। এগুলোর জন্য target environment end-to-end test আবশ্যিক।

---

এই manual-এর সঙ্গে `README.md` quick-start এবং `AGENTS.md` engineering contract একসাথে ব্যবহার করুন। Public behavior বদলালে code, smoke coverage এবং তিনটি documentation একই change-এ update করুন।
