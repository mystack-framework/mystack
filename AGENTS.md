# Mystack Framework AI/Agent Instructions

You are working within the "mystack" PHP framework, a highly advanced, zero-dependency, single-class oriented framework. Your primary goal is to write clean, secure, and perfectly compatible code according to the unique architecture of this framework.

## 1. Project Architecture & Directory Structure
- **Root Directory (`/`)**: Contains the entry point `index.php`, documentation (`README.md`, `AGENTS.md`), and the CLI tool `mystack`.
- **`library/`**: Contains all core framework components. **NEVER modify** these files unless explicitly asked. Always use the classes provided here.
- **`app/`**: All Backend logic goes here. This includes Controllers, Models, Middleware, and API Logic. **NO subfolders** should be created here. All files sit directly in `/app`.
- **`component/`**: All Frontend UI components and Views go here. **NO subfolders** should be created here. All files sit directly in `/component`.
- **`src/`**: Contains static assets like JS (`src/js/`), caching directories, etc.

## 2. Core Libraries & Usage Manual

### Routing & Security (`PHRO`)
- **Usage**: Handles all routing, middleware, and firewall protection.
- **Methods**: `PHRO::add($method, $path, $callback)`, `PHRO::get()`, `PHRO::post()`, `PHRO::guard()`.
- **Note**: Supports built-in XSS, SQLi, CSRF, and Rate Limit shields.

### Database ORM (`PHDB`)
- **Usage**: Used for all database interactions. Uses mysqli prepared statements.
- **Methods**: `PHDB::select($table, $columns, $where)`, `PHDB::insert()`, `PHDB::update()`, `PHDB::delete()`, `PHDB::query()`.
- **Rule**: NEVER write raw insecure SQL. Always use `PHDB` helper methods. Do NOT create migration scripts; rely on custom dynamic table creation if needed.

### UI Engine (`PHUI` & `PHML`)
- **Usage**: The native component-based UI engine. Replaces Blade/Twig.
- **Methods**: `PHUI::ui('html:div', ['class' => 'p-4', 'text' => 'Hello'])` or `PHUI::render()`.
- **Rule**: ALWAYS use `PHUI` to build UI components. Components must be fully responsive, support theme switching via `PHCS`, and avoid the term "readymade" in names. Support placeholders like `{{key1|Default}}`.

### CSS Generator (`PHCS`)
- **Usage**: On-the-fly Tailwind-like utility CSS generator.
- **Rule**: Use standard Tailwind CSS utility classes in HTML/PHML. `PHCS` automatically parses and generates the CSS.

### Javascript Bridge (`PHJS`)
- **Usage**: Hybrid PHP-JS Bridge. Generates JS via Natural Language Processing.
- **Methods**: `phjs('click of #btn')` or `tjs()`.
- **Rule**: Frontend reactivity relies on `src/js/phjs.js` and Alpine/HTMX. Output dynamic interactions via `PHJS::gen()`.

### Payment Architecture (`PHPA`)
- **Usage**: Massive payment gateway library supporting 30+ gateways.
- **Methods**: `PHPA::gatewayName()->setKeys(...)->charge(...)`.
- **Rule**: Gateways can be extended or overridden at runtime using `setLogic($chargeCallback, $verifyCallback)`.

### Authentication & Authorization (`PHAU`)
- **Usage**: Handles user login, registration, and session management securely.

### Mail System (`PHEM`)
- **Usage**: Built-in library for sending emails via SMTP, IMAP, and POP3 protocols.

### WebSockets & Events (`PHEV`)
- **Usage**: Real-time event loops and WebSocket connections.

## 3. General Coding Guidelines & Rules
1. **Dynamic Importer**: Use `import('app:Filename')` to load backend logic and `import('component:Filename')` to load views/components. Do not use standard `require/include`.
2. **File Creation**: When asked to create a Controller, Model, or Component, place them directly in the `/app` or `/component` directories. Generated files should include rich, executable demo code.
3. **No External Dependencies**: Do NOT use Composer, NPM, or any third-party template engines (Blade, Twig) or ORMs.
4. **Error Handling**: Follow strict exception handling. The framework provides structured global error handling.
5. **Language**: When communicating with the user for explanations or reports, **ALWAYS write in Bengali**.
6. **Execution Priority**: Always prioritize the user's explicit instructions over general knowledge. If the user asks for a large monolithic file, generate it exactly as requested.

## 4. Troubleshooting & CLI
- The root directory contains a `mystack` CLI tool.
- Run `php mystack doctor` to automatically scan the `app` and `component` directories to fix PHP structural issues, missing tags, BOM issues, and file permissions.

By following these instructions, you will ensure seamless integration with the Mystack ecosystem.
Appended details to AGENTS.md

## 5. Frontend & Asset Handling (`src/js/` & `PHJS`)
- **Alpine.js & HTMX Integration**: The framework deeply integrates with Alpine.js and HTMX. The `PHJS` core understands human-readable syntax and maps them to standard Alpine/HTMX attributes (e.g., `x-data`, `x-show`, `hx-get`).
- **Service Worker (`sw.js`)**: Handles background sync, caching (Assets, Pages, Images), and offline modes seamlessly. Do not override this logic; it's self-contained.
- **Custom JS (`custom.js` & `ui-js.js`)**: Native tooltips, UI components interactions, and cryptographic operations (SHA-256) are already implemented. Reuse existing logic.
- **`phjs()` & `tjs()` Usage**: Use `phjs('toggle modal')` inside UI button attributes to generate necessary JS.
