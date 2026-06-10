<?php

/**
 * ============================================================================
 * Class: PHTP
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * This class is a core component of the MyStack framework.
 * It provides essential functionalities tailored for high performance and security.
 */



class PHTP {
    private static $base32Map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Base32 decode a given string.
     *
     * @param string $in The base32 encoded string.
     * @return string The decoded string.
     */
    private static function base32Decode($in) {
        $length = strlen($in);
        $n = $bitShift = 0;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $n = ($n << 5) + stripos(self::$base32Map, $in[$i]);
            $bitShift = ($bitShift + 5) % 8;
            if ($bitShift < 5) {
                $out .= chr(($n & (255 << $bitShift)) >> $bitShift);
            }
        }

        return $out;
    }

    /**
     * Generate a base32 encoded secret key.
     *
     * @param int $length The length of the secret (default is 24).
     * @param string $mode The mode, either 'TOTP' or 'OTP' (default is 'TOTP').
     * @return array The generated secret key or an error message.
     */
    public static function key($length = 24, $mode = 'TOTP') {
        if ($length < 16 || $length % 8 !== 0) {
            return ['status' => false, 'message' => 'Length must be a multiple of 8, and at least 16'];
        }

        $secret = '';
        while ($length--) {
            $random = @gettimeofday()['usec'] % 53;
            while ($random--) {
                mt_rand();
            }
            $secret .= self::$base32Map[mt_rand(0, 31)];
        }

        if (strtoupper($mode) === 'OTP') {
            $time = time();
            $secret .= dechex($time);
        }

        return ['status' => true, 'message' => 'Secret key generated successfully', 'data' => $secret];
    }

    /**
     * Generate a One-Time Password (OTP) or Time-based One-Time Password (TOTP).
     *
     * @param string $secret The base32 encoded secret key.
     * @param string $mode The mode, either 'TOTP' or 'OTP' (default is 'TOTP').
     * @param int $digits The number of digits in the OTP (default is 6).
     * @param int $time The time period in seconds (default is 30).
     * @param int $offset The time offset (default is 0).
     * @param string $algo The hash algorithm (default is 'sha1').
     * @return array The generated OTP or an error message in the required format.
     */
    public static function code($secret, $mode = 'TOTP', $digits = 6, $time = 30, $offset = 0, $algo = 'sha1') {
        if (strtoupper($mode) === 'TOTP') {
            if (strlen($secret) < 16 || strlen($secret) % 8 != 0) {
                return ['status' => false, 'message' => 'Length of secret must be a multiple of 8, and at least 16 characters'];
            } elseif (preg_match('/[^A-Z2-7]/i', $secret) === 1) {
                return ['status' => false, 'message' => 'Secret contains non-base32 characters'];
            }
        }

        $digits = intval($digits);
        if ($digits < 6 || $digits > 8) {
            return ['status' => false, 'message' => 'Digits must be 6, 7, or 8'];
        } elseif (!in_array(strtolower($algo), ['sha1', 'sha256', 'sha512'])) {
            return ['status' => false, 'message' => 'Algorithm must be SHA1, SHA256, or SHA512'];
        }
        
        if (strtoupper($mode) === 'TOTP') {
            $time = str_pad(pack('N', intval(($offset + time()) / $time)), 8, "\x00", STR_PAD_LEFT);
        } elseif (strtoupper($mode) === 'OTP') {
            $timePart = substr($secret, -8);
            $secret = substr($secret, 0, -8);
            $otpTimeINT = (int)hexdec($timePart);
            $otpTime = PHTM::setTime($otpTimeINT, 'Y-m-d H:i:s');
            $otpXPtime = PHTM::setTime($otpTimeINT + $time, 'Y-m-d H:i:s');
            $diff = PHTM::calculate(PHTM::getTime('Y-m-d H:i:s'), $otpXPtime);

            if ($diff['expire'] === true && $diff['expire'] !== false) {
                return ['status' => false, 'message' => 'OTP is expired'];
            } else {
                $time = str_pad(pack('N', intval($otpTime)), 8, "\x00", STR_PAD_LEFT);
            }
        }

        $seed = self::base32Decode($secret);
        $hash = hash_hmac(strtolower($algo), $time, $seed, false);
        $otp = (hexdec(substr($hash, hexdec($hash[-1]) * 2, 8)) & 0x7fffffff) % pow(10, $digits);

        return ['status' => true, 'message' => 'OTP generated successfully', 'data' => sprintf("%0{$digits}d", $otp)];
    }

    /**
     * Verify a One-Time Password (OTP) or Time-based One-Time Password (TOTP).
     *
     * @param string $otp The user-provided OTP.
     * @param string $secret The base32 encoded secret key.
     * @param string $mode The verification mode, either 'TOTP' or 'OTP' (default is 'TOTP').
     * @param int $digits The number of digits in the OTP (default is 6).
     * @param int $time The time period in seconds (default is 30, for TOTP).
     * @param int $offset The time offset for drift tolerance (default is 0, for TOTP).
     * @param string $algo The hash algorithm (default is 'sha1').
     * @return array The result of OTP verification.
     */
    public static function verify($otp, $secret, $mode = 'TOTP', $digits = 6, $time = 30, $offset = 0, $algo = 'sha1') {
        $generatedOtp = self::code($secret, $mode, $digits, $time, $offset, $algo);

        if ($generatedOtp['status'] === false) {
            return ['status' => false, 'message' => $generatedOtp['message']];
        }

        if ($generatedOtp['data'] === $otp) {
            return ['status' => true, 'message' => 'OTP is valid'];
        } else {
            return ['status' => false, 'message' => 'Invalid OTP'];
        }
    }

    /**
     * Generate a URI for the TOTP setup.
     *
     * @param string $account The account name.
     * @param string $secret The base32 encoded secret key.
     * @param int|null $digits The number of digits in the OTP.
     * @param int|null $time The time period in seconds.
     * @param string|null $issuer The issuer name.
     * @param string|null $algo The hash algorithm.
     * @return array The generated URI or an error message.
     */
    public static function url($account, $secret, $digits = null, $time = null, $issuer = null, $algo = null) {
        if (empty($account) || empty($secret)) {
            return ['status' => false, 'message' => 'You must provide at least an account and a secret'];
        } elseif (strpos($account . $issuer, ':') !== false) {
            return ['status' => false, 'message' => 'Neither account nor issuer can contain a colon (:) character'];
        }

        $account = rawurlencode($account);
        $issuer = rawurlencode($issuer);
        $label = empty($issuer) ? $account : "$issuer:$account";

        $uri = 'otpauth://totp/' . $label . "?secret=$secret" . (is_null($algo) ? '' : "&algorithm=$algo") .
                (is_null($digits) ? '' : "&digits=$digits") . (is_null($time) ? '' : "&period=$time") . (empty($issuer) ? '' : "&issuer=$issuer");

        return ['status' => true, 'message' => 'URI generated successfully', 'data' => $uri];
    }
}
?>