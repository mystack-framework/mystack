<?php

/**
 * ============================================================================
 * File: library.php (MyStack Core Bootstrapper & Dynamic Importer)
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * This is the heart of the MyStack Framework. It initializes core classes, 
 * manages the directory registry via `DIR`, and provides the `import()` helper 
 * to dynamically load PHP components, scripts, CSS, and views with zero hassle.
 *
 * Features:
 * - Global `import()` function replacing `require`/`include`.
 * - Cross-environment Directory Path detection.
 * - Automatic loading of controllers, UI assets, and media files.
 * 
 * Usage Example:
 * ```php
 * // Inside any file to include a dependency
 * import('app:Controllers/UserController');
 * import('css:styles.css');
 * ```
 */



class DIR
{
    /** @var string|null $rootDir Absolute path to the project root directory. */
    private static $rootDir;

    /** @var string|null $baseUrl Base URL of the project. */
    private static $baseUrl;

    /** @var bool $initialized Whether the DIR class has been initialized. */
    private static $initialized = false;

    /** @var array $directoryMap Map of resource keys to their absolute filesystem paths. */
    private static $directoryMap = [];

    /** @var array $linkMap Map of resource keys to their base web URLs. */
    private static $linkMap = [];

    /** @var array $extensionMap Default file extensions for different resource types. */
    private static $extensionMap = [
        'js' => 'js',
        'css' => 'css',
        'cs' => 'css',
        'scss' => 'scss',
        'sass' => 'sass',
        'img' => 'png',
        'icon' => 'svg',
        'font' => 'woff2',
        'doc' => 'pdf',
        'json' => 'json',
        'xml' => 'xml',
        'component' => 'php',
        'app' => 'php',
        'library' => 'php'
    ];

    /**
     * Initializes the DIR class with root directory and base URL.
     *
     * @param array $options Optional configuration: 'rootDir', 'baseUrl', 'configPath'.
     * @return void
     */
    public static function initialize(array $options = [])
    {
        if (self::$initialized)
            return;

        // এখন initialize() শুধুমাত্র ম্যানুয়াল ওভাররাইডের জন্য, কিন্তু এটি কল করা বাধ্যতামূলক নয়
        self::$rootDir = $options['rootDir'] ?? null;
        self::$baseUrl = $options['baseUrl'] ?? null;

        self::resolvePaths(); // নতুন কেন্দ্রীয় ফাংশন কল

        $configPath = $options['configPath'] ?? self::$rootDir . 'config' . DIRECTORY_SEPARATOR . 'directories.php';
        self::loadDirectoryConfig($configPath);
        self::$initialized = true;
    }

    /**
     * Gets the absolute filesystem path for a given resource using colon notation.
     * e.g., 'css', 'component:forms:input', 'js:main.min.js'
     *
     * @param string $key The resource key or colon-separated path.
     * @return string The absolute path.
     */
    public static function path($key)
    {
        self::initialize();

        $parts = explode(':', $key);
        $baseKey = array_shift($parts); // First part is always the base key

        $baseDir = self::$directoryMap[$baseKey] ?? self::$rootDir;

        // If no subpath is provided, return the base directory path.
        if (empty($parts)) {
            return $baseDir;
        }

        // The rest of the parts form the subpath.
        $subPath = implode(DIRECTORY_SEPARATOR, $parts);

        $fullPath = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $subPath;

        // Intelligent Extension: Append only if the final segment has no extension.
        if (!pathinfo($subPath, PATHINFO_EXTENSION)) {
            $defaultExtension = self::$extensionMap[$baseKey] ?? 'php';
            $fullPath .= '.' . $defaultExtension;
        }

        return $fullPath;
    }

    /**
     * Gets the full web URL for a given resource using colon notation.
     * e.g., 'css', 'js:main.min', 'img:logo.svg'
     *
     * @param string $key The resource key or colon-separated path.
     * @param bool|string $cacheBust Add cache-busting query string (true for timestamp, or custom string).
     * @return string The full URL.
     */
    public static function link($key, $cacheBust = false)
    {
        self::initialize();

        $parts = explode(':', $key);
        $baseKey = array_shift($parts);

        $baseUrl = self::$linkMap[$baseKey] ?? self::$baseUrl;

        $subPath = implode('/', $parts);
        $fullUrl = rtrim($baseUrl, '/');

        if ($subPath) {
            $fullUrl .= '/' . $subPath;
        }

        // Intelligent Extension for URL: Append only if the final segment has no extension.
        if ($subPath && !pathinfo($subPath, PATHINFO_EXTENSION)) {
            if (isset(self::$extensionMap[$baseKey])) {
                $fullUrl .= '.' . self::$extensionMap[$baseKey];
            }
        }

        // Cache-Busting
        if ($cacheBust) {
            // Re-build the key for the path() method to find the physical file
            $pathKey = $key;
            if ($subPath && !pathinfo($subPath, PATHINFO_EXTENSION)) {
                $defaultExtension = self::$extensionMap[$baseKey] ?? 'php';
                $pathKey .= '.' . $defaultExtension;
            }
            $physicalPath = self::path($pathKey);

            if (file_exists($physicalPath)) {
                $version = ($cacheBust === true) ? filemtime($physicalPath) : $cacheBust;
                $fullUrl .= '?v=' . $version;
            }
        }

        return $fullUrl;
    }

    /**
     * Gets the raw content of a given resource using colon notation.
     *
     * @param string $key The resource key or colon-separated path.
     * @return string|false The file contents or false if not found.
     */
    public static function raw($key)
    {
        $path = self::path($key);
        return file_exists($path) ? file_get_contents($path) : false;
    }

    /**
     * Safely requires a PHP file and passes data to it.
     *
     * @param string $key The resource key for the PHP file.
     * @param array $data Associative array of data to extract into the file's scope.
     * @return mixed The return value of the required file.
     * @throws \Exception If the file is not found.
     */
    public static function secureRequire($key, array $data = [])
    {
        $filePath = self::path($key);
        if (!file_exists($filePath)) {
            throw new \Exception("Required file not found at path: {$filePath}");
        }
        extract($data, EXTR_SKIP);
        return require $filePath;
    }

    /**
     * Returns the detected or set project root directory.
     *
     * @return string The root directory path.
     */
    public static function getRootDir()
    {
        self::initialize();
        return self::$rootDir;
    }

    /**
     * Returns the detected or set project base URL.
     *
     * @return string The base URL.
     */
    public static function getBaseUrl()
    {
        self::initialize();
        return self::$baseUrl;
    }

    /**
     * Resolves the root directory and base URL if not already set.
     *
     * @return void
     */
    private static function resolvePaths()
    {
        if (self::$rootDir === null) {
            self::$rootDir = self::autoDetectRootDir();
        }
        if (self::$baseUrl === null) {
            self::$baseUrl = self::autoDetectBaseUrl();
        }
        // চূড়ান্ত পরিচ্ছন্নতা
        self::$rootDir = rtrim(self::$rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        self::$baseUrl = rtrim(self::$baseUrl, '/');
    }

    /**
     * Automatically detects the project root directory.
     *
     * @return string The detected root directory path.
     */
    private static function autoDetectRootDir(): string
    {
        // Strategy 1: Composer's vendor directory (most reliable for Composer projects)
        if (($vendorDir = self::findVendorDirectory(__DIR__))) {
            return dirname($vendorDir) . DIRECTORY_SEPARATOR;
        }

        // Strategy 2: Look for common project markers (index.php, app/, config/)
        $path = __DIR__;
        while (strlen($path) > 1 && @is_readable($path)) {
            if (file_exists($path . '/index.php') || is_dir($path . '/app') || is_dir($path . '/config') || file_exists($path . '/.env')) {
                return $path . DIRECTORY_SEPARATOR;
            }
            $parent = dirname($path);
            if ($parent === $path)
                break; // Reached the filesystem root
            $path = $parent;
        }

        // Fallback: The directory of the entry script (e.g., index.php)
        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            return dirname($_SERVER['SCRIPT_FILENAME']) . DIRECTORY_SEPARATOR;
        }

        return dirname(__DIR__) . DIRECTORY_SEPARATOR; // Last resort
    }

    /**
     * Automatically detects the project base URL.
     *
     * @return string The detected base URL.
     */
    private static function autoDetectBaseUrl(): string
    {
        // --- Strategy 1: Check if running in a Command Line Interface (CLI) environment ---
        if (php_sapi_name() === 'cli' || defined('STDIN')) {
            // In CLI, there's no "base URL". Returning a placeholder or project root is the best we can do.
            // We'll return the root directory path, which is useful for CLI scripts generating file paths.
            return self::autoDetectRootDir();
        }

        // --- Strategy 2: Leverage PHRO's powerful root detection if the class exists ---
        // This creates a powerful synergy between the two libraries.
        if (class_exists('PHRO') && method_exists('PHRO', 'root')) {
            // We trust PHRO's detection as it's designed for complex routing scenarios.
            // We call initialize() to ensure PHRO's base path is calculated.
            if (method_exists('PHRO', 'initialize')) {
                PHRO::initialize();
            }
            return PHRO::root();
        }

        // --- Strategy 3: Reliable detection using server variables (the previous "magic part") ---
        $protocol = 'http://';
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443 || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $protocol = "https://";
        }

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '';

        if (!empty($host) && !empty($script_name)) {
            $script_dir = dirname($script_name);

            // If the script directory is the root, return just the protocol and host.
            if ($script_dir === '/' || $script_dir === '\\' || $script_dir === '.') {
                return rtrim($protocol . $host, '/');
            }

            // Otherwise, append the script's directory.
            return rtrim($protocol . $host . $script_dir, '/');
        }

        // --- Strategy 4: Last resort fallback ---
        // If SCRIPT_NAME is not reliable, use PHP_SELF as a guess.
        if (isset($_SERVER['PHP_SELF'])) {
            return rtrim($protocol . $host . dirname($_SERVER['PHP_SELF']), '/');
        }

        return $protocol . $host;
    }

    /**
     * Searches for the Composer vendor directory.
     *
     * @param string $startDir The directory to start searching from.
     * @return string|false The path to the vendor directory or false if not found.
     */
    private static function findVendorDirectory($startDir)
    {
        $dir = realpath($startDir);

        $allowedRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? ''); 

        while ($dir && (!empty($allowedRoot) ? strpos($dir, $allowedRoot) === 0 : true)) {
            $vendorPath = $dir . '/vendor';

            if (is_dir($vendorPath)) {
                return $vendorPath;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return false;
    }

    /**
     * Loads directory configuration from a file.
     *
     * @param string $path Path to the directory configuration file.
     * @return void
     */
    private static function loadDirectoryConfig($path)
    {
        $defaultDirs = [
            'app' => 'app',
            'component' => 'component',
            'library' => 'library',
            'src' => 'src',
            'audio' => 'src/audio',
            'css' => 'src/css',
            'cs' => 'src/css',
            'doc' => 'src/doc',
            'element' => 'src/element',
            'files' => 'src/files',
            'file' => 'src/files',
            'font' => 'src/font',
            'img' => 'src/img',
            'js' => 'src/js',
            'scss' => 'src/scss',
            'upload' => 'src/upload',
            'up' => 'src/upload',
            'video' => 'src/video',
            'cache' => 'src/cache'
        ];
        $configDirs = [];
        if (file_exists($path)) {
            $configDirs = require $path;
        }
        $mergedDirs = array_merge($defaultDirs, $configDirs);
        foreach ($mergedDirs as $key => $dir) {
            self::$directoryMap[$key] = rtrim(self::$rootDir . str_replace('/', DIRECTORY_SEPARATOR, $dir), DIRECTORY_SEPARATOR);
            self::$linkMap[$key] = rtrim(self::$baseUrl . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $dir), '/');
        }
    }
}

/**
 * Recursively requires all PHP files in a directory.
 *
 * @param string $directory Path to the directory.
 * @return void
 */
function requireDirectory($directory)
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            require_once $file->getPathname();
        }
    }
}



/**
 * Class Importer
 * The Intelligent Import Helper for loading files, assets, and components.
 */

class Importer
{
    /** @var Importer|null $instance Singleton instance. */
    private static ?self $instance = null;

    /** @var array $context_vars Variables from the caller context. */
    private array $context_vars = [];

    /** @var array $imported_files List of already imported PHP files. */
    private array $imported_files = [];

    /**
     * Singleton: private constructor
     */
    private function __construct()
    {
    }

    /**
     * Gets the single instance of the Importer.
     *
     * @return Importer
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Sets the variables from the caller's context (e.g., from the router).
     *
     * @param array $vars Context variables.
     * @return void
     */
    public function setContext(array $vars): void
    {
        $this->context_vars = $vars;
    }

    /**
     * Clears the context to prevent data leaks.
     *
     * @return void
     */
    public function clearContext(): void
    {
        $this->context_vars = [];
    }

    /**
     * The main method to load files, replacing the old import() function.
     * Supports various syntax and actions like require, link, path, etc.
     *
     * @param mixed ...$args File keys, options, and data.
     * @return mixed Boolean success, path string, HTML tag string, or array of results.
     */
    public function load(...$args)
    {
        $manual_data = [];
        if (count($args) > 1 && is_array(end($args))) {
            $last_arg = end($args);
            if (isset($last_arg['data']) || isset($last_arg['params']) || count(array_filter(array_keys($last_arg), 'is_string')) > 0) {
                $manual_data = array_pop($args);
            }
        }

        $final_data_to_pass = array_merge($this->context_vars, $manual_data);

        static $imported_php_files = [];

        $dirAliases = [
            'app' => ['app', 'application', 'core', 'main', 'system'],
            'component' => ['component', 'comp', 'cmp', 'widgets', 'modules'],
            'library' => ['library', 'lib', 'libs', 'class', 'classes', 'helper', 'helpers'],
            'src' => ['src', 'source', 'assets', 'public', 'resources'],
            'css' => ['cs', 'css', 'style', 'styles', 'stylesheet', 'stylesheets'],
            'js' => ['js', 'script', 'scripts', 'javascript', 'jscript'],
            'img' => ['img', 'image', 'images', 'pic', 'pics', 'picture', 'pictures', 'icon', 'icons'],
            'font' => ['font', 'fonts', 'typeface', 'typography', 'webfonts'],
            'video' => ['video', 'vid', 'videos', 'movie', 'movies', 'clip', 'clips'],
            'audio' => ['audio', 'sound', 'sounds', 'music', 'track', 'tracks'],
            'doc' => ['doc', 'docs', 'document', 'documents', 'manual', 'manuals'],
            'file' => ['file', 'files', 'download', 'downloads', 'upload', 'uploads'],
            'element' => ['element', 'elements', 'elem', 'part', 'parts'],
            'scss' => ['scss', 'sass', 'style-src'],
            'cache' => ['cache', 'temp', 'tmp']
        ];
        $actAliases = [
            'require' => ['require', 'req', 'include', 'inc', 'load'],
            'url' => ['url', 'href', 'src', 'link-raw', 'raw'],
            'link' => ['link', 'css', 'style', 'stylesheet'],
            'script' => ['script', 'js', 'javascript'],
            'defer' => ['defer', 'def', 'script-defer', 'js-defer', 'js-def'],
            'async' => ['async', 'asc', 'script-async', 'js-async', 'js-asc'],
            'module' => ['module', 'esm', 'js-module'],
            'preload' => ['preload', 'prefetch', 'early'],
            'icon' => ['icon', 'favicon', 'shortcut-icon'],
            'path' => ['path', 'dir', 'file', 'location']
        ];

        $files = [];
        foreach ($args as $arg) {
            if (is_array($arg)) {
                $files = array_merge($files, $arg);
            } else {
                $files[] = $arg;
            }
        }
        $results = [];

        foreach ($files as $item) {
            if (!is_string($item))
                continue;
            $parts = explode(':', $item);
            $baseKey = 'app';
            $subPath = '';
            $action = 'auto';

            if (count($parts) === 1) {
                $ext = pathinfo($item, PATHINFO_EXTENSION);
                $subPath = $item;
                if ($ext) {
                    if (in_array($ext, ['css', 'scss', 'sass'])) {
                        $baseKey = 'css';
                        $action = 'link';
                    } elseif (in_array($ext, ['js', 'mjs'])) {
                        $baseKey = 'js';
                        $action = 'script';
                    } elseif (in_array($ext, ['jpg', 'png', 'svg', 'gif', 'webp', 'ico'])) {
                        $baseKey = 'img';
                        $action = 'url';
                    } // ico added
                    elseif (in_array($ext, ['woff', 'woff2', 'ttf', 'otf'])) {
                        $baseKey = 'font';
                        $action = 'url';
                    } // fonts
                    elseif ($ext === 'php') {
                        $baseKey = 'component';
                        $action = 'require';
                    } else {
                        $baseKey = 'file';
                        $action = 'url';
                    }
                } else {
                    $baseKey = 'component';
                    $action = 'require';
                }
            } elseif (count($parts) >= 2) {
                $rawKey = $parts[0];
                foreach ($dirAliases as $realKey => $aliases) {
                    if ($rawKey === $realKey || in_array($rawKey, $aliases)) {
                        $baseKey = $realKey;
                        break;
                    }
                }
                $subPath = $parts[1];
                if (isset($parts[2])) {
                    $rawAct = $parts[2];
                    foreach ($actAliases as $realAct => $aliases) {
                        if ($rawAct === $realAct || in_array($rawAct, $aliases)) {
                            $action = $realAct;
                            break;
                        }
                    }
                } else {
                    $ext = pathinfo($subPath, PATHINFO_EXTENSION);
                    if (!$ext) {
                        if ($baseKey === 'css')
                            $action = 'link';
                        elseif ($baseKey === 'js')
                            $action = 'script';
                        elseif (in_array($baseKey, ['img', 'font', 'video', 'audio']))
                            $action = 'url';
                        else
                            $action = 'require';
                    } else {
                        if ($ext === 'php')
                            $action = 'require';
                        elseif ($ext === 'css')
                            $action = 'link';
                        elseif ($ext === 'js' || $ext === 'mjs')
                            $action = 'script';
                        else
                            $action = 'url';
                    }
                }
            }

            if (strpos($subPath, '*') !== false) {
                try {
                    $pattern = DIR::path($baseKey . ':' . $subPath);

                    $globFiles = glob($pattern);

                    if ($globFiles) {
                        $dirParam = dirname($subPath);
                        $actionSuffix = isset($parts[2]) ? ':' . $parts[2] : '';

                        foreach ($globFiles as $filepath) {
                            $filename = basename($filepath);

                            $newSubPath = ($dirParam === '.') ? $filename : $dirParam . '/' . $filename;

                            $generated = import($baseKey . ':' . $newSubPath . $actionSuffix);

                            if (is_array($generated)) {
                                $results = array_merge($results, $generated);
                            } else {
                                $results[] = $generated;
                            }
                        }
                    }
                } catch (Exception $e) {
                    $results[] = false;
                }
                continue;
            }

            try {
                $fullKey = $baseKey . ':' . $subPath;

                if ($action === 'require') {
                    $path = DIR::path($fullKey);
                    $real_path = realpath($path);

                    if ($real_path && !isset($imported_php_files[$real_path])) {
                        $imported_php_files[$real_path] = true;
                        (function ($__file_path, $__data) {
                            extract($__data, EXTR_SKIP);
                            require $__file_path;
                        })($real_path, $final_data_to_pass);

                        $results[] = true;
                    } else {
                        if (!pathinfo($path, PATHINFO_EXTENSION)) {
                            $path .= '.php';
                            if (file_exists($path)) {
                                $imported_php_files[$real_path] = true;
                                (function ($__file_path, $__data) {
                                    extract($__data, EXTR_SKIP);
                                    require $__file_path;
                                })($real_path, $final_data_to_pass);

                                $results[] = true;
                            } else {
                                $results[] = false;
                            }
                        } else {
                            $results[] = false;
                        }
                    }
                } elseif ($action === 'path') {
                    $results[] = DIR::path($fullKey);
                } else {
                    $link = DIR::link($fullKey, true);

                    if ($action === 'url') {
                        $results[] = $link;
                    } elseif ($action === 'link') {
                        $results[] = '<link rel="stylesheet" href="' . $link . '">';
                    } elseif ($action === 'script') {
                        $results[] = '<script src="' . $link . '"></script>';
                    } elseif ($action === 'defer') {
                        $results[] = '<script src="' . $link . '" defer></script>';
                    } elseif ($action === 'async') {
                        $results[] = '<script src="' . $link . '" async></script>';
                    } elseif ($action === 'module') {
                        $results[] = '<script type="module" src="' . $link . '"></script>';
                    } elseif ($action === 'preload') {
                        $as = 'image';
                        $ext = pathinfo($subPath, PATHINFO_EXTENSION);
                        if (in_array($ext, ['css']))
                            $as = 'style';
                        elseif (in_array($ext, ['js', 'mjs']))
                            $as = 'script';
                        elseif (in_array($ext, ['woff', 'woff2', 'ttf']))
                            $as = 'font';
                        elseif (in_array($ext, ['jpg', 'png', 'webp', 'svg']))
                            $as = 'image';
                        elseif (in_array($ext, ['mp4', 'webm']))
                            $as = 'video';

                        $crossorigin = ($as === 'font') ? ' crossorigin' : '';
                        $results[] = '<link rel="preload" href="' . $link . '" as="' . $as . '"' . $crossorigin . '>';
                    } elseif ($action === 'icon') {
                        $results[] = '<link rel="icon" href="' . $link . '">';
                    }
                }
            } catch (Exception $e) {
                $results[] = false;
            }
        }
        if (count($results) === 1) {
            return $results[0];
        }
        return $results;
    }
}


/**
 * Global helper function to access the Importer instance.
 * The usage remains exactly the same: import('component:file').
 *
 * @param mixed ...$args File keys, options, and data.
 * @return mixed Success status, path, HTML tag, or list of results.
 */
function import(...$args)
{
    return Importer::getInstance()->load(...$args);
}

require_once(DIR::path('library:PHDE'));
require_once(DIR::path('library:PHRO'));
require_once(DIR::path('library:PHOB'));
require_once(DIR::path('library:PHEV'));
require_once(DIR::path('library:PHEM'));
require_once(DIR::path('library:PHML'));
require_once(DIR::path('library:PHCS'));
require_once(DIR::path('library:PHJS'));
require_once(DIR::path('library:PHJC'));
require_once(DIR::path('library:PHCO'));
require_once(DIR::path('library:PHSE'));
require_once(DIR::path('library:PHLS'));
require_once(DIR::path('library:PHDB'));
require_once(DIR::path('library:PHRQ'));
require_once(DIR::path('library:PHQR'));
require_once(DIR::path('library:PHED'));
require_once(DIR::path('library:PHTP'));
require_once(DIR::path('library:PHTM'));
require_once(DIR::path('library:PHVD'));
require_once(DIR::path('library:PHCD'));
require_once(DIR::path('library:PHJT'));
require_once(DIR::path('library:PHTR'));
require_once(DIR::path('library:PHAU'));
require_once(DIR::path('library:PHOP'));
require_once(DIR::path('library:PHAI'));
require_once(DIR::path('library:PHAP'));
require_once(DIR::path('library:PHUI'));
require_once(DIR::path('library:PHPA'));
?>