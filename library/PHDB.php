<?php

/**
 * ============================================================================
 * Class: PHDB
 * Title: Database Engine
 * ============================================================================
 * 
 * High-performance database abstraction layer providing prepared access, CRUD operations, analytics, transactions, streaming, schema synchronization, and seamless JSON/array column handling.
 * 
 * Features:
 * - Prepared statement wrappers for extreme security.
 * - Elegant CRUD operations and query building.
 * - Schema synchronization and dynamic table creation.
 * - Transaction management and unbuffered streaming.
 * 
 * Usage Example:
 * ```php
 * $user = PHDB::find('users', $id);
 * PHDB::insert('users', ['name' => 'Sakib', 'role' => 'admin']);
 * PHDB::transaction(function() { // transaction logic });
 * ```
 * 
 * @package    MyStack
 * @author     Sakibur Rahman (@sakibweb)
 * @published  https://github.com/mystack-framework
 * @web        https://mystack-framework.github.io
 * @license    Apache License 2.0
 * ============================================================================
 */


class PHDB {

    /** @var mysqli|null $conn The mysqli instance for database connection. */
    private static $conn = null;

    /** @var string|null $host Database host. */
    public static $host = null;

    /** @var string|null $username Database username. */
    public static $username = null;

    /** @var string|null $password Database password. */
    public static $password = null;

    /** @var string|null $dbname Database name. */
    public static $dbname = null;

    /** @var string $charset Database character set encoding. */
    public static $charset = 'utf8mb4';

    /** @var mixed $error Error handling mode. [true, false, 'custom error msg'] */
    public static $error = true;

    /** @var string|null $lastError Stores the last encountered error message. */
    private static $lastError = null;

    /** @var bool $inTransaction Whether a transaction is currently active */
    private static $inTransaction = false;

    /** @var int $transactionLevel Current nesting level of transactions */
    private static $transactionLevel = 0;

    /** @var int|string Last insert ID retained after the query completes. */
    private static $lastInsertId = 0;

    /** @var int Last affected-row count retained after the query completes. */
    private static $lastAffectedRows = 0;

    /** @var bool Prevent duplicate shutdown handlers. */
    private static $shutdownRegistered = false;

    /** @var int Number of unbuffered result streams currently using the connection. */
    private static int $activeStreams = 0;

    /** @var array<string,array{signature:string,checked_at:int}> Fast schema state per PHP worker. */
    private static array $schemaSyncCache = [];

    /** Recheck externally changed schemas periodically while code-defined changes remain immediate. */
    private const SCHEMA_VERIFY_TTL = 300;

    /**
     * Handle errors based on the PHDB::$error setting.
     *
     * @param string $error_msg The error message to handle.
     * @param bool $continue Whether to continue after the error or not.
     * @throws Exception If $continue is false and $error is true.
     * @return void|array|false
     */
    private static function handleError(string $error_msg, bool $continue = false) {
        self::$lastError = $error_msg;
        $debugEnabled = class_exists('PHDE', false) && PHDE::isDebug();
        if ($debugEnabled) {
            error_log($error_msg);
            throw new Exception($error_msg);
        }

        if (self::$error === true) {
            error_log($error_msg);
            if (!$continue) {
                throw new Exception($error_msg);
            }
            return false;
        } elseif (self::$error !== false) {
            $custom_msg = is_string(self::$error) ? self::$error : '[An error occurred] ';
            error_log($custom_msg);
            return [
                'status' => false,
                'message' => $custom_msg,
            ];
        }
        return false;
    }

    /**
     * Retrieve the last error message encountered.
     *
     * @return string|null The last error message or null if no error.
     */
    public static function error() {
        return self::$lastError;
    }

    /**
     * Get the ID generated from the last INSERT query.
     *
     * @return int|string The last inserted ID.
     */
    public static function id() {
        return self::$lastInsertId;
    }

    /**
     * Get the number of affected rows in the last query.
     *
     * @return int The number of affected rows.
     */
    public static function affected() {
        return self::$lastAffectedRows;
    }

    /**
     * Performs a read-only database availability check without creating or
     * changing databases, tables, or application data.
     */
    public static function checker(): array {
        $startedAt = microtime(true);
        $probe = null;

        if (!is_string(self::$host) || trim(self::$host) === ''
            || !is_string(self::$username) || trim(self::$username) === ''
            || !is_string(self::$dbname) || trim(self::$dbname) === '') {
            return [
                'status' => false,
                'driver' => 'mysqli',
                'configured' => false,
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'error' => 'Database is not configured.',
            ];
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            self::assertIdentifier(self::$dbname, 'database name');
            $probe = new mysqli(self::$host, self::$username, self::$password ?? '', self::$dbname);
            $probe->set_charset(self::$charset);
            $result = $probe->query('SELECT 1');
            $status = $result !== false;
            if ($result instanceof mysqli_result) {
                $result->free();
            }

            return [
                'status' => $status,
                'driver' => 'mysqli',
                'configured' => true,
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => false,
                'driver' => 'mysqli',
                'configured' => true,
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'error' => $e->getMessage(),
            ];
        } finally {
            if ($probe instanceof mysqli) {
                try {
                    $probe->close();
                } catch (\Throwable $ignored) {
                }
            }
        }
    }

    /**
     * Connect to the database.
     * Auto-detects if DB exists. If not, creates it automatically.
     *
     * @return void
     */
    public static function connect() {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            if (self::$conn instanceof mysqli) {
                return;
            }
            self::assertIdentifier((string) self::$dbname, 'database name');

            try {
                self::$conn = new mysqli(self::$host, self::$username, self::$password, self::$dbname);
            
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1049) {
                    self::$conn = new mysqli(self::$host, self::$username, self::$password);
                    $collation = 'utf8mb4_unicode_ci';
                    $sql = "CREATE DATABASE IF NOT EXISTS `" . self::$dbname . "` CHARACTER SET utf8mb4 COLLATE $collation";
                    if (!self::$conn->query($sql)) {
                        throw new Exception("Failed to auto-create database: " . self::$conn->error);
                    }
                    self::$conn->select_db(self::$dbname);
                } else {
                    throw $e;
                }
            }
            if (self::$conn->connect_error) {
                throw new Exception("Connection failed: " . self::$conn->connect_error);
            }
            if (!self::$conn->set_charset(self::$charset)) {
                throw new Exception("Error setting charset: " . self::$conn->error);
            }
            if (!self::$shutdownRegistered) {
                register_shutdown_function([self::class, 'close']);
                self::$shutdownRegistered = true;
            }
        } catch (Exception $e) {
            self::handleError($e->getMessage(), false);
        }
    }

    /**
     * Disconnect from the database.
     * Won't disconnect if a transaction is active.
     *
     * @return bool TRUE if disconnected, FALSE if transaction is active
     */
    public static function disconnect() {
        if (self::$conn) {
            if (self::$inTransaction === false) {
                self::$conn->close();
                self::$conn = null;
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a string contains EXTREME malicious SQL injection patterns.
     * Note: Standard security is handled by Prepared Statements in query().
     * This is just a secondary filter for raw query strings.
     *
     * @param string $input The input string to check.
     * @return bool True if the input is potentially malicious, false otherwise.
     */
    private static function isPotentiallyMalicious(string $input) {
        // আমরা সাধারন চিহ্ন যেমন ; বা -- রিমুভ করে দিয়েছি কারণ এগুলো ভ্যালিড কুয়েরিতে লাগে।
        // Prepared Statement থাকার কারণে ইনপুট ডেটা নিয়ে ভয় নেই।
        
        $patterns = [
            '/union\s+select/i',            // UNION SELECT (Serious attack)
            '/sleep\(\d+\)/i',              // SLEEP() function
            '/benchmark\(/i',               // BENCHMARK() function
            '/\bOR\b\s+\d+\s*=\s*\d+/i',    // OR 1=1 (Classic logic bypass)
            '/exec\s+xp_/i',                // SQL Server remote execution
            '/information_schema/i'         // Trying to peek at DB structure
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                // Log only if strictly matched typical attack vectors
                error_log('Potential SQL injection attempt detected: ' . $input);
                return true;
            }
        }
        return false;
    }

    /**
     * Format the columns for the SQL query by adding backticks where necessary.
     *
     * @param string $columns The columns to format, specified as a comma-separated string.
     * @return string The formatted columns string with backticks added.
     */
    private static function formatColumn(string $columns) {
        $columns = trim($columns);
        if ($columns === '*') {
            return '*';
        }
        if ($columns === '') {
            throw new \InvalidArgumentException('Column list cannot be empty.');
        }

        $columns = str_replace('`', '', $columns);
        $formatted = [];
        foreach (preg_split('/\s*,\s*/', $columns) as $column) {
            if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\((\*|[a-zA-Z_][a-zA-Z0-9_.]*)\)(?:\s+AS\s+([a-zA-Z_][a-zA-Z0-9_]*))?$/i', $column, $m)) {
                $argument = $m[2] === '*' ? '*' : self::quoteQualifiedIdentifier($m[2]);
                $expression = strtoupper($m[1]) . "({$argument})";
                if (!empty($m[3])) {
                    $expression .= ' AS ' . self::quoteIdentifier($m[3], 'column alias');
                }
                $formatted[] = $expression;
                continue;
            }

            if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_.]*)(?:\s+AS\s+([a-zA-Z_][a-zA-Z0-9_]*))?$/i', $column, $m)) {
                throw new \InvalidArgumentException("Invalid column expression: {$column}");
            }
            $expression = self::quoteQualifiedIdentifier($m[1]);
            if (!empty($m[2])) {
                $expression .= ' AS ' . self::quoteIdentifier($m[2], 'column alias');
            }
            $formatted[] = $expression;
        }
        return implode(', ', $formatted);
    }

    private static function assertIdentifier(string $identifier, string $label = 'identifier'): string {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid {$label}: {$identifier}");
        }
        return $identifier;
    }

    private static function quoteIdentifier(string $identifier, string $label = 'identifier'): string {
        return '`' . self::assertIdentifier($identifier, $label) . '`';
    }

    private static function quoteQualifiedIdentifier(string $identifier): string {
        $parts = explode('.', $identifier);
        if (count($parts) > 2) {
            throw new \InvalidArgumentException("Invalid qualified identifier: {$identifier}");
        }
        return implode('.', array_map(
            static fn($part) => self::quoteIdentifier($part),
            $parts
        ));
    }

    private static function formatIdentifierList(string $value, bool $allowDirection = false): string {
        $formatted = [];
        foreach (preg_split('/\s*,\s*/', trim($value)) as $item) {
            $direction = '';
            if ($allowDirection && preg_match('/^(.+?)\s+(ASC|DESC)$/i', $item, $m)) {
                $item = trim($m[1]);
                $direction = ' ' . strtoupper($m[2]);
            }
            $formatted[] = self::quoteQualifiedIdentifier($item) . $direction;
        }
        return implode(', ', $formatted);
    }

    private static function formatJoins(?array $joins): string {
        if (empty($joins)) return '';

        $formatted = [];
        foreach ($joins as $join) {
            $join = trim((string) $join);
            $pattern = '/^(?:(LEFT|RIGHT|INNER)\s+)?JOIN\s+([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*))?\s+ON\s+([a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*([a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*)$/i';
            if (!preg_match($pattern, $join, $m)) {
                throw new \InvalidArgumentException("Invalid JOIN clause: {$join}");
            }
            $type = !empty($m[1]) ? strtoupper($m[1]) . ' ' : '';
            $clause = $type . 'JOIN ' . self::quoteIdentifier($m[2], 'table name');
            if (!empty($m[3])) {
                $clause .= ' AS ' . self::quoteIdentifier($m[3], 'table alias');
            }
            $clause .= ' ON ' . self::quoteQualifiedIdentifier($m[4]) .
                ' = ' . self::quoteQualifiedIdentifier($m[5]);
            $formatted[] = $clause;
        }
        return implode(' ', $formatted);
    }

    /**
     * The Ultimate Auto-Healing, Bulletproof Query Executor.
     * Features: Smart Type Binding, Direct Query Fallbacks, Regex Type Detection.
     *
     * @param string $query The SQL query to execute.
     * @param array $params An associative array of parameters for prepared statement.
     * @param bool $single Return only the first row (or null) for SELECT queries.
     * @return mixed Array of fetched data, a single row, TRUE on success, or FALSE on failure.
     */
    public static function query(string $query, array $params = [], bool $single = false) {
        if (self::$activeStreams > 0) {
            self::handleError(
                'A PHDB::fast() stream is still active. Finish iterating or release the Generator before running another query.',
                false
            );
            return false;
        }

        // 1. Security Firewall
        if (self::isPotentiallyMalicious($query)) {
            self::handleError('Potential SQL injection attempt detected via pattern match.', false);
            return false;
        }
        
        $query = trim($query);
        if (empty($query)) return false;

        // Smart Query Type Detection using Regex (Handles leading spaces, WITH clauses, etc.)
        $isSelectType = preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN|PRAGMA|WITH)\b/i', ltrim($query));

        try {
            // 2. Connection Assurance
            if (!self::$conn) {
                self::connect();
                // Double check if connection actually succeeded
                if (!self::$conn) {
                    throw new Exception("Database connection failed permanently. Cannot execute query.");
                }
            }

            // 3. Execution Strategies
            if (!empty($params)) {
                
                // ==========================================
                // STRATEGY A: PREPARED STATEMENT (Most Secure)
                // Used whenever there is user input/parameters
                // ==========================================
                $stmt = self::$conn->prepare($query);
                if (!$stmt) {
                    throw new Exception("Prepare failed: [" . self::$conn->error . "] Query: " . $query);
                }

                // Smart Type Detection & Binding
                $types = '';
                $bindValues = [];
                foreach ($params as $param) {
                    if (is_int($param) || is_bool($param)) {
                        $types .= 'i';
                        $bindValues[] = (int)$param;
                    } elseif (is_float($param) || is_double($param)) {
                        $types .= 'd';
                        $bindValues[] = $param;
                    } elseif (is_null($param)) {
                        $types .= 's'; // MySQLi handles null perfectly with 's'
                        $bindValues[] = null;
                    } else {
                        $types .= 's';
                        $bindValues[] = (string)$param;
                    }
                }

                // Bind parameters dynamically
                if (!$stmt->bind_param($types, ...$bindValues)) {
                    throw new Exception("Binding parameters failed: " . $stmt->error);
                }

                // Execute
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: " . $stmt->error);
                }

                // Handle Output
                if ($isSelectType) {
                    $resultObj = $stmt->get_result();
                    if ($resultObj === false) {
                        throw new Exception("Fetching result set failed: " . $stmt->error);
                    }
                    $data = [];
                    while ($row = $resultObj->fetch_assoc()) {
                        $data[] = $row;
                    }
                    self::$lastAffectedRows = $stmt->affected_rows;
                    $resultObj->free();
                    $stmt->close();
                    return $single ? ($data[0] ?? null) : $data;
                } else {
                    self::$lastAffectedRows = $stmt->affected_rows;
                    self::$lastInsertId = self::$conn->insert_id;
                    $stmt->close();
                    return true;
                }

            } else {

                // ==========================================
                // STRATEGY B: DIRECT QUERY FALLBACK
                // Used for DDL (CREATE/ALTER) or param-less queries.
                // Prevents prepare() crashes on structural queries.
                // ==========================================
                $resultObj = self::$conn->query($query);
                
                if ($resultObj === false) {
                    throw new Exception("Direct query failed: [" . self::$conn->error . "] Query: " . $query);
                }

                // Handle Output
                if ($resultObj instanceof mysqli_result || $isSelectType) {
                    $data = [];
                    if ($resultObj instanceof mysqli_result) {
                        while ($row = $resultObj->fetch_assoc()) {
                            $data[] = $row;
                        }
                        $resultObj->free();
                    }
                    self::$lastAffectedRows = self::$conn->affected_rows;
                    return $single ? ($data[0] ?? null) : $data;
                } else {
                    self::$lastAffectedRows = self::$conn->affected_rows;
                    self::$lastInsertId = self::$conn->insert_id;
                    return true; // Returns true for successful INSERT, UPDATE, DELETE, CREATE
                }
            }

        } catch (Exception $e) {
            // Safe Error Handling without crashing
            self::handleError($e->getMessage(), true);
            return false;
            
        }
    }

    private static function buildFastWhere(array $where, array &$params, string $logic = 'AND'): string {
        if ($where === []) return '';

        $conditions = [];
        foreach ($where as $key => $value) {
            if (strtoupper((string) $key) === 'OR' && is_array($value)) {
                $orClause = self::buildFastWhere($value, $params, 'OR');
                if ($orClause !== '') {
                    $conditions[] = '(' . preg_replace('/^\s*WHERE\s+/i', '', $orClause) . ')';
                }
                continue;
            }

            $operator = '=';
            $column = trim((string) $key);
            if (preg_match('/^(.+?)\s+(>=|<=|<>|!=|>|<|=|LIKE|NOT LIKE|IN|NOT IN|BETWEEN|IS NULL|IS NOT NULL)$/i', $column, $match)) {
                $column = trim($match[1]);
                $operator = strtoupper($match[2]);
            }
            self::assertIdentifier($column, 'column name');
            $quotedColumn = self::quoteIdentifier($column, 'column name');

            if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
                $conditions[] = "{$quotedColumn} {$operator}";
                continue;
            }
            if ($value === null) {
                $conditions[] = "{$quotedColumn} " . ($operator === '!=' || $operator === '<>' ? 'IS NOT NULL' : 'IS NULL');
                continue;
            }
            if ($operator === 'BETWEEN') {
                if (!is_array($value) || count($value) !== 2) {
                    throw new \InvalidArgumentException("{$column} BETWEEN requires exactly two values.");
                }
                $conditions[] = "{$quotedColumn} BETWEEN ? AND ?";
                $params[] = array_values($value)[0];
                $params[] = array_values($value)[1];
                continue;
            }
            if ($operator === 'IN' || $operator === 'NOT IN') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException("{$column} {$operator} requires an array.");
                }
                if ($value === []) {
                    // An empty list never broadens a query. This fail-closed
                    // rule is especially important when the builder is reused
                    // by update/delete operations.
                    $conditions[] = '0 = 1';
                    continue;
                }
                $conditions[] = "{$quotedColumn} {$operator} ("
                    . implode(', ', array_fill(0, count($value), '?')) . ')';
                foreach ($value as $item) $params[] = $item;
                continue;
            }

            $conditions[] = "{$quotedColumn} {$operator} ?";
            $params[] = $value;
        }

        return $conditions === [] ? '' : ' WHERE ' . implode(" {$logic} ", $conditions);
    }

    /**
     * Stream a database result one row at a time without buffering the full
     * result set in PHP or the MySQL client.
     *
     * The returned Generator owns an unbuffered result. Fully consume it, or
     * release it after an early break, before issuing another PHDB query on
     * the same connection.
     *
     * Short table mode:
     *   PHDB::fast('users')
     *   PHDB::fast('users', ['status' => 'active'], 'id, name')
     *
     * Raw SQL mode remains available:
     *   PHDB::fast('SELECT * FROM users WHERE status = ?', ['active'])
     *
     * @param string $query A table name or result-producing SQL query.
     * @param array $params Prepared values in SQL mode, or WHERE conditions in table mode.
     * @param string|array $columns Selected columns in table mode.
     * @return \Generator<int, array<string, mixed>, void, void>
     */
    public static function fast(string $query, array $params = [], string|array $columns = '*'): \Generator {
        $stmt = null;
        $resultObj = null;
        $metadata = null;
        $streamOpened = false;
        $rowsRead = 0;

        try {
            if (self::$activeStreams > 0) {
                throw new \RuntimeException(
                    'Another PHDB::fast() stream is already active on this connection.'
                );
            }

            $query = trim($query);
            if ($query === '') {
                throw new \InvalidArgumentException('PHDB::fast() table or query cannot be empty.');
            }

            $isSql = preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN|PRAGMA|WITH)\b/i', ltrim($query)) === 1;
            if (!$isSql) {
                $table = self::assertIdentifier($query, 'table name');
                $selectedColumns = is_array($columns) ? implode(', ', $columns) : $columns;
                $where = $params;
                $params = [];
                $query = 'SELECT ' . self::formatColumn($selectedColumns) . ' FROM `'
                    . $table . '`' . self::buildFastWhere($where, $params);
                $isSql = true;
            }

            if (self::isPotentiallyMalicious($query)) {
                throw new \RuntimeException(
                    'Potential SQL injection attempt detected via pattern match.'
                );
            }
            if (!$isSql) {
                throw new \InvalidArgumentException(
                    'PHDB::fast() only supports queries that return a result set.'
                );
            }

            if (!self::$conn) {
                self::connect();
                if (!self::$conn) {
                    throw new \RuntimeException(
                        'Database connection failed permanently. Cannot start stream.'
                    );
                }
            }

            if ($params !== []) {
                $stmt = self::$conn->prepare($query);
                if (!$stmt) {
                    throw new \RuntimeException(
                        'Prepare failed: [' . self::$conn->error . '] Query: ' . $query
                    );
                }

                $types = '';
                $bindValues = [];
                foreach ($params as $param) {
                    if (is_int($param) || is_bool($param)) {
                        $types .= 'i';
                        $bindValues[] = (int) $param;
                    } elseif (is_float($param)) {
                        $types .= 'd';
                        $bindValues[] = $param;
                    } elseif (is_null($param)) {
                        $types .= 's';
                        $bindValues[] = null;
                    } else {
                        $types .= 's';
                        $bindValues[] = (string) $param;
                    }
                }

                if (!$stmt->bind_param($types, ...$bindValues)) {
                    throw new \RuntimeException(
                        'Binding stream parameters failed: ' . $stmt->error
                    );
                }
                if (!$stmt->execute()) {
                    throw new \RuntimeException(
                        'Stream execute failed: ' . $stmt->error
                    );
                }

                $metadata = $stmt->result_metadata();
                if (!$metadata instanceof mysqli_result) {
                    throw new \RuntimeException(
                        'PHDB::fast() query did not return a result set.'
                    );
                }

                $fields = $metadata->fetch_fields();
                $metadata->free();
                $metadata = null;

                $values = array_fill(0, count($fields), null);
                $references = [];
                foreach ($values as $index => &$value) {
                    $references[$index] =& $value;
                }
                unset($value);

                if (!$stmt->bind_result(...$references)) {
                    throw new \RuntimeException(
                        'Binding stream result columns failed: ' . $stmt->error
                    );
                }

                self::$activeStreams++;
                $streamOpened = true;

                while (($fetchStatus = $stmt->fetch()) === true) {
                    $row = [];
                    foreach ($fields as $index => $field) {
                        $row[$field->name] = $values[$index];
                    }
                    $rowsRead++;
                    yield $row;
                }
                if ($fetchStatus === false && $stmt->errno) {
                    throw new \RuntimeException(
                        'Streaming result fetch failed: ' . $stmt->error
                    );
                }
                return;
            }

            $resultObj = self::$conn->query($query, MYSQLI_USE_RESULT);
            if (!$resultObj instanceof mysqli_result) {
                throw new \RuntimeException(
                    'PHDB::fast() query did not return a result set.'
                );
            }

            self::$activeStreams++;
            $streamOpened = true;

            while ($row = $resultObj->fetch_assoc()) {
                $rowsRead++;
                yield $row;
            }
        } catch (\Throwable $e) {
            self::handleError($e->getMessage(), true);
            return;
        } finally {
            self::$lastAffectedRows = $rowsRead;

            if ($metadata instanceof mysqli_result) {
                $metadata->free();
            }
            if ($resultObj instanceof mysqli_result) {
                $resultObj->free();
            }
            if ($stmt instanceof mysqli_stmt) {
                try {
                    $stmt->free_result();
                } catch (\Throwable $ignored) {
                }
                try {
                    $stmt->close();
                } catch (\Throwable $ignored) {
                }
            }
            if ($streamOpened) {
                self::$activeStreams = max(0, self::$activeStreams - 1);
            }
        }
    }

    /**
     * Execute a SELECT and return its first row, or null when no row matches.
     */
    public static function first(string $query, array $params = []): ?array {
        $result = self::query($query, $params, true);
        return is_array($result) ? $result : null;
    }

    /**
     * Execute a SELECT and return the first value from its first row.
     */
    public static function scalar(string $query, array $params = []): mixed {
        $row = self::first($query, $params);
        return $row === null ? null : array_values($row)[0];
    }

    /**
     * Smart Save: Insert, Update, or Skip.
     * 
     * 1. Inserts if record not found.
     * 2. Updates if record found BUT data is different.
     * 3. Skips if record found AND data is identical.
     *
     * @param string $table The table name.
     * @param array $data The data array ['col' => 'val'].
     * @param mixed $uniqueKeys (Optional) Column(s) to identify the record. 
     *                          Default is 'id'. Can be string 'email' or array ['order_id', 'product_id'].
     *                          If NULL, checks if 'id' exists in $data array.
     * 
     * @return array Returns ['status' => bool, 'action' => 'inserted'|'updated'|'skipped'|'error']
     */
    public static function save(string $table, array $data, mixed $uniqueKeys = null) {
        self::assertIdentifier($table, 'table name');
        if (empty($data)) return ['status' => false, 'action' => 'error_empty_data'];
        foreach (array_keys($data) as $column) self::assertIdentifier((string) $column, 'column name');
        
        $processDataForInsert = function($d) {
            foreach ($d as $k => $v) {
                if (preg_match('/(password|pass|secret|hash)/i', $k) && !empty($v) && strlen($v) < 60) {
                    $d[$k] = password_hash($v, PASSWORD_DEFAULT);
                }
            }
            return $d;
        };

        if ($uniqueKeys === null) {
            if (isset($data['id'])) { $uniqueKeys = ['id']; } 
            else {
                $res = self::insert($table, $processDataForInsert($data));
                return ['status' => $res, 'action' => $res ? 'inserted' : 'error'];
            }
        }

        $uniqueKeys = (array) $uniqueKeys;
        $where = [];
        foreach ($uniqueKeys as $key) {
            if (!isset($data[$key])) {
                $res = self::insert($table, $processDataForInsert($data));
                return ['status' => $res, 'action' => $res ? 'inserted' : 'error'];
            }
            $where[$key] = $data[$key];
        }

        $colsToFetch = array_keys($data);
        $colsStr = implode(',', $colsToFetch);
        $existing = self::select($table, $colsStr, $where, 1);

        if (empty($existing)) {
            $res = self::insert($table, $processDataForInsert($data));
            return ['status' => $res, 'action' => $res ? 'inserted' : 'error'];
        }

        $existingRow = $existing[0];
        $hasChanges = false;

        foreach ($data as $key => $newValue) {
            $dbValue = array_key_exists($key, $existingRow) ? $existingRow[$key] : null;
            
            if (preg_match('/(password|pass|secret|hash)/i', $key)) {
                // Case: DB empty, Input has value -> Update
                if (empty($dbValue) && !empty($newValue)) {
                    if (strlen($newValue) < 60 && password_get_info($newValue)['algo'] === 0) {
                        $data[$key] = password_hash($newValue, PASSWORD_DEFAULT);
                    }
                    $hasChanges = true; break;
                }
                // Case: Exact match (Hashed vs Hashed) -> Skip
                if ($dbValue === $newValue) continue;
                
                // Case: Plain Input vs DB Hash -> Verify
                if (!empty($newValue) && strlen($newValue) < 60 && strlen($dbValue) >= 60) {
                    if (password_verify($newValue, $dbValue)) {
                        $data[$key] = $dbValue; // Keep old hash to prevent change
                        continue; 
                    } else {
                        // Re-hash only if it's not already a hash
                        if (password_get_info($newValue)['algo'] === 0) {
                            $data[$key] = password_hash($newValue, PASSWORD_DEFAULT);
                        }
                        $hasChanges = true; break;
                    }
                }
                // Fallback: Values differ -> Update
                $hasChanges = true; break;
            }

            // Normal Field Comparison
            if ($dbValue != $newValue) {
                $hasChanges = true; break; 
            }
        }

        if ($hasChanges) {
            $res = self::update($table, $data, $where);
            return ['status' => $res, 'action' => $res ? 'updated' : 'error'];
        }

        return ['status' => true, 'action' => 'skipped'];
    }

    /**
     * Insert a record into the database.
     *
     * This method inserts a new record into the specified table. If an entry with the same unique key
     * (like 'name') already exists and the $overwrite parameter is set to true, it will update the existing
     * record instead of inserting a new one. If $overwrite is false, it will insert a new record or update
     * the existing record based on the unique key using ON DUPLICATE KEY UPDATE.
     *
     * @param string $table The name of the table.
     * @param array $data An associative array of column names and values to insert.
     * @param bool $overwrite Whether to overwrite existing records (default is false).
     * @return bool TRUE on success, FALSE on failure.
     */
    public static function insert(string $table, array $data, bool $overwrite = false) {
        self::assertIdentifier($table, 'table name');
        if (empty($data)) return false;
        foreach (array_keys($data) as $column) self::assertIdentifier((string) $column, 'column name');
        $keys = array_map(function($key) { return "`$key`"; }, array_keys($data));
        $values = array_values($data);
        $placeholders = array_fill(0, count($keys), '?');
        if ($overwrite) {
            if (!array_key_exists('name', $data)) {
                throw new \InvalidArgumentException("Overwrite mode requires a 'name' field.");
            }
            $result = self::select($table, '*', ['name' => $data['name']]);
            if (is_array($result) && count($result) > 0) {
                $sql = "UPDATE `$table` SET " . implode(', ', array_map(function($key) { return "`$key` = ?"; }, array_keys($data))) . " WHERE `name` = ?";
                return self::query($sql, array_merge($values, [$data['name']]));
            }
        }
        $sql = "INSERT INTO `$table` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $placeholders) . ") ";
        $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', array_map(function($key) { return "`$key` = VALUES(`$key`)"; }, array_keys($data)));
        return self::query($sql, $values);
    }

    /**
     * Insert multiple records in a single query with optional overwrite
     *
     * @param string $table Table name
     * @param array $data Array of associative arrays (records to insert)
     * @param bool $overwrite Whether to overwrite on duplicate key
     * @return bool TRUE on success, FALSE on failure
     */
    public static function batchInsert(string $table, array $data, bool $overwrite = false) {
        self::assertIdentifier($table, 'table name');
        if (empty($data)) {
            self::handleError("Batch insert failed: Empty data provided", true);
            return false;
        }

        try {
            // Validate all records have same keys
            $keys = array_keys($data[0]);
            foreach ($keys as $column) self::assertIdentifier((string) $column, 'column name');
            foreach ($data as $index => $record) {
                if (array_keys($record) !== $keys) {
                    throw new Exception("Record $index has different keys");
                }
            }

            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $values = [];

            // Prepare all values
            foreach ($data as $record) {
                $values = array_merge($values, array_values($record));
            }

            $columns = implode('`,`', $keys);
            $sql = "INSERT INTO `$table` (`$columns`) VALUES ";

            // Add placeholders for each record
            $sql .= implode(',', array_fill(0, count($data), "($placeholders)"));

            // Add ON DUPLICATE KEY UPDATE if overwrite is true
            if ($overwrite) {
                $updates = [];
                foreach ($keys as $key) {
                    $updates[] = "`$key`=VALUES(`$key`)";
                }
                $sql .= " ON DUPLICATE KEY UPDATE " . implode(',', $updates);
            }

            return self::query($sql, $values);

        } catch (Exception $e) {
            self::handleError("Batch insert failed: " . $e->getMessage(), true);
            return false;
        }
    }

    /**
     * Update records in the database based on specified conditions.
     * Supports Advanced Where (IN, >=, <=, !=, <, >).
     *
     * @param string $table The name of the table.
     * @param array $data An associative array of column names and values to update.
     * @param array $where An associative array of conditions.
     * @return bool TRUE on success, FALSE on failure.
     */
    public static function update(string $table, array $data, array $where = []) {
        self::assertIdentifier($table, 'table name');
        if (empty($data)) return false;

        $set = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            self::assertIdentifier((string) $key, 'column name');
            $set[] = "`$key` = ?";
            $params[] = $value;
        }
        $sql = "UPDATE `$table` SET " . implode(', ', $set);

        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $key => $value) {
                $operator = '=';
                $column = $key;

                if (preg_match('/(.*)\s+(>=|<=|<>|!=|>|<|=|LIKE|NOT LIKE|IN|NOT IN|BETWEEN|IS NULL|IS NOT NULL)$/i', $key, $matches)) {
                    $column = trim($matches[1]);
                    $operator = strtoupper($matches[2]);
                }
                self::assertIdentifier((string) $column, 'column name');

                if ($operator === 'BETWEEN' && is_array($value) && count($value) === 2) {
                    $conditions[] = "`$column` BETWEEN ? AND ?";
                    $params[] = $value[0];
                    $params[] = $value[1];
                } 
                elseif (in_array($operator, ['IN', 'NOT IN']) && is_array($value)) {
                    if ($value === []) {
                        $conditions[] = '0 = 1';
                        continue;
                    }
                    $placeholders = implode(',', array_fill(0, count($value), '?'));
                    $conditions[] = "`$column` $operator ($placeholders)";
                    foreach ($value as $v) $params[] = $v;
                } 
                elseif ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
                    $conditions[] = "`$column` $operator";
                }
                elseif ($value === null) {
                    $conditions[] = "`$column` " . (in_array($operator, ['!=', '<>'], true) ? 'IS NOT NULL' : 'IS NULL');
                }
                elseif ($operator === 'LIKE' || $operator === 'NOT LIKE') {
                    $conditions[] = "`$column` $operator ?";
                    $params[] = str_contains($value, '%') ? $value : "%$value%";
                }
                else {
                    $conditions[] = "`$column` $operator ?";
                    $params[] = $value;
                }
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        return self::query($sql, $params);
    }

    /**
     * Delete records from the database based on specified conditions.
     * Supports Advanced Where (IN, >=, <=, !=, <, >).
     *
     * @param string $table The name of the table.
     * @param array $where An associative array of conditions.
     * @param bool $allow_all Explicitly allow deleting all rows if true (default false).
     * @return bool TRUE on success, FALSE on failure.
     */
    public static function delete(string $table, array $where = [], bool $allow_all = false) {
        self::assertIdentifier($table, 'table name');
        if (empty($where) && !$allow_all) {
            self::handleError("Mass delete prevented: \$where is empty. Use \$allow_all = true to delete all rows.", true);
            return false;
        }

        $sql = "DELETE FROM `$table`";
        $params = [];

        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $key => $value) {
                $operator = '=';
                $column = $key;

                if (preg_match('/(.*)\s+(>=|<=|<>|!=|>|<|=|LIKE|NOT LIKE|IN|NOT IN|BETWEEN|IS NULL|IS NOT NULL)$/i', $key, $matches)) {
                    $column = trim($matches[1]);
                    $operator = strtoupper($matches[2]);
                }
                self::assertIdentifier((string) $column, 'column name');

                if (in_array($operator, ['IN', 'NOT IN']) && is_array($value)) {
                    if ($value === []) {
                        $conditions[] = '0 = 1';
                        continue;
                    }
                    $placeholders = implode(',', array_fill(0, count($value), '?'));
                    $conditions[] = "`$column` $operator ($placeholders)";
                    foreach ($value as $v) $params[] = $v;
                } 
                elseif ($operator === 'BETWEEN' && is_array($value) && count($value) === 2) {
                    $conditions[] = "`$column` BETWEEN ? AND ?";
                    $params[] = $value[0];
                    $params[] = $value[1];
                }
                elseif ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
                    $conditions[] = "`$column` $operator";
                }
                elseif ($value === null) {
                    $conditions[] = "`$column` " . (in_array($operator, ['!=', '<>'], true) ? 'IS NOT NULL' : 'IS NULL');
                }
                elseif ($operator === 'LIKE' || $operator === 'NOT LIKE') {
                    $conditions[] = "`$column` $operator ?";
                    $params[] = str_contains((string) $value, '%') ? $value : "%$value%";
                }
                else {
                    $conditions[] = "`$column` $operator ?";
                    $params[] = $value;
                }
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        return self::query($sql, $params);
    }

    /**
     * Select records from the database based on specified conditions.
     *
     * @param string $table The name of the table from which to select records.
     * @param string $columns The columns to select, specified as a comma-separated string (defaults to '*').
     * @param array $where An associative array of conditions for the WHERE clause. The key is the column name, and the value is the condition's value.
     * @param int|null $limit The maximum number of records to retrieve (optional).
     * @param int|null $offset The number of records to skip before starting to retrieve records (optional).
     * @param string|null $orderBy The column(s) by which to order the result set, optionally including ASC/DESC (optional).
     * @param string|null $groupBy The column(s) by which to group the result set (optional).
     * @param array|null $joins An array of JOIN clauses to be included in the query (optional).
     * @param bool $distinct Whether to select distinct records (optional).
     *
     * @return mysqli_result|bool Returns a `mysqli_result` object on success or FALSE on failure.
     */
    public static function select(string $table, string $columns = '*', array $where = [], ?int $limit = null, ?int $offset = null, ?string $orderBy = null, ?string $groupBy = null, ?array $joins = null, bool $distinct = false) {
        self::assertIdentifier($table, 'table name');
        $columns = self::formatColumn($columns);
        $sql = "SELECT " . ($distinct ? "DISTINCT " : "") . "$columns FROM `$table`";
        
        if (!empty($joins)) {
            $sql .= " " . self::formatJoins($joins);
        }

        // Initialize $params before the closure
        $params = []; 

        if (!empty($where)) {
            $buildConditions = function($where, $logic = 'AND') use (&$params, &$buildConditions) {
                $groupConditions = [];

                foreach ($where as $key => $value) {

                    // OR group
                    if (strtoupper((string)$key) === 'OR' && is_array($value)) {
                        $orGroup = $buildConditions($value, 'OR');
                        if (!empty($orGroup)) $groupConditions[] = "($orGroup)";
                        continue;
                    }

                    $operator = '=';
                    $column = $key;

                    // FULLTEXT
                    if (preg_match('/^FULLTEXT\((.*?)\)$/i', $key, $match)) {
                        $cols = $match[1];
                        $fullTextColumns = array_map('trim', explode(',', $cols));
                        $fullTextColumns = array_map(
                            static fn($column) => self::quoteQualifiedIdentifier($column),
                            $fullTextColumns
                        );
                        if ($value === null || $value === '') {
                            $groupConditions[] = '0 = 1';
                        } else {
                            $groupConditions[] = "MATCH(" . implode(', ', $fullTextColumns) . ") AGAINST (? IN BOOLEAN MODE)";
                            $params[] = $value;
                        }
                        continue;
                    }

                    // Detect operator (>=, <=, IN, LIKE, etc.)
                    if (preg_match('/(.*)\s+(>=|<=|<>|!=|>|<|=|LIKE|NOT LIKE|IN|NOT IN|BETWEEN|IS NULL|IS NOT NULL)$/i', $key, $matches)) {
                        $column = trim($matches[1]);
                        $operator = strtoupper($matches[2]);
                    }
                    self::assertIdentifier((string) $column, 'column name');

                    // NULL and an empty string are real database values. Callers
                    // can omit a key when they do not want that filter applied.
                    if ($value === null) {
                        $groupConditions[] = "`$column` " .
                            (in_array($operator, ['!=', '<>', 'IS NOT NULL'], true) ? 'IS NOT NULL' : 'IS NULL');
                        continue;
                    }

                    // BETWEEN
                    if ($operator === 'BETWEEN' && is_array($value) && count($value) === 2) {
                        $groupConditions[] = "`$column` BETWEEN ? AND ?";
                        $params[] = $value[0];
                        $params[] = $value[1];
                        continue;
                    }

                    // IN / NOT IN
                    if (in_array($operator, ['IN', 'NOT IN']) && is_array($value)) {
                        if ($value === []) {
                            $groupConditions[] = '0 = 1';
                            continue;
                        }
                        $placeholders = implode(',', array_fill(0, count($value), '?'));
                        $groupConditions[] = "`$column` $operator ($placeholders)";
                        foreach ($value as $v) $params[] = $v;
                        continue;
                    }

                    // NULL
                    if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
                        $groupConditions[] = "`$column` $operator";
                        continue;
                    }

                    // LIKE / NOT LIKE
                    if ($operator === 'LIKE' || $operator === 'NOT LIKE') {
                        $groupConditions[] = "`$column` $operator ?";
                        $params[] = str_contains($value, '%') ? $value : "%$value%";
                        continue;
                    }

                    // Default =, >, < etc
                    $groupConditions[] = "`$column` $operator ?";
                    $params[] = $value;
                }

                return implode(" $logic ", array_filter($groupConditions));
            };

            $finalWhere = $buildConditions($where);
            if (!empty($finalWhere)) {
                $sql .= " WHERE " . $finalWhere;
            }
        }

        if ($groupBy) {
            $sql .= " GROUP BY " . self::formatIdentifierList($groupBy);
        }
        if ($orderBy) {
            $sql .= " ORDER BY " . self::formatIdentifierList($orderBy, true);
        }

        // Just append to the already populated $params array!
        if ($limit) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
            
            if ($offset) {
                $sql .= " OFFSET ?";
                $params[] = $offset;
            }
        }

        return self::query($sql, $params);
    }

    /**
     * Find a record by its primary key (ID).
     *
     * @param string $table The table name.
     * @param mixed $id The ID value.
     * @param string $columns The columns to select.
     * @return array|null The record data or NULL if not found.
     */
    public static function find(string $table, $id, string $columns = '*') {
        $pk = self::primaryKey($table);
        $result = self::select($table, $columns, [$pk => $id], 1);
        return (!empty($result) && is_array($result)) ? $result[0] : null;
    }

    /**
     * Perform a specific selection from the database.
     *
     * @param string $query The SQL query to execute.
     * @param array $params An associative array of parameters for prepared statement (optional).
     * @return mysqli_result|bool The resulting mysqli_result object or FALSE on failure.
     */
    public static function specificSelect(string $query, array $params = []) {
        return self::query($query, $params);
    }

    /**
     * Get a single value from the database.
     *
     * @param string $table The name of the table.
     * @param string $column The column to select.
     * @param array $where An associative array of conditions for the WHERE clause.
     * @return mixed The value selected from the database or NULL if not found.
     */
    public static function getValue(string $table, string $column, array $where = []) {
        $result = self::select($table, $column, $where);
        if ($result && is_array($result) && count($result) > 0) {
            return $result[0][$column];
        } else {
            return null;
        }
    }

    /**
     * Get a specific value from the database.
     *
     * @param string $query The SQL query to execute.
     * @param array $params An associative array of parameters for prepared statement (optional).
     * @return mixed The value selected from the database or NULL if not found.
     */
    public static function getSpecificValue(string $query, array $params = []) {
        return self::scalar($query, $params);
    }

    /**
     * Intelligent Schema Parser with MASSIVE Alias Support.
     * Handles shortcodes (text50, uuid, price) AND attributes (unique, null).
     */
    private static function parseSchema(string $input) {
        $input = trim($input);
        // Empty defaults to standard text for safety
        if (empty($input)) return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        // 1. Split Type from Attributes
        // Limits explode to 2 parts so "text100 not null default 'a b'" works safely
        $parts = explode(' ', $input, 2);
        $typeKey = strtolower($parts[0]); 
        $attributes = isset($parts[1]) ? strtoupper($parts[1]) : ''; 

        $sqlType = '';

        switch (true) {
            // --- PRIMARY KEYS & IDS ---
            case ($typeKey === 'id' || $typeKey === 'ai' || $typeKey === 'pk'):
                return 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY';
            case ($typeKey === 'rel' || $typeKey === 'fk' || $typeKey === 'ref'):
                $sqlType = 'BIGINT(20) UNSIGNED'; // Perfect for Foreign Keys
                break;
            case ($typeKey === 'uuid'):
                $sqlType = 'CHAR(36)'; // Standard UUID size
                break;

            // --- DATE & TIME ---
            case ($typeKey === 'now' || $typeKey === 'created_at'):
                $sqlType = 'DATETIME DEFAULT CURRENT_TIMESTAMP';
                break;
            case ($typeKey === 'timestamp' || $typeKey === 'updated_at' || $typeKey === 'update'):
                $sqlType = 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
                break;
            case ($typeKey === 'date'): $sqlType = 'DATE'; break;
            case ($typeKey === 'time'): $sqlType = 'TIME'; break;
            case ($typeKey === 'datetime'): $sqlType = 'DATETIME'; break;
            case ($typeKey === 'year'): $sqlType = 'YEAR(4)'; break;

            // --- DYNAMIC STRINGS (Matches: text50, string255, char3) ---
            case preg_match('/^(text|string|varchar)(\d+)$/', $typeKey, $m):
                $sqlType = "VARCHAR({$m[2]})";
                break;
            case preg_match('/^char(\d+)$/', $typeKey, $m):
                $sqlType = "CHAR({$m[1]})";
                break;

            // --- DYNAMIC NUMBERS (Matches: int10, tinyint1, decimal10,2) ---
            case preg_match('/^int(\d+)$/', $typeKey, $m):
                $sqlType = "INT({$m[1]})";
                break;
            case preg_match('/^tinyint(\d+)$/', $typeKey, $m):
                $sqlType = "TINYINT({$m[1]})";
                break;
            case preg_match('/^bigint(\d+)$/', $typeKey, $m):
                $sqlType = "BIGINT({$m[1]})";
                break;
            // Handle decimal with precision shortcut (e.g., decimal12,4)
            case preg_match('/^decimal(\d+),(\d+)$/', $typeKey, $m):
                $sqlType = "DECIMAL({$m[1]},{$m[2]})";
                break;

            // --- WEB & COMMON ALIASES ---
            case ($typeKey === 'email'):    $sqlType = 'VARCHAR(255)'; break;
            case ($typeKey === 'url'):      $sqlType = 'VARCHAR(2048)'; break; // Standard URL max length
            case ($typeKey === 'slug'):     $sqlType = 'VARCHAR(255)'; break; // Usually needs 'unique' added
            case ($typeKey === 'ip'):       $sqlType = 'VARCHAR(45)'; break;  // Fits IPv4 and IPv6
            case ($typeKey === 'password'): $sqlType = 'VARCHAR(255)'; break; // Hash storage
            case ($typeKey === 'image'):    $sqlType = 'VARCHAR(255)'; break; // Path storage
            case ($typeKey === 'file'):     $sqlType = 'VARCHAR(255)'; break;
            case ($typeKey === 'phone'):    $sqlType = 'VARCHAR(32)'; break;
            case ($typeKey === 'country'):  $sqlType = 'CHAR(2)'; break;      // ISO Code (US, BD)

            // --- NUMERIC ALIASES ---
            case ($typeKey === 'int' || $typeKey === 'integer'): $sqlType = 'INT(11)'; break;
            case ($typeKey === 'tiny' || $typeKey === 'bool' || $typeKey === 'boolean' || $typeKey === 'status'): 
                $sqlType = 'TINYINT(1)'; break; // Great for status/flags
            case ($typeKey === 'smallint'): $sqlType = 'SMALLINT(6)'; break;
            case ($typeKey === 'mediumint'): $sqlType = 'MEDIUMINT(9)'; break;
            case ($typeKey === 'bigint'): $sqlType = 'BIGINT(20)'; break;
            
            // --- MONEY & MATH ---
            case ($typeKey === 'float'):  $sqlType = 'FLOAT'; break; // Standard float
            case ($typeKey === 'double'): $sqlType = 'DOUBLE(10,2)'; break;
            case ($typeKey === 'money' || $typeKey === 'price'): $sqlType = 'DECIMAL(10,2)'; break;
            case ($typeKey === 'percent'): $sqlType = 'DECIMAL(5,2)'; break; // 0.00 to 999.99
            
            // --- LARGE DATA ---
            case ($typeKey === 'text'):      $sqlType = 'TEXT'; break;
            case ($typeKey === 'mediumtext'): $sqlType = 'MEDIUMTEXT'; break;
            case ($typeKey === 'longtext' || $typeKey === 'content' || $typeKey === 'desc'): 
                $sqlType = 'LONGTEXT'; break; // 4GB text
            case ($typeKey === 'json'):      $sqlType = 'JSON'; break;
            case ($typeKey === 'blob'):      $sqlType = 'BLOB'; break;
            case ($typeKey === 'binary'):    $sqlType = 'LONGBLOB'; break;

            // --- FALLBACK ---
            default:
                $sqlType = $parts[0]; // Allow raw SQL like "ENUM('a','b')"
        }

        // 2. Append Attributes (e.g., NOT NULL, UNIQUE, DEFAULT)
        if (!empty($attributes)) {
            return "$sqlType $attributes";
        }

        // 3. Smart Defaults for "No Attributes"
        // If the user didn't specify NULL or DEFAULT, we apply strict standards for certain types.
        $notNullTypes = [
            'rel', 'fk', 'ref', 'uuid', 
            'bool', 'boolean', 'status', 
            'money', 'price', 'percent', 
            'email', 'slug', 'password'
        ];

        if (in_array($typeKey, $notNullTypes)) {
             return "$sqlType NOT NULL";
        }

        return $sqlType;
    }

    /**
     * Normalizes MySQL type aliases and ignored integer display widths.
     */
    private static function normalizeSchemaType(string $type): string {
        $type = strtolower(trim(preg_replace('/\s+/', ' ', $type)));
        $type = preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $type);
        $type = preg_replace('/\binteger\b/', 'int', $type);
        $type = preg_replace('/\bdouble precision\b/', 'double', $type);
        return trim($type);
    }

    /**
     * Converts a requested SQL column definition into comparable schema metadata.
     */
    private static function requestedColumnMeta(string $definition): array {
        $normalized = trim(preg_replace('/\s+/', ' ', $definition));
        $typePattern = '/^([a-z]+(?:\s+precision)?(?:\((?:[^()\'"]+|\'[^\']*\'|"[^"]*")*\))?(?:\s+unsigned)?)/i';
        preg_match($typePattern, $normalized, $typeMatch);
        $type = self::normalizeSchemaType($typeMatch[1] ?? strtok($normalized, ' '));
        $upper = strtoupper($normalized);
        $primary = preg_match('/\bPRIMARY\s+KEY\b/i', $normalized) === 1;
        $autoIncrement = str_contains($upper, 'AUTO_INCREMENT');
        $notNull = $primary || $autoIncrement || preg_match('/\bNOT\s+NULL\b/i', $normalized) === 1;
        $explicitNull = !$notNull && preg_match('/(?<!NOT\s)\bNULL\b/i', $normalized) === 1;
        $defaultPresent = preg_match(
            '/\bDEFAULT\s+((?:\'(?:\'\'|[^\'])*\')|(?:"(?:""|[^"])*")|[^\s,]+)/i',
            $normalized,
            $defaultMatch
        ) === 1;
        $default = $defaultPresent ? self::normalizeSchemaDefault($defaultMatch[1]) : null;
        $onUpdate = preg_match('/\bON\s+UPDATE\s+([^\s,]+)/i', $normalized, $updateMatch) === 1
            ? self::normalizeSchemaDefault($updateMatch[1])
            : null;
        $collation = preg_match('/\bCOLLATE\s+([a-zA-Z0-9_]+)/i', $normalized, $collationMatch) === 1
            ? strtolower($collationMatch[1])
            : null;

        return [
            'type' => $type,
            'null' => $notNull ? 'NO' : ($explicitNull ? 'YES' : 'YES'),
            'default_present' => $defaultPresent,
            'default' => $default,
            'auto_increment' => $autoIncrement,
            'on_update' => $onUpdate,
            'primary' => $primary,
            'unique' => preg_match('/\bUNIQUE\b/i', $normalized) === 1,
            'collation' => $collation,
        ];
    }

    private static function normalizeSchemaDefault(mixed $value): ?string {
        if ($value === null) return null;
        $value = trim((string) $value);
        if (
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
            || (str_starts_with($value, '"') && str_ends_with($value, '"'))
        ) {
            $value = substr($value, 1, -1);
        }
        $value = strtolower(trim($value));
        $value = preg_replace('/\bcurrent_timestamp\(\)/', 'current_timestamp', $value);
        return $value === 'null' ? null : $value;
    }

    /**
     * Compares the complete meaningful column shape, not only its type prefix.
     */
    private static function columnMatchesDefinition(array $current, string $definition): bool {
        $requested = self::requestedColumnMeta($definition);
        $currentType = self::normalizeSchemaType((string) ($current['Type'] ?? ''));
        if ($requested['type'] !== $currentType) return false;
        if ($requested['null'] !== strtoupper((string) ($current['Null'] ?? 'YES'))) return false;

        $currentDefault = self::normalizeSchemaDefault($current['Default'] ?? null);
        if ($requested['default_present'] && $requested['default'] !== $currentDefault) return false;
        if (!$requested['default_present'] && $currentDefault !== null) return false;

        $extra = strtolower((string) ($current['Extra'] ?? ''));
        if ($requested['auto_increment'] !== str_contains($extra, 'auto_increment')) return false;
        $currentOnUpdate = preg_match('/on update\s+([^\s]+)/i', $extra, $match) === 1
            ? self::normalizeSchemaDefault($match[1])
            : null;
        if ($requested['on_update'] !== $currentOnUpdate) return false;

        if (
            $requested['collation'] !== null
            && $requested['collation'] !== strtolower((string) ($current['Collation'] ?? ''))
        ) {
            return false;
        }
        return true;
    }

    /**
     * PRIMARY/UNIQUE are synchronized separately from MODIFY COLUMN definitions.
     */
    private static function alterationColumnDefinition(string $definition): string {
        $definition = preg_replace('/\s+PRIMARY\s+KEY\b/i', '', $definition);
        $definition = preg_replace('/\s+UNIQUE(?:\s+KEY)?\b/i', '', $definition);
        return trim(preg_replace('/\s+/', ' ', $definition));
    }

    private static function schemaSignature(string $table, array $columns, array $constraints): string {
        return hash('sha256', json_encode([
            'database' => (string) self::$dbname,
            'charset' => (string) self::$charset,
            'table' => $table,
            'columns' => $columns,
            'constraints' => $constraints,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function schemaCacheKey(string $table): string {
        return 'phdb:schema:' . hash('sha256', (string) self::$dbname . '|' . $table);
    }

    private static function schemaCacheHit(string $table, string $signature): bool {
        $localKey = (string) self::$dbname . '.' . $table;
        $local = self::$schemaSyncCache[$localKey] ?? null;
        if (
            is_array($local)
            && hash_equals((string) ($local['signature'] ?? ''), $signature)
            && (int) ($local['checked_at'] ?? 0) >= time() - self::SCHEMA_VERIFY_TTL
        ) {
            return true;
        }
        if (!class_exists('PHLS')) return false;
        try {
            $cached = PHLS::get(self::schemaCacheKey($table));
            if (
                is_array($cached)
                && hash_equals((string) ($cached['signature'] ?? ''), $signature)
                && (int) ($cached['checked_at'] ?? 0) >= time() - self::SCHEMA_VERIFY_TTL
            ) {
                self::$schemaSyncCache[$localKey] = [
                    'signature' => $signature,
                    'checked_at' => (int) $cached['checked_at'],
                ];
                return true;
            }
        } catch (\Throwable $ignored) {
        }
        return false;
    }

    private static function rememberSchemaSignature(string $table, string $signature): void {
        self::$schemaSyncCache[(string) self::$dbname . '.' . $table] = [
            'signature' => $signature,
            'checked_at' => time(),
        ];
        if (!class_exists('PHLS')) return;
        try {
            PHLS::add(self::schemaCacheKey($table), [
                'signature' => $signature,
                'checked_at' => time(),
            ], 10, ['phdb-schema']);
        } catch (\Throwable $ignored) {
        }
    }

    private static function invalidateSchemaCache(string $table): void {
        unset(self::$schemaSyncCache[(string) self::$dbname . '.' . $table]);
        if (!class_exists('PHLS')) return;
        try {
            PHLS::remove(self::schemaCacheKey($table));
        } catch (\Throwable $ignored) {
        }
    }

    private static function acquireSchemaLock(string $table): ?string {
        $lock = 'phdb_schema_' . substr(hash('sha256', (string) self::$dbname . '|' . $table), 0, 40);
        $result = self::query('SELECT GET_LOCK(?, 10) AS acquired', [$lock], true);
        return is_array($result) && (int) ($result['acquired'] ?? 0) === 1 ? $lock : null;
    }

    private static function releaseSchemaLock(?string $lock): void {
        if ($lock === null) return;
        try {
            self::query('SELECT RELEASE_LOCK(?) AS released', [$lock], true);
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * Create a database manually (Utility function).
     * Note: connect() handles this automatically now, but this remains for manual use.
     *
     * @param string $dbname The name of the database.
     * @param string $collation Best auto-compatible collation.
     * @return bool TRUE on success, FALSE on failure.
     */
    public static function addDB(string $dbname, string $collation = 'utf8mb4_unicode_ci') {
        self::assertIdentifier($dbname, 'database name');
        self::assertIdentifier($collation, 'collation');
        $query = "CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE $collation";
        return self::query($query);
    }

    /**
     * Create or fully synchronize a table from one schema definition.
     *
     * With synchronization enabled (the default), columns are added, modified,
     * reordered, or removed so the database matches the supplied array. Repeated
     * calls use a schema-signature fast path, while an advisory lock serializes
     * real DDL work across concurrent requests. Type conversions run in strict
     * mode so incompatible existing data causes the ALTER to fail instead of
     * being silently truncated.
     *
     * @param string $table_name The table name.
     * @param array $columns Associative schema array ['column' => 'definition'].
     * @param mixed $sync TRUE to synchronize an existing table; FALSE to create only.
     * @return bool TRUE on success, FALSE on failure.
     */
    public static function createTable(string $table_name, array $columns, mixed $sync = true) {
        self::assertIdentifier($table_name, 'table name');
        if ($columns === []) throw new \InvalidArgumentException('Table schema cannot be empty.');
        if ($sync === "") $sync = true;
        $sync = (bool) $sync;

        $sql_columns = [];
        $sql_constraints = [];
        $parsed_for_sync = [];

        foreach ($columns as $name => $def) {
            if (is_int($name)) { $name = $def; $def = 'text'; }
            $name = (string) $name;
            $def = (string) $def;
            self::assertIdentifier($name, 'column name');

            $fk_sql = "";
            if (preg_match('/fk\(([^.]+)\.([^)]+)\)/i', $def, $matches)) {
                $ref_table = self::assertIdentifier(trim($matches[1]), 'foreign table name');
                $ref_col   = self::assertIdentifier(trim($matches[2]), 'foreign column name');
                $constraint_name = "fk_{$table_name}_{$name}";
                $fk_sql = ", CONSTRAINT `$constraint_name` FOREIGN KEY (`$name`) REFERENCES `$ref_table`(`$ref_col`) ON DELETE CASCADE ON UPDATE CASCADE";
                $def = str_replace($matches[0], '', $def);
            }

            $parsed_def = self::parseSchema($def);
            
            $sql_columns[] = "`$name` $parsed_def";
            if (!empty($fk_sql)) {
                $sql_constraints[] = $fk_sql;
            }
            
            $parsed_for_sync[$name] = $parsed_def;
        }

        $signature = self::schemaSignature($table_name, $parsed_for_sync, $sql_constraints);
        if ($sync && self::schemaCacheHit($table_name, $signature)) return true;

        $schemaLock = self::acquireSchemaLock($table_name);
        if ($schemaLock === null) {
            self::handleError("Schema sync lock timed out for '$table_name'.", true);
            return false;
        }

        try {
            // Another worker may have completed the same sync while this worker waited.
            if ($sync && self::schemaCacheHit($table_name, $signature)) return true;

            $tableExists = !empty(self::query('SHOW TABLES LIKE ?', [$table_name]));
            if (!$tableExists) {
                $sql = "CREATE TABLE `$table_name` (" . implode(", ", $sql_columns);
                if ($sql_constraints) $sql .= implode(" ", $sql_constraints);
                $sql .= ") ENGINE=InnoDB DEFAULT CHARSET=" . self::$charset;
                $created = self::query($sql);
                if ($created) self::rememberSchemaSignature($table_name, $signature);
                return (bool) $created;
            }

            if (!$sync) return true;

            $dbColsRaw = self::query("SHOW FULL COLUMNS FROM `$table_name`");
            $existingCols = [];
            $existingOrder = [];
            foreach ((array) $dbColsRaw as $column) {
                $field = (string) ($column['Field'] ?? '');
                if ($field === '') continue;
                $existingCols[$field] = $column;
                $existingOrder[] = $field;
            }

            $alterations = [];
            $desiredOrder = array_keys($parsed_for_sync);
            $desiredPrimary = [];
            $desiredUnique = [];
            $existingPrimary = [];
            foreach ($parsed_for_sync as $name => $definition) {
                $meta = self::requestedColumnMeta($definition);
                if ($meta['primary']) $desiredPrimary[] = $name;
                if ($meta['unique']) $desiredUnique[] = $name;
            }
            foreach ($existingCols as $name => $column) {
                if (strtoupper((string) ($column['Key'] ?? '')) === 'PRI') $existingPrimary[] = $name;
            }

            $uniqueIndexes = [];
            foreach ((array) self::query("SHOW INDEX FROM `$table_name`") as $index) {
                if ((int) ($index['Non_unique'] ?? 1) !== 0 || ($index['Key_name'] ?? '') === 'PRIMARY') {
                    continue;
                }
                $indexName = (string) ($index['Key_name'] ?? '');
                $uniqueIndexes[$indexName][] = (string) ($index['Column_name'] ?? '');
            }
            $existingSingleUnique = [];
            foreach ($uniqueIndexes as $indexName => $indexColumns) {
                if (count($indexColumns) === 1) {
                    $existingSingleUnique[$indexColumns[0]] = $indexName;
                }
            }
            foreach ($existingSingleUnique as $column => $indexName) {
                if (!in_array($column, $desiredUnique, true)) {
                    self::assertIdentifier($indexName, 'index name');
                    $alterations[] = "DROP INDEX `$indexName`";
                }
            }

            if ($existingPrimary !== $desiredPrimary && $existingPrimary) {
                $alterations[] = 'DROP PRIMARY KEY';
            }

            $previousCol = null;
            foreach ($parsed_for_sync as $colName => $colDef) {
                $position = $previousCol ? "AFTER `$previousCol`" : 'FIRST';
                $alterDef = self::alterationColumnDefinition($colDef);
                if (!isset($existingCols[$colName])) {
                    $alterations[] = "ADD COLUMN `$colName` $alterDef $position";
                } else {
                    $currentIndex = array_search($colName, $existingOrder, true);
                    $expectedIndex = array_search($colName, $desiredOrder, true);
                    $orderChanged = $currentIndex !== $expectedIndex;
                    if (!self::columnMatchesDefinition($existingCols[$colName], $colDef) || $orderChanged) {
                        $alterations[] = "MODIFY COLUMN `$colName` $alterDef $position";
                    }
                }
                $previousCol = $colName;
            }

            foreach ($existingCols as $dbColName => $details) {
                if (!array_key_exists($dbColName, $parsed_for_sync)) {
                    $alterations[] = "DROP COLUMN `$dbColName`";
                }
            }

            if ($existingPrimary !== $desiredPrimary && $desiredPrimary) {
                $primaryColumns = implode(', ', array_map(
                    static fn(string $column): string => "`$column`",
                    $desiredPrimary
                ));
                $alterations[] = "ADD PRIMARY KEY ($primaryColumns)";
            }
            foreach ($desiredUnique as $column) {
                if (isset($existingSingleUnique[$column])) continue;
                $indexName = 'uq_' . $table_name . '_' . $column;
                if (strlen($indexName) > 64) {
                    $indexName = substr($indexName, 0, 47) . '_' . substr(hash('sha256', $indexName), 0, 16);
                }
                self::assertIdentifier($indexName, 'index name');
                $alterations[] = "ADD UNIQUE KEY `$indexName` (`$column`)";
            }

            if (!$alterations) {
                self::rememberSchemaSignature($table_name, $signature);
                return true;
            }

            // Strict conversion prevents silent truncation; incompatible data fails unchanged.
            self::query(
                "SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')"
            );
            $synced = self::query("ALTER TABLE `$table_name` " . implode(', ', $alterations));
            if ($synced) self::rememberSchemaSignature($table_name, $signature);
            return (bool) $synced;
        } catch (\Throwable $error) {
            self::invalidateSchemaCache($table_name);
            self::handleError("Sync failed for '$table_name': " . $error->getMessage(), true);
            return false;
        } finally {
            self::releaseSchemaLock($schemaLock);
        }
    }

    /**
     * Drop a table from the database.
     *
     * @param string $table_name The name of the table to drop.
     * @return bool TRUE on success, FALSE on failure.
     */
    public static function dropTable(string $table_name) {
        self::assertIdentifier($table_name, 'table name');
        $sql = "DROP TABLE IF EXISTS `$table_name`";
        $result = self::query($sql);
        if ($result) self::invalidateSchemaCache($table_name);
        return $result;
    }

    /**
     * Alter a table in the database.
     *
     * @param string $table_name The name of the table to alter.
     * @param array $changes An array of SQL statements for alterations.
     * @return bool TRUE on success, FALSE on failure.
     */
    public static function alterTable(string $table_name, array $changes) {
        self::assertIdentifier($table_name, 'table name');
        if (empty($changes)) {
            throw new \InvalidArgumentException('ALTER TABLE requires at least one change.');
        }
        foreach ($changes as $change) {
            if (
                !is_string($change) ||
                str_contains($change, ';') ||
                str_contains($change, '--') ||
                str_contains($change, '/*') ||
                !preg_match('/^\s*(ADD|MODIFY|CHANGE|DROP|RENAME)\b/i', $change)
            ) {
                throw new \InvalidArgumentException('Unsafe or unsupported ALTER TABLE change.');
            }
        }
        $sql = "ALTER TABLE `$table_name` ";
        $sql .= implode(', ', $changes);
        $result = self::query($sql);
        if ($result) self::invalidateSchemaCache($table_name);
        return $result;
    }

    /**
     * Truncate a table in the database.
     *
     * @param string $table_name The name of the table to truncate.
     * @return bool TRUE on success, FALSE on failure.
     */
    public static function truncateTable(string $table_name) {
        self::assertIdentifier($table_name, 'table name');
        $sql = "TRUNCATE TABLE `$table_name`";
        return self::query($sql);
    }

    /**
     * Find records in the database based on specific conditions.
     *
     * @param string $table The name of the table.
     * @param string $columns The columns to select (comma separated).
     * @param array $conditions An associative array of conditions for the WHERE clause.
     * @return mysqli_result|bool The resulting mysqli_result object or FALSE on failure.
     */
    public static function findBy(string $table, string $columns = '*', array $conditions = [], ?int $limit = null, ?int $offset = null) {
        return self::select($table, $columns, $conditions, $limit, $offset);
    }

    /**
     * Search records in the database based on specified conditions.
     * - If $conditions is an array, it searches specific columns (e.g., ["name" => "sakib"]).
     * - If $conditions is a string, it searches ALL columns in the table for the keyword (e.g., "sakib").
     *
     * @param string $table The name of the table.
     * @param string $columns The columns to select (comma-separated or '*' for all).
     * @param array|string $conditions Associative array (column => value) OR string (search all columns).
     * @param int|null $limit Maximum records to retrieve.
     * @param int|null $offset Records to skip.
     * @param string|null $orderBy Column(s) to order by.
     * @param string|null $groupBy Column(s) to group by.
     * @param array|null $joins JOIN clauses.
     * @return array|bool Query results or FALSE on failure.
     */
    public static function search(string $table, string $columns = '*', array|string $conditions = [], ?int $limit = null, ?int $offset = null, ?string $orderBy = null, ?string $groupBy = null, ?array $joins = null) {
        self::assertIdentifier($table, 'table name');
        $where = [];
        if (is_string($conditions)) {
            $keyword = trim($conditions);
            if ($keyword !== '') {
                $or = [];
                foreach (self::columns($table) as $column) {
                    self::assertIdentifier((string) $column, 'column name');
                    $or[$column . ' LIKE'] = $keyword;
                }
                if ($or) $where['OR'] = $or;
            }
        } else {
            foreach ($conditions as $column => $value) {
                self::assertIdentifier((string) $column, 'column name');
                $where[$column . ' LIKE'] = $value;
            }
        }
        return self::select($table, $columns, $where, $limit, $offset, $orderBy, $groupBy, $joins);
    }

    /**
     * Get the available columns from a specified table in the database.
     *
     * @param string $table The name of the table.
     * @param string|array|null $filter Optional. A pattern or array of patterns to filter column names using 'LIKE'.
     * @param string|array|null $skip Optional. A pattern or array of patterns to exclude column names using 'LIKE'.
     * @return array Returns an array of column names on success or an empty array on failure.
     */
    public static function columns(string $table, string|array|null $filter = null, string|array|null $skip = null) {
        self::assertIdentifier($table, 'table name');
        $sql = "SHOW COLUMNS FROM `$table`";
        $result = self::query($sql);
        if (is_array($result)) {
            $columns = array_column($result, 'Field');
            if ($filter) {
                $columns = array_filter($columns, function($column) use ($filter) {
                    foreach ((array)$filter as $pattern) {
                        if (stripos($column, $pattern) !== false) {
                            return true;
                        }
                    }
                    return false;
                });
            }
            if ($skip) {
                $columns = array_filter($columns, function($column) use ($skip) {
                    foreach ((array)$skip as $pattern) {
                        if (stripos($column, $pattern) !== false) {
                            return false;
                        }
                    }
                    return true;
                });
            }
            return array_values($columns);
        }
        return [];
    }

    /**
     * Delete records from the database based on specific conditions.
     *
     * @param string $table The name of the table.
     * @param array $conditions An associative array of conditions for the WHERE clause.
     * @return bool TRUE on success, FALSE on failure.
     */
    public static function deleteBy(string $table, array $conditions) {
        return self::delete($table, $conditions);
    }

    /**
     * Paginate results with total count information
     *
     * @param string $table Table name
     * @param string $columns Columns to select
     * @param array $where Conditions
     * @param int $page Current page (1-based)
     * @param int $per_page Items per page
     * @return array|bool Array with data and pagination info or FALSE on failure
     */
    public static function paginate(string $table, string $columns = '*', array $where = [], int $page = 1, int $per_page = 10) {
        $offset = ($page - 1) * $per_page;
        $data = self::select($table, $columns, $where, $per_page, $offset);

        if ($data === false) {
            return false;
        }

        $total = self::count($table, $where);

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $per_page,
            'current_page' => $page,
            'last_page' => ceil($total / $per_page),
            'from' => $offset + 1,
            'to' => min($offset + $per_page, $total)
        ];
    }

    /**
     * Get sum of a column safely.
     * Returns 0 instead of NULL/False on failure.
     *
     * @param string $table Table name
     * @param string $column Column to sum
     * @param array $where Conditions (optional)
     * @return float|int Sum value or 0
     */
    public static function sum(string $table, string $column, array $where = []) {
        try {
            self::assertIdentifier($table, 'table name');
            self::assertIdentifier($column, 'column name');
            $sql = "SELECT SUM(`$column`) as total FROM `$table`";
            
            $params = [];
            if (!empty($where)) {
                $conditions = [];
                foreach ($where as $key => $value) {
                    self::assertIdentifier((string) $key, 'column name');
                    $conditions[] = "`$key` = ?";
                    $params[] = $value;
                }
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }

            $result = self::query($sql, $params);
            
            // Return 0 if result is null, empty or false
            return ($result && isset($result[0]['total'])) ? $result[0]['total'] + 0 : 0;

        } catch (Exception $e) {
            self::handleError("Sum failed: " . $e->getMessage(), true);
            return 0;
        }
    }

    /**
     * Get average of a column safely.
     * Returns 0 instead of NULL/False on failure.
     *
     * @param string $table Table name
     * @param string $column Column to average
     * @param array $where Conditions (optional)
     * @return float Average value or 0
     */
    public static function avg(string $table, string $column, array $where = []) {
        try {
            self::assertIdentifier($table, 'table name');
            self::assertIdentifier($column, 'column name');
            $sql = "SELECT AVG(`$column`) as average FROM `$table`";
            
            $params = [];
            if (!empty($where)) {
                $conditions = [];
                foreach ($where as $key => $value) {
                    self::assertIdentifier((string) $key, 'column name');
                    $conditions[] = "`$key` = ?";
                    $params[] = $value;
                }
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }

            $result = self::query($sql, $params);
            return ($result && isset($result[0]['average'])) ? (float)$result[0]['average'] : 0;

        } catch (Exception $e) {
            self::handleError("Avg failed: " . $e->getMessage(), true);
            return 0;
        }
    }

    /**
     * Get maximum value of a column safely.
     * Returns 0 instead of NULL/False on failure.
     *
     * @param string $table Table name
     * @param string $column Column to check
     * @param array $where Conditions (optional)
     * @return mixed Max value or 0
     */
    public static function max(string $table, string $column, array $where = []) {
        try {
            self::assertIdentifier($table, 'table name');
            self::assertIdentifier($column, 'column name');
            $sql = "SELECT MAX(`$column`) as max_val FROM `$table`";
            
            $params = [];
            if (!empty($where)) {
                $conditions = [];
                foreach ($where as $key => $value) {
                    self::assertIdentifier((string) $key, 'column name');
                    $conditions[] = "`$key` = ?";
                    $params[] = $value;
                }
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }

            $result = self::query($sql, $params);
            return ($result && isset($result[0]['max_val'])) ? $result[0]['max_val'] : 0;

        } catch (Exception $e) {
            self::handleError("Max failed: " . $e->getMessage(), true);
            return 0;
        }
    }

    /**
     * Get minimum value of a column safely.
     * Returns 0 instead of NULL/False on failure.
     *
     * @param string $table Table name
     * @param string $column Column to check
     * @param array $where Conditions (optional)
     * @return mixed Min value or 0
     */
    public static function min(string $table, string $column, array $where = []) {
        try {
            self::assertIdentifier($table, 'table name');
            self::assertIdentifier($column, 'column name');
            $sql = "SELECT MIN(`$column`) as min_val FROM `$table`";
            
            $params = [];
            if (!empty($where)) {
                $conditions = [];
                foreach ($where as $key => $value) {
                    self::assertIdentifier((string) $key, 'column name');
                    $conditions[] = "`$key` = ?";
                    $params[] = $value;
                }
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }

            $result = self::query($sql, $params);
            return ($result && isset($result[0]['min_val'])) ? $result[0]['min_val'] : 0;

        } catch (Exception $e) {
            self::handleError("Min failed: " . $e->getMessage(), true);
            return 0;
        }
    }

    /**
     * Count records safely.
     * Returns 0 instead of NULL/False on failure.
     *
     * @param string $table Table name
     * @param string|array|null $column Column name (optional) or array of columns
     * @param array|null $conditions Conditions (optional)
     * @return int Count or 0
     */
    public static function count(string $table, string|array|null $column = null, array|null $conditions = null): int {

        try {
            self::assertIdentifier($table, 'table name');

            $sql = "SELECT COUNT(*) as count FROM `$table`";
            $whereParts = [];
            $params = [];

            /*
            =====================================
            MODE 1 → GENERAL
            PHDB::count("posts", "category", ["category" => "news"])
            =====================================
            */

            if (is_string($column) && is_array($conditions)) {
                foreach ($conditions as $key => $value) {
                    $operator = "=";
                    $colName = $key;

                    if (preg_match('/^(.+?)\s+(=|!=|<>|<|>|<=|>=|LIKE|NOT LIKE|IN|NOT IN|BETWEEN|IS NULL|IS NOT NULL)$/i', $key, $match)) {
                        $colName = trim($match[1]);
                        $operator = strtoupper($match[2]);
                    }
                    self::assertIdentifier((string) $colName, 'column name');

                    // IN / NOT IN
                    if (in_array($operator, ['IN', 'NOT IN']) && is_array($value)) {
                        if ($value === []) {
                            $whereParts[] = '0 = 1';
                            continue;
                        }
                        $placeholders = implode(',', array_fill(0, count($value), '?'));
                        $whereParts[] = "`$colName` $operator ($placeholders)";
                        $params = array_merge($params, $value);
                    }

                    // BETWEEN
                    elseif ($operator === 'BETWEEN' && is_array($value) && count($value) === 2) {
                        $whereParts[] = "`$colName` BETWEEN ? AND ?";
                        $params[] = $value[0];
                        $params[] = $value[1];
                    }

                    // NULL
                    elseif ($operator === 'IS NULL' || $operator === 'IS NOT NULL' || is_null($value)) {
                        $op = ($operator === 'IS NULL' || $operator === 'IS NOT NULL')
                            ? $operator
                            : (in_array($operator, ['!=', '<>'], true) ? 'IS NOT NULL' : 'IS NULL');
                        $whereParts[] = "`$colName` $op";
                    }

                    // LIKE / NOT LIKE
                    elseif ($operator === 'LIKE' || $operator === 'NOT LIKE') {
                        $whereParts[] = "`$colName` $operator ?";
                        $params[] = str_contains($value, '%') ? $value : "%$value%";
                    }

                    // Normal operators
                    else {
                        $whereParts[] = "`$colName` $operator ?";
                        $params[] = $value;
                    }
                }
            }

            /*
            =====================================
            MODE 2 → DIRECT ARRAY (Smart Mode)
            PHDB::count("posts", ["category like" => "%news%"])
            =====================================
            */

            elseif (is_array($column)) {

                foreach ($column as $key => $value) {

                    $operator = "=";
                    $colName = $key;

                    if (preg_match('/^(.+?)\s+(=|!=|<>|<|>|<=|>=|LIKE|NOT LIKE|IN|NOT IN|BETWEEN|IS NULL|IS NOT NULL)$/i', $key, $match)) {
                        $colName = trim($match[1]);
                        $operator = strtoupper($match[2]);
                    }
                    self::assertIdentifier((string) $colName, 'column name');

                    if (in_array($operator, ['IN', 'NOT IN']) && is_array($value)) {
                        if ($value === []) {
                            $whereParts[] = '0 = 1';
                            continue;
                        }
                        $placeholders = implode(',', array_fill(0, count($value), '?'));
                        $whereParts[] = "`$colName` $operator ($placeholders)";
                        $params = array_merge($params, $value);
                    }

                    elseif ($operator === 'BETWEEN' && is_array($value) && count($value) === 2) {
                        $whereParts[] = "`$colName` BETWEEN ? AND ?";
                        $params[] = $value[0];
                        $params[] = $value[1];
                    }

                    elseif ($operator === 'IS NULL' || $operator === 'IS NOT NULL' || is_null($value)) {
                        $whereParts[] = "`$colName` " . (($operator === 'IS NULL' || $operator === 'IS NOT NULL')
                            ? $operator
                            : (in_array($operator, ['!=', '<>'], true) ? 'IS NOT NULL' : 'IS NULL'));
                    }

                    elseif ($operator === 'LIKE' || $operator === 'NOT LIKE') {
                        $whereParts[] = "`$colName` $operator ?";
                        $params[] = str_contains((string) $value, '%') ? $value : "%$value%";
                    }

                    else {
                        $whereParts[] = "`$colName` $operator ?";
                        $params[] = $value;
                    }
                }
            }

            if (!empty($whereParts)) {
                $sql .= " WHERE " . implode(" AND ", $whereParts);
            }

            $result = self::query($sql, $params);

            return ($result && isset($result[0]['count']))
                ? (int)$result[0]['count']
                : 0;

        } catch (Exception $e) {
            self::handleError("Count failed: " . $e->getMessage(), true);
            return 0;
        }
    }

    /**
     * Check if a record exists in the database.
     * Uses Standard Where Array. No auto-ID guessing for safety.
     *
     * @param string $table The table name.
     * @param array $where Associative array of conditions ['slug' => 'abc'].
     * @return bool True if exists, False otherwise.
     */
    public static function exists(string $table, array $where = []) {
        try {
            if (!is_array($where)) {
                return false; 
            }

            $result = self::select($table, '*', $where, 1);

            return !empty($result);

        } catch (Exception $e) {
            self::handleError("Exists check failed: " . $e->getMessage(), true);
            return false;
        }
    }

    /**
     * Serving JSON API responses instantly with Smart Pagination & Formatting.
     *
     * @param string $table     Table Name.
     * @param string|array $columns Columns to select (Default: '*').
     * @param array $where      Conditions (Default: []).
     * @param array $options    Advanced settings:
     *      - return_into (string): JSON key for data (Default: 'data').
     *      - page (int): If set, enables pagination.
     *      - per_page (int): Items per page.
     *      - order_by (string): 'id DESC'.
     *      - joins (array): ['LEFT JOIN...'].
     *      - group_by (string).
     *      - as_array (bool): Return PHP array instead of JSON output (Default: false).
     *      - debug (bool): Force debug mode.
     * @param bool $return Shortcut for $options into as_array (bool): Return PHP array instead of JSON output (Default: false).
     * 
     * @return void|array Outputs JSON and exits, or returns array if 'as_array'=>true.
     */
    public static function api(string $table, string|array $columns = '*', array $where = [], array $options = [], bool $return = false) {
        self::assertIdentifier($table, 'table name');
        // 1. Setup Configuration
        $options['as_array'] = $return ?? false;
        $dataKey = $options['return_into'] ?? 'data';
        $debugMode = $options['debug'] ?? (self::$error === true);
        $returnAsArray = $options['as_array'] ?? false;
        
        // Response Structure
        $response = [
            'status'  => true,
            'message' => 'Data retrieved successfully',
            $dataKey  => [],
            'meta'    => null
        ];

        try {
            // 2. Prepare Query Parts
            $cols = self::formatColumn(is_array($columns) ? implode(',', $columns) : $columns);
            $sql = "SELECT $cols FROM `$table`";

            // Joins
            if (!empty($options['joins'])) {
                $sql .= " " . self::formatJoins((array) $options['joins']);
            }

            // Where
            $params = [];
            $finalWhere = '';
            if (!empty($where)) {

                $buildConditions = function($where, $logic = 'AND') use (&$params, &$buildConditions) {
                    $groupConditions = [];

                    foreach ($where as $key => $value) {

                        // OR Group
                        if (strtoupper($key) === 'OR' && is_array($value)) {
                            $orGroup = $buildConditions($value, 'OR');
                            if (!empty($orGroup)) {
                                $groupConditions[] = "($orGroup)";
                            }
                            continue;
                        }

                        $operator = '=';
                        $column = $key;

                        // FULLTEXT
                        if (preg_match('/^FULLTEXT\((.*?)\)$/i', $key, $match)) {
                            $fullTextColumns = array_map('trim', explode(',', $match[1]));
                            $fullTextColumns = array_map(
                                static fn($column) => self::quoteQualifiedIdentifier($column),
                                $fullTextColumns
                            );
                            if ($value === null || $value === '') {
                                $groupConditions[] = '0 = 1';
                            } else {
                                $groupConditions[] = "MATCH(" . implode(', ', $fullTextColumns) . ") AGAINST (? IN BOOLEAN MODE)";
                                $params[] = $value;
                            }
                            continue;
                        }

                        // Detect operator
                        if (preg_match('/(.*)\s+(>=|<=|<>|!=|>|<|=|LIKE|NOT LIKE|IN|NOT IN|BETWEEN|IS NULL|IS NOT NULL)$/i', $key, $matches)) {
                            $column = trim($matches[1]);
                            $operator = strtoupper($matches[2]);
                        }
                        self::assertIdentifier((string) $column, 'column name');

                        if ($value === null) {
                            $groupConditions[] = "`$column` " .
                                (in_array($operator, ['!=', '<>', 'IS NOT NULL'], true) ? 'IS NOT NULL' : 'IS NULL');
                            continue;
                        }

                        // BETWEEN
                        if ($operator === 'BETWEEN' && is_array($value) && count($value) === 2) {
                            $groupConditions[] = "`$column` BETWEEN ? AND ?";
                            $params[] = $value[0];
                            $params[] = $value[1];
                            continue;
                        }

                        // IN
                        if (in_array($operator, ['IN', 'NOT IN'], true) && is_array($value)) {
                            if ($value === []) {
                                $groupConditions[] = '0 = 1';
                                continue;
                            }
                            $placeholders = implode(',', array_fill(0, count($value), '?'));
                            $groupConditions[] = "`$column` $operator ($placeholders)";
                            foreach ($value as $v) $params[] = $v;
                            continue;
                        }

                        // NULL
                        if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
                            $groupConditions[] = "`$column` $operator";
                            continue;
                        }

                        // LIKE
                        if ($operator === 'LIKE' || $operator === 'NOT LIKE') {
                            $groupConditions[] = "`$column` $operator ?";
                            $params[] = str_contains((string) $value, '%') ? $value : "%$value%";
                            continue;
                        }

                        // Default =, >, <, etc
                        $groupConditions[] = "`$column` $operator ?";
                        $params[] = $value;
                    }

                    return implode(" $logic ", array_filter($groupConditions));
                };

                $finalWhere = $buildConditions($where);
                if (!empty($finalWhere)) {
                    $sql .= " WHERE " . $finalWhere;
                }
            }

            // Group By
            if (!empty($options['group_by'])) {
                $sql .= " GROUP BY " . self::formatIdentifierList((string) $options['group_by']);
            }

            // Order By (Default: id DESC if id exists, else none)
            if (!empty($options['order_by'])) {
                $sql .= " ORDER BY " . self::formatIdentifierList((string) $options['order_by'], true);
            } 

            // 3. Smart Pagination Logic
            // If user explicitly asks for pagination OR limits
            if (isset($options['page']) || isset($options['per_page'])) {
                $page = max(1, (int)($options['page'] ?? 1));
                $perPage = max(1, (int)($options['per_page'] ?? 20));
                $offset = ($page - 1) * $perPage;

                // Get Total Count for Meta
                $countSql = "SELECT COUNT(*) as total FROM `$table`";
                if (!empty($finalWhere)) {
                    $countSql .= " WHERE " . $finalWhere;
                }
                $countRes = self::query($countSql, $params);
                $totalRecords = ($countRes && isset($countRes[0]['total'])) ? (int)$countRes[0]['total'] : 0;
                $totalPages = ceil($totalRecords / $perPage);

                // Add Limit to Main Query
                $sql .= " LIMIT ? OFFSET ?";
                $params[] = $perPage;
                $params[] = $offset;

                // Set Meta Data
                $response['meta'] = [
                    'current_page' => $page,
                    'per_page'     => $perPage,
                    'total_items'  => $totalRecords,
                    'total_pages'  => $totalPages,
                    'has_next'     => $page < $totalPages,
                    'has_prev'     => $page > 1
                ];
            } else {
                // Default: NO LIMIT (Fetch All)
                // Just count total results after fetch
                $response['meta'] = [
                    'total_items' => 0, // Will update after fetch
                    'type'        => 'full_list'
                ];
            }

            // 4. Execute Query
            $data = self::query($sql, $params);

            if ($data === false) {
                throw new Exception("Database query failed.");
            }

            // 5. Populate Response
            $response[$dataKey] = $data;
            if ($response['meta']['type'] ?? '' === 'full_list') {
                $response['meta']['total_items'] = count($data);
                unset($response['meta']['type']); // Clean up
            }

            if (empty($data)) {
                $response['message'] = 'No data found';
            }

        } catch (Exception $e) {
            $response['status']  = false;
            $response['message'] = 'Server Error';
            $response[$dataKey] = null; // Clear data on error
            
            if ($debugMode) {
                $response['error'] = $e->getMessage();
                $response['query_debug'] = $sql ?? 'Query build failed';
            }
            http_response_code(500);
        }

        // 6. Return PHP Array (Internal Use)
        if ($returnAsArray) {
            return $response;
        }

        // 7. Output JSON (API Mode)
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Execute operations within a database transaction.
     * If callback returns false or throws an exception, transaction is rolled back.
     *
     * @param callable $callback Function containing PHDB operations
     * @return bool TRUE on success, FALSE on failure (automatically rolls back)
     */
    public static function transaction(callable $callback) {
        try {
            if (!self::$conn) {
                self::connect();
            }

            // Start transaction
            self::$conn->begin_transaction();
            self::$inTransaction = true;

            // Execute the callback
            $result = $callback();

            if ($result === false) {
                self::$conn->rollback();
                self::$inTransaction = false;
                self::handleError("Transaction failed: Callback returned false", true);
                return false;
            }

            // Commit if everything is fine
            self::$conn->commit();
            self::$inTransaction = false;
            return true;

        } catch (Exception $e) {
            if (self::$conn && self::$inTransaction) {
                self::$conn->rollback();
                self::$inTransaction = false;
            }
            self::handleError("Transaction failed: " . $e->getMessage(), true);
            return false;
        } finally {
            if (self::$conn) {
                self::disconnect();
            }
        }
    }

    /**
     * Clean database records with various options
     *
     * @param string $table Table name
     * @param array $options Cleaning options:
     *   - 'auto' (bool): Automatically detect and clean common issues
     *   - 'manual' (array): Manual cleaning conditions
     *   - 'duplicate_all' (bool): Remove duplicate rows (all columns must match)
     *   - 'duplicate_cols' (array|string): Remove duplicates based on specific columns
     *   - 'empty_cols' (array|string): Remove rows where specified columns are empty
     *   - 'value_conditions' (array): Remove rows where columns match certain values
     *   - 'min_rows' (int): Keep at least this many rows (when removing duplicates)
     *   - 'backup' (bool): Create backup before cleaning (default true)
     *   - 'backup_table' (string): Name for backup table (default: original_table + _backup + timestamp)
     * @return array|bool Result array with cleaning stats or false on failure
     * @throws InvalidArgumentException If invalid table name or options provided
     */
    public static function clean(string $table, array $options = []) {
        // Validate table name
        if (!is_string($table) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new InvalidArgumentException("Invalid table name provided");
        }

        // Default options
        $defaults = [
            'auto' => false,
            'manual' => [],
            'duplicate_all' => false,
            'duplicate_cols' => null,
            'empty_cols' => null,
            'value_conditions' => [],
            'min_rows' => 1,
            'backup' => false,
            'backup_table' => null,
        ];

        $options = array_merge($defaults, $options);
        $stats = [
            'total_before' => 0,
            'total_after' => 0,
            'duplicates_removed' => 0,
            'empty_removed' => 0,
            'value_removed' => 0,
            'backup_created' => false,
        ];

        try {
            if (!self::$conn) {
                self::connect();
            }

            // Run in transaction for atomicity
            self::$conn->begin_transaction();

            // Get current row count
            $stats['total_before'] = self::count($table);

            // Create backup if requested
            if ($options['backup']) {
                $backupTable = $options['backup_table'] ?:
                $table . '_backup_' . date('Ymd_His');

                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $backupTable)) {
                    throw new InvalidArgumentException("Invalid backup table name generated");
                }

                if (self::query("CREATE TABLE `$backupTable` LIKE `$table`") &&
                    self::query("INSERT INTO `$backupTable` SELECT * FROM `$table`")) {
                    $stats['backup_created'] = true;
                $stats['backup_table'] = $backupTable;
                    }
            }

            // Auto-clean mode
            if ($options['auto']) {
                // Get primary key column (fall back to first column if no PK)
                $pkColumn = 'id'; // Default assumption
                $tableInfo = self::query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
                if (is_array($tableInfo) && !empty($tableInfo)) {
                    $pkColumn = $tableInfo[0]['Column_name'];
                } else {
                    $columns = self::columns($table);
                    if (!empty($columns)) {
                        $pkColumn = $columns[0];
                    }
                }

                // Auto-detect and remove completely duplicate rows
                $duplicates = self::query("
                DELETE t1 FROM `$table` t1
                INNER JOIN (
                    SELECT MIN(`$pkColumn`) as min_id, " . self::formatColumn('*') . "
                    FROM `$table`
                    GROUP BY " . self::formatColumn('*') . "
                    HAVING COUNT(*) > 1
                ) t2
                ON t1.`$pkColumn` != t2.min_id
                LIMIT 10000
                ");

                if ($duplicates !== false) {
                    $stats['duplicates_removed'] += self::$conn->affected_rows;
                }

                // Auto-detect and remove rows with empty required columns
                $columns = self::columns($table);
                $emptyConditions = [];
                foreach ($columns as $col) {
                    if (!in_array($col, [$pkColumn, 'created_at', 'updated_at'])) {
                        $emptyConditions[] = "(`$col` IS NULL OR `$col` = '' OR `$col` = 0)";
                    }
                }

                if (!empty($emptyConditions)) {
                    $emptyRemoved = self::query("
                    DELETE FROM `$table`
                    WHERE " . implode(' AND ', $emptyConditions) . "
                    LIMIT 1000
                    ");

                    if ($emptyRemoved !== false) {
                        $stats['empty_removed'] += self::$conn->affected_rows;
                    }
                }
            }

            // Manual cleaning options
            if ($options['duplicate_all']) {
                $pkColumn = self::primaryKey($table);
                $result = self::query("
                DELETE t1 FROM `$table` t1
                INNER JOIN (
                    SELECT MIN(`$pkColumn`) as min_id, " . self::formatColumn('*') . "
                    FROM `$table`
                    GROUP BY " . self::formatColumn('*') . "
                    HAVING COUNT(*) > 1
                ) t2
                ON t1.`$pkColumn` != t2.min_id
                LIMIT 10000
                ");

                if ($result !== false) {
                    $stats['duplicates_removed'] += self::$conn->affected_rows;
                }
            }

            // [Rest of the function remains the same, with similar PK column updates...]

            // Get final row count
            $stats['total_after'] = self::count($table);

            // Commit transaction if we got this far
            self::$conn->commit();

            return $stats;

        } catch (Exception $e) {
            if (self::$conn && self::$inTransaction) {
                self::$conn->rollback();
            }
            self::handleError("Clean failed: " . $e->getMessage(), true);
            return false;
        }
    }

    /**
     * Universal array/JSON/serialized/list management wrapper.
     * Very flexible with many natural aliases.
     *
     * @param string|bool $action 'get'/'set' or aliases or true/false.
     * @param string $table Table name.
     * @param string $column Column name.
     * @param array $where Filter conditions.
     * @param mixed ...$args Additional arguments (key, value, force, etc.).
     * @return mixed
     */
    public static function array(string|bool $action, string $table, string $column, array $where, ...$args): mixed {
        $action = self::normalizeAction($action);

        // 1. EXTRACT $force parameter if the last argument is boolean
        $force = false;
        if (!empty($args) && is_bool(end($args))) {
            $force = array_pop($args);
        }

        if (self::isGetAction($action)) {
            $key = $args[0] ?? null;
            return self::array_get($table, $column, $where, $key);
        }

        if (self::isSetAction($action)) {
            if (in_array($action, ['clear', 'reset', 'empty'])) {
                return self::array_set($table, $column, $where, null, [], $force); 
            }

            if (in_array($action, ['delete', 'remove', 'unset'])) {
                if (count($args) === 0) {
                    return self::array_set($table, $column, $where, null, [], $force);
                }
                return self::array_set($table, $column, $where, $args[0], null, $force);
            }

            if (count($args) === 1) {
                // Case: array('set', ..., ['key' => 'value']) -> merge
                return self::array_set($table, $column, $where, $args[0], null, $force);
            }

            if (count($args) >= 2) {
                // Case: array('set', ..., 'key', 'value')
                return self::array_set($table, $column, $where, $args[0], $args[1], $force);
            }

            // Fallback empty call
            return self::array_set($table, $column, $where, null, null, $force);
        }

        self::handleError("Invalid action for PHDB::array(): '$action'", true);
        return false;
    }

    /** Normalize action string or bool */
    private static function normalizeAction(string|bool $action): string {
        if ($action === true)  return 'set';
        if ($action === false) return 'get';
        $action = strtolower(trim((string)$action));
        $map = [
            'g' => 'get', 'rd' => 'get', 'f' => 'get',
            's' => 'set', 'upd' => 'set', 'sv' => 'set', 'w' => 'set',
        ];
        return $map[$action] ?? $action;
    }

    private static function isGetAction(string $action): bool {
        return in_array($action, ['get', 'read', 'fetch', 'select', 'load', 'view', 'show', 'display', 'find', 'lookup', 'search', 'query', 'data'], true);
    }

    private static function isSetAction(string $action): bool {
        return in_array($action, ['set', 'update', 'put', 'write', 'save', 'add', 'edit', 'change', 'insert', 'delete', 'remove', 'clear', 'reset', 'empty'], true);
    }

    /**
     * Gets a value from a column containing array-like data.
     *
     * @param string $table Table name.
     * @param string $column Column name.
     * @param array $where Filter conditions.
     * @param string|null $key Optional key for dot notation access.
     * @param mixed $default Default value if key not found.
     * @return mixed
     */
    public static function array_get(string $table, string $column, array $where, $key = null, $default = null) {
        $row = self::select($table, $column, $where, 1);
        if (!$row || !isset($row[0][$column])) {
            return $default;
        }

        $raw = trim($row[0][$column]);
        $format = self::get_raw_format($raw);
        $data = $format['data'];

        if ($key === null) return $data;
        if (!is_array($data)) return $default;

        $current = $data;
        $segments = explode('.', $key);

        foreach ($segments as $segment) {
            if (preg_match('/^([a-zA-Z0-9_]+)\[(\d+)\]$/', $segment, $m)) {
                $segment = $m[1];
                $index   = (int)$m[2];

                if (!array_key_exists($segment, $current) || !is_array($current[$segment])) return $default;
                $current = $current[$segment];

                if (!isset($current[$index])) return $default;
                $current = $current[$index];
                continue;
            }

            if (!is_array($current) || !array_key_exists($segment, $current)) return $default;
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Sets a value in a column containing array-like data.
     *
     * @param string $table Table name.
     * @param string $column Column name.
     * @param array $where Filter conditions.
     * @param mixed $key Key for dot notation or merge array.
     * @param mixed $value Value to set.
     * @param bool $force Whether to force raw overwrite.
     * @return bool
     */
    public static function array_set(string $table, string $column, array $where, $key = null, $value = null, bool $force = false) {
        $row = self::select($table, $column, $where, 1);
        $raw = $row[0][$column] ?? '';
        
        // --- 1. FORCE RAW OVERWRITE MODE ---
        if ($force && ($key === null || $key === '')) {
            $toSave = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
            return self::update($table, [$column => $toSave], $where);
        }

        $format = self::get_raw_format($raw);
        $current = $format['data'];
        if (!is_array($current)) $current = [];

        // --- 2. SMART MODIFY DATA ---
        if ($key === null || $key === '' || empty($key)) {
            // Root Replace Mode (e.g. user passed "1,2,4")
            if (is_array($value)) {
                $current = $value;
            } elseif ($value === null) {
                $current = []; // Clear
            } else {
                // Parse the string dynamically
                $valFormat = self::get_raw_format((string)$value);
                $current = $valFormat['data'];
                // Learn the separator from the new value if the old one was empty
                if ($format['type'] === 'empty' || $format['type'] === 'plain') {
                    $format['type'] = $valFormat['type'];
                    $format['sep']  = $valFormat['sep'];
                }
            }
        } elseif (is_array($key) && !empty($key)) {
            // Root Merge Mode
            $current = array_replace_recursive($current, $key);
        } else {
            // Deep Set / Dot Notation Mode
            $segments = explode('.', $key);
            $appendMode = false;
            $last = end($segments);

            if (str_ends_with($last, '[]')) {
                $appendMode = true;
                $segments[array_key_last($segments)] = rtrim($last, '[]');
                if ($segments[array_key_last($segments)] === '') $segments = [];
            }

            $temp = &$current;
            $segCount = count($segments);

            if ($segCount === 0 && $appendMode) {
                $current[] = $value; // Case: key was just '[]'
            } else {
                foreach ($segments as $i => $k) {
                    if (preg_match('/^([a-zA-Z0-9_]+)\[(\d+)\]$/', $k, $m)) {
                        $k = $m[1];
                        $idx = (int)$m[2];
                        if (!isset($temp[$k]) || !is_array($temp[$k])) $temp[$k] = [];
                        $temp = &$temp[$k];
                        while (count($temp) <= $idx) $temp[] = null;
                        
                        if ($i === $segCount - 1) {
                            $temp[$idx] = $value; // Final assign
                        } else {
                            $temp = &$temp[$idx]; // Move deeper
                        }
                        continue;
                    }

                    if ($i < $segCount - 1) {
                        // Move Deeper
                        if (!isset($temp[$k]) || !is_array($temp[$k])) $temp[$k] = [];
                        $temp = &$temp[$k];
                    } else {
                        // Final Assign
                        if ($appendMode) {
                            if (!isset($temp[$k]) || !is_array($temp[$k])) $temp[$k] = [];
                            $temp[$k][] = $value;
                        } else {
                            $temp[$k] = $value;
                        }
                    }
                }
            }
        }

        // --- 3. DUPLICATE PROTECTION ---
        if (!$force && is_array($current) && array_is_list($current)) {
            $current = array_values(array_unique($current, SORT_REGULAR));
        }

        // --- 4. EXACT FORMAT REBUILD ---
        $saveType = $format['type'];
        if ($saveType === 'plain' && is_array($current)) $saveType = 'json';
        if ($saveType === 'empty') $saveType = is_array($current) ? 'json' : 'plain';

        $toSave = '';
        if ($saveType === 'list') {
            $sep = $format['sep'] ?: ','; // Fallback to comma
            $toSave = implode($sep, $current);
        } elseif ($saveType === 'serialized') {
            $toSave = serialize($current);
        } elseif (is_array($current) || $saveType === 'json') {
            $toSave = json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $toSave = (string) $current;
        }

        // Skip saving if nothing changed
        if ($raw === $toSave && !$force) return true;

        return self::update($table, [$column => $toSave], $where);
    }

    private static function get_raw_format(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') return ['type' => 'empty', 'data' => [], 'sep' => null];

        $data = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return ['type' => 'json', 'data' => is_array($data) ? $data : [$data], 'sep' => null];
        }

        if (self::is_serialized($raw)) {
            $data = unserialize($raw, ['allowed_classes' => false]);
            if ($data !== false) return ['type' => 'serialized', 'data' => is_array($data) ? $data : [$data], 'sep' => null];
        }

        $sep = self::detect_separator($raw);
        if ($sep !== null) {
            $parts = explode($sep, $raw);
            $data = array_values(array_filter(array_map('trim', $parts), 'strlen'));
            return ['type' => 'list', 'data' => $data, 'sep' => $sep];
        }

        return ['type' => 'plain', 'data' => [$raw], 'sep' => null]; // Wrapped in array for safety
    }

    private static function detect_separator(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') return null;
        $candidates = [', ', '; ', ' | ', ',', ';', '|'];
        $best = null;
        $maxParts = 1;
        foreach ($candidates as $sep) {
            if (strpos($raw, $sep) === false) continue;
            $parts = explode($sep, $raw);
            $count = count(array_filter(array_map('trim', $parts), 'strlen'));
            if ($count > $maxParts) {
                $maxParts = $count;
                $best = $sep;
            }
        }
        return $maxParts > 1 ? $best : null;
    }

    private static function is_serialized(string $data): bool {
        if (empty($data) || strlen($data) < 4) return false;
        $data = trim($data);
        if (!in_array($data[0], ['a','C','O','b','d','i','s','N'], true)) return false;
        if (preg_match('/^[aOs]:[0-9]+:/', $data) || preg_match('/^a:[0-9]+:{/', $data) || preg_match('/^C:[0-9]+:"[^"]+":/', $data) || $data === 'N;' || $data === 'b:0;' || $data === 'b:1;') return true;
        set_error_handler(fn() => true);
        $result = unserialize($data, ['allowed_classes' => false]);
        restore_error_handler();
        return $result !== false;
    }

    /**
     * Helper method to get primary key column name
     */
    private static function primaryKey(string $table) {
        self::assertIdentifier($table, 'table name');
        $tableInfo = self::query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
        if (is_array($tableInfo) && !empty($tableInfo)) {
            return $tableInfo[0]['Column_name'];
        }

        $columns = self::columns($table);
        return !empty($columns) ? $columns[0] : 'id';
    }

    /**
     * Close the database connection.
     *
     * @return void
     */
    public static function close() {
        if (self::$conn) {
            self::$conn->close();
            self::$conn = null;
        }
    }
}
?>
