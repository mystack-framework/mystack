# MyStack Framework — AI/Agent Engineering Contract

This file is the authoritative working contract for AI agents and automated tools operating inside a MyStack project. Follow the current source code before assumptions, examples from other frameworks, or older documentation.

## 1. Source-of-truth order

When information conflicts, use this order:

1. The user's explicit request.
2. Executable framework code in `library/`, canonical browser runtime in `src/js/PHJS-min.php`, and the root `mystack` CLI.
3. This `AGENTS.md` contract.
4. `MANUAL_BN.md` and `README.md`.
5. Comments, examples, and historical conventions.

Never invent a method, configuration key, provider capability, or runtime dependency. Inspect the relevant class and verify the exact signature before use.

## 2. Architecture and file placement

| Path | Purpose | Agent rule |
| --- | --- | --- |
| `index.php` | Application bootstrap, framework configuration, imports, routes, and final `PHRO::listen()` | Preserve initialization order and keep secrets out of committed examples. |
| `library/` | Thirty zero-Composer framework libraries plus `library.php` loader | Modify only when the user explicitly requests framework-level work. Preserve backward-compatible public APIs. |
| `app/` | Controllers, models, middleware, API and backend logic | Keep files directly in `app/`; do not create subdirectories. |
| `component/` | Views, layouts, and reusable UI components | Keep files directly in `component/`; do not create subdirectories. |
| `src/js/` | PHJS runtime/build sources, Service Worker, and optional browser assets | Treat `PHJS-min.php` as canonical; see synchronization rules below. |
| `src/cache/css`, `src/cache/js`, `src/cache/php` | Generated component builds | Directories are auto-created. Do not rename generated files to hashes or opaque names. |
| `.mystack/` | Private PHLS/PHMO runtime state and structured logs | Never expose publicly or commit runtime data. Keep its access guard intact. |
| `mystack` | Intelligent CLI, doctor, audit, smoke test, folder repair, updater | Keep executable PHP syntax and update its smoke coverage with framework changes. |

`app/` and `component/` intentionally use a flat layout. Framework-owned runtime folders may have their own internal structure.

## 3. Bootstrap conventions

Load the framework once:

```php
<?php
require_once 'library/library.php';

PHDE::debug(true);
PHDE::memory('512M');
PHTM::setZone('Asia/Dhaka');

PHRO::guard();
PHRO::key('replace-with-a-long-secret', false);
PHRO::track(false);

PHJT::key('replace-with-a-separate-jwt-secret');
PHJT::algorithm('HS512');

// Configure optional services here.

import('app:HomeController', 'component:PublicHome');

PHRO::get('/', [HomeController::class, 'index']);
PHRO::listen();
```

MyStack does not depend on `APP_ENV`, `APP_DEBUG`, or automatic `.env` parsing. Runtime behavior is controlled by framework APIs such as `PHDE::debug(true|false)`. A project may load environment values explicitly, but documentation and generated code must not present that as a framework requirement.

Order-sensitive rules:

- Configure `PHDE`, timezone, guards, keys, cross-origin behavior, storage, and optional services before registering application routes.
- Call `PHRO::guard()` before protected request processing.
- Register `PHFY`, `PHMO`, PHCD, debug UIs, and application routes before `PHRO::listen()`.
- Keep `PHRO::listen()` at the end of the request bootstrap.
- Debug-only UIs must remain unavailable when `PHDE::isDebug()` is false.

## 4. Dynamic paths and imports

Use the framework path layer instead of hardcoded document-root or project-folder assumptions:

```php
$path = DIR::path('component:PublicHome');
$url  = DIR::link('js:custom.js', true);
$base = PHCO::path();
$prefix = PHCO::pre();
```

Use `import()` for project/application assets:

```php
import('app:UserController');
import('component:PublicHome');
import('js:custom.js');
import('css:app.css');
```

Rules:

- Do not hardcode `/projects/name`, a hosting document root, or a domain.
- The application must work at a domain root, hosting root, subdomain, or nested subfolder.
- Use `PHCO::pre()` for project-scoped cookie/storage namespaces and `PHCO::path()` for the detected base path.
- Do not replace `import()` with ordinary `require/include` in application code. The root loader itself is the exception.
- Never allow user input to become an unchecked import/path key.

## 5. Core library catalog

All libraries are loaded by `library/library.php`.

| Library | Responsibility |
| --- | --- |
| `DIR` / `Importer` | Portable filesystem/URL resolution and dynamic imports. |
| `PHDE` | Debug state, error handling, API errors, memory limits, and debug API Bar. |
| `PHRO` | Router, route metadata, middleware, CSRF, security guard, WAF, proxy/IP resolution, tracking, sitemap/robots/manifest, channels, and asset routes. |
| `PHOB` | Browser/device capability build and identity helpers. |
| `PHEV` | WebSocket server, SSE, StreamUI, clients, events, and broadcasts. |
| `PHEM` | Native SMTP, IMAP, and POP3 mail operations. |
| `PHML` | Markup DSL, layouts, partials, blocks, components, head/body and asset composition. |
| `PHCS` | PHP-native utility CSS processing and build engine. |
| `PHJS` | PHP-to-JavaScript DSL, typed builder, declarative actions, request and application bridge. |
| `PHJC` | View/page composition, HTML/JS rendering, slots, layouts, assets, metadata, and compiled component cache. |
| `PHCO` | Secure project-scoped cookies, base path, and namespace prefix. |
| `PHSE` | Secure session lifecycle and expiring session values. |
| `PHLS` | SQLite-backed local state, atomic counters, TTL, tags, rate-limit data, recovery, and locking. |
| `PHDB` | Prepared database access, CRUD, analytics, transactions, streaming, schema synchronization, and JSON/array columns. |
| `PHRQ` | Server/browser HTTP requests, CORS/CSP policy, response status, streaming, file output, and Live Map. |
| `PHQR` | Memory-safe QR code data-URI generation. |
| `PHED` | Authenticated application encryption and key management. |
| `PHTP` | OTP/TOTP plus account-level Authenticator enrollment, replay protection, and recovery. |
| `PHTM` | Timezone, parsing, calculation, modification, and formatting. |
| `PHVD` | Input validation and database-aware rules. |
| `PHCD` | Authorized, atomic client-package installation/update and package UI. |
| `PHJT` | HMAC JWT creation, verification, algorithm selection, and key rotation. |
| `PHTR` | Translation provider requests and response parsing. |
| `PHAU` | Identity creation/checking, verification flows, OAuth/OIDC provider matrix and callbacks. |
| `PHOP` | Image, video, ZIP and text processing helpers. |
| `PHAI` | AI provider bridge, clustering, MCP server/tools/prompts/resources and guarded remote bridge. |
| `PHAP` | Compact REST API routes, validation, auth, resources, pagination and JSON responses. |
| `PHUI` | Registered UI elements, sections, layouts, pages, placeholders, aliases and searchable catalog. |
| `PHPA` | Payment gateway facade, capability-aware gateway adapters, webhooks/refunds, and courier facade. |
| `PHFY` | ntfy public/private notifications, PHLS subscriptions, VAPID/Web Push and PHJS client configuration. |
| `PHMO` | Request/trace IDs, JSON logs, health/ready probes, metrics, alerts, retention, and debug dashboard. |

## 6. Routing and security rules

Prefer the fluent router:

```php
PHRO::post('/users', [UserController::class, 'store'])
    ->name('users.store')
    ->middleware(AuthMiddleware::requireRole(['admin']))
    ->header('Cache-Control', 'no-store, private')
    ->disallow();
```

- Use the exact HTTP method; never accept state changes through GET.
- Apply authorization on the server, even when the UI hides an action.
- Use `PHRO::csrfField()` in state-changing HTML forms and validate via the guard.
- Keep `PHRO::guard()` defaults unless a specific verified requirement justifies an override.
- `trustProxies()` accepts only explicit trusted proxy addresses. Forwarded headers are fallback information only after proxy validation; never blindly trust client-supplied forwarding headers.
- Use parameterized callbacks and gathered request data; escape output according to its HTML/JSON/URL context.
- Use route cache headers deliberately: public cache only for genuinely public data; `no-store, private` for auth, account, payment, and sensitive pages.
- Keep CSP/CORS restrictive. `PHRQ::cross(true)` means framework-managed self-aware policy; use an explicit origin/IP list for cross-site access.
- Never expose debug errors, API Bar, Live Map, PHCD manager, monitor UI, secrets, stack traces, or `.mystack` storage publicly in production.

## 7. Database and storage rules

Use PHDB prepared helpers:

```php
$user = PHDB::find('users', $id);
$rows = PHDB::select('users', '*', ['status' => 'active'], 50);
$id   = PHDB::insert('users', ['name' => $name, 'email' => $email]);
PHDB::update('users', ['status' => 'disabled'], ['id' => $id]);
```

- Raw SQL is allowed only through `PHDB::query()`/`fast()` with placeholders and a separate parameter array. Never interpolate untrusted values.
- `PHDB::fast()` is an unbuffered generator; consume it in one pass and do not start another query on that connection until iteration finishes.
- `PHDB::delete(..., allow_all: true)`, destructive schema sync, truncate, or drop requires explicit user intent.
- `PHDB::createTable()` is the framework schema-sync mechanism. Preserve its current destructive-sync semantics unless the user explicitly asks to change them.
- Use `PHDB::transaction()` for multi-step writes.
- Use `PHLS` for local single-server state, counters, TTL, locks, and subscriptions. It is not a multi-server shared database.
- Keep PHLS calls non-fatal at request-protection boundaries; preserve its corruption recovery and bounded retry behavior.
- Use `PHSE` for request/user session state and `PHCO` for client cookies; do not substitute one storage type for another without considering lifetime and trust.

## 8. Frontend, PHUI, PHCS and PHJS

Build reusable UI with PHUI/PHML and utility classes processed by PHCS:

```php
echo PHUI::element('button:primary', [
    'slot' => 'Save',
    'class' => 'w-full md:w-auto',
    'phjs' => ['click' => 'toast "Saved"'],
]);
```

- All UI must be responsive, keyboard accessible, focus-visible, semantic, and theme-safe.
- Escape untrusted text. PHUI raw HTML is allowed only through its guarded/checking path and only for explicitly trusted markup.
- Support placeholders such as `{{key|Default}}`; do not remove existing aliases or registry entries.
- Do not introduce Blade, Twig, React, Vue, Alpine, or HTMX as a required runtime dependency.
- PHJS is self-contained. Compatibility helpers may recognize Alpine/HTMX-like behavior, but application behavior must work through PHJS without loading those libraries.
- Prefer PHJS HTML directives and PHP builders over page-specific inline JavaScript when equivalent behavior exists.
- Preserve SPA navigation boundaries: only same-origin document links are intercepted; mailto, tel, download, external, non-HTML, and native links remain native.
- Preserve one-time initialization, request de-duplication, destination CSS preparation, page cache, touch/hover intent prefetch, Service Worker, accessibility, toast, and cleanup lifecycles.

### PHJS synchronization contract

`src/js/PHJS-min.php` is the canonical `/app.js` source. Any PHJS runtime behavior change must be synchronized so these builds contain the same behavior:

- `src/js/PHJS-min.php` — canonical PHP-aware runtime
- `src/js/PHJS.php`
- `src/js/phjs.js`
- `src/js/phjs.min.js`
- any directly related generated/runtime counterpart required by the changed section

Do not merely copy PHP interpolation into plain `.js`; preserve equivalent resolved behavior. Verify syntax, UTF-8/Bengali integrity, runtime markers, and the `PHJS synchronized builds` smoke test. `src/js/SW.php` is the canonical dynamic Service Worker source; keep `sw.js` synchronized when Service Worker behavior changes.

## 9. Authentication, integration and sensitive flows

- Hash passwords with PHP password APIs; never encrypt or log plaintext passwords.
- Keep PHRO application key, PHJT JWT key, PHED encryption key, OAuth secrets, payment keys, mail passwords, ntfy tokens, and VAPID private keys separate.
- Use HTTPS for OAuth callbacks, payment/webhook endpoints, Web Push and credential submission.
- OAuth state/PKCE/nonce and callback validation must remain enabled. Store only the provider fields needed by the application; avoid persisting raw provider responses.
- TOTP enrollment must be confirmed before activation. Preserve replay prevention and single-use recovery codes.
- Verify payment/courier capability before calling an operation. Treat a configured adapter as integration-ready, not proof that live merchant credentials or provider approval are valid.
- Verify signed webhooks before processing and make fulfillment idempotent.
- PHFY public topics are public by nature. Put user-specific data only in authorized private delivery, and keep browser permission opt-in.
- PHAI remote URL/bridge access must remain allowlisted, TLS-verified, timeout-bounded, and protected against private-network SSRF unless explicitly authorized.

## 10. Observability and production behavior

Recommended safe bootstrap:

```php
PHDE::debug(false);
PHMO::configure(['enabled' => true]);
PHMO::registerRoutes();            // /health and /ready by default
// PHMO::dashboard('/monitor');    // visible only in debug mode
```

- Production must use `PHDE::debug(false)` and must not reveal database errors or stack traces to clients.
- PHMO writes structured logs under `.mystack`, retains the configured window (default 90 days), and provides request/trace IDs, latency and error metrics.
- `/health` is a liveness check; `/ready` includes dependency readiness and may return 503.
- Monitoring endpoints do not replace external uptime checks, backups, restore drills, browser regression tests, or load/soak testing.
- Long-running PHEV workers must be supervised by the hosting platform; do not start blocking loops inside ordinary web requests.

## 11. CLI workflow and validation

Use the built-in CLI from the project root:

```bash
php mystack help
php mystack doctor
php mystack doctor --fix
php mystack audit
php mystack smoke
php mystack update --check
```

- `doctor` is read-only; `doctor --fix` applies bounded formatting/permission repair.
- `audit` is a read-only production review.
- `smoke` is the canonical framework regression suite and must remain green.
- `update` compares the official GitHub `main` branch by SHA-256/exact bytes, prompts before changes, validates staged files, runs smoke, and rolls back on failure.
- Update scope is strictly limited to `library/*`, `src/js/*`, `AGENTS.md`, `LICENSE`, `MANUAL_BN.md`, `README.md`, and `mystack`. It never deletes unmatched local files.
- Before handoff, run syntax checks for changed PHP, relevant targeted tests, and `php mystack smoke` for framework changes.

## 12. Change safety and compatibility checklist

Before editing:

- Read the whole relevant method/class and all call sites.
- Search for synchronized copies, generated files, aliases, reflection-based use, and smoke assertions.
- Preserve unrelated user changes and runtime data.

While editing:

- Keep existing public signatures and return shapes unless a breaking change was explicitly requested.
- Add features through optional defaults so existing projects continue to run.
- Validate paths, URLs, headers, filenames, HTML and remote responses at their trust boundary.
- Use atomic writes/renames and rollback for package, updater, schema-sensitive, or stateful operations.
- Do not add Composer/NPM dependencies or silently call third-party services.
- Never claim “100% error-free” without evidence; report what was actually verified and what still needs live-provider/hosting testing.

After editing:

- Run `php -l` on every changed PHP executable.
- Run `php mystack smoke`; use `audit` when production/security behavior changed.
- Check UTF-8/Bengali text, responsive UI, keyboard/focus behavior, base-path portability, and debug-off behavior where relevant.
- Update `README.md`, `MANUAL_BN.md`, this contract, and CLI smoke coverage when public behavior changes.

## 13. Communication rules

- Explain work and results to the project owner in Bengali.
- Lead with the outcome, then provide exact affected files and verification evidence.
- Distinguish framework guarantees from hosting, browser, credential, provider, and production-environment dependencies.
- Ask before destructive, externally visible, or scope-expanding actions; do not ask unnecessary questions when a safe local implementation is clear.
