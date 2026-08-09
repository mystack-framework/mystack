# PHRO API

Source: `library/PHRO.php`  
SHA-256: `9951ff5d55fc29f54519f3d63904f23c2bdae60fe04832641655fd0e2415cfed`

Classes: `PhroSecurityException`, `PhroShield`, `PhroGuard`, `PhroContentTypeShield`, `PhroSqlInjectionShield`, `PhroXssShield`, `PhroRateLimitShield`, `PhroAttemptShield`, `PhroFileUploadShield`, `PhroHeaderInspectionShield`, `PhroHoneypotShield`, `PhroOpenRedirectShield`, `PhroCsrfShield`, `PhroChannel`, `PHRO`

## Public methods

### `__construct(string $message = "", int $code = 403, ?\Throwable $previous = null)`

See executable source and call sites for the behavioral contract.

### `inspect(array $request_data, array $config)`

See executable source and call sites for the behavioral contract.

### `__construct(array $config, array $request_data)`

See executable source and call sites for the behavioral contract.

### `addShield(string $key, PhroShield $shield)`

Adds a custom shield to the guard.

### `getShield(string $key): ?PhroShield`

Retrieves a shield instance by its key.

### `removeShield(string $key)`

Removes a shield from the guard.

### `protect()`

Executes all enabled shields to protect the application.

### `static block(string $message = 'Forbidden', int $code = 403)`

Blocks the request immediately and displays a forbidden message.

### `inspect(array $request_data, array $config)`

See executable source and call sites for the behavioral contract.

### `inspect(array $request_data, array $config)`

See executable source and call sites for the behavioral contract.

### `inspect(array $request_data, array $config)`

See executable source and call sites for the behavioral contract.

### `inspect(array $request_data, array $config)`

See executable source and call sites for the behavioral contract.

### `inspect(array $request_data, array $config)`

See executable source and call sites for the behavioral contract.

### `checkAndIncrementAttempt(string $event_name, int $max_attempts, int $block_duration_minutes, int $reset_after_minutes, string $block_message): array`

See executable source and call sites for the behavioral contract.

### `performAttemptReset(string $event_name): void`

See executable source and call sites for the behavioral contract.

### `inspect(array $request_data, array $config)`

See executable source and call sites for the behavioral contract.

### `inspect(array $request_data, array $config)`

See executable source and call sites for the behavioral contract.

### `inspect(array $request_data, array $config)`

See executable source and call sites for the behavioral contract.

### `inspect(array $request_data, array $config)`

See executable source and call sites for the behavioral contract.

### `inspect(array $request_data, array $config)`

See executable source and call sites for the behavioral contract.

### `__construct(string $channel_id)`

See executable source and call sites for the behavioral contract.

### `authorize(callable $callback): self`

See executable source and call sites for the behavioral contract.

### `worker(string $name, callable $handler, int $max_runs = 0, int $interval_seconds = 0): self`

See executable source and call sites for the behavioral contract.

### `listen(): void`

See executable source and call sites for the behavioral contract.

### `static initialize($custom_base_path = null)`

Initializes the router. This can be called manually to set a custom base path,

### `static guard(array $config = [])`

Configures and enables the security guard (WAF).

### `static secure(): bool`

Public proxy-aware HTTPS check for framework components such as PHCO.

### `static getToken(): string`

Return the session CSRF token, creating it when necessary.

### `static csrfField(): string`

Return a ready-to-render hidden CSRF field.

### `static regenerateToken(): string`

Rotate and return the current session CSRF token.

### `static trustProxies(array $ipAddresses): void`

Trust forwarding headers only from these reverse-proxy IP addresses.

### `static root()`

Get the root URL for the application.

### `static getCallbackContext(): array`

Public accessor to get the variables from the current route's callback context.

### `static get(string $url, $callback): self`

Define a route for GET method.

### `static post(string $url, $callback): self`

Define a route for POST method.

### `static put(string $url, $callback): self`

Define a route for PUT method.

### `static patch(string $url, $callback): self`

Define a route for PATCH method.

### `static delete(string $url, $callback): self`

Define a route for DELETE method.

### `static head(string $url, $callback): self`

Define a route for HEAD method.

### `static options(string $url, $callback): self`

Define a route for OPTIONS method.

### `static group(string $prefix, callable $callback): self`

Creates a route group with shared attributes that can be chained.

### `static crud(string $uri, string|array $controller, array $options = []): self`

Registers a full set of CRUD routes.

### `static gap(string $uri, string|array $controller, array $options = []): self`

Registers a common set of routes for a resource using only GET and POST methods.

### `static sgap(string $uri, string|array $controller, array $options = []): self`

Registers two essential routes for a single resource URI: a GET and a POST.

### `static add($method, $url, $callback)`

Define a route for custom HTTP method.

### `name(string $name): self`

Assigns a name to the most recently defined route or group of routes.

### `middleware($middleware): self`

Attach middleware(s) to the most recently defined route.

### `header($header, ?string $value = null): self`

Attaches response headers to the most recently defined route or group.

### `mcp(string $type, string $name, string $description, array $schema = []): self`

⚡ THE ULTIMATE AI BRIDGE (MCP INTEGRATION) ⚡

### `static gatherRequestData(): array`

Gets the full filesystem path for a given resource using colon notation.

### `static attempt($config_or_message = 'generic_attempt_fail', bool $return_details_override = false)`

Tracks failed attempts and enforces a block if the limit is exceeded.

### `static resetAttempt(string $event_name): void`

Resets the failed attempt count for a specific event and client.

### `static task(...$tasks): void`

⚡ PHOP Ultimate Async & Parallel Engine (Simplified SSL Auto - Final) ⚡

### `static stream(callable $messageProvider): void`

⚡ PHOP Real-Time SSE Engine (Final - Heartbeat + Last-Event-ID Support) ⚡

### `static channel(string $channel_id): PhroChannel`

Open a Real-Time Channel (Receiver Route Setup).

### `static publish(string $channel_id, string $command_name, $data = null): bool`

Publish data/command to a specific channel (Sender Route Setup).

### `static routes($path = null, $method = 'GET')`

Get all defined routes or filter by short, link, and method.

### `static route(?string $identifier = null, ?array $params = [])`

Finds routes, generates URLs, or returns the current route details.

### `static source($short, $method = 'GET')`

Get the source code of the callback for the specified route.

### `static getUserIP()`

Get the user's IP address.

### `static gatherHeaders()`

Gathers all HTTP request headers.

### `static getGeolocationData()`

Fetches Server/Caller Geolocation with multiple fallbacks.

### `static extractIdentityFromCookie()`

Extracts client identity data from the cookie if available.

### `static netKey($data): string`

Create an unchangeable network identity key.

### `static deviceKey($data): string`

Create an unchangeable device identity key (The Fingerprint Master).

### `static decrypt($encryptedData)`

Decrypt the encrypted data

### `static key($new_key, $dataPrint = false)`

Updates the default encryption key.

### `static track($footprint = false)`

Init the footprint/track.

### `static footprint(): array`

Collects and processes exhaustive request data, generates robust unique identifiers,

### `static setIdentityCookie($params, $expiryTime = 525600): bool`

Sets the identity cookie with encrypted user identity data.

### `static userAgentInfo()`

Extract comprehensive information from the HTTP_USER_AGENT string and store it in $params.

### `static createSlug(string $string, string $separator = '-'): string`

Creates a clean, URL-safe, SEO-friendly slug from ANY language.

### `sitemap($options = true): self`

The Ultimate AI-Aware Sitemap Configuration Engine.

### `disallow($user_agents = '*'): self`

Marks a route to be "Disallowed" in robots.txt for specific user agents.

### `allow($user_agents = '*'): self`

Marks a route to be "Allowed" in robots.txt.

### `static getSitemapRoutes(): array`

Generates a complete list of URLs for the sitemap.

### `static manifest(array $config): void`

Configures and enables a universally compatible, auto-generated manifest.json.

### `static addSitemapEntry(string $loc, array $options = []): void`

Adds a custom URL entry to the sitemap.

### `static addRobotsRule(string $rule): void`

Adds a custom line to the robots.txt file.

### `static listen($error_handler = null)`

Listen for incoming HTTP requests and execute matching route callback.


Generated by `mystack docs:build`; do not hand-edit.
