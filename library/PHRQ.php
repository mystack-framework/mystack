<?php

/**
 * ============================================================================
 * Class: PHRQ
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * This class is a core component of the MyStack framework.
 * It provides essential functionalities tailored for high performance and security.
 */



class PHRQ {
    private static ?array $liveMapHostInfo = null;

    /**
     * Send an HTTP request from PHP using cURL.
     *
     * @param string $method HTTP method (GET/POST/PUT/PATCH/DELETE/HEAD/OPTIONS/CUSTOM).
     * @param string $url URL to send the request to.
     * @param array $headers Associative array of request headers.
     * @param mixed $body Request body data.
     * @param array $options cURL options.
     * @return mixed Response data.
     */
    public static function php($method, $url, $headers = [], $body = null, $options = []) {
        $ch = null;
        try {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('Only HTTP and HTTPS URLs are supported.');
            }

            $ch = curl_init($url);
    
            if ($ch === false) {
                throw new Exception('Failed to initialize cURL');
            }
    
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    
            $normalizedHeaders = [];
            foreach ((array) $headers as $key => $value) {
                $line = is_int($key) ? (string) $value : ((string) $key . ': ' . (string) $value);
                if (preg_match('/[\r\n]/', $line)) throw new \InvalidArgumentException('HTTP headers cannot contain line breaks.');
                $normalizedHeaders[] = $line;
            }
    
            if ($body !== null) {
                if (is_array($body)) {
                    $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $hasContentType = false;
                    foreach ($normalizedHeaders as $headerLine) {
                        if (stripos($headerLine, 'Content-Type:') === 0) { $hasContentType = true; break; }
                    }
                    if (!$hasContentType) $normalizedHeaders[] = 'Content-Type: application/json';
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            if ($normalizedHeaders !== []) curl_setopt($ch, CURLOPT_HTTPHEADER, $normalizedHeaders);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    
            foreach ($options as $key => $value) {
                if ($key === 'ssl') {
                    continue;
                }
                if (is_string($key) && defined($key)) {
                    curl_setopt($ch, constant($key), $value);
                }
            }

            // TLS verification is a framework invariant and cannot be disabled by
            // request options. This is deliberately applied after custom options.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
            $response = curl_exec($ch);
            if ($response === false) {
                throw new Exception(curl_error($ch));
            }
    
            $decoded_response = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded_response;
            }
            return $response;
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            if ($ch instanceof \CurlHandle || is_resource($ch)) curl_close($ch);
        }
    }

    /**
     * Generate JavaScript code for sending an HTTP request using XHR.
     *
     * @param string $method HTTP method (GET/POST/PUT/PATCH/DELETE/HEAD/OPTIONS/CUSTOM).
     * @param string $url URL to send the request to.
     * @param array $headers Associative array of request headers.
     * @param mixed $body Request body data.
     * @param array $options XHR options.
     * @return string JavaScript code.
     */
    public static function js($method, $url, $headers = [], $body = null, $options = []) {
        $jsFunction = <<<JS
async function(method, url, headers, body, options) {
    try {
        var xhr = new XMLHttpRequest();
        options = options || {};
        xhr.open(method, url, true);
        if (Number.isFinite(options.timeout) && options.timeout > 0) xhr.timeout = options.timeout;
        if (options.withCredentials === true) xhr.withCredentials = true;

        if (headers) {
            for (var key in headers) {
                xhr.setRequestHeader(key, headers[key]);
            }
        }

        if (body !== null && typeof body === 'object' && !(body instanceof FormData) && !(body instanceof Blob)) {
            var hasContentType = Object.keys(headers || {}).some(key => key.toLowerCase() === 'content-type');
            if (!hasContentType) xhr.setRequestHeader('Content-Type', 'application/json');
            body = JSON.stringify(body);
        }
        xhr.send(body);

        return new Promise((resolve, reject) => {
            xhr.onreadystatechange = function() {
                if (xhr.readyState === XMLHttpRequest.DONE) {
                    var responseText = xhr.responseText;
                    var contentType = xhr.getResponseHeader('Content-Type');
                    if (contentType && contentType.indexOf('application/json') !== -1 && responseText !== '') {
                        try { responseText = JSON.parse(responseText); }
                        catch (parseError) { reject(parseError); return; }
                    }
                    if (xhr.status >= 200 && xhr.status < 300) resolve(responseText);
                    else {
                        var error = new Error('HTTP ' + xhr.status);
                        error.status = xhr.status;
                        error.response = responseText;
                        reject(error);
                    }
                }
            };
            xhr.onerror = function() {
                reject(new Error(xhr.statusText || 'Network request failed'));
            };
            xhr.ontimeout = function() { reject(new Error('Request timed out')); };
        });
    } catch (e) {
        console.error('XHR request failed:', e);
        throw e;
    }
}
JS;
        // Call the function and return its code with await keyword
        $encodeFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
        return 'async function() { return await (' . $jsFunction . ')('
            . json_encode((string) $method, $encodeFlags) . ', '
            . json_encode((string) $url, $encodeFlags) . ', '
            . json_encode($headers, $encodeFlags) . ', '
            . json_encode($body, $encodeFlags) . ', '
            . json_encode($options, $encodeFlags)
            . '); }';
    }


    /**
     * Set HTTP response headers for API responses.
     *
     * @param string $method HTTP method for the request (e.g., "GET", "POST").
     * @param string $origin CORS origin (default is "*").
     * @param string $contentType MIME type of the response content (default is 'application/json').
     * @param array $additionalHeaders Array of additional headers to set.
     */
    public static function header($method = 'GET', $origin = '*', $contentType = 'application/json', $additionalHeaders = []) {
        header('Content-Type: '.$contentType);
        $headers = '*';
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'auto') {
            $method = $requestMethod;
        }
        if (strtoupper($method) === 'POST') {
            $method = 'POST, OPTIONS';
            $headers = 'Content-Type, Authorization';
        }
        if ($requestMethod === 'OPTIONS') {
            self::status();
            exit(0);
        }
        header("Access-Control-Allow-Methods: $method");
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Headers: ".$headers);
        header('Access-Control-Max-Age: 86400');
        foreach ($additionalHeaders as $key => $value) {
            header("$key: $value");
        }
    }

    
    /**
     * Enable or disable CORS for API responses.
     *
     * @param bool $enable True to enable CORS, false to disable.
     * @param string $origin CORS origin (default is '*').
     */
    public static function cross($enable = true, string|array $origin = '*', bool $credentials = false) {
        if ($enable) {
            $requestedOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
            $selfOrigin = self::requestOrigin();
            $configured = is_array($origin) ? $origin : [$origin];
            $allowed = [];
            foreach ($configured as $candidate) {
                $normalized = self::normalizeOrigin((string) $candidate, $selfOrigin);
                if ($normalized === null) {
                    throw new \InvalidArgumentException('CORS origins must be *, self, a domain/IP, or an absolute HTTP(S) origin.');
                }
                $allowed[] = $normalized;
            }
            $allowed = array_values(array_unique($allowed));
            if ($credentials && in_array('*', $allowed, true)) {
                throw new \InvalidArgumentException('Credentialed CORS cannot use a wildcard origin.');
            }

            $responseOrigin = null;
            if (in_array('*', $allowed, true)) {
                $responseOrigin = '*';
            } elseif ($requestedOrigin !== '' && in_array(self::normalizeOrigin($requestedOrigin, $selfOrigin), $allowed, true)) {
                $responseOrigin = $requestedOrigin;
            } elseif ($requestedOrigin === '' && in_array($selfOrigin, $allowed, true)) {
                $responseOrigin = $selfOrigin;
            }

            if ($responseOrigin !== null) {
                header("Access-Control-Allow-Origin: {$responseOrigin}");
            }
            header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
            header("Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-XSRF-Token");
            if (!in_array('*', $allowed, true)) {
                self::appendVary('Origin');
            }
            if ($credentials) {
                header('Access-Control-Allow-Credentials: true');
            }

            // Functional baseline CSP for PHJS: executable expressions currently
            // require unsafe-eval and generated bootstrap blocks require inline.
            // All other resource types remain same-origin first and object/embed
            // execution is disabled.
            if (!self::hasResponseHeader('Content-Security-Policy')) {
                $connectSources = ["'self'", 'https:', 'wss:'];
                foreach ($allowed as $allowedOrigin) {
                    if ($allowedOrigin !== '*' && $allowedOrigin !== $selfOrigin) $connectSources[] = $allowedOrigin;
                }
                $connectSources[] = 'https://cloudflareinsights.com';
                header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://static.cloudflareinsights.com https://cdn.jsdelivr.net; script-src-elem 'self' 'unsafe-inline' https://static.cloudflareinsights.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: blob: https:; font-src 'self' data: https:; connect-src " . implode(' ', array_unique($connectSources)) . "; worker-src 'self' blob:");
            }
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
                http_response_code($requestedOrigin !== '' && $responseOrigin === null ? 403 : 204);
                exit(0);
            }
        }
    }

    private static function requestOrigin(): string
    {
        $secure = class_exists('PHRO', false) && is_callable(['PHRO', 'secure'])
            ? PHRO::secure()
            : (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
        $scheme = $secure ? 'https' : 'http';
        $host = preg_replace('/[\r\n].*/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return $scheme . '://' . $host;
    }

    private static function normalizeOrigin(string $origin, string $selfOrigin): ?string
    {
        $origin = trim($origin);
        if ($origin === '*') return '*';
        if ($origin === '' || strtolower($origin) === 'self') return $selfOrigin;
        if (!str_contains($origin, '://')) {
            $origin = (str_starts_with($selfOrigin, 'https://') ? 'https://' : 'http://') . $origin;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) return null;
        if (isset($parts['user']) || isset($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) return null;
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') return null;
        $normalized = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) $normalized .= ':' . (int) $parts['port'];
        return $normalized;
    }

    private static function appendVary(string $value): void
    {
        $values = [];
        foreach (headers_list() as $header) {
            if (stripos($header, 'Vary:') === 0) {
                $values = array_merge($values, array_map('trim', explode(',', substr($header, 5))));
            }
        }
        $values[] = $value;
        header('Vary: ' . implode(', ', array_unique(array_filter($values))), true);
    }

    private static function hasResponseHeader(string $name): bool
    {
        foreach (headers_list() as $header) {
            if (stripos($header, $name . ':') === 0) return true;
        }
        return false;
    }

    /**
     * Set HTTP response headers for API responses.
     *
     * @param int $code HTTP status code (default is 200).
     * @param string|null $msg Custom message for the response (default is null).
     * @return array HTTP response information containing code and message.
     */
    public static function status(int $code = 200, ?string $msg = null) {
        $statusMessages = [
            100 => 'Continue',
            101 => 'Switching Protocols',
            102 => 'Processing (WebDAV)',
            103 => 'Early Hints',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            207 => 'Multi-Status (WebDAV)',
            208 => 'Already Reported (WebDAV)',
            226 => 'IM Used',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            306 => 'Switch Proxy',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Payload Too Large',
            414 => 'URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Range Not Satisfiable',
            417 => 'Expectation Failed',
            418 => 'I’m a Teapot',
            421 => 'Misdirected Request',
            422 => 'Unprocessable Entity (WebDAV)',
            423 => 'Locked (WebDAV)',
            424 => 'Failed Dependency (WebDAV)',
            425 => 'Too Early',
            426 => 'Upgrade Required',
            428 => 'Precondition Required',
            429 => 'Too Many Requests',
            431 => 'Request Header Fields Too Large',
            451 => 'Unavailable For Legal Reasons',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
            506 => 'Variant Also Negotiates',
            507 => 'Insufficient Storage (WebDAV)',
            508 => 'Loop Detected (WebDAV)',
            510 => 'Not Extended',
            511 => 'Network Authentication Required',
            520 => 'Web Server Is Returning an Unknown Error',
            521 => 'Web Server Is Down',
            522 => 'Connection Timed Out',
            523 => 'Origin Is Unreachable',
            524 => 'A Timeout Occurred',
            525 => 'SSL Handshake Failed',
            526 => 'Invalid SSL Certificate',
            527 => 'Railgun Error',
            530 => 'Site is Frozen',
            600 => 'Custom Network Error',
            601 => 'Service Dependency Failure',
            602 => 'Request Rate Exceeded',
            603 => 'Database Unavailable',
            604 => 'Configuration Error',
            605 => 'Service Overloaded',
            606 => 'Timeout Waiting for Response',
            607 => 'Service Restarting',
            608 => 'Quota Limit Exceeded',
            609 => 'API Limit Reached',
            610 => 'Unprocessable Input',
            611 => 'Session Expired',
            612 => 'Request Blocked',
            613 => 'Security Violation',
            614 => 'License Expired',
            615 => 'Feature Not Supported',
            616 => 'User Action Required',
            617 => 'Invalid Credentials',
            618 => 'Token Expired',
            619 => 'Content Moderation Failed',
            620 => 'Blocked by Firewall',
            621 => 'Resource Locked',
            622 => 'Policy Violation',
            623 => 'Resource Not Allowed',
            1000 => 'DNS Query Timeout',
            1001 => 'DNS Record Not Found',
            1002 => 'SSL Certificate Mismatch',
            1003 => 'Rate Limiting Error',
            1004 => 'Blocked IP Address',
            1005 => 'Invalid Request Parameter',
            1006 => 'Unsupported Encoding',
            1007 => 'Content Security Policy Violation',
            1008 => 'Unrecognized Method',
            1009 => 'Malformed Request Body',
            1010 => 'Service Upgrade Required',
            1011 => 'Request Suspended',
            1012 => 'Server is Shutting Down',
            1013 => 'Server Restart Required',
            1014 => 'Read-Only Mode',
            1015 => 'Resource Quarantine',
            1016 => 'Blocked by Admin Policy',
            1017 => 'Service Dependency Unavailable',
            1018 => 'Service Undergoing Maintenance',
            1019 => 'Deprecated Version',
            1020 => 'API Version Deprecated',
            1021 => 'Data Conflict Detected',
            1022 => 'Service Not Available',
            1023 => 'Maintenance Mode Enabled',
            1024 => 'Data Integrity Violation',
            1025 => 'Operation Not Supported',
            1026 => 'Service Temporarily Unavailable',
            1027 => 'Invalid Session ID',
            1028 => 'Database Connection Failed',
            1029 => 'Database Lock Timeout',
            1030 => 'Duplicate Entry Detected',
            1031 => 'Invalid Data Format',
            1032 => 'Unsupported File Type',
            1033 => 'Storage Quota Exceeded',
            1034 => 'Data Migration Required',
            1035 => 'Resource Unavailable',
            1036 => 'Request Timeout Exceeded',
            1037 => 'Service Out of Scope',
            1038 => 'Operation Not Allowed in Current State',
            1039 => 'Feature Disabled',
            1040 => 'Invalid Access Level',
            1041 => 'Unsupported Authorization Method',
            1042 => 'Unapproved Application',
            1043 => 'Session Timeout',
            1044 => 'Authentication Timeout',
            1045 => 'Permission Denied',
            1046 => 'Malformed Authorization Header',
            1047 => 'Insufficient Permissions',
            1048 => 'Rate Limit Exceeded',
            1049 => 'Resource Already Exists',
            1050 => 'Invalid API Key',
            1051 => 'Data Type Mismatch',
            1052 => 'Invalid Parameter Value',
            1053 => 'Missing Required Parameter',
            1054 => 'Operation Aborted',
            1055 => 'Invalid Username or Password',
            1056 => 'Expired Token',
            1057 => 'Redirect Loop Detected',
            1058 => 'Invalid URL',
            1059 => 'Invalid Format',
            1060 => 'Service Temporarily Paused',
            1061 => 'Server Maintenance in Progress',
            1062 => 'Resource Locked for Editing',
            1063 => 'Session Already Active',
            1064 => 'Invalid Resource State',
            1065 => 'Unexpected Server Error',
            1066 => 'Internal Configuration Error',
            1067 => 'Feature Under Development',
            1068 => 'User Not Found',
            1069 => 'User Account Locked',
            1070 => 'User Account Suspended',
            1071 => 'Invalid User Input',
            1072 => 'Timeout Waiting for Data',
            1073 => 'Untrusted Source Detected',
            1074 => 'Data Expired',
            1075 => 'Unexpected Error Occurred',
            1076 => 'Insufficient Resources',
            1077 => 'Unexpected API Response',
            1078 => 'Invalid Request Format',
            1079 => 'Access Restricted',
            1080 => 'Network Error Occurred',
            1081 => 'Protocol Error',
            1082 => 'Connection Refused',
            1083 => 'Network Congestion Detected',
            1084 => 'SSL Configuration Error',
            1085 => 'Request Could Not Be Processed',
            1086 => 'Data Validation Failed',
            1087 => 'Unrecognized Token',
            1088 => 'Custom Error Detected',
            1089 => 'Inconsistent Data Detected',
            1090 => 'Service Out of Order',
            1091 => 'Service Will Be Back Soon',
            1092 => 'User Account Not Verified',
            1093 => 'Precondition Not Met',
            1094 => 'Authorization Required',
            1095 => 'Rate Limiting Exceeded',
            1096 => 'Invalid Operation',
            1097 => 'Request Not Found',
            1098 => 'Feature Disabled by Admin',
            1099 => 'Invalid Token',
            1100 => 'Security Token Required',
            1101 => 'Internal API Error',
            1102 => 'Connection Timeout',
            1103 => 'System Error Detected',
            1104 => 'Application Error Detected',
            1105 => 'Duplicate Request',
            1106 => 'Concurrent Modification Error',
            1107 => 'Service Not Configured',
            1108 => 'Unable to Load Resource',
            1109 => 'Custom Rate Limit Exceeded',
            1110 => 'Invalid Operation for Current State',
            1111 => 'Maintenance Break Scheduled',
            1112 => 'Insufficient Access Rights',
            1113 => 'Custom Invalid Error',
            1114 => 'Data Retrieval Error',
            1115 => 'Invalid Request Header',
            1116 => 'Service Configuration Error',
            1117 => 'Configuration Not Loaded',
            1118 => 'Invalid Authentication Token',
            1119 => 'Invalid Session',
            1120 => 'Invalid OAuth Token',
            1121 => 'Unexpected Behavior Detected',
            1122 => 'Custom Feature Unavailable',
            1123 => 'Configuration In Progress',
            1124 => 'Data Format Error',
            1125 => 'Data Processing Error',
            1126 => 'API Rate Limit Exceeded',
            1127 => 'User Action Required',
            1128 => 'User Input Required',
            1129 => 'Temporary Block Applied',
            1130 => 'Resource Expired',
            1131 => 'Feature Not Available',
            1132 => 'Invalid State Transition',
            1133 => 'Resource Usage Limit Reached',
            1134 => 'Service Not Available Temporarily',
            1135 => 'Service Maintenance in Progress',
            1136 => 'Service Will Be Back Shortly',
            1137 => 'Custom Server Error',
            1138 => 'Temporary Service Error',
            1139 => 'Data Modification Not Allowed',
            1140 => 'Invalid Callback',
            1141 => 'Not Supported',
            1142 => 'Invalid Webhook',
            1143 => 'Rate Limit Reached for User',
            1144 => 'Unexpected API Behavior',
            1145 => 'Resource Already Used',
            1146 => 'Concurrent Request Limit Reached',
            1147 => 'Request Failed',
            1148 => 'Service Blocked',
            1149 => 'Server Not Available',
            1150 => 'Invalid Payment Method',
            1151 => 'Payment Required',
            1152 => 'Outdated Client Version',
            1153 => 'User Needs to Reauthorize',
            1154 => 'Feature Deprecated',
            1155 => 'Service Overload',
            1156 => 'Service Requiring Approval',
            1157 => 'Policy Update Required',
            1158 => 'Resource Not Created',
            1159 => 'Action Not Allowed',
            1160 => 'Task Queued',
            1161 => 'Data Not Found',
            1162 => 'Too Many Active Sessions',
            1163 => 'Session Needs Renewal',
            1164 => 'Timeout Expired',
            1165 => 'Connection Lost',
            1166 => 'Data Transmission Error',
            1167 => 'Service Lag Detected',
            1168 => 'Slow Network Detected',
            1169 => 'Network Latency Issue',
            1170 => 'Service Restarting',
            1171 => 'Configuration Change Pending',
            1172 => 'Database Migration Required',
            1173 => 'Resource Unavailable Temporarily',
            1174 => 'In Maintenance Mode',
            1175 => 'Legacy Feature Disabled',
            1176 => 'User Registration Required',
            1177 => 'Custom Server Message',
            1178 => 'Invalid Request Timeout',
            1179 => 'Unsupported Resource Type',
            1180 => 'User Banned',
            1181 => 'Not Acceptable',
            1182 => 'Custom Rate Limit Reached',
            1183 => 'Service Disruption',
            1184 => 'Service Capacity Reached',
            1185 => 'Data Access Denied',
            1186 => 'Rate Limit Exceeded for Application',
            1187 => 'Service Request Denied',
            1188 => 'Not Allowed',
            1189 => 'Invalid Content Type',
            1190 => 'Invalid Access Token',
            1191 => 'Untrusted Application',
            1192 => 'Invalid State',
            1193 => 'Invalid IP Address',
            1194 => 'Unknown Error Occurred',
            1195 => 'Invalid Resource Access',
            1196 => 'Configuration Conflict',
            1197 => 'Unverified User',
            1198 => 'User Verification Required',
            1199 => 'Insufficient Funds',
            1200 => 'Invalid Request Structure',
            1201 => 'Service Queued',
            1202 => 'Service Interruption',
            1203 => 'Action Blocked',
            1204 => 'Unexpected API Status',
            1205 => 'Internal Service Error',
            1206 => 'Malformed URL',
            1207 => 'Custom Action Failed',
            1208 => 'Request Body Too Large',
            1209 => 'Resource Not Configured',
            1210 => 'Invalid User ID',
            1211 => 'User Needs Action',
            1212 => 'Invalid Transaction ID',
            1213 => 'Invalid Parameter',
            1214 => 'Transaction Error',
            1215 => 'Payment Declined',
            1216 => 'API Not Responding',
            1217 => 'Feature Lockout',
            1218 => 'Invalid Session Token',
            1219 => 'Feature Unavailable',
            1220 => 'Feature Not Configured',
            1221 => 'Feature Blocked',
            1222 => 'Invalid Account',
            1223 => 'Service Validation Failed',
            1224 => 'User Session Expired',
            1225 => 'Invalid Login Attempt',
            1226 => 'Rate Limit Resetting',
            1227 => 'Service Needs Restart',
            1228 => 'Network Error Detected',
            1229 => 'User Has No Permissions',
            1230 => 'Resource Not Initialized',
            1231 => 'Service Will Be Back Soon',
            1232 => 'Service Temporary Suspension',
            1233 => 'Rate Limit Will Reset Soon',
            1234 => 'API Not Available',
            1235 => 'Service Not Responding',
            1236 => 'Invalid Resource ID',
            1237 => 'Invalid Resource State',
            1238 => 'Invalid Data Entry',
            1239 => 'Service Dependencies Not Met',
            1240 => 'Resource Locked for Editing',
            1241 => 'Resource Already Exists',
            1242 => 'Resource Already Deleted',
            1243 => 'Resource Currently Unavailable',
            1244 => 'Data Type Conflict',
            1245 => 'Invalid Content Structure',
            1246 => 'User Registration Incomplete',
            1247 => 'User Needs Verification',
            1248 => 'User Suspended',
            1249 => 'User Needs to Re-Authenticate',
            1250 => 'Service Restart Required',
            1251 => 'Action Not Permitted',
            1252 => 'Service Downtime',
            1253 => 'Service Request Failed',
            1254 => 'API Downtime',
            1255 => 'System Error Detected',
            1256 => 'Resource Lock Failed',
            1257 => 'Service Temporarily Down',
            1258 => 'Resource Not Updated',
            1259 => 'Action Queued',
            1260 => 'Data Update Failed',
            1261 => 'Connection Refused',
            1262 => 'Invalid Configuration',
            1263 => 'Database Update Failed',
            1264 => 'Database Write Failed',
            1265 => 'Feature Needs Approval',
            1266 => 'Access Control Error',
            1267 => 'Service Under Review',
            1268 => 'Configuration Conflict Detected',
            1269 => 'Invalid Permissions',
            1270 => 'Operation Cancelled',
            1271 => 'Operation Not Allowed',
            1272 => 'Custom User Error',
            1273 => 'Server Too Busy',
            1274 => 'Service Unresponsive',
            1275 => 'Invalid Web Request',
            1276 => 'Request Not Permitted',
            1277 => 'Request Denied',
            1278 => 'Invalid Application Request',
            1279 => 'Rate Limit Applied',
            1280 => 'Service Setup Required',
            1281 => 'Invalid API Credentials',
            1282 => 'API Blocked',
            1283 => 'Unauthorized Access Attempt',
            1284 => 'Operation Timed Out',
            1285 => 'Request Rejected',
            1286 => 'Invalid Application ID',
            1287 => 'Server Error Detected',
            1288 => 'Feature Requires Reconfiguration',
            1289 => 'Insufficient System Resources',
            1290 => 'Feature Not Available',
            1291 => 'User Privileges Insufficient',
            1292 => 'User Registration Required',
            1293 => 'Action Not Allowed in Current Context',
            1294 => 'Service Configuration Error',
            1295 => 'Network Configuration Error',
            1296 => 'Unsupported Action',
            1297 => 'Invalid Session State',
            1298 => 'Invalid API Version',
            1299 => 'Rate Limit Reached for User',
            1300 => 'Unrecognized Response',
            1301 => 'Unknown Service Error',
            1302 => 'Invalid Content Encoding',
            1303 => 'Unrecognized Token',
            1304 => 'Invalid Credentials Provided',
            1305 => 'Data Not Found for User',
            1306 => 'Custom Rate Limit Exceeded',
            1307 => 'Custom Error Message',
            1308 => 'Server Processing Error',
            1309 => 'Invalid Query Parameter',
            1310 => 'Feature Version Conflict',
            1311 => 'Service Timeout',
            1312 => 'User Permission Change Required',
            1313 => 'API Key Required',
            1314 => 'Unexpected Error Occurred',
            1315 => 'Custom Timeout Error',
            1316 => 'Invalid Request Method',
            1317 => 'Service Unavailable',
            1318 => 'Error Fetching Data',
            1319 => 'Service Configuration Conflict',
            1320 => 'Invalid User Action',
            1321 => 'Custom Response Error',
            1322 => 'Service Needs Restart',
            1323 => 'Request Already Processed',
            1324 => 'Custom API Error',
            1325 => 'User Account Locked',
            1326 => 'Request Timed Out',
            1327 => 'Resource Access Denied',
            1328 => 'API Rate Limit Exceeded',
            1329 => 'Service Under Maintenance',
            1330 => 'Invalid Update Request',
            1331 => 'Data Source Error',
            1332 => 'Database Connection Failed',
            1333 => 'Service Response Error',
            1334 => 'Invalid File Upload',
            1335 => 'Database Query Failed',
            1336 => 'File Not Found',
            1337 => 'Custom Authentication Error',
            1338 => 'Connection Timeout',
            1339 => 'Invalid Request Format',
            1340 => 'Database Read Error',
            1341 => 'Resource Unavailable',
            1342 => 'Data Update Error',
            1343 => 'Data Validation Error',
            1344 => 'Connection Lost',
            1345 => 'Feature Not Implemented',
            1346 => 'Unsupported Operation',
            1347 => 'User Permission Required',
            1348 => 'Invalid Service Status',
            1349 => 'User Account Disabled',
            1350 => 'User Account Inactive',
            1351 => 'Invalid Subscription',
            1352 => 'Service Blocked',
            1353 => 'Request Already Completed',
            1354 => 'Rate Limit Already Exceeded',
            1355 => 'Unknown User Error',
            1356 => 'Session Management Error',
            1357 => 'Data Sync Error',
            1358 => 'Configuration Load Error',
            1359 => 'Unknown Resource Error',
            1360 => 'Invalid Resource Token',
            1361 => 'Service Maintenance Required',
            1362 => 'User Needs to Refresh Session',
            1363 => 'User Token Expired',
            1364 => 'Invalid API Endpoint',
            1365 => 'Action Not Supported',
            1366 => 'Invalid Subscription Status',
            1367 => 'Unexpected Error Occurred',
            1368 => 'User Needs to Retry',
            1369 => 'Server Restart Required',
            1370 => 'Unsupported Request Format',
            1371 => 'Feature Lockdown',
            1372 => 'Server Configuration Error',
            1373 => 'Unknown Error',
            1374 => 'Resource Unavailable for Editing',
            1375 => 'Request Denied for User',
            1376 => 'User Account Verification Required',
            1377 => 'Invalid Authorization Code',
            1378 => 'Service Requires Maintenance',
            1379 => 'Service Update Required',
            1380 => 'User Needs to Log In Again',
            1381 => 'Rate Limit Warning',
            1382 => 'User Account Expired',
            1383 => 'Unexpected Server Behavior',
            1384 => 'Invalid Session Timeout',
            1385 => 'Operation Not Found',
            1386 => 'Request Blocked',
            1387 => 'Action Not Allowed',
            1388 => 'Service Overload Detected',
            1389 => 'Server Load Limit Exceeded',
            1390 => 'Invalid Configuration Setting',
            1391 => 'API Response Timeout',
            1392 => 'Custom Service Error',
            1393 => 'User Needs to Log In',
            1394 => 'Connection Error',
            1395 => 'Operation Not Permitted',
            1396 => 'Action Not Supported for User',
            1397 => 'User Permission Level Insufficient',
            1398 => 'Feature Disabled',
            1399 => 'Configuration Validation Error',
            1400 => 'Feature Not Accessible',
            1401 => 'Service Error Detected',
            1402 => 'Session State Error',
            1403 => 'Resource Conflict Detected',
            1404 => 'Invalid Token Provided',
            1405 => 'Database Access Denied',
            1406 => 'Service Configuration Required',
            1407 => 'Service Not Available Temporarily',
            1408 => 'Resource Update Error',
            1409 => 'User Not Authorized',
            1410 => 'User Account Needs Review',
            1411 => 'Custom Service Response Error',
            1412 => 'Service Call Failed',
            1413 => 'User Action Required for Access',
            1414 => 'Invalid User Input',
            1415 => 'Service Configuration Update Required',
            1416 => 'Invalid Account Status',
            1417 => 'User Session Blocked',
            1418 => 'Invalid Parameter Value',
            1419 => 'Custom Operation Error',
            1420 => 'Resource Unavailable for Action',
            1421 => 'Invalid API Key',
            1422 => 'Invalid Service Token',
            1423 => 'Invalid Account ID',
            1424 => 'Resource Not Active',
            1425 => 'Service Setup Required',
            1426 => 'User Registration Needed',
            1427 => 'Request Failed with Error',
            1428 => 'Invalid Authentication',
            1429 => 'Invalid API Request',
            1430 => 'Data Lock Error',
            1431 => 'Connection Timeout Error',
            1432 => 'Action Failed',
            1433 => 'User Permission Error',
            1434 => 'Resource Unavailable for Update',
            1435 => 'Invalid HTTP Method',
            1436 => 'Service Restart Required',
            1437 => 'Feature Request Denied',
            1438 => 'Invalid Service Identifier',
            1439 => 'Feature Not Available for User',
            1440 => 'Service Limit Reached',
            1441 => 'Unknown Error Code',
            1442 => 'Service Request Not Allowed',
            1443 => 'User Token Invalid',
            1444 => 'Invalid User Request',
            1445 => 'Service Blocked Temporarily',
            1446 => 'Configuration Validation Required',
            1447 => 'Invalid Resource Type',
            1448 => 'Service Blocked for User',
            1449 => 'Service Will Be Unavailable',
            1450 => 'Resource Access Denied',
            1451 => 'User Needs to Validate Account',
            1452 => 'Invalid Resource Action',
            1453 => 'User Needs to Re-Authenticate',
            1454 => 'Action Blocked for User',
            1455 => 'Invalid Webhook Token',
            1456 => 'Service Requires Attention',
            1457 => 'User Account Verification Needed',
            1458 => 'Service Temporarily Unavailable',
            1459 => 'Invalid Account Action',
            1460 => 'User Permission Change Required',
            1461 => 'Invalid API Method',
            1462 => 'Service Call Denied',
            1463 => 'Invalid Service Action',
            1464 => 'Service Request Timeout',
            1465 => 'Invalid URL Request',
            1466 => 'User Needs to Re-Log In',
            1467 => 'Service Requires Update',
            1468 => 'Server Error Occurred',
            1469 => 'User Account Locked Out',
            1470 => 'Resource Update Failed',
            1471 => 'Invalid Data Response',
            1472 => 'Request Not Processed',
            1473 => 'Invalid Client ID',
            1474 => 'Service Not Available',
            1475 => 'Server Error in Processing Request',
            1476 => 'Service Handling Error',
            1477 => 'User Needs to Accept Terms',
            1478 => 'Invalid Payment Request',
            1479 => 'Invalid Request URL',
            1480 => 'Service Access Denied',
            1481 => 'Service Error Detected',
            1482 => 'Request Body Invalid',
            1483 => 'Service Function Not Available',
            1484 => 'User Action Required for Authentication',
            1485 => 'Invalid Session Key',
            1486 => 'Server Communication Error',
            1487 => 'Service Setup Needed',
            1488 => 'Invalid Client Credentials',
            1489 => 'Feature Needs Attention',
            1490 => 'Service Request Error',
            1491 => 'Invalid Service Endpoint',
            1492 => 'Resource Not Found',
            1493 => 'Invalid Transaction',
            1494 => 'Service Blocked for Account',
            1495 => 'Configuration Error',
            1496 => 'Service Unavailable',
            1497 => 'Invalid URL Parameter',
            1498 => 'Invalid Request Context',
            1499 => 'Service Shutdown Detected',
        ];

        if ($msg === null OR is_null($msg) OR $msg === '') {
            $msg = $statusMessages[200];
        } else {
            $msg = $statusMessages[$code];
        }

        http_response_code($code);
        header("HTTP/1.1 $code $msg");

        return [
            'code' => $code,
            'message' => $msg,
        ];
    }    

    /**
     * Set HTTP response headers for file downloads.
     *
     * @param string $name Filename of the file being downloaded.
     * @param int $length Length of the file being downloaded.
     */
    public static function file($name, $length) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: '.$length);
    }

    private static function liveMapHostInfo(): array
    {
        if (self::$liveMapHostInfo !== null) return self::$liveMapHostInfo;
        $serverIp = trim((string) ($_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? ''));
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\\d+$/', '', $host);
        $serverIsPublic = filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (!$serverIsPublic && $host !== '' && filter_var($host, FILTER_VALIDATE_IP)) {
            $serverIp = $host;
        }
        if (!filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && $host !== '' && function_exists('gethostbyname')) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host) $serverIp = $resolved;
        }
        $info = [
            'hostIP' => filter_var($serverIp, FILTER_VALIDATE_IP) ? $serverIp : null,
            'hostLat' => null, 'hostLon' => null, 'hostCountryCode' => null,
            'hostCountry' => null, 'hostCity' => null, 'hostArea' => null,
            'hostIsp' => null, 'hostOrg' => null, 'hostAs' => null, 'hostVpn' => null,
        ];
        $publicIp = filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($publicIp) {
            $info['hostVpn'] = false;
            $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true, 'header' => "Accept: application/json\r\nUser-Agent: MyStack-PHRQ\r\n"]]);
            $decoded = null;
            foreach ([
                'https://ipapi.co/' . rawurlencode($publicIp) . '/json/',
                'https://ipwho.is/' . rawurlencode($publicIp),
            ] as $geoUrl) {
                $geo = @file_get_contents($geoUrl, false, $context);
                $candidate = is_string($geo) ? json_decode($geo, true) : null;
                if (is_array($candidate) && (($candidate['success'] ?? true) !== false)) {
                    $decoded = $candidate;
                    break;
                }
            }
            if (is_array($decoded)) {
                $connection = is_array($decoded['connection'] ?? null) ? $decoded['connection'] : [];
                $info['hostLat'] = isset($decoded['latitude']) && is_numeric($decoded['latitude']) ? (float) $decoded['latitude'] : null;
                $info['hostLon'] = isset($decoded['longitude']) && is_numeric($decoded['longitude']) ? (float) $decoded['longitude'] : null;
                $info['hostCountryCode'] = $decoded['country_code'] ?? null;
                $info['hostCountry'] = $decoded['country_name'] ?? ($decoded['country'] ?? null);
                $info['hostCity'] = $decoded['city'] ?? null;
                $info['hostArea'] = $decoded['region'] ?? ($decoded['region_name'] ?? null);
                $info['hostIsp'] = $decoded['org'] ?? ($connection['isp'] ?? null);
                $info['hostOrg'] = $decoded['org'] ?? ($connection['org'] ?? null);
                $info['hostAs'] = $decoded['asn'] ?? ($connection['asn'] ?? null);
                $info['hostVpn'] = isset($decoded['security']['vpn']) ? (bool) $decoded['security']['vpn'] : false;
            }
        }
        return self::$liveMapHostInfo = $info;
    }

    /**
     * Handle live map data collection and processing based on the request.
     *
     * This method collects footprint data from the current request and processes it,
     * unless the request matches an entry in the skip list. It limits the number of 
     * entries stored for the 'livemap' data type.
     *
     * @param string $url The URL endpoint for the live map (default is '/livemap').
     * @param array $skipList An associative array mapping request methods to URLs, that should be skipped from processing.
     * @param int $limit Maximum number of livemap entries to store.
     * @param int $time Time window in seconds to consider entries as 'live'.
     */
    public static function livemap($url = '/livemap', $skipList = [], $limit = 10, $time = 60 * 24) {
        if (!class_exists('PHDE') || !PHDE::isDebug()) {
            return;
        }
        $url = '/' . trim((string) $url, '/') ?: '/';
        $limit = max(1, min(500, (int) $limit));
        $time = max(1, min(60 * 24 * 30, (int) $time));
        PHRO::track(true);
        $footprint = PHRO::footprint();
        $hostInfo = self::liveMapHostInfo();
        $skip = false;
        
        $parsedRoot = parse_url(PHRO::root());
        $rootUrl = rtrim((string) ($parsedRoot['path'] ?? ''), '/');
        $requestUri = (string) ($footprint['REQUEST_URI'] ?? '/');
        $nowURL = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        if ($rootUrl !== '' && str_starts_with($nowURL, $rootUrl)) {
            $nowURL = substr($nowURL, strlen($rootUrl)) ?: '/';
        }
        
        foreach ($skipList as $method => $skipUrl) {
            $skipPath = parse_url((string) $skipUrl, PHP_URL_PATH) ?: '/';
            if ($rootUrl !== '' && str_starts_with($skipPath, $rootUrl)) {
                $skipPath = substr($skipPath, strlen($rootUrl)) ?: '/';
            }
            $skipPath = '/' . trim($skipPath, '/') ?: '/';
            if (strtoupper((string) ($footprint['REQUEST_METHOD'] ?? 'GET')) === strtoupper((string) $method) && $skipPath === ('/' . trim($nowURL, '/'))) {
                $skip = true;
                break;
            }
        }
        
        if ($skip === false) {
            $data = [
                'hostIP' => $footprint['hostIP'] ?? $hostInfo['hostIP'],
                'hostLat' => $footprint['hostLat'] ?? $hostInfo['hostLat'],
                'hostLon' => $footprint['hostLon'] ?? $hostInfo['hostLon'],
                'hostCountryCode' => $footprint['hostCountryCode'] ?? $hostInfo['hostCountryCode'],
                'hostCountry' => $footprint['hostCountry'] ?? $hostInfo['hostCountry'],
                'hostCity' => $footprint['hostCity'] ?? $hostInfo['hostCity'],
                'hostArea' => $footprint['hostArea'] ?? $hostInfo['hostArea'],
                'hostIsp' => $footprint['hostIsp'] ?? $hostInfo['hostIsp'],
                'hostOrg' => $footprint['hostOrg'] ?? $hostInfo['hostOrg'],
                'hostAs' => $footprint['hostAs'] ?? $hostInfo['hostAs'],
                'hostVpn' => $footprint['hostVpn'] ?? $hostInfo['hostVpn'],
                'clientPlatform' => $footprint['clientPlatform'] ?? $footprint['clientXPlatform'] ?? null,
                'clientBrowser' => $footprint['clientBrowser'] ?? $footprint['clientXBrowser'] ?? null,
                'clientIP' => $footprint['clientIP'] ?? $footprint['clientXIP'] ?? null,
                'clientLat' => $footprint['clientLat'] ?? $footprint['clientXLat'] ?? null,
                'clientLon' => $footprint['clientLon'] ?? $footprint['clientXLon'] ?? null,
                'clientCountryCode' => $footprint['clientCountryCode'] ?? $footprint['clientXCountryCode'] ?? null,
                'clientCountry' => $footprint['clientCountry'] ?? $footprint['clientXCountry'] ?? null,
                'clientCity' => $footprint['clientCity'] ?? $footprint['clientXCity'] ?? null,
                'clientArea' => $footprint['clientArea'] ?? $footprint['clientXArea'] ?? null,
                'clientIsp' => $footprint['clientIsp'] ?? $footprint['clientXIsp'] ?? null,
                'clientVpn' => $footprint['clientProxy'] ?? $footprint['clientXProxy'] ?? null,
                // 'agent1' => $footprint['Sec-Ch-Ua'] ?? null,
                // 'agent2' => $footprint['HTTP_USER_AGENT'] ?? null,
                // 'agent3' => $footprint['User-Agent'] ?? null,
                'clientPlatform' => $footprint['clientPlatform'] ?? null,
                'clientDevice' => $footprint['clientDevice'] ?? null,
                'clientIs_desktop' => $footprint['clientDesktop'] ?? null,
                'clientIs_mobile' => $footprint['clientMobile'] ?? null,
                'requestMETHOD' => isset($footprint['requestMETHOD']) ? $footprint['requestMETHOD'] : (isset($footprint['REQUEST_METHOD']) ? $footprint['REQUEST_METHOD'] : null),
                'requestURL' => isset($footprint['requestURL']) ? $footprint['requestURL'] : (isset($footprint['REQUEST_URI']) ? $footprint['REQUEST_URI'] : null),
                'netkey' => isset($footprint['clientNetkey']) ? $footprint['clientNetkey'] : null,
                'devicekey' => isset($footprint['clientDevicekey']) ? $footprint['clientDevicekey'] : null,
                'requestTime' => isset($footprint['requestTime']) ? $footprint['requestTime'] : (isset($footprint['REQUEST_TIME']) ? $footprint['REQUEST_TIME'] : null),
                'requestFLOAT' => isset($footprint['requestFLOAT']) ? $footprint['requestFLOAT'] : (isset($footprint['REQUEST_TIME_FLOAT']) ? $footprint['REQUEST_TIME_FLOAT'] : null),
                'datetime' => isset($footprint['requestFLOAT']) ? date("Y-m-d H:i:s", (int)$footprint['requestFLOAT']) : (isset($footprint['REQUEST_TIME_FLOAT']) ? date("Y-m-d H:i:s", (int)$footprint['REQUEST_TIME_FLOAT']) : null)
            ];
            // $data = array_merge($footprint, $data);
    
            PHLS::limitizer('livemap', $data, $limit, $time);
        }

        PHRO::get($url, function() {
            if (!class_exists('PHDE') || !PHDE::isDebug()) {
                http_response_code(404);
                return;
            }
            PHRQ::header("GET", "*", "text/html; charset=UTF-8", []);
                ob_start();
                print <<<EOT
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>🟢Tratraffic Map (PHRQ)</title>
                    <meta name="icon" content="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABIAAAASCAMAAABhEH5lAAAB3VBMVEVHcEydiSEAAAChNxsbCQSQfR9HGwi+RCELBALApykQEAepOh2xPR6wmCVVMw7cTiWGdB14KhUAAABkWBd5aRu+QiDVSSPPtCqUMxrGRSG5QB+GfEaJMBg+NQZ/KhOtn02okiTWUi2YNhvAQiC+QyLAmildURVfEADWh3G9fG1pXBjEqihNRBOELxikORyKbR29pCcuKQ2QPxrrWCjxZirx0ij63R/jUSZ/jKv94B44PWPpaiqircLoVCbuZChgao7vYSnuzymUnbjqei/Iw4OPl5/glCOWp8bzthitv9nsxCn11yXE0t+KmbyOm7OHjaNzf6Lk6vLatyayvNLxbzrByNN3gqakuNjlxyy1rZJbZo+5qDjobjrpyizfwiyanLGWiJWGfFm9jDd6a2rddR/dvx2UbVL340Oqm3y2eCbtzSBue53akx311xZ+h56bizvx1i6/p3fzpS7qqhq5vszshi5OV4GhmaW0oYW2sr7Fj331glGbrc3xbDLKaENpc5jGmIzSn5bbhmXQ3OmqUkKur76Hb32zblc/RWx6em5UWn3jyivpyyvUwU1teJu/dV3JwHXCw7jcdU3rzxuQkorb0Hnx1B6wq3RgZ4DErC5tcoDgtCrLZTmAd0vjXybI0qtxAAAAM3RSTlMAtQJvD50l1wjYFYKX2j7+jVcBb3zc+PpY8KzqRjA188Twh73N11Ea59tk90p1r6zjM6B1d64/AAABMUlEQVQY02MQUhJlBwNVTjBg5WBg4DE3BgFrL1MQiGUECnGbm5g4O1uXBnl7ZVmaxbIyMTAwA4Wsc9w8it0yUy3NIgQYGBgk+B1CqotyvT1SktItzeJEgEIyYiEN5RWFkRlpya4uBUFaQCE5xUY/e/8S/zwLK3t3F3E1oBCbRqBNgFNoqE22VYCnjzwfUEhdM96iJswn0LPMyt3XT1oWKMSi0tIaXt8c7BvVEe6az8sGFOJQNq5rCwu26Yvq6ox25OUCCjFJ2dY2VSY42U+Z2GtmqsACFGLgsY2pau93mhE9dUK3I8g/IB/Z2dlNip8VOX2yWQ/IPyAfOZiYxCRYWCROg/gH6CNJfltjhzmJc2c6xmkLg4U4+HT1RMXmzRY3YBTR4WKAAg42QSN9YUOwbQwA5ztHGpIVCvUAAAAASUVORK5CYII=">
                    <meta name="description" content="PHKing Dev | PHP Framework">
                    <meta name="author" content="sakibweb">
                    <link rel="icon" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABIAAAASCAMAAABhEH5lAAAB3VBMVEVHcEydiSEAAAChNxsbCQSQfR9HGwi+RCELBALApykQEAepOh2xPR6wmCVVMw7cTiWGdB14KhUAAABkWBd5aRu+QiDVSSPPtCqUMxrGRSG5QB+GfEaJMBg+NQZ/KhOtn02okiTWUi2YNhvAQiC+QyLAmildURVfEADWh3G9fG1pXBjEqihNRBOELxikORyKbR29pCcuKQ2QPxrrWCjxZirx0ij63R/jUSZ/jKv94B44PWPpaiqircLoVCbuZChgao7vYSnuzymUnbjqei/Iw4OPl5/glCOWp8bzthitv9nsxCn11yXE0t+KmbyOm7OHjaNzf6Lk6vLatyayvNLxbzrByNN3gqakuNjlxyy1rZJbZo+5qDjobjrpyizfwiyanLGWiJWGfFm9jDd6a2rddR/dvx2UbVL340Oqm3y2eCbtzSBue53akx311xZ+h56bizvx1i6/p3fzpS7qqhq5vszshi5OV4GhmaW0oYW2sr7Fj331glGbrc3xbDLKaENpc5jGmIzSn5bbhmXQ3OmqUkKur76Hb32zblc/RWx6em5UWn3jyivpyyvUwU1teJu/dV3JwHXCw7jcdU3rzxuQkorb0Hnx1B6wq3RgZ4DErC5tcoDgtCrLZTmAd0vjXybI0qtxAAAAM3RSTlMAtQJvD50l1wjYFYKX2j7+jVcBb3zc+PpY8KzqRjA188Twh73N11Ea59tk90p1r6zjM6B1d64/AAABMUlEQVQY02MQUhJlBwNVTjBg5WBg4DE3BgFrL1MQiGUECnGbm5g4O1uXBnl7ZVmaxbIyMTAwA4Wsc9w8it0yUy3NIgQYGBgk+B1CqotyvT1SktItzeJEgEIyYiEN5RWFkRlpya4uBUFaQCE5xUY/e/8S/zwLK3t3F3E1oBCbRqBNgFNoqE22VYCnjzwfUEhdM96iJswn0LPMyt3XT1oWKMSi0tIaXt8c7BvVEe6az8sGFOJQNq5rCwu26Yvq6ox25OUCCjFJ2dY2VSY42U+Z2GtmqsACFGLgsY2pau93mhE9dUK3I8g/IB/Z2dlNip8VOX2yWQ/IPyAfOZiYxCRYWCROg/gH6CNJfltjhzmJc2c6xmkLg4U4+HT1RMXmzRY3YBTR4WKAAg42QSN9YUOwbQwA5ztHGpIVCvUAAAAASUVORK5CYII=">

                    <style>
                        body {
                            margin: 0;
                            overflow: hidden;
                            background-color: black;
                            display: flex;
                            flex-direction: column;
                            align-items: center;
                            /* Center the contents */
                            height: 100vh;
                            /* Use full viewport height */
                        }

                        #map {
                            position: relative;
                            top: 0;
                            left: 0;
                            width: 100%;
                            height: 100%;
                        }

                        .header,
                        .footer {
                            position: absolute;
                            width: calc(60% - 60px);
                            /* Keep this at 60% */
                            background: rgba(255, 255, 255, 0.02);
                            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
                            backdrop-filter: blur(2px);
                            -webkit-backdrop-filter: blur(2px);
                            color: white;
                            text-align: center;
                            padding: 10px;
                            z-index: 2;
                            /* Ensure the header is on top */
                        }

                        .header {
                            top: 0px;
                            /* Set some space from the top */
                            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
                            color: white;
                            text-decoration: none;
                            font-weight: bold;
                            font-size: 18px;
                        }
                        .header a {
                            color: white;
                            text-decoration: none;
                            font-weight: bold;
                            font-size: 13px;
                        }

                        .footer {
                            bottom: 0px;
                            /* Set some space from the bottom */
                            display: flex;
                            justify-content: center;
                            border-top: 1px solid rgba(255, 255, 255, 0.2);
                        }

                        .color-palette {
                            width: auto;
                            display: flex;
                            justify-content: space-around;
                            align-content: center;
                            align-items: center;
                            flex-wrap: nowrap;
                            flex-direction: row;
                        }

                        .color-palette-item {
                            width: 20px;
                            height: 20px;
                            border-right: 1px solid rgba(255, 255, 255, 0.2);
                            border-radius: 5px;
                        }

                        .color-palette a {
                            padding-left: 5px;
                            padding-right: 8px;
                        }

                        .sidebar {
                            position: absolute;
                            left: 0;
                            width: 20%;
                            height: calc(100% - 100px);
                            background: rgba(255, 255, 255, 0.02);
                            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
                            backdrop-filter: blur(2px);
                            -webkit-backdrop-filter: blur(2px);
                            border-right: 1px solid rgba(255, 255, 255, 0.2);
                            color: white;
                            overflow-y: auto; /* Allow scrolling */
                            z-index: 2; /* Ensure the sidebar is on top */
                            padding: 10px;
                            margin-top: 41px;
                            margin-bottom: 40px;

                            /* Scrollbar styling for modern browsers */
                            scrollbar-width: thin; /* Sets scrollbar width (options: auto, thin, none) */
                            scrollbar-color: #484848bd #ffffff14; /* Thumb color #888, Track color #333 */
                            scroll-margin-right: 5px;
                        }

                        .details {
                            position: absolute;
                            /* top: 50px; Below the header */
                            right: 0;
                            width: 20%;
                            height: calc(100% - 100px);
                            /* Full height minus header and footer */
                            background: rgba(255, 255, 255, 0.02);
                            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
                            backdrop-filter: blur(2px);
                            -webkit-backdrop-filter: blur(2px);
                            border-left: 1px solid rgba(255, 255, 255, 0.2);
                            color: white;
                            overflow-y: auto;
                            /* Allow scrolling */
                            z-index: 2;
                            /* Ensure the details section is on top */
                            padding: 10px;
                            margin-top: 41px;
                            margin-bottom: 40px;
                            /* Scrollbar styling for modern browsers */
                            scrollbar-width: thin; /* Sets scrollbar width (options: auto, thin, none) */
                            scrollbar-color: #484848bd #ffffff14; /* Thumb color #888, Track color #333 */
                            scroll-margin-right: 5px;
                        }

                        .tooltip {
                            position: absolute;
                            background: rgba(255, 255, 255, 0.02);
                            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
                            backdrop-filter: blur(2px);
                            -webkit-backdrop-filter: blur(2px);
                            border: 1px solid rgba(255, 255, 255, 0.2);
                            color: white;
                            padding: 5px;
                            border-radius: 5px;
                            display: none;
                            /* Hidden by default */
                            z-index: 3;
                            /* Above everything else */
                        }

                        .attack-card {
                            background: rgba(255, 255, 255, 0.05);
                            border-radius: 8px;
                            padding: 10px;
                            margin-bottom: 10px;
                            color: white;
                            display: flex;
                            flex-direction: column;
                            border: 1px solid rgba(255, 255, 255, 0.2);
                            cursor: pointer; /* Change cursor to pointer */
                        }

                        .attack-header,
                        .attack-footer {
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                        }

                        .attack-country img {
                            width: 20px;
                            margin-right: 5px;
                        }

                        .attack-type {
                            background-color: #FF6347;
                            padding: 5px 10px;
                            border-radius: 12px;
                            color: white;
                            font-size: 12px;
                            text-transform: uppercase;
                        }

                        .attack-footer img {
                            width: 20px;
                            margin-right: 5px;
                        }

                        #attack-list {
                            padding-left: 0px;
                        }

                        .attack-body {
                            font-size: 14px;
                            margin-top: 8px;
                            margin-bottom: 8px;
                            display: flex;
                            flex-direction: row;
                            flex-wrap: nowrap;
                            align-items: center;
                            overflow-x: auto;
                            white-space: nowrap;
                            justify-content: space-between;
                            align-content: space-between;
                        }

                        #details-content {
                            font-size: 16px;
                        }
                        
                        #details-content strong, br {
                            font-weight: bold;
                            margin-top: 8px;
                            margin-bottom: 8px;
                            padding-top: 8px;
                            padding-bottom: 8px;
                        }

                        button {
                            background: rgba(255, 255, 255, 0.05);
                            border-radius: 8px;
                            padding: 10px;
                            margin-top: 10px;
                            color: white;
                            display: flex;
                            flex-direction: column;
                            border: 1px solid rgba(255, 255, 255, 0.2);
                            cursor: pointer; /* Change cursor to pointer */
                        }

                        /* Modal Styles */
                        .modal {
                            display: none; /* Hidden by default */
                            position: fixed; 
                            z-index: 1000; 
                            left: 0;
                            top: 0;
                            width: 100%; 
                            height: 100%; 
                            background-color: rgba(0,0,0,0.4); /* Black background with opacity */
                        }

                        /* Modal Content */
                        .modal-content {
                            position: absolute;
                            top: 50%;
                            left: 50%;
                            transform: translate(-50%, -50%);
                            background-color: #0000006b;
                            color: white;
                            padding: 20px;
                            border: 1px solid #888;
                            border-radius: 8px;
                            width: 300px;
                            box-shadow: 0px 0px 10px #000;
                        }

                        .modal-content h2 {
                            display: block;
                            font-size: 1.5em;
                            margin-inline-start: 0px;
                            margin-inline-end: 0px;
                            font-weight: bold;
                            unicode-bidi: isolate;
                        }

                        .close-btn {
                            float: right;
                            font-size: 28px;
                            cursor: pointer;
                        }

                        #settingsForm div {
                            display: flex;
                            flex-wrap: nowrap;
                            align-content: center;
                            justify-content: space-between;
                            align-items: center;
                            flex-direction: row;
                            margin-top: 5px;
                            margin-bottom: 5px;
                        }

                        #settingsForm select, #settingsForm input {
                            background-color: #0000006b;
                            color: white;
                            border: 1.5px solid white;
                            border-radius: 5px;
                            /* padding: 1px; */
                            padding-top: 1px;
                            padding-bottom: 1px;
                            padding-left: 5px;
                            padding-right: 5px;
                            margin: 2px 0;
                        }

                        #live-status { display:inline-block; margin-left:10px; color:#9ee7ff; font-size:12px; font-weight:600; }
                        .modal-content { max-width:calc(100vw - 28px); max-height:calc(100vh - 28px); overflow:auto; }
                        #settingsForm button { flex:1 1 90px; justify-content:center; }
                        @media (max-width:900px) {
                            .header,.footer { left:10px; width:calc(100% - 20px); }
                            .sidebar,.details { width:calc(100% - 20px); left:10px; right:auto; padding:8px; }
                            .sidebar { height:26%; margin-top:42px; }
                            .details { height:24%; bottom:42px; margin:0; }
                            .footer { padding:7px; overflow-x:auto; justify-content:flex-start; }
                            .color-palette { min-width:max-content; justify-content:flex-start; gap:4px; }
                        }
                        @media (max-width:560px) {
                            .header { font-size:14px; padding:8px; }
                            .header a { font-size:12px; }
                            #gset { display:inline-block; padding:4px 7px; border:1px solid #ffffff44; border-radius:5px; }
                            .sidebar { height:30%; }
                            .details { height:28%; }
                            .attack-card { padding:8px; margin-bottom:7px; }
                            .attack-body { gap:7px; font-size:12px; }
                            #settingsForm div { flex-wrap:wrap; gap:5px; }
                            #settingsForm label { flex:1 1 100%; }
                        }

                    </style>
                    <script src="https://cdn.jsdelivr.net/npm/globe.gl"></script>
                    <script>
                        // List of replacements for unwanted characters
                        const replacements = {
                            '+': '--',
                            '/': '__',
                            '=': '..',
                            '\'': '~~',
                            '"': '!!',
                            '`': '||',
                            "'": '**',
                            ' ': '%%',
                        };

                        // Reverse mapping for decoding
                        const reverseReplacements = Object.fromEntries(Object.entries(replacements).map(([key, value]) => [value, key]));

                        /**
                         * Compress and encode an object to a safe base64-like format.
                         * @param {Object} obj - The input object to be encoded.
                         * @returns {String} - Encoded and safe base64 string.
                         */
                        function encodeObjectToBase64(object) {
                            const jsonString = JSON.stringify(object);
                            const uriEncodedString = encodeURIComponent(jsonString);
                            return uriEncodedString;
                        }

                        /**
                         * Decode and decompress a safe base64-like format back to an object.
                         * @param {String} base64String - The encoded string.
                         * @returns {Object} - Decoded object.
                         */
                        function decodeBase64ToObject(base64String) {
                            const uriDecodedString = decodeURIComponent(base64String);
                            return JSON.parse(uriDecodedString);
                        }

                    </script>
                </head>

                <body>
                    <div class="header"><a href="https://github.com/sakibweb" target="_blank">🟢Tratraffic Map (PHRQ)</a><a id="gset" style="cursor: pointer; margin-left: 5px;">⚙️Settings</a></div>
                    <div id="map"></div>
                    <div class="sidebar" id="live-attacks">
                        <h3>Live Request</h3>
                        <ul id="attack-list"></ul>
                    </div>
                    <div class="details" id="attack-details">
                        <h3>Request Details</h3>
                        <p id="details-content">Select a request to view details.</p>
                    </div>
                    <div class="footer">
                        <div class="color-palette" id="color-palette">
                        <div class="color-palette-item" style="background-color: #FF1493;"></div><a>GET</a>
                        <div class="color-palette-item" style="background-color: #0125d5;"></div><a>POST</a>
                        <div class="color-palette-item" style="background-color: #1eb621;"></div><a>PUT</a>
                        <div class="color-palette-item" style="background-color: #c4c420;"></div><a>PATCH</a>
                        <div class="color-palette-item" style="background-color: #ba0000;"></div><a>DELETE</a>
                        <div class="color-palette-item" style="background-color: #8A2BE2;"></div><a>HEAD</a>
                        <div class="color-palette-item" style="background-color: #21a9b7;"></div><a>OPTIONS</a>
                        <div class="color-palette-item" style="background-color: #FF6347;"></div><a>CUSTOM</a>
                        <svg viewBox="-4 0 36 36" height="20" width="20" style="fill: #4682B4;"> <path d="M14,0 C21.732,0 28,5.641 28,12.6 C28,23.963 14,36 14,36 C14,36 0,24.064 0,12.6 C0,5.641 6.268,0 14,0 Z"></path><circle fill="black" cx="14" cy="14" r="7"></circle></svg><a>HOST</a>
                        <svg viewBox="-4 0 36 36" height="20" width="20" style="fill: #FF4500;"> <path d="M14,0 C21.732,0 28,5.641 28,12.6 C28,23.963 14,36 14,36 C14,36 0,24.064 0,12.6 C0,5.641 6.268,0 14,0 Z"></path><circle fill="black" cx="14" cy="14" r="7"></circle></svg><a>CLIENT</a>
                        </div>
                    </div>
                    <!-- Popup Modal Structure -->
                    <div id="settingsModal" class="modal">
                        <div class="modal-content">
                            <div style="display: flex; flex-wrap: nowrap; justify-content: space-between; align-content: center; align-items: center; flex-direction: row-reverse; max-height: 30px; margin-bottom: 20px;">
                                <span class="close-btn">&times;</span>
                                <h2>Settings</h2>
                            </div>

                            <!-- Settings Options -->
                            <form id="settingsForm">
                                <div>
                                    <label>Animation Mode:</label>
                                    <select id="animationMode">
                                        <option value="true" selected>On</option>
                                        <option value="false">Off</option>
                                    </select>
                                </div>

                                <div>
                                    <label>Animation Speed:</label>
                                    <input type="number" id="animationSpeed" min="0.1" max="5" step="0.1" value="0.5">
                                </div>

                                <div>
                                    <label>Landmark:</label>
                                    <select id="landmark">
                                        <option value="true" selected>Show</option>
                                        <option value="false">Hide</option>
                                    </select>
                                </div>

                                <div>
                                    <label>Focus Mode:</label>
                                    <select id="focusMode">
                                        <option value="true" selected>On</option>
                                        <option value="false">Off</option>
                                    </select>
                                </div>

                                <div>
                                    <label>Ground:</label>
                                    <select id="ground">
                                        <option value="true" selected>Show</option>
                                        <option value="false">Hide</option>
                                    </select>
                                </div>

                                <div>
                                    <label>Country:</label>
                                    <select id="country">
                                        <option value="true" selected>Show</option>
                                        <option value="false">Hide</option>
                                    </select>
                                </div>

                                <div>
                                    <label>Resolution:</label>
                                    <input type="number" id="resolution" min="0.1" max="2" step="0.1" value="1">
                                </div>

                                <div>
                                    <label>Refreshment (ms):</label>
                                    <input type="number" id="refreshment" min="100" max="60000" step="100" value="10000">
                                </div>

                                <div>
                                    <label>View Time (sec):</label>
                                    <input type="number" id="viewTime" min="3" max="10" step="1" value="5">
                                </div>

                                <div>
                                    <label>Power Saving:</label>
                                    <select id="powerSaving">
                                        <option value="false" selected>Off</option>
                                        <option value="true">On</option>
                                    </select>
                                </div>
                                <div style="display: flex; align-content: center; flex-wrap: nowrap; justify-content: space-evenly; align-items: center; margin-top: 10px;">
                                    <button type="button" id="applySettings">Apply</button>
                                    <button onclick="location.reload();">Reload</button>
                                    <button type="button" id="resetSettings">Reset</button>
                                    <button type="button" id="closeModal-btn">close</button>
                                </div>
                            </form>
                            <h2 id="save-rp" style="display: none;">Settings Applied!</h2>
                        </div>
                    </div>
                    <div class="tooltip" id="tooltip"></div>
                    <script>
                        // Default settings
                        const defaultSettings = {
                            animationMode: true,
                            animationSpeed: 0.5,
                            landmark: true,
                            focusMode: true,
                            ground: true,
                            country: true,
                            resolution: 1,
                            refreshment: 10000, // ms
                            viewTime: 5000, // ms
                            powerSaving: false
                        };

                        // Power-saving settings
                        const powerSaveSettings = {
                            animationMode: false,
                            animationSpeed: 0.0,
                            landmark: false,
                            focusMode: false,
                            ground: false,
                            country: false,
                            resolution: 1,
                            refreshment: 10000, // ms
                            viewTime: 10000, // ms
                            powerSaving: true
                        };

                        // Open and Close Modal Functions
                        const settingsModal = document.getElementById("settingsModal");
                        const gsetButton = document.getElementById("gset");
                        const closeBtn = document.querySelector(".close-btn");
                        const liveStatus = document.getElementById('live-status') || (() => {
                            const status = document.createElement('span');
                            status.id = 'live-status';
                            status.setAttribute('role', 'status');
                            status.setAttribute('aria-live', 'polite');
                            status.textContent = 'Connecting…';
                            document.querySelector('.header')?.appendChild(status);
                            return status;
                        })();
                        const setLiveStatus = (message, state = 'ok') => {
                            if (liveStatus) { liveStatus.textContent = message; liveStatus.dataset.state = state; }
                        };

                        // Open the modal when the "gset" button is clicked
                        gsetButton.onclick = () => settingsModal.style.display = "block";

                        // Close the modal when the close button is clicked
                        closeBtn.onclick = () => settingsModal.style.display = "none";
                        document.getElementById("closeModal-btn").onclick = () => settingsModal.style.display = "none";

                        // Close the modal only when clicking outside the modal content
                        window.onclick = (event) => {
                            if (event.target === settingsModal) {
                                settingsModal.style.display = "none";
                            }
                        };

                        // Apply Settings and Store in Cookies
                        document.getElementById("applySettings").onclick = () => {
                            const settings = getCurrentSettings();
                            document.cookie = `globeSettings=\${JSON.stringify(settings)}; path=/;`;

                            //settingsModal.style.display = "none"; // Close the modal
                            showConfirmationMessage();
                            applyGlobeSettings(settings);
                        };

                        // **NEW** Reset settings to defaults
                        document.getElementById("resetSettings").onclick = () => {
                            applyGlobeSettings(defaultSettings);
                            document.cookie = `globeSettings=\${JSON.stringify(defaultSettings)}; path=/;`;
                            showConfirmationMessage("Settings reset to default.");
                        };

                        // Load Settings from Cookies
                        function loadSettings() {
                            const cookieData = document.cookie.split('; ').find(row => row.startsWith('globeSettings='));
                            if (!cookieData) return { ...defaultSettings };
                            try {
                                const parsed = JSON.parse(cookieData.substring(cookieData.indexOf('=') + 1));
                                return { ...defaultSettings, ...(parsed && typeof parsed === 'object' ? parsed : {}) };
                            } catch (_) {
                                return { ...defaultSettings };
                            }
                        }

                        // Sync current settings from the form and return as an object
                        function getCurrentSettings() {
                            return {
                                animationMode: document.getElementById("animationMode").value === "true",
                                animationSpeed: parseFloat(document.getElementById("animationSpeed").value),
                                landmark: document.getElementById("landmark").value === "true",
                                focusMode: document.getElementById("focusMode").value === "true",
                                ground: document.getElementById("ground").value === "true",
                                country: document.getElementById("country").value === "true",
                                resolution: parseFloat(document.getElementById("resolution").value),
                                refreshment: parseInt(document.getElementById("refreshment").value), // ms
                                viewTime: parseInt(document.getElementById("viewTime").value) * 1000, // sec to ms
                                powerSaving: document.getElementById("powerSaving").value === "true"
                            };
                        }

                        // Update form inputs based on current settings
                        function applyGlobeSettings(settings) {
                            document.getElementById("animationMode").value = settings.animationMode.toString();
                            document.getElementById("animationSpeed").value = settings.animationSpeed;
                            document.getElementById("landmark").value = settings.landmark.toString();
                            document.getElementById("focusMode").value = settings.focusMode.toString();
                            document.getElementById("ground").value = settings.ground.toString();
                            document.getElementById("country").value = settings.country.toString();
                            document.getElementById("resolution").value = settings.resolution;
                            document.getElementById("refreshment").value = settings.refreshment;
                            document.getElementById("viewTime").value = settings.viewTime / 1000; // ms to sec
                            document.getElementById("powerSaving").value = settings.powerSaving.toString();

                            togglePowerSaving(settings.powerSaving);
                        }

                        // Toggle the settings' inputs based on Power Saving mode
                        function togglePowerSaving(isPowerSaving) {
                            const inputs = document.querySelectorAll('#settingsForm input, #settingsForm select');
                            inputs.forEach(input => {
                                if (input.id !== 'powerSaving') {
                                    input.disabled = isPowerSaving;
                                }
                            });
                        }

                        // Monitor Power Saving change
                        document.getElementById("powerSaving").addEventListener("change", (event) => {
                            const isPowerSaving = event.target.value === "true";
                            togglePowerSaving(isPowerSaving);
                            applyGlobeSettings(isPowerSaving ? powerSaveSettings : loadSettings());
                        });

                        // Show confirmation message after applying settings
                        function showConfirmationMessage() {
                            const settingsForm = document.getElementById("settingsForm");
                            const confirmationMessage = document.getElementById("save-rp");

                            settingsForm.style.display = "none";
                            confirmationMessage.style.display = "block";

                            // Show confirmation for 3-5 random seconds, then switch back
                            const displayDuration = Math.floor(Math.random() * (5 - 3 + 1) + 3) * 1000;
                            setTimeout(() => {
                                confirmationMessage.style.display = "none";
                                settingsForm.style.display = "block";
                                settingsModal.style.display = "none"; // Close the modal
                            }, displayDuration);
                        }

                        // Initial Load of Settings
                        document.addEventListener("DOMContentLoaded", () => {
                            const initialSettings = loadSettings();
                            applyGlobeSettings(initialSettings);
                        });



                        // Function to get color by HTTP method
                        function getColor(method) {
                            const methodColors = {
                                'GET': '#FF1493',
                                'POST': '#0125d5',
                                'PUT': '#1eb621',
                                'PATCH': '#c4c420',
                                'DELETE': '#ba0000',
                                'HEAD': '#8A2BE2',
                                'OPTIONS': '#21a9b7',
                                'CUSTOM': '#FF6347'
                            };

                            return methodColors[method.toUpperCase()] || '#FF6347';
                        }

                        // Define fixed colors for client-side and host-side markers
                        const clientColor = '#FF4500'; // Example: Client marker color (Orange)
                        const hostColor = '#4682B4'; // Example: Host marker color (Steel Blue)

                        const markerSvg = `<svg viewBox="-4 0 36 36">
                                <path fill="currentColor" d="M14,0 C21.732,0 28,5.641 28,12.6 C28,23.963 14,36 14,36 C14,36 0,24.064 0,12.6 C0,5.641 6.268,0 14,0 Z"></path>
                                <circle fill="black" cx="14" cy="14" r="7"></circle>
                            </svg>`;

                        if (typeof window.Globe !== 'function') {
                            setLiveStatus('Map library was blocked or unavailable', 'error');
                            const mapNode = document.getElementById('map');
                            if (mapNode) mapNode.innerHTML = '<div style="display:grid;place-items:center;height:100%;padding:24px;color:#fecaca;text-align:center">Live map library could not be loaded. Check the CDN/CSP configuration.</div>';
                        } else {
                        const map = Globe()
                            .globeImageUrl('https://cdn.jsdelivr.net/npm/three-globe/example/img/earth-night.jpg')
                            .bumpImageUrl('https://cdn.jsdelivr.net/npm/three-globe/example/img/earth-topology.png')
                            .backgroundImageUrl('https://cdn.jsdelivr.net/npm/three-globe/example/img/night-sky.png')
                            .arcColor('color')
                            .arcStroke(0.8)
                            .arcDashLength(0.6)
                            .arcDashGap(0.05)
                            .arcDashAnimateTime(() => Math.random() * 4000 + 200)
                            .arcStartLat('startLat')
                            .arcStartLng('startLng')
                            .arcEndLat('endLat')
                            .arcEndLng('endLng')
                            (document.getElementById('map'));




                        if (loadSettings()['ground'] === true) {
                            // Polygons
                            fetch('https://globe.gl/example/datasets/ne_110m_admin_0_countries.geojson')
                            .then(res => res.json())
                            .then(countries => {
                                map
                                    .polygonsData(countries.features)
                                    .polygonCapColor(() => '#191d1b')
                                    .polygonSideColor(() => '#010f23')
                                    .polygonStrokeColor(() => '#010f23')
                                    .polygonAltitude(0.02);
                            });
                        }

                        if (loadSettings()['country'] === true) {
                            // Label
                            fetch('https://globe.gl/example/datasets/ne_110m_populated_places_simple.geojson')
                            .then(res => res.json())
                            .then(places => {
                                map
                                    .labelsData(places.features)
                                    .labelLat(d => d.properties.latitude)
                                    .labelLng(d => d.properties.longitude)
                                    .labelText(d => d.properties.name)
                                    .labelSize(1)
                                    .labelColor(() => 'rgba(255, 165, 0, 0.75)')
                                    .labelResolution(1)
                                    .labelAltitude(0.02);
                            });
                        }

                        // Control the zoom (min and max distance)
                        const controls = map.controls();
                        controls.minDistance = 250;
                        controls.maxDistance = 450;

                        //map.renderer().setPixelRatio(window.devicePixelRatio / 1); // Reduce resolution


                        // Fetch attack data
                        let fetchInFlight = false;
                        let refreshTimer = null;
                        async function fetchAttackData() {
                            if (fetchInFlight) return;
                            fetchInFlight = true;
                            try {
                                const response = await fetch(window.location.href, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
                                    }
                                });
                                if (!response.ok) throw new Error(`HTTP \${response.status}`);
                                const data = await response.json();
                                processAttackData(Array.isArray(data) ? data : []);
                                setLiveStatus(`Live · \${Array.isArray(data) ? data.length : 0} requests`, 'ok');
                            } catch (error) {
                                setLiveStatus('Live map connection failed', 'error');
                            } finally {
                                fetchInFlight = false;
                                scheduleAttackFetch();
                            }
                        }

                        function scheduleAttackFetch() {
                            if (refreshTimer) clearTimeout(refreshTimer);
                            const delay = Math.max(1000, Math.min(60000, Number(loadSettings().refreshment) || 10000));
                            refreshTimer = setTimeout(fetchAttackData, delay);
                        }



                        // Store processed attack data for comparison
                        const processedAttacks = new Map();

                        // Calculate center point between host and client markers
                        function getMidpoint(lat1, lng1, lat2, lng2) {
                            // console.log([lat1, lng1, lat2, lng2]);

                            // Convert degrees to radians
                            const toRadians = deg => deg * Math.PI / 180;
                            const toDegrees = rad => rad * 180 / Math.PI;

                            // Latitude and longitude in radians
                            const lat1Rad = toRadians(lat1);
                            const lat2Rad = toRadians(lat2);
                            const lng1Rad = toRadians(lng1);
                            const lng2Rad = toRadians(lng2);

                            // Calculate the midpoint latitude and longitude
                            const dLng = lng2Rad - lng1Rad;

                            // Calculate Bx and By using spherical trigonometry
                            const Bx = Math.cos(lat2Rad) * Math.cos(dLng);
                            const By = Math.cos(lat2Rad) * Math.sin(dLng);

                            // Calculate the midpoint latitude and longitude in radians
                            const midLatRad = Math.atan2(
                                Math.sin(lat1Rad) + Math.sin(lat2Rad),
                                Math.sqrt((Math.cos(lat1Rad) + Bx) * (Math.cos(lat1Rad) + Bx) + By * By)
                            );

                            const midLngRad = lng1Rad + Math.atan2(By, Math.cos(lat1Rad) + Bx);

                            // Convert back to degrees
                            const midLat = toDegrees(midLatRad);
                            let midLng = toDegrees(midLngRad);

                            // Normalize the longitude to be within -180° to 180°
                            if (midLng > 180) {
                                midLng -= 360;
                            } else if (midLng < -180) {
                                midLng += 360;
                            }

                            // console.log({ lat: midLat, lng: midLng });

                            // Return the calculated midpoint
                            return {
                                lat: midLat,
                                lng: midLng
                            };
                        }



                        // Process and display attack data
                        function processAttackData(data) {
                            const newAttacks = data.filter(attack => !processedAttacks.has(`\${attack.clientLat}-\${attack.clientLon}-\${attack.requestFLOAT}`));
                            
                            if (newAttacks.length > 0) {
                                newAttacks.forEach(attack => processedAttacks.set(`\${attack.clientLat}-\${attack.clientLon}-\${attack.requestFLOAT}`, attack)); // Add new attacks

                                // Separate client and host markers for NEW attacks only
                                const markersData = newAttacks.flatMap(item => [{
                                        lat: item.clientLat,
                                        lng: item.clientLon,
                                        color: clientColor,
                                        role: 'client',
                                        type: item.requestMETHOD
                                    },
                                    {
                                        lat: item.hostLat,
                                        lng: item.hostLon,
                                        color: hostColor,
                                        role: 'host',
                                        type: item.requestMETHOD
                                    }
                                ]);

                                const arcsData = newAttacks.map(item => ({
                                    startLat: item.clientLat,
                                    startLng: item.clientLon,
                                    endLat: item.hostLat, // server as the endpoint
                                    endLng: item.hostLon,
                                    color: getColor(item.requestMETHOD)
                                }));




                                // Add new attack markers and arcs to the map
                                map.htmlElementsData(map.htmlElementsData().concat(markersData)).htmlElement(marker => {
                                    const el = document.createElement('div');
                                    el.innerHTML = markerSvg;
                                    el.style.color = marker.color;
                                    el.style.width = '16px'; // Adjust marker size
                                    el.className = 'marker';
                                    el.title = `\${marker.role.toUpperCase()}`;
                                    el.style['pointer-events'] = 'auto';
                                    el.style.cursor = 'pointer';
                                    el.onclick = () => {
                                        // document.getElementById('attack-info').textContent = `Clicked \${marker.role} marker at Lat: \${marker.lat}, Lng: \${marker.lng}`;
                                    };

                                    // Tooltip functionality
                                    el.onmouseover = () => {
                                        showTooltip(marker.lat, marker.lng, el);
                                    };
                                    el.onmouseout = () => {
                                        hideTooltip();
                                    };
                                    return el;
                                });


                                map.arcsData(map.arcsData().concat(arcsData)); // Add new arcs

                                addToAttackList(newAttacks); // Add only new attacks to the list


                                // Calculate center point between the first client attack and server (host)
                                const {
                                    lat: centerLat,
                                    lng: centerLng
                                } = getMidpoint(
                                    newAttacks[0].clientLat,
                                    newAttacks[0].clientLon,
                                    newAttacks[0].hostLat,
                                    newAttacks[0].hostLon
                                );

                                // Immediately center and auto-rotate the map on the new attack point
                                map.pointOfView({
                                    lat: centerLat,
                                    lng: centerLng
                                }, 1000);

                                pauseAnimation(); // Disable rotation for cleanup

                                const detailsContent = document.getElementById("details-content");
                                const makeUnselectButton = document.getElementById("makeUnselect");
                                const contentLength = detailsContent.textContent.length;

                                if ((contentLength < 50) || (contentLength >= 50 && !makeUnselectButton.hasAttribute("data"))) {
                                    let details = newAttacks[0];
                                    let formattedHTML = "";

                                    for (const [key, value] of Object.entries(details)) {
                                        formattedHTML += `<strong>\${escapeHtml(key)}</strong>: \${escapeHtml(value)}<br>`;
                                    }
                                    formattedHTML += '<button id="makeUnselect" data="auto" onclick="makeUnselect()">Clear Details</button>';

                                    detailsContent.innerHTML = formattedHTML;
                                }

                                // console.log(loadSettings());
                                

                                // Remove client markers and arcs after 5 seconds
                                setTimeout(() => {
                                    pauseAnimation(); // Disable rotation for cleanup

                                    const markersToRemove = newAttacks.map(attack => `\${attack.clientLat}-\${attack.clientLon}`);

                                    // Remove markers for host and client
                                    const updatedMarkers = map.htmlElementsData().filter(marker =>
                                        marker.role === 'host' || !markersToRemove.includes(`\${marker.lat}-\${marker.lng}`)
                                    );
                                    map.htmlElementsData(updatedMarkers);

                                    // Remove arcs associated with the new attacks
                                    const updatedArcs = map.arcsData().filter(arc =>
                                        !newAttacks.some(attack =>
                                            arc.startLat === attack.clientLat && arc.startLng === attack.clientLon
                                        )
                                    );

                                    map.arcsData(updatedArcs);

                                    // Enable auto-rotation again after cleanup
                                    resumeAnimation(); // Enable auto-rotation

                                    if ((contentLength < 50) || (contentLength >= 50 && makeUnselectButton.hasAttribute("data"))) {
                                        document.getElementById("details-content").innerHTML = "Select a request to view details.";
                                    }

                                    map.pointOfView({ lat: 0, lng: 0}, 1000);
                                }, loadSettings()['viewTime']);
                            } else {
                                // If no new attacks, just update the attack list
                                addToAttackList([]);
                                resumeAnimation(); // Enable auto-rotation
                            }
                        }

                            function escapeHtml(value) {
                                const node = document.createElement('div');
                                node.textContent = value == null ? '' : String(value);
                                return node.innerHTML;
                            }

                            function truncateString(str, maxLength) {
                                if (str && str.length > maxLength) {
                                    return str.substring(0, maxLength) + "...";
                                }
                                return str;
                            }


                        function addToAttackList(data) {
                            const attackList = document.getElementById('attack-list');
                            const maxItems = 100; // Define the maximum number of items allowed in the list

                            // Create a Map to keep track of unique entries by their unique keys
                            const existingEntries = new Map();

                            // Loop through the current items in the attack list to populate the Map
                            attackList.querySelectorAll('li.attack-card').forEach(item => {
                                const requestTime = item.querySelector('.attack-time').textContent.trim();
                                const requestMethod = item.querySelector('.attack-type').textContent.trim(); // Mapped from requestMETHOD
                                const privateIp = item.querySelector('.attack-body .attack-ip').textContent.trim();
                                const publicIp = item.querySelector('.attack-body .attack-ip:nth-child(3)').textContent.trim(); // Adjust if not correct
                                const netkey = item.dataset.netkey; // Assuming this is stored as a data attribute
                                const devicekey = item.dataset.devicekey; // Assuming this is stored as a data attribute

                                const entryKey = `\${requestTime}-\${requestMethod}-\${privateIp}-\${publicIp}-\${netkey}-\${devicekey}`;
                                existingEntries.set(entryKey, item);
                            });

                            // Add or update entries based on the new data received
                            data.forEach(item => {
                                const requestTime = new Date(item.requestTime * 1000).toLocaleTimeString();
                                const requestFLOAT = item.requestFLOAT ? parseFloat(item.requestFLOAT).toFixed(2) : '';
                                
                                const entryKey = `\${requestTime}-\${requestFLOAT}-\${item.requestMETHOD}-\${item.hostIP}-\${item.clientIP}-\${item.netkey}-\${item.devicekey}`;

                                if (existingEntries.has(entryKey)) {
                                    const existingItem = existingEntries.get(entryKey);
                                    attackList.prepend(existingItem); // Move the existing item to the top
                                } else {
                                    let connection = '»';

                                    function isValidIP(ip) {
                                        const ipRegex = /^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
                                        return ipRegex.test(ip);
                                    }

                                    function ipInRange(ip1, ip2) {
                                        // Check if either ip1 or ip2 is null or not a string
                                        if (typeof ip1 !== 'string' || !ip1 || typeof ip2 !== 'string' || !ip2) {
                                            return false; // Or handle as needed, e.g., return false or throw an error
                                        }
                                        // Extract the first three octets to check for matching ranges (Class C network)
                                        const range1 = ip1.split('.').slice(0, 3).join('.');
                                        const range2 = ip2.split('.').slice(0, 3).join('.');
                                        return range1 === range2;
                                    }

                                    // Check conditions and update connection value
                                    if (ipInRange(item.clientIP, item.hostIP) && item.clientIP === item.hostIP) {
                                        connection = '« local »';
                                    } else if (!ipInRange(item.clientIP, item.hostIP) && item.clientIP != item.hostIP && item.clientVpn === false) {
                                        connection = '« remote »';
                                    } else if (ipInRange(item.clientIP, item.hostIP) && item.clientIP === item.hostIP && item.clientVpn === true && item.hostVpn === true) {
                                        connection = '« local|vpn »';
                                    } else if (item.clientIP !== item.hostIP && item.clientVpn === true) {
                                        connection = '« vpn »';
                                    } else {
                                        connection = '»';
                                    }
                                    
                                    const li = document.createElement('li');
                                    li.className = 'attack-card';
                                    li.dataset.netkey = item.netkey;
                                    li.dataset.devicekey = item.devicekey;
                                    li.dataset.requesttime = item.requestTime; // Store requestTIME as a data attribute
                                    li.dataset.requestFLOAT = item.requestFLOAT; // Store requestFLOAT as a data attribute
                                    li.dataset.data = encodeObjectToBase64(item); // Store requestFLOAT as a data attribute
                                    li.innerHTML = `
                                        <div class="attack-header">
                                            <div class="attack-country" tooltipData="\${item.clientCountry}">
                                                \${item.clientCountryCode && typeof item.clientCountryCode === 'string' ? `<img src="https://flagcdn.com/w320/\${item.clientCountryCode.toLowerCase()}.png" alt="\${item.clientCountry}">\${item.clientCountryCode}` : (item.clientCountryCode ? item.clientCountryCode : '')}
                                            </div>
                                            <div class="attack-time">\${requestTime}</div>
                                            <div class="attack-type" style="background-color:\${getColor(item.requestMETHOD)};">\${item.requestMETHOD}</div>
                                        </div>
                                        <div class="attack-body">
                                            <div class="attack-ip">\${(isValidIP(item.clientIP) && item.clientIP.length <= 16) ? item.clientIP : item.clientIP}</div>
                                            <div class="attack-time">\${connection}</div>
                                            <div class="attack-ip">\${item.hostIP}</div>
                                        </div>
                                        <div class="attack-body">
                                            <div class="attack-time">\${item.requestMETHOD} »</div>
                                            <div class="attack-time">\${item.requestURL}</div>
                                        </div>
                                        <div class="attack-footer">
                                            <div class="attack-device">\${item.clientPlatform}</div>
                                            <div class="attack-time">« \${truncateString(item.devicekey, 10)} »</div>
                                            <div class="attack-network">\${item.clientBrowser}</div>
                                        </div>
                                    `;

                                    attackList.prepend(li);
                                    existingEntries.set(entryKey, li);
                                }
                            });

                            // Manage list length by removing excess items
                            while (attackList.children.length > maxItems) {
                                attackList.removeChild(attackList.lastChild);
                            }

                            // Optional: Realign or sort the list based on the time and requestFLOAT if necessary
                            realignAttackList(attackList);
                        }


                        // Function to realign the attack list based on requestTIME, requestFLOAT, and datetime
                        function realignAttackList(attackList) {
                            const itemsArray = Array.from(attackList.children);

                            // Sort items by requestTIME and requestFLOAT (if available)
                            itemsArray.sort((a, b) => {
                                const timeA = parseInt(a.dataset.requesttime || a.querySelector('.attack-time').textContent.trim(), 10);
                                const timeB = parseInt(b.dataset.requesttime || b.querySelector('.attack-time').textContent.trim(), 10);
                                
                                const floatA = parseFloat(a.dataset.requestfloat || 0);
                                const floatB = parseFloat(b.dataset.requestfloat || 0);

                                // Sort by requestTIME (descending), then by requestFLOAT (descending)
                                return (timeB - timeA) || (floatB - floatA);
                            });

                            // Re-append items in the sorted order
                            itemsArray.forEach(item => attackList.appendChild(item));
                        }


                        // Function to add click event listener to attack-list items
                        function setAttackDetailsOnClick(listId, detailsId) {
                            const attackList = document.getElementById(listId);
                            const detailsContent = document.getElementById(detailsId);

                            // Delegate click event to the attack-list
                            attackList.addEventListener('click', (event) => {
                                const target = event.target.closest('li.attack-card'); // Select the closest attack-card li
                                if (target) {
                                    const attackCountry = target.querySelector('.attack-country').textContent.trim();
                                    const attackTime = target.querySelector('.attack-time').textContent.trim();
                                    const attackType = target.querySelector('.attack-type').textContent.trim();
                                    const attackIpFrom = target.querySelector('.attack-body .attack-ip').textContent.trim();
                                    const attackIpTo = target.querySelector('.attack-body .attack-ip:nth-child(3)').textContent.trim();
                                    const attackDevice = target.querySelector('.attack-device').textContent.trim();
                                    const attackNetwork = target.querySelector('.attack-network').textContent.trim();

                                    const decoded = decodeBase64ToObject(target.getAttribute('data-data'));

                                    // Construct the details content
                                    const detailsContent = document.getElementById("details-content");
                                    let formattedHTML = "";
                                    for (const [key, value] of Object.entries(decoded)) {
                                        formattedHTML += `<strong>\${escapeHtml(key)}</strong>: \${escapeHtml(value)}<br>`;
                                    }
                                    formattedHTML += '<button id="makeUnselect" onclick="makeUnselect()">Clear Details</button>';
                                    detailsContent.innerHTML = formattedHTML;
                                
                                }
                            });
                        }



                        // Clear any content inside #details-content and set default text
                        function makeUnselect() {
                            document.getElementById("details-content").innerHTML = "Select a request to view details.";
                        }

                        // Tooltip functions
                        function showTooltip(lat, lng, el) {
                            const tooltip = document.getElementById('tooltip');
                            tooltip.textContent = `Lat: \${lat}, Lng: \${lng}`;
                            tooltip.style.display = 'block';
                            tooltip.style.top = `\${el.getBoundingClientRect().top}px`;
                            tooltip.style.left = `\${el.getBoundingClientRect().left + el.clientWidth}px`;
                        }

                        function hideTooltip() {
                            const tooltip = document.getElementById('tooltip');
                            tooltip.style.display = 'none';
                        }

                        // Define pause and resume animation functions
                        function pauseAnimation() {
                            map.controls().autoRotate = false; // Disable auto-rotate if enabled
                            map.controls().autoRotateSpeed = 0; // Set your desired rotation speed
                        }

                        function resumeAnimation() {
                            map.controls().autoRotate = loadSettings()['animationMode']; // Enable auto-rotate when the page is visible again
                            map.controls().autoRotateSpeed = loadSettings()['animationSpeed']; // Set your desired rotation speed
                        }

                        // Function to initialize tooltips for elements with tooltipData attribute
                        function initializeTooltips() {
                            // Select all elements with tooltipData attribute
                            const tooltipElements = document.querySelectorAll('[tooltipData]');

                            tooltipElements.forEach(el => {
                                el.addEventListener('mouseover', () => {
                                    const tooltipData = el.getAttribute('tooltipData'); // Get the tooltip data
                                    showTooltip(tooltipData, el); // Show the tooltip with the data
                                });

                                el.addEventListener('mouseout', hideTooltip); // Hide the tooltip on mouse out
                            });
                        }



                        // Function to handle visibility change and window events
                        let eventListenersSet = false;
                        function handleAutoRotation() {
                            if (eventListenersSet) return; // Skip if listeners are already set

                            // Set event listeners for various events
                            document.addEventListener("visibilitychange", () => {
                                if (document.hidden) {
                                    pauseAnimation();
                                } else {
                                    resumeAnimation();
                                }
                            });

                            window.addEventListener("beforeunload", () => {
                                pauseAnimation();
                            });

                            window.addEventListener("unload", () => {
                                pauseAnimation();
                            });

                            window.addEventListener("blur", () => {
                                pauseAnimation();
                            });

                            window.addEventListener("focus", () => {
                                resumeAnimation();
                            });
                            eventListenersSet = true;
                        }


                        // Initialize the fetch process and set the attack details click event
                        fetchAttackData();
                        handleAutoRotation();
                        setAttackDetailsOnClick('attack-list', 'details-content');
                        // Call this function after your DOM content is loaded
                        document.addEventListener('DOMContentLoaded', initializeTooltips);
                        }
                    </script>
                </body>
                </html>
                EOT;
                $livemapHtml = ob_get_clean();
                $csrfToken = class_exists('PHRO') && is_callable(['PHRO', 'getToken'])
                    ? json_encode(PHRO::getToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                    : 'null';
                $csrfMeta = '<meta name="csrf-token" content="' . htmlspecialchars((string) json_decode($csrfToken), ENT_QUOTES, 'UTF-8') . '">';
                $livemapHtml = str_replace('<head>', '<head>' . $csrfMeta, $livemapHtml);
                print $livemapHtml;
        });

        PHRO::post($url, function() {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');

            $data = PHLS::get('livemap');

            echo json_encode(is_array($data) ? array_values($data) : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        });
    }

    /**
     * Stream data to the client.
     *
     * @param int $sleepSec The number of seconds to sleep between data updates (min: 1, max: 300).
     * @param string $type The content type for the response (default: "text").
     * @param callable $callback The callback function to execute for generating data.
     */
    public static function stream(int $sleep = 1, string $type = "text", ?callable $callback = null) {
        if ($sleep < 1 || $sleep > 300) {
            throw new InvalidArgumentException("Sleep duration must be between 1 and 300 seconds.");
        }

        header('Content-Type: ' . $type . '/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        ob_implicit_flush(true);
        ob_end_flush();

        while (true) {
            if (connection_aborted()) {
                exit("Client disconnected, terminating stream.\n");
            }

            $data = call_user_func($callback);

            echo "data: " . json_encode($data) . "\n\n";

            ob_flush();
            flush();

            sleep($sleep);
        }
    }
}
?>
