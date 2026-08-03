<?php

/**
 * ============================================================================
 * Class: PHLS
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * This class is a core component of the MyStack framework.
 * It provides essential functionalities tailored for high performance and security.
 */



class PHLS
{
    private static ?\PDO $pdo = null;
    private static string $file = '.env';
    private static array $stmt_cache = [];
    private static bool $shutdown_registered = false;
    private static bool $lock_warning_logged = false;
    private static bool $recovery_attempted = false;
    private const MAX_FILE_BYTES = 52428800; // 50 MB soft safety limit
    private const BUSY_TIMEOUT_MS = 5000;
    private const TRANSACTION_ATTEMPTS = 8;

    /**
     * Establishes a connection to the SQLite database and ensures the schema is up to date.
     */
    private static function connect()
    {
        if (self::$pdo === null) {
            try {
                $options = [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_TIMEOUT => 10, // Wait up to 10 seconds if locked
                    \PDO::ATTR_PERSISTENT => false // Ensure connections close properly
                ];

                $db_path = self::storagePath();
                $db_directory = dirname($db_path);
                if (!is_dir($db_directory) && !@mkdir($db_directory, 0750, true) && !is_dir($db_directory)) {
                    throw new \RuntimeException("PHLS storage directory could not be created.");
                }

                self::$pdo = new \PDO('sqlite:' . $db_path, null, null, $options);
                if (is_file($db_path) && PHP_OS_FAMILY !== 'Windows') {
                    @chmod($db_path, 0600);
                }

                // Apply the wait policy before any PRAGMA or schema inspection.
                self::$pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS . ';');
                self::initializeStorage($db_path);
                self::$pdo->exec('PRAGMA synchronous = NORMAL;');
                self::$pdo->exec('PRAGMA wal_autocheckpoint = 1000;');
                self::$pdo->exec('PRAGMA temp_store = MEMORY;');

                self::enforceSizeLimit($db_path);

                if (rand(1, 100) <= 5)
                    self::autoCleanup();

                if (!self::$shutdown_registered) {
                    register_shutdown_function([self::class, 'disconnect']);
                    self::$shutdown_registered = true;
                }
            } catch (\PDOException $e) {
                if (self::isCorruptionException($e) && !self::$recovery_attempted && self::recoverCorruptStorage($db_path)) {
                    self::$recovery_attempted = true;
                    self::connect();
                    return;
                }
                // If the file is not writable, this throws a clear error
                throw new \RuntimeException("PHLS SQLite connection failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Serializes first-connection WAL/schema setup across PHP workers.
     * Existing databases are inspected read-only and do not repeat DDL writes.
     */
    private static function initializeStorage(string $db_path): void
    {
        $lock_path = $db_path . '.init.lock';
        $lock_handle = @fopen($lock_path, 'c');
        if ($lock_handle === false) {
            throw new \RuntimeException('PHLS initialization lock could not be opened.');
        }

        try {
            if (!flock($lock_handle, LOCK_EX)) {
                throw new \RuntimeException('PHLS initialization lock could not be acquired.');
            }

            $journal_mode = strtolower((string) self::$pdo->query('PRAGMA journal_mode')->fetchColumn());
            if ($journal_mode !== 'wal') {
                self::$pdo->exec('PRAGMA journal_mode = WAL;');
            }

            $tables = self::$pdo
                ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('storage', 'storage_tags')")
                ->fetchAll(\PDO::FETCH_COLUMN);

            if (!in_array('storage', $tables, true)) {
                self::$pdo->exec(
                    "CREATE TABLE storage (key TEXT PRIMARY KEY, value TEXT NOT NULL, expiration INTEGER)"
                );
            }
            if (!in_array('storage_tags', $tables, true)) {
                self::$pdo->exec(
                    "CREATE TABLE storage_tags (tag TEXT NOT NULL, key TEXT NOT NULL, PRIMARY KEY (tag, key))"
                );
            }
        } finally {
            @flock($lock_handle, LOCK_UN);
            @fclose($lock_handle);
            if (is_file($lock_path)) {
                @chmod($lock_path, 0600);
            }
        }
    }

    /**
     * Closes the database connection. Intended for use with register_shutdown_function.
     */
    public static function disconnect()
    {
        self::$pdo = null;
        self::$stmt_cache = [];
    }

    /**
     * Sets the storage file path. Must be called before any other method.
     * @param string $path The file path.
     */
    public static function setFile(string $path)
    {
        if (self::$pdo !== null) {
            trigger_error("PHLS::setFile() must be called before any database connection is made.", E_USER_WARNING);
            return;
        }
        self::$file = $path;
    }

    /**
     * Returns a portable absolute storage path for relative, Unix, Windows and UNC paths.
     */
    private static function storagePath(): string
    {
        $path = trim(self::$file);
        if ($path === '') {
            $path = '.env';
        }

        $is_absolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        if ($is_absolute) {
            return $path;
        }

        return dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    /**
     * Checks storage availability and optionally verifies an actual write/read/delete cycle.
     * No existing application value is changed.
     */
    public static function checker(bool $write_test = false): array
    {
        $probe_key = 'phls:checker:' . bin2hex(random_bytes(8));
        $probe_value = ['nonce' => bin2hex(random_bytes(16)), 'time' => time()];

        try {
            self::connect();
            $path = self::storagePath();
            clearstatcache(true, $path);
            $quick_check = (string) self::$pdo->query('PRAGMA quick_check')->fetchColumn();
            $journal_mode = (string) self::$pdo->query('PRAGMA journal_mode')->fetchColumn();
            $write_ok = null;

            if ($write_test) {
                $write_ok = self::_add($probe_key, $probe_value, 1, ['phls-checker']);
                $write_ok = $write_ok && self::get($probe_key) === $probe_value;
                self::remove($probe_key);
            }

            $exists = is_file($path);
            $readable = $exists && is_readable($path);
            $writable = $exists ? is_writable($path) : is_writable(dirname($path));
            if ($exists && (!$readable || !$writable)) {
                $readHandle = @fopen($path, 'rb');
                if ($readHandle !== false) {
                    $readable = true;
                    @fclose($readHandle);
                }
                $writeHandle = @fopen($path, 'ab');
                if ($writeHandle !== false) {
                    $writable = true;
                    @fclose($writeHandle);
                }
            }
            $size = $exists ? (int) (@filesize($path) ?: 0) : 0;

            return [
                'status' => $quick_check === 'ok' && $writable && ($write_ok !== false),
                'driver' => 'sqlite',
                'scope' => 'single-host',
                'shared_state' => false,
                'file' => $path,
                'exists' => $exists,
                'readable' => $readable,
                'writable' => $writable,
                'integrity' => $quick_check,
                'journal_mode' => $journal_mode,
                'size' => $size,
                'limit' => self::MAX_FILE_BYTES,
                'write_test' => $write_ok,
            ];
        } catch (\Throwable $e) {
            try {
                if (self::$pdo !== null) {
                    self::remove($probe_key);
                }
            } catch (\Throwable $ignored) {
            }

            return [
                'status' => false,
                'driver' => 'sqlite',
                'scope' => 'single-host',
                'shared_state' => false,
                'file' => self::storagePath(),
                'error' => $e->getMessage(),
                'write_test' => false,
            ];
        }
    }

    /**
     * Cleans expired values when the storage reaches its soft limit.
     * Persistent values are never deleted automatically.
     */
    private static function enforceSizeLimit(string $path): void
    {
        clearstatcache(true, $path);
        $file_size = @filesize($path);
        if ($file_size === false || $file_size <= self::MAX_FILE_BYTES) {
            return;
        }

        self::autoCleanup();
        try {
            self::$pdo->exec('PRAGMA wal_checkpoint(PASSIVE)');
        } catch (\PDOException $ignored) {
        }

        clearstatcache(true, $path);
        $file_size = @filesize($path);
        if ($file_size !== false && $file_size > self::MAX_FILE_BYTES) {
            error_log('PHLS storage exceeded the 50 MB soft limit; persistent data was preserved.');
        }
    }

    /**
     * Runs a read-modify-write operation under one SQLite IMMEDIATE transaction.
     * Concurrent writers wait briefly and retry instead of losing updates.
     */
    private static function immediateTransaction(callable $callback, bool $fail_soft = false)
    {
        self::connect();
        $attempts = 0;

        while ($attempts < self::TRANSACTION_ATTEMPTS) {
            self::connect();
            $transaction_open = false;
            try {
                self::$pdo->exec('BEGIN IMMEDIATE TRANSACTION');
                $transaction_open = true;
                $result = $callback(self::$pdo);
                self::$pdo->exec('COMMIT');
                $transaction_open = false;
                return $result;
            } catch (\Throwable $e) {
                if ($transaction_open) {
                    try {
                        self::$pdo->exec('ROLLBACK');
                    } catch (\PDOException $ignored) {
                    }
                }

                if (self::isCorruptionException($e) && !self::$recovery_attempted) {
                    self::$recovery_attempted = true;
                    $recovered = self::recoverCorruptStorage(self::storagePath());
                    if (!$recovered) self::disconnect();
                    $attempts++;
                    continue;
                }

                if (!self::isLockException($e)) {
                    throw $e;
                }

                $attempts++;
                if ($attempts < self::TRANSACTION_ATTEMPTS) {
                    $wait_us = min(500000, 15000 * (1 << min($attempts, 5)));
                    usleep($wait_us + random_int(5000, 50000));
                }
            }
        }

        if ($fail_soft) {
            self::reportLockContention();
            return false;
        }

        throw new \RuntimeException('PHLS storage remained locked after retrying.');
    }

    /**
     * Detects SQLite lock/busy errors without hiding unrelated HY000 failures.
     */
    private static function isLockException(\Throwable $error): bool
    {
        if (!$error instanceof \PDOException) {
            return false;
        }

        $message = strtolower($error->getMessage());
        if (str_contains($message, 'locked') || str_contains($message, 'busy')) {
            return true;
        }

        $driver_code = $error->errorInfo[1] ?? null;
        return in_array((int) $driver_code, [5, 6, 261, 262, 517, 518], true)
            || in_array((string) $error->getCode(), ['5', '6'], true);
    }

    private static function isCorruptionException(\Throwable $error): bool
    {
        if (!$error instanceof \PDOException) return false;
        $message = strtolower($error->getMessage());
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        return str_contains($message, 'database disk image is malformed')
            || str_contains($message, 'database schema is malformed')
            || str_contains($message, 'not a database')
            || in_array($driverCode, [11, 26], true);
    }

    /** Salvages readable rows, removes the corrupt store, and recreates it cleanly. */
    private static function recoverCorruptStorage(string $path): bool
    {
        self::$pdo = null;
        self::$stmt_cache = [];
        if (!is_file($path)) return false;

        $storageRows = [];
        $tagRows = [];
        try {
            $source = new \PDO('sqlite:' . $path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 3,
            ]);
            foreach ($source->query('SELECT key, value, expiration FROM storage') as $row) {
                if (is_string($row['key'] ?? null) && $row['key'] !== '' && is_string($row['value'] ?? null)) {
                    $storageRows[] = [$row['key'], $row['value'], $row['expiration'] ?? null];
                }
            }
            try {
                foreach ($source->query('SELECT tag, key FROM storage_tags') as $row) {
                    if (is_string($row['tag'] ?? null) && is_string($row['key'] ?? null)) {
                        $tagRows[] = [$row['tag'], $row['key']];
                    }
                }
            } catch (\Throwable $ignored) {
            }
            $source = null;
        } catch (\Throwable $ignored) {
        }

        foreach ([$path, $path . '-wal', $path . '-shm'] as $oldFile) {
            if (is_file($oldFile)) @unlink($oldFile);
        }

        try {
            self::$pdo = new \PDO('sqlite:' . $path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 10,
            ]);
            self::$pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS . ';');
            self::$pdo->exec('PRAGMA journal_mode = WAL;');
            self::$pdo->exec('CREATE TABLE storage (key TEXT PRIMARY KEY, value TEXT NOT NULL, expiration INTEGER)');
            self::$pdo->exec('CREATE TABLE storage_tags (tag TEXT NOT NULL, key TEXT NOT NULL, PRIMARY KEY (tag, key))');
            $insert = self::$pdo->prepare('INSERT OR REPLACE INTO storage (key, value, expiration) VALUES (?, ?, ?)');
            foreach ($storageRows as $row) $insert->execute($row);
            $tagInsert = self::$pdo->prepare('INSERT OR IGNORE INTO storage_tags (tag, key) VALUES (?, ?)');
            foreach ($tagRows as $row) $tagInsert->execute($row);
            if (PHP_OS_FAMILY !== 'Windows') @chmod($path, 0600);
            error_log('PHLS SQLite corruption repaired; salvaged ' . count($storageRows) . ' storage row(s).');
            return true;
        } catch (\Throwable $error) {
            self::$pdo = null;
            error_log('PHLS SQLite recovery failed: ' . $error->getMessage());
            return false;
        }
    }

    /**
     * Reports prolonged contention once per PHP process without flooding logs.
     */
    private static function reportLockContention(): void
    {
        if (self::$lock_warning_logged) {
            return;
        }

        self::$lock_warning_logged = true;
        error_log('PHLS write contention exceeded the retry window; the non-critical operation was skipped.');
    }

    /**
     * (Internal) Adds or updates a key using an IMMEDIATE TRANSACTION to prevent locking.
     * @param string $key The key.
     * @param mixed $value The value.
     * @param int|null $expiration The expiration time in minutes.
     * @param array $tags The tags associated with the key.
     */
    private static function _add(string $key, $value, ?int $expiration = null, array $tags = [])
    {
        return self::immediateTransaction(function (\PDO $pdo) use ($key, $value, $expiration, $tags) {
            $exp_time = ($expiration !== null) ? time() + ($expiration * 60) : null;
            $json_value = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

            $stmt = $pdo->prepare(
                "INSERT OR REPLACE INTO storage (key, value, expiration) VALUES (?, ?, ?)"
            );
            $stmt->execute([$key, $json_value, $exp_time]);

            $delete_tags_stmt = $pdo->prepare("DELETE FROM storage_tags WHERE key = ?");
            $delete_tags_stmt->execute([$key]);

            if ($tags) {
                $insert_tag_stmt = $pdo->prepare(
                    "INSERT OR IGNORE INTO storage_tags (tag, key) VALUES (?, ?)"
                );
                foreach ($tags as $tag) {
                    $insert_tag_stmt->execute([(string) $tag, $key]);
                }
            }

            return true;
        }, true);
    }
    /**
     * Adds or updates a key-value pair, wrapping the operation in a transaction.
     * @param string $key The key.
     * @param mixed $value The value.
     * @param int|null $expiration The expiration time in minutes.
     * @param array $tags The tags associated with the key.
     */
    public static function add(string $key, $value, ?int $expiration = null, array $tags = []): bool
    {
        self::connect();
        // Transaction handling is now done inside _add()
        try {
            if (strpos($key, '=>') !== false) {
                return self::setNested($key, $value, $expiration, $tags);
            } else {
                return self::_add($key, $value, $expiration, $tags);
            }
        } catch (\Exception $e) {
            // Rollback is handled inside _add, but we re-throw to alert the user
            throw $e;
        }
    }

    /**
     * Stores a value only when the key does not already exist.
     * This is atomic across concurrent PHP requests.
     */
    public static function addIfAbsent(string $key, $value, ?int $expiration = null, array $tags = []): bool
    {
        self::connect();
        $json_value = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $exp_time = ($expiration !== null) ? time() + ($expiration * 60) : null;

        return self::immediateTransaction(function (\PDO $pdo) use ($key, $json_value, $exp_time, $tags) {
            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO storage (key, value, expiration) VALUES (?, ?, ?)'
            );
            $stmt->execute([$key, $json_value, $exp_time]);
            $inserted = $stmt->rowCount() === 1;

            if ($inserted && $tags) {
                $tag_stmt = $pdo->prepare(
                    'INSERT OR IGNORE INTO storage_tags (tag, key) VALUES (?, ?)'
                );
                foreach ($tags as $tag) {
                    $tag_stmt->execute([(string) $tag, $key]);
                }
            }

            return $inserted;
        }, true);
    }
    /**
     * Alias for add(). Included for API completeness.
     * @deprecated Use add() instead.
     * @see add()
     */
    public static function update(string $key, $value, ?int $expiration = null, array $tags = []): bool
    {
        return self::add($key, $value, $expiration, $tags);
    }

    /**
     * Removes a key-value pair. Handles nested keys automatically.
     * @param string $key The key to remove.
     */
    public static function remove(string $key)
    {
        self::connect();
        return self::immediateTransaction(function (\PDO $pdo) use ($key) {
            if (strpos($key, '=>') !== false) {
                $parts = explode('=>', $key);
                $root_key = array_shift($parts);
                $last_key = array_pop($parts);
                $select = $pdo->prepare(
                    "SELECT value, expiration FROM storage WHERE key = ? AND (expiration IS NULL OR expiration > ?)"
                );
                $select->execute([$root_key, time()]);
                $record = $select->fetch();
                $current = is_array($record) ? json_decode((string) $record['value'], true) : null;
                if (!is_array($current)) {
                    return true;
                }

                $pointer = &$current;
                foreach ($parts as $part) {
                    if (!isset($pointer[$part]) || !is_array($pointer[$part])) {
                        return true;
                    }
                    $pointer = &$pointer[$part];
                }
                unset($pointer[$last_key]);

                $save = $pdo->prepare(
                    "INSERT OR REPLACE INTO storage (key, value, expiration) VALUES (?, ?, ?)"
                );
                $save->execute([
                    $root_key,
                    json_encode($current, JSON_THROW_ON_ERROR),
                    $record['expiration'],
                ]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM storage WHERE key = ?");
                $stmt->execute([$key]);
                $stmt_tags = $pdo->prepare("DELETE FROM storage_tags WHERE key = ?");
                $stmt_tags->execute([$key]);
            }
            return true;
        });
    }

    /**
     * Manually expires a specific key.
     * @param string $key The key to expire.
     */
    public static function expire(string $key)
    {
        self::connect();
        return self::immediateTransaction(function (\PDO $pdo) use ($key) {
            $stmt = $pdo->prepare("UPDATE storage SET expiration = ? WHERE key = ?");
            $stmt->execute([time() - 1, $key]);
            return true;
        });
    }

    /**
     * Manually expires all keys.
     */
    public static function expireAllExpired()
    {
        self::connect();
        self::autoCleanup();
    }

    /**
     * Checks if a key exists and has expired.
     * @param string $key The key to check.
     * @return bool True if the key exists and is expired, false otherwise.
     */
    public static function isExpired(string $key): bool
    {
        self::connect();
        $stmt = self::$pdo->prepare("SELECT expiration FROM storage WHERE key = ?");
        $stmt->execute([$key]);
        $expiration = $stmt->fetchColumn();
        if ($expiration === false)
            return false;
        return ($expiration !== null && $expiration <= time());
    }

    /**
     * Gets details (value and expiration) of all expired keys.
     * @return array An array of expired keys with their values and expiration times.
     */
    public static function getExpiredDetails(): array
    {
        self::connect();
        $stmt = self::$pdo->prepare("SELECT key, value, expiration FROM storage WHERE expiration IS NOT NULL AND expiration <= ?");
        $stmt->execute([time()]);
        return self::decodeValues($stmt->fetchAll());
    }

    /**
     * Gets details (value and expiration) of all active (non-expired) keys.
     * @return array An array of active keys with their values and expiration times.
     */
    public static function getActiveDetails(): array
    {
        self::connect();
        $stmt = self::$pdo->prepare("SELECT key, value, expiration FROM storage WHERE expiration IS NULL OR expiration > ?");
        $stmt->execute([time()]);
        return self::decodeValues($stmt->fetchAll());
    }

    /**
     * Adds a value to an array, keeping the array size at a specified limit.
     * Note: This method works best on root keys for performance.
     * @param string $key The key holding the array.
     * @param mixed $value The new value to prepend.
     * @param int $limit The maximum size of the array.
     * @param int|null $expiration Expiration time for the entire array in minutes.
     */
    public static function limitizer(string $key, $value, int $limit = 20, ?int $expiration = null)
    {
        self::connect();
        return self::immediateTransaction(function (\PDO $pdo) use ($key, $value, $limit, $expiration) {
            $select = $pdo->prepare(
                "SELECT value FROM storage WHERE key = ? AND (expiration IS NULL OR expiration > ?)"
            );
            $select->execute([$key, time()]);
            $stored = $select->fetchColumn();
            $current_list = $stored !== false ? json_decode((string) $stored, true) : [];
            if (!is_array($current_list))
                $current_list = [];

            array_unshift($current_list, $value);
            if (count($current_list) > $limit) {
                $current_list = array_slice($current_list, 0, $limit);
            }

            $exp_time = ($expiration !== null) ? time() + ($expiration * 60) : null;
            $save = $pdo->prepare(
                "INSERT OR REPLACE INTO storage (key, value, expiration) VALUES (?, ?, ?)"
            );
            $save->execute([$key, json_encode($current_list, JSON_THROW_ON_ERROR), $exp_time]);
            return true;
        }, true);
    }

    /**
     * Caches and retrieves a prepared statement for performance.
     * @param string $name The unique name for the statement.
     * @param string $sql The SQL query.
     */
    private static function getStatement(string $name, string $sql): \PDOStatement
    {
        self::connect();
        if (!isset(self::$stmt_cache[$name])) {
            self::$stmt_cache[$name] = self::$pdo->prepare($sql);
        }
        return self::$stmt_cache[$name];
    }

    /**
     * Gets a nested value from a root key's data.
     * @return mixed|null The nested value or null if not found.
     */
    private static function getNested(string $key)
    {
        $keys = explode('=>', $key);
        $root_key = array_shift($keys);

        $data = self::get($root_key);

        if (!is_array($data))
            return null;

        foreach ($keys as $key_part) {
            if (is_array($data) && isset($data[$key_part])) {
                $data = $data[$key_part];
            } else {
                return null;
            }
        }
        return $data;
    }

    /**
     * Sets a nested value within a root key's data.
     * @param string $key The full nested key (e.g., "user=>settings=>theme").
     * @param mixed $value The value to set.
     * @param int|null $expiration The expiration time in minutes for the root key.
     * @param array $tags The tags associated with the root key.
     * @return void 
     */
    private static function setNested(string $key, $value, ?int $expiration = null, array $tags = []): bool
    {
        return self::immediateTransaction(function (\PDO $pdo) use ($key, $value, $expiration, $tags) {
            $keys = explode('=>', $key);
            $root_key = array_shift($keys);
            $select = $pdo->prepare(
                "SELECT value FROM storage WHERE key = ? AND (expiration IS NULL OR expiration > ?)"
            );
            $select->execute([$root_key, time()]);
            $stored = $select->fetchColumn();
            $current_data = $stored !== false ? json_decode((string) $stored, true) : [];
            if (!is_array($current_data))
                $current_data = [];

            $data_pointer = &$current_data;
            foreach ($keys as $key_part) {
                if (!isset($data_pointer[$key_part]) || !is_array($data_pointer[$key_part]))
                    $data_pointer[$key_part] = [];
                $data_pointer = &$data_pointer[$key_part];
            }
            $data_pointer = $value;

            $exp_time = ($expiration !== null) ? time() + ($expiration * 60) : null;
            $save = $pdo->prepare(
                "INSERT OR REPLACE INTO storage (key, value, expiration) VALUES (?, ?, ?)"
            );
            $save->execute([
                $root_key,
                json_encode($current_data, JSON_THROW_ON_ERROR),
                $exp_time,
            ]);

            $delete_tags = $pdo->prepare("DELETE FROM storage_tags WHERE key = ?");
            $delete_tags->execute([$root_key]);
            if ($tags) {
                $insert_tag = $pdo->prepare(
                    "INSERT OR IGNORE INTO storage_tags (tag, key) VALUES (?, ?)"
                );
                foreach ($tags as $tag) {
                    $insert_tag->execute([(string) $tag, $root_key]);
                }
            }
            return true;
        });
    }

    /**
     * Removes a nested key from a root key's data.
     * @param string $key The full nested key (e.g., "user=>settings=>theme").
     */
    private static function removeNested(string $key)
    {
        $keys = explode('=>', $key);
        $root_key = $keys[0];
        $current_data = self::get($root_key);
        if (!is_array($current_data))
            return;
        $data_pointer = &$current_data;
        $last_key = array_pop($keys);
        array_shift($keys);
        foreach ($keys as $key_part) {
            if (isset($data_pointer[$key_part]) && is_array($data_pointer[$key_part])) {
                $data_pointer = &$data_pointer[$key_part];
            } else {
                return;
            }
        }
        unset($data_pointer[$last_key]);
        self::_add($root_key, $current_data);
    }

    /**
     * Retrieves a value by its key. Handles nested keys automatically.
     * @param string $key The key to retrieve.
     * @return mixed|null The value or null if not found or expired.
     */
    public static function get(string $key)
    {
        if (strpos($key, '=>') !== false)
            return self::getNested($key);
        $stmt = self::getStatement('get', "SELECT value FROM storage WHERE key = ? AND (expiration IS NULL OR expiration > ?)");
        $stmt->execute([$key, time()]);
        $result = $stmt->fetchColumn();
        return ($result !== false) ? json_decode($result, true) : null;
    }

    /**
     * Retrieves all active (non-expired) key-value pairs.
     * @return array An associative array of all active key-value pairs.
     */
    public static function getAll(): array
    {
        self::connect();
        $stmt = self::$pdo->prepare("SELECT key, value FROM storage WHERE expiration IS NULL OR expiration > ?");
        $stmt->execute([time()]);
        $results = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach ($results as &$value) {
            $value = json_decode($value, true);
        }
        return $results;
    }

    /**
     * *** NEW: "Cache & Fetch" atomic operation. ***
     * Retrieves an item from the cache. If it doesn't exist, executes the callback,
     * stores the result in the cache, and returns it.
     *
     * @param string $key The cache key.
     * @param int $expiration Expiration time in minutes.
     * @param callable $callback The function to execute to generate the value.
     * @param array $tags Optional tags.
     * @return mixed The cached or newly generated value.
     */
    public static function remember(string $key, int $expiration, callable $callback, array $tags = [])
    {
        $value = self::get($key);
        if ($value !== null)
            return $value;

        $value = $callback();
        self::addIfAbsent($key, $value, $expiration, $tags);
        return self::get($key);
    }

    /**
     * Atomically increments a numeric value.
     * If the key does not exist, it will be created with the initial amount.
     * @param string $key The key.
     * @param int $amount The amount to increment by.
     * @return int The new value.
     */
    public static function increment(string $key, int $amount = 1, ?int $expiration = null): int
    {
        self::connect();
        return self::immediateTransaction(function (\PDO $pdo) use ($key, $amount, $expiration) {
            $select = $pdo->prepare(
                "SELECT value FROM storage WHERE key = ? AND (expiration IS NULL OR expiration > ?)"
            );
            $select->execute([$key, time()]);
            $stored = $select->fetchColumn();
            $current_value = $stored !== false ? (int) json_decode((string) $stored, true) : 0;
            $new_value = $current_value + $amount;

            $exp_time = ($expiration !== null) ? time() + ($expiration * 60) : null;
            $save = $pdo->prepare(
                "INSERT OR REPLACE INTO storage (key, value, expiration) VALUES (?, ?, ?)"
            );
            $save->execute([$key, json_encode($new_value, JSON_THROW_ON_ERROR), $exp_time]);
            return $new_value;
        });
    }

    /**
     * Atomically decrements a numeric value.
     * If the key does not exist, it will be created with the negative initial amount.
     * @param string $key The key.
     * @param int $amount The amount to decrement by.
     * @return int The new value.
     */
    public static function decrement(string $key, int $amount = 1, ?int $expiration = null): int
    {
        return self::increment($key, -$amount, $expiration);
    }

    /**
     * Decodes JSON values in a result set.
     * @param array $results The result set.
     * @return array The result set with decoded values.
     */
    private static function decodeValues(array $results): array
    {
        foreach ($results as &$row)
            $row['value'] = json_decode($row['value'], true);
        return $results;
    }

    /**
     * Flushes (removes) all cache entries associated with a given tag.
     * @param string $tag The tag to flush.
     * @return void
     * @throws \Exception If a database error occurs.
     */
    public static function flushByTag(string $tag)
    {
        self::connect();
        return self::immediateTransaction(function (\PDO $pdo) use ($tag) {
            $select_stmt = $pdo->prepare("SELECT key FROM storage_tags WHERE tag = ?");
            $select_stmt->execute([$tag]);
            $keys_to_delete = $select_stmt->fetchAll(\PDO::FETCH_COLUMN);

            if (!empty($keys_to_delete)) {
                $placeholders = '?' . str_repeat(',?', count($keys_to_delete) - 1);
                $delete_storage_stmt = $pdo->prepare("DELETE FROM storage WHERE key IN ($placeholders)");
                $delete_storage_stmt->execute($keys_to_delete);

                $delete_tags_stmt = $pdo->prepare("DELETE FROM storage_tags WHERE tag = ?");
                $delete_tags_stmt->execute([$tag]);
            }
            return true;
        });
    }

    /**
     * Removes all entries from the database. Use with caution!
     */
    public static function removeAll(bool $shrink = true)
    {
        self::connect();

        try {
            self::$pdo->exec("DELETE FROM storage");
            self::$pdo->exec("DELETE FROM storage_tags");

            if ($shrink) {
                self::$pdo->exec("VACUUM");
                clearstatcache();
            }
        } catch (\PDOException $e) {
            throw new \Exception("PHLS removeAll failed: " . $e->getMessage());
        }
    }

    /**
     * Deletes all expired entries from the database.
     */
    private static function autoCleanup()
    {
        if (self::$pdo === null)
            return;
        try {
            $stmt = self::$pdo->prepare("DELETE FROM storage WHERE expiration IS NOT NULL AND expiration <= ?");
            $stmt->execute([time()]);
            self::$pdo->exec("DELETE FROM storage_tags WHERE key NOT IN (SELECT key FROM storage)");
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'locked') === false && stripos($e->getMessage(), 'busy') === false) {
                throw $e;
            }
        }
    }
}
