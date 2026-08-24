<?php

/**
 * ============================================================================
 * Class: PHJT
 * Title: JWT (JSON Web Token) Manager
 * ============================================================================
 * 
 * Provides secure HMAC JWT creation, verification, algorithm selection, and key rotation strategies for stateless authentication.
 * 
 * Features:
 * - Stateless JWT generation and verification.
 * - Multiple hashing algorithm support (e.g., HS256, HS512).
 * - Secure key rotation and expiry validation.
 * 
 * Usage Example:
 * ```php
 * PHJT::key('super_secret_key');
 * $token = PHJT::encode(['user_id' => 123]);
 * $payload = PHJT::decode($token);
 * ```
 * 
 * @package    MyStack
 * @author     Sakibur Rahman (@sakibweb)
 * @published  https://github.com/mystack-framework
 * @web        https://mystack-framework.github.io
 * @license    Apache License 2.0
 * ============================================================================
 */



class PHJT {

    /**
     * Default algorithm.
     * @var string
     */
    private static string $defaultAlgorithm = 'HS256';

    /**
     * Secret key for signing the token.
     * Unified source of truth for security.
     * @var string
     */
    private static string $secretKey = '';

    /**
     * Supported symmetric algorithms (HMAC).
     * @var array
     */
    private static array $supportedAlgs = [
        'HS256' => 'sha256',
        'HS384' => 'sha384',
        'HS512' => 'sha512',
    ];

    /**
     * Base64URL encoding without padding
     */
    private static function base64UrlEncode(string $data): string {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Base64URL decoding
     */
    private static function base64UrlDecode(string $data): ?string {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $data), true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * Updates the encryption key.
     * (Alias for setting the secret key)
     */
    public static function key(string $new_key): array {
        if (strlen($new_key) < 18) {
            return ['status' => false, 'message' => 'Key must be at least 18 characters long.', 'data' => null];
        }
        self::$secretKey = $new_key;
        return ['status' => true, 'message' => 'Key updated successfully.', 'data' => null];
    }

    /**
     * Rotate the secret key.
     * (Functionally same as key(), kept for architecture compatibility)
     */
    public static function rotate(string $newSecretKey): array {
        return self::key($newSecretKey);
    }

    /**
     * Set a new default algorithm for signing
     */
    public static function algorithm(string $newAlgorithm): array {
        if (!isset(self::$supportedAlgs[$newAlgorithm])) {
            return ['status' => false, 'message' => 'Unsupported algorithm', 'data' => null];
        }
        self::$defaultAlgorithm = $newAlgorithm;
        return ['status' => true, 'message' => 'Default algorithm updated successfully', 'data' => null];
    }

    /**
     * Create a JWT token with claims
     */
    public static function create(array $payload, int $expiresIn = 3600, ?string $algorithm = null): array {
        $algorithm = $algorithm ?? self::$defaultAlgorithm;

        if (!isset(self::$supportedAlgs[$algorithm])) {
            return ['status' => false, 'message' => 'Unsupported algorithm', 'data' => null];
        }

        // Security Check: Ensure secret key is set
        if (empty(self::$secretKey) || strlen(self::$secretKey) < 18) {
            return ['status' => false, 'message' => 'Encryption key is not set or too short. Call PHJT::key() first.', 'data' => null];
        }

        $header = [
            'alg' => $algorithm,
            'typ' => 'JWT',
        ];

        $payload['iat'] = time(); // Issued at
        $payload['exp'] = time() + $expiresIn; // Expiration time
        
        if(!isset($payload['jti'])){
            $payload['jti'] = bin2hex(random_bytes(16)); // Unique JWT ID
        }

        $jsonHeader = json_encode($header);
        $jsonPayload = json_encode($payload);

        if ($jsonHeader === false || $jsonPayload === false) {
            return ['status' => false, 'message' => 'JSON encoding failed.', 'data' => null];
        }

        $headerEncoded = self::base64UrlEncode($jsonHeader);
        $payloadEncoded = self::base64UrlEncode($jsonPayload);

        // Create Signature
        $signature = self::sign("$headerEncoded.$payloadEncoded", $algorithm);

        return ['status' => true, 'message' => 'Token created successfully', 'data' => "$headerEncoded.$payloadEncoded.$signature"];
    }

    /**
     * Sign the data
     */
    private static function sign(string $data, string $algorithm): string {
        return self::base64UrlEncode(hash_hmac(self::$supportedAlgs[$algorithm], $data, self::$secretKey, true));
    }

    /**
     * Verify and decode the JWT token
     */
    public static function verify(string $jwt, ?string $algorithm = null): array {
        $algorithm = $algorithm ?? self::$defaultAlgorithm;

        try {
            if (!isset(self::$supportedAlgs[$algorithm])) {
                return ['status' => false, 'message' => 'Unsupported algorithm', 'data' => null];
            }
            if (empty(self::$secretKey) || strlen(self::$secretKey) < 18) {
                return ['status' => false, 'message' => 'Verification key is not set or too short.', 'data' => null];
            }
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                return ['status' => false, 'message' => 'Invalid token format', 'data' => null];
            }

            [$headerEncoded, $payloadEncoded, $signatureProvided] = $parts;

            // 1. Verify Signature First
            $signatureGenerated = self::sign("$headerEncoded.$payloadEncoded", $algorithm);

            if (!hash_equals($signatureGenerated, $signatureProvided)) {
                return ['status' => false, 'message' => 'Signature verification failed', 'data' => null];
            }

            // 2. Decode Payload
            $payloadJson = self::base64UrlDecode($payloadEncoded);
            if ($payloadJson === null) {
                return ['status' => false, 'message' => 'Invalid payload encoding', 'data' => null];
            }
            $payload = json_decode($payloadJson, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
                return ['status' => false, 'message' => 'Invalid JSON payload', 'data' => null];
            }

            // 3. Verify Algorithm mismatch (Header vs Provided)
            $headerJson = self::base64UrlDecode($headerEncoded);
            if ($headerJson === null) {
                return ['status' => false, 'message' => 'Invalid header encoding', 'data' => null];
            }
            $header = json_decode($headerJson, true);
            if (!is_array($header) || ($header['alg'] ?? null) !== $algorithm) {
                 return ['status' => false, 'message' => 'Algorithm mismatch', 'data' => null];
            }

            // 4. Verify Expiration
            if (isset($payload['exp']) && (!is_numeric($payload['exp']) || time() >= (int) $payload['exp'])) {
                return ['status' => false, 'message' => 'Token has expired', 'data' => null];
            }
            if (isset($payload['nbf']) && (!is_numeric($payload['nbf']) || time() < (int) $payload['nbf'])) {
                return ['status' => false, 'message' => 'Token is not active yet', 'data' => null];
            }

            return ['status' => true, 'message' => 'Token is valid', 'data' => $payload];

        } catch (\Throwable $e) {
            return ['status' => false, 'message' => 'Verification error: ' . $e->getMessage(), 'data' => null];
        }
    }
}
?>
