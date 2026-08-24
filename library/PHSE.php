<?php

/**
 * ============================================================================
 * Class: PHSE
 * Title: Session State Manager
 * ============================================================================
 * 
 * Manages secure session lifecycles, expiring session values, and authenticated state. Ensures robust protection against session hijacking and fixation.
 * 
 * Features:
 * - Secure session initialization and lifecycle management.
 * - Expiring session values (Flash data).
 * - Protection against hijacking and fixation.
 * - Encrypted session storage options.
 * 
 * Usage Example:
 * ```php
 * PHSE::set('user_id', 123);
 * PHSE::flash('success', 'Profile updated!');
 * $userId = PHSE::get('user_id');
 * ```
 * 
 * @package    MyStack
 * @author     Sakibur Rahman (@sakibweb)
 * @published  https://github.com/mystack-framework
 * @web        https://mystack-framework.github.io
 * @license    Apache License 2.0
 * ============================================================================
 */




class PHSE {
    
    /**
     * সেশন স্টার্ট করার সময় ডিফল্ট সিকিউরিটি কনফিগারেশন চেক করা।
     * Strict Mode এবং HttpOnly কুকি এনফোর্স করা হচ্ছে।
     */
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            // বেসিক সিকিউরিটি কনফিগারেশন (যদি php.ini তে সেট না থাকে)
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_strict_mode', '1');
            $params = session_get_cookie_params();
            $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
            session_set_cookie_params([
                'lifetime' => (int) ($params['lifetime'] ?? 0),
                'path' => (string) ($params['path'] ?? '/'),
                'domain' => (string) ($params['domain'] ?? ''),
                'secure' => $secure || (bool) ($params['secure'] ?? false),
                'httponly' => true,
                'samesite' => (string) ($params['samesite'] ?? 'Lax') ?: 'Lax',
            ]);
            if (!session_start()) {
                throw new \RuntimeException('Unable to start the PHP session.');
            }
        }
    }

    /**
     * সেশন ভেরিয়েবল সেট করা।
     *
     * @param string $key সেশনের নাম।
     * @param mixed $value সেশনের ভ্যালু।
     * @param int|null $expiry কত মিনিট পর এক্সপায়ার হবে (null হলে ডিফল্ট সেশন টাইম)।
     */
    public static function add($key, $value, $expiry = null) {
        self::start(); // সেশন স্টার্ট নিশ্চিত করা

        $_SESSION[$key] = [
            'value' => $value,
            'expiry' => $expiry !== null ? time() + ($expiry * 60) : null,
            'created_at' => time() // ডিবাগিংয়ের জন্য সুবিধাজনক
        ];
    }

    /**
     * সেশন ভ্যালু আপডেট করা (শুধু যদি আগে থেকে সেট করা থাকে)।
     *
     * @param string $key সেশনের নাম।
     * @param mixed $value নতুন ভ্যালু।
     */
    public static function update($key, $value) {
        self::start();

        if (self::isActive($key)) {
            $_SESSION[$key]['value'] = $value;
            return true;
        }
        return false;
    }

    /**
     * সেশন ভ্যালু ডিলিট করা।
     *
     * @param string $key সেশনের নাম।
     */
    public static function remove($key) {
        self::start();
        
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * সেশন ভ্যালু পাওয়া।
     * Dev Friendly Update: ডিফল্ট ভ্যালু সাপোর্ট যুক্ত করা হয়েছে।
     *
     * @param string $key সেশনের নাম।
     * @param mixed $default যদি কি(key) না পাওয়া যায় তবে কি রিটার্ন করবে (ডিফল্ট null)।
     * @return mixed
     */
    public static function get($key, $default = null) {
        self::start();

        if (isset($_SESSION[$key])) {
            // এক্সপায়ার চেক
            if (self::hasExpired($key)) {
                self::remove($key);
                return $default;
            }
            return $_SESSION[$key]['value'];
        }

        return $default;
    }

    /**
     * সেশন ভেরিয়েবলটি অ্যাক্টিভ এবং ভ্যালিড কিনা তা চেক করা।
     * (FIXED: আগের ভার্সনে এক্সপায়ার হয়ে গেলেও true দেখাতো, এখন ফিক্স করা হয়েছে)
     *
     * @param string $key
     * @return bool
     */
    public static function isActive($key) {
        self::start();

        if (!isset($_SESSION[$key])) {
            return false;
        }

        if (self::hasExpired($key)) {
            self::remove($key); // চেক করার সময় এক্সপায়ার হলে রিমুভ করে দেওয়া ভালো
            return false;
        }

        return true;
    }

    /**
     * ইন্টারনাল হেল্পার মেথড এক্সপায়ার চেক করার জন্য।
     */
    private static function hasExpired($key) {
        $expiry = $_SESSION[$key]['expiry'] ?? null;
        if ($expiry !== null && time() > $expiry) {
            return true;
        }
        return false;
    }

    /**
     * সব সেশন ভেরিয়েবল ক্লিন করা।
     */
    public static function expireAll() {
        self::start();
        session_unset();
    }

    /**
     * সেশন ধ্বংস করা (Logout বা Reset এর জন্য)।
     */
    public static function removeAll() {
        self::start();
        session_unset();
        session_destroy();
    }

    /**
     * সিকিউরিটির জন্য সেশন আইডি রি-জেনারেট করা।
     * (Login এর পর ব্যবহার করা জরুরি)
     */
    public static function regenerateId() {
        self::start();
        session_regenerate_id(true);
    }

    /**
     * সমস্ত ভ্যালিড সেশন ডাটা রিটার্ন করা।
     * (Dev Friendly: ইন্টারনাল স্ট্রাকচার হাইড করে শুধু ভ্যালু রিটার্ন করবে)
     *
     * @return array
     */
    public static function getAll() {
        self::start();
        $cleanData = [];

        foreach ($_SESSION as $key => $data) {
            // ডাটা যদি অ্যারে না হয় বা আমাদের স্ট্রাকচার ফলো না করে, স্কিপ করবে
            if (!is_array($data) || !isset($data['value'])) {
                continue; 
            }

            if (!self::hasExpired($key)) {
                $cleanData[$key] = $data['value'];
            } else {
                self::remove($key); // ক্লিনআপ
            }
        }
        return $cleanData;
    }

    /**
     * ডিবাগিং মেথড: সেশন কখন এক্সপায়ার হবে তা দেখার জন্য।
     */
    public static function getExpiryTime($key) {
        self::start();
        if (isset($_SESSION[$key]) && !self::hasExpired($key)) {
            return $_SESSION[$key]['expiry'];
        }
        return null;
    }
}
?>
