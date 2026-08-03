<?php

/**
 * MyStack one-time-password and account-level Authenticator/2FA service.
 *
 * The original key(), code(), verify() and url() APIs remain compatible.
 * The enrollment APIs use PHED authenticated encryption and PHLS storage.
 */
class PHTP
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const RECORD_VERSION = 1;

    private static array $config = [
        'issuer' => 'MyStack',
        'encryption_key' => '',
        'digits' => 6,
        'period' => 30,
        'algorithm' => 'SHA1',
        'window' => 1,
        'secret_length' => 32,
        'recovery_codes' => 8,
        'recovery_length' => 10,
        'max_attempts' => 5,
        'lock_minutes' => 5,
        'pending_minutes' => 10,
    ];

    /** Configure the account-level Authenticator service. */
    public static function configure(array $config = []): array
    {
        $allowed = array_intersect_key($config, self::$config);
        $next = array_replace(self::$config, $allowed);

        $next['issuer'] = trim((string) $next['issuer']);
        $next['encryption_key'] = (string) $next['encryption_key'];
        $next['digits'] = (int) $next['digits'];
        $next['period'] = (int) $next['period'];
        $next['window'] = (int) $next['window'];
        $next['secret_length'] = (int) $next['secret_length'];
        $next['recovery_codes'] = (int) $next['recovery_codes'];
        $next['recovery_length'] = (int) $next['recovery_length'];
        $next['max_attempts'] = (int) $next['max_attempts'];
        $next['lock_minutes'] = (int) $next['lock_minutes'];
        $next['pending_minutes'] = (int) $next['pending_minutes'];
        $next['algorithm'] = strtoupper(trim((string) $next['algorithm']));

        if ($next['issuer'] === '' || str_contains($next['issuer'], ':')) {
            return self::fail('Issuer cannot be empty or contain a colon.');
        }
        if ($next['encryption_key'] !== '' && strlen($next['encryption_key']) < 18) {
            return self::fail('Authenticator encryption key must be at least 18 characters long.');
        }
        if (!in_array($next['digits'], [6, 7, 8], true)) {
            return self::fail('Authenticator digits must be 6, 7, or 8.');
        }
        if ($next['period'] < 15 || $next['period'] > 300) {
            return self::fail('Authenticator period must be between 15 and 300 seconds.');
        }
        if (!in_array($next['algorithm'], ['SHA1', 'SHA256', 'SHA512'], true)) {
            return self::fail('Authenticator algorithm must be SHA1, SHA256, or SHA512.');
        }
        if ($next['window'] < 0 || $next['window'] > 5) {
            return self::fail('Authenticator verification window must be between 0 and 5.');
        }
        if ($next['secret_length'] < 16 || $next['secret_length'] % 8 !== 0) {
            return self::fail('Authenticator secret length must be a multiple of 8 and at least 16.');
        }
        if ($next['recovery_codes'] < 1 || $next['recovery_codes'] > 20
            || $next['recovery_length'] < 8 || $next['recovery_length'] > 32) {
            return self::fail('Recovery-code configuration is outside the safe range.');
        }
        if ($next['max_attempts'] < 1 || $next['max_attempts'] > 100
            || $next['lock_minutes'] < 1 || $next['lock_minutes'] > 1440
            || $next['pending_minutes'] < 1 || $next['pending_minutes'] > 1440) {
            return self::fail('Authenticator attempt or expiry configuration is outside the safe range.');
        }

        self::$config = $next;
        return self::ok('Authenticator configured successfully.');
    }

    /** Generate a Base32 secret. */
    public static function key($length = 24, $mode = 'TOTP')
    {
        $length = (int) $length;
        $mode = strtoupper(trim((string) $mode));
        if ($length < 16 || $length % 8 !== 0) {
            return self::fail('Length must be a multiple of 8, and at least 16');
        }
        if (!in_array($mode, ['TOTP', 'OTP'], true)) {
            return self::fail('Mode must be TOTP or OTP');
        }

        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32[random_int(0, 31)];
        }
        if ($mode === 'OTP') {
            $secret .= str_pad(dechex(time()), 8, '0', STR_PAD_LEFT);
        }

        return self::ok('Secret key generated successfully', $secret);
    }

    /** Generate an OTP/TOTP code. Offset is expressed in seconds. */
    public static function code($secret, $mode = 'TOTP', $digits = 6, $time = 30, $offset = 0, $algo = 'sha1')
    {
        $mode = strtoupper(trim((string) $mode));
        $digits = (int) $digits;
        $time = (int) $time;
        $offset = (int) $offset;
        $algo = strtolower(trim((string) $algo));

        $valid = self::validateParameters((string) $secret, $mode, $digits, $time, $algo);
        if ($valid !== null) {
            return self::fail($valid);
        }

        if ($mode === 'TOTP') {
            $counter = intdiv(time() + $offset, $time);
            return self::codeForCounter((string) $secret, $counter, $digits, $algo);
        }

        $secret = (string) $secret;
        $createdHex = substr($secret, -8);
        $baseSecret = substr($secret, 0, -8);
        if (!preg_match('/^[a-f0-9]{8}$/i', $createdHex)) {
            return self::fail('Invalid OTP secret');
        }
        $createdAt = (int) hexdec($createdHex);
        if (time() > ($createdAt + $time)) {
            return self::fail('OTP is expired');
        }
        return self::codeForCounter($baseSecret, $createdAt, $digits, $algo);
    }

    /**
     * Verify an OTP/TOTP. The optional window checks adjacent TOTP periods.
     * Existing calls stay exact because the default window remains zero.
     */
    public static function verify($otp, $secret, $mode = 'TOTP', $digits = 6, $time = 30, $offset = 0, $algo = 'sha1', $window = 0)
    {
        $mode = strtoupper(trim((string) $mode));
        $digits = (int) $digits;
        $time = (int) $time;
        $window = max(0, min(5, (int) $window));
        $provided = trim((string) $otp);
        if (!preg_match('/^\d{' . $digits . '}$/D', $provided)) {
            return self::fail('Invalid OTP');
        }

        if ($mode !== 'TOTP') {
            $generated = self::code($secret, $mode, $digits, $time, $offset, $algo);
            return !empty($generated['status']) && hash_equals((string) $generated['data'], $provided)
                ? self::ok('OTP is valid')
                : self::fail($generated['message'] ?? 'Invalid OTP');
        }

        $valid = self::validateParameters((string) $secret, $mode, $digits, $time, strtolower((string) $algo));
        if ($valid !== null) {
            return self::fail($valid);
        }
        $baseCounter = intdiv(time() + (int) $offset, $time);
        for ($step = -$window; $step <= $window; $step++) {
            $generated = self::codeForCounter((string) $secret, $baseCounter + $step, $digits, strtolower((string) $algo));
            if (!empty($generated['status']) && hash_equals((string) $generated['data'], $provided)) {
                return self::ok('OTP is valid', ['counter' => $baseCounter + $step, 'drift' => $step]);
            }
        }
        return self::fail('Invalid OTP');
    }

    /** Build a standards-compatible otpauth URI. */
    public static function url($account, $secret, $digits = null, $time = null, $issuer = null, $algo = null)
    {
        $account = trim((string) $account);
        $secret = self::normalizeSecret((string) $secret);
        $issuer = is_null($issuer) ? '' : trim((string) $issuer);
        $digits = is_null($digits) ? 6 : (int) $digits;
        $time = is_null($time) ? 30 : (int) $time;
        $algo = is_null($algo) ? 'SHA1' : strtoupper(trim((string) $algo));

        if ($account === '' || $secret === '') {
            return self::fail('You must provide at least an account and a secret');
        }
        if (str_contains($account, ':') || str_contains($issuer, ':')) {
            return self::fail('Neither account nor issuer can contain a colon (:) character');
        }
        $valid = self::validateParameters($secret, 'TOTP', $digits, $time, strtolower($algo));
        if ($valid !== null) {
            return self::fail($valid);
        }

        $encodedAccount = rawurlencode($account);
        $encodedIssuer = rawurlencode($issuer);
        $label = $issuer === '' ? $encodedAccount : $encodedIssuer . ':' . $encodedAccount;
        $query = http_build_query([
            'secret' => $secret,
            'algorithm' => $algo,
            'digits' => $digits,
            'period' => $time,
        ], '', '&', PHP_QUERY_RFC3986);
        if ($issuer !== '') {
            $query .= '&issuer=' . $encodedIssuer;
        }

        return self::ok('URI generated successfully', 'otpauth://totp/' . $label . '?' . $query);
    }

    /** Begin enrollment or rotation without replacing an active factor yet. */
    public static function enroll(string|int $account, array $options = []): array
    {
        $account = self::normalizeAccount($account);
        if ($account === null) {
            return self::fail('A valid Authenticator account identifier is required.', null, 400);
        }
        $settings = self::settings($options);
        if (isset($settings['error'])) {
            return self::fail($settings['error'], null, 400);
        }

        $generated = self::key($settings['secret_length'], 'TOTP');
        if (empty($generated['status'])) {
            return $generated;
        }
        $secret = (string) $generated['data'];
        $encrypted = self::encryptSecret($secret, $settings['encryption_key']);
        if (empty($encrypted['status'])) {
            return self::fail($encrypted['message'], null, 500);
        }
        $uri = self::url($account, $secret, $settings['digits'], $settings['period'], $settings['issuer'], $settings['algorithm']);
        if (empty($uri['status'])) {
            return $uri;
        }
        [$recoveryCodes, $recoveryHashes] = self::makeRecoveryCodes($settings['recovery_codes'], $settings['recovery_length']);
        $record = [
            'version' => self::RECORD_VERSION,
            'secret' => $encrypted['data'],
            'digits' => $settings['digits'],
            'period' => $settings['period'],
            'algorithm' => $settings['algorithm'],
            'window' => $settings['window'],
            'recovery_hashes' => $recoveryHashes,
            'used_recovery_keys' => [],
            'created_at' => time(),
        ];

        try {
            $saved = PHLS::add(self::pendingKey($account), $record, $settings['pending_minutes'], ['phtp', 'phtp-pending']);
        } catch (\Throwable $e) {
            $saved = false;
        }
        if (!$saved) {
            return self::fail('Authenticator enrollment could not be stored safely. Please retry.', null, 503);
        }

        $qr = null;
        $warning = null;
        try {
            $qr = PHQR::make((string) $uri['data']);
        } catch (\Throwable $e) {
            $warning = 'QR image unavailable; use the setup URI or secret instead.';
        }

        return self::ok('Scan the QR code and confirm one Authenticator code.', [
            'account' => $account,
            'secret' => $secret,
            'uri' => $uri['data'],
            'qr' => $qr,
            'recovery_codes' => $recoveryCodes,
            'expires_in' => $settings['pending_minutes'] * 60,
            'warning' => $warning,
        ]);
    }

    /** Confirm a pending enrollment and atomically activate it for the account. */
    public static function confirm(string|int $account, string|int $code): array
    {
        $account = self::normalizeAccount($account);
        if ($account === null) {
            return self::fail('A valid Authenticator account identifier is required.', null, 400);
        }
        try {
            $record = PHLS::get(self::pendingKey($account));
        } catch (\Throwable $e) {
            return self::fail('Authenticator storage is temporarily unavailable.', null, 503);
        }
        if (!is_array($record)) {
            return self::fail('Authenticator enrollment is missing or expired.', null, 410);
        }
        $secret = self::decryptSecret((string) ($record['secret'] ?? ''), self::$config['encryption_key']);
        if (empty($secret['status'])) {
            return self::fail('Authenticator enrollment cannot be decrypted with the configured key.', null, 500);
        }
        $match = self::matchTotp((string) $code, (string) $secret['data'], $record);
        if ($match === null) {
            return self::fail('Invalid Authenticator code.', null, 401);
        }

        try {
            $claimed = PHLS::addIfAbsent(
                self::replayKey($account, $match),
                1,
                10,
                ['phtp', 'phtp-replay']
            );
        } catch (\Throwable $e) {
            $claimed = false;
        }
        if (!$claimed) {
            return self::fail('This Authenticator code was already used.', null, 409);
        }

        $record['enabled_at'] = time();
        $record['last_used_at'] = null;
        try {
            $saved = PHLS::add(self::recordKey($account), $record, null, ['phtp', 'phtp-active']);
            if ($saved) {
                PHLS::remove(self::pendingKey($account));
                PHLS::remove(self::attemptKey($account));
            }
        } catch (\Throwable $e) {
            $saved = false;
        }
        return $saved
            ? self::ok('Authenticator enabled successfully.', self::publicStatus($account, $record))
            : self::fail('Authenticator activation could not be stored safely.', null, 503);
    }

    /** Verify an Authenticator or one-time recovery code for an active account. */
    public static function authenticate(string|int $account, string|int $code): array
    {
        $account = self::normalizeAccount($account);
        if ($account === null) {
            return self::fail('Invalid account or verification code.', null, 401);
        }
        try {
            $attempt = PHLS::increment(self::attemptKey($account), 1, self::$config['lock_minutes']);
        } catch (\Throwable $e) {
            return self::fail('Authenticator protection is temporarily unavailable.', null, 503);
        }
        if ($attempt > self::$config['max_attempts']) {
            return self::fail('Too many Authenticator attempts. Try again later.', [
                'retry_after' => self::$config['lock_minutes'] * 60,
            ], 429);
        }

        try {
            $record = PHLS::get(self::recordKey($account));
        } catch (\Throwable $e) {
            return self::fail('Authenticator storage is temporarily unavailable.', null, 503);
        }
        if (!is_array($record) || empty($record['enabled_at'])) {
            return self::fail('Invalid account or verification code.', null, 401);
        }

        $provided = trim((string) $code);
        if (preg_match('/^\d{' . (int) ($record['digits'] ?? 6) . '}$/D', $provided)) {
            $secret = self::decryptSecret((string) ($record['secret'] ?? ''), self::$config['encryption_key']);
            if (empty($secret['status'])) {
                return self::fail('Authenticator secret cannot be decrypted with the configured key.', null, 500);
            }
            $counter = self::matchTotp($provided, (string) $secret['data'], $record);
            if ($counter !== null) {
                $replayKey = self::replayKey($account, $counter);
                try {
                    $claimed = PHLS::addIfAbsent($replayKey, 1, 10, ['phtp', 'phtp-replay']);
                } catch (\Throwable $e) {
                    $claimed = false;
                }
                if (!$claimed) {
                    return self::fail('This Authenticator code was already used.', null, 409);
                }
                $record['last_used_at'] = time();
                $record['last_counter'] = $counter;
                try {
                    PHLS::add(self::recordKey($account), $record, null, ['phtp', 'phtp-active']);
                    PHLS::remove(self::attemptKey($account));
                } catch (\Throwable $ignored) {
                }
                return self::ok('Authenticator verified successfully.', ['method' => 'totp']);
            }
        }

        $recovery = self::consumeRecoveryCode($account, $provided, $record);
        if (!empty($recovery['status'])) {
            try {
                PHLS::remove(self::attemptKey($account));
            } catch (\Throwable $ignored) {
            }
            return $recovery;
        }
        return self::fail('Invalid account or verification code.', null, 401);
    }

    /** Return non-sensitive enrollment status. */
    public static function status(string|int $account): array
    {
        $account = self::normalizeAccount($account);
        if ($account === null) {
            return self::fail('A valid Authenticator account identifier is required.', null, 400);
        }
        try {
            $record = PHLS::get(self::recordKey($account));
            $pending = PHLS::get(self::pendingKey($account));
        } catch (\Throwable $e) {
            return self::fail('Authenticator storage is temporarily unavailable.', null, 503);
        }
        if (!is_array($record)) {
            return self::ok('Authenticator is not enabled.', [
                'enabled' => false,
                'pending' => is_array($pending),
            ]);
        }
        return self::ok('Authenticator status loaded.', self::publicStatus($account, $record));
    }

    /** Replace recovery codes after proving possession of the current factor. */
    public static function recovery(string|int $account, string|int $currentCode): array
    {
        $verified = self::authenticate($account, $currentCode);
        if (empty($verified['status'])) {
            return $verified;
        }
        $normalized = self::normalizeAccount($account);
        try {
            $record = PHLS::get(self::recordKey((string) $normalized));
        } catch (\Throwable $e) {
            return self::fail('Authenticator storage is temporarily unavailable.', null, 503);
        }
        if (!is_array($record)) {
            return self::fail('Authenticator is not enabled.', null, 404);
        }
        [$codes, $hashes] = self::makeRecoveryCodes(self::$config['recovery_codes'], self::$config['recovery_length']);
        foreach ((array) ($record['used_recovery_keys'] ?? []) as $usedKey) {
            try {
                PHLS::remove((string) $usedKey);
            } catch (\Throwable $ignored) {
            }
        }
        $record['recovery_hashes'] = $hashes;
        $record['used_recovery_keys'] = [];
        $record['recovery_rotated_at'] = time();
        try {
            $saved = PHLS::add(self::recordKey((string) $normalized), $record, null, ['phtp', 'phtp-active']);
        } catch (\Throwable $e) {
            $saved = false;
        }
        return $saved
            ? self::ok('Recovery codes replaced successfully.', ['recovery_codes' => $codes])
            : self::fail('Recovery codes could not be stored safely.', null, 503);
    }

    /** Disable the factor. Force mode is intended only for an already-authorized administrator. */
    public static function disable(string|int $account, string|int|null $code = null, bool $force = false): array
    {
        $account = self::normalizeAccount($account);
        if ($account === null) {
            return self::fail('A valid Authenticator account identifier is required.', null, 400);
        }
        if (!$force) {
            if ($code === null || $code === '') {
                return self::fail('A current Authenticator or recovery code is required.', null, 401);
            }
            $verified = self::authenticate($account, $code);
            if (empty($verified['status'])) {
                return $verified;
            }
        }
        try {
            $record = PHLS::get(self::recordKey($account));
            foreach ((array) (($record['used_recovery_keys'] ?? [])) as $usedKey) {
                PHLS::remove((string) $usedKey);
            }
            PHLS::remove(self::recordKey($account));
            PHLS::remove(self::pendingKey($account));
            PHLS::remove(self::attemptKey($account));
        } catch (\Throwable $e) {
            return self::fail('Authenticator could not be disabled safely.', null, 503);
        }
        return self::ok('Authenticator disabled successfully.');
    }

    private static function validateParameters(string $secret, string $mode, int $digits, int $period, string $algorithm): ?string
    {
        if (!in_array($mode, ['TOTP', 'OTP'], true)) {
            return 'Mode must be TOTP or OTP';
        }
        if ($period <= 0) {
            return 'Time period must be greater than zero';
        }
        $baseSecret = $mode === 'OTP' ? substr($secret, 0, -8) : $secret;
        $baseSecret = self::normalizeSecret($baseSecret);
        if (strlen($baseSecret) < 16 || strlen($baseSecret) % 8 !== 0) {
            return 'Length of secret must be a multiple of 8, and at least 16 characters';
        }
        if (!preg_match('/^[A-Z2-7]+$/D', $baseSecret)) {
            return 'Secret contains non-base32 characters';
        }
        if (!in_array($digits, [6, 7, 8], true)) {
            return 'Digits must be 6, 7, or 8';
        }
        if (!in_array(strtolower($algorithm), ['sha1', 'sha256', 'sha512'], true)) {
            return 'Algorithm must be SHA1, SHA256, or SHA512';
        }
        return null;
    }

    private static function codeForCounter(string $secret, int $counter, int $digits, string $algorithm): array
    {
        if ($counter < 0) {
            return self::fail('Invalid OTP counter');
        }
        $decoded = self::base32Decode($secret);
        if ($decoded === null) {
            return self::fail('Invalid Base32 secret');
        }
        $high = intdiv($counter, 4294967296);
        $low = $counter % 4294967296;
        $binaryCounter = pack('N2', $high, $low);
        $hash = hash_hmac($algorithm, $binaryCounter, $decoded, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = unpack('N', substr($hash, $offset, 4));
        $value = ((int) ($binary[1] ?? 0)) & 0x7fffffff;
        $otp = $value % (10 ** $digits);
        return self::ok('OTP generated successfully', str_pad((string) $otp, $digits, '0', STR_PAD_LEFT));
    }

    private static function base32Decode(string $input): ?string
    {
        $input = self::normalizeSecret($input);
        if ($input === '' || !preg_match('/^[A-Z2-7]+$/D', $input)) {
            return null;
        }
        $buffer = 0;
        $bits = 0;
        $output = '';
        foreach (str_split($input) as $character) {
            $value = strpos(self::BASE32, $character);
            if ($value === false) {
                return null;
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            while ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xff);
                $buffer &= $bits === 0 ? 0 : ((1 << $bits) - 1);
            }
        }
        return $output;
    }

    private static function normalizeSecret(string $secret): string
    {
        return strtoupper((string) preg_replace('/[\s=-]+/', '', trim($secret)));
    }

    private static function normalizeAccount(string|int $account): ?string
    {
        $account = trim((string) $account);
        if ($account === '' || strlen($account) > 320 || preg_match('/[\x00-\x1F\x7F]/', $account)) {
            return null;
        }
        return $account;
    }

    private static function settings(array $options): array
    {
        $settings = array_replace(self::$config, array_intersect_key($options, self::$config));
        // The storage key is application-wide and cannot safely vary per account.
        $settings['encryption_key'] = self::$config['encryption_key'];
        $original = self::$config;
        $validation = self::configure($settings);
        self::$config = $original;
        if (empty($validation['status'])) {
            return ['error' => $validation['message']];
        }
        $settings['algorithm'] = strtoupper((string) $settings['algorithm']);
        foreach (['digits', 'period', 'window', 'secret_length', 'recovery_codes', 'recovery_length', 'pending_minutes'] as $key) {
            $settings[$key] = (int) $settings[$key];
        }
        return $settings;
    }

    private static function encryptSecret(string $secret, string $key): array
    {
        if ($key !== '') {
            $result = (new PHED())->hide($secret, $key, 'encrypt');
            return !empty($result['status'])
                ? self::ok('Secret encrypted.', 'configured:' . $result['data'])
                : self::fail((string) ($result['message'] ?? 'Secret encryption failed.'));
        }
        $result = PHED::make($secret, 'encrypt');
        return !empty($result['status'])
            ? self::ok('Secret encrypted.', 'global:' . $result['data'])
            : self::fail('Configure PHTP encryption_key or PHED::key() before enrollment.');
    }

    private static function decryptSecret(string $encrypted, string $key): array
    {
        if (str_starts_with($encrypted, 'configured:')) {
            if ($key === '') {
                return self::fail('Authenticator encryption key is not configured.');
            }
            return (new PHED())->hide(substr($encrypted, 11), $key, 'decrypt');
        }
        if (str_starts_with($encrypted, 'global:')) {
            return PHED::make(substr($encrypted, 7), 'decrypt');
        }
        return self::fail('Unsupported Authenticator secret format.');
    }

    private static function matchTotp(string $provided, string $secret, array $record): ?int
    {
        $digits = (int) ($record['digits'] ?? 6);
        if (!preg_match('/^\d{' . $digits . '}$/D', trim($provided))) {
            return null;
        }
        $period = max(1, (int) ($record['period'] ?? 30));
        $window = max(0, min(5, (int) ($record['window'] ?? 1)));
        $algorithm = strtolower((string) ($record['algorithm'] ?? 'sha1'));
        $baseCounter = intdiv(time(), $period);
        for ($step = -$window; $step <= $window; $step++) {
            $counter = $baseCounter + $step;
            $generated = self::codeForCounter($secret, $counter, $digits, $algorithm);
            if (!empty($generated['status']) && hash_equals((string) $generated['data'], trim($provided))) {
                return $counter;
            }
        }
        return null;
    }

    private static function makeRecoveryCodes(int $count, int $length): array
    {
        $codes = [];
        $hashes = [];
        while (count($codes) < $count) {
            $raw = '';
            for ($i = 0; $i < $length; $i++) {
                $raw .= self::BASE32[random_int(0, 31)];
            }
            $code = implode('-', str_split($raw, 5));
            if (in_array($code, $codes, true)) {
                continue;
            }
            $codes[] = $code;
            $hashes[] = password_hash($raw, PASSWORD_DEFAULT);
        }
        return [$codes, $hashes];
    }

    private static function consumeRecoveryCode(string $account, string $provided, array $record): array
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Z2-7]/i', '', $provided));
        if (strlen($normalized) < 8) {
            return self::fail('Invalid recovery code.');
        }
        foreach ((array) ($record['recovery_hashes'] ?? []) as $index => $hash) {
            if (!is_string($hash) || !password_verify($normalized, $hash)) {
                continue;
            }
            $consumeKey = self::recoveryUseKey($account, $hash);
            try {
                $claimed = PHLS::addIfAbsent($consumeKey, time(), null, ['phtp', 'phtp-recovery-used']);
            } catch (\Throwable $e) {
                $claimed = false;
            }
            if (!$claimed) {
                return self::fail('This recovery code was already used.', null, 409);
            }
            unset($record['recovery_hashes'][$index]);
            $record['recovery_hashes'] = array_values($record['recovery_hashes']);
            $record['used_recovery_keys'][] = $consumeKey;
            $record['used_recovery_keys'] = array_values(array_unique($record['used_recovery_keys']));
            $record['last_used_at'] = time();
            try {
                PHLS::add(self::recordKey($account), $record, null, ['phtp', 'phtp-active']);
            } catch (\Throwable $ignored) {
            }
            return self::ok('Recovery code verified successfully.', [
                'method' => 'recovery',
                'recovery_remaining' => self::remainingRecoveryCodes($account, $record),
            ]);
        }
        return self::fail('Invalid recovery code.');
    }

    private static function remainingRecoveryCodes(string $account, array $record): int
    {
        $remaining = 0;
        foreach ((array) ($record['recovery_hashes'] ?? []) as $hash) {
            try {
                if (PHLS::get(self::recoveryUseKey($account, (string) $hash)) === null) {
                    $remaining++;
                }
            } catch (\Throwable $e) {
                $remaining++;
            }
        }
        return $remaining;
    }

    private static function publicStatus(string $account, array $record): array
    {
        return [
            'enabled' => !empty($record['enabled_at']),
            'pending' => false,
            'enabled_at' => $record['enabled_at'] ?? null,
            'last_used_at' => $record['last_used_at'] ?? null,
            'algorithm' => $record['algorithm'] ?? 'SHA1',
            'digits' => (int) ($record['digits'] ?? 6),
            'period' => (int) ($record['period'] ?? 30),
            'recovery_remaining' => self::remainingRecoveryCodes($account, $record),
        ];
    }

    private static function recordKey(string $account): string
    {
        return 'phtp:account:' . hash('sha256', strtolower($account));
    }

    private static function pendingKey(string $account): string
    {
        return 'phtp:pending:' . hash('sha256', strtolower($account));
    }

    private static function attemptKey(string $account): string
    {
        return 'phtp:attempt:' . hash('sha256', strtolower($account));
    }

    private static function replayKey(string $account, int $counter): string
    {
        return 'phtp:used:' . hash('sha256', strtolower($account) . '|' . $counter);
    }

    private static function recoveryUseKey(string $account, string $hash): string
    {
        return 'phtp:recovery-used:' . hash('sha256', strtolower($account) . '|' . $hash);
    }

    private static function ok(string $message, mixed $data = null, int $code = 200): array
    {
        $result = ['status' => true, 'code' => $code, 'message' => $message];
        if ($data !== null) {
            $result['data'] = $data;
        }
        return $result;
    }

    private static function fail(string $message, mixed $data = null, int $code = 400): array
    {
        $result = ['status' => false, 'code' => $code, 'message' => $message];
        if ($data !== null) {
            $result['data'] = $data;
        }
        return $result;
    }
}
