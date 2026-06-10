<?php

/**
 * ============================================================================
 * Class: PHJT
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * This class is a core component of the MyStack framework.
 * It provides essential functionalities tailored for high performance and security.
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
    private static function base64UrlDecode(string $data): string {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
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
            $payload = json_decode($payloadJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['status' => false, 'message' => 'Invalid JSON payload', 'data' => null];
            }

            // 3. Verify Algorithm mismatch (Header vs Provided)
            $headerJson = self::base64UrlDecode($headerEncoded);
            $header = json_decode($headerJson, true);
            if (isset($header['alg']) && $header['alg'] !== $algorithm) {
                 return ['status' => false, 'message' => 'Algorithm mismatch', 'data' => null];
            }

            // 4. Verify Expiration
            if (isset($payload['exp']) && time() >= $payload['exp']) {
                return ['status' => false, 'message' => 'Token has expired', 'data' => null];
            }

            return ['status' => true, 'message' => 'Token is valid', 'data' => $payload];

        } catch (Exception $e) {
            return ['status' => false, 'message' => 'Verification error: ' . $e->getMessage(), 'data' => null];
        }
    }
}
?>