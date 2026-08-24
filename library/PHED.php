<?php

/**
 * ============================================================================
 * Class: PHED
 * Title: Encryption & Key Management
 * ============================================================================
 * 
 * Secure, authenticated application encryption engine. Manages key lifecycles and provides foolproof encryption and decryption for sensitive payloads.
 * 
 * Features:
 * - Authenticated symmetric encryption.
 * - Secure key management and rotation.
 * - Tamper-proof payload verification.
 * 
 * Usage Example:
 * ```php
 * $encrypted = PHED::encrypt('sensitive_data');
 * $decrypted = PHED::decrypt($encrypted);
 * ```
 * 
 * @package    MyStack
 * @author     Sakibur Rahman (@sakibweb)
 * @published  https://github.com/mystack-framework
 * @web        https://mystack-framework.github.io
 * @license    Apache License 2.0
 * ============================================================================
 */



class PHED {

    private const FORMAT_V2 = "PHED2";

    /**
     * Default encryption key. This should be changed or set securely.
     * @var string
     */
    private static $key = "";

    /**
     * Encrypts the provided plaintext with a derived key and salt.
     * @param string $plaintext
     * @param string $key
     * @return array
     */
    private function encrypt_string($plaintext, $key) {
        try {
            // Generate a secure IV
            $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));

            // Encrypt the plaintext
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if ($ciphertext === false) {
                throw new Exception('Encryption failed.');
            }

            // Create HMAC for integrity check
            $hmac = hash_hmac('sha512', $iv . $ciphertext, $key, true);

            // Encode everything
            return [
                'status' => true,
                'message' => 'Encryption successful.',
                'data' => base64_encode($iv . $hmac . $ciphertext)
            ];
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => 'Encryption error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Decrypts the provided ciphertext with a derived key and salt.
     * @param string $ciphertext
     * @param string $key
     * @return array
     */
    private function decrypt_string($ciphertext, $key) {
        try {
            // Decode the base64 encoded ciphertext
            $decoded = base64_decode($ciphertext, true);
            $iv_length = openssl_cipher_iv_length('aes-256-cbc');
            if ($decoded === false || strlen($decoded) < $iv_length + 64 + 1) {
                throw new Exception('Invalid base64 string.');
            }

            // Extract IV, HMAC, and ciphertext components
            $iv = substr($decoded, 0, $iv_length);
            $hmac = substr($decoded, $iv_length, 64);
            $ciphertext = substr($decoded, $iv_length + 64);

            // Verify HMAC integrity
            $calculated_hmac = hash_hmac('sha512', $iv . $ciphertext, $key, true);
            if (!hash_equals($hmac, $calculated_hmac)) {
                throw new Exception("HMAC verification failed.");
            }

            // Decrypt the ciphertext
            $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if ($decrypted === false) {
                throw new Exception('Decryption failed.');
            }

            return ['status' => true, 'message' => 'Decryption successful.', 'data' => $decrypted];
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => 'Decryption error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Derives a secure encryption key from a given key and salt using PBKDF2.
     * @param string $key
     * @param string $salt
     * @return string|false
     */
    private function derive_key($key, $salt) {
        try {
            // Derive a secure key using PBKDF2
            return hash_pbkdf2('sha512', $key, $salt, 100000, 32, true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Encrypt using a versioned AEAD envelope while legacy CBC remains readable. */
    private function encrypt_v2(string $plaintext, string $key): array {
        try {
            if (!in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
                throw new \RuntimeException('AES-256-GCM is unavailable.');
            }
            $salt = random_bytes(16);
            $iv = random_bytes(12);
            $derivedKey = $this->derive_key($key, $salt);
            if ($derivedKey === false) throw new \RuntimeException('Key derivation failed.');
            $tag = '';
            $ciphertext = openssl_encrypt(
                $plaintext,
                'aes-256-gcm',
                $derivedKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                self::FORMAT_V2,
                16
            );
            if ($ciphertext === false || strlen($tag) !== 16) throw new \RuntimeException('Encryption failed.');
            return [
                'status' => true,
                'message' => 'Encryption successful.',
                'data' => base64_encode(self::FORMAT_V2 . $salt . $iv . $tag . $ciphertext),
            ];
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => 'Encryption error: ' . $e->getMessage(), 'data' => null];
        }
    }

    private function decrypt_v2(string $decoded, string $key): array {
        try {
            $minimum = strlen(self::FORMAT_V2) + 16 + 12 + 16;
            if (strlen($decoded) < $minimum || !str_starts_with($decoded, self::FORMAT_V2)) {
                throw new \RuntimeException('Invalid encrypted envelope.');
            }
            $offset = strlen(self::FORMAT_V2);
            $salt = substr($decoded, $offset, 16); $offset += 16;
            $iv = substr($decoded, $offset, 12); $offset += 12;
            $tag = substr($decoded, $offset, 16); $offset += 16;
            $ciphertext = substr($decoded, $offset);
            $derivedKey = $this->derive_key($key, $salt);
            if ($derivedKey === false) throw new \RuntimeException('Key derivation failed.');
            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $derivedKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                self::FORMAT_V2
            );
            if ($plaintext === false) throw new \RuntimeException('Authentication or decryption failed.');
            return ['status' => true, 'message' => 'Decryption successful.', 'data' => $plaintext];
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => 'Decryption error: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Encrypts or decrypts the string based on the provided action.
     * @param string $string
     * @param string $key
     * @param string $action
     * @return array
     */
    public function hide($string, $key, $action) {
        try {
            // Validate action type
            if (!in_array($action, ['encrypt', 'decrypt'])) {
                throw new Exception('Invalid action specified.');
            }

            if ($action === 'encrypt') {
                if (function_exists('openssl_get_cipher_methods') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
                    return $this->encrypt_v2((string) $string, (string) $key);
                }
                // Generate a secure salt
                $salt = random_bytes(16);

                // Derive encryption key
                $derived_key = $this->derive_key($key, $salt);
                if ($derived_key === false) {
                    throw new Exception('Key derivation failed.');
                }

                // Encrypt the string
                $encryption_result = $this->encrypt_string($string, $derived_key);
                if ($encryption_result['status'] === false) {
                    return $encryption_result;
                }

                // Prepend salt to encrypted data
                return [
                    'status' => true,
                    'message' => 'Encryption successful.',
                    'data' => base64_encode($salt . base64_decode($encryption_result['data']))
                ];
            } elseif ($action === 'decrypt') {
                // Decode the base64 string and extract salt
                $decoded = base64_decode($string, true);
                if ($decoded === false || strlen($decoded) < 17) {
                    throw new Exception('Invalid base64 string.');
                }

                if (str_starts_with($decoded, self::FORMAT_V2)) {
                    return $this->decrypt_v2($decoded, (string) $key);
                }

                $salt = substr($decoded, 0, 16);
                $encrypted_data = base64_encode(substr($decoded, 16));

                // Derive the encryption key from the salt
                $derived_key = $this->derive_key($key, $salt);

                // Decrypt the data
                return $this->decrypt_string($encrypted_data, $derived_key);
            }
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Public interface to encrypt or decrypt a string using the default key.
     * @param string $string The string to encrypt or decrypt.
     * @param string $action The action to perform ('en', 'de').
     * @return array The result with status, message, and data.
     */
    public static function make($string, $action) {
        try {
            $action = match (strtolower(trim((string) $action))) {
                'en', 'encode' => 'encrypt',
                'de', 'decode' => 'decrypt',
                default => strtolower(trim((string) $action)),
            };
            // Ensure key is securely set
            $key = self::$key;
            if (empty($key) || strlen($key) < 18) {
                throw new Exception('Encryption key must be at least 18 characters long.');
            }

            $phed = new self();

            // Evaluate security score
            $score = self::score();
            if ($score < 100) {
                throw new Exception("Security score too low: {$score}/100.");
            }

            return $phed->hide($string, $key, $action);
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Evaluates the security score based on key length, algorithm, and integrity measures.
     * @return int Security score out of 100.
     */
    public static function score() {
        $score = 100;

        // Check for secure key length
        if (strlen(self::$key) < 18) {
            $score -= 20;
        }

        // Check for secure cipher algorithm
        if (!function_exists('openssl_get_cipher_methods') ||
            (!in_array('aes-256-gcm', openssl_get_cipher_methods(), true) && !in_array('aes-256-cbc', openssl_get_cipher_methods(), true))) {
            $score -= 40;
        }

        // Ensure HMAC and PBKDF2 are used
        if (!function_exists('hash_hmac') || !function_exists('hash_pbkdf2')) {
            $score -= 40;
        }

        return $score;
    }

    /**
     * Updates the default encryption key.
     * @param string $new_key The new encryption key.
     * @return array
     */
    public static function key($new_key) {
        try {
            if (!empty($new_key) && strlen($new_key) >= 18) {
                self::$key = $new_key;
                return ['status' => true, 'message' => 'Key updated successfully.', 'data' => null];
            } else {
                throw new Exception('New key must be at least 18 characters long.');
            }
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }
}
?>
