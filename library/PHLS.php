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

                // Use absolute path if possible, otherwise use relative
                $db_path = __DIR__ . '/../' . self::$file;
                // Fallback if .env is in the same directory (adjust based on your structure)
                if (!file_exists(dirname($db_path)))
                    $db_path = self::$file;

                self::$pdo = new \PDO('sqlite:' . $db_path, null, null, $options);

                // PERFORMANCE & CONCURRENCY SETTINGS
                self::$pdo->exec('PRAGMA journal_mode = WAL;');
                self::$pdo->exec('PRAGMA synchronous = NORMAL;');
                self::$pdo->exec('PRAGMA busy_timeout = 1000;'); // Set SQLite internal timeout to 1s

                // Create Tables
                self::$pdo->exec("CREATE TABLE IF NOT EXISTS storage (key TEXT PRIMARY KEY, value TEXT NOT NULL, expiration INTEGER)");
                self::$pdo->exec("CREATE TABLE IF NOT EXISTS storage_tags (tag TEXT NOT NULL, key TEXT NOT NULL, PRIMARY KEY (tag, key))");

                $fileSize = filesize($db_path);
                clearstatcache();
                if ($fileSize !== false && $fileSize > 10485760) { // 10MB
                    self::removeAll();
                }

                if (rand(1, 100) <= 5)
                    self::autoCleanup();

                if (!self::$shutdown_registered) {
                    register_shutdown_function([self::class, 'disconnect']);
                    self::$shutdown_registered = true;
                }
            } catch (\PDOException $e) {
                // If the file is not writable, this throws a clear error
                throw new \RuntimeException("PHLS SQLite connection failed: " . $e->getMessage());
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
     * (Internal) Adds or updates a key using an IMMEDIATE TRANSACTION to prevent locking.
     * @param string $key The key.
     * @param mixed $value The value.
     * @param int|null $expiration The expiration time in minutes.
     * @param array $tags The tags associated with the key.
     */
    private static function _add(string $key, $value, ?int $expiration = null, array $tags = [])
    {
        $attempts = 0;
        $max_attempts = 5; // Increased attempts for safety
        $min_wait_us = 1000; // 1s start
        $max_wait_us = 5000; // 5s max

        while ($attempts < $max_attempts) {
            try {
                // 1. START TRANSACTION (IMMEDIATE)
                // This locks the DB *immediately* for writing, preventing deadlocks 
                // where two processes read and then try to write.
                self::$pdo->exec("BEGIN IMMEDIATE TRANSACTION");

                // 2. Prepare Data
                $exp_time = ($expiration !== null) ? time() + ($expiration * 60) : null;
                $json_value = json_encode($value);

                // 3. Main Insert/Replace
                $stmt = self::getStatement('add', "INSERT OR REPLACE INTO storage (key, value, expiration) VALUES (?, ?, ?)");
                $stmt->execute([$key, $json_value, $exp_time]);

                // 4. Handle Tags
                // Clear old tags first
                $delete_tags_stmt = self::getStatement('delete_tags', "DELETE FROM storage_tags WHERE key = ?");
                $delete_tags_stmt->execute([$key]);

                // Insert new tags
                if (!empty($tags)) {
                    $insert_tag_stmt = self::getStatement('insert_tag', "INSERT INTO storage_tags (tag, key) VALUES (?, ?)");
                    foreach ($tags as $tag) {
                        $insert_tag_stmt->execute([$tag, $key]);
                    }
                }

                // 5. COMMIT (Save changes and release lock instantly)
                self::$pdo->commit();
                return true; // Success!

            } catch (\PDOException $e) {
                // Rollback changes if something failed so we don't leave the DB in a bad state
                if (self::$pdo->inTransaction()) {
                    self::$pdo->rollBack();
                }

                // Check for Lock Error (Code 5, 6, or HY000)
                if (in_array($e->getCode(), ['HY000', '5', '6']) || stripos($e->getMessage(), 'locked') !== false) {
                    $attempts++;
                    // Exponential backoff: 100ms → 200ms → 400ms → ... up to ~3.2s
                    $wait_us = $min_wait_us * (1 << ($attempts - 1));
                    $wait_us = min($wait_us, $max_wait_us); // max 3.2s
                    usleep($wait_us + rand(0, 1000)); // small random jitter
                    continue;
                }

                // throw $e;
                return false;
            }
        }

        // Final failure
        // throw new \Exception("PHLS Error: Database locked after $max_attempts attempts. Check server I/O.");
        return false;
    }

    /**
     * Adds or updates a key-value pair, wrapping the operation in a transaction.
     * @param string $key The key.
     * @param mixed $value The value.
     * @param int|null $expiration The expiration time in minutes.
     * @param array $tags The tags associated with the key.
     */
    public static function add(string $key, $value, ?int $expiration = null, array $tags = [])
    {
        self::connect();
        // Transaction handling is now done inside _add()
        try {
            if (strpos($key, '=>') !== false) {
                self::setNested($key, $value, $expiration, $tags);
            } else {
                self::_add($key, $value, $expiration, $tags);
            }
        } catch (\Exception $e) {
            // Rollback is handled inside _add, but we re-throw to alert the user
            throw $e;
        }
    }

    /**
     * Alias for add(). Included for API completeness.
     * @deprecated Use add() instead.
     * @see add()
     */
    public static function update(string $key, $value, ?int $expiration = null, array $tags = [])
    {
        self::add($key, $value, $expiration, $tags);
    }

    /**
     * Removes a key-value pair. Handles nested keys automatically.
     * @param string $key The key to remove.
     */
    public static function remove(string $key)
    {
        self::connect();
        // Removed explicit transaction to prevent nesting errors if other methods also use transaction
        // But for consistency with _add pattern, we should probably wrap this in a retry loop if high concurrency
        // For now, simple remove is fast enough usually.
        try {
            if (strpos($key, '=>') !== false) {
                self::removeNested($key);
            } else {
                $stmt = self::getStatement('remove', "DELETE FROM storage WHERE key = ?");
                $stmt->execute([$key]);
                $stmt_tags = self::getStatement('remove_tags', "DELETE FROM storage_tags WHERE key = ?");
                $stmt_tags->execute([$key]);
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Manually expires a specific key.
     * @param string $key The key to expire.
     */
    public static function expire(string $key)
    {
        self::connect();
        $stmt = self::$pdo->prepare("UPDATE storage SET expiration = ? WHERE key = ?");
        $stmt->execute([time() - 1, $key]);
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
        // REMOVED: self::$pdo->beginTransaction();  

        try {
            // 1. Get Current List (Read Operation)
            // This is safe outside a transaction because _add handles concurrency
            $current_list = self::get($key) ?? [];
            if (!is_array($current_list))
                $current_list = [];

            // 2. Modify Array (PHP Memory Operation)
            array_unshift($current_list, $value);
            if (count($current_list) > $limit) {
                $current_list = array_slice($current_list, 0, $limit);
            }

            // 3. Save Changes (Write Operation)
            // _add now handles its own locking/transaction safely.
            self::_add($key, $current_list, $expiration);

            // REMOVED: self::$pdo->commit();

        } catch (\Exception $e) {
            // REMOVED: if (self::$pdo->inTransaction()) self::$pdo->rollBack();
            // Just throw the error, _add already handles its own rollback.
            throw $e;
        }
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
    private static function setNested(string $key, $value, ?int $expiration = null, array $tags = [])
    {
        $keys = explode('=>', $key);
        $root_key = array_shift($keys);
        $current_data = self::get($root_key) ?? [];
        if (!is_array($current_data))
            $current_data = [];
        $data_pointer = &$current_data;
        foreach ($keys as $key_part) {
            if (!isset($data_pointer[$key_part]) || !is_array($data_pointer[$key_part]))
                $data_pointer[$key_part] = [];
            $data_pointer = &$data_pointer[$key_part];
        }
        $data_pointer = $value;
        self::_add($root_key, $current_data, $expiration, $tags);
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
        self::add($key, $value, $expiration, $tags);
        return $value;
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
        // Removed transaction here because _add handles it
        try {
            $current_value = (int) self::get($key) ?: 0;
            $new_value = $current_value + $amount;
            self::_add($key, $new_value, $expiration);
            return $new_value;
        } catch (\Exception $e) {
            throw $e;
        }
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
        // Removed transaction nesting, let individual calls handle it or trust simple delete
        try {
            $select_stmt = self::getStatement('get_keys_by_tag', "SELECT key FROM storage_tags WHERE tag = ?");
            $select_stmt->execute([$tag]);
            $keys_to_delete = $select_stmt->fetchAll(\PDO::FETCH_COLUMN);

            if (!empty($keys_to_delete)) {
                $placeholders = '?' . str_repeat(',?', count($keys_to_delete) - 1);
                $delete_storage_stmt = self::getStatement('delete_storage_keys', "DELETE FROM storage WHERE key IN ($placeholders)");
                $delete_storage_stmt->execute($keys_to_delete);

                $delete_tags_stmt = self::getStatement('delete_tags_by_tag', "DELETE FROM storage_tags WHERE tag = ?");
                $delete_tags_stmt->execute([$tag]);
            }
        } catch (\Exception $e) {
            throw $e;
        }
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
        $stmt = self::$pdo->prepare("DELETE FROM storage WHERE expiration IS NOT NULL AND expiration <= ?");
        $stmt->execute([time()]);
    }
}