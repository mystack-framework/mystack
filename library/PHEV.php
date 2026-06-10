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

    public static function initialize($path = '/websocket', $address = '0.0.0.0', $port = 8000) {
        self::$address = $address ?? parse_url(PHRO::root())['host'];
        self::$port = $port;
        PHRO::add('WS', $path, function() {
            PHRQ::header('WS', '*', 'application/json; charset=utf-8', []);
            PHEV::start();
        });
    }

    public static function start() {
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
        self::run();
    }

    public static function restart() {
        self::stop();
        self::start();
    }
    
    public static function stop() {
        if (self::$socket) {
            foreach (self::$clients as $client) {
                if (is_resource($client)) {
                    @socket_shutdown($client, 2);
                    @socket_close($client);
                }
            }
            self::$clients = [];
            self::$clientIds = [];
            self::$clientPaths = [];
            self::$clientMethods = [];
            if (is_resource(self::$socket)) {
                @socket_shutdown(self::$socket, 2);
                @socket_close(self::$socket);
            }
            self::$socket = null;
            echo "WebSocket server forcefully stopped.\n";
        } else {
            echo "No active WebSocket server to stop.\n";
        }
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
        while (true) {
            $changed = self::$clients;
            $changed[] = self::$socket;

            socket_select($changed, $null, $null, 0);

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
        $path = DIR::path('root');
        if (!$path) {
            return false;
        }
        $fileList = self::listPhpFiles($path);
        if ($fileList) {
            $find = self::findFunctionInFiles($fileList, $message);
            if ($find) {
                $code = self::extractFunction($find['file'], $find['code']);
                if ($code) {
                    return eval("return function(\$clientId, \$clientPath, \$clientMethod) {{$code}};");
                }
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
            if ($getHandler && $getHandler != false) {
                if ($getHandler && is_callable($getHandler)) {
                    $response = call_user_func($getHandler, $clientId, $clientPath, $clientMethod, $data);
                    if ($response !== null) {
                        $responseMessage = self::mask($response);
                        @socket_write(self::$clientIds[$clientId], $responseMessage, strlen($responseMessage));
                        echo "Response sent to client $clientId: $response\n";
                    }
                    return true;
                }
            } elseif (isset($data['action'])) {
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
    public static function stream(string $key, int $interval = 10000) {
        self::initHeaders();
        self::setRetry(self::$retry = $interval);

        $startTime = microtime(true);

        while (true) {
            if (connection_aborted()) {
                break;
            }
            try {
                ob_start();
                eval($functionCode . ';');
                $currentData = ob_get_clean();
                self::sendSE($currentData);
            } catch (\Throwable $e) {
                $currentData = json_encode([
                    'error' => true,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
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
    public static function streamUInew(string $key, int $interval = 10000) {
        self::initHeaders();
        self::setRetry(self::$retry = $interval);
        $startTime = microtime(true);

        $previousData = '';
        $contentStore = '';

        $getR = PHRO::routes($key);
        $short = $getR['short'];
        $link = $getR['link'];
        $method = $getR['method'];
        $file = $getR['callback_details']['file'];

        $phpCode = PHRO::source($short);
        $lines = explode("\n", $phpCode);
        $firstLine = $lines[0] ?? null;
        $lastLine = end($lines);

        // Initialize previous hashes as a static variable to persist across calls
        static $previousHashes = [];
        // Define the root directory (modify this as needed)
        $rootDir = DIR::path('root');
        // Define target directories and files to skip
        $targetDirs = ['app', 'component', 'library'];
        $skipFiles = ['.htaccess', '.env'];

        function scanDirectories($dir, $targetDirs, $skipFiles, &$previousHashes) {
            if (!is_dir($dir)) {
                echo "The specified directory does not exist: $dir" . PHP_EOL;
                return false;
            }
            // Open the directory for scanning
            $items = scandir($dir);
            $foundChange = false;  // Track if any changes are found
            foreach ($items as $item) {
                // Skip current and parent directory entries
                if ($item === '.' || $item === '..') {
                    continue;
                }
                // Construct the full path
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                // If it's a directory and is in the target directories
                if (is_dir($path) && in_array($item, $targetDirs)) {
                    // Recursively scan this directory
                    if (scanDirectories($path, $targetDirs, $skipFiles, $previousHashes)) {
                        $foundChange = true; // Change was found in the subdirectory
                    }
                } elseif (is_file($path) && !in_array($item, $skipFiles)) {
                    // Calculate the MD5 hash of the file
                    $hash = md5_file($path);
                    // Check if the file exists in the previous hashes
                    if (isset($previousHashes[$path])) {
                        // If the hash has changed, file has been modified
                        if ($previousHashes[$path] !== $hash) {
                            $foundChange = true;
                        }
                    } else {
                        // New file added
                        $foundChange = true;
                    }
                    // Update or store the hash
                    $previousHashes[$path] = $hash;
                }
            }
            // Check for deleted files
            foreach ($previousHashes as $storedPath => $hash) {
                if (!file_exists($storedPath)) {
                    $foundChange = true;
                    unset($previousHashes[$storedPath]); // Remove from hash tracking
                }
            }
            return $foundChange; // Return whether a change was found
        }

        

        function extractFunction($file, $startPattern) {
            if (!file_exists($file)) {
                return null;
            }
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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


        function extractScript($link, $method = 'GET') {
            $urlParts = parse_url($link);
            if (!isset($urlParts['host'])) {
                throw new Exception('Invalid URL.');
            }
            if (strtoupper($method) !== 'GET') {
                throw new Exception('Only GET method is supported.');
            }
        
            $localHost = 'localhost';
            $link = str_replace($urlParts['host'], $localHost, $link);
            $response = file_get_contents($link);
            if ($response === false) {
                throw new Exception('Error occurred while fetching the URL.');
            }
        
            // Normalize malformed <script> tags
            $response = preg_replace('/<<script>/i', '<script>', $response);
            $response = preg_replace('/<\/script>\s*<\/script>>/i', '</script>', $response);
        
            // Use regex to target scripts containing "//PHJC"
            preg_match_all('/<script[^>]*>([^<]*\/\/PHJC.*?<\/script>)/si', $response, $matches);
        
            if (empty($matches[1])) {
                throw new Exception('No matching scripts found with identifier //PHJC.');
            }
        
            // Combine the matched scripts into one
            $resultScripts = implode(PHP_EOL, $matches[1]);
        
            // Return the scripts wrapped in a single <script> tag
            return "<script>" . PHP_EOL . $resultScripts . PHP_EOL . "</script>";
        }


        function replaceScript($html, $scripts) {
            $pattern = '<noscript>';
            return preg_replace($pattern, $scripts, $html);
        }


        function cleanPHJCScripts($htmlContent) {
            // Regex to find improperly nested script tags with "//PHJC"
            $pattern = '/<script>\s*\/\/PHJC([\s\S]*?)<\/script>\s*<\/script>>/i';
            
            // Replace with a correctly formatted script tag
            $replacement = '<script>//PHJC$1</script>';
            
            // Perform the replacement
            $cleanedContent = preg_replace($pattern, $replacement, $htmlContent);

            $cleanedContent = str_replace("<<script>", "<script>", $cleanedContent);
            
            return $cleanedContent;
        }


        // $extractScript = extractScript($link, $method);
        $functionCode = extractFunction($file, $firstLine);
        scanDirectories($rootDir, $targetDirs, $skipFiles, $previousHashes);

        while (true) {
            if (connection_aborted()) {
                break;
            }
            try {
                if (scanDirectories($rootDir, $targetDirs, $skipFiles, $previousHashes)) {
                    // Change detected, indicate reload
                    // echo 'reload now' . PHP_EOL;
                    $functionCode = extractFunction($file, $firstLine);
                    ob_start();
                    eval($functionCode . ';');
                    $currentData = ob_get_clean();
                    // $currentData = replaceScript($currentData, extractScript($link, $method));
                    // $currentData = cleanPHJCScripts($currentData);
                    // if ($currentData !== $previousData) {
                        self::sendSE($currentData);
                        // $previousData = $currentData;
                        // $contentStore = $currentData;
                    // }
                }
            } catch (\Throwable $e) {
                $currentData = json_encode([
                    'error' => true,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
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

    public static function streamUI(string $name = '/streamui', int $interval = 10000) {
        define('STREAMUI', 'false');
        $GLOBALS['STREAMUI'] = 'false';

        define('STREAMUIPATH', $name);
        $GLOBALS['STREAMUIPATH'] = $name;

        define('STREAMUIP', '');
        $GLOBALS['STREAMUIP'] = '';

        PHRO::get($name.'-lib', function ($data) use ($name) {
            PHRQ::header('GET', '*', 'application/javascript; charset=utf-8', []);
            if (!empty($data['path'])) {
                echo PHJC::streamJS($data['path'], PHRO::root().$name, PHRO::root());
            }
        });
        PHRO::get($name, function ($data) use ($interval) {
            define('STREAMUI', 'true');
            $GLOBALS['STREAMUI'] = 'true';
            if (!empty($data['path'])) {
                self::streamUInew($data['path'], $interval);
            }
        });
    }    
}