<?php

/**
 * ============================================================================
 * Class: PHRO
 * Title: Router & Web Application Firewall (WAF)
 * ============================================================================
 * 
 * Highly powerful, zero-dependency Routing Engine equipped with a military-grade Web Application Firewall (WAF). Protects against XSS, SQLi, CSRF, DDoS, and other attacks.
 * 
 * Features:
 * - Dynamic RESTful Routing (GET, POST, PUT, DELETE, etc.).
 * - Multi-layered Middleware Support.
 * - Comprehensive WAF: Rate Limiting, Open Redirect Shields, Honeypots.
 * - Cross-Site Request Forgery (CSRF) protection.
 * 
 * Usage Example:
 * ```php
 * PHRO::get('/', [HomeController::class, 'index']);
 * PHRO::guard(); // Enables Global Firewall
 * PHRO::listen();
 * ```
 * 
 * @package    MyStack
 * @author     Sakibur Rahman (@sakibweb)
 * @published  https://github.com/mystack-framework
 * @web        https://mystack-framework.github.io
 * @license    Apache License 2.0
 * ============================================================================
 */





// -----------------------------------------------------------------
// Security Guard System: Core Classes & Interfaces
// -----------------------------------------------------------------

class PhroSecurityException extends \Exception
{
    public function __construct(string $message = "", int $code = 403, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
interface PhroShield
{
    public function inspect(array $request_data, array $config);
}


class PhroGuard
{
    protected array $shields = []; // Contains instances of PhroShield
    protected array $config;
    protected array $request_data;

    public function __construct(array $config, array $request_data)
    {
        $this->config = $config;
        $this->request_data = $request_data;
        $this->registerDefaultShields();
    }

    protected function registerDefaultShields()
    {
        // Here, we create the instances and store them directly in $this->shields.
        // There is no need for a separate $shield_instances array if $this->shields is used consistently.
        $this->shields = [
            'content_type' => new PhroContentTypeShield(),
            'sql_injection' => new PhroSqlInjectionShield(),
            'xss' => new PhroXssShield(),
            'rate_limit' => new PhroRateLimitShield(),
            'attempt_shield' => new PhroAttemptShield(),
            'file_upload' => new PhroFileUploadShield(),
            'header' => new PhroHeaderInspectionShield(),
            'honeypot' => new PhroHoneypotShield(),
            'open_redirect' => new PhroOpenRedirectShield(),
            'csrf' => new PhroCsrfShield(),
        ];
    }

    /**
     * Adds a custom shield to the guard.
     *
     * @param string $key The unique identifier for the shield.
     * @param PhroShield $shield The instance of the shield.
     */
    public function addShield(string $key, PhroShield $shield)
    {
        $this->shields[$key] = $shield;
    }

    /**
     * Retrieves a shield instance by its key.
     * This is used by PHRO::attempt() to get the PhroAttemptShield instance.
     *
     * @param string $key The unique identifier for the shield.
     * @return PhroShield|null The shield instance, or null if not found.
     */
    public function getShield(string $key): ?PhroShield
    {
        return $this->shields[$key] ?? null;
    }

    /**
     * Removes a shield from the guard.
     *
     * @param string $key The unique identifier for the shield.
     */
    public function removeShield(string $key)
    {
        unset($this->shields[$key]);
    }

    /**
     * Executes all enabled shields to protect the application.
     *
     * @throws PhroSecurityException If any shield detects a threat.
     */
    public function protect()
    {
        foreach ($this->shields as $key => $shield) {
            // Only run shields that are explicitly enabled in the config.
            if (!empty($this->config[$key]) && ($this->config[$key]['enabled'] ?? false) === true) {
                // For 'attempt_shield', its 'inspect' method is not directly used for main protection.
                // Its logic is driven by PHRO::attempt() calls.
                // So, we only call inspect() for other active shields.
                // Or we can let inspect() be called for attempt_shield too if it has global rules.
                // For now, assuming attempt_shield's inspect() is empty or for internal setup.
                $shield->inspect($this->request_data, $this->config[$key]);
            }
        }
    }

    /**
     * Blocks the request immediately and displays a forbidden message.
     * This method directly terminates script execution.
     *
     * @param string $message The message to display.
     * @param int $code The HTTP status code.
     */
    public static function block(string $message = 'Forbidden', int $code = 403)
    {
        http_response_code($code);
        $log_message = sprintf(
            "PHRO Guard Blocked Request from IP %s: %s (URI: %s, User-Agent: %s)",
            $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
            $message,
            $_SERVER['REQUEST_URI'] ?? 'N/A',
            $_SERVER['HTTP_USER_AGENT'] ?? 'N/A'
        );
        error_log($log_message);
        die("<h1>{$code} Forbidden</h1><p>Your request has been blocked for security reasons.</p><!-- Threat Signature: " . base64_encode($message) . " -->");
    }
}


// -----------------------------------------------------------------
// Security Guard System: Individual Shields
// -----------------------------------------------------------------
class PhroContentTypeShield implements PhroShield
{
    public function inspect(array $request_data, array $config)
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // We only care about methods that typically have a body.
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return;
        }

        $content_type = $request_data['headers']['content-type'] ?? '';
        $allowed_types = $config['allowed'] ?? ['application/x-www-form-urlencoded', 'multipart/form-data', 'application/json'];

        // If a Content-Type is sent, it MUST be in the allowed list.
        if (!empty($content_type)) {
            $is_allowed = false;
            foreach ($allowed_types as $allowed) {
                if (stripos($content_type, $allowed) !== false) {
                    $is_allowed = true;
                    break;
                }
            }
            if (!$is_allowed) {
                throw new PhroSecurityException('Disallowed Content-Type: ' . htmlspecialchars($content_type));
            }
        }
    }
}




class PhroSqlInjectionShield implements PhroShield
{
    public function inspect(array $request_data, array $config)
    {
        // --- The Ultimate SQLi & Injection Pattern Collection v2 (with fixes) ---
        $patterns_with_scores = $config['patterns'] ?? [
            // === Level 1: High-Risk Terminators & Stacking ===
            '/((\/\*|\*\/)|--\s|;\s*(select|insert|update|delete|drop|begin|commit|rollback|declare|exec)\b)/i' => 5,
            '/(\w+\/\*.*\*\/|sel\/\*.*\*\/ect)/i' => 5,

            // === Level 2: Core SQL Keywords ===
            '/\b(select\s+.+?\s+from\s+\w+|insert\s+into\s+\w+|update\s+\w+\s+set\s+|delete\s+from\s+\w+|drop\s+table\s+\w+|union\s+all?\s+select)\b/i' => 5,
            '/\b(select\s+.+?\s+(order\s+by|group\s+by)\s+\w+)/i' => 3,
            '/\b(order\s+by\s+\d+|group\s+by\s+\d+)\b/i' => 4,

            // === Level 3: Blind Injection Techniques ===
            '/\b(or|and)\s+([0-9]+)\s*=\s*\2/i' => 5,
            '/\b(or|and)\s+(["\']).*?\2\s*=\s*\2.*?\2/i' => 5,
            '/\b(benchmark|sleep|pg_sleep|waitfor delay)\b/i' => 5,
            '/\b(if|case)\s*\(?.*\)?\s*then/i' => 3,

            // === Level 4: Information Gathering & System Functions ===
            '/\b(information_schema|pg_catalog|mysql\.user|sys\.user_tables)\b/i' => 5,
            '/\b(version\(|user\(|database\(|@@version|current_setting|load_file|outfile|dumpfile)\b/i' => 4,
            '/\b(db_name|schema_name|table_name|column_name)\b/i' => 4,

            // === Level 5: Obfuscation & Evasion ===
            '/\b(char|concat|ascii|hex|unhex|base64|cast|convert|substring|substr)\s*\(/i' => 3,
            '/(%u[0-9a-f]{4}|%25[0-9a-f]{2})/i' => 4,
            '/(?:and|or|select|from|where|=)\s*0x[0-9a-f]{4,}/i' => 4,

            // === Level 6: Database Specific & Error-Based ===
            '/\b(sp_configure|xp_cmdshell)\b/i' => 5,
            '/\b(extractvalue|updatexml)\s*\(/i' => 5,
            '/\b(geometrycollection|multipoint|polygon|multipolygon|linestring|multilinestring)\s*\(/i' => 5,

            // === Level 7: Other Injection Types ===
            '/((\$|%24)(ne|gt|gte|lt|lte|in|nin|where|regex))\s*:/i' => 4,
            '/(\|\||&&|`)\s*\w+/i' => 5,
            '/(\{\{.*?\}\}|\{\%.*?\%\}|<\%.*?\%>)/i' => 4,

            // === Level 8: Catch-All ===
            '/\d\s*=\s*\d/' => 2,
            '/\b(or|and)\s+\d+\s*=\s*\d+\b/i' => 5,
            '/\b(like)\s*(\'|").*?(\1|\s)/i' => 2,
        ];

        $threshold = $config['threshold'] ?? 5; // থ্রেশহোল্ড ৫ এ ফিরিয়ে আনা হলো, কারণ প্যাটার্নগুলো এখন আরও সুনির্দিষ্ট

        $data_to_scan = [
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'params' => $request_data['params'] ?? [],
            'data' => $request_data['data'] ?? [],
            'cookies' => $request_data['cookies'] ?? [],
        ];

        $total_score = 0;
        $matched_patterns = [];

        array_walk_recursive($data_to_scan, function ($value) use ($patterns_with_scores, &$total_score, &$matched_patterns) {
            if (is_string($value) && !empty($value)) {
                $normalized_value = $this->normalizeInput($value);
                $values_to_check = array_unique([urldecode($value), $normalized_value]);

                foreach ($values_to_check as $check_value) {
                    foreach ($patterns_with_scores as $pattern => $score) {
                        if (preg_match($pattern, $check_value) && !in_array($pattern, $matched_patterns)) {
                            $total_score += $score;
                            $matched_patterns[] = $pattern;
                        }
                    }
                }
            }
        });

        if ($total_score >= $threshold) {
            throw new PhroSecurityException(
                'Potential Injection detected. Risk score: ' . $total_score .
                ' (Threshold: ' . $threshold . '). Matched: ' . implode(', ', $matched_patterns)
            );
        }
    }

    /**
     * Normalizes input by decoding various encoding schemes (Unicode, etc.)
     * to reveal the underlying payload.
     */
    private function normalizeInput(string $value): string
    {
        $normalized = $value;
        // 1. Recursive URL decoding (aggressive)
        $previous = '';
        while ($normalized !== $previous) {
            $previous = $normalized;
            $normalized = rawurldecode($normalized);
        }

        // 2. Unicode decoding (%uXXXX)
        $normalized = preg_replace_callback('/%u([0-9a-f]{4})/i', static function ($match) {
            if (function_exists('mb_convert_encoding')) {
                return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
            }
            return html_entity_decode('&#x' . $match[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $normalized);

        // 3. HTML Entity decoding
        $normalized = html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 4. Lowercase and remove null bytes ONLY. We will let regex handle comments.
        $normalized = strtolower($normalized);
        $normalized = str_replace("\0", '', $normalized);

        return $normalized;
    }
}

class PhroXssShield implements PhroShield
{
    public function inspect(array $request_data, array $config)
    {
        // --- The Ultimate XSS Pattern Collection with Risk Scores ---
        // Score legend: 1-3 (Suspicious), 4-6 (Dangerous), 7+ (Highly Malicious)
        $patterns_with_scores = $config['patterns'] ?? [
            // Level 1: Suspicious but common JS keywords (low score to avoid false positives)
            '/(alert|confirm|prompt|document\.cookie|window\.location)\s*\(/i' => 2,
            '/(eval|setTimeout|setInterval)\s*\(/i' => 3,

            // Level 2: Dangerous protocols and data URIs
            '/(javascript|vbscript|data|file|php)\s*:/i' => 7,

            // Level 3: HTML Tags known for XSS vectors
            '/<(script|iframe|svg|object|embed|applet|video|audio|meta|link|base|form)\b/i' => 7,

            // Level 4: Dangerous HTML5 attributes and event handlers
            '/\s(on\w+|formaction|formmethod|autofocus|xlink:href)\s*=/i' => 7,

            // Level 5: Style-based and other attribute attacks
            '/(<[^>]+style\s*=\s*["\']?[^>]*?(expression|behavior)\()/i' => 8,
            '/@import/i' => 4,

            // Level 6: Encoding and Evasion Techniques
            '/(&#x[0-9a-f]+;?|&#\d+;?)/i' => 3, // HTML Entities
            '/(%u[0-9a-f]{4})/i' => 4, // Unicode encoding
            '/(\\\x[0-9a-f]{2})/i' => 4, // Hex encoding
            '/(fromcharcode|base64)/i' => 3, // Obfuscation keywords
        ];

        $threshold = $config['threshold'] ?? 8; // একটি উচ্চ থ্রেশহোল্ড, কারণ XSS প্যাটার্ন বৈধ HTML এও থাকতে পারে

        $data_to_scan = [
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'params' => $request_data['params'] ?? [],
            'data' => $request_data['data'] ?? [],
            'cookies' => $request_data['cookies'] ?? [],
        ];

        $total_score = 0;
        $matched_patterns = [];

        array_walk_recursive($data_to_scan, function ($value) use ($patterns_with_scores, &$total_score, &$matched_patterns) {
            if (is_string($value) && !empty($value)) {
                // Use the same advanced normalization as the SQLi shield
                $normalized_value = $this->normalizeInput($value);

                $values_to_check = array_unique([urldecode($value), $normalized_value]);

                foreach ($values_to_check as $check_value) {
                    foreach ($patterns_with_scores as $pattern => $score) {
                        if (preg_match($pattern, $check_value) && !in_array($pattern, $matched_patterns)) {
                            $total_score += $score;
                            $matched_patterns[] = $pattern;
                        }
                    }
                }
            }
        });

        if ($total_score >= $threshold) {
            throw new PhroSecurityException(
                'Potential XSS detected. Risk score: ' . $total_score .
                ' (Threshold: ' . $threshold . '). Matched: ' . implode(', ', $matched_patterns)
            );
        }
    }

    /**
     * Normalizes input by decoding various encoding schemes to reveal the underlying payload.
     * This method is crucial for defeating XSS evasion techniques.
     */
    private function normalizeInput(string $value): string
    {
        $normalized = $value;
        // 1. Recursive URL decoding
        while (rawurldecode($normalized) !== $normalized) {
            $normalized = rawurldecode($normalized);
        }

        // 2. HTML Entity decoding (numeric and named)
        $normalized = html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 3. Unicode decoding (%uXXXX)
        $normalized = preg_replace_callback('/%u([0-9a-f]{4})/i', static function ($match) {
            if (function_exists('mb_convert_encoding')) {
                return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
            }
            return html_entity_decode('&#x' . $match[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $normalized);

        // 4. Hex decoding (\xHH)
        $normalized = preg_replace_callback('/\\\\x([0-9a-f]{2})/i', fn($m) => chr(hexdec($m[1])), $normalized);

        // 5. Lowercase and remove common obfuscation characters (null bytes, comments, tabs, newlines)
        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[\s\r\n\t\0]+/', ' ', $normalized); // Replace all whitespace with a single space
        $normalized = str_replace(['/*', '*/'], '', $normalized); // Remove comments

        return $normalized;
    }
}


class PhroRateLimitShield implements PhroShield
{
    private static bool $storage_warning_logged = false;

    public function inspect(array $request_data, array $config)
    {
        // Ensure PHLS class is available
        if (!class_exists('PHLS')) {
            return; // Cannot perform rate limiting without PHLS
        }

        // --- Step 1: Adaptive Configuration for Multi-Tiered Limiting ---
        $default_config = [
            'fingerprint_requests' => 60,
            'fingerprint_minutes' => 1,
            'fingerprint_block_minutes' => 5,
            'session_requests' => 100,
            'session_minutes' => 2,
            'session_block_minutes' => 3,
            'ip_requests' => 200,
            'ip_minutes' => 5,
            'ip_block_minutes' => 1,
            'slowdown_us' => 500000,
            'graylist_threshold' => 3,
            'graylist_slowdown_us' => 100000,
        ];
        $config = array_replace_recursive($default_config, $config);

        // --- Step 2: Identify the client at multiple levels ---
        $client_ip = 'cli'; // Default for CLI or if no IP is found

        // 1. Get from PHRO::footprint() (most reliable, already processed through headers)
        if (class_exists('PHRO') && method_exists('PHRO', 'getCallbackContext')) {
            $context_vars = PHRO::getCallbackContext();
            $client_ip = $context_vars['clientIP'] ?? $context_vars['clientXIP'] ?? $client_ip;
        }

        // 2. Fallback: Check 'identity' cookie directly if PHRO context didn't provide it
        if ($client_ip === 'cli' && class_exists('PHCO') && class_exists('PHRO')) {
            $identity_cookie_payload = \PHCO::get("identity");
            if ($identity_cookie_payload) {
                $decrypted_identity = \PHRO::decrypt($identity_cookie_payload);
                $client_ip = $decrypted_identity['identity']['ip'] ?? $client_ip;
            }
        }

        // 3. Fallback: Check common proxy headers (if not already resolved by footprint())
        if ($client_ip === 'cli' || $client_ip === '127.0.0.1') {
            $client_ip = PHRO::getUserIP();
        }

        // 4. Validate IP (Optional, but good for robustness)
        if ($client_ip !== 'cli' && !filter_var($client_ip, FILTER_VALIDATE_IP)) {
            $client_ip = 'unknown_ip'; // Invalid IP, treat as a unique fallback
        }

        if ($client_ip === 'cli')
            return; // If still 'cli', then truly CLI or unknown.

        $stateless_hash = substr(hash(
            'sha256',
            ($client_ip === 'cli' ? '' : $client_ip) .
            ($_SERVER['HTTP_USER_AGENT'] ?? '') .
            ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
        ), 0, 16);

        $fingerprint_id = null;
        $session_id = null;

        // Resolve fingerprint_id (most robust)
        if (class_exists('PHRO') && method_exists('PHRO', 'getCallbackContext')) {
            $context_vars = PHRO::getCallbackContext();
            $fingerprint_id = PHCO::get("hash") ?? $decrypted_identity['identity']['id']['hash'] ??
                $decrypted_identity['identity']['id']['fingerprint'] ?? $decrypted_identity['identity']['id']['device'] ?? $stateless_hash ??
                $context_vars['clientFingerprint'] ?? $context_vars['clientXFingerprint'] ?? $context_vars['clientDevicekey'] ??
                $context_vars['clientXDevicekey'] ?? $context_vars['clientNetkey'] ?? $context_vars['clientXNetkey'] ?? null;
        }

        // Resolve session_id
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $session_id = session_id();
        }

        // --- Step 3: Prioritized Block & Limit Enforcement with Immediate Halt ---
        // This is the CORE FIX. The try-catch block here ensures that any exception from
        // checkAndEnforceLimit immediately halts the entire inspect() process.

        try {
            // Tier 1 (Strongest): Fingerprint-based Limiting
            if ($fingerprint_id) {
                $this->checkAndEnforceLimit(
                    'fp',
                    $fingerprint_id,
                    $config['fingerprint_requests'],
                    $config['fingerprint_minutes'],
                    $config['fingerprint_block_minutes'],
                    $config['slowdown_us'],
                    $config['graylist_threshold'],
                    $config['graylist_slowdown_us'],
                    'Your access has been blocked due to suspicious activity from this device.'
                );
            }

            // Tier 2 (Medium): Session-based Limiting
            if ($session_id) {
                $this->checkAndEnforceLimit(
                    'session',
                    $session_id,
                    $config['session_requests'],
                    $config['session_minutes'],
                    $config['session_block_minutes'],
                    $config['slowdown_us'] / 2,
                    $config['graylist_threshold'],
                    $config['graylist_slowdown_us'],
                    'Your session has been temporarily rate-limited.'
                );
            }

            // Tier 3 (Weakest): IP-based Limiting
            $this->checkAndEnforceLimit(
                'ip',
                $client_ip,
                $config['ip_requests'],
                $config['ip_minutes'],
                $config['ip_block_minutes'],
                $config['slowdown_us'] / 4,
                $config['graylist_threshold'],
                $config['graylist_slowdown_us'],
                'Unusual network activity detected from your IP. Access temporarily restricted.'
            );

        } catch (PhroSecurityException $e) {
            // If any tier throws a PhroSecurityException, it means the client is blocked/rate-limited.
            // Re-throw the exception to halt the entire request processing immediately.
            throw $e;
        }
    }

    /**
     * Helper method to apply rate limiting logic for a specific tier.
     * This method now handles both block checks AND limit enforcement.
     */
    private function checkAndEnforceLimit(string $tier_name, string $client_id, int $max_requests, int $time_window_minutes, int $block_duration_minutes, int $slowdown_us, int $graylist_threshold, int $graylist_slowdown_us, string $block_message): void
    {
        $log_key = "rl_log_{$tier_name}_" . $client_id;
        $block_key = "rl_blocked_{$tier_name}_" . $client_id;
        $graylist_count_key = "rl_graylist_count_{$tier_name}_" . $client_id;

        try {
        // First, check if client is already in the hard block.
        if (PHLS::get($block_key) !== null) {
            usleep($slowdown_us); // Apply corresponding tier's slowdown
            throw new PhroSecurityException($block_message, 429);
        }

        // Then, log the current request and check if limit is exceeded.
        if (!PHLS::limitizer($log_key, time(), $max_requests + 1, $time_window_minutes + 1)) {
            return;
        }
        $requests_timestamps = PHLS::get($log_key);

        if (!is_array($requests_timestamps)) {
            return; // Unexpected state, just return. (No exception to allow other tiers to run if this is faulty)
        }

        if (count($requests_timestamps) > $max_requests) {
            $oldest_request_time = end($requests_timestamps);
            $latest_request_time = $requests_timestamps[0];
            $time_span = $latest_request_time - $oldest_request_time;

            if ($time_span < ($time_window_minutes * 60)) {
                // Rate limit exceeded for this tier! Apply block.
                PHLS::add($block_key, time(), $block_duration_minutes);
                PHLS::remove($log_key); // Clear log for blocked client

                // Increment graylist counter (persists longer)
                $block_count = PHLS::increment(
                    $graylist_count_key,
                    1,
                    $block_duration_minutes * $graylist_threshold * 2
                );

                usleep($slowdown_us); // Apply configured slowdown
                throw new PhroSecurityException($block_message); // Halt immediately
            }
        } else {
            // If client is not blocked but is on graylist, apply a minor slowdown even if not fully blocked yet.
            $block_count = PHLS::get($graylist_count_key) ?? 0;
            if ($block_count > 0 && $block_count >= $graylist_threshold) {
                usleep($graylist_slowdown_us);
            }
        }
        } catch (PhroSecurityException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Storage contention must never turn the protection layer into a site-wide outage.
            if (!self::$storage_warning_logged) {
                self::$storage_warning_logged = true;
                error_log('PHRO rate-limit storage was temporarily unavailable: ' . $e->getMessage());
            }
            return;
        }
    }
}


class PhroAttemptShield implements PhroShield
{
    // --- Internal Client Resolver (Stateless & Deterministic) ---
    private function resolveClientIdentifiers(): array
    {
        $client_ip = 'cli';
        $fingerprint_id = null;
        $session_id = null;

        // 1. IP Resolution (Robust)
        if (class_exists('PHRO') && method_exists('PHRO', 'getCallbackContext')) {
            $context_vars = PHRO::getCallbackContext();
            $client_ip = $context_vars['clientIP'] ?? $context_vars['clientXIP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'cli';
            $identity_cookie_payload = \PHCO::get("identity");
            if ($identity_cookie_payload) {
                $decrypted_identity = \PHRO::decrypt($identity_cookie_payload);
                $client_ip = $decrypted_identity['identity']['ip'] ?? $client_ip;
            }
            $stateless_hash = substr(hash(
                'sha256',
                ($client_ip === 'cli' ? '' : $client_ip) .
                ($_SERVER['HTTP_USER_AGENT'] ?? '') .
                ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
            ), 0, 16);
            // Try to get fingerprint from cookie-backed context
            $fingerprint_id = PHCO::get("hash") ?? $decrypted_identity['identity']['id']['hash'] ??
                $decrypted_identity['identity']['id']['fingerprint'] ?? $decrypted_identity['identity']['id']['device'] ?? $stateless_hash ??
                $context_vars['clientFingerprint'] ?? $context_vars['clientXFingerprint'] ?? $context_vars['clientDevicekey'] ??
                $context_vars['clientXDevicekey'] ?? $context_vars['clientNetkey'] ?? $context_vars['clientXNetkey'] ?? null;
        }

        if ($client_ip === 'cli' || $client_ip === '127.0.0.1') {
            $client_ip = PHRO::getUserIP();
        }
        if ($client_ip !== 'cli' && !filter_var($client_ip, FILTER_VALIDATE_IP)) {
            $client_ip = 'unknown_ip';
        }

        // 2. Session Resolution
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE)
            $session_id = session_id();

        // 3. ★★★ THE CRITICAL FIX: Deterministic Stateless Fingerprint ★★★
        // We MUST NOT use uniqid(). We need an ID that stays the same even if cookies are disabled (Private Mode).
        $stateless_hash = substr(hash(
            'sha256',
            ($client_ip === 'cli' ? '' : $client_ip) .
            ($_SERVER['HTTP_USER_AGENT'] ?? '') .
            ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
        ), 0, 16);

        // If fingerprint from cookie is empty, use the stable stateless hash
        if (empty($fingerprint_id)) {
            $fingerprint_id = $stateless_hash;
        }

        // --- Determine the SINGLE MOST RELIABLE IDENTIFIER ---
        $final_identifier = null;

        // Priority 1: Fingerprint (Either from cookie or our deterministic stateless hash)
        if (!empty($fingerprint_id)) {
            $final_identifier = ['type' => 'fp', 'id' => $fingerprint_id];
        }
        // Priority 2: Session
        elseif (!empty($session_id)) {
            $final_identifier = ['type' => 'session', 'id' => $session_id];
        }
        // Priority 3: IP
        elseif ($client_ip !== 'cli' && $client_ip !== 'unknown_ip') {
            $final_identifier = ['type' => 'ip', 'id' => $client_ip];
        }
        // Priority 4: Ultimate Fallback (Should rarely happen now)
        else {
            $final_identifier = ['type' => 'ip', 'id' => 'generic_client_' . $stateless_hash];
        }

        return [$final_identifier];
    }


    public function inspect(array $request_data, array $config)
    {
    }

    public function checkAndIncrementAttempt(
        string $event_name,
        int $max_attempts,
        int $block_duration_minutes,
        int $reset_after_minutes,
        string $block_message
    ): array {
        if (!class_exists('PHLS')) {
            return ['attempts' => 0, 'remaining' => $max_attempts, 'status' => 'disabled'];
        }

        $identifiers = $this->resolveClientIdentifiers();
        $identifier = $identifiers[0]; // Take the single most reliable identifier

        $tier_name = $identifier['type'];
        $client_id = $identifier['id'];

        $attempt_key = "att_log_{$event_name}_{$tier_name}_{$client_id}";
        $block_key = "att_blocked_{$event_name}_{$tier_name}_{$client_id}";

        // 1. Check if ALREADY BLOCKED
        if (PHLS::get($block_key) !== null) {
            throw new PhroSecurityException($block_message, 429);
        }

        // 2. Atomically increment so concurrent failed attempts cannot overwrite each other.
        $new_attempts = PHLS::increment($attempt_key, 1, $reset_after_minutes);

        // 4. Check if THIS attempt caused a block
        if ($new_attempts >= $max_attempts) {
            PHLS::add($block_key, time(), $block_duration_minutes); // Block this tier
            PHLS::remove($attempt_key); // Clear attempts log for blocked tier
            throw new PhroSecurityException($block_message, 429); // Halt immediately
        }

        return ['attempts' => $new_attempts, 'remaining' => max(0, $max_attempts - $new_attempts), 'status' => 'active'];
    }

    public function performAttemptReset(string $event_name): void
    {
        if (!class_exists('PHLS'))
            return;

        $identifiers = $this->resolveClientIdentifiers();
        $identifier = $identifiers[0];

        $tier_name = $identifier['type'];
        $client_id = $identifier['id'];
        $attempt_key = "att_log_{$event_name}_{$tier_name}_{$client_id}";
        $block_key = "att_blocked_{$event_name}_{$tier_name}_{$client_id}";

        PHLS::remove($attempt_key);
        PHLS::remove($block_key);
    }
}

class PhroFileUploadShield implements PhroShield
{
    public function inspect(array $request_data, array $config)
    {
        // Step 1: Check if file uploads are globally enabled in the config.
        if (($config['uploads_enabled'] ?? true) === false) {
            if (!empty($_FILES)) {
                // Block if uploads are disabled but a file was sent.
                throw new PhroSecurityException('File uploads are disabled on this server.');
            }
            return; // Uploads are disabled, and no files were sent, so we're good.
        }

        // No files uploaded, nothing to inspect.
        if (empty($_FILES)) {
            return;
        }

        // Step 2: Define default allowed MIME types.
        $default_mimes = [
            // Images
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            // Videos
            'video/mp4',
            'video/webm',
            'video/ogg',
            // Audio
            'audio/mpeg',
            'audio/ogg',
            'audio/wav',
            // Documents
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv'
        ];

        // Step 3: Get configuration, with fallbacks to defaults.
        $allowed_mimes = $config['allowed_mimes'] ?? $default_mimes;
        $max_size_kb = $config['max_size_kb'] ?? 5120; // Default 5MB

        // Step 4: Iterate through all uploaded files via $_FILES.
        foreach ($_FILES as $file_input_name => $file_info) {
            // First, check for any upload errors reported by PHP.
            if (is_array($file_info['error'])) {
                foreach ($file_info['error'] as $error_code) {
                    if ($error_code > UPLOAD_ERR_OK)
                        throw new PhroSecurityException('An error occurred during file upload (Code: ' . $error_code . ').');
                }
            } else {
                if ($file_info['error'] > UPLOAD_ERR_OK)
                    throw new PhroSecurityException('An error occurred during file upload (Code: ' . $file_info['error'] . ').');
            }

            // This structure handles both single file uploads (e.g., <input type="file" name="avatar">)
            // and multiple file uploads (e.g., <input type="file" name="docs[]" multiple>).
            if (is_array($file_info['name'])) {
                for ($i = 0; $i < count($file_info['name']); $i++) {
                    if ($file_info['error'][$i] === UPLOAD_ERR_OK) {
                        $this->validateFile(
                            $file_info['tmp_name'][$i],
                            $file_info['size'][$i],
                            $allowed_mimes,
                            $max_size_kb
                        );
                    }
                }
            } else {
                if ($file_info['error'] === UPLOAD_ERR_OK) {
                    $this->validateFile(
                        $file_info['tmp_name'],
                        $file_info['size'],
                        $allowed_mimes,
                        $max_size_kb
                    );
                }
            }
        }
    }

    private function validateFile($tmp_name, $size, $allowed_mimes, $max_size_kb)
    {
        // 1. Size Check
        if (($size / 1024) > $max_size_kb) {
            throw new PhroSecurityException('Uploaded file exceeds the maximum allowed size of ' . $max_size_kb . ' KB.');
        }

        // 2. MIME Type Check (more reliable than extension)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp_name);
        if (!empty($allowed_mimes) && !in_array($mime, $allowed_mimes, true)) {
            throw new PhroSecurityException('The file type (' . $mime . ') is not allowed.');
        }

        // 3. Content Sniffing for malicious code (Full Malware Scan)
        $content = file_get_contents($tmp_name);

        // Advanced Malware/Webshell Patterns
        $malware_patterns = [
            '/<(\?php|script)/i', // PHP or script tags
            '/(eval|system|passthru|shell_exec|exec|popen|proc_open|pcntl_exec)\s*\(/i', // Dangerous functions
            '/(base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i', // Obfuscation functions
            '/(fsockopen|pfsockopen|stream_socket_client)\s*\(/i', // Remote connection functions
            '/(\$_POST\[.+\]\s*\(\s*\$_POST\[.+\]\s*\))/i', // Classic one-liner webshell
            '/(c99shell|r57shell|phpspy|pr!ncess_sh3ll)/i' // Known webshell names
        ];

        foreach ($malware_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                throw new PhroSecurityException('Potential malicious code (webshell) detected in the uploaded file.');
            }
        }
    }
}

class PhroHeaderInspectionShield implements PhroShield
{
    public function inspect(array $request_data, array $config)
    {
        $headers = $request_data['headers'] ?? [];
        $user_agent = $headers['user-agent'] ?? '';
        if (!empty($config['banned_user_agents'])) {
            foreach ($config['banned_user_agents'] as $banned) {
                if (stripos($user_agent, $banned) !== false)
                    throw new PhroSecurityException('Banned user agent.');
            }
        }
        if (!empty($config['required_headers'])) {
            foreach ($config['required_headers'] as $header) {
                if (!isset($headers[strtolower($header)]))
                    throw new PhroSecurityException("Required header '{$header}' missing.");
            }
        }
    }
}

class PhroHoneypotShield implements PhroShield
{
    public function inspect(array $request_data, array $config)
    {
        $field_name = $config['field_name'] ?? 'honeypot_email';
        if (isset($request_data['data'][$field_name]) && !empty($request_data['data'][$field_name])) {
            throw new PhroSecurityException('Honeypot triggered. Request appears to be from a bot.');
        }
    }
}

class PhroOpenRedirectShield implements PhroShield
{
    public function inspect(array $request_data, array $config)
    {
        $param_name = $config['param_name'] ?? 'redirect_url';
        if (isset($request_data['data'][$param_name])) {
            $url = $request_data['data'][$param_name];
            $host = parse_url($url, PHP_URL_HOST);
            $allowed_hosts = $config['allowed_hosts'] ?? [$_SERVER['HTTP_HOST']];
            if ($host && !in_array($host, $allowed_hosts)) {
                throw new PhroSecurityException('Open redirect attempt detected.');
            }
        }
    }
}

class PhroCsrfShield implements PhroShield
{
    public function inspect(array $request_data, array $config)
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return;
        }

        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        foreach ((array) ($config['except'] ?? []) as $excludedPath) {
            $pattern = '#^' . str_replace('\*', '.*', preg_quote((string) $excludedPath, '#')) . '$#';
            if (preg_match($pattern, $requestPath)) {
                return;
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $session_token = $_SESSION['csrf_token'] ?? null;
        if (!$session_token) {
            throw new PhroSecurityException('CSRF token missing or session expired.');
        }

        $request_token = $request_data['data']['csrf_token'] ?? 
                         $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 
                         $_SERVER['HTTP_X_XSRF_TOKEN'] ?? null;

        if (!$request_token || !hash_equals($session_token, $request_token)) {
            throw new PhroSecurityException('CSRF token mismatch.');
        }
    }
}

// -----------------------------------------------------------------
// The Main PHRO Router Class
// -----------------------------------------------------------------



// -----------------------------------------------------------------
// Real-Time Channel Engine (Pub/Sub)
// -----------------------------------------------------------------
class PhroChannel
{
    private string $channel_id;
    private $auth_callback = null;
    private array $workers = [];
    private float $last_check_time = 0;

    public function __construct(string $channel_id)
    {
        $this->channel_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $channel_id);
    }

    public function authorize(callable $callback): self
    {
        $this->auth_callback = $callback;
        return $this;
    }

    public function worker(string $name, callable $handler, int $max_runs = 0, int $interval_seconds = 0): self
    {
        $this->workers[$name] = [
            'handler' => $handler,
            'max_runs' => $max_runs,
            'interval' => $interval_seconds,
            'run_count' => 0,
            'last_run' => 0
        ];
        return $this;
    }

    public function listen(): void
    {
        if (is_callable($this->auth_callback)) {
            if (!call_user_func($this->auth_callback, $this->channel_id)) {
                http_response_code(403);
                echo "403 Forbidden: Not authorized for this channel.";
                exit;
            }
        }

        if (!class_exists('PHLS'))
            die("PHLS class is required for PhroChannel.");

        $this->last_check_time = microtime(true);
        $queue_key = "phro_chan_" . $this->channel_id;

        \PHRO::stream(function (&$stream_active) use ($queue_key) {
            $events_to_send = [];
            $current_time = microtime(true);

            // --- A. Process Incoming Commands ---
            $queue = \PHLS::get($queue_key) ?: [];
            $has_new_messages = false;

            foreach ($queue as $msg) {
                if (isset($msg['t']) && $msg['t'] > $this->last_check_time) {
                    $cmd_name = $msg['cmd'];
                    $payload = $msg['data'] ?? null;

                    // 1. BUILT-IN DESTROY COMMAND
                    // If someone publishes a 'destroy_channel' command, we kill it instantly!
                    if ($cmd_name === 'destroy_channel') {
                        $stream_active = false;
                        return [['event' => 'system', 'data' => 'Connection closed by server.']];
                    }

                    if (isset($this->workers[$cmd_name])) {
                        $worker = &$this->workers[$cmd_name];
                        if ($worker['max_runs'] === 0 || $worker['run_count'] < $worker['max_runs']) {
                            try {
                                // We also pass $stream_active to the user's worker so they can kill it
                                $result = call_user_func($worker['handler'], $payload, $this->channel_id, $stream_active);
                                if ($result !== null) {
                                    $events_to_send[] = ['event' => $cmd_name, 'data' => $result];
                                }
                                $worker['run_count']++;
                                $worker['last_run'] = $current_time;
                            } catch (\Throwable $e) {
                                error_log("Channel [{$this->channel_id}] Worker Error: " . $e->getMessage());
                            }
                        }
                    }
                    $this->last_check_time = max($this->last_check_time, $msg['t']);
                    $has_new_messages = true;
                }
            }

            if ($has_new_messages && count($queue) > 20) {
                $recent_queue = array_slice($queue, -20);
                \PHLS::add($queue_key, $recent_queue, 60);
            }

            // --- B. Process Auto-Interval Workers ---
            // If the stream was destroyed by a command above, skip intervals
            if ($stream_active) {
                foreach ($this->workers as $name => &$worker) {
                    if ($worker['interval'] > 0 && ($worker['max_runs'] === 0 || $worker['run_count'] < $worker['max_runs'])) {
                        if (($current_time - $worker['last_run']) >= $worker['interval']) {
                            try {
                                // Pass $stream_active here too
                                $result = call_user_func($worker['handler'], null, $this->channel_id, $stream_active);
                                if ($result !== null) {
                                    $events_to_send[] = ['event' => $name, 'data' => $result];
                                }
                                $worker['run_count']++;
                                $worker['last_run'] = $current_time;
                            } catch (\Throwable $e) {
                                error_log("Channel Interval Worker Error: " . $e->getMessage());
                            }
                        }
                    }
                }
            }

            return empty($events_to_send) ? null : $events_to_send;
        });
    }
}


/**
 * PHRO is PHP Route / Router Library
 * A PHP library for defining and handling HTTP routes in PHP applications.
 */
class PHRO
{

    /**
     * Flag to check if initialization has been done.
     * @var bool
     */
    private static $initialized = false;

    /**
     * Base path of the application. Can be auto-detected or manually set.
     * @var string
     */
    private static $base_path = '';

    /**
     * Array to store server URL segments.
     * @var array
     */
    private static $server_url = [];

    /**
     * HTTP request method.
     * @var string
     */
    private static $server_method;

    /**
     * Callback function to execute when a route is matched.
     * @var callable
     */
    private static $callback;

    /**
     * Flag indicating whether a route has been matched.
     * @var bool
     */
    private static $matched = false;

    /**
     * Array to store matched route.
     * @var array
     */
    private static $matched_route = [];

    /**
     * Array to store URL parameters.
     * @var array
     */
    private static $params = [];

    /**
     * Static cache for footprint data within a single request.
     * @var array|null
     */
    private static $footprint_cache = null;

    /**
     * Footprint init.
     * @var bool
     */
    private static $footprint = false;

    /**
     * Cross-request cache for Geo-IP tracker data.
     */
    private const GEO_CACHE_TTL = 21600;
    private const GEO_FAILURE_CACHE_TTL = 300;

    /**
     * Regular expression pattern to trim URL segments.
     * @var string
     */
    private static $trim = '/\^$/';

    /**
     * Default home URL for routes.
     * @var string
     */
    private static $default_home_url;

    /**
     * Stores all named routes for quick URL generation.
     * @var array
     */
    private static array $named_routes = [];

    /**
     * Stores the key of the most recently added route.
     * @var int|null
     */
    private static $last_route_key = null;

    /**
     * @var array A stack to hold group properties (prefix, middleware).
     */
    private static array $group_stack = [];

    /**
     * Array to store all defined routes.
     * @var array
     */
    private static $routes = [];

    /**
     * Secret key for encription.
     * **IMPORTANT:** Change this to a strong, unique, and secret key for production!
     * @var string
     */
    private static $key = 'YOUR_VERY_SECRET_KEY_CHANGE_ME_MIN_18_CHARS';


    /**
     * Encription data print.
     * @var bool
     */
    private static $printData = false;


    /**
     * Guard configuration.
     * @var array|null
     */
    private static $guard_config = null;

    /**
     * Guard activation flag.
     * @var bool
     */
    private static $guard_activated = false;

    /**
     * 
     */
    private static $guard_instance = null;

    /** @var bool Whether the MCP transport endpoint should be registered. */
    private static bool $mcp_enabled = false;

    /** @var array IP addresses of reverse proxies whose forwarding headers are trusted. */
    private static array $trusted_proxies = [];

    /**
     * Array to store middlewares.
     * @var array
     */
    private static $middlewares = [];

    /**
     * Array to store route-specific middlewares.
     * @var array
     */
    private static $route_middlewares = [];


    /**
     * A temporary storage for variables passed to the route callback.
     * This allows the import() function to access them without changing its call signature.
     * @var array
     */
    private static $callback_context_vars = [];


    /**
     * Stores the configuration for the web app manifest.
     * @var array|null
     */
    private static $manifest_config = null;

    /**
     * Stores custom URLs to be added to the sitemap.
     * @var array
     */
    private static $sitemap_custom_entries = [];


    /**
     * Stores custom rules for the robots.txt file.
     * @var array
     */
    private static $robots_custom_rules = [];


    /**
     * Transforms specified array keys by prepending 'client' and capitalizing,
     * ensuring consistent client-side data representation.
     *
     * @param array $data The array to transform.
     * @param array $keysToTransform Keys to be transformed.
     * @return array Transformed array.
     */
    private static function transformKeys(array $data, array $keysToTransform): array
    {
        $transformedData = [];
        foreach ($data as $key => $value) {
            // Only transform if the key is in the list AND it's not already transformed.
            if (in_array($key, $keysToTransform) && strpos($key, 'client') !== 0) {
                // Skip special internal keys if they come through here
                if ($key === 'query' || $key === 'status' || $key === 'ip') {
                    continue;
                }
                $transformedKey = 'client' . ucfirst($key);
                $transformedData[$transformedKey] = $value;
            } else {
                // Keep already transformed keys or non-transformable keys as is.
                $transformedData[$key] = $value;
            }
        }
        return $transformedData;
    }


    /**
     * Initializes the router. This can be called manually to set a custom base path,
     * or it will be called automatically on the first routing call.
     *
     * @param string|null $custom_base_path Manually set the base path. If null, it will be auto-detected.
     * @return void
     */
    public static function initialize($custom_base_path = null)
    {
        // Prevent re-initialization
        if (self::$initialized) {
            return;
        }

        // If a custom path is provided, use it. Otherwise, auto-detect.
        if ($custom_base_path !== null) {
            self::$base_path = rtrim($custom_base_path, '/') . '/';
        } else {
            self::$base_path = self::autoDetectBasePath();
        }

        $url = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        self::$server_method = strtolower((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // Remove query string from URL (e.g., ?a=b)
        if (strpos($url, '?') !== false) {
            $url = substr($url, 0, strpos($url, '?'));
        }

        $url_path = urldecode(trim($url, '/')); // Use urldecode for paths with spaces etc.
        $base_path_trimmed = trim(self::$base_path, '/');

        // Remove the base path from the start of the URL path
        if ($base_path_trimmed && strpos($url_path, $base_path_trimmed) === 0) {
            $url_path = trim(substr($url_path, strlen($base_path_trimmed)), '/');
        }

        // Handle the root path ("/") case specifically
        if ($url_path === '') {
            self::$server_url = ['/']; // Use a special identifier for the root
        } else {
            self::$server_url = explode('/', $url_path);
        }

        self::$footprint_cache = null;
        self::$initialized = true; // Mark as initialized
    }

    /**
     * Configures and enables the security guard (WAF).
     * This must be called before any routes are defined.
     *
     * @param array $config The configuration for the guard and its shields.
     */
    public static function guard(array $config = [])
    {
        if (self::$initialized) {
            trigger_error("PHRO::guard() must be called before any routes are defined.", E_USER_WARNING);
            return;
        }
        $default_config = [
            'content_type' => ['enabled' => true, 'allowed' => ['application/x-www-form-urlencoded', 'multipart/form-data', 'application/json']],
            'on_threat' => ['action' => 'block'],
            'sql_injection' => ['enabled' => true],
            'xss' => ['enabled' => true],
            'rate_limit' => ['enabled' => true, 'fingerprint_requests' => 60, 'fingerprint_minutes' => 1, 'fingerprint_block_minutes' => 5, 'session_requests' => 100, 'session_minutes' => 2, 'session_block_minutes' => 3, 'ip_requests' => 200, 'ip_minutes' => 5, 'ip_block_minutes' => 1, 'slowdown_us' => 500000, 'graylist_threshold' => 3, 'graylist_slowdown_us' => 100000],
            'attempt_shield' => ['enabled' => true, 'max_attempts' => 5, 'block_duration_minutes' => 15, 'reset_after_minutes' => 30],
            'file_upload' => ['enabled' => true, 'uploads_enabled' => true, 'allowed_mimes' => null, 'max_size_kb' => 5120],
            'header' => ['enabled' => true, 'banned_user_agents' => ['sqlmap', 'nmap', 'nikto', 'badbot']],
            'honeypot' => ['enabled' => true, 'field_name' => 'user_email_confirm'],
            'open_redirect' => ['enabled' => true, 'allowed_hosts' => null],
            'csrf' => ['enabled' => true, 'except' => []],
            // Safe same-host reverse-proxy defaults. Cloudflare requests are
            // additionally recognized by isTrustedProxyRequest().
            'trusted_proxies' => ['127.0.0.1', '::1'],
            'session' => [
                'strict_mode' => true,
                'cache_limiter' => '',
                'name' => null,
                'lifetime' => 0,
                'path' => 'auto',
                'secure' => 'auto',
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ];
        $trustedProxyConfig = array_key_exists('trusted_proxies', $config)
            ? (array) $config['trusted_proxies']
            : $default_config['trusted_proxies'];
        self::$guard_config = empty($config) ? $default_config : array_replace_recursive($default_config, $config);
        self::$guard_config['trusted_proxies'] = $trustedProxyConfig;
        self::trustProxies((array) (self::$guard_config['trusted_proxies'] ?? []));
        self::configureGuardSession((array) (self::$guard_config['session'] ?? []));

        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }

        if ((self::$guard_config['csrf']['enabled'] ?? false) === true) {
            self::ensureCsrfToken();
        }
    }

    /**
     * Apply secure, portable session defaults before CSRF starts the session.
     */
    private static function configureGuardSession(array $config): void
    {
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return;
        }

        ini_set('session.use_strict_mode', !empty($config['strict_mode']) ? '1' : '0');
        session_cache_limiter((string) ($config['cache_limiter'] ?? ''));

        $name = trim((string) ($config['name'] ?? ''));
        if ($name === '') {
            $name = (class_exists('PHCO') ? PHCO::pre() : 'mystack_') . 'session';
        }
        session_name($name);

        $path = $config['path'] ?? 'auto';
        if ($path === 'auto' || !is_string($path) || $path === '') {
            $path = self::autoDetectBasePath();
        }
        $path = '/' . trim((string) $path, '/') . '/';
        if ($path === '//') {
            $path = '/';
        }

        $secure = $config['secure'] ?? 'auto';
        if ($secure === 'auto') {
            $secure = self::isSecureRequest();
        } else {
            $secure = (bool) $secure;
        }

        $sameSite = ucfirst(strtolower((string) ($config['samesite'] ?? 'Lax')));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }
        if ($sameSite === 'None') {
            $secure = true;
        }

        session_set_cookie_params([
            'lifetime' => max(0, (int) ($config['lifetime'] ?? 0)),
            'path' => $path,
            'secure' => $secure,
            'httponly' => (bool) ($config['httponly'] ?? true),
            'samesite' => $sameSite,
        ]);
    }

    private static function isSecureRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if (!self::isTrustedProxyRequest()) {
            return false;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        if (stripos((string) ($_SERVER['HTTP_FORWARDED'] ?? ''), 'proto=https') !== false) {
            return true;
        }
        $visitor = json_decode((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
        return is_array($visitor) && ($visitor['scheme'] ?? null) === 'https';
    }

    /**
     * Public proxy-aware HTTPS check for framework components such as PHCO.
     */
    public static function secure(): bool
    {
        return self::isSecureRequest();
    }

    /**
     * Return the session CSRF token, creating it when necessary.
     */
    public static function getToken(): string
    {
        return self::ensureCsrfToken();
    }

    /**
     * Return a ready-to-render hidden CSRF field.
     */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' .
            htmlspecialchars(self::getToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Rotate and return the current session CSRF token.
     */
    public static function regenerateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Unable to start a session for CSRF protection.');
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    private static function ensureCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Unable to start a session for CSRF protection.');
        }
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Trust forwarding headers only from these reverse-proxy IP addresses.
     */
    public static function trustProxies(array $ipAddresses): void
    {
        self::$trusted_proxies = array_values(array_unique(array_filter(
            array_map('trim', $ipAddresses),
            static fn($ip) => self::isValidProxyRule($ip)
        )));
    }

    private static function isTrustedProxyRequest(): bool
    {
        $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach (self::$trusted_proxies as $proxyRule) {
            if (self::ipMatchesRule($remoteAddress, $proxyRule)) {
                return true;
            }
        }

        return self::isCloudflareProxyRequest();
    }

    private static function isCloudflareProxyRequest(): bool
    {
        $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        // Cloudflare headers are trusted only when the direct peer is inside
        // an official Cloudflare network. Header names alone are spoofable
        // when an origin server is reachable directly.
        if (empty($_SERVER['HTTP_CF_RAY']) ||
            empty($_SERVER['HTTP_CF_CONNECTING_IP']) ||
            filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach (self::cloudflareProxyRanges() as $proxyRule) {
            if (self::ipMatchesRule($remoteAddress, $proxyRule)) {
                return true;
            }
        }
        return false;
    }

    private static function cloudflareProxyRanges(): array
    {
        return [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
            '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
            '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
            '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
            '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
            '2c0f:f248::/32',
        ];
    }

    private static function isTrustedProxyAddress(string $address): bool
    {
        foreach (array_merge(self::$trusted_proxies, self::cloudflareProxyRanges()) as $rule) {
            if (self::ipMatchesRule($address, $rule)) return true;
        }
        return false;
    }

    private static function isValidProxyRule(string $rule): bool
    {
        if (filter_var($rule, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (!str_contains($rule, '/')) {
            return false;
        }
        [$network, $prefix] = explode('/', $rule, 2);
        if (filter_var($network, FILTER_VALIDATE_IP) === false || !ctype_digit($prefix)) {
            return false;
        }
        $packed = @inet_pton($network);
        return $packed !== false && (int) $prefix >= 0 && (int) $prefix <= strlen($packed) * 8;
    }

    private static function ipMatchesRule(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return hash_equals($rule, $ip);
        }
        [$network, $prefixText] = explode('/', $rule, 2);
        $ipBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefix = (int) $prefixText;
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }

    /**
     * Auto-detects the base path of the application using multiple strategies
     * for maximum compatibility across different server environments.
     *
     * @return string The auto-detected base path.
     */
    private static function autoDetectBasePath()
    {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        // Some front-controller servers set SCRIPT_NAME to the requested asset
        // (for example /app.js). In that case derive the application directory
        // from the actual entry script and the document root instead.
        if (strtolower(pathinfo($scriptName, PATHINFO_EXTENSION)) !== 'php') {
            $scriptFile = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
            $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
            if ($scriptFile !== false && $documentRoot !== false) {
                $normalizedFile = str_replace('\\', '/', $scriptFile);
                $normalizedRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
                $prefix = $normalizedRoot . '/';
                if (str_starts_with(strtolower($normalizedFile), strtolower($prefix))) {
                    $relativeScript = substr($normalizedFile, strlen($prefix));
                    $scriptName = '/' . ltrim($relativeScript, '/');
                }
            }
        }

        if (strtolower(pathinfo($scriptName, PATHINFO_EXTENSION)) !== 'php') {
            return '/';
        }

        $basePath = str_replace('\\', '/', dirname($scriptName));
        if ($basePath === '.' || $basePath === '/' || $basePath === '\\') {
            return '/';
        }

        return '/' . trim($basePath, '/') . '/';
    }

    /**
     * Get the root URL for the application.
     * This method is designed to be highly accurate, even behind reverse proxies and load balancers.
     *
     * @return string The full base URL of the application (e.g., https://example.com/myapp).
     */
    public static function root()
    {
        // Ensure the router is initialized to get the correct base path.
        if (!self::$initialized) {
            self::initialize();
        }

        $is_secure = false;

        if (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on') {
            $is_secure = true;
        }
        $trustForwardedHeaders = self::isTrustedProxyRequest();
        if (!$is_secure && $trustForwardedHeaders && isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            $is_secure = true;
        }
        if (!$is_secure && $trustForwardedHeaders && isset($_SERVER['HTTP_FORWARDED'])) {
            if (strpos(strtolower($_SERVER['HTTP_FORWARDED']), 'proto=https') !== false) {
                $is_secure = true;
            }
        }
        if (!$is_secure && $trustForwardedHeaders && isset($_SERVER['HTTP_CF_VISITOR'])) {
            $visitor_data = json_decode($_SERVER['HTTP_CF_VISITOR']);
            if (isset($visitor_data->scheme) && $visitor_data->scheme === 'https') {
                $is_secure = true;
            }
        }
        if (!$is_secure && isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
            $is_secure = true;
        }

        $protocol = $is_secure ? "https://" : "http://";

        $domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        if (!is_string($domain) || !preg_match('/^(?:\[[0-9a-fA-F:]+\]|[a-zA-Z0-9.-]+)(?::\d{1,5})?$/', $domain)) {
            $domain = 'localhost';
        }
        $domain = preg_replace('/:(80|443)$/', '', $domain);

        $base_path = rtrim(self::$base_path, '/');

        return $protocol . $domain . $base_path;
    }

    /**
     * Public accessor to get the variables from the current route's callback context.
     * @return array
     */
    public static function getCallbackContext(): array
    {
        return self::$callback_context_vars;
    }

    /**
     * The central method for adding a route to the collection.
     * It processes group attributes (prefix, middleware) and gathers detailed
     * callback information using Reflection.
     *
     * @param string $method The HTTP method.
     * @param string $url The URL pattern.
     * @param callable|array $callback The callback handler.
     * @return void
     */
    private static function addRoute(string $method, string $url, $callback)
    {
        if (!self::$initialized)
            self::initialize();

        // --- ধাপ ১: গ্রুপ থেকে প্রিফিক্স এবং মিডলওয়্যার সংগ্রহ ---
        $final_prefix = '';
        $group_middlewares = [];

        foreach (self::$group_stack as $group) {
            $final_prefix .= $group['prefix'] ?? '';
            if (isset($group['middleware'])) {
                $group_middlewares = array_merge($group_middlewares, (array) $group['middleware']);
            }
        }

        // --- ধাপ ২: চূড়ান্ত URL এবং Link তৈরি ---
        $final_url = rtrim($final_prefix, '/') . '/' . ltrim($url, '/');
        // রুট URL ('/') এর জন্য বিশেষ হ্যান্ডলিং, যেন শেষে অতিরিক্ত स्लैश না আসে
        if ($url === '/') {
            $final_url = rtrim($final_prefix, '/');
            // যদি প্রিফিক্সও খালি থাকে, তাহলে URL হবে '/'
            if (empty($final_url)) {
                $final_url = '/';
            }
        }

        $final_link = rtrim(self::root(), '/') . '/' . ltrim($final_url, '/');

        $auto_name = null;
        if ($final_url === '/' || $final_url === '') {
            $auto_name = 'index';
        } else {
            $parts = explode('/', trim($final_url, '/'));
            $name_parts = [];
            foreach ($parts as $part) {
                // Ignore dynamic parameters (@id) for naming
                if (!empty($part) && $part[0] !== '@') {
                    $name_parts[] = strtolower($part); // Lowercase for consistency
                }
            }

            // If the URL was purely dynamic (e.g., /@slug), provide a fallback name
            if (empty($name_parts)) {
                $auto_name = trim(str_replace(["/", "@"], [".", ""], $final_url), ".");
            } else {
                $auto_name = implode('.', $name_parts);
            }
        }

        // --- ধাপ ৩: মূল রুট অ্যারে তৈরি ---
        $route = [
            'name' => $auto_name,
            'named_manually' => false,
            'method' => strtolower($method),
            'url' => $final_url,
            'link' => $final_link,
            'headers' => [],
            'callback' => $callback,
            'middlewares' => $group_middlewares,
            'callback_details' => [],
        ];

        // --- ধাপ ৪: বিস্তারিত কলব্যাক তথ্য সংগ্রহ (আপনার Reflection লজিক) ---
        try {
            $reflection = null;
            $representation = 'Invalid or Non-representable';

            if ($callback instanceof \Closure) {
                $reflection = new \ReflectionFunction($callback);
                $type = 'Closure';
                $representation = "Closure @ " . basename($reflection->getFileName()) . ":" . $reflection->getStartLine();
            } elseif (is_array($callback) && isset($callback[0], $callback[1])) {
                if (class_exists($callback[0]) && method_exists($callback[0], $callback[1])) {
                    $reflection = new \ReflectionMethod($callback[0], $callback[1]);
                    $type = 'Method';
                    $representation = "Method: " . $callback[0] . "::" . $callback[1];
                }
            } elseif (is_string($callback) && function_exists($callback)) {
                $reflection = new \ReflectionFunction($callback);
                $type = 'Function';
                $representation = "Function: " . $callback;
            }

            $route['callback_details']['representation'] = $representation;

            if ($reflection) {
                $route['callback_details']['type'] = $type;
                $route['callback_details']['name'] = $reflection->getName();
                $route['callback_details']['file'] = $reflection->getFileName();
                $route['callback_details']['start_line'] = $reflection->getStartLine();
                $route['callback_details']['end_line'] = $reflection->getEndLine();

                $params_info = [];
                foreach ($reflection->getParameters() as $param) {
                    $params_info[] = [
                        'name' => $param->getName(),
                        'type' => $param->getType() ? $param->getType()->getName() : 'mixed',
                        'optional' => $param->isOptional(),
                        'default' => $param->isOptional() ? $param->getDefaultValue() : null,
                    ];
                }
                $route['callback_details']['parameters'] = $params_info;
            }
        } catch (\ReflectionException $e) {
            error_log("PHRO Reflection Error: " . $e->getMessage());
        }

        self::$routes[] = $route;
        self::$last_route_key = array_key_last(self::$routes);
    }

    /**
     * Define a route for GET method.
     *
     * @param string $url Route URL pattern.
     * @param callable|array $callback Callback function to execute when route is matched.
     * @return self Returns an instance of the class to allow method chaining.
     */
    public static function get(string $url, $callback): self
    {
        self::addRoute('get', $url, $callback);
        return new self();
    }

    /**
     * Define a route for POST method.
     *
     * @param string $url Route URL pattern.
     * @param callable|array $callback Callback function to execute when route is matched.
     * @return self Returns an instance of the class to allow method chaining.
     */
    public static function post(string $url, $callback): self
    {
        self::addRoute('post', $url, $callback);
        return new self();
    }

    /**
     * Define a route for PUT method.
     *
     * @param string $url Route URL pattern.
     * @param callable|array $callback Callback function to execute when route is matched.
     * @return self Returns an instance of the class to allow method chaining.
     */
    public static function put(string $url, $callback): self
    {
        self::addRoute('put', $url, $callback);
        return new self();
    }

    /**
     * Define a route for PATCH method.
     *
     * @param string $url Route URL pattern.
     * @param callable|array $callback Callback function to execute when route is matched.
     * @return self Returns an instance of the class to allow method chaining.
     */
    public static function patch(string $url, $callback): self
    {
        self::addRoute('patch', $url, $callback);
        return new self();
    }

    /**
     * Define a route for DELETE method.
     *
     * @param string $url Route URL pattern.
     * @param callable|array $callback Callback function to execute when route is matched.
     * @return self Returns an instance of the class to allow method chaining.
     */
    public static function delete(string $url, $callback): self
    {
        self::addRoute('delete', $url, $callback);
        return new self();
    }

    /**
     * Define a route for HEAD method.
     * Note: The router automatically handles HEAD requests for any defined GET route.
     * Use this only if you need specific logic for a HEAD request.
     *
     * @param string $url Route URL pattern.
     * @param callable|array $callback Callback to execute.
     * @return self
     */
    public static function head(string $url, $callback): self
    {
        self::addRoute('head', $url, $callback);
        return new self();
    }

    /**
     * Define a route for OPTIONS method.
     * Note: The router automatically handles OPTIONS requests for any URL with defined routes.
     * Use this only if you need custom CORS headers or specific logic.
     *
     * @param string $url Route URL pattern.
     * @param callable|array $callback Callback to execute.
     * @return self
     */
    public static function options(string $url, $callback): self
    {
        self::addRoute('options', $url, $callback);
        return new self();
    }

    /**
     * Creates a route group with shared attributes that can be chained.
     * e.g., PHRO::group('/api', ...)->name('api.')->middleware(...);
     *
     * @param string $prefix The URL prefix for the group.
     * @param callable $callback The callback function where routes are defined.
     * @return self Returns an instance of the class to allow method chaining.
     */
    public static function group(string $prefix, callable $callback): self
    {
        // ধাপ ১: গ্রুপের কাজ শুরু হওয়ার আগে রুটের সংখ্যা মনে রাখা
        $before_route_count = count(self::$routes);

        // ধাপ ২: গ্রুপের প্রিফিক্সটিকে স্ট্যাকে যোগ করা (শুধুমাত্র URL এর জন্য)
        self::$group_stack[] = ['prefix' => $prefix];

        // ধাপ ৩: কলব্যাক রান করা, যা গ্রুপের ভেতরের রুটগুলোকে রেজিস্টার করবে
        call_user_func($callback);

        // ধাপ ৪: প্রিফিক্সটিকে স্ট্যাক থেকে সরিয়ে ফেলা
        array_pop(self::$group_stack);

        // ধাপ ৫: গ্রুপের ভেতরে তৈরি হওয়া নতুন রুটগুলোর কী (key) গুলো সংগ্রহ করা
        $after_route_count = count(self::$routes);
        self::$last_route_key = range($before_route_count, $after_route_count - 1);

        // ধাপ ৬: চেইনিং এর জন্য নিজেকে রিটার্ন করা
        return new self();
    }

    /**
     * Registers a full set of CRUD routes.
     * It can accept a controller class name (for standard CRUD) or a [class, method] array for custom mapping.
     *
     * @param string $uri The base URI for the resource.
     * @param string|array $controller The controller class name or a [class, method] array.
     * @param array $options Options to customize the routes.
     * @return self
     */
    public static function crud(string $uri, string|array $controller, array $options = []): self
    {
        $before_route_count = count(self::$routes);

        $uri = '/' . trim($uri, '/');
        $param_name = $options['param'] ?? 'id';
        $param = '/@' . $param_name;

        $auto_base_name = str_replace(['/', '@'], ['.', ''], trim($uri, '/'));
        if (empty($auto_base_name))
            $auto_base_name = 'index';

        $actions = [
            'index' => ['method' => 'get', 'url' => $uri],
            'create' => ['method' => 'get', 'url' => $uri . '/create'],
            'store' => ['method' => 'post', 'url' => $uri],
            'show' => ['method' => 'get', 'url' => $uri . $param],
            'edit' => ['method' => 'get', 'url' => $uri . $param . '/edit'],
            'replace' => ['method' => 'put', 'url' => $uri . $param],
            'update' => ['method' => 'patch', 'url' => $uri . $param],
            'destroy' => ['method' => 'delete', 'url' => $uri . $param],
        ];

        $active_actions = array_keys($actions);
        if (!empty($options['only'])) {
            $active_actions = array_intersect($active_actions, $options['only']);
        } elseif (!empty($options['except'])) {
            $active_actions = array_diff($active_actions, $options['except']);
        }

        foreach ($active_actions as $action_name) {
            $action_details = $actions[$action_name];
            $method = $action_details['method'];
            $url = $action_details['url'];

            $callback = [];

            if (is_string($controller)) {
                $callback = [$controller, $action_name];
            } elseif (is_array($controller) && isset($controller[0], $controller[1])) {
                $callback = [$controller[0], $controller[1]];
            } else {
                continue;
            }

            self::$method($url, $callback)->name($auto_base_name . '.' . $action_name);
        }

        $after_route_count = count(self::$routes);
        self::$last_route_key = range($before_route_count, $after_route_count - 1);

        return new self();
    }

    /**
     * Registers a common set of routes for a resource using only GET and POST methods.
     * This is ideal for traditional HTML form-based interactions.
     * It creates routes for: index, create, store, show, edit, and update (via POST).
     *
     * @param string $uri The base URI for the resource.
     * @param string|array $controller The controller class name or a [class, method] array.
     * @param array $options Options to customize the routes (e.g., 'only', 'except').
     * @return self
     */
    public static function gap(string $uri, string|array $controller, array $options = []): self
    {
        $before_route_count = count(self::$routes);

        $uri = '/' . trim($uri, '/');
        $param_name = $options['param'] ?? 'id';
        $param = '/@' . $param_name;

        $auto_base_name = str_replace(['/', '@'], ['.', ''], trim($uri, '/'));
        if (empty($auto_base_name))
            $auto_base_name = 'index';

        // Actions limited to GET and POST verbs
        $actions = [
            'index' => ['method' => 'get', 'url' => $uri],
            'create' => ['method' => 'get', 'url' => $uri . '/create'],
            'store' => ['method' => 'post', 'url' => $uri],
            'show' => ['method' => 'get', 'url' => $uri . $param],
            'edit' => ['method' => 'get', 'url' => $uri . $param . '/edit'],
            'update' => ['method' => 'post', 'url' => $uri . $param], // Using POST for updates
        ];

        $active_actions = array_keys($actions);
        if (!empty($options['only'])) {
            $active_actions = array_intersect($active_actions, $options['only']);
        } elseif (!empty($options['except'])) {
            $active_actions = array_diff($active_actions, $options['except']);
        }

        foreach ($active_actions as $action_name) {
            $action_details = $actions[$action_name];
            $method = $action_details['method'];
            $url = $action_details['url'];

            $callback = [];

            if (is_string($controller)) {
                $callback = [$controller, $action_name];
            } elseif (is_array($controller) && isset($controller[0], $controller[1])) {
                $callback = [$controller[0], $controller[1]];
            } else {
                continue; // Skip if controller format is invalid
            }

            self::$method($url, $callback)->name($auto_base_name . '.' . $action_name);
        }

        $after_route_count = count(self::$routes);
        self::$last_route_key = range($before_route_count, $after_route_count - 1);

        return new self();
    }

    /**
     * Registers two essential routes for a single resource URI: a GET and a POST.
     * This is perfect for pages that display a form (GET) and handle its submission (POST),
     * such as login, contact, or settings pages.
     *
     * @param string $uri The resource URI.
     * @param string|array $controller The controller class name or a [class, method] array.
     * @param array $options Options to customize method names. e.g., ['get' => 'show', 'post' => 'handle']
     * @return self
     */
    public static function sgap(string $uri, string|array $controller, array $options = []): self
    {
        $before_route_count = count(self::$routes);

        $uri = '/' . trim($uri, '/');

        // Default method names in the controller
        $get_method_name = $options['get'] ?? 'show';
        $post_method_name = $options['post'] ?? 'handle';

        // Auto-generate a base name for the routes from the URI
        $auto_base_name = str_replace('/', '.', trim($uri, '/'));
        if (empty($auto_base_name))
            $auto_base_name = 'index';

        // Define the two actions: GET and POST
        $actions = [
            'get' => ['method' => 'get', 'controller_method' => $get_method_name],
            'post' => ['method' => 'post', 'controller_method' => $post_method_name],
        ];

        foreach ($actions as $action_name => $details) {
            $http_method = $details['method'];
            $controller_method = $details['controller_method'];

            $callback = [];
            if (is_string($controller)) {
                $callback = [$controller, $controller_method];
            } elseif (is_array($controller) && isset($controller[0], $controller[1])) {
                // If a specific [class, method] is provided, it overrides the default logic
                $callback = [$controller[0], $controller[1]];
            } else {
                continue; // Skip if controller format is invalid
            }

            // Register the route using the corresponding HTTP method function (e.g., self::get)
            self::$http_method($uri, $callback)->name($auto_base_name . '.' . $action_name);
        }

        $after_route_count = count(self::$routes);
        self::$last_route_key = range($before_route_count, $after_route_count - 1);

        return new self();
    }

    /**
     * Define a route for custom HTTP method.
     *
     * @param string $method Custom HTTP method.
     * @param string $url Route URL pattern.
     * @param callable $callback Callback function to execute when route is matched.
     * @return void
     */
    public static function add($method, $url, $callback)
    {
        self::addRoute($method, $url, $callback);
        return new self();
    }

    /**
     * Assigns a name to the most recently defined route or group of routes.
     * This method handles both single routes and groups intelligently.
     *
     * @param string $name The name to assign. For groups, it's used as a prefix.
     * @return self
     */
    public function name(string $name): self
    {
        $keys_to_name = is_array(self::$last_route_key) ? self::$last_route_key : [self::$last_route_key];
        $is_group_or_crud = is_array(self::$last_route_key);

        foreach ($keys_to_name as $key) {
            if (isset(self::$routes[$key])) {
                self::$routes[$key]['named_manually'] = true;

                $old_name = self::$routes[$key]['name']; // পুরনো নামটি মনে রাখা

                if ($is_group_or_crud) {
                    // গ্রুপ/CRUD এর জন্য: রুটের পুরনো নামটি ফেলে দিয়ে নতুন প্রিফিক্স ব্যবহার করা
                    $action_name = substr(strrchr(self::$routes[$key]['name'], "."), 1);
                    if ($action_name === false) { // যদি কোনো ডট না থাকে
                        $action_name = self::$routes[$key]['name'];
                    }
                    $prefix = rtrim($name, '.') . '.';
                    self::$routes[$key]['name'] = $prefix . $action_name;
                } else {
                    // সিঙ্গেল রুটের জন্য: সরাসরি নামটি সেট করা
                    self::$routes[$key]['name'] = $name;
                }

                // *** অত্যন্ত গুরুত্বপূর্ণ: named_routes অ্যারে আপডেট করা ***
                // পুরনো নামের এন্ট্রি (যদি থাকে) মুছে ফেলা
                if ($old_name && isset(self::$named_routes[$old_name])) {
                    unset(self::$named_routes[$old_name]);
                }
                // নতুন নামে এন্ট্রি যোগ করা
                self::$named_routes[self::$routes[$key]['name']] = self::$routes[$key]['url'];
            }
        }
        return $this;
    }

    /**
     * Attach middleware(s) to the most recently defined route.
     *
     * @param callable|array $middleware A single middleware function or an array of middleware functions.
     * @return $this
     */
    public function middleware($middleware): self
    {
        $middlewares_to_add = [];
        if (is_array($middleware) && isset($middleware[0]) && is_string($middleware[0])) {
            $middlewares_to_add[] = $middleware;
        } elseif (is_array($middleware)) {
            $middlewares_to_add = $middleware;
        } else {
            $middlewares_to_add[] = $middleware;
        }

        $keys_to_modify = is_array(self::$last_route_key) ? self::$last_route_key : [self::$last_route_key];

        foreach ($keys_to_modify as $key) {
            if (isset(self::$routes[$key])) {
                self::$routes[$key]['middlewares'] = array_merge(
                    self::$routes[$key]['middlewares'] ?? [],
                    $middlewares_to_add
                );
            }
        }
        return $this;
    }

    /**
     * Attaches response headers to the most recently defined route or group.
     * This is an all-in-one, highly powerful method with an extensive list of shortcuts
     * and intelligent mixed-array support.
     *
     * @param string|array $header A shortcut name ('json'), a custom header key, or a mixed array of shortcuts and key-value pairs.
     * @param string|null $value The value for the header if providing a single custom key.
     * @return self
     */
    public function header($header, ?string $value = null): self
    {
        $final_headers = [];
        if (is_array($header)) {
            $items_to_process = $header;
        } elseif ($value === null) {
            $items_to_process = [$header];
        } else {
            $items_to_process = [$header => $value];
        }

        foreach ($items_to_process as $key => $val) {
            $is_shortcut = is_int($key) && is_string($val);
            $item_to_resolve = $is_shortcut ? $val : [$key => $val];

            if (is_string($item_to_resolve)) {
                $shortcut = strtolower($item_to_resolve);
                $now = gmdate('D, d M Y H:i:s') . ' GMT';
                $one_year = 31536000;

                // --- Extensive Shortcut & Preset Alias Library ---
                $aliases = [
                    // === Presets (Grouped Headers) ===
                    'api' => [
                        'Content-Type' => 'application/json; charset=utf-8',
                        'Cache-Control' => 'no-cache, private',
                        'Access-Control-Allow-Origin' => '*',
                        'X-Content-Type-Options' => 'nosniff',
                    ],
                    'secure-api' => [
                        'Content-Type' => 'application/json; charset=utf-8',
                        'Strict-Transport-Security' => "max-age={$one_year}; includeSubDomains; preload",
                        'X-Frame-Options' => 'DENY',
                        'X-Content-Type-Options' => 'nosniff',
                        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
                        'Cache-Control' => 'no-store, no-cache, must-revalidate',
                        'Pragma' => 'no-cache',
                    ],
                    'asset' => [ // For static assets like CSS, JS
                        'Cache-Control' => 'public, max-age=604800, immutable', // 1 week
                        'Vary' => 'Accept-Encoding',
                    ],
                    'secure-page' => [
                        'Strict-Transport-Security' => "max-age={$one_year}; includeSubDomains; preload",
                        'X-Content-Type-Options' => 'nosniff',
                        'X-Frame-Options' => 'SAMEORIGIN',
                        'Referrer-Policy' => 'strict-origin-when-cross-origin',
                        'Content-Security-Policy' => "default-src 'self'; object-src 'none'; frame-ancestors 'self';",
                    ],

                    // === Content-Type ===
                    'html' => ['Content-Type' => 'text/html; charset=utf-8'],
                    'json' => ['Content-Type' => 'application/json; charset=utf-8'],
                    'xml' => ['Content-Type' => 'application/xml; charset=utf-8'],
                    'text' => ['Content-Type' => 'text/plain; charset=utf-8'],
                    'js' => ['Content-Type' => 'application/javascript; charset=utf-8'],
                    'css' => ['Content-Type' => 'text/css; charset=utf-8'],
                    'csv' => ['Content-Type' => 'text/csv; charset=utf-8'],
                    'rss' => ['Content-Type' => 'application/rss+xml; charset=utf-8'],
                    'atom' => ['Content-Type' => 'application/atom+xml; charset=utf-8'],
                    'form' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    'multipart' => ['Content-Type' => 'multipart/form-data'],

                    // === Image Content-Types ===
                    'jpg' => ['Content-Type' => 'image/jpeg'],
                    'jpeg' => ['Content-Type' => 'image/jpeg'],
                    'png' => ['Content-Type' => 'image/png'],
                    'gif' => ['Content-Type' => 'image/gif'],
                    'webp' => ['Content-Type' => 'image/webp'],
                    'svg' => ['Content-Type' => 'image/svg+xml'],
                    'ico' => ['Content-Type' => 'image/x-icon'],
                    'bmp' => ['Content-Type' => 'image/bmp'],
                    'avif' => ['Content-Type' => 'image/avif'],
                    'tiff' => ['Content-Type' => 'image/tiff'],

                    // === Video/Audio Content-Types ===
                    'mp4' => ['Content-Type' => 'video/mp4'],
                    'webm' => ['Content-Type' => 'video/webm'],
                    'oggv' => ['Content-Type' => 'video/ogg'],
                    'mov' => ['Content-Type' => 'video/quicktime'],
                    'mp3' => ['Content-Type' => 'audio/mpeg'],
                    'wav' => ['Content-Type' => 'audio/wav'],
                    'ogga' => ['Content-Type' => 'audio/ogg'],

                    // === Font Content-Types ===
                    'woff' => ['Content-Type' => 'font/woff'],
                    'woff2' => ['Content-Type' => 'font/woff2'],
                    'ttf' => ['Content-Type' => 'font/ttf'],
                    'otf' => ['Content-Type' => 'font/otf'],
                    'eot' => ['Content-Type' => 'application/vnd.ms-fontobject'],

                    // === Document Content-Types ===
                    'pdf' => ['Content-Type' => 'application/pdf'],
                    'zip' => ['Content-Type' => 'application/zip'],
                    'doc' => ['Content-Type' => 'application/msword'],
                    'docx' => ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                    'xls' => ['Content-Type' => 'application/vnd.ms-excel'],
                    'xlsx' => ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                    'ppt' => ['Content-Type' => 'application/vnd.ms-powerpoint'],
                    'pptx' => ['Content-Type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
                    'jsonld' => ['Content-Type' => 'application/ld+json'],

                    // === Content-Disposition ===
                    'download' => ['Content-Disposition' => 'attachment'],
                    'inline' => ['Content-Disposition' => 'inline'],

                    // === Caching ===
                    'no-cache' => ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache', 'Expires' => '0'],
                    'cache:none' => ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache', 'Expires' => '0'],
                    'cache' => ['Cache-Control' => 'public, max-age=3600'], // 1 hour
                    'cache:minute' => ['Cache-Control' => 'public, max-age=60'],
                    'cache:short' => ['Cache-Control' => 'public, max-age=60'],
                    'cache:hour' => ['Cache-Control' => 'public, max-age=3600'],
                    'cache:day' => ['Cache-Control' => 'public, max-age=86400'],
                    'cache:medium' => ['Cache-Control' => 'public, max-age=86400'],
                    'cache:week' => ['Cache-Control' => 'public, max-age=604800'],
                    'cache:long' => ['Cache-Control' => 'public, max-age=604800'],
                    'cache:month' => ['Cache-Control' => 'public, max-age=2592000'],
                    'cache:year' => ['Cache-Control' => 'public, max-age=31536000'],
                    'cache:immutable' => ['Cache-Control' => 'public, max-age=31536000, immutable'],

                    // === CORS (Cross-Origin Resource Sharing) ===
                    'cors' => ['Access-Control-Allow-Origin' => '*'],
                    'cors:*' => ['Access-Control-Allow-Origin' => '*'],
                    'cors:all' => [
                        'Access-Control-Allow-Origin' => '*',
                        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
                        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
                        'Access-Control-Max-Age' => '86400'
                    ],

                    // === Security Headers ===
                    'secure' => [
                        'Strict-Transport-Security' => "max-age={$one_year}; includeSubDomains; preload",
                        'X-Content-Type-Options' => 'nosniff',
                        'X-Frame-Options' => 'SAMEORIGIN',
                        'Referrer-Policy' => 'strict-origin-when-cross-origin',
                        'Content-Security-Policy' => "default-src 'self'",
                    ],
                    'csp:default-self' => ['Content-Security-Policy' => "default-src 'self'"],
                    'csp:none' => ['Content-Security-Policy' => "default-src 'none'"],
                    'clickjack-deny' => ['X-Frame-Options' => 'DENY'],
                    'hsts' => ['Strict-Transport-Security' => "max-age={$one_year}; includeSubDomains; preload"],

                    // === Server-Sent Events (SSE) ===
                    'sse' => ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Connection' => 'keep-alive'],
                    'event-stream' => ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Connection' => 'keep-alive'],

                    // === Other Common Headers ===
                    'powered-by:hide' => ['X-Powered-By' => ''],
                    'hide-powered-by' => ['X-Powered-By' => ''],
                    'last-modified' => ['Last-Modified' => $now],
                    'modified' => ['Last-Modified' => $now],
                    'etag' => ['ETag' => '"' . md5($now . rand()) . '"'],
                    'vary:accept' => ['Vary' => 'Accept-Encoding, Accept'],
                    'no-transform' => ['Cache-Control' => 'no-transform'],
                ];

                // --- Handle Dynamic Shortcuts ---
                $resolved_header = [];
                if (isset($aliases[$shortcut])) {
                    $resolved_header = $aliases[$shortcut];
                }
                // cache:300 -> Cache-Control: public, max-age=300
                else if (str_starts_with($shortcut, 'cache:')) {
                    $value = substr($shortcut, 6);
                    if (is_numeric($value)) {
                        $resolved_header = ['Cache-Control' => "public, max-age=" . (int) $value];
                    }
                }
                // download:report.pdf -> Content-Disposition: attachment; filename="report.pdf"
                else if (str_starts_with($shortcut, 'download:')) {
                    $filename = substr($shortcut, 9);
                    if (!empty($filename))
                        $resolved_header = ['Content-Disposition' => 'attachment; filename="' . basename($filename) . '"'];
                }
                // cors:https://example.com -> Access-Control-Allow-Origin: https://example.com
                else if (str_starts_with($shortcut, 'cors:')) {
                    $domain = substr($shortcut, 5);
                    if (!empty($domain))
                        $resolved_header = ['Access-Control-Allow-Origin' => $domain];
                }
                // redirect:/path/to/go -> Location: /path/to/go
                else if (str_starts_with($shortcut, 'redirect:')) {
                    $url = substr($shortcut, 9);
                    if (!empty($url))
                        $resolved_header = ['Location' => $url];
                }
                // content-type:image/avif -> Content-Type: image/avif
                else if (str_starts_with($shortcut, 'content-type:')) {
                    $mime_type = substr($shortcut, 13);
                    if (!empty($mime_type))
                        $resolved_header = ['Content-Type' => $mime_type];
                }
                // csp:script-src 'self' -> Content-Security-Policy: script-src 'self'
                else if (str_starts_with($shortcut, 'csp:')) {
                    $policy = substr($shortcut, 4);
                    if (!empty($policy))
                        $resolved_header = ['Content-Security-Policy' => $policy];
                }
                // refresh:5 -> Refresh: 5 (reload after 5s)
                // refresh:5;url=/login -> Refresh: 5;url=/login (redirect after 5s)
                else if (str_starts_with($shortcut, 'refresh:')) {
                    $value = substr($shortcut, 8);
                    if (!empty($value))
                        $resolved_header = ['Refresh' => $value];
                }
                // auth:basic -> WWW-Authenticate: Basic realm="Restricted Area"
                else if (str_starts_with($shortcut, 'auth:')) {
                    $type = substr($shortcut, 5);
                    if ($type === 'basic') {
                        $resolved_header = ['WWW-Authenticate' => 'Basic realm="Restricted Area"'];
                    } elseif ($type === 'bearer') {
                        $resolved_header = ['WWW-Authenticate' => 'Bearer realm="Restricted Area"'];
                    }
                }
                // vary:User-Agent -> Vary: User-Agent
                else if (str_starts_with($shortcut, 'vary:')) {
                    $value = substr($shortcut, 5);
                    if (!empty($value))
                        $resolved_header = ['Vary' => $value];
                }

                $final_headers = array_merge($final_headers, $resolved_header);
            } else {
                $final_headers = array_merge($final_headers, $item_to_resolve);
            }
        }

        if (empty($final_headers))
            return $this;

        $keys_to_modify = is_array(self::$last_route_key) ? self::$last_route_key : [self::$last_route_key];
        foreach ($keys_to_modify as $key) {
            if (isset(self::$routes[$key])) {
                self::$routes[$key]['headers'] = array_merge(self::$routes[$key]['headers'] ?? [], $final_headers);
            }
        }
        return $this;
    }

    /**
     * ⚡ THE ULTIMATE AI BRIDGE (MCP INTEGRATION) ⚡
     * Converts any Web Route into an MCP Tool, Prompt, Resource, or Template instantly.
     * Supports chaining for multiple MCP bindings on a single route.
     *
     * @param string $type "tool", "prompt", "resource", or "template"
     * @param string $name The unique name for the AI to call (e.g., "admin_posts")
     * @param string $description What this does (crucial for AI intelligence)
     * @param array $schema JSON Schema for args (Tools/Prompts) or custom URI config.
     * @return self
     */
    public function mcp(string $type, string $name, string $description, array $schema = []): self
    {
        self::$mcp_enabled = true;
        $keys_to_modify = is_array(self::$last_route_key) ? self::$last_route_key : [self::$last_route_key];

        foreach ($keys_to_modify as $key) {
            if (isset(self::$routes[$key])) {
                $route = self::$routes[$key];

                // ঐচ্ছিক: রাউটের ভেতরে MCP মেটাডেটা সেভ রাখা
                self::$routes[$key]['mcp'][] = [
                    'type' => $type,
                    'name' => $name,
                    'description' => $description
                ];

                // যদি PHAI ইন্সটল করা না থাকে, তবে ক্র্যাশ ঠেকানো
                if (!class_exists('PHAI'))
                    continue;

                // 🧠 The Magic Wrapper: Converts MCP Call -> Web Route Context
                $mcp_wrapper = function ($args) use ($route) {

                    // ১. PHRO এর ডিফল্ট $data স্ট্রাকচার মক (Mock) করা
                    $mock_data = [
                        'data' => $args,       // AI এর পাঠানো ডেটা POST হিসেবে যাবে
                        'params' => $args,     // AI এর পাঠানো ডেটা URL Param হিসেবে যাবে
                        'headers' => [],
                        'cookies' => [],
                        'client' => [],
                        'server' => $_SERVER,
                        'route_details' => $route
                    ];

                    // ২. আউটপুট বাফারিং (যদি ওয়েব রাউট echo করে, সেটি ধরার জন্য)
                    ob_start();

                    try {
                        $callback = $route['callback'];
                        $result = null;

                        // ৩. ইন্টেলিজেন্ট এক্সিকিউশন (Smart Execution)
                        $reflection = null;
                        if (is_array($callback) && isset($callback[0], $callback[1])) {
                            $reflection = new \ReflectionMethod($callback[0], $callback[1]);
                        } elseif (is_callable($callback)) {
                            $reflection = new \ReflectionFunction($callback);
                        }

                        if ($reflection) {
                            $passArgs = [];
                            foreach ($reflection->getParameters() as $param) {
                                $param_name = $param->getName();
                                if (in_array($param_name, ['data', 'request', 'req'])) {
                                    $passArgs[] = $mock_data; // $data প্যারামিটারে মক ডেটা পাস
                                } elseif (isset($args[$param_name])) {
                                    $passArgs[] = $args[$param_name];
                                } elseif ($param->isDefaultValueAvailable()) {
                                    $passArgs[] = $param->getDefaultValue();
                                } else {
                                    $passArgs[] = null;
                                }
                            }

                            // ফাংশন বা ক্লাস মেথড কল করা
                            if ($reflection instanceof \ReflectionMethod) {
                                if ($reflection->isStatic()) {
                                    $result = $reflection->invokeArgs(null, $passArgs);
                                } else {
                                    $instance = new $callback[0]();
                                    $result = $reflection->invokeArgs($instance, $passArgs);
                                }
                            } else {
                                $result = $reflection->invokeArgs($passArgs);
                            }
                        }

                        // ৪. Echo হওয়া ডেটা সংগ্রহ
                        $output = ob_get_clean();

                        // ৫. প্রাইওরিটি রিটার্ন (1. Return value, 2. Echoed JSON, 3. Echoed Text)
                        if ($result !== null)
                            return $result;

                        if (!empty($output)) {
                            // ওয়েব API সাধারণত JSON echo করে, তাই সেটিকে অ্যারে তে কনভার্ট করা হচ্ছে
                            // যেন PHAI এটিকে সুন্দরভাবে JSON-RPC ফরম্যাটে সাজাতে পারে।
                            $decoded = json_decode($output, true);
                            return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $output;
                        }

                        return "Success (Executed internally without output)";

                    } catch (\Throwable $e) {
                        ob_end_clean();
                        throw new \Exception("MCP Route Error: " . $e->getMessage());
                    }
                };

                // 🚀 PHAI ইঞ্জিনে স্বয়ংক্রিয় রেজিস্ট্রেশন
                $type = strtolower($type);
                if ($type === 'tool') {
                    $inputSchema = empty($schema) ? ['type' => 'object', 'properties' => (object) []] : $schema;
                    \PHAI::tool($name, $description, $inputSchema, $mcp_wrapper);
                } elseif ($type === 'prompt') {
                    \PHAI::prompt($name, $description, $schema, $mcp_wrapper);
                } elseif ($type === 'resource') {
                    $uri = empty($schema) ? $route['url'] : ($schema['uri'] ?? $route['url']);
                    \PHAI::resource($uri, $name, $description, $mcp_wrapper);
                } elseif ($type === 'template') {
                    // PHRO এর @id ফরম্যাটকে MCP এর {id} ফরম্যাটে কনভার্ট করা
                    $uriTemplate = preg_replace('/@([a-zA-Z0-9_]+)/', '{$1}', $route['url']);
                    \PHAI::resourceTemplate($uriTemplate, $name, $description, $mcp_wrapper);
                }
            }
        }
        return $this;
    }

    /**
     * Gets the full filesystem path for a given resource using colon notation.
     * e.g., 'lib', 'models:User', 'controllers:AuthController'
     *
     * @param string $key The resource key or colon-separated path.
     * @return string The full filesystem path.
     */
    public static function gatherRequestData(): array
    {
        $request_data = [
            'params' => self::$params ?? [],
            'headers' => self::gatherHeaders(),
            'cookies' => $_COOKIE,
            'data' => self::gatherInputData(),
            'agent' => self::gatherAgentInfo(),
            'client' => (self::$footprint === true) ? self::footprint() : self::userAgentInfo() ?? self::footprint(),
            'server' => $_SERVER
        ];
        return $request_data;
    }

    /**
     * Tracks failed attempts and enforces a block if the limit is exceeded.
     * This is the primary interface for failed attempt limiting with a simplified API.
     *
     * @param string|array $config_or_message Can be:
     *   - A string: Used as the event_name and default block message.
     *   - An array: Allows custom configuration. Keys:
     *     - 'event_name' (string, auto-generated if not provided)
     *     - 'message' (string, default 'Too many failed attempts...')
     *     - 'return_details' (bool, default false)
     *     - 'max_attempts' (int, default 10)
     *     - 'block_duration_minutes' (int, default 15)
     *     - 'reset_after_minutes' (int, default 30)
     * @param bool $return_details_override If true, forces returning details regardless of $config_or_message['return_details'].
     * @return array|bool Returns an array with attempt details if $return_details is true, otherwise returns true on success (attempts not exceeded) or throws an exception on block.
     * @throws PhroSecurityException
     */
    public static function attempt($config_or_message = 'generic_attempt_fail', bool $return_details_override = false)
    {
        // --- 1. Resolve Configuration ---
        $config = [
            'event_name' => 'generic_attempt_fail',
            'message' => 'Too many failed attempts. Access temporarily blocked.',
            'return_details' => false,
            'max_attempts' => 10,
            'block_duration_minutes' => 15,
            'reset_after_minutes' => 30,
        ];

        if (is_string($config_or_message)) {
            $config['event_name'] = self::createSlug($config_or_message);
            $config['message'] = $config_or_message;
        } elseif (is_array($config_or_message)) {
            $config = array_replace_recursive($config, $config_or_message);
            if (is_string($config['event_name'])) {
                $config['event_name'] = self::createSlug($config['event_name']);
            }
        }

        if ($return_details_override) {
            $config['return_details'] = true;
        }

        if (empty($config['event_name']) || $config['event_name'] === 'generic-attempt-fail') {
            $config['event_name'] = self::createSlug(
                ($_SERVER['REQUEST_URI'] ?? 'uri') . '-' . ($_SERVER['REQUEST_METHOD'] ?? 'method') . '-auto'
            );
        }

        // --- 2. Shield Activation Check ---
        if (!self::$guard_activated || !(self::$guard_config['attempt_shield']['enabled'] ?? false)) {
            return ['attempts' => 0, 'remaining' => $config['max_attempts'], 'status' => 'disabled'];
        }

        // Get the AttemptShield instance.
        $shield = self::$guard_activated ? (self::$guard_instance->getShield('attempt_shield') ?? null) : null;
        if (!$shield instanceof PhroAttemptShield) {
            error_log("PHRO::attempt() called but AttemptShield is not properly configured/instantiated.");
            return ['attempts' => 0, 'remaining' => $config['max_attempts'], 'status' => 'error_shield_not_found'];
        }

        // --- 3. Perform Check and Increment ---
        // This is the core logic. Check if already blocked, then increment, then re-check for new block.
        $current_status = $shield->checkAndIncrementAttempt(
            $config['event_name'],
            $config['max_attempts'],
            $config['block_duration_minutes'],
            $config['reset_after_minutes'],
            $config['message']
        );

        if ($current_status['status'] === 'blocked') {
            throw new PhroSecurityException($config['message']);
        }

        // --- 4. Return Results ---
        if ($config['return_details']) {
            return $current_status;
        }

        return true; // No block, attempts allowed for this request.
    }

    /**
     * Resets the failed attempt count for a specific event and client.
     * Typically called after a successful action (e.g., successful login).
     *
     * @param string $event_name A unique name for the attempt.
     */
    public static function resetAttempt(string $event_name): void
    {
        if (!self::$guard_activated || !(self::$guard_config['attempt_shield']['enabled'] ?? false)) {
            return;
        }
        $shield = self::$guard_activated ? (self::$guard_instance->getShield('attempt_shield') ?? null) : null;
        if ($shield instanceof PhroAttemptShield) {
            $shield->performAttemptReset(self::createSlug($event_name));
        }
    }

    /**
     * ⚡ PHOP Ultimate Async & Parallel Engine (Simplified SSL Auto - Final) ⚡
     * 
     * - No trustedHosts array
     * - SSL verify: https → true, http → false
     * - Everything else same
     * Public entry point (renamed to task as per your code)
     * 
     * @param callable|string ...$tasks
     * @return void
     */
    public static function task(...$tasks): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        if (php_sapi_name() !== 'cli') {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                $output = ob_get_length() ? ob_get_clean() : '';
                header('Connection: close');
                header('Content-Length: ' . strlen($output));
                header('Content-Encoding: none');
                echo $output;
                while (ob_get_level() > 0)
                    @ob_end_flush();
                @flush();
            }
        }

        self::runBackgroundTasks($tasks);
    }

    /**
     * Private: All configs + execution logic
     */
    private static function runBackgroundTasks(array $tasks): void
    {
        // Configs
        $batchSize = 20;
        $timeoutMs = 500;
        $logExpirationMin = 60;
        $delayBetweenBatchesUs = 50000;

        // Process tasks
        $urls = [];

        foreach ($tasks as $task) {
            if (is_callable($task)) {
                try {
                    $task();
                } catch (\Throwable $e) {
                    self::logErrorPhls('Callable Execution Failed', $e, $logExpirationMin);
                }
            } elseif (is_string($task) && filter_var($task, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_HOST_REQUIRED)) {
                $urls[] = $task;
            } else {
                $ex = new \InvalidArgumentException('Invalid task: callable or valid URL required');
                self::logErrorPhls('Invalid Task Provided', $ex, $logExpirationMin);
            }
        }

        if ($urls === []) {
            gc_collect_cycles();
            exit;
        }
        if (!function_exists('curl_multi_init')) {
            self::logErrorPhls(
                'Background URL Tasks Unavailable',
                new \RuntimeException('The cURL extension is required for URL background tasks.'),
                $logExpirationMin
            );
            gc_collect_cycles();
            exit;
        }

        $batches = array_chunk($urls, $batchSize);

        foreach ($batches as $batch) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($batch as $i => $url) {
                $ch = curl_init($url);
                $verify = self::shouldVerifySsl($url);  // Simplified call

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => $verify,
                    CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                    CURLOPT_TIMEOUT_MS => $timeoutMs,
                    CURLOPT_NOSIGNAL => 1,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                ]);

                curl_multi_add_handle($mh, $ch);
                $handles[$i] = ['handle' => $ch, 'url' => $url];
            }

            $running = null;
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh);
                }
            } while ($running > 0 && $status == CURLM_OK);

            foreach ($handles as $info) {
                $ch = $info['handle'];
                $url = $info['url'];

                $errNo = curl_errno($ch);
                if ($errNo !== CURLE_OK) {
                    $errMsg = curl_error($ch);
                    $ex = new \RuntimeException("cURL failed [$url]: $errMsg", $errNo);
                    self::logErrorPhls('cURL Request Failed', $ex, $logExpirationMin);
                }

                curl_multi_remove_handle($mh, $ch);
                if (is_resource($ch)) { curl_close($ch); }
            }

            curl_multi_close($mh);
            usleep($delayBetweenBatchesUs);
        }

        gc_collect_cycles();
        exit;
    }

    /**
     * Super simple SSL verify decision
     * https → true (secure), http → false, invalid → false
     */
    private static function shouldVerifySsl(string $url): bool
    {
        $parsed = parse_url($url);
        return isset($parsed['scheme']) && strtolower($parsed['scheme']) === 'https';
    }

    /**
     * Log error to PHLS
     */
    private static function logErrorPhls(string $context, \Throwable $e, int $expirationMin): void
    {
        $key = 'task_error_' . time() . '_' . substr(md5($context . $e->getMessage()), 0, 10);

        $value = [
            'timestamp' => date('Y-m-d H:i:s'),
            'context' => $context,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];

        try {
            PHLS::add(
                $key,
                $value,
                $expirationMin,
                ['task', 'error', 'task_engine']
            );
        } catch (\Throwable $inner) {
            error_log("PHLS FAILED: " . $inner->getMessage());
            error_log(json_encode($value, JSON_PRETTY_PRINT));
        }
    }


    /**
     * ⚡ PHOP Real-Time SSE Engine (Final - Heartbeat + Last-Event-ID Support) ⚡
     * 
     * - Heartbeat every 15 seconds (: ping)
     * - Last-Event-ID support for reconnection recovery
     * - Memory-safe, low CPU, shared hosting compatible
     * - User connect → auto start, disconnect → auto stop
     * 
     * @param callable $messageProvider  Returns string|array|null per loop
     * @return void
     */
    public static function stream(callable $messageProvider): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        if (php_sapi_name() !== 'cli') {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0)
                @ob_end_flush();
        }

        echo "retry: 2000\n\n";
        @ob_flush();
        @flush();

        $lastEventId = filter_var($_SERVER['HTTP_LAST_EVENT_ID'] ?? '', FILTER_SANITIZE_NUMBER_INT);
        $nextId = $lastEventId ? (int) $lastEventId + 1 : 1;

        $lastHeartbeat = time();
        $startTime = time();

        $stream_active = true;

        while ($stream_active) {
            if (connection_aborted() || connection_status() !== CONNECTION_NORMAL) {
                break;
            }

            $currentTime = time();

            if ($currentTime - $lastHeartbeat >= 15) {
                echo ": ping\n\n";
                @ob_flush();
                @flush();
                $lastHeartbeat = $currentTime;
            }

            try {
                // Pass a reference to $stream_active so the callback can modify it to stop the loop
                $data = $messageProvider($stream_active);

                if ($data !== null) {
                    $payload = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : (string) $data;
                    echo "id: $nextId\n";
                    echo "data: $payload\n\n";
                    @ob_flush();
                    @flush();
                    $nextId++;
                }
            } catch (\Throwable $e) {
                // Silent fail
            }

            gc_collect_cycles();

            // Safety timeout (1 hour)
            if ($currentTime - $startTime > 3600) {
                break;
            }

            // If the callback set $stream_active to false, break immediately before sleeping
            if (!$stream_active) {
                break;
            }

            usleep(1000000);
        }

        gc_collect_cycles();
        exit; // Fully terminate the process
    }

    /**
     * Open a Real-Time Channel (Receiver Route Setup).
     */
    public static function channel(string $channel_id): PhroChannel
    {
        return new PhroChannel($channel_id);
    }

    /**
     * Publish data/command to a specific channel (Sender Route Setup).
     * @param string $channel_id The target channel.
     * @param string $command_name The command to trigger in the receiver.
     * @param mixed $data Payload to send.
     */
    public static function publish(string $channel_id, string $command_name, $data = null): bool
    {
        if (!class_exists('PHLS'))
            return false;

        $channel_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $channel_id);
        $queue_key = "phro_chan_" . $channel_id;

        $message = [
            'cmd' => $command_name,
            'data' => $data,
            't' => microtime(true)
        ];

        $queue = \PHLS::get($queue_key) ?: [];
        $queue[] = $message;

        if (count($queue) > 50) {
            $queue = array_slice($queue, -50);
        }

        $result = \PHLS::add($queue_key, $queue, 60);
        return (bool) $result;
    }

    /**
     * Match the route URL pattern with the current request URL.
     *
     * @param string $method HTTP method.
     * @param string $url Route URL pattern.
     * @param callable $callback Callback function to execute when route is matched.
     * @return void
     */
    private static function match($method, $url, $callback)
    {
        if (!self::$initialized) {
            self::initialize();
        }
        $route = [
            'name' => null,
            'short' => $url,
            'method' => strtoupper($method),
            'link' => self::root() . '/' . trim($url, '/'),
            'callback' => $callback,
            'callback_details' => [],
            'middlewares' => [],
        ];

        if (is_callable($callback)) {
            if ($callback instanceof \Closure) {
                $reflection = new \ReflectionFunction($callback);
                $route['callback_details'] = [
                    'type' => 'Closure',
                    'name' => null,
                    'file' => $reflection->getFileName(),
                    'start_line' => $reflection->getStartLine(),
                    'end_line' => $reflection->getEndLine(),
                    'parameters' => array_map(function ($param) {
                        return [
                            'name' => $param->getName(),
                            'optional' => $param->isOptional(),
                            'default' => $param->isOptional() ? $param->getDefaultValue() : null,
                        ];
                    }, $reflection->getParameters()),
                ];
            } elseif (is_array($callback) && count($callback) === 2) {
                $reflection = new \ReflectionMethod($callback[0], $callback[1]);
                $route['callback_details'] = [
                    'type' => 'Method',
                    'name' => $reflection->getName(),
                    'class' => $reflection->getDeclaringClass()->getName(),
                    'file' => $reflection->getFileName(),
                    'start_line' => $reflection->getStartLine(),
                    'end_line' => $reflection->getEndLine(),
                    'parameters' => array_map(function ($param) {
                        return [
                            'name' => $param->getName(),
                            'optional' => $param->isOptional(),
                            'default' => $param->isOptional() ? $param->getDefaultValue() : null,
                        ];
                    }, $reflection->getParameters()),
                ];
            } elseif (is_string($callback)) {
                $reflection = new \ReflectionFunction($callback);
                $route['callback_details'] = [
                    'type' => 'Function',
                    'name' => $reflection->getName(),
                    'file' => $reflection->getFileName(),
                    'start_line' => $reflection->getStartLine(),
                    'end_line' => $reflection->getEndLine(),
                    'parameters' => array_map(function ($param) {
                        return [
                            'name' => $param->getName(),
                            'optional' => $param->isOptional(),
                            'default' => $param->isOptional() ? $param->getDefaultValue() : null,
                        ];
                    }, $reflection->getParameters()),
                ];
            }
        }

        self::$routes[] = $route;

        if (self::$matched) {
            return;
        }

        if ($url === '/') {
            $current_url = ['/'];
        } else {
            $current_url = explode('/', trim($url, '/'));
        }

        $url_length = count($current_url);

        if ($method != self::$server_method) {
            return;
        }
        if ($url_length != count(self::$server_url)) {
            return;
        }

        $temp_params = []; // একটি অস্থায়ী ভ্যারিয়েবল ব্যবহার করা হচ্ছে
        $matched = true;

        for ($i = 0; $i < $url_length; $i++) {
            if ($current_url[$i] == self::$server_url[$i]) {
                continue;
            }
            if (isset($current_url[$i][0]) && $current_url[$i][0] == '@') {
                $temp_params[substr($current_url[$i], 1)] = self::$server_url[$i]; // প্যারামিটার অস্থায়ী ভ্যারিয়েবলে রাখা হচ্ছে
                continue;
            }
            $matched = false;
            break;
        }

        if ($matched) {
            self::$params = $temp_params; // রুট সম্পূর্ণ ম্যাচ হলেই self::$params আপডেট করা হচ্ছে

            self::$matched_route['short'] = $route['short'];
            self::$matched_route['link'] = $route['link'];
            self::$matched_route['method'] = $route['method'];
            self::$matched_route['callback_name'] = $route['callback_details']['name'] ?? 'Closure';
            self::$matched_route['callback_type'] = $route['callback_details']['type'];
            self::$matched_route['callback_file'] = $route['callback_details']['file'];
            self::$matched_route['callback_start'] = $route['callback_details']['start_line'];
            self::$matched_route['callback_end'] = $route['callback_details']['end_line'];
            self::$callback = $callback;
            self::$matched = true;
        }
    }

    /**
     * Get all defined routes or filter by short, link, and method.
     *
     * @param string|null $path The short route identifier or link to search for.
     * @param string|null $method The HTTP method to filter by (default: 'GET').
     * @return array|null All routes if $path is null/empty or a specific route if $path and $method match.
     */
    public static function routes($path = null, $method = 'GET')
    {
        $method = strtolower($method);
        if (empty($path)) {
            return self::$routes;
        }
        foreach (self::$routes as $route) {
            $isMatch = ((isset($route['short']) && $route['short'] === $path) || (isset($route['link']) && $route['link'] === $path));
            if ($isMatch && (!isset($route['method']) || $route['method'] === $method)) {
                return $route;
            }
        }
        return null;
    }

    /**
     * Finds routes, generates URLs, or returns the current route details.
     * 
     * Usage:
     * - PHRO::route() : Automatically detects current URL and returns the matching route details.
     * - PHRO::route('user.profile') : Returns details for named route 'user.profile'.
     * - PHRO::route('/admin/users') : Returns details for URL path '/admin/users'.
     * - PHRO::route('user.show', ['id' => 5]) : Returns route with 'resolved_link' (e.g., /user/5).
     *
     * @param string|null $identifier Optional. Route name, URL path, or null for auto-detection.
     * @param array|null $params Optional. Parameters for URL generation.
     * @return array|null Route details array or null if not found.
     */
    public static function route(?string $identifier = null, ?array $params = [])
    {
        if (!self::$initialized)
            self::initialize();

        // --- Case 1: Auto-Detection (If identifier is null) ---
        if ($identifier === null) {
            // ১. বর্তমান রিকোয়েস্ট URI এবং রুট পাথ সংগ্রহ করা
            $requestUri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
            $rootUrl = self::root();
            $rootPath = parse_url($rootUrl, PHP_URL_PATH);

            // ২. রুট পাথ (যেমন /myapp/) বাদ দিয়ে ক্লিন পাথ বের করা
            // টার্গেট: /myapp/user/profile -> user/profile
            $cleanPath = $requestUri;
            if ($rootPath && $rootPath !== '/' && strpos($requestUri, $rootPath) === 0) {
                $cleanPath = substr($requestUri, strlen($rootPath));
            }

            // ৩. স্ল্যাশ ক্লিনআপ এবং ফলব্যাক
            $identifier = '/' . ltrim($cleanPath, '/');

            // ৪. যদি একদম রুটে থাকে (খালি স্ট্রিং), তবে '/' সেট করা
            if ($identifier === '')
                $identifier = '/';
        }

        if (empty($identifier))
            return null;

        $found_route = null;

        // --- Step 1: Route Discovery Strategy ---

        // Strategy 1.A: Exact Name Match
        foreach (self::$routes as $route) {
            if (isset($route['name']) && $route['name'] === $identifier) {
                $found_route = $route;
                break;
            }
        }

        // Strategy 1.B: URL Path Match (Direct Match)
        if (!$found_route) {
            // ইনপুট যদি ডট নোটেশন হয় (admin.users), স্ল্যাশ এ কনভার্ট করা
            $url_to_find = '/' . ltrim(str_replace('.', '/', $identifier), '/');

            foreach (self::$routes as $route) {
                // এক্সাক্ট URL ম্যাচ
                if ($route['url'] === $url_to_find) {
                    $found_route = $route;
                    break;
                }
            }
        }

        // Strategy 1.C: Pattern Match (For Dynamic Routes like /user/@id)
        // যদি সরাসরি ম্যাচ না করে, আমরা প্যাটার্ন ম্যাচ করার চেষ্টা করব
        if (!$found_route) {
            $target_segments = explode('/', trim($identifier, '/'));

            foreach (self::$routes as $route) {
                $route_segments = explode('/', trim($route['url'], '/'));

                if (count($route_segments) !== count($target_segments))
                    continue;

                $is_match = true;
                $extracted_params = [];

                foreach ($route_segments as $index => $segment) {
                    if (isset($segment[0]) && $segment[0] === '@') {
                        // এটি একটি ডাইনামিক প্যারামিটার, ভ্যালু এক্সট্রাক্ট করো
                        $param_name = substr($segment, 1);
                        $extracted_params[$param_name] = $target_segments[$index];
                    } elseif ($segment !== $target_segments[$index]) {
                        $is_match = false;
                        break;
                    }
                }

                if ($is_match) {
                    $found_route = $route;
                    // অটোমেটিক প্যারামিটারগুলো মার্জ করা হচ্ছে
                    $params = array_merge($extracted_params, $params ?? []);
                    break;
                }
            }
        }

        if (!$found_route)
            return null;

        // --- Step 2: Parameter Resolution & Link Generation ---

        $url = $found_route['url'];
        $request_data = self::gatherRequestData();

        preg_match_all('/@(\w+)/', $url, $matches);
        $required_params = $matches[1];
        $all_params_resolved = true;

        foreach ($required_params as $param_name) {
            $value = null;

            // Priority 1: Manually passed params
            if (isset($params[$param_name])) {
                $value = $params[$param_name];
            }
            // Priority 2: Current Request URL Params (self::$params)
            elseif (isset(self::$params[$param_name])) {
                $value = self::$params[$param_name];
            }
            // Priority 3: Request Body/Query
            elseif (isset($request_data['data'][$param_name])) {
                $value = $request_data['data'][$param_name];
            }
            // Priority 4: Cookies
            elseif (isset($request_data['cookies'][$param_name])) {
                $value = $request_data['cookies'][$param_name];
            }

            if ($value !== null) {
                $url = str_replace('@' . $param_name, urlencode((string) $value), $url);
            } else {
                $all_params_resolved = false;
            }
        }

        // resolved_link তৈরি করা (Base Path সহ)
        if ($all_params_resolved) {
            $found_route['resolved_link'] = rtrim(self::root(), '/') . '/' . ltrim($url, '/');
        } else {
            // যদি প্যারামিটার রিজলভ না হয়, তবুও অরিজিনাল লিংকটা দিয়ে রাখা ভালো (টেমপ্লেটের জন্য)
            $found_route['resolved_link'] = rtrim(self::root(), '/') . '/' . ltrim($found_route['url'], '/');
        }

        // --- Step 3: Final Cleanup ---
        // কলব্যাক অবজেক্ট রিমুভ করা (সিকিউরিটি এবং ক্লিন আউটপুট)
        if (isset($found_route['callback'])) {
            unset($found_route['callback']);
        }

        return $found_route;
    }

    /**
     * Get the source code of the callback for the specified route.
     *
     * @param string $short The short URL pattern of the route.
     * @param string $method The HTTP method.
     * @return string|null The source code of the callback or null if not found.
     */
    public static function source($short, $method = 'GET')
    {
        foreach (self::$routes as $route) {
            if ($route['short'] === $short && $route['method'] === strtoupper($method)) {
                if (is_callable($route['callback'])) {
                    try {
                        $reflection = new \ReflectionFunction($route['callback']);
                        $file = $reflection->getFileName();
                        $startLine = $reflection->getStartLine() - 1;
                        $endLine = $reflection->getEndLine() - 0;

                        if (file_exists($file)) {
                            $lines = file($file, FILE_IGNORE_NEW_LINES);
                            $functionLines = array_slice($lines, $startLine, $endLine - $startLine);
                            return implode("\n", $functionLines);
                        } else {
                            return null;
                        }
                    } catch (\ReflectionException $e) {
                        return "Error: Unable to retrieve source code. " . $e->getMessage();
                    }
                }
            }
        }
        return null;
    }

    /**
     * Get the user's IP address.
     *
     * This function checks for the user's IP address from headers.
     *
     * @return string User's IP address.
     */
    public static function getUserIP()
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $remoteIsValid = filter_var($remote, FILTER_VALIDATE_IP) !== false;
        $trustedRequest = $remoteIsValid && self::isTrustedProxyRequest();

        if ($trustedRequest) {
            // Cloudflare's direct connecting-IP header is authoritative only
            // when the direct peer itself belongs to Cloudflare.
            if (self::isCloudflareProxyRequest()) {
                $cloudflareClient = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
                if (filter_var($cloudflareClient, FILTER_VALIDATE_IP)) return $cloudflareClient;
            }

            // Walk X-Forwarded-For from the trusted edge inward. This ignores
            // attacker-prepended values while retaining multi-proxy support.
            $forwarded = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))
            ), static fn($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false));
            $forwarded[] = $remote;
            for ($i = count($forwarded) - 1; $i >= 0; $i--) {
                if (!self::isTrustedProxyAddress($forwarded[$i])) return $forwarded[$i];
            }

            $realIp = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
            if (filter_var($realIp, FILTER_VALIDATE_IP)) return $realIp;
            return $remote;
        }

        if ($remoteIsValid) return $remote;

        $candidates = [];

        // Keep proxy headers as a fallback when the direct peer is unavailable.
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $candidates = array_merge($candidates, explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) $candidates[] = $_SERVER['HTTP_X_REAL_IP'];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }

    /**
     * Read cached tracker data from APCu when available, otherwise from a
     * non-public temporary directory.
     */
    private static function geoCacheFetch(string $key): ?array
    {
        $cacheKey = 'mystack_geo_' . hash('sha256', dirname(__DIR__) . '|' . $key);

        if (function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOL)) {
            $success = false;
            $cached = apcu_fetch($cacheKey, $success);
            if ($success && is_array($cached)) {
                return $cached;
            }
        }

        $cacheDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'mystack-geo-cache';
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $cacheKey) . '.json';
        if (!is_file($cacheFile) || !is_readable($cacheFile)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($cacheFile), true);
        if (
            !is_array($payload) ||
            !isset($payload['expires'], $payload['data']) ||
            (int) $payload['expires'] < time() ||
            !is_array($payload['data'])
        ) {
            return null;
        }

        return $payload['data'];
    }

    /**
     * Persist tracker data and return it for concise call sites.
     */
    private static function geoCacheStore(string $key, array $data, int $ttl = self::GEO_CACHE_TTL): array
    {
        $ttl = max(60, $ttl);
        $cacheKey = 'mystack_geo_' . hash('sha256', dirname(__DIR__) . '|' . $key);

        if (function_exists('apcu_store') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOL)) {
            apcu_store($cacheKey, $data, $ttl);
        }

        $cacheDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'mystack-geo-cache';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
            return $data;
        }

        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $cacheKey) . '.json';
        $payload = json_encode([
            'expires' => time() + $ttl,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload !== false) {
            file_put_contents($cacheFile, $payload, LOCK_EX);
        }

        return $data;
    }

    /**
     * Gathers input data from GET, POST, and raw body (JSON).
     * @return array
     */
    private static function gatherInputData()
    {
        $input_data = [];
        $raw_body = file_get_contents('php://input');

        if ($raw_body) {
            $json_data = json_decode($raw_body, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json_data)) {
                $input_data = $json_data;
            } else {
                // If not JSON, maybe form-data? Let's try parsing.
                parse_str($raw_body, $parsed_data);
                $input_data = $parsed_data;
            }
        }

        // POST data has higher priority than GET data if keys conflict
        return array_merge($_GET, $_POST, $input_data);
    }

    /**
     * Gathers all HTTP request headers.
     * @return array
     */
    public static function gatherHeaders()
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $value) {
                $headers[strtolower($key)] = $value;
            }
        } else {
            foreach ($_SERVER as $key => $value) {
                if (substr($key, 0, 5) === 'HTTP_') {
                    $header_key = strtolower(str_replace('_', '-', substr($key, 5)));
                    $headers[$header_key] = $value;
                }
            }
        }

        // --- Robust Authorization Fallback ---
        if (!isset($headers['authorization'])) {
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $headers['authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['PHP_AUTH_USER'])) {
                $pw = $_SERVER['PHP_AUTH_PW'] ?? '';
                $headers['authorization'] = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $pw);
            }
        }

        return $headers;
    }

    /**
     * Gathers and parses comprehensive information from the User-Agent string.
     * This function is self-contained and returns an array with agent details.
     *
     * @return array An associative array containing browser, platform, device, and other agent info.
     */
    private static function gatherAgentInfo()
    {
        $agent_info = [];

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $http_accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

        // Prioritize Sec-CH-UA headers for modern browsers
        $sec_ch_ua = $_SERVER['HTTP_SEC_CH_UA'] ?? ($_SERVER['Sec-Ch-Ua'] ?? '');

        // Default values
        $agent_info['browser'] = 'Unknown';
        $agent_info['browser_version'] = 'Unknown';
        $agent_info['platform'] = 'Unknown';
        $agent_info['platform_version'] = 'Unknown';
        $agent_info['device'] = 'Unknown';
        $agent_info['bot'] = 'no';
        $agent_info['mobile'] = false;
        $agent_info['desktop'] = true;
        $agent_info['accept_language'] = $http_accept_language;
        $agent_info['full_user_agent'] = $user_agent;

        if (empty($user_agent)) {
            $agent_info['browser'] = 'Script';
            $agent_info['platform'] = 'Server';
            $agent_info['device'] = 'Server';
            return $agent_info;
        }

        // Data definitions
        $browsers = [
            'Brave' => '/Brave\/([0-9.]+)/i',
            'Chrome' => '/Chrome\/([0-9.]+)/i',
            'Firefox' => '/Firefox\/([0-9.]+)/i',
            'Safari' => '/Safari\/([0-9.]+)(?!.*Chrome)/i',
            'Edge' => '/Edge\/([0-9.]+)/i',
            'Opera' => '/OPR\/([0-9.]+)/i',
            'IE' => '/MSIE ([0-9.]+);|Trident\/.*rv:([0-9.]+)/i',
            'PlayStation' => '/PlayStation 4|PlayStation Vita/i',
            'SamsungBrowser' => '/SamsungBrowser\/([0-9.]+)/i',
            'Xbox' => '/Xbox|Xbox Series X/i',
            'Vivaldi' => '/Vivaldi\/([0-9.]+)/i',
            'Chromium' => '/Chromium\/([0-9.]+)/i',
            'Silk' => '/Silk\/([0-9.]+)/i',
            'UCBrowser' => '/UCBrowser\/([0-9.]+)/i',
            'QQBrowser' => '/QQBrowser\/([0-9.]+)/i',
            'Maxthon' => '/Maxthon\/([0-9.]+)/i',
            'Pale Moon' => '/PaleMoon\/([0-9.]+)/i',
        ];
        $platforms = [
            'Windows' => '/Windows NT ([0-9.]+)/i',
            'Mac' => '/Mac OS X ([0-9._]+)/i',
            'Linux' => '/Linux/i',
            'iPhone' => '/iPhone; CPU iPhone OS ([0-9_]+)/i',
            'iPad' => '/iPad; CPU OS ([0-9_]+)/i',
            'Android' => '/Android ([0-9.]+)/i',
            'Xbox' => '/Xbox; Windows NT ([0-9.]+)/i',
            'PlayStation' => '/PlayStation 4|PlayStation Vita/i',
            'Chrome OS' => '/CrOS ([a-zA-Z0-9.]+)/i',
            'Nvidia Shield' => '/SHIELD Tablet K1/i',
            'Tizen' => '/Tizen/i',
            'Fire OS' => '/Fire OS\/([0-9.]+)/i',
            'BlackBerry' => '/BlackBerry|BB[0-9]+/i',
            'Symbian' => '/SymbianOS\/([0-9.]+)|SymbOS\/([0-9.]+)/i',
        ];
        $devices = [
            'Samsung' => '/SM-([A-Za-z0-9]+)/i',
            'Nvidia Shield' => '/SHIELD Tablet K1/i',
            'iPhone' => '/iPhone/i',
            'iPad' => '/iPad/i',
            'Xbox' => '/Xbox/i',
            'PlayStation' => '/PlayStation/i',
            'Google Pixel' => '/Pixel [0-9]+/i',
            'OnePlus' => '/ONEPLUS/i',
            'Huawei' => '/Huawei|HUAWEI/i',
            'Xiaomi' => '/Mi|Redmi/i',
            'LG' => '/LG-[A-Za-z0-9]+/i',
            'Motorola' => '/Moto[A-Za-z0-9]+/i',
            'Sony' => '/Sony[A-Za-z0-9]+/i',
            'HTC' => '/HTC[A-Za-z0-9]+/i',
            'Amazon Kindle' => '/Kindle Fire|Silk\/|Kindle/i',
            'Android' => '/Android/i',
        ];
        $bots = [
            'GoogleBot' => '/Googlebot/i',
            'YandexBot' => '/YandexBot/i',
            'DiscordBot' => '/Discordbot/i',
            'TwitterBot' => '/Twitterbot/i',
            'DuckDuckGoBot' => '/DuckDuckBot/i',
            'BaiduBot' => '/Baiduspider/i',
            'BingBot' => '/Bingbot/i',
            'SlurpBot' => '/Slurp/i',
            'UptimeRobot' => '/UptimeRobot/i',
            'SemrushBot' => '/SemrushBot/i',
            'MJ12bot' => '/MJ12bot/i',
            'PetalBot' => '/PetalBot/i',
            'DataForSeoBot' => '/DataForSeoBot/i',
        ];

        // 1. Detect Browser
        $browser_detected = false;
        if (!empty($sec_ch_ua)) {
            $parsed_sec_ch_ua = [];
            preg_match_all('/"([^"]+)";v="([^"]+)"/', $sec_ch_ua, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $parsed_sec_ch_ua[$match[1]] = $match[2];
            }

            if (isset($parsed_sec_ch_ua['Brave'])) {
                $agent_info['browser'] = 'Brave';
                $agent_info['browser_version'] = $parsed_sec_ch_ua['Brave'];
                $browser_detected = true;
            } elseif (isset($parsed_sec_ch_ua['Google Chrome'])) {
                $agent_info['browser'] = 'Chrome';
                $agent_info['browser_version'] = $parsed_sec_ch_ua['Google Chrome'];
                $browser_detected = true;
            } elseif (isset($parsed_sec_ch_ua['Chromium'])) {
                $agent_info['browser'] = 'Chromium';
                $agent_info['browser_version'] = $parsed_sec_ch_ua['Chromium'];
                $browser_detected = true;
            }
        }
        if (!$browser_detected) {
            foreach ($browsers as $browser => $regex) {
                if (preg_match($regex, $user_agent, $matches)) {
                    $agent_info['browser'] = $browser;
                    $agent_info['browser_version'] = $matches[2] ?? $matches[1] ?? 'Unknown';
                    break;
                }
            }
        }

        // 2. Detect Platform
        foreach ($platforms as $platform => $regex) {
            if (preg_match($regex, $user_agent, $matches)) {
                $agent_info['platform'] = $platform;
                $agent_info['platform_version'] = str_replace('_', '.', $matches[1] ?? 'Unknown');
                break;
            }
        }

        // 3. Detect Device
        foreach ($devices as $device => $regex) {
            if (preg_match($regex, $user_agent)) {
                $agent_info['device'] = $device;
                break;
            }
        }

        // 4. Detect Bot
        foreach ($bots as $bot => $regex) {
            if (preg_match($regex, $user_agent)) {
                $agent_info['bot'] = $bot;
                $agent_info['platform'] = 'Bot'; // If it's a bot, override platform
                break;
            }
        }

        // 5. Detect if mobile
        if (preg_match('/Mobile|Android|iPhone|iPad|Opera Mini|BlackBerry|webOS|UCBrowser|IEMobile|Silk/', $user_agent)) {
            $agent_info['mobile'] = true;
            $agent_info['desktop'] = false;
        }

        return $agent_info;
    }

    /**
     * Fetches user IP information with multiple fallbacks and low timeouts.
     * Order: ipwho.is -> ipapi.co -> geoplugin -> ip-api
     *
     * @param string $userIP User's IP address.
     * @return array IP information or empty array on failure.
     */
    private static function _fetchUserIPInfoWithRetry($userIP)
    {
        $userIP = trim(explode(',', (string) $userIP)[0]);

        // 1. List of API providers
        $providers = [
            'ipwhois' => "https://ipwho.is/{$userIP}",
            'ipapi' => "https://ipapi.co/{$userIP}/json/",
            'geoplugin' => "http://www.geoplugin.net/json.gp?ip={$userIP}",
            'ip-api' => "http://ip-api.com/json/{$userIP}?fields=status,message,country,countryCode,region,regionName,city,lat,lon,zip,timezone,isp,org,as,mobile,proxy,hosting,query"
        ];

        $default_ip_info = [
            'countryCode' => null,
            'country' => null,
            'regionName' => null,
            'city' => null,
            'lat' => null,
            'lon' => null,
            'zip' => null,
            'timezone' => null,
            'isp' => null,
            'org' => null,
            'as' => null,
            'proxy' => null,
            'query' => $userIP
        ];

        $cached = self::geoCacheFetch('client:' . $userIP);
        if ($cached !== null) {
            return $cached;
        }

        if (!filter_var(
            $userIP,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            return self::geoCacheStore(
                'client:' . $userIP,
                $default_ip_info,
                self::GEO_FAILURE_CACHE_TTL
            );
        }
        if (!function_exists('curl_init')) {
            return self::geoCacheStore(
                'client:' . $userIP,
                $default_ip_info,
                self::GEO_FAILURE_CACHE_TTL
            );
        }

        foreach ($providers as $source => $url) {
            // Primary gets 3s, Fallbacks get 1-2s to fail fast
            $timeout = ($source === 'ipwhois') ? 3 : 2;

            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1); // Connect extremely fast
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Compatible; GeoFetcher/1.0)');

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if (curl_errno($ch) || $httpCode !== 200) {
                    // Log only if needed, kept silent to keep logs clean during fallback switch
                    if ($ch !== false) { curl_close($ch); }
                    continue; // Move to next provider
                }
                if ($ch !== false) { curl_close($ch); }

                $data = json_decode($response, true);
                if (!$data)
                    continue;

                // --- DATA MAPPING STRATEGY ---
                $mapped = $default_ip_info;

                if ($source === 'ipwhois' && isset($data['success']) && $data['success'] === true) {
                    $security = (array) ($data['security'] ?? []);
                    $mapped = [
                        'countryCode' => $data['country_code'] ?? null,
                        'country' => $data['country'] ?? null,
                        'regionName' => $data['region'] ?? null,
                        'city' => $data['city'] ?? null,
                        'lat' => $data['latitude'] ?? null,
                        'lon' => $data['longitude'] ?? null,
                        'zip' => $data['postal'] ?? null,
                        'timezone' => $data['timezone']['id'] ?? null,
                        'isp' => $data['connection']['isp'] ?? null,
                        'org' => $data['connection']['org'] ?? null,
                        'as' => isset($data['connection']['asn']) ? "AS" . $data['connection']['asn'] : null,
                        'query' => $data['ip'] ?? $userIP,
                        'proxy' => (bool) ($security['proxy'] ?? $security['vpn'] ?? $security['tor'] ?? $security['anonymous'] ?? false),
                        'hosting' => (bool) ($security['hosting'] ?? false)
                    ];
                    return self::geoCacheStore('client:' . $userIP, $mapped);
                } elseif ($source === 'ipapi' && empty($data['error'])) {
                    $mapped = [
                        'countryCode' => $data['country_code'] ?? null,
                        'country' => $data['country_name'] ?? null,
                        'regionName' => $data['region'] ?? null,
                        'city' => $data['city'] ?? null,
                        'lat' => $data['latitude'] ?? null,
                        'lon' => $data['longitude'] ?? null,
                        'zip' => $data['postal'] ?? null,
                        'timezone' => $data['timezone'] ?? null,
                        'isp' => $data['org'] ?? null, // ipapi puts ISP/Org loosely
                        'org' => $data['org'] ?? null,
                        'as' => $data['asn'] ?? null,
                        'query' => $data['ip'] ?? $userIP,
                    ];
                    return self::geoCacheStore('client:' . $userIP, $mapped);
                } elseif ($source === 'geoplugin' && isset($data['geoplugin_countryCode'])) {
                    $mapped = [
                        'countryCode' => $data['geoplugin_countryCode'] ?? null,
                        'country' => $data['geoplugin_countryName'] ?? null,
                        'regionName' => $data['geoplugin_regionName'] ?? null,
                        'city' => $data['geoplugin_city'] ?? null,
                        'lat' => $data['geoplugin_latitude'] ?? null,
                        'lon' => $data['geoplugin_longitude'] ?? null,
                        'zip' => null, // geoplugin rarely gives zip free
                        'timezone' => $data['geoplugin_timezone'] ?? null,
                        'isp' => null,
                        'org' => null,
                        'as' => null,
                        'query' => $data['geoplugin_request'] ?? $userIP,
                    ];
                    return self::geoCacheStore('client:' . $userIP, $mapped);
                } elseif ($source === 'ip-api' && isset($data['status']) && $data['status'] === 'success') {
                    // Your original structure
                    unset($data['status'], $data['message']);
                    return self::geoCacheStore(
                        'client:' . $userIP,
                        array_merge($default_ip_info, $data)
                    );
                }

            } catch (\Throwable $e) {
                // Should not happen due to try/catch, but safety net
                error_log("Geo Error {$source}: " . $e->getMessage());
            }
        }

        error_log("All Geo-IP providers failed for IP: {$userIP}");
        return self::geoCacheStore(
            'client:' . $userIP,
            $default_ip_info,
            self::GEO_FAILURE_CACHE_TTL
        );
    }

    /**
     * Fetches Server/Caller Geolocation with multiple fallbacks.
     */
    public static function getGeolocationData()
    {
        // List of API providers (Auto-detect IP versions)
        $providers = [
            'ipwhois' => "https://ipwho.is/",
            'ipapi' => "https://ipapi.co/json/",
            'geoplugin' => "http://www.geoplugin.net/json.gp",
            'ip-api' => "http://ip-api.com/json/?fields=status,message,country,countryCode,region,regionName,city,lat,lon,zip,timezone,isp,org,as,query"
        ];

        $default_geo_data = [
            'query' => null,
            'lat' => null,
            'lon' => null,
            'countryCode' => null,
            'country' => null,
            'city' => null,
            'regionName' => null,
            'isp' => null,
            'org' => null,
            'as' => null,
            'proxy' => null,
            'zip' => null,
            'timezone' => null
        ];

        $cached = self::geoCacheFetch('server');
        if ($cached !== null) {
            return $cached;
        }
        if (!function_exists('curl_init')) {
            return self::geoCacheStore('server', $default_geo_data, self::GEO_FAILURE_CACHE_TTL);
        }

        foreach ($providers as $source => $url) {
            // Timeout: 3s for Primary, 2s for fallbacks to ensure page loads fast
            $timeout = ($source === 'ipwhois') ? 3 : 2;

            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Compatible; GeoCaller/1.0)');

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if (curl_errno($ch) || $httpCode !== 200) {
                    if ($ch !== false) { curl_close($ch); }
                    continue;
                }
                if ($ch !== false) { curl_close($ch); }

                $data = json_decode($response, true);
                if (!$data)
                    continue;

                // --- DATA MAPPING ---

                // 1. ipwho.is
                if ($source === 'ipwhois' && isset($data['success']) && $data['success'] === true) {
                    return self::geoCacheStore('server', [
                        'query' => $data['ip'] ?? null,
                        'lat' => $data['latitude'] ?? null,
                        'lon' => $data['longitude'] ?? null,
                        'countryCode' => $data['country_code'] ?? null,
                        'country' => $data['country'] ?? null,
                        'city' => $data['city'] ?? null,
                        'regionName' => $data['region'] ?? null,
                        'zip' => $data['postal'] ?? null,
                        'timezone' => $data['timezone']['id'] ?? null,
                        'isp' => $data['connection']['isp'] ?? null,
                        'org' => $data['connection']['org'] ?? null,
                        'as' => isset($data['connection']['asn']) ? "AS" . $data['connection']['asn'] : null,
                        'proxy' => null
                    ], 86400);
                }

                // 2. ipapi.co
                elseif ($source === 'ipapi' && empty($data['error'])) {
                    return self::geoCacheStore('server', [
                        'query' => $data['ip'] ?? null,
                        'lat' => $data['latitude'] ?? null,
                        'lon' => $data['longitude'] ?? null,
                        'countryCode' => $data['country_code'] ?? null,
                        'country' => $data['country_name'] ?? null,
                        'city' => $data['city'] ?? null,
                        'regionName' => $data['region'] ?? null,
                        'zip' => $data['postal'] ?? null,
                        'timezone' => $data['timezone'] ?? null,
                        'isp' => $data['org'] ?? null,
                        'org' => $data['org'] ?? null,
                        'as' => $data['asn'] ?? null,
                        'proxy' => null
                    ], 86400);
                }

                // 3. geoplugin
                elseif ($source === 'geoplugin' && isset($data['geoplugin_countryCode'])) {
                    return self::geoCacheStore('server', [
                        'query' => $data['geoplugin_request'] ?? null,
                        'lat' => $data['geoplugin_latitude'] ?? null,
                        'lon' => $data['geoplugin_longitude'] ?? null,
                        'countryCode' => $data['geoplugin_countryCode'] ?? null,
                        'country' => $data['geoplugin_countryName'] ?? null,
                        'city' => $data['geoplugin_city'] ?? null,
                        'regionName' => $data['geoplugin_regionName'] ?? null,
                        'zip' => null,
                        'timezone' => $data['geoplugin_timezone'] ?? null,
                        'isp' => null,
                        'org' => null,
                        'as' => null,
                        'proxy' => null
                    ], 86400);
                }

                // 4. ip-api
                elseif ($source === 'ip-api' && isset($data['status']) && $data['status'] === 'success') {
                    unset($data['status'], $data['message']);
                    return self::geoCacheStore(
                        'server',
                        array_merge($default_geo_data, $data),
                        86400
                    );
                }

            } catch (\Throwable $e) {
                // Silent fail to next provider
            }
        }

        error_log("Max retries reached for Geolocation API. Using default geo data.");
        return self::geoCacheStore(
            'server',
            $default_geo_data,
            self::GEO_FAILURE_CACHE_TTL
        );
    }


    /**
     * Extracts client identity data from the cookie if available.
     * If not found, it returns default data with a placeholder message.
     *
     * @return array Client identity information or default message.
     */
    public static function extractIdentityFromCookie()
    {
        $defaultData = [
            'clientXIP' => null,
            'clientXPlatform' => null,
            'clientXBrowser' => null,
            'clientXCountryCode' => null,
            'clientXCountry' => null,
            'clientXCity' => null,
            'clientXArea' => null,
            'clientXLat' => null,
            'clientXLon' => null,
            'clientXZip' => null,
            'clientXTimezone' => null, // Added timezone
            'clientXIsp' => null,
            'clientXOrg' => null,      // Added org
            'clientXAs' => null,       // Added as
            'clientXMobile' => null,    // Added mobile
            'clientXProxy' => null,     // Added proxy
            'clientXDevicekey' => null,
            'clientXNetkey' => null,
            'clientXHash' => null,
            'clientXFingerprint' => null,
        ];

        $encryptedIdentityCookie = class_exists('PHCO')
            ? \PHCO::get('identity')
            : ($_COOKIE['identity'] ?? null);

        if (!is_string($encryptedIdentityCookie) || $encryptedIdentityCookie === '') {
            return $defaultData;
        }

        $decryptedIdentity = self::decrypt($encryptedIdentityCookie);

        if ($decryptedIdentity === null || !isset($decryptedIdentity['identity'])) {
            // Cookie exists but decryption failed or no identity data
            // You might want to handle invalid cookies (e.g., delete them)
            if (class_exists('PHCO')) {
                \PHCO::remove('identity');
            } else {
                setcookie('identity', '', time() - 3600, '/');
            }
            return $defaultData; // Return default data or handle as needed
        }

        $identityData = $decryptedIdentity['identity'];

        $mappedData = [
            'clientXIP' => $identityData['ip'] ?? $defaultData['clientXIP'], // Use null coalescing operator
            'clientXPlatform' => $identityData['environment']['platform'] ?? $defaultData['clientXPlatform'],
            'clientXBrowser' => $identityData['environment']['browser'] ?? $defaultData['clientXBrowser'],
            'clientXCountryCode' => $identityData['location']['countryCode'] ?? $defaultData['clientXCountryCode'],
            'clientXCountry' => $identityData['location']['country'] ?? $defaultData['clientXCountry'],
            'clientXCity' => $identityData['location']['city'] ?? $defaultData['clientXCity'],
            'clientXArea' => $identityData['location']['area'] ?? $defaultData['clientXArea'],
            'clientXLat' => $identityData['location']['lat'] ?? $defaultData['clientXLat'],
            'clientXLon' => $identityData['location']['lon'] ?? $defaultData['clientXLon'],
            'clientXZip' => $identityData['location']['zip'] ?? $defaultData['clientXZip'],
            'clientXTimezone' => $identityData['location']['timezone'] ?? $defaultData['clientXTimezone'], // Added timezone
            'clientXIsp' => $identityData['location']['isp'] ?? $defaultData['clientXIsp'],
            'clientXOrg' => $identityData['location']['org'] ?? $defaultData['clientXOrg'],        // Added org
            'clientXAs' => $identityData['location']['as'] ?? $defaultData['clientXAs'],         // Added as
            'clientXMobile' => $identityData['location']['mobile'] ?? $defaultData['clientXMobile'],  // Added mobile
            'clientXProxy' => $identityData['location']['proxy'] ?? $defaultData['clientXProxy'],   // Added proxy
            'clientXDevicekey' => $identityData['id']['device'] ?? $defaultData['clientXDevicekey'],
            'clientXNetkey' => $identityData['id']['netkey'] ?? $defaultData['clientXNetkey'],
            'clientXHash' => $identityData['id']['hash'] ?? $defaultData['clientXHash'],
            'clientXFingerprint' => $identityData['id']['fingerprint'] ?? $defaultData['clientXFingerprint'],
        ];

        return $mappedData;
    }

    /**
     * Perform an HTTP GET request with a specified timeout.
     *
     * @param string $url The URL to fetch data from.
     * @param int $timeout The timeout duration in seconds.
     * @return string The response body.
     * @throws Exception If the request fails or times out.
     */
    private static function getHTTPResponse($url, $timeout)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $curl_error = curl_error($ch);
            if (is_resource($ch)) { curl_close($ch); }
            throw new Exception('Curl error: ' . $curl_error);
        }

        if (is_resource($ch)) { curl_close($ch); }
        return $response;
    }

    /**
     * Create an unchangeable network identity key.
     * This function generates a strong, unique identity key based on
     * extensive user network-related data and dynamic salting.
     *
     * @param array $data Network information and headers.
     * @return string Unchangeable identity key (hash).
     */
    public static function netKey($data): string
    {
        try {
            // --- 1. Define the most exhaustive list of network-specific keys ---
            $identityKeys = [
                'clientIP',
                'latitude',
                'longitude',
                'city',
                'country',
                'countryCode',
                'regionName',
                'timezone',
                'proxy',
                'isp',
                'zip',
                'org',
                'as',
                'mobile_isp',
                'reverse_dns', // New: Mobile ISP, Reverse DNS

                // Server-side network parameters (more stable)
                'HTTP_HOST',
                'SERVER_NAME',
                'SERVER_ADDR',
                'REMOTE_ADDR',
                'SERVER_PORT',
                'HTTPS',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_REAL_IP',
                'HTTP_CF_CONNECTING_IP',
                'HTTP_CLIENT_IP', // Proxy headers
            ];

            $identityData = [];
            foreach ($identityKeys as $key) {
                $val = $data[strtolower($key)] ?? $data[strtoupper($key)] ?? $data[$key] ?? '';
                if (is_array($val))
                    $val = json_encode($val);
                $identityData[$key] = (string) $val;
            }

            // --- 2. Sort for consistent hashing ---
            ksort($identityData);
            $identityString = json_encode($identityData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // --- 3. Dynamic Salting ---
            $userAgentForSalt = $data['full_user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
            $clientIPForSalt = $data['clientIP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');

            $dynamicSalt = hash('sha256', $clientIPForSalt . $userAgentForSalt . ($_SERVER['HTTP_HOST'] ?? ''));
            $secretKey = self::$key . $dynamicSalt;

            if (empty($secretKey) || strlen($secretKey) < 16) {
                $secretKey = self::$key . ($data['clientIP'] ?? 'fallback_salt_net');
                if (strlen($secretKey) < 16)
                    $secretKey = self::$key . 'min_length_fallback_net';
            }

            // --- 4. Generate the robust HMAC hash ---
            $identityKey = hash_hmac('sha512', $identityString, $secretKey);

            return $identityKey;
        } catch (\Throwable $e) {
            error_log('PHRO NetKey Error: ' . $e->getMessage());
            return hash('sha512', ($data['clientIP'] ?? '') . ($data['REMOTE_ADDR'] ?? '') . self::$key . 'error_fallback_net_key');
        }
    }

    /**
     * Create an unchangeable device identity key (The Fingerprint Master).
     * This function generates a robust, unique identity key based on
     * an extensive range of user device-related data points and dynamic salting.
     * It's designed to be highly resilient to spoofing and changes.
     *
     * @param array $data Device information and headers (already gathered from client).
     * @return string Unchangeable device identity key (hash).
     */
    public static function deviceKey($data): string
    {
        try {
            // --- 1. Define the most exhaustive list of device-specific keys ---
            $identityKeys = [
                // User-Agent & Client Hints (Browser/OS/Device info)
                'mobile',
                'desktop',
                'browser',
                'browser_version',
                'platform',
                'platform_version',
                'device',
                'full_user_agent',
                'accept_language',
                'HTTP_SEC_CH_UA',
                'HTTP_SEC_CH_UA_MOBILE',
                'HTTP_SEC_CH_UA_PLATFORM',
                'HTTP_DNT',
                'DNT', // Do Not Track
                'HTTP_ACCEPT',
                'Accept',
                'HTTP_ACCEPT_ENCODING',
                'Accept-Encoding',
                'HTTP_ACCEPT_LANGUAGE',

                // Display/Hardware (Often comes from JS fingerprinting or advanced headers)
                'screen_res',
                'color_depth',
                'pixel_ratio',
                'timezone_offset',
                'timezone',
                'device_memory',
                'hardware_concurrency', // Device RAM, CPU cores

                // Canvas Fingerprint (From JS - if provided via a hidden field or header)
                'canvas_fingerprint',
            ];

            $identityData = [];
            foreach ($identityKeys as $key) {
                // Collect data, falling back to empty string for missing values.
                // Normalize keys to lowercase for consistency.
                $val = $data[strtolower($key)] ?? $data[strtoupper($key)] ?? $data[$key] ?? '';
                if (is_array($val))
                    $val = json_encode($val); // Ensure arrays are consistent
                $identityData[$key] = (string) $val;
            }

            // --- 2. Sort the data array alphabetically for consistent hashing ---
            // This makes the hash "order-agnostic" to how data is collected.
            ksort($identityData);
            $identityString = json_encode($identityData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // --- 3. Dynamic Salting for extra security ---
            // Combine a static secret with user-specific semi-permanent data.
            $userAgentForSalt = $data['full_user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
            $clientIPForSalt = $data['clientIP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');

            $dynamicSalt = hash('sha256', $clientIPForSalt . $userAgentForSalt . ($_SERVER['HTTP_HOST'] ?? ''));
            $secretKey = self::$key . $dynamicSalt; // Mix static secret with dynamic salt

            if (empty($secretKey) || strlen($secretKey) < 16) {
                $secretKey = self::$key . ($data['clientIP'] ?? 'fallback_salt');
                if (strlen($secretKey) < 16)
                    $secretKey = self::$key . 'min_length_fallback';
            }

            // --- 4. Generate the robust HMAC hash ---
            $deviceKey = hash_hmac('sha512', $identityString, $secretKey);

            return $deviceKey;
        } catch (\Throwable $e) {
            error_log('PHRO DeviceKey Error: ' . $e->getMessage());
            // Fallback to a less specific but still unique key on error.
            return hash('sha512', ($data['clientIP'] ?? '') . ($data['full_user_agent'] ?? '') . self::$key . 'error_fallback_key');
        }
    }

    /**
     * Encrypt the data array
     *
     * @param array $data The data to encrypt
     * @return string|null Encrypted data (base64url encoded) or null on failure
     */
    private static function encrypt($data)
    {
        try {
            $secretKey = self::$key;
            if (empty($secretKey) || strlen($secretKey) < 18) {
                throw new Exception('Encryption key is not properly set.');
            }

            $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-gcm'));

            $encryptedData = openssl_encrypt(
                json_encode($data),
                'aes-256-gcm',
                $secretKey,
                0,
                $iv,
                $tag
            );

            if ($encryptedData === false) {
                throw new Exception('Encryption failed: ' . openssl_error_string());
            }

            $output = $iv . $tag . $encryptedData;
            return rtrim(strtr(base64_encode($output), '+/', '-_'), '=');
        } catch (Exception $e) {
            error_log('Encryption Error: ' . $e->getMessage()); // Log encryption errors
            return null;
        }
    }

    /**
     * Decrypt the encrypted data
     *
     * @param string $encryptedData The base64url encoded encrypted data
     * @return array|null Decrypted data or null on failure
     */
    public static function decrypt($encryptedData)
    {
        try {
            $secretKey = self::$key;
            if (empty($secretKey) || strlen($secretKey) < 18) {
                throw new Exception('Decryption key is not properly set.');
            }

            $decodedData = base64_decode(strtr($encryptedData, '-_', '+/'));

            $ivLength = openssl_cipher_iv_length('aes-256-gcm');
            $tagLength = 16;
            $iv = substr($decodedData, 0, $ivLength);
            $tag = substr($decodedData, $ivLength, $tagLength);
            $encryptedPayload = substr($decodedData, $ivLength + $tagLength);
            $decryptedData = openssl_decrypt($encryptedPayload, 'aes-256-gcm', $secretKey, 0, $iv, $tag);

            if ($decryptedData === false) {
                throw new Exception('Decryption failed: ' . openssl_error_string());
            }

            return json_decode($decryptedData, true);
        } catch (Exception $e) {
            error_log('Decryption Error: ' . $e->getMessage()); // Log decryption errors
            return null;
        }
    }

    /**
     * Updates the default encryption key.
     *
     * @param string $new_key The new encryption key.
     * @param bool $dataPrint Enable or disable encryption data print.
     * @return array
     */
    public static function key($new_key, $dataPrint = false)
    {
        try {
            self::$printData = $dataPrint;
            if (!empty($new_key) && strlen($new_key) >= 18) {
                self::$key = $new_key;
                return ['status' => true, 'message' => 'Key updated successfully.', 'data' => null];
            } else {
                throw new Exception('New key must be at least 18 characters long.');
            }
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Init the footprint/track.
     *
     * @param bool $footprint Enable or disable.
     */
    public static function track($footprint = false)
    {
        self::$footprint = $footprint;
    }

    /**
     * Collects and processes exhaustive request data, generates robust unique identifiers,
     * and handles encryption, building a bulletproof client "footprint".
     *
     * @return array Updated and enriched request parameters.
     */
    public static function footprint(): array
    {
        // Retrieve cached footprint if available (for performance within a single request)
        if (self::$footprint_cache !== null) {
            return self::$footprint_cache;
        }

        // --- 1. Consolidate Raw Input Data ---
        // Gather all possible raw data points into one array.
        $raw_data = array_merge(
            $_SERVER,
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES,
            $_REQUEST,
            self::gatherHeaders(),
            ['raw_body' => file_get_contents('php://input')]
        );

        // Parse JSON raw body if present
        $decoded_raw_body = json_decode($raw_data['raw_body'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_raw_body)) {
            $raw_data = array_merge($raw_data, $decoded_raw_body);
        }

        // --- 2. Initialize self::$params with transformed agent info ---
        // Start with a clean self::$params. Agent info is a core part of client identity.
        $agentInfo = self::gatherAgentInfo();
        self::$params = $agentInfo; // Start self::$params with client's agent info

        // --- 3. Add Client IP ---
        self::$params['clientIP'] = self::getUserIP();

        // --- 4. Merge Cookie-based Identity (highest priority for existing client IDs) ---
        // This will bring clientXDevicekey, clientXNetkey, etc. if present in cookie.
        $cookie_identity_decoded = self::extractIdentityFromCookie();
        self::$params = array_merge(self::$params, $cookie_identity_decoded);

        // --- 5. Conditional Geo-IP Fetch (only if tracking is enabled) ---
        $ipInfo = [];
        if (self::$footprint === true) {
            $ipInfo = self::_fetchUserIPInfoWithRetry(self::$params['clientIP']);
        }
        // Tracker data must describe the visitor, not the application server.
        self::$params = array_merge(self::$params, $ipInfo);

        // --- 6. Generate/Validate Robust Identity Keys (ClientDevicekey, ClientNetkey, ClientHash, ClientFingerprint) ---
        // The goal is to always have valid, 16-char keys.

        // Resolve initial values from cookie or default to null for generation.
        $finalClientDevicekey = self::$params['clientXDevicekey'] ?? null;
        $finalClientNetkey = self::$params['clientXNetkey'] ?? null;
        $finalClientHash = self::$params['clientXHash'] ?? null;

        // Generate new if missing or invalid (length check for 16)
        if (empty($finalClientDevicekey) || strlen($finalClientDevicekey) !== 16) {
            $finalClientDevicekey = substr(self::deviceKey($raw_data), 0, 16);
        }
        if (empty($finalClientNetkey) || strlen($finalClientNetkey) !== 16) {
            $finalClientNetkey = substr(self::netKey($raw_data), 0, 16);
        }
        if (empty($finalClientHash) || strlen($finalClientHash) !== 16) {
            if (class_exists('PHCO')) {
                // Attempt to get hash from PHCO, or generate a robust fallback.
                $phco_hash = \PHCO::get("hash")
                    ?? ($raw_data['HTTP_X_DEVICE_FINGERPRINT'] ?? $raw_data['X-Device-Fingerprint'] ?? null);
                if (!is_string($phco_hash) || !preg_match('/^[a-f0-9]{16}$/i', $phco_hash)) {
                    $phco_hash = null;
                }
                $finalClientHash = (empty($phco_hash) || strlen($phco_hash) !== 16) ? substr(hash('sha256', uniqid('phco_fallback_', true)), 0, 16) : $phco_hash;
            } else {
                $finalClientHash = substr(hash('sha256', uniqid('no_phco_hash_', true)), 0, 16);
            }
        }

        // Store final validated/generated keys in self::$params
        self::$params['clientDevicekey'] = $finalClientDevicekey;
        self::$params['clientNetkey'] = $finalClientNetkey;
        self::$params['clientHash'] = $finalClientHash;

        // Generate the Super-Composite Fingerprint
        $compositeFingerprintData = [
            'net' => self::$params['clientNetkey'],
            'device' => self::$params['clientDevicekey'],
            'hash' => self::$params['clientHash'],
            'user_agent' => $raw_data['HTTP_USER_AGENT'] ?? '',
            'ip' => self::$params['clientIP'],
            'host' => $raw_data['HTTP_HOST'] ?? ''
        ];
        ksort($compositeFingerprintData);
        $compositeFingerprintString = json_encode($compositeFingerprintData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::$params['clientFingerprint'] = substr(hash_hmac('sha512', $compositeFingerprintString, self::$key . (self::$params['clientIP'] ?? 'fingerprint_salt')), 0, 16);


        // --- 7. Set/Update Identity Cookie ---
        // Internal PHFY configuration still receives guard/rate-limit protection,
        // but does not need to refresh the large tracking cookie on every poll.
        if (self::shouldRefreshIdentityCookie()) {
            self::setIdentityCookie(self::$params);
        }

        // --- 8. Final Transformation (to clientX prefixed keys for final output) ---
        // This is the last step for consistency, after all internal processing is done.
        $keysToTransform = [
            'ip',
            'platform',
            'browser',
            'countryCode',
            'country',
            'city',
            'area',
            'lat',
            'lon',
            'zip',
            'timezone',
            'isp',
            'org',
            'as',
            'mobile',
            'proxy',
            'devicekey',
            'netkey',
            'hash',
            'fingerprint'
        ];
        self::$params = self::transformKeys(self::$params, $keysToTransform);

        // --- 9. Merge all other raw data into self::$params at the end ---
        // This ensures all $_SERVER, $_GET, etc. are also available, but specific clientX keys are prioritized.
        // Also merge back raw_data to have all original headers etc.
        self::$params = array_merge($raw_data, self::$params);

        // --- 10. Handle Encrypted Data Print ---
        if (self::$printData === true) {
            $encryptData = ["encryptdata" => self::encrypt(self::$params)];
            self::$params = array_merge(self::$params, $encryptData);
        }

        self::$footprint_cache = self::$params; // Cache for current request
        return self::$params;
    }

    /**
     * Keeps tracking enabled while avoiding redundant cookie refreshes on
     * framework-internal configuration requests.
     */
    private static function shouldRefreshIdentityCookie(): bool
    {
        if (class_exists('PHMO', false)
            && method_exists('PHMO', 'isProbeRequest')
            && PHMO::isProbeRequest()) {
            return false;
        }

        $request_path = rawurldecode((string) parse_url(
            $_SERVER['REQUEST_URI'] ?? '/',
            PHP_URL_PATH
        ));
        $base_path = '/' . trim((string) self::$base_path, '/');
        if ($base_path !== '/' && (
            $request_path === $base_path
            || str_starts_with($request_path, $base_path . '/')
        )) {
            $request_path = substr($request_path, strlen($base_path)) ?: '/';
        }

        return ('/' . ltrim($request_path, '/')) !== '/_phfy/config';
    }

    /**
     * Sets the identity cookie with encrypted user identity data.
     *
     * @param array $params The array of user parameters collected by `footprint`.
     * @param int $expiryTime (optional) Cookie expiry time in seconds from now. Default is 1 year.
     * @return bool True if cookie was successfully set, false otherwise.
     */
    public static function setIdentityCookie($params, $expiryTime = 525600): bool
    {
        // Ensure that clientX prefixed data is used for the cookie payload if present
        // Otherwise, use the original non-prefixed keys after they've been gathered in footprint()
        $identityData = [
            'identity' => [
                'ip' => $params['clientIP'] ?? null,
                'environment' => [
                    'platform' => $params['clientPlatform'] ?? $params['platform'] ?? null,
                    'browser' => $params['clientXBrowser'] ?? $params['browser'] ?? null,
                ],
                'location' => [
                    'countryCode' => $params['clientXCountryCode'] ?? $params['countryCode'] ?? null,
                    'country' => $params['clientXCountry'] ?? $params['country'] ?? null,
                    'city' => $params['clientXCity'] ?? $params['city'] ?? null,
                    'area' => $params['clientXArea'] ?? $params['regionName'] ?? null,
                    'lat' => $params['clientXLat'] ?? $params['lat'] ?? null,
                    'lon' => $params['clientXLon'] ?? $params['lon'] ?? null,
                    'zip' => $params['clientXZip'] ?? $params['zip'] ?? null,
                    'timezone' => $params['clientXTimezone'] ?? $params['timezone'] ?? null,
                    'isp' => $params['clientXIsp'] ?? $params['isp'] ?? null,
                    'org' => $params['clientXOrg'] ?? $params['org'] ?? null,
                    'proxy' => $params['clientXProxy'] ?? $params['proxy'] ?? null,
                ],
                'id' => [
                    'device' => $params['clientDevicekey'] ?? null,
                    'netkey' => $params['clientNetkey'] ?? null,
                    'hash' => $params['clientHash'] ?? null,
                    'fingerprint' => $params['clientFingerprint'] ?? null,
                    'fingerprintAlgorithm' => 'sha512-hmac-composite',
                ],
            ]
        ];

        $encryptedIdentity = self::encrypt($identityData);

        if ($encryptedIdentity === null) {
            return false;
        }

        return class_exists('PHCO') ? \PHCO::add('identity', $encryptedIdentity, $expiryTime) : false;
    }

    /**
     * Extract comprehensive information from the HTTP_USER_AGENT string and store it in $params.
     *
     * @return void
     */
    public static function userAgentInfo()
    {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? ''; // 1st priority = HTTP_USER_AGENT (Environment Variable)
        $sec_ch_ua_headers = [
            'HTTP_SEC_CH_UA', // Added HTTP_ prefix for server environment
            'Sec-Ch-Ua'       // Standard header name
        ];
        $sec_ch_ua = ''; // 2nd priority = Sec-Ch-Ua
        foreach ($sec_ch_ua_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $sec_ch_ua = $_SERVER[$header];
                break; // Use the first available Sec-Ch-Ua header
            }
        }
        $http_accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

        $parsed_sec_ch_ua = [];
        if (!empty($sec_ch_ua)) {
            preg_match_all('/"([^"]+)";v="([^"]+)"/', $sec_ch_ua, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $parsed_sec_ch_ua[$match[1]] = $match[2];
            }
        }

        $browsers = [
            'Brave' => '/Brave\/([0-9.]+)/i',
            'Chrome' => '/Chrome\/([0-9.]+)/i',
            'Firefox' => '/Firefox\/([0-9.]+)/i',
            'Safari' => '/Safari\/([0-9.]+)(?!.*Chrome)/i',
            'Edge' => '/Edge\/([0-9.]+)/i',
            'Opera' => '/OPR\/([0-9.]+)/i',
            'IE' => '/MSIE ([0-9.]+);|Trident\/.*rv:([0-9.]+)/i',
            'PlayStation' => '/PlayStation 4|PlayStation Vita/i',
            'SamsungBrowser' => '/SamsungBrowser\/([0-9.]+)/i',
            'Xbox' => '/Xbox|Xbox Series X/i',
            'Vivaldi' => '/Vivaldi\/([0-9.]+)/i',
            'Chromium' => '/Chromium\/([0-9.]+)/i',
            'Silk' => '/Silk\/([0-9.]+)/i',
            'UCBrowser' => '/UCBrowser\/([0-9.]+)/i',
            'QQBrowser' => '/QQBrowser\/([0-9.]+)/i',
            'Maxthon' => '/Maxthon\/([0-9.]+)/i',
            'Pale Moon' => '/PaleMoon\/([0-9.]+)/i',
        ];

        $platforms = [
            'Windows' => '/Windows NT ([0-9.]+)/i',
            'Mac' => '/Mac OS X ([0-9._]+)/i',
            'Linux' => '/Linux/i',
            'iPhone' => '/iPhone; CPU iPhone OS ([0-9_]+)/i',
            'iPad' => '/iPad; CPU OS ([0-9_]+)/i',
            'Android' => '/Android ([0-9.]+)/i',
            'Xbox' => '/Xbox; Windows NT ([0-9.]+)/i',
            'PlayStation' => '/PlayStation 4|PlayStation Vita/i',
            'Chrome OS' => '/CrOS ([a-zA-Z0-9.]+)/i',
            'Nvidia Shield' => '/SHIELD Tablet K1/i',
            'Tizen' => '/Tizen/i',
            'Fire OS' => '/Fire OS\/([0-9.]+)/i',
            'BlackBerry' => '/BlackBerry|BB[0-9]+/i',
            'Symbian' => '/SymbianOS\/([0-9.]+)|SymbOS\/([0-9.]+)/i',
        ];

        $devices = [
            'Samsung' => '/SM-([A-Za-z0-9]+)/i',
            'Nvidia Shield' => '/SHIELD Tablet K1/i',
            'iPhone' => '/iPhone/i',
            'iPad' => '/iPad/i',
            'Xbox' => '/Xbox/i',
            'PlayStation' => '/PlayStation/i',
            'Google Pixel' => '/Pixel [0-9]+/i',
            'OnePlus' => '/ONEPLUS/i',
            'Huawei' => '/Huawei|HUAWEI/i',
            'Xiaomi' => '/Mi|Redmi/i',
            'LG' => '/LG-[A-Za-z0-9]+/i',
            'Motorola' => '/Moto[A-Za-z0-9]+/i',
            'Sony' => '/Sony[A-Za-z0-9]+/i',
            'HTC' => '/HTC[A-Za-z0-9]+/i',
            'Amazon Kindle' => '/Kindle Fire|Silk\/|Kindle/i',
            'Android' => '/Android/i',
        ];

        $bots = [
            'GoogleBot' => '/Googlebot/i',
            'YandexBot' => '/YandexBot/i',
            'DiscordBot' => '/Discordbot/i',
            'TwitterBot' => '/Twitterbot/i',
            'DuckDuckGoBot' => '/DuckDuckBot/i',
            'BaiduBot' => '/Baiduspider/i',
            'BingBot' => '/Bingbot/i',
            'SlurpBot' => '/Slurp/i',
            'UptimeRobot' => '/UptimeRobot/i',
            'SemrushBot' => '/SemrushBot/i',
            'MJ12bot' => '/MJ12bot/i',
            'PetalBot' => '/PetalBot/i',
            'DataForSeoBot' => '/DataForSeoBot/i',
        ];

        self::$params = [];

        if (empty($user_agent)) {
            self::$params['browser'] = 'Script';
            self::$params['browser_version'] = null;
            self::$params['platform'] = 'Server';
            self::$params['platform_version'] = null;
            self::$params['device'] = 'Server';
            self::$params['bot'] = 'no';
            self::$params['mobile'] = false;
            self::$params['desktop'] = true;
            self::$params['accept_language'] = $http_accept_language;
            return;
        }

        $browser_detected_from_sec_ch_ua = false;
        if (!empty($parsed_sec_ch_ua)) {
            if (isset($parsed_sec_ch_ua['Brave'])) {
                self::$params['browser'] = 'Brave';
                self::$params['browser_version'] = $parsed_sec_ch_ua['Brave'];
                $browser_detected_from_sec_ch_ua = true;
            } elseif (isset($parsed_sec_ch_ua['Google Chrome'])) {
                self::$params['browser'] = 'Chrome';
                self::$params['browser_version'] = $parsed_sec_ch_ua['Google Chrome'];
                $browser_detected_from_sec_ch_ua = true;
            } elseif (isset($parsed_sec_ch_ua['Chromium'])) {
                self::$params['browser'] = 'Chromium';
                self::$params['browser_version'] = $parsed_sec_ch_ua['Chromium'];
                $browser_detected_from_sec_ch_ua = true;
            }
        }

        if (!$browser_detected_from_sec_ch_ua) {
            $browser_detected = false;
            foreach ($browsers as $browser => $regex) {
                if (preg_match($regex, $user_agent, $matches)) {
                    self::$params['browser'] = $browser;
                    self::$params['browser_version'] = $matches[1] ?? 'unknown';
                    $browser_detected = true;
                    break;
                }
            }
            if (!$browser_detected) {
                self::$params['browser'] = 'Script';
                self::$params['browser_version'] = 'unknown';
            }
        }

        $platform_detected = false;
        foreach ($platforms as $platform => $regex) {
            if (preg_match($regex, $user_agent, $matches)) {
                self::$params['platform'] = $platform;
                self::$params['platform_version'] = str_replace('_', '.', $matches[1] ?? 'unknown');
                $platform_detected = true;
                break;
            }
        }
        if (!$platform_detected) {
            self::$params['platform'] = 'Bot';
            self::$params['platform_version'] = 'unknown';
        }

        $device_detected = false;
        foreach ($devices as $device => $regex) {
            if (preg_match($regex, $user_agent)) {
                self::$params['device'] = $device;
                $device_detected = true;
                break;
            }
        }
        if (!$device_detected) {
            self::$params['device'] = 'Server';
        }

        $bot_detected = false;
        foreach ($bots as $bot => $regex) {
            if (preg_match($regex, $user_agent)) {
                self::$params['bot'] = $bot;
                $bot_detected = true;
                break;
            }
        }
        if (!$bot_detected) {
            self::$params['bot'] = 'no';
        }

        self::$params['mobile'] = preg_match('/Mobile|Android|iPhone|iPad|Opera Mini|BlackBerry|webOS|UCBrowser/', $user_agent) ? true : false;
        self::$params['desktop'] = !self::$params['mobile'];
        self::$params['accept_language'] = $http_accept_language;
    }

    /**
     * Helper method to safely process and filter uploaded files.
     * This ensures only valid files are passed into the request data.
     *
     * @param array $files_array The $_FILES superglobal array.
     * @return array An array of processed file information.
     */
    private static function processUploadedFiles(array $files_array): array
    {
        $processed_files = [];

        // Define default allowed extensions and MIME types (can be configured via FileUploadShield too)
        $allowed_extensions = [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
            'svg',
            'bmp',
            'ico',
            'tiff',
            'tif', // Images
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'txt',
            'rtf',
            'csv',
            'odt',
            'ods',
            'odp', // Documents
            'mp3',
            'wav',
            'ogg',
            'm4a',
            'aac', // Audio
            'mp4',
            'mov',
            'avi',
            'wmv',
            'webm',
            'mkv', // Video
            'zip',
            'rar',
            '7z',
            'tar',
            'gz', // Archives
            'json',
            'xml' // Code/Data (handle with care)
        ];

        $allowed_mime_types = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'image/bmp',
            'image/x-icon',
            'image/tiff',
            'application/pdf',
            'text/plain',
            'text/csv',
            'application/rtf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
            'audio/mpeg',
            'audio/wav',
            'audio/ogg',
            'audio/x-m4a',
            'audio/aac',
            'video/mp4',
            'video/quicktime',
            'video/x-msvideo',
            'video/x-ms-wmv',
            'video/webm',
            'video/x-matroska',
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
            'application/x-tar',
            'application/gzip',
            'application/json',
            'text/xml',
            'application/xml'
        ];
        $max_file_size = 256 * 1024 * 1024; // 256 MB default limit

        foreach ($files_array as $file_input_name => $file_info) {
            // Handle multiple file uploads (e.g., <input type="file" name="docs[]" multiple>)
            if (is_array($file_info['name'])) {
                for ($i = 0; $i < count($file_info['name']); $i++) {
                    if ($file_info['error'][$i] === UPLOAD_ERR_OK) {
                        $tmp_name = $file_info['tmp_name'][$i];
                        $size = $file_info['size'][$i];
                        $type = mime_content_type($tmp_name);
                        $ext = strtolower(pathinfo($file_info['name'][$i], PATHINFO_EXTENSION));

                        if ($size <= $max_file_size && in_array($ext, $allowed_extensions) && in_array($type, $allowed_mime_types)) {
                            $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file_info['name'][$i]));
                            $processed_files[$file_input_name][] = [
                                'name' => $safe_name,
                                'type' => $type,
                                'tmp_name' => $tmp_name,
                                'size' => $size,
                                'extension' => $ext
                            ];
                        }
                    }
                }
            } else { // Handle single file uploads
                if ($file_info['error'] === UPLOAD_ERR_OK) {
                    $tmp_name = $file_info['tmp_name'];
                    $size = $file_info['size'];
                    $type = mime_content_type($tmp_name);
                    $ext = strtolower(pathinfo($file_info['name'], PATHINFO_EXTENSION));

                    if ($size <= $max_file_size && in_array($ext, $allowed_extensions) && in_array($type, $allowed_mime_types)) {
                        $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file_info['name']));
                        $processed_files[$file_input_name] = [
                            'name' => $safe_name,
                            'type' => $type,
                            'tmp_name' => $tmp_name,
                            'size' => $size,
                            'extension' => $ext
                        ];
                    }
                }
            }
        }
        return $processed_files;
    }

    /**
     * Creates a clean, URL-safe, SEO-friendly slug from ANY language.
     * - Keeps original Unicode letters & numbers
     * - Auto removes ALL emojis, symbols, punctuation
     * - No manual list needed — uses Unicode categories
     *
     * @param string $string Input text
     * @param string $separator Word separator (default '-')
     * @return string Clean slug
     */
    public static function createSlug(string $string, string $separator = '-'): string
    {
        // 1. Trim & normalize whitespace
        $string = trim(preg_replace('/\s+/u', ' ', $string));

        if (empty($string)) {
            return 'untitled';
        }

        // 2. Lowercase (multibyte safe)
        $string = function_exists('mb_strtolower')
            ? mb_strtolower($string, 'UTF-8')
            : strtolower($string);

        // 3. Replace common words (optional but good for SEO)
        $string = str_replace(
            ['&', '+'],
            ['and', 'plus'],
            $string
        );

        // 4. Remove ALL emojis, symbols, punctuation, and other non-letter/number chars
        // \p{L}  = any letter from any language
        // \p{N}  = any number
        // \p{M}  = combining marks (accents)
        // Everything else → remove (including emoji, symbols, punctuation)
        $string = preg_replace('/[^\p{L}\p{N}\p{M}\s-]/u', '', $string);

        // 5. Replace spaces with separator
        $string = preg_replace('/\s+/u', $separator, $string);

        // 6. Remove multiple separators
        $string = preg_replace('/' . preg_quote($separator, '/') . '{2,}/', $separator, $string);

        // 7. Trim separator from start/end
        $string = trim($string, $separator);

        // 8. Final fallback
        return $string ?: 'untitled';
    }

    /**
     * The Ultimate AI-Aware Sitemap Configuration Engine.
     * Auto-corrects typos, fixes out-of-bound numbers, handles massive aliases,
     * and features a built-in DB Template Engine for zero-loop dynamic generation.
     *
     * @param mixed $options Configuration array, string, float, or closure.
     * @return self
     */
    public function sitemap($options = true): self
    {
        $keys_to_modify = is_array(self::$last_route_key) ? self::$last_route_key : [self::$last_route_key];

        $config = ['priority' => '0.8', 'changefreq' => 'daily', 'exclude' => false, 'callback' => null, 'db' => null];

        // --- 1. MAGIC TYPE INFERENCE ---
        if (is_bool($options))
            $config['exclude'] = !$options;
        elseif (is_numeric($options))
            $config['priority'] = (string) $options;
        elseif (is_string($options))
            $config['changefreq'] = $options;
        elseif (is_callable($options))
            $config['callback'] = $options;
        elseif (is_array($options)) {

            // --- 2. MASSIVE ALIAS DICTIONARY ---
            $alias_map = [
                'priority' => ['priority', 'prio', 'pr', 'weight', 'score', 'p', 'importance', 'rank', 'level', 'val', 'v'],
                'changefreq' => ['changefreq', 'freq', 'frequency', 'change', 'update', 'time', 'interval', 'f', 'period', 'cycle', 'rate', 'every'],
                'callback' => ['callback', 'call', 'data', 'source', 'custom_fetch', 'items', 'fn', 'action', 'provider', 'manual', 'custom', 'generator'],
                'db' => ['db', 'database', 'table', 'auto', 'model', 'query_builder', 'phdb', 'sql', 'fetch_db', 'auto_fetch', 'query', 'fetch'],
                'exclude' => ['exclude', 'ignore', 'skip', 'hide', 'omit', 'block', 'remove', 'x', 'disable', 'off', 'false', 'no']
            ];

            foreach ($options as $key => $value) {
                // If user passed a flat array: ['daily', 0.9, function(){...}]
                if (is_int($key)) {
                    if (is_bool($value))
                        $config['exclude'] = !$value;
                    elseif (is_numeric($value))
                        $config['priority'] = (string) $value;
                    elseif (is_string($value))
                        $config['changefreq'] = $value;
                    elseif (is_callable($value))
                        $config['callback'] = $value;
                    continue;
                }

                $key_lower = strtolower(trim($key));
                foreach ($alias_map as $official_key => $aliases) {
                    if (in_array($key_lower, $aliases, true)) {
                        $config[$official_key] = $value;
                        break;
                    }
                }
            }
        }

        // --- 3. AI TYPO CORRECTOR & NUMBER CLAMPING ---

        // Fix Priority (If someone writes 8 or 80 or 80%, fix it to 0.8)
        $prio_raw = str_replace('%', '', (string) $config['priority']);
        $prio = (float) $prio_raw;
        if ($prio > 1 && $prio <= 10)
            $prio = $prio / 10;
        if ($prio > 10 && $prio <= 100)
            $prio = $prio / 100;
        if ($prio < 0.0)
            $prio = 0.0;
        if ($prio > 1.0)
            $prio = 1.0;
        $config['priority'] = number_format($prio, 1, '.', '');

        // Fix Frequency Typos (e.g. 'daliy' -> 'daily')
        $freq = strtolower(trim($config['changefreq']));
        $typo_fixes = [
            'day' => 'daily',
            'daliy' => 'daily',
            'everyday' => 'daily',
            'week' => 'weekly',
            'wekly' => 'weekly',
            'month' => 'monthly',
            'mothly' => 'monthly',
            'year' => 'yearly',
            'yerly' => 'yearly',
            'alway' => 'always'
        ];
        if (isset($typo_fixes[$freq]))
            $freq = $typo_fixes[$freq];

        $valid_freqs = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];
        if (!in_array($freq, $valid_freqs, true))
            $freq = 'daily'; // Ultimate safe fallback
        $config['changefreq'] = $freq;

        // --- 4. THE SMART DB TEMPLATE ENGINE ---
        if (!empty($config['db']) && is_array($config['db']) && $config['callback'] === null) {

            // 🧠 The Auto-Solver: Parse the DB array intelligently
            $db_conf = [
                'table' => null,
                'map' => [],
                'where' => null,
                'order' => null,
                'limit' => null
            ];

            $raw_db = $config['db'];

            // Scenario A: Associative Array (The standard, verbose way)
            if (count(array_filter(array_keys($raw_db), 'is_string')) > 0) {
                // Map aliases for associative keys
                $db_aliases = [
                    'table' => ['table', 'from', 't'],
                    'map' => ['map', 'columns', 'fields', 'select', 'format', 'm'],
                    'where' => ['where', 'condition', 'conditions', 'filter', 'w'],
                    'order' => ['order', 'order_by', 'sort', 'sort_by', 'o'],
                    'limit' => ['limit', 'take', 'max', 'l']
                ];
                foreach ($raw_db as $k => $v) {
                    $kl = strtolower(trim($k));
                    foreach ($db_aliases as $official => $aliases) {
                        if (in_array($kl, $aliases, true)) {
                            $db_conf[$official] = $v;
                            break;
                        }
                    }
                }
            }
            // Scenario B: Flat Array (The One-Liner Magic)
            // Example: ['spaces', ['post_type' => '{category}', ...], ['status' => 'Active'], 'updated_at DESC']
            else {
                foreach ($raw_db as $item) {
                    if (is_string($item)) {
                        // If it contains space or DESC/ASC, it's an Order By clause
                        if (preg_match('/\b(desc|asc)\b/i', $item) || strpos($item, ',') !== false) {
                            $db_conf['order'] = $item;
                        }
                        // If it's a number string, it's a Limit
                        elseif (is_numeric($item)) {
                            $db_conf['limit'] = (int) $item;
                        }
                        // Otherwise, it must be the Table Name
                        elseif ($db_conf['table'] === null) {
                            $db_conf['table'] = $item;
                        }
                    }
                    // If it's an array, figure out if it's the Map or the Where condition
                    elseif (is_array($item)) {
                        // A map usually has specific URL placeholders as keys (e.g., 'url_slug', 'post_type')
                        // A where clause usually has DB column names (e.g., 'status', 'type')
                        // To be smart: if it contains a template string '{...}' or known map keys, it's the Map.
                        $is_map = false;
                        $map_hints = ['lastmod', 'date', 'update', 'url_slug'];
                        foreach ($item as $k => $v) {
                            if (strpos((string) $v, '{') !== false || in_array(strtolower($k), $map_hints)) {
                                $is_map = true;
                                break;
                            }
                        }

                        if ($is_map || empty($db_conf['map'])) {
                            $db_conf['map'] = $item;
                        } else {
                            $db_conf['where'] = $item;
                        }
                    }
                    // If it's a raw integer, it's a Limit
                    elseif (is_int($item)) {
                        $db_conf['limit'] = $item;
                    }
                }
            }

            // Set the callback to execute the DB query
            if ($db_conf['table'] && !empty($db_conf['map'])) {
                $config['callback'] = function () use ($db_conf) {
                    $table = $db_conf['table'];
                    $map = $db_conf['map'];
                    $where = $db_conf['where'];
                    $order = $db_conf['order'];
                    $limit = $db_conf['limit'];

                    // Resolve Map Aliases (e.g. 'update' -> 'lastmod')
                    $date_aliases = ['update', 'updated', 'time', 'date', 'modified_at'];
                    $resolved_map = [];
                    foreach ($map as $k => $v) {
                        $clean_k = strtolower(trim($k));
                        if (in_array($clean_k, $date_aliases)) {
                            $resolved_map['lastmod'] = $v;
                        } else {
                            $resolved_map[$k] = $v;
                        }
                    }
                    $map = $resolved_map;

                    // Extract columns needed from the database
                    $columns_needed = [];
                    foreach ($map as $template) {
                        if (preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $template, $matches)) {
                            $columns_needed = array_merge($columns_needed, $matches[1]);
                        } else {
                            // Extract base column name (e.g., 'tags' from 'tags[]' or 'settings' from 'settings.theme')
                            $base_col = preg_split('/[\.\[]/', $template)[0];
                            $columns_needed[] = $base_col;
                        }
                    }
                    $columns_needed = array_unique(array_filter($columns_needed));
                    $select_cols = empty($columns_needed) ? '*' : implode(', ', $columns_needed);

                    // Execute query (Ensure PHDB class exists and select() returns iterable)
                    if (!class_exists('PHDB'))
                        return [];
                    $results = \PHDB::select($table, $select_cols, $where, null, $limit, $order);

                    if (!$results || !is_iterable($results))
                        return [];

                    $mapped_results = [];
                    foreach ($results as $row) {
                        // 1. Build a Base Item and track Array columns
                        $base_item = [];
                        $array_columns = []; // Holds the expanded arrays for this row

                        foreach ($map as $param_key => $template) {
                            $is_date_field = in_array(strtolower($param_key), ['lastmod', 'updated_at', 'date']);

                            if (preg_match('/\{.*\}/', $template)) {
                                // Template string (assume it results in a single string)
                                $val = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) use ($row) {
                                    return $row[$m[1]] ?? '';
                                }, $template);
                                $base_item[$param_key] = \PHRO::createSlug($val);
                            } else {
                                // Single column mapping. This might be an array!
                                // We use a dummy where clause to trick array_get into parsing the $row directly
                                // Assuming PHRO::array_get can process a direct value if we structure it right,
                                // BUT since we already have the $row data, it's more efficient to just parse it here.

                                // To use your exact array_get method, we need the Primary Key. 
                                // Since we might not know it, let's simulate the parsing logic directly on $row data.
                                $raw_val = $row[$template] ?? null;

                                // Check if the template explicitly asks for an array (e.g., tags[])
                                $is_array_request = strpos($template, '[]') !== false;
                                $clean_template = str_replace('[]', '', $template);

                                // Try to decode if it looks like JSON or Serialized or Comma separated
                                $parsed_val = $row[$clean_template] ?? null;

                                if (is_string($parsed_val)) {
                                    if (strpos($parsed_val, '[') === 0 || strpos($parsed_val, '{') === 0) {
                                        $decoded = json_decode($parsed_val, true);
                                        if (json_last_error() === JSON_ERROR_NONE)
                                            $parsed_val = $decoded;
                                    } elseif (strpos($parsed_val, 'a:') === 0) {
                                        $decoded = @unserialize($parsed_val, ['allowed_classes' => false]);
                                        if ($decoded !== false)
                                            $parsed_val = $decoded;
                                    } elseif (strpos($parsed_val, ',') !== false) {
                                        $parsed_val = array_map('trim', explode(',', $parsed_val));
                                    }
                                }

                                // If the result is an array AND it's not a date field
                                if (is_array($parsed_val) && !$is_date_field) {
                                    // Store this array for expansion later
                                    $array_columns[$param_key] = $parsed_val;
                                    // Set a temporary placeholder in the base item
                                    $base_item[$param_key] = '__ARRAY_PLACEHOLDER__';
                                } else {
                                    // Normal single value
                                    $val = is_array($parsed_val) ? current($parsed_val) : (string) $parsed_val; // Fallback if array wasn't expected

                                    if (!$is_date_field && !preg_match('/^[a-z0-9\-]+$/', $val)) {
                                        $val = \PHRO::createSlug($val);
                                    }
                                    $base_item[$param_key] = $val;
                                }
                            }
                        }

                        // 2. Expand the Base Item if array columns exist
                        if (empty($array_columns)) {
                            // No arrays, just add the base item
                            $mapped_results[] = $base_item;
                        } else {
                            // We have arrays! We need to create a Cartesian product.
                            // For simplicity, we assume usually only ONE column expands into an array per route.
                            // E.g., a post has multiple tags.

                            // Let's take the first array column to expand on (handling multiple arrays is very complex for sitemaps)
                            $expand_key = array_key_first($array_columns);
                            $values_to_expand = $array_columns[$expand_key];

                            $existing_slugs = []; // To prevent duplicates within the same row expansion

                            foreach ($values_to_expand as $val) {
                                if (empty($val) || is_array($val))
                                    continue; // Skip empty or deeply nested values

                                $slugified_val = \PHRO::createSlug((string) $val);

                                // Prevent duplicate entries for the same tag/category
                                if (in_array($slugified_val, $existing_slugs))
                                    continue;
                                $existing_slugs[] = $slugified_val;

                                $new_item = $base_item;
                                $new_item[$expand_key] = $slugified_val;

                                // Clean up any other placeholders if multiple array columns existed (edge case)
                                foreach ($new_item as $k => $v) {
                                    if ($v === '__ARRAY_PLACEHOLDER__') {
                                        $new_item[$k] = \PHRO::createSlug((string) current($array_columns[$k]));
                                    }
                                }

                                $mapped_results[] = $new_item;
                            }
                        }
                    }

                    return $mapped_results;
                };
            }
        }

        // --- 5. APPLY TO ROUTES ---
        foreach ($keys_to_modify as $key) {
            if (isset(self::$routes[$key]) && self::$routes[$key]['method'] === 'get') {
                if ($config['exclude']) {
                    unset(self::$routes[$key]['sitemap']);
                } else {
                    self::$routes[$key]['sitemap'] = $config;
                }
            }
        }
        return $this;
    }

    /**
     * Marks a route to be "Disallowed" in robots.txt for specific user agents.
     *
     * @param string|array $user_agents User agent(s) to apply the rule to. '*' for all.
     * @return self
     */
    public function disallow($user_agents = '*'): self
    {
        $keys_to_modify = is_array(self::$last_route_key) ? self::$last_route_key : [self::$last_route_key];
        foreach ($keys_to_modify as $key) {
            if (isset(self::$routes[$key])) {
                self::$routes[$key]['robots'] = [
                    'rule' => 'Disallow',
                    'agents' => (array) $user_agents,
                ];
            }
        }
        return $this;
    }

    /**
     * Marks a route to be "Allowed" in robots.txt.
     * Useful for allowing a specific sub-path of a disallowed directory.
     *
     * @param string|array $user_agents User agent(s) to apply the rule to. '*' for all.
     * @return self
     */
    public function allow($user_agents = '*'): self
    {
        $keys_to_modify = is_array(self::$last_route_key) ? self::$last_route_key : [self::$last_route_key];
        foreach ($keys_to_modify as $key) {
            if (isset(self::$routes[$key])) {
                self::$routes[$key]['robots'] = [
                    'rule' => 'Allow',
                    'agents' => (array) $user_agents,
                ];
            }
        }
        return $this;
    }

    /**
     * Generates a complete list of URLs for the sitemap.
     *
     * @return array
     */
    public static function getSitemapRoutes(): array
    {
        $sitemap_urls = [];
        $lastmod_keys = ['lastmod', 'updated_at', 'modified_at', 'updated', 'modified'];

        $format_date = function ($date_string) {
            if (empty($date_string))
                return date('Y-m-d');
            try {
                return (new \DateTime($date_string))->format('Y-m-d');
            } catch (\Exception $e) {
                return date('Y-m-d');
            }
        };

        foreach (self::$routes as $route) {
            if (isset($route['sitemap'])) {
                if (is_callable($route['sitemap']['callback'])) {
                    try {
                        $dynamic_items = call_user_func($route['sitemap']['callback']);

                        if (!is_iterable($dynamic_items))
                            continue;

                        foreach ($dynamic_items as $item) {
                            $item = (array) $item;
                            $final_url = $route['url'];
                            $lastmod = null;

                            foreach ($item as $key => $value) {
                                $final_url = str_replace('@' . $key, $value, $final_url);
                                if (in_array(strtolower($key), $lastmod_keys))
                                    $lastmod = $value;
                            }

                            $sitemap_urls[] = ['loc' => rtrim(self::root(), '/') . $final_url, 'lastmod' => $format_date($lastmod), 'changefreq' => $route['sitemap']['changefreq'], 'priority' => $route['sitemap']['priority']];
                        }
                    } catch (\Throwable $e) {
                        error_log("PHRO Sitemap Error on route {$route['url']}: " . $e->getMessage());
                    }
                } elseif (strpos($route['url'], '@') === false) {
                    $sitemap_urls[] = ['loc' => $route['link'], 'lastmod' => date('Y-m-d'), 'changefreq' => $route['sitemap']['changefreq'], 'priority' => $route['sitemap']['priority']];
                }
            }
        }

        foreach (self::$sitemap_custom_entries as $entry) {
            $sitemap_urls[] = ['loc' => $entry['loc'], 'lastmod' => $format_date($entry['lastmod'] ?? null), 'changefreq' => $entry['changefreq'] ?? 'daily', 'priority' => $entry['priority'] ?? '0.7'];
        }

        return $sitemap_urls;
    }


    /**
     * Configures and enables a universally compatible, auto-generated manifest.json.
     * This method is highly flexible, accepting numerous aliases for each key.
     *
     * @param array $config The master configuration array for the manifest.
     */
    public static function manifest(array $config): void
    {
        // --- Master Alias Map ---
        $alias_map = [
            'name' => ['name', 'app_name', 'title', 'full_name', 'long_name', 'site_name', 'applicationName', 'appName', 'display_name', 'page_title'],
            'short_name' => ['short_name', 'short', 'initials', 'nick', 'nickname', 'appShortName', 'applicationShortName', 'shortName', 'small_name', 'mini_name'],
            'description' => ['description', 'desc', 'summary', 'about', 'details', 'info', 'appDescription', 'tagline', 'app_desc', 'site_description'],
            'start_url' => ['start_url', 'start', 'home', 'index', 'launch_url', 'startUrl', 'launchUrl', 'entry_point', 'main', 'root'],
            'display' => ['display', 'display_mode', 'mode', 'view', 'app_display', 'displayMode', 'window_mode', 'launch_mode', 'shell_mode', 'ui_mode'],
            'background_color' => ['background_color', 'bg_color', 'bg', 'background', 'backgroundColor', 'bgColor', 'canvas_color', 'splash_bg', 'window_color', 'app_bg'],
            'theme_color' => ['theme_color', 'theme', 'primary_color', 'accent_color', 'brand_color', 'themeColor', 'primaryColor', 'header_color', 'toolbar_color', 'main_color'],
            'orientation' => ['orientation', 'screen_orientation', 'orient', 'screen', 'direction', 'view_orientation', 'lock_orientation', 'display_orientation', 'layout', 'rotation'],
            'scope' => ['scope', 'app_scope', 'navigation_scope', 'scope_url', 'url_scope', 'limit', 'boundary', 'appScope', 'context', 'path_scope'],
            'icons' => ['icons', 'icon', 'logos', 'artwork', 'app_icons', 'images', 'assets', 'brand_assets', 'icon_list', 'app_logos'],
            'shortcuts' => ['shortcuts', 'shortcut', 'quick_actions', 'tasks', 'actions', 'app_shortcuts', 'links', 'quick_links', 'context_menu', 'jumplist'],
            'categories' => ['categories', 'category', 'tags', 'keywords', 'genre', 'app_categories', 'store_categories', 'app_genre', 'classification', 'topic'],
            'screenshots' => ['screenshots', 'screens', 'previews', 'gallery', 'showcase', 'app_screenshots', 'promo_images', 'feature_graphics', 'product_shots', 'captures'],
            'iarc_rating_id' => ['iarc_rating_id', 'iarc', 'rating_id', 'content_rating', 'age_rating', 'iarcId', 'certification', 'iarc_code', 'maturity_rating', 'esrb'],
            'related_applications' => ['related_applications', 'related_apps', 'related', 'store_apps', 'app_links', 'native_apps', 'relatedApps', 'alternate_apps', 'companion_apps', 'platform_apps'],
        ];

        $manifest = [];

        // --- Process the user's config using the alias map ---
        foreach ($alias_map as $official_key => $aliases) {
            foreach ($aliases as $alias) {
                if (isset($config[$alias])) {
                    $manifest[$official_key] = $config[$alias];
                    break; // Move to the next official key once a match is found
                }
            }
        }

        // --- Intelligent Defaults & Auto-Corrections ---
        $root_path = rtrim(parse_url(self::root(), PHP_URL_PATH) ?? '/', '/') . '/';

        // Set smart defaults if not provided
        $manifest['name'] ??= 'My App';
        $manifest['short_name'] ??= 'App';
        $manifest['start_url'] ??= $root_path;
        $manifest['scope'] ??= $root_path;
        $manifest['display'] ??= 'standalone';
        $manifest['background_color'] ??= '#ffffff';
        $manifest['theme_color'] ??= '#000000';

        // Auto-complete icon and screenshot paths
        if (!empty($manifest['icons'])) {
            foreach ($manifest['icons'] as &$icon) {
                if (isset($icon['src']) && !filter_var($icon['src'], FILTER_VALIDATE_URL) && $icon['src'][0] !== '/') {
                    $icon['src'] = rtrim(self::root(), '/') . '/' . ltrim($icon['src'], '/');
                }
            }
        }
        if (!empty($manifest['screenshots'])) {
            foreach ($manifest['screenshots'] as &$screenshot) {
                if (isset($screenshot['src']) && !filter_var($screenshot['src'], FILTER_VALIDATE_URL) && $screenshot['src'][0] !== '/') {
                    $screenshot['src'] = rtrim(self::root(), '/') . '/' . ltrim($screenshot['src'], '/');
                }
            }
        }

        self::$manifest_config = $manifest;
    }

    /**
     * Adds a custom URL entry to the sitemap.
     *
     * @param string $loc The full URL.
     * @param array $options Options like 'lastmod', 'priority', 'changefreq'.
     */
    public static function addSitemapEntry(string $loc, array $options = []): void
    {
        self::$sitemap_custom_entries[] = array_merge($options, ['loc' => $loc]);
    }

    /**
     * Adds a custom line to the robots.txt file.
     * Useful for adding rules not tied to a specific route, like Crawl-delay.
     *
     * @param string $rule The full line to add (e.g., "Crawl-delay: 10").
     */
    public static function addRobotsRule(string $rule): void
    {
        self::$robots_custom_rules[] = $rule;
    }

    /**
     * Listen for incoming HTTP requests and execute matching route callback.
     *
     * @param callable|null $callback Callback function to execute when no route is matched.
     * @return void
     */
    public static function listen($error_handler = null)
    {
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        if (
            in_array($requestMethod, ['GET', 'HEAD'], true) &&
            preg_match(
                '~(?:^|/)src/cache/(css|js)/([a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.(css|js))$~i',
                $requestPath,
                $cacheAsset
            )
        ) {
            $section = strtolower($cacheAsset[1]);
            $extension = strtolower($cacheAsset[3]);
            if ($section === $extension) {
                $cacheRoot = realpath(DIR::path('cache'));
                $assetFile = realpath(
                    rtrim(DIR::path('cache'), '/\\') .
                    DIRECTORY_SEPARATOR . $section .
                    DIRECTORY_SEPARATOR . $cacheAsset[2]
                );

                if (
                    $cacheRoot !== false &&
                    $assetFile !== false &&
                    str_starts_with(
                        str_replace('\\', '/', $assetFile),
                        rtrim(str_replace('\\', '/', $cacheRoot), '/') . '/' . $section . '/'
                    ) &&
                    is_file($assetFile)
                ) {
                    header(
                        'Content-Type: ' .
                        ($extension === 'css'
                            ? 'text/css; charset=utf-8'
                            : 'application/javascript; charset=utf-8')
                    );
                    header('Cache-Control: public, max-age=604800, immutable');
                    header('X-Content-Type-Options: nosniff');
                    header('Content-Length: ' . filesize($assetFile));
                    if ($requestMethod === 'GET') {
                        readfile($assetFile);
                    }
                    exit;
                }
            }
        }

        if (self::$mcp_enabled && class_exists('PHAI')) {
            PHAI::routes('/mcp');
            PHAI::middleware(function ($method) {
                return $method === 'tools/call';
            });
        }

        self::get("/app.js", function ($data) {
            $source = DIR::path("js:PHJS-min.php");
            $etag = '"' . hash('sha256', $source . '|' . filemtime($source) . '|' . filesize($source) . '|' . (PHDE::isDebug() ? 'debug' : 'release')) . '"';
            header_remove('Set-Cookie');
            header('Cache-Control: public, max-age=604800, immutable', true);
            header('ETag: ' . $etag);
            header('Vary: Accept-Encoding');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            if (self::requestEtagMatches($etag)) {
                http_response_code(304);
                exit;
            }
            import("js:PHJS-min.php");
            exit;
        })->header(['js', 'asset'])->name("app-js")->allow();

        self::get("/sw.js", function ($data) {
            $source = DIR::path("js:SW.php");
            $etag = '"' . hash('sha256', $source . '|' . filemtime($source) . '|' . filesize($source)) . '"';
            header_remove('Set-Cookie');
            header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate', true);
            header('Pragma: no-cache');
            header('Expires: 0');
            header('ETag: ' . $etag);
            header('Vary: Accept-Encoding');
            header('X-Content-Type-Options: nosniff');
            if (self::requestEtagMatches($etag)) {
                http_response_code(304);
                exit;
            }
            import("js:SW.php");
            exit;
        })->header([
            'js',
            'Cache-Control' => 'no-cache, max-age=0, must-revalidate',
        ])->name("sw-js")->allow();

        self::get("/_phjs/network-info", function ($data) {
            $footprint = self::footprint();
            $geo = self::_fetchUserIPInfoWithRetry(
                $footprint['clientIP'] ?? self::getUserIP()
            );
            $footprint = array_merge($footprint, $geo);
            $network = [
                'ip' => $footprint['query'] ?? $footprint['clientIP'] ?? self::getUserIP(),
                'isp' => $footprint['clientXIsp'] ?? $footprint['isp'] ?? null,
                'city' => $footprint['clientXCity'] ?? $footprint['city'] ?? null,
                'country' => $footprint['clientXCountry'] ?? $footprint['country'] ?? null,
                'countryCode' => $footprint['clientXCountryCode'] ?? $footprint['countryCode'] ?? null,
                'proxy' => (bool) ($footprint['clientXProxy'] ?? $footprint['proxy'] ?? false),
                'hosting' => (bool) ($footprint['hosting'] ?? false),
                'key' => $footprint['clientXNetkey'] ?? $footprint['clientNetkey'] ?? null,
                'hash' => $footprint['clientXHash'] ?? $footprint['clientHash'] ?? null,
                'deviceKey' => $footprint['clientXDevicekey'] ?? $footprint['clientDevicekey'] ?? null,
                'fingerprint' => $footprint['clientXFingerprint'] ?? $footprint['clientFingerprint'] ?? null,
            ];

            header('Cache-Control: private, max-age=3600, stale-while-revalidate=3600');
            echo json_encode($network, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        })->header([
            'json',
            'Cache-Control' => 'private, max-age=3600, stale-while-revalidate=3600',
        ])->name("phjs-network-info")->allow();

        self::get("/manifest.json", function ($data) {
            if (self::$manifest_config !== null) {
                header("Content-Type: application/manifest+json; charset=utf-8");
                echo json_encode(self::$manifest_config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                exit;
            }
        })->header(['json'])->name("manifest")->allow();

        self::get("/sitemap.xml", function ($data) {
            header("Content-Type: application/xml; charset=utf-8");

            // XML Structure Start
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

            try {
                // Fetch raw URLs from the router
                $raw_urls = self::getSitemapRoutes();
                $processed_urls = [];

                // 🧠 THE INTELLIGENT SITEMAP ENGINE
                foreach ($raw_urls as $url_data) {
                    try {
                        // 1. URL Normalization (Fix double slashes like site.com//page -> site.com/page)
                        // This regex replaces multiple slashes with a single slash, ignoring the '://' in http(s)://
                        $loc = preg_replace('/([^:])(\/{2,})/', '$1/', $url_data['loc'] ?? '');

                        // 2. Strict Validation: Skip if it's not a valid URL
                        if (!filter_var($loc, FILTER_VALIDATE_URL)) {
                            continue;
                        }

                        // 3. Smart Deduplication & Priority Resolution
                        // If URL already exists, keep the one with the higher priority
                        if (!isset($processed_urls[$loc])) {
                            $url_data['loc'] = $loc;
                            $processed_urls[$loc] = $url_data;
                        } else {
                            $existing_priority = (float) ($processed_urls[$loc]['priority'] ?? 0);
                            $new_priority = (float) ($url_data['priority'] ?? 0);

                            if ($new_priority > $existing_priority) {
                                $url_data['loc'] = $loc;
                                $processed_urls[$loc] = array_merge($processed_urls[$loc], $url_data);
                            }
                        }
                    } catch (\Throwable $inner_e) {
                        // 🛡️ Skip individual faulty URLs without breaking the loop
                        continue;
                    }
                }

                // 4. Generate Beautifully Formatted XML
                foreach ($processed_urls as $url) {
                    $xml .= "  <url>\n";
                    $xml .= "    <loc>" . htmlspecialchars($url['loc'], ENT_QUOTES, 'UTF-8') . "</loc>\n";

                    if (!empty($url['lastmod'])) {
                        $xml .= "    <lastmod>" . htmlspecialchars($url['lastmod'], ENT_QUOTES, 'UTF-8') . "</lastmod>\n";
                    }
                    if (!empty($url['changefreq'])) {
                        $xml .= "    <changefreq>" . htmlspecialchars($url['changefreq'], ENT_QUOTES, 'UTF-8') . "</changefreq>\n";
                    }
                    if (isset($url['priority'])) {
                        // Ensure priority is perfectly formatted (e.g., "1.0", "0.8")
                        $priority = number_format((float) $url['priority'], 1, '.', '');
                        $xml .= "    <priority>" . $priority . "</priority>\n";
                    }

                    $xml .= "  </url>\n";
                }

            } catch (\Throwable $e) {
                // 🛡️ Ultimate Fallback: If EVERYTHING fails, don't show 500 error.
                // Just output the homepage so Google doesn't penalize the site.
                error_log("PHRO Sitemap Critical Error: " . $e->getMessage());
                // echo "PHRO Sitemap Critical Error: " . $e->getMessage();

                $xml .= "  <url>\n";
                $xml .= "    <loc>" . htmlspecialchars(self::root() . '/', ENT_QUOTES, 'UTF-8') . "</loc>\n";
                $xml .= "    <priority>1.0</priority>\n";
                $xml .= "  </url>\n";
            }

            $xml .= "</urlset>";

            echo $xml;
            exit;
        })->header(['xml'])->name("sitemap")->allow();

        self::get("/robots.txt", function ($data) {
            header("Content-Type: text/plain; charset=utf-8");

            $rules_by_agent = ['*' => ['Allow' => [], 'Disallow' => []]];
            $root_url = self::root();
            $url_path_prefix = rtrim(parse_url($root_url, PHP_URL_PATH) ?? '', '/');

            // 🧠 THE SEMANTIC NORMALIZER (The real intelligence)
            $normalize = function ($path) use ($url_path_prefix) {
                // ১. ডাবল স্ল্যাশ ক্লিন করা (// -> /)
                $clean = preg_replace('#/+#', '/', $path);

                // ২. ওয়াইল্ডকার্ড ক্লিন করা (/*/* -> /*)
                $clean = rtrim(preg_replace('/(\/\*)+/', '/*', $clean), '*');
                $clean = preg_replace('/(\*)+/', '*', $clean);

                // ৩. বেস পাথের স্ল্যাশ ফিক্স (আসল কালপ্রিট!)
                if ($clean === $url_path_prefix || $clean === $url_path_prefix . '/') {
                    return $url_path_prefix . '/';
                }

                return $clean;
            };

            // 1. Process all routes
            foreach (self::routes() as $route) {
                if ($route['method'] !== 'get')
                    continue;

                $rule_config = null;

                if (isset($route['robots'])) {
                    $rule_config = $route['robots'];
                } elseif (isset($route['sitemap']) || $route['url'] === '/') {
                    $rule_config = ['rule' => 'Allow', 'agents' => ['*']];
                } else {
                    $rule_config = ['rule' => 'Disallow', 'agents' => ['*']];
                }

                if ($rule_config) {
                    $robot_url = preg_replace('/@[a-zA-Z0-9_]+/', '*', $route['url']);
                    $full_path = $url_path_prefix . '/' . ltrim($robot_url, '/');

                    $full_path = $normalize($full_path);

                    // 🛡️ BULLETPROOF PROTECTION ENGINE: Prevent site-wide lockouts
                    if ($rule_config['rule'] === 'Disallow') {
                        $dangerous_paths = ['/', '/*', $url_path_prefix . '/', $url_path_prefix . '/*'];
                        if (in_array($full_path, $dangerous_paths, true)) {
                            continue;
                        }
                    }

                    // Store rules categorized by Agent AND Type
                    foreach ($rule_config['agents'] as $agent) {
                        if (!isset($rules_by_agent[$agent])) {
                            $rules_by_agent[$agent] = ['Allow' => [], 'Disallow' => []];
                        }
                        $rule_type = $rule_config['rule'];
                        if (!in_array($full_path, $rules_by_agent[$agent][$rule_type])) {
                            $rules_by_agent[$agent][$rule_type][] = $full_path;
                        }
                    }
                }
            }

            // 2. Build the output string
            $content = '';
            foreach ($rules_by_agent as $agent => $categories) {
                $content .= "User-agent: " . $agent . "\n\n";

                // Safe global allows
                $final_allows = ["/"];
                if ($url_path_prefix !== '') {
                    $final_allows[] = $url_path_prefix . "/"; // গ্লোবাল বেস পাথ
                }

                // Merge and UNIQUE the normalized allows
                $final_allows = array_unique(array_merge($final_allows, $categories['Allow']));
                $raw_disallows = array_unique($categories['Disallow']);
                $final_disallows = [];

                // 🧠 CONFLICT RESOLVER
                foreach ($raw_disallows as $disallow_path) {
                    if (!in_array($disallow_path, $final_allows)) {
                        $final_disallows[] = $disallow_path;
                    }
                }

                sort($final_allows);
                sort($final_disallows);

                // ★ Print Allow Rules ★
                foreach ($final_allows as $path) {
                    $content .= "Allow: " . $path . "\n";
                }

                // ★ FORMATTING: EXACTLY 1 LINE GAP ★
                if (!empty($final_allows) && !empty($final_disallows)) {
                    $content .= "\n";
                }

                // ★ Print Disallow Rules ★
                foreach ($final_disallows as $path) {
                    $content .= "Disallow: " . $path . "\n";
                }

                $content .= "\n";
            }

            // 3. Add custom rules
            if (!empty(self::$robots_custom_rules)) {
                foreach (self::$robots_custom_rules as $rule_line) {
                    $content .= $rule_line . "\n";
                }
                $content .= "\n";
            }

            // 4. Add Sitemap
            $content .= "Sitemap: " . rtrim(self::root(), '/') . "/sitemap.xml\n";

            echo rtrim($content);
            exit;
        })->header(['text'])->name("robots")->allow();

        // ১. ইনিশিয়ালাইজেশন নিশ্চিত করা
        if (!self::$initialized)
            self::initialize();
        // PHML::init();

        $server_url_parts = self::$server_url;
        $request_method = strtolower($_SERVER['REQUEST_METHOD']);

        // =====================================================================
        // ⚡ THE BULLETPROOF ROUTE SPECIFICITY ALGORITHM ⚡
        // =====================================================================
        usort(self::$routes, function ($a, $b) {
            $a_parts = $a['url'] === '/' ? ['/'] : explode('/', trim($a['url'], '/'));
            $b_parts = $b['url'] === '/' ? ['/'] : explode('/', trim($b['url'], '/'));

            // রুল ১: সেগমেন্ট সংখ্যা (লম্বা রুটগুলো আগে সাজানো হবে)
            if (count($a_parts) !== count($b_parts)) {
                return count($b_parts) <=> count($a_parts);
            }

            // রুল ২: Binary Weight Scoring (Static = 1, Dynamic = 0)
            $a_score = 0;
            $b_score = 0;

            foreach ($a_parts as $part) {
                $a_score = $a_score << 1; // Left Shift: আগের ভ্যালুকে দ্বিগুণ করে (বাম দিকের সেগমেন্টের প্রায়োরিটি বেশি)
                if ($part !== '' && $part[0] !== '@') {
                    $a_score += 1; // Static হলে ১ যোগ
                }
            }

            foreach ($b_parts as $part) {
                $b_score = $b_score << 1;
                if ($part !== '' && $part[0] !== '@') {
                    $b_score += 1;
                }
            }

            // রুল ৩: Root (/) কে সর্বোচ্চ প্রায়োরিটি দেওয়া
            if ($a['url'] === '/')
                $a_score += 999;
            if ($b['url'] === '/')
                $b_score += 999;

            // বড় স্কোর আগে আসবে (Descending Order)
            return $b_score <=> $a_score;
        });
        // =====================================================================

        $matched_route = null;
        $allowed_methods_for_url = [];
        $found_url_pattern = null; // Specificity Lock

        // ২. সাজানো রুটের উপর লুপ চালিয়ে পারফেক্ট ম্যাচ বের করা
        foreach (self::$routes as $route) {
            $route_url_parts = ($route['url'] === '/') ? ['/'] : explode('/', trim($route['url'], '/'));

            // সেগমেন্ট সংখ্যা না মিললে এই রুট বাদ
            if (count($route_url_parts) !== count($server_url_parts))
                continue;

            $temp_params = [];
            $url_matches = true;
            $current_score = 0;

            for ($i = 0; $i < count($route_url_parts); $i++) {
                $route_part = $route_url_parts[$i];
                $server_part = $server_url_parts[$i];

                if (isset($route_part[0]) && $route_part[0] === '@') {
                    // ক্রিটিক্যাল ফিক্স: '/' বা খালি স্ট্রিং কখনোই ডাইনামিক প্যারামিটার হতে পারে না
                    if ($server_part === '/' || $server_part === '') {
                        $url_matches = false;
                        break;
                    }
                    $temp_params[substr($route_part, 1)] = $server_part;
                } elseif ($route_part !== $server_part) {
                    $url_matches = false;
                    break;
                }
            }

            if ($url_matches) {
                // যেহেতু রুটগুলো হাই-স্কোর থেকে লো-স্কোরে সাজানো, 
                // তাই প্রথম যে রুটটি ম্যাচ করবে, সেটিই হলো সবচেয়ে "Specific" এবং সঠিক রুট!
                if ($found_url_pattern === null || $current_score >= $best_score) {
                    $found_url_pattern = $route['url'];
                    $best_score = $current_score;
                }

                // একবার প্যাটার্ন লক হয়ে গেলে, অন্য কোনো ডাইনামিক ওভাররাইট অ্যালাউ করব না।
                // শুধুমাত্র এই নির্দিষ্ট প্যাটার্নের অন্যান্য মেথডগুলো (POST, PUT) সংগ্রহ করব OPTIONS রিকোয়েস্টের জন্য।
                if ($route['url'] === $found_url_pattern) {
                    $allowed_methods_for_url[] = strtoupper($route['method']);

                    if (strtolower($route['method']) === $request_method && !$matched_route) {
                        self::$params = $temp_params;
                        self::$matched_route = $route;
                        self::$callback = $route['callback'];
                        self::$matched = true;
                        $matched_route = $route;
                    }
                }
            }
        }

        // ৩. স্বয়ংক্রিয় HEAD সাপোর্ট: যদি GET মেথড অনুমোদিত থাকে, তাহলে HEAD ও থাকবে
        if (in_array('GET', $allowed_methods_for_url)) {
            $allowed_methods_for_url[] = 'HEAD';
            // যদি রিকোয়েস্টটি HEAD হয় এবং কোনো GET রুট ম্যাচ করে, কিন্তু কোনো explicit HEAD রুট না থাকে
            if ($request_method === 'head' && !$matched_route) {
                // GET রুটটিকেই matched_route হিসেবে ব্যবহার করা হবে
                foreach (self::$routes as $route) {
                    if ($route['url'] === ($matched_route['url'] ?? $route['url']) && $route['method'] === 'get') {
                        $matched_route = $route;
                        break;
                    }
                }
            }
        }

        // ৪. স্বয়ংক্রিয় OPTIONS রিকোয়েস্ট হ্যান্ডলিং
        if ($request_method === 'options') {
            if (!empty($allowed_methods_for_url)) {
                http_response_code(204); // No Content
                header('Allow: ' . implode(', ', array_unique($allowed_methods_for_url)));
                // আপনি চাইলে এখানে কাস্টম CORS হেডার যোগ করতে পারেন
                // header('Access-Control-Allow-Methods: ' . implode(', ', $allowed_methods_for_url));
                // header('Access-Control-Allow-Origin: *');
            } else {
                if (is_callable($error_handler))
                    $error_handler(404, 'Not Found', null);
                else {
                    http_response_code(404);
                    echo "<h1>404 Not Found</h1>";
                }
            }
            self::finalizeCachePolicy();
            return;
        }

        // ৫. রুট খুঁজে পাওয়া গেলে প্রসেস করা, অন্যথায় 404/405 হ্যান্ডেল করা
        if ($matched_route) {
            // HEAD রিকোয়েস্টের জন্য আউটপুট বাফারিং শুরু
            if ($request_method === 'head') {
                ob_start();
            }

            $request_data = self::gatherRequestData();

            // --- Global Try-Catch for Guard and Route Callback ---
            try {
                if (self::$guard_config !== null && !self::$guard_activated) {
                    self::$guard_instance = new PhroGuard(self::$guard_config, $request_data);
                    self::$guard_instance->protect(); // This will run all shields except attempt_shield initially
                    self::$guard_activated = true;
                }

                // এটি PHRO::executeCallback($matched_route['callback'], $final_data, $error_handler); এর ঠিক উপরে থাকবে।
                // PHRO::listen() মেথডের ভেতরে মাঝের সমস্ত কোড, যা middleware এবং callback execution হ্যান্ডেল করে,
                // সবকিছু এই নতুন try-catch ব্লকের ভেতরে চলে আসবে।

            } catch (PhroSecurityException $e) {
                // 🚨 এই ক্যাচ ব্লকটি এখন PhroGuard::protect() এবং PhroAttemptShield উভয় থেকে আসা
                // PhroSecurityException কে সঠিকভাবে হ্যান্ডেল করবে।
                if (is_callable($error_handler)) {
                    $error_handler($e->getCode(), $e->getMessage(), $e->getFile() . ":" . $e->getLine()); // Pass correct code and message
                } else {
                    // Fallback to PhroGuard::block if no custom error_handler is provided
                    PhroGuard::block($e->getMessage(), $e->getCode(), $e->getFile() . ":" . $e->getLine());
                }
                self::finalizeCachePolicy();
                return; // Stop processing immediately
            }

            // === UNIFIED SECURITY EXCEPTION HANDLER ===
            if (!isset($securityHandlerRegistered)) {
                $securityHandlerRegistered = true;

                set_exception_handler(function (\Throwable $e) use ($error_handler) {
                    if ($e instanceof PhroSecurityException) {
                        $securityCode = $e->getCode() ?: 403;
                        if (is_callable($error_handler)) {
                            $error_handler($securityCode, $e->getMessage(), $e->getFile() . ":" . $e->getLine());
                        } else {
                            PhroGuard::block($e->getMessage(), $securityCode);
                        }
                        exit;
                    }

                    error_log(sprintf(
                        'Unhandled %s: %s in %s:%d',
                        $e::class,
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ));
                    if (is_callable($error_handler)) {
                        $error_handler(500, $e->getMessage(), $e->getFile() . ":" . $e->getLine());
                    } else {
                        http_response_code(500);
                        echo '<h1>500 Internal Server Error</h1>';
                    }
                    self::finalizeCachePolicy();
                    exit;
                });
            }

            try {
                foreach ($matched_route['middlewares'] as $mw) {
                    $callback = null;
                    $reflection = null;

                    // --- চূড়ান্ত এবং নির্ভরযোগ্য ধরন শনাক্তকরণ ---

                    // ধরন ১: [ClassName::class, 'methodName']
                    if (is_array($mw) && isset($mw[0]) && is_string($mw[0]) && class_exists($mw[0])) {
                        $class = $mw[0];
                        $method = $mw[1] ?? 'handle'; // *** নতুন: যদি মেথড না দেওয়া হয়, তবে ডিফল্ট 'handle' ***

                        if (!method_exists($class, $method)) {
                            throw new \Exception("Middleware method '{$method}' not found in class '{$class}'.");
                        }
                        $callback = [$class, $method];
                        $reflection = new \ReflectionMethod($callback[0], $callback[1]);
                    }
                    // ধরন ২: ClassName::class (স্ট্রিং)
                    elseif (is_string($mw) && class_exists($mw)) {
                        if (!method_exists($mw, 'handle')) {
                            throw new \Exception("Default middleware method 'handle' not found in class '{$mw}'.");
                        }
                        $callback = [$mw, 'handle'];
                        $reflection = new \ReflectionMethod($callback[0], $callback[1]);
                    }
                    // ধরন ৩: Closure
                    elseif ($mw instanceof \Closure) {
                        $callback = $mw;
                        $reflection = new \ReflectionFunction($callback);
                    }
                    // অন্য কোনো কিছু হলে এরর
                    else {
                        throw new \Exception("Invalid middleware specified. It must be a class name, a [class, method] array, or a Closure.");
                    }

                    // প্যারামিটার রিজলভিং (সহজ সংস্করণ)
                    $args_to_pass = [];
                    if ($reflection->getNumberOfParameters() > 0) {
                        $args_to_pass[] = $request_data;
                    }

                    $result = null;

                    // সঠিক উপায়ে কল করা
                    if ($reflection instanceof \ReflectionMethod) {
                        if ($reflection->isStatic()) {
                            $result = $reflection->invokeArgs(null, $args_to_pass);
                        } else {
                            $instance = new $callback[0]();
                            $result = $reflection->invokeArgs($instance, $args_to_pass);
                        }
                    } else { // ReflectionFunction
                        $result = $reflection->invokeArgs($args_to_pass);
                    }

                    if ($result !== true) {
                        return;
                    }
                }
            } catch (\Throwable $e) {
                if ($e instanceof PhroSecurityException) {
                    $code = $e->getCode() ?: 403;
                    if (is_callable($error_handler)) {
                        $error_handler($code, $e->getMessage(), $e->getFile() . ":" . $e->getLine());
                    } else {
                        http_response_code($code);
                        echo "<h1>{$code} Blocked</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
                    }
                    return;
                }

                // normal error (500)
                if (is_callable($error_handler)) {
                    $error_handler(500, $e->getMessage(), $e->getFile() . ":" . $e->getLine());
                } else {
                    http_response_code(500);
                    echo "<h1>500 Server Error</h1>";
                }
            }

            // রেসপন্স হেডার সেট করা
            $headers_to_send = $matched_route['headers'] ?? [];
            if (!isset($headers_to_send['Content-Type'])) {
                $content_type_exists = false;
                foreach ($headers_to_send as $key => $value) {
                    if (strtolower($key) === 'content-type') {
                        $content_type_exists = true;
                        break;
                    }
                }
                if (!$content_type_exists) {
                    $headers_to_send['Content-Type'] = 'text/html; charset=utf-8';
                }
            }
            foreach ($headers_to_send as $key => $value) {
                if ($value === '')
                    header_remove($key);
                else
                    header("{$key}: {$value}");
            }

            $processed_files = [];
            if (!empty($_FILES)) {
                $allowed_extensions = [
                    // Images
                    'jpg',
                    'jpeg',
                    'png',
                    'gif',
                    'webp',
                    'svg',
                    'bmp',
                    'ico',
                    'tiff',
                    'tif',
                    // Documents
                    'pdf',
                    'doc',
                    'docx',
                    'xls',
                    'xlsx',
                    'ppt',
                    'pptx',
                    'txt',
                    'rtf',
                    'csv',
                    'odt',
                    'ods',
                    'odp',
                    // Audio
                    'mp3',
                    'wav',
                    'ogg',
                    'm4a',
                    'aac',
                    // Video
                    'mp4',
                    'mov',
                    'avi',
                    'wmv',
                    'webm',
                    'mkv',
                    // Archives
                    'zip',
                    'rar',
                    '7z',
                    'tar',
                    'gz',
                    // Code/Data (Careful with these in production)
                    'json',
                    'xml'
                ];

                $allowed_mime_types = [
                    // Images
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'image/webp',
                    'image/svg+xml',
                    'image/bmp',
                    'image/x-icon',
                    'image/tiff',
                    // Documents
                    'application/pdf',
                    'text/plain',
                    'text/csv',
                    'application/rtf',
                    'application/msword',                                                      // .doc
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
                    'application/vnd.ms-excel',                                                // .xls
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',       // .xlsx
                    'application/vnd.ms-powerpoint',                                           // .ppt
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',// .pptx
                    'application/vnd.oasis.opendocument.text',                                 // .odt
                    'application/vnd.oasis.opendocument.spreadsheet',                          // .ods
                    'application/vnd.oasis.opendocument.presentation',                         // .odp
                    // Audio
                    'audio/mpeg',
                    'audio/wav',
                    'audio/ogg',
                    'audio/x-m4a',
                    'audio/aac',
                    // Video
                    'video/mp4',
                    'video/quicktime',
                    'video/x-msvideo',
                    'video/x-ms-wmv',
                    'video/webm',
                    'video/x-matroska',
                    // Archives
                    'application/zip',
                    'application/x-rar-compressed',
                    'application/x-7z-compressed',
                    'application/x-tar',
                    'application/gzip',
                    // Code/Data
                    'application/json',
                    'text/xml',
                    'application/xml'
                ];
                $max_file_size = 256 * 1024 * 1024;

                foreach ($_FILES as $key => $file) {
                    if (is_array($file['name'])) {
                        foreach ($file['name'] as $i => $name) {
                            if ($file['error'][$i] === UPLOAD_ERR_OK) {
                                $tmp_name = $file['tmp_name'][$i];
                                $size = $file['size'][$i];
                                $type = mime_content_type($tmp_name);
                                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                                if (
                                    $size <= $max_file_size &&
                                    in_array($ext, $allowed_extensions) &&
                                    in_array($type, $allowed_mime_types)
                                ) {

                                    $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($name));

                                    $processed_files[$key][] = [
                                        'name' => $safe_name,
                                        'type' => $type,
                                        'tmp_name' => $tmp_name,
                                        'size' => $size,
                                        'extension' => $ext
                                    ];
                                }
                            }
                        }
                    } else {
                        if ($file['error'] === UPLOAD_ERR_OK) {
                            $tmp_name = $file['tmp_name'];
                            $size = $file['size'];
                            $type = mime_content_type($tmp_name);
                            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                            if (
                                $size <= $max_file_size &&
                                in_array($ext, $allowed_extensions) &&
                                in_array($type, $allowed_mime_types)
                            ) {

                                $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name']));

                                $processed_files[$key] = [
                                    'name' => $safe_name,
                                    'type' => $type,
                                    'tmp_name' => $tmp_name,
                                    'size' => $size,
                                    'extension' => $ext
                                ];
                            }
                        }
                    }
                }
            }

            $final_data = array_merge(
                $request_data['server'],
                $request_data['agent'],
                $request_data['headers'],
                $request_data['cookies'],
                $request_data['params'],
                $request_data['data']
            );
            $final_data = array_merge($final_data, $request_data);
            if ($processed_files) {
                $final_data['files'] = $processed_files;
                $final_data['data']['files'] = $processed_files;
            }
            if (!empty($_FILES)) {
                $final_data['files'] = self::processUploadedFiles($_FILES);
            }
            $final_data['route_details'] = $matched_route;

            self::$callback_context_vars = $final_data;
            $importer = Importer::getInstance();
            $importer->setContext(self::$params);

            self::executeCallback($matched_route['callback'], $final_data, $error_handler);

            self::$callback_context_vars = [];
            $importer->clearContext();

            if ($request_method === 'head') {
                ob_end_clean();
            }
        } else {
            // যদি URL ম্যাচ করে কিন্তু মেথড না (405 Method Not Allowed)
            if (!empty($allowed_methods_for_url)) {
                http_response_code(405);
                header('Allow: ' . implode(', ', array_unique($allowed_methods_for_url)));
                if (is_callable($error_handler))
                    $error_handler(405, 'Method Not Allowed', null);
                else {
                    echo "<h1>405 Method Not Allowed</h1>";
                }
            } else {
                // যদি URL ই ম্যাচ না করে (404 Not Found)
                if (is_callable($error_handler))
                    $error_handler(404, 'Not Found', null);
                else {
                    http_response_code(404);
                    echo "<h1>404 Not Found</h1>";
                }
            }
        }

        self::finalizeCachePolicy($matched_route['headers'] ?? []);
    }

    /**
     * Applies one final cache policy after sessions, middleware and callbacks.
     * Error responses are never cached; successful routes keep their explicit policy.
     */
    private static function finalizeCachePolicy(array $route_headers = []): void
    {
        if (headers_sent()) {
            return;
        }

        $status = http_response_code();
        if ($status >= 400) {
            header_remove('Cache-Control');
            header_remove('Pragma');
            header_remove('Expires');
            header('Cache-Control: no-store, private, max-age=0, must-revalidate', true);
            header('Pragma: no-cache', true);
            header('Expires: 0', true);
            return;
        }

        $cache_policy = null;
        foreach ($route_headers as $name => $value) {
            if (is_string($name) && strtolower($name) === 'cache-control') {
                $cache_policy = (string) $value;
                break;
            }
        }

        if ($cache_policy !== null && $cache_policy !== '') {
            header_remove('Cache-Control');
            header('Cache-Control: ' . $cache_policy, true);
            if (stripos($cache_policy, 'no-cache') === false && stripos($cache_policy, 'no-store') === false) {
                header_remove('Pragma');
                header_remove('Expires');
            }
        }
    }

    /**
     * Matches strong/weak validators and compression variants emitted by
     * Nginx or a CDN for the same source representation.
     */
    private static function requestEtagMatches(string $etag): bool
    {
        $header = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($header === '') {
            return false;
        }

        $normalize = static function (string $value): string {
            $value = trim($value);
            if (str_starts_with($value, 'W/')) {
                $value = substr($value, 2);
            }
            return preg_replace('/-(?:gzip|br)"$/i', '"', $value) ?? $value;
        };
        $expected = $normalize($etag);

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || hash_equals($expected, $normalize($candidate))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Executes the given callback with advanced, automatic parameter resolving.
     * It intelligently handles closures, instance methods, and static methods.
     * It resolves parameters by name, type, and defaults.
     *
     * @param mixed $callback The callback to execute (Closure, ['Class', 'method']).
     * @param array $data The full request data array.
     * @param callable|null $error_handler The global error handler.
     * @return void
     */
    private static function executeCallback($callback, array $data, $error_handler)
    {
        try {
            $reflection = null;
            if (is_array($callback) && isset($callback[0], $callback[1])) {
                // Handles [ClassName::class, 'methodName']
                if (!class_exists($callback[0]))
                    throw new \Exception("Controller class '{$callback[0]}' not found.");
                if (!method_exists($callback[0], $callback[1]))
                    throw new \Exception("Method '{$callback[1]}' not found in class '{$callback[0]}'.");
                $reflection = new \ReflectionMethod($callback[0], $callback[1]);
            } elseif (is_callable($callback)) {
                // Handles closures and standard functions
                $reflection = new \ReflectionFunction($callback);
            } else {
                throw new \Exception("Invalid callback provided for the route.");
            }

            $parameters = $reflection->getParameters();
            $args_to_pass = [];

            foreach ($parameters as $param) {
                $param_name = $param->getName();
                $param_type = $param->getType() ? $param->getType()->getName() : null;

                // --- Automatic Parameter Resolution Logic ---
                // Priority: 1. URL Params, 2. Typed Objects, 3. Named Data, 4. Globals, 5. Defaults

                // 1. Resolve from URL parameters by name (e.g., @id -> $id)
                if (isset($data['params'][$param_name])) {
                    $args_to_pass[] = $data['params'][$param_name];

                    // 2. Resolve by Type-Hint (future-proof for DI container)
                    // Example: function (Request $request) { ... }
                    // For now, we can add a simple check for a potential Request class.
                    // elseif ($param_type === 'Request' && class_exists('Request')) {
                    //     $args_to_pass[] = new Request($data);

                    // 3. Resolve the full data array if named 'data', 'request', etc.
                } elseif (in_array($param_name, ['data', 'request', 'final_data', 'dataa', 'info', 'req', 'dt'])) {
                    $args_to_pass[] = $data;

                    // 4. Resolve from global variables (for advanced cases like your $nnn example)
                } elseif (isset($GLOBALS[$param_name])) {
                    $args_to_pass[] = $GLOBALS[$param_name];

                    // 5. Resolve if the parameter has a default value
                } elseif ($param->isDefaultValueAvailable()) {
                    $args_to_pass[] = $param->getDefaultValue();

                    // 6. Resolve to null if the parameter allows it
                } elseif ($param->allowsNull()) {
                    $args_to_pass[] = null;

                    // 7. If nothing matches, we cannot resolve the parameter.
                } else {
                    $callable_name = $reflection->getName();
                    throw new \Exception("Could not resolve parameter '{$param_name}' for callable '{$callable_name}'.");
                }
            }

            // Finally, invoke the callback with the resolved arguments
            if ($reflection instanceof \ReflectionMethod) {
                if ($reflection->isStatic()) {
                    // Call as: ClassName::methodName(...$args_to_pass);
                    $reflection->invokeArgs(null, $args_to_pass);
                } else {
                    // Call as: (new ClassName())->methodName(...$args_to_pass);
                    $controller_instance = new $callback[0]();
                    $reflection->invokeArgs($controller_instance, $args_to_pass);
                }
            } else { // It's a ReflectionFunction
                // Call as: functionName(...$args_to_pass);
                $reflection->invokeArgs($args_to_pass);
            }

        } catch (\Throwable $e) {
            // যদি এটা security সম্পর্কিত exception হয় → সঠিক কোড + মেসেজ দিয়ে handler কল করো
            if ($e instanceof PhroSecurityException) {
                $code = $e->getCode() ?: 403;          // default 403 রাখা যায়
                $message = $e->getMessage();
                $at = $e->getFile() . ":" . $e->getLine();

                if (is_callable($error_handler)) {
                    $error_handler($code, $message, $at);   // ← শুধু আসল মেসেজ পাঠাও, ফাইল-লাইন যোগ করো না
                } else {
                    // fallback যদি কোনো handler না থাকে
                    http_response_code($code);
                    echo "<h1>{$code} Blocked</h1>";
                    echo "<p>" . htmlspecialchars($message) . "</p>";
                }
                return;   // ← খুব জরুরি — এরপর আর কোনো কোড চালানো যাবে না
            }

            // বাকি সব সাধারণ PHP error → 500
            $code = 500;
            $message = $e->getMessage();   // ফাইল-লাইন এখানেও যোগ করা যাবে না যদি custom handler থাকে
            $at = $e->getFile() . ":" . $e->getLine();

            if (is_callable($error_handler)) {
                $error_handler($code, $message, $at);       // ← আবারো: শুধু মেসেজ, ফাইল-লাইন নয়
            } else {
                http_response_code(500);
                echo "<h1>500 Internal Server Error</h1>";
                if (class_exists('PHDE', false) && PHDE::isDebug()) {
                    echo "<p>Something went wrong: " . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>";
                }
                // debugging এর জন্য চাইলে ফাইল-লাইন দেখাতে পারো, কিন্তু production এ না
            }
        }
    }
}
?>
