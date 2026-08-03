<?php

/**
 * ============================================================================
 * Class: PHJC
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * This class is a core component of the MyStack framework.
 * It provides essential functionalities tailored for high performance and security.
 */


class PHJC {
    private static $head = [
        'name' => '',  // e.g., 'Your Site Name'
        'title' => '',  // e.g., 'Your Page Title - Primary Keyword | Secondary Keywords'
        'description' => '',  // e.g., 'A concise and compelling description of the page's content, under 160 characters. Include primary and secondary keywords naturally.'
        'short' => 'auto',  // e.g., 'A concise and compelling short description of the page's content, under 60 characters / otherwise use 'auto'.'
        'type' => 'article',  // 'article' is a fixed value
        'category' => '',  // e.g., 'Category of the article'
        'author' => '',  // e.g., 'Your Name or Company Name'
        'keywords' => '',  // e.g., 'Relevant, Keywords, Separated, By, Commas, Long-Tail, Keywords'
        'url' => '',  // e.g., 'https://www.yoursite.com/page-url'
        'lang' => 'en',  // e.g., 'en'
        'locale' => '',  // e.g., 'en_US'
        'locale_other' => [],  // e.g., ['en_US' => 'https://www.yoursite.com/page-url']
        'published' => '',  // e.g., 'YYYY-MM-DDTHH:mm:ssZZ'
        'modified' => '',  // e.g., 'YYYY-MM-DDTHH:mm:ssZZ'
        'image' => '',  // e.g., 'https://www.yoursite.com/path/to/high-quality-image.jpg'
        'image_alt' => '',  // e.g., 'Descriptive alt text for your image'
        'favicon' => '',  // e.g., '/favicon.ico'
        'icon_192' => '',  // e.g., '/path/to/192x192.png'
        'icon_180' => '',  // e.g., '/apple-touch-icon/180x180.png'
        'icon_32' => '',  // e.g., '/favicon-32x32.png'
        'icon_16' => '',  // e.g., '/favicon-16x16.png'
        'theme_color' => '',  // e.g., '#317EFB'
        'manifest' => '',  // e.g., '/manifest.json'
        'formatable' => 'no',  // e.g., 'formatting of phone numbers as clickable links on mobile devices. use: yes/no'
        'fb_app_id' => '',  // e.g., 'YOUR_FB_APP_ID'
        'twitter_creator' => '',  // e.g., '@YourPersonalHandle'
        'telegram_username' => '',  // e.g., 'YourTelegramUsername'
        'pinterest_app' => '',  // e.g., 'YOUR_PINTEREST_APP_ID'
        'google_site_verification' => '',  // e.g., 'Your Google Verification Code'
        'msvalidate' => '',  // e.g., 'Your Bing Verification Code'
        'csp' => "default-src 'self'; img-src 'self' data:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';",  // Content-Security-Policy (CSP)
        'streamui' => true,
        'streampath' => '',
    ];
    private static $header = '';
    private static $headPart = '';
    private static $body = '';
    private static $bodyPram = [];
    private static $bodyPart = '';
    private static $css = '';
    private static $js = '';
    private static $sharedData = [];
    private static $customDirectives = [];
    private static $minify = false;
    private static $resolvedPaths = [];
    private static $slots = [];

    // ==========================================
    // FAST UI & DX HELPERS
    // ==========================================
    
    public static function fastUI(): void {
        self::css("
            :root { --p-primary: #3b82f6; --p-bg: #f9fafb; --p-text: #1f2937; --p-border: #e5e7eb; }
            .p-btn { padding: 0.5rem 1rem; border-radius: 0.375rem; border: none; cursor: pointer; transition: 0.2s; background: var(--p-primary); color: white; font-weight: 500; text-decoration: none; display: inline-block; }
            .p-btn:hover { opacity: 0.9; transform: translateY(-1px); }
            .p-card { background: white; padding: 1.5rem; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--p-border); margin-bottom: 1rem; }
            .p-row { display: flex; flex-wrap: wrap; margin: -0.5rem; }
            .p-col { flex: 1; padding: 0.5rem; min-width: 250px; }
            .p-alert { padding: 1rem; border-radius: 0.375rem; margin-bottom: 1rem; font-weight: 500; display: flex; align-items: center; gap: 0.5rem; }
            .p-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #10b981; }
            .p-alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }
            .p-input { width: 100%; padding: 0.5rem; border: 1px solid var(--p-border); border-radius: 0.375rem; margin-top: 0.25rem; box-sizing: border-box; }
        ");
    }

    public static function ui(string $type, array $attr = [], string $content = ''): string {
        $attrString = '';
        foreach ($attr as $k => $v) {
            $attrString .= " $k=\"".htmlspecialchars((string)$v)."\"";
        }
        
        return match($type) {
            'card' => "<div class='p-card'$attrString>$content</div>",
            'btn'  => "<button class='p-btn'$attrString>$content</button>",
            'row'  => "<div class='p-row'$attrString>$content</div>",
            'col'  => "<div class='p-col'$attrString>$content</div>",
            'alert_success' => "<div class='p-alert p-alert-success'$attrString>$content</div>",
            'alert_error'   => "<div class='p-alert p-alert-error'$attrString>$content</div>",
            'input' => "<input class='p-input'$attrString />",
            default => "<div$attrString>$content</div>"
        };
    }

    public static function icon(string $name, string $style = ''): string {
        if (!str_contains(self::$headPart, 'Material+Symbols')) {
            self::import('css', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200');
        }
        return "<span class='material-symbols-outlined' style='vertical-align:middle; $style'>$name</span>";
    }

    public static function slot(string $name, ?string $content = null) {
        if ($content !== null) {
            self::$slots[$name] = $content;
            return '';
        }
        return self::$slots[$name] ?? '';
    }

    public static function layout(string $title, string $bodyContent): string {
        self::head(['title' => $title]);
        self::body($bodyContent);
        return self::render();
    }


    // ==========================================
    // PHTP (Template Engine) Properties
    // ==========================================
    public static $loops = [];

    // ==========================================
    // PHTP (Template Engine) Methods (CLEAN CACHE)
    // ==========================================

    private static function getLastModTime(string $path): int {
        if (!file_exists($path)) return 0;
        if (!is_dir($path)) return filemtime($path);
        $maxMtime = filemtime($path);
        try {
            $flags = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, $flags),
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iterator as $fileInfo) {
                $mtime = $fileInfo->getMTime();
                if ($mtime > $maxMtime) $maxMtime = $mtime;
            }
        } catch (\Throwable $e) {}
        return $maxMtime;
    }

    private static function getPath(string $type): string {
        if (class_exists('DIR') && method_exists('DIR', 'path')) {
            return rtrim(str_replace('\\', '/', DIR::path($type)), '/');
        }
        return $type === 'cache' ? 'cache' : 'component'; 
    }

    /**
     * Use the same readable kebab-case filename for PHP, CSS, and JS caches.
     */
    private static function normalizeCacheName(string $name, string $fallback = 'index'): string {
        $name = trim(str_replace('\\', '/', $name), '/');
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name) ?? $name;
        $name = preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? '';
        $name = strtolower(trim($name, '-'));
        return $name !== '' ? $name : $fallback;
    }

    /**
     * Keep compiled PHP templates in the framework cache directory.
     * Server rules deny direct web access to this dedicated subdirectory.
     */
    private static function getCompiledCacheDir(): string {
        $cacheDir = rtrim(self::getPath('cache'), '/\\') . DIRECTORY_SEPARATOR . 'php';

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0750, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException("Unable to create compiled view cache directory: {$cacheDir}");
        }
        return str_replace('\\', '/', $cacheDir);
    }

    private static function resolveViewPath(string $view): string {
        if (isset(self::$resolvedPaths[$view])) return self::$resolvedPaths[$view];

        $viewPath = str_replace('.', '/', $view);
        if (
            str_contains($viewPath, "\0") ||
            preg_match('~(?:^|/)\.\.(?:/|$)~', str_replace('\\', '/', $viewPath))
        ) {
            throw new \InvalidArgumentException('View path traversal is not allowed.');
        }
        $basePath = self::getPath('component') . '/' . ltrim($viewPath, '/');

        $resolved = '';
        if (file_exists($basePath . '.mix.php')) $resolved = $basePath . '.mix.php';
        elseif (file_exists($basePath . '.php')) $resolved = $basePath . '.php';

        // If not found in component, check app folder
        if (!$resolved) {
            $appPath = self::getPath('app') . '/' . ltrim($viewPath, '/');
            if (file_exists($appPath . '.php')) $resolved = $appPath . '.php';
        }

        if (!$resolved) return '';

        return self::$resolvedPaths[$view] = $resolved;
    }
    /**
     * 🚀 EXACT ROUTE NAME CACHING (No Hash)
     */
    private static function getCacheFilePath(string $view, ?string $fragment = null): string {
        $cacheDir = self::getCompiledCacheDir();
        
        $routeName = self::normalizeCacheName($view, 'view');
        
        // যদি রাউটের নাম না থাকে, তবে ভিউয়ের নাম ব্যবহার করবে

        // ফ্র্যাগমেন্ট থাকলে নামের শেষে যুক্ত হবে
        if ($fragment !== null) {
            $routeName .= '-' . self::normalizeCacheName($fragment, 'fragment');
        }

        return $cacheDir . '/' . $routeName . '.php';
    }

    /**
     * ক্যাশ পরিষ্কার করার মেথড
     */
    public static function clearCache(): bool {
        $cacheDir = self::getCompiledCacheDir();
        if (!is_dir($cacheDir)) return true;
        $files = glob($cacheDir . '/*.php');
        foreach ($files as $file) {
            if (is_file($file)) @unlink($file);
        }
        return true;
    }

    /**
     * মূল রেন্ডার মেথড
     */
    public static function view(string $view, array $data = [], ?string $fragment = null): string {
        $viewFile = self::resolveViewPath($view);

        // FALLBACK TO PHUI (If no file exists and name starts with ui., ui: or ui-)
        if (!$viewFile && preg_match('/^ui[.:-]/i', $view)) {
            $slug = str_replace(['.', '-'], ':', substr($view, 3));
            if (class_exists('PHUI') && PHUI::exists($slug)) {
                return PHUI::ui($slug, $data);
            }
        }

        if (!$viewFile) {
            throw new \InvalidArgumentException("View not found: {$view}");
        }

        $cacheFile = self::getCacheFilePath($view, $fragment);
        $mustBuild = !file_exists($cacheFile);
        
        if (!$mustBuild) {
            $cacheTime = filemtime($cacheFile);

            // ১. মেইন ভিউ ফাইল আপডেট চেক
            if ($viewFile && filemtime($viewFile) > $cacheTime) {
                $mustBuild = true;
            } 
            // ২. রাউটার (index.php), ৩. অ্যাপ (Backend) বা কম্পোনেন্ট (UI) ফোল্ডারের গ্লোবাল আপডেট চেক
            else {
                $rootPath = (class_exists('DIR') && method_exists('DIR', 'path')) ? DIR::path('root') : './';
                $routerFile = rtrim($rootPath, '/\\') . '/index.php';
                
                $pathsToCheck = [
                    $routerFile,
                    (class_exists('DIR') && method_exists('DIR', 'path')) ? DIR::path('app') : '',
                    (class_exists('DIR') && method_exists('DIR', 'path')) ? DIR::path('component') : ''
                ];
                
                foreach ($pathsToCheck as $path) {
                    if (!empty($path) && file_exists($path)) {
                        if (self::getLastModTime($path) > $cacheTime) {
                            $mustBuild = true;
                            break;
                        }
                    }
                }
            }
            
            // ৪. ইঞ্জিন (PHJC.php) আপডেট চেক
            if (!$mustBuild && filemtime(__FILE__) > $cacheTime) {
                $mustBuild = true;
            }
        }

        if ($mustBuild) {
            self::buildMasterTemplate($view, $cacheFile, $fragment);
        }

        return self::evaluateView($cacheFile, $data);
    }

    public static function includeView(string $view, array $data = []): string {
        return self::view($view, $data);
    }

    /**
     * মাস্টার স্ট্রিং তৈরি করা
     */
    private static function buildMasterTemplate(string $mainView, string $cacheFile, ?string $fragment = null) {
        $mainViewFile = self::resolveViewPath($mainView);
        if (!$mainViewFile || !is_readable($mainViewFile)) {
            throw new \InvalidArgumentException("View not found or unreadable: {$mainView}");
        }
        $content = file_get_contents($mainViewFile);
        if ($content === false) {
            throw new \RuntimeException("Unable to read view: {$mainView}");
        }

        // 0. Remove Template Comments
        $content = preg_replace('/\{\{--.*?--\}\}/s', '', $content);

        // 🚀 SCENARIO 1: Fragment
        if ($fragment !== null) {
            if (preg_match('/@(fragment|part)\s*\(\s*[\'"]?' . preg_quote($fragment, '/') . '[\'"]?\s*\)(.*?)@(endfragment|endpart)/is', $content, $m)) {
                $content = trim($m[2]);
            } else {
                $content = "<!-- Fragment '{$fragment}' not found in {$mainView} -->";
            }
        } 
        // 🚀 SCENARIO 2: Full
        else {
            // 1. Layout Check
            $layoutContent = '';
            if (preg_match('/@(extends|layout|master|inherits)\s*\(\s*[\'"]?(.*?)[\'"]?\s*\)/i', $content, $matches)) {
                $layoutViewFile = self::resolveViewPath($matches[2]);
                if (!$layoutViewFile || !is_readable($layoutViewFile)) {
                    throw new \InvalidArgumentException("Layout not found or unreadable: {$matches[2]}");
                }
                $layoutContent = file_get_contents($layoutViewFile);
                $content = preg_replace('/@(extends|layout|master|inherits)\s*\(\s*[\'"]?(.*?)[\'"]?\s*\)/i', '', $content);
            }

            $sections = [];
            $stacks = [];

            // 2. Sections Extract করা (শুধুমাত্র section/block/define, fragment নয়!)
            preg_match_all('/@(section|block|define)\s*\(\s*[\'"]?(.*?)[\'"]?\s*,\s*[\'"]?(.*?)[\'"]?\s*\)/i', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $sections[trim($match[2])] = trim($match[3]);
            }
            $content = preg_replace('/@(section|block|define)\s*\(\s*[\'"]?(.*?)[\'"]?\s*,\s*[\'"]?(.*?)[\'"]?\s*\)/i', '', $content);

            preg_match_all('/@(section|block|define)\s*\(\s*[\'"]?(.*?)[\'"]?\s*\)(.*?)@(endsection|endblock|enddefine)/is', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $sections[trim($match[2])] = trim($match[3]);
            }

            // 3. Pushes Extract করা
            preg_match_all('/@(push|append|inject)\s*\(\s*[\'"]?(.*?)[\'"]?\s*\)(.*?)@(endpush|endappend|endinject)/is', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $stackName = trim($match[2]);
                if (!isset($stacks[$stackName])) $stacks[$stackName] = '';
                $stacks[$stackName] .= trim($match[3]) . "\n";
            }

            // 4. লেআউটে সেকশনগুলো বসানো
            if ($layoutContent !== '') {
                foreach ($sections as $name => $body) {
                    $layoutContent = preg_replace('/@(yield|render|insert|show|content)\s*\(\s*[\'"]?' . preg_quote($name, '/') . '[\'"]?\s*\)/i', $body, $layoutContent);
                }
                $layoutContent = preg_replace('/@(yield|render|insert|show|content)\s*\(\s*[\'"]?(.*?)[\'"]?\s*\)/i', '', $layoutContent);
                
                foreach ($stacks as $name => $body) {
                    $layoutContent = preg_replace('/@(stack|pusharea)\s*\(\s*[\'"]?' . preg_quote($name, '/') . '[\'"]?\s*\)/i', $body, $layoutContent);
                }
                $layoutContent = preg_replace('/@(stack|pusharea)\s*\(\s*[\'"]?(.*?)[\'"]?\s*\)/i', '', $layoutContent);

                // মূল কন্টেন্ট এখন লেআউটসহ
                $content = $layoutContent;
            } else {
                $content = preg_replace('/@(section|block|define|push|append|inject)\s*\(\s*[\'"]?(.*?)[\'"]?\s*\)/i', '', $content);
                $content = preg_replace('/@(endsection|endblock|enddefine|endpush|endappend|endinject)/i', '', $content);
            }

            // 5. fragment ট্যাগগুলো মুছে যাবে, 
            $content = preg_replace('/@(fragment|part)\s*\(\s*[\'"]?(.*?)[\'"]?\s*\)/i', '', $content);
            $content = preg_replace('/@(endfragment|endpart)/i', '', $content);
        }

        // 6. Include Fragments with Aliases
        $content = preg_replace_callback('/@(includeFragment|fragment|use|load|injectBlock|importSection|includePart)\s*\(\s*[\'"]?(.*?)[\'"]?\s*,\s*[\'"]?(.*?)[\'"]?(?:\s*,\s*(\[.*?\]))?\s*\)/i', function($m) {
            $viewToInclude = trim($m[2]);
            $fragmentName = trim($m[3]);
            $dataCode = !empty($m[4]) ? $m[4] : '[]';
            return "<?php echo \PHJC::view(" . var_export($viewToInclude, true)
                . ", array_merge(get_defined_vars(), {$dataCode}), "
                . var_export($fragmentName, true) . "); ?>";
        }, $content);

        // 7. Inject Components & Includes
        $content = self::injectComponentsAndIncludes($content);

        // 8. Compile directives
        $content = self::compileDirectives($content);

        // ফাইল তৈরি
        try {
            token_get_all($content, TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new \RuntimeException(
                "Compiled view contains invalid PHP ({$mainView}): {$e->getMessage()}",
                0,
                $e
            );
        }
        self::writeCompiledCache($cacheFile, trim($content));
    }

    private static function writeCompiledCache(string $cacheFile, string $content): void {
        $directory = dirname($cacheFile);
        $lockPath = $directory . DIRECTORY_SEPARATOR . '.phjc-compile.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new \RuntimeException("Unable to lock compiled view cache: {$cacheFile}");
        }

        $temporary = @tempnam($directory, '.phjc-');
        try {
            if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) === false) {
                throw new \RuntimeException("Unable to write compiled view cache: {$cacheFile}");
            }
            @chmod($temporary, 0640);

            if (!@rename($temporary, $cacheFile)) {
                // Windows cannot always replace an existing file atomically.
                // The lock keeps framework writers serialized in that case.
                if (file_put_contents($cacheFile, $content, LOCK_EX) === false) {
                    throw new \RuntimeException("Unable to replace compiled view cache: {$cacheFile}");
                }
                @unlink($temporary);
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function injectComponentsAndIncludes($content) {
        // @include | @partial | @import
        $content = preg_replace_callback('/@(include|partial|import)\s*\(\s*[\'"]?(.*?)[\'"]?\s*(?:,\s*(\[.*?\]))?\s*\)/i', function($m) {
            $viewPath = self::resolveViewPath($m[2]);
            if (!$viewPath || !is_readable($viewPath)) {
                throw new \InvalidArgumentException("Included view not found or unreadable: {$m[2]}");
            }
            $includedHtml = file_get_contents($viewPath);
            if ($includedHtml === false) {
                throw new \RuntimeException("Unable to read included view: {$m[2]}");
            }
            $dataCode = !empty($m[3]) ? "<?php extract({$m[3]}); ?>" : "";
            return $dataCode . self::injectComponentsAndIncludes($includedHtml);
        }, $content);

        // <x-component />
        $content = preg_replace_callback('/<x-([\w\.\-]+)([^>]*)\/>/s', function($m) {
            return self::parseComponentTag($m[1], $m[2], '');
        }, $content);
        
        // <x-component> ... </x-component>
        $content = preg_replace_callback('/<x-([\w\.\-]+)([^>]*)>(.*?)<\/x-\1>/s', function($m) {
            return self::parseComponentTag($m[1], $m[2], $m[3]);
        }, $content);

        return $content;
    }

    private static function parseComponentTag($name, $attributeString, $slotContent) {
        $cleanName = str_replace('-', '.', $name);
        $viewFile = self::resolveViewPath($cleanName);

        // FALLBACK TO PHUI (If name starts with ui- and no file exists)
        if (!$viewFile && str_starts_with($name, 'ui-')) {
            $slug = str_replace('-', ':', substr($name, 3));
            if (class_exists('PHUI') && PHUI::exists($slug)) {
                $attributes = [];
                preg_match_all('/(:?[\w\-]+)(?:=["\'](.*?)["\'])?/', $attributeString, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $k = $match[1]; $v = $match[2] ?? 'true';
                    if (str_starts_with($k, ':')) {
                        $k = substr($k, 1);
                        $attributes[] = "'$k' => $v";
                    } else {
                        $attributes[] = var_export($k, true) . ' => ' . var_export($v, true);
                    }
                }
                $attributes[] = "'slot' => " . var_export($slotContent, true);
                $attrArrayStr = '[' . implode(', ', $attributes) . ']';
                
                return "<?php echo PHUI::ui('$slug', array_merge(\$__phjc_data ?? [], $attrArrayStr)); ?>";
            }
        }

        if (!$viewFile) {
            throw new Exception("PHJC Template Error: Component '$name' not found in component/ or app/ folders.");
        }

        $componentHtml = file_get_contents($viewFile);
        $componentHtml = str_ireplace('{{ $slot }}', $slotContent, $componentHtml);

        $attributes = [];
        preg_match_all('/(:?[\w\-]+)(?:=["\'](.*?)["\'])?/', $attributeString, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = $match[1]; $value = $match[2] ?? 'true';
            if (str_starts_with($key, ':')) { 
                $key = substr($key, 1); 
                $attributes[] = "'$key' => $value"; 
            } else { 
                $attributes[] = var_export($key, true) . ' => ' . var_export($value, true);
            }
        }
        
        $attrArrayStr = '[' . implode(', ', $attributes) . ']';
        
        // Isolated Scope for Components
        $phpCodeStart = "<?php (function() use (\$__phjc_data) { extract(\$__phjc_data); extract({$attrArrayStr}); ?>";
        $phpCodeEnd = "<?php })(); ?>";

        return $phpCodeStart . self::injectComponentsAndIncludes($componentHtml) . $phpCodeEnd;
    }

    private static function compileDirectives($content) {
        $content = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/is', function($m) {
            $scriptBody = preg_replace('/\{\{\s*(.+?)\s*\}\}/', '<?php echo json_encode($1); ?>', $m[1]);
            return str_replace($m[1], $scriptBody, $m[0]);
        }, $content);

        $content = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/', function($m) {
            return self::compileEchoWithFilters($m[1]);
        }, $content);

        $content = preg_replace('/\{\!\!\s*(.+?)\s*\!\!\}/', '<?php echo $1 ?? \'\'; ?>', $content);
        $content = preg_replace('/@php(.*?)@endphp/s', '<?php $1 ?>', $content);

        // PHUI kit directives. Keep @section reserved for layout composition.
        // Each UI directive intentionally occupies one template line so nested
        // PHP expressions inside its data array remain safe to compile.
        $content = preg_replace_callback(
            '/^[ \t]*@(ui|element|uisection|uilayout|uipage)\s*\(\s*([\'"])([a-z0-9:._-]+)\2\s*(?:,\s*(.+))?\s*\)[ \t]*$/im',
            static function ($match) {
                $method = match (strtolower($match[1])) {
                    'element' => 'element',
                    'uisection' => 'section',
                    'uilayout' => 'layout',
                    'uipage' => 'page',
                    default => 'ui',
                };
                $slug = var_export($match[3], true);
                $data = isset($match[4]) && trim($match[4]) !== '' ? trim($match[4]) : '[]';
                return "<?php echo \\PHUI::{$method}({$slug}, {$data}); ?>";
            },
            $content
        );

        $content = preg_replace_callback('/@foreach\s*\((.+?)\s+as\s+(.+?)\)/', function($m) {
            return "<?php \n \PHJC::startLoop({$m[1]});\n foreach({$m[1]} as {$m[2]}): \n \$loop = \PHJC::currentLoop(); \n ?>";
        }, $content);
        $content = preg_replace('/@endforeach/', '<?php \PHJC::endLoop(); endforeach; ?>', $content);

        if (!empty(self::$customDirectives)) {
            $directiveNames = array_map('preg_quote', array_keys(self::$customDirectives));
            $pattern = '/@(' . implode('|', $directiveNames) . ')\s*(?:\((.*?)\))?/s';
            $content = preg_replace_callback($pattern, function($m) {
                return call_user_func(self::$customDirectives[$m[1]], $m[2] ?? null);
            }, $content);
        }

        $compilers = [
            '/@if\s*\((.*)\)/' => '<?php if($1): ?>',
            '/@elseif\s*\((.*)\)/' => '<?php elseif($1): ?>',
            '/@else/' => '<?php else: ?>',
            '/@endif/' => '<?php endif; ?>',
            '/@isset\s*\((.*)\)/' => '<?php if(isset($1)): ?>',
            '/@endisset/' => '<?php endif; ?>',
            '/@empty\s*\((.*)\)/' => '<?php if(empty($1)): ?>',
            '/@endempty/' => '<?php endif; ?>',
            '/@for\s*\((.*)\)/' => '<?php for($1): ?>',
            '/@endfor/' => '<?php endfor; ?>',
            '/@while\s*\((.*)\)/' => '<?php while($1): ?>',
            '/@endwhile/' => '<?php endwhile; ?>',
            '/@switch\s*\((.*)\)/' => '<?php switch($1): ?>',
            '/@case\s*\((.*)\)/' => '<?php case $1: ?>',
            '/@default/' => '<?php default: ?>',
            '/@break/' => '<?php break; ?>',
            '/@endswitch/' => '<?php endswitch; ?>',            
            '/@auth/' => '<?php if(!empty($_SESSION[\'user\'])): ?>',
            '/@endauth/' => '<?php endif; ?>',
            '/@guest/' => '<?php if(empty($_SESSION[\'user\'])): ?>',
            '/@endguest/' => '<?php endif; ?>',
            '/@error\s*\(\s*[\'"](.*?)[\'"]\s*\)/' => '<?php if(isset($_SESSION[\'errors\'][\'$1\'])): ?>',
            '/@enderror/' => '<?php endif; ?>',
            '/@csrf/' => '<?php echo \'<input type="hidden" name="csrf_token" value="\'.(class_exists(\'PHRO\') ? \PHRO::getToken() : ($_SESSION[\'csrf_token\'] ?? \'\')).\'">\'; ?>',
            '/@dump\s*\((.*)\)/' => '<?php var_dump($1); ?>',
            '/@dd\s*\((.*)\)/' => '<?php var_dump($1); die; ?>',
        ];

        $content = preg_replace(array_keys($compilers), array_values($compilers), $content);

        // Individual complex replacements
        $content = preg_replace('/@old\s*\(\s*[\'"](.*?)[\'"]\s*(?:,\s*(.*?))?\s*\)/', '<?php echo htmlspecialchars($_REQUEST[\'$1\'] ?? $2 ?? \'\'); ?>', $content);
        $content = preg_replace('/@method\s*\(\s*[\'"](.*?)[\'"]\s*\)/', '<?php echo \'<input type="hidden" name="_method" value="$1">\'; ?>', $content);

        return $content;
    }

    private static function compileEchoWithFilters(string $expression): string {
        $parts = explode('|', $expression);
        $var = trim(array_shift($parts));
        foreach ($parts as $filterStr) {
            $filterStr = trim($filterStr);
            if (preg_match('/(\w+)\((.*?)\)/', $filterStr, $m)) { $filter = $m[1]; $arg = $m[2]; } 
            else { $filter = $filterStr; $arg = null; }
            switch ($filter) {
                case 'upper': $var = "strtoupper((string)$var)"; break;
                case 'lower': $var = "strtolower((string)$var)"; break;
                case 'capitalize': $var = "ucwords((string)$var)"; break;
                case 'length': $var = "(is_array($var) || is_object($var) ? count($var) : strlen((string)$var))"; break;
                case 'default': $var = "(!empty($var) ? $var : $arg)"; break;
                case 'json': $var = "json_encode($var)"; break;
                case 'date': $var = "date($arg, strtotime($var))"; break;
            }
        }
        return "<?php echo htmlspecialchars((string) ($var ?? ''), ENT_QUOTES, 'UTF-8'); ?>"; 
    }

    private static function evaluateView(string $__phjc_path, array $__phjc_data): string {
        $__phjc_data = array_merge(self::$sharedData, $__phjc_data);
        extract($__phjc_data, EXTR_SKIP);
        ob_start();
        try {
            include $__phjc_path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new \Exception("PHJC Engine Error: {$e->getMessage()} in {$e->getFile()} (Line: {$e->getLine()})", 0, $e);
        }
        return trim(ob_get_clean());
    }

    public static function startLoop($array): void {
        $count = is_array($array) || $array instanceof Countable ? count($array) : 0;
        self::$loops[] = ['iteration' => 0, 'index' => -1, 'count' => $count, 'first' => true, 'last' => $count === 1, 'depth' => count(self::$loops) + 1];
    }

    public static function currentLoop(): object {
        $depth = count(self::$loops) - 1;
        
        if ($depth < 0 || !isset(self::$loops[$depth])) {
            return (object) [
                'iteration' => 1,
                'index' => 0,
                'count' => 0,
                'first' => true,
                'last' => true,
                'depth' => 1
            ];
        }

        self::$loops[$depth]['iteration']++; 
        self::$loops[$depth]['index']++;
        self::$loops[$depth]['first'] = (self::$loops[$depth]['index'] === 0);
        self::$loops[$depth]['last'] = (self::$loops[$depth]['iteration'] === self::$loops[$depth]['count']);
        
        return (object) self::$loops[$depth];
    }
    
    public static function endLoop(): void { array_pop(self::$loops); }

    public static function share($key, $value = null): void {
        if (is_array($key)) {
            self::$sharedData = array_merge(self::$sharedData, $key);
        } else {
            self::$sharedData[$key] = $value;
        }
    }

    public static function directive(string $name, callable $handler): void {
        self::$customDirectives[$name] = $handler;
    }

    public static function minify(bool $state = true): void {
        self::$minify = $state;
    }

    public static function metaPreset(string $type, array $data = []): void {
        $presets = [
            'article' => ['type' => 'article'],
            'website' => ['type' => 'website'],
            'blog' => ['type' => 'article', 'category' => 'Blog'],
            'product' => ['type' => 'product'],
        ];
        if (isset($presets[$type])) {
            self::head(array_merge($presets[$type], $data));
        }
    }

    public static function breadcrumb(array $crumbs): void {
        $items = [];
        foreach ($crumbs as $i => $crumb) {
            $items[] = [
                "@type" => "ListItem",
                "position" => $i + 1,
                "name" => $crumb['name'],
                "item" => $crumb['url']
            ];
        }
        $json = json_encode([
            "@context" => "https://schema.org",
            "@type" => "BreadcrumbList",
            "itemListElement" => $items
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::header("<script type=\"application/ld+json\">$json</script>");
    }
    // ==========================================
    // PHTP (Template Engine) Methods END
    // ==========================================


    public static function reset() {
        self::$head = [
            'name' => '',  // e.g., 'Your Site Name'
            'title' => '',  // e.g., 'Your Page Title - Primary Keyword | Secondary Keywords'
            'description' => '',  // e.g., 'A concise and compelling description of the page's content, under 160 characters. Include primary and secondary keywords naturally.'
            'short' => 'auto',  // e.g., 'A concise and compelling short description of the page's content, under 60 characters / otherwise use 'auto'.'
            'type' => 'article',  // 'article' is a fixed value
            'category' => '',  // e.g., 'Category of the article'
            'author' => '',  // e.g., 'Your Name or Company Name'
            'keywords' => '',  // e.g., 'Relevant, Keywords, Separated, By, Commas, Long-Tail, Keywords'
            'url' => '',  // e.g., 'https://www.yoursite.com/page-url'
            'lang' => 'en',  // e.g., 'en'
            'locale' => '',  // e.g., 'en_US'
            'locale_other' => [],  // e.g., ['en_US' => 'https://www.yoursite.com/page-url']
            'published' => '',  // e.g., 'YYYY-MM-DDTHH:mm:ssZZ'
            'modified' => '',  // e.g., 'YYYY-MM-DDTHH:mm:ssZZ'
            'image' => '',  // e.g., 'https://www.yoursite.com/path/to/high-quality-image.jpg'
            'image_alt' => '',  // e.g., 'Descriptive alt text for your image'
            'favicon' => '',  // e.g., '/favicon.ico'
            'icon_192' => '',  // e.g., '/path/to/192x192.png'
            'icon_180' => '',  // e.g., '/apple-touch-icon/180x180.png'
            'icon_32' => '',  // e.g., '/favicon-32x32.png'
            'icon_16' => '',  // e.g., '/favicon-16x16.png'
            'theme_color' => '',  // e.g., '#317EFB'
            'manifest' => '',  // e.g., '/manifest.json'
            'formatable' => 'no',  // e.g., 'formatting of phone numbers as clickable links on mobile devices. use: yes/no'
            'fb_app_id' => '',  // e.g., 'YOUR_FB_APP_ID'
            'twitter_creator' => '',  // e.g., '@YourPersonalHandle'
            'telegram_username' => '',  // e.g., 'YourTelegramUsername'
            'pinterest_app' => '',  // e.g., 'YOUR_PINTEREST_APP_ID'
            'google_site_verification' => '',  // e.g., 'Your Google Verification Code'
            'msvalidate' => '',  // e.g., 'Your Bing Verification Code'
            'csp' => "default-src 'self'; img-src 'self' data:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';",  // Content-Security-Policy (CSP)
            'streamui' => true,
            'streampath' => '',
        ];
        self::$header = '';
        self::$headPart = '';
        self::$body = '';
        self::$bodyPram = [];
        self::$bodyPart = '';
        self::$css = '';
        self::$js = '';
    }

    public static function head(array $data) {
        foreach ($data as $key => $value) {
            if (array_key_exists($key, self::$head)) {
                self::$head[$key] = $value;
            }
        }
        return ['status' => 'success', 'msg' => 'Head values updated successfully.'];
    }

    public static function buildHead() {
        $html_head = '';
        $html_head .= "<meta charset=\"UTF-8\">" . PHP_EOL;
        $html_head .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0, user-scalable=no, shrink-to-fit=no\">" . PHP_EOL;
        
        if (!empty(self::$head['title'])) {
            $html_head .= "<title>" . htmlspecialchars(self::$head['title'], ENT_QUOTES, 'UTF-8') . "</title>" . PHP_EOL;
        }
        
        if (!empty(self::$head['description'])) {
            $html_head .= "<meta name=\"description\" content=\"" . htmlspecialchars(self::$head['description'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        $html_head .= "<meta name=\"robots\" content=\"max-snippet:-1, max-image-preview:large, max-video-preview:-1\">" . PHP_EOL;
        $html_head .= "<meta name=\"googlebot\" content=\"index, follow\">" . PHP_EOL;
        
        if (!empty(self::$head['url'])) {
            $html_head .= "<link rel=\"canonical\" href=\"" . htmlspecialchars(self::$head['url'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['title'])) {
            $html_head .= "<meta property=\"og:title\" content=\"" . htmlspecialchars(self::$head['title'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['description'])) {
            $html_head .= "<meta property=\"og:description\" content=\"" . htmlspecialchars(self::$head['description'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['image'])) {
            $html_head .= "<meta property=\"og:image\" content=\"" . htmlspecialchars(self::$head['image'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
            $html_head .= "<meta property=\"og:image:width\" content=\"1200\">" . PHP_EOL;
            $html_head .= "<meta property=\"og:image:height\" content=\"630\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['image_alt'])) {
            $html_head .= "<meta property=\"og:image:alt\" content=\"" . htmlspecialchars(self::$head['image_alt'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['url'])) {
            $html_head .= "<meta property=\"og:url\" content=\"" . htmlspecialchars(self::$head['url'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['type'])) {
            $html_head .= "<meta property=\"og:type\" content=\"" . htmlspecialchars(self::$head['type'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['locale'])) {
            $html_head .= "<meta property=\"og:locale\" content=\"" . htmlspecialchars(self::$head['locale'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['name'])) {
            $html_head .= "<meta property=\"og:site_name\" content=\"" . htmlspecialchars(self::$head['name'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['fb_app_id'])) {
            $html_head .= "<meta property=\"fb:app_id\" content=\"" . htmlspecialchars(self::$head['fb_app_id'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['author'])) {
            $html_head .= "<meta property=\"article:author\" content=\"" . htmlspecialchars(self::$head['author'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['published'])) {
            $html_head .= "<meta property=\"article:published_time\" content=\"" . htmlspecialchars(self::$head['published'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['modified'])) {
            $html_head .= "<meta property=\"article:modified_time\" content=\"" . htmlspecialchars(self::$head['modified'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['category'])) {
            $html_head .= "<meta property=\"article:section\" content=\"" . htmlspecialchars(self::$head['category'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['short'])) {
            $html_head .= "<meta property=\"og:determiner\" content=\"" . htmlspecialchars(self::$head['short'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['locale_other'])) {
            foreach (self::$head['locale_other'] as $locale => $url) {
                $html_head .= "<meta property=\"og:locale:alternate\" content=\"" . htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
            }
        
            foreach (self::$head['locale_other'] as $locale => $url) {
                $html_head .= "<link rel=\"alternate\" href=\"" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "\" hreflang=\"" . htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
            }
        }
        
        if (!empty(self::$head['image'])) {
            $html_head .= "<meta name=\"twitter:card\" content=\"summary_large_image\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['title'])) {
            $html_head .= "<meta name=\"twitter:site\" content=\"" . htmlspecialchars(self::$head['title'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['twitter_creator'])) {
            $html_head .= "<meta name=\"twitter:creator\" content=\"" . htmlspecialchars(self::$head['twitter_creator'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['title'])) {
            $html_head .= "<meta name=\"twitter:title\" content=\"" . htmlspecialchars(self::$head['title'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['description'])) {
            $html_head .= "<meta name=\"twitter:description\" content=\"" . htmlspecialchars(self::$head['description'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['image'])) {
            $html_head .= "<meta name=\"twitter:image:src\" content=\"" . htmlspecialchars(self::$head['image'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
            $html_head .= "<meta name=\"twitter:image\" content=\"" . htmlspecialchars(self::$head['image'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['image_alt'])) {
            $html_head .= "<meta name=\"twitter:image:alt\" content=\"" . htmlspecialchars(self::$head['image_alt'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['description'])) {
            $html_head .= "<meta property=\"whatsapp:description\" content=\"" . htmlspecialchars(self::$head['description'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['title'])) {
            $html_head .= "<meta property=\"whatsapp:title\" content=\"" . htmlspecialchars(self::$head['title'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['url'])) {
            $html_head .= "<meta property=\"instapp:payload\" content=\"" . htmlspecialchars(self::$head['url'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['url'])) {
            $html_head .= "<meta property=\"og:site\" content=\"" . htmlspecialchars(self::$head['url'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['published'])) {
            $html_head .= "<meta property=\"og:article:published_time\" content=\"" . htmlspecialchars(self::$head['published'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['description'])) {
            $html_head .= "<meta property=\"messenger:description\" content=\"" . htmlspecialchars(self::$head['description'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['telegram_username'])) {
            $html_head .= "<meta property=\"telegram:username\" content=\"" . htmlspecialchars(self::$head['telegram_username'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['keywords'])) {
            $html_head .= "<meta property=\"og:news_keywords\" content=\"" . htmlspecialchars(self::$head['keywords'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['keywords'])) {
            $html_head .= "<meta property=\"bahasa\" content=\"" . htmlspecialchars(self::$head['keywords'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['description'])) {
            $html_head .= "<meta name=\"pinterest:description\" content=\"" . htmlspecialchars(self::$head['description'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['pinterest_app'])) {
            $html_head .= "<meta name=\"pinterest:app\" content=\"" . htmlspecialchars(self::$head['pinterest_app'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['title'])) {
            $html_head .= "<meta property=\"snapchat:caption\" content=\"" . htmlspecialchars(self::$head['title'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['image'])) {
            $html_head .= "<meta property=\"vk:image\" content=\"" . htmlspecialchars(self::$head['image'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['description'])) {
            $html_head .= "<meta property=\"vk:description\" content=\"" . htmlspecialchars(self::$head['description'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['description'])) {
            $html_head .= "<meta name=\"tiktok:description\" content=\"" . htmlspecialchars(self::$head['description'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['title'])) {
            $html_head .= "<meta property=\"reddit:title\" content=\"" . htmlspecialchars(self::$head['title'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['description'])) {
            $html_head .= "<meta property=\"reddit:description\" content=\"" . htmlspecialchars(self::$head['description'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['favicon'])) {
            $html_head .= "<link rel=\"shortcut icon\" href=\"" . htmlspecialchars(self::$head['favicon'], ENT_QUOTES, 'UTF-8') . "\" type=\"image/x-icon\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['icon_192'])) {
            $html_head .= "<link rel=\"icon\" sizes=\"192x192\" href=\"" . htmlspecialchars(self::$head['icon_192'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['icon_180'])) {
            $html_head .= "<link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"" . htmlspecialchars(self::$head['icon_180'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['icon_32'])) {
            $html_head .= "<link rel=\"icon\" type=\"image/png\" sizes=\"32x32\" href=\"" . htmlspecialchars(self::$head['icon_32'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['icon_16'])) {
            $html_head .= "<link rel=\"icon\" type=\"image/png\" sizes=\"16x16\" href=\"" . htmlspecialchars(self::$head['icon_16'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['keywords'])) {
            $html_head .= "<meta name=\"keywords\" content=\"" . htmlspecialchars(self::$head['keywords'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['author'])) {
            $html_head .= "<meta name=\"author\" content=\"" . htmlspecialchars(self::$head['author'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['theme_color'])) {
            $html_head .= "<meta name=\"theme-color\" content=\"" . htmlspecialchars(self::$head['theme_color'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['formatable'])) {
            $html_head .= "<meta name=\"format-detection\" content=\"telephone=" . htmlspecialchars(self::$head['formatable'], ENT_QUOTES, 'UTF-8') . ", email=" . htmlspecialchars(self::$head['formatable'], ENT_QUOTES, 'UTF-8') . ", address=" . htmlspecialchars(self::$head['formatable'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['locale'])) {
            $html_head .= "<meta http-equiv=\"Content-Language\" content=\"" . htmlspecialchars(self::$head['locale'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['theme_color'])) {
            $html_head .= "<meta name=\"msapplication-TileColor\" content=\"" . htmlspecialchars(self::$head['theme_color'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['google_site_verification'])) {
            $html_head .= "<meta name=\"google-site-verification\" content=\"" . htmlspecialchars(self::$head['google_site_verification'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['msvalidate'])) {
            $html_head .= "<meta name=\"msvalidate.01\" content=\"" . htmlspecialchars(self::$head['msvalidate'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['manifest'])) {
            $html_head .= "<link rel=\"manifest\" href=\"" . htmlspecialchars(self::$head['manifest'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['description'])) {
            $html_head .= "<meta property=\"web_chat:description\" content=\"" . htmlspecialchars(self::$head['description'], ENT_QUOTES, 'UTF-8') . "\"/>" . PHP_EOL;
        }
        
        if (!empty(self::$head['author'])) {
            $html_head .= "<meta property=\"web_chat:author\" content=\"" . htmlspecialchars(self::$head['author'], ENT_QUOTES, 'UTF-8') . "\"/>" . PHP_EOL;
        }
        
        if (!empty(self::$head['title'])) {
            $html_head .= "<meta property=\"xt:title\" content=\"" . htmlspecialchars(self::$head['title'], ENT_QUOTES, 'UTF-8') . "\"/>" . PHP_EOL;
        }
        
        if (!empty(self::$head['description'])) {
            $html_head .= "<meta property=\"xt:description\" content=\"" . htmlspecialchars(self::$head['description'], ENT_QUOTES, 'UTF-8') . "\"/>" . PHP_EOL;
        }
        
        if (!empty(self::$head['csp'])) {
            $html_head .= "<meta http-equiv=\"Content-Security-Policy\" content=\"" . htmlspecialchars(self::$head['csp'], ENT_COMPAT, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        if (!empty(self::$head['url'])) {
            $html_head .= "<link rel=\"dns-prefetch\" href=\"//" . htmlspecialchars(self::$head['url'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
            $html_head .= "<link rel=\"preconnect\" href=\"" . htmlspecialchars(self::$head['url'], ENT_QUOTES, 'UTF-8') . "\">" . PHP_EOL;
        }
        
        $html_head .= "<meta name=\"mobile-web-app-capable\" content=\"yes\">" . PHP_EOL;
        $html_head .= "<meta name=\"apple-mobile-web-app-status-bar-style\" content=\"black-translucent\">" . PHP_EOL;
        $html_head .= "<meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\">" . PHP_EOL;
        $html_head .= "<meta name=\"x-ua-compatible\" content=\"IE=edge\">" . PHP_EOL;
        $html_head .= "<meta name=\"referrer\" content=\"no-referrer\">" . PHP_EOL;
        
        $html_head .= "<script type=\"application/ld+json\">" . PHP_EOL;
        $html_head .= json_encode([
            "@context" => "https://schema.org",
            "@type" => self::$head['type'] ?: 'article',
            "headline" => self::$head['title'],
            "description" => self::$head['description'],
            "image" => self::$head['image'],
            "author" => [
                "@type" => "Person",
                "name" => self::$head['author'],
            ],
            "publisher" => [
                "@type" => "Organization",
                "name" => self::$head['name'],
                "logo" => [
                    "@type" => "ImageObject",
                    "url" => self::$head['icon_192']
                ]
            ],
            "datePublished" => self::$head['published'],
            "dateModified" => self::$head['modified'],
            "articleSection" => self::$head['category'],
            "keywords" => self::$head['keywords'],
            "mainEntityOfPage" => [
                "@type" => "WebPage",
                "@id" => self::$head['url']
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $html_head .= PHP_EOL . "</script>" . PHP_EOL;


        if (!empty(self::$head['streamui']) && !empty(self::$head['streampath']) && self::$head['streamui'] === true) {
            $strm = '';
            if (defined('STREAMUI')) {
                $strm = STREAMUI;
            } elseif (isset($GLOBALS['STREAMUI'])) {
                $strm = $GLOBALS['STREAMUI'];
            }
            $path = '';
            if (defined('STREAMUIPATH')) {
                $path = STREAMUIPATH;
            } elseif (isset($GLOBALS['STREAMUIPATH'])) {
                $path = $GLOBALS['STREAMUIPATH'];
            }
            if (PHDE::getType() === 'html' && !empty($path) && !empty($strm)) {
                $html_head .= "<script id=\"streamui\" root=\"".htmlspecialchars(PHRO::routes($path)['link'], ENT_QUOTES, 'UTF-8')."\" path=\"".self::$head['streampath']."\"  src=\"" . htmlspecialchars(PHRO::routes($path)['link'], ENT_QUOTES, 'UTF-8') . "-lib?path=".self::$head['streampath']. "\"></script>" . PHP_EOL;
            }
        }


        return $html_head;
    }

    public static $tagMap = [
        // Basic text formatting tags
        'p'   => 'p',           // Paragraph
        'b'   => 'b',           // Bold
        'i'   => 'i',           // Italic
        'a'   => 'a',           // Anchor (link)
        's'   => 'span',        // Span for inline elements
        'd'   => 'div',         // Division
        'dv'   => 'div',         // Division
        'di'   => 'div',         // Division
        // Headings
        'h1'  => 'h1',          // Heading 1
        'h2'  => 'h2',          // Heading 2
        'h3'  => 'h3',          // Heading 3
        'h4'  => 'h4',          // Heading 4
        'h5'  => 'h5',          // Heading 5
        'h6'  => 'h6',          // Heading 6
        // Lists
        'ul'  => 'ul',          // Unordered list
        'ol'  => 'ol',          // Ordered list
        'li'  => 'li',          // List item
        // Interactive elements
        'bt' => 'button',      // Button
        'btn' => 'button',      // Button
        'f'   => 'form',        // Form
        'in' => 'input',       // Input field
        'ipt' => 'input',       // Input field
        'slc' => 'select',      // Select dropdown
        'opt' => 'option',      // Option within select
        'lbl' => 'label',       // Label for form elements
        // Media
        'img' => 'img',         // Image
        'vid' => 'video',       // Video
        'aud' => 'audio',       // Audio
        // Tables
        'tbl' => 'table',       // Table
        'tr'  => 'tr',          // Table row
        'td'  => 'td',          // Table cell
        'th'  => 'th',          // Table header cell
        // Containers and sections
        'sec' => 'section',     // Section
        'he' => 'header',      // Header
        'fo' => 'footer',      // Footer
        'nav' => 'nav',         // Navigation
        'art' => 'article',     // Article
        'asc' => 'aside',       // Aside or sidebar
        // Forms and controls
        'txt' => 'textarea',    // Text area
        'chb' => 'checkbox',    // Checkbox
        'rdo' => 'radio',       // Radio button
        // Miscellaneous
        'ifr' => 'iframe',      // Inline frame
        'scr' => 'script',      // Script
        'sty' => 'style',       // Style tag for CSS
        'fig' => 'figure',      // Figure container for media
        'figc'=> 'figcaption',  // Figure caption
        'hr'  => 'hr',          // Horizontal rule
        'br'  => 'br',          // Line break
        'bq'  => 'blockquote',  // Blockquote
        'pre' => 'pre',         // Preformatted text
        'cd'  => 'code',        // Code block
    ];

    public static $attributeMap = [
        // Basic HTML attributes
        'i'     => 'id',              // Element ID
        'c'     => 'class',           // CSS class
        's'     => 'style',           // Inline style
        'o'     => 'onclick',         // JavaScript onclick event
        'v'     => 'value',           // Value for inputs
        'n'     => 'name',            // Element name
        'hr'    => 'href',            // Hyperlink reference
        't'     => 'type',            // Input type or button type
        'p'     => 'placeholder',     // Placeholder for inputs
        'd'     => 'disabled',        // Disabled attribute for form elements
        'r'     => 'readonly',        // Read-only attribute
        'a'     => 'alt',             // Alt text for images
        'm'     => 'maxlength',       // Max length for input fields
        'h'     => 'height',          // Element height (alternate shorthand)
        'w'     => 'width',           // Element width (alternate shorthand)
        // Accessibility attributes
        'ar'    => 'aria-role',       // ARIA role for accessibility
        'ar-l'  => 'aria-label',      // Accessible label
        'ar-h'  => 'aria-hidden',     // Accessibility visibility (hidden or visible)
        // Form and Input specific
        'tab'   => 'tabindex',        // Tab order focus control
        'req'   => 'required',        // Required field in forms
        'min'   => 'min',             // Minimum value for inputs
        'max'   => 'max',             // Maximum value for inputs
        'pl'    => 'placeholder',     // Placeholder text for inputs (alternate shorthand)
        'en'    => 'enctype',         // Form encoding type (e.g., multipart/form-data)
        'ac'    => 'autocomplete',    // Auto-completion setting for forms
        // Label associations and relational attributes
        'for'   => 'for',             // Label association with input by ID
        'src'   => 'src',             // Source attribute (e.g., for images, videos)
        'tgt'   => 'target',          // Target for links (_blank, _self, etc.)
        // Media-specific
        'lp'    => 'loop',            // Looping for media elements (e.g., video, audio)
        'ms'    => 'maxlength',       // Maximum text length for inputs (duplicate of 'm')
        // Checkbox, radio, and multiple selection
        'ch'    => 'checked',         // Checkbox/radio checked state
        'mu'    => 'multiple',        // Multiple selection attribute (e.g., for <select>)
        // Textarea and text formatting
        'ra'    => 'rows',            // Number of rows (e.g., for <textarea>)
        'co'    => 'cols',            // Number of columns (e.g., for <textarea>)
        // Draggable and other UI behaviors
        'dr'    => 'draggable',       // Draggable attribute
        'sp'    => 'spellcheck',      // Spellcheck option for text inputs
        // Meta tags and SEO
        'ct'    => 'content',         // Content for <meta> tags (e.g., description, keywords)
        // Additional multimedia and usability attributes
        'ap' => 'autoplay',           // Autoplay media files (e.g., video, audio)
        'cty'   => 'controls',        // Controls attribute for media players
        'dl'    => 'download',        // Download attribute for <a> tags
        'pat'   => 'pattern',         // Regex pattern for input validation
        'fs'    => 'form',            // Form attribute linking elements to forms
        'an'    => 'async',           // Async for scripts
        'df'    => 'defer',           // Defer for scripts
        'sc'    => 'srcset',          // Srcset for responsive images
        'rel'   => 'rel',             // Relationship attribute (e.g., for links like 'noopener')
        
        // HTMX Directives
        'hx-g'  => 'hx-get',
        'hx-p'  => 'hx-post',
        'hx-t'  => 'hx-target',
        'hx-s'  => 'hx-swap',
        'hx-tr' => 'hx-trigger',
        'hx-i'  => 'hx-indicator',
        'hx-v'  => 'hx-vals',
        'hx-b'  => 'hx-boost',
        'hx-pu' => 'hx-push-url',
        
        // Alpine.js / PHJS Directives
        'x-d'   => 'x-data',
        'x-i'   => 'x-init',
        'x-s'   => 'x-show',
        'x-m'   => 'x-model',
        'x-t'   => 'x-text',
        'x-h'   => 'x-html',
        'x-b'   => 'x-bind',
        'x-o'   => 'x-on',
        'x-f'   => 'x-for',
        'x-if'  => 'x-if',
        'x-tr'  => 'x-transition',
        'x-c'   => 'x-cloak',
        'x-p'   => 'x-persist',
        'x-e'   => 'x-effect',
        'x-r'   => 'x-ref',
    ];

    public static function newHTML($tag = null, $attributes = [], $content = '') {
        if (is_array($tag) && (empty($attributes) && empty($content)) ) {
            return self::singleHTML($tag);
        } elseif (is_string($tag) && !is_array($tag) && empty($attributes) && empty($content)) {
            $decoded = @json_decode($tag, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return self::singleHTML($decoded);
            }
        } elseif (is_array($tag) && empty($attributes) && empty($content)) {
            $tag = $tag[0];
            $attributes = $tag[1] ?? [];
            $content = $tag[2] ?? '';
        } elseif ($tag === null) {
            return new self();
        }

        $fullTag = self::$tagMap[$tag] ?? $tag;
    
        $attributeString = '';
        if (is_array($attributes)) {
            foreach ($attributes as $key => $value) {
                $fullKey = self::$attributeMap[$key] ?? $key;
                $attributeString .= " $fullKey=\"" . htmlspecialchars((string)$value) . "\"";
            }
        }
    
        if (empty($attributes['id']) && !isset($attributes['i']) && $fullTag !== 'br') {
            $attributeString .= ' id="' . self::generateId($fullTag, $attributeString, $content) . '"';
        }
    
        if (is_array($content)) {
            $contentHtml = '';
            foreach ($content as $child) {
                if (is_string($child)) {
                    $contentHtml .= $child;
                } elseif (is_array($child)) {
                    $tag = $child[0] ?? '';
                    $attributes = $child[1] ?? [];
                    $childContent = $child[2] ?? '';
                    $contentHtml .= self::newHTML($tag, $attributes, $childContent);
                }
            }
            $content = $contentHtml;
        }
    
        return "<$fullTag$attributeString>$content</$fullTag>" . PHP_EOL;
    }

    public static function singleHTML($html = []) {
        if (!empty($html) && is_array($html) && isset($html[0])) {
            return self::newHTML(...$html);
        }
        return '';
    }

    public static function mergeHTML(array $htmlParts) {
        $mergedContent = '';
        foreach ($htmlParts as $part) {
            $mergedContent .= $part;
        }
        return $mergedContent;
    }

    public static function p2j($php, $json = true) {
        if (empty($php)) {
            return null;
        }
        if ($json === true) {
            return json_encode($php, true);
        } else {
            return json_encode($php);
        }
    }
    
    public static function h2p($html, $json = true, $echo = false, $pre = false) {
        if (empty($html)) {
            return null;
        }
        $dom = new DOMDocument();

        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $root = $dom->documentElement;
    
        $html = self::convertNodeToArray($root);
        if ($json === true) {
            if ($echo === true) {
                if ($pre === true) {
                    echo '<pre>';
                    echo json_encode($html, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    echo '</pre>';
                } else {
                    echo json_encode($html, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }
            } else {
                return json_encode($html, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        } else {
            if ($echo === true) {
                if ($pre === true) {
                    echo '<pre>';
                    print_r($html);
                    echo '</pre>';
                } else {
                    print_r($html);
                }
            } else {
                return $html;
            }
        }
    }
    
    private static function convertNodeToArray($node) {
        $tag = $node->nodeName;
    
        $attributes = [];
        if ($node->hasAttributes()) {
            foreach ($node->attributes as $attr) {
                $attributes[$attr->name] = $attr->value;
            }
        }
    
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $children[] = trim($child->nodeValue);
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $children[] = self::convertNodeToArray($child);
            }
        }
    
        if (count($children) === 1 && is_string($children[0])) {
            $children = $children[0];
        }
    
        return [$tag, $attributes, $children];
    }

    public static function css($rules) {
        self::$css .= "<style>" . $rules . "</style>" . PHP_EOL;
        return ['status' => 'success', 'msg' => 'CSS added successfully.'];
    }

    public static function countElements($input) {
        if (is_array($input)) {
            return array_reduce($input, function($carry, $item) {
                return $carry + self::countElements($item);
            }, 0);
        } elseif (is_string($input)) {
            return strlen($input);
        } elseif (is_int($input) || is_float($input)) {
            return (int)(strlen((string)$input));
        } elseif (is_null($input)) {
            return 3;
        }
        return 4;
    }    

    public static function generateId($fullTag, $attributeString, $content) {
        $normalizedTag = strtolower(preg_replace('/[^a-z0-9]+/', '', (string)$fullTag));
    
        $firstChar = substr($normalizedTag, 0, 1);
        $firstCharNumber = ord($firstChar) - ord('a') + 1;
    
        $normalizedC = self::flattenContent($content);
    
        $normalizedC = strtolower(preg_replace('/[^a-z0-9]+/', '', $normalizedC));
    
        $lastChar = substr($normalizedC, 0, 1);
        $lastCharNumber = ord($lastChar) - ord('a') + 1;
    
        $attributeCount = self::countElements($attributeString);
        $contentCount = self::countElements($content);
    
        $firstCountChar = $attributeCount > 0 ? chr(96 + min($attributeCount, 26)) : 'a';
        $secondCountChar = $contentCount > 0 ? chr(96 + min($contentCount, 26)) : 'a';
    
        $totalCount = (int)($attributeCount * $contentCount) - (int)($firstCharNumber + $lastCharNumber) / 9;
        $uniqueId = $normalizedTag . $lastChar . (int)$totalCount . $firstCountChar . $secondCountChar;
        return $uniqueId . self::countElements($uniqueId);
    }
    
    private static function flattenContent($content) {
        $result = '';
    
        if (is_array($content)) {
            foreach ($content as $item) {
                if (is_array($item)) {
                    $result .= self::flattenContent($item);
                } elseif (is_string($item) || is_numeric($item)) {
                    $result .= $item;
                }
            }
        } else {
            $result = (string)$content;
        }
    
        return $result;
    }    

    public static function import($type, $source, $location = 'head', $version = null) {
        $type = strtolower($type);
        $location = strtolower($location);

        $headAliases = ['head', 'header', 'h', 'top', 't', 'first', 'f'];
        $bodyAliases = ['body', 'b', 'end', 'e', 'last', 'l', 'down', 'd'];

        if (in_array($location, $headAliases)) {
            $location = 'head';
        } elseif (in_array($location, $bodyAliases)) {
            $location = 'body';
        } else {
            throw new InvalidArgumentException("Invalid location: $location");
        }

        $tag = '';
        $isCode = false;

        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $isCode = false;
        } elseif (file_exists($source)) {
            $source = realpath($source) ?: $source;
            $isCode = false;
        } else {
            $isCode = (bool)preg_match('/[\s<>{}\n\r;]/', trim($source));
        }

        if (!$isCode && $version !== null) {
            $source .= (strpos($source, '?') === false ? '?' : '&') . 'v=' . $version;
        }

        switch ($type) {
            case 'script':
            case 's':
            case 'js':
            case 'j':
                if ($isCode) {
                    $tag = "<script>\n    " . trim($source) . "\n</script>";
                } else {
                    $tag = "<script src=\"$source\"";
                    if ($type === 'module' || $type === 'm') {
                        $tag .= " type=\"module\"";
                    }
                    $tag .= "></script>";
                }
                break;

            case 'link':
            case 'l':
            case 'css':
            case 'c':
                if ($isCode) {
                    $tag = "<style>\n    " . trim($source) . "\n</style>";
                } else {
                    $tag = "<link rel=\"stylesheet\" href=\"$source\">";
                }
                break;

            case 'font':
            case 'f':
                if ($isCode) {
                    throw new InvalidArgumentException("Fonts cannot be provided as inline code.");
                }
                $tag = "<link href=\"$source\" rel=\"stylesheet\">";
                break;

            case 'icon':
            case 'favicon':
                if ($isCode) {
                    throw new InvalidArgumentException("Icons cannot be provided as inline code.");
                }
                $tag = "<link rel=\"icon\" href=\"$source\">";
                break;

            case 'meta':
                if ($isCode) {
                    $tag = trim($source);
                } else {
                    throw new InvalidArgumentException("Meta tags must be provided as code.");
                }
                break;

            default:
                throw new InvalidArgumentException("Unsupported type: $type");
        }

        $formattedTag = "    $tag\n";
        if ($location === 'head') {
            self::$headPart .= $formattedTag;
        } else {
            self::$bodyPart .= $formattedTag;
        }

        return $formattedTag;
    }      

    public static function header($content) {
        self::$header .= $content . PHP_EOL;
        return ['status' => 'success', 'msg' => 'Header added successfully.'];
    }

    public static function body($content, $bodyPram = []) {
        self::$bodyPram = array_merge(self::$bodyPram ?? [], $bodyPram);
        self::$body .= $content . PHP_EOL;
        return ['status' => 'success', 'msg' => 'HTML added successfully.'];
    }




    
    public static function streamJS($thisPath = '', $stream = '', $rootPath = '') {
$streamJS = <<<'JS'
let virtualDOM = null; // Virtual DOM Storage
let activeMainScript; // Track the active main script element
let abortController; // Controller for aborting ongoing requests
const rootPath = "__ROOTPLACEHOLDER__";
const thisPath = "__PATHPLACEHOLDER__";
const streamPath = "__STREAMPLACEHOLDER__";

// Initialize the EventSource
const eventSource = new EventSource(streamPath + "?path=" + thisPath);

// Capture the full DOM structure as Virtual DOM
function captureVirtualDOM(node) {
    return {
        nodeType: node.nodeType,
        tagName: node.tagName,
        attributes: node.nodeType === Node.ELEMENT_NODE
            ? Array.from(node.attributes).map(attr => ({ name: attr.name, value: attr.value }))
            : [],
        content: node.nodeType === Node.TEXT_NODE ? node.nodeValue : null,
        children: node.nodeType === Node.ELEMENT_NODE
            ? Array.from(node.childNodes).map(captureVirtualDOM)
            : [],
    };
}

// Apply Virtual DOM updates to the actual DOM
function applyVirtualDOMUpdates(currentNode, newVNode, oldVNode) {
    if (currentNode.nodeType !== newVNode.nodeType || currentNode.tagName !== newVNode.tagName) {
        const newDomNode = createNodeFromVNode(newVNode);
        currentNode.replaceWith(newDomNode);
        return newDomNode;
    }

    if (newVNode.nodeType === Node.TEXT_NODE) {
        if (oldVNode.content !== newVNode.content) {
            currentNode.nodeValue = newVNode.content;
        }
        return currentNode;
    }

    if (newVNode.nodeType === Node.ELEMENT_NODE) {
        syncAttributes(currentNode, newVNode, oldVNode);

        const currentChildren = Array.from(currentNode.childNodes);
        const newChildren = newVNode.children;
        const oldChildren = oldVNode.children || [];

        const maxLength = Math.max(currentChildren.length, newChildren.length);
        for (let i = 0; i < maxLength; i++) {
            const currentChild = currentChildren[i];
            const newChild = newChildren[i];
            const oldChild = oldChildren[i];

            if (!newChild && currentChild) {
                currentNode.removeChild(currentChild);
            } else if (!currentChild) {
                currentNode.appendChild(createNodeFromVNode(newChild));
            } else {
                applyVirtualDOMUpdates(currentChild, newChild, oldChild || {});
            }
        }
    }
}

// Sync attributes between current and new virtual nodes
function syncAttributes(currentNode, newVNode, oldVNode) {
    const oldAttributes = oldVNode.attributes.reduce((map, attr) => {
        map[attr.name] = attr.value;
        return map;
    }, {});
    const newAttributes = newVNode.attributes.reduce((map, attr) => {
        map[attr.name] = attr.value;
        return map;
    }, {});

    newVNode.attributes.forEach(attr => {
        if (oldAttributes[attr.name] !== attr.value) {
            currentNode.setAttribute(attr.name, attr.value);
        }
    });

    oldVNode.attributes.forEach(attr => {
        if (!(attr.name in newAttributes)) {
            currentNode.removeAttribute(attr.name);
        }
    });
}

// Create a new DOM Node from a Virtual DOM Node
function createNodeFromVNode(vNode) {
    if (vNode.nodeType === Node.TEXT_NODE) {
        return document.createTextNode(vNode.content);
    }
    if (vNode.nodeType === Node.ELEMENT_NODE) {
        const newNode = document.createElement(vNode.tagName);
        vNode.attributes.forEach(attr => newNode.setAttribute(attr.name, attr.value));
        vNode.children.forEach(childVNode => newNode.appendChild(createNodeFromVNode(childVNode)));
        return newNode;
    }
    return null;
}

// Terminate the currently active main script
function terminateMainScript() {
    // console.log('Terminating active "main" script.');

    if (abortController) {
        abortController.abort(); // Abort any ongoing requests
        // console.log('Aborted ongoing requests for "main" script.');
    }

    if (activeMainScript && activeMainScript.parentNode) {
        activeMainScript.parentNode.removeChild(activeMainScript); // Remove the script element
        // console.log('Removed the active "main" script element.');
    }

    // Optional: Manual cleanup of global variables or states using the script
    if (typeof cleanupGlobals === 'function') {
        cleanupGlobals(); // Call a cleanup function if defined
    }

    activeMainScript = null; // Reset active script reference
    abortController = null; // Reset abort controller reference
}

// Function to clean up global resources (like intervals, event listeners, etc.)
function cleanUpGlobalResources() {
    // Replace this with the actual cleanup code relevant to your "main" script.
    // For example, if your "main" script sets intervals:
    const activeIntervals = document.querySelectorAll('.active-interval'); // Assume you have a way to identify active intervals
    activeIntervals.forEach(interval => clearInterval(interval));

    // If there are global variables, you might reset or delete them here.
    // e.g., delete window.someGlobalVariable;
}

// Load the main script from the given URL
async function loadMainScript(scriptSrc) {
    // console.log(`Loading script from: ${scriptSrc}`);

    // Ensure previous script is stopped before loading a new one
    terminateMainScript(); 

    activeMainScript = document.createElement('script');
    activeMainScript.src = scriptSrc;
    activeMainScript.id = 'main';

    // Create a new AbortController for the current load
    abortController = new AbortController();

    activeMainScript.onload = () => {
        // console.log(`Successfully loaded script from: ${scriptSrc}`);
    };

    activeMainScript.onerror = () => {
        // console.error(`Failed to load script from: ${scriptSrc}`);
        activeMainScript = null; // Reset if loading fails
    };

    // Append to head and trigger loading
    document.head.appendChild(activeMainScript);
}

// Process incoming updates from the EventSource
eventSource.onmessage = function (event) {
    try {
        const parser = new DOMParser();
        const liveData = event.data.trim();
        const incomingDoc = parser.parseFromString(liveData, 'text/html');

        if (!incomingDoc || incomingDoc.querySelector('parsererror')) {
            return;
        }

        const newVirtualDOM = captureVirtualDOM(incomingDoc.documentElement);
        if (virtualDOM) {
            applyVirtualDOMUpdates(document.documentElement, newVirtualDOM, virtualDOM);
        }

        virtualDOM = JSON.parse(JSON.stringify(newVirtualDOM));

        // Load or reload the main script
        const mainScriptSrc = rootPath+'/app.js?path='+thisPath;
        loadMainScript(mainScriptSrc);
    } catch (error) {
        // console.error('Error processing incoming data:', error);
    }
};

// Load the worker script when the DOM is fully loaded
document.addEventListener('DOMContentLoaded', () => {
    virtualDOM = captureVirtualDOM(document.documentElement);
    loadMainScript(rootPath+'/app.js?path='+thisPath); // Load the main script initially
});

// Handle EventSource Errors
eventSource.onerror = function (error) {
    // console.error('EventSource error:', error);
};

// Example of how you might structure a cleanup in the main script
// This should be defined inside the main script for cleanup to work effectively
// It can be as simple or as complex as your main script's logic requires.
function cleanupGlobals() {
    // Automatically remove event listeners from all elements
    const allElements = document.querySelectorAll('*');

    allElements.forEach(element => {
        // Clone the element to remove event listeners
        const newElement = element.cloneNode(true);

        // Replace the old element with the new one in the DOM
        element.parentNode.replaceChild(newElement, element);
    });

    // Automatically clear global variables (example with a common pattern)
    for (const key in window) {
        if (key.startsWith('app_') || key.startsWith('global_')) { // Adjust this to your naming conventions
            delete window[key];
        }
    }

    // Additional global cleanup logic (optional)
    // This could be to reset any states, clean timers, etc.
    // Use a known list of global variables if automatic detection is not sufficient
    const knownGlobals = ['rng', 'currentUser', 'sessionData']; // Add your globals as necessary
    knownGlobals.forEach(globalName => {
        if (typeof window[globalName] !== 'undefined') {
            delete window[globalName];
        }
    });

    // Clear intervals and timeouts if necessary (could be stored in a global array when created)
    const highestTimeoutId = setTimeout(() => {}); // This will get the highest timeout id
    for (let i = 0; i < highestTimeoutId; i++) {
        clearTimeout(i);
    }

    const highestIntervalId = setInterval(() => {}, 1000); // This will get the highest interval id
    for (let i = 0; i < highestIntervalId; i++) {
        clearInterval(i);
    }

    // console.log('Cleanup completed successfully.');
}
JS;
        $streamJS = str_replace('__ROOTPLACEHOLDER__', $rootPath, $streamJS);
        $streamJS = str_replace('__PATHPLACEHOLDER__', $thisPath, $streamJS);
        $streamJS = str_replace('__STREAMPLACEHOLDER__', $stream, $streamJS);
        return $streamJS;
    }


    public static function newJS($js) {
        if (is_array($js)) {
            foreach ($js as $line) {
                self::$js .= $line . PHP_EOL;
            }
        } elseif (is_string($js)) {
            self::$js .= $js . PHP_EOL;
        } else {
            throw new InvalidArgumentException('Input must be a string or an array.');
        }
        return self::$js;
    }    

    public static function phjs($js): void {
        $code = is_array($js) ? implode(PHP_EOL, $js) : $js;
        self::$js .= "APP.ready(() => {" . PHP_EOL . $code . PHP_EOL . "});" . PHP_EOL;
    }

    public static function use(string|array $libs): void {
        $libs = (array) $libs;
        $map = [
            'alpine' => ['type' => 'script', 'src' => 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js', 'attr' => 'defer'],
            'htmx'   => ['type' => 'script', 'src' => 'https://unpkg.com/htmx.org@1.9.12'],
            'phjs'   => ['type' => 'script', 'src' => 'phjs.js'],
            'animate' => ['type' => 'link', 'src' => 'https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css']
        ];
        
        foreach ($libs as $lib) {
            $lib = strtolower($lib);
            if (isset($map[$lib])) {
                $item = $map[$lib];
                if ($item['type'] === 'script') {
                    $tag = "<script src=\"{$item['src']}\"" . (isset($item['attr']) ? " {$item['attr']}" : "") . "></script>\n";
                    self::$headPart .= "    " . $tag;
                } else {
                    self::import($item['type'], $item['src']);
                }
            }
        }
    }

    public static function render_h(): string {
        return '<head>' . PHP_EOL . self::buildHead() . PHP_EOL . self::$header . PHP_EOL . self::$css . PHP_EOL . self::$headPart . PHP_EOL . '</head>' . PHP_EOL; // .'<script id="streamJS">//streamJS'  . PHP_EOL . self::streamJS() . '</script>'
    }

    public static function render_c(): string {
        return self::$css;
    }

    public static function render_b(): string {
        $jss = self::render_j(false);
        if (empty($jss)) {
            $jss = "<noscript>";
        } else {
            // $jss = '<script id="main">//PHJC' . PHP_EOL . $jss . PHP_EOL . '</script>'; //. self::streamJS() 
            
            // $jss = '<script id="main" src="http://192.168.1.10/main/app.js?path=/html"></script>';
        }
        $pram = '';
        foreach (self::$bodyPram as $key => $value) {
            $pram .= $key . '="' . $value . '" ';
        }
        $pram = rtrim($pram);
        return '<body' . (!empty($pram) ? ' ' . $pram : '') . '>' . PHP_EOL . self::$body . PHP_EOL . self::$bodyPart . PHP_EOL . '</body>';
        // return '<body>' . PHP_EOL . self::$body . PHP_EOL . '</body>'; // PHP_EOL . $jss . 
    }

    public static function render_j($state = true) {
        if ($state) {
            if (self::$js) {
                echo '<script id="main">//PHJC' . PHP_EOL . self::$js . PHP_EOL . '</script>';
            } else {
                echo "<noscript>";
            }
        } else {
           return self::$js; 
        }
    }

    public static function app(string $stream, callable $producer) {
        PHRO::get($stream, function($data) use ($producer) {
            PHRQ::header('GET', '*', 'application/javascript; charset=utf-8', []);
            $result = $producer($data);
            if ($result instanceof \Stringable || is_scalar($result)) {
                echo (string) $result;
                return;
            }
            if ($result !== null) {
                throw new \UnexpectedValueException(
                    'PHJC::app producer must return a string, scalar, Stringable, or null.'
                );
            }
        });
    }

    public static function render(): string {
        $bodyContent = self::$body;

        // ---- NEW CODE FOR CSS/JS FILE CACHE ----
        $routeName = '';
        if (class_exists('PHRO') && is_callable(['PHRO', 'route'])) {
            $routeInfo = PHRO::route();
            if (!empty($routeInfo['name'])) $routeName = $routeInfo['name'];
        }
        if (empty($routeName)) {
            $uri = $_SERVER['REQUEST_URI'] ?? 'index';
            $routeName = (string) parse_url($uri, PHP_URL_PATH);
        }
        $routeName = self::normalizeCacheName($routeName);
        
        $cacheDir = self::getPath('cache');
        $cssCacheDir = $cacheDir . '/css';
        $jsCacheDir = $cacheDir . '/js';
        foreach ([$cssCacheDir, $jsCacheDir] as $assetCacheDir) {
            if (
                !is_dir($assetCacheDir) &&
                !mkdir($assetCacheDir, 0755, true) &&
                !is_dir($assetCacheDir)
            ) {
                throw new \RuntimeException("Unable to create asset cache directory: {$assetCacheDir}");
            }
        }
        
        $docRoot = str_replace(['\\', '//'], '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
        $cachePathNormalized = str_replace(['\\', '//'], '/', rtrim($cacheDir, '/'));
        $publicUrl = str_replace($docRoot, '', $cachePathNormalized);
        $publicUrl = '/' . ltrim($publicUrl, '/');

        $cssFile = $cssCacheDir . '/' . $routeName . '.css';
        $jsFile = $jsCacheDir . '/' . $routeName . '.js';

        // CSS Cache
        $rawCss = trim(preg_replace('/<\/?style[^>]*>/i', '', self::$css));
        if ($rawCss !== '') {
            $v = md5($rawCss);
            $writeCss = true;
            if (file_exists($cssFile) && md5_file($cssFile) === $v) {
                $writeCss = false;
            }
            if ($writeCss) {
                if (file_put_contents($cssFile, $rawCss, LOCK_EX) === false) {
                    throw new \RuntimeException("Unable to write CSS cache file: {$cssFile}");
                }
            }
            self::$headPart .= "    <link rel=\"stylesheet\" href=\"{$publicUrl}/css/{$routeName}.css?v={$v}\">\n";
            self::$css = ''; 
        } else {
            if (file_exists($cssFile)) @unlink($cssFile);
            self::$css = '';
        }

        // JS Cache
        $rawJs = trim(self::$js);
        if ($rawJs !== '') {
            $v = md5($rawJs);
            $writeJs = true;
            if (file_exists($jsFile) && md5_file($jsFile) === $v) {
                $writeJs = false;
            }
            if ($writeJs) {
                if (file_put_contents($jsFile, $rawJs, LOCK_EX) === false) {
                    throw new \RuntimeException("Unable to write JavaScript cache file: {$jsFile}");
                }
            }
            self::$bodyPart .= "    <script id=\"main\" src=\"{$publicUrl}/js/{$routeName}.js?v={$v}\" defer></script>\n";
            self::$js = ''; 
        } else {
            if (file_exists($jsFile)) @unlink($jsFile);
            self::$js = '';
        }
        // ---- END NEW CODE ----
        
        // Smart Document Merge: ইউজার যদি লেআউটে <html> <head> দিয়ে থাকে
        if (stripos($bodyContent, '<html') !== false && stripos($bodyContent, '</head>') !== false) {
            
            // PHJC এর জেনারেট করা মেটা, টাইটেল এবং CSS
            $headContent = self::buildHead() . PHP_EOL . self::$header . PHP_EOL . self::$css . PHP_EOL . self::$headPart;
            
            // ইউজারের </head> এর ঠিক আগে PHJC এর ট্যাগগুলো বসিয়ে দেওয়া হলো
            $bodyContent = str_ireplace('</head>', $headContent . PHP_EOL . '</head>', $bodyContent);
            
            // ইউজারের </body> এর ঠিক আগে PHJC এর স্ক্রিপ্টগুলো বসিয়ে দেওয়া হলো
            $bodyContent = str_ireplace('</body>', self::$bodyPart . PHP_EOL . '</body>', $bodyContent);
            
            $html = $bodyContent;
        } else {
            // যদি ইউজার <html> না দেয়, তাহলে PHJC নিজে বানিয়ে নেবে
            $html = '<!DOCTYPE html>' . PHP_EOL . '<html lang="'. (self::$head['lang'] ?? 'en') .'">' . PHP_EOL . self::render_h() . PHP_EOL . self::render_b() . PHP_EOL . '</html>';
        }

        if (self::$minify) {
            $search = ['/(\n|^)(\x20+|\t)/', '/(\n|^)\s*(?=\<)/', '/\>\s+\</', '/\s{2,}/'];
            $replace = ["\n", "\n", '><', ' '];
            $html = preg_replace($search, $replace, $html);
        }
        
        self::reset();
        return $html;
    }



    
    // JavaScript function mappings
    private static array $functionMappings = [
        'byName' => 'document.getElementsByName',
        'byID' => 'document.getElementById',
        'byTag' => 'document.getElementsByTagName',
        'byClass' => 'document.getElementsByClassName',
        'bySel' => 'document.querySelector',
        'bySelAll' => 'document.querySelectorAll',
        'print' => 'console.log',
        'echo' => 'console.log',
        'log' => 'console.log',
        'warn' => 'console.warn',
        'error' => 'console.error',
        'elseif' => 'else if',
        'foreach' => 'forEach',
        
        // PHJS Shortcuts
        'toast' => 'APP.ui.toast',
        'palette' => 'APP.palette.register',
        'navigate' => 'APP.navigate',
        'store' => 'APP.store',
        'emit' => 'APP.emit',
        'on' => 'APP.on',
        
        'prevent' => 'event.preventDefault',
        'stop' => 'event.stopPropagation',
        'fetch' => 'fetch',
        'alert' => 'alert',
        'confirm' => 'confirm',
        'prompt' => 'prompt',
        'timeout' => 'setTimeout',
        'interval' => 'setInterval',
    ];

    private static array $events = [
        'click', 'dblclick', 'mouseover', 'mouseout', 'mousemove', 'mousedown', 'mouseup',
        'keydown', 'keyup', 'keypress', 'submit', 'input', 'change', 'focus', 'blur', 'reset',
        'load', 'resize', 'scroll', 'DOMContentLoaded', 'touchstart', 'touchend',
        'dragstart', 'drag', 'dragend', 'drop', 'contextmenu', 'wheel', 'error', 'abort',
        'popstate', 'hashchange', 'offline', 'online'
    ];

    private static array $setout = [
        'display' => 'style.display',
        'html' => 'innerHTML',
        'text' => 'textContent',
        'fontSize' => 'style.fontSize',
        'value' => 'value',
        'src' => 'src',
        'href' => 'href',
        'class' => 'className',
        'css' => 'style.cssText',
        'bg' => 'style.backgroundColor',
        'color' => 'style.color',
        'width' => 'style.width',
        'height' => 'style.height',
        'opacity' => 'style.opacity',
    ];

    public function __call(string $name, array $arguments) {
        return self::handleCall($name, $arguments);
    }

    public static function __callStatic(string $name, array $arguments) {
        return self::handleCall($name, $arguments);
    }

    private static function handleCall(string $name, array $arguments): string {
        if (isset(self::$functionMappings[$name])) {
            return self::generateFunctionCall(self::$functionMappings[$name], $arguments);
        } elseif (isset(self::$setout[$name])) {
            return self::generatePropertyAccess(self::$setout[$name], $arguments);
        } elseif (in_array($name, self::$events)) {
            return self::generateEventListener($name, $arguments);
        } elseif ($name === 'if') {
            return self::generateIf($arguments);
        } elseif ($name === 'else') {
            return 'else {' . PHP_EOL;
        } elseif ($name === 'elseif') {
            return self::generateElseIf($arguments);
        } elseif ($name === 'function') {
            return self::generateFunction($arguments);
        }
        throw new Exception("Method $name does not exist.");
    }

    private static function generateIf(array $arguments): string {
        [$condition] = $arguments;
        return "if ($condition) {" . PHP_EOL;
    }

    private static function generateElseIf(array $arguments): string {
        [$condition] = $arguments;
        return "else if ($condition) {" . PHP_EOL;
    }

    private static function generateFunction(array $arguments): string {
        $funcName = $arguments[0];
        $params = implode(', ', array_slice($arguments, 1, -1));
        $body = rtrim($arguments[count($arguments) - 1]);
        return "function {$funcName}({$params}) {" . PHP_EOL . $body . PHP_EOL . "}" . PHP_EOL;
    }

    private static function generateFunctionCall(string $function, array $arguments): string {
        $gape = "'";
        $end = "";
        if (($key = array_search('<-call[end]', $arguments)) !== false) {
            unset($arguments[$key]);
            $gape = "";
            $end = ";". PHP_EOL;
        } elseif (($key = array_search('<-call', $arguments)) !== false) {
            unset($arguments[$key]);
            $gape = "";
        } elseif (($key = array_search('[end]', $arguments)) !== false) {
            unset($arguments[$key]);
            $end = ";". PHP_EOL;
        }
        $args = implode('. ', array_map(fn($arg) => is_numeric($arg) ? $arg : $gape . addslashes($arg) . $gape, $arguments));
        return "$function($args)".$end;
    }

    private static function generatePropertyAccess(string $property, array $arguments): string {
        $gape = "'";
        $end = ";". PHP_EOL;
        if (($key = array_search('<-call[end]', $arguments)) !== false) {
            unset($arguments[$key]);
            $gape = "";
            $end = "";
        } elseif (($key = array_search('<-call', $arguments)) !== false) {
            unset($arguments[$key]);
            $gape = "";
        } elseif (($key = array_search('[end]', $arguments)) !== false) {
            unset($arguments[$key]);
            $end = "";
        }
        [$element, $value] = $arguments;
        return "$element.$property = " . (is_numeric($value) ? $value : $gape . addslashes($value) . $gape) . "$end";
    }

    private static function generateEventListener(string $event, array $arguments): string {
        [$element, $handler] = $arguments;
        return "$element.addEventListener('$event', $handler);". PHP_EOL;
    }

    public static function set(string $varName, string $value, string $type = 'var'): string {
        $prefix = match ($type) {
            'const' => 'const',
            'let' => 'let',
            'var' => 'var',
            default => 'const'
        };
        return trim("if (typeof $varName === 'undefined') {". PHP_EOL ."$prefix $varName = $value;". PHP_EOL . "}". PHP_EOL);
    }

    public static function op(string $f1, string $op, string $f2): string {
        return $f1 . $op . $f2;
    }

    public static function get(string ...$varNames): string {
        return implode('.', $varNames);
    }

    public static function endFun(): string {
        return "}" . PHP_EOL;
    }

    public static function endCod(): string {
        return ";" . PHP_EOL;
    }
}
?>
