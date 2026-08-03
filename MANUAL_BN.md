# MyStack Framework — পূর্ণাঙ্গ বাংলা ম্যানুয়াল

এই ম্যানুয়ালটি বর্তমান executable codebase, ৩০টি framework library, `library.php` loader, canonical PHJS runtime এবং `mystack` CLI যাচাই করে লেখা। এটি কোনো পুরোনো School ERP-নির্ভর guide নয়; MyStack দিয়ে সাধারণ website, eCommerce, dashboard, API, SaaS, education, agency, realtime এবং integration-heavy application তৈরির বর্তমান reference।

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
├── AGENTS.md
└── LICENSE
```

`app/` ও `component/`-এর ভেতরে subfolder ব্যবহার করা হয় না। Hosting path hardcode করবেন না:

```php
$component = DIR::path('component:PublicHome');
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
| `php mystack help` | সব command ও উদাহরণ |
| `php mystack get:started` | starter controller/route/view |
| `php mystack make:controller User` | `app/`-এ controller |
| `php mystack make:model Product` | PHDB demo-সহ model |
| `php mystack make:middleware Auth` | middleware |
| `php mystack make:component Alert` | reusable component |
| `php mystack make:view Dashboard` | full page view |
| `php mystack serve 8000` | local server |
| `php mystack cache:clear` | generated cache clear |
| `php mystack doctor` | read-only structure/syntax/permission scan ও folder repair check |
| `php mystack doctor --fix` | bounded safe fix |
| `php mystack audit` | production-oriented read-only audit |
| `php mystack smoke` | full framework regression suite |
| `php mystack update --check` | GitHub `main` SHA/byte diff, কোনো change নয় |
| `php mystack update [path]` | allowlisted path compare, confirm, apply |
| `php mystack update [path] --yes` | prompt ছাড়া verified apply |

Updater Release/version ব্যবহার করে না। এটি official `main` snapshot থেকে `library/*`, `src/js/*`, `AGENTS.md`, `LICENSE`, `MANUAL_BN.md`, `README.md`, `mystack` file-by-file নেয়। Stage, hash, text/PHP/JS validation এবং smoke pass না হলে rollback করে; upstream-এ অনুপস্থিত বলে local file delete করে না।

## 6. DIR ও dynamic importer

এক বা একাধিক import:

```php
import('app:UserController');
import('component:PublicHome', 'js:custom.js', 'css:app.css');
import('app:*');
```

Common path key:

```php
DIR::path('library:PHDB');
DIR::path('app:UserController');
DIR::path('component:PublicHome');
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
echo PHJC::view('PublicHome', ['title' => 'Home']);
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
PHFY::public('Admission is open', [
    'title' => 'School update',
    'keywords' => ['admission'],
]);

PHFY::private('Your result is ready', [
    'users' => ['student@example.com'],
    'permissions' => ['student'],
    'data' => ['result_id' => 123],
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

### Guarantee boundary

Smoke test code path, synchronization ও local capability যাচাই করে। এটি live merchant account approval, third-party uptime, production DNS/TLS, browser vendor policy, real traffic scale বা backup restore সফলতা প্রমাণ করে না। এগুলোর জন্য target environment end-to-end test আবশ্যিক।

---

এই manual-এর সঙ্গে `README.md` quick-start এবং `AGENTS.md` engineering contract একসাথে ব্যবহার করুন। Public behavior বদলালে code, smoke coverage এবং তিনটি documentation একই change-এ update করুন।
