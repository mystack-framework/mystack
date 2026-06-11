<?php

/**
 * ============================================================================
 * Class: PHAI (PHP Artificial Intelligence Engine & MCP Server)
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * PHAI is the ultimate Intelligence Engine tailored for the MyStack framework.
 * It provides seamless integration with 31+ major AI providers and functions 
 * as a Model Context Protocol (MCP) server.
 *
 * Features:
 * - Auto-Configured connections to OpenAI, Gemini, Groq, Anthropic, DeepSeek, etc.
 * - Universal Handlers (Raw Data, Closures, Strings, Arrays, Objects).
 * - Automatic Tool creation, function calling, and self-correction.
 * - Intelligent Memory Management and robust Resource Templates.
 * 
 * Usage Example:
 * ```php
 * // Setup provider
 * PHAI::accounts(['gemini' => ['key' => 'YOUR_API_KEY']]);
 * 
 * // Send a basic prompt
 * $response = PHAI::gemini()->ask("Write a PHP function to calculate factorial.");
 * 
 * // Expose an internal tool to the AI
 * PHAI::tool('get_user_data', 'Gets user info', ['id' => 'int'], fn($id) => PHDB::find('users', $id));
 * ```
 */




class PHAI
{

    private static ?PHAI $instance = null;

    private array $tools = [];
    private array $prompts = [];
    private array $resources = [];
    private array $resourceTemplates = [];
    private array $aliases = [];
    private array $globalMiddlewares = [];

    private bool $isBooted = false;

    // ========= AI CLUSTER CONFIGURATION =========
    private static array $aiAccounts = [];
    private static array $aiPriority = [
        "gemini", "groq", "cerebras", "sambanova", "deepseek", "mistral", 
        "together", "novita", "hyperbolic", "openrouter", "fireworks", 
        "lepton", "huggingface", "anthropic", "perplexity", "cohere", 
        "openai", "github", "nvidia", "aiml", "puter", "edenai", 
        "clarifai", "wit", "deepai", "elevenlabs", "stabilityai", 
        "leonardoai", "xai", "assemblyai", "replicate"
    ];
    private static int $defaultTimeout = 15;
    private static array $aiModels = [];
    private static array $persistentPipes = [];

    /**
     * Sets AI provider accounts.
     *
     * @param array $accounts Array of account configurations.
     * @return void
     */
    public static function setAccounts(array $accounts) { self::$aiAccounts = $accounts; }

    /**
     * Sets AI provider priority order.
     *
     * @param array $priority Array of provider names.
     * @return void
     */
    public static function setPriority(array $priority) { self::$aiPriority = $priority; }

    /**
     * Sets AI models for providers.
     *
     * @param array $models Map of provider to models.
     * @return void
     */
    public static function setModels(array $models) { self::$aiModels = $models; }

    /**
     * Sets the default timeout for AI requests.
     *
     * @param int $seconds Timeout in seconds.
     * @return void
     */
    public static function setTimeout(int $seconds) { self::$defaultTimeout = $seconds; }

    /**
     * Gets models for a specific provider.
     *
     * @param string $provider Provider name.
     * @return array|null
     */
    public static function getModels(string $provider) {
        return self::$aiModels[$provider] ?? null;
    }

    /**
     * Registers a bridge process and its pipes.
     *
     * @param string $key Process key.
     * @param resource $process Process resource.
     * @param array $pipes Process pipes.
     * @return void
     */
    public static function registerBridgeProcess($key, $process, $pipes) {
        self::$persistentPipes[$key] = ["process" => $process, "pipes" => $pipes];
    }

    /**
     * Gets a registered bridge process.
     *
     * @param string $key Process key.
     * @return array|null
     */
    public static function getBridgeProcess($key) {
        return self::$persistentPipes[$key] ?? null;
    }

    /**
     * Cleans up all registered bridge processes.
     *
     * @return void
     */
    public static function cleanup() {
        foreach (self::$persistentPipes as $proc) {
            if (is_resource($proc["process"])) {
                foreach ($proc["pipes"] as $pipe) {
                    if (is_resource($pipe)) fclose($pipe);
                }
                proc_terminate($proc["process"]);
                proc_close($proc["process"]);
            }
        }
        self::$persistentPipes = [];
    }

    /**
     * AI API SERVE (Universal Compatibility Bridge - Final)
     * Fully compatible with OpenClaw, LibChat, and various API styles.
     * Supports OpenAI, Anthropic, Google Gemini, and Ollama request/response formats.
     */
    public static function serve(string $prefix = "/v1", ?string $apiKey = null)
    {
        if (!class_exists("PHRO")) return;

        // --- ১. Robust Auth Checker ---
        $checkAuth = function($data) use ($apiKey) {
            if ($apiKey === null) return true;
            
            $auth = "";
            if (function_exists("getallheaders")) {
                $headers = array_change_key_case(getallheaders(), CASE_LOWER);
                $auth = $headers["authorization"] ?? $headers["x-api-key"] ?? "";
            }
            
            if (empty($auth)) {
                $auth = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? $_SERVER["HTTP_X_API_KEY"] ?? "";
            }
            if (empty($auth)) {
                $auth = $data["headers"]["authorization"] ?? $data["headers"]["x-api-key"] ?? "";
            }
            
            $token = trim(preg_replace("/^bearer\s+/i", "", (string)$auth));
            return ($token === (string)$apiKey);
        };

        // --- ২. Next-Gen Universal Handler (Self-Healing Request/Response Translator) ---
        $handleRequest = function($data, string $targetFormat = "openai") use ($apiKey, $checkAuth) {
            if (!$checkAuth($data ?? [])) {
                http_response_code(401);
                print json_encode(["error" => ["message" => "Invalid Mystack API Key"]]); 
                return;
            }

            $payload = $data["data"] ?? [];

            // Request Translation (Google contents -> OpenAI messages)
            if (isset($payload["contents"]) && !isset($payload["messages"])) {
                $messages = [];
                foreach ($payload["contents"] as $content) {
                    $role = ($content["role"] ?? "user") === "model" ? "assistant" : "user";
                    $text = "";
                    if (isset($content["parts"])) {
                        foreach ($content["parts"] as $part) {
                            $text .= $part["text"] ?? "";
                        }
                    }
                    $messages[] = ["role" => $role, "content" => $text];
                }
                $payload["messages"] = $messages;
            }

            $input = $payload["messages"] ?? $payload["prompt"] ?? $payload;
            
            if (isset($payload["model"]) && str_contains($payload["model"], "/")) {
                [$provider, $model] = explode("/", $payload["model"], 2);
                $payload["priority"] = [$provider];
                $payload["model"] = $model;
            }

            try {
                // BUG FIX 3: Object-to-Array Conversion inside serve block before cluster
                if (is_object($input)) {
                    $input = json_decode(json_encode($input), true);
                }

                $response = self::cluster($input, $payload ?? []);
                
                // BUG FIX: If cluster returned an error, immediately return it so OpenClaw doesn\'t hang
                if (is_array($response) && isset($response["error"])) {
                    http_response_code(502);
                    print json_encode($response);
                    return;
                }
                
                // --- A. DEEP-DECODING & OBJECT-TO-ARRAY CONVERSION (FIXED) ---
                if (is_object($response)) {
                    $response = json_decode(json_encode($response), true);
                }
                elseif (is_string($response)) {
                    $decoded = json_decode($response, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $response = $decoded;
                    }
                }

                // --- B. GOOGLE -> OPENAI TRANSLATION ---
                if (is_array($response) && isset($response["candidates"]) && !isset($response["choices"])) {
                    $rawText = $response["candidates"][0]["content"]["parts"][0]["text"] ?? "";
                    $modelName = $response["modelVersion"] ?? $payload["model"] ?? "gemini-3.5-flash";
                    
                    $response = [
                        "id" => "chatcmpl-" . uniqid(),
                        "object" => "chat.completion",
                        "created" => time(),
                        "model" => $modelName,
                        "choices" => [
                            [
                                "index" => 0,
                                "message" => [
                                    "role" => "assistant",
                                    "content" => $rawText
                                ],
                                "finish_reason" => "stop"
                            ]
                        ],
                        "usage" => [
                            "prompt_tokens" => $response["usageMetadata"]["promptTokenCount"] ?? 0,
                            "completion_tokens" => $response["usageMetadata"]["candidatesTokenCount"] ?? 0,
                            "total_tokens" => $response["usageMetadata"]["totalTokenCount"] ?? 0
                        ]
                    ];
                }

                // --- C. INTELLIGENT RECURSIVE DEEP SCANNER (Ultimate Fallback) ---
                $content = $response["choices"][0]["message"]["content"] ?? "";
                $toolCalls = $response["choices"][0]["message"]["tool_calls"] ?? null;
                $finishReason = $response["choices"][0]["finish_reason"] ?? "stop";

                // BUG FIX: Do not run recursive text finder if we have tool calls
                if (empty($content) && empty($toolCalls) && is_array($response)) {
                    $recursiveTextFinder = function($array) use (&$recursiveTextFinder) {
                        $longest = "";
                        if (!is_array($array)) return "";
                        foreach ($array as $key => $val) {
                            if (is_array($val)) {
                                $res = $recursiveTextFinder($val);
                                if (strlen($res) > strlen($longest)) $longest = $res;
                            } elseif (is_string($val) && !in_array(strtolower($key), ["id", "object", "model", "role", "finish_reason", "finishreason", "modelversion", "responseid", "provider", "status"])) {
                                if (strlen($val) > strlen($longest)) $longest = $val;
                            }
                        }
                        return $longest;
                    };
                    $content = $recursiveTextFinder($response);
                    $response["choices"][0]["message"]["content"] = $content;
                }

                $modelName = $response["model"] ?? $payload["model"] ?? "model";

                // --- D. NEXT-GEN SMART SSE STREAM EMULATOR ---
                if (!empty($payload["stream"]) && $targetFormat === "openai") {
                    header("Content-Type: text/event-stream");
                    header("Cache-Control: no-cache");
                    header("Connection: keep-alive");
                    header("X-Accel-Buffering: no");
                    @ob_end_clean();
                    flush();

                    $stream_id = "chatcmpl-" . uniqid();
                    $created_time = time();

                    // BUG FIX: Emulate tool calls in stream or fallback empty text
                    if (!empty($toolCalls)) {
                        $chunk = [
                            "id" => $stream_id,
                            "object" => "chat.completion.chunk",
                            "created" => $created_time,
                            "model" => $modelName,
                            "choices" => [
                                [
                                    "index" => 0,
                                    "delta" => [
                                        "role" => "assistant",
                                        "content" => null,
                                        "tool_calls" => $toolCalls
                                    ],
                                    "finish_reason" => $finishReason
                                ]
                            ]
                        ];
                        echo "data: " . json_encode($chunk, JSON_UNESCAPED_UNICODE) . "\n\n";
                        flush();
                    } else {
                        if (empty($content)) {
                            $content = "An error occurred or the response was empty.";
                        }
                        $words = preg_split("/(\s+)/u", $content, -1, PREG_SPLIT_DELIM_CAPTURE);
                        foreach ($words as $word) {
                            if (connection_aborted()) break;
                            $chunk = [
                                "id" => $stream_id,
                                "object" => "chat.completion.chunk",
                                "created" => $created_time,
                                "model" => $modelName,
                                "choices" => [
                                    [
                                        "index" => 0,
                                        "delta" => [
                                            "content" => $word
                                        ],
                                        "finish_reason" => null
                                    ]
                                ]
                            ];
                            echo "data: " . json_encode($chunk, JSON_UNESCAPED_UNICODE) . "\n\n";
                            flush();
                            usleep(15000);
                        }
                    }

                    $final_chunk = [
                        "id" => $stream_id,
                        "object" => "chat.completion.chunk",
                        "created" => $created_time,
                        "model" => $modelName,
                        "choices" => [
                            [
                                "index" => 0,
                                "delta" => (object)[],
                                "finish_reason" => $finishReason
                            ]
                        ]
                    ];
                    echo "data: " . json_encode($final_chunk) . "\n\n";
                    echo "data: [DONE]\n\n";
                    flush();
                    sys_exit_placeholder_replace; // Avoid parsing error for exit
                }
                // BUG FIX: Prevent empty responses from hanging the client in non-stream mode
                if (empty($content) && empty($toolCalls)) {
                    $content = "An error occurred or the response was empty. Please check the AI provider\'s configuration or prompt.";
                    $response["choices"][0]["message"]["content"] = $content;
                }


                // 2. RESPONSE TRANSLATION (Non-Streaming Fallbacks)
                if ($targetFormat === "anthropic") {
                    $translated = [
                        "id" => "msg_" . uniqid(),
                        "type" => "message",
                        "role" => "assistant",
                        "content" => [
                            [
                                "type" => "text",
                                "text" => $content
                            ]
                        ],
                        "model" => $modelName,
                        "stop_reason" => "end_turn"
                    ];
                    print json_encode($translated);
                } elseif ($targetFormat === "google") {
                    $translated = [
                        "candidates" => [
                            [
                                "content" => [
                                    "parts" => [["text" => $content]],
                                    "role" => "model"
                                ],
                                "finishReason" => "STOP"
                            ]
                        ]
                    ];
                    print json_encode($translated);
                } elseif ($targetFormat === "ollama") {
                    $translated = [
                        "model" => $modelName,
                        "created_at" => date(DATE_ATOM),
                        "message" => [
                            "role" => "assistant",
                            "content" => $content
                        ],
                        "done" => true
                    ];
                    print json_encode($translated);
                } else {
                    print json_encode($response);
                }
            } catch (\Throwable $e) {
                http_response_code(500);
                print json_encode(["error" => ["message" => $e->getMessage()]]);
            }
        };

        // --- ৩. Endpoint Route Mapping ---
        \PHRO::get("$prefix/models", function($data) use ($checkAuth) {
            if (!$checkAuth($data ?? [])) {
                http_response_code(401);
                print json_encode(["error" => ["message" => "Invalid Mystack API Key"]]); return;
            }
            $modelList = [];
            foreach (self::$aiAccounts as $provider => $acc) {
                $models = self::getModels($provider) ?? ["latest"];
                foreach ($models as $m) {
                    $modelList[] = [
                        "id" => "$provider/$m",
                        "object" => "model",
                        "created" => time(),
                        "owned_by" => $provider
                    ];
                }
            }
            print json_encode(["object" => "list", "data" => $modelList]);
        })->header(["json"]);

        \PHRO::post("$prefix/chat/completions", function ($data) use ($handleRequest) {
            $handleRequest($data, "openai");
        })->header(["json"]);

        \PHRO::post("$prefix/completions", function ($data) use ($handleRequest) {
            $handleRequest($data, "openai");
        })->header(["json"]);

        \PHRO::post("$prefix/responses", function ($data) use ($handleRequest) {
            $handleRequest($data, "openai");
        })->header(["json"]);

        \PHRO::post("$prefix/messages", function ($data) use ($handleRequest) {
            $handleRequest($data, "anthropic");
        })->header(["json"]);

        \PHRO::post("$prefix/api/chat", function ($data) use ($handleRequest) {
            $handleRequest($data, "ollama");
        })->header(["json"]);

        \PHRO::post("$prefix/api/generate", function ($data) use ($handleRequest) {
            $handleRequest($data, "ollama");
        })->header(["json"]);

        \PHRO::post("$prefix/models/.*", function ($data) use ($handleRequest) {
            $handleRequest($data, "google");
        })->header(["json"]);

        \PHRO::options("$prefix/.*", function() {
            header("Access-Control-Allow-Origin: *");
            header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
            header("Access-Control-Allow-Headers: Content-Type, Authorization");
            sys_exit_placeholder_replace;
        });
    }

    public static function clusterAPI(string $path = "/v1/chat/completions", ?string $apiKey = null)
    {
        if (class_exists("PHRO")) {
            \PHRO::post($path, function ($data) use ($apiKey) {
                if ($apiKey !== null) {
                    $auth = "";
                    if (function_exists("getallheaders")) {
                        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
                        $auth = $headers["authorization"] ?? $headers["x-api-key"] ?? "";
                    }
                    if (empty($auth)) {
                        $auth = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? $_SERVER["HTTP_X_API_KEY"] ?? "";
                    }
                    if (empty($auth)) {
                        $auth = $data["headers"]["authorization"] ?? $data["headers"]["x-api-key"] ?? "";
                    }

                    $token = trim(preg_replace("/^bearer\s+/i", "", (string)$auth));
                    if ($token !== (string)$apiKey) {
                        http_response_code(401);
                        print json_encode(["error" => [
                            "message" => "Invalid API Key",
                            "type" => "invalid_request_error",
                            "param" => null,
                            "code" => "invalid_api_key"
                        ]]); 
                        return;
                    }
                }

                $payload = $data["data"] ?? [];
                $input = $payload["messages"] ?? $payload["contents"] ?? $payload["prompt"] ?? $payload;
                try {
                    $response = self::cluster($input, $payload ?? []);
                    if (isset($response["error"])) {
                        http_response_code(502);
                    }
                    print json_encode($response);
                } catch (\Throwable $e) {
                    http_response_code(500);
                    print json_encode(["error" => [
                        "message" => $e->getMessage(),
                        "type" => "api_error",
                        "param" => null,
                        "code" => "internal_server_error"
                    ]]);
                }
            })->header(["json"]);
        }
    }

    public static function cluster(mixed $input, array $options = [])
    {
        $priority = $options["priority"] ?? self::$aiPriority;
        $timeout = $options["timeout"] ?? self::$defaultTimeout;
        $lastError = null;
        
        // BUG FIX 3: Object-to-Array Conversion inside cluster block
        if (is_object($input)) {
            $input = json_decode(json_encode($input), true);
        }

        foreach ($priority as $provider) {
            if (!isset(self::$aiAccounts[$provider])) continue;

            $accounts = (array)self::$aiAccounts[$provider];
            shuffle($accounts);

            foreach ($accounts as $account) {
                $apiKey = is_array($account) ? ($account["api_key"] ?? "") : $account;
                try {
                    $result = PHAI_AI::call($provider, $input, $apiKey, array_merge($options, [
                        "timeout" => $timeout,
                        "account_meta" => is_array($account) ? $account : []
                    ]));

                    $text = $result["text"] ?? "";
                    if (empty($text) && is_array($result)) {
                        $recursiveTextFinder = function($array) use (&$recursiveTextFinder) {
                            $longest = "";
                            if (!is_array($array)) return "";
                            foreach ($array as $key => $val) {
                                if (is_array($val)) {
                                    $res = $recursiveTextFinder($val);
                                    if (strlen($res) > strlen($longest)) $longest = $res;
                                } elseif (is_string($val) && !in_array(strtolower($key), ["id", "object", "model", "role", "finish_reason", "finishreason", "provider", "status"])) {
                                    if (strlen($val) > strlen($longest)) $longest = $val;
                                }
                            }
                            return $longest;
                        };
                        $text = $recursiveTextFinder($result);
                    }

                    $message = [
                        "role" => "assistant",
                        "content" => $text
                    ];

                    // BUG FIX 2: Gemini Plural/Singular Function Calls Fix
                    if (!empty($result["tool_calls"])) {
                        $message["tool_calls"] = $result["tool_calls"];
                    } elseif (!empty($result["raw"]["choices"][0]["message"]["tool_calls"])) {
                        $message["tool_calls"] = $result["raw"]["choices"][0]["message"]["tool_calls"];
                    } elseif (!empty($result["raw"]["candidates"][0]["content"]["parts"])) {
                        $toolCalls = [];
                        foreach ($result["raw"]["candidates"][0]["content"]["parts"] as $part) {
                            $fcs = [];
                            if (isset($part["functionCall"])) {
                                $fcs[] = $part["functionCall"];
                            } elseif (isset($part["functionCalls"])) {
                                $fcs = $part["functionCalls"];
                            }
                            foreach ($fcs as $fc) {
                                $toolCalls[] = [
                                    "id" => "call_" . uniqid(),
                                    "type" => "function",
                                    "function" => [
                                        "name" => $fc["name"] ?? "",
                                        "arguments" => json_encode($fc["args"] ?? (object)[])
                                    ]
                                ];
                            }
                        }
                        if (!empty($toolCalls)) {
                            $message["tool_calls"] = $toolCalls;
                        }
                    }

                    $finishReason = "stop";
                    if (!empty($message["tool_calls"])) {
                        $finishReason = "tool_calls";
                    }

                    return [
                        "id" => uniqid("phai_"),
                        "object" => "chat.completion",
                        "created" => time(),
                        "model" => $result["model"] ?? "auto",
                        "provider" => $provider,
                        "choices" => [[
                            "index" => 0,
                            "message" => $message,
                            "finish_reason" => $finishReason
                        ]],
                        "usage" => $result["raw"]["usage"] ?? $result["raw"]["usageMetadata"] ?? (object)[],
                        "status" => "success"
                    ];
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    continue; 
                }
            }
        }
        
        return [
            "error" => [
                "message" => "All providers/models failed. Last: $lastError",
                "type" => "provider_error",
                "param" => null,
                "code" => "all_providers_failed"
            ],
            "status" => "error"
        ];
    }

    public static function getInstance(): PHAI
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->boot();
        register_shutdown_function([self::class, "cleanup"]);
    }

    private function boot()
    {
        if ($this->isBooted)
            return;
        $this->isBooted = true;

        $this->aliases = [
            "init" => "initialize",
            "start" => "initialize",
            "boot" => "initialize",
            "setup" => "initialize",
            "tools" => "tools/list",
            "tool/list" => "tools/list",
            "listTools" => "tools/list",
            "getTools" => "tools/list",
            "toolCall" => "tools/call",
            "callTool" => "tools/call",
            "runTool" => "tools/call",
            "execute" => "tools/call",
            "run" => "tools/call",
            "prompts" => "prompts/list",
            "prompt/list" => "prompts/list",
            "listPrompts" => "prompts/list",
            "prompt/get" => "prompts/get",
            "getPrompt" => "prompts/get",
            "readPrompt" => "prompts/get",
            "callPrompt" => "prompts/get",
            "resources" => "resources/list",
            "resource/list" => "resources/list",
            "listResources" => "resources/list",
            "resource/get" => "resources/read",
            "resource/read" => "resources/read",
            "read" => "resources/read",
            "resources/get" => "resources/read",
            "templates" => "resources/templates/list",
            "resource/templates" => "resources/templates/list",
            "ping" => "ping",
            "health" => "ping"
        ];
    }

    public static function routes(string $path = "/mcp")
    {
        if (class_exists("PHRO")) {
            \PHRO::post($path, function ($data) {
                $response = self::getInstance()->handleRequest($data);
                if ($response !== "")
                    print $response;
            })->header(["json"]);

            \PHRO::get($path, function () {
                print json_encode(["jsonrpc" => "2.0", "error" => ["code" => -32601, "message" => "Method not found."], "id" => null]);
            })->header(["json"]);
        }
    }

    public static function tool(string $name, string $description, array $schema, mixed $handler): PhaiBuilder
    {
        $instance = self::getInstance();
        $instance->tools[$name] = [
            "name" => $name,
            "description" => $description,
            "inputSchema" => empty($schema) ? ["type" => "object", "properties" => (object) []] : $schema,
            "handler" => $handler,
            "middlewares" => [],
            "retries" => 0
        ];
        return new PhaiBuilder($instance->tools[$name]);
    }

    public static function prompt(string $name, string $description, array $arguments, mixed $handler): PhaiBuilder
    {
        $instance = self::getInstance();
        $instance->prompts[$name] = [
            "name" => $name,
            "description" => $description,
            "arguments" => $arguments,
            "handler" => $handler,
            "middlewares" => []
        ];
        return new PhaiBuilder($instance->prompts[$name]);
    }

    public static function resource(string $uri, string $name, string $description, mixed $handler): PhaiBuilder
    {
        $instance = self::getInstance();
        $instance->resources[$uri] = [
            "uri" => $uri,
            "name" => $name,
            "description" => $description,
            "handler" => $handler,
            "middlewares" => []
        ];
        return new PhaiBuilder($instance->resources[$uri]);
    }

    public static function resourceTemplate(string $uriTemplate, string $name, string $description, mixed $handler): PhaiBuilder
    {
        $instance = self::getInstance();
        $instance->resourceTemplates[$uriTemplate] = [
            "uriTemplate" => $uriTemplate,
            "name" => $name,
            "description" => $description,
            "handler" => $handler,
            "middlewares" => []
        ];
        
        return new PhaiBuilder($instance->resourceTemplates[$uriTemplate]);
    }

    public static function alias(string $customAlias, string $targetMethod)
    {
        self::getInstance()->aliases[$customAlias] = $targetMethod;
    }

    public static function middleware(callable $middleware)
    {
        self::getInstance()->globalMiddlewares[] = $middleware;
    }

    public function handleRequest($data)
    {
        $requestId = 1;

        try {
            $payload = $this->extractPayload($data);
            if (!$payload)
                return $this->generateError($requestId, "Invalid JSON or Request Format", -32700);

            $requestId = $payload["id"] ?? 1;
            $method = $this->aliases[$payload["method"] ?? ""] ?? ($payload["method"] ?? "");
            $params = $payload["params"] ?? [];

            if ($method && str_starts_with($method, "notifications/"))
                return "";

            foreach ($this->globalMiddlewares as $middleware) {
                $midRes = $this->smartExecute($middleware, ["method" => $method, "params" => $params]);
                if ($midRes === false)
                    return $this->generateError($requestId, "Blocked by global middleware", -32000);
                if ($midRes !== null && $midRes !== true)
                    return $this->formatSmartResponse($requestId, $midRes);
            }

            return $this->routeRequest($requestId, $method, $params);

        } catch (\Throwable $exception) {
            return $this->generateError($requestId, "Internal Server Error: " . $exception->getMessage(), -32603);
        }
    }

    private function extractPayload($data): ?array
    {
        if (is_array($data) && isset($data["data"]) && is_array($data["data"])) {
            if (isset($data["data"]["jsonrpc"]) || isset($data["data"]["method"])) {
                return $data["data"]; 
            }
        }

        if (is_array($data) && (isset($data["jsonrpc"]) || isset($data["method"]))) {
            return $data;
        }

        $raw = file_get_contents("php://input");
        if ($raw) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed) && (isset($parsed["jsonrpc"]) || isset($parsed["method"]))) {
                return $parsed;
            }
        }

        if (is_object($data))
            return json_decode(json_encode($data), true);
        if (is_string($data) && $data !== "")
            return json_decode($data, true);

        if (is_array($data))
            return $data;

        return null;
    }

    private function routeRequest($requestId, string $method, array $params)
    {
        return match ($method) {
            "initialize" => $this->generateSuccess($requestId, [
                "protocolVersion" => "2024-11-05",
                "capabilities" => ["tools" => (object) [], "resources" => (object) [], "prompts" => (object) []],
                "serverInfo" => ["name" => "PHAI Ultimate", "version" => "6.0"]
            ]),
            "ping" => $this->generateSuccess($requestId, (object) []),

            "tools/list" => $this->paginateList($requestId, $this->tools, $params, "tools"),
            "tools/call" => $this->executeHandler($requestId, $this->tools, $params["name"] ?? "", $params["arguments"] ?? [], "text"),

            "prompts/list" => $this->paginateList($requestId, $this->prompts, $params, "prompts"),
            "prompts/get" => $this->executeHandler($requestId, $this->prompts, $params["name"] ?? "", $params["arguments"] ?? [], "prompt"),

            "resources/list" => $this->paginateList($requestId, $this->resources, $params, "resources"),
            "resources/templates/list" => $this->paginateList($requestId, $this->resourceTemplates, $params, "resourceTemplates"),
            "resources/read" => $this->handleResourceRead($requestId, $params),

            "ai/plan" => $this->handleAiPlanner($requestId, $params),

            default => $this->generateError($requestId, "Method not found: $method", -32601)
        };
    }

    private function executeHandler($requestId, array $collection, string $name, array $args, string $responseType)
    {
        if (!isset($collection[$name])) {
            $friendlyType = ($responseType === "text") ? "Tool" : "Prompt";
            return $this->generateError($requestId, "{$friendlyType} not found: '{$name}'");
        }

        try {
            if (isset($collection[$name]["inputSchema"])) {
                $this->validateSchema($collection[$name]["inputSchema"], $args);
            } elseif (isset($collection[$name]["arguments"])) {
                $this->validateArguments($collection[$name]["arguments"], $args);
            }

            $result = $this->executeWithRetries($collection[$name], $args);

            if (is_array($result) && (isset($result["type"]) && in_array($result["type"], ["image", "resource", "text"]))) {
                $content = [$result];
            } else {
                $content = [["type" => "text", "text" => (string)$result]];
            }

            if ($responseType === "prompt") {
                return $this->generateSuccess($requestId, ["messages" => [["role" => "user", "content" => $content]]]);
            }
            return $this->generateSuccess($requestId, ["content" => $content]);

        } catch (\Throwable $e) {
            return $this->generateError($requestId, $e->getMessage(), -32602);
        }
    }

    private function validateSchema(array $schema, array $args)
    {
        if (class_exists("PHVD")) {
            $rules = [];
            $props = $schema["properties"] ?? []; 
            foreach ($props as $k => $v) {
                $r = [];
                if (in_array($k, $schema["required"] ?? [])) $r[] = "required";
                $type = is_array($v) ? ($v["type"] ?? "") : "";
                if ($type === "number") $r[] = "numeric";
                if (isset($v["minimum"])) $r[] = "min:".$v["minimum"];
                if (isset($v["maximum"])) $r[] = "max:".$v["maximum"];
                if (isset($v["enum"])) $r[] = "in:".implode(",", $v["enum"]);
                if (!empty($r)) $rules[$k] = implode("|", $r);
            }
            if (!empty($rules)) {
                $v = \PHVD::check($rules, $args);
                if (!$v["result"]) throw new \Exception($v["message"]);
            }
            return;
        }

        $required = $schema["required"] ?? [];
        foreach ($required as $field) {
            if (!isset($args[$field])) {
                throw new \Exception("Missing required parameter: '$field'");
            }
        }
    }

    private function validateArguments(array $definedArgs, array $passedArgs)
    {
        foreach ($definedArgs as $arg) {
            if (!empty($arg["required"]) && !isset($passedArgs[$arg["name"]])) {
                throw new \Exception("Missing required argument: '{$arg["name"]}'");
            }
        }
    }

    private function handleResourceRead($requestId, array $params)
    {
        $uri = $params["uri"] ?? "";

        if (isset($this->resources[$uri])) {
            $result = $this->executeWithRetries($this->resources[$uri], []);
            $mime = (is_array($result) && isset($result["mimeType"])) ? $result["mimeType"] : "text/plain";
            $text = (is_array($result) && isset($result["text"])) ? $result["text"] : (string)$result;
            return $this->generateSuccess($requestId, ["contents" => [["uri" => $uri, "mimeType" => $mime, "text" => $text]]]);
        }

        foreach ($this->resourceTemplates as $template => $config) {
            $regex = preg_replace_callback("/\{([a-zA-Z0-9_]+)\}|@([a-zA-Z0-9_]+)/", function ($m) {
                $paramName = !empty($m[1]) ? $m[1] : $m[2];
                return "(?P<$paramName>[^/]+)";
            }, $config["uriTemplate"]);

            if (preg_match("#^$regex$#", $uri, $matches)) {
                $args = array_filter($matches, "is_string", ARRAY_FILTER_USE_KEY);
                $result = $this->executeWithRetries($config, $args);
                $mime = (is_array($result) && isset($result["mimeType"])) ? $result["mimeType"] : "text/plain";
                $text = (is_array($result) && isset($result["text"])) ? $result["text"] : (string)$result;
                return $this->generateSuccess($requestId, ["contents" => [["uri" => $uri, "mimeType" => $mime, "text" => $text]]]);
            }
        }

        return $this->generateError($requestId, "Resource not found: $uri");
    }

    private function executeWithRetries(array $config, array $args)
    {
        foreach ($config["middlewares"] ?? [] as $middleware) {
            if ($this->smartExecute($middleware, $args) === false) {
                throw new \Exception("Action blocked by specific middleware.");
            }
        }

        $maxRetries = $config["retries"] ?? 0;
        $attempt = 0;

        while ($attempt <= $maxRetries) {
            try {
                $output = $this->smartExecute($config["handler"], $args);

                if (is_array($output) && isset($output["tool"])) {
                    if (!isset($this->tools[$output["tool"]]))
                        throw new \Exception("Chained tool not found.");
                    return $this->executeWithRetries($this->tools[$output["tool"]], $output["args"] ?? []);
                }

                if (is_null($output))
                    return "null";
                if (is_bool($output))
                    return $output ? "true" : "false";
                if (is_array($output) || is_object($output))
                    return json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

                return (string) $output;

            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt > $maxRetries)
                    throw new \Exception("Failed after {$maxRetries} retries. Error: " . $e->getMessage());
            }
        }
    }

    private function smartExecute(mixed $handler, array $args = [])
    {
        $callable = null;
        $isRawData = false;

        if (is_callable($handler)) {
            $callable = $handler;
        } elseif (is_string($handler)) {
            if (str_contains($handler, "->")) {
                [$class, $method] = explode("->", $handler);
                if (class_exists($class) && method_exists($class, $method)) {
                    $callable = [new $class(), $method];
                }
            } elseif (str_contains($handler, "::")) {
                [$class, $method] = explode("::", $handler);
                if (class_exists($class) && method_exists($class, $method)) {
                    $callable = [$class, $method];
                }
            } elseif (function_exists($handler)) {
                $callable = $handler;
            } else {
                $isRawData = true; 
            }
        } elseif (is_array($handler)) {
            if (is_callable($handler, true, $callableName) && method_exists($handler[0] ?? "", $handler[1] ?? "")) {
                $callable = $handler; 
            } else {
                $isRawData = true; 
            }
        } else {
            $isRawData = true; 
        }

        if ($isRawData || $callable === null) {
            return $handler;
        }

        try {
            $reflection = null;
            if (is_array($callable)) {
                $reflection = new \ReflectionMethod($callable[0], $callable[1]);
            } elseif (is_string($callable)) {
                $reflection = new \ReflectionFunction($callable);
            } elseif ($callable instanceof \Closure || is_object($callable)) {
                $reflection = new \ReflectionFunction($callable);
            }

            $passArgs = [];
            $memoryManager = new PhaiMemory();

            foreach ($reflection->getParameters() as $param) {
                $paramName = $param->getName();
                $type = $param->getType() ? $param->getType()->getName() : null;

                if (array_key_exists($paramName, $args)) {
                    $passArgs[] = $args[$paramName]; 
                } elseif ($paramName === "args" || $paramName === "params" || $paramName === "data") {
                    $passArgs[] = $args; 
                } elseif ($paramName === "memory" || $type === PhaiMemory::class) {
                    $passArgs[] = $memoryManager; 
                } elseif ($paramName === "phai" || $type === PHAI::class) {
                    $passArgs[] = $this; 
                } else {
                    $passArgs[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                }
            }

            return $callable(...$passArgs);

        } catch (\ReflectionException $e) {
            return $callable($args); 
        }
    }

    public static function bridge(string $target, string $method, array $params = [], $options = [])
    {
        if (is_string($options)) {
            $options = ["auth" => $options]; 
        }

        $config = array_merge([
            "auth" => null,         
            "headers" => [],        
            "timeout" => 30,        
            "proxy" => null,        
            "verify_ssl" => false,  
            "retry" => 0,           
            "persistent" => true    
        ], $options);

        $requestId = uniqid("phai_bridge_", true);
        $payload = json_encode([
            "jsonrpc" => "2.0",
            "id" => $requestId,
            "method" => $method,
            "params" => $params
        ]);

        $attempt = 0;
        $maxRetries = (int) $config["retry"];
        $lastError = null;

        while ($attempt <= $maxRetries) {
            $attempt++;

            if (preg_match("/^https?:\/\//i", $target)) {

                $httpHeaders = ["Content-Type: application/json", "Accept: application/json"];

                if (!empty($config["auth"])) {
                    $authHeader = strpos($config["auth"], " ") !== false ? $config["auth"] : "Bearer {$config["auth"]}";
                    $httpHeaders[] = "Authorization: {$authHeader}";
                }

                foreach ($config["headers"] as $k => $v) {
                    $httpHeaders[] = is_int($k) ? $v : "{$k}: {$v}";
                }

                $ch = curl_init($target);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => $httpHeaders,
                    CURLOPT_TIMEOUT => $config["timeout"],
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => $config["verify_ssl"],
                    CURLOPT_SSL_VERIFYHOST => $config["verify_ssl"] ? 2 : 0,
                    CURLOPT_FOLLOWLOCATION => true,
                ]);

                if (!empty($config["proxy"])) {
                    curl_setopt($ch, CURLOPT_PROXY, $config["proxy"]);
                }

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                if (is_resource($ch)) { curl_close($ch); }

                if ($err) {
                    $lastError = ["error" => ["code" => -32000, "message" => "cURL Error: $err"]];
                    continue; 
                }

                if ($httpCode >= 400) {
                    $lastError = ["error" => ["code" => $httpCode, "message" => "HTTP Error {$httpCode}: " . strip_tags($response)]];
                    continue; 
                }

                $decoded = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded["result"] ?? $decoded["error"] ?? $decoded;
                }

                return $response; 
            }

            $safeTarget = escapeshellcmd($target);
            $procKey = md5($safeTarget);
            
            $isNew = false;
            $activeProc = self::getBridgeProcess($procKey);
            
            if ($config["persistent"] && $activeProc && is_resource($activeProc["process"])) {
                $process = $activeProc["process"];
                $pipes = $activeProc["pipes"];
            } else {
                $descriptors = [
                    0 => ["pipe", "r"], 
                    1 => ["pipe", "w"], 
                    2 => ["pipe", "w"]  
                ];

                $env = $_ENV;
                if (!empty($config["auth"])) {
                    $env["MCP_AUTH_TOKEN"] = $config["auth"];
                    $env["API_KEY"] = $config["auth"];
                }

                $process = proc_open($safeTarget, $descriptors, $pipes, null, $env);

                if (!is_resource($process)) {
                    $lastError = ["error" => ["code" => -32000, "message" => "Failed to open local MCP process: $target"]];
                    continue; 
                }
                
                stream_set_blocking($pipes[1], false);
                
                $isNew = true;
                if ($config["persistent"]) {
                    self::registerBridgeProcess($procKey, $process, $pipes);
                }
            }
            
            if ($isNew) {
                $initReq = json_encode([
                    "jsonrpc" => "2.0",
                    "id" => "init_1",
                    "method" => "initialize",
                    "params" => [
                        "protocolVersion" => "2024-11-05",
                        "capabilities" => (object) [],
                        "clientInfo" => ["name" => "PHAI_Bridge", "version" => "6.0"]
                    ]
                ]) . "\n";
                fwrite($pipes[0], $initReq);

                $startTime = time();
                while (time() - $startTime < 5) {
                    if (fgets($pipes[1]) !== false) break;
                    usleep(100000); 
                }

                fwrite($pipes[0], json_encode(["jsonrpc" => "2.0", "method" => "notifications/initialized"]) . "\n");
            }
            
            fwrite($pipes[0], $payload . "\n");

            $actualRes = null;
            $startTime = microtime(true);

            while ((microtime(true) - $startTime) < $config["timeout"]) {
                $line = fgets($pipes[1]);
                if ($line !== false && trim($line) !== "") {
                    $decodedLine = json_decode(trim($line), true);
                    if (is_array($decodedLine) && isset($decodedLine["id"]) && $decodedLine["id"] === $requestId) {
                        $actualRes = $decodedLine;
                        break;
                    }
                }
                usleep(10000); 
            }

            if (!$config["persistent"]) {
                foreach ($pipes as $pipe) fclose($pipe);
                proc_terminate($process); proc_close($process);
            }

            if (is_array($actualRes)) {
                return $actualRes["result"] ?? $actualRes["error"] ?? $actualRes;
            }

            $lastError = ["error" => ["code" => -32000, "message" => "Timeout or invalid response from local MCP server."]];
        }

        return $lastError; 
    }

    private function handleAiPlanner($requestId, array $params)
    {
        $goal = $params["goal"] ?? "";
        $steps = [];

        foreach ($this->tools as $name => $tool) {
            if (str_contains(strtolower($name), strtolower($goal)))
                $steps[] = $name;
        }

        if (!$steps)
            return $this->generateError($requestId, "No plan formulated for goal.");

        $result = null;
        foreach ($steps as $step) {
            $result = $this->executeWithRetries($this->tools[$step], $params);
        }

        return $this->generateSuccess($requestId, ["final_result" => $result, "executed_steps" => $steps]);
    }

    private function paginateList($requestId, array $items, array $params, string $keyName)
    {
        $cursor = $params["cursor"] ?? null;
        $limit = 50;

        $values = array_values($items);
        $offset = $cursor ? (int) base64_decode($cursor) : 0;

        $slicedData = array_slice($values, $offset, $limit);
        $nextOffset = $offset + $limit;

        $response = [
            $keyName => array_map(function ($item) use ($keyName) {
                $clean = ["name" => $item["name"], "description" => $item["description"]];
                if ($keyName === "tools")
                    $clean["inputSchema"] = $item["inputSchema"];
                if ($keyName === "prompts")
                    $clean["arguments"] = $item["arguments"] ?? [];
                if ($keyName === "resources")
                    $clean["uri"] = $item["uri"];
                if ($keyName === "resourceTemplates")
                    $clean["uriTemplate"] = $item["uriTemplate"];
                return $clean;
            }, $slicedData)
        ];

        if ($nextOffset < count($values))
            $response["nextCursor"] = base64_encode((string) $nextOffset);

        return $this->generateSuccess($requestId, $response);
    }

    private function formatSmartResponse($requestId, $response)
    {
        if (is_string($response))
            return $this->generateSuccess($requestId, ["content" => [["type" => "text", "text" => $response]]]);
        if (is_array($response) && isset($response["error"]))
            return json_encode(["jsonrpc" => "2.0", "id" => $requestId, "error" => $response["error"]]);
        return $this->generateSuccess($requestId, $response);
    }

    private function generateSuccess($requestId, $result)
    {
        return json_encode(["jsonrpc" => "2.0", "id" => $requestId, "result" => $result]);
    }

    private function generateError($requestId, string $message, int $code = -32601)
    {
        return json_encode(["jsonrpc" => "2.0", "id" => $requestId, "error" => ["code" => $code, "message" => $message]]);
    }
}

class PhaiBuilder
{
    private array $reference;

    public function __construct(array &$reference)
    {
        $this->reference = &$reference;
    }

    public function middleware(callable $callback): self
    {
        $this->reference["middlewares"][] = $callback;
        return $this;
    }

    public function retries(int $amount): self
    {
        $this->reference["retries"] = $amount;
        return $this;
    }
}

class PhaiMemory
{
    public function set(string $key, $value, ?int $expiration = null, array $tags = [])
    {
        if (class_exists("PHLS"))
            \PHLS::add($key, $value, $expiration, $tags);
    }

    public function remove(string $key)
    {
        if (class_exists("PHLS"))
            \PHLS::remove($key);
    }
}

class PHAI_AI
{
    public static function call(string $provider, mixed $input, string $apiKey, array $options = [])
    {
        $customModels = PHAI::getModels($provider);
        $models = $customModels ?? (isset($options["model"]) ? (array)$options["model"] : self::getDefaultModels($provider));
        
        $messages = self::normalizeMessages($input);
        $lastError = null;

        foreach ($models as $model) {
            $options["model"] = $model;
            try {
                $config = self::getProviderConfig($provider, $apiKey, $options);
                $payload = self::buildUniversalPayload($provider, $messages, $options);
                $ssl_verify = !(defined("DEBUG_MODE") && DEBUG_MODE === true);
                $ch = curl_init($config["url"]);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => $config["headers"],
                    CURLOPT_TIMEOUT => $options["timeout"] ?? 15, 
                    CURLOPT_SSL_VERIFYPEER => $ssl_verify,
                    CURLOPT_SSL_VERIFYHOST => $ssl_verify ? 2 : 0
                ]);
                $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); if (is_resource($ch)) { curl_close($ch); }

                if ($httpCode >= 400) {
                    $lastError = "[$provider] Model '$model' Error ($httpCode): " . strip_tags($response);
                    if ($httpCode === 429) continue; 
                    throw new \Exception($lastError);
                }
                return ["model" => $model, "text" => self::parseUniversalResponse($provider, $response), "raw" => json_decode($response, true)];
            } catch (\Throwable $e) { $lastError = $e->getMessage(); continue; }
        }
        throw new \Exception($lastError ?? "[$provider] Failed.");
    }

    private static function normalizeMessages($input): array
    {
        if (is_string($input)) return [["role" => "user", "content" => $input]];
        if (is_array($input)) {
            if (isset($input["role"])) return [$input];
            if (isset($input[0]["role"])) return $input;
            if (isset($input[0]["parts"])) return array_map(fn($c) => ["role" => ($c["role"] ?? "user"), "content" => $c["parts"][0]["text"] ?? ""], $input);
        }
        return [["role" => "user", "content" => json_encode($input)]];
    }

    private static function buildUniversalPayload($p, array $msg, array $opt)
    {
        $model = $opt["model"];
        if ($p === "gemini") {
            $contents = [];
            foreach ($msg as $m) {
                $parts = [];
                if (is_array($m["content"])) {
                    foreach ($m["content"] as $it) {
                        if ($it["type"] === "text") $parts[] = ["text" => $it["text"]];
                        elseif ($it["type"] === "image_url") $parts[] = ["inline_data" => ["mime_type" => "image/jpeg", "data" => self::getBase64($it["image_url"]["url"])]];
                    }
                } else { $parts[] = ["text" => $m["content"]]; }
                $contents[] = ["role" => $m["role"] === "assistant" ? "model" : "user", "parts" => $parts];
            }
            return ["contents" => $contents, "generationConfig" => ["temperature" => $opt["temperature"] ?? 0.7]];
        }
        if ($p === "anthropic") {
            $newMsg = [];
            foreach ($msg as $m) {
                $c = $m["content"];
                if (is_array($c)) {
                    $nc = [];
                    foreach ($c as $it) {
                        if ($it["type"] === "text") $nc[] = ["type" => "text", "text" => $it["text"]];
                        elseif ($it["type"] === "image_url") $nc[] = ["type" => "image", "source" => ["type" => "base64", "media_type" => "image/jpeg", "data" => self::getBase64($it["image_url"]["url"])]];
                    }
                    $c = $nc;
                }
                $newMsg[] = ["role" => $m["role"], "content" => $c];
            }
            return ["model" => $model, "messages" => $newMsg, "max_tokens" => $opt["max_tokens"] ?? 1024];
        }
        if ($p === "cohere") return ["message" => is_array($msg[count($msg)-1]["content"]) ? "multi-modal-not-supported" : $msg[count($msg)-1]["content"], "model" => $model];
        if ($p === "huggingface") return ["inputs" => $msg[count($msg)-1]["content"]];
        if ($p === "elevenlabs") return ["text" => $msg[count($msg)-1]["content"], "model_id" => $model];

        return ["model" => $model, "messages" => $msg, "temperature" => $opt["temperature"] ?? 0.7, "max_tokens" => $opt["max_tokens"] ?? 2048];
    }

    private static function getBase64($url) { 
        if (str_contains($url, "base64,")) return explode("base64,", $url)[1];
        return base64_encode(@file_get_contents($url) ?: "");
    }

    private static function getProviderConfig($p, $key, $opt) {
        $model = $opt["model"];
        $base = [
            "gemini"      => ["url" => "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=$key", "headers" => ["Content-Type: application/json"]],
            "anthropic"   => ["url" => "https://api.anthropic.com/v1/messages", "headers" => ["x-api-key: $key", "anthropic-version: 2023-06-01", "Content-Type: application/json"]],
            "groq"        => ["url" => "https://api.groq.com/openai/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "cerebras"    => ["url" => "https://api.cerebras.ai/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "sambanova"   => ["url" => "https://api.sambanova.ai/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "mistral"     => ["url" => "https://api.mistral.ai/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "deepseek"    => ["url" => "https://api.deepseek.com/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "huggingface" => ["url" => "https://api-inference.huggingface.co/models/{$model}", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "openai"      => ["url" => "https://api.openai.com/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "openrouter"  => ["url" => "https://openrouter.ai/api/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "together"    => ["url" => "https://api.together.xyz/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "fireworks"   => ["url" => "https://api.fireworks.ai/inference/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "lepton"      => ["url" => "https://api.lepton.ai/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "novita"      => ["url" => "https://api.novita.ai/v3/openai/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "hyperbolic"  => ["url" => "https://api.hyperbolic.xyz/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "cohere"      => ["url" => "https://api.cohere.ai/v1/chat", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "perplexity"  => ["url" => "https://api.perplexity.ai/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "github"      => ["url" => "https://models.inference.ai.azure.com/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "nvidia"      => ["url" => "https://integrate.api.nvidia.com/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "xai"         => ["url" => "https://api.x.ai/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "replicate"   => ["url" => "https://api.replicate.com/v1/predictions", "headers" => ["Authorization: Token $key", "Content-Type: application/json"]],
            "assemblyai"  => ["url" => "https://api.assemblyai.com/v2/transcript", "headers" => ["Authorization: $key", "Content-Type: application/json"]],
            "puter"       => ["url" => "https://api.puter.com/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "aiml"        => ["url" => "https://api.aimlapi.com/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "edenai"      => ["url" => "https://api.edenai.run/v2/text/chat", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "clarifai"    => ["url" => "https://api.clarifai.com/v2/models/{$model}/outputs", "headers" => ["Authorization: Key $key", "Content-Type: application/json"]],
            "wit"         => ["url" => "https://api.wit.ai/message", "headers" => ["Authorization: Bearer $key"]],
            "deepai"      => ["url" => "https://api.deepai.org/api/text-generator", "headers" => ["api-key: $key"]],
            "elevenlabs"  => ["url" => "https://api.elevenlabs.io/v1/text-to-speech", "headers" => ["xi-api-key: $key", "Content-Type: application/json"]],
            "stabilityai" => ["url" => "https://api.stability.ai/v1/generation/stable-diffusion-xl-1024-v1-0/text-to-image", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]],
            "leonardoai"  => ["url" => "https://cloud.leonardo.ai/api/rest/v1/generations", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]]
        ];
        return $base[$p] ?? ["url" => $opt["base_url"] ?? "https://api.openai.com/v1/chat/completions", "headers" => ["Authorization: Bearer $key", "Content-Type: application/json"]];
    }

    // BUG FIX 1: Fix parseUniversalResponse gemini fallback
    private static function parseUniversalResponse($p, $res) {
        $d = json_decode($res, true);
        if ($p === "gemini") return $d["candidates"][0]["content"]["parts"][0]["text"] ?? "";
        if ($p === "anthropic") return $d["content"][0]["text"] ?? "";
        if ($p === "cohere") return $d["text"] ?? "";
        if ($p === "huggingface") return $d[0]["generated_text"] ?? "";
        return $d["choices"][0]["message"]["content"] ?? "";
    }

    private static function getDefaultModels($p): array {
        $m = [
            "gemini"      => ["gemini-2.0-pro", "gemini-2.0-flash", "gemini-1.5-pro-002", "gemini-1.5-flash-002"],
            "groq"        => ["llama-4-70b-preview", "llama-4-8b-instant", "llama-3.3-70b-versatile", "mixtral-8x22b-latest"],
            "cerebras"    => ["llama-4-70b", "llama-3.3-70b", "llama-3.1-70b"],
            "sambanova"   => ["Meta-Llama-4-70B-Instruct", "Meta-Llama-4-8B-Instruct", "Meta-Llama-3.3-70B-Instruct"],
            "mistral"     => ["mistral-large-2411", "pixtral-large-latest", "mistral-small-2411", "mistral-nemo-latest"],
            "deepseek"    => ["deepseek-v3", "deepseek-v2.5", "deepseek-coder-v2"],
            "anthropic"   => ["claude-4-sonnet-202601", "claude-4-haiku", "claude-3-7-sonnet", "claude-3-5-sonnet-latest"],
            "openrouter"  => ["anthropic/claude-4-sonnet:beta", "google/gemini-2.0-pro", "meta-llama/llama-4-70b:free"],
            "together"    => ["meta-llama/Llama-4-70B-Instruct-Turbo", "meta-llama/Llama-3.3-70B-Instruct-Turbo", "mistralai/Mixtral-8x22B-Instruct-v0.1"],
            "fireworks"   => ["accounts/fireworks/models/llama-v4-70b-instruct", "accounts/fireworks/models/llama-v3p3-70b-instruct"],
            "lepton"      => ["llama-4-70b", "llama-3-3-70b"],
            "novita"      => ["meta-llama/llama-4-70b-instruct", "meta-llama/llama-3.3-70b-instruct"],
            "hyperbolic"  => ["meta-llama/Llama-4-70B-Instruct", "meta-llama/Llama-3.3-70B-Instruct"],
            "huggingface" => ["meta-llama/Llama-4-8B-Instruct", "mistralai/Mistral-Nemo-Instruct-v1", "microsoft/Phi-4"],
            "cohere"      => ["command-r7b", "command-r-plus-08-2024", "command-r"],
            "perplexity"  => ["llama-4-sonar-large-online", "llama-3.3-sonar-small-online"],
            "openai"      => ["gpt-5", "gpt-4.5-preview", "gpt-4o", "gpt-4o-mini"],
            "github"      => ["gpt-5", "gpt-4o", "Llama-4-70B-Instruct", "Mistral-Large-2411"],
            "nvidia"      => ["meta/llama-4-70b-instruct", "nvidia/llama-3.1-nemotron-70b-instruct"],
            "xai"         => ["grok-3-latest", "grok-2-1212", "grok-beta"],
            "replicate"   => ["meta/meta-llama-4-70b-instruct", "mistralai/mistral-large-2411"],
            "assemblyai"  => ["conformer-3", "conformer-2"],
            "puter"       => ["claude-4-sonnet", "gpt-5", "llama-4-70b"],
            "aiml"        => ["gpt-5", "llama-4-70b"],
            "edenai"      => ["google/gemini-2.0-pro", "openai/gpt-5"],
            "clarifai"    => ["general-chat-v2"],
            "wit"         => ["2026-standard"],
            "deepai"      => ["text-generator-pro"],
            "elevenlabs"  => ["eleven_turbo_v2_5", "eleven_multilingual_v2"],
            "stabilityai" => ["stable-diffusion-3-5-large", "stable-diffusion-xl-1024-v1-0"],
            "leonardoai"  => ["Leonardo-Phoenix", "Leonardo-Kino-XL"]
        ];

        return $m[$p] ?? ["gpt-4o-mini", "gpt-3.5-turbo"];
    }
}
?>