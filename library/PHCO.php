<?php

/**
 * ============================================================================
 * Class: PHCO
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * This class is a core component of the MyStack framework.
 * It provides essential functionalities tailored for high performance and security.
 */



class PHCO {
    
    // Default Security Settings
    private const HTTP_ONLY = true;   // false = JS access
    private const SAME_SITE = 'Lax';
    private const PATH      = '/';

    /**
     * http or https
     */
    public static function isSecure(): bool {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $port443 = $_SERVER['SERVER_PORT'] == 443;
        
        return $https || $port443;
    }

    /**
     * Internal Helper: Safely gets the current domain variations for robust cookie management.
     * 
     * @return array An array of possible domain variations to try.
     */
    private static function getDomainVariations(): array {
        $host = strtolower($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $host = explode(':', $host)[0];

        // For localhost or IP addresses, no special variations are needed.
        if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return [$host]; 
        }

        $variations = [$host]; // e.g., www.example.com

        // Add version with a leading dot for subdomain coverage
        if (substr_count($host, '.') >= 1) {
            $variations[] = '.' . $host; // e.g., .www.example.com
        }

        // Add version without 'www.' if it exists
        if (strpos($host, 'www.') === 0) {
            $non_www_host = substr($host, 4);
            $variations[] = $non_www_host; // e.g., example.com
            $variations[] = '.' . $non_www_host; // e.g., .example.com
        }
        
        // Return unique variations to avoid duplicates
        return array_unique($variations);
    }

    /**
     * Get the project-specific base path for cookies
     * Example: if PHRO::root() = https://abc.xyz/projects/a1
     *          returns /projects/a1/
     */
    public static function path(): string {
        $path = '/';

        if (class_exists('PHRO') && method_exists('PHRO', 'root')) {
            $root = PHRO::root();
            $parsed = parse_url($root);
            $path = $parsed['path'] ?? '/';
        } else {
            // Fallback: current script's base path
            $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
        }
        
        // Ensure it ends with / (very important for sub-folder match)
        if (substr($path, -1) !== '/') {
            $path .= '/';
        }
        
        // Remove trailing index.php or similar if present
        $path = preg_replace('#/index\.php$#i', '/', $path);
        
        return $path;
    }

    /**
     * Auto-generate project-specific cookie prefix from PHRO::root()
     * Example: https://abc.xyz/projects/a1 → "a1"
     * Fallback: current folder name
     */
    public static function pre(): string {
        $prefix = '';

        if (class_exists('PHRO') && method_exists('PHRO', 'root')) {
            $root = PHRO::root();
            $parsed = parse_url($root);
            $path = trim($parsed['path'] ?? '/', '/');
            
            // Extract last meaningful segment (project slug)
            $segments = explode('/', $path);
            $lastSegment = end($segments) ?? "root";
            
            if ($lastSegment && $lastSegment !== 'index.php') {
                $prefix = strtolower(preg_replace('/[^a-z0-9]/i', '', $lastSegment)) . '_';
            }
        }

        // Fallback: current folder name
        if (empty($prefix)) {
            $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
            $segments = explode('/', $scriptDir);
            $lastSegment = end($segments);
            if ($lastSegment && $lastSegment !== '') {
                $prefix = strtolower(preg_replace('/[^a-z0-9]/i', '', $lastSegment)) . '_';
            }
        }

        return $prefix ?: 'root_'; // Ultimate fallback
    }

    /**
     * Adds a new cookie or updates an existing one.
     * Stores data as JSON to keep track of expiration time server-side.
     *
     * @param string $name The name of the cookie.
     * @param mixed $value The value to set.
     * @param int $expireMinutes The expiration time in minutes. (Default: 0 for Session)
     * @return bool True on success, false on failure.
     */
    public static function add(string $name, $value, int $expireMinutes = 0): bool {
        $name = self::pre() . $name;
        $expireTime = ($expireMinutes > 0) ? time() + ($expireMinutes * 60) : 0;

        // To track expiration details later, we must store the expiry time INSIDE the value.
        // Browsers do not send expiry time back to PHP.
        $payload = json_encode([
            'v' => $value,       // Actual Value
            'e' => $expireTime   // Expiration Timestamp
        ]);

        // Modern PHP (7.3+) way to set cookies securely
        $options = [
            'expires'   => $expireTime,
            'path'      => self::path() ?? self::PATH,
            'domain'    => self::getDomainVariations()[0],
            'secure'    => self::isSecure(), 
            'httponly'  => self::HTTP_ONLY,
            'samesite'  => self::SAME_SITE
        ];
        return setcookie($name, $payload, $options);
    }

    /**
     * Updates a cookie. Since setcookie overwrites, this is an alias for add.
     * 
     * @param string $name
     * @param mixed $value
     * @param int $expireMinutes
     * @return bool
     */
    public static function update(string $name, $value, int $expireMinutes = 0): bool {
        return self::add($name, $value, $expireMinutes);
    }

    /**
     * Removes a cookie ONLY from the current project's path.
     * Does NOT touch root ('/') or other projects' cookies.
     * 
     * @param string $name The name of the cookie to remove.
     * @return bool True if removal was attempted successfully on at least one variation.
     */
    public static function remove(string $name): bool {
        // If cookie doesn't exist in current request → nothing to remove
        if (!self::exists($name)) {
            return false;
        }
        $name = self::pre() . $name;
        // Immediately unset from PHP's perspective
        unset($_COOKIE[$name]);

        // Get ONLY project-specific path (e.g., /projects/lakelandpost/)
        $projectPath = self::path() ?? self::PATH;

        // Domains: current host + optional dot version (for subdomain coverage)
        $domains = self::getDomainVariations();

        // Paths: ONLY project path + current script's directory (if different)
        // NO root '/' path — to avoid affecting other projects
        $paths = [$projectPath];
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
        if ($scriptDir !== $projectPath && $scriptDir !== '//') {
            $paths[] = $scriptDir;
        }
        $paths = array_unique($paths);

        // Expire options
        $options = [
            'expires'   => time() - 3600,          // Past time to expire
            'secure'    => self::isSecure(),
            'httponly'  => self::HTTP_ONLY,
            'samesite'  => self::SAME_SITE
        ];

        $success = false;

        foreach ($domains as $domain) {
            foreach ($paths as $path) {
                $opts = $options;
                $opts['domain'] = $domain;
                $opts['path']   = $path;

                // Try to expire the cookie
                if (setcookie($name, '', $opts)) {
                    $success = true;
                }
            }
        }

        // Optional: empty domain fallback (ONLY for project path)
        $opts = $options;
        $opts['domain'] = '';           // Some servers need this
        $opts['path']   = $projectPath;
        if (setcookie($name, '', $opts)) {
            $success = true;
        }

        return $success;
    }

    /**
     * Retrieves the ACTUAL value of the cookie.
     * Automatically decodes the JSON payload to ignore metadata.
     *
     * @param string $name
     * @return mixed|null
     */
    public static function get(string $name) {
        try {
            // Step 1: Apply prefix only if exists check passes
            $prefixedName = self::pre() . $name;

            // Step 2: Check if prefixed cookie exists
            if (isset($_COOKIE[$prefixedName])) {
                $payload = self::parsePayload($_COOKIE[$prefixedName]);
                return $payload['v'] ?? $_COOKIE[$prefixedName];
            }

            // Step 3: Fallback to non-prefixed (legacy support)
            if (isset($_COOKIE[$name])) {
                $payload = self::parsePayload($_COOKIE[$name]);
                return $payload['v'] ?? $_COOKIE[$name];
            }

            // Step 4: No cookie found
            return null;
        } catch (\Throwable $th) {
            // Silent fail in production
            return null;
        }
    }

    /**
     * Checks if a cookie exists.
     *
     * @param string $name
     * @return bool
     */
    public static function exists(string $name): bool {
        $name = self::pre() . $name;
        return isset($_COOKIE[$name]);
    }

    /**
     * Checks if a cookie has theoretically expired based on stored metadata.
     * Note: Browsers usually delete expired cookies automatically, so PHP rarely sees them.
     *
     * @param string $name
     * @return bool
     */
    public static function expired(string $name): bool {

        if (!self::exists($name)) {
            return true;
        }
        $name = self::pre() . $name;
        $payload = self::parsePayload($_COOKIE[$name]);
        
        // If 'e' (expiry) is 0, it is a session cookie, technically not expired until browser closes
        if (isset($payload['e']) && $payload['e'] > 0) {
            return time() > $payload['e'];
        }
        
        return false;
    }

    /**
     * Checks if a cookie is active.
     *
     * @param string $name
     * @return bool
     */
    public static function active(string $name): bool {
        return !self::expired($name);
    }

    /**
     * Retrieves remaining seconds until expiration.
     *
     * @param string $name
     * @return int|null Seconds remaining, or null if not exists/session cookie.
     */
    public static function getExpiredDetails(string $name): ?int {
        if (self::exists($name)) {
            $name = self::pre() . $name;
            $payload = self::parsePayload($_COOKIE[$name]);
            
            if (isset($payload['e']) && $payload['e'] > 0) {
                return $payload['e'] - time();
            }
        }
        return null;
    }

    /**
     * Forces a cookie to expire immediately.
     */
    public static function makeExpired(string $name): bool {
        return self::remove($name);
    }

    /**
     * Retrieves all cookies (decoded values).
     *
     * @return array
     */
    public static function getAll(): array {
        $cleanCookies = [];
        $prefix = self::pre();

        foreach ($_COOKIE as $name => $value) {
            if (str_starts_with($name, $prefix)) {
                $originalName = substr($name, strlen($prefix));
                $payload = self::parsePayload($value);
                $cleanCookies[$originalName] = $payload['v'] ?? $value;
            }
        }
        return $cleanCookies;
    }

    /**
     * Internal Helper to decode JSON payload safely.
     * Uses try-catch to handle any unexpected decoding issues gracefully.
     * 
     * @param string $cookieValue The raw cookie value
     * @return array Decoded payload or fallback array
     */
    private static function parsePayload(string $cookieValue): array {
        if (empty($cookieValue) || !is_string($cookieValue)) {
            return ['v' => $cookieValue, 'e' => 0];
        }
        try {
            $decoded = json_decode($cookieValue, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return $decoded;
            }
            return ['v' => $decoded, 'e' => 0];
        } 
        catch (\JsonException $e) {
            return ['v' => $cookieValue, 'e' => 0];
        }
        catch (\Throwable $e) {
            // throw new \Exception("PHCO: Unexpected error in parsePayload: " . $e->getMessage());
            return ['v' => $cookieValue, 'e' => 0];
        }
    }
}
?>