<?php

/**
 * ============================================================================
 * Class: PHMO
 * Title: Monitoring & Observability
 * ============================================================================
 * 
 * Enterprise-grade observability tools. Tracks request/trace IDs, generates structured JSON logs, exposes health/ready probes, gathers metrics, and powers the debug dashboard.
 * 
 * Features:
 * - Request and trace ID injection.
 * - Structured JSON logging for external ingestion.
 * - Health (`/health`) and Readiness (`/ready`) probes.
 * - Latency, error metrics, and interactive dashboard.
 * 
 * Usage Example:
 * ```php
 * PHMO::configure(['enabled' => true]);
 * PHMO::registerRoutes(); // Exposes /health & /ready
 * ```
 * 
 * @package    MyStack
 * @author     Sakibur Rahman (@sakibweb)
 * @published  https://github.com/mystack-framework
 * @web        https://mystack-framework.github.io
 * @license    Apache License 2.0
 * ============================================================================
 */
final class PHMO
{
    private const RETENTION_DAYS = 90;
    private static array $config = [];
    private static bool $configured = false;
    private static bool $routesRegistered = false;
    private static bool $dashboardRegistered = false;
    private static bool $shutdownRegistered = false;
    private static float $startedAt = 0.0;
    private static string $requestId = '';
    private static string $traceId = '';

    public static function configure(array $options = []): array
    {
        $defaults = [
            'enabled' => false,
            'health_route' => '/health',
            'ready_route' => '/ready',
            'request_logging' => true,
            'retention_days' => self::RETENTION_DAYS,
            'max_file_bytes' => 10 * 1024 * 1024,
            'log_directory' => dirname(__DIR__) . DIRECTORY_SEPARATOR . '.mystack',
        ];

        self::$config = array_replace($defaults, $options);
        self::$config['enabled'] = (bool) self::$config['enabled'];
        self::$config['request_logging'] = (bool) self::$config['request_logging'];
        // PHMO always keeps the latest 90 UTC calendar days.
        self::$config['retention_days'] = self::RETENTION_DAYS;
        self::$config['max_file_bytes'] = max(1024 * 1024, min(100 * 1024 * 1024, (int) self::$config['max_file_bytes']));
        self::$config['health_route'] = self::route((string) self::$config['health_route'], '/health');
        self::$config['ready_route'] = self::route((string) self::$config['ready_route'], '/ready');
        self::$configured = true;

        if (self::$config['enabled']) {
            self::initializeRequest();
        }

        return self::$config;
    }

    public static function config(): array
    {
        return self::$configured ? self::$config : self::configure();
    }

    public static function requestId(): string
    {
        if (self::$requestId === '') {
            self::initializeRequest();
        }
        return self::$requestId;
    }

    public static function traceId(): string
    {
        if (self::$traceId === '') {
            self::initializeRequest();
        }
        return self::$traceId;
    }

    public static function isProbeRequest(): bool
    {
        $cfg = self::config();
        if (!$cfg['enabled']) {
            return false;
        }
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $basePath = class_exists('PHRO', false) && method_exists('PHRO', 'root')
            ? (string) (parse_url((string) PHRO::root(), PHP_URL_PATH) ?: '')
            : '';
        if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, rtrim($basePath, '/') . '/')) {
            $path = substr($path, strlen(rtrim($basePath, '/'))) ?: '/';
        }
        $path = '/' . trim($path, '/');
        return in_array($path, [$cfg['health_route'], $cfg['ready_route']], true);
    }

    public static function registerRoutes(): void
    {
        $cfg = self::config();
        if (!$cfg['enabled'] || self::$routesRegistered || !class_exists('PHRO')) {
            return;
        }

        PHRO::get($cfg['health_route'], static function () {
            self::respond(false);
        })->name('phmo.health')
            ->header('Cache-Control', 'no-store, private')
            ->disallow();

        PHRO::get($cfg['ready_route'], static function () {
            self::respond(true);
        })->name('phmo.ready')
            ->header('Cache-Control', 'no-store, private')
            ->disallow();

        self::$routesRegistered = true;
    }

    /**
     * Registers a self-contained, read-only observability dashboard.
     * The route does not exist while PHDE debug mode is disabled.
     */
    public static function dashboard(string $url = '/monitor'): void
    {
        if (self::$dashboardRegistered || !self::debugMode() || !class_exists('PHRO')) {
            return;
        }

        $url = self::route($url, '/monitor');
        PHRO::get($url, static function (): void {
            if (!self::debugMode()) {
                http_response_code(404);
                return;
            }
            self::renderDashboard();
        })->name('phmo.dashboard')
            ->header('Cache-Control', 'no-store, private, max-age=0, must-revalidate')
            ->disallow();

        self::$dashboardRegistered = true;
    }

    /**
     * Builds a bounded log report suitable for dashboards and local tooling.
     */
    public static function report(
        ?string $date = null,
        int $limit = 500,
        string $level = '',
        string $search = ''
    ): array {
        $date = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : gmdate('Y-m-d');
        $limit = max(25, min(2000, $limit));
        $level = strtolower(trim($level));
        $allowedLevels = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];
        if (!in_array($level, $allowedLevels, true)) {
            $level = '';
        }
        $search = trim($search);
        $records = [];
        $levels = array_fill_keys($allowedLevels, 0);
        $events = [];
        $issues = [];
        $total = 0;
        $requestFailures = 0;

        foreach (self::logFiles($date) as $file) {
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $record = json_decode($line, true);
                if (!is_array($record)) {
                    continue;
                }
                $total++;
                $recordLevel = strtolower((string) ($record['level'] ?? 'info'));
                $recordEvent = (string) ($record['event'] ?? 'application.event');
                $context = is_array($record['context'] ?? null) ? $record['context'] : [];
                $levels[$recordLevel] = ($levels[$recordLevel] ?? 0) + 1;
                $events[$recordEvent] = ($events[$recordEvent] ?? 0) + 1;
                if ((int) ($context['status'] ?? 0) >= 400) {
                    $requestFailures++;
                }

                if (in_array($recordLevel, ['warning', 'error', 'critical'], true)) {
                    $error = is_array($context['error'] ?? null) ? $context['error'] : [];
                    $message = (string) ($context['message'] ?? $error['message'] ?? $recordEvent);
                    $errorFile = (string) ($context['file'] ?? $error['file'] ?? '');
                    $errorLine = (int) ($context['line'] ?? $error['line'] ?? 0);
                    $issueKey = hash('sha256', $recordLevel . '|' . $recordEvent . '|' . $message . '|' . $errorFile . '|' . $errorLine);
                    if (!isset($issues[$issueKey])) {
                        $issues[$issueKey] = [
                            'level' => $recordLevel,
                            'event' => $recordEvent,
                            'message' => $message,
                            'file' => $errorFile,
                            'line' => $errorLine,
                            'count' => 0,
                            'last_seen' => (string) ($record['timestamp'] ?? ''),
                        ];
                    }
                    $issues[$issueKey]['count']++;
                    $issues[$issueKey]['last_seen'] = (string) ($record['timestamp'] ?? '');
                }

                $matchesLevel = $level === '' || $recordLevel === $level;
                $matchesSearch = $search === ''
                    || stripos((string) json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $search) !== false;
                if ($matchesLevel && $matchesSearch) {
                    $records[] = $record;
                    if (count($records) > $limit) {
                        array_shift($records);
                    }
                }
            }
            fclose($handle);
        }

        arsort($events);
        usort($issues, static fn(array $left, array $right): int =>
            ($right['count'] <=> $left['count']) ?: strcmp($right['last_seen'], $left['last_seen'])
        );

        return [
            'date' => $date,
            'total' => $total,
            'problems' => array_sum(array_intersect_key($levels, array_flip(['warning', 'error', 'critical']))),
            'request_failures' => $requestFailures,
            'levels' => $levels,
            'events' => array_slice($events, 0, 12, true),
            'issues' => array_slice($issues, 0, 100),
            'records' => array_reverse($records),
            'filters' => ['level' => $level, 'search' => $search, 'limit' => $limit],
        ];
    }

    public static function health(bool $withDependencies = false): array
    {
        $checks = [];
        $ready = true;

        if ($withDependencies) {
            $phls = class_exists('PHLS') ? PHLS::checker(false) : ['status' => false];
            $database = class_exists('PHDB') && method_exists('PHDB', 'checker')
                ? PHDB::checker()
                : ['status' => false];

            $checks = [
                'phls' => self::publicCheck($phls),
                'database' => self::publicCheck($database),
            ];
            $ready = (bool) ($phls['status'] ?? false) && (bool) ($database['status'] ?? false);
        }

        return [
            'status' => $ready ? 'ok' : 'not_ready',
            'ready' => $ready,
            'time' => gmdate('c'),
            'request_id' => self::requestId(),
            'trace_id' => self::traceId(),
            'checks' => $checks,
        ];
    }

    /**
     * Returns today's request metrics by reading PHMO's own structured logs.
     * It performs no writes and is deliberately not exposed as a public route.
     */
    public static function metrics(?string $date = null): array
    {
        $date = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : gmdate('Y-m-d');
        $files = self::logFiles($date);
        $requests = 0;
        $errors = 0;
        $latencies = [];

        foreach ($files as $file) {
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $record = json_decode($line, true);
                if (!is_array($record) || ($record['event'] ?? '') !== 'request.completed') {
                    continue;
                }
                $requests++;
                $status = (int) ($record['context']['status'] ?? 0);
                if ($status >= 500) {
                    $errors++;
                }
                $latencies[] = (float) ($record['context']['latency_ms'] ?? 0);
            }
            fclose($handle);
        }

        sort($latencies, SORT_NUMERIC);
        $average = $latencies ? array_sum($latencies) / count($latencies) : 0.0;
        $p95Index = $latencies ? (int) ceil(count($latencies) * 0.95) - 1 : 0;

        return [
            'date' => $date,
            'requests' => $requests,
            'errors' => $errors,
            'error_rate' => $requests > 0 ? round(($errors / $requests) * 100, 4) : 0.0,
            'latency_ms' => [
                'average' => round($average, 2),
                'p95' => round($latencies[$p95Index] ?? 0, 2),
                'max' => round($latencies ? max($latencies) : 0, 2),
            ],
        ];
    }

    public static function log(string $level, string $event, array $context = []): bool
    {
        $cfg = self::config();
        if (!$cfg['enabled']) {
            return false;
        }

        $level = strtolower(trim($level));
        if (!in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true)) {
            $level = 'info';
        }

        $record = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'event' => preg_replace('/[^a-z0-9._-]+/i', '.', trim($event)) ?: 'application.event',
            'request_id' => self::requestId(),
            'trace_id' => self::traceId(),
            'context' => self::sanitize($context),
        ];
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return false;
        }

        try {
            $file = self::logFile();
            $lock = @fopen(dirname($file) . DIRECTORY_SEPARATOR . '.phmo.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                return false;
            }
            try {
                self::rotate($file);
                $written = @file_put_contents($file, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
                if ($written !== false) {
                    @chmod($file, 0640);
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            self::prune();
            return $written !== false;
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    private static function initializeRequest(): void
    {
        if (self::$startedAt <= 0) {
            self::$startedAt = isset($_SERVER['REQUEST_TIME_FLOAT'])
                ? (float) $_SERVER['REQUEST_TIME_FLOAT']
                : microtime(true);
        }

        if (self::$requestId === '') {
            $incoming = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
            self::$requestId = self::validId($incoming, 8, 128) ? $incoming : bin2hex(random_bytes(16));
        }

        if (self::$traceId === '') {
            $traceParent = trim((string) ($_SERVER['HTTP_TRACEPARENT'] ?? ''));
            if (preg_match('/^[\da-f]{2}-([\da-f]{32})-[\da-f]{16}-[\da-f]{2}$/i', $traceParent, $match)
                && $match[1] !== str_repeat('0', 32)) {
                self::$traceId = strtolower($match[1]);
            } else {
                self::$traceId = bin2hex(random_bytes(16));
            }
        }

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('X-Request-ID: ' . self::$requestId);
            header('X-Trace-ID: ' . self::$traceId);
        }

        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'finishRequest']);
            self::$shutdownRegistered = true;
        }
    }

    public static function finishRequest(): void
    {
        $cfg = self::config();
        if (!$cfg['enabled'] || !$cfg['request_logging'] || PHP_SAPI === 'cli') {
            return;
        }

        $status = http_response_code();
        if (!is_int($status) || $status < 100) {
            $status = 200;
        }
        $lastError = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (is_array($lastError) && in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true)) {
            $status = max(500, $status);
        }

        self::log($status >= 500 ? 'error' : 'info', 'request.completed', [
            'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            'path' => parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/',
            'status' => $status,
            'latency_ms' => round((microtime(true) - self::$startedAt) * 1000, 2),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'fatal' => $status >= 500 && is_array($lastError),
            'error' => $status >= 500 && is_array($lastError) ? [
                'type' => (int) ($lastError['type'] ?? 0),
                'message' => (string) ($lastError['message'] ?? 'Fatal PHP error'),
                'file' => (string) ($lastError['file'] ?? ''),
                'line' => (int) ($lastError['line'] ?? 0),
            ] : null,
        ]);
    }

    private static function renderDashboard(): void
    {
        $date = trim((string) ($_GET['date'] ?? gmdate('Y-m-d')));
        $level = trim((string) ($_GET['level'] ?? ''));
        $search = trim((string) ($_GET['search'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 500);
        $report = self::report($date, $limit, $level, $search);
        $metrics = self::metrics($report['date']);
        $escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $levelOptions = '<option value="">All levels</option>';
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $option) {
            $selected = $report['filters']['level'] === $option ? ' selected' : '';
            $levelOptions .= '<option value="' . $option . '"' . $selected . '>' . ucfirst($option) . '</option>';
        }
        $limitOptions = '';
        foreach ([100, 250, 500, 1000, 2000] as $option) {
            $selected = $report['filters']['limit'] === $option ? ' selected' : '';
            $limitOptions .= '<option value="' . $option . '"' . $selected . '>' . $option . '</option>';
        }
        if (!in_array($report['filters']['limit'], [100, 250, 500, 1000, 2000], true)) {
            $limitOptions = '<option value="' . (int) $report['filters']['limit'] . '" selected>'
                . (int) $report['filters']['limit'] . '</option>' . $limitOptions;
        }

        $issueRows = '';
        $issueCards = '';
        foreach ($report['issues'] as $issue) {
            $location = $issue['file'] !== ''
                ? $escape($issue['file']) . ($issue['line'] > 0 ? ':' . $issue['line'] : '')
                : 'No source location recorded';
            $issueRows .= '<tr><td><span class="badge ' . $escape($issue['level']) . '">'
                . $escape($issue['level']) . '</span></td><td><strong>' . $escape($issue['message'])
                . '</strong><small>' . $escape($issue['event']) . '</small></td><td><code>' . $location
                . '</code></td><td class="number">' . (int) $issue['count'] . '</td><td>'
                . $escape($issue['last_seen']) . '</td></tr>';
            $issueCards .= '<article class="issue-card"><div class="issue-card-head"><span class="badge '
                . $escape($issue['level']) . '">' . $escape($issue['level']) . '</span><strong class="issue-count">×'
                . (int) $issue['count'] . '</strong></div><h3>' . $escape($issue['message'])
                . '</h3><small>' . $escape($issue['event']) . '</small><dl><div><dt>File and line</dt><dd><code>'
                . $location . '</code></dd></div><div><dt>Last seen</dt><dd>'
                . $escape($issue['last_seen']) . '</dd></div></dl></article>';
        }
        if ($issueRows === '') {
            $issueRows = '<tr><td colspan="5" class="empty">No warning, error, or critical log found.</td></tr>';
            $issueCards = '<div class="empty">No warning, error, or critical log found.</div>';
        }

        $recordRows = '';
        foreach ($report['records'] as $record) {
            $context = is_array($record['context'] ?? null) ? $record['context'] : [];
            $error = is_array($context['error'] ?? null) ? $context['error'] : [];
            $file = (string) ($context['file'] ?? $error['file'] ?? '');
            $line = (int) ($context['line'] ?? $error['line'] ?? 0);
            $location = $file !== '' ? $file . ($line > 0 ? ':' . $line : '') : '—';
            $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $recordRows .= '<tr><td>' . $escape($record['timestamp'] ?? '') . '</td><td><span class="badge '
                . $escape($record['level'] ?? 'info') . '">' . $escape($record['level'] ?? 'info')
                . '</span></td><td><strong>' . $escape($record['event'] ?? '') . '</strong><small>request '
                . $escape($record['request_id'] ?? '') . ' · trace ' . $escape($record['trace_id'] ?? '')
                . '</small></td><td><code>' . $escape($location) . '</code></td><td><details><summary>Details</summary><pre>'
                . $escape($json === false ? '{}' : $json) . '</pre></details></td></tr>';
        }
        if ($recordRows === '') {
            $recordRows = '<tr><td colspan="5" class="empty">No log matches the selected filters.</td></tr>';
        }

        $eventRows = '';
        foreach ($report['events'] as $event => $count) {
            $eventRows .= '<li><code>' . $escape($event) . '</code><strong>' . (int) $count . '</strong></li>';
        }
        if ($eventRows === '') {
            $eventRows = '<li class="empty">No event recorded.</li>';
        }

        if (!headers_sent()) {
            header_remove('Cache-Control');
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, private, max-age=0, must-revalidate', true);
            header('Pragma: no-cache');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self'; base-uri 'none'; frame-ancestors 'self'");
        }

        $dateValue = $escape($report['date']);
        $searchValue = $escape($report['filters']['search']);
        $generated = $escape(gmdate('Y-m-d H:i:s') . ' UTC');
        $total = (int) $report['total'];
        $problems = (int) $report['problems'];
        $failures = (int) $report['request_failures'];
        $requests = (int) ($metrics['requests'] ?? 0);
        $errorRate = $escape($metrics['error_rate'] ?? 0);
        $average = $escape($metrics['latency_ms']['average'] ?? 0);
        $p95 = $escape($metrics['latency_ms']['p95'] ?? 0);

        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark">
<title>PHMO Monitor</title>
<style>
:root{color-scheme:dark;--bg:#070b14;--panel:#111827;--line:#263247;--text:#e5edf8;--muted:#8da0b8;--blue:#60a5fa;--green:#34d399;--yellow:#fbbf24;--red:#fb7185}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#13213a 0,#070b14 42%);color:var(--text);font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.wrap{width:min(1500px,calc(100% - 32px));margin:auto;padding:28px 0 60px}header{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:20px}h1{margin:0;font-size:30px;letter-spacing:-.03em}h1 span{color:var(--blue)}.muted,small{display:block;color:var(--muted)}.cards{display:grid;grid-template-columns:repeat(6,minmax(140px,1fr));gap:12px}.card,.panel{background:rgba(17,24,39,.88);border:1px solid var(--line);box-shadow:0 18px 45px rgba(0,0,0,.22);border-radius:15px}.card{padding:16px}.card strong{display:block;font-size:25px;margin-top:5px}.card.problem strong{color:var(--red)}.card.good strong{color:var(--green)}form{display:grid;grid-template-columns:160px 160px minmax(220px,1fr) 110px auto;gap:10px;margin:18px 0}.input,button{border:1px solid var(--line);border-radius:10px;background:#0b1220;color:var(--text);padding:11px 12px}button{cursor:pointer;background:#2563eb;border-color:#3b82f6;font-weight:700}.grid{display:grid;grid-template-columns:minmax(0,3fr) minmax(250px,1fr);gap:14px}.panel{overflow:hidden;margin-top:14px}.panel h2{font-size:16px;margin:0;padding:15px 17px;border-bottom:1px solid var(--line)}.scroll{overflow:auto;max-height:560px}table{width:100%;border-collapse:collapse;min-width:900px}th,td{text-align:left;padding:11px 13px;border-bottom:1px solid rgba(38,50,71,.72);vertical-align:top}th{position:sticky;top:0;background:#172033;color:#aebdd0;font-size:12px;text-transform:uppercase;z-index:1}.badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#243047;font-size:11px;text-transform:uppercase;font-weight:800}.badge.warning{color:#111827;background:var(--yellow)}.badge.error,.badge.critical{color:#fff;background:#be123c}.badge.info{color:#07111f;background:var(--blue)}.badge.notice{color:#07111f;background:#a78bfa}.badge.debug{color:#07111f;background:#94a3b8}.number{text-align:center;font-size:18px;font-weight:800}code{overflow-wrap:anywhere;color:#bfd7f5}details{min-width:105px}summary{cursor:pointer;color:var(--blue)}pre{white-space:pre-wrap;word-break:break-word;max-width:700px;color:#cbd5e1;background:#050914;border:1px solid var(--line);padding:12px;border-radius:8px}.events{list-style:none;padding:5px 15px 14px;margin:0}.events li{display:flex;justify-content:space-between;gap:12px;padding:10px 2px;border-bottom:1px solid var(--line)}.empty{text-align:center;color:var(--muted);padding:28px}@media(max-width:1000px){.cards{grid-template-columns:repeat(3,1fr)}.grid{grid-template-columns:1fr}form{grid-template-columns:1fr 1fr}.search{grid-column:1/-1}}@media(max-width:600px){.wrap{width:min(100% - 18px,1500px);padding-top:18px}header{align-items:start;flex-direction:column}.cards{grid-template-columns:1fr 1fr}form{grid-template-columns:1fr}.search{grid-column:auto}}
.panel,.grid{min-width:0}.scroll{max-width:100%}.problem-desktop{display:none}.problem-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:14px;max-height:640px;overflow:auto}.issue-card{min-width:0;padding:14px;background:#0b1220;border:1px solid var(--line);border-radius:12px}.issue-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.issue-count{color:var(--red);font-size:16px}.issue-card h3{margin:10px 0 3px;font-size:15px;line-height:1.4;overflow-wrap:anywhere}.issue-card dl{display:grid;gap:9px;margin:13px 0 0}.issue-card dl div{min-width:0}.issue-card dt{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em}.issue-card dd{min-width:0;margin:2px 0 0;overflow-wrap:anywhere}@media(max-width:1000px){.grid{grid-template-columns:minmax(0,1fr)}}@media(max-width:760px){.problem-cards{grid-template-columns:minmax(0,1fr);gap:10px;padding:12px}}@media(max-width:600px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}form{grid-template-columns:minmax(0,1fr)}}</style>
</head>
<body><main class="wrap">
<header><div><h1><span>PHMO</span> Monitor</h1><p class="muted">Debug-only structured logs, failures and request performance.</p></div><span class="muted">Generated {$generated}</span></header>
<section class="cards">
<article class="card"><span class="muted">Log records</span><strong>{$total}</strong></article>
<article class="card problem"><span class="muted">Problems</span><strong>{$problems}</strong></article>
<article class="card"><span class="muted">HTTP ≥ 400</span><strong>{$failures}</strong></article>
<article class="card good"><span class="muted">Requests</span><strong>{$requests}</strong></article>
<article class="card"><span class="muted">Error rate</span><strong>{$errorRate}%</strong></article>
<article class="card"><span class="muted">Latency avg / p95</span><strong>{$average} / {$p95}</strong><small>milliseconds</small></article>
</section>
<form method="get"><input class="input" type="date" name="date" value="{$dateValue}"><select class="input" name="level">{$levelOptions}</select><input class="input search" name="search" value="{$searchValue}" placeholder="Search message, event, file, request ID…"><select class="input" name="limit">{$limitOptions}</select><button type="submit">Apply filters</button></form>
<section class="grid"><article class="panel"><h2>Problem groups</h2><div class="scroll problem-desktop"><table><thead><tr><th>Level</th><th>Problem</th><th>File and line</th><th>Count</th><th>Last seen</th></tr></thead><tbody>{$issueRows}</tbody></table></div><div class="problem-cards">{$issueCards}</div></article><aside class="panel"><h2>Top events</h2><ul class="events">{$eventRows}</ul></aside></section>
<section class="panel"><h2>Recent structured records</h2><div class="scroll"><table><thead><tr><th>Time</th><th>Level</th><th>Event / IDs</th><th>File and line</th><th>Context</th></tr></thead><tbody>{$recordRows}</tbody></table></div></section>
</main></body></html>
HTML;
    }

    private static function debugMode(): bool
    {
        return class_exists('PHDE') && method_exists('PHDE', 'isDebug') && PHDE::isDebug();
    }

    private static function respond(bool $withDependencies): void
    {
        $payload = self::health($withDependencies);
        http_response_code($payload['ready'] ? 200 : 503);
        header_remove('Cache-Control');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private, max-age=0, must-revalidate', true);
        header('Pragma: no-cache');
        header('Expires: 0');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function publicCheck(array $check): array
    {
        $result = ['status' => (bool) ($check['status'] ?? false)];
        if (isset($check['driver'])) {
            $result['driver'] = (string) $check['driver'];
        }
        if (isset($check['latency_ms'])) {
            $result['latency_ms'] = (float) $check['latency_ms'];
        }
        if (isset($check['integrity'])) {
            $result['integrity'] = (string) $check['integrity'];
        }
        return $result;
    }

    private static function logFile(): string
    {
        $directory = (string) self::$config['log_directory'];
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('PHMO log directory could not be created.');
        }
        self::protectLogDirectory($directory);
        return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.phmo-' . gmdate('Y-m-d') . '.log';
    }

    /**
     * Adds an Apache-level deny rule beside the logs. Existing protection is
     * preserved; PHMO only creates the guard when it is missing.
     */
    private static function protectLogDirectory(string $directory): void
    {
        $guard = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
        if (is_file($guard)) {
            return;
        }

        $rules = "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order allow,deny\n"
            . "    Deny from all\n"
            . "</IfModule>\n"
            . "Options -Indexes\n";
        $handle = @fopen($guard, 'x');
        if ($handle === false) {
            return;
        }
        try {
            @flock($handle, LOCK_EX);
            @fwrite($handle, $rules);
            @fflush($handle);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
        @chmod($guard, 0640);
    }

    private static function logFiles(string $date): array
    {
        $directory = rtrim((string) self::config()['log_directory'], '/\\');
        if (!is_dir($directory)) {
            return [];
        }
        $files = glob($directory . DIRECTORY_SEPARATOR . '.phmo-' . $date . '*.log');
        return is_array($files) ? $files : [];
    }

    private static function rotate(string $file): void
    {
        clearstatcache(true, $file);
        if (!is_file($file) || (int) (@filesize($file) ?: 0) < (int) self::$config['max_file_bytes']) {
            return;
        }

        $directory = dirname($file);
        $base = basename($file, '.log');
        for ($index = 1; $index <= 999; $index++) {
            $target = $directory . DIRECTORY_SEPARATOR . $base . '.' . $index . '.log';
            if (!file_exists($target)) {
                if (!@rename($file, $target)) {
                    error_log('[PHMO] Unable to rotate log file: ' . basename($file));
                }
                return;
            }
        }
        error_log('[PHMO] Log rotation segment limit reached for: ' . basename($file));
    }

    private static function prune(): void
    {
        $directory = rtrim((string) self::$config['log_directory'], '/\\');
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false) {
            return;
        }

        $marker = $resolvedDirectory . DIRECTORY_SEPARATOR . '.phmo-prune';
        $markerExisted = is_file($marker);
        $markerHandle = @fopen($marker, 'c+');
        if ($markerHandle === false || !@flock($markerHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($markerHandle)) {
                @fclose($markerHandle);
            }
            return;
        }

        try {
            clearstatcache(true, $marker);
            $utcToday = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
            if ($markerExisted && (int) (@filemtime($marker) ?: 0) >= $utcToday->getTimestamp()) {
                return;
            }

            $cutoffDate = $utcToday->modify('-' . (self::RETENTION_DAYS - 1) . ' days')->format('Y-m-d');
            $cleanupSucceeded = true;
            $files = glob($resolvedDirectory . DIRECTORY_SEPARATOR . '.phmo-*.log');
            foreach (is_array($files) ? $files : [] as $file) {
                $resolvedFile = realpath($file);
                if ($resolvedFile === false
                    || dirname($resolvedFile) !== $resolvedDirectory
                    || !preg_match('/^\.phmo-(\d{4}-\d{2}-\d{2})(?:\.\d+)?\.log$/', basename($resolvedFile), $match)) {
                    continue;
                }
                if ($match[1] < $cutoffDate && !@unlink($resolvedFile)) {
                    $cleanupSucceeded = false;
                }
            }

            if ($cleanupSucceeded) {
                @ftruncate($markerHandle, 0);
                @rewind($markerHandle);
                @fwrite($markerHandle, gmdate('c'));
                @fflush($markerHandle);
                @touch($marker);
                @chmod($marker, 0600);
            }
        } finally {
            @flock($markerHandle, LOCK_UN);
            @fclose($markerHandle);
        }
    }

    private static function sanitize($value, ?string $key = null, int $depth = 0)
    {
        if ($depth > 5) {
            return '[depth-limit]';
        }
        if ($key !== null && preg_match('/pass(word)?|secret|token|authorization|cookie|api.?key|private.?key/i', $key)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $itemKey => $itemValue) {
                $clean[$itemKey] = self::sanitize($itemValue, is_string($itemKey) ? $itemKey : null, $depth + 1);
            }
            return $clean;
        }
        if (is_object($value)) {
            return self::sanitize(get_object_vars($value), $key, $depth + 1);
        }
        if (is_string($value)) {
            return function_exists('mb_substr') ? mb_substr($value, 0, 4096) : substr($value, 0, 4096);
        }
        return is_scalar($value) || $value === null ? $value : gettype($value);
    }

    private static function validId(string $value, int $minimum, int $maximum): bool
    {
        $length = strlen($value);
        return $length >= $minimum
            && $length <= $maximum
            && preg_match('/^[A-Za-z0-9._:-]+$/', $value) === 1;
    }

    private static function route(string $route, string $fallback): string
    {
        $route = '/' . trim($route, '/');
        return preg_match('~^/[a-z0-9/_-]+$~i', $route) ? $route : $fallback;
    }
}
