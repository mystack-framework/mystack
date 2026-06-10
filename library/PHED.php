<?php

/**
 * ============================================================================
 * Class: PHED
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * This class is a core component of the MyStack framework.
 * It provides essential functionalities tailored for high performance and security.
 */



class PHED {

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
            $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
            if ($iv === false) {
                throw new Exception('IV generation failed.');
            }

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
        } catch (Exception $e) {
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
            $decoded = base64_decode($ciphertext);
            if ($decoded === false) {
                throw new Exception('Invalid base64 string.');
            }

            // Extract IV, HMAC, and ciphertext components
            $iv_length = openssl_cipher_iv_length('aes-256-cbc');
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
            return false;
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
                // Generate a secure salt
                $salt = openssl_random_pseudo_bytes(16);
                if ($salt === false) {
                    throw new Exception('Salt generation failed.');
                }

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
                $decoded = base64_decode($string);
                if ($decoded === false) {
                    throw new Exception('Invalid base64 string.');
                }

                $salt = substr($decoded, 0, 16);
                $encrypted_data = base64_encode(substr($decoded, 16));

                // Derive the encryption key from the salt
                $derived_key = $this->derive_key($key, $salt);

                // Decrypt the data
                return $this->decrypt_string($encrypted_data, $derived_key);
            }
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        if (!in_array('aes-256-cbc', openssl_get_cipher_methods())) {
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
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }
}
?>