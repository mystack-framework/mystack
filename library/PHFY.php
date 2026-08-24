<?php

/**
 * ============================================================================
 * Class: PHFY
 * Title: Notifications & Subscriptions
 * ============================================================================
 * 
 * Handles advanced notifications including ntfy public/private topics, PHLS-backed subscriptions, VAPID/Web Push notifications, and PHJS client integration.
 * 
 * Features:
 * - ntfy public and private notification delivery.
 * - VAPID/Web Push notification handling.
 * - PHLS-backed topic subscriptions.
 * - Seamless PHJS client configuration.
 * 
 * Usage Example:
 * ```php
 * PHFY::notify('alerts', 'System deployment completed successfully.');
 * ```
 * 
 * @package    MyStack
 * @author     Sakibur Rahman (@sakibweb)
 * @published  https://github.com/mystack-framework
 * @web        https://mystack-framework.github.io
 * @license    Apache License 2.0
 * ============================================================================
 */
final class PHFY
{
    private static array $config = [];
    private static bool $configured = false;
    private static bool $routesRegistered = false;
    private static ?array $storageCheck = null;

    public static function configure(array $options = []): array
    {
        $project = self::slug((string) ($options['project'] ?? self::projectName()));
        $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        $defaults = [
            'enabled' => false,
            'server' => 'https://ntfy.sh',
            'project' => $project,
            'public_topic' => $project . '-public',
            'private_topic' => $project . '-private',
            'token' => '',
            'private_token' => '',
            'vapid_public_key' => '',
            'vapid_private_key' => '',
            'vapid_subject' => 'mailto:admin@' . $host,
            'webpush_auto' => true,
            'private_endpoint' => '',
            'poll_seconds' => 20,
            'authorizer' => null,
            'user' => $_SESSION['user']['email'] ?? $_SESSION['user']['account'] ?? null,
            'permissions' => array_values(array_filter([$_SESSION['user']['role'] ?? null])),
            'keywords' => [],
        ];
        self::$config = array_replace($defaults, $options);
        self::$config['server'] = rtrim((string) self::$config['server'], '/');
        self::$config['project'] = self::slug((string) self::$config['project']);
        self::$config['public_topic'] = self::slug((string) self::$config['public_topic']);
        self::$config['private_topic'] = self::slug((string) self::$config['private_topic']);
        self::$config['poll_seconds'] = max(5, (int) self::$config['poll_seconds']);
        self::$config['permissions'] = self::listValue(self::$config['permissions']);
        self::$config['keywords'] = self::listValue(self::$config['keywords']);
        self::$config['private_endpoint'] = self::$config['enabled']
            ? (self::$config['private_endpoint'] ?: self::defaultEndpoint())
            : '';
        self::$configured = true;
        return self::$config;
    }

    public static function config(): array
    {
        return self::$configured ? self::$config : self::configure();
    }

    public static function public(string $message, array $options = []): array
    {
        return self::send($message, array_replace($options, ['type' => 'public']));
    }

    public static function private(string $message, array $options = []): array
    {
        return self::send($message, array_replace($options, ['type' => 'private']));
    }

    public static function send(string $message, array $options = []): array
    {
        $cfg = self::config();
        $type = (($options['type'] ?? 'public') === 'private') ? 'private' : 'public';
        $topic = $type === 'private' ? $cfg['private_topic'] : $cfg['public_topic'];
        if (!$cfg['enabled'] || $topic === '')
            return ['status' => false, 'skipped' => true];

        $payload = [
            'id' => bin2hex(random_bytes(10)),
            'type' => $type,
            'message' => $message,
            'title' => (string) ($options['title'] ?? ''),
            'users' => self::listValue($options['users'] ?? $options['user'] ?? []),
            'permissions' => self::listValue($options['permissions'] ?? $options['permission'] ?? []),
            'keywords' => self::listValue($options['keywords'] ?? []),
            'timestamp' => time(),
            'data' => is_array($options['data'] ?? null) ? $options['data'] : new stdClass(),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false)
            return ['status' => false, 'error' => 'Unable to encode notification.'];

        if ($type === 'private' && $cfg['private_token'] === '') {
            $stored = self::storePrivatePayload($payload);
            $result = [
                'status' => $stored,
                'code' => $stored ? 202 : 503,
                'body' => '',
                'transport' => 'local',
            ];
        } else {
            $headers = ['Content-Type: application/json', 'Title: ' . self::headerValue($payload['title'])];
            $token = $type === 'private' ? $cfg['private_token'] : $cfg['token'];
            if ($token !== '')
                $headers[] = 'Authorization: Bearer ' . $token;
            $result = self::http($cfg['server'] . '/' . rawurlencode($topic), 'POST', $body, $headers);
        }
        $push = self::deliverWebPush($payload);
        return $result + ['id' => $payload['id'], 'topic' => $topic, 'web_push' => $push];
    }

    public static function clientConfig(array $context = []): array
    {
        self::ensureVapidKeys();
        $cfg = self::config();
        $capability = self::webPushCapability();
        $authorization = self::authorize();
        $private_allowed = !empty($authorization['status'])
            && (string) $cfg['private_endpoint'] !== '';
        return [
            'enabled' => (bool) $cfg['enabled'],
            'project' => $cfg['project'],
            'projectPrefix' => self::projectPrefix(),
            'basePath' => self::pathPrefix(),
            'publicUrl' => $cfg['server'] . '/' . rawurlencode($cfg['public_topic']) . '/sse',
            'privateAllowed' => $private_allowed,
            'privateEndpoint' => $private_allowed ? $cfg['private_endpoint'] : '',
            'pushEnabled' => $capability['enabled'],
            'vapidPublicKey' => $cfg['vapid_public_key'],
            'pushSubscribeEndpoint' => self::pathPrefix() . '/_phfy/push/subscribe',
            'pushMode' => $capability['mode'],
            'csrfToken' => class_exists('PHRO') ? PHRO::getToken() : '',
            'pollSeconds' => (int) $cfg['poll_seconds'],
            'user' => $context['user'] ?? $authorization['user'] ?? $cfg['user'],
            'permissions' => self::listValue(
                $context['permissions'] ?? $authorization['permissions'] ?? $cfg['permissions']
            ),
            'keywords' => self::listValue(
                $context['keywords'] ?? $authorization['keywords'] ?? $cfg['keywords']
            ),
        ];
    }

    public static function webPushCapability(): array
    {
        $cfg = self::config();
        $crypto = function_exists('openssl_encrypt') && function_exists('openssl_sign');
        if (!$cfg['enabled'] || !$cfg['webpush_auto'])
            return ['enabled' => false, 'mode' => 'ntfy'];
        $keyReady = self::ensureVapidKeys();
        if ($crypto && $keyReady)
            return ['enabled' => true, 'mode' => 'webpush'];
        return ['enabled' => false, 'mode' => 'ntfy'];
    }

    /**
     * Performs an in-memory hosting capability test without storing keys.
     */
    public static function cryptoCapability(): array
    {
        if (
            !function_exists('openssl_pkey_new')
            || !function_exists('openssl_pkey_derive')
            || !function_exists('openssl_sign')
            || !function_exists('openssl_encrypt')
        ) {
            return ['status' => false, 'ec' => false, 'derive' => false, 'sign' => false, 'encrypt' => false];
        }

        $first = self::createEcKey();
        $second = self::createEcKey();
        if ($first === false || $second === false) {
            return ['status' => false, 'ec' => false, 'derive' => false, 'sign' => false, 'encrypt' => false];
        }
        $firstDetails = openssl_pkey_get_details($first);
        $secondDetails = openssl_pkey_get_details($second);
        $derive = is_array($secondDetails)
            && !empty($secondDetails['key'])
            && openssl_pkey_derive($secondDetails['key'], $first, 32) !== false;
        $signature = '';
        $sign = openssl_sign('mystack-webpush-check', $signature, $first, OPENSSL_ALGO_SHA256);
        $tag = '';
        $encrypt = openssl_encrypt(
            'mystack-webpush-check',
            'aes-128-gcm',
            random_bytes(16),
            OPENSSL_RAW_DATA,
            random_bytes(12),
            $tag
        ) !== false;
        $ec = is_array($firstDetails) && !empty($firstDetails['ec']['x']) && !empty($firstDetails['ec']['y']);
        return [
            'status' => $ec && $derive && $sign && $encrypt,
            'ec' => $ec,
            'derive' => $derive,
            'sign' => $sign,
            'encrypt' => $encrypt,
        ];
    }

    private static function ensureVapidKeys(): bool
    {
        $cfg = self::config();
        if ($cfg['vapid_public_key'] !== '' && $cfg['vapid_private_key'] !== '')
            return true;
        try {
            if (self::$storageCheck === null) {
                self::$storageCheck = PHLS::checker(false);
            }
            if (empty(self::$storageCheck['status']))
                return false;

            $vapidKey = self::storageKey('vapid');
            $saved = PHLS::get($vapidKey);
            if (!is_array($saved)) {
                // One-time, non-destructive migration from the pre-PHCO namespace.
                $legacy = PHLS::get('phfy:vapid');
                if (is_array($legacy) && !empty($legacy['public']) && !empty($legacy['private'])) {
                    PHLS::addIfAbsent($vapidKey, $legacy, null, ['phfy', 'vapid']);
                    $saved = PHLS::get($vapidKey) ?: $legacy;
                }
            }
            if (is_array($saved) && !empty($saved['public']) && !empty($saved['private'])) {
                self::$config['vapid_public_key'] = (string) $saved['public'];
                self::$config['vapid_private_key'] = (string) $saved['private'];
                return true;
            }
            if (!function_exists('openssl_pkey_new'))
                return false;
            $key = self::createEcKey();
            if ($key === false || !openssl_pkey_export($key, $privatePem))
                return false;
            $details = openssl_pkey_get_details($key);
            if (!is_array($details) || empty($details['ec']['x']) || empty($details['ec']['y']))
                return false;
            $public = self::b64url("\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT));
            PHLS::addIfAbsent($vapidKey, ['public' => $public, 'private' => $privatePem], null, ['phfy', 'vapid']);

            // Always use the value that actually won the atomic storage write.
            $stored = PHLS::get($vapidKey);
            if (!is_array($stored) || empty($stored['public']) || empty($stored['private']))
                return false;
            self::$config['vapid_public_key'] = (string) $stored['public'];
            self::$config['vapid_private_key'] = (string) $stored['private'];
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function deliverWebPush(array $payload): array
    {
        if (!self::ensureVapidKeys() || !function_exists('openssl_pkey_derive') || !function_exists('curl_init'))
            return ['status' => false, 'fallback' => 'ntfy'];
        $sent = 0;
        $failed = 0;
        $subscriptionPrefix = self::storageKey('subscription:');
        try {
            foreach (PHLS::getAll() as $key => $record) {
                if (
                    !self::matchesStoragePrefix((string) $key, $subscriptionPrefix, 'phfy:subscription:') || !is_array($record) || !self::matches($payload, [
                        'user' => $record['user'] ?? null,
                        'permissions' => $record['permissions'] ?? [],
                        'keywords' => $record['keywords'] ?? [],
                    ])
                )
                    continue;
                $code = self::sendOneWebPush($record, $payload);
                if ($code >= 200 && $code < 300)
                    $sent++;
                else {
                    $failed++;
                    if (in_array($code, [404, 410], true))
                        PHLS::remove((string) $key);
                }
            }
        } catch (Throwable $e) {
            return ['status' => false, 'sent' => $sent, 'failed' => $failed + 1, 'fallback' => 'ntfy'];
        }
        return ['status' => $failed === 0, 'sent' => $sent, 'failed' => $failed, 'fallback' => $failed ? 'ntfy' : null];
    }

    private static function sendOneWebPush(array $subscription, array $payload): int
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        $uaPublic = self::b64urlDecode((string) ($subscription['keys']['p256dh'] ?? ''));
        $auth = self::b64urlDecode((string) ($subscription['keys']['auth'] ?? ''));
        if (!self::isSafePushEndpoint($endpoint) || strlen($uaPublic) !== 65 || strlen($auth) < 16)
            return 0;
        $local = self::createEcKey();
        $details = $local ? openssl_pkey_get_details($local) : false;
        if (!$local || !is_array($details) || empty($details['ec']['x']) || empty($details['ec']['y']))
            return 0;
        $serverPublic = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $peerDer = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $uaPublic;
        $peerPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($peerDer), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $peer = openssl_pkey_get_public($peerPem);
        if (!$peer)
            return 0;
        $secret = openssl_pkey_derive($peer, $local, 32);
        if ($secret === false)
            return 0;
        $ikm = self::hkdf($auth, $secret, "WebPush: info\0" . $uaPublic . $serverPublic, 32);
        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = self::hkdfExpand($prk, "Content-Encoding: aes128gcm\0", 16);
        $nonce = self::hkdfExpand($prk, "Content-Encoding: nonce\0", 12);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 3800)
            return 0;
        $tag = '';
        $encrypted = openssl_encrypt(($json ?: '{}') . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($encrypted === false)
            return 0;
        $body = $salt . pack('N', 4096) . chr(strlen($serverPublic)) . $serverPublic . $encrypted . $tag;
        $jwt = self::vapidJwt($endpoint);
        if ($jwt === null)
            return 0;
        $headers = ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Authorization: vapid t=' . $jwt . ', k=' . self::$config['vapid_public_key']];
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        curl_exec($ch);
        $code = curl_error($ch) === '' ? (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) : 0;
        curl_close($ch);
        return $code;
    }

    private static function vapidJwt(string $endpoint): ?string
    {
        $origin = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $header = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64url(json_encode(['aud' => $origin, 'exp' => time() + 43200, 'sub' => self::$config['vapid_subject']]));
        $input = $header . '.' . $claims;
        $der = '';
        if (!openssl_sign($input, $der, self::$config['vapid_private_key'], OPENSSL_ALGO_SHA256))
            return null;
        $raw = self::ecdsaDerToRaw($der);
        return $raw === null ? null : $input . '.' . self::b64url($raw);
    }

    private static function ecdsaDerToRaw(string $der): ?string
    {
        $total = strlen($der);
        if ($total < 8 || $der[0] !== "\x30")
            return null;

        $offset = 1;
        $sequenceLength = self::readDerLength($der, $offset);
        if ($sequenceLength === null || $sequenceLength !== $total - $offset)
            return null;

        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if ($offset >= $total || $der[$offset++] !== "\x02")
                return null;
            $length = self::readDerLength($der, $offset);
            if ($length === null || $length < 1 || $offset + $length > $total)
                return null;
            $value = ltrim(substr($der, $offset, $length), "\0");
            $offset += $length;
            if ($value === '' || strlen($value) > 32)
                return null;
            $parts[] = str_pad($value, 32, "\0", STR_PAD_LEFT);
        }

        return $offset === $total ? $parts[0] . $parts[1] : null;
    }

    private static function readDerLength(string $der, int &$offset): ?int
    {
        $total = strlen($der);
        if ($offset >= $total)
            return null;
        $first = ord($der[$offset++]);
        if (($first & 0x80) === 0)
            return $first;

        $octets = $first & 0x7f;
        if ($octets < 1 || $octets > 4 || $offset + $octets > $total)
            return null;
        $length = 0;
        for ($i = 0; $i < $octets; $i++) {
            $length = ($length << 8) | ord($der[$offset++]);
        }
        return $length;
    }

    private static function hkdf(string $salt, string $ikm, string $info, int $length): string
    {
        return self::hkdfExpand(hash_hmac('sha256', $ikm, $salt, true), $info, $length);
    }
    private static function hkdfExpand(string $prk, string $info, int $length): string
    {
        return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $length);
    }
    private static function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
    private static function b64urlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }

    public static function registerRoutes(): void
    {
        if (self::$routesRegistered || !class_exists('PHRO') || !self::config()['enabled'])
            return;
        self::$routesRegistered = true;
        PHRO::get('/_phfy/config', function ($data) {
            header('Content-Type: application/json; charset=utf-8');
            header_remove('Cache-Control');
            header('Cache-Control: no-store, private', true);
            echo json_encode(self::clientConfig(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        })->header([
                    'json',
                    'Cache-Control' => 'no-store, private',
                ])->name('phfy-config')->allow();
        if (self::config()['private_endpoint'] !== '') {
            PHRO::get(self::routePath(), function ($data) {
                self::privateFeed(); });
        }
        if (method_exists('PHRO', 'post')) {
            PHRO::post('/_phfy/push/subscribe', function ($data) {
                self::savePushSubscription(); });
            PHRO::post('/_phfy/push/unsubscribe', function ($data) {
                self::removePushSubscription(); });
        }
    }

    private static function savePushSubscription(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input['endpoint']) || !is_array($input['keys'] ?? null)) {
            http_response_code(422);
            echo json_encode(['status' => false, 'error' => 'Invalid push subscription']);
            return;
        }
        if (
            strlen((string) $input['endpoint']) > 2048
            || strlen((string) ($input['keys']['p256dh'] ?? '')) > 256
            || strlen((string) ($input['keys']['auth'] ?? '')) > 128
        ) {
            http_response_code(422);
            echo json_encode(['status' => false, 'error' => 'Push subscription is too large']);
            return;
        }
        $endpoint = filter_var((string) $input['endpoint'], FILTER_VALIDATE_URL);
        if (!$endpoint || !self::isSafePushEndpoint($endpoint)) {
            http_response_code(422);
            echo json_encode(['status' => false, 'error' => 'Invalid push endpoint']);
            return;
        }
        $record = [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => (string) ($input['keys']['p256dh'] ?? ''),
                'auth' => (string) ($input['keys']['auth'] ?? ''),
            ],
            'user' => $_SESSION['user']['email'] ?? $_SESSION['user']['account'] ?? null,
            'permissions' => array_values(array_filter([$_SESSION['user']['role'] ?? null])),
            'keywords' => self::listValue(self::config()['keywords'] ?? []),
            'updated_at' => time(),
        ];
        $publicKey = self::b64urlDecode($record['keys']['p256dh']);
        $authKey = self::b64urlDecode($record['keys']['auth']);
        if (strlen($publicKey) !== 65 || $publicKey[0] !== "\x04" || strlen($authKey) < 16) {
            http_response_code(422);
            echo json_encode(['status' => false, 'error' => 'Invalid push keys']);
            return;
        }
        $key = self::storageKey('subscription:' . hash('sha256', $endpoint));
        try {
            PHLS::add($key, $record, 60 * 24 * 60, ['phfy', 'push']);
            echo json_encode(['status' => true]);
        } catch (Throwable $e) {
            http_response_code(503);
            echo json_encode(['status' => false, 'error' => 'Subscription storage unavailable']);
        }
    }

    private static function removePushSubscription(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode((string) file_get_contents('php://input'), true);
        $endpoint = is_array($input) ? (string) ($input['endpoint'] ?? '') : '';
        if ($endpoint !== '' && strlen($endpoint) <= 2048 && self::isSafePushEndpoint($endpoint)) {
            try {
                $suffix = 'subscription:' . hash('sha256', $endpoint);
                PHLS::remove(self::storageKey($suffix));
                PHLS::remove('phfy:' . $suffix);
            } catch (Throwable $e) {
            }
        }
        echo json_encode(['status' => true]);
    }

    public static function privateFeed(): void
    {
        $cfg = self::config();
        header('Content-Type: application/json; charset=utf-8');
        $auth = self::authorize();
        if (!$auth['status']) {
            http_response_code(403);
            echo json_encode(['status' => false, 'error' => 'Unauthorized']);
            return;
        }
        $since = max(0, (int) ($_GET['since'] ?? (time() - 60)));
        if ($cfg['private_token'] === '') {
            echo json_encode([
                'status' => true,
                'messages' => self::localPrivateMessages($since, $auth),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        $headers = ['Authorization: Bearer ' . $cfg['private_token']];
        $result = self::http($cfg['server'] . '/' . rawurlencode($cfg['private_topic']) . '/json?poll=1&since=' . $since, 'GET', null, $headers);
        $messages = [];
        foreach (preg_split('/\r?\n/', (string) ($result['body'] ?? '')) as $line) {
            $item = json_decode($line, true);
            if (is_array($item) && ($item['event'] ?? '') === 'message') {
                $payload = json_decode((string) ($item['message'] ?? ''), true);
                if (is_array($payload) && self::matches($payload, $auth))
                    $messages[] = $payload;
            }
        }
        echo json_encode(['status' => true, 'messages' => $messages], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function authorize(): array
    {
        $cfg = self::config();
        if (is_callable($cfg['authorizer'])) {
            $value = call_user_func($cfg['authorizer']);
            return is_array($value) ? $value : ['status' => (bool) $value];
        }
        if (class_exists('PHAU')) {
            try {
                $check = PHAU::check('users');
                if (!empty($check['status'])) {
                    return [
                        'status' => true,
                        'user' => $check['data']['account'] ?? null,
                        'permissions' => [$check['data']['role'] ?? null],
                        'keywords' => self::listValue($cfg['keywords'] ?? []),
                    ];
                }
            } catch (Throwable $e) {
            }
        }
        if ($cfg['user'] !== null && trim((string) $cfg['user']) !== '') {
            return [
                'status' => true,
                'user' => (string) $cfg['user'],
                'permissions' => self::listValue($cfg['permissions'] ?? []),
                'keywords' => self::listValue($cfg['keywords'] ?? []),
            ];
        }
        return ['status' => false];
    }

    private static function matches(array $payload, array $auth): bool
    {
        $users = self::listValue($payload['users'] ?? []);
        $permissions = self::listValue($payload['permissions'] ?? []);
        $keywords = self::listValue($payload['keywords'] ?? []);
        if ($users && !in_array((string) ($auth['user'] ?? ''), array_map('strval', $users), true))
            return false;
        if ($permissions && !array_intersect(array_map('strval', $permissions), array_map('strval', self::listValue($auth['permissions'] ?? []))))
            return false;
        if ($keywords && !array_intersect(array_map('strval', $keywords), array_map('strval', self::listValue($auth['keywords'] ?? []))))
            return false;
        return true;
    }

    private static function storePrivatePayload(array $payload): bool
    {
        try {
            return PHLS::add(
                self::storageKey('private:' . (int) ($payload['timestamp'] ?? time()) . ':' . (string) ($payload['id'] ?? bin2hex(random_bytes(8)))),
                $payload,
                24 * 60,
                ['phfy', 'private']
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function localPrivateMessages(int $since, array $auth): array
    {
        $messages = [];
        $privatePrefix = self::storageKey('private:');
        try {
            foreach (PHLS::getAll() as $key => $payload) {
                if (!self::matchesStoragePrefix((string) $key, $privatePrefix, 'phfy:private:') || !is_array($payload)) {
                    continue;
                }
                if ((int) ($payload['timestamp'] ?? 0) < $since || !self::matches($payload, $auth)) {
                    continue;
                }
                $messages[] = $payload;
            }
        } catch (Throwable $e) {
            return [];
        }
        usort($messages, static fn($left, $right) => (int) ($left['timestamp'] ?? 0) <=> (int) ($right['timestamp'] ?? 0));
        return $messages;
    }

    private static function isSafePushEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            return false;
        }

        $host = trim((string) $parts['host'], '[]');
        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses[] = $host;
        } else {
            $records = function_exists('dns_get_record')
                ? @dns_get_record($host, DNS_A | DNS_AAAA)
                : [];
            foreach (is_array($records) ? $records : [] as $record) {
                if (!empty($record['ip']))
                    $addresses[] = $record['ip'];
                if (!empty($record['ipv6']))
                    $addresses[] = $record['ipv6'];
            }
            if (!$addresses) {
                $fallback = @gethostbynamel($host);
                $addresses = is_array($fallback) ? $fallback : [];
            }
        }
        if (!$addresses) {
            return false;
        }
        foreach (array_unique($addresses) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Creates a P-256 key and automatically locates openssl.cnf on hosts
     * where PHP/OpenSSL does not expose its configuration path.
     *
     * @return \OpenSSLAsymmetricKey|resource|false
     */
    private static function createEcKey()
    {
        $baseOptions = [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];
        $key = @openssl_pkey_new($baseOptions);
        if ($key !== false) {
            return $key;
        }

        $iniFile = php_ini_loaded_file();
        $candidates = array_filter([
            getenv('OPENSSL_CONF') ?: null,
            $iniFile ? dirname($iniFile) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf' : null,
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
        ]);
        foreach (array_unique($candidates) as $configFile) {
            if (!is_file($configFile) || !is_readable($configFile)) {
                continue;
            }
            $key = @openssl_pkey_new($baseOptions + ['config' => $configFile]);
            if ($key !== false) {
                return $key;
            }
        }
        return false;
    }

    private static function http(string $url, string $method, ?string $body, array $headers): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            return ['status' => $error === '' && $status >= 200 && $status < 300, 'code' => $status, 'body' => (string) $response, 'error' => $error];
        }
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'timeout' => 12, 'ignore_errors' => true]]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m))
            $status = (int) $m[1];
        return ['status' => $response !== false && $status >= 200 && $status < 300, 'code' => $status, 'body' => (string) $response];
    }

    private static function defaultEndpoint(): string
    {
        return self::pathPrefix() . '/_phfy/private';
    }
    private static function routePath(): string
    {
        return '/_phfy/private';
    }
    private static function pathPrefix(): string
    {
        if (class_exists('PHCO') && method_exists('PHCO', 'path')) {
            $path = '/' . trim((string) PHCO::path(), '/');
            return $path === '/' ? '' : $path;
        }
        if (class_exists('PHRO') && method_exists('PHRO', 'root')) {
            $path = (string) (parse_url((string) PHRO::root(), PHP_URL_PATH) ?: '');
            return $path === '/' ? '' : rtrim($path, '/');
        }
        return '';
    }
    private static function projectPrefix(): string
    {
        $prefix = class_exists('PHCO') && method_exists('PHCO', 'pre')
            ? (string) PHCO::pre()
            : preg_replace('/[^a-z0-9]/i', '', basename(dirname(__DIR__))) . '_';
        $prefix = strtolower(trim($prefix));
        return rtrim($prefix, '_') . '_';
    }
    private static function projectName(): string
    {
        return rtrim(self::projectPrefix(), '_') ?: 'mystack';
    }
    private static function storageKey(string $suffix): string
    {
        return self::projectPrefix() . 'phfy:' . ltrim($suffix, ':');
    }
    private static function matchesStoragePrefix(string $key, string $current, string $legacy): bool
    {
        return str_starts_with($key, $current)
            || ($current !== $legacy && str_starts_with($key, $legacy));
    }
    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_replace('/[^a-z0-9._-]+/', '-', $value) ?: 'mystack';
    }
    private static function listValue($value): array
    {
        if (!is_array($value))
            $value = ($value === '' || $value === null) ? [] : [$value];
        return array_values(array_filter(array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $value), static fn($v) => $v !== ''));
    }
    private static function headerValue(string $value): string
    {
        return preg_replace('/[\r\n]+/', ' ', trim($value));
    }
}
