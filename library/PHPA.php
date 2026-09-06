<?php

/**
 * ============================================================================
 * Class: PHPA
 * Title: Payment & Courier Gateway
 * ============================================================================
 * 
 * Unified facade for payment gateways and courier services. Features capability-aware adapters, secure webhooks, refunds processing, and shipment tracking.
 * 
 * Features:
 * - Unified payment gateway facade (Stripe, PayPal, local gateways).
 * - Webhook signature verification and idempotency.
 * - Refunds and transaction management.
 * - Courier and shipment tracking adapters.
 * 
 * Usage Example:
 * ```php
 * PHPA::charge($amount, $currency, $gatewayAdapter);
 * PHPA::handleWebhook($payload, $signature);
 * ```
 * 
 * @package    MyStack
 * @author     Sakibur Rahman (@sakibweb)
 * @published  https://github.com/mystack-framework
 * @web        https://mystack-framework.github.io
 * @license    Apache License 2.0
 * ============================================================================
 */




interface PHPAGatewayInterface {
    public function setKeys(string $key1, string $key2 = '', string $key3 = '', string $key4 = ''): self;
    public function setLogic(?callable $chargeCallback = null, ?callable $verifyCallback = null): self;
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array;
    public function verify(string $transactionId): array;
}

abstract class PHPA_BaseGateway implements PHPAGatewayInterface {
    private static array $seenWebhookEvents = [];
    protected string $key1 = ''; // API Key / Public Key / Store ID
    protected string $key2 = ''; // Secret Key / Private Key / Store Password
    protected string $key3 = ''; // Webhook Secret / Merchant ID / App Key
    protected string $key4 = ''; // Extra credential
    protected bool $isSandbox = false;
    protected $customChargeLogic = null;
    protected $customVerifyLogic = null;
    protected $customRefundLogic = null;
    protected $customWebhookLogic = null;
    protected $customTransport = null;
    protected int $connectTimeout = 10;
    protected int $requestTimeout = 30;
    protected int $webhookTolerance = 300;
    protected array $expectedPayment = [];

    public function setLogic(?callable $chargeCallback = null, ?callable $verifyCallback = null): self {
        $this->customChargeLogic = $chargeCallback;
        $this->customVerifyLogic = $verifyCallback;
        return $this;
    }

    public function setRefundLogic(?callable $callback): self {
        $this->customRefundLogic = $callback;
        return $this;
    }

    public function setWebhookLogic(?callable $callback): self {
        $this->customWebhookLogic = $callback;
        return $this;
    }

    /**
     * Injectable transport for deterministic tests and private gateway adapters.
     */
    public function setTransport(?callable $callback): self {
        $this->customTransport = $callback;
        return $this;
    }

    public function timeout(int $seconds, ?int $connectSeconds = null): self {
        $this->requestTimeout = max(1, min(120, $seconds));
        $this->connectTimeout = max(1, min(
            $this->requestTimeout,
            $connectSeconds ?? min(10, $this->requestTimeout)
        ));
        return $this;
    }

    public function expect(
        string $orderId,
        ?float $amount = null,
        ?string $currency = null
    ): self {
        $this->expectedPayment = [
            'order_id' => trim($orderId),
            'amount' => $amount,
            'currency' => $currency === null ? null : strtoupper(trim($currency)),
        ];
        return $this;
    }

    public function setKeys(string $key1, string $key2 = '', string $key3 = '', string $key4 = ''): self {
        $this->key1 = trim($key1);
        $this->key2 = trim($key2);
        $this->key3 = trim($key3);
        $this->key4 = trim($key4);
        return $this;
    }
    public function sandbox(bool $status = true): self { $this->isSandbox = $status; return $this; }

    public function capabilities(): array {
        return PHPA::gatewayCapabilities($this->gatewayName());
    }

    protected function gatewayName(): string {
        return strtolower(str_replace('PHPA_', '', static::class));
    }

    protected function requireKeys(int $count): ?array {
        $keys = [$this->key1, $this->key2, $this->key3, $this->key4];
        for ($index = 0; $index < $count; $index++) {
            if ($keys[$index] === '') {
                return $this->failure(
                    'configuration_error',
                    'Required gateway credential key' . ($index + 1) . ' is missing.'
                );
            }
        }
        return null;
    }

    protected function validatePayment(float $amount, string $currency, string $orderId): ?array {
        if (!is_finite($amount) || $amount <= 0) {
            return $this->failure('invalid_amount', 'Amount must be greater than zero.');
        }
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3,10}$/', $currency)) {
            return $this->failure('invalid_currency', 'Currency must be a valid currency code.');
        }
        $orderId = trim($orderId);
        if ($orderId === '' || strlen($orderId) > 128) {
            return $this->failure('invalid_order_id', 'Order ID must contain 1 to 128 characters.');
        }
        if ($this->expectedPayment === []) {
            $this->expectedPayment = [
                'order_id' => $orderId,
                'amount' => $amount,
                'currency' => $currency,
            ];
        }
        return null;
    }

    protected function requireCurrency(string $currency, array $supported): ?array {
        $currency = strtoupper(trim($currency));
        $supported = array_map('strtoupper', $supported);
        return in_array($currency, $supported, true)
            ? null
            : $this->failure(
                'unsupported_currency',
                $this->gatewayName() . ' supports: ' . implode(', ', $supported) . '.'
            );
    }

    protected function decimal(float $amount, int $scale = 2): string {
        return number_format($amount, $scale, '.', '');
    }

    protected function minor(float $amount, string $currency): int {
        $zeroDecimal = [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
            'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ];
        $threeDecimal = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];
        $currency = strtoupper($currency);
        $scale = in_array($currency, $zeroDecimal, true)
            ? 0
            : (in_array($currency, $threeDecimal, true) ? 3 : 2);
        return (int) round($amount * (10 ** $scale), 0, PHP_ROUND_HALF_UP);
    }

    protected function idempotencyKey(
        string $operation,
        string $reference,
        array $context = []
    ): string {
        return substr(hash('sha256', implode('|', [
            $this->gatewayName(),
            $operation,
            $reference,
            json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ])), 0, 48);
    }

    protected function success(array $data = []): array {
        unset($data['success'], $data['gateway'], $data['code'], $data['message']);
        return [
            'success' => true,
            'gateway' => $this->gatewayName(),
            'code' => null,
            'message' => null,
            ...$data,
        ];
    }

    protected function failure(
        string $code,
        string $message,
        array $data = []
    ): array {
        unset($data['success'], $data['gateway'], $data['code'], $data['message']);
        return [
            'success' => false,
            'gateway' => $this->gatewayName(),
            'code' => $code,
            'message' => $message,
            ...$data,
        ];
    }

    protected function unsupported(string $operation): array {
        return $this->failure(
            'unsupported_operation',
            ucfirst($operation) . ' is not available for this gateway without a custom merchant adapter.'
        );
    }

    protected function responseFailure(array $response, string $operation): array {
        $message = $response['error']
            ?? ($response['data']['message'] ?? null)
            ?? ($response['data']['error']['message'] ?? null)
            ?? ($response['data']['error'] ?? null)
            ?? ucfirst($operation) . ' request failed.';
        if (!is_string($message)) $message = json_encode($message, JSON_UNESCAPED_UNICODE);
        return $this->failure(
            'gateway_error',
            (string) $message,
            ['http_code' => (int) ($response['code'] ?? 0), 'raw' => $response['data'] ?? null]
        );
    }

    protected function paymentMatches(
        ?string $orderId,
        float|string|null $amount,
        ?string $currency
    ): bool {
        if ($this->expectedPayment === []) return true;
        if (
            ($this->expectedPayment['order_id'] ?? '') !== ''
            && (string) $orderId !== (string) $this->expectedPayment['order_id']
        ) {
            return false;
        }
        if (
            $this->expectedPayment['amount'] !== null
            && $amount !== null
            && abs((float) $amount - (float) $this->expectedPayment['amount']) > 0.00001
        ) {
            return false;
        }
        if (
            $this->expectedPayment['currency'] !== null
            && $currency !== null
            && strtoupper($currency) !== $this->expectedPayment['currency']
        ) {
            return false;
        }
        return true;
    }

    protected function header(array $headers, string $name): ?string {
        foreach ($headers as $key => $value) {
            if (is_int($key) && str_contains((string) $value, ':')) {
                [$key, $value] = explode(':', (string) $value, 2);
            }
            if (strcasecmp(trim((string) $key), $name) === 0) return trim((string) $value);
        }
        return null;
    }

    protected function acceptWebhookEvent(?string $eventId): bool {
        $eventId = trim((string) $eventId);
        if ($eventId === '') return true;
        $key = $this->gatewayName() . ':' . $eventId;
        if (isset(self::$seenWebhookEvents[$key])) return false;
        if (class_exists('PHLS')) {
            try {
                $fresh = PHLS::addIfAbsent(
                    'phpa:webhook:' . hash('sha256', $key),
                    time(),
                    60 * 24 * 7,
                    ['phpa-webhook']
                );
                if (!$fresh) return false;
            } catch (\Throwable $ignored) {
            }
        }
        self::$seenWebhookEvents[$key] = true;
        return true;
    }

    protected function request(
        string $method,
        string $url,
        array $headers = [],
        mixed $data = null,
        array $options = []
    ): array {
        $method = strtoupper($method);
        if (is_callable($this->customTransport)) {
            $result = call_user_func(
                $this->customTransport,
                $method,
                $url,
                $headers,
                $data,
                $options,
                $this
            );
            return is_array($result)
                ? $result
                : ['code' => 0, 'data' => null, 'raw' => null, 'error' => 'Invalid custom transport response.'];
        }
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'data' => null, 'raw' => null, 'error' => 'PHP cURL extension is unavailable.'];
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowLegacySandboxHttp = $this->isSandbox
            && !empty($options['allow_insecure_sandbox'])
            && $host === 'sandbox.mynagad.com';
        if ($scheme !== 'https' && !($scheme === 'http' && $allowLegacySandboxHttp)) {
            return ['code' => 0, 'data' => null, 'raw' => null, 'error' => 'Insecure or invalid payment API URL refused.'];
        }

        $hasContentType = false;
        $hasAccept = false;
        foreach ($headers as $header) {
            $hasContentType = $hasContentType || str_starts_with(strtolower($header), 'content-type:');
            $hasAccept = $hasAccept || str_starts_with(strtolower($header), 'accept:');
        }
        if (!$hasAccept) $headers[] = 'Accept: application/json';
        if ($data !== null && !$hasContentType) $headers[] = 'Content-Type: application/json';
        $headers[] = 'User-Agent: MyStack-PHPA/2.0';

        try {
            $payload = $data;
            if (is_array($data)) {
                $payload = json_encode(
                    $data,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
            }
        } catch (\JsonException $error) {
            return ['code' => 0, 'data' => null, 'raw' => null, 'error' => $error->getMessage()];
        }

        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => $allowLegacySandboxHttp
                ? CURLPROTO_HTTPS | CURLPROTO_HTTP
                : CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return $length;
            },
        ]);
        if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($response === false || $curlErrno !== 0) {
            return [
                'code' => $httpCode,
                'data' => null,
                'raw' => $response,
                'headers' => $responseHeaders,
                'error' => $curlError !== '' ? $curlError : 'Payment network request failed.',
            ];
        }

        $contentType = strtolower((string) ($responseHeaders['content-type'] ?? ''));
        $decoded = null;
        if (str_contains($contentType, 'json') || preg_match('/^\s*[\[{]/', $response)) {
            try {
                $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                return [
                    'code' => $httpCode,
                    'data' => null,
                    'raw' => $response,
                    'headers' => $responseHeaders,
                    'error' => 'Invalid JSON response: ' . $error->getMessage(),
                ];
            }
        } else {
            $decoded = $response;
        }
        return [
            'code' => $httpCode,
            'raw' => $response,
            'data' => $decoded,
            'headers' => $responseHeaders,
            'error' => $httpCode >= 200 && $httpCode < 300 ? null : 'Gateway returned HTTP ' . $httpCode . '.',
        ];
    }

    public function refund(string $transactionId, ?float $amount = null): array {
        if (is_callable($this->customRefundLogic)) {
            return call_user_func($this->customRefundLogic, $this, $transactionId, $amount);
        }
        return $this->unsupported('refund');
    }

    public function webhook(string $payload, array $headers = [], array $context = []): array {
        if (is_callable($this->customWebhookLogic)) {
            return call_user_func($this->customWebhookLogic, $this, $payload, $headers, $context);
        }
        return $this->unsupported('webhook');
    }
}

/**
 * Unified courier contract. Courier payloads intentionally remain provider-native:
 * PHPA normalizes transport, authentication and the response envelope without
 * discarding carrier-specific fields required by official APIs.
 */
interface PHPACourierInterface {
    public function setKeys(string $key1, string $key2 = '', string $key3 = '', string $key4 = ''): self;
    public function configure(array $options): self;
    public function sandbox(bool $status = true): self;
    public function setTransport(?callable $callback): self;
    public function create(array $shipment): array;
    public function track(string $trackingId, array $options = []): array;
    public function rate(array $shipment): array;
    public function cancel(string $trackingId, array $options = []): array;
    public function label(string $trackingId, array $options = []): array;
    public function pickup(array $pickup): array;
    public function call(string $operation, array $payload = [], array $options = []): array;
    public function capabilities(): array;
}

final class PHPA_Courier implements PHPACourierInterface {
    private array $profile;
    private array $keys = ['', '', '', ''];
    private array $options = [];
    private bool $isSandbox = false;
    private $transport = null;
    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;

    public function __construct(array $profile) { $this->profile = $profile; }

    public function setKeys(string $key1, string $key2 = '', string $key3 = '', string $key4 = ''): self {
        $this->keys = [trim($key1), trim($key2), trim($key3), trim($key4)];
        $this->accessToken = null;
        $this->accessTokenExpiresAt = 0;
        return $this;
    }

    public function configure(array $options): self {
        $this->options = array_replace_recursive($this->options, $options);
        if (array_key_exists('sandbox', $options)) $this->isSandbox = (bool) $options['sandbox'];
        if (isset($options['token'])) {
            $this->accessToken = trim((string) $options['token']);
            $this->accessTokenExpiresAt = PHP_INT_MAX;
        }
        return $this;
    }

    public function sandbox(bool $status = true): self { $this->isSandbox = $status; return $this; }
    public function setTransport(?callable $callback): self { $this->transport = $callback; return $this; }
    public function profile(): array { return $this->profile; }
    public function name(): string { return (string) ($this->profile['name'] ?? 'courier'); }

    public function capabilities(): array {
        $operations = array_keys((array) ($this->profile['endpoints'] ?? []));
        $custom = (array) ($this->options['endpoints'] ?? []);
        $operations = array_values(array_unique([...$operations, ...array_keys($custom)]));
        $result = [];
        foreach (['create', 'track', 'rate', 'cancel', 'label', 'pickup', 'locations', 'webhook'] as $operation) {
            $result[$operation] = in_array($operation, $operations, true);
        }
        $result['sandbox'] = !empty($this->profile['sandbox_url']);
        $result['configurable'] = true;
        return $result;
    }

    public function create(array $shipment): array {
        if ($shipment === []) return $this->failure('invalid_shipment', 'Shipment data is required.', 'create');
        return $this->call('create', $shipment);
    }

    public function track(string $trackingId, array $options = []): array {
        $trackingId = trim($trackingId);
        if ($trackingId === '' || strlen($trackingId) > 160) {
            return $this->failure('invalid_tracking_id', 'A valid tracking ID is required.', 'track');
        }
        return $this->call('track', ['tracking_id' => $trackingId, ...$options], $options);
    }

    public function rate(array $shipment): array {
        if ($shipment === []) return $this->failure('invalid_shipment', 'Shipment data is required.', 'rate');
        return $this->call('rate', $shipment);
    }

    public function cancel(string $trackingId, array $options = []): array {
        $trackingId = trim($trackingId);
        if ($trackingId === '') return $this->failure('invalid_tracking_id', 'A tracking ID is required.', 'cancel');
        return $this->call('cancel', ['tracking_id' => $trackingId, ...$options], $options);
    }

    public function label(string $trackingId, array $options = []): array {
        $trackingId = trim($trackingId);
        if ($trackingId === '') return $this->failure('invalid_tracking_id', 'A tracking ID is required.', 'label');
        return $this->call('label', ['tracking_id' => $trackingId, ...$options], $options);
    }

    public function pickup(array $pickup): array {
        if ($pickup === []) return $this->failure('invalid_pickup', 'Pickup data is required.', 'pickup');
        return $this->call('pickup', $pickup);
    }

    public function call(string $operation, array $payload = [], array $options = []): array {
        $operation = strtolower(trim($operation));
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $operation)) {
            return $this->failure('invalid_operation', 'Courier operation is invalid.', $operation);
        }
        $endpoints = array_replace_recursive(
            (array) ($this->profile['endpoints'] ?? []),
            (array) ($this->options['endpoints'] ?? [])
        );
        $endpoint = $endpoints[$operation] ?? null;
        if (is_string($endpoint)) $endpoint = ['method' => 'POST', 'path' => $endpoint];
        if (!is_array($endpoint) || empty($endpoint['path'])) {
            return $this->failure(
                'unsupported_operation',
                ucfirst($operation) . ' requires an official merchant endpoint for ' . $this->name() . '.',
                $operation,
                ['documentation' => $this->profile['docs'] ?? null]
            );
        }

        $method = strtoupper((string) ($options['method'] ?? $endpoint['method'] ?? 'POST'));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $this->failure('invalid_method', 'Courier request method is invalid.', $operation);
        }
        $path = (string) ($options['path'] ?? $endpoint['path']);
        $profileName = strtolower($this->name());
        if ($operation === 'track' && $profileName === 'ecourier' && isset($payload['tracking_id'])) {
            $payload['ecr'] = $payload['tracking_id']; unset($payload['tracking_id']);
        } elseif ($operation === 'cancel' && $profileName === 'ecourier' && isset($payload['tracking_id'])) {
            $payload['tracking'] = $payload['tracking_id']; unset($payload['tracking_id']);
            $payload['comment'] = (string) ($payload['comment'] ?? 'Cancelled by merchant');
        } elseif ($operation === 'label' && $profileName === 'ecourier' && isset($payload['tracking_id'])) {
            $payload['tracking'] = $payload['tracking_id']; unset($payload['tracking_id']);
        } elseif ($operation === 'track' && $profileName === 'fedex' && isset($payload['tracking_id'])) {
            $trackingId = (string) $payload['tracking_id'];
            $payload = ['includeDetailedScans' => true, 'trackingInfo' => [[
                'trackingNumberInfo' => ['trackingNumber' => $trackingId],
            ]]];
        } elseif ($operation === 'track' && $profileName === 'australia post' && isset($payload['tracking_id'])) {
            $payload['tracking_ids'] = $payload['tracking_id']; unset($payload['tracking_id']);
        }
        foreach ($payload as $key => $value) {
            if (is_scalar($value) && str_contains($path, '{' . $key . '}')) {
                $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
                unset($payload[$key]);
            }
        }
        if (preg_match('/\{[a-z0-9_]+\}/i', $path)) {
            return $this->failure('missing_parameter', 'Courier endpoint parameters are incomplete.', $operation);
        }

        $base = rtrim((string) ($this->options['base_url'] ?? (
            $this->isSandbox && !empty($this->profile['sandbox_url'])
                ? $this->profile['sandbox_url']
                : ($this->profile['base_url'] ?? '')
        )), '/');
        if ($base === '') {
            return $this->failure(
                'configuration_error',
                $this->name() . ' uses a merchant/region-specific API. Set base_url and endpoints from your official contract.',
                $operation,
                ['documentation' => $this->profile['docs'] ?? null]
            );
        }
        $url = $base . '/' . ltrim($path, '/');
        $headers = $this->authorizationHeaders($operation);
        if (isset($headers['error'])) return $this->failure('configuration_error', $headers['error'], $operation);
        $headers = [...$headers, ...(array) ($this->options['headers'] ?? []), ...(array) ($options['headers'] ?? [])];

        $query = $method === 'GET' || (($endpoint['payload'] ?? '') === 'query');
        if ($query && $payload !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
            $payload = [];
        }
        $format = strtolower((string) ($options['format'] ?? $endpoint['format'] ?? 'json'));
        $response = $this->send($method, $url, $headers, $payload === [] && $method === 'GET' ? null : $payload, $format);
        return $this->normalize($operation, $response);
    }

    private function authorizationHeaders(string $operation): array {
        $auth = strtolower((string) ($this->options['auth'] ?? $this->profile['auth'] ?? 'none'));
        if ($auth === 'none') return array();
        if ($auth === 'bearer') {
            $token = $this->accessToken ?: $this->keys[0];
            return $token !== '' ? ['Authorization: Bearer ' . $token] : ['error' => 'Bearer token is missing.'];
        }
        if ($auth === 'basic') {
            return $this->keys[0] !== '' && $this->keys[1] !== ''
                ? ['Authorization: Basic ' . base64_encode($this->keys[0] . ':' . $this->keys[1])]
                : ['error' => 'API username and password are required.'];
        }
        if ($auth === 'headers') {
            $names = (array) ($this->profile['key_headers'] ?? []);
            $headers = [];
            foreach ($names as $index => $name) {
                if (($this->keys[$index] ?? '') === '') return ['error' => $name . ' credential is missing.'];
                $headers[] = $name . ': ' . $this->keys[$index];
            }
            return $headers;
        }
        if (in_array($auth, ['oauth_client', 'oauth_password'], true)) {
            $token = $this->token($auth);
            return $token !== null ? ['Authorization: Bearer ' . $token] : ['error' => 'Unable to obtain courier access token.'];
        }
        return ['error' => 'Unsupported courier authentication mode.'];
    }

    private function token(string $auth): ?string {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt - 30) return $this->accessToken;
        $tokenPath = (string) ($this->profile['token_path'] ?? $this->options['token_path'] ?? '');
        if ($tokenPath === '' || $this->keys[0] === '' || $this->keys[1] === '') return null;
        $base = rtrim((string) ($this->options['base_url'] ?? (
            $this->isSandbox && !empty($this->profile['sandbox_url']) ? $this->profile['sandbox_url'] : $this->profile['base_url']
        )), '/');
        $body = $auth === 'oauth_password'
            ? ['client_id' => $this->keys[0], 'client_secret' => $this->keys[1], 'username' => $this->keys[2], 'password' => $this->keys[3], 'grant_type' => 'password']
            : ['client_id' => $this->keys[0], 'client_secret' => $this->keys[1], 'grant_type' => 'client_credentials'];
        $tokenOptions = (array) ($this->profile['token_options'] ?? []);
        $headers = (array) ($tokenOptions['headers'] ?? []);
        if (!empty($tokenOptions['basic'])) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->keys[0] . ':' . $this->keys[1]);
            if (!empty($tokenOptions['omit_client_body'])) unset($body['client_id'], $body['client_secret']);
        }
        $response = $this->send('POST', $base . '/' . ltrim($tokenPath, '/'), $headers, $body, 'form');
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $token = trim((string) ($data['access_token'] ?? $data['token'] ?? ''));
        if ($token === '') return null;
        $this->accessToken = $token;
        $this->accessTokenExpiresAt = time() + max(60, (int) ($data['expires_in'] ?? 3600));
        return $token;
    }

    private function send(string $method, string $url, array $headers, mixed $data, string $format): array {
        if (is_callable($this->transport)) {
            $result = call_user_func($this->transport, $method, $url, $headers, $data, ['format' => $format], $this);
            return is_array($result) ? $result : ['code' => 0, 'data' => null, 'error' => 'Invalid custom transport response.'];
        }
        if (!function_exists('curl_init')) return ['code' => 0, 'data' => null, 'error' => 'PHP cURL extension is unavailable.'];
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return ['code' => 0, 'data' => null, 'error' => 'Insecure courier API URL refused.'];
        }
        $hasAccept = false; $hasType = false;
        foreach ($headers as $header) {
            $lower = strtolower((string) $header);
            $hasAccept = $hasAccept || str_starts_with($lower, 'accept:');
            $hasType = $hasType || str_starts_with($lower, 'content-type:');
        }
        if (!$hasAccept) $headers[] = 'Accept: application/json';
        $body = $data;
        if ($data !== null && $format === 'form') {
            if (!$hasType) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query((array) $data, '', '&', PHP_QUERY_RFC3986);
        } elseif ($data !== null && $format === 'xml') {
            if (!$hasType) $headers[] = 'Content-Type: text/xml; charset=utf-8';
            $body = is_string($data) ? $data : (string) ($data['xml'] ?? '');
        } elseif ($data !== null) {
            if (!$hasType) $headers[] = 'Content-Type: application/json';
            try { $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
            catch (\JsonException $error) { return ['code' => 0, 'data' => null, 'error' => $error->getMessage()]; }
        }
        $headers[] = 'User-Agent: MyStack-PHPA-Courier/1.0';
        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $responseHeaders[strtolower(trim($name))] = trim($value); }
                return $length;
            },
        ]);
        if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch); $errno = curl_errno($ch); curl_close($ch);
        if ($raw === false || $errno !== 0) return ['code' => $code, 'data' => null, 'raw' => $raw, 'headers' => $responseHeaders, 'error' => $error ?: 'Courier request failed.'];
        $decoded = $raw;
        if (preg_match('/^\s*[\[{]/', $raw)) {
            $json = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) $decoded = $json;
        } elseif (preg_match('/^\s*</', $raw) && function_exists('simplexml_load_string')) {
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            libxml_clear_errors(); libxml_use_internal_errors($previous);
            if ($xml !== false) {
                $json = json_encode($xml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $array = is_string($json) ? json_decode($json, true) : null;
                if (is_array($array)) $decoded = $array;
            }
        }
        return ['code' => $code, 'data' => $decoded, 'raw' => $raw, 'headers' => $responseHeaders, 'error' => $code >= 200 && $code < 300 ? null : 'Courier returned HTTP ' . $code . '.'];
    }

    private function normalize(string $operation, array $response): array {
        $code = (int) ($response['code'] ?? 0);
        $data = $response['data'] ?? null;
        $ok = $code >= 200 && $code < 300;
        if (is_array($data)) {
            if (array_key_exists('success', $data)) $ok = $ok && filter_var($data['success'], FILTER_VALIDATE_BOOL);
            if (isset($data['status']) && is_bool($data['status'])) $ok = $ok && $data['status'];
            if (isset($data['response_code']) && is_numeric($data['response_code'])) $ok = $ok && (int) $data['response_code'] < 400;
        }
        $tracking = $this->find($data, ['tracking_code', 'tracking_id', 'tracking_number', 'consignment_id', 'consignmentId', 'shipmentTrackingNumber', 'shipment_number', 'ecr', 'waybill_number', 'airWaybillNumber']);
        $status = $this->find($data, ['delivery_status', 'shipment_status', 'current_status', 'status', 'status_name', 'latestStatus']);
        $label = $this->find($data, ['label_url', 'labelUrl', 'label', 'print_url', 'documents.0.url']);
        $message = $response['error'] ?? $this->find($data, ['message', 'error_description', 'error.message', 'errors.0.message', 'errors.0']);
        return [
            'success' => $ok,
            'courier' => strtolower($this->name()),
            'operation' => $operation,
            'code' => $ok ? null : ($code > 0 ? 'http_' . $code : 'network_error'),
            'message' => $message === null ? ($ok ? null : 'Courier request failed.') : (is_scalar($message) ? (string) $message : json_encode($message)),
            'tracking_id' => is_scalar($tracking) ? (string) $tracking : null,
            'status' => is_scalar($status) ? (string) $status : null,
            'label_url' => is_string($label) && filter_var($label, FILTER_VALIDATE_URL) ? $label : null,
            'http_code' => $code,
            'raw' => $data,
        ];
    }

    private function find(mixed $data, array $paths): mixed {
        if (!is_array($data)) return null;
        foreach ($paths as $path) {
            $value = $data;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) { $value = null; break; }
                $value = $value[$segment];
            }
            if ($value !== null && $value !== '') return $value;
        }
        foreach ($data as $value) {
            if (is_array($value) && ($found = $this->find($value, $paths)) !== null) return $found;
        }
        return null;
    }

    private function failure(string $code, string $message, string $operation, array $extra = []): array {
        return ['success' => false, 'courier' => strtolower($this->name()), 'operation' => $operation, 'code' => $code, 'message' => $message, 'tracking_id' => null, 'status' => null, 'label_url' => null, 'http_code' => 0, 'raw' => null, ...$extra];
    }
}

class PHPA {
    private static array $gateways = [];
    private static array $couriers = [];
    private const COURIERS = [
        // Bangladesh: public merchant APIs are wired directly; contract/private
        // APIs remain first-class configurable profiles instead of guessed URLs.
        'pathao' => [
            'name' => 'Pathao', 'region' => 'BD', 'auth' => 'oauth_password',
            'base_url' => 'https://api-hermes.pathao.com',
            'sandbox_url' => 'https://courier-api-sandbox.pathao.com',
            'token_path' => '/aladdin/api/v1/issue-token',
            'docs' => 'https://merchant.pathao.com/courier/developer-api',
            'endpoints' => [
                'create' => ['method' => 'POST', 'path' => '/aladdin/api/v1/orders'],
                'track' => ['method' => 'GET', 'path' => '/aladdin/api/v1/orders/{tracking_id}/info'],
                'rate' => ['method' => 'POST', 'path' => '/aladdin/api/v1/merchant/price-plan'],
                'locations' => ['method' => 'GET', 'path' => '/aladdin/api/v1/countries/1/city-list'],
            ],
        ],
        'steadfast' => [
            'name' => 'Steadfast', 'region' => 'BD', 'auth' => 'headers',
            'key_headers' => ['Api-Key', 'Secret-Key'], 'base_url' => 'https://portal.packzy.com/api/v1',
            'docs' => 'https://docs.steadfast.com.bd/',
            'endpoints' => [
                'create' => ['method' => 'POST', 'path' => '/create_order'],
                'track' => ['method' => 'GET', 'path' => '/status_by_trackingcode/{tracking_id}'],
                'balance' => ['method' => 'GET', 'path' => '/get_balance'],
                'bulk' => ['method' => 'POST', 'path' => '/create_order/bulk-order'],
            ],
        ],
        'redx' => [
            'name' => 'RedX', 'region' => 'BD', 'auth' => 'bearer',
            'docs' => 'https://redx.com.bd/', 'endpoints' => [],
        ],
        'ecourier' => [
            'name' => 'eCourier', 'region' => 'BD', 'auth' => 'headers',
            'key_headers' => ['API-KEY', 'API-SECRET', 'USER-ID'],
            'base_url' => 'https://backoffice.ecourier.com.bd/api',
            'sandbox_url' => 'https://staging.ecourier.com.bd/api',
            'docs' => 'https://ecourier.com.bd/wp-content/uploads/eCourier_Merchant_API_Document_General_v5.2.pdf',
            'endpoints' => [
                'create' => ['method' => 'POST', 'path' => '/order-place'],
                'track' => ['method' => 'POST', 'path' => '/track'],
                'cancel' => ['method' => 'POST', 'path' => '/cancel-order'],
                'label' => ['method' => 'POST', 'path' => '/label-print'],
                'locations' => ['method' => 'POST', 'path' => '/city-list'],
            ],
        ],
        'paperfly' => ['name' => 'Paperfly', 'region' => 'BD', 'auth' => 'headers', 'key_headers' => ['API-Key'], 'docs' => 'https://paperfly.com.bd/', 'endpoints' => []],
        'deliverytiger' => ['name' => 'Delivery Tiger', 'region' => 'BD', 'auth' => 'bearer', 'docs' => 'https://deliverytiger.com.bd/', 'endpoints' => []],
        'carrybee' => ['name' => 'CarryBee', 'region' => 'BD', 'auth' => 'bearer', 'docs' => 'https://carrybee.com/', 'endpoints' => []],
        'sundarban' => ['name' => 'Sundarban Courier', 'region' => 'BD', 'auth' => 'headers', 'key_headers' => ['API-Key'], 'docs' => 'https://www.sundarbancourierltd.com/', 'endpoints' => []],
        'saparibahan' => ['name' => 'S.A. Paribahan', 'region' => 'BD', 'auth' => 'headers', 'key_headers' => ['API-Key'], 'docs' => 'https://saparibahan.com/', 'endpoints' => []],
        'ajr' => ['name' => 'AJR Courier', 'region' => 'BD', 'auth' => 'headers', 'key_headers' => ['API-Key'], 'docs' => 'https://ajrcourier.com/', 'endpoints' => []],

        // International carriers. Payloads are the official provider-native
        // request objects; PHPA handles auth, transport, environment and output.
        'dhl' => [
            'name' => 'DHL', 'region' => 'GLOBAL', 'auth' => 'basic',
            'base_url' => 'https://express.api.dhl.com/mydhlapi',
            'sandbox_url' => 'https://express.api.dhl.com/mydhlapi/test',
            'docs' => 'https://developer.dhl.com/api-reference/mydhl-api-dhl-express',
            'endpoints' => [
                'create' => ['method' => 'POST', 'path' => '/shipments'],
                'track' => ['method' => 'GET', 'path' => '/shipments/{tracking_id}/tracking'],
                'rate' => ['method' => 'POST', 'path' => '/rates'],
                'pickup' => ['method' => 'POST', 'path' => '/pickups'],
            ],
        ],
        'fedex' => [
            'name' => 'FedEx', 'region' => 'GLOBAL', 'auth' => 'oauth_client',
            'base_url' => 'https://apis.fedex.com', 'sandbox_url' => 'https://apis-sandbox.fedex.com',
            'token_path' => '/oauth/token', 'docs' => 'https://developer.fedex.com/api/en-us/catalog.html',
            'endpoints' => [
                'create' => ['method' => 'POST', 'path' => '/ship/v1/shipments'],
                'track' => ['method' => 'POST', 'path' => '/track/v1/trackingnumbers'],
                'rate' => ['method' => 'POST', 'path' => '/rate/v1/rates/quotes'],
                'cancel' => ['method' => 'PUT', 'path' => '/ship/v1/shipments/cancel'],
            ],
        ],
        'ups' => [
            'name' => 'UPS', 'region' => 'GLOBAL', 'auth' => 'oauth_client',
            'base_url' => 'https://onlinetools.ups.com', 'sandbox_url' => 'https://wwwcie.ups.com',
            'token_path' => '/security/v1/oauth/token',
            'token_options' => ['basic' => true, 'omit_client_body' => true],
            'docs' => 'https://developer.ups.com/',
            'endpoints' => [
                'create' => ['method' => 'POST', 'path' => '/api/shipments/v2409/ship'],
                'track' => ['method' => 'GET', 'path' => '/api/track/v1/details/{tracking_id}'],
                'rate' => ['method' => 'POST', 'path' => '/api/rating/v2409/Rate'],
                'cancel' => ['method' => 'DELETE', 'path' => '/api/shipments/v2409/void/cancel/{tracking_id}'],
            ],
        ],
        'usps' => [
            'name' => 'USPS', 'region' => 'US', 'auth' => 'oauth_client',
            'base_url' => 'https://apis.usps.com', 'sandbox_url' => 'https://apis-tem.usps.com',
            'token_path' => '/oauth2/v3/token', 'docs' => 'https://developers.usps.com/',
            'endpoints' => [
                'create' => ['method' => 'POST', 'path' => '/labels/v3/label'],
                'track' => ['method' => 'GET', 'path' => '/tracking/v3/tracking/{tracking_id}'],
                'rate' => ['method' => 'POST', 'path' => '/prices/v3/base-rates/search'],
                'cancel' => ['method' => 'DELETE', 'path' => '/labels/v3/label/{tracking_id}'],
            ],
        ],
        'royalmail' => [
            'name' => 'Royal Mail', 'region' => 'GB', 'auth' => 'headers',
            'key_headers' => ['X-IBM-Client-Id', 'X-IBM-Client-Secret'],
            'base_url' => 'https://api.royalmail.net/shipping/v2',
            'sandbox_url' => 'https://pp.api.royalmail.net/shipping/v2',
            'docs' => 'https://developer.royalmail.net/',
            'endpoints' => [
                'create' => ['method' => 'POST', 'path' => '/shipments'],
                'cancel' => ['method' => 'DELETE', 'path' => '/{tracking_id}'],
                'label' => ['method' => 'PUT', 'path' => '/{tracking_id}/label'],
            ],
        ],
        'canadapost' => [
            'name' => 'Canada Post', 'region' => 'CA', 'auth' => 'basic',
            'base_url' => 'https://soa-gw.canadapost.ca', 'sandbox_url' => 'https://ct.soa-gw.canadapost.ca',
            'docs' => 'https://www.canadapost-postescanada.ca/cpc/en/commercial/integrate-apis.page',
            'endpoints' => [
                'track' => ['method' => 'GET', 'path' => '/vis/track/pin/{tracking_id}/detail'],
            ],
        ],
        'australiapost' => [
            'name' => 'Australia Post', 'region' => 'AU', 'auth' => 'basic',
            'base_url' => 'https://digitalapi.auspost.com.au/shipping/v1',
            'sandbox_url' => 'https://digitalapi.auspost.com.au/test/shipping/v1',
            'docs' => 'https://developers.auspost.com.au/apis/shipping-and-tracking/reference',
            'endpoints' => [
                'create' => ['method' => 'POST', 'path' => '/shipments'],
                'track' => ['method' => 'GET', 'path' => '/track', 'payload' => 'query'],
                'label' => ['method' => 'POST', 'path' => '/labels'],
            ],
        ],
        'aramex' => [
            'name' => 'Aramex', 'region' => 'GLOBAL', 'auth' => 'none',
            'base_url' => 'https://ws.aramex.net',
            'docs' => 'https://www.aramex.com/ws/en/developers-solution-center/aramex-apis',
            'endpoints' => [
                'create' => ['method' => 'POST', 'path' => '/ShippingAPI.V2/Shipping/Service_1_0.svc', 'format' => 'xml'],
                'track' => ['method' => 'POST', 'path' => '/ShippingAPI.V2/Tracking/Service_1_0.svc', 'format' => 'xml'],
                'rate' => ['method' => 'POST', 'path' => '/ShippingAPI.V2/RateCalculator/Service_1_0.svc', 'format' => 'xml'],
            ],
        ],
        'dpd' => ['name' => 'DPD', 'region' => 'EU', 'auth' => 'bearer', 'docs' => 'https://developer.dpd.com/', 'endpoints' => []],
        'gls' => ['name' => 'GLS', 'region' => 'EU', 'auth' => 'headers', 'key_headers' => ['X-API-Key'], 'docs' => 'https://api-portal.gls.nl/', 'endpoints' => []],
    ];
    private const CAPABILITIES = [
        'stripe' =>       ['charge', 'verify', 'refund', 'webhook', 'sandbox'],
        'paypal' =>       ['charge', 'verify', 'refund', 'sandbox'],
        'razorpay' =>     ['charge', 'verify', 'refund', 'webhook', 'sandbox'],
        'authorize' =>    ['charge', 'verify', 'sandbox'],
        'twocheckout' =>  ['charge', 'sandbox'],
        'payoneer' =>     ['charge', 'sandbox'],
        'square' =>       ['charge', 'verify', 'refund', 'sandbox'],
        'adyen' =>        ['charge', 'refund', 'webhook', 'sandbox'],
        'mollie' =>       ['charge', 'verify', 'refund', 'webhook', 'sandbox'],
        'coinbase' =>     ['charge', 'verify', 'webhook', 'sandbox'],
        'binance' =>      ['charge', 'verify', 'sandbox'],
        'coinpayments' => ['charge', 'verify', 'sandbox'],
        'bitpay' =>       ['charge', 'verify', 'sandbox'],
        'nowpayments' =>  ['charge', 'verify', 'webhook', 'sandbox'],
        'cryptocom' =>    ['charge', 'sandbox'],
        'coingate' =>     ['charge', 'verify', 'sandbox'],
        'btcpay' =>       ['charge', 'verify', 'refund', 'webhook', 'sandbox'],
        'bkash' =>        ['charge', 'verify', 'sandbox'],
        'nagad' =>        ['charge', 'verify', 'sandbox'],
        'sslcommerz' =>   ['charge', 'verify', 'refund', 'webhook', 'sandbox'],
        'aamarpay' =>     ['charge', 'verify', 'sandbox'],
        'surjopay' =>     ['charge', 'verify', 'sandbox'],
        'portwallet' =>   ['charge', 'sandbox'],
    ];
    public static function courier(string $name): PHPACourierInterface {
        $name = self::normalizeCourierName($name);
        if (isset(self::$couriers[$name])) {
            $factory = self::$couriers[$name];
            $courier = $factory();
            if (!$courier instanceof PHPACourierInterface) {
                throw new RuntimeException("PHPA: Courier factory '$name' returned an invalid adapter.");
            }
            return $courier;
        }
        if (!isset(self::COURIERS[$name])) throw new InvalidArgumentException("PHPA: Courier '$name' is not registered.");
        return new PHPA_Courier(self::COURIERS[$name]);
    }
    public static function extendCourier(string $name, callable $factory): void {
        $name = self::normalizeCourierName($name);
        if ($name === '') throw new InvalidArgumentException('PHPA: Courier name is invalid.');
        self::$couriers[$name] = $factory;
    }
    public static function courierAvailable(?string $region = null): array {
        $region = $region === null ? null : strtoupper(trim($region));
        $result = [];
        foreach (self::COURIERS as $slug => $profile) {
            if ($region !== null && strtoupper((string) ($profile['region'] ?? '')) !== $region) continue;
            $result[$slug] = [
                'name' => $profile['name'],
                'region' => $profile['region'],
                'capabilities' => (new PHPA_Courier($profile))->capabilities(),
                'documentation' => $profile['docs'] ?? null,
                'requires_endpoint_configuration' => empty($profile['base_url']),
            ];
        }
        foreach (self::$couriers as $slug => $_) {
            if (!isset($result[$slug]) && $region === null) $result[$slug] = ['name' => $slug, 'region' => 'CUSTOM', 'capabilities' => [], 'documentation' => null, 'requires_endpoint_configuration' => false];
        }
        return $result;
    }
    public static function courierProfile(string $name): array {
        $name = self::normalizeCourierName($name);
        if (!isset(self::COURIERS[$name])) return array();
        $profile = self::COURIERS[$name];
        unset($profile['token_options']);
        return $profile;
    }
    private static function normalizeCourierName(string $name): string {
        $name = strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($name)) ?? '');
        return match ($name) {
            'sa', 'saparibahanlimited' => 'saparibahan',
            'auspost', 'australiapost' => 'australiapost',
            'canadapost', 'postcanada' => 'canadapost',
            'royalmailgroup' => 'royalmail',
            default => $name,
        };
    }
    public static function extend(string $name, string $className): void {
        if (class_exists($className) && is_subclass_of($className, PHPAGatewayInterface::class)) {
            self::$gateways[strtolower($name)] = $className;
        } else { throw new Exception("PHPA: Gateway class '$className' must implement PHPAGatewayInterface."); }
    }
    public static function available(): array {
        $classes = get_declared_classes();
        $gateways = [];
        foreach ($classes as $class) {
            if ($class !== PHPA_BaseGateway::class && str_starts_with($class, 'PHPA_') && is_subclass_of($class, PHPAGatewayInterface::class)) {
                $gateways[] = strtolower(substr($class, 5));
            }
        }
        return array_values(array_unique([...array_keys(self::$gateways), ...$gateways]));
    }
    public static function gatewayCapabilities(string $name): array {
        $enabled = self::CAPABILITIES[strtolower($name)] ?? [];
        return [
            'charge' => in_array('charge', $enabled, true),
            'verify' => in_array('verify', $enabled, true),
            'refund' => in_array('refund', $enabled, true),
            'webhook' => in_array('webhook', $enabled, true),
            'sandbox' => in_array('sandbox', $enabled, true),
        ];
    }
    public static function __callStatic($name, $arguments): PHPAGatewayInterface {
        $name = strtolower($name);
        if (!isset(self::$gateways[$name])) {
            $prebuiltClass = 'PHPA_' . ucfirst($name);
            if (class_exists($prebuiltClass)) { self::$gateways[$name] = $prebuiltClass; } 
            else { throw new Exception("PHPA: Payment Gateway '$name' is not registered."); }
        }
        $className = self::$gateways[$name]; return new $className();
    }
}

// ==========================================
// 🌍 Top 10 International Gateways
// ==========================================
class PHPA_Stripe extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(1)) return $error;
        $url = "https://api.stripe.com/v1/payment_intents";
        $data = [
            'amount' => $this->minor($amount, $currency),
            'currency' => strtolower($currency),
            'metadata' => ['order_id' => $orderId],
            'automatic_payment_methods' => ['enabled' => 'true'],
        ];
        if (!empty($options['customer'])) $data['customer'] = $options['customer'];
        if (!empty($options['description'])) $data['description'] = $options['description'];
        $res = $this->request('POST', $url, [
            "Authorization: Bearer {$this->key1}",
            "Content-Type: application/x-www-form-urlencoded",
            'Idempotency-Key: ' . ($options['idempotency_key']
                ?? $this->idempotencyKey('charge', $orderId, [$amount, strtoupper($currency)])),
        ], http_build_query($data));
        if ($res['code'] !== 200 || empty($res['data']['id'])) return $this->responseFailure($res, 'charge');
        return $this->success([
            'transaction_id' => $res['data']['id'],
            'client_secret' => $res['data']['client_secret'] ?? null,
            'checkout_url' => null,
            'status' => $res['data']['status'] ?? null,
            'raw' => $res['data'],
        ]);
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(1)) return $error;
        $res = $this->request('GET', "https://api.stripe.com/v1/payment_intents/" . rawurlencode($transactionId), ["Authorization: Bearer {$this->key1}"]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        $data = (array) $res['data'];
        $matches = $this->paymentMatches(
            $data['metadata']['order_id'] ?? null,
            null,
            $data['currency'] ?? null
        );
        return ($data['status'] ?? '') === 'succeeded' && $matches
            ? $this->success(['transaction_id' => $data['id'] ?? $transactionId, 'status' => $data['status'], 'raw' => $data])
            : $this->failure('payment_not_completed', $matches ? 'Payment is not completed.' : 'Payment does not match the expected order.', ['status' => $data['status'] ?? null, 'raw' => $data]);
    }
    public function refund(string $transactionId, ?float $amount = null): array {
        if (is_callable($this->customRefundLogic)) return parent::refund($transactionId, $amount);
        if ($error = $this->requireKeys(1)) return $error;
        $data = ['payment_intent' => $transactionId];
        if ($amount !== null) {
            if ($amount <= 0) return $this->failure('invalid_amount', 'Refund amount must be greater than zero.');
            $data['amount'] = $this->minor($amount, $this->expectedPayment['currency'] ?? 'USD');
        }
        $res = $this->request('POST', 'https://api.stripe.com/v1/refunds', [
            "Authorization: Bearer {$this->key1}",
            'Content-Type: application/x-www-form-urlencoded',
            'Idempotency-Key: ' . $this->idempotencyKey('refund', $transactionId, [$amount]),
        ], http_build_query($data));
        if ($res['code'] !== 200 || empty($res['data']['id'])) return $this->responseFailure($res, 'refund');
        return $this->success(['refund_id' => $res['data']['id'], 'status' => $res['data']['status'] ?? null, 'raw' => $res['data']]);
    }
    public function webhook(string $payload, array $headers = [], array $context = []): array {
        if (is_callable($this->customWebhookLogic)) return parent::webhook($payload, $headers, $context);
        $secret = $context['secret'] ?? $this->key3;
        $signatureHeader = $this->header($headers, 'Stripe-Signature');
        if ($secret === '' || $signatureHeader === null) return $this->failure('invalid_webhook', 'Stripe webhook secret or signature is missing.');
        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) $parts[$key][] = $value;
        }
        $timestamp = (int) ($parts['t'][0] ?? 0);
        if ($timestamp <= 0 || abs(time() - $timestamp) > $this->webhookTolerance) {
            return $this->failure('expired_webhook', 'Stripe webhook timestamp is outside the allowed tolerance.');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($parts['v1'] ?? [] as $signature) $valid = $valid || hash_equals($expected, $signature);
        if (!$valid) return $this->failure('invalid_signature', 'Stripe webhook signature is invalid.');
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return $this->failure('invalid_payload', $error->getMessage());
        }
        if (!$this->acceptWebhookEvent($event['id'] ?? null)) return $this->success(['duplicate' => true, 'event_id' => $event['id'] ?? null]);
        return $this->success(['event_id' => $event['id'] ?? null, 'event' => $event['type'] ?? null, 'data' => $event['data']['object'] ?? null]);
    }
    public function capabilities(): array {
        return ['charge' => true, 'verify' => true, 'refund' => true, 'webhook' => true, 'sandbox' => true];
    }
}

class PHPA_Paypal extends PHPA_BaseGateway {
    private function getToken(): ?string {
        if ($this->key1 === '' || $this->key2 === '') return null;
        $url = $this->isSandbox ? "https://api-m.sandbox.paypal.com/v1/oauth2/token" : "https://api-m.paypal.com/v1/oauth2/token";
        $auth = base64_encode("{$this->key1}:{$this->key2}");
        $res = $this->request('POST', $url, ["Authorization: Basic $auth", "Content-Type: application/x-www-form-urlencoded"], "grant_type=client_credentials");
        return $res['data']['access_token'] ?? null;
    }
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(2)) return $error;
        $token = $this->getToken();
        if ($token === null) return $this->failure('authentication_failed', 'PayPal access token could not be obtained.');
        $url = $this->isSandbox ? "https://api-m.sandbox.paypal.com/v2/checkout/orders" : "https://api-m.paypal.com/v2/checkout/orders";
        $data = ['intent' => 'CAPTURE', 'purchase_units' => [['reference_id' => $orderId, 'custom_id' => $orderId, 'amount' => ['currency_code' => strtoupper($currency), 'value' => $this->decimal($amount)]]]];
        if (!empty($options['return_url']) && !empty($options['cancel_url'])) {
            $data['payment_source']['paypal']['experience_context'] = [
                'return_url' => $options['return_url'],
                'cancel_url' => $options['cancel_url'],
                'user_action' => 'PAY_NOW',
            ];
        }
        $res = $this->request('POST', $url, [
            "Authorization: Bearer $token",
            "Content-Type: application/json",
            'PayPal-Request-Id: ' . ($options['idempotency_key'] ?? $this->idempotencyKey('charge', $orderId, [$amount, strtoupper($currency)])),
        ], $data);
        if ($res['code'] !== 201 || empty($res['data']['id'])) return $this->responseFailure($res, 'charge');
        $link = null;
        if(isset($res['data']['links'])) foreach($res['data']['links'] as $l) if($l['rel'] == 'approve') $link = $l['href'];
        return $this->success(['transaction_id' => $res['data']['id'], 'checkout_url' => $link, 'status' => $res['data']['status'] ?? null, 'raw' => $res['data']]);
    }
    public function capture(string $orderId): array {
        if ($error = $this->requireKeys(2)) return $error;
        $token = $this->getToken();
        if ($token === null) return $this->failure('authentication_failed', 'PayPal access token could not be obtained.');
        $base = $this->isSandbox ? "https://api-m.sandbox.paypal.com" : "https://api-m.paypal.com";
        $res = $this->request('POST', $base . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
            'PayPal-Request-Id: ' . $this->idempotencyKey('capture', $orderId),
        ], '{}');
        if (!in_array($res['code'], [200, 201], true)) return $this->responseFailure($res, 'capture');
        $capture = $res['data']['purchase_units'][0]['payments']['captures'][0] ?? [];
        return ($res['data']['status'] ?? '') === 'COMPLETED'
            ? $this->success(['transaction_id' => $res['data']['id'] ?? $orderId, 'capture_id' => $capture['id'] ?? null, 'status' => $res['data']['status'], 'raw' => $res['data']])
            : $this->failure('payment_not_completed', 'PayPal order capture is not completed.', ['raw' => $res['data']]);
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(2)) return $error;
        $token = $this->getToken();
        if ($token === null) return $this->failure('authentication_failed', 'PayPal access token could not be obtained.');
        $base = $this->isSandbox ? "https://api-m.sandbox.paypal.com" : "https://api-m.paypal.com";
        $url = $base . '/v2/checkout/orders/' . rawurlencode($transactionId);
        $res = $this->request('GET', $url, ["Authorization: Bearer $token", "Content-Type: application/json"]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        $unit = $res['data']['purchase_units'][0] ?? [];
        $matches = $this->paymentMatches(
            $unit['reference_id'] ?? $unit['custom_id'] ?? null,
            $unit['amount']['value'] ?? null,
            $unit['amount']['currency_code'] ?? null
        );
        return ($res['data']['status'] ?? '') === 'COMPLETED' && $matches
            ? $this->success(['transaction_id' => $res['data']['id'] ?? $transactionId, 'status' => $res['data']['status'], 'raw' => $res['data']])
            : $this->failure('payment_not_completed', $matches ? 'PayPal order is not completed.' : 'PayPal order does not match the expected payment.', ['raw' => $res['data']]);
    }
    public function refund(string $transactionId, ?float $amount = null): array {
        if (is_callable($this->customRefundLogic)) return parent::refund($transactionId, $amount);
        if ($error = $this->requireKeys(2)) return $error;
        $token = $this->getToken();
        if ($token === null) return $this->failure('authentication_failed', 'PayPal access token could not be obtained.');
        $base = $this->isSandbox ? "https://api-m.sandbox.paypal.com" : "https://api-m.paypal.com";
        $data = '{}';
        if ($amount !== null) {
            if ($amount <= 0) return $this->failure('invalid_amount', 'Refund amount must be greater than zero.');
            $data = ['amount' => ['value' => $this->decimal($amount), 'currency_code' => $this->expectedPayment['currency'] ?? 'USD']];
        }
        $res = $this->request('POST', $base . '/v2/payments/captures/' . rawurlencode($transactionId) . '/refund', [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
            'PayPal-Request-Id: ' . $this->idempotencyKey('refund', $transactionId, [$amount]),
        ], $data);
        if (!in_array($res['code'], [200, 201], true) || empty($res['data']['id'])) return $this->responseFailure($res, 'refund');
        return $this->success(['refund_id' => $res['data']['id'], 'status' => $res['data']['status'] ?? null, 'raw' => $res['data']]);
    }
}

class PHPA_Razorpay extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(2)) return $error;
        $auth = base64_encode("{$this->key1}:{$this->key2}");
        $res = $this->request('POST', 'https://api.razorpay.com/v1/orders', ["Authorization: Basic $auth", "Content-Type: application/json"], ['amount' => $this->minor($amount, $currency), 'currency' => strtoupper($currency), 'receipt' => $orderId, 'notes' => ['order_id' => $orderId]]);
        if ($res['code'] !== 200 || empty($res['data']['id'])) return $this->responseFailure($res, 'charge');
        return $this->success(['transaction_id' => $res['data']['id'], 'status' => $res['data']['status'] ?? null, 'raw' => $res['data']]);
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(2)) return $error;
        $auth = base64_encode("{$this->key1}:{$this->key2}");
        $res = $this->request('GET', "https://api.razorpay.com/v1/orders/" . rawurlencode($transactionId) . "/payments", ["Authorization: Basic $auth"]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        foreach ((array) ($res['data']['items'] ?? []) as $item) {
            if (($item['status'] ?? '') === 'captured') {
                return $this->success(['transaction_id' => $item['id'] ?? null, 'order_id' => $transactionId, 'status' => 'captured', 'raw' => $item]);
            }
        }
        return $this->failure('payment_not_completed', 'No captured Razorpay payment was found for this order.', ['raw' => $res['data']]);
    }
    public function verifySignature(string $orderId, string $paymentId, string $signature): bool {
        if ($this->key2 === '' || $signature === '') return false;
        return hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $this->key2), $signature);
    }
    public function refund(string $transactionId, ?float $amount = null): array {
        if (is_callable($this->customRefundLogic)) return parent::refund($transactionId, $amount);
        if ($error = $this->requireKeys(2)) return $error;
        $auth = base64_encode("{$this->key1}:{$this->key2}");
        $data = [];
        if ($amount !== null) {
            if ($amount <= 0) return $this->failure('invalid_amount', 'Refund amount must be greater than zero.');
            $data['amount'] = $this->minor($amount, $this->expectedPayment['currency'] ?? 'INR');
        }
        $res = $this->request('POST', 'https://api.razorpay.com/v1/payments/' . rawurlencode($transactionId) . '/refund', [
            "Authorization: Basic $auth",
            'Content-Type: application/json',
            'X-Razorpay-Idempotency-Key: ' . $this->idempotencyKey('refund', $transactionId, [$amount]),
        ], $data);
        if ($res['code'] !== 200 || empty($res['data']['id'])) return $this->responseFailure($res, 'refund');
        return $this->success(['refund_id' => $res['data']['id'], 'status' => $res['data']['status'] ?? null, 'raw' => $res['data']]);
    }
    public function webhook(string $payload, array $headers = [], array $context = []): array {
        if (is_callable($this->customWebhookLogic)) return parent::webhook($payload, $headers, $context);
        $secret = $context['secret'] ?? $this->key3;
        $signature = $this->header($headers, 'X-Razorpay-Signature');
        if ($secret === '' || $signature === null) return $this->failure('invalid_webhook', 'Razorpay webhook secret or signature is missing.');
        if (!hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) return $this->failure('invalid_signature', 'Razorpay webhook signature is invalid.');
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return $this->failure('invalid_payload', $error->getMessage());
        }
        $eventId = $this->header($headers, 'X-Razorpay-Event-Id');
        if (!$this->acceptWebhookEvent($eventId)) return $this->success(['duplicate' => true, 'event_id' => $eventId]);
        return $this->success(['event_id' => $eventId, 'event' => $event['event'] ?? null, 'data' => $event['payload'] ?? null]);
    }
}

class PHPA_Braintree extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        return $this->unsupported('charge');
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); } return $this->unsupported('verify'); }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Authorize extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(2)) return $error;
        $url = $this->isSandbox ? "https://apitest.authorize.net/xml/v1/request.api" : "https://api.authorize.net/xml/v1/request.api";
        $data = ['createTransactionRequest' => ['merchantAuthentication' => ['name' => $this->key1, 'transactionKey' => $this->key2], 'transactionRequest' => ['transactionType' => 'authCaptureTransaction', 'amount' => $amount, 'order' => ['invoiceNumber' => $orderId]]]];
        $res = $this->request('POST', $url, ["Content-Type: application/json"], $data);
        return ['success' => ($res['data']['messages']['resultCode'] ?? '') === 'Ok', 'transaction_id' => $res['data']['transactionResponse']['transId'] ?? null, 'raw' => $res['data']];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(2)) return $error;
        $url = $this->isSandbox ? "https://apitest.authorize.net/xml/v1/request.api" : "https://api.authorize.net/xml/v1/request.api";
        $res = $this->request('POST', $url, ['Content-Type: application/json'], [
            'getTransactionDetailsRequest' => [
                'merchantAuthentication' => ['name' => $this->key1, 'transactionKey' => $this->key2],
                'transId' => $transactionId,
            ],
        ]);
        if ($res['code'] !== 200 || ($res['data']['messages']['resultCode'] ?? '') !== 'Ok') return $this->responseFailure($res, 'verify');
        $transaction = (array) ($res['data']['transaction'] ?? []);
        $status = (string) ($transaction['transactionStatus'] ?? '');
        return in_array($status, ['settledSuccessfully', 'capturedPendingSettlement'], true)
            ? $this->success(['transaction_id' => $transaction['transId'] ?? $transactionId, 'status' => $status, 'raw' => $transaction])
            : $this->failure('payment_not_completed', 'Authorize.Net transaction is not captured or settled.', ['status' => $status, 'raw' => $transaction]);
    }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Twocheckout extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(2)) return $error;
        $auth = base64_encode("{$this->key1}:{$this->key2}");
        $res = $this->request('POST', 'https://api.2checkout.com/rest/6.0/orders/', ["Accept: application/json", "Authorization: Basic $auth"], ['Amount' => $amount, 'Currency' => $currency, 'ExternalReference' => $orderId]);
        return ['success' => $res['code'] == 200, 'transaction_id' => $res['data']['RefNo'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); } return $this->unsupported('verify'); }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Payoneer extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(2)) return $error;
        $auth = base64_encode("{$this->key1}:{$this->key2}");
        $res = $this->request('POST', 'https://api.payoneer.com/v2/programs/charges', ["Authorization: Basic $auth"], ['amount' => $amount, 'currency' => $currency, 'client_reference_id' => $orderId]);
        return ['success' => $res['code'] == 200, 'transaction_id' => $res['data']['charge_id'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); } return $this->unsupported('verify'); }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Square extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(1)) return $error;
        if (empty($options['source_id'])) return $this->failure('missing_payment_source', 'Square source_id is required.');
        $url = $this->isSandbox ? "https://connect.squareupsandbox.com/v2/payments" : "https://connect.squareup.com/v2/payments";
        $res = $this->request('POST', $url, ["Authorization: Bearer {$this->key1}", "Content-Type: application/json", "Square-Version: 2026-05-20"], [
            'source_id' => $options['source_id'],
            'idempotency_key' => $options['idempotency_key'] ?? $this->idempotencyKey('charge', $orderId, [$amount, strtoupper($currency)]),
            'amount_money' => ['amount' => $this->minor($amount, $currency), 'currency' => strtoupper($currency)],
            'reference_id' => $orderId,
            'autocomplete' => $options['autocomplete'] ?? true,
        ]);
        if ($res['code'] !== 200 || empty($res['data']['payment']['id'])) return $this->responseFailure($res, 'charge');
        return $this->success(['transaction_id' => $res['data']['payment']['id'], 'status' => $res['data']['payment']['status'] ?? null, 'raw' => $res['data']['payment']]);
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(1)) return $error;
        $url = $this->isSandbox ? "https://connect.squareupsandbox.com/v2/payments/" : "https://connect.squareup.com/v2/payments/";
        $res = $this->request('GET', $url . rawurlencode($transactionId), ["Authorization: Bearer {$this->key1}", "Square-Version: 2026-05-20"]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        $payment = (array) ($res['data']['payment'] ?? []);
        return ($payment['status'] ?? '') === 'COMPLETED'
            ? $this->success(['transaction_id' => $payment['id'] ?? $transactionId, 'status' => $payment['status'], 'raw' => $payment])
            : $this->failure('payment_not_completed', 'Square payment is not completed.', ['status' => $payment['status'] ?? null, 'raw' => $payment]);
    }
    public function refund(string $transactionId, ?float $amount = null): array {
        if (is_callable($this->customRefundLogic)) return parent::refund($transactionId, $amount);
        if ($error = $this->requireKeys(1)) return $error;
        if ($amount === null || $amount <= 0) return $this->failure('invalid_amount', 'Square refund requires a positive amount.');
        $currency = $this->expectedPayment['currency'] ?? 'USD';
        $url = $this->isSandbox ? "https://connect.squareupsandbox.com/v2/refunds" : "https://connect.squareup.com/v2/refunds";
        $res = $this->request('POST', $url, ["Authorization: Bearer {$this->key1}", "Content-Type: application/json", "Square-Version: 2026-05-20"], [
            'idempotency_key' => $this->idempotencyKey('refund', $transactionId, [$amount]),
            'payment_id' => $transactionId,
            'amount_money' => ['amount' => $this->minor($amount, $currency), 'currency' => $currency],
        ]);
        if ($res['code'] !== 200 || empty($res['data']['refund']['id'])) return $this->responseFailure($res, 'refund');
        return $this->success(['refund_id' => $res['data']['refund']['id'], 'status' => $res['data']['refund']['status'] ?? null, 'raw' => $res['data']['refund']]);
    }
}

class PHPA_Adyen extends PHPA_BaseGateway {
    private function escapeHmacValue(mixed $value): string {
        return str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $value);
    }
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(2)) return $error;
        if (empty($options['payment_method']) || empty($options['return_url'])) {
            return $this->failure('missing_payment_data', 'Adyen payment_method and return_url are required.');
        }
        if (!$this->isSandbox && $this->key4 === '') return $this->failure('configuration_error', 'Adyen live URL prefix (key4) is required.');
        $base = $this->isSandbox
            ? 'https://checkout-test.adyen.com'
            : 'https://' . preg_replace('/[^a-zA-Z0-9-]/', '', $this->key4) . '-checkout-live.adyenpayments.com/checkout';
        $res = $this->request('POST', $base . '/v72/payments', ["X-API-Key: {$this->key1}", "Content-Type: application/json", 'Idempotency-Key: ' . $this->idempotencyKey('charge', $orderId, [$amount, strtoupper($currency)])], [
            'amount' => ['currency' => strtoupper($currency), 'value' => $this->minor($amount, $currency)],
            'reference' => $orderId,
            'merchantAccount' => $this->key2,
            'paymentMethod' => $options['payment_method'],
            'returnUrl' => $options['return_url'],
            'shopperReference' => $options['shopper_reference'] ?? null,
        ]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'charge');
        return $this->success(['transaction_id' => $res['data']['pspReference'] ?? null, 'status' => $res['data']['resultCode'] ?? null, 'action' => $res['data']['action'] ?? null, 'raw' => $res['data']]);
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); } return $this->unsupported('verify'); }
    public function refund(string $transactionId, ?float $amount = null): array {
        if (is_callable($this->customRefundLogic)) return parent::refund($transactionId, $amount);
        if ($error = $this->requireKeys(2)) return $error;
        if (!$this->isSandbox && $this->key4 === '') return $this->failure('configuration_error', 'Adyen live URL prefix (key4) is required.');
        $base = $this->isSandbox ? 'https://checkout-test.adyen.com' : 'https://' . preg_replace('/[^a-zA-Z0-9-]/', '', $this->key4) . '-checkout-live.adyenpayments.com/checkout';
        $data = ['merchantAccount' => $this->key2, 'reference' => $this->idempotencyKey('refund', $transactionId, [$amount])];
        if ($amount !== null) {
            if ($amount <= 0) return $this->failure('invalid_amount', 'Refund amount must be greater than zero.');
            $currency = $this->expectedPayment['currency'] ?? 'USD';
            $data['amount'] = ['currency' => $currency, 'value' => $this->minor($amount, $currency)];
        }
        $res = $this->request('POST', $base . '/v72/payments/' . rawurlencode($transactionId) . '/refunds', ["X-API-Key: {$this->key1}", "Content-Type: application/json", 'Idempotency-Key: ' . $this->idempotencyKey('refund', $transactionId, [$amount])], $data);
        if ($res['code'] !== 201 || empty($res['data']['pspReference'])) return $this->responseFailure($res, 'refund');
        return $this->success(['refund_id' => $res['data']['pspReference'], 'status' => $res['data']['status'] ?? 'received', 'raw' => $res['data']]);
    }
    public function webhook(string $payload, array $headers = [], array $context = []): array {
        if (is_callable($this->customWebhookLogic)) return parent::webhook($payload, $headers, $context);
        $hexKey = (string) ($context['secret'] ?? $this->key3);
        if ($hexKey === '' || !ctype_xdigit($hexKey) || strlen($hexKey) % 2 !== 0) {
            return $this->failure('invalid_webhook', 'Adyen HMAC key is missing or invalid.');
        }
        try {
            $body = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return $this->failure('invalid_payload', $error->getMessage());
        }
        $events = [];
        foreach ((array) ($body['notificationItems'] ?? []) as $wrapper) {
            $item = (array) ($wrapper['NotificationRequestItem'] ?? []);
            $fields = [
                $item['pspReference'] ?? '',
                $item['originalReference'] ?? '',
                $item['merchantAccountCode'] ?? '',
                $item['merchantReference'] ?? '',
                $item['amount']['value'] ?? '',
                $item['amount']['currency'] ?? '',
                $item['eventCode'] ?? '',
                $item['success'] ?? '',
            ];
            $signing = implode(':', array_map(fn($value) => $this->escapeHmacValue($value), $fields));
            $expected = base64_encode(hash_hmac('sha256', $signing, hex2bin($hexKey), true));
            $provided = (string) ($item['additionalData']['hmacSignature'] ?? '');
            if ($provided === '' || !hash_equals($expected, $provided)) {
                return $this->failure('invalid_signature', 'Adyen webhook HMAC signature is invalid.');
            }
            $events[] = $item;
        }
        if ($events === []) return $this->failure('invalid_payload', 'Adyen notification items are missing.');
        $eventId = ($events[0]['pspReference'] ?? '') . ':' . ($events[0]['eventCode'] ?? '');
        if (!$this->acceptWebhookEvent($eventId)) return $this->success(['duplicate' => true, 'event_id' => $eventId]);
        return $this->success(['event_id' => $eventId, 'event' => $events[0]['eventCode'] ?? null, 'transaction_id' => $events[0]['pspReference'] ?? null, 'data' => $events]);
    }
}

class PHPA_Mollie extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(1)) return $error;
        if (empty($options['redirect_url'])) return $this->failure('missing_redirect_url', 'Mollie redirect_url is required.');
        $data = ['amount' => ['currency' => strtoupper($currency), 'value' => $this->decimal($amount)], 'description' => $options['description'] ?? $orderId, 'redirectUrl' => $options['redirect_url'], 'metadata' => ['order_id' => $orderId]];
        if (!empty($options['webhook_url'])) $data['webhookUrl'] = $options['webhook_url'];
        $res = $this->request('POST', 'https://api.mollie.com/v2/payments', ["Authorization: Bearer {$this->key1}", 'Content-Type: application/json', 'Idempotency-Key: ' . ($options['idempotency_key'] ?? $this->idempotencyKey('charge', $orderId, [$amount, strtoupper($currency)]))], $data);
        if ($res['code'] !== 201 || empty($res['data']['id'])) return $this->responseFailure($res, 'charge');
        return $this->success(['transaction_id' => $res['data']['id'], 'checkout_url' => $res['data']['_links']['checkout']['href'] ?? null, 'status' => $res['data']['status'] ?? null, 'raw' => $res['data']]);
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(1)) return $error;
        $res = $this->request('GET', "https://api.mollie.com/v2/payments/" . rawurlencode($transactionId), ["Authorization: Bearer {$this->key1}"]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        $matches = $this->paymentMatches($res['data']['metadata']['order_id'] ?? null, $res['data']['amount']['value'] ?? null, $res['data']['amount']['currency'] ?? null);
        return ($res['data']['status'] ?? '') === 'paid' && $matches
            ? $this->success(['transaction_id' => $res['data']['id'] ?? $transactionId, 'status' => 'paid', 'raw' => $res['data']])
            : $this->failure('payment_not_completed', $matches ? 'Mollie payment is not paid.' : 'Mollie payment does not match the expected payment.', ['status' => $res['data']['status'] ?? null, 'raw' => $res['data']]);
    }
    public function refund(string $transactionId, ?float $amount = null): array {
        if (is_callable($this->customRefundLogic)) return parent::refund($transactionId, $amount);
        if ($error = $this->requireKeys(1)) return $error;
        $data = [];
        if ($amount !== null) {
            if ($amount <= 0) return $this->failure('invalid_amount', 'Refund amount must be greater than zero.');
            $data['amount'] = ['currency' => $this->expectedPayment['currency'] ?? 'EUR', 'value' => $this->decimal($amount)];
        }
        $res = $this->request('POST', 'https://api.mollie.com/v2/payments/' . rawurlencode($transactionId) . '/refunds', ["Authorization: Bearer {$this->key1}", 'Content-Type: application/json', 'Idempotency-Key: ' . $this->idempotencyKey('refund', $transactionId, [$amount])], $data);
        if ($res['code'] !== 201 || empty($res['data']['id'])) return $this->responseFailure($res, 'refund');
        return $this->success(['refund_id' => $res['data']['id'], 'status' => $res['data']['status'] ?? null, 'raw' => $res['data']]);
    }
    public function webhook(string $payload, array $headers = [], array $context = []): array {
        if (is_callable($this->customWebhookLogic)) return parent::webhook($payload, $headers, $context);
        parse_str($payload, $form);
        $id = (string) ($context['id'] ?? $form['id'] ?? '');
        if ($id === '') {
            try {
                $json = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                $id = (string) ($json['id'] ?? '');
            } catch (\JsonException $ignored) {
            }
        }
        if ($id === '') return $this->failure('invalid_payload', 'Mollie payment ID is missing.');
        return $this->verify($id);
    }
}

// ==========================================
// 🪙 Top 10 Crypto Gateways
// ==========================================
class PHPA_Coinbase extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(1)) return $error;
        $res = $this->request('POST', 'https://api.commerce.coinbase.com/charges', ["X-CC-Api-Key: {$this->key1}", "X-CC-Version: 2018-03-22"], ['name' => 'Order '.$orderId, 'description' => 'Payment for order', 'pricing_type' => 'fixed_price', 'local_price' => ['amount' => $amount, 'currency' => $currency]]);
        return ['success' => $res['code'] == 201, 'transaction_id' => $res['data']['data']['id'] ?? null, 'checkout_url' => $res['data']['data']['hosted_url'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(1)) return $error;
        $res = $this->request('GET', "https://api.commerce.coinbase.com/charges/" . rawurlencode($transactionId), ["X-CC-Api-Key: {$this->key1}", "X-CC-Version: 2018-03-22"]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        $timeline = $res['data']['data']['timeline'] ?? [];
        $status = end($timeline)['status'] ?? '';
        return $status === 'COMPLETED'
            ? $this->success(['transaction_id' => $res['data']['data']['id'] ?? $transactionId, 'status' => $status, 'raw' => $res['data']['data'] ?? $res['data']])
            : $this->failure('payment_not_completed', 'Coinbase charge is not completed.', ['status' => $status, 'raw' => $res['data']]);
    }
    public function webhook(string $payload, array $headers = [], array $context = []): array {
        if (is_callable($this->customWebhookLogic)) return parent::webhook($payload, $headers, $context);
        $secret = $context['secret'] ?? $this->key3;
        $signature = $this->header($headers, 'X-CC-Webhook-Signature');
        if ($secret === '' || $signature === null) return $this->failure('invalid_webhook', 'Coinbase webhook secret or signature is missing.');
        if (!hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) return $this->failure('invalid_signature', 'Coinbase webhook signature is invalid.');
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return $this->failure('invalid_payload', $error->getMessage());
        }
        $eventId = $event['event']['id'] ?? null;
        if (!$this->acceptWebhookEvent($eventId)) return $this->success(['duplicate' => true, 'event_id' => $eventId]);
        return $this->success(['event_id' => $eventId, 'event' => $event['event']['type'] ?? null, 'data' => $event['event']['data'] ?? null]);
    }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Binance extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(2)) return $error;
        $nonce = bin2hex(random_bytes(16));
        $timestamp = round(microtime(true) * 1000);
        $payload = json_encode(['env' => ['terminalType' => 'WEB'], 'merchantTradeNo' => $orderId, 'orderAmount' => $amount, 'currency' => $currency, 'goods' => ['goodsType' => '02', 'goodsCategory' => 'Z000', 'referenceGoodsId' => $orderId, 'goodsName' => 'Order']]);
        $signature = strtoupper(hash_hmac('sha512', "$timestamp\n$nonce\n$payload\n", $this->key2));
        $res = $this->request('POST', 'https://bpay.binanceapi.com/binancepay/openapi/v2/order', ["Content-Type: application/json", "BinancePay-Timestamp: $timestamp", "BinancePay-Nonce: $nonce", "BinancePay-Certificate-SN: {$this->key1}", "BinancePay-Signature: $signature"], $payload);
        return ['success' => ($res['data']['status'] ?? '') === 'SUCCESS', 'transaction_id' => $res['data']['data']['prepayId'] ?? null, 'checkout_url' => $res['data']['data']['checkoutUrl'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(2)) return $error;
        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string) round(microtime(true) * 1000);
        $payload = json_encode(['merchantTradeNo' => $transactionId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = strtoupper(hash_hmac('sha512', "$timestamp\n$nonce\n$payload\n", $this->key2));
        $res = $this->request('POST', 'https://bpay.binanceapi.com/binancepay/openapi/v2/order/query', ["Content-Type: application/json", "BinancePay-Timestamp: $timestamp", "BinancePay-Nonce: $nonce", "BinancePay-Certificate-SN: {$this->key1}", "BinancePay-Signature: $signature"], $payload);
        if ($res['code'] !== 200 || ($res['data']['status'] ?? '') !== 'SUCCESS') return $this->responseFailure($res, 'verify');
        $status = strtoupper((string) ($res['data']['data']['status'] ?? ''));
        return $status === 'PAID'
            ? $this->success(['transaction_id' => $res['data']['data']['prepayId'] ?? $transactionId, 'status' => $status, 'raw' => $res['data']['data']])
            : $this->failure('payment_not_completed', 'Binance Pay order is not paid.', ['status' => $status, 'raw' => $res['data']['data'] ?? null]);
    }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Coinpayments extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(2)) return $error;
        $req = ['version' => 1, 'cmd' => 'create_transaction', 'amount' => $amount, 'currency1' => $currency, 'currency2' => $options['crypto'] ?? 'BTC', 'buyer_email' => $options['email'] ?? 'test@test.com', 'key' => $this->key1, 'format' => 'json'];
        $post_data = http_build_query($req, '', '&');
        $hmac = hash_hmac('sha512', $post_data, $this->key2);
        $res = $this->request('POST', 'https://www.coinpayments.net/api.php', ["HMAC: $hmac", "Content-Type: application/x-www-form-urlencoded"], $post_data);
        return ['success' => ($res['data']['error'] ?? '') === 'ok', 'transaction_id' => $res['data']['result']['txn_id'] ?? null, 'checkout_url' => $res['data']['result']['checkout_url'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(2)) return $error;
        $request = ['version' => 1, 'cmd' => 'get_tx_info', 'txid' => $transactionId, 'key' => $this->key1, 'format' => 'json'];
        $payload = http_build_query($request, '', '&');
        $res = $this->request('POST', 'https://www.coinpayments.net/api.php', ['HMAC: ' . hash_hmac('sha512', $payload, $this->key2), 'Content-Type: application/x-www-form-urlencoded'], $payload);
        if ($res['code'] !== 200 || ($res['data']['error'] ?? '') !== 'ok') return $this->responseFailure($res, 'verify');
        $status = (int) ($res['data']['result']['status'] ?? -1);
        return ($status >= 100 || $status === 2)
            ? $this->success(['transaction_id' => $transactionId, 'status' => $status, 'raw' => $res['data']['result']])
            : $this->failure('payment_not_completed', 'CoinPayments transaction is not complete.', ['status' => $status, 'raw' => $res['data']['result'] ?? null]);
    }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Bitpay extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(1)) return $error;
        $url = $this->isSandbox ? "https://test.bitpay.com/invoices" : "https://bitpay.com/invoices";
        $res = $this->request('POST', $url, ["X-Accept-Version: 2.0.0", "Content-Type: application/json"], ['price' => $amount, 'currency' => $currency, 'orderId' => $orderId, 'token' => $this->key1]);
        return ['success' => isset($res['data']['data']['id']), 'transaction_id' => $res['data']['data']['id'] ?? null, 'checkout_url' => $res['data']['data']['url'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(1)) return $error;
        $base = $this->isSandbox ? 'https://test.bitpay.com' : 'https://bitpay.com';
        $res = $this->request('GET', $base . '/invoices/' . rawurlencode($transactionId) . '?' . http_build_query(['token' => $this->key1]), ['X-Accept-Version: 2.0.0']);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        $invoice = (array) ($res['data']['data'] ?? []);
        $status = strtolower((string) ($invoice['status'] ?? ''));
        return in_array($status, ['confirmed', 'complete'], true)
            ? $this->success(['transaction_id' => $invoice['id'] ?? $transactionId, 'status' => $status, 'raw' => $invoice])
            : $this->failure('payment_not_confirmed', 'BitPay invoice is not confirmed.', ['status' => $status, 'raw' => $invoice]);
    }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Nowpayments extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(1)) return $error;
        $res = $this->request('POST', 'https://api.nowpayments.io/v1/invoice', ["x-api-key: {$this->key1}", "Content-Type: application/json"], ['price_amount' => $amount, 'price_currency' => strtolower($currency), 'order_id' => $orderId]);
        return ['success' => isset($res['data']['id']), 'transaction_id' => $res['data']['id'] ?? null, 'checkout_url' => $res['data']['invoice_url'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(1)) return $error;
        $res = $this->request('GET', 'https://api.nowpayments.io/v1/payment/' . rawurlencode($transactionId), ["x-api-key: {$this->key1}"]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        $status = strtolower((string) ($res['data']['payment_status'] ?? ''));
        return in_array($status, ['finished', 'confirmed'], true)
            ? $this->success(['transaction_id' => $res['data']['payment_id'] ?? $transactionId, 'status' => $status, 'raw' => $res['data']])
            : $this->failure('payment_not_completed', 'NOWPayments payment is not completed.', ['status' => $status, 'raw' => $res['data']]);
    }
    public function webhook(string $payload, array $headers = [], array $context = []): array {
        if (is_callable($this->customWebhookLogic)) return parent::webhook($payload, $headers, $context);
        $secret = $context['secret'] ?? $this->key2;
        $signature = $this->header($headers, 'X-Nowpayments-Sig');
        if ($secret === '' || $signature === null) return $this->failure('invalid_webhook', 'NOWPayments IPN secret or signature is missing.');
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            ksort($data);
            $canonical = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return $this->failure('invalid_payload', $error->getMessage());
        }
        if (!hash_equals(hash_hmac('sha512', $canonical, $secret), strtolower($signature))) return $this->failure('invalid_signature', 'NOWPayments IPN signature is invalid.');
        $eventId = ($data['payment_id'] ?? '') . ':' . ($data['payment_status'] ?? '');
        if (!$this->acceptWebhookEvent($eventId)) return $this->success(['duplicate' => true, 'event_id' => $eventId]);
        return $this->success(['event_id' => $eventId, 'event' => 'payment.' . strtolower((string) ($data['payment_status'] ?? 'unknown')), 'transaction_id' => $data['payment_id'] ?? null, 'data' => $data]);
    }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Cryptocom extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(1)) return $error;
        $res = $this->request('POST', 'https://pay.crypto.com/api/payments', ["Authorization: Bearer {$this->key1}"], ['amount' => $amount * 100, 'currency' => $currency, 'description' => $orderId]);
        return ['success' => isset($res['data']['id']), 'transaction_id' => $res['data']['id'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); } return $this->unsupported('verify'); }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Coingate extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(1)) return $error;
        $url = $this->isSandbox ? "https://api-sandbox.coingate.com/v2/orders" : "https://api.coingate.com/v2/orders";
        $res = $this->request('POST', $url, ["Authorization: Token {$this->key1}"], ['order_id' => $orderId, 'price_amount' => $amount, 'price_currency' => $currency, 'receive_currency' => $currency]);
        return ['success' => isset($res['data']['id']), 'transaction_id' => $res['data']['id'] ?? null, 'checkout_url' => $res['data']['payment_url'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(1)) return $error;
        $url = $this->isSandbox ? "https://api-sandbox.coingate.com/v2/orders/" : "https://api.coingate.com/v2/orders/";
        $res = $this->request('GET', $url . rawurlencode($transactionId), ["Authorization: Token {$this->key1}"]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        $status = strtolower((string) ($res['data']['status'] ?? ''));
        return $status === 'paid'
            ? $this->success(['transaction_id' => $res['data']['id'] ?? $transactionId, 'status' => $status, 'raw' => $res['data']])
            : $this->failure('payment_not_completed', 'CoinGate order is not paid.', ['status' => $status, 'raw' => $res['data']]);
    }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Trustwallet extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        return $this->unsupported('charge');
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); } return $this->unsupported('verify'); }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Btcpay extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireKeys(3)) return $error;
        $res = $this->request('POST', rtrim($this->key2, '/')."/api/v1/stores/{$this->key3}/invoices", ["Authorization: token {$this->key1}"], ['amount' => $amount, 'currency' => $currency, 'metadata' => ['orderId' => $orderId]]);
        return ['success' => isset($res['data']['id']), 'transaction_id' => $res['data']['id'] ?? null, 'checkout_url' => $res['data']['checkoutLink'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(3)) return $error;
        $url = rtrim($this->key2, '/') . "/api/v1/stores/" . rawurlencode($this->key3) . "/invoices/" . rawurlencode($transactionId);
        $res = $this->request('GET', $url, ["Authorization: token {$this->key1}"]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        $status = strtolower((string) ($res['data']['status'] ?? ''));
        $additional = strtolower((string) ($res['data']['additionalStatus'] ?? 'none'));
        return $status === 'settled' && in_array($additional, ['none', 'paidover'], true)
            ? $this->success(['transaction_id' => $res['data']['id'] ?? $transactionId, 'status' => $status, 'raw' => $res['data']])
            : $this->failure('payment_not_settled', 'BTCPay invoice is not safely settled.', ['status' => $status, 'additional_status' => $additional, 'raw' => $res['data']]);
    }
    public function refund(string $transactionId, ?float $amount = null): array {
        if (is_callable($this->customRefundLogic)) return parent::refund($transactionId, $amount);
        if ($error = $this->requireKeys(3)) return $error;
        $url = rtrim($this->key2, '/') . "/api/v1/stores/" . rawurlencode($this->key3) . "/invoices/" . rawurlencode($transactionId) . '/refund';
        $data = ['refundVariant' => $amount === null ? 'CurrentRate' : 'Fiat'];
        if ($amount !== null) {
            if ($amount <= 0) return $this->failure('invalid_amount', 'Refund amount must be greater than zero.');
            $data['amount'] = $this->decimal($amount);
            $data['currency'] = $this->expectedPayment['currency'] ?? 'USD';
        }
        $res = $this->request('POST', $url, ["Authorization: token {$this->key1}", 'Content-Type: application/json'], $data);
        if (!in_array($res['code'], [200, 201], true)) return $this->responseFailure($res, 'refund');
        return $this->success(['refund_id' => $res['data']['id'] ?? null, 'checkout_url' => $res['data']['viewLink'] ?? null, 'raw' => $res['data']]);
    }
    public function webhook(string $payload, array $headers = [], array $context = []): array {
        if (is_callable($this->customWebhookLogic)) return parent::webhook($payload, $headers, $context);
        $secret = $context['secret'] ?? $this->key4;
        $signature = $this->header($headers, 'BTCPay-Sig');
        if ($secret === '' || $signature === null) return $this->failure('invalid_webhook', 'BTCPay webhook secret or signature is missing.');
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) return $this->failure('invalid_signature', 'BTCPay webhook signature is invalid.');
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return $this->failure('invalid_payload', $error->getMessage());
        }
        $eventId = $event['deliveryId'] ?? null;
        if (!$this->acceptWebhookEvent($eventId)) return $this->success(['duplicate' => true, 'event_id' => $eventId]);
        return $this->success(['event_id' => $eventId, 'event' => $event['type'] ?? null, 'transaction_id' => $event['invoiceId'] ?? null, 'data' => $event]);
    }
}

class PHPA_Metamask extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        return $this->unsupported('charge');
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); } return $this->unsupported('verify'); }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

// ==========================================
// 🇧🇩 Top 10 Bangladesh Gateways
// ==========================================
class PHPA_Bkash extends PHPA_BaseGateway {
    private function getToken() {
        $url = $this->isSandbox ? "https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/token/grant" : "https://tokenized.bka.sh/v1.2.0-beta/tokenized/checkout/token/grant";
        $res = $this->request('POST', $url, ["username: {$this->key3}", "password: {$this->key4}", "Content-Type: application/json"], ['app_key' => $this->key1, 'app_secret' => $this->key2]);
        return $res['data']['id_token'] ?? null;
    }
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireCurrency($currency, ['BDT'])) return $error;
        if ($error = $this->requireKeys(4)) return $error;
        $token = $this->getToken();
        $url = $this->isSandbox ? "https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/create" : "https://tokenized.bka.sh/v1.2.0-beta/tokenized/checkout/create";
        $res = $this->request('POST', $url, ["Authorization: $token", "X-APP-Key: {$this->key1}", "Content-Type: application/json"], ['mode' => '0011', 'payerReference' => ' ', 'callbackURL' => $options['callback_url'] ?? '', 'amount' => $amount, 'currency' => 'BDT', 'intent' => 'sale', 'merchantInvoiceNumber' => $orderId]);
        return ['success' => isset($res['data']['paymentID']), 'transaction_id' => $res['data']['paymentID'] ?? null, 'checkout_url' => $res['data']['bkashURL'] ?? null];
    }
    public function verify(string $paymentId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $paymentId); }
        if ($error = $this->requireKeys(4)) return $error;
        $token = $this->getToken();
        if ($token === null) return $this->failure('authentication_failed', 'bKash access token could not be obtained.');
        $base = $this->isSandbox ? "https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout" : "https://tokenized.bka.sh/v1.2.0-beta/tokenized/checkout";
        $res = $this->request('POST', $base . '/payment/status', ["Authorization: $token", "X-APP-Key: {$this->key1}", "Content-Type: application/json"], ['paymentID' => $paymentId]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        return ($res['data']['transactionStatus'] ?? '') === 'Completed'
            ? $this->success(['transaction_id' => $res['data']['trxID'] ?? $paymentId, 'payment_id' => $paymentId, 'status' => $res['data']['transactionStatus'], 'raw' => $res['data']])
            : $this->failure('payment_not_completed', 'bKash payment is not completed.', ['status' => $res['data']['transactionStatus'] ?? null, 'raw' => $res['data']]);
    }
    public function execute(string $paymentId): array {
        if ($error = $this->requireKeys(4)) return $error;
        $token = $this->getToken();
        if ($token === null) return $this->failure('authentication_failed', 'bKash access token could not be obtained.');
        $url = $this->isSandbox ? "https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/execute" : "https://tokenized.bka.sh/v1.2.0-beta/tokenized/checkout/execute";
        $res = $this->request('POST', $url, ["Authorization: $token", "X-APP-Key: {$this->key1}", "Content-Type: application/json"], ['paymentID' => $paymentId]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'execute');
        return ($res['data']['transactionStatus'] ?? '') === 'Completed'
            ? $this->success(['transaction_id' => $res['data']['trxID'] ?? null, 'payment_id' => $paymentId, 'status' => $res['data']['transactionStatus'], 'raw' => $res['data']])
            : $this->failure('payment_not_completed', 'bKash payment execution did not complete.', ['raw' => $res['data']]);
    }
    public function refundPayment(string $paymentId, string $trxId, float $amount, string $sku = 'refund', string $reason = 'Customer refund'): array {
        if ($error = $this->requireKeys(4)) return $error;
        if ($amount <= 0) return $this->failure('invalid_amount', 'Refund amount must be greater than zero.');
        $token = $this->getToken();
        if ($token === null) return $this->failure('authentication_failed', 'bKash access token could not be obtained.');
        $base = $this->isSandbox ? "https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout" : "https://tokenized.bka.sh/v1.2.0-beta/tokenized/checkout";
        $res = $this->request('POST', $base . '/payment/refund', ["Authorization: $token", "X-APP-Key: {$this->key1}", "Content-Type: application/json"], [
            'paymentID' => $paymentId,
            'trxID' => $trxId,
            'amount' => $this->decimal($amount),
            'sku' => $sku,
            'reason' => $reason,
        ]);
        if ($res['code'] !== 200 || empty($res['data']['refundTrxID'])) return $this->responseFailure($res, 'refund');
        return $this->success(['refund_id' => $res['data']['refundTrxID'], 'status' => $res['data']['transactionStatus'] ?? null, 'raw' => $res['data']]);
    }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Nagad extends PHPA_BaseGateway {
    private function getPrivateKey() {
        $key = $this->key2;
        if (strpos($key, 'BEGIN RSA PRIVATE KEY') === false) {
            $key = "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
        }
        return $key;
    }

    private function getPublicKey() {
        $key = $this->key1;
        if (strpos($key, 'BEGIN PUBLIC KEY') === false) {
            $key = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        }
        return $key;
    }

    private function encryptData($data) {
        if (!openssl_public_encrypt($data, $encrypted, $this->getPublicKey())) {
            throw new \RuntimeException('Nagad public-key encryption failed.');
        }
        return base64_encode($encrypted);
    }

    private function decryptData($data) {
        $decoded = base64_decode($data, true);
        if ($decoded === false || !openssl_private_decrypt($decoded, $decrypted, $this->getPrivateKey())) {
            throw new \RuntimeException('Nagad private-key decryption failed.');
        }
        return $decrypted;
    }

    private function signData($data) {
        if (!openssl_sign($data, $signature, $this->getPrivateKey(), OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Nagad signature generation failed.');
        }
        return base64_encode($signature);
    }

    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireCurrency($currency, ['BDT'])) return $error;
        if ($error = $this->requireKeys(3)) return $error;
        $merchantId = $this->key3; 
        $datetime = date('YmdHis');
        $random = random_int(1000, 9999);
        $urlBase = $this->isSandbox ? "http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs" : "https://api.mynagad.com/api/dfs";
        
        $initData = ['merchant' => $merchantId, 'datetime' => $datetime, 'orderId' => $orderId, 'challenge' => $random];
        $initPayload = json_encode($initData);
        $initRes = $this->request('POST', "$urlBase/check-out/initialize/$merchantId/$orderId", ["X-KM-Api-Version: v-0.2.0", "X-KM-IP-V4: " . ($options['client_ip'] ?? '127.0.0.1'), "X-KM-Client-Type: PC_WEB", "Content-Type: application/json"], ['accountNumber' => $options['account'] ?? $merchantId, 'dateTime' => $datetime, 'sensitiveData' => $this->encryptData($initPayload), 'signature' => $this->signData($initPayload)], ['allow_insecure_sandbox' => true]);

        if (empty($initRes['data']['sensitiveData'])) return ['success' => false, 'message' => 'Initialization failed', 'raw' => $initRes];

        $resData = json_decode($this->decryptData($initRes['data']['sensitiveData']), true);
        $completePayload = json_encode(['merchant' => $merchantId, 'orderId' => $orderId, 'amount' => $amount, 'currencyCode' => '050', 'challenge' => $resData['challenge']]);
        
        $completeRes = $this->request('POST', "$urlBase/check-out/complete/$merchantId/$orderId", ["X-KM-Api-Version: v-0.2.0", "X-KM-IP-V4: " . ($options['client_ip'] ?? '127.0.0.1'), "X-KM-Client-Type: PC_WEB", "Content-Type: application/json"], ['sensitiveData' => $this->encryptData($completePayload), 'signature' => $this->signData($completePayload), 'merchantCallbackURL' => $options['callback_url'] ?? ''], ['allow_insecure_sandbox' => true]);

        return ['success' => isset($completeRes['data']['callBackUrl']), 'transaction_id' => $orderId, 'checkout_url' => $completeRes['data']['callBackUrl'] ?? null, 'raw' => $completeRes['data']];
    }
    
    public function verify(string $paymentRefId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $paymentRefId); }
        if ($error = $this->requireKeys(3)) return $error;
        $urlBase = $this->isSandbox ? "http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs" : "https://api.mynagad.com/api/dfs";
        $res = $this->request('GET', "$urlBase/verify/payment/" . rawurlencode($paymentRefId), [], null, ['allow_insecure_sandbox' => true]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        return ['success' => ($res['data']['status'] ?? '') === 'Success', 'transaction_id' => $res['data']['paymentRefId'] ?? $paymentRefId, 'status' => $res['data']['status'] ?? null];
    }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}
class PHPA_Rocket extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        return $this->unsupported('charge');
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); } return $this->unsupported('verify'); }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Sslcommerz extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireCurrency($currency, ['BDT'])) return $error;
        if ($error = $this->requireKeys(2)) return $error;
        $url = $this->isSandbox ? "https://sandbox.sslcommerz.com/gwprocess/v3/api.php" : "https://securepay.sslcommerz.com/gwprocess/v3/api.php";
        $data = ['store_id' => $this->key1, 'store_passwd' => $this->key2, 'total_amount' => $amount, 'currency' => 'BDT', 'tran_id' => $orderId, 'success_url' => $options['success_url'] ?? '', 'fail_url' => $options['fail_url'] ?? '', 'cancel_url' => $options['cancel_url'] ?? '', 'cus_name' => $options['name'] ?? 'Customer', 'cus_email' => $options['email'] ?? 'test@test.com', 'cus_add1' => 'Dhaka', 'cus_phone' => '01700000000'];
        $res = $this->request('POST', $url, [], http_build_query($data));
        return ['success' => ($res['data']['status'] ?? '') === 'SUCCESS', 'transaction_id' => $res['data']['sessionkey'] ?? null, 'checkout_url' => $res['data']['GatewayPageURL'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(2)) return $error;
        $url = $this->isSandbox ? "https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php" : "https://securepay.sslcommerz.com/validator/api/validationserverAPI.php";
        $res = $this->request('GET', $url . '?' . http_build_query(['val_id' => $transactionId, 'store_id' => $this->key1, 'store_passwd' => $this->key2, 'v' => 1, 'format' => 'json']));
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        $status = strtoupper((string) ($res['data']['status'] ?? ''));
        $matches = $this->paymentMatches($res['data']['tran_id'] ?? null, $res['data']['amount'] ?? null, $res['data']['currency'] ?? $res['data']['currency_type'] ?? null);
        return in_array($status, ['VALID', 'VALIDATED'], true) && $matches
            ? $this->success(['transaction_id' => $res['data']['tran_id'] ?? null, 'validation_id' => $transactionId, 'status' => $status, 'risk_level' => $res['data']['risk_level'] ?? null, 'raw' => $res['data']])
            : $this->failure('payment_not_valid', $matches ? 'SSLCommerz transaction is not valid.' : 'SSLCommerz transaction does not match the expected payment.', ['status' => $status, 'raw' => $res['data']]);
    }
    public function refund(string $transactionId, ?float $amount = null): array {
        if (is_callable($this->customRefundLogic)) return parent::refund($transactionId, $amount);
        if ($error = $this->requireKeys(2)) return $error;
        if ($amount === null || $amount <= 0) return $this->failure('invalid_amount', 'SSLCommerz refund requires a positive amount.');
        $url = $this->isSandbox ? "https://sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php" : "https://securepay.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php";
        $refundId = $this->idempotencyKey('refund', $transactionId, [$amount]);
        $res = $this->request('GET', $url . '?' . http_build_query([
            'bank_tran_id' => $transactionId,
            'refund_amount' => $this->decimal($amount),
            'refund_remarks' => 'Customer refund',
            'refund_trans_id' => $refundId,
            'store_id' => $this->key1,
            'store_passwd' => $this->key2,
            'format' => 'json',
        ]));
        if ($res['code'] !== 200 || strtoupper((string) ($res['data']['APIConnect'] ?? '')) !== 'DONE') return $this->responseFailure($res, 'refund');
        return $this->success(['refund_id' => $res['data']['refund_ref_id'] ?? $refundId, 'status' => $res['data']['status'] ?? 'processing', 'raw' => $res['data']]);
    }
    public function webhook(string $payload, array $headers = [], array $context = []): array {
        if (is_callable($this->customWebhookLogic)) return parent::webhook($payload, $headers, $context);
        parse_str($payload, $form);
        $validationId = (string) ($context['val_id'] ?? $form['val_id'] ?? '');
        if ($validationId === '') return $this->failure('invalid_payload', 'SSLCommerz val_id is missing.');
        return $this->verify($validationId);
    }
}

class PHPA_Aamarpay extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireCurrency($currency, ['BDT'])) return $error;
        if ($error = $this->requireKeys(2)) return $error;
        $url = $this->isSandbox ? "https://sandbox.aamarpay.com/jsonpost.php" : "https://secure.aamarpay.com/jsonpost.php";
        $data = ['store_id' => $this->key1, 'signature_key' => $this->key2, 'amount' => $amount, 'currency' => 'BDT', 'tran_id' => $orderId, 'success_url' => $options['success_url'] ?? '', 'fail_url' => $options['fail_url'] ?? '', 'cancel_url' => $options['cancel_url'] ?? '', 'cus_name' => 'Customer', 'cus_email' => 'test@test.com', 'cus_phone' => '01700000000', 'desc' => 'Payment'];
        $res = $this->request('POST', $url, ["Content-Type: application/json"], $data);
        $checkout_url = $this->isSandbox ? "https://sandbox.aamarpay.com/" : "https://secure.aamarpay.com/";
        if(isset($res['data']['payment_url'])) $checkout_url = $res['data']['payment_url'];
        return ['success' => isset($res['data']['result']) && $res['data']['result'] !== 'false', 'transaction_id' => $orderId, 'checkout_url' => $checkout_url];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId ?? $paymentRefId ?? $paymentId); }
        $url = $this->isSandbox ? "https://sandbox.aamarpay.com/api/v1/trxcheck/request.php" : "https://secure.aamarpay.com/api/v1/trxcheck/request.php";
        $res = $this->request('GET', $url . '?' . http_build_query([
            'request_id' => $transactionId,
            'store_id' => $this->key1,
            'signature_key' => $this->key2,
            'type' => 'json',
        ]));
        return ['success' => ($res['data']['pay_status'] ?? '') === 'Successful'];
    }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Surjopay extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireCurrency($currency, ['BDT'])) return $error;
        if ($error = $this->requireKeys(3)) return $error;
        // Authenticate first
        $urlBase = $this->isSandbox ? "https://sandbox.surjopay.bd.com" : "https://securepay.surjopay.bd.com";
        $authRes = $this->request('POST', "$urlBase/api/get_token", ["Content-Type: application/json"], ['username' => $this->key1, 'password' => $this->key2]);
        $token = $authRes['data']['token'] ?? '';
        
        $data = ['prefix' => $this->key3, 'token' => $token, 'return_url' => $options['success_url'] ?? '', 'cancel_url' => $options['cancel_url'] ?? '', 'store_id' => $authRes['data']['store_id'] ?? '', 'amount' => $amount, 'order_id' => $orderId, 'currency' => 'BDT', 'customer_name' => 'Customer', 'customer_phone' => '01700000000'];
        $res = $this->request('POST', "$urlBase/api/secret-pay", ["Authorization: Bearer $token", "Content-Type: application/json"], $data);
        return ['success' => isset($res['data']['checkout_url']), 'transaction_id' => $orderId, 'checkout_url' => $res['data']['checkout_url'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); }
        if ($error = $this->requireKeys(3)) return $error;
        $urlBase = $this->isSandbox ? "https://sandbox.surjopay.bd.com" : "https://securepay.surjopay.bd.com";
        $auth = $this->request('POST', "$urlBase/api/get_token", ['Content-Type: application/json'], ['username' => $this->key1, 'password' => $this->key2]);
        $token = $auth['data']['token'] ?? '';
        if ($token === '') return $this->responseFailure($auth, 'authentication');
        $res = $this->request('POST', "$urlBase/api/verification", ["Authorization: Bearer $token", 'Content-Type: application/json'], ['order_id' => $transactionId]);
        if ($res['code'] !== 200) return $this->responseFailure($res, 'verify');
        $payment = isset($res['data'][0]) ? $res['data'][0] : $res['data'];
        $code = (string) ($payment['sp_code'] ?? '');
        return $code === '1000'
            ? $this->success(['transaction_id' => $payment['order_id'] ?? $transactionId, 'status' => $payment['transaction_status'] ?? 'completed', 'raw' => $payment])
            : $this->failure('payment_not_completed', 'SurjoPay payment is not completed.', ['status' => $code, 'raw' => $payment]);
    }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Portwallet extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); }
        if ($error = $this->validatePayment($amount, $currency, $orderId)) return $error;
        if ($error = $this->requireCurrency($currency, ['BDT'])) return $error;
        if ($error = $this->requireKeys(2)) return $error;
        $url = $this->isSandbox ? "https://api-sandbox.portwallet.com/payment/v2/invoice" : "https://api.portwallet.com/payment/v2/invoice";
        $auth = base64_encode("{$this->key1}:{$this->key2}");
        $data = ['order' => ['amount' => $amount, 'currency' => 'BDT', 'redirect_url' => $options['success_url'] ?? ''], 'product' => ['name' => 'Order', 'description' => $orderId], 'billing' => ['customer' => ['name' => 'Customer', 'email' => 'test@test.com', 'phone' => '01700000000', 'address' => ['street' => 'Dhaka', 'city' => 'Dhaka', 'country' => 'BD', 'zipcode' => '1000']]]];
        $res = $this->request('POST', $url, ["Authorization: Bearer $auth", "Content-Type: application/json"], $data);
        return ['success' => ($res['data']['result'] ?? '') === 'success', 'transaction_id' => $res['data']['data']['invoice_id'] ?? null, 'checkout_url' => $res['data']['data']['action']['url'] ?? null];
    }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); } return $this->unsupported('verify'); }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Upay extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); } return $this->unsupported('charge'); }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); } return $this->unsupported('verify'); }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Shurjomukhi extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); } return $this->unsupported('charge'); }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); } return $this->unsupported('verify'); }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}

class PHPA_Nexuspay extends PHPA_BaseGateway {
    public function charge(float $amount, string $currency, string $orderId, array $options = []): array {
        if (is_callable($this->customChargeLogic)) { return call_user_func($this->customChargeLogic, $this, $amount, $currency, $orderId, $options); } return $this->unsupported('charge'); }
    public function verify(string $transactionId): array {
        if (is_callable($this->customVerifyLogic)) { return call_user_func($this->customVerifyLogic, $this, $transactionId); } return $this->unsupported('verify'); }
    public function refund(string $transactionId, ?float $amount = null): array { return parent::refund($transactionId, $amount); }
}
