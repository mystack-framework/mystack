<div align="center">
  <img src="icon.png" alt="MyStack Framework Icon" width="150" />
  <h1>🚀 MyStack Framework</h1>
  <p><b>The Ultimate, Auto-Healing, Zero-Dependency PHP Ecosystem</b></p>
</div>

## 🌟 Introduction
Welcome to **MyStack**, the next-generation, incredibly fast, and self-sustaining PHP framework. Designed to break boundaries, MyStack entirely removes the complexities of traditional frameworks like Laravel, Symfony, or CodeIgniter. It offers a monolithic, single-class driven, zero-dependency architecture.

Why is it the best?
- **Zero Dependencies:** No Composer bloat, no `vendor` folder chaos. Everything is native.
- **Blazing Fast:** Direct execution and optimized routing with zero overhead.
- **Auto-Healing CLI:** Integrated `mystack doctor` to instantly fix code structure, missing tags, BOM issues, and more.
- **All-in-One Powerhouse:** Built-in ORM, Routing, UI Components, Payment Gateways, HTML Builder, Utility CSS Generator, and Natural Language JS logic—all accessible directly.

MyStack is perfect for everything: from rapid prototyping and small startups to high-performance enterprise APIs, e-commerce platforms, and real-time SaaS applications.

---

## 🛠️ Core Libraries (The Heart of MyStack)

MyStack operates on uniquely powerful, globally accessible classes located in the `library/` folder.

### 1. `PHRO` (Routing Engine)
- **What it does:** The traffic controller of your application.
- **Features:** Supports RESTful verbs, regex constraints, rate limiting, IP blocking, Bot detection, and instant middleware injection.
- **Example:**
  ```php
  PHRO::get('/user/{id}', [UserController::class, 'show'])->where(['id' => '[0-9]+']);
  ```

### 2. `PHDB` (Database ORM)
- **What it does:** A highly secure, fluid wrapper around `mysqli` with prepared statements.
- **Features:** No complex migrations needed (uses MyStack's custom table creation logic). Supports joins, transactions, caching, and dynamic query building.
- **Example:**
  ```php
  $user = PHDB::table('users')->where('status', 'active')->first();
  ```

### 3. `PHUI` (User Interface Engine)
- **What it does:** Replaces Twig/Blade with a direct PHP-driven component system.
- **Features:** Over 7000+ responsive UI components. Parses multi-dimensional nested arrays. Automatically connects with `PHCS` for theme switching. Never uses the word 'readymade' in conventions.
- **Example:**
  ```php
  echo PHUI::HeroSection(['title' => 'Welcome', 'subtitle' => 'Build Faster']);
  ```

### 4. `PHML` (HTML Builder)
- **What it does:** An object-oriented way to generate complex HTML DOM trees directly in PHP without messy string concatenations.
- **Features:** Chainable methods, automatic attribute handling, dynamic inner structures.

### 5. `PHVD` (Validation Engine)
- **What it does:** Bulletproof security for incoming data.
- **Features:** Contains standard checks (email, max, min, numeric) and extreme security filters like `safe` (prevents SQLi, XSS, Path Traversal, Null Bytes), `active`, `expire`, `age`, and custom regex patterns.
- **Example:**
  ```php
  $errors = PHVD::check(['username' => 'required|safe']);
  ```

### 6. `PHCS` (On-the-Fly CSS Generator)
- **What it does:** Similar to Tailwind CSS but completely native. No Node.js build steps needed!
- **Features:** Reads your classes on runtime and dynamically injects exactly the CSS you need into `library/PHCS.php`.

### 7. `PHPA` (Payment Gateway Integration)
- **What it does:** Seamless and extensible payment processing.
- **Features:** Can dynamically inject or override payment logic via Closure functions using the `setLogic()` method without modifying core code.

### 8. `PHAI` (AI Engine & MCP Server)
- **What it does:** Brings the power of Artificial Intelligence directly into your PHP application context.

---

## ⚡ JavaScript Core (Hybrid PHP-JS Bridge)

The frontend synchronization is maintained flawlessly with built-in JS assets.

### 1. `src/js/phjs` (`PHJS.js` / `PHJS.php`)
- **What it does:** The primary heartbeat of MyStack's frontend logic.
- **Features:** Evaluates NLP (Natural Language Processing) syntax dynamically parsed via `tjs('...')`. Connects UI component triggers with server-side responses instantly.

### 2. `src/js/ws` (`ws.js`)
- **What it does:** Real-time WebSockets integration handling instant messaging, live notifications, and real-time data streaming perfectly aligned with `PHRO`.

---

## 🏆 Why MyStack is at the Top?
1. **Unmatched Developer Experience:** The ultimate VS Code Extension (`mystack-extension`) provides God-mode autocompletion for all classes, 7000+ UI components, Tailwind-like utility classes, and custom NLP syntaxes.
2. **Infinite Scalability:** Eliminates the "framework overhead" completely. It runs closer to bare-metal PHP than anything else.
3. **Security First:** Built-in SQLi/XSS prevention in `PHVD`, robust bot-banning algorithms in `PHRO`.
4. **No Build Tools:** Forget Webpack, Vite, or Node modules. `PHCS` generates CSS, and PHP serves the JS. Just write code and hit refresh!

---

## 🛠️ Installation & Usage
1. Clone or extract the project.
2. Place frontend views directly inside the `./component` directory.
3. Place Controllers, Models, and Middlewares directly inside the `./app` directory.
4. Run `php mystack doctor` to ensure the structure is perfectly healthy.
5. Code effortlessly using the powerful VS Code extension.

---
<div align="center">
  <p>Built with ❤️ for speed and simplicity. <b>MyStack makes coding an art again.</b></p>
</div>
