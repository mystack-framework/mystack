<?php

/**
 * ============================================================================
 * Class: PHEV (PHP Events & WebSocket Server)
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * PHEV is the real-time heartbeat of the MyStack framework. It provides a native, 
 * robust WebSocket server and asynchronous event-loop system directly in PHP.
 *
 * Features:
 * - Real-time bi-directional communication over WebSockets (ws://).
 * - StreamUI Support for real-time frontend component hydration.
 * - Custom Event listeners and emitters.
 * - Client management, connection broadcasting, and handshake processing.
 * 
 * Usage Example:
 * ```php
 * // Start the WebSocket server on port 8000
 * PHEV::initialize('/websocket', '0.0.0.0', 8000);
 * 
 * // Listen for messages
 * PHEV::on('message', function($clientId, $message) {
 *     PHEV::broadcast("User $clientId says: $message");
 * });
 * ```
 */


class PHEV {
    private static $address = '0.0.0.0';
    private static $port = 8000;
    private static $socket;
    private static $clients = [];
    private static $clientIds = [];
    private static $clientPaths = [];
    private static $clientMethods = [];
    private static $handlers = [];
    private static bool $running = false;
    private static bool $allowWebWorker = false;

    /** Explicit compatibility switch for hosts that intentionally run a socket loop in a web worker. */
    public static function allowWebWorker(bool $allow = true): void {
        self::$allowWebWorker = $allow;
    }

    public static function initialize($path = '/websocket', $address = '0.0.0.0', $port = 8000) {
        self::$address = $address ?? parse_url(PHRO::root())['host'];
        self::$port = $port;
        PHRO::add('WS', $path, function() {
            PHRQ::header('WS', '*', 'application/json; charset=utf-8', []);
            $result = PHEV::start();
            if (PHP_SAPI !== 'cli' && is_string($result)) {
                echo $result;
            }
        });
    }

    public static function start() {
        if (PHP_SAPI !== 'cli' && !self::$allowWebWorker) {
            if (!headers_sent()) http_response_code(503);
            return 'WebSocket workers must be started from CLI. Call PHEV::allowWebWorker(true) only for a dedicated web worker.';
        }
        set_time_limit(0);
        if (self::isPortInUse()) {
            echo "WebSocket server is already running on ws://".self::$address.":".self::$port."\n";
            return "WebSocket server is already running on ws://".self::$address.":".self::$port."\n";
        }
        self::$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!self::$socket) {
            die("Failed to create socket: " . socket_strerror(socket_last_error()) . "\n");
        }
        if (!socket_set_option(self::$socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            die("Failed to set socket option: " . socket_strerror(socket_last_error(self::$socket)) . "\n");
        }
        if (!@socket_bind(self::$socket, self::$address, self::$port)) {
            die("Failed to bind socket: " . socket_strerror(socket_last_error(self::$socket)) . "\n");
        }
        if (!socket_listen(self::$socket)) {
            die("Failed to listen on socket: " . socket_strerror(socket_last_error(self::$socket)) . "\n");
        }
        echo "WebSocket server started at ws://".self::$address.":".self::$port."\n";
        self::$running = true;
        self::run();
    }

    public static function restart() {
        self::stop();
        self::start();
    }
    
    public static function stop() {
        self::$running = false;
        if (self::$socket) {
            foreach (self::$clients as $client) {
                if (self::isSocket($client)) {
                    @socket_shutdown($client, 2);
                    @socket_close($client);
                }
            }
            self::$clients = [];
            self::$clientIds = [];
            self::$clientPaths = [];
            self::$clientMethods = [];
            if (self::isSocket(self::$socket)) {
                @socket_shutdown(self::$socket, 2);
                @socket_close(self::$socket);
            }
            self::$socket = null;
            echo "WebSocket server forcefully stopped.\n";
        } else {
            echo "No active WebSocket server to stop.\n";
        }
    }

    public static function running(): bool {
        return self::$running;
    }

    private static function isSocket($socket): bool {
        return is_resource($socket) || (class_exists('Socket', false) && $socket instanceof \Socket);
    }

    public static function clients() {
        $clientInfo = [];
        foreach (self::$clientIds as $clientId => $socket) {
            $clientInfo[] = [
                'id' => $clientId,
                'path' => self::$clientPaths[$clientId],
                'method' => self::$clientMethods[$clientId]
            ];
        }
        return $clientInfo;
    }

    public static function debugClients() {
        echo "Clients Array:\n";
        print_r(self::$clients);
        echo "Client IDs Array:\n";
        print_r(self::$clientIds);
        echo "Client Paths Array:\n";
        print_r(self::$clientPaths);
        echo "Client Methods Array:\n";
        print_r(self::$clientMethods);
    }

    private static function isPortInUse() {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$socket) {
            return true; 
        }

        if (!@socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            socket_close($socket);
            return true;
        }

        $result = @socket_bind($socket, self::$address, self::$port);
        if ($result === false) {
            return true; 
        }

        socket_close($socket);
        return false; 
    }

    private static function run() {
        while (self::$running && self::isSocket(self::$socket)) {
            $changed = self::$clients;
            $changed[] = self::$socket;

            $write = null;
            $except = null;
            $selected = @socket_select($changed, $write, $except, 1);
            if ($selected === false) {
                if (!self::$running) break;
                usleep(10000);
                continue;
            }
            if ($selected === 0) continue;

            if (in_array(self::$socket, $changed)) {
                self::handleNewConnection();
                unset($changed[array_search(self::$socket, $changed)]);
            }

            foreach ($changed as $changedSocket) {
                $buffer = '';
                $bytesReceived = @socket_recv($changedSocket, $buffer, 1024, 0);

                if ($bytesReceived === false || $bytesReceived === 0) {
                    self::handleClientDisconnection($changedSocket);
                    continue;
                }

                if ($bytesReceived > 0) {
                    $buffer = self::unmask($buffer);
                    $clientId = self::getClientIdBySocket($changedSocket);
                    echo "Received message [$clientId]: $buffer\n";

                    if ($clientId !== false) { 
                        self::handleMessage($buffer, $clientId);
                    }

                    // $response = self::mask($buffer);
                    // @socket_write($changedSocket, $response, strlen($response));
                    // echo "Successfully echoed back to client: $buffer\n";
                }
            }
        }
        self::$running = false;
    }    

    private static function handleNewConnection() {
        $newSocket = socket_accept(self::$socket);
        if ($newSocket === false) {
            echo "Failed to accept new connection: " . socket_strerror(socket_last_error(self::$socket)) . "\n";
            return;
        }
        self::$clients[] = $newSocket;
        $header = socket_read($newSocket, 1024);
        if (preg_match('/GET (\/[^\s]*) HTTP/', $header, $matches)) {
            $requestPath = ltrim($matches[1], '/'); // Get path without leading slash
            preg_match('/(GET|POST|PUT|DELETE|OPTIONS|HEAD) /', $header, $methodMatches);
            $requestMethod = isset($methodMatches[1]) ? $methodMatches[1] : 'GET'; // Default to GET if not found
            $clientId = count(self::$clientIds) + 1; // Ensure unique ID
            self::$clientIds[$clientId] = $newSocket; // Associate the ID with the socket
            self::$clientPaths[$clientId] = $requestPath; // Store client's request path
            self::$clientMethods[$clientId] = $requestMethod; // Store client's request method
            preg_match('/Sec-WebSocket-Key: (.*)\r\n/', $header, $matches);
            $secKey = trim($matches[1]);
            $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
            $responseHeader = "HTTP/1.1 101 Switching Protocols\r\n".
                              "Upgrade: websocket\r\n".
                              "Connection: Upgrade\r\n".
                              "Sec-WebSocket-Accept: $secAccept\r\n\r\n";
            socket_write($newSocket, $responseHeader, strlen($responseHeader));
            echo "Client connected on path: $requestPath with method: $requestMethod, ID: $clientId\n";
            $welcomeMessage = "Welcome to the WebSocket server! Your ID is $clientId. Your path is '$requestPath'. Your method is '$requestMethod'.";
            $maskedMessage = self::mask($welcomeMessage);
            socket_write($newSocket, $maskedMessage, strlen($maskedMessage));
        }
    }

    private static function listPhpFiles($directory = '', $acceptDirectories = ['app'], $skipFiles = []) {
        if (!$directory || !is_dir($directory)) {
            return ["Error: The directory does not exist or is invalid: $directory"];
        }
        $directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );
        } catch (Exception $e) {
            return ["Error: Unable to read the directory. Exception: " . $e->getMessage()];
        }
        $phpFiles = [];
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $relativePath = str_replace($directory, '', $fileInfo->getPathname());
                $pathParts = explode(DIRECTORY_SEPARATOR, $relativePath);
                $topLevelFolder = $pathParts[0] ?? '';
                $fileName = $fileInfo->getFilename();
                $isInRoot = empty($topLevelFolder) || $topLevelFolder === $fileName;
                $isAcceptedFolder = in_array($topLevelFolder, $acceptDirectories, true);
                if (($isInRoot || $isAcceptedFolder) && !in_array($fileName, $skipFiles, true)) {
                    $phpFiles[] = $fileInfo->getPathname();
                }
            }
        }
        return $phpFiles;
    }

    private static function findFunctionInFiles(array $fileList, string $keyword) {
        if (empty($fileList) || empty($keyword)) {
            return false;
        }
        $escapedKeyword = preg_quote($keyword, '/');
        $regex = '/^\s*PHEV::handler\(\s*[\'\"]' . $escapedKeyword . '[\'\"]\s*/';
        foreach ($fileList as $filePath) {
            if (!file_exists($filePath) || !is_readable($filePath)) {
                continue;
            }
            $lines = file($filePath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $lineNumber => $lineContent) {
                if (preg_match($regex, $lineContent)) {
                    return [
                        'file' => $filePath,
                        'line_number' => $lineNumber + 1,
                        'code' => trim($lineContent),
                    ];
                }
            }
        }
        return false;
    }

    private static function extractFunction($file, $startPattern) {
        if (!file_exists($file)) {
            return null;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $functionCode = [];
        $braceCount = 0;
        $isCapturing = false;
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (!$isCapturing) {
                if (preg_match('/' . preg_quote($startPattern, '/') . '/', $trimmedLine)) {
                    $isCapturing = true;
                }
            }
            if ($isCapturing) {
                $functionCode[] = $line;
                $braceCount += substr_count($line, '{') - substr_count($line, '}');
                if ($braceCount === 0) {
                    break;
                }
            }
        }
        if (!$functionCode) {
            return null;
        }
        array_shift($functionCode);
        array_pop($functionCode);
        $extractedCode = implode("\n", $functionCode);
        $cleanedCode = preg_replace('/^\s*\n+/m', '', $extractedCode);
        return $cleanedCode ?: null;
    }

    public static function getHandler($message) {
        foreach (self::$handlers as $pathHandlers) {
            if (isset($pathHandlers[$message]) && is_callable($pathHandlers[$message])) {
                return $pathHandlers[$message];
            }
        }
        return false;
    }

    public static function handler($requestPath, $action, $handler) {
        if (is_callable($handler)) {
            if (!isset(self::$handlers[$requestPath])) {
                self::$handlers[$requestPath] = [];
            }
            self::$handlers[$requestPath][$action] = $handler;
            return true;
        }
        return false;
    }

    private static function handleMessage($message, $clientId) {
        $message = trim($message);
        $clientPath = self::$clientPaths[$clientId];
        $clientMethod = self::$clientMethods[$clientId];
        try {
            $data = json_decode($message, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON: ' . json_last_error_msg());
            }
            echo "Handling message from client $clientId (Path: $clientPath, Method: $clientMethod): $message\n";
            if (isset($data['action'])) {
                $action = $data['action'];
                if (isset(self::$handlers[$clientPath][$action])) {
                    $handler = self::$handlers[$clientPath][$action];
                    if (is_callable($handler)) {
                        $response = call_user_func($handler, $clientId, $clientPath, $clientMethod, $data);
                        if ($response !== null) {
                            $responseMessage = self::mask($response);
                            @socket_write(self::$clientIds[$clientId], $responseMessage, strlen($responseMessage));
                            echo "Response sent to client $clientId: $response\n";
                        }
                        return true;
                    }
                }
            }
            echo "No handler for path: $clientPath and action: " . ($data['action'] ?? 'undefined') . "\n";
        } catch (Exception $e) {
            echo 'Error handling message: ', $e->getMessage(), "\n";
            $errorMessage = "Error: " . $e->getMessage();
            self::message($clientId, $errorMessage);
        }
    }
    
    public static function message($clientId, $message) {
        if (isset(self::$clientIds[$clientId])) {
            $clientSocket = self::$clientIds[$clientId];
            $formattedMessage = self::mask($message);
            @socket_write($clientSocket, $formattedMessage, strlen($formattedMessage));
            echo "Sent message to Client ID $clientId: $message\n";
        } else {
            echo "Client ID $clientId does not exist.\n";
        }
    }

    public static function broadcast($message) {
        foreach (self::$clientIds as $clientId => $clientSocket) {
            if (isset($clientSocket)) {
                $formattedMessage = self::mask($message);
                @socket_write($clientSocket, $formattedMessage, strlen($formattedMessage));
                echo "Broadcasted message to Client ID $clientId: $message\n";
            }
        }
    }

    private static function handleClientDisconnection($socket) {
        $clientId = self::getClientIdBySocket($socket);
        if ($clientId !== false) {
            unset(self::$clients[array_search($socket, self::$clients)]);
            unset(self::$clientIds[$clientId]); 
            unset(self::$clientPaths[$clientId]);
            unset(self::$clientMethods[$clientId]);
            echo "Client with ID $clientId disconnected.\n";
        }
        socket_close($socket);
    }

    private static function getClientIdBySocket($socket) {
        foreach (self::$clientIds as $clientId => $clientSocket) {
            if ($clientSocket === $socket) {
                return $clientId;
            }
        }
        return false; // Return false if not found
    }

    private static function unmask($text) {
        $length = ord($text[1]) & 127;
        
        if ($length == 126) {
            $length = unpack('n', substr($text, 2, 2))[1];
            $mask = substr($text, 4, 4);
            $data = substr($text, 8);
        } elseif ($length == 127) {
            $length = unpack('P', substr($text, 2, 8))[1];
            $mask = substr($text, 10, 4);
            $data = substr($text, 14);
        } else {
            $mask = substr($text, 2, 4);
            $data = substr($text, 6);
        }

        $decoded = '';
        for ($i = 0; $i < $length; $i++) {
            $decoded .= $data[$i] ^ $mask[$i % 4];
        }

        return $decoded;
    }

    private static function mask($text) {
        $length = strlen($text);
        $frame = '';

        $frame .= chr(129); // FIN + text frame

        if ($length <= 125) {
            $frame .= chr($length);
        } elseif ($length >= 126 && $length <= 65535) {
            $frame .= chr(126);
            $frame .= pack('n', $length);
        } else {
            $frame .= chr(127);
            $frame .= pack('P', $length);
        }

        $frame .= $text;

        return $frame;
    }

    public static function disconnect($clientId = null) {
        if ($clientId !== null && isset(self::$clientIds[$clientId])) {
            $clientSocket = self::$clientIds[$clientId];
            @socket_shutdown($clientSocket, 2);
            @socket_close($clientSocket);
            unset(self::$clients[array_search($clientSocket, self::$clients)]);
            unset(self::$clientIds[$clientId]);
            echo "Disconnected Client ID $clientId.\n";
        } elseif ($clientId === null) {
            foreach (self::$clients as $socket) {
                @socket_shutdown($socket, 2);
                @socket_close($socket);
            }
            self::$clients = [];
            self::$clientIds = [];
            echo "Disconnected all clients.\n";
        } else {
            echo "Client ID $clientId does not exist.\n";
        }
    }


    

    ////////////////////////////////////////////////////////////////////////////




    // Default retry interval (in milliseconds)
    public static $retry = 1000;

    /**
     * Initialize SSE headers
     */
    public static function initHeaders() {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache'); // Prevent caching
        header('Connection: keep-alive'); // Keep connection open
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1'); // Disable gzip for smooth streaming
        }
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        @ini_set('implicit_flush', '1');
        ob_implicit_flush(true); // Automatically flush output
        while (ob_get_level()) {
            ob_end_flush(); // Clear any existing output buffers
        }
    }

    /**
     * Send data to the client
     *
     * @param string $data The event data
     * @param string|null $event Optional event name
     * @param int|null $id Optional event ID
     */
    public static function sendSE($data, $event = null, $id = null) {
        if (connection_aborted()) {
            exit;
        }
        if ($id !== null) {
            echo "id: $id\n";
        }
        if ($event !== null) {
            echo "event: $event\n";
        }
        echo "data: " . str_replace("\n", "\ndata: ", $data) . "\n\n";
        @ob_flush();
        @flush();
    }

    /**
     * Set a retry interval for the client
     *
     * @param int $milliseconds Retry interval in milliseconds
     */
    public static function setRetry($milliseconds) {
        echo "retry: $milliseconds\n\n";
        @ob_flush();
        @flush();
    }



    /**
     * Start streaming data continuously
     *
     * @param callable $callback A callback function for generating data
     * @param int $interval Interval in milliseconds between each event
     */
    public static function stream(callable $callback, int $interval = 10000) {
        self::initHeaders();
        self::setRetry(self::$retry = $interval);

        $startTime = microtime(true);

        while (true) {
            if (connection_aborted()) {
                break;
            }
            try {
                $currentData = $callback();
                if ($currentData instanceof \Stringable || is_scalar($currentData)) {
                    $currentData = (string) $currentData;
                } elseif ($currentData === null) {
                    $currentData = '';
                } else {
                    $currentData = json_encode($currentData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                self::sendSE($currentData);
            } catch (\Throwable $e) {
                $currentData = json_encode([
                    'error' => true,
                    'message' => class_exists('PHDE', false) && PHDE::isDebug() ? $e->getMessage() : 'Stream callback failed.',
                ]);
                self::sendSE($currentData);
            }
            $elapsedTime = (microtime(true) - $startTime) * 1000;
            $sleepTime = max(0, $interval - $elapsedTime);
            // echo ": keep-alive\n\n";
            @ob_flush();
            @flush();

            usleep($sleepTime * 1000);
            $startTime = microtime(true);
        }
    }



    /**
     * Start streaming data continuously
     *
     * @param callable $callback A callback function for generating data
     * @param int $interval Interval in milliseconds between each event
     */
    private static function projectFingerprint(): string {
        $hashes = [];
        foreach (['app', 'component', 'library'] as $directory) {
            $root = DIR::path($directory);
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $hashes[$path] = $file->getMTime() . ':' . $file->getSize();
            }
        }
        ksort($hashes);
        return hash('sha256', json_encode($hashes, JSON_UNESCAPED_SLASHES));
    }

    public static function streamUInew(string $key, int $interval = 10000) {
        if ($interval < 100) {
            throw new \InvalidArgumentException('StreamUI interval must be at least 100 milliseconds.');
        }

        $route = PHRO::routes($key);
        if (!$route || empty($route['callback']) || !is_callable($route['callback'])) {
            throw new \InvalidArgumentException("StreamUI route not found or not callable: {$key}");
        }

        self::initHeaders();
        self::setRetry(self::$retry = $interval);
        $callback = $route['callback'];
        $previousFingerprint = self::projectFingerprint();

        while (!connection_aborted()) {
            $startedAt = microtime(true);
            $bufferLevel = ob_get_level();
            try {
                $fingerprint = self::projectFingerprint();
                if (!hash_equals($previousFingerprint, $fingerprint)) {
                    $previousFingerprint = $fingerprint;
                    ob_start();
                    $result = $callback([
                        'path' => $key,
                        'params' => [],
                        'data' => [],
                        'route_details' => $route,
                    ]);
                    $currentData = '';
                    while (ob_get_level() > $bufferLevel) {
                        $chunk = ob_get_clean();
                        if ($chunk !== false) {
                            $currentData = $chunk . $currentData;
                        }
                    }
                    if ($result instanceof \Stringable || is_scalar($result)) {
                        $currentData .= (string) $result;
                    } elseif ($result !== null) {
                        $currentData .= json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    self::sendSE($currentData);
                }
            } catch (\Throwable $e) {
                while (ob_get_level() > $bufferLevel) {
                    ob_end_clean();
                }
                self::sendSE(json_encode([
                    'error' => true,
                    'message' => class_exists('PHDE', false) && PHDE::isDebug()
                        ? $e->getMessage()
                        : 'StreamUI callback failed.',
                ]));
            }

            @ob_flush();
            @flush();
            $elapsed = (microtime(true) - $startedAt) * 1000;
            usleep((int) max(0, ($interval - $elapsed) * 1000));
        }
    }

    public static function streamUI(string $name = '/streamui', int $interval = 10000) {
        if (!defined('STREAMUI')) define('STREAMUI', 'false');
        $GLOBALS['STREAMUI'] = 'false';

        if (!defined('STREAMUIPATH')) define('STREAMUIPATH', $name);
        $GLOBALS['STREAMUIPATH'] = $name;

        if (!defined('STREAMUIP')) define('STREAMUIP', '');
        $GLOBALS['STREAMUIP'] = '';

        PHRO::get($name.'-lib', function ($data) use ($name) {
            PHRQ::header('GET', '*', 'application/javascript; charset=utf-8', []);
            if (!empty($data['path'])) {
                echo PHJC::streamJS($data['path'], PHRO::root().$name, PHRO::root());
            }
        });
        PHRO::get($name, function ($data) use ($interval) {
            $GLOBALS['STREAMUI'] = 'true';
            if (!empty($data['path'])) {
                self::streamUInew($data['path'], $interval);
            }
        });
    }    
}
