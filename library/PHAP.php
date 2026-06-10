<?php

/**
 * ============================================================================
 * Class: PHAP
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * This class is a core component of the MyStack framework.
 * It provides essential functionalities tailored for high performance and security.
 */




class PHAP
{
    private static array $hiddenFields = ['password', 'pwd', 'token', 'secret', 'hash', 'api_key', 'private_key', 'salt', 'remember_token'];

    // ==========================================
    // 👑 MASTER API METHOD (Positional - The Easiest Way)
    // ==========================================

    /**
     * MASTER API METHOD (Positional - The Easiest Way)
     * PHAP::api('POST /api/v1/register', 'auth', ['email' => 'required'], function($d) { ... });
     * 
     * @param string $signature "METHOD /path" (e.g., "GET /users" or "POST /save")
     * @param mixed  $middleware Boolean (true = auth), string, or callback
     * @param array  $rules      Validation rules for input
     * @param mixed  $logic      Table name (Smart CRUD) or Callback/Output
     * @param string $msg        Custom Success Message
     * @return void
     */
    public static function api(
        string $signature, 
        mixed $middleware = false, 
        array $rules = [], 
        mixed $logic = null, 
        string $msg = "Success"
    ): void {
        if (!class_exists('PHRO')) return;

        // 1. Parse Signature (e.g., "GET /api/products")
        $parts = explode(' ', trim($signature), 2);
        if (count($parts) === 2) {
            $method = strtoupper($parts[0]);
            $route  = $parts[1];
        } else {
            $method = 'GET';
            $route  = $signature;
        }

        $routerMethod = strtolower($method);
        if (!method_exists('PHRO', $routerMethod)) $routerMethod = 'any';

        PHRO::$routerMethod($route, function() use ($middleware, $rules, $logic, $msg) {
            // 2. Middleware/Auth Check
            if ($middleware === true || $middleware === 'auth' || (is_array($middleware) && in_array('auth', $middleware))) {
                self::auth();
            } elseif (is_callable($middleware)) {
                if ($middleware(self::input()) === false) self::fail("Forbidden", 403);
            }

            // 3. Logic Execution & Output
            if (is_string($logic) && !str_contains($logic, '->')) {
                // Smart CRUD: Auto-detect based on method
                $table = $logic;
                $id = self::input('id') ?? self::input('idx');

                switch (strtoupper($_SERVER['REQUEST_METHOD'])) {
                    case 'GET':    $id ? self::get($table, $id) : self::all($table); break;
                    case 'POST':   self::add($table, $rules); break;
                    case 'PUT':    case 'PATCH': self::up($table, $id, $rules); break;
                    case 'DELETE': self::rm($table, $id); break;
                }
            } elseif (is_callable($logic)) {
                self::run($logic, $rules, $msg);
            } else {
                self::fail("No valid logic defined for this route");
            }
        });
    }

    // ==========================================
    // 🚀 MAGIC CRUD METHODS (The Easiest Way)
    // ==========================================

    /**
     * PHAP::all('users') -> Returns paginated list
     *
     * @param string $table Table name.
     * @param array $where Filter conditions.
     * @param int $limit Pagination limit.
     * @return void
     */
    public static function all(string $table, array $where = [], int $limit = 15): void
    {
        self::page($table, $where, $limit);
    }

    /**
     * PHAP::get('users', 5) -> Returns single item
     *
     * @param string $table Table name.
     * @param mixed $id Record ID.
     * @param string $col ID column name.
     * @return void
     */
    public static function get(string $table, mixed $id, string $col = 'id'): void
    {
        $res = PHDB::select($table, '*', [$col => $id]);
        if (!$res) self::fail("Item not found", 404);
        self::ok($res[0]);
    }

    /**
     * PHAP::add('users', ['email' => 'required|email']) -> Inserts data
     *
     * @param string $table Table name.
     * @param array $rules Validation rules.
     * @param string $msg Success message.
     * @return void
     */
    public static function add(string $table, array $rules = [], string $msg = "Record created"): void
    {
        self::run(fn($d) => PHDB::insert($table, $d), $rules, $msg);
    }

    /**
     * PHAP::up('users', 5, ['name' => 'required']) -> Updates data
     *
     * @param string $table Table name.
     * @param mixed $id Record ID.
     * @param array $rules Validation rules.
     * @param string $col ID column name.
     * @return void
     */
    public static function up(string $table, mixed $id, array $rules = [], string $col = 'id'): void
    {
        self::run(fn($d) => PHDB::update($table, $d, [$col => $id]), $rules, "Record updated");
    }

    /**
     * PHAP::rm('users', 5) -> Deletes data
     *
     * @param string $table Table name.
     * @param mixed $id Record ID.
     * @param string $col ID column name.
     * @return void
     */
    public static function rm(string $table, mixed $id, string $col = 'id'): void
    {
        $res = PHDB::delete($table, [$col => $id]);
        $res ? self::ok([], "Record deleted") : self::fail("Delete failed");
    }

    // ==========================================
    // 🛠 CORE ENGINE METHODS
    // ==========================================

    /**
     * A-Z API EXECUTION ENGINE
     *
     * @param callable $logic Logic function to execute.
     * @param array $rules Validation rules.
     * @param string $successMsg Success message.
     * @return void
     */
    public static function run(callable $logic, array $rules = [], string $successMsg = "Action Successful"): void
    {
        try {
            $input = !empty($rules) ? self::valid($rules) : self::input();
            $result = $logic($input);

            if (is_array($result) && isset($result['status']) && $result['status'] === false) {
                self::fail($result['message'] ?? "Operation failed", $result['code'] ?? 400);
            }

            self::ok($result, $successMsg);
        } catch (\Throwable $e) {
            self::fail($e->getMessage(), 500);
        }
    }

    /**
     * Universal Input Handler (JSON, POST, GET, Route Params)
     * Automatically detects and sanitizes input.
     *
     * @param string|null $key Input key.
     * @param mixed $default Default value.
     * @return mixed
     */
    public static function input(string $key = null, mixed $default = null): mixed
    {
        static $data = null;
        if ($data === null) {
            $json = json_decode(file_get_contents('php://input'), true) ?? [];
            $params = class_exists('PHRO') ? (PHRO::data()['params'] ?? []) : [];
            $data = array_merge($_GET, $_POST, $json, $params);
            
            // Sanitize: Basic XSS protection for all inputs
            array_walk_recursive($data, function(&$item) {
                if (is_string($item)) $item = htmlspecialchars(trim($item), ENT_QUOTES, 'UTF-8');
            });
        }

        if ($key) return $data[$key] ?? $default;
        return $data;
    }

    /**
     * Smart Resource Transformer
     * Automatically handles Item or Collection based on data structure.
     *
     * @param mixed $data Data to transform.
     * @param callable|null $callback Optional transformer callback.
     * @return mixed
     */
    public static function resource(mixed $data, ?callable $callback = null): mixed
    {
        if (empty($data)) return [];
        
        // If it's a numeric array, it's a collection
        if (isset($data[0]) && is_array($data[0])) {
            return self::collection($data, $callback);
        }
        
        return self::item($data, $callback);
    }

    /**
     * Quick Success Response (200 OK)
     *
     * @param mixed $data Response data.
     * @param string $msg Success message.
     * @return void
     */
    public static function ok(mixed $data = [], string $msg = "Success"): void
    {
        self::send(self::resource($data), $msg, 200, true);
    }

    /**
     * Quick Failure Response
     *
     * @param string $msg Failure message.
     * @param int $code HTTP status code.
     * @return void
     */
    public static function fail(string $msg = "Action Failed", int $code = 400): void
    {
        self::send([], $msg, $code, false);
    }

    /**
     * Auto-Validate Request and Exit on Failure
     *
     * @param array $rules Validation rules.
     * @param array $customData Data to validate (defaults to input).
     * @return array Validated data.
     */
    public static function valid(array $rules, array $customData = []): array
    {
        $data = !empty($customData) ? $customData : self::input();
        $val = PHVD::check($rules, $data);
        
        if (!$val['result']) {
            self::fail($val['message'], 400);
        }
        
        return $data;
    }

    /**
     * Smart Database Pagination Response
     *
     * @param string $table Table name.
     * @param array $where Filter conditions.
     * @param int $limit Records per page.
     * @param callable|null $callback Optional transformer callback.
     * @return void
     */
    public static function page(string $table, array $where = [], int $limit = 15, ?callable $callback = null): void
    {
        $page = (int)(self::input('page', 1));
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $limit;

        $totalRes = PHDB::query("SELECT COUNT(*) as total FROM `$table`" . (empty($where) ? "" : " WHERE " . self::buildWhere($where)), array_values($where));
        $total = $totalRes[0]['total'] ?? 0;

        $data = PHDB::select($table, '*', $where, "LIMIT $limit OFFSET $offset");
        $items = self::collection($data, $callback);

        $response = [
            'items' => $items,
            'meta' => [
                'total'        => (int)$total,
                'current_page' => $page,
                'per_page'     => $limit,
                'last_page'    => ceil($total / $limit),
                'has_more'     => ($offset + $limit) < $total
            ]
        ];

        self::send($response, "Page $page loaded", 200, true);
    }

    /**
     * Quick Auth Check (Returns user data or fails)
     *
     * @param string $table Auth table name.
     * @return array User data.
     */
    public static function auth(string $table = 'users'): array
    {
        $check = PHAU::check($table);
        if (!$check['status']) {
            self::fail("Unauthorized access", 401);
        }
        return $check['data'];
    }

    /**
     * Standardized JSON Response
     *
     * @param mixed $data Response data.
     * @param string $message Success/Failure message.
     * @param int $code HTTP status code.
     * @param bool $status Success status.
     * @return void
     */
    public static function send(mixed $data = [], string $message = "Success", int $code = 200, bool $status = true): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *'); // Basic CORS Support
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        }

        echo json_encode([
            'status'  => $status,
            'code'    => $code,
            'message' => $message,
            'data'    => self::clean($data),
            'time'    => time()
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    private static function buildWhere(array $where): string
    {
        $cond = [];
        foreach (array_keys($where) as $key) {
            $cond[] = "`$key` = ?";
        }
        return implode(' AND ', $cond);
    }

    /**
     * Transforms a single item.
     *
     * @param mixed $data Item data.
     * @param callable|null $callback Optional transformer callback.
     * @return array
     */
    public static function item(mixed $data, ?callable $callback = null): array
    {
        if (empty($data)) return [];
        return is_callable($callback) ? $callback($data) : self::clean($data);
    }

    /**
     * Transforms a collection of items.
     *
     * @param array $data Collection data.
     * @param callable|null $callback Optional transformer callback.
     * @return array
     */
    public static function collection(array $data, ?callable $callback = null): array
    {
        if (empty($data)) return [];
        return array_map(fn($i) => self::item($i, $callback), $data);
    }

    /**
     * Cleans sensitive fields from data.
     *
     * @param mixed $data Data to clean.
     * @param array $extraFields Additional fields to hide.
     * @return mixed
     */
    public static function clean(mixed $data, array $extraFields = []): mixed
    {
        if (!is_array($data) && !is_object($data)) return $data;
        $res = is_object($data) ? (array)$data : $data;
        $masks = array_merge(self::$hiddenFields, $extraFields);

        foreach ($res as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $res[$k] = self::clean($v, $extraFields);
            }
            foreach ($masks as $m) {
                if (stripos((string)$k, $m) !== false) {
                    unset($res[$k]); break;
                }
            }
        }
        return is_object($data) ? (object)$res : $res;
    }
}
?>