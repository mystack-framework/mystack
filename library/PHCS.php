<?php

/**
 * ============================================================================
 * Class: PHCS
 * Title: CSS Processing Engine
 * ============================================================================
 * 
 * PHP-native utility CSS processing and build engine. Eliminates the need for Node.js dependencies by dynamically compiling, minifying, and optimizing CSS classes on the fly.
 * 
 * Features:
 * - PHP-native utility-first CSS compilation.
 * - Dynamic class discovery and generation.
 * - Zero Node.js dependency build engine.
 * - Built-in minification and caching.
 * 
 * Usage Example:
 * ```php
 * PHCS::build(); // Normally handled by the framework lifecycle
 * ```
 * 
 * @package    MyStack
 * @author     Sakibur Rahman (@sakibweb)
 * @published  https://github.com/mystack-framework
 * @web        https://mystack-framework.github.io
 * @license    Apache License 2.0
 * ============================================================================
 */



class PHCS {
    /** @var self|null $instance Singleton instance. */
    private static ? self $instance = null;
    private array $config;
    private array $htmlContents = [];
    private array $cssContents = [];
    private array $neededKeyframes = [];
    private array $generatedUtilitySignatures = []; 
    private array $currentGradientStops = [];
    private array $utilityHandlers = [];
    private bool $preflightAdded = false;
    private array $layerCss = ['base' => [], 'components' => [], 'utilities' => []];
    private array $mediaScreens = ['sm' => '640px', 'md' => '768px', 'lg' => '1024px', 'xl' => '1280px', '2xl' => '1536px', 'xxl' => '1536px', 'xxxl' => '1920px', '3xl' => '1920px', 'xxxxl' => '2560px', '4xl' => '2560px'];
    private array $variantOrder = [];
    private array $neededProperties = [];
    private array $bsToTwMap = [];
    private array $dynamicBsToTwPatterns = [];

    public function __construct(array $customConfig = []) {
        $this->loadDefaultConfig();
        $this->config = array_replace_recursive($this->config, $customConfig);

        if (isset($this->config['theme']['extend']) && is_array($this->config['theme']['extend'])) {
            foreach ($this->config['theme']['extend'] as $section => $values) {
                if (isset($this->config['theme'][$section]) && is_array($this->config['theme'][$section])) {
                    $this->config['theme'][$section] = array_replace_recursive($this->config['theme'][$section], $values);
                } else {
                    $this->config['theme'][$section] = $values;
                }
            }
            unset($this->config['theme']['extend']);
        }

        if (isset($this->config['preset']) && isset($this->config['presets'][$this->config['preset']])) {
            $presetName = $this->config['preset'];
            $this->config['theme'] = array_replace_recursive($this->config['theme'], $this->config['presets'][$presetName]);
        }

        $this->bsToTwMap = $this->config['bsToTwMap'] ?? [];
        $this->dynamicBsToTwPatterns = $this->config['dynamicBsToTwPatterns'] ?? [];

        $this->initializeUtilityHandlers();
        $this->initializeVariantOrder();
        if (self::$instance === null) {
            self::$instance = $this;
        }
    }

    private function getClassesFromHtml(string $html): array {
        if (empty(trim($html))) { return []; }
        $allClasses = [];
        $allClassStrings = []; // Fixed: Initialize to prevent undefined variable warning

        // --- ১. DOM Parsing (Exact HTML attributes) ---
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        $query = '//@class | //@*[contains(name(), "class")]';
        $attributeNodes = $xpath->query($query);
        
        if ($attributeNodes) {
            foreach ($attributeNodes as $node) {
                $classString = htmlspecialchars_decode(trim($node->nodeValue));
                $allClassStrings[] = $classString;
            }
        }

        // --- ২. JS/Framework Class Bindings (Vue, React, Alpine, Svelte, Angular) ---
        $pattern = '/(?:class(?:Name)?|:class|x-bind:class|x-transition(?::\w+)*|hx-[-\w]+)\s*=\s*(["\']|{`|{)(.*?)(?:\1|`}|})|\bclass:([a-zA-Z0-9\-_:\[\]\(\)\/.,%]+)|\[class\.([a-zA-Z0-9\-_:\[\]\(\)\/.,%]+)\]|(["\'])([a-zA-Z0-9\-_:\[\]\(\)\/.,%\s]+)\5\s*:\s*(?=[^,}\]]*[,}\]])/is';

        $matches = [];
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        if (!empty($matches)) {
            foreach ($matches as $match) {
                if (isset($match[2])) $allClassStrings[] = $match[2]; // class, className, :class etc.
                if (isset($match[3])) $allClassStrings[] = $match[3]; // class:foo
                if (isset($match[4])) $allClassStrings[] = $match[4]; // [class.foo]
                if (isset($match[6])) $allClassStrings[] = $match[6]; // {'foo': bar}
            }
        }
        
        // --- ৩. HTMX OOB Recursive Parsing ---
        $htmxOobMatches = [];
        if (preg_match_all('/hx-swap-oob="[^"]*"(?:>| \/>)(.*?)<\/[^>]+>/is', $html, $htmxOobMatches) && !empty($htmxOobMatches[1])) {
            foreach ($htmxOobMatches[1] as $oobHtml) {
                $nestedClasses = $this->getClassesFromHtml($oobHtml);
                if (!empty($nestedClasses)) {
                     $allClasses = array_merge($allClasses, $nestedClasses);
                }
            }
        }

        // --- ৪. Broad Scanner Fallback (For Raw JS/PHP strings) ---
        // পিএইচপি/ব্লেড/লজিক মুছে ফেলার পর বাকি সব টেক্সটকে স্ক্যান করা
        $cleanHtmlForBroadScan = preg_replace([
            '/<\?php.*?\?>/s',
            '/@php.*?@endphp/s',
            '/{{.*?}}/s',
            '/{%.*?%}/s',
            '/<!--.*?-->/s',
            '/\/\*.*?\*\//s',
        ], ' ', $html);

        preg_match_all('/[a-zA-Z0-9\-_:\[\]\(\)\/.,%@]+/', $cleanHtmlForBroadScan, $broadMatches);
        if (!empty($broadMatches[0])) {
            foreach ($broadMatches[0] as $candidate) {
                $allClassStrings[] = $candidate;
            }
        }

        // --- ৫. চূড়ান্ত ক্লাস লিস্ট তৈরি এবং পরিষ্কার করা ---
        foreach ($allClassStrings as $classString) {
            // টেমপ্লেটিং লজিক পরিষ্কার করা (PHP, Blade, JS Template Literals)
            $cleaned = preg_replace([
                '/<\?php.*?\?>/s',
                '/@.*?@end\w+/s',
                '/{{\s*.*?}}/s',
                '/\$\{.*?\}/s', // JS template literals ${color}
            ], ' ', $classString);
            
            $parsed = $this->parseClassString($cleaned);

            if (!empty($parsed)) {
                foreach ($parsed as $class) {
                    // ইন্টেলিজেন্ট ক্লিনিং
                    $cleanClass = html_entity_decode($class, ENT_QUOTES, 'UTF-8');
                    $cleanClass = trim($cleanClass, " \t\n\r\0\x0B'\",:{}");
                    $cleanClass = rtrim($cleanClass, ":"); // Fix for JS Object Keys

                    if ($this->isValidClassCandidate($cleanClass)) {
                        $allClasses[] = $cleanClass;
                    }
                }
            }
        }

        return array_values(array_unique($allClasses));
    }

    private function isValidClassCandidate(string $class): bool {
        $len = strlen($class);

        // ক. ন্যূনতম এবং সর্বোচ্চ দৈর্ঘ্য (বেশি বড় টেক্সট বাতিল)
        if ($len < 2 || $len > 150) {
            return false;
        }

        // খ. পরিচিত জাভাস্ক্রিপ্ট অপারেটর এবং কীওয়ার্ড ব্ল্যাকলিস্ট
        $jsBlacklist = [
            '===', '==', '=>', '?', '&&', '||', '!', '!=', '!==', 'true', 'false', 'null', 'undefined',
            'return', 'function', 'import', 'export', 'const', 'let', 'var', 'await', 'async',
            'responseStatus', 'responseMessage', 'success', 'error', 'isActive',
            'is-active', 'is_active'
        ];
        if (in_array($class, $jsBlacklist)) {
            return false;
        }

        // গ. বৈধ অক্ষরের প্যাটার্ন পরীক্ষা (Tailwind Arbitrary সাপোর্ট সহ)
        // Allow single quotes inside the arbitrary value brackets
        if (!preg_match('/^!?([a-zA-Z0-9\-_:\[\]\(\)\/.,%@\']+|\[--[a-zA-Z0-9-]+:.+\])$/', $class)) {
            return false;
        }
        
        // ঘ. ইন্টেলিজেন্ট হিউরিস্টিক: camelCase ভেরিয়েবল সনাক্তকরণ
        if (
            !str_contains($class, '-') &&
            !str_contains($class, ':') &&
            !str_contains($class, '[') &&
            !str_contains($class, '/') &&
            preg_match('/[a-z][A-Z]/', $class)
        ) {
            return false; // JS variable like `isActiveStatus` বাদ পড়বে
        }

        // ঙ. অন্তত একটি ইংরেজি বর্ণ থাকতে হবে (শুধুমাত্র নাম্বার হলে সেটি ক্লাস নয়)
        if (!preg_match('/[a-zA-Z]/', $class)) {
            return false;
        }

        // সব পরীক্ষায় পাস করলে, এটি একটি বৈধ ক্লাস
        return true;
    }

    private function parseClassString(string $classString): array {
        $classes = [];
        $length = strlen($classString);
        $currentClass = '';
        $inBrackets = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $classString[$i];

            if ($char === '[') {
                $inBrackets = true;
            } elseif ($char === ']') {
                $inBrackets = false;
            }

            // যদি স্পেস বা নিউলাইন পাওয়া যায় এবং আমরা কোনো ব্র্যাকেটের ভেতরে না থাকি
            if (preg_match('/\s/', $char) && !$inBrackets) {
                if (!empty($currentClass)) {
                    $classes[] = $currentClass;
                }
                $currentClass = ''; // ক্লাস রিসেট করুন
            } else {
                $currentClass .= $char;
            }
        }

        // শেষ ক্লাসটি যোগ করুন
        if (!empty($currentClass)) {
            $classes[] = $currentClass;
        }

        return $classes;
    }

    private static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function config(array $config): void {
        self::$instance = new self($config);
    }

    public static function HTML(string $htmlContent): void {
        self::getInstance()->htmlContents[] = $htmlContent;
    }

    public function addHtml(string $htmlContent): self {
        $this->htmlContents[] = $htmlContent;
        return $this;
    }

    public static function CSS(string $cssContent): void {
        self::getInstance()->cssContents[] = $cssContent;
    }

    public function addCss(string $cssContent): self {
        $this->cssContents[] = $cssContent;
        return $this;
    }

    public static function process(string $content, string $type = 'html'): string {
        $instance = self::getInstance();
        
        if ($type === 'css') {
            return $instance->processCss($content);
        }
        
        $classes = $instance->getClassesFromHtml($content);
        return $instance->generateCss($classes);
    }

    public static function build(bool $modular = false): string {
        return self::getInstance()->buildCss($modular);
    }

    private function loadDefaultConfig(): void {
        $this->config = [
            'safelist' => [],
            'prefix' => '', 
            'separator' => ':',
            'important' => false, 
            'layers' => ['base', 'components', 'utilities'], // Default layer order
            'forms' => [ 
                'strategy' => 'class', // 'base' or 'class' (class is recommended)
                'classPrefix' => 'form-', // e.g., .form-input, .form-select
                'baseStyles' => true,
                'defaultRingColor' => ['theme' => 'colors.primary.DEFAULT'],
                'defaultBorderColor' => 'hsl(var(--bc) / 0.2)',
                'defaultCheckboxRadioColor' => ['theme' => 'colors.primary.DEFAULT'],
                'classStyles' => [
                    'input' => [
                        'appearance' => 'none',
                        'background-color' => '#fff',
                        'border-color' => ['theme' => 'forms.defaultBorderColor'],
                        'border-width' => '1px',
                        'border-radius' => '0.375rem', // rounded-md
                        'padding' => '0.5rem 0.75rem',
                        'font-size' => '1rem',
                        'line-height' => '1.5rem',
                        '--tw-shadow' => '0 0 #0000',
                        '_focusStyles' => [
                            'outline' => '2px solid transparent',
                            'outline-offset' => '0px',
                            '--tw-ring-color' => ['theme' => 'colors.primary.DEFAULT'],
                            'border-color' => ['theme' => 'colors.primary.DEFAULT'],
                            'box-shadow' => 'var(--tw-ring-inset) 0 0 0 calc(1px + var(--tw-ring-offset-width)) var(--tw-ring-color)',
                        ],
                        '_placeholderStyles' => [
                            'color' => '#6b7280', // gray-500
                            'opacity' => '1',
                        ],
                    ],
                    'textarea' => [
                        // Inherits from 'input' and adds specific styles
                        '_extends' => 'input', 
                        'min-height' => '80px',
                        'resize' => 'vertical',
                    ],
                    'select' => [
                        '_extends' => 'input',
                        'background-image' => "url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e\")",
                        'background-position' => 'right 0.5rem center',
                        'background-repeat' => 'no-repeat',
                        'background-size' => '1.5em 1.5em',
                        'padding-right' => '2.5rem',
                    ],
                    'multiselect' => [
                        '_extends' => 'input',
                        'background-image' => 'none',
                        'padding-right' => '0.75rem',
                    ],
                    'checkbox' => [
                        'appearance' => 'none',
                        'padding' => '0',
                        'display' => 'inline-block',
                        'vertical-align' => 'middle',
                        'height' => '1rem',
                        'width' => '1rem',
                        'flex-shrink' => '0',
                        'color' => ['theme' => 'forms.defaultCheckboxRadioColor'],
                        'background-color' => '#fff',
                        'border-color' => ['theme' => 'forms.defaultBorderColor'],
                        'border-width' => '1px',
                        'border-radius' => '0.25rem', // rounded
                        '_checkedStyles' => [
                            'border-color' => 'transparent',
                            'background-color' => 'currentColor',
                            'background-size' => '100% 100%',
                            'background-position' => 'center',
                            'background-repeat' => 'no-repeat',
                            'background-image' => "url(\"data:image/svg+xml,%3csvg viewBox='0 0 16 16' fill='white' xmlns='http://www.w3.org/2000/svg'%3e%3cpath d='M12.207 4.793a1 1 0 010 1.414l-5 5a1 1 0 01-1.414 0l-2-2a1 1 0 011.414-1.414L6.5 9.086l4.293-4.293a1 1 0 011.414 0z'/%3e%3c/svg%3e\")",
                        ],
                        '_focusStyles' => [
                            'outline' => '2px solid transparent',
                            'outline-offset' => '0px',
                            '--tw-ring-offset-width' => '0px',
                            '--tw-ring-color' => ['theme' => 'forms.defaultCheckboxRadioColor'],
                            'box-shadow' => 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow, 0 0 #0000)',
                        ],
                    ],
                    'radio' => [
                        '_extends' => 'checkbox',
                        'border-radius' => '9999px', // rounded-full
                        '_checkedStyles' => [
                            'background-image' => "url(\"data:image/svg+xml,%3csvg viewBox='0 0 16 16' fill='white' xmlns='http://www.w3.org/2000/svg'%3e%3ccircle cx='8' cy='8' r='3'/%3e%3c/svg%3e\")",
                        ],
                    ],
                ],
            ],
            'typography' => [ // Configuration for @tailwindcss/typography (prose)
                'className' => 'prose', // Default class name for prose styles
                'target' => 'default', // or 'legacy' etc. for different style sets
                'modifiers' => [
                    'sm' => [
                        'css' => [
                            '--tw-prose-font-size' => ['theme' => 'fontSize.sm.0'], // Use theme reference
                            '--tw-prose-line-height' => ['theme' => 'lineHeight.relaxed'],
                        ],
                        'p' => ['marginTop' => '1em', 'marginBottom' => '1em'],
                        'h1' => ['fontSize' => '1.8em', 'lineHeight' => '1.2'],
                        'a' => ['fontWeight' => '500'],
                        'dark' => [
                            'css' => [
                                '--tw-prose-body' => 'var(--tw-prose-invert-body)',
                                '--tw-prose-headings' => 'var(--tw-prose-invert-headings)',
                            ],
                            'a' => ['color' => ['theme' => 'colors.slate.300']],
                        ]
                    ],
                    'lg' => [
                        'css' => [
                            '--tw-prose-font-size' => ['theme' => 'fontSize.lg.0'],
                            '--tw-prose-line-height' => ['theme' => 'lineHeight.normal'],
                        ],
                        'h1' => ['fontSize' => '2.5em', 'lineHeight' => '1.1'],
                    ],
                    'xl' => [
                        'css' => [
                            '--tw-prose-font-size' => ['theme' => 'fontSize.xl.0'],
                            '--tw-prose-line-height' => ['theme' => 'lineHeight.normal'],
                        ],
                        'h1' => ['fontSize' => '2.75em', 'lineHeight' => '1.1'],
                    ],
                    '2xl' => [
                        'css' => [
                            '--tw-prose-font-size' => ['theme' => 'fontSize.2xl.0'],
                            '--tw-prose-line-height' => ['theme' => 'lineHeight.normal'],
                        ],
                        'h1' => ['fontSize' => '3em', 'lineHeight' => '1'],
                    ],
                    'invert' => [
                        'css' => [
                            '--tw-prose-body' => 'var(--tw-prose-invert-body)',
                            '--tw-prose-headings' => 'var(--tw-prose-invert-headings)',
                            '--tw-prose-lead' => 'var(--tw-prose-invert-lead)',
                            '--tw-prose-links' => 'var(--tw-prose-invert-links)',
                            '--tw-prose-bold' => 'var(--tw-prose-invert-bold)',
                            '--tw-prose-counters' => 'var(--tw-prose-invert-counters)',
                            '--tw-prose-bullets' => 'var(--tw-prose-invert-bullets)',
                            '--tw-prose-hr' => 'var(--tw-prose-invert-hr)',
                            '--tw-prose-quotes' => 'var(--tw-prose-invert-quotes)',
                            '--tw-prose-quote-borders' => 'var(--tw-prose-invert-quote-borders)',
                            '--tw-prose-captions' => 'var(--tw-prose-invert-captions)',
                            '--tw-prose-code' => 'var(--tw-prose-invert-code)',
                            '--tw-prose-pre-code' => 'var(--tw-prose-invert-pre-code)',
                            '--tw-prose-pre-bg' => 'var(--tw-prose-invert-pre-bg)',
                            '--tw-prose-th-borders' => 'var(--tw-prose-invert-th-borders)',
                            '--tw-prose-td-borders' => 'var(--tw-prose-invert-td-borders)',
                        ]
                    ],
                ],
                'elements' => [ 
                    'DEFAULT' => [
                        /* Fluid Base Typography */
                        'fontSize' => 'clamp(1rem, 0.91rem + 0.38vw, 1.125rem)', /* auto scales 16px to 18px */
                        'lineHeight' => 'clamp(1.5, 1.4vw + 1.2, 1.75)', /* auto scales line height */
                        'color' => 'var(--tw-prose-body)', 
                        'maxWidth' => '65ch',

                        /* Fluid Spacing for Paragraphs */
                        'p' => ['marginTop' => 'clamp(1em, 1.5vw, 1.25em)', 'marginBottom' => 'clamp(1em, 1.5vw, 1.25em)'],
                        'a' => ['color' => 'var(--tw-prose-links)', 'textDecoration' => 'underline', 'fontWeight' => '500', ':hover' => ['opacity' => '0.8']],
                        'strong' => ['color' => 'var(--tw-prose-bold)', 'fontWeight' => '600'],
                        
                        /* Lists */
                        'ol' => ['listStyleType' => 'decimal', 'marginTop' => 'clamp(1em, 1.5vw, 1.25em)', 'marginBottom' => 'clamp(1em, 1.5vw, 1.25em)', 'paddingLeft' => 'clamp(1.25em, 2vw, 1.625em)'],
                        'ol[type="A"]' => ['listStyleType' => 'upper-alpha'], 
                        'ol[type="a"]' => ['listStyleType' => 'lower-alpha'],
                        'ol[type="I"]' => ['listStyleType' => 'upper-roman'], 
                        'ol[type="i"]' => ['listStyleType' => 'lower-roman'],
                        'ol > li' => ['position' => 'relative', 'paddingLeft' => '0.5em'],
                        'ul' => ['listStyleType' => 'disc', 'marginTop' => 'clamp(1em, 1.5vw, 1.25em)', 'marginBottom' => 'clamp(1em, 1.5vw, 1.25em)', 'paddingLeft' => 'clamp(1.25em, 2vw, 1.625em)'],
                        'ul > li' => ['position' => 'relative', 'paddingLeft' => '0.5em'],
                        
                        /* Divider */
                        'hr' => ['borderColor' => 'var(--tw-prose-hr)', 'borderTopWidth' => '1px', 'marginTop' => 'clamp(2em, 4vw, 3em)', 'marginBottom' => 'clamp(2em, 4vw, 3em)'],
                        
                        /* Blockquote */
                        'blockquote' => ['fontWeight' => '500', 'fontStyle' => 'italic', 'color' => 'var(--tw-prose-quotes)', 'borderLeftWidth' => '0.25rem', 'borderLeftColor' => 'var(--tw-prose-quote-borders)', 'quotes' => '"\\201C""\\201D""\\2018""\\2019"', 'marginTop' => 'clamp(1.2em, 2vw, 1.6em)', 'marginBottom' => 'clamp(1.2em, 2vw, 1.6em)', 'paddingLeft' => '1em'],
                        'blockquote p:first-of-type::before' => ['content' => 'open-quote'],
                        'blockquote p:last-of-type::after' => ['content' => 'close-quote'],
                        
                        /* Fluid Headings (The real magic) */
                        'h1' => ['color' => 'var(--tw-prose-headings)', 'fontWeight' => '800', 'fontSize' => 'clamp(2rem, 1.50rem + 2.50vw, 3rem)', 'marginTop' => '0', 'marginBottom' => '0.88em', 'lineHeight' => '1.1'],
                        'h2' => ['color' => 'var(--tw-prose-headings)', 'fontWeight' => '700', 'fontSize' => 'clamp(1.5rem, 1.25rem + 1.25vw, 2rem)', 'marginTop' => 'clamp(1.5em, 3vw, 2em)', 'marginBottom' => '1em', 'lineHeight' => '1.2'],
                        'h3' => ['color' => 'var(--tw-prose-headings)', 'fontWeight' => '600', 'fontSize' => 'clamp(1.25rem, 1.125rem + 0.625vw, 1.5rem)', 'marginTop' => 'clamp(1.2em, 2vw, 1.6em)', 'marginBottom' => '0.6em', 'lineHeight' => '1.4'],
                        'h4' => ['color' => 'var(--tw-prose-headings)', 'fontWeight' => '600', 'fontSize' => 'clamp(1.125rem, 1.06rem + 0.31vw, 1.25rem)', 'marginTop' => '1.5em', 'marginBottom' => '0.5em', 'lineHeight' => '1.5'],
                        
                        /* Media */
                        'img' => ['marginTop' => 'clamp(1.5em, 3vw, 2em)', 'marginBottom' => 'clamp(1.5em, 3vw, 2em)', 'borderRadius' => 'var(--rounded-box, 0.5rem)'],
                        'video' => ['marginTop' => 'clamp(1.5em, 3vw, 2em)', 'marginBottom' => 'clamp(1.5em, 3vw, 2em)', 'borderRadius' => 'var(--rounded-box, 0.5rem)'],
                        'figure' => ['marginTop' => 'clamp(1.5em, 3vw, 2em)', 'marginBottom' => 'clamp(1.5em, 3vw, 2em)'],
                        'figure > *' => ['marginTop' => '0', 'marginBottom' => '0'],
                        'figcaption' => ['color' => 'var(--tw-prose-captions)', 'fontSize' => '0.875em', 'lineHeight' => '1.4', 'marginTop' => '0.85em'],
                        
                        /* Code & Pre */
                        'code' => ['color' => 'var(--tw-prose-code)', 'fontWeight' => '600', 'fontSize' => '0.875em'],
                        'code::before' => ['content' => '"`"'], 'code::after' => ['content' => '"`"'],
                        'pre' => ['color' => 'var(--tw-prose-pre-code)', 'backgroundColor' => 'var(--tw-prose-pre-bg)', 'overflowX' => 'auto', 'fontSize' => '0.875em', 'lineHeight' => '1.7', 'marginTop' => '1.7em', 'marginBottom' => '1.7em', 'borderRadius' => '0.375rem', 'padding' => 'clamp(0.75rem, 1.5vw, 1.25rem)'],
                        'pre code' => ['backgroundColor' => 'transparent', 'borderWidth' => '0', 'borderRadius' => '0', 'padding' => '0', 'fontWeight' => '400', 'color' => 'inherit', 'fontSize' => 'inherit', 'fontFamily' => 'inherit', 'lineHeight' => 'inherit'],
                        'pre code::before' => ['content' => 'none'], 'pre code::after' => ['content' => 'none'],
                        
                        /* Tables */
                        'table' => ['width' => '100%', 'tableLayout' => 'auto', 'textAlign' => 'left', 'marginTop' => '2em', 'marginBottom' => '2em', 'fontSize' => '0.875em', 'lineHeight' => '1.7'],
                        'thead' => ['color' => 'var(--tw-prose-headings)', 'fontWeight' => '600', 'borderBottomWidth' => '1px', 'borderBottomColor' => 'var(--tw-prose-th-borders)'],
                        'thead th' => ['verticalAlign' => 'bottom', 'paddingRight' => '0.57em', 'paddingBottom' => '0.57em', 'paddingLeft' => '0.57em'],
                        'tbody tr' => ['borderBottomWidth' => '1px', 'borderBottomColor' => 'var(--tw-prose-td-borders)'],
                        'tbody tr:last-child' => ['borderBottomWidth' => '0'],
                        'tbody td' => ['verticalAlign' => 'top', 'padding' => '0.57em'],
                        
                        /* Nested elements logic */
                        'li' => ['marginTop' => '0.5em', 'marginBottom' => '0.5em'],
                        '> ul > li p' => ['marginTop' => '0.75em', 'marginBottom' => '0.75em'],
                        '> ul > li > *:first-child' => ['marginTop' => '1.25em'], '> ul > li > *:last-child' => ['marginBottom' => '1.25em'],
                        '> ol > li > *:first-child' => ['marginTop' => '1.25em'], '> ol > li > *:last-child' => ['marginBottom' => '1.25em'],
                        'ul ul, ul ol, ol ul, ol ol' => ['marginTop' => '0.75em', 'marginBottom' => '0.75em'],
                        'hr + *' => ['marginTop' => '0 !important'], 'h2 + *' => ['marginTop' => '0 !important'],
                        'h3 + *' => ['marginTop' => '0 !important'], 'h4 + *' => ['marginTop' => '0 !important'],

                        // --- Dark mode styles ---
                        'dark' => [ 
                            'color' => 'var(--tw-prose-invert-body)',
                            'a' => ['color' => 'var(--tw-prose-invert-links)'],
                            'strong' => ['color' => 'var(--tw-prose-invert-bold)'],
                            'ol > li::before' => ['color' => 'var(--tw-prose-invert-counters)'],
                            'ul > li::before' => ['backgroundColor' => 'var(--tw-prose-invert-bullets)'],
                            'hr' => ['borderColor' => 'var(--tw-prose-invert-hr)'],
                            'blockquote' => ['color' => 'var(--tw-prose-invert-quotes)', 'borderLeftColor' => 'var(--tw-prose-invert-quote-borders)'],
                            'h1' => ['color' => 'var(--tw-prose-invert-headings)'],
                            'h2' => ['color' => 'var(--tw-prose-invert-headings)'],
                            'h3' => ['color' => 'var(--tw-prose-invert-headings)'],
                            'h4' => ['color' => 'var(--tw-prose-invert-headings)'],
                            'figcaption' => ['color' => 'var(--tw-prose-invert-captions)'],
                            'code' => ['color' => 'var(--tw-prose-invert-code)'],
                            'pre' => ['color' => 'var(--tw-prose-invert-pre-code)', 'backgroundColor' => 'var(--tw-prose-invert-pre-bg)'],
                            'thead' => ['color' => 'var(--tw-prose-invert-headings)', 'borderBottomColor' => 'var(--tw-prose-invert-th-borders)'],
                            'tbody tr' => ['borderBottomColor' => 'var(--tw-prose-invert-td-borders)'],
                        ]
                    ]
                ],
                'cssVariables' => [
                    'DEFAULT' => [ // Default (light mode) prose variables
                        // === 1. Tailwind Typography (Prose) - Light Mode ===
                        '--tw-prose-body' => ['theme' => 'colors.slate.700'],
                        '--tw-prose-headings' => ['theme' => 'colors.slate.900'],
                        '--tw-prose-lead' => ['theme' => 'colors.slate.600'],
                        '--tw-prose-links' => ['theme' => 'colors.blue.600'], // Primary color
                        '--tw-prose-bold' => ['theme' => 'colors.slate.900'],
                        '--tw-prose-counters' => ['theme' => 'colors.slate.500'],
                        '--tw-prose-bullets' => ['theme' => 'colors.slate.400'],
                        '--tw-prose-hr' => ['theme' => 'colors.slate.200'],
                        '--tw-prose-quotes' => ['theme' => 'colors.slate.900'],
                        '--tw-prose-quote-borders' => ['theme' => 'colors.slate.200'],
                        '--tw-prose-captions' => ['theme' => 'colors.slate.500'],
                        '--tw-prose-code' => ['theme' => 'colors.slate.900'],
                        '--tw-prose-pre-code' => ['theme' => 'colors.slate.200'],
                        '--tw-prose-pre-bg' => ['theme' => 'colors.slate.800'],
                        '--tw-prose-th-borders' => ['theme' => 'colors.slate.300'],
                        '--tw-prose-td-borders' => ['theme' => 'colors.slate.200'],

                        // === 2. Tailwind Typography (Prose) - Dark Mode (Invert) ===
                        // Used when .prose-invert is applied or inside dark mode
                        '--tw-prose-invert-body' => ['theme' => 'colors.slate.300'],
                        '--tw-prose-invert-headings' => ['theme' => 'colors.white'],
                        '--tw-prose-invert-lead' => ['theme' => 'colors.slate.400'],
                        '--tw-prose-invert-links' => ['theme' => 'colors.blue.400'],
                        '--tw-prose-invert-bold' => ['theme' => 'colors.white'],
                        '--tw-prose-invert-counters' => ['theme' => 'colors.slate.400'],
                        '--tw-prose-invert-bullets' => ['theme' => 'colors.slate.600'],
                        '--tw-prose-invert-hr' => ['theme' => 'colors.slate.700'],
                        '--tw-prose-invert-quotes' => ['theme' => 'colors.slate.100'],
                        '--tw-prose-invert-quote-borders' => ['theme' => 'colors.slate.700'],
                        '--tw-prose-invert-captions' => ['theme' => 'colors.slate.400'],
                        '--tw-prose-invert-code' => ['theme' => 'colors.white'],
                        '--tw-prose-invert-pre-code' => ['theme' => 'colors.slate.300'],
                        '--tw-prose-invert-pre-bg' => 'rgb(30 41 59 / 50%)', // slate-800 with opacity
                        '--tw-prose-invert-th-borders' => ['theme' => 'colors.slate.600'],
                        '--tw-prose-invert-td-borders' => ['theme' => 'colors.slate.700'],

                        // === 3. Bootstrap Compatibility Variables ===
                        // Ensures BS utilities work correctly inside prose
                        '--bs-body-color' => ['theme' => 'colors.slate.700'],
                        '--bs-body-color-rgb' => '51, 65, 85', // slate-700 rgb
                        '--bs-body-bg' => ['theme' => 'colors.white'],
                        '--bs-body-bg-rgb' => '255, 255, 255',
                        '--bs-link-color' => ['theme' => 'colors.blue.600'],
                        '--bs-link-color-rgb' => '37, 99, 235',
                        '--bs-link-hover-color' => ['theme' => 'colors.blue.700'],
                        '--bs-link-hover-color-rgb' => '29, 78, 216',
                        '--bs-code-color' => ['theme' => 'colors.pink.500'],
                        '--bs-border-color' => ['theme' => 'colors.slate.200'],
                        '--bs-border-color-translucent' => 'rgba(0, 0, 0, 0.175)',

                        // === 4. daisyUI Compatibility Variables ===
                        // Mapping prose colors to daisyUI semantic vars for consistency
                        '--bc' => ['theme' => 'colors.slate.700'], // Base Content
                        '--p'  => ['theme' => 'colors.blue.600'],  // Primary
                        '--b1' => ['theme' => 'colors.white'],     // Base 100
                        '--b2' => ['theme' => 'colors.slate.100'], // Base 200
                        '--b3' => ['theme' => 'colors.slate.200'], // Base 300
                    ]
                ],
            ],
            // Theme presets (example, can be expanded significantly)
            'presets' => [
                'default' => [ // Light Theme (Base)
                    'color-scheme' => 'light',
                    // Base Colors
                    '--background' => ['theme' => 'colors.white'],
                    '--foreground' => ['theme' => 'colors.slate.900'],
                    '--card'       => ['theme' => 'colors.white'],
                    '--card-foreground' => ['theme' => 'colors.slate.900'],
                    '--popover'    => ['theme' => 'colors.white'],
                    '--popover-foreground' => ['theme' => 'colors.slate.900'],
                    '--border'     => ['theme' => 'colors.slate.200'],
                    '--input'      => ['theme' => 'colors.slate.300'],
                    '--ring'       => ['theme' => 'colors.blue.500'],
                    '--radius'     => '0.5rem',
                    
                    // daisyUI Vars
                    '--rounded-box' => '1rem', '--rounded-btn' => '0.5rem', '--rounded-badge' => '1.9rem',
                    '--animation-btn' => '0.25s', '--animation-input' => '0.2s', '--btn-focus-scale' => '0.95',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0.5rem',
                    
                    // daisyUI Colors
                    '--p' => ['theme' => 'colors.blue.600'], '--pf' => ['theme' => 'colors.blue.700'], '--pc' => ['theme' => 'colors.slate.50'],
                    '--s' => ['theme' => 'colors.slate.600'], '--sf' => ['theme' => 'colors.slate.700'], '--sc' => ['theme' => 'colors.slate.50'],
                    '--a' => ['theme' => 'colors.pink.500'], '--af' => ['theme' => 'colors.pink.600'], '--ac' => ['theme' => 'colors.slate.50'],
                    '--n' => ['theme' => 'colors.slate.100'], '--nf' => ['theme' => 'colors.slate.100'], '--nc' => ['theme' => 'colors.slate.900'],
                    '--b1' => ['theme' => 'colors.white'], '--b2' => ['theme' => 'colors.slate.100'], '--b3' => ['theme' => 'colors.slate.200'], '--bc' => ['theme' => 'colors.slate.900'],
                    '--in' => ['theme' => 'colors.sky.500'], '--inc' => ['theme' => 'colors.white'],
                    '--su' => ['theme' => 'colors.green.600'], '--suc' => ['theme' => 'colors.white'],
                    '--wa' => ['theme' => 'colors.amber.500'], '--wac' => ['theme' => 'colors.slate.900'],
                    '--er' => ['theme' => 'colors.red.600'], '--erc' => ['theme' => 'colors.white'],

                    // Semantic Colors
                    '--primary' => ['theme' => 'colors.blue.600'], '--primary-hover' => ['theme' => 'colors.blue.700'], '--primary-foreground' => ['theme' => 'colors.slate.50'],
                    '--secondary' => ['theme' => 'colors.slate.700'], '--secondary-hover' => ['theme' => 'colors.slate.800'], '--secondary-foreground' => ['theme' => 'colors.slate.50'],
                    '--accent' => ['theme' => 'colors.pink.500'], '--accent-hover' => ['theme' => 'colors.pink.600'], '--accent-foreground' => ['theme' => 'colors.slate.50'],
                    '--success' => ['theme' => 'colors.green.600'], '--success-hover' => ['theme' => 'colors.green.700'], '--success-foreground' => ['theme' => 'colors.white'],
                    '--warning' => ['theme' => 'colors.amber.500'], '--warning-hover' => ['theme' => 'colors.amber.600'], '--warning-foreground' => ['theme' => 'colors.slate.900'],
                    '--info' => ['theme' => 'colors.sky.500'], '--info-hover' => ['theme' => 'colors.sky.600'], '--info-foreground' => ['theme' => 'colors.white'],
                    '--danger' => ['theme' => 'colors.red.500'], '--danger-hover' => ['theme' => 'colors.red.600'], '--danger-foreground' => ['theme' => 'colors.slate.50'],
                    '--error' => ['theme' => 'colors.red.500'], '--error-hover' => ['theme' => 'colors.red.600'], '--error-foreground' => ['theme' => 'colors.slate.50'],
                    '--destructive' => ['theme' => 'colors.red.500'], '--destructive-hover' => ['theme' => 'colors.red.600'], '--destructive-foreground' => ['theme' => 'colors.slate.50'],
                    '--muted' => ['theme' => 'colors.slate.300'], '--muted-hover' => ['theme' => 'colors.slate.400'], '--muted-foreground' => ['theme' => 'colors.slate.500'],

                    // Prose
                    '--tw-prose-body' => ['theme' => 'colors.slate.700'], '--tw-prose-headings' => ['theme' => 'colors.slate.900'], '--tw-prose-lead' => ['theme' => 'colors.slate.600'],
                    '--tw-prose-links' => ['theme' => 'colors.blue.700'], '--tw-prose-bold' => ['theme' => 'colors.slate.900'], '--tw-prose-counters' => ['theme' => 'colors.slate.500'],
                    '--tw-prose-bullets' => ['theme' => 'colors.slate.400'], '--tw-prose-hr' => ['theme' => 'colors.slate.200'], '--tw-prose-quotes' => ['theme' => 'colors.slate.900'],
                    '--tw-prose-quote-borders' => ['theme' => 'colors.slate.200'], '--tw-prose-captions' => ['theme' => 'colors.slate.500'], '--tw-prose-code' => ['theme' => 'colors.slate.900'],
                    '--tw-prose-pre-code' => ['theme' => 'colors.slate.200'], '--tw-prose-pre-bg' => ['theme' => 'colors.slate.800'],
                    '--tw-prose-th-borders' => ['theme' => 'colors.slate.300'], '--tw-prose-td-borders' => ['theme' => 'colors.slate.200'],
                    
                    // Glass
                    '--glass-bg-base' => ['raw' => '255 255 255'], '--glass-border-base' => ['raw' => '229 231 235'],
                    '--glass-bg-dark-base' => ['raw' => '30 41 59'], '--glass-border-dark-base' => ['raw' => '51 65 85'],
                    '--glass-bg-primary' => ['raw' => '59 130 246'], '--glass-border-primary' => ['raw' => '37 99 235'],
                ],
                'light' => [ // Light Theme (Base)
                    'color-scheme' => 'light',
                    // Base Colors
                    '--background' => ['theme' => 'colors.white'],
                    '--foreground' => ['theme' => 'colors.slate.900'],
                    '--card'       => ['theme' => 'colors.white'],
                    '--card-foreground' => ['theme' => 'colors.slate.900'],
                    '--popover'    => ['theme' => 'colors.white'],
                    '--popover-foreground' => ['theme' => 'colors.slate.900'],
                    '--border'     => ['theme' => 'colors.slate.200'],
                    '--input'      => ['theme' => 'colors.slate.300'],
                    '--ring'       => ['theme' => 'colors.blue.500'],
                    '--radius'     => '0.5rem',
                    
                    // daisyUI Vars
                    '--rounded-box' => '1rem', '--rounded-btn' => '0.5rem', '--rounded-badge' => '1.9rem',
                    '--animation-btn' => '0.25s', '--animation-input' => '0.2s', '--btn-focus-scale' => '0.95',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0.5rem',
                    
                    // daisyUI Colors
                    '--p' => ['theme' => 'colors.blue.600'], '--pf' => ['theme' => 'colors.blue.700'], '--pc' => ['theme' => 'colors.slate.50'],
                    '--s' => ['theme' => 'colors.slate.600'], '--sf' => ['theme' => 'colors.slate.700'], '--sc' => ['theme' => 'colors.slate.50'],
                    '--a' => ['theme' => 'colors.pink.500'], '--af' => ['theme' => 'colors.pink.600'], '--ac' => ['theme' => 'colors.slate.50'],
                    '--n' => ['theme' => 'colors.slate.100'], '--nf' => ['theme' => 'colors.slate.100'], '--nc' => ['theme' => 'colors.slate.900'],
                    '--b1' => ['theme' => 'colors.white'], '--b2' => ['theme' => 'colors.slate.100'], '--b3' => ['theme' => 'colors.slate.200'], '--bc' => ['theme' => 'colors.slate.900'],
                    '--in' => ['theme' => 'colors.sky.500'], '--inc' => ['theme' => 'colors.white'],
                    '--su' => ['theme' => 'colors.green.600'], '--suc' => ['theme' => 'colors.white'],
                    '--wa' => ['theme' => 'colors.amber.500'], '--wac' => ['theme' => 'colors.slate.900'],
                    '--er' => ['theme' => 'colors.red.600'], '--erc' => ['theme' => 'colors.white'],

                    // Semantic Colors
                    '--primary' => ['theme' => 'colors.blue.600'], '--primary-hover' => ['theme' => 'colors.blue.700'], '--primary-foreground' => ['theme' => 'colors.slate.50'],
                    '--secondary' => ['theme' => 'colors.slate.700'], '--secondary-hover' => ['theme' => 'colors.slate.800'], '--secondary-foreground' => ['theme' => 'colors.slate.50'],
                    '--accent' => ['theme' => 'colors.pink.500'], '--accent-hover' => ['theme' => 'colors.pink.600'], '--accent-foreground' => ['theme' => 'colors.slate.50'],
                    '--success' => ['theme' => 'colors.green.600'], '--success-hover' => ['theme' => 'colors.green.700'], '--success-foreground' => ['theme' => 'colors.white'],
                    '--warning' => ['theme' => 'colors.amber.500'], '--warning-hover' => ['theme' => 'colors.amber.600'], '--warning-foreground' => ['theme' => 'colors.slate.900'],
                    '--info' => ['theme' => 'colors.sky.500'], '--info-hover' => ['theme' => 'colors.sky.600'], '--info-foreground' => ['theme' => 'colors.white'],
                    '--danger' => ['theme' => 'colors.red.500'], '--danger-hover' => ['theme' => 'colors.red.600'], '--danger-foreground' => ['theme' => 'colors.slate.50'],
                    '--error' => ['theme' => 'colors.red.500'], '--error-hover' => ['theme' => 'colors.red.600'], '--error-foreground' => ['theme' => 'colors.slate.50'],
                    '--destructive' => ['theme' => 'colors.red.500'], '--destructive-hover' => ['theme' => 'colors.red.600'], '--destructive-foreground' => ['theme' => 'colors.slate.50'],
                    '--muted' => ['theme' => 'colors.slate.300'], '--muted-hover' => ['theme' => 'colors.slate.400'], '--muted-foreground' => ['theme' => 'colors.slate.500'],

                    // Prose
                    '--tw-prose-body' => ['theme' => 'colors.slate.700'], '--tw-prose-headings' => ['theme' => 'colors.slate.900'], '--tw-prose-lead' => ['theme' => 'colors.slate.600'],
                    '--tw-prose-links' => ['theme' => 'colors.blue.700'], '--tw-prose-bold' => ['theme' => 'colors.slate.900'], '--tw-prose-counters' => ['theme' => 'colors.slate.500'],
                    '--tw-prose-bullets' => ['theme' => 'colors.slate.400'], '--tw-prose-hr' => ['theme' => 'colors.slate.200'], '--tw-prose-quotes' => ['theme' => 'colors.slate.900'],
                    '--tw-prose-quote-borders' => ['theme' => 'colors.slate.200'], '--tw-prose-captions' => ['theme' => 'colors.slate.500'], '--tw-prose-code' => ['theme' => 'colors.slate.900'],
                    '--tw-prose-pre-code' => ['theme' => 'colors.slate.200'], '--tw-prose-pre-bg' => ['theme' => 'colors.slate.800'],
                    '--tw-prose-th-borders' => ['theme' => 'colors.slate.300'], '--tw-prose-td-borders' => ['theme' => 'colors.slate.200'],
                    
                    // Glass
                    '--glass-bg-base' => ['raw' => '255 255 255'], '--glass-border-base' => ['raw' => '229 231 235'],
                    '--glass-bg-dark-base' => ['raw' => '30 41 59'], '--glass-border-dark-base' => ['raw' => '51 65 85'],
                    '--glass-bg-primary' => ['raw' => '59 130 246'], '--glass-border-primary' => ['raw' => '37 99 235'],
                ],
                'dark' => [ // Dark Theme
                    'color-scheme' => 'dark',
                    '--background' => ['theme' => 'colors.slate.900'], '--foreground' => ['theme' => 'colors.slate.50'],
                    '--card' => ['theme' => 'colors.slate.800'], '--card-foreground' => ['theme' => 'colors.slate.50'],
                    '--popover' => ['theme' => 'colors.slate.800'], '--popover-foreground' => ['theme' => 'colors.slate.50'],
                    '--border' => ['theme' => 'colors.slate.700'], '--input' => ['theme' => 'colors.slate.700'], '--ring' => ['theme' => 'colors.blue.500'], '--radius' => '0.5rem',
                    
                    '--rounded-box' => '1rem', '--rounded-btn' => '0.5rem', '--rounded-badge' => '1.9rem',
                    '--animation-btn' => '0.25s', '--animation-input' => '0.2s', '--btn-focus-scale' => '0.95',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0.5rem',
                    '--p' => ['theme' => 'colors.blue.500'], '--pf' => ['theme' => 'colors.blue.500'], '--pc' => ['theme' => 'colors.slate.950'],
                    '--s' => ['theme' => 'colors.slate.600'], '--sf' => ['theme' => 'colors.slate.600'], '--sc' => ['theme' => 'colors.slate.100'],
                    '--a' => ['theme' => 'colors.pink.500'], '--af' => ['theme' => 'colors.pink.500'], '--ac' => ['theme' => 'colors.pink.50'],
                    '--n' => ['theme' => 'colors.slate.800'], '--nf' => ['theme' => 'colors.slate.800'], '--nc' => ['theme' => 'colors.slate.100'],
                    '--b1' => ['theme' => 'colors.slate.900'], '--b2' => ['theme' => 'colors.slate.800'], '--b3' => ['theme' => 'colors.slate.700'], '--bc' => ['theme' => 'colors.slate.50'],
                    '--in' => ['theme' => 'colors.sky.400'], '--inc' => ['theme' => 'colors.sky.900'],
                    '--su' => ['theme' => 'colors.green.500'], '--suc' => ['theme' => 'colors.green.50'],
                    '--wa' => ['theme' => 'colors.amber.400'], '--wac' => ['theme' => 'colors.amber.900'],
                    '--er' => ['theme' => 'colors.red.500'], '--erc' => ['theme' => 'colors.red.50'],

                    '--primary' => ['theme' => 'colors.blue.500'], '--primary-hover' => ['theme' => 'colors.blue.600'], '--primary-foreground' => ['theme' => 'colors.slate.950'],
                    '--secondary' => ['theme' => 'colors.slate.600'], '--secondary-hover' => ['theme' => 'colors.slate.700'], '--secondary-foreground' => ['theme' => 'colors.slate.100'],
                    '--accent' => ['theme' => 'colors.pink.500'], '--accent-hover' => ['theme' => 'colors.pink.600'], '--accent-foreground' => ['theme' => 'colors.pink.50'],
                    '--success' => ['theme' => 'colors.green.500'], '--success-hover' => ['theme' => 'colors.green.600'], '--success-foreground' => ['theme' => 'colors.green.50'],
                    '--warning' => ['theme' => 'colors.amber.400'], '--warning-hover' => ['theme' => 'colors.amber.500'], '--warning-foreground' => ['theme' => 'colors.amber.900'],
                    '--info' => ['theme' => 'colors.sky.400'], '--info-hover' => ['theme' => 'colors.sky.500'], '--info-foreground' => ['theme' => 'colors.sky.900'],
                    '--danger' => ['theme' => 'colors.red.500'], '--danger-hover' => ['theme' => 'colors.red.600'], '--danger-foreground' => ['theme' => 'colors.red.50'],
                    '--error' => ['theme' => 'colors.red.500'], '--error-hover' => ['theme' => 'colors.red.600'], '--error-foreground' => ['theme' => 'colors.red.50'],
                    '--destructive' => ['theme' => 'colors.red.500'], '--destructive-hover' => ['theme' => 'colors.red.600'], '--destructive-foreground' => ['theme' => 'colors.red.50'],
                    '--muted' => ['theme' => 'colors.slate.700'], '--muted-hover' => ['theme' => 'colors.slate.800'], '--muted-foreground' => ['theme' => 'colors.slate.400'],

                    // Prose Dark
                    '--tw-prose-body' => 'var(--tw-prose-invert-body)', '--tw-prose-headings' => 'var(--tw-prose-invert-headings)', '--tw-prose-lead' => 'var(--tw-prose-invert-lead)',
                    '--tw-prose-links' => 'var(--tw-prose-invert-links)', '--tw-prose-bold' => 'var(--tw-prose-invert-bold)', '--tw-prose-counters' => 'var(--tw-prose-invert-counters)',
                    '--tw-prose-bullets' => 'var(--tw-prose-invert-bullets)', '--tw-prose-hr' => 'var(--tw-prose-invert-hr)', '--tw-prose-quotes' => 'var(--tw-prose-invert-quotes)',
                    '--tw-prose-quote-borders' => 'var(--tw-prose-invert-quote-borders)', '--tw-prose-captions' => 'var(--tw-prose-invert-captions)', '--tw-prose-code' => 'var(--tw-prose-invert-code)',
                    '--tw-prose-pre-code' => 'var(--tw-prose-invert-pre-code)', '--tw-prose-pre-bg' => 'var(--tw-prose-invert-pre-bg)',
                    '--tw-prose-th-borders' => 'var(--tw-prose-invert-th-borders)', '--tw-prose-td-borders' => 'var(--tw-prose-invert-td-borders)',
                    
                    // Glass Dark
                    '--glass-bg-base' => ['raw' => '30 41 59'],
                    '--glass-border-base' => ['raw' => '51 65 85'],
                    '--glass-bg-dark-base' => ['raw' => '15 23 42'],
                    '--glass-border-dark-base' => ['raw' => '30 41 59'],
                    '--glass-bg-primary' => ['raw' => '37 99 235'],
                    '--glass-border-primary' => ['raw' => '29 78 216'],
                ],
                'forest' => [ // Forest Theme
                    'color-scheme' => 'dark',
                    '--background' => ['theme' => 'colors.green.950'], '--foreground' => ['theme' => 'colors.lime.100'],
                    '--card' => ['theme' => 'colors.green.900'], '--card-foreground' => ['theme' => 'colors.lime.100'],
                    '--popover' => ['theme' => 'colors.green.900'], '--popover-foreground' => ['theme' => 'colors.lime.100'],
                    '--border' => ['theme' => 'colors.green.800'], '--input' => ['theme' => 'colors.green.800'], '--ring' => ['theme' => 'colors.green.500'], '--radius' => '0.375rem',
                    
                    '--rounded-box' => '1rem', '--rounded-btn' => '0.5rem', '--rounded-badge' => '1.9rem',
                    '--animation-btn' => '0.25s', '--animation-input' => '0.2s', '--btn-focus-scale' => '0.95',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0.5rem',
                    '--p' => ['theme' => 'colors.green.600'], '--pf' => ['theme' => 'colors.green.500'], '--pc' => ['theme' => 'colors.green.50'],
                    '--s' => ['theme' => 'colors.stone.700'], '--sf' => ['theme' => 'colors.stone.600'], '--sc' => ['theme' => 'colors.stone.200'],
                    '--a' => ['theme' => 'colors.amber.400'], '--af' => ['theme' => 'colors.amber.500'], '--ac' => ['theme' => 'colors.amber.950'],
                    '--n' => ['theme' => 'colors.stone.800'], '--nf' => ['theme' => 'colors.stone.900'], '--nc' => ['theme' => 'colors.stone.200'],
                    '--b1' => ['theme' => 'colors.green.950'], '--b2' => ['theme' => 'colors.green.900'], '--b3' => ['theme' => 'colors.green.800'], '--bc' => ['theme' => 'colors.lime.100'],
                    '--in' => ['theme' => 'colors.cyan.500'], '--inc' => ['theme' => 'colors.white'],
                    '--su' => ['theme' => 'colors.teal.500'], '--suc' => ['theme' => 'colors.teal.50'],
                    '--wa' => ['theme' => 'colors.yellow.400'], '--wac' => ['theme' => 'colors.yellow.900'],
                    '--er' => ['theme' => 'colors.red.600'], '--erc' => ['theme' => 'colors.red.50'],

                    '--primary' => ['theme' => 'colors.green.600'], '--primary-hover' => ['theme' => 'colors.green.500'], '--primary-foreground' => ['theme' => 'colors.green.50'],
                    '--secondary' => ['theme' => 'colors.stone.700'], '--secondary-hover' => ['theme' => 'colors.stone.600'], '--secondary-foreground' => ['theme' => 'colors.stone.200'],
                    '--accent' => ['theme' => 'colors.amber.400'], '--accent-hover' => ['theme' => 'colors.amber.500'], '--accent-foreground' => ['theme' => 'colors.amber.950'],
                    '--muted' => ['theme' => 'colors.stone.600'], '--muted-hover' => ['theme' => 'colors.stone.400'], '--muted-foreground' => ['theme' => 'colors.stone.400'],
                    '--success' => ['theme' => 'colors.teal.500'], '--success-hover' => ['theme' => 'colors.teal.600'], '--success-foreground' => ['theme' => 'colors.teal.50'],
                    '--warning' => ['theme' => 'colors.yellow.400'], '--warning-hover' => ['theme' => 'colors.yellow.500'], '--warning-foreground' => ['theme' => 'colors.yellow.900'],
                    '--info' => ['theme' => 'colors.cyan.500'], '--info-hover' => ['theme' => 'colors.cyan.600'], '--info-foreground' => ['theme' => 'colors.white'],
                    '--danger' => ['theme' => 'colors.red.600'], '--danger-hover' => ['theme' => 'colors.red.700'], '--danger-foreground' => ['theme' => 'colors.red.50'],
                    '--error' => ['theme' => 'colors.red.600'], '--error-hover' => ['theme' => 'colors.red.700'], '--error-foreground' => ['theme' => 'colors.red.50'],
                    '--destructive' => ['theme' => 'colors.red.600'], '--destructive-hover' => ['theme' => 'colors.red.700'], '--destructive-foreground' => ['theme' => 'colors.red.50'],

                    '--tw-prose-body' => ['theme' => 'colors.lime.200'], '--tw-prose-headings' => ['theme' => 'colors.lime.50'], '--tw-prose-lead' => ['theme' => 'colors.lime.300'],
                    '--tw-prose-links' => ['theme' => 'colors.emerald.400'], '--tw-prose-bold' => ['theme' => 'colors.lime.50'], '--tw-prose-counters' => ['theme' => 'colors.lime.400'],
                    '--tw-prose-bullets' => ['theme' => 'colors.green.400'], '--tw-prose-hr' => ['theme' => 'colors.green.700'], '--tw-prose-quotes' => ['theme' => 'colors.lime.100'],
                    '--tw-prose-quote-borders' => ['theme' => 'colors.green.600'], '--tw-prose-captions' => ['theme' => 'colors.lime.400'], '--tw-prose-code' => ['theme' => 'colors.lime.50'],
                    '--tw-prose-pre-code' => ['theme' => 'colors.lime.200'], '--tw-prose-pre-bg' => ['raw' => 'rgb(15 30 20 / 90%)'],
                    '--tw-prose-th-borders' => ['theme' => 'colors.green.700'], '--tw-prose-td-borders' => ['theme' => 'colors.green.800'],
                    
                    '--glass-bg-base' => ['raw' => '21 74 51'], '--glass-border-base' => ['raw' => '22 107 56'], '--glass-bg-primary' => ['raw' => '22 163 74'],
                ],
                'ocean' => [ // Ocean Theme
                    'color-scheme' => 'light',
                    '--background' => ['theme' => 'colors.sky.50'], '--foreground' => ['theme' => 'colors.slate.800'],
                    '--card' => ['theme' => 'colors.cyan.100'], '--card-foreground' => ['theme' => 'colors.slate.900'],
                    '--popover' => ['theme' => 'colors.teal.100'], '--popover-foreground' => ['theme' => 'colors.slate.950'],
                    '--border' => ['theme' => 'colors.blue.200'], '--input' => ['theme' => 'colors.sky.100'], '--ring' => ['theme' => 'colors.blue.400'], '--radius' => '0.5rem',
                    
                    '--rounded-box' => '1rem', '--rounded-btn' => '0.5rem', '--rounded-badge' => '1.9rem',
                    '--animation-btn' => '0.25s', '--animation-input' => '0.2s', '--btn-focus-scale' => '0.95',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0.5rem',
                    '--p' => ['theme' => 'colors.blue.500'], '--pf' => ['theme' => 'colors.blue.600'], '--pc' => ['theme' => 'colors.white'],
                    '--s' => ['theme' => 'colors.teal.400'], '--sf' => ['theme' => 'colors.teal.500'], '--sc' => ['theme' => 'colors.teal.900'],
                    '--a' => ['theme' => 'colors.orange.500'], '--af' => ['theme' => 'colors.orange.600'], '--ac' => ['theme' => 'colors.white'],
                    '--n' => ['theme' => 'colors.sky.200'], '--nf' => ['theme' => 'colors.sky.300'], '--nc' => ['theme' => 'colors.sky.700'],
                    '--b1' => ['theme' => 'colors.sky.50'], '--b2' => ['theme' => 'colors.sky.100'], '--b3' => ['theme' => 'colors.sky.200'], '--bc' => ['theme' => 'colors.slate.800'],
                    '--in' => ['theme' => 'colors.sky.500'], '--inc' => ['theme' => 'colors.white'],
                    '--su' => ['theme' => 'colors.green.500'], '--suc' => ['theme' => 'colors.white'],
                    '--wa' => ['theme' => 'colors.yellow.400'], '--wac' => ['theme' => 'colors.black'],
                    '--er' => ['theme' => 'colors.red.600'], '--erc' => ['theme' => 'colors.white'],

                    '--primary' => ['theme' => 'colors.blue.500'], '--primary-hover' => ['theme' => 'colors.blue.600'], '--primary-foreground' => ['theme' => 'colors.white'],
                    '--secondary' => ['theme' => 'colors.teal.400'], '--secondary-hover' => ['theme' => 'colors.teal.500'], '--secondary-foreground' => ['theme' => 'colors.teal.900'],
                    '--accent' => ['theme' => 'colors.orange.500'], '--accent-hover' => ['theme' => 'colors.orange.600'], '--accent-foreground' => ['theme' => 'colors.white'],
                    '--muted' => ['theme' => 'colors.sky.200'], '--muted-hover' => ['theme' => 'colors.sky.300'], '--muted-foreground' => ['theme' => 'colors.sky.700'],
                    '--success' => ['theme' => 'colors.green.500'], '--success-hover' => ['theme' => 'colors.green.600'], '--success-foreground' => ['theme' => 'colors.white'],
                    '--warning' => ['theme' => 'colors.yellow.400'], '--warning-hover' => ['theme' => 'colors.yellow.500'], '--warning-foreground' => ['theme' => 'colors.black'],
                    '--info' => ['theme' => 'colors.sky.500'], '--info-hover' => ['theme' => 'colors.sky.600'], '--info-foreground' => ['theme' => 'colors.white'],
                    '--danger' => ['theme' => 'colors.red.600'], '--danger-hover' => ['theme' => 'colors.red.700'], '--danger-foreground' => ['theme' => 'colors.white'],
                    '--error' => ['theme' => 'colors.red.600'], '--error-hover' => ['theme' => 'colors.red.700'], '--error-foreground' => ['theme' => 'colors.white'],
                    '--destructive' => ['theme' => 'colors.red.600'], '--destructive-hover' => ['theme' => 'colors.red.700'], '--destructive-foreground' => ['theme' => 'colors.white'],

                    '--tw-prose-body' => ['theme' => 'colors.slate.700'], '--tw-prose-headings' => ['theme' => 'colors.blue.800'], '--tw-prose-lead' => ['theme' => 'colors.slate.600'],
                    '--tw-prose-links' => ['theme' => 'colors.sky.600'], '--tw-prose-bold' => ['theme' => 'colors.blue.800'], '--tw-prose-counters' => ['theme' => 'colors.sky.500'],
                    '--tw-prose-bullets' => ['theme' => 'colors.cyan.400'], '--tw-prose-hr' => ['theme' => 'colors.sky.200'], '--tw-prose-quotes' => ['theme' => 'colors.blue.800'],
                    '--tw-prose-quote-borders' => ['theme' => 'colors.sky.300'], '--tw-prose-captions' => ['theme' => 'colors.sky.700'], '--tw-prose-code' => ['theme' => 'colors.blue.900'],
                    '--tw-prose-pre-code' => ['theme' => 'colors.cyan.100'], '--tw-prose-pre-bg' => ['theme' => 'colors.blue.900'],
                    '--tw-prose-th-borders' => ['theme' => 'colors.sky.300'], '--tw-prose-td-borders' => ['theme' => 'colors.sky.200'],
                    
                    '--glass-bg-base' => ['raw' => '224 242 254'], '--glass-border-base' => ['raw' => '186 230 253'], '--glass-bg-primary' => ['raw' => '59 130 246'],
                ],
                'sunset' => [ // Sunset Theme
                    'color-scheme' => 'light',
                    '--background' => ['theme' => 'colors.orange.50'], '--foreground' => ['theme' => 'colors.stone.900'],
                    '--card' => ['theme' => 'colors.amber.100'], '--card-foreground' => ['theme' => 'colors.stone.950'],
                    '--popover' => ['theme' => 'colors.orange.200'], '--popover-foreground' => ['theme' => 'colors.stone.950'],
                    '--border' => ['theme' => 'colors.amber.300'], '--input' => ['theme' => 'colors.orange.100'], '--ring' => ['theme' => 'colors.orange.500'], '--radius' => '0.75rem',
                    
                    '--rounded-box' => '1rem', '--rounded-btn' => '0.5rem', '--rounded-badge' => '1.9rem',
                    '--animation-btn' => '0.25s', '--animation-input' => '0.2s', '--btn-focus-scale' => '0.95',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0.5rem',
                    '--p' => ['theme' => 'colors.orange.600'], '--pf' => ['theme' => 'colors.orange.500'], '--pc' => ['theme' => 'colors.white'],
                    '--s' => ['theme' => 'colors.yellow.500'], '--sf' => ['theme' => 'colors.yellow.400'], '--sc' => ['theme' => 'colors.yellow.900'],
                    '--a' => ['theme' => 'colors.rose.500'], '--af' => ['theme' => 'colors.rose.600'], '--ac' => ['theme' => 'colors.white'],
                    '--n' => ['theme' => 'colors.orange.200'], '--nf' => ['theme' => 'colors.orange.300'], '--nc' => ['theme' => 'colors.orange.800'],
                    '--b1' => ['theme' => 'colors.orange.50'], '--b2' => ['theme' => 'colors.amber.100'], '--b3' => ['theme' => 'colors.amber.200'], '--bc' => ['theme' => 'colors.stone.800'],
                    '--in' => ['theme' => 'colors.sky.500'], '--inc' => ['theme' => 'colors.white'],
                    '--su' => ['theme' => 'colors.green.600'], '--suc' => ['theme' => 'colors.white'],
                    '--wa' => ['theme' => 'colors.amber.400'], '--wac' => ['theme' => 'colors.black'],
                    '--er' => ['theme' => 'colors.red.500'], '--erc' => ['theme' => 'colors.white'],

                    '--primary' => ['theme' => 'colors.orange.600'], '--primary-hover' => ['theme' => 'colors.orange.500'], '--primary-foreground' => ['theme' => 'colors.white'],
                    '--secondary' => ['theme' => 'colors.yellow.500'], '--secondary-hover' => ['theme' => 'colors.yellow.400'], '--secondary-foreground' => ['theme' => 'colors.yellow.900'],
                    '--accent' => ['theme' => 'colors.rose.500'], '--accent-hover' => ['theme' => 'colors.rose.600'], '--accent-foreground' => ['theme' => 'colors.white'],
                    '--muted' => ['theme' => 'colors.orange.200'], '--muted-hover' => ['theme' => 'colors.orange.300'], '--muted-foreground' => ['theme' => 'colors.orange.800'],
                    '--success' => ['theme' => 'colors.green.600'], '--success-hover' => ['theme' => 'colors.green.500'], '--success-foreground' => ['theme' => 'colors.white'],
                    '--warning' => ['theme' => 'colors.amber.400'], '--warning-hover' => ['theme' => 'colors.amber.500'], '--warning-foreground' => ['theme' => 'colors.black'],
                    '--info' => ['theme' => 'colors.sky.500'], '--info-hover' => ['theme' => 'colors.sky.600'], '--info-foreground' => ['theme' => 'colors.white'],
                    '--danger' => ['theme' => 'colors.red.500'], '--danger-hover' => ['theme' => 'colors.red.600'], '--danger-foreground' => ['theme' => 'colors.white'],
                    '--error' => ['theme' => 'colors.red.500'], '--error-hover' => ['theme' => 'colors.red.600'], '--error-foreground' => ['theme' => 'colors.white'],
                    '--destructive' => ['theme' => 'colors.red.500'], '--destructive-hover' => ['theme' => 'colors.red.600'], '--destructive-foreground' => ['theme' => 'colors.white'],

                    '--tw-prose-body' => ['theme' => 'colors.stone.800'], '--tw-prose-headings' => ['theme' => 'colors.orange.900'], '--tw-prose-lead' => ['theme' => 'colors.stone.700'],
                    '--tw-prose-links' => ['theme' => 'colors.red.600'], '--tw-prose-bold' => ['theme' => 'colors.orange.900'], '--tw-prose-counters' => ['theme' => 'colors.amber.700'],
                    '--tw-prose-bullets' => ['theme' => 'colors.orange.500'], '--tw-prose-hr' => ['theme' => 'colors.amber.200'], '--tw-prose-quotes' => ['theme' => 'colors.orange.900'],
                    '--tw-prose-quote-borders' => ['theme' => 'colors.amber.300'], '--tw-prose-captions' => ['theme' => 'colors.amber.700'], '--tw-prose-code' => ['theme' => 'colors.rose.800'],
                    '--tw-prose-pre-code' => ['theme' => 'colors.orange.100'], '--tw-prose-pre-bg' => ['theme' => 'colors.stone.800'],
                    '--tw-prose-th-borders' => ['theme' => 'colors.amber.400'], '--tw-prose-td-borders' => ['theme' => 'colors.amber.200'],
                    
                    '--glass-bg-base' => ['raw' => '255 237 213'], '--glass-border-base' => ['raw' => '253 224 182'], '--glass-bg-primary' => ['raw' => '234 88 12'],
                ],
                'retro' => [ // Retro Theme (Full Coverage)
                    'color-scheme' => 'light',
                    '--background' => ['raw' => '#ece3ca'], '--foreground' => ['raw' => '#282425'],
                    '--card' => ['raw' => '#e4d8b4'], '--card-foreground' => ['raw' => '#282425'],
                    '--popover' => ['raw' => '#e4d8b4'], '--popover-foreground' => ['raw' => '#282425'],
                    '--border' => ['raw' => '#b8a47f'], '--input' => ['raw' => '#b8a47f'], '--ring' => ['raw' => '#ef9995'], '--radius' => '0.4rem',
                    
                    '--rounded-box' => '0.4rem', '--rounded-btn' => '0.4rem', '--rounded-badge' => '0.4rem',
                    '--animation-btn' => '0.25s', '--animation-input' => '0.2s', '--btn-focus-scale' => '0.95',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0.5rem',
                    '--p' => ['raw' => '#ef9995'], '--pf' => ['raw' => '#e08884'], '--pc' => ['raw' => '#282425'],
                    '--s' => ['raw' => '#a4cbb4'], '--sf' => ['raw' => '#93baa3'], '--sc' => ['raw' => '#282425'],
                    '--a' => ['raw' => '#ebdc99'], '--af' => ['raw' => '#dacc88'], '--ac' => ['raw' => '#282425'],
                    '--n' => ['raw' => '#7d7259'], '--nf' => ['raw' => '#665d49'], '--nc' => ['raw' => '#e4d8b4'],
                    '--b1' => ['raw' => '#ece3ca'], '--b2' => ['raw' => '#e4d8b4'], '--b3' => ['raw' => '#d2c59d'], '--bc' => ['raw' => '#282425'],
                    '--in' => ['raw' => '#2563eb'], '--inc' => ['raw' => '#ffffff'],
                    '--su' => ['raw' => '#16a34a'], '--suc' => ['raw' => '#ffffff'],
                    '--wa' => ['raw' => '#d97706'], '--wac' => ['raw' => '#ffffff'],
                    '--er' => ['raw' => '#dc2626'], '--erc' => ['raw' => '#ffffff'],

                    '--primary' => ['raw' => '#ef9995'], '--primary-hover' => ['raw' => '#e08884'], '--primary-foreground' => ['raw' => '#282425'],
                    '--secondary' => ['raw' => '#a4cbb4'], '--secondary-hover' => ['raw' => '#93baa3'], '--secondary-foreground' => ['raw' => '#282425'],
                    '--accent' => ['raw' => '#ebdc99'], '--accent-hover' => ['raw' => '#dacc88'], '--accent-foreground' => ['raw' => '#282425'],
                    '--muted' => ['raw' => '#d2c59d'], '--muted-hover' => ['raw' => '#c1b48c'], '--muted-foreground' => ['raw' => '#7d7259'],
                    '--success' => ['raw' => '#16a34a'], '--success-hover' => ['raw' => '#15803d'], '--success-foreground' => ['raw' => '#ffffff'],
                    '--warning' => ['raw' => '#d97706'], '--warning-hover' => ['raw' => '#b45309'], '--warning-foreground' => ['raw' => '#ffffff'],
                    '--info' => ['raw' => '#2563eb'], '--info-hover' => ['raw' => '#1d4ed8'], '--info-foreground' => ['raw' => '#ffffff'],
                    '--danger' => ['raw' => '#dc2626'], '--danger-hover' => ['raw' => '#b91c1c'], '--danger-foreground' => ['raw' => '#ffffff'],
                    '--error' => ['raw' => '#dc2626'], '--error-hover' => ['raw' => '#b91c1c'], '--error-foreground' => ['raw' => '#ffffff'],
                    '--destructive' => ['raw' => '#dc2626'], '--destructive-hover' => ['raw' => '#b91c1c'], '--destructive-foreground' => ['raw' => '#ffffff'],

                    '--tw-prose-body' => ['raw' => '#282425'], '--tw-prose-headings' => ['raw' => '#282425'], '--tw-prose-lead' => ['raw' => '#7d7259'],
                    '--tw-prose-links' => ['raw' => '#ef9995'], '--tw-prose-bold' => ['raw' => '#282425'], '--tw-prose-counters' => ['raw' => '#7d7259'],
                    '--tw-prose-bullets' => ['raw' => '#a4cbb4'], '--tw-prose-hr' => ['raw' => '#b8a47f'], '--tw-prose-quotes' => ['raw' => '#282425'],
                    '--tw-prose-quote-borders' => ['raw' => '#b8a47f'], '--tw-prose-captions' => ['raw' => '#7d7259'], '--tw-prose-code' => ['raw' => '#282425'],
                    '--tw-prose-pre-code' => ['raw' => '#282425'], '--tw-prose-pre-bg' => ['raw' => '#e4d8b4'],
                    '--tw-prose-th-borders' => ['raw' => '#b8a47f'], '--tw-prose-td-borders' => ['raw' => '#b8a47f'],

                    '--glass-bg-base' => ['raw' => '236 227 202'], '--glass-border-base' => ['raw' => '184 164 127'], '--glass-bg-primary' => ['raw' => '239 153 149'],
                ],
                'cyberpunk' => [ // Cyberpunk Theme (Full Coverage)
                    'color-scheme' => 'light',
                    '--background' => ['raw' => '#ffee00'], '--foreground' => ['raw' => '#000000'],
                    '--card' => ['raw' => '#fff990'], '--card-foreground' => ['raw' => '#000000'],
                    '--popover' => ['raw' => '#fff990'], '--popover-foreground' => ['raw' => '#000000'],
                    '--border' => ['raw' => '#000000'], '--input' => ['raw' => '#ffffff'], '--ring' => ['raw' => '#ff00ff'], '--radius' => '0px',
                    
                    '--rounded-box' => '0', '--rounded-btn' => '0', '--rounded-badge' => '0',
                    '--animation-btn' => '0.25s', '--animation-input' => '0.2s', '--btn-focus-scale' => '0.95',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0',
                    '--p' => ['raw' => '#ff7598'], '--pf' => ['raw' => '#e66989'], '--pc' => ['raw' => '#000000'],
                    '--s' => ['raw' => '#75d1f0'], '--sf' => ['raw' => '#69bdd8'], '--sc' => ['raw' => '#000000'],
                    '--a' => ['raw' => '#c07eec'], '--af' => ['raw' => '#ad71d4'], '--ac' => ['raw' => '#000000'],
                    '--n' => ['raw' => '#ffee00'], '--nf' => ['raw' => '#e6d600'], '--nc' => ['raw' => '#000000'],
                    '--b1' => ['raw' => '#ffee00'], '--b2' => ['raw' => '#fff990'], '--b3' => ['raw' => '#ffeb80'], '--bc' => ['raw' => '#000000'],
                    '--in' => ['raw' => '#0000ff'], '--inc' => ['raw' => '#ffffff'],
                    '--su' => ['raw' => '#00ff00'], '--suc' => ['raw' => '#000000'],
                    '--wa' => ['raw' => '#ffff00'], '--wac' => ['raw' => '#000000'],
                    '--er' => ['raw' => '#ff0000'], '--erc' => ['raw' => '#ffffff'],

                    '--primary' => ['raw' => '#ff7598'], '--primary-hover' => ['raw' => '#e66989'], '--primary-foreground' => ['raw' => '#000000'],
                    '--secondary' => ['raw' => '#75d1f0'], '--secondary-hover' => ['raw' => '#69bdd8'], '--secondary-foreground' => ['raw' => '#000000'],
                    '--accent' => ['raw' => '#c07eec'], '--accent-hover' => ['raw' => '#ad71d4'], '--accent-foreground' => ['raw' => '#000000'],
                    '--muted' => ['raw' => '#ffeb80'], '--muted-hover' => ['raw' => '#e6d473'], '--muted-foreground' => ['raw' => '#807700'],
                    '--success' => ['raw' => '#00ff00'], '--success-hover' => ['raw' => '#00e600'], '--success-foreground' => ['raw' => '#000000'],
                    '--warning' => ['raw' => '#ffff00'], '--warning-hover' => ['raw' => '#e6e600'], '--warning-foreground' => ['raw' => '#000000'],
                    '--info' => ['raw' => '#0000ff'], '--info-hover' => ['raw' => '#0000e6'], '--info-foreground' => ['raw' => '#ffffff'],
                    '--destructive' => ['raw' => '#ff0000'], '--destructive-hover' => ['raw' => '#e60000'], '--destructive-foreground' => ['raw' => '#ffffff'],

                    '--tw-prose-body' => ['raw' => '#000000'], '--tw-prose-headings' => ['raw' => '#000000'], '--tw-prose-lead' => ['raw' => '#000000'],
                    '--tw-prose-links' => ['raw' => '#ff00ff'], '--tw-prose-bold' => ['raw' => '#000000'], '--tw-prose-counters' => ['raw' => '#000000'],
                    '--tw-prose-bullets' => ['raw' => '#000000'], '--tw-prose-hr' => ['raw' => '#000000'], '--tw-prose-quotes' => ['raw' => '#000000'],
                    '--tw-prose-quote-borders' => ['raw' => '#000000'], '--tw-prose-captions' => ['raw' => '#000000'], '--tw-prose-code' => ['raw' => '#ff00ff'],
                    '--tw-prose-pre-code' => ['raw' => '#000000'], '--tw-prose-pre-bg' => ['raw' => '#fff990'],
                    '--tw-prose-th-borders' => ['raw' => '#000000'], '--tw-prose-td-borders' => ['raw' => '#000000'],

                    '--glass-bg-base' => ['raw' => '255 238 0'], '--glass-border-base' => ['raw' => '0 0 0'], '--glass-bg-primary' => ['raw' => '255 117 152'],
                ],
                'valentine' => [ // Valentine Theme (Full Coverage)
                    'color-scheme' => 'light',
                    '--background' => ['raw' => '#f0d6e8'], '--foreground' => ['raw' => '#632c3b'],
                    '--card' => ['raw' => '#e9aad9'], '--card-foreground' => ['raw' => '#632c3b'],
                    '--popover' => ['raw' => '#e9aad9'], '--popover-foreground' => ['raw' => '#632c3b'],
                    '--border' => ['raw' => '#e9aad9'], '--input' => ['raw' => '#ffffff'], '--ring' => ['raw' => '#e96d7b'], '--radius' => '1.9rem',
                    
                    '--rounded-box' => '1rem', '--rounded-btn' => '1.9rem', '--rounded-badge' => '1.9rem',
                    '--animation-btn' => '0.25s', '--animation-input' => '0.2s', '--btn-focus-scale' => '0.95',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0.5rem',
                    '--p' => ['raw' => '#e96d7b'], '--pf' => ['raw' => '#d1626e'], '--pc' => ['raw' => '#ffffff'],
                    '--s' => ['raw' => '#a991f7'], '--sf' => ['raw' => '#9882de'], '--sc' => ['raw' => '#ffffff'],
                    '--a' => ['raw' => '#88dbdd'], '--af' => ['raw' => '#7ac5c7'], '--ac' => ['raw' => '#1f2937'],
                    '--n' => ['raw' => '#af4670'], '--nf' => ['raw' => '#9e3f65'], '--nc' => ['raw' => '#f0d6e8'],
                    '--b1' => ['raw' => '#f0d6e8'], '--b2' => ['raw' => '#e9aad9'], '--b3' => ['raw' => '#e58fce'], '--bc' => ['raw' => '#632c3b'],
                    '--in' => ['raw' => '#2563eb'], '--inc' => ['raw' => '#ffffff'],
                    '--su' => ['raw' => '#16a34a'], '--suc' => ['raw' => '#ffffff'],
                    '--wa' => ['raw' => '#d97706'], '--wac' => ['raw' => '#ffffff'],
                    '--er' => ['raw' => '#dc2626'], '--erc' => ['raw' => '#ffffff'],

                    '--primary' => ['raw' => '#e96d7b'], '--primary-hover' => ['raw' => '#d1626e'], '--primary-foreground' => ['raw' => '#ffffff'],
                    '--secondary' => ['raw' => '#a991f7'], '--secondary-hover' => ['raw' => '#9882de'], '--secondary-foreground' => ['raw' => '#ffffff'],
                    '--accent' => ['raw' => '#88dbdd'], '--accent-hover' => ['raw' => '#7ac5c7'], '--accent-foreground' => ['raw' => '#1f2937'],
                    '--muted' => ['raw' => '#e58fce'], '--muted-hover' => ['raw' => '#ce80b9'], '--muted-foreground' => ['raw' => '#af4670'],
                    '--success' => ['raw' => '#16a34a'], '--success-hover' => ['raw' => '#15803d'], '--success-foreground' => ['raw' => '#ffffff'],
                    '--warning' => ['raw' => '#d97706'], '--warning-hover' => ['raw' => '#b45309'], '--warning-foreground' => ['raw' => '#ffffff'],
                    '--info' => ['raw' => '#2563eb'], '--info-hover' => ['raw' => '#1d4ed8'], '--info-foreground' => ['raw' => '#ffffff'],
                    '--danger' => ['raw' => '#ff0000'], '--danger-hover' => ['raw' => '#e60000'], '--danger-foreground' => ['raw' => '#ffffff'],
                    '--error' => ['raw' => '#ff0000'], '--error-hover' => ['raw' => '#e60000'], '--error-foreground' => ['raw' => '#ffffff'],
                    '--destructive' => ['raw' => '#ff0000'], '--destructive-hover' => ['raw' => '#e60000'], '--destructive-foreground' => ['raw' => '#ffffff'],

                    '--tw-prose-body' => ['raw' => '#632c3b'], '--tw-prose-headings' => ['raw' => '#632c3b'], '--tw-prose-lead' => ['raw' => '#af4670'],
                    '--tw-prose-links' => ['raw' => '#e96d7b'], '--tw-prose-bold' => ['raw' => '#632c3b'], '--tw-prose-counters' => ['raw' => '#af4670'],
                    '--tw-prose-bullets' => ['raw' => '#e96d7b'], '--tw-prose-hr' => ['raw' => '#e9aad9'], '--tw-prose-quotes' => ['raw' => '#632c3b'],
                    '--tw-prose-quote-borders' => ['raw' => '#e9aad9'], '--tw-prose-captions' => ['raw' => '#af4670'], '--tw-prose-code' => ['raw' => '#632c3b'],
                    '--tw-prose-pre-code' => ['raw' => '#632c3b'], '--tw-prose-pre-bg' => ['raw' => '#e9aad9'],
                    '--tw-prose-th-borders' => ['raw' => '#e9aad9'], '--tw-prose-td-borders' => ['raw' => '#e9aad9'],

                    '--glass-bg-base' => ['raw' => '240 214 232'], '--glass-border-base' => ['raw' => '233 170 217'], '--glass-bg-primary' => ['raw' => '233 109 123'],
                ],
                'aqua' => [ // Aqua Theme (Full Coverage)
                    'color-scheme' => 'dark',
                    '--background' => ['raw' => '#345da7'], '--foreground' => ['raw' => '#bfe2ff'],
                    '--card' => ['raw' => '#2a4c8a'], '--card-foreground' => ['raw' => '#bfe2ff'],
                    '--popover' => ['raw' => '#2a4c8a'], '--popover-foreground' => ['raw' => '#bfe2ff'],
                    '--border' => ['raw' => '#4b7ccf'], '--input' => ['raw' => '#4b7ccf'], '--ring' => ['raw' => '#09ecf3'], '--radius' => '0.5rem',
                    
                    '--rounded-box' => '1rem', '--rounded-btn' => '0.5rem', '--rounded-badge' => '1.9rem',
                    '--animation-btn' => '0.25s', '--animation-input' => '0.2s', '--btn-focus-scale' => '0.95',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0.5rem',
                    '--p' => ['raw' => '#09ecf3'], '--pf' => ['raw' => '#08d4da'], '--pc' => ['raw' => '#005355'],
                    '--s' => ['raw' => '#966fb3'], '--sf' => ['raw' => '#8764a1'], '--sc' => ['raw' => '#e9dbf4'],
                    '--a' => ['raw' => '#ffe999'], '--af' => ['raw' => '#e6d18a'], '--ac' => ['raw' => '#5a4f1f'],
                    '--n' => ['raw' => '#3b8ac4'], '--nf' => ['raw' => '#357cb0'], '--nc' => ['raw' => '#ffffff'],
                    '--b1' => ['raw' => '#345da7'], '--b2' => ['raw' => '#2a4c8a'], '--b3' => ['raw' => '#1f3b6d'], '--bc' => ['raw' => '#bfe2ff'],
                    '--in' => ['raw' => '#2563eb'], '--inc' => ['raw' => '#ffffff'],
                    '--su' => ['raw' => '#16a34a'], '--suc' => ['raw' => '#ffffff'],
                    '--wa' => ['raw' => '#d97706'], '--wac' => ['raw' => '#ffffff'],
                    '--er' => ['raw' => '#dc2626'], '--erc' => ['raw' => '#ffffff'],

                    '--primary' => ['raw' => '#09ecf3'], '--primary-hover' => ['raw' => '#08d4da'], '--primary-foreground' => ['raw' => '#005355'],
                    '--secondary' => ['raw' => '#966fb3'], '--secondary-hover' => ['raw' => '#8764a1'], '--secondary-foreground' => ['raw' => '#e9dbf4'],
                    '--accent' => ['raw' => '#ffe999'], '--accent-hover' => ['raw' => '#e6d18a'], '--accent-foreground' => ['raw' => '#5a4f1f'],
                    '--muted' => ['raw' => '#1f3b6d'], '--muted-hover' => ['raw' => '#152b50'], '--muted-foreground' => ['raw' => '#3b8ac4'],
                    '--success' => ['raw' => '#16a34a'], '--success-hover' => ['raw' => '#15803d'], '--success-foreground' => ['raw' => '#ffffff'],
                    '--warning' => ['raw' => '#d97706'], '--warning-hover' => ['raw' => '#b45309'], '--warning-foreground' => ['raw' => '#ffffff'],
                    '--info' => ['raw' => '#2563eb'], '--info-hover' => ['raw' => '#1d4ed8'], '--info-foreground' => ['raw' => '#ffffff'],
                    '--danger' => ['raw' => '#dc2626'], '--danger-hover' => ['raw' => '#b91c1c'], '--danger-foreground' => ['raw' => '#ffffff'],
                    '--error' => ['raw' => '#dc2626'], '--error-hover' => ['raw' => '#b91c1c'], '--error-foreground' => ['raw' => '#ffffff'],
                    '--destructive' => ['raw' => '#dc2626'], '--destructive-hover' => ['raw' => '#b91c1c'], '--destructive-foreground' => ['raw' => '#ffffff'],

                    '--tw-prose-body' => ['raw' => '#bfe2ff'], '--tw-prose-headings' => ['raw' => '#09ecf3'], '--tw-prose-lead' => ['raw' => '#3b8ac4'],
                    '--tw-prose-links' => ['raw' => '#966fb3'], '--tw-prose-bold' => ['raw' => '#09ecf3'], '--tw-prose-counters' => ['raw' => '#3b8ac4'],
                    '--tw-prose-bullets' => ['raw' => '#4b7ccf'], '--tw-prose-hr' => ['raw' => '#1f3b6d'], '--tw-prose-quotes' => ['raw' => '#bfe2ff'],
                    '--tw-prose-quote-borders' => ['raw' => '#1f3b6d'], '--tw-prose-captions' => ['raw' => '#3b8ac4'], '--tw-prose-code' => ['raw' => '#ffe999'],
                    '--tw-prose-pre-code' => ['raw' => '#bfe2ff'], '--tw-prose-pre-bg' => ['raw' => '#2a4c8a'],
                    '--tw-prose-th-borders' => ['raw' => '#4b7ccf'], '--tw-prose-td-borders' => ['raw' => '#1f3b6d'],

                    '--glass-bg-base' => ['raw' => '52 93 167'], '--glass-border-base' => ['raw' => '75 124 207'], '--glass-bg-primary' => ['raw' => '9 236 243'],
                ],
                'dracula' => [ // Developer's Favorite Dark Theme
                    'color-scheme' => 'dark',
                    // Base Colors
                    '--background' => ['raw' => '231 15% 18%'], '--foreground' => ['raw' => '60 30% 96%'],
                    '--card' => ['raw' => '231 15% 18%'], '--card-foreground' => ['raw' => '60 30% 96%'],
                    '--popover' => ['raw' => '231 15% 18%'], '--popover-foreground' => ['raw' => '60 30% 96%'],
                    '--border' => ['raw' => '225 27% 51%'], '--input' => ['raw' => '231 15% 18%'], '--ring' => ['raw' => '326 100% 74%'],
                    '--radius' => '0.5rem',
                    
                    // daisyUI Shapes & Animations
                    '--rounded-box' => '1rem', '--rounded-btn' => '0.5rem', '--rounded-badge' => '1.9rem',
                    '--animation-btn' => '0.25s', '--animation-input' => '0.2s', '--btn-focus-scale' => '0.95',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0.5rem',
                    
                    // daisyUI Base Colors
                    '--p' => ['raw' => '326 100% 74%'], '--pf' => ['raw' => '326 100% 64%'], '--pc' => ['raw' => '0 0% 15%'],
                    '--s' => ['raw' => '265 89% 78%'], '--sf' => ['raw' => '265 89% 68%'], '--sc' => ['raw' => '0 0% 100%'],
                    '--a' => ['raw' => '60 100% 60%'], '--af' => ['raw' => '60 100% 50%'], '--ac' => ['raw' => '0 0% 15%'],
                    '--n' => ['raw' => '231 15% 18%'], '--nf' => ['raw' => '230 15% 14%'], '--nc' => ['raw' => '60 30% 96%'],
                    '--b1' => ['raw' => '231 15% 18%'], '--b2' => ['raw' => '230 15% 14%'], '--b3' => ['raw' => '225 27% 51%'], '--bc' => ['raw' => '60 30% 96%'],
                    '--in' => ['raw' => '191 97% 77%'], '--inc' => ['raw' => '0 0% 15%'],
                    '--su' => ['raw' => '135 94% 65%'], '--suc' => ['raw' => '0 0% 15%'],
                    '--wa' => ['raw' => '31 100% 71%'], '--wac' => ['raw' => '0 0% 15%'],
                    '--er' => ['raw' => '0 100% 67%'], '--erc' => ['raw' => '0 0% 100%'],

                    // Semantic Variables (Crucial for components like hover states, badges, alerts)
                    '--primary' => ['raw' => '326 100% 74%'], '--primary-hover' => ['raw' => '326 100% 64%'], '--primary-foreground' => ['raw' => '0 0% 15%'],
                    '--secondary' => ['raw' => '265 89% 78%'], '--secondary-hover' => ['raw' => '265 89% 68%'], '--secondary-foreground' => ['raw' => '0 0% 100%'],
                    '--accent' => ['raw' => '60 100% 60%'], '--accent-hover' => ['raw' => '60 100% 50%'], '--accent-foreground' => ['raw' => '0 0% 15%'],
                    '--success' => ['raw' => '135 94% 65%'], '--success-hover' => ['raw' => '135 94% 55%'], '--success-foreground' => ['raw' => '0 0% 15%'],
                    '--warning' => ['raw' => '31 100% 71%'], '--warning-hover' => ['raw' => '31 100% 61%'], '--warning-foreground' => ['raw' => '0 0% 15%'],
                    '--info' => ['raw' => '191 97% 77%'], '--info-hover' => ['raw' => '191 97% 67%'], '--info-foreground' => ['raw' => '0 0% 15%'],
                    '--danger' => ['raw' => '0 100% 67%'], '--danger-hover' => ['raw' => '0 100% 57%'], '--danger-foreground' => ['raw' => '0 0% 100%'],
                    '--error' => ['raw' => '0 100% 67%'], '--error-hover' => ['raw' => '0 100% 57%'], '--error-foreground' => ['raw' => '0 0% 100%'],
                    '--destructive' => ['raw' => '0 100% 67%'], '--destructive-hover' => ['raw' => '0 100% 57%'], '--destructive-foreground' => ['raw' => '0 0% 100%'],
                    '--muted' => ['raw' => '230 15% 14%'], '--muted-hover' => ['raw' => '225 27% 51%'], '--muted-foreground' => ['raw' => '60 30% 70%'],

                    // Prose Dark Typography
                    '--tw-prose-body' => 'var(--tw-prose-invert-body)', '--tw-prose-headings' => 'var(--tw-prose-invert-headings)', '--tw-prose-lead' => 'var(--tw-prose-invert-lead)',
                    '--tw-prose-links' => ['raw' => '326 100% 74%'], '--tw-prose-bold' => 'var(--tw-prose-invert-bold)', '--tw-prose-counters' => 'var(--tw-prose-invert-counters)',
                    '--tw-prose-bullets' => 'var(--tw-prose-invert-bullets)', '--tw-prose-hr' => 'var(--tw-prose-invert-hr)', '--tw-prose-quotes' => 'var(--tw-prose-invert-quotes)',
                    '--tw-prose-quote-borders' => 'var(--tw-prose-invert-quote-borders)', '--tw-prose-captions' => 'var(--tw-prose-invert-captions)', '--tw-prose-code' => 'var(--tw-prose-invert-code)',
                    '--tw-prose-pre-code' => 'var(--tw-prose-invert-pre-code)', '--tw-prose-pre-bg' => 'var(--tw-prose-invert-pre-bg)',
                    '--tw-prose-th-borders' => 'var(--tw-prose-invert-th-borders)', '--tw-prose-td-borders' => 'var(--tw-prose-invert-td-borders)',
                    
                    // Glass Dark Effect (RGB raw values required for RGBA conversions)
                    '--glass-bg-base' => ['raw' => '40 42 54'], '--glass-border-base' => ['raw' => '68 71 90'], '--glass-bg-primary' => ['raw' => '255 121 198'],
                ],
                'cupcake' => [ // Cute & Soft Light Theme
                    'color-scheme' => 'light',
                    // Base Colors
                    '--background' => ['raw' => '0 0% 98%'], '--foreground' => ['raw' => '321 14% 18%'],
                    '--card' => ['raw' => '0 0% 100%'], '--card-foreground' => ['raw' => '321 14% 18%'],
                    '--popover' => ['raw' => '0 0% 100%'], '--popover-foreground' => ['raw' => '321 14% 18%'],
                    '--border' => ['raw' => '31 18% 85%'], '--input' => ['raw' => '0 0% 100%'], '--ring' => ['raw' => '183 47% 59%'],
                    '--radius' => '1rem',
                    
                    // daisyUI Shapes & Animations
                    '--rounded-box' => '1.5rem', '--rounded-btn' => '1.9rem', '--rounded-badge' => '1.9rem',
                    '--animation-btn' => '0.25s', '--animation-input' => '0.2s', '--btn-focus-scale' => '0.95',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0.5rem',
                    
                    // daisyUI Base Colors
                    '--p' => ['raw' => '183 47% 59%'], '--pf' => ['raw' => '183 47% 49%'], '--pc' => ['raw' => '0 0% 100%'], // Teal
                    '--s' => ['raw' => '326 31% 72%'], '--sf' => ['raw' => '326 31% 62%'], '--sc' => ['raw' => '0 0% 100%'], // Pink
                    '--a' => ['raw' => '42 66% 75%'], '--af' => ['raw' => '42 66% 65%'], '--ac' => ['raw' => '0 0% 15%'], // Yellow
                    '--n' => ['raw' => '321 14% 18%'], '--nf' => ['raw' => '321 14% 10%'], '--nc' => ['raw' => '0 0% 98%'],
                    '--b1' => ['raw' => '0 0% 98%'], '--b2' => ['raw' => '31 18% 90%'], '--b3' => ['raw' => '31 18% 85%'], '--bc' => ['raw' => '321 14% 18%'],
                    '--in' => ['raw' => '200 70% 50%'], '--inc' => ['raw' => '0 0% 100%'], 
                    '--su' => ['raw' => '160 50% 45%'], '--suc' => ['raw' => '0 0% 100%'], 
                    '--wa' => ['raw' => '40 80% 55%'], '--wac' => ['raw' => '0 0% 15%'], 
                    '--er' => ['raw' => '0 70% 60%'], '--erc' => ['raw' => '0 0% 100%'],

                    // Semantic Variables
                    '--primary' => ['raw' => '183 47% 59%'], '--primary-hover' => ['raw' => '183 47% 49%'], '--primary-foreground' => ['raw' => '0 0% 100%'],
                    '--secondary' => ['raw' => '326 31% 72%'], '--secondary-hover' => ['raw' => '326 31% 62%'], '--secondary-foreground' => ['raw' => '0 0% 100%'],
                    '--accent' => ['raw' => '42 66% 75%'], '--accent-hover' => ['raw' => '42 66% 65%'], '--accent-foreground' => ['raw' => '0 0% 15%'],
                    '--success' => ['raw' => '160 50% 45%'], '--success-hover' => ['raw' => '160 50% 35%'], '--success-foreground' => ['raw' => '0 0% 100%'],
                    '--warning' => ['raw' => '40 80% 55%'], '--warning-hover' => ['raw' => '40 80% 45%'], '--warning-foreground' => ['raw' => '0 0% 15%'],
                    '--info' => ['raw' => '200 70% 50%'], '--info-hover' => ['raw' => '200 70% 40%'], '--info-foreground' => ['raw' => '0 0% 100%'],
                    '--danger' => ['raw' => '0 70% 60%'], '--danger-hover' => ['raw' => '0 70% 50%'], '--danger-foreground' => ['raw' => '0 0% 100%'],
                    '--error' => ['raw' => '0 70% 60%'], '--error-hover' => ['raw' => '0 70% 50%'], '--error-foreground' => ['raw' => '0 0% 100%'],
                    '--destructive' => ['raw' => '0 70% 60%'], '--destructive-hover' => ['raw' => '0 70% 50%'], '--destructive-foreground' => ['raw' => '0 0% 100%'],
                    '--muted' => ['raw' => '31 18% 90%'], '--muted-hover' => ['raw' => '31 18% 85%'], '--muted-foreground' => ['raw' => '321 14% 40%'],

                    // Prose Light Typography
                    '--tw-prose-body' => ['raw' => '321 14% 18%'], '--tw-prose-headings' => ['raw' => '321 14% 10%'], '--tw-prose-lead' => ['raw' => '321 14% 30%'],
                    '--tw-prose-links' => ['raw' => '183 47% 59%'], '--tw-prose-bold' => ['raw' => '321 14% 10%'], '--tw-prose-counters' => ['raw' => '321 14% 40%'],
                    '--tw-prose-bullets' => ['raw' => '183 47% 59%'], '--tw-prose-hr' => ['raw' => '31 18% 85%'], '--tw-prose-quotes' => ['raw' => '321 14% 10%'],
                    '--tw-prose-quote-borders' => ['raw' => '31 18% 85%'], '--tw-prose-captions' => ['raw' => '321 14% 40%'], '--tw-prose-code' => ['raw' => '326 31% 72%'],
                    '--tw-prose-pre-code' => ['raw' => '321 14% 18%'], '--tw-prose-pre-bg' => ['raw' => '31 18% 90%'],
                    '--tw-prose-th-borders' => ['raw' => '31 18% 85%'], '--tw-prose-td-borders' => ['raw' => '31 18% 90%'],

                    // Glass Light Effect
                    '--glass-bg-base' => ['raw' => '250 249 246'], '--glass-border-base' => ['raw' => '238 224 213'], '--glass-bg-primary' => ['raw' => '101 195 200'],
                ],
                'corporate' => [ // Professional Admin Dashboard Theme
                    'color-scheme' => 'light',
                    // Base Colors
                    '--background' => ['raw' => '0 0% 100%'], '--foreground' => ['raw' => '215 28% 17%'],
                    '--card' => ['raw' => '0 0% 100%'], '--card-foreground' => ['raw' => '215 28% 17%'],
                    '--popover' => ['raw' => '0 0% 100%'], '--popover-foreground' => ['raw' => '215 28% 17%'],
                    '--border' => ['raw' => '214 32% 91%'], '--input' => ['raw' => '0 0% 100%'], '--ring' => ['raw' => '221 83% 53%'],
                    '--radius' => '0.25rem',
                    
                    // daisyUI Shapes & Animations
                    '--rounded-box' => '0.25rem', '--rounded-btn' => '0.25rem', '--rounded-badge' => '0.25rem',
                    '--animation-btn' => '0', '--animation-input' => '0', '--btn-focus-scale' => '1',
                    '--border-btn' => '1px', '--tab-border' => '1px', '--tab-radius' => '0.25rem',
                    
                    // daisyUI Base Colors
                    '--p' => ['raw' => '221 83% 53%'], '--pf' => ['raw' => '221 83% 43%'], '--pc' => ['raw' => '0 0% 100%'], // Royal Blue
                    '--s' => ['raw' => '210 20% 50%'], '--sf' => ['raw' => '210 20% 40%'], '--sc' => ['raw' => '0 0% 100%'], // Slate
                    '--a' => ['raw' => '160 84% 39%'], '--af' => ['raw' => '160 84% 29%'], '--ac' => ['raw' => '0 0% 100%'], // Emerald
                    '--n' => ['raw' => '215 28% 17%'], '--nf' => ['raw' => '215 28% 10%'], '--nc' => ['raw' => '0 0% 100%'],
                    '--b1' => ['raw' => '0 0% 100%'], '--b2' => ['raw' => '210 40% 96%'], '--b3' => ['raw' => '214 32% 91%'], '--bc' => ['raw' => '215 28% 17%'],
                    '--in' => ['raw' => '200 90% 50%'], '--inc' => ['raw' => '0 0% 100%'], 
                    '--su' => ['raw' => '140 70% 45%'], '--suc' => ['raw' => '0 0% 100%'], 
                    '--wa' => ['raw' => '40 90% 50%'], '--wac' => ['raw' => '0 0% 15%'], 
                    '--er' => ['raw' => '0 80% 55%'], '--erc' => ['raw' => '0 0% 100%'],

                    // Semantic Variables
                    '--primary' => ['raw' => '221 83% 53%'], '--primary-hover' => ['raw' => '221 83% 43%'], '--primary-foreground' => ['raw' => '0 0% 100%'],
                    '--secondary' => ['raw' => '210 20% 50%'], '--secondary-hover' => ['raw' => '210 20% 40%'], '--secondary-foreground' => ['raw' => '0 0% 100%'],
                    '--accent' => ['raw' => '160 84% 39%'], '--accent-hover' => ['raw' => '160 84% 29%'], '--accent-foreground' => ['raw' => '0 0% 100%'],
                    '--success' => ['raw' => '140 70% 45%'], '--success-hover' => ['raw' => '140 70% 35%'], '--success-foreground' => ['raw' => '0 0% 100%'],
                    '--warning' => ['raw' => '40 90% 50%'], '--warning-hover' => ['raw' => '40 90% 40%'], '--warning-foreground' => ['raw' => '0 0% 15%'],
                    '--info' => ['raw' => '200 90% 50%'], '--info-hover' => ['raw' => '200 90% 40%'], '--info-foreground' => ['raw' => '0 0% 100%'],
                    '--danger' => ['raw' => '0 80% 55%'], '--danger-hover' => ['raw' => '0 80% 45%'], '--danger-foreground' => ['raw' => '0 0% 100%'],
                    '--error' => ['raw' => '0 80% 55%'], '--error-hover' => ['raw' => '0 80% 45%'], '--error-foreground' => ['raw' => '0 0% 100%'],
                    '--destructive' => ['raw' => '0 80% 55%'], '--destructive-hover' => ['raw' => '0 80% 45%'], '--destructive-foreground' => ['raw' => '0 0% 100%'],
                    '--muted' => ['raw' => '210 40% 96%'], '--muted-hover' => ['raw' => '214 32% 91%'], '--muted-foreground' => ['raw' => '215 16% 47%'],

                    // Prose Light Typography
                    '--tw-prose-body' => ['raw' => '215 28% 17%'], '--tw-prose-headings' => ['raw' => '215 28% 10%'], '--tw-prose-lead' => ['raw' => '215 16% 47%'],
                    '--tw-prose-links' => ['raw' => '221 83% 53%'], '--tw-prose-bold' => ['raw' => '215 28% 10%'], '--tw-prose-counters' => ['raw' => '215 16% 47%'],
                    '--tw-prose-bullets' => ['raw' => '214 32% 91%'], '--tw-prose-hr' => ['raw' => '214 32% 91%'], '--tw-prose-quotes' => ['raw' => '215 28% 10%'],
                    '--tw-prose-quote-borders' => ['raw' => '214 32% 91%'], '--tw-prose-captions' => ['raw' => '215 16% 47%'], '--tw-prose-code' => ['raw' => '221 83% 53%'],
                    '--tw-prose-pre-code' => ['raw' => '215 28% 17%'], '--tw-prose-pre-bg' => ['raw' => '210 40% 96%'],
                    '--tw-prose-th-borders' => ['raw' => '214 32% 91%'], '--tw-prose-td-borders' => ['raw' => '210 40% 96%'],

                    // Glass Light Effect
                    '--glass-bg-base' => ['raw' => '248 250 252'], '--glass-border-base' => ['raw' => '226 232 240'], '--glass-bg-primary' => ['raw' => '37 99 235'],
                ],
            ],
            'theme' => [
                'screens' => [
                    'sm' => '640px', 'md' => '768px', 'lg' => '1024px', 'xl' => '1280px', '2xl' => '1536px',
                ],
                'padding' => [
                    'DEFAULT' => '1rem',
                    'sm' => '2rem',
                    'lg' => '4rem',
                    'xl' => '5rem',
                    '2xl' => '6rem',
                    '3xl' => '8rem',
                ],
                'center' => true, // Default: center the container
                'container' => [
                    'center' => true, // Default: center the container
                    'padding' => [    // Default padding for the container itself
                        'DEFAULT' => '1rem', // p-4 equivalent for left/right
                        'sm' => '2rem',
                        'lg' => '4rem',
                        'xl' => '5rem',
                        '2xl' => '6rem',
                        '3xl' => '8rem',
                    ],
                    'screens' => [
                        'sm' => '640px',
                        'md' => '768px',
                        'lg' => '1024px',
                        'xl' => '1280px',
                        '2xl' => '1536px',
                        '3xl' => '1920px',
                    ],
                ],
                'zIndex' => [
                    'auto' => 'auto',
                    '0' => '0', '10' => '10', '20' => '20', '30' => '30', '40' => '40', '50' => '50',
                    'dropdown'            => '1000',
                    'sticky'              => '1020',
                    'fixed'               => '1030',
                    'offcanvas-backdrop'  => '1040',
                    'offcanvas'           => '1045',
                    'modal-backdrop'      => '1050',
                    'modal'               => '1055',
                    'popover'             => '1070',
                    'tooltip'             => '1080',
                    'toast'               => '1090',
                ],
                'link' => [
                    'DEFAULT' => [ // For the plain .link class or as a base for others
                        'color' => ['theme' => 'colors.primary.DEFAULT'], // Use semantic primary color
                        'hover' => ['theme' => 'colors.primary.dark'],   // Use darker shade on hover
                        'focus' => ['theme' => 'colors.primary.dark'],   // Same as hover
                        'active' => ['theme' => 'colors.primary.dark'],  // Same as hover
                    ],
                    'primary' => [ // For .primary-link class
                        'color' => ['theme' => 'colors.primary.DEFAULT'],
                        'hover' => ['theme' => 'colors.primary.hover'],
                    ],
                    'secondary' => [ // For .secondary-link class
                        'color' => ['theme' => 'colors.secondary.DEFAULT'],
                        'hover' => ['theme' => 'colors.secondary.hover'],
                    ],
                    'info' => [ // For .info-link class
                        'color' => ['theme' => 'colors.info.DEFAULT'],
                        'hover' => ['theme' => 'colors.info.hover'],
                    ],
                    'success' => [
                        'color' => ['theme' => 'colors.success.DEFAULT'],
                        'hover' => ['theme' => 'colors.success.hover'],
                    ],
                    'warning' => [
                        'color' => ['theme' => 'colors.warning.DEFAULT'],
                        'hover' => ['theme' => 'colors.warning.hover'],
                    ],
                    'destructive' => [
                        'color' => ['theme' => 'colors.destructive.DEFAULT'],
                        'hover' => ['theme' => 'colors.destructive.hover'],
                    ],
                    'danger' => [
                        'color' => ['theme' => 'colors.destructive.DEFAULT'],
                        'hover' => ['theme' => 'colors.destructive.hover'],
                    ],
                ],
                'colors' => [
                    'inherit' => 'inherit',
                    'current' => 'currentColor',
                    'transparent' => 'transparent',
                    'black' => '#000000',
                    'white' => '#ffffff',
                    'background' => 'hsl(var(--background))',
                    'foreground' => 'hsl(var(--foreground))',
                    'card' => 'hsl(var(--card))',
                    'card-foreground' => 'hsl(var(--card-foreground))',
                    'popover' => 'hsl(var(--popover))',
                    'popover-foreground' => 'hsl(var(--popover-foreground))',
                    'primary' => [
                        'DEFAULT' => 'hsl(var(--primary))',
                        'foreground' => 'hsl(var(--primary-foreground))',
                    ],
                    'primary-hover' => [
                        'DEFAULT' => 'hsl(var(--primary-hover))',
                        'foreground' => 'hsl(var(--primary-foreground))',
                    ],
                    'secondary' => [
                        'DEFAULT' => 'hsl(var(--secondary))',
                        'foreground' => 'hsl(var(--secondary-foreground))',
                    ],
                    'secondary-hover' => [
                        'DEFAULT' => 'hsl(var(--secondary-hover))',
                        'foreground' => 'hsl(var(--secondary-foreground))',
                    ],
                    'muted' => [
                        'DEFAULT' => 'hsl(var(--muted))',
                        'foreground' => 'hsl(var(--muted-foreground))',
                    ],
                    'muted-hover' => [
                        'DEFAULT' => 'hsl(var(--muted-hover))',
                        'foreground' => 'hsl(var(--muted-foreground))',
                    ],
                    'accent' => [
                        'DEFAULT' => 'hsl(var(--accent))',
                        'foreground' => 'hsl(var(--accent-foreground))',
                    ],
                    'accent-hover' => [
                        'DEFAULT' => 'hsl(var(--accent-hover))',
                        'foreground' => 'hsl(var(--accent-foreground))',
                    ],
                    'danger' => [
                        'DEFAULT' => 'hsl(var(--danger))',
                        'foreground' => 'hsl(var(--danger-foreground))',
                    ],
                    'danger-hover' => [
                        'DEFAULT' => 'hsl(var(--danger-hover))',
                        'foreground' => 'hsl(var(--danger-foreground))',
                    ],
                    'error' => [
                        'DEFAULT' => 'hsl(var(--error))',
                        'foreground' => 'hsl(var(--error-foreground))',
                    ],
                    'error-hover' => [
                        'DEFAULT' => 'hsl(var(--error-hover))',
                        'foreground' => 'hsl(var(--error-foreground))',
                    ],
                    'destructive' => [
                        'DEFAULT' => 'hsl(var(--destructive))',
                        'foreground' => 'hsl(var(--destructive-foreground))',
                    ],
                    'destructive-hover' => [
                        'DEFAULT' => 'hsl(var(--destructive-hover))',
                        'foreground' => 'hsl(var(--destructive-foreground))',
                    ],
                    'success' => [
                        'DEFAULT' => 'hsl(var(--success))',
                        'foreground' => 'hsl(var(--success-foreground))',
                    ],
                    'success-hover' => [
                        'DEFAULT' => 'hsl(var(--success-hover))',
                        'foreground' => 'hsl(var(--success-foreground))',
                    ],
                    'warning' => [
                        'DEFAULT' => 'hsl(var(--warning))',
                        'foreground' => 'hsl(var(--warning-foreground))',
                    ],
                    'warning-hover' => [
                        'DEFAULT' => 'hsl(var(--warning-hover))',
                        'foreground' => 'hsl(var(--warning-foreground))',
                    ],
                    'info' => [
                        'DEFAULT' => 'hsl(var(--info))',
                        'foreground' => 'hsl(var(--info-foreground))',
                    ],
                    'info-hover' => [
                        'DEFAULT' => 'hsl(var(--info-hover))',
                        'foreground' => 'hsl(var(--info-foreground))',
                    ],
                    'base' => [
                        '100' => 'hsl(var(--b1))',
                        '200' => 'hsl(var(--b2))',
                        '300' => 'hsl(var(--b3))',
                        'content' => 'hsl(var(--bc))',
                    ],
                    'neutral' => [
                        'DEFAULT' => 'hsl(var(--n))',
                        'focus' => 'hsl(var(--nf))',
                        'content' => 'hsl(var(--nc))',
                        '50' => '#fafafa', '100' => '#f5f5f5', '200' => '#e5e5e5', '300' => '#d4d4d4',
                        '400' => '#a3a3a3', '500' => '#737373', '600' => '#525252', '700' => '#404040',
                        '800' => '#262626', '900' => '#171717', '950' => '#0a0a0a',
                    ],
                    'danger' => [
                        'DEFAULT' => ['theme' => 'colors.destructive.DEFAULT'],
                        'foreground' => ['theme' => 'colors.destructive.foreground'],
                    ],
                    'light' => [
                        'DEFAULT' => ['theme' => 'colors.slate.100'],
                        'foreground' => ['theme' => 'colors.slate.900'],
                    ],
                    'dark' => [
                        'DEFAULT' => ['theme' => 'colors.slate.900'],
                        'foreground' => ['theme' => 'colors.slate.100'],
                    ],
                    'link' => [
                        'DEFAULT' => ['theme' => 'colors.primary.DEFAULT'],
                        'hover' => ['theme' => 'colors.primary.hover'],
                    ],
                    'border' => 'hsl(var(--border))',
                    'input' => 'hsl(var(--input))',
                    'ring' => 'hsl(var(--ring))',
                    'glass' => [
                        'DEFAULT' => 'rgba(var(--glass-bg-base), 0.2)',
                        'border' => 'rgba(var(--glass-border-base), 0.1)',
                    ],
                    'glass-light' => [
                        'DEFAULT' => 'rgba(var(--glass-bg-base), 0.5)',
                        'border' => 'rgba(var(--glass-border-base), 0.2)',
                    ],
                    'glass-dark' => [
                        'DEFAULT' => 'rgba(var(--glass-bg-dark-base), 0.2)',
                        'border' => 'rgba(var(--glass-border-dark-base), 0.1)',
                    ],
                    'glass-apple-light' => [
                        'DEFAULT' => 'rgba(255, 255, 255, 0.25)',
                        'border' => 'rgba(255, 255, 255, 0.1)',
                    ],
                    'glass-apple-dark' => [
                        'DEFAULT' => 'rgba(30, 30, 30, 0.3)',
                        'border' => 'rgba(255, 255, 255, 0.08)',
                    ],
                    'glass-primary' => [
                        'DEFAULT' => 'hsla(var(--primary), 0.15)',
                        'border' => 'hsla(var(--primary), 0.25)',
                    ],
                    'glass-secondary' => [
                        'DEFAULT' => 'hsla(var(--secondary), 0.15)',
                        'border' => 'hsla(var(--secondary), 0.25)',
                    ],
                    'glass-accent' => [
                        'DEFAULT' => 'hsla(var(--accent), 0.2)',
                        'border' => 'hsla(var(--accent), 0.3)',
                    ],
                    'glass-danger' => [
                        'DEFAULT' => 'hsla(var(--danger), 0.15)',
                        'border' => 'hsla(var(--danger), 0.25)',
                    ],
                    'glass-error' => [
                        'DEFAULT' => 'hsla(var(--error), 0.15)',
                        'border' => 'hsla(var(--error), 0.25)',
                    ],
                    'glass-destructive' => [
                        'DEFAULT' => 'hsla(var(--destructive), 0.15)',
                        'border' => 'hsla(var(--destructive), 0.25)',
                    ],
                    'glass-success' => [
                        'DEFAULT' => 'hsla(var(--success), 0.15)',
                        'border' => 'hsla(var(--success), 0.25)',
                    ],
                    'glass-warning' => [
                        'DEFAULT' => 'hsla(var(--warning), 0.2)',
                        'border' => 'hsla(var(--warning), 0.3)',
                    ],
                    'glass-info' => [
                        'DEFAULT' => 'hsla(var(--info), 0.15)',
                        'border' => 'hsla(var(--info), 0.25)',
                    ],
                    'glass-aurora' => [
                        'DEFAULT' => 'rgba(128, 0, 128, 0.1)',
                        'border' => 'rgba(255, 255, 255, 0.1)',
                    ],
                    'glass-emerald' => [
                        'DEFAULT' => 'rgba(5, 150, 105, 0.15)',
                        'border' => 'rgba(16, 185, 129, 0.2)',
                    ],
                    'glass-sunset' => [
                        'DEFAULT' => 'rgba(234, 88, 12, 0.1)',
                        'border' => 'rgba(249, 115, 22, 0.2)',
                    ],
                    'glass-frosted' => [
                        'DEFAULT' => 'rgba(255, 255, 255, 0.15)',
                        'border' => 'rgba(255, 255, 255, 0.2)',
                    ],
                    'glass-frosted-dark' => [
                        'DEFAULT' => 'rgba(30, 30, 30, 0.25)',
                        'border' => 'rgba(255, 255, 255, 0.1)',
                    ],
                    'brand' => [
                        'facebook' => '#1877F2',
                        'twitter' => '#1DA1F2',
                        'instagram' => '#E1306C',
                        'linkedin' => '#0077B5',
                        'youtube' => '#FF0000',
                        'github' => '#181717',
                        'discord' => '#5865F2',
                        'whatsapp' => '#25D366',
                        'telegram' => '#229ED9',
                        'tiktok' => '#000000',
                        'pinterest' => '#E60023',
                        'slack' => '#4A154B',
                        'spotify' => '#1DB954',
                        'twitch' => '#9146FF',
                    ],
                    'metal' => [
                        'gold' => '#FFD700',
                        'silver' => '#C0C0C0',
                        'bronze' => '#CD7F32',
                        'copper' => '#B87333',
                        'platinum' => '#E5E4E2',
                    ],
                    'neon' => [
                        'green' => '#39FF14',
                        'blue' => '#4D4DFF',
                        'pink' => '#FF10F0',
                        'purple' => '#BC13FE',
                        'yellow' => '#FFFF00',
                        'orange' => '#FF5F1F',
                        'cyan' => '#00FFFF',
                    ],
                    'slate' => [
                        '50' => '#f8fafc', '100' => '#f1f5f9', '200' => '#e2e8f0', '300' => '#cbd5e1',
                        '400' => '#94a3b8', '500' => '#64748b', '600' => '#475569', '700' => '#334155',
                        '800' => '#1e293b', '900' => '#0f172a', '950' => '#020617', 'DEFAULT' => '#64748b',
                    ],
                    'gray' => [
                        '50' => '#f9fafb', '100' => '#f3f4f6', '200' => '#e5e7eb', '300' => '#d1d5db',
                        '400' => '#9ca3af', '500' => '#6b7280', '600' => '#4b5563', '700' => '#374151',
                        '800' => '#1f2937', '900' => '#111827', '950' => '#030712', 'DEFAULT' => '#6b7280',
                    ],
                    'coolGray' => [
                        '50' => '#f9fafb', '100' => '#f3f4f6', '200' => '#e5e7eb', '300' => '#d1d5db', '400' => '#9ca3af',
                        '500' => '#6b7280', '600' => '#4b5563', '700' => '#374151', '800' => '#1f2937', '900' => '#111827',
                        'DEFAULT' => '#6b7280',
                    ],
                    'trueGray' => [
                        '50' => '#fafafa', '100' => '#f5f5f5', '200' => '#e5e5e5', '300' => '#d4d4d4', '400' => '#a3a3a3',
                        '500' => '#737373', '600' => '#525252', '700' => '#404040', '800' => '#262626', '900' => '#171717',
                        'DEFAULT' => '#737373',
                    ],
                    'warmGray' => [
                        '50' => '#fafaf9', '100' => '#f5f5f4', '200' => '#e7e5e4', '300' => '#d6d3d1', '400' => '#a8a29e',
                        '500' => '#78716c', '600' => '#57534e', '700' => '#44403c', '800' => '#292524', '900' => '#1c1917',
                        'DEFAULT' => '#78716c',
                    ],
                    'zinc' => [
                        '50' => '#fafafa', '100' => '#f4f4f5', '200' => '#e4e4e7', '300' => '#d4d4d8',
                        '400' => '#a1a1aa', '500' => '#71717a', '600' => '#52525b', '700' => '#3f3f46',
                        '800' => '#27272a', '900' => '#18181b', '950' => '#09090b', 'DEFAULT' => '#71717a',
                    ],
                    'stone' => [
                        '50' => '#fafaf9', '100' => '#f5f5f4', '200' => '#e7e5e4', '300' => '#d6d3d1',
                        '400' => '#a8a29e', '500' => '#78716c', '600' => '#57534e', '700' => '#44403c',
                        '800' => '#292524', '900' => '#1c1917', '950' => '#0c0a09', 'DEFAULT' => '#78716c',
                    ],
                    'red' => [
                        '50' => '#fef2f2', '100' => '#fee2e2', '200' => '#fecaca', '300' => '#fca5a5',
                        '400' => '#f87171', '500' => '#ef4444', '600' => '#dc2626', '700' => '#b91c1c',
                        '800' => '#991b1b', '900' => '#7f1d1d', '950' => '#450a0a', 'DEFAULT' => '#ef4444',
                    ],
                    'orange' => [
                        '50' => '#fff7ed', '100' => '#ffedd5', '200' => '#fed7aa', '300' => '#fdba74',
                        '400' => '#fb923c', '500' => '#f97316', '600' => '#ea580c', '700' => '#c2410c',
                        '800' => '#9a3412', '900' => '#7c2d12', '950' => '#431407', 'DEFAULT' => '#f97316',
                    ],
                    'amber' => [
                        '50' => '#fffbeb', '100' => '#fef3c7', '200' => '#fde68a', '300' => '#fcd34d',
                        '400' => '#fbbf24', '500' => '#f59e0b', '600' => '#d97706', '700' => '#b45309',
                        '800' => '#92400e', '900' => '#78350f', '950' => '#451a03', 'DEFAULT' => '#f59e0b',
                    ],
                    'yellow' => [
                        '50' => '#fefce8', '100' => '#fef9c3', '200' => '#fef08a', '300' => '#fde047',
                        '400' => '#facc15', '500' => '#eab308', '600' => '#ca8a04', '700' => '#a16207',
                        '800' => '#854d0e', '900' => '#713f12', '950' => '#422006', 'DEFAULT' => '#eab308',
                    ],
                    'lime' => [
                        '50' => '#f7fee7', '100' => '#ecfccb', '200' => '#d9f99d', '300' => '#bef264',
                        '400' => '#a3e635', '500' => '#84cc16', '600' => '#65a30d', '700' => '#4d7c0f',
                        '800' => '#3f6212', '900' => '#365314', '950' => '#1a2e05', 'DEFAULT' => '#84cc16',
                    ],
                    'green' => [
                        '50' => '#f0fdf4', '100' => '#dcfce7', '200' => '#bbf7d0', '300' => '#86efac',
                        '400' => '#4ade80', '500' => '#22c55e', '600' => '#16a34a', '700' => '#15803d',
                        '800' => '#166534', '900' => '#14532d', '950' => '#052e16', 'DEFAULT' => '#22c55e',
                    ],
                    'emerald' => [
                        '50' => '#ecfdf5', '100' => '#d1fae5', '200' => '#a7f3d0', '300' => '#6ee7b7',
                        '400' => '#34d399', '500' => '#10b981', '600' => '#059669', '700' => '#047857',
                        '800' => '#065f46', '900' => '#064e3b', '950' => '#022c22', 'DEFAULT' => '#10b981',
                    ],
                    'teal' => [
                        '50' => '#f0fdfa', '100' => '#ccfbf1', '200' => '#99f6e4', '300' => '#5eead4',
                        '400' => '#2dd4bf', '500' => '#14b8a6', '600' => '#0d9488', '700' => '#0f766e',
                        '800' => '#115e59', '900' => '#134e4a', '950' => '#042f2e', 'DEFAULT' => '#14b8a6',
                    ],
                    'cyan' => [
                        '50' => '#ecfeff', '100' => '#cffafe', '200' => '#a5f3fc', '300' => '#67e8f9',
                        '400' => '#22d3ee', '500' => '#06b6d4', '600' => '#0891b2', '700' => '#0e7490',
                        '800' => '#155e75', '900' => '#164e63', '950' => '#083344', 'DEFAULT' => '#06b6d4',
                    ],
                    'sky' => [
                        '50' => '#f0f9ff', '100' => '#e0f2fe', '200' => '#bae6fd', '300' => '#7dd3fc',
                        '400' => '#38bdf8', '500' => '#0ea5e9', '600' => '#0284c7', '700' => '#0369a1',
                        '800' => '#075985', '900' => '#0c4a6e', '950' => '#082f49', 'DEFAULT' => '#0ea5e9',
                    ],
                    'blue' => [
                        '50' => '#eff6ff', '100' => '#dbeafe', '200' => '#bfdbfe', '300' => '#93c5fd',
                        '400' => '#60a5fa', '500' => '#3b82f6', '600' => '#2563eb', '700' => '#1d4ed8',
                        '800' => '#1e40af', '900' => '#1e3a8a', '950' => '#172554', 'DEFAULT' => '#3b82f6',
                    ],
                    'indigo' => [
                        '50' => '#eef2ff', '100' => '#e0e7ff', '200' => '#c7d2fe', '300' => '#a5b4fc',
                        '400' => '#818cf8', '500' => '#6366f1', '600' => '#4f46e5', '700' => '#4338ca',
                        '800' => '#3730a3', '900' => '#312e81', '950' => '#1e1b4b', 'DEFAULT' => '#6366f1',
                    ],
                    'violet' => [
                        '50' => '#f5f3ff', '100' => '#ede9fe', '200' => '#ddd6fe', '300' => '#c4b5fd',
                        '400' => '#a78bfa', '500' => '#8b5cf6', '600' => '#7c3aed', '700' => '#6d28d9',
                        '800' => '#5b21b6', '900' => '#4c1d95', '950' => '#2e1065', 'DEFAULT' => '#8b5cf6',
                    ],
                    'purple' => [
                        '50' => '#faf5ff', '100' => '#f3e8ff', '200' => '#e9d5ff', '300' => '#d8b4fe',
                        '400' => '#c084fc', '500' => '#a855f7', '600' => '#9333ea', '700' => '#7e22ce',
                        '800' => '#6b21a8', '900' => '#581c87', '950' => '#3b0764', 'DEFAULT' => '#a855f7',
                    ],
                    'fuchsia' => [
                        '50' => '#fdf4ff', '100' => '#fae8ff', '200' => '#f5d0fe', '300' => '#f0abfc',
                        '400' => '#e879f9', '500' => '#d946ef', '600' => '#c026d3', '700' => '#a21caf',
                        '800' => '#86198f', '900' => '#701a75', '950' => '#4a044e', 'DEFAULT' => '#d946ef',
                    ],
                    'pink' => [
                        '50' => '#fdf2f8', '100' => '#fce7f3', '200' => '#fbcfe8', '300' => '#f9a8d4',
                        '400' => '#f472b6', '500' => '#ec4899', '600' => '#db2777', '700' => '#be185d',
                        '800' => '#9d174d', '900' => '#831843', '950' => '#500724', 'DEFAULT' => '#ec4899',
                    ],
                    'rose' => [
                        '50' => '#fff1f2', '100' => '#ffe4e6', '200' => '#fecdd3', '300' => '#fda4af',
                        '400' => '#fb7185', '500' => '#f43f5e', '600' => '#e11d48', '700' => '#be123c',
                        '800' => '#9f1239', '900' => '#881337', '950' => '#4c0519', 'DEFAULT' => '#f43f5e',
                    ],
                    'cream' => '#FFFDD0',
                    'beige' => '#F5F5DC',
                    'brown' => '#A52A2A',
                    'olive' => '#808000',
                    'maroon' => '#800000',
                    'navy' => '#000080',
                    'aquamarine' => '#7FFFD4',
                    'coral' => '#FF7F50',
                    'salmon' => '#FA8072',
                    'khaki' => '#F0E68C',
                    'lavender' => '#E6E6FA',
                    'crimson' => '#DC143C',
                    'plum' => '#DDA0DD',
                    'chocolate' => '#D2691E',
                ],
                'glassEffect' => [
                    'DEFAULT' => [
                        'base' => [
                            'position' => 'relative',
                            'overflow' => 'hidden',
                            'z-index' => '0',
                            'isolation' => 'isolate',
                            'transition' => 'transform 0.4s ease, box-shadow 0.4s ease',
                            'transform-style' => 'preserve-3d',
                            'transform' => 'perspective(1500px)',
                            'backdrop-filter' => 'blur(var(--glass-blur, 16px))',
                            '-webkit-backdrop-filter' => 'blur(var(--glass-blur, 16px))',
                        ],
                        'vars' => [
                            '--glass-bg' => 'rgba(40, 40, 50, 0.4)',
                            '--glass-blur' => '16px',
                            '--glass-border-from' => 'rgba(255, 255, 255, 0.15)',
                            '--glass-border-to' => 'rgba(255, 255, 255, 0.05)',
                            '--glass-glow-color' => 'hsla(var(--primary), 0.2)',
                            '--glass-glow-size' => '0px',
                            '--glass-glow-opacity' => '0',
                            '--mouse-x' => '50%',
                            '--mouse-y' => '50%',
                        ],
                        'glow' => [// ::before for the mouse-tracking glow
                            'content' => '""', 'position' => 'absolute', 'inset' => '0',
                            'z-index' => '-2', 'border-radius' => 'inherit',
                            'background' => 'radial-gradient(circle at var(--mouse-x) var(--mouse-y), var(--glass-glow-color) 0%, transparent 50%)',
                            'opacity' => 'var(--glass-glow-opacity)',
                            'transition' => 'opacity 0.4s ease',
                        ],
                        'overlay' => [ // ::after for background tint, border, and noise
                            'content' => '""', 'position' => 'absolute', 'inset' => '0',
                            'border-radius' => 'inherit', 'pointer-events' => 'none', 'z-index' => '-1',
                            'background-color' => 'var(--glass-bg)',
                        ],
                        'border' => [ 
                            'content' => '""', 'position' => 'absolute', 'inset' => '0',
                            'border-radius' => 'inherit',
                            'padding' => '1px', 
                            'background' => 'var(--glass-border-gradient, linear-gradient(135deg, var(--glass-border-from), var(--glass-border-to))) border-box',
                            '-webkit-mask' => 'linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0)',
                            '-webkit-mask-composite' => 'xor', 'mask-composite' => 'exclude',
                            'pointer-events' => 'none', 'z-index' => '-1',
                        ],
                        'hover-glow' => [
                            '--glass-glow-opacity' => '0.75',
                            '--glass-glow-size' => '350px',
                        ]
                    ],
                    'light' => [
                        'vars' => [
                            '--glass-bg' => 'rgba(255, 255, 255, 0.2)',
                            '--glass-blur' => '8px',
                            '--glass-border-from' => 'rgba(255, 255, 255, 0.7)',
                            '--glass-border-to' => 'rgba(255, 255, 255, 0.3)',
                            '--glass-glow-color' => 'hsla(var(--primary), 0.3)',
                        ],
                    ],
                    'dark' => [
                        'vars' => [
                            '--glass-bg' => 'rgba(20, 20, 30, 0.4)',
                            '--glass-blur' => '16px',
                            '--glass-border-from' => 'rgba(255, 255, 255, 0.1)',
                            '--glass-border-to' => 'rgba(255, 255, 255, 0.05)',
                            '--glass-glow-color' => 'hsla(var(--primary), 0.25)',
                        ],
                    ],
                    'primary' => ['vars' => ['--glass-glow-color' => 'hsla(var(--primary), 0.3)']],
                    'secondary' => ['vars' => ['--glass-glow-color' => 'hsla(var(--secondary), 0.3)']],
                    'accent' => ['vars' => ['--glass-glow-color' => 'hsla(var(--accent), 0.3)']],
                    'aurora' => [
                        'base' => ['backdrop-filter' => 'blur(20px) saturate(120%)', '-webkit-backdrop-filter' => 'blur(20px) saturate(120%)'],
                        'glow' => [
                            'background' => 'linear-gradient(135deg, hsla(var(--primary), 0.25) 0%, hsla(var(--accent), 0.25) 50%, hsla(var(--secondary), 0.25) 100%)',
                            'background-size' => '250% 250%',
                            'animation' => 'aurora-pan 16s ease infinite alternate',
                            'opacity' => '1',
                        ],
                        'hover-glow' => [
                            '--glass-glow-opacity' => '0.5',
                            '--glass-glow-size' => '350px',
                        ],
                    ],
                    'aurora-hover' => [
                        'base' => [ // Inherits from 'aurora'
                            'backdrop-filter' => 'blur(20px) saturate(120%)',
                            '-webkit-backdrop-filter' => 'blur(20px) saturate(120%)',
                        ],
                        'glow' => [ 
                            'background' => 'linear-gradient(135deg, hsla(var(--primary), 0.15) 0%, hsla(var(--accent), 0.15) 50%, hsla(var(--secondary), 0.15) 100%)',
                            'background-size' => '250% 250%',
                            'opacity' => '0.5', // Initially more subtle
                            'transition' => 'opacity 0.5s ease, transform 0.5s ease',
                        ],
                        'hover-glow' => [
                            'opacity' => '1',
                            'animation' => 'aurora-pan 12s ease infinite alternate',
                            'transform' => 'scale(1.2)' // Example: zoom in the glow on hover
                        ],
                        'hover' => [ // Default hover styles
                            'transform' => 'perspective(1500px) rotateX(var(--tilt-x, 0deg)) rotateY(var(--tilt-y, 0deg)) scale3d(1.03, 1.03, 1.03)',
                            'box-shadow' => '0 16px 48px -10px rgba(0, 0, 0, 0.5)',
                        ],
                    ],
                    'frosted' => [
                        'base' => ['backdrop-filter' => 'blur(40px)', '-webkit-backdrop-filter' => 'blur(40px)'],
                        'vars' => ['--glass-bg' => 'rgba(255, 255, 255, 0.05)'],
                    ],
                    'cyber' => [
                        'vars' => [
                            '--glass-bg' => 'rgba(20, 255, 255, 0.1)',
                            '--glass-border-from' => 'rgba(0, 255, 255, 0.8)',
                            '--glass-border-to' => 'rgba(0, 255, 255, 0.3)',
                            '--glass-glow-color' => 'hsla(180, 100%, 50%, 0.4)',
                        ],
                    ],
                    'lens-effect' => [
                        'base' => [
                            'position' => 'relative',
                            'border-radius' => 'var(--radius, 1.25rem)',
                            'transform-style' => 'preserve-3d',
                            'overflow' => 'hidden',
                        ],
                        'background' => [ // ::before
                            'content' => '""', 'position' => 'absolute', 'inset' => '-50px', // Larger to avoid blurred edges
                            'z-index' => '-2', 'border-radius' => 'inherit',
                            'background-image' => 'var(--glass-bg-image, none)',
                            'background-position' => 'center', 'background-size' => 'cover',
                            'filter' => 'blur(var(--glass-blur, 16px))',
                            'transform' => 'scale(1.1)', // Scale up to hide blurry edges
                        ],
                        'foreground' => [ // ::after
                            'content' => '""', 'position' => 'absolute', 'inset' => '0',
                            'z-index' => '-1', 'border-radius' => 'inherit',
                            'background-image' => 'var(--glass-bg-image, none)',
                            'background-position' => 'center', 'background-size' => 'cover',
                            '-webkit-mask-image' => 'radial-gradient(circle at center, black 0%, black var(--lens-clear-radius, 40%), transparent var(--lens-feather-radius, 70%))',
                            'mask-image' => 'radial-gradient(circle at center, black 0%, black var(--lens-clear-radius, 40%), transparent var(--lens-feather-radius, 70%))',
                        ],
                    ],
                ],
                // Add a specific handler/config for `glass-noise`
                'glassNoise' => [ // Moved noise configuration to its own key
                    'background-image' => 'url("data:image/svg+xml,%3Csvg viewBox=\'0 0 512 512\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cfilter id=\'noiseFilter\'%3E%3CfeTurbulence type=\'fractalNoise\' baseFrequency=\'0.75\' numOctaves=\'4\' stitchTiles=\'stitch\'/%3E%3C/filter%3E%3Crect width=\'100%25\' height=\'100%25\' filter=\'url(%23noiseFilter)\'/%3E%3C/svg%3E")',
                    'opacity' => 'var(--glass-noise-opacity, 0.08)',
                    'pointer-events' => 'none',
                    'z-index' => '3', // On top of everything
                    'background-blend-mode' => 'soft-light',
                ],

                'glassTilt' => [ // Configuration for the tilt effect
                    'base' => [
                        'transform-style' => 'preserve-3d',
                        'transform' => 'perspective(1500px)',
                    ],
                    'hover' => [
                        'transition' => 'transform 0.4s cubic-bezier(0.1, 0.8, 0.2, 1)',
                        'transform' => 'perspective(1500px) rotateX(var(--tilt-x, 0deg)) rotateY(var(--tilt-y, 0deg)) scale3d(1.05, 1.05, 1.05)',
                    ],
                ],

                'glassGlow' => [ // Configuration for the glow effect
                    'hover-glow' => [ // Styles for ::before on hover
                        '--glass-glow-opacity' => '0.5',
                        '--glass-glow-size' => '350px',
                    ],
                ],
                'mesh' => [
                    'gemini-header' => [
                        'light' => "radial-gradient(ellipse at 70% 20%, hsla(210, 90%, 70%, 0.5) 0px, transparent 40%), radial-gradient(ellipse at 30% 25%, hsla(280, 80%, 70%, 0.5) 0px, transparent 40%), radial-gradient(ellipse at 90% 30%, hsla(160, 80%, 65%, 0.4) 0px, transparent 50%), radial-gradient(ellipse at 50% 20%, hsla(40, 90%, 65%, 0.4) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(ellipse at 70% 20%, hsla(210, 90%, 60%, 0.7) 0px, transparent 40%), radial-gradient(ellipse at 30% 25%, hsla(280, 80%, 55%, 0.6) 0px, transparent 40%), radial-gradient(ellipse at 90% 30%, hsla(160, 80%, 50%, 0.5) 0px, transparent 50%), radial-gradient(ellipse at 50% 20%, hsla(40, 90%, 55%, 0.5) 0px, transparent 50%)",
                        'animation' => ['name' => 'gemini-flow', 'duration' => '45s', 'timing' => 'linear', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'twilight-aurora' => [
                        'light' => "radial-gradient(at 80% 20%, hsla(190, 100%, 80%, 0.7) 0px, transparent 50%), radial-gradient(at 10% 15%, hsla(310, 100%, 85%, 0.7) 0px, transparent 50%), radial-gradient(at 50% 70%, hsla(250, 100%, 88%, 0.6) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 80% 20%, hsla(190, 95%, 15%, 0.9) 0px, transparent 50%), radial-gradient(at 10% 15%, hsla(310, 90%, 20%, 0.9) 0px, transparent 50%), radial-gradient(at 50% 70%, hsla(250, 90%, 15%, 1) 0px, transparent 50%)",
                        'animation' => ['name' => 'aurora-flow', 'duration' => '35s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'vibrant-fusion' => [
                        'light' => "radial-gradient(at 15% 85%, hsla(330, 100%, 85%, 0.5) 0px, transparent 40%), radial-gradient(at 85% 20%, hsla(160, 100%, 80%, 0.6) 0px, transparent 40%), radial-gradient(at 40% 40%, hsla(0, 100%, 85%, 0.6) 0px, transparent 40%)",
                        'dark'  => "radial-gradient(at 15% 85%, hsla(330, 95%, 15%, 1) 0px, transparent 50%), radial-gradient(at 85% 20%, hsla(160, 90%, 20%, 0.9) 0px, transparent 50%), radial-gradient(at 40% 40%, hsla(0, 95%, 25%, 0.9) 0px, transparent 50%)",
                        'animation' => ['name' => 'nebula-drift', 'duration' => '50s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'gemini-warm' => [
                        'light' => "radial-gradient(at 90% 10%, hsla(30, 100%, 80%, 0.7) 0px, transparent 40%), radial-gradient(at 20% 85%, hsla(330, 95%, 85%, 0.6) 0px, transparent 50%), radial-gradient(at 50% 50%, hsla(210, 100%, 90%, 0.5) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 90% 10%, hsla(30, 90%, 20%, 0.9) 0px, transparent 40%), radial-gradient(at 20% 85%, hsla(330, 85%, 25%, 0.8) 0px, transparent 50%), radial-gradient(at 50% 50%, hsla(210, 90%, 15%, 0.8) 0px, transparent 60%)",
                        'animation' => ['name' => 'gemini-flow', 'duration' => '40s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate-reverse']
                    ],
                    'spotlight-blue' => [
                        'light' => "radial-gradient(ellipse at center, hsla(210, 100%, 80%, 0.9) 0px, transparent 60%)",
                        'dark'  => "radial-gradient(ellipse at center, hsla(210, 100%, 15%, 1) 0px, transparent 60%)",
                        'animation' => false
                    ],
                    'spotlight-red' => [
                        'light' => "radial-gradient(ellipse at center, hsla(0, 100%, 85%, 0.9) 0px, transparent 60%)",
                        'dark'  => "radial-gradient(ellipse at center, hsla(0, 100%, 20%, 1) 0px, transparent 60%)",
                        'animation' => ['name' => 'gentle-pulse-scale', 'duration' => '10s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate'] // হালকা পালস ইফেক্ট
                    ],
                    'pastel-dream' => [
                        'light' => "radial-gradient(at 20% 20%, hsla(180, 100%, 85%, 0.7) 0px, transparent 50%), radial-gradient(at 80% 20%, hsla(270, 100%, 90%, 0.7) 0px, transparent 50%), radial-gradient(at 50% 80%, hsla(30, 100%, 85%, 0.7) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 20% 20%, hsla(180, 90%, 15%, 0.7) 0px, transparent 50%), radial-gradient(at 80% 20%, hsla(270, 85%, 20%, 0.6) 0px, transparent 50%), radial-gradient(at 50% 80%, hsla(30, 95%, 25%, 0.6) 0px, transparent 50%)",
                        'animation' => ['name' => 'subtle-float', 'duration' => '25s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'midnight-velvet' => [
                        'light' => "radial-gradient(at 10% 85%, hsla(240, 100%, 85%, 0.6) 0px, transparent 50%), radial-gradient(at 85% 15%, hsla(300, 100%, 88%, 0.6) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 10% 85%, hsla(240, 95%, 15%, 1) 0px, transparent 50%), radial-gradient(at 85% 15%, hsla(300, 90%, 20%, 0.9) 0px, transparent 50%)",
                        'animation' => ['name' => 'axis-rotation', 'duration' => '30s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'emerald-water' => [
                        'light' => "radial-gradient(at 90% 90%, hsla(160, 100%, 85%, 0.8) 0px, transparent 50%), radial-gradient(at 10% 10%, hsla(190, 100%, 80%, 0.7) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 90% 90%, hsla(160, 90%, 15%, 0.9) 0px, transparent 50%), radial-gradient(at 10% 10%, hsla(190, 85%, 15%, 0.9) 0px, transparent 50%)",
                        'animation' => ['name' => 'stream-flow', 'duration' => '20s', 'timing' => 'linear', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'citrus-burst' => [
                        'light' => "radial-gradient(at 80% 20%, hsla(50, 100%, 80%, 0.9) 0px, transparent 50%), radial-gradient(at 15% 75%, hsla(140, 100%, 85%, 0.7) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 80% 20%, hsla(50, 100%, 20%, 1) 0px, transparent 50%), radial-gradient(at 15% 75%, hsla(140, 95%, 15%, 0.8) 0px, transparent 50%)",
                        'animation' => ['name' => 'subtle-float', 'duration' => '28s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'nebula' => [
                        'light' => "radial-gradient(at 80% 80%, hsla(300, 100%, 85%, 0.6) 0px, transparent 50%), radial-gradient(at 10% 20%, hsla(20, 100%, 85%, 0.5) 0px, transparent 50%), radial-gradient(at 50% 50%, hsla(260, 100%, 90%, 0.5) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 80% 80%, hsla(300, 95%, 20%, 0.9) 0px, transparent 50%), radial-gradient(at 10% 20%, hsla(20, 90%, 25%, 0.8) 0px, transparent 50%), radial-gradient(at 50% 50%, hsla(260, 90%, 10%, 0.8) 0px, transparent 50%)",
                        'animation' => ['name' => 'nebula-drift', 'duration' => '60s', 'timing' => 'linear', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'aurora-borealis' => [
                        'light' => "radial-gradient(at 20% 80%, hsla(120, 100%, 85%, 0.7) 0px, transparent 50%), radial-gradient(at 80% 20%, hsla(280, 100%, 88%, 0.6) 0px, transparent 50%), radial-gradient(at 50% 50%, hsla(320, 100%, 85%, 0.6) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 20% 80%, hsla(120, 95%, 20%, 0.8) 0px, transparent 50%), radial-gradient(at 80% 20%, hsla(280, 90%, 25%, 0.7) 0px, transparent 50%), radial-gradient(at 50% 50%, hsla(320, 95%, 15%, 0.6) 0px, transparent 50%)",
                        'animation' => ['name' => 'aurora-flow', 'duration' => '28s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate-reverse']
                    ],
                    'cyber-flicker' => [
                        'light' => "radial-gradient(at 15% 15%, hsla(320, 100%, 85%, 0.7) 0px, transparent 40%), radial-gradient(at 85% 85%, hsla(185, 100%, 80%, 0.7) 0px, transparent 40%)",
                        'dark'  => "radial-gradient(at 15% 15%, hsla(320, 100%, 20%, 1) 0px, transparent 40%), radial-gradient(at 85% 85%, hsla(185, 95%, 15%, 1) 0px, transparent 40%)",
                        'animation' => ['name' => 'glitch-shift', 'duration' => '5s', 'timing' => 'steps(4, end)', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'lavender-fields' => [
                        'light' => "radial-gradient(at 5% 10%, hsla(250, 100%, 90%, 0.8) 0px, transparent 50%), radial-gradient(at 80% 50%, hsla(270, 100%, 88%, 0.7) 0px, transparent 50%), radial-gradient(at 40% 90%, hsla(320, 100%, 92%, 0.7) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 5% 10%, hsla(250, 90%, 20%, 0.8) 0px, transparent 50%), radial-gradient(at 80% 50%, hsla(270, 85%, 15%, 0.7) 0px, transparent 50%), radial-gradient(at 40% 90%, hsla(320, 90%, 25%, 0.7) 0px, transparent 50%)",
                        'animation' => ['name' => 'subtle-float', 'duration' => '35s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'solar-flare' => [
                        'light' => "radial-gradient(circle at 50% 50%, hsla(10, 100%, 80%, 0.7) 0px, transparent 40%), radial-gradient(circle at 50% 50%, hsla(45, 100%, 75%, 0.7) 0px, transparent 40%)",
                        'dark'  => "radial-gradient(circle at center, hsla(10, 100%, 20%, 1) 0px, transparent 40%), radial-gradient(circle at center, hsla(45, 100%, 15%, 1) 0px, transparent 40%)",
                        'animation' => ['name' => 'gentle-pulse-scale', 'duration' => '15s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'ocean-depth' => [
                        'light' => "radial-gradient(at 10% 10%, hsla(210, 100%, 85%, 0.7) 0px, transparent 50%), radial-gradient(at 80% 90%, hsla(180, 100%, 85%, 0.6) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 10% 10%, hsla(210, 95%, 10%, 1) 0px, transparent 50%), radial-gradient(at 80% 90%, hsla(180, 90%, 10%, 0.9) 0px, transparent 50%)",
                        'animation' => ['name' => 'stream-flow', 'duration' => '22s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'candy-floss' => [
                        'light' => "radial-gradient(at 90% 15%, hsla(330, 100%, 88%, 0.8) 0px, transparent 50%), radial-gradient(at 15% 80%, hsla(200, 100%, 85%, 0.7) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 90% 15%, hsla(330, 95%, 25%, 0.9) 0px, transparent 50%), radial-gradient(at 15% 80%, hsla(200, 90%, 20%, 0.8) 0px, transparent 50%)",
                        'animation' => ['name' => 'aurora-flow', 'duration' => '30s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'galactic-sparkle' => [
                        'light' => "radial-gradient(at 20% 20%, hsla(240, 100%, 85%, 0.7) 0px, transparent 50%), radial-gradient(at 80% 80%, hsla(300, 100%, 90%, 0.6) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 20% 20%, hsla(240, 95%, 15%, 0.8) 0px, transparent 50%), radial-gradient(at 80% 80%, hsla(300, 90%, 20%, 0.7) 0px, transparent 50%)",
                        'animation' => ['name' => 'nebula-drift', 'duration' => '40s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                    'neon-jungle' => [
                        'light' => "radial-gradient(at 10% 90%, hsla(140, 80%, 70%, 0.6) 0px, transparent 50%), radial-gradient(at 90% 20%, hsla(320, 90%, 75%, 0.7) 0px, transparent 50%)",
                        'dark'  => "radial-gradient(at 10% 90%, hsla(140, 70%, 25%, 0.8) 0px, transparent 50%), radial-gradient(at 90% 20%, hsla(320, 80%, 30%, 0.9) 0px, transparent 50%)",
                        'animation' => ['name' => 'aurora-flow', 'duration' => '25s', 'timing' => 'ease-in-out', 'iteration' => 'infinite', 'direction' => 'alternate']
                    ],
                ],
                'gradientColorStops' => ['theme' => 'colors'], // For from-*, via-*, to-*
                'gradientColorStopPositions' => [ // For from-10%, via-50%, to-90% (Tailwind v3.3+)
                    '0%' => '0%', '5%' => '5%', '10%' => '10%', '15%' => '15%', '20%' => '20%',
                    '25%' => '25%', '30%' => '30%', '35%' => '35%', '40%' => '40%', '45%' => '45%',
                    '50%' => '50%', '55%' => '55%', '60%' => '60%', '65%' => '65%', '70%' => '70%',
                    '75%' => '75%', '80%' => '80%', '85%' => '85%', '90%' => '90%', '95%' => '95%', '100%' => '100%',
                ],
                'saturate' => [ // For saturate-* and backdrop-saturate-*
                    '0' => '0', '50' => '.5', '100' => '1', '150' => '1.5', '200' => '2',
                    'DEFAULT' => '1',
                ],
                'spacing' => [ /* ... All spacing ... */ 
                    'px' => '1px', '0' => '0px', '0.5' => '0.125rem', '1' => '0.25rem', '1.5' => '0.375rem',
                    '2' => '0.5rem', '2.5' => '0.625rem', '3' => '0.75rem', '3.5' => '0.875rem', '4' => '1rem',
                    '5' => '1.25rem', '6' => '1.5rem', '7' => '1.75rem', '8' => '2rem', '9' => '2.25rem',
                    '10' => '2.5rem', '11' => '2.75rem', '12' => '3rem', '14' => '3.5rem', '16' => '4rem',
                    '20' => '5rem', '24' => '6rem', '28' => '7rem', '32' => '8rem', '36' => '9rem',
                    '40' => '10rem', '44' => '11rem', '48' => '12rem', '52' => '13rem', '56' => '14rem',
                    '60' => '15rem', '64' => '16rem', '72' => '18rem', '80' => '20rem', '96' => '24rem',
                    // --- Premium Fluid Spacing (Auto-scales between Mobile and Desktop) ---
                    'fluid-1'  => 'clamp(0.125rem, 0.06rem + 0.31vw, 0.25rem)',
                    'fluid-2'  => 'clamp(0.25rem, 0.13rem + 0.63vw, 0.5rem)',
                    'fluid-3'  => 'clamp(0.5rem, 0.38rem + 0.63vw, 0.75rem)',
                    'fluid-4'  => 'clamp(0.5rem, 0.25rem + 1.25vw, 1rem)',
                    'fluid-5'  => 'clamp(0.75rem, 0.50rem + 1.25vw, 1.25rem)',
                    'fluid-6'  => 'clamp(0.75rem, 0.38rem + 1.88vw, 1.5rem)',
                    'fluid-8'  => 'clamp(1rem, 0.50rem + 2.50vw, 2rem)',
                    'fluid-10' => 'clamp(1.25rem, 0.63rem + 3.13vw, 2.5rem)',
                    'fluid-12' => 'clamp(1.5rem, 0.75rem + 3.75vw, 3rem)',
                    'fluid-16' => 'clamp(2rem, 1.00rem + 5.00vw, 4rem)',
                    'fluid-20' => 'clamp(2.5rem, 1.25rem + 6.25vw, 5rem)',
                    'fluid-24' => 'clamp(3rem, 1.50rem + 7.50vw, 6rem)',
                    'fluid-32' => 'clamp(4rem, 2.00rem + 10.00vw, 8rem)',
                    'fluid-40' => 'clamp(5rem, 2.50rem + 12.50vw, 10rem)',
                    'fluid-48' => 'clamp(6rem, 3.00rem + 15.00vw, 12rem)',
                    'fluid-64' => 'clamp(8rem, 4.00rem + 20.00vw, 16rem)',
                    'fluid-80' => 'clamp(10rem, 5.00rem + 25.00vw, 20rem)',
                    'fluid-96' => 'clamp(12rem, 6.00rem + 30.00vw, 24rem)',
                ],
                'inset' => ['spacing', 'auto' => 'auto', '1/2' => '50%', '1/3' => '33.333333%', '2/3' => '66.666667%', '1/4' => '25%', '3/4' => '75%', 'full' => '100%'],
                'width' => ['spacing', 'auto' => 'auto', '1/2' => '50%', '1/3' => '33.333333%', '2/3' => '66.666667%', '1/4' => '25%', '3/4' => '75%', 'full' => '100%', 'screen' => '100vw', 'min' => 'min-content', 'max' => 'max-content', 'fit' => 'fit-content'],
                'height' => ['spacing', 'auto' => 'auto', '1/2' => '50%', '1/4' => '25%', '3/4' => '75%', 'full' => '100%', 'screen' => '100vh', 'svh' => '100svh', 'lvh' => '100lvh', 'dvh' => '100dvh', 'min' => 'min-content', 'max' => 'max-content', 'fit' => 'fit-content'],
                'minWidth' => ['0' => '0px', 'full' => '100%', 'min' => 'min-content', 'max' => 'max-content', 'fit' => 'fit-content'],
                'maxWidth' => ['screens', '0' => '0px', 'none' => 'none', 'full' => '100%', 'min' => 'min-content', 'max' => 'max-content', 'fit' => 'fit-content', 'xs' => '20rem', 'sm' => '24rem', 'md' => '28rem', 'lg' => '32rem', 'xl' => '36rem', '2xl' => '42rem', '3xl' => '48rem', '4xl' => '56rem', '5xl' => '64rem', '6xl' => '72rem', '7xl' => '80rem'],
                'minHeight' => ['0' => '0px', 'full' => '100%', 'screen' => '100vh', 'svh' => '100svh', 'lvh' => '100lvh', 'dvh' => '100dvh'],
                'maxHeight' => ['spacing', 'full' => '100%', 'screen' => '100vh', 'min' => 'min-content', 'max' => 'max-content', 'fit' => 'fit-content'],
                'fontSize' => [
                    // --- Regular Static Sizes ---
                    'xs'  => ['0.75rem', ['lineHeight' => '1rem']],    
                    'sm'  => ['0.875rem', ['lineHeight' => '1.25rem']],
                    'base'=> ['1rem', ['lineHeight' => '1.5rem']],  
                    'lg'  => ['1.125rem', ['lineHeight' => '1.75rem']],
                    'xl'  => ['1.25rem', ['lineHeight' => '1.75rem']], 
                    '2xl' => ['1.5rem', ['lineHeight' => '2rem']],
                    '3xl' => ['1.875rem', ['lineHeight' => '2.25rem']],
                    '4xl' => ['2.25rem', ['lineHeight' => '2.5rem']],
                    '5xl' => ['3rem', ['lineHeight' => '1']],        
                    '6xl' => ['3.75rem', ['lineHeight' => '1']],
                    '7xl' => ['4.5rem', ['lineHeight' => '1']],       
                    '8xl' => ['6rem', ['lineHeight' => '1']],
                    '9xl' => ['8rem', ['lineHeight' => '1']],
                    // --- Premium Fluid / Auto-Scaling Sizes ---
                    'fluid-xs'   => ['clamp(0.70rem, 0.66rem + 0.20vw, 0.75rem)', ['lineHeight' => '1rem']],
                    'fluid-sm'   => ['clamp(0.80rem, 0.76rem + 0.20vw, 0.875rem)', ['lineHeight' => '1.25rem']],
                    'fluid-base' => ['clamp(0.875rem, 0.81rem + 0.31vw, 1rem)', ['lineHeight' => '1.5rem']],
                    'fluid-lg'   => ['clamp(1rem, 0.94rem + 0.31vw, 1.125rem)', ['lineHeight' => '1.75rem']],
                    'fluid-xl'   => ['clamp(1.125rem, 1.06rem + 0.31vw, 1.25rem)', ['lineHeight' => '1.75rem']],
                    'fluid-2xl'  => ['clamp(1.25rem, 1.13rem + 0.63vw, 1.5rem)', ['lineHeight' => '2rem']],
                    'fluid-3xl'  => ['clamp(1.5rem, 1.31rem + 0.94vw, 1.875rem)', ['lineHeight' => '2.25rem']],
                    'fluid-4xl'  => ['clamp(1.75rem, 1.50rem + 1.25vw, 2.25rem)', ['lineHeight' => '2.5rem']],
                    'fluid-5xl'  => ['clamp(2rem, 1.50rem + 2.50vw, 3rem)', ['lineHeight' => '1']],
                    'fluid-6xl'  => ['clamp(2.5rem, 1.88rem + 3.13vw, 3.75rem)', ['lineHeight' => '1']],
                    'fluid-7xl'  => ['clamp(3rem, 2.25rem + 3.75vw, 4.5rem)', ['lineHeight' => '1']],
                    'fluid-8xl'  => ['clamp(3.5rem, 2.25rem + 6.25vw, 6rem)', ['lineHeight' => '1']],
                    'fluid-9xl'  => ['clamp(4.5rem, 2.75rem + 8.75vw, 8rem)', ['lineHeight' => '1']],
                ],
                'lineHeight' => [
                    'none' => '1', 'tight' => '1.25', 'snug' => '1.375', 'normal' => '1.5', 'relaxed' => '1.625', 'loose' => '2',
                    '3' => '.75rem', '4' => '1rem', '5' => '1.25rem', '6' => '1.5rem', '7' => '1.75rem', '8' => '2rem', '9' => '2.25rem', '10' => '2.5rem',
                    // --- Premium Fluid Line Heights ---
                    'fluid-tight'  => 'clamp(1.1, 1.2vw + 1rem, 1.25)',
                    'fluid-normal' => 'clamp(1.35, 1.5vw + 1rem, 1.5)',
                    'fluid-loose'  => 'clamp(1.6, 2vw + 1rem, 2)',
                ],
                'letterSpacing' => [
                    'tighter' => '-0.05em', 'tight' => '-0.025em', 'normal' => '0em', 'wide' => '0.025em', 'wider' => '0.05em', 'widest' => '0.1em',
                ],
                'textDecorationThickness' => ['auto' => 'auto', 'from-font' => 'from-font', '0' => '0px', '1' => '1px', '2' => '2px', '4' => '4px', '8' => '8px'],
                'textUnderlineOffset' => ['auto' => 'auto', '0' => '0px', '1' => '1px', '2' => '2px', '4' => '4px', '8' => '8px'],
                'outlineWidth' => ['0'=>'0px', '1'=>'1px', '2'=>'2px', '4'=>'4px', '8'=>'8px'],
                'outlineOffset' => ['0'=>'0px', '1'=>'1px', '2'=>'2px', '4'=>'4px', '8'=>'8px'],
                'borderRadius' => [
                    'none' => '0px',
                    'sm' => 'calc(var(--radius, 0.25rem) - 0.125rem)',
                    'DEFAULT' => 'var(--radius, 0.5rem)',
                    'md' => 'var(--radius, 0.5rem)',
                    'lg' => 'calc(var(--radius, 0.5rem) + 0.25rem)',
                    'xl' => 'calc(var(--radius, 0.5rem) + 0.5rem)',
                    '2xl' => '1rem',
                    '3xl' => '1.5rem',
                    'full' => '9999px',
                    'dock' => '1.8rem',
                    'dock-lg' => '2.5rem',
                    'button' => '3rem',
                    'button-lg' => '4rem',
                    // --- Fluid Radius (Scales based on screen size) ---
                    'fluid-sm'  => 'clamp(0.125rem, 0.06rem + 0.31vw, 0.25rem)',
                    'fluid-md'  => 'clamp(0.25rem, 0.13rem + 0.63vw, 0.5rem)',
                    'fluid-lg'  => 'clamp(0.375rem, 0.19rem + 0.94vw, 0.75rem)',
                    'fluid-xl'  => 'clamp(0.5rem, 0.25rem + 1.25vw, 1rem)',
                    'fluid-2xl' => 'clamp(0.75rem, 0.38rem + 1.88vw, 1.5rem)',
                    'fluid-3xl' => 'clamp(1rem, 0.50rem + 2.50vw, 2rem)',
                ],
                'borderWidth' => [ /* ... */ 
                    'DEFAULT' => '1px', '0' => '0px', '2' => '2px', '4' => '4px', '8' => '8px',
                ],
                'fontWeight' => [ /* ... */ 
                    'thin' => '100', 'extralight' => '200', 'light' => '300', 'normal' => '400',
                    'medium' => '500', 'semibold' => '600', 'bold' => '700', 'extrabold' => '800', 'black' => '900',
                ],
                'textIndent' => ['theme' => 'spacing'],
                'textStrokeWidth' => ['theme' => 'borderWidth'],
                'fontVariantNumeric' => [
                    'normal'          => 'normal',
                    'ordinal'         => 'ordinal',
                    'slashed-zero'    => 'slashed-zero',
                    'lining-nums'     => 'lining-nums',
                    'oldstyle-nums'   => 'oldstyle-nums',
                    'proportional-nums' => 'proportional-nums',
                    'tabular-nums'    => 'tabular-nums',
                    'diagonal-fractions' => 'diagonal-fractions',
                    'stacked-fractions'  => 'stacked-fractions',
                ],
                'boxShadow' => [
                    'sm' => '0 1px 2px 0 rgb(0 0 0 / 0.05)',
                    'DEFAULT' => '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)',
                    'md' => '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)',
                    'lg' => '0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)',
                    'xl' => '0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1)',
                    '2xl' => '0 25px 50px -12px rgb(0 0 0 / 0.25)',
                    'inner' => 'inset 0 2px 4px 0 rgb(0 0 0 / 0.05)',
                    'glass' => 'inset 0 1px 1px 0 rgb(255 255 255 / 0.1), 0 2px 4px 0 rgb(0 0 0 / 0.1)',
                    'none' => 'none',
                ],
                'opacity' => [
                    '0' => '0', '5' => '0.05', '10' => '0.1', '15' => '0.15', '20' => '0.2',
                    '25' => '0.25', '30' => '0.3', '35' => '0.35', '40' => '0.4', '45' => '0.45',
                    '50' => '0.5', '55' => '0.55', '60' => '0.6', '65' => '0.65', '70' => '0.7',
                    '75' => '0.75', '80' => '0.8', '85' => '0.85', '90' => '0.9', '95' => '0.95', '100' => '1',
                    'DEFAULT' => '1',
                ],
                'blur' => ['none'=>'0','sm'=>'4px','DEFAULT'=>'8px','md'=>'12px','lg'=>'16px','xl'=>'24px','2xl'=>'40px','3xl'=>'64px'],
                'glass' => [
                    'DEFAULT' => [ // Default light glass
                        'background' => 'rgba(255, 255, 255, 0.2)',
                        'border'     => 'rgba(255, 255, 255, 0.15)',
                        'blur'       => ['theme' => 'blur.DEFAULT']
                    ],
                    'dark' => [    // Default dark glass
                        'background' => 'rgba(30, 41, 59, 0.3)', // slate-800 with alpha
                        'border'     => 'rgba(71, 85, 105, 0.3)',  // slate-600 with alpha
                        'blur'       => ['theme' => 'blur.DEFAULT']
                    ],
                    'light' => [   // More opaque light glass
                        'background' => 'rgba(255, 255, 255, 0.5)',
                        'border'     => 'rgba(226, 232, 240, 0.5)', // slate-200 with alpha
                        'blur'       => ['theme' => 'blur.sm']
                    ],
                    'primary' => [
                        'background' => $this->resolveThemeValue(['theme' => 'colors.primary.DEFAULT'], 'rgba(59,130,246,1)', false, 0.15), // Resolve and apply alpha
                        'border'     => $this->resolveThemeValue(['theme' => 'colors.primary.DEFAULT'], 'rgba(59,130,246,1)', false, 0.25),
                        'blur'       => ['theme' => 'blur.DEFAULT']
                    ],
                    'secondary' => [
                        'background' => $this->resolveThemeValue(['theme' => 'colors.secondary.DEFAULT'], 'rgba(100,116,139,1)', false, 0.2), // slate-500
                        'border'     => $this->resolveThemeValue(['theme' => 'colors.secondary.DEFAULT'], 'rgba(100,116,139,1)', false, 0.3),
                        'blur'       => ['theme' => 'blur.DEFAULT']
                    ],
                    'accent' => [
                        'background' => $this->resolveThemeValue(['theme' => 'colors.accent.DEFAULT'], 'rgba(236,72,153,1)', false, 0.15), // pink-500
                        'border'     => $this->resolveThemeValue(['theme' => 'colors.accent.DEFAULT'], 'rgba(236,72,153,1)', false, 0.25),
                        'blur'       => ['theme' => 'blur.md']
                    ],
                    'destructive' => [
                        'background' => $this->resolveThemeValue(['theme' => 'colors.destructive.DEFAULT'], 'rgba(220,38,38,1)', false, 0.2), // red-600
                        'border'     => $this->resolveThemeValue(['theme' => 'colors.destructive.DEFAULT'], 'rgba(220,38,38,1)', false, 0.3),
                        'blur'       => ['theme' => 'blur.DEFAULT']
                    ],
                    'success' => [
                        'background' => $this->resolveThemeValue(['theme' => 'colors.success.DEFAULT'], 'rgba(34,197,94,1)', false, 0.15), // green-500
                        'border'     => $this->resolveThemeValue(['theme' => 'colors.success.DEFAULT'], 'rgba(34,197,94,1)', false, 0.25),
                        'blur'       => ['theme' => 'blur.sm']
                    ],
                    'warning' => [
                        'background' => $this->resolveThemeValue(['theme' => 'colors.warning.DEFAULT'], 'rgba(245,158,11,1)', false, 0.2), // amber-500
                        'border'     => $this->resolveThemeValue(['theme' => 'colors.warning.DEFAULT'], 'rgba(245,158,11,1)', false, 0.3),
                        'blur'       => ['theme' => 'blur.DEFAULT']
                    ],
                    'info' => [
                        'background' => $this->resolveThemeValue(['theme' => 'colors.info.DEFAULT'], 'rgba(14,165,233,1)', false, 0.15), // sky-500
                        'border'     => $this->resolveThemeValue(['theme' => 'colors.info.DEFAULT'], 'rgba(14,165,233,1)', false, 0.25),
                        'blur'       => ['theme' => 'blur.DEFAULT']
                    ],
                    // Specific use-case glass
                    'sidebar' => [
                        'background' => 'rgba(15, 23, 42, 0.6)', // slate-900 with more opacity
                        'border'     => 'rgba(51, 65, 85, 0.5)',  // slate-700 with opacity
                        'blur'       => ['theme' => 'blur.lg']
                    ],
                    'modal' => [
                        'background' => 'rgba(255, 255, 255, 0.1)',
                        'border'     => 'rgba(255, 255, 255, 0.05)',
                        'blur'       => ['theme' => 'blur.xl']
                    ],
                    'navbar' => [
                        'background' => 'rgba(255, 255, 255, 0.05)', // Very subtle
                        'border'     => 'rgba(255, 255, 255, 0.1)',
                        'blur'       => ['theme' => 'blur.3xl']     // Max blur
                    ]
                ],
                'brightness' => ['0'=>'0','50'=>'.5','75'=>'.75','90'=>'.9','95'=>'.95','100'=>'1','105'=>'1.05','110'=>'1.1','125'=>'1.25','150'=>'1.5','200'=>'2'],
                'contrast' => ['0'=>'0','50'=>'.5','75'=>'.75','100'=>'1','125'=>'1.25','150'=>'1.5','200'=>'2'], // Example
                'dropShadow' => [ // Example for drop-shadow filter
                    'sm' => 'drop-shadow(0 1px 1px rgb(0 0 0 / 0.05))',
                    'DEFAULT' => ['drop-shadow(0 1px 2px rgb(0 0 0 / 0.1))', 'drop-shadow(0 1px 1px rgb(0 0 0 / 0.06))'], // Can be array for multiple shadows
                    'lg' => 'drop-shadow(0 10px 8px rgb(0 0 0 / 0.04)) drop-shadow(0 4px 3px rgb(0 0 0 / 0.1))',
                ],
                'transitionProperty' => [
                    'none' => 'none',
                    'all' => 'all',
                    'DEFAULT' => 'color, background-color, border-color, text-decoration-color, fill, stroke, opacity, box-shadow, transform, filter, backdrop-filter', // Ensure transform is here
                    'colors' => 'color, background-color, border-color, text-decoration-color, fill, stroke',
                    'opacity' => 'opacity',
                    'shadow' => 'box-shadow',
                    'transform' => 'transform', // Ensure this key exists
                ],
                'transitionDuration' => [
                    'DEFAULT'=>'150ms','75'=>'75ms','100'=>'100ms','150'=>'150ms','200'=>'200ms','300'=>'300ms','500'=>'500ms','700'=>'700ms','1000'=>'1000ms',
                ],
                'transitionTimingFunction' => [
                    'DEFAULT'=>'cubic-bezier(0.4, 0, 0.2, 1)','linear'=>'linear','in'=>'cubic-bezier(0.4, 0, 1, 1)','out'=>'cubic-bezier(0, 0, 0.2, 1)','in-out'=>'cubic-bezier(0.4, 0, 0.2, 1)','bouncy' => 'cubic-bezier(0.175, 0.885, 0.32, 1.5)',
                ],
                'transitionDelay' => [
                    '75'=>'75ms','100'=>'100ms','150'=>'150ms','200'=>'200ms','300'=>'300ms','500'=>'500ms','700'=>'700ms','1000'=>'1000ms',
                ],
                'perspective' => [
                    'none' => 'none',
                    'sm'   => '500px',
                    'DEFAULT' => '800px',
                    'md'   => '1000px',
                    'lg'   => '1500px',
                ],
                'perspectiveOrigin' => [
                    'center' => 'center',
                    'top' => 'top',
                    'top-right' => 'top right',
                    'right' => 'right',
                    'bottom-right' => 'bottom right',
                    'bottom' => 'bottom',
                    'bottom-left' => 'bottom left',
                    'left' => 'left',
                    'top-left' => 'top left',
                ],

                'animation' => [
                    // Default Tailwind animations
                    'none' => 'none',
                    'spin' => 'spin',
                    'ping' => 'ping',
                    'pulse' => 'pulse',
                    'bounce' => 'bounce',

                    // Gradient Animation
                    'gradient' => 'gradientAnimation',
                    'bg-pan' => 'bgPan',
                    'bgMove' => 'bgMove',
                    'move-bg' => 'moveBackground',

                    // Attention seekers
                    'flash' => 'flash',
                    'rubberBand' => 'rubberBand',
                    'shakeX' => 'shakeX',
                    'shakeY' => 'shakeY',
                    'headShake' => 'headShake',
                    'swing' => 'swing',
                    'tada' => 'tada',
                    'wobble' => 'wobble',
                    'jello' => 'jello',
                    'heartBeat' => 'heartBeat',

                    // Back entrances
                    'backInDown' => 'backInDown',
                    'backInLeft' => 'backInLeft',
                    'backInRight' => 'backInRight',
                    'backInUp' => 'backInUp',

                    // Back exits
                    'backOutDown' => 'backOutDown',
                    'backOutLeft' => 'backOutLeft',
                    'backOutRight' => 'backOutRight',
                    'backOutUp' => 'backOutUp',

                    // Bouncing entrances
                    'bounceIn' => 'bounceIn',
                    'bounceInDown' => 'bounceInDown',
                    'bounceInLeft' => 'bounceInLeft',
                    'bounceInRight' => 'bounceInRight',
                    'bounceInUp' => 'bounceInUp',

                    // Bouncing exits
                    'bounceOut' => 'bounceOut',
                    'bounceOutDown' => 'bounceOutDown',
                    'bounceOutLeft' => 'bounceOutLeft',
                    'bounceOutRight' => 'bounceOutRight',
                    'bounceOutUp' => 'bounceOutUp',

                    // Fading entrances
                    'fadeIn' => 'fadeIn',
                    'fadeInDown' => 'fadeInDown',
                    'fadeInDownBig' => 'fadeInDownBig',
                    'fadeInLeft' => 'fadeInLeft',
                    'fadeInLeftBig' => 'fadeInLeftBig',
                    'fadeInRight' => 'fadeInRight',
                    'fadeInRightBig' => 'fadeInRightBig',
                    'fadeInUp' => 'fadeInUp',
                    'fadeInUpBig' => 'fadeInUpBig',
                    'fadeInTopLeft' => 'fadeInTopLeft',
                    'fadeInTopRight' => 'fadeInTopRight',
                    'fadeInBottomLeft' => 'fadeInBottomLeft',
                    'fadeInBottomRight' => 'fadeInBottomRight',

                    // Fading exits
                    'fadeOut' => 'fadeOut',
                    'fadeOutDown' => 'fadeOutDown',
                    'fadeOutDownBig' => 'fadeOutDownBig',
                    'fadeOutLeft' => 'fadeOutLeft',
                    'fadeOutLeftBig' => 'fadeOutLeftBig',
                    'fadeOutRight' => 'fadeOutRight',
                    'fadeOutRightBig' => 'fadeOutRightBig',
                    'fadeOutUp' => 'fadeOutUp',
                    'fadeOutUpBig' => 'fadeOutUpBig',
                    'fadeOutTopLeft' => 'fadeOutTopLeft',
                    'fadeOutTopRight' => 'fadeOutTopRight',
                    'fadeOutBottomRight' => 'fadeOutBottomRight',
                    'fadeOutBottomLeft' => 'fadeOutBottomLeft',

                    // Flippers
                    'flip' => 'flip',
                    'flipInX' => 'flipInX',
                    'flipInY' => 'flipInY',
                    'flipOutX' => 'flipOutX',
                    'flipOutY' => 'flipOutY',

                    // Lightspeed
                    'lightSpeedInRight' => 'lightSpeedInRight',
                    'lightSpeedInLeft' => 'lightSpeedInLeft',
                    'lightSpeedOutRight' => 'lightSpeedOutRight',
                    'lightSpeedOutLeft' => 'lightSpeedOutLeft',

                    // Rotating entrances
                    'rotateIn' => 'rotateIn',
                    'rotateInDownLeft' => 'rotateInDownLeft',
                    'rotateInDownRight' => 'rotateInDownRight',
                    'rotateInUpLeft' => 'rotateInUpLeft',
                    'rotateInUpRight' => 'rotateInUpRight',

                    // Rotating exits
                    'rotateOut' => 'rotateOut',
                    'rotateOutDownLeft' => 'rotateOutDownLeft',
                    'rotateOutDownRight' => 'rotateOutDownRight',
                    'rotateOutUpLeft' => 'rotateOutUpLeft',
                    'rotateOutUpRight' => 'rotateOutUpRight',

                    // Specials
                    'hinge' => 'hinge',
                    'jackInTheBox' => 'jackInTheBox',
                    'rollIn' => 'rollIn',
                    'rollOut' => 'rollOut',

                    // Zooming entrances
                    'zoomIn' => 'zoomIn',
                    'zoomInDown' => 'zoomInDown',
                    'zoomInLeft' => 'zoomInLeft',
                    'zoomInRight' => 'zoomInRight',
                    'zoomInUp' => 'zoomInUp',

                    // Zooming exits
                    'zoomOut' => 'zoomOut',
                    'zoomOutDown' => 'zoomOutDown',
                    'zoomOutLeft' => 'zoomOutLeft',
                    'zoomOutRight' => 'zoomOutRight',
                    'zoomOutUp' => 'zoomOutUp',

                    // Sliding entrances
                    'slideInDown' => 'slideInDown',
                    'slideInLeft' => 'slideInLeft',
                    'slideInRight' => 'slideInRight',
                    'slideInUp' => 'slideInUp',

                    // Sliding exits
                    'slideOutDown' => 'slideOutDown',
                    'slideOutLeft' => 'slideOutLeft',
                    'slideOutRight' => 'slideOutRight',
                    'slideOutUp' => 'slideOutUp',

                    // Custom
                    'shimmer' => 'shimmer',
                    'fade-in-up' => 'fade-in-up',
                    'tilt' => 'tilt',
                    'pulse-border' => 'pulse-border',
                    'border-spin' => 'border-spin',
                    'glow-on' => 'glow',
                    'corner-glow' => 'corner-glow',
                    'glow-off' => 'glowoff',
                    'corner-glow-off' => 'corner-glow-off',
                    'gradient-flow' => 'gradient-flow',
                    'gradient-1' => 'gradient1',
                    'gradient-2' => 'gradient2',
                    'gradient-3' => 'gradient3',
                    'gradient-4' => 'gradient4',
                    'spin-and-breathe' => 'spin-and-breathe',
                    'wipe-in' => 'wipe-in',
                    'wipe-out' => 'wipe-out',
                ],

                'keyframes' => [
                    // Default Tailwind keyframes
                    'spin' => '{ to { transform: rotate(360deg); } }',
                    'ping' => '{ 75%, 100% { transform: scale(2); opacity: 0; } }',
                    'pulse' => '{ 0%, 100% { opacity: 1; } 50% { opacity: .5; } }',
                    'bounce' => '{ 0%, 100% { transform: translateY(-25%); animation-timing-function: cubic-bezier(0.8,0,1,1); } 50% { transform: translateY(0); animation-timing-function: cubic-bezier(0,0,0.2,1); } }',
                    
                    // Gradient Animation
                    'gradientAnimation' => '{ 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }',
                    'bgPan' => '{ 0% { background-position: 0% 0%; } 100% { background-position: 100% 100%; } }',
                    'bgMove' => '{ from { background-position: 0% 0%; } to { background-position: 0% -500%; } }',
                    'moveBackground' => '{ from { background-position: 0% 0%; } to { background-position: 0% -500%; } }',
                    'gemini-flow' => '{ 0% { transform: translate(0, 0); } 50% { transform: translate(40px, 60px); } 100% { transform: translate(0, 0); } }',
                    'aurora-flow' => '{ 0% { transform: translate(0, 0) rotate(0deg); } 50% { transform: translate(10px, 20px) rotate(5deg); } 100% { transform: translate(0, 0) rotate(0deg); } }',
                    'cosmic-flow' => '{ 0% { transform: translate(-50%, -50%) rotate(0deg); } 25% { transform: translate(-40%, -60%) rotate(10deg); } 50% { transform: translate(-50%, -50%) rotate(0deg); } 75% { transform: translate(-60%, -40%) rotate(-10deg); } 100% { transform: translate(-50%, -50%) rotate(0deg); } }',
                    'subtle-float' => '{ 0% { transform: translateY(0px); } 50% { transform: translateY(-15px); } 100% { transform: translateY(0px); } }',
                    'circular-path' => '{ 0% { transform: translate(0, 0) rotate(0deg); } 25% { transform: translate(40px, 20px) rotate(90deg); } 50% { transform: translate(0, 40px) rotate(180deg); } 75% { transform: translate(-40px, 20px) rotate(270deg); } 100% { transform: translate(0, 0) rotate(360deg); } }',
                    'gentle-pulse-scale' => '{ 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }',
                    'stream-flow' => '{ 0% { transform: translate(-10%, -10%); } 100% { transform: translate(10%, 10%); } }',
                    'nebula-drift' => '{ 0% { transform: translate(0, 0) rotate(0deg); } 20% { transform: translate(15px, 25px) rotate(5deg); } 40% { transform: translate(-20px, -15px) rotate(-3deg); } 60% { transform: translate(25px, -5px) rotate(2deg); } 80% { transform: translate(-10px, 15px) rotate(-4deg); } 100% { transform: translate(0, 0) rotate(0deg); } }',                    
                    'axis-rotation' => '{ 0% { transform: rotateY(0deg) rotateX(0deg); } 50% { transform: rotateY(15deg) rotateX(-10deg); } 100% { transform: rotateY(0deg) rotateX(0deg); } }',
                    'glitch-shift' => '{ 0%, 100% { transform: translate(0, 0); } 10% { transform: translate(-3px, 2px); } 20% { transform: translate(2px, -3px); } 30% { transform: translate(-2px, 3px); } 40% { transform: translate(3px, -2px); } 50% { transform: translate(0, 0); } }',
                    'grid-pan' => '{ 0% { background-position: 0 0; } 100% { background-position: 0 100%; } }',
                    'horizon-glow' => '{ 0%, 100% { box-shadow: 0 0 12px 3px var(--retro-glow-color); opacity: 0.8; } 50% { box-shadow: 0 0 20px 5px var(--retro-glow-color); opacity: 1; } }',
                    'rotateColors' => '{ from { --angle-to-the-dangle: 0deg; } to { --angle-to-the-dangle: 360deg; } }',

                    // Attention Seekers
                    'flash' => '{ from, 50%, to { opacity: 1; } 25%, 75% { opacity: 0; } }',
                    'rubberBand' => '{ from { transform: scale3d(1, 1, 1); } 30% { transform: scale3d(1.25, 0.75, 1); } 40% { transform: scale3d(0.75, 1.25, 1); } 50% { transform: scale3d(1.15, 0.85, 1); } 65% { transform: scale3d(0.95, 1.05, 1); } 75% { transform: scale3d(1.05, 0.95, 1); } to { transform: scale3d(1, 1, 1); } }',
                    'shakeX' => '{ from, to { transform: translate3d(0, 0, 0); } 10%, 30%, 50%, 70%, 90% { transform: translate3d(-10px, 0, 0); } 20%, 40%, 60%, 80% { transform: translate3d(10px, 0, 0); } }',
                    'shakeY' => '{ from, to { transform: translate3d(0, 0, 0); } 10%, 30%, 50%, 70%, 90% { transform: translate3d(0, -10px, 0); } 20%, 40%, 60%, 80% { transform: translate3d(0, 10px, 0); } }',
                    'headShake' => '{ 0% { transform: translateX(0); } 6.5% { transform: translateX(-6px) rotateY(-9deg); } 18.5% { transform: translateX(5px) rotateY(7deg); } 31.5% { transform: translateX(-3px) rotateY(-5deg); } 43.5% { transform: translateX(2px) rotateY(3deg); } 50% { transform: translateX(0); } }',
                    'swing' => '{ 20% { transform: rotate3d(0, 0, 1, 15deg); } 40% { transform: rotate3d(0, 0, 1, -10deg); } 60% { transform: rotate3d(0, 0, 1, 5deg); } 80% { transform: rotate3d(0, 0, 1, -5deg); } to { transform: rotate3d(0, 0, 1, 0deg); } }',
                    'tada' => '{ from { transform: scale3d(1, 1, 1); } 10%, 20% { transform: scale3d(0.9, 0.9, 0.9) rotate3d(0, 0, 1, -3deg); } 30%, 50%, 70%, 90% { transform: scale3d(1.1, 1.1, 1.1) rotate3d(0, 0, 1, 3deg); } 40%, 60%, 80% { transform: scale3d(1.1, 1.1, 1.1) rotate3d(0, 0, 1, -3deg); } to { transform: scale3d(1, 1, 1); } }',
                    'wobble' => '{ from { transform: translate3d(0, 0, 0); } 15% { transform: translate3d(-25%, 0, 0) rotate3d(0, 0, 1, -5deg); } 30% { transform: translate3d(20%, 0, 0) rotate3d(0, 0, 1, 3deg); } 45% { transform: translate3d(-15%, 0, 0) rotate3d(0, 0, 1, -3deg); } 60% { transform: translate3d(10%, 0, 0) rotate3d(0, 0, 1, 2deg); } 75% { transform: translate3d(-5%, 0, 0) rotate3d(0, 0, 1, -1deg); } to { transform: translate3d(0, 0, 0); } }',
                    'jello' => '{ from, 11.1%, to { transform: translate3d(0, 0, 0); } 22.2% { transform: skewX(-12.5deg) skewY(-12.5deg); } 33.3% { transform: skewX(6.25deg) skewY(6.25deg); } 44.4% { transform: skewX(-3.125deg) skewY(-3.125deg); } 55.5% { transform: skewX(1.5625deg) skewY(1.5625deg); } 66.6% { transform: skewX(-0.78125deg) skewY(-0.78125deg); } 77.7% { transform: skewX(0.390625deg) skewY(0.390625deg); } 88.8% { transform: skewX(-0.1953125deg) skewY(-0.1953125deg); } }',
                    'heartBeat' => '{ 0% { transform: scale(1); } 14% { transform: scale(1.3); } 28% { transform: scale(1); } 42% { transform: scale(1.3); } 70% { transform: scale(1); } }',

                    // Back Entrances
                    'backInDown' => '{ 0% { transform: translateY(-1200px) scale(0.7); opacity: 0.7; } 80% { transform: translateY(0px) scale(0.7); opacity: 0.7; } 100% { transform: scale(1); opacity: 1; } }',
                    'backInLeft' => '{ 0% { transform: translateX(-2000px) scale(0.7); opacity: 0.7; } 80% { transform: translateX(0px) scale(0.7); opacity: 0.7; } 100% { transform: scale(1); opacity: 1; } }',
                    'backInRight' => '{ 0% { transform: translateX(2000px) scale(0.7); opacity: 0.7; } 80% { transform: translateX(0px) scale(0.7); opacity: 0.7; } 100% { transform: scale(1); opacity: 1; } }',
                    'backInUp' => '{ 0% { transform: translateY(1200px) scale(0.7); opacity: 0.7; } 80% { transform: translateY(0px) scale(0.7); opacity: 0.7; } 100% { transform: scale(1); opacity: 1; } }',

                    // Back Exits
                    'backOutDown' => '{ 0% { transform: scale(1); opacity: 1; } 20% { transform: translateY(0px) scale(0.7); opacity: 0.7; } 100% { transform: translateY(700px) scale(0.7); opacity: 0.7; } }',
                    'backOutLeft' => '{ 0% { transform: scale(1); opacity: 1; } 20% { transform: translateX(0px) scale(0.7); opacity: 0.7; } 100% { transform: translateX(-2000px) scale(0.7); opacity: 0.7; } }',
                    'backOutRight' => '{ 0% { transform: scale(1); opacity: 1; } 20% { transform: translateX(0px) scale(0.7); opacity: 0.7; } 100% { transform: translateX(2000px) scale(0.7); opacity: 0.7; } }',
                    'backOutUp' => '{ 0% { transform: scale(1); opacity: 1; } 20% { transform: translateY(0px) scale(0.7); opacity: 0.7; } 100% { transform: translateY(-700px) scale(0.7); opacity: 0.7; } }',

                    // Bouncing Entrances
                    'bounceIn' => '{ from, 20%, 40%, 60%, 80%, to { animation-timing-function: cubic-bezier(0.215, 0.61, 0.355, 1); } 0% { opacity: 0; transform: scale3d(0.3, 0.3, 0.3); } 20% { transform: scale3d(1.1, 1.1, 1.1); } 40% { transform: scale3d(0.9, 0.9, 0.9); } 60% { opacity: 1; transform: scale3d(1.03, 1.03, 1.03); } 80% { transform: scale3d(0.97, 0.97, 0.97); } to { opacity: 1; transform: scale3d(1, 1, 1); } }',
                    'bounceInDown' => '{ from, 60%, 75%, 90%, to { animation-timing-function: cubic-bezier(0.215, 0.61, 0.355, 1); } 0% { opacity: 0; transform: translate3d(0, -3000px, 0) scaleY(3); } 60% { opacity: 1; transform: translate3d(0, 25px, 0) scaleY(0.9); } 75% { transform: translate3d(0, -10px, 0) scaleY(0.95); } 90% { transform: translate3d(0, 5px, 0) scaleY(0.985); } to { transform: translate3d(0, 0, 0); } }',
                    'bounceInLeft' => '{ from, 60%, 75%, 90%, to { animation-timing-function: cubic-bezier(0.215, 0.61, 0.355, 1); } 0% { opacity: 0; transform: translate3d(-3000px, 0, 0) scaleX(3); } 60% { opacity: 1; transform: translate3d(25px, 0, 0) scaleX(1); } 75% { transform: translate3d(-10px, 0, 0) scaleX(0.98); } 90% { transform: translate3d(5px, 0, 0) scaleX(0.995); } to { transform: translate3d(0, 0, 0); } }',
                    'bounceInRight' => '{ from, 60%, 75%, 90%, to { animation-timing-function: cubic-bezier(0.215, 0.61, 0.355, 1); } 0% { opacity: 0; transform: translate3d(3000px, 0, 0) scaleX(3); } 60% { opacity: 1; transform: translate3d(-25px, 0, 0) scaleX(1); } 75% { transform: translate3d(10px, 0, 0) scaleX(0.98); } 90% { transform: translate3d(-5px, 0, 0) scaleX(0.995); } to { transform: translate3d(0, 0, 0); } }',
                    'bounceInUp' => '{ from, 60%, 75%, 90%, to { animation-timing-function: cubic-bezier(0.215, 0.61, 0.355, 1); } 0% { opacity: 0; transform: translate3d(0, 3000px, 0) scaleY(3); } 60% { opacity: 1; transform: translate3d(0, -20px, 0) scaleY(0.9); } 75% { transform: translate3d(0, 10px, 0) scaleY(0.95); } 90% { transform: translate3d(0, -5px, 0) scaleY(0.985); } to { transform: translate3d(0, 0, 0); } }',

                    // Bouncing Exits
                    'bounceOut' => '{ 20% { transform: scale3d(0.9, 0.9, 0.9); } 50%, 55% { opacity: 1; transform: scale3d(1.1, 1.1, 1.1); } to { opacity: 0; transform: scale3d(0.3, 0.3, 0.3); } }',
                    'bounceOutDown' => '{ 20% { transform: translate3d(0, 10px, 0) scaleY(0.985); } 40%, 45% { opacity: 1; transform: translate3d(0, -20px, 0) scaleY(0.9); } to { opacity: 0; transform: translate3d(0, 2000px, 0) scaleY(3); } }',
                    'bounceOutLeft' => '{ 20% { opacity: 1; transform: translate3d(20px, 0, 0) scaleX(0.9); } to { opacity: 0; transform: translate3d(-2000px, 0, 0) scaleX(2); } }',
                    'bounceOutRight' => '{ 20% { opacity: 1; transform: translate3d(-20px, 0, 0) scaleX(0.9); } to { opacity: 0; transform: translate3d(2000px, 0, 0) scaleX(2); } }',
                    'bounceOutUp' => '{ 20% { transform: translate3d(0, -10px, 0) scaleY(0.985); } 40%, 45% { opacity: 1; transform: translate3d(0, 20px, 0) scaleY(0.9); } to { opacity: 0; transform: translate3d(0, -2000px, 0) scaleY(3); } }',

                    // Fading Entrances
                    'fadeIn' => '{ from { opacity: 0; } to { opacity: 1; } }',
                    'fadeInDown' => '{ from { opacity: 0; transform: translate3d(0, -20px, 0); /* Animate.css uses -100% */ } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'fadeInDownBig' => '{ from { opacity: 0; transform: translate3d(0, -2000px, 0); } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'fadeInLeft' => '{ from { opacity: 0; transform: translate3d(-20px, 0, 0); /* Animate.css uses -100% */ } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'fadeInLeftBig' => '{ from { opacity: 0; transform: translate3d(-2000px, 0, 0); } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'fadeInRight' => '{ from { opacity: 0; transform: translate3d(20px, 0, 0); /* Animate.css uses 100% */ } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'fadeInRightBig' => '{ from { opacity: 0; transform: translate3d(2000px, 0, 0); } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'fadeInUp' => '{ from { opacity: 0; transform: translate3d(0, 20px, 0); /* Animate.css uses 100% */ } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'fadeInUpBig' => '{ from { opacity: 0; transform: translate3d(0, 2000px, 0); } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'fadeInTopLeft' => '{ from { opacity: 0; transform: translate3d(-20px, -20px, 0); } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'fadeInTopRight' => '{ from { opacity: 0; transform: translate3d(20px, -20px, 0); } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'fadeInBottomLeft' => '{ from { opacity: 0; transform: translate3d(-20px, 20px, 0); } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'fadeInBottomRight' => '{ from { opacity: 0; transform: translate3d(20px, 20px, 0); } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'fadeInText' => '{ from { opacity: 0; transform: translate3d(0, 20px, 0); } to { opacity: 1; transform: translate3d(0, 0, 0); } }',

                    // Fading Exits
                    'fadeOut' => '{ from { opacity: 1; } to { opacity: 0; visibility: hidden; } }',
                    'fadeOutText' => '{ from { opacity: 1; transform: translate3d(0, 20px, 0); } to { opacity: 0; transform: translate3d(0, 0, 0); visibility: hidden; } }',
                    'pulse-alt' => '{ 0%, 100% { opacity: 1; } 50% { opacity: .5; } }',
                    'fadeOutDown' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(0, 20px, 0); visibility: hidden; } }',
                    'fadeOutDownBig' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(0, 2000px, 0); visibility: hidden; } }',
                    'fadeOutLeft' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(-20px, 0, 0); visibility: hidden; } }',
                    'fadeOutLeftBig' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(-2000px, 0, 0); visibility: hidden; } }',
                    'fadeOutRight' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(20px, 0, 0); visibility: hidden; } }',
                    'fadeOutRightBig' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(2000px, 0, 0); visibility: hidden; } }',
                    'fadeOutUp' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(0, -20px, 0); visibility: hidden; } }',
                    'fadeOutUpBig' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(0, -2000px, 0); visibility: hidden; } }',
                    'fadeOutTopLeft' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(-20px, -20px, 0); visibility: hidden; } }',
                    'fadeOutTopRight' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(20px, -20px, 0); visibility: hidden; } }',
                    'fadeOutBottomRight' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(20px, 20px, 0); visibility: hidden; } }',
                    'fadeOutBottomLeft' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(-20px, 20px, 0); visibility: hidden; } }',

                    // Flippers
                    'flip' => '{ from { transform: perspective(400px) scale3d(1, 1, 1) translate3d(0, 0, 0) rotate3d(0, 1, 0, -360deg); animation-timing-function: ease-out; } 40% { transform: perspective(400px) scale3d(1, 1, 1) translate3d(0, 0, 150px) rotate3d(0, 1, 0, -190deg); animation-timing-function: ease-out; } 50% { transform: perspective(400px) scale3d(1, 1, 1) translate3d(0, 0, 150px) rotate3d(0, 1, 0, -170deg); animation-timing-function: ease-in; } 80% { transform: perspective(400px) scale3d(0.95, 0.95, 0.95) translate3d(0, 0, 0) rotate3d(0, 1, 0, 0deg); animation-timing-function: ease-in; } to { transform: perspective(400px) scale3d(1, 1, 1) translate3d(0, 0, 0) rotate3d(0, 1, 0, 0deg); animation-timing-function: ease-in; } }',
                    'flipInX' => '{ from { transform: perspective(400px) rotate3d(1, 0, 0, 90deg); animation-timing-function: ease-in; opacity: 0; } 40% { transform: perspective(400px) rotate3d(1, 0, 0, -20deg); animation-timing-function: ease-in; } 60% { transform: perspective(400px) rotate3d(1, 0, 0, 10deg); opacity: 1; } 80% { transform: perspective(400px) rotate3d(1, 0, 0, -5deg); } to { transform: perspective(400px); } }',
                    'flipInY' => '{ from { transform: perspective(400px) rotate3d(0, 1, 0, 90deg); animation-timing-function: ease-in; opacity: 0; } 40% { transform: perspective(400px) rotate3d(0, 1, 0, -20deg); animation-timing-function: ease-in; } 60% { transform: perspective(400px) rotate3d(0, 1, 0, 10deg); opacity: 1; } 80% { transform: perspective(400px) rotate3d(0, 1, 0, -5deg); } to { transform: perspective(400px); } }',
                    'flipOutX' => '{ from { transform: perspective(400px); } 30% { transform: perspective(400px) rotate3d(1, 0, 0, -20deg); opacity: 1; } to { transform: perspective(400px) rotate3d(1, 0, 0, 90deg); opacity: 0; } }',
                    'flipOutY' => '{ from { transform: perspective(400px); } 30% { transform: perspective(400px) rotate3d(0, 1, 0, -15deg); opacity: 1; } to { transform: perspective(400px) rotate3d(0, 1, 0, 90deg); opacity: 0; } }',

                    // Lightspeed
                    'lightSpeedInRight' => '{ from { transform: translate3d(100%, 0, 0) skewX(-30deg); opacity: 0; } 60% { transform: skewX(20deg); opacity: 1; } 80% { transform: skewX(-5deg); } to { transform: translate3d(0, 0, 0); } }',
                    'lightSpeedInLeft' => '{ from { transform: translate3d(-100%, 0, 0) skewX(30deg); opacity: 0; } 60% { transform: skewX(-20deg); opacity: 1; } 80% { transform: skewX(5deg); } to { transform: translate3d(0, 0, 0); } }',
                    'lightSpeedOutRight' => '{ from { opacity: 1; } to { transform: translate3d(100%, 0, 0) skewX(30deg); opacity: 0; } }',
                    'lightSpeedOutLeft' => '{ from { opacity: 1; } to { transform: translate3d(-100%, 0, 0) skewX(-30deg); opacity: 0; } }',

                    // Rotating Entrances
                    'rotateIn' => '{ from { transform: rotate3d(0, 0, 1, -200deg); opacity: 0; } to { transform: translate3d(0, 0, 0); opacity: 1; } }',
                    'rotateInDownLeft' => '{ from { transform-origin: left bottom; transform: rotate3d(0, 0, 1, -45deg); opacity: 0; } to { transform-origin: left bottom; transform: translate3d(0, 0, 0); opacity: 1; } }',
                    'rotateInDownRight' => '{ from { transform-origin: right bottom; transform: rotate3d(0, 0, 1, 45deg); opacity: 0; } to { transform-origin: right bottom; transform: translate3d(0, 0, 0); opacity: 1; } }',
                    'rotateInUpLeft' => '{ from { transform-origin: left bottom; transform: rotate3d(0, 0, 1, 45deg); opacity: 0; } to { transform-origin: left bottom; transform: translate3d(0, 0, 0); opacity: 1; } }',
                    'rotateInUpRight' => '{ from { transform-origin: right bottom; transform: rotate3d(0, 0, 1, -90deg); opacity: 0; } to { transform-origin: right bottom; transform: translate3d(0, 0, 0); opacity: 1; } }',

                    // Rotating Exits
                    'rotateOut' => '{ from { opacity: 1; } to { transform: rotate3d(0, 0, 1, 200deg); opacity: 0; } }',
                    'rotateOutDownLeft' => '{ from { transform-origin: left bottom; opacity: 1; } to { transform-origin: left bottom; transform: rotate3d(0, 0, 1, 45deg); opacity: 0; } }',
                    'rotateOutDownRight' => '{ from { transform-origin: right bottom; opacity: 1; } to { transform-origin: right bottom; transform: rotate3d(0, 0, 1, -45deg); opacity: 0; } }',
                    'rotateOutUpLeft' => '{ from { transform-origin: left bottom; opacity: 1; } to { transform-origin: left bottom; transform: rotate3d(0, 0, 1, -45deg); opacity: 0; } }',
                    'rotateOutUpRight' => '{ from { transform-origin: right bottom; opacity: 1; } to { transform-origin: right bottom; transform: rotate3d(0, 0, 1, 90deg); opacity: 0; } }',
                    'text-rotate' => '{ 0%, 22%, 100% { transform: translate3d(0, 0, 0) } 25%, 47% { transform: translate3d(0, -100%, 0) } 50%, 72% { transform: translate3d(0, -200%, 0) } 75%, 97% { transform: translate3d(0, -300%, 0) } }',

                    // Specials
                    'hinge' => '{ 0% { transform-origin: top left; animation-timing-function: ease-in-out; } 20%, 60% { transform: rotate3d(0, 0, 1, 80deg); transform-origin: top left; animation-timing-function: ease-in-out; } 40%, 80% { transform: rotate3d(0, 0, 1, 60deg); transform-origin: top left; animation-timing-function: ease-in-out; opacity: 1; } to { transform: translate3d(0, 700px, 0); opacity: 0; } }',
                    'jackInTheBox' => '{ from { opacity: 0; transform: scale(0.1) rotate(30deg); transform-origin: center bottom; } 50% { transform: rotate(-10deg); } 70% { transform: rotate(3deg); } to { opacity: 1; transform: scale(1); } }',
                    'rollIn' => '{ from { opacity: 0; transform: translate3d(-100%, 0, 0) rotate3d(0, 0, 1, -120deg); } to { opacity: 1; transform: translate3d(0, 0, 0); } }',
                    'rollOut' => '{ from { opacity: 1; } to { opacity: 0; transform: translate3d(100%, 0, 0) rotate3d(0, 0, 1, 120deg); } }',

                    // Zooming Entrances
                    'zoomIn' => '{ from { opacity: 0; transform: scale3d(0.3, 0.3, 0.3); } 50% { opacity: 1; } }', // 'to' implicitly has opacity:1, transform:scale3d(1,1,1)
                    'zoomInDown' => '{ from { opacity: 0; transform: scale3d(0.1, 0.1, 0.1) translate3d(0, -1000px, 0); animation-timing-function: cubic-bezier(0.55, 0.055, 0.675, 0.19); } 60% { opacity: 1; transform: scale3d(0.475, 0.475, 0.475) translate3d(0, 60px, 0); animation-timing-function: cubic-bezier(0.175, 0.885, 0.32, 1); } }',
                    'zoomInLeft' => '{ from { opacity: 0; transform: scale3d(0.1, 0.1, 0.1) translate3d(-1000px, 0, 0); animation-timing-function: cubic-bezier(0.55, 0.055, 0.675, 0.19); } 60% { opacity: 1; transform: scale3d(0.475, 0.475, 0.475) translate3d(10px, 0, 0); animation-timing-function: cubic-bezier(0.175, 0.885, 0.32, 1); } }',
                    'zoomInRight' => '{ from { opacity: 0; transform: scale3d(0.1, 0.1, 0.1) translate3d(1000px, 0, 0); animation-timing-function: cubic-bezier(0.55, 0.055, 0.675, 0.19); } 60% { opacity: 1; transform: scale3d(0.475, 0.475, 0.475) translate3d(-10px, 0, 0); animation-timing-function: cubic-bezier(0.175, 0.885, 0.32, 1); } }',
                    'zoomInUp' => '{ from { opacity: 0; transform: scale3d(0.1, 0.1, 0.1) translate3d(0, 1000px, 0); animation-timing-function: cubic-bezier(0.55, 0.055, 0.675, 0.19); } 60% { opacity: 1; transform: scale3d(0.475, 0.475, 0.475) translate3d(0, -60px, 0); animation-timing-function: cubic-bezier(0.175, 0.885, 0.32, 1); } }',

                    // Zooming Exits
                    'zoomOut' => '{ from { opacity: 1; } 50% { opacity: 0; transform: scale3d(0.3, 0.3, 0.3); } to { opacity: 0; } }',
                    'zoomOutDown' => '{ 40% { opacity: 1; transform: scale3d(0.475, 0.475, 0.475) translate3d(0, -60px, 0); animation-timing-function: cubic-bezier(0.55, 0.055, 0.675, 0.19); } to { opacity: 0; transform: scale3d(0.1, 0.1, 0.1) translate3d(0, 2000px, 0); animation-timing-function: cubic-bezier(0.175, 0.885, 0.32, 1); transform-origin: center bottom; } }',
                    'zoomOutLeft' => '{ 40% { opacity: 1; transform: scale3d(0.475, 0.475, 0.475) translate3d(42px, 0, 0); } to { opacity: 0; transform: scale(0.1) translate3d(-2000px, 0, 0); transform-origin: left center; } }',
                    'zoomOutRight' => '{ 40% { opacity: 1; transform: scale3d(0.475, 0.475, 0.475) translate3d(-42px, 0, 0); } to { opacity: 0; transform: scale(0.1) translate3d(2000px, 0, 0); transform-origin: right center; } }',
                    'zoomOutUp' => '{ 40% { opacity: 1; transform: scale3d(0.475, 0.475, 0.475) translate3d(0, 60px, 0); animation-timing-function: cubic-bezier(0.55, 0.055, 0.675, 0.19); } to { opacity: 0; transform: scale3d(0.1, 0.1, 0.1) translate3d(0, -2000px, 0); animation-timing-function: cubic-bezier(0.175, 0.885, 0.32, 1); transform-origin: center bottom; } }',

                    // Sliding Entrances
                    'slideInDown' => '{ from { transform: translate3d(0, -100%, 0); visibility: visible; } to { transform: translate3d(0, 0, 0); } }',
                    'slideInLeft' => '{ from { transform: translate3d(-100%, 0, 0); visibility: visible; } to { transform: translate3d(0, 0, 0); } }',
                    'slideInRight' => '{ from { transform: translate3d(100%, 0, 0); visibility: visible; } to { transform: translate3d(0, 0, 0); } }',
                    'slideInUp' => '{ from { transform: translate3d(0, 100%, 0); visibility: visible; } to { transform: translate3d(0, 0, 0); } }',

                    // Sliding Exits
                    'slideOutDown' => '{ from { transform: translate3d(0, 0, 0); } to { visibility: hidden; transform: translate3d(0, 100%, 0); } }',
                    'slideOutLeft' => '{ from { transform: translate3d(0, 0, 0); } to { visibility: hidden; transform: translate3d(-100%, 0, 0); } }',
                    'slideOutRight' => '{ from { transform: translate3d(0, 0, 0); } to { visibility: hidden; transform: translate3d(100%, 0, 0); } }',
                    'slideOutUp' => '{ from { transform: translate3d(0, 0, 0); } to { visibility: hidden; transform: translate3d(0, -100%, 0); } }',

                    // Your custom keyframes
                    'spinner-grow' => '{ 0% { transform: scale(0); } 50% { opacity: 1; transform: none; } }',
                    'fade-in-up' => '{ from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }',
                    'tilt' => '{ 0%, 100% { transform: rotate(0deg); } 50% { transform: rotate(1deg); } }',
                    'pulse-border' => '{ 0%, 100% { border-color: var(--tw-pulse-border-color-from, transparent); } 50% { border-color: var(--tw-pulse-border-color-to, currentColor); }}',
                    'aurora-pan' => '{ from { background-position: 0% 50%; } to { background-position: 100% 50%; } }',
                    'mesh-pan' => '{ from { background-position: 0% 50%; } to { background-position: 100% 50%; } }',
                    'aurora-flow' => '{ 0% { transform: translate(0, 0) rotate(0deg); } 50% { transform: translate(10px, 20px) rotate(5deg); } 100% { transform: translate(0, 0) rotate(0deg); } }',
                    'mesh-flow' => '{ 0% { transform: translate(0, 0) rotate(0deg); } 50% { transform: translate(10px, 20px) rotate(5deg); } 100% { transform: translate(0, 0) rotate(0deg); } }',
                    'noise-pan' => '{ 0%, 100% { transform: translate(0, 0); } 10% { transform: translate(-5%, -10%); } 20% { transform: translate(-15%, 5%); } 30% { transform: translate(7%, -25%); } 40% { transform: translate(-22%, 25%); } 50% { transform: translate(15%, 10%); } 60% { transform: translate(0%, -20%); } 70% { transform: translate(18%, -24%); } 80% { transform: translate(-20%, 13%); } 90% { transform: translate(-10%, -5%); } }',
                    'shine' => '{ 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }',
                    'shimmer' => '{ 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }',
                    'float' => '{  0% { transform: translateY(0px); } 50% { transform: translateY(-8px); } 100% { transform: translateY(0px); } }',
                    'morph-colors' => '{ 0% { background-color: hsla(var(--primary), 0.15); } 33% { background-color: hsla(var(--accent), 0.15); } 66% { background-color: hsla(var(--secondary), 0.15); } 100% { background-color: hsla(var(--primary), 0.15); } }',
                    'border-spin' => '{ 100% { transform: rotate(360deg); } }',
                    'liquid-distort' => '{ 0%, 100% { border-radius: 42% 58% 70% 30% / 45% 45% 55% 55%; transform: translate3d(0,0,0) rotateZ(0.01deg); } 34% { border-radius: 70% 30% 46% 54% / 30% 29% 71% 70%; transform: translate3d(0, 5px, 0) rotateZ(0.01deg); } 50% { transform: translate3d(0,0,0) rotateZ(0.01deg); } 67% { border-radius: 100% 60% 60% 100% / 100% 100% 60% 60%; transform: translate3d(0, -3px, 0) rotateZ(0.01deg); } }',
                    'liquid-rotate' => '{ 0%, 100% { transform: rotate(0deg); } 50% { transform: rotate(180deg); } }',
                    'liquid-rotate-reverse' => '{ 0%, 100% { transform: rotate(0deg); } 50% { transform: rotate(-180deg); } }',
                    'liquid-rotate-360' => '{ 0%, 100% { transform: rotate(0deg); } 50% { transform: rotate(360deg); } }',
                    'liquid-rotate-360-reverse' => '{ 0%, 100% { transform: rotate(0deg); } 50% { transform: rotate(-360deg); } }',
                    'glow' => '{ 0% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.7); } 50% { box-shadow: 0 0 10px 10px rgba(255, 255, 255, 0.7); } }',
                    'corner-glow' => '{ 0% { opacity: 0; } 3% { opacity: 1; } 10% { opacity: 0; } 12% { opacity: 0.7; } 16% { opacity: 0.3; animation-timing-function: cubic-bezier(0.5, 1, 0.89, 1); } 100% { opacity: 1; animation-timing-function: cubic-bezier(0.5, 1, 0.89, 1); } }',
                    'glowoff' => '{ to { opacity: 0; } }',
                    'corner-glow-off' => '{ to { opacity: 0; } }',
                    'glow-reverse' => '{ 0% { box-shadow: 0 0 10px 10px rgba(255, 255, 255, 0.7); } 50% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.7); } }',
                    'glow-pulse' => '{ 0%, 100% { box-shadow: 0 0 10px 10px rgba(255, 255, 255, 0.7); } 50% { box-shadow: 0 0 20px 20px rgba(255, 255, 255, 0.7); } }',
                    'glow-pulse-nc' => '{ 0%, 100% { opacity: 0.7; transform: scale(1); } 50% { opacity: 1; transform: scale(1.02); } }',
                    'glow-pulse-reverse' => '{ 0%, 100% { box-shadow: 0 0 20px 20px rgba(255, 255, 255, 0.7); } 50% { box-shadow: 0 0 10px 10px rgba(255, 255, 255, 0.7); } }',
                    'neon-glow-pan' => '{ from { background-position: 0% 50%; } to { background-position: 200% 50%; } }',
                    'glass-hover' => '{ perspective(1500px) rotateX(var(--tilt-x)) rotateY(var(--tilt-y)) scale3d(1.02, 1.02, 1.02) }',
                    'aurora-flow-soft' => '{ 0% { transform: translate(10%, 10%) rotate(0deg); } 50% { transform: translate(-10%, -10%) rotate(5deg); } 100% { transform: translate(10%, 10%) rotate(0deg); } }',
                    'gradient-flow' => '{ 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }',
                    'gradient1' => '{ from { transform: rotate(0deg) translateX(30px) rotate(0deg); } to { transform: rotate(360deg) translateX(30px) rotate(-360deg); } }',
                    'gradient2' => '{ from { transform: rotate(0deg) translateX(50px) rotate(0deg); } to { transform: rotate(-360deg) translateX(50px) rotate(360deg); } }',
                    'gradient3' => '{ from { transform: rotate(0deg) translateX(-20px) rotate(0deg); } to { transform: rotate(360deg) translateX(-20px) rotate(-360deg); } }',
                    'gradient4' => '{ from { transform: rotate(0deg) translateX(40px) rotate(0deg); } to { transform: rotate(-360deg) translateX(40px) rotate(360deg); } }',
                    'spin-and-breathe' => '{ 0% { transform: scale(1) rotate(0deg); } 50% { transform: scale(1.1) rotate(180deg); } 100% { transform: scale(1) rotate(360deg); } }',
                    'wipe-in' => '{ from { clip-path: inset(0 100% 0 0); } to { clip-path: inset(0 0 0 0); } }',
                    'wipe-out' => '{ from { clip-path: inset(0 0 0 0); } to { clip-path: inset(0 0 0 100%); } }',
                    'border-shine-spin' => '{ from { transform: rotate(0deg); } to { transform: rotate(360deg); } }',
                    'loading-dots' => '{ 0% { transform: scale(1); opacity: 1; } 20% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.5); opacity: .5; } 100% { transform: scale(1); opacity: 1; } }',
                    'loading-ring' => '{ 0% { transform: rotate(0deg) } 100% { transform: rotate(360deg) } }',
                    'loading-ball' => '{ 0%, 100% { animation-timing-function: cubic-bezier(0.5, 0, 1, 0.5) } 0% { transform: rotateY(0deg) } 50% { transform: rotateY(1800deg); animation-timing-function: cubic-bezier(0, 0.5, 0.5, 1) } 100% { transform: rotateY(3600deg) } }',
                    'loading-bars' => '{ 0% { transform: scaleY(.1) } 25% { transform: scaleY(1) } 50% { transform: scaleY(.1) } 75% { transform: scaleY(1) } 100% { transform: scaleY(.1) } }',
                    'loading-infinity' => '{ 0% { transform: rotate(0deg) } 100% { transform: rotate(360deg) } }',
                ],
                'animationDuration' => [ // Corresponds to 'duration-*' classes
                    'DEFAULT' => '1s', // Default duration if animate-{name} doesn't specify one
                    '75' => '75ms', '100' => '100ms', '150' => '150ms', '200' => '200ms',
                    '300' => '300ms', '500' => '500ms', '700' => '700ms', '1000' => '1000ms',
                    '1s' => '1s', '2s' => '2s', '3s' => '3s', '4s' => '4s', '5s' => '5s', '6s' => '6s', '7s' => '7s', '8s' => '8s', '9s' => '9s', '10s' => '10s',
                ],
                'animationDelay' => [ // Corresponds to 'delay-*' classes
                    '75' => '75ms', '100' => '100ms', '300' => '300ms', '500' => '500ms', '1000' => '1000ms',
                ],
                'animationTimingFunction' => [ // Corresponds to 'ease-*' classes
                    'DEFAULT' => 'cubic-bezier(0.4, 0, 0.2, 1)', // ease-in-out
                    'linear' => 'linear',
                    'in' => 'cubic-bezier(0.4, 0, 1, 1)',
                    'out' => 'cubic-bezier(0, 0, 0.2, 1)',
                    'in-out' => 'cubic-bezier(0.4, 0, 0.2, 1)',
                ],
                // --- CSS @property Rules (Houdini) ---
                'properties' => [
                    // --- General-Purpose Numeric Properties ---
                    'generic-number' => [
                        'syntax' => '"<number>"',
                        'initial-value' => '0',
                        'inherits' => 'false',
                    ],
                    'generic-integer' => [
                        'syntax' => '"<integer>"',
                        'initial-value' => '0',
                        'inherits' => 'false',
                    ],
                    'generic-percentage' => [
                        'syntax' => '"<percentage>"',
                        'initial-value' => '0%',
                        'inherits' => 'false',
                    ],

                    // --- Dimensions ---
                    'length' => [ // for width, height, padding, margin, font-size etc.
                        'syntax' => '"<length>"',
                        'initial-value' => '0px',
                        'inherits' => 'false',
                    ],
                    'length-percentage' => [ // for properties accepting both
                        'syntax' => '"<length-percentage>"',
                        'initial-value' => '0px',
                        'inherits' => 'false',
                    ],

                    // --- Angles & Time ---
                    'angle' => [ // for rotations, gradients
                        'syntax' => '"<angle>"',
                        'initial-value' => '0deg',
                        'inherits' => 'false',
                    ],
                    'time' => [ // for animation/transition duration/delay
                        'syntax' => '"<time>"',
                        'initial-value' => '0s',
                        'inherits' => 'false',
                    ],
                    'resolution' => [ // for media queries, image resolution
                        'syntax' => '"<resolution>"',
                        'initial-value' => '96dpi',
                        'inherits' => 'false',
                    ],

                    // --- Colors ---
                    'color' => [ // for any color property
                        'syntax' => '"<color>"',
                        'initial-value' => 'transparent',
                        'inherits' => 'true', // Colors are often inherited
                    ],
                    // Specific color components (useful for complex animations)
                    'hue' => [
                        'syntax' => '"<number>"', // Hue in HSL is a number (0-360)
                        'initial-value' => '0',
                        'inherits' => 'false',
                    ],
                    'saturation' => [
                        'syntax' => '"<percentage>"',
                        'initial-value' => '0%',
                        'inherits' => 'false',
                    ],
                    'lightness' => [
                        'syntax' => '"<percentage>"',
                        'initial-value' => '0%',
                        'inherits' => 'false',
                    ],

                    // --- Images & Gradients ---
                    'image' => [ // for background-image, mask-image etc.
                        'syntax' => '"<image>"',
                        'initial-value' => 'none',
                        'inherits' => 'false',
                    ],

                    // --- URLs ---
                    'url' => [ // for filter: url(...), src etc.
                        'syntax' => '"<url>"',
                        'initial-value' => '"about:blank"',
                        'inherits' => 'false',
                    ],

                    // --- Custom Identifiers (Keywords) ---
                    // Example: --my-custom-keyword: "foo" | "bar";
                    'custom-ident' => [ // For custom keyword sets
                        'syntax' => '"<custom-ident>"',
                        'initial-value' => 'initial', // requires a default keyword
                        'inherits' => 'true',
                    ],
                    
                    // --- Transform Functions ---
                    // Multiple values can be animated together
                    'transform-list' => [
                        'syntax' => '"<transform-list>"',
                        'initial-value' => 'none',
                        'inherits' => 'false',
                    ],
                    
                    // --- Universal Syntax ---
                    // Allows ANY valid CSS value. Less performant for animation than specific types.
                    'any' => [
                        'syntax' => '"*"',
                        'initial-value' => 'initial',
                        'inherits' => 'false',
                    ],
                    
                    // --- Specific Use-Case Examples (from previous answers) ---
                    'progress' => [
                        'syntax' => '"<percentage>"',
                        'initial-value' => '0%',
                        'inherits' => 'false',
                    ],
                    'counter' => [
                        'syntax' => '"<integer>"',
                        'initial-value' => '0',
                        'inherits' => 'false',
                    ],
                    'angle-to-the-dangle' => [
                        'syntax' => '"<angle>"',
                        'initial-value' => '0deg',
                        'inherits' => 'true',
                    ],
                ],

                // --- SVG Icons ---
                'icons' => [
                    // A
                    'academic-cap' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M3.375 6.75a.75.75 0 01.75-.75h15a.75.75 0 010 1.5h-15a.75.75 0 01-.75-.75zM3.375 12a.75.75 0 01.75-.75h15a.75.75 0 010 1.5h-15a.75.75 0 01-.75-.75zM3.375 17.25a.75.75 0 01.75-.75h15a.75.75 0 010 1.5h-15a.75.75 0 01-.75-.75z" /><path fill-rule="evenodd" d="M12 2.25c-5.134 0-9.25 1.12-9.25 2.5v12.559c0 .548.223 1.054.593 1.424.37.37.876.591 1.424.591h14.466c.548 0 1.054-.221 1.424-.591.37-.37.593-.876.593-1.424V4.75c0-1.38-4.116-2.5-9.25-2.5zM4.75 4.819C5.553 4.413 7.02 4 9.25 4c2.228 0 3.696.413 4.5.819V18.25a.75.75 0 01-.75.75h-7.5a.75.75 0 01-.75-.75V4.819z" clip-rule="evenodd" /></svg>',
                    'adjustments-horizontal' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M3 3.75A.75.75 0 013.75 3h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 3.75zM3 12a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 12zm0 8.25a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75z" /></svg>',
                    'adjustments-vertical' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M3.75 3a.75.75 0 00-1.5 0v1.5a.75.75 0 001.5 0V3zM3 8.25a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 8.25zM3.75 15a.75.75 0 00-1.5 0v1.5a.75.75 0 001.5 0v-1.5zM8.25 3a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5A.75.75 0 018.25 3zM15 3.75a.75.75 0 000-1.5h-1.5a.75.75 0 000 1.5h1.5zm-6.75 18a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5a.75.75 0 01-.75-.75zM12 15.75a.75.75 0 000-1.5h-1.5a.75.75 0 000 1.5h1.5zM8.25 15a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5A.75.75 0 018.25 15zm6.75 0a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5a.75.75 0 01-.75-.75zM20.25 3a.75.75 0 00-1.5 0v1.5a.75.75 0 001.5 0V3zm-1.5 18a.75.75 0 001.5 0v-1.5a.75.75 0 00-1.5 0v1.5zm0-8.25a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5a.75.75 0 01-.75-.75z" /></svg>',
                    'archive-box' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M2.25 2.25a.75.75 0 00-.75.75v11.25c0 .414.336.75.75.75h19.5a.75.75 0 00.75-.75V3a.75.75 0 00-.75-.75H2.25zM9 12a.75.75 0 000 1.5h6a.75.75 0 000-1.5H9zM2.25 17.25a.75.75 0 01.75-.75h18a.75.75 0 01.75.75v3a.75.75 0 01-.75.75H3a.75.75 0 01-.75-.75v-3z" clip-rule="evenodd" /></svg>',
                    'arrow-down-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm-.53 14.03a.75.75 0 001.06 0l3-3a.75.75 0 10-1.06-1.06l-1.72 1.72V8.25a.75.75 0 00-1.5 0v5.69l-1.72-1.72a.75.75 0 00-1.06 1.06l3 3z" clip-rule="evenodd" /></svg>',
                    'arrow-down-on-square' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25a.75.75 0 01.75.75v11.69l3.22-3.22a.75.75 0 111.06 1.06l-4.5 4.5a.75.75 0 01-1.06 0l-4.5-4.5a.75.75 0 111.06-1.06l3.22 3.22V3a.75.75 0 01.75-.75zM2.25 16.5a.75.75 0 01.75-.75h18a.75.75 0 01.75.75v3.75a.75.75 0 01-.75.75H3a.75.75 0 01-.75-.75V16.5z" clip-rule="evenodd" /></svg>',
                    'arrow-down-tray' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 1.5a.75.75 0 01.75.75V7.5h-1.5V2.25A.75.75 0 0112 1.5zM11.25 4.5v15.75a.75.75 0 001.5 0V4.5h-1.5z" /><path fill-rule="evenodd" d="M7.525 12.352a.75.75 0 011.06 0L12 15.79l3.415-3.438a.75.75 0 111.06 1.06l-4.5 4.5a.75.75 0 01-1.06 0l-4.5-4.5a.75.75 0 010-1.06z" clip-rule="evenodd" /></svg>',
                    'arrow-down' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25a.75.75 0 01.75.75v16.19l6.22-6.22a.75.75 0 111.06 1.06l-7.5 7.5a.75.75 0 01-1.06 0l-7.5-7.5a.75.75 0 111.06-1.06l6.22 6.22V3a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'arrow-left-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm-4.28 9.22a.75.75 0 000 1.06l3 3a.75.75 0 101.06-1.06L9.56 12l1.72-1.72a.75.75 0 10-1.06-1.06l-3 3z" clip-rule="evenodd" /></svg>',
                    'arrow-left-on-rectangle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M15.75 2.25a.75.75 0 01.75.75v5.25a.75.75 0 01-1.5 0V4.81L8.03 12l6.97 7.19v-3.94a.75.75 0 011.5 0v5.25a.75.75 0 01-.75.75h-9a.75.75 0 01-.75-.75V3a.75.75 0 01.75-.75h9z" clip-rule="evenodd" /></svg>',
                    'arrow-left' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M7.72 12.53a.75.75 0 010-1.06l7.5-7.5a.75.75 0 111.06 1.06L9.31 12l6.97 6.97a.75.75 0 11-1.06 1.06l-7.5-7.5z" clip-rule="evenodd" /></svg>',
                    'arrow-path' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M4.755 10.059a7.5 7.5 0 0112.548-3.364l1.903 1.903h-4.5a.75.75 0 010-1.5h6a.75.75 0 01.75.75v6a.75.75 0 01-1.5 0v-4.5l-1.904 1.904a7.5 7.5 0 01-12.548 3.364zM4.755 10.059L3.48 10.762A9 9 0 0012 21a9 9 0 009-9 9 9 0 00-15.548-5.364L4.755 10.059z" clip-rule="evenodd" /></svg>',
                    'arrow-right-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm4.28 9.22a.75.75 0 000-1.06l-3-3a.75.75 0 10-1.06 1.06L14.44 12l-1.72 1.72a.75.75 0 101.06 1.06l3-3z" clip-rule="evenodd" /></svg>',
                    'arrow-right-on-rectangle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M8.25 2.25a.75.75 0 01.75.75v5.25a.75.75 0 01-1.5 0V4.81L1.53 12l6.97 7.19v-3.94a.75.75 0 011.5 0v5.25a.75.75 0 01-.75.75h-9a.75.75 0 01-.75-.75V3a.75.75 0 01.75-.75h9z" clip-rule="evenodd" /></svg>',
                    'arrow-right' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M16.28 11.47a.75.75 0 010 1.06l-7.5 7.5a.75.75 0 01-1.06-1.06L14.69 12 7.72 5.03a.75.75 0 011.06-1.06l7.5 7.5z" clip-rule="evenodd" /></svg>',
                    'arrow-trending-down' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M1.72 18.28a.75.75 0 011.06 0l5.47-5.47 2.94 2.94a.75.75 0 001.06 0l7.22-7.22a.75.75 0 10-1.06-1.06L11.5 13.94l-2.94-2.94a.75.75 0 00-1.06 0L1.72 17.22a.75.75 0 000 1.06z" clip-rule="evenodd" /></svg>',
                    'arrow-trending-up' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M1.72 5.72a.75.75 0 011.06 0l5.47 5.47 2.94-2.94a.75.75 0 011.06 0l7.22 7.22a.75.75 0 11-1.06 1.06L12.5 9.06l-2.94 2.94a.75.75 0 01-1.06 0L2.28 6.78a.75.75 0 01-.56-1.06z" clip-rule="evenodd" /></svg>',
                    'arrow-top-right-on-square' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M15.75 2.25a.75.75 0 01.75.75v5.25a.75.75 0 01-1.5 0V4.81L8.03 12l6.97 7.19v-3.94a.75.75 0 011.5 0v5.25a.75.75 0 01-.75.75h-9a.75.75 0 01-.75-.75V3a.75.75 0 01.75-.75h9z" clip-rule="evenodd" /></svg>',
                    'arrow-up-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm.53 7.97a.75.75 0 00-1.06 0l-3 3a.75.75 0 101.06 1.06l1.72-1.72v5.69a.75.75 0 001.5 0v-5.69l1.72 1.72a.75.75 0 101.06-1.06l-3-3z" clip-rule="evenodd" /></svg>',
                    'arrow-up-on-square' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25a.75.75 0 01.75.75v11.69l3.22-3.22a.75.75 0 111.06 1.06l-4.5 4.5a.75.75 0 01-1.06 0l-4.5-4.5a.75.75 0 111.06-1.06l3.22 3.22V3a.75.75 0 01.75-.75zM2.25 16.5a.75.75 0 01.75-.75h18a.75.75 0 01.75.75v3.75a.75.75 0 01-.75.75H3a.75.75 0 01-.75-.75V16.5z" clip-rule="evenodd" /></svg>',
                    'arrow-up-tray' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 21.75a.75.75 0 01-.75-.75V16.5h1.5v4.5a.75.75 0 01-.75.75z" /><path fill-rule="evenodd" d="M7.525 11.648a.75.75 0 010-1.06l4.5-4.5a.75.75 0 011.06 0l4.5 4.5a.75.75 0 01-1.06 1.06L12 7.811l-3.415 3.414a.75.75 0 01-1.06 0z" clip-rule="evenodd" /><path d="M3 15.75a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75z" /></svg>',
                    'arrow-up' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25a.75.75 0 01.75.75v16.19l6.22-6.22a.75.75 0 111.06 1.06l-7.5 7.5a.75.75 0 01-1.06 0l-7.5-7.5a.75.75 0 111.06-1.06l6.22 6.22V3a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'arrow-uturn-down' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M9.53 2.47a.75.75 0 010 1.06L4.81 8.25H15a6.75 6.75 0 010 13.5h-3a.75.75 0 010-1.5h3a5.25 5.25 0 100-10.5H4.81l4.72 4.72a.75.75 0 11-1.06 1.06l-6-6a.75.75 0 010-1.06l6-6a.75.75 0 011.06 0z" clip-rule="evenodd" /></svg>',
                    'arrow-uturn-left' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M9.53 2.47a.75.75 0 010 1.06L4.81 8.25H15a6.75 6.75 0 010 13.5h-3a.75.75 0 010-1.5h3a5.25 5.25 0 100-10.5H4.81l4.72 4.72a.75.75 0 11-1.06 1.06l-6-6a.75.75 0 010-1.06l6-6a.75.75 0 011.06 0z" clip-rule="evenodd" /></svg>',
                    'arrow-uturn-right' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M14.47 2.47a.75.75 0 010 1.06L19.19 8.25H9a6.75 6.75 0 000 13.5h3a.75.75 0 010 1.5H9a8.25 8.25 0 010-16.5h10.19l-4.72 4.72a.75.75 0 11-1.06-1.06l6-6a.75.75 0 011.06 0z" clip-rule="evenodd" /></svg>',
                    'arrow-uturn-up' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M14.47 21.53a.75.75 0 010-1.06l4.72-4.72H9a6.75 6.75 0 010-13.5h3a.75.75 0 010 1.5H9a5.25 5.25 0 100 10.5h10.19l-4.72-4.72a.75.75 0 111.06-1.06l6 6a.75.75 0 010 1.06l-6 6a.75.75 0 01-1.06 0z" clip-rule="evenodd" /></svg>',
                    'at-symbol' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12.5 6a.5.5 0 00-1 0v.5a.5.5 0 001 0V6zM12 7.25a.75.75 0 01.75.75v5a2.75 2.75 0 00-5.5 0v-5a.75.75 0 011.5 0v5a1.25 1.25 0 012.5 0v-5a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    
                    // B
                    'backward' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M9.53 2.47a.75.75 0 010 1.06L4.81 8.25H15a6.75 6.75 0 010 13.5h-3a.75.75 0 010-1.5h3a5.25 5.25 0 100-10.5H4.81l4.72 4.72a.75.75 0 11-1.06 1.06l-6-6a.75.75 0 010-1.06l6-6a.75.75 0 011.06 0z" clip-rule="evenodd" /></svg>',
                    'badge-check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M8.603 3.799A4.49 4.49 0 0112 2.25c1.357 0 2.573.6 3.397 1.549a4.49 4.49 0 013.498 1.307 4.491 4.491 0 011.307 3.497A4.49 4.49 0 0121.75 12c0 1.357-.6 2.573-1.549 3.397a4.49 4.49 0 01-1.307 3.497 4.491 4.491 0 01-3.497 1.307A4.49 4.49 0 0112 21.75a4.49 4.49 0 01-3.397-1.549 4.49 4.49 0 01-3.498-1.306 4.491 4.491 0 01-1.307-3.498A4.49 4.49 0 012.25 12c0-1.357.6-2.573 1.549-3.397a4.49 4.49 0 011.307-3.497 4.491 4.491 0 013.497-1.307zm3.169 7.776a.75.75 0 00-1.06-1.06l-2.434 2.433-1.12-1.12a.75.75 0 00-1.06 1.06l1.65 1.65a.75.75 0 001.06 0l3-3z" clip-rule="evenodd" /></svg>',
                    'banknotes' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 3a.75.75 0 00-1.5 0v1.5a.75.75 0 001.5 0V3zM3.75 6A.75.75 0 013 6.75v10.5a.75.75 0 011.5 0v-1.5h15v1.5a.75.75 0 01-1.5 0v-1.5a.75.75 0 00-1.5 0v1.5a.75.75 0 01-1.5 0v-1.5h-9v1.5a.75.75 0 01-1.5 0V6.75A.75.75 0 013.75 6zM21 3a.75.75 0 00-1.5 0v1.5a.75.75 0 001.5 0V3z" clip-rule="evenodd" /><path d="M12 10.5a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75zM12 6a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0V6.75A.75.75 0 0112 6zM12 15a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75z" /></svg>',
                    'bars-2' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 6.75A.75.75 0 013.75 6h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 6.75zM3 12a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 12zm0 5.25a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'bars-3' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 6.75A.75.75 0 013.75 6h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 6.75zM3 12a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 12zm0 5.25a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'menu' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 6.75A.75.75 0 013.75 6h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 6.75zM3 12a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 12zm0 5.25a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'battery-0' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M4.5 9a.75.75 0 01.75-.75h10.5a.75.75 0 01.75.75v6a.75.75 0 01-.75.75H5.25a.75.75 0 01-.75-.75V9zm12.75-3A.75.75 0 0118 6.75v10.5a.75.75 0 01-1.5 0V6.75a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'battery-100' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M4.5 9a.75.75 0 01.75-.75h10.5a.75.75 0 01.75.75v6a.75.75 0 01-.75.75H5.25a.75.75 0 01-.75-.75V9zM6 10.5v3h9v-3H6zm11.25-3a.75.75 0 01.75.75v10.5a.75.75 0 01-1.5 0V8.25a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'battery-50' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M4.5 9.75A.75.75 0 015.25 9h10.5a.75.75 0 01.75.75v4.5a.75.75 0 01-.75.75H5.25a.75.75 0 01-.75-.75v-4.5z" /><path fill-rule="evenodd" d="M3 9.75A1.75 1.75 0 014.75 8h11.5A1.75 1.75 0 0118 9.75v4.5A1.75 1.75 0 0116.25 16H4.75A1.75 1.75 0 013 14.25v-4.5zM18 15a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75zM18 9a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0V9.75a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'beaker' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3.75 3A.75.75 0 014.5 2.25h12.75a.75.75 0 010 1.5H4.5A.75.75 0 013.75 3zM3 6.75A.75.75 0 013.75 6h14.25a.75.75 0 010 1.5H3.75A.75.75 0 013 6.75zM6 10.5a.75.75 0 01.75-.75H18a.75.75 0 01.75.75v8.25a1.5 1.5 0 01-1.5 1.5H6.75a1.5 1.5 0 01-1.5-1.5V10.5z" clip-rule="evenodd" /></svg>',
                    'bell-alert' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12.75 2.25a.75.75 0 00-1.5 0v.134a1.5 1.5 0 00-1.5 1.492V6A2.25 2.25 0 007.5 8.25v2.261a.75.75 0 01-1.5 0V8.25a3.75 3.75 0 013.75-3.75V3.75a1.5 1.5 0 011.5-1.5h1.5a.75.75 0 000-1.5h-1.5z" /><path fill-rule="evenodd" d="M12.75 9a.75.75 0 00-1.5 0v4.281a1.125 1.125 0 01-2.25 0V9a.75.75 0 00-1.5 0v4.281a2.625 2.625 0 105.25 0V9zM15.75 9.75a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3z" clip-rule="evenodd" /><path d="M12 21a2.25 2.25 0 002.25-2.25H9.75A2.25 2.25 0 0012 21z" /></svg>',
                    'bell-slash' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-2.485 0-4.5 2.015-4.5 4.5v.268l-5.023 5.023a.75.75 0 001.06 1.06L5.75 11.832V15a.75.75 0 00.75.75h1.532l-1.06 1.06a.75.75 0 001.06 1.06L9 16.532V18a2.25 2.25 0 004.5 0v-1.468l2.121 2.121a.75.75 0 001.06-1.06L3.81 3.81a.75.75 0 00-1.06-1.06L2.25 3.25l-.023.023A.75.75 0 002.25 4.333l.023-.023L3.333 3.25l-.023.023a.75.75 0 001.06 1.06l16.687 16.687a.75.75 0 101.06-1.06L3.81 3.81zm9-5.932l-8.91 8.91a2.25 2.25 0 01-1.34.64v-3.328l8.91-8.91a2.25 2.25 0 011.34-.64v3.328z" clip-rule="evenodd" /></svg>',
                    'bell' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-2.485 0-4.5 2.015-4.5 4.5v.268l-1.57 1.57a.75.75 0 00-.06 1.06l.06.06L7.5 11.25v2.25a.75.75 0 001.5 0v-2.25H15v2.25a.75.75 0 001.5 0v-2.25l1.57-1.57a.75.75 0 00.06-1.06l-.06-.06-1.57-1.57V6.75c0-2.485-2.015-4.5-4.5-4.5zM12 15.75a2.25 2.25 0 002.25-2.25H9.75a2.25 2.25 0 002.25 2.25z" clip-rule="evenodd" /></svg>',
                    'bolt' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25a.75.75 0 01.75.75v7.5a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM12.5 15.75a.75.75 0 00-1.5 0v.01a.75.75 0 001.5 0v-.01zM11.25 15a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5a.75.75 0 01.75-.75zM12.75 15a.75.75 0 00-1.5 0v4.5a.75.75 0 001.5 0v-4.5z" clip-rule="evenodd" /></svg>',
                    'book-open' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-2.485 0-4.5 2.015-4.5 4.5s2.015 4.5 4.5 4.5 4.5-2.015 4.5-4.5-2.015-4.5-4.5-4.5zM7.5 4.5a2.25 2.25 0 000 4.5h9a2.25 2.25 0 000-4.5h-9zM4.5 13.5a3 3 0 013-3h9a3 3 0 013 3v6a3 3 0 01-3 3h-9a3 3 0 01-3-3v-6z" clip-rule="evenodd" /></svg>',
                    'bookmark-slash' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-1.246 0-2.391.1-3.447.283a.75.75 0 00-.553.886l.94 4.703a.75.75 0 001.396-.28l-.94-4.703A3.75 3.75 0 0112 3.75c1.171 0 2.22.456 2.977 1.203a.75.75 0 001.05-1.08A5.25 5.25 0 0012 2.25zM4.772 4.772a.75.75 0 00-1.06 1.06L5.833 8.01l-1.88 9.398A2.25 2.25 0 006.25 20.25h11.5a2.25 2.25 0 002.248-2.842l-1.88-9.398L20.25 6.833l-1.121-1.121-14.357 14.357a.75.75 0 01-1.06-1.06L4.772 4.772z" clip-rule="evenodd" /></svg>',
                    'bookmark' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M6 3a3 3 0 00-3 3v12a3 3 0 003 3h12a3 3 0 003-3V6a3 3 0 00-3-3H6zm1.5 1.5a.75.75 0 000 1.5h9a.75.75 0 000-1.5h-9z" clip-rule="evenodd" /></svg>',
                    'briefcase' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M9 3.75a.75.75 0 01.75-.75h4.5a.75.75 0 01.75.75v1.5h-6v-1.5z" clip-rule="evenodd" /><path d="M6 6a1.5 1.5 0 00-1.5 1.5v9A1.5 1.5 0 006 18h12a1.5 1.5 0 001.5-1.5v-9A1.5 1.5 0 0018 6H6zm1.5 1.5a.75.75 0 000 1.5h9a.75.75 0 000-1.5h-9z" /></svg>',
                    'bug-ant' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-2.485 0-4.5 2.015-4.5 4.5s2.015 4.5 4.5 4.5 4.5-2.015 4.5-4.5-2.015-4.5-4.5-4.5zM5.25 9.75a.75.75 0 01.75-.75H18a.75.75 0 01.75.75v4.5a.75.75 0 01-.75.75H6a.75.75 0 01-.75-.75v-4.5zM4.5 16.5a.75.75 0 01.75-.75h13.5a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0v-.75H5.25v.75a.75.75 0 01-1.5 0v-1.5z" clip-rule="evenodd" /></svg>',
                    'building-office' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M4.5 2.25a.75.75 0 000 1.5v16.5a.75.75 0 001.5 0V3.75a.75.75 0 00-1.5 0zM7.5 2.25a.75.75 0 000 1.5v16.5a.75.75 0 001.5 0V3.75a.75.75 0 00-1.5 0zM10.5 2.25a.75.75 0 000 1.5v16.5a.75.75 0 001.5 0V3.75a.75.75 0 00-1.5 0zM13.5 2.25a.75.75 0 000 1.5v16.5a.75.75 0 001.5 0V3.75a.75.75 0 00-1.5 0zM16.5 2.25a.75.75 0 000 1.5v16.5a.75.75 0 001.5 0V3.75a.75.75 0 00-1.5 0zM19.5 2.25a.75.75 0 000 1.5v16.5a.75.75 0 001.5 0V3.75a.75.75 0 00-1.5 0z" clip-rule="evenodd" /></svg>',
                    
                    // C
                    'calculator' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M6 3a3 3 0 00-3 3v12a3 3 0 003 3h12a3 3 0 003-3V6a3 3 0 00-3-3H6zm1.5 4.5a.75.75 0 000 1.5h9a.75.75 0 000-1.5h-9zM9 12a.75.75 0 01.75-.75h4.5a.75.75 0 010 1.5H9.75A.75.75 0 019 12zm-.75 3.75a.75.75 0 000 1.5h1.5a.75.75 0 000-1.5h-1.5z" clip-rule="evenodd" /></svg>',
                    'calendar-days' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M6 3a3 3 0 00-3 3v12a3 3 0 003 3h12a3 3 0 003-3V6a3 3 0 00-3-3H6zm1.5 4.5a.75.75 0 000 1.5h9a.75.75 0 000-1.5h-9zM9 12a.75.75 0 01.75-.75h.01a.75.75 0 01.75.75v.01a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75V12zm2.25.75a.75.75 0 00-.75-.75h-.01a.75.75 0 00-.75.75v.01a.75.75 0 00.75.75h.01a.75.75 0 00.75-.75V12.75zM15 12a.75.75 0 01.75-.75h.01a.75.75 0 01.75.75v.01a.75.75 0 01-.75.75h-.01a.75.75 0 01-.75-.75V12zM9 15.75a.75.75 0 01.75-.75h.01a.75.75 0 01.75.75v.01a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v-.01zM12 15a.75.75 0 01.75-.75h.01a.75.75 0 01.75.75v.01a.75.75 0 01-.75.75h-.01a.75.75 0 01-.75-.75V15z" clip-rule="evenodd" /></svg>',
                    'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M6 3a3 3 0 00-3 3v12a3 3 0 003 3h12a3 3 0 003-3V6a3 3 0 00-3-3H6zm1.5 3a.75.75 0 01.75-.75h9a.75.75 0 010 1.5h-9a.75.75 0 01-.75-.75z" /></svg>',
                    'camera' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12.75 6a.75.75 0 00-1.5 0v6a.75.75 0 001.5 0V6zM12 13.5a1.5 1.5 0 110 3 1.5 1.5 0 010-3z" clip-rule="evenodd" /></svg>',
                    'chart-bar-square' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 3a.75.75 0 01.75-.75h16.5a.75.75 0 01.75.75v16.5a.75.75 0 01-.75.75H3.75a.75.75 0 01-.75-.75V3zm6 4.5a.75.75 0 01.75.75v8.25a.75.75 0 01-1.5 0V8.25A.75.75 0 019 7.5zm3.75 0a.75.75 0 01.75.75v8.25a.75.75 0 01-1.5 0V8.25a.75.75 0 01.75-.75zm3.75 0a.75.75 0 01.75.75v8.25a.75.75 0 01-1.5 0V8.25a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'chart-bar' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M3 3v18h18V3H3zm16.5 16.5H4.5V4.5h15v15zM7.5 15.75a.75.75 0 00.75.75h.01a.75.75 0 00.75-.75v-6a.75.75 0 00-1.5 0v6zM11.25 15.75a.75.75 0 00.75.75h.01a.75.75 0 00.75-.75v-9a.75.75 0 00-1.5 0v9zM15 15.75a.75.75 0 00.75.75h.01a.75.75 0 00.75-.75v-3a.75.75 0 00-1.5 0v3z" /></svg>',
                    'chart-pie' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12 12.75a.75.75 0 00-1.5 0v5.25a.75.75 0 001.5 0v-5.25zm.75-6a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3z" clip-rule="evenodd" /></svg>',
                    'chat-bubble-left-ellipsis' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M10.5 4.5a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75zm-.75 2.25a.75.75 0 000 1.5h.01a.75.75 0 000-1.5h-.01zm1.5 0a.75.75 0 000 1.5h.01a.75.75 0 000-1.5h-.01z" clip-rule="evenodd" /><path d="M12 2.25a.75.75 0 01.75.75v18a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75z" /></svg>',
                    'chat-bubble-left-right' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M4.5 3.75A.75.75 0 015.25 3h9a.75.75 0 01.75.75v9a.75.75 0 01-.75.75H6a2.25 2.25 0 00-1.5 2.25V18a.75.75 0 01-1.5 0v-2.25A3.75 3.75 0 016.75 12H13.5V3.75H5.25z" clip-rule="evenodd" /><path d="M18.75 6a2.25 2.25 0 012.25 2.25v9.75a.75.75 0 01-1.5 0V8.25a.75.75 0 00-.75-.75h-9a.75.75 0 010-1.5h9z" /></svg>',
                    'chat-bubble-left' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M4.5 5.25a.75.75 0 01.75-.75h13.5a.75.75 0 01.75.75V15a.75.75 0 01-.75.75H12a.75.75 0 00-.75.75v2.25a.75.75 0 01-1.5 0V15.75a2.25 2.25 0 012.25-2.25H18V6H6v6.75a2.25 2.25 0 01-2.25 2.25H3a.75.75 0 01-.75-.75V6a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'chat-bubble-oval-left' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 3.48-9.75 7.75s4.365 7.75 9.75 7.75c.795 0 1.564-.09 2.296-.263a.75.75 0 01.554.886l-.94 4.703a.75.75 0 001.396.28l.94-4.703c.513-.274 1.002-.596 1.458-.958a7.722 7.722 0 002.59-3.13c.48-.99.742-2.074.742-3.21V10c0-4.27-4.365-7.75-9.75-7.75z" clip-rule="evenodd" /></svg>',
                    'check-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zm13.36-1.814a.75.75 0 10-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.14-.094l3.75-5.25z" clip-rule="evenodd" /></svg>',
                    'check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M19.916 4.626a.75.75 0 01.208 1.04l-9 13.5a.75.75 0 01-1.154.114l-6-6a.75.75 0 011.06-1.06l5.353 5.353 8.493-12.739a.75.75 0 011.04-.208z" clip-rule="evenodd" /></svg>',
                    'chevron-double-down' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12.53 16.28a.75.75 0 01-1.06 0l-7.5-7.5a.75.75 0 011.06-1.06L12 14.69l6.97-6.97a.75.75 0 111.06 1.06l-7.5 7.5z" clip-rule="evenodd" /><path fill-rule="evenodd" d="M12.53 10.28a.75.75 0 01-1.06 0l-7.5-7.5a.75.75 0 111.06-1.06L12 8.69l6.97-6.97a.75.75 0 111.06 1.06l-7.5 7.5z" clip-rule="evenodd" /></svg>',
                    'chevron-double-left' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M7.72 12.53a.75.75 0 010-1.06l7.5-7.5a.75.75 0 111.06 1.06L9.31 12l6.97 6.97a.75.75 0 11-1.06 1.06l-7.5-7.5z" clip-rule="evenodd" /><path fill-rule="evenodd" d="M1.72 12.53a.75.75 0 010-1.06l7.5-7.5a.75.75 0 111.06 1.06L3.31 12l6.97 6.97a.75.75 0 11-1.06 1.06l-7.5-7.5z" clip-rule="evenodd" /></svg>',
                    'chevron-double-right' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M16.28 11.47a.75.75 0 010 1.06l-7.5 7.5a.75.75 0 01-1.06-1.06L14.69 12 7.72 5.03a.75.75 0 011.06-1.06l7.5 7.5z" clip-rule="evenodd" /><path fill-rule="evenodd" d="M22.28 11.47a.75.75 0 010 1.06l-7.5 7.5a.75.75 0 01-1.06-1.06L20.69 12l-6.97-6.97a.75.75 0 011.06-1.06l7.5 7.5z" clip-rule="evenodd" /></svg>',
                    'chevron-double-up' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M11.47 7.72a.75.75 0 011.06 0l7.5 7.5a.75.75 0 11-1.06 1.06L12 9.31l-6.97 6.97a.75.75 0 01-1.06-1.06l7.5-7.5z" clip-rule="evenodd" /><path fill-rule="evenodd" d="M11.47 1.72a.75.75 0 011.06 0l7.5 7.5a.75.75 0 11-1.06 1.06L12 3.31 5.03 10.28a.75.75 0 01-1.06-1.06l7.5-7.5z" clip-rule="evenodd" /></svg>',
                    'chevron-down' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.23 8.29a.75.75 0 010-1.06z" clip-rule="evenodd" /></svg>',
                    'chevron-left' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.842 10l3.928 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" /></svg>',
                    'chevron-right' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.158 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>',
                    'chevron-up-down' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M11.47 4.72a.75.75 0 011.06 0l3.75 3.75a.75.75 0 01-1.06 1.06L12 6.31 8.78 9.53a.75.75 0 01-1.06-1.06l3.75-3.75zm-3.75 9.75a.75.75 0 011.06 0L12 17.69l3.22-3.22a.75.75 0 111.06 1.06l-3.75 3.75a.75.75 0 01-1.06 0l-3.75-3.75a.75.75 0 010-1.06z" clip-rule="evenodd" /></svg>',
                    'chevron-up' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.842l-3.71 3.928a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd" /></svg>',
                    'chip' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 3a.75.75 0 01.75-.75h16.5a.75.75 0 01.75.75v16.5a.75.75 0 01-.75.75H3.75a.75.75 0 01-.75-.75V3zm4.5 1.5a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3zM9 4.5a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3A.75.75 0 019 4.5zm3.75 0a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3zM15 4.5a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3a.75.75 0 01.75-.75zm3.75 0a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3zM4.5 9a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3A.75.75 0 014.5 9zM9 9.75a.75.75 0 000-1.5h10.5a.75.75 0 000 1.5H9zM4.5 12.75a.75.75 0 01.75-.75h13.5a.75.75 0 010 1.5H5.25a.75.75 0 01-.75-.75zM4.5 16.5a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'clipboard-document-check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12.75 2.25a.75.75 0 01.75.75v3.375a.75.75 0 01-1.5 0V3.75h-1.5v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75h3z" clip-rule="evenodd" /><path d="M4.5 6.75A.75.75 0 015.25 6h13.5a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75H5.25a.75.75 0 01-.75-.75V6.75z" /><path fill-rule="evenodd" d="M10.03 14.03a.75.75 0 010-1.06l1.5-1.5a.75.75 0 011.06 0l3 3a.75.75 0 01-1.06 1.06L12 14.81l-.97.97a.75.75 0 01-1.06 0l-1.5-1.5-.97.97a.75.75 0 01-1.06 0l-1.5-1.5a.75.75 0 010-1.06l1.5-1.5a.75.75 0 011.06 0l.97.97 1.5-1.5a.75.75 0 011.06 0l3 3a.75.75 0 01-1.06 1.06L12 14.81l-.97.97z" clip-rule="evenodd" /></svg>',
                    'clipboard-document' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12.75 2.25a.75.75 0 01.75.75v3.375a.75.75 0 01-1.5 0V3.75h-1.5v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75h3z" clip-rule="evenodd" /><path d="M4.5 6.75A.75.75 0 015.25 6h13.5a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75H5.25a.75.75 0 01-.75-.75V6.75z" /></svg>',
                    'clipboard' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12.75 2.25a.75.75 0 01.75.75v3.375a.75.75 0 01-1.5 0V3.75h-1.5v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75h3z" clip-rule="evenodd" /><path d="M4.5 6.75A.75.75 0 015.25 6h13.5a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75H5.25a.75.75 0 01-.75-.75V6.75z" /></svg>',
                    'clock' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12.75 6a.75.75 0 00-1.5 0v6c0 .414.336.75.75.75h4.5a.75.75 0 000-1.5h-3.75V6z" clip-rule="evenodd" /></svg>',
                    'cloud-arrow-down' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 100 13.5 6.75 6.75 0 000-13.5zM2.25 10.5a8.25 8.25 0 1114.59 5.28l4.69 4.69a.75.75 0 11-1.06 1.06l-4.69-4.69A8.25 8.25 0 012.25 10.5z" clip-rule="evenodd" /><path d="M12 6.75a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3a.75.75 0 01.75-.75z" /></svg>',

                    'cloud-arrow-up' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 100 13.5 6.75 6.75 0 000-13.5zM2.25 10.5a8.25 8.25 0 1114.59 5.28l4.69 4.69a.75.75 0 11-1.06 1.06l-4.69-4.69A8.25 8.25 0 012.25 10.5z" clip-rule="evenodd" /><path d="M12 6.75a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3a.75.75 0 01.75-.75z" /></svg>',
                    'cloud' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M19.5 9.75a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3a.75.75 0 01.75-.75zM15 9.75a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3a.75.75 0 01.75-.75zM10.5 9.75a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3a.75.75 0 01.75-.75zM6 9.75a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3A.75.75 0 016 9.75z" clip-rule="evenodd" /><path d="M4.5 3A1.5 1.5 0 003 4.5v15A1.5 1.5 0 004.5 21h15a1.5 1.5 0 001.5-1.5v-15A1.5 1.5 0 0019.5 3h-15z" /></svg>',
                    'code-bracket-square' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 3a.75.75 0 01.75-.75h16.5a.75.75 0 01.75.75v16.5a.75.75 0 01-.75.75H3.75a.75.75 0 01-.75-.75V3zm6.22 5.22a.75.75 0 010 1.06l-2.25 2.25 2.25 2.25a.75.75 0 01-1.06 1.06l-3-3a.75.75 0 010-1.06l3-3a.75.75 0 011.06 0zm4.5 1.06a.75.75 0 011.06 0l3 3a.75.75 0 010 1.06l-3 3a.75.75 0 01-1.06-1.06l2.25-2.25-2.25-2.25a.75.75 0 010-1.06z" clip-rule="evenodd" /></svg>',
                    'code-bracket' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M10.22 4.22a.75.75 0 010 1.06L5.47 10l4.75 4.72a.75.75 0 11-1.06 1.06l-5.25-5.25a.75.75 0 010-1.06l5.25-5.25a.75.75 0 011.06 0zm3.56 0a.75.75 0 011.06 0l5.25 5.25a.75.75 0 010 1.06l-5.25 5.25a.75.75 0 11-1.06-1.06L18.53 10l-4.75-4.72a.75.75 0 010-1.06z" clip-rule="evenodd" /></svg>',
                    'cog-6-tooth' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M11.078 2.25c-.917 0-1.699.663-1.946 1.55l-.06.273a.75.75 0 01-.364.552l-.248.149c-.83.498-1.423 1.258-1.743 2.188l-.05.15a.75.75 0 01-.22.502l-.18.271c-.593.896-.94 1.956-.94 3.078v.1c0 1.122.347 2.182.94 3.078l.18.271a.75.75 0 01.22.502l.05.15c.32.93.913 1.69 1.743 2.188l.248.149a.75.75 0 01.364.552l.06.273c.247.887 1.03 1.55 1.946 1.55h1.844c.917 0 1.699-.663 1.946-1.55l.06-.273a.75.75 0 01.364-.552l.248-.149c.83-.498 1.423-1.258 1.743-2.188l.05-.15a.75.75 0 01.22-.502l.18-.271c.593-.896.94-1.956.94-3.078v-.1c0-1.122-.347-2.182-.94-3.078l-.18-.271a.75.75 0 01-.22-.502l-.05-.15c-.32-.93-.913-1.69-1.743-2.188l-.248-.149a.75.75 0 01-.364-.552l-.06-.273A2.001 2.001 0 0012.922 2.25H11.08zM12 9a3 3 0 100 6 3 3 0 000-6z" clip-rule="evenodd" /></svg>',
                    'cog' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M11.078 2.25c-.917 0-1.699.663-1.946 1.55l-.06.273a.75.75 0 01-.364.552l-.248.149c-.83.498-1.423 1.258-1.743 2.188l-.05.15a.75.75 0 01-.22.502l-.18.271c-.593.896-.94 1.956-.94 3.078v.1c0 1.122.347 2.182.94 3.078l.18.271a.75.75 0 01.22.502l.05.15c.32.93.913 1.69 1.743 2.188l.248.149a.75.75 0 01.364.552l.06.273c.247.887 1.03 1.55 1.946 1.55h1.844c.917 0 1.699-.663 1.946-1.55l.06-.273a.75.75 0 01.364-.552l.248-.149c.83-.498 1.423-1.258 1.743-2.188l.05-.15a.75.75 0 01.22-.502l.18-.271c.593-.896.94-1.956.94-3.078v-.1c0-1.122-.347-2.182-.94-3.078l-.18-.271a.75.75 0 01-.22-.502l-.05-.15c-.32-.93-.913-1.69-1.743-2.188l-.248-.149a.75.75 0 01-.364-.552l-.06-.273A2.001 2.001 0 0012.922 2.25H11.08zM12 9a3 3 0 100 6 3 3 0 000-6z" clip-rule="evenodd" /></svg>',
                    'command-line' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 3a.75.75 0 01.75-.75h16.5a.75.75 0 01.75.75v16.5a.75.75 0 01-.75.75H3.75a.75.75 0 01-.75-.75V3zm4.5 5.25a.75.75 0 000 1.5h.75a.75.75 0 000-1.5H7.5zm3 0a.75.75 0 000 1.5h3a.75.75 0 000-1.5h-3zm-3 3a.75.75 0 000 1.5h.75a.75.75 0 000-1.5H7.5zm3 0a.75.75 0 000 1.5h3a.75.75 0 000-1.5h-3zm-3 3a.75.75 0 000 1.5h.75a.75.75 0 000-1.5H7.5zm3 0a.75.75 0 000 1.5h3a.75.75 0 000-1.5h-3z" clip-rule="evenodd" /></svg>',
                    'computer-desktop' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25v7.5A2.25 2.25 0 0118.75 15H5.25A2.25 2.25 0 013 12.75v-7.5zM12 18a.75.75 0 000 1.5h-3.75a.75.75 0 000 1.5h7.5a.75.75 0 000-1.5H12a.75.75 0 000-1.5z" clip-rule="evenodd" /></svg>',
                    'cpu-chip' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 3a.75.75 0 01.75-.75h16.5a.75.75 0 01.75.75v16.5a.75.75 0 01-.75.75H3.75a.75.75 0 01-.75-.75V3zm4.5 1.5a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3zM9 4.5a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3A.75.75 0 019 4.5zm3.75 0a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3zM15 4.5a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3a.75.75 0 01.75-.75zm3.75 0a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3zM4.5 9a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3A.75.75 0 014.5 9zM9 9.75a.75.75 0 000-1.5h10.5a.75.75 0 000 1.5H9zM4.5 12.75a.75.75 0 01.75-.75h13.5a.75.75 0 010 1.5H5.25a.75.75 0 01-.75-.75zM4.5 16.5a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'credit-card' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M3.75 3A1.75 1.75 0 002 4.75v14.5A1.75 1.75 0 003.75 21h16.5A1.75 1.75 0 0022 19.25V4.75A1.75 1.75 0 0020.25 3H3.75zM8.25 6a.75.75 0 01.75.75h6a.75.75 0 010 1.5H9a.75.75 0 01-.75-.75V6z" /></svg>',
                    'cube-transparent' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25a.75.75 0 01.75.75v8.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75z" clip-rule="evenodd" /><path d="M3 5.25a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75z" /><path fill-rule="evenodd" d="M12 2.25c-4.434 0-8.207 3.036-9.157 7.022a.75.75 0 011.458-.337A6.75 6.75 0 0112 3.75c3.088 0 5.72 2.058 6.7 4.935a.75.75 0 011.458.337C20.207 5.286 16.434 2.25 12 2.25z" clip-rule="evenodd" /><path d="M3.043 12.337a.75.75 0 011.458-.337A6.75 6.75 0 0112 10.5c3.088 0 5.72 2.058 6.7 4.935a.75.75 0 01-1.458.337A5.25 5.25 0 0012 12a5.25 5.25 0 00-6.7-4.935z" /><path d="M4.5 17.25a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0v-1.5a.75.75 0 01.75-.75zM18.75 17.25a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0v-1.5a.75.75 0 01.75-.75z" /></svg>',
                    'cube' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12.75 6a.75.75 0 00-1.5 0v6a.75.75 0 00.75.75h3.75a.75.75 0 000-1.5H12.75V6z" clip-rule="evenodd" /></svg>',
                    'currency-dollar' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12.75 6a.75.75 0 00-1.5 0v1.5a.75.75 0 001.5 0V6zM12 8.25a.75.75 0 01.75.75v3.75a.75.75 0 01-1.5 0V9a.75.75 0 01.75-.75zM12 15a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0v-1.5a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'currency-euro' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM10.5 7.5a.75.75 0 00-1.5 0v9a.75.75 0 001.5 0v-9zm4.5 0a.75.75 0 00-1.5 0v9a.75.75 0 001.5 0v-9zM7.5 11.25a.75.75 0 000 1.5h9a.75.75 0 000-1.5h-9z" clip-rule="evenodd" /></svg>',
                    'currency-pound' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM10.5 6a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0V6.75a.75.75 0 01.75-.75zm3 0a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0V6.75A.75.75 0 0113.5 6zM7.5 11.25a.75.75 0 010-1.5h9a.75.75 0 010 1.5h-9zM12 15a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0v-1.5a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'currency-rupee' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM9 8.25a.75.75 0 01.75-.75h4.5a.75.75 0 010 1.5H9.75A.75.75 0 019 8.25zm.75 3a.75.75 0 000 1.5h3a.75.75 0 000-1.5h-3zm-1.5 3.75a.75.75 0 01.75-.75h6a.75.75 0 010 1.5h-6a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'currency-yen' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM9.75 6.75a.75.75 0 01.75-.75h3a.75.75 0 01.75.75v.01a.75.75 0 01-.75.75h-3a.75.75 0 01-.75-.75V6.75zM12 9a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0v-1.5A.75.75 0 0112 9zm-2.25 3a.75.75 0 010 1.5h4.5a.75.75 0 010-1.5h-4.5zM12 15a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0v-1.5a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    
                    // D
                    'document-arrow-down' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.625 1.5A3.375 3.375 0 002.25 4.875v14.25A3.375 3.375 0 005.625 22.5h12.75A3.375 3.375 0 0021.75 19.125V4.875A3.375 3.375 0 0018.375 1.5H5.625zM12 10.5a.75.75 0 01.75.75v2.56l1.22-1.22a.75.75 0 111.06 1.06l-2.5 2.5a.75.75 0 01-1.06 0l-2.5-2.5a.75.75 0 111.06-1.06l1.22 1.22v-2.56a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'document-arrow-up' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.625 1.5A3.375 3.375 0 002.25 4.875v14.25A3.375 3.375 0 005.625 22.5h12.75A3.375 3.375 0 0021.75 19.125V4.875A3.375 3.375 0 0018.375 1.5H5.625zM12 9a.75.75 0 01.75.75v2.56l1.22-1.22a.75.75 0 111.06 1.06l-2.5 2.5a.75.75 0 01-1.06 0l-2.5-2.5a.75.75 0 111.06-1.06l1.22 1.22V9.75A.75.75 0 0112 9z" clip-rule="evenodd" /></svg>',
                    'document-chart-bar' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.625 1.5A3.375 3.375 0 002.25 4.875v14.25A3.375 3.375 0 005.625 22.5h12.75A3.375 3.375 0 0021.75 19.125V4.875A3.375 3.375 0 0018.375 1.5H5.625zM9 9.75a.75.75 0 000 1.5h.01a.75.75 0 00.75-.75v-3a.75.75 0 00-1.5 0v2.25H9zm2.25.75a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75zm-3 3a.75.75 0 000 1.5h.01a.75.75 0 00.75-.75v-3a.75.75 0 00-1.5 0v2.25H9zm2.25.75a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'document-check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.625 1.5A3.375 3.375 0 002.25 4.875v14.25A3.375 3.375 0 005.625 22.5h12.75A3.375 3.375 0 0021.75 19.125V4.875A3.375 3.375 0 0018.375 1.5H5.625zM10.875 10.5a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.06 0l4.5-4.5a.75.75 0 00-1.06-1.06l-3.97 3.97-1.72-1.72z" clip-rule="evenodd" /></svg>',
                    'document-duplicate' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M4.5 4.5a3 3 0 00-3 3v9a3 3 0 003 3h9a3 3 0 003-3v-9a3 3 0 00-3-3h-9z" /><path fill-rule="evenodd" d="M9 1.5a.75.75 0 01.75-.75h9a3 3 0 013 3v9a.75.75 0 01-1.5 0V3.75a1.5 1.5 0 00-1.5-1.5h-9a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'document-minus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.625 1.5A3.375 3.375 0 002.25 4.875v14.25A3.375 3.375 0 005.625 22.5h12.75A3.375 3.375 0 0021.75 19.125V4.875A3.375 3.375 0 0018.375 1.5H5.625zM9.75 12a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'document-plus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.625 1.5A3.375 3.375 0 002.25 4.875v14.25A3.375 3.375 0 005.625 22.5h12.75A3.375 3.375 0 0021.75 19.125V4.875A3.375 3.375 0 0018.375 1.5H5.625zM12.75 9.75a.75.75 0 00-1.5 0v1.5h-1.5a.75.75 0 000 1.5h1.5v1.5a.75.75 0 001.5 0v-1.5h1.5a.75.75 0 000-1.5h-1.5v-1.5z" clip-rule="evenodd" /></svg>',
                    'document-text' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.625 1.5A3.375 3.375 0 002.25 4.875v14.25A3.375 3.375 0 005.625 22.5h12.75A3.375 3.375 0 0021.75 19.125V4.875A3.375 3.375 0 0018.375 1.5H5.625zM9 7.5A.75.75 0 008.25 9h7.5a.75.75 0 000-1.5h-7.5zM9 11.25a.75.75 0 000 1.5h7.5a.75.75 0 000-1.5h-7.5zM8.25 15a.75.75 0 01.75-.75h4.5a.75.75 0 010 1.5h-4.5a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'document' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M4.5 4.5a3 3 0 00-3 3v9a3 3 0 003 3h9a3 3 0 003-3v-9a3 3 0 00-3-3h-9z" /></svg>',
                    
                    // E
                    'ellipsis-horizontal' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M6 12a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm6 0a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm6 0a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z" /></svg>',
                    'ellipsis-vertical' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 6a1.5 1.5 0 110-3 1.5 1.5 0 010 3zm0 6a1.5 1.5 0 110-3 1.5 1.5 0 010 3zm0 6a1.5 1.5 0 110-3 1.5 1.5 0 010 3z" /></svg>',
                    'envelope-open' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M4.5 3.75a3 3 0 00-3 3v.75h21v-.75a3 3 0 00-3-3h-15z" /><path fill-rule="evenodd" d="M22.5 9.75h-21v7.5a3 3 0 003 3h15a3 3 0 003-3v-7.5zm-18.75 3a.75.75 0 01.75-.75h6a.75.75 0 010 1.5h-6a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'envelope' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M1.5 8.67v8.58a3 3 0 003 3h15a3 3 0 003-3V8.67l-8.928 5.493a3 3 0 01-3.144 0L1.5 8.67z" /><path d="M22.5 6.908V6.75a3 3 0 00-3-3h-15a3 3 0 00-3 3v.158l9.714 5.978a1.5 1.5 0 001.572 0L22.5 6.908z" /></svg>',
                    'exclamation-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zM12 8.25a.75.75 0 01.75.75v3.75a.75.75 0 01-1.5 0V9a.75.75 0 01.75-.75zm0 8.25a.75.75 0 100-1.5.75.75 0 000 1.5z" clip-rule="evenodd" /></svg>',
                    'exclamation-triangle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M9.401 3.003c1.155-2 4.043-2 5.197 0l7.355 12.748c1.154 2-.29 4.5-2.599 4.5H4.645c-2.309 0-3.752-2.5-2.598-4.5L9.4 3.003zM12 8.25a.75.75 0 01.75.75v3.75a.75.75 0 01-1.5 0V9a.75.75 0 01.75-.75zm0 8.25a.75.75 0 100-1.5.75.75 0 000 1.5z" clip-rule="evenodd" /></svg>',
                    'eye-slash' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M3.53 2.47a.75.75 0 00-1.06 1.06l18 18a.75.75 0 101.06-1.06l-18-18zM22.676 12.553a11.249 11.249 0 01-2.631 4.31l-3.099-3.099a5.25 5.25 0 00-6.71-6.71L7.759 4.577a11.217 11.217 0 014.242-.827c4.97 0 9.185 3.223 10.675 7.69.12.362.12.752 0 1.114z" /><path d="M11.245 15.46a5.25 5.25 0 006.71-6.71l-6.71 6.71zM1.324 12.553a11.25 11.25 0 0110.675-7.69 11.25 11.25 0 014.945 1.565l-3.099 3.099a5.25 5.25 0 00-6.71 6.71L1.324 12.553z" /></svg>',
                    'eye' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 15a3 3 0 100-6 3 3 0 000 6z" /><path fill-rule="evenodd" d="M1.323 11.447C2.811 6.976 7.028 3.75 12 3.75c4.97 0 9.185 3.223 10.677 7.697.12.362.12.752 0 1.114C21.185 17.024 16.97 20.25 12 20.25c-4.97 0-9.185-3.223-10.677-7.697a.82.82 0 010-1.114zM17.25 12a5.25 5.25 0 11-10.5 0 5.25 5.25 0 0110.5 0z" clip-rule="evenodd" /></svg>',
                    
                    // F
                    'face-frown' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM8.25 8.25a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75zm5.25.75a.75.75 0 00-1.5 0v.01a.75.75 0 001.5 0v-.01zM9 15.75a.75.75 0 01.75-.75h4.5a.75.75 0 010 1.5h-4.5a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'face-smile' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM8.25 8.25a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75zm5.25.75a.75.75 0 00-1.5 0v.01a.75.75 0 001.5 0v-.01zm.75 3a.75.75 0 00-1.5 0v3.25a.75.75 0 001.5 0v-3.25z" clip-rule="evenodd" /></svg>',
                    'film' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3.75 3A.75.75 0 014.5 2.25h15a.75.75 0 01.75.75v18a.75.75 0 01-1.5 0v-1.5H5.25v1.5a.75.75 0 01-1.5 0V3zM6 4.5a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5H6.75A.75.75 0 016 4.5zm0 3a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5H6.75A.75.75 0 016 7.5zm0 3a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5H6.75a.75.75 0 01-.75-.75zm0 3a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5H6.75a.75.75 0 01-.75-.75zm10.5-9a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5a.75.75 0 01-.75-.75zm0 3a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5a.75.75 0 01-.75-.75zm0 3a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5a.75.75 0 01-.75-.75zm0 3a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'finger-print' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 3a9 9 0 00-9 9c0 4.968 4.032 9 9 9s9-4.032 9-9a9 9 0 00-9-9zM8.25 7.5a.75.75 0 01.75-.75h.75a.75.75 0 01.75.75v9a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v-9zm3-1.5a.75.75 0 01.75-.75h.75a.75.75 0 01.75.75v12a.75.75 0 01-.75.75h-.75a.75.75 0 01-.75-.75V6zm3-1.5a.75.75 0 01.75-.75h.75a.75.75 0 01.75.75v15a.75.75 0 01-.75.75h-.75a.75.75 0 01-.75-.75V4.5z" clip-rule="evenodd" /></svg>',
                    'fire' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12.963 2.286a.75.75 0 00-1.071 1.052A11.218 11.218 0 0112 11.25c0 1.488-.28 2.91-.806 4.223a.75.75 0 001.07 1.052 12.71 12.71 0 00.806-4.223c0-3.32-1.34-6.32-3.537-8.514z" clip-rule="evenodd" /><path d="M10.875 14.625c0 1.41-1.146 2.563-2.563 2.563s-2.563-1.152-2.563-2.563c0-.623.224-1.196.602-1.666a.75.75 0 011.13.992 1.06 1.06 0 00-.363.774c0 .586.474 1.06 1.06 1.06s1.06-.474 1.06-1.06c0-.288-.112-.55-.31-.745a.75.75 0 111.06-1.06c.66.66.975 1.54.975 2.47z" /></svg>',
                    'flag' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 2.25a.75.75 0 01.75.75v18a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75z" clip-rule="evenodd" /><path d="M6.75 6.75a.75.75 0 01.75-.75h10.5a.75.75 0 01.75.75v6a.75.75 0 01-.75.75H7.5a.75.75 0 01-.75-.75v-6z" /></svg>',
                    'folder-minus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.625 3A2.625 2.625 0 003 5.625v12.75c0 1.448 1.177 2.625 2.625 2.625h12.75A2.625 2.625 0 0021 18.375V5.625A2.625 2.625 0 0018.375 3H5.625zM9.75 12a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'folder-open' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M4.5 4.5a3 3 0 00-3 3v9a3 3 0 003 3h9a3 3 0 003-3v-9a3 3 0 00-3-3h-9z" /><path fill-rule="evenodd" d="M9 1.5a.75.75 0 01.75-.75h9a3 3 0 013 3v9a.75.75 0 01-1.5 0V3.75a1.5 1.5 0 00-1.5-1.5h-9a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'folder-plus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.625 3A2.625 2.625 0 003 5.625v12.75c0 1.448 1.177 2.625 2.625 2.625h12.75A2.625 2.625 0 0021 18.375V5.625A2.625 2.625 0 0018.375 3H5.625zM12.75 9.75a.75.75 0 00-1.5 0v1.5h-1.5a.75.75 0 000 1.5h1.5v1.5a.75.75 0 001.5 0v-1.5h1.5a.75.75 0 000-1.5h-1.5v-1.5z" clip-rule="evenodd" /></svg>',
                    'folder' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M4.5 4.5a3 3 0 00-3 3v9a3 3 0 003 3h9a3 3 0 003-3v-9a3 3 0 00-3-3h-9z" /></svg>',
                    'forward' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M14.47 2.47a.75.75 0 010 1.06L19.19 8.25H9a6.75 6.75 0 000 13.5h3a.75.75 0 010 1.5H9a8.25 8.25 0 010-16.5h10.19l-4.72 4.72a.75.75 0 11-1.06-1.06l6-6a.75.75 0 011.06 0z" clip-rule="evenodd" /></svg>',
                    
                    // G
                    'gift' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-2.485 0-4.5 2.015-4.5 4.5v.75a.75.75 0 001.5 0v-.75a3 3 0 016 0v.75a.75.75 0 001.5 0v-.75a4.5 4.5 0 00-4.5-4.5z" clip-rule="evenodd" /><path d="M6 8.25a3 3 0 00-3 3v9a3 3 0 003 3h12a3 3 0 003-3v-9a3 3 0 00-3-3H6zm.75 3a.75.75 0 000 1.5h10.5a.75.75 0 000-1.5H6.75z" /></svg>',
                    'globe-alt' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM8.25 7.5a.75.75 0 01.75-.75h.75a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01H8.25zm.75 3a.75.75 0 00-1.5 0v.01a.75.75 0 001.5 0v-.01zM11.25 7.5a.75.75 0 01.75-.75h.75a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01H11.25zm.75 3a.75.75 0 00-1.5 0v.01a.75.75 0 001.5 0v-.01zM14.25 7.5a.75.75 0 01.75-.75h.75a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01H14.25zm.75 3a.75.75 0 00-1.5 0v.01a.75.75 0 001.5 0v-.01z" clip-rule="evenodd" /></svg>',
                    'globe-americas' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM8.25 8.25a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75zm.75 3a.75.75 0 00-1.5 0v.01a.75.75 0 001.5 0v-.01zM11.25 8.25a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75zm.75 3a.75.75 0 00-1.5 0v.01a.75.75 0 001.5 0v-.01z" clip-rule="evenodd" /></svg>',
                    
                    // H
                    'hand-raised' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-2.485 0-4.5 2.015-4.5 4.5v10.5a.75.75 0 01-1.5 0V6.75A6 6 0 0112 1.5a6 6 0 016 5.25v10.5a.75.75 0 01-1.5 0V6.75a4.5 4.5 0 00-4.5-4.5z" clip-rule="evenodd" /></svg>',
                    'hand-thumb-down' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-2.485 0-4.5 2.015-4.5 4.5v.75a.75.75 0 001.5 0v-.75a3 3 0 016 0v.75a.75.75 0 001.5 0v-.75a4.5 4.5 0 00-4.5-4.5z" clip-rule="evenodd" /><path d="M3.75 12.75a.75.75 0 000 1.5h16.5a.75.75 0 000-1.5H3.75z" /></svg>',
                    'hand-thumb-up' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><!--!Font Awesome Free v7.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path d="M144 224C161.7 224 176 238.3 176 256L176 512C176 529.7 161.7 544 144 544L96 544C78.3 544 64 529.7 64 512L64 256C64 238.3 78.3 224 96 224L144 224zM334.6 80C361.9 80 384 102.1 384 129.4L384 133.6C384 140.4 382.7 147.2 380.2 153.5L352 224L512 224C538.5 224 560 245.5 560 272C560 291.7 548.1 308.6 531.1 316C548.1 323.4 560 340.3 560 360C560 383.4 543.2 402.9 521 407.1C525.4 414.4 528 422.9 528 432C528 454.2 513 472.8 492.6 478.3C494.8 483.8 496 489.8 496 496C496 522.5 474.5 544 448 544L360.1 544C323.8 544 288.5 531.6 260.2 508.9L248 499.2C232.8 487.1 224 468.7 224 449.2L224 262.6C224 247.7 227.5 233 234.1 219.7L290.3 107.3C298.7 90.6 315.8 80 334.6 80z"/></svg>',
                    'hashtag' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.25 3.75a.75.75 0 01.75-.75h3.04l-1.5 18a.75.75 0 01-1.48-.26l1.5-18H5.25zM15 3.75a.75.75 0 01.75-.75h.04l1.5 18a.75.75 0 01-1.48.26l-1.5-18H15zM7.5 9.75a.75.75 0 01.75-.75h9a.75.75 0 010 1.5h-9a.75.75 0 01-.75-.75zM7.5 14.25a.75.75 0 01.75-.75h9a.75.75 0 010 1.5h-9a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'heart' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 185"><path d="M100 184.606a15.384 15.384 0 0 1-8.653-2.678C53.565 156.28 37.205 138.695 28.182 127.7 8.952 104.264-.254 80.202.005 54.146.308 24.287 24.264 0 53.406 0c21.192 0 35.869 11.937 44.416 21.879a2.884 2.884 0 0 0 4.356 0C110.725 11.927 125.402 0 146.594 0c29.142 0 53.098 24.287 53.4 54.151.26 26.061-8.956 50.122-28.176 73.554-9.023 10.994-25.383 28.58-63.165 54.228a15.384 15.384 0 0 1-8.653 2.673Z"/></svg>',
                    'home' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M11.47 3.84a.75.75 0 011.06 0l8.69 8.69a.75.75 0 101.06-1.06l-8.689-8.69a2.25 2.25 0 00-3.182 0l-8.69 8.69a.75.75 0 101.061 1.06l8.69-8.69z" /><path d="M12 5.432l8.159 8.159c.03.03.06.058.091.086v6.198c0 1.035-.84 1.875-1.875 1.875H15a.75.75 0 01-.75-.75v-4.5a.75.75 0 00-.75-.75h-3a.75.75 0 00-.75.75V21a.75.75 0 01-.75.75H5.625a1.875 1.875 0 01-1.875-1.875v-6.198a2.29 2.29 0 00.091-.086L12 5.43z" /></svg>',
                    'hourglass' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12.75 6a.75.75 0 00-1.5 0v6a.75.75 0 001.5 0V6zM12 13.5a1.5 1.5 0 110 3 1.5 1.5 0 010-3z" clip-rule="evenodd" /></svg>',
                    
                    // I
                    'identification' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 3a.75.75 0 01.75-.75h16.5a.75.75 0 01.75.75v16.5a.75.75 0 01-.75.75H3.75a.75.75 0 01-.75-.75V3zm4.5 1.5a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3zM9 4.5a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3A.75.75 0 019 4.5zm3.75 0a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3zM15 4.5a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3a.75.75 0 01.75-.75zm3.75 0a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3zM4.5 9a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3A.75.75 0 014.5 9zM9 9.75a.75.75 0 000-1.5h10.5a.75.75 0 000 1.5H9zM4.5 12.75a.75.75 0 01.75-.75h13.5a.75.75 0 010 1.5H5.25a.75.75 0 01-.75-.75zM4.5 16.5a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'inbox-arrow-down' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.625 3A2.625 2.625 0 003 5.625v12.75c0 1.448 1.177 2.625 2.625 2.625h12.75A2.625 2.625 0 0021 18.375V5.625A2.625 2.625 0 0018.375 3H5.625zM12 10.5a.75.75 0 01.75.75v2.56l1.22-1.22a.75.75 0 111.06 1.06l-2.5 2.5a.75.75 0 01-1.06 0l-2.5-2.5a.75.75 0 111.06-1.06l1.22 1.22v-2.56a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'inbox-stack' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 4.5a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 4.5zM3.75 8.25a.75.75 0 01.75-.75h15a.75.75 0 010 1.5h-15a.75.75 0 01-.75-.75z" clip-rule="evenodd" /><path d="M4.5 12a.75.75 0 01.75-.75h13.5a.75.75 0 010 1.5H5.25a.75.75 0 01-.75-.75z" /></svg>',
                    'inbox' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.625 3A2.625 2.625 0 003 5.625v12.75c0 1.448 1.177 2.625 2.625 2.625h12.75A2.625 2.625 0 0021 18.375V5.625A2.625 2.625 0 0018.375 3H5.625zM9 7.5a.75.75 0 000 1.5h6a.75.75 0 000-1.5H9z" clip-rule="evenodd" /></svg>',
                    'information-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zM12 8.25a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75zM12 10.5a.75.75 0 01.75.75v3.75a.75.75 0 01-1.5 0V11.25a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    
                    // K
                    'key' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12.525 3.336a.75.75 0 00-1.05 1.05l5.25 5.25a.75.75 0 001.05-1.05l-5.25-5.25z" clip-rule="evenodd" /><path d="M12 6a.75.75 0 01.75.75v10.5a.75.75 0 01-1.5 0V6.75A.75.75 0 0112 6z" /><path fill-rule="evenodd" d="M3.75 9.75a.75.75 0 01.75-.75h4.5a.75.75 0 010 1.5h-4.5a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    
                    // L
                    'language' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12.97 3.97a.75.75 0 011.06 0l7.5 7.5a.75.75 0 010 1.06l-7.5 7.5a.75.75 0 11-1.06-1.06l6.22-6.22H3a.75.75 0 010-1.5h16.19l-6.22-6.22a.75.75 0 010-1.06z" clip-rule="evenodd" /></svg>',
                    'lifebuoy' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12 6a1.5 1.5 0 100 3 1.5 1.5 0 000-3zm-3 4.5a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0zm6 0a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0zm3 4.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" clip-rule="evenodd" /></svg>',
                    'light-bulb' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 6a.75.75 0 000 1.5h1.5a.75.75 0 000-1.5H7.5zM15 6a.75.75 0 000 1.5h1.5a.75.75 0 000-1.5H15z" /><path fill-rule="evenodd" d="M4.5 9A3 3 0 017.5 6h9a3 3 0 013 3v9a3 3 0 01-3 3h-9a3 3 0 01-3-3V9zm3 1.5a.75.75 0 01.75-.75h4.5a.75.75 0 010 1.5h-4.5a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'link' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 18a6 6 0 100-12 6 6 0 000 12z" /><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zM12 1.5A10.5 10.5 0 1012 22.5 10.5 10.5 0 0012 1.5z" clip-rule="evenodd" /></svg>',
                    'list-bullet' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3.75 12a.75.75 0 01.75-.75h15a.75.75 0 010 1.5h-15a.75.75 0 01-.75-.75zM3.75 6a.75.75 0 01.75-.75h15a.75.75 0 010 1.5h-15a.75.75 0 01-.75-.75zM3.75 18a.75.75 0 01.75-.75h15a.75.75 0 010 1.5h-15a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'lock-closed' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 1.5a5.25 5.25 0 00-5.25 5.25v3a3 3 0 00-3 3v6.75a3 3 0 003 3h10.5a3 3 0 003-3v-6.75a3 3 0 00-3-3v-3A5.25 5.25 0 0012 1.5zm-3.75 5.25a3.75 3.75 0 107.5 0v3h-7.5v-3z" clip-rule="evenodd" /></svg>',
                    'lock-open' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M18 1.5c2.9 0 5.25 2.35 5.25 5.25v3.75a.75.75 0 01-1.5 0V6.75a3.75 3.75 0 10-7.5 0v3a3 3 0 013 3v6.75a3 3 0 01-3 3H9a3 3 0 01-3-3v-6.75a3 3 0 013-3h1.5V6.75a5.25 5.25 0 0110.5 0v1.5a.75.75 0 01-1.5 0V6.75a3.75 3.75 0 00-7.5 0v3a.75.75 0 01-.75.75h-1.5a1.5 1.5 0 00-1.5 1.5v6.75a1.5 1.5 0 001.5 1.5H15a1.5 1.5 0 001.5-1.5V13.5a1.5 1.5 0 00-1.5-1.5H9.75a.75.75 0 01-.75-.75v-3a3.75 3.75 0 017.5 0v1.5a.75.75 0 01-1.5 0V6.75a2.25 2.25 0 10-4.5 0v3a.75.75 0 01-1.5 0V6.75a3.75 3.75 0 017.5 0z" /></svg>',
                    
                    // M
                    'magnifying-glass-minus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 100 13.5 6.75 6.75 0 000-13.5zM2.25 10.5a8.25 8.25 0 1114.59 5.28l4.69 4.69a.75.75 0 11-1.06 1.06l-4.69-4.69A8.25 8.25 0 012.25 10.5z" clip-rule="evenodd" /><path d="M9.75 10.5a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5a.75.75 0 01-.75-.75z" /></svg>',
                    'magnifying-glass-plus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 100 13.5 6.75 6.75 0 000-13.5zM2.25 10.5a8.25 8.25 0 1114.59 5.28l4.69 4.69a.75.75 0 11-1.06 1.06l-4.69-4.69A8.25 8.25 0 012.25 10.5z" clip-rule="evenodd" /><path d="M11.25 9.75a.75.75 0 01.75.75v.75h.75a.75.75 0 010 1.5h-.75v.75a.75.75 0 01-1.5 0v-.75h-.75a.75.75 0 010-1.5h.75V10.5a.75.75 0 01.75-.75z" /></svg>',
                    'magnifying-glass' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 100 13.5 6.75 6.75 0 000-13.5zM2.25 10.5a8.25 8.25 0 1114.59 5.28l4.69 4.69a.75.75 0 11-1.06 1.06l-4.69-4.69A8.25 8.25 0 012.25 10.5z" clip-rule="evenodd" /></svg>',
                    'map-pin' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12.963 2.286a.75.75 0 00-1.071 1.052A11.218 11.218 0 0112 11.25c0 1.488-.28 2.91-.806 4.223a.75.75 0 001.07 1.052 12.71 12.71 0 00.806-4.223c0-3.32-1.34-6.32-3.537-8.514z" clip-rule="evenodd" /><path d="M9.75 9.75a.75.75 0 000 1.5h4.5a.75.75 0 000-1.5h-4.5z" /></svg>',
                    'map' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75z" /><path fill-rule="evenodd" d="M14.25 3.75c1.43 0 2.5 1.07 2.5 2.5v11.25l-2.5-1.25-2.5 1.25-2.5-1.25-2.5 1.25V6.25c0-1.43 1.07-2.5 2.5-2.5h7.5zM12 6a.75.75 0 01.75.75v3.75a.75.75 0 01-1.5 0V6.75A.75.75 0 0112 6z" clip-rule="evenodd" /></svg>',
                    'microphone' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-2.485 0-4.5 2.015-4.5 4.5v6a4.5 4.5 0 009 0v-6a4.5 4.5 0 00-4.5-4.5zM8.25 7.5a.75.75 0 01.75-.75h6a.75.75 0 01.75.75v5.25a.75.75 0 01-.75.75h-6a.75.75 0 01-.75-.75V7.5z" clip-rule="evenodd" /></svg>',
                    'minus-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm-1.72 6.97a.75.75 0 00-1.06 1.06L10.94 12l-1.72 1.72a.75.75 0 101.06 1.06L12 13.06l1.72 1.72a.75.75 0 101.06-1.06L13.06 12l1.72-1.72a.75.75 0 10-1.06-1.06L12 10.94l-1.72-1.72z" clip-rule="evenodd" /></svg>',
                    'minus-small' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.25 12a.75.75 0 01.75-.75h12a.75.75 0 010 1.5H6a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'minus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3.75 12a.75.75 0 01.75-.75h15a.75.75 0 010 1.5h-15a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'moon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 6a.75.75 0 000 1.5h1.5a.75.75 0 000-1.5H7.5zM15 6a.75.75 0 000 1.5h1.5a.75.75 0 000-1.5H15z" clip-rule="evenodd" /><path d="M2.25 12a.75.75 0 01.75-.75h2.25a.75.75 0 010 1.5H3a.75.75 0 01-.75-.75zM18.75 12a.75.75 0 01.75-.75h2.25a.75.75 0 010 1.5H19.5a.75.75 0 01-.75-.75zM12 18a.75.75 0 01-.75.75v2.25a.75.75 0 011.5 0V18.75a.75.75 0 01-.75-.75zM7.5 18a.75.75 0 000-1.5H6a.75.75 0 000 1.5h1.5zM15 18a.75.75 0 000-1.5h-1.5a.75.75 0 000 1.5H15z" /></svg>',
                    'musical-note' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12 6a1.5 1.5 0 100 3 1.5 1.5 0 000-3zm-1.5 4.5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    
                    // N
                    'newspaper' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 3a.75.75 0 01.75-.75h16.5a.75.75 0 01.75.75v16.5a.75.75 0 01-.75.75H3.75a.75.75 0 01-.75-.75V3zm3.75 3a.75.75 0 01.75-.75h9a.75.75 0 010 1.5h-9a.75.75 0 01-.75-.75zM6.75 9a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75zM6.75 12a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75zM6.75 15a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75zm6-6a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75zM12.75 12a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75zM12.75 15a.75.75 0 01.75-.75h3a.75.75 0 010 1.5h-3a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    
                    // P
                    'paint-brush' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12 8.25a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75zM12 10.5a.75.75 0 01.75.75v3.75a.75.75 0 01-1.5 0V11.25a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'paper-airplane' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 18.75a.75.75 0 01-.75-.75V6.75L6.75 12l5.25 6.75z" /><path fill-rule="evenodd" d="M12 2.25c5.385 0 9.75 4.365 9.75 9.75s-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12 6.615 2.25 12 2.25zM12 3.75A8.25 8.25 0 1012 20.25 8.25 8.25 0 0012 3.75z" clip-rule="evenodd" /></svg>',
                    'paper-clip' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12.525 3.336a.75.75 0 00-1.05 1.05l5.25 5.25a.75.75 0 001.05-1.05l-5.25-5.25z" clip-rule="evenodd" /><path d="M12 6a.75.75 0 01.75.75v10.5a.75.75 0 01-1.5 0V6.75A.75.75 0 0112 6z" /><path fill-rule="evenodd" d="M3.75 9.75a.75.75 0 01.75-.75h4.5a.75.75 0 010 1.5h-4.5a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'pause' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M6.75 5.25a.75.75 0 01.75-.75H9a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75H7.5a.75.75 0 01-.75-.75V5.25zm7.5 0a.75.75 0 01.75-.75H16.5a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75h-1.5a.75.75 0 01-.75-.75V5.25z" clip-rule="evenodd" /></svg>',
                    'pencil-square' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 1.5a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V2.25a.75.75 0 01.75-.75z" /><path fill-rule="evenodd" d="M15.75 3.75a.75.75 0 01.75.75v15a.75.75 0 01-1.5 0V4.5a.75.75 0 01.75-.75zM12 3.75a.75.75 0 01.75.75v15a.75.75 0 01-1.5 0V4.5a.75.75 0 01.75-.75zM8.25 3.75a.75.75 0 01.75.75v15a.75.75 0 01-1.5 0V4.5a.75.75 0 01.75-.75z" clip-rule="evenodd" /><path d="M4.5 9.75a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5H5.25a.75.75 0 01-.75-.75zM18 9.75a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5H18.75a.75.75 0 01-.75-.75z" /></svg>',
                    'pencil' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12.963 2.286a.75.75 0 00-1.071 1.052A11.218 11.218 0 0112 11.25c0 1.488-.28 2.91-.806 4.223a.75.75 0 001.07 1.052 12.71 12.71 0 00.806-4.223c0-3.32-1.34-6.32-3.537-8.514z" clip-rule="evenodd" /><path d="M12 15a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75z" /></svg>',
                    'phone' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M1.5 4.5a3 3 0 013-3h1.372c.86 0 1.61.586 1.819 1.42l1.105 4.423a1.875 1.875 0 01-.694 2.255l-1.293.97c-.135.101-.164.292-.086.431l.086.143a4.5 4.5 0 006.42 6.42l.143.086c.14.078.33.049.431-.086l.97-1.293a1.875 1.875 0 012.255-.694l4.423 1.105c.834.209 1.42.959 1.42 1.82V19.5a3 3 0 01-3 3h-1.372c-5.468 0-10.354-4.524-10.743-10.024L1.5 9.25v-4.75z" clip-rule="evenodd" /></svg>',
                    'photo' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M1.5 6a2.25 2.25 0 012.25-2.25h16.5A2.25 2.25 0 0122.5 6v12a2.25 2.25 0 01-2.25 2.25H3.75A2.25 2.25 0 011.5 18V6zM3 16.06V18c0 .414.336.75.75.75h16.5a.75.75 0 00.75-.75v-1.94l-2.69-2.689a1.5 1.5 0 00-2.12 0l-.88.879.97.97a.75.75 0 11-1.06 1.06l-5.16-5.159a1.5 1.5 0 00-2.12 0L3 16.061z" clip-rule="evenodd" /></svg>',
                    'play' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M4.5 5.653c0-1.426 1.529-2.33 2.779-1.643l11.54 6.648c1.295.742 1.295 2.545 0 3.286L7.279 20.99c-1.25.72-2.779-.217-2.779-1.643V5.653z" clip-rule="evenodd" /></svg>',
                    'plus-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12.75 9a.75.75 0 00-1.5 0v2.25H9a.75.75 0 000 1.5h2.25V15a.75.75 0 001.5 0v-2.25H15a.75.75 0 000-1.5h-2.25V9z" clip-rule="evenodd" /></svg>',
                    'plus-small' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 6.75a.75.75 0 01.75.75v3.75h3.75a.75.75 0 010 1.5h-3.75v3.75a.75.75 0 01-1.5 0v-3.75H8.25a.75.75 0 010-1.5h3.75V7.5a.75.75 0 01.75-.75z" /></svg>',
                    'plus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 3.75a.75.75 0 01.75.75v6.75h6.75a.75.75 0 010 1.5h-6.75v6.75a.75.75 0 01-1.5 0v-6.75H4.5a.75.75 0 010-1.5h6.75V4.5a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'power' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12 6a.75.75 0 01.75.75v3.75a.75.75 0 01-1.5 0V6.75A.75.75 0 0112 6z" clip-rule="evenodd" /></svg>',
                    'printer' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 3a.75.75 0 01.75-.75h16.5a.75.75 0 01.75.75v16.5a.75.75 0 01-.75.75H3.75a.75.75 0 01-.75-.75V3zM6 6a.75.75 0 01.75-.75h10.5a.75.75 0 01.75.75v3.75a.75.75 0 01-.75.75H6.75a.75.75 0 01-.75-.75V6zM6 12a.75.75 0 01.75-.75h10.5a.75.75 0 01.75.75v3.75a.75.75 0 01-.75.75H6.75a.75.75 0 01-.75-.75V12z" clip-rule="evenodd" /></svg>',
                    
                    // Q
                    'question-mark-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zM12 8.25a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75zM12 10.5a.75.75 0 01.75.75v3.75a.75.75 0 01-1.5 0V11.25a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    
                    // R
                    'receipt-percent' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3.75 3A.75.75 0 014.5 2.25h15a.75.75 0 01.75.75v18a.75.75 0 01-1.5 0v-1.5H5.25v1.5a.75.75 0 01-1.5 0V3zM9 9a.75.75 0 01-.75-.75v-.01a.75.75 0 011.5 0v.01a.75.75 0 01-.75.75zm3 0a.75.75 0 01-.75-.75v-.01a.75.75 0 011.5 0v.01a.75.75 0 01-.75.75zm3 0a.75.75 0 01-.75-.75v-.01a.75.75 0 011.5 0v.01a.75.75 0 01-.75.75zm-6 3a.75.75 0 01-.75-.75v-.01a.75.75 0 011.5 0v.01a.75.75 0 01-.75.75zm3 0a.75.75 0 01-.75-.75v-.01a.75.75 0 011.5 0v.01a.75.75 0 01-.75.75zm3 0a.75.75 0 01-.75-.75v-.01a.75.75 0 011.5 0v.01a.75.75 0 01-.75.75zm-6 3a.75.75 0 01-.75-.75v-.01a.75.75 0 011.5 0v.01a.75.75 0 01-.75.75zm3 0a.75.75 0 01-.75-.75v-.01a.75.75 0 011.5 0v.01a.75.75 0 01-.75.75zm3 0a.75.75 0 01-.75-.75v-.01a.75.75 0 011.5 0v.01a.75.75 0 01-.75.75z" clip-rule="evenodd" /></svg>',
                    'receipt-refund' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3.75 3A.75.75 0 014.5 2.25h15a.75.75 0 01.75.75v18a.75.75 0 01-1.5 0v-1.5H5.25v1.5a.75.75 0 01-1.5 0V3zM9.53 7.47a.75.75 0 010 1.06l-1.5 1.5a.75.75 0 01-1.06 0l-1.5-1.5a.75.75 0 011.06-1.06L7.5 7.94l.97-.97a.75.75 0 011.06 0zm4.5 0a.75.75 0 010 1.06l-1.5 1.5a.75.75 0 01-1.06 0l-1.5-1.5a.75.75 0 011.06-1.06L12 7.94l.97-.97a.75.75 0 011.06 0zm4.5 0a.75.75 0 010 1.06l-1.5 1.5a.75.75 0 01-1.06 0l-1.5-1.5a.75.75 0 011.06-1.06l.97.97 1.03-1.03a.75.75 0 011.06 0z" clip-rule="evenodd" /></svg>',
                    'rectangle-group' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3.75 3A.75.75 0 014.5 2.25h4.5a.75.75 0 010 1.5H5.25v16.5h3.75a.75.75 0 010 1.5H4.5a.75.75 0 01-.75-.75V3z" clip-rule="evenodd" /><path d="M12.75 3a.75.75 0 01.75-.75h4.5a.75.75 0 01.75.75v18a.75.75 0 01-.75.75h-4.5a.75.75 0 010-1.5h3.75V3.75h-3.75a.75.75 0 01-.75-.75z" /></svg>',
                    'rectangle-stack' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 4.5a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 4.5zM3.75 8.25a.75.75 0 01.75-.75h15a.75.75 0 010 1.5h-15a.75.75 0 01-.75-.75z" clip-rule="evenodd" /><path d="M4.5 12a.75.75 0 01.75-.75h13.5a.75.75 0 010 1.5H5.25a.75.75 0 01-.75-.75z" /></svg>',
                    'rocket-launch' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12 6a.75.75 0 01.75.75v3.75a.75.75 0 01-1.5 0V6.75A.75.75 0 0112 6zM12 12a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    
                    // S (continued)
                    'signal' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M3.75 12a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75v9a.75.75 0 01-.75.75H4.5a.75.75 0 01-.75-.75v-9zM9 8.25a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75v12.75a.75.75 0 01-.75.75h-1.5a.75.75 0 01-.75-.75V8.25zM14.25 3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75v17.25a.75.75 0 01-.75.75h-1.5a.75.75 0 01-.75-.75V3.75z" /></svg>',
                    'sparkles' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M9.315 7.584C10.51 6.533 11.5 6 12 6c.5 0 1.49.533 2.685 1.584l.115.096a.75.75 0 010 1.14l-4.5 3.75a.75.75 0 01-1.127-.08l-4.5-5.25a.75.75 0 011.08-.992l.08.09z" clip-rule="evenodd" /><path fill-rule="evenodd" d="M12.555 18.354a.75.75 0 010-1.14l4.5-3.75a.75.75 0 11.992 1.08l-.09.08c-1.194 1.05-2.185 1.583-2.685 1.583-.5 0-1.49-.533-2.685-1.584l-.115-.096z" clip-rule="evenodd" /></svg>',
                    'speaker-wave' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M13.5 4.06c0-1.336-1.616-2.005-2.56-1.06l-4.5 4.5H4.508c-1.141 0-2.318.664-2.66 1.905A9.76 9.76 0 001.5 12c0 .898.121 1.768.348 2.595.341 1.24 1.518 1.905 2.66 1.905H6.44l4.5 4.5c.945.945 2.561.276 2.561-1.06V4.06zM18.584 5.106a.75.75 0 011.06 0c3.808 3.807 3.808 9.98 0 13.788a.75.75 0 11-1.06-1.06 8.25 8.25 0 000-11.668.75.75 0 010-1.06z" /><path d="M15.932 7.757a.75.75 0 011.061 0 6 6 0 010 8.486.75.75 0 01-1.06-1.061 4.5 4.5 0 000-6.364.75.75 0 010-1.06z" /></svg>',
                    'speaker-x-mark' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M13.5 4.06c0-1.336-1.616-2.005-2.56-1.06l-4.5 4.5H4.508c-1.141 0-2.318.664-2.66 1.905A9.76 9.76 0 001.5 12c0 .898.121 1.768.348 2.595.341 1.24 1.518 1.905 2.66 1.905H6.44l4.5 4.5c.945.945 2.561.276 2.561-1.06V4.06z" /><path fill-rule="evenodd" d="M16.403 12a.75.75 0 010 1.06l-1.72 1.72a.75.75 0 11-1.06-1.06l1.72-1.72-1.72-1.72a.75.75 0 111.06-1.06l1.72 1.72 1.72-1.72a.75.75 0 111.06 1.06L18.525 12l1.72 1.72a.75.75 0 11-1.06 1.06l-1.72-1.72-1.72 1.72a.75.75 0 11-1.06-1.06l1.72-1.72z" clip-rule="evenodd" /></svg>',
                    'square-2-stack' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3.75 4.5a.75.75 0 01.75-.75h14.25a.75.75 0 01.75.75v14.25a.75.75 0 01-.75.75H4.5a.75.75 0 01-.75-.75V4.5zM3.75 1.5A2.25 2.25 0 001.5 3.75v16.5A2.25 2.25 0 003.75 22.5h16.5A2.25 2.25 0 0022.5 20.25V3.75A2.25 2.25 0 0020.25 1.5H3.75z" clip-rule="evenodd" /></svg>',
                    'square-3-stack-3d' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12.562 1.259a1.5 1.5 0 00-1.124 0L4.53 4.287a1.5 1.5 0 00-.946 1.348v8.682a1.5 1.5 0 00.946 1.348l6.908 3.028a1.5 1.5 0 001.124 0l6.908-3.028a1.5 1.5 0 00.946-1.348V5.635a1.5 1.5 0 00-.946-1.348L12.562 1.26z" /></svg>',
                    'squares-2x2' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 4.5A1.5 1.5 0 014.5 3h6A1.5 1.5 0 0112 4.5v6a1.5 1.5 0 01-1.5 1.5h-6A1.5 1.5 0 013 10.5v-6zM4.5 4.5a.75.75 0 00-.75.75v4.5c0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75v-4.5a.75.75 0 00-.75-.75h-4.5z" clip-rule="evenodd" /><path fill-rule="evenodd" d="M13.5 4.5A1.5 1.5 0 0115 3h6a1.5 1.5 0 011.5 1.5v6a1.5 1.5 0 01-1.5 1.5h-6a1.5 1.5 0 01-1.5-1.5v-6zM15 4.5a.75.75 0 00-.75.75v4.5c0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75v-4.5a.75.75 0 00-.75-.75h-4.5z" clip-rule="evenodd" /><path fill-rule="evenodd" d="M3 15A1.5 1.5 0 014.5 13.5h6A1.5 1.5 0 0112 15v6a1.5 1.5 0 01-1.5 1.5h-6A1.5 1.5 0 013 21v-6zM4.5 15a.75.75 0 00-.75.75v4.5c0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75v-4.5a.75.75 0 00-.75-.75h-4.5z" clip-rule="evenodd" /><path fill-rule="evenodd" d="M13.5 15A1.5 1.5 0 0115 13.5h6a1.5 1.5 0 011.5 1.5v6a1.5 1.5 0 01-1.5 1.5h-6a1.5 1.5 0 01-1.5-1.5v-6zM15 15a.75.75 0 00-.75.75v4.5c0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75v-4.5a.75.75 0 00-.75-.75h-4.5z" clip-rule="evenodd" /></svg>',
                    'squares-plus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M9 3.75a.75.75 0 01.75-.75h4.5a.75.75 0 010 1.5h-4.5A.75.75 0 019 3.75zM9 20.25a.75.75 0 01.75-.75h4.5a.75.75 0 010 1.5h-4.5a.75.75 0 01-.75-.75zM3.75 9a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 013.75 9zM20.25 9a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0120.25 9z" clip-rule="evenodd" /><path d="M12.75 9a.75.75 0 00-1.5 0v6a.75.75 0 001.5 0V9zM9 12.75a.75.75 0 000-1.5H3a.75.75 0 000 1.5h6zM21 12.75a.75.75 0 000-1.5h-6a.75.75 0 000 1.5h6z" /></svg>',
                    'star' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.007 5.404.433c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.433 2.082-5.006z" clip-rule="evenodd" /></svg>',
                    'stop-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm-1.72 6.97a.75.75 0 00-1.06 1.06L10.94 12l-1.72 1.72a.75.75 0 101.06 1.06L12 13.06l1.72 1.72a.75.75 0 101.06-1.06L13.06 12l1.72-1.72a.75.75 0 10-1.06-1.06L12 10.94l-1.72-1.72z" clip-rule="evenodd" /></svg>',
                    'stop' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M4.5 7.5a3 3 0 013-3h9a3 3 0 013 3v9a3 3 0 01-3 3h-9a3 3 0 01-3-3v-9z" clip-rule="evenodd" /></svg>',
                    'save' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M5 3a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V7l-5-4H5z"/><path d="M12 17a2 2 0 110-4 2 2 0 010 4z"/><path d="M17 3v4H7V3"/></svg>',
                    'sun' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 6a.75.75 0 000 1.5h1.5a.75.75 0 000-1.5H7.5zM15 6a.75.75 0 000 1.5h1.5a.75.75 0 000-1.5H15z" /><path fill-rule="evenodd" d="M2.25 12a.75.75 0 01.75-.75h2.25a.75.75 0 010 1.5H3a.75.75 0 01-.75-.75zM18.75 12a.75.75 0 01.75-.75h2.25a.75.75 0 010 1.5H19.5a.75.75 0 01-.75-.75zM12 18a.75.75 0 01-.75.75v2.25a.75.75 0 011.5 0V18.75a.75.75 0 01-.75-.75zM7.5 18a.75.75 0 000-1.5H6a.75.75 0 000 1.5h1.5zM15 18a.75.75 0 000-1.5h-1.5a.75.75 0 000 1.5H15z" clip-rule="evenodd" /></svg>',
                    'swatch' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 6a3 3 0 013-3h12a3 3 0 013 3v12a3 3 0 01-3 3H6a3 3 0 01-3-3V6zm4.5 9a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" clip-rule="evenodd" /><path d="M13.5 15a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" /></svg>',
                    'bars-3-bottom-left' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 6.75A.75.75 0 013.75 6h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 6.75zM3 12a.75.75 0 01.75-.75H12a.75.75 0 010 1.5H3.75A.75.75 0 013 12zm0 5.25a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'spinner' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><circle cx="12" cy="12" r="10"/></svg>',
                    'starlink' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 2l2.5 5.5L20 9l-4 3 1 6L12 16 7 18l1-6-4-3 5.5-1.5L12 2z"/></svg>',
                    'start' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><polygon points="12,2 15,10 23,10 17,14 19,22 12,17 5,22 7,14 1,10 9,10"/></svg>',
                    'step' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><rect x="3" y="3" width="6" height="6"/><rect x="9" y="9" width="6" height="6"/><rect x="15" y="15" width="6" height="6"/></svg>',
                    'sunflower' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><circle cx="12" cy="12" r="3"/><g><circle cx="12" cy="3" r="2"/><circle cx="12" cy="21" r="2"/><circle cx="3" cy="12" r="2"/><circle cx="21" cy="12" r="2"/><circle cx="5" cy="5" r="2"/><circle cx="19" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/></g></svg>',
                    'satellite' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M21 3l-6 6-4-4-6 6 4 4-6 6 2 2 6-6 4 4 6-6-4-4 6-6z"/></svg>',
                    'sail' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M4 20h2L12 4v16h-8zM14 6l6 6-6 6V6z"/></svg>',
                    'search-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>',
                    'search' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M10 4a6 6 0 100 12 6 6 0 000-12zM21 21l-4.35-4.35"/></svg>',
                    'server' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><rect x="2" y="3" width="20" height="6" rx="2"/><rect x="2" y="11" width="20" height="6" rx="2"/><rect x="2" y="19" width="20" height="2" rx="1"/></svg>',
                    'share' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M4 12v7a1 1 0 001 1h14a1 1 0 001-1v-7"/><path d="M12 3v12"/><path d="M7 8l5-5 5 5"/></svg>',
                    'shield-check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 2l8 4v6c0 5-3.6 9.7-8 10-4.4-.3-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/></svg>',
                    'shield-x' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 2l8 4v6c0 5-3.6 9.7-8 10-4.4-.3-8-5-8-10V6l8-4z"/><path d="M9 9l6 6M15 9l-6 6"/></svg>',
                    'shuffle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M3 5h4l6 8 6-8h2"/><path d="M3 19h4l6-8 6 8h2"/></svg>',
                    'skull' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 2c4 0 8 3 8 7 0 3-2 5-2 5v3a3 3 0 01-3 3H9a3 3 0 01-3-3v-3s-2-2-2-5c0-4 4-7 8-7z"/><circle cx="9" cy="11" r="1.2"/><circle cx="15" cy="11" r="1.2"/></svg>',
                    'sd-card' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M6 2h7l5 5v13a1 1 0 01-1 1H6a1 1 0 01-1-1V3a1 1 0 011-1z"/><rect x="8" y="10" width="2" height="4"/><rect x="12" y="10" width="2" height="4"/></svg>',
                    'spark' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 2l1.8 4.5L18 8l-4 2 1 5-5-3-5 3 1-5-4-2 4.2-1.5L12 2z"/></svg>',
                    'scissors' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><circle cx="6" cy="7" r="3"/><circle cx="6" cy="17" r="3"/><path d="M8 9l12 6M8 15l12-6"/></svg>',
                    'speech-bubble' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M21 11.5A8.5 8.5 0 1112.5 3 8.5 8.5 0 0121 11.5z"/><path d="M8 21l3-4h6"/></svg>',
                    'sparkle-star' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 3l1.5 4.5L18 9l-4.5 2L12 15l-1.5-4.5L6 9l4.5-1.5L12 3z"/><path d="M4 4l1 1M20 4l1 1M4 20l1-1M20 20l1-1"/></svg>',

                    // T
                    'table-cells' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12.75 3a.75.75 0 00-1.5 0v1.5a.75.75 0 001.5 0V3zM8.25 3a.75.75 0 00-1.5 0v1.5a.75.75 0 001.5 0V3zM17.25 3a.75.75 0 00-1.5 0v1.5a.75.75 0 001.5 0V3z" /><path fill-rule="evenodd" d="M4.5 5.25a3 3 0 00-3 3v10.5a3 3 0 003 3h15a3 3 0 003-3V8.25a3 3 0 00-3-3h-15zm14.25 3a.75.75 0 000-1.5h-13.5a.75.75 0 000 1.5h13.5z" clip-rule="evenodd" /></svg>',
                    'tag' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M11.983 2.502c.76-1.332 2.47-1.332 3.23 0l6.25 10.938c.76 1.332-.19 3.06-1.615 3.06H3.353c-1.426 0-2.375-1.728-1.615-3.06l6.25-10.937zM12 14.25a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75zm0-4.5a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V10.5a.75.75 0 01.75-.75z" clip-rule="evenodd" /></svg>',
                    'ticket' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M1.5 6.375c0-1.036.84-1.875 1.875-1.875h17.25c1.035 0 1.875.84 1.875 1.875v3.125a.75.75 0 01-1.5 0V6.375a.375.375 0 00-.375-.375H3.375a.375.375 0 00-.375.375v11.25c0 .207.168.375.375.375h17.25a.375.375 0 00.375-.375v-3.125a.75.75 0 011.5 0v3.125c0 1.035-.84 1.875-1.875 1.875H3.375A1.875 1.875 0 011.5 17.625V6.375z" clip-rule="evenodd" /><path d="M10.875 12a.75.75 0 00-1.5 0v.01a.75.75 0 001.5 0V12zm-3 0a.75.75 0 00-1.5 0v.01a.75.75 0 001.5 0V12zm6 0a.75.75 0 00-1.5 0v.01a.75.75 0 001.5 0V12z" /></svg>',
                    'trash' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M16.5 4.478v.227a48.816 48.816 0 013.878.512.75.75 0 11-.256 1.478l-.209-.035-1.005 13.07a3 3 0 01-2.991 2.77H8.084a3 3 0 01-2.991-2.77L4.087 6.66l-.209.035a.75.75 0 01-.256-1.478A48.567 48.567 0 017.5 4.705v-.227c0-1.564 1.213-2.9 2.816-2.9h1.368c1.603 0 2.816 1.336 2.816 2.9z" clip-rule="evenodd" /></svg>',
                    'trophy' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M15.75 2.25c.394 0 .783.042 1.163.122a.75.75 0 01.537.896l-.82 4.1a2.25 2.25 0 01-2.134 1.882H9.497a2.25 2.25 0 01-2.134-1.882l-.82-4.1a.75.75 0 01.537-.896 9.17 9.17 0 011.163-.122h6.253zM4.5 9.75a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75zM18.75 9.75a.75.75 0 01.75.75v.01a.75.75 0 01-1.5 0v-.01a.75.75 0 01.75-.75z" clip-rule="evenodd" /><path d="M6 12a.75.75 0 01.75-.75h10.5a.75.75 0 010 1.5H6.75A.75.75 0 016 12zM10.5 14.25a.75.75 0 01.75.75v3a.75.75 0 01-1.5 0v-3a.75.75 0 01.75-.75z" /></svg>',
                    'truck' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12.963 2.286a.75.75 0 00-1.071 1.052A11.218 11.218 0 0112 11.25c0 1.488-.28 2.91-.806 4.223a.75.75 0 001.07 1.052 12.71 12.71 0 00.806-4.223c0-3.32-1.34-6.32-3.537-8.514z" clip-rule="evenodd" /><path d="M15 15a3 3 0 100-6 3 3 0 000 6z" /></svg>',
                    'thumb-up' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><!--!Font Awesome Free v7.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path d="M144 224C161.7 224 176 238.3 176 256L176 512C176 529.7 161.7 544 144 544L96 544C78.3 544 64 529.7 64 512L64 256C64 238.3 78.3 224 96 224L144 224zM334.6 80C361.9 80 384 102.1 384 129.4L384 133.6C384 140.4 382.7 147.2 380.2 153.5L352 224L512 224C538.5 224 560 245.5 560 272C560 291.7 548.1 308.6 531.1 316C548.1 323.4 560 340.3 560 360C560 383.4 543.2 402.9 521 407.1C525.4 414.4 528 422.9 528 432C528 454.2 513 472.8 492.6 478.3C494.8 483.8 496 489.8 496 496C496 522.5 474.5 544 448 544L360.1 544C323.8 544 288.5 531.6 260.2 508.9L248 499.2C232.8 487.1 224 468.7 224 449.2L224 262.6C224 247.7 227.5 233 234.1 219.7L290.3 107.3C298.7 90.6 315.8 80 334.6 80z"/></svg>',

                    // U
                    'user-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M18.685 19.097A9.723 9.723 0 0021.75 12c0-5.385-4.365-9.75-9.75-9.75S2.25 6.615 2.25 12a9.723 9.723 0 003.065 7.097A9.716 9.716 0 0012 21.75a9.716 9.716 0 006.685-2.653zM15 9.75a3 3 0 11-6 0 3 3 0 016 0z" clip-rule="evenodd" /></svg>',
                    'user-group' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><!--!Font Awesome Free v7.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path d="M96 192C96 130.1 146.1 80 208 80C269.9 80 320 130.1 320 192C320 253.9 269.9 304 208 304C146.1 304 96 253.9 96 192zM32 528C32 430.8 110.8 352 208 352C305.2 352 384 430.8 384 528L384 534C384 557.2 365.2 576 342 576L74 576C50.8 576 32 557.2 32 534L32 528zM464 128C517 128 560 171 560 224C560 277 517 320 464 320C411 320 368 277 368 224C368 171 411 128 464 128zM464 368C543.5 368 608 432.5 608 512L608 534.4C608 557.4 589.4 576 566.4 576L421.6 576C428.2 563.5 432 549.2 432 534L432 528C432 476.5 414.6 429.1 385.5 391.3C408.1 376.6 435.1 368 464 368z"/></svg>',
                    'user-minus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M7.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM3.751 20.105a8.25 8.25 0 0116.498 0 .75.75 0 01-.437.695A18.683 18.683 0 0112 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 01-.437-.695z" /><path d="M16.5 12.75a.75.75 0 000-1.5h-3a.75.75 0 000 1.5h3z" /></svg>',
                    'user-plus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M7.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM3.751 20.105a8.25 8.25 0 0116.498 0 .75.75 0 01-.437.695A18.683 18.683 0 0112 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 01-.437-.695z" /><path d="M14.25 12.75a.75.75 0 000-1.5h-.75v-.75a.75.75 0 00-1.5 0v.75h-.75a.75.75 0 000 1.5h.75v.75a.75.75 0 001.5 0v-.75h.75z" /></svg>',
                    'users' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M10.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM5.25 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0z" /><path fill-rule="evenodd" d="M1.5 15.375a3 3 0 013-3h15a3 3 0 013 3v.375a3 3 0 01-3 3H4.5a3 3 0 01-3-3v-.375z" clip-rule="evenodd" /></svg>',

                    // V
                    'variable' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm-1.72 6.97a.75.75 0 00-1.06 1.06L10.94 12l-1.72 1.72a.75.75 0 101.06 1.06L12 13.06l1.72 1.72a.75.75 0 101.06-1.06L13.06 12l1.72-1.72a.75.75 0 10-1.06-1.06L12 10.94l-1.72-1.72z" clip-rule="evenodd" /></svg>',
                    'video-camera-slash' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M3.53 2.47a.75.75 0 00-1.06 1.06l18 18a.75.75 0 101.06-1.06l-18-18z" /><path fill-rule="evenodd" d="M4.5 5.653c0-1.426 1.529-2.33 2.779-1.643l11.54 6.648c1.295.742 1.295 2.545 0 3.286L7.279 20.99c-1.25.72-2.779-.217-2.779-1.643V5.653z" clip-rule="evenodd" /></svg>',
                    'video-camera' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M4.5 5.653c0-1.426 1.529-2.33 2.779-1.643l11.54 6.648c1.295.742 1.295 2.545 0 3.286L7.279 20.99c-1.25.72-2.779-.217-2.779-1.643V5.653z" clip-rule="evenodd" /></svg>',
                    'view-columns' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M8.25 3a.75.75 0 000 1.5h7.5a.75.75 0 000-1.5h-7.5z" clip-rule="evenodd" /><path d="M12 21a.75.75 0 000-1.5H8.25a.75.75 0 000 1.5H12zM3 8.25a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 8.25z" /></svg>',
                    'viewfinder-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM9 12a3 3 0 116 0 3 3 0 01-6 0z" clip-rule="evenodd" /><path d="M12 7.5a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0V8.25a.75.75 0 01.75-.75zM12 14.25a.75.75 0 01.75.75v1.5a.75.75 0 01-1.5 0v-1.5a.75.75 0 01.75-.75zM7.5 12a.75.75 0 00-1.5 0h1.5zm9 0a.75.75 0 00-1.5 0h1.5z" /></svg>',

                    // W
                    'wallet' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M3.75 3A1.75 1.75 0 002 4.75v14.5A1.75 1.75 0 003.75 21h16.5A1.75 1.75 0 0022 19.25V4.75A1.75 1.75 0 0020.25 3H3.75zM8.25 6a.75.75 0 01.75.75h6a.75.75 0 010 1.5H9a.75.75 0 01-.75-.75V6z" /><path d="M18.75 9.75a.75.75 0 00-1.5 0v3a.75.75 0 001.5 0v-3z" /></svg>',
                    'wifi' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12.75 16.5a.75.75 0 00-1.5 0v.01a.75.75 0 001.5 0v-.01z" /><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12 1.5A10.5 10.5 0 1012 22.5 10.5 10.5 0 0012 1.5z" clip-rule="evenodd" /></svg>',
                    'window' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 3a.75.75 0 01.75-.75h16.5a.75.75 0 01.75.75v16.5a.75.75 0 01-.75.75H3.75a.75.75 0 01-.75-.75V3zm4.5 1.5a.75.75 0 00-1.5 0v6.75a.75.75 0 001.5 0V4.5zM12 4.5a.75.75 0 01.75.75v6.75a.75.75 0 01-1.5 0V5.25A.75.75 0 0112 4.5zm3.75 0a.75.75 0 00-1.5 0v6.75a.75.75 0 001.5 0V4.5zM7.5 12.75a.75.75 0 01.75-.75h8.25a.75.75 0 010 1.5H8.25a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'wrench-screwdriver' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 6.75a5.25 5.25 0 015.25 5.25c0 1.282-.463 2.467-1.246 3.417a.75.75 0 01-1.209-.922A3.75 3.75 0 0015.75 12a3.75 3.75 0 00-3.75-3.75H8.25a.75.75 0 010-1.5h3.75z" clip-rule="evenodd" /><path d="M3.75 3A1.75 1.75 0 002 4.75v14.5A1.75 1.75 0 003.75 21h16.5A1.75 1.75 0 0022 19.25V4.75A1.75 1.75 0 0020.25 3H3.75z" /></svg>',
                    'wrench' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 6.75a5.25 5.25 0 015.25 5.25c0 1.282-.463 2.467-1.246 3.417a.75.75 0 01-1.209-.922A3.75 3.75 0 0015.75 12a3.75 3.75 0 00-3.75-3.75H8.25a.75.75 0 010-1.5h3.75z" clip-rule="evenodd" /></svg>',

                    // X
                    'x-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm-1.72 6.97a.75.75 0 00-1.06 1.06L10.94 12l-1.72 1.72a.75.75 0 101.06 1.06L12 13.06l1.72 1.72a.75.75 0 101.06-1.06L13.06 12l1.72-1.72a.75.75 0 10-1.06-1.06L12 10.94l-1.72-1.72z" clip-rule="evenodd" /></svg>',
                    'x-mark' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.47 5.47a.75.75 0 011.06 0L12 10.94l5.47-5.47a.75.75 0 111.06 1.06L13.06 12l5.47 5.47a.75.75 0 11-1.06 1.06L12 13.06l-5.47 5.47a.75.75 0 01-1.06-1.06L10.94 12 5.47 6.53a.75.75 0 010-1.06z" clip-rule="evenodd" /></svg>',

                    // Z
                    'zoom-in' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 100 13.5 6.75 6.75 0 000-13.5zM2.25 10.5a8.25 8.25 0 1114.59 5.28l4.69 4.69a.75.75 0 11-1.06 1.06l-4.69-4.69A8.25 8.25 0 012.25 10.5z" clip-rule="evenodd" /><path d="M11.25 9.75a.75.75 0 01.75.75v.75h.75a.75.75 0 010 1.5h-.75v.75a.75.75 0 01-1.5 0v-.75h-.75a.75.75 0 010-1.5h.75V10.5a.75.75 0 01.75-.75z" /></svg>',
                    'zoom-out' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 100 13.5 6.75 6.75 0 000-13.5zM2.25 10.5a8.25 8.25 0 1114.59 5.28l4.69 4.69a.75.75 0 11-1.06 1.06l-4.69-4.69A8.25 8.25 0 012.25 10.5z" clip-rule="evenodd" /><path d="M9.75 10.5a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5a.75.75 0 01-.75-.75z" /></svg>',

                    // Additional Common UI Icons
                    'user' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M7.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM3.751 20.105a8.25 8.25 0 0116.498 0 .75.75 0 01-.437.695A18.683 18.683 0 0112 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 01-.437-.695z" clip-rule="evenodd" /></svg>',
                    'shopping-cart' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M2.25 2.25a.75.75 0 000 1.5h1.386c.17 0 .315.113.367.27l2.89 8.67a.75.75 0 00.712.51h10.85a.75.75 0 00.712-.51l2.89-8.67a.75.75 0 00-.367-.27H6.106L5.606 2.25H2.25zm4.217 4.5h11.066l-2.0 6H8.467l-2.0-6zM7.5 21a1.5 1.5 0 110-3 1.5 1.5 0 010 3zm9 0a1.5 1.5 0 110-3 1.5 1.5 0 010 3z" /></svg>',
                    'external-link' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M15.75 2.25a.75.75 0 01.75.75v5.25a.75.75 0 01-1.5 0V4.81L8.03 12l6.97 7.19v-3.94a.75.75 0 011.5 0v5.25a.75.75 0 01-.75.75h-9a.75.75 0 01-.75-.75V3a.75.75 0 01.75-.75h9z" clip-rule="evenodd" /></svg>',
                    'settings' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M11.078 2.25c-.917 0-1.699.663-1.85 1.567L9.05 4.889c-.02.12-.115.26-.297.348a7.493 7.493 0 00-.986.57c-.166.115-.334.11-.414.03L6.463 5.01a1.875 1.875 0 00-2.652 0l-.746.747a1.875 1.875 0 000 2.652l.827.827c.08.08.085.248-.03.414a7.496 7.493 0 00-.57.986c-.088.182-.228.277-.348.297L1.517 11.23a1.875 1.875 0 00-1.567 1.85v1.059c0 .917.663 1.699 1.567 1.85l1.059.176c.12.02.26.115.348.297.16.333.35.663.57.986.115.166.11.334.03.414l-.827.827a1.875 1.875 0 000 2.652l.747.747a1.875 1.875 0 002.652 0l.827-.827c.08-.08.248-.085.414.03.333.16.663.35.986.57.166.115.277.228.297.348l.176 1.059c.151.904.933 1.567 1.85 1.567h1.059c.917 0 1.699-.663 1.85-1.567l.176-1.059c.02-.12.115-.26.297-.348a7.493 7.493 0 00.986-.57c.166-.115.334-.11.414-.03l.827.827a1.875 1.875 0 002.652 0l.747-.747a1.875 1.875 0 000-2.652l-.827-.827c-.08-.08-.085-.248.03-.414a7.493 7.493 0 00.57-.986c.088-.182.228-.277.348-.297l1.059-.176a1.875 1.875 0 001.567-1.85V12.23c0-.917-.663-1.699-1.567-1.85l-1.059-.176c-.12-.02-.26-.115-.348-.297a7.493 7.493 0 00-.57-.986c-.115-.166-.11-.334-.03-.414l.827-.827a1.875 1.875 0 000-2.652l-.747-.747a1.875 1.875 0 00-2.652 0l-.827.827c-.08.08-.248.085-.414-.03a7.493 7.493 0 00-.986-.57c-.166-.115-.277-.228-.297-.348l-.176-1.059a1.875 1.875 0 00-1.85-1.567h-1.059zM12 15.75a3.75 3.75 0 100-7.5 3.75 3.75 0 000 7.5z" clip-rule="evenodd" /></svg>',
                    'menu' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M3 6.75A.75.75 0 013.75 6h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 6.75zM3 12a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75A.75.75 0 013 12zm0 5.25a.75.75 0 01.75-.75h16.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75z" clip-rule="evenodd" /></svg>',
                    'bell' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M5.25 9a6.75 6.75 0 0113.5 0v.75c0 2.123.8 4.057 2.118 5.52a.75.75 0 01-.297 1.206c-1.544.57-3.16.99-4.831 1.243a3.75 3.75 0 11-7.48 0 24.585 24.585 0 01-4.831-1.244.75.75 0 01-.298-1.205A8.217 8.217 0 005.25 9.75V9zm4.502 8.9a2.25 2.25 0 104.496 0 25.057 25.057 0 01-4.496 0z" clip-rule="evenodd" /></svg>',
                    'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M6.75 2.25A.75.75 0 017.5 3v1.5h9V3a.75.75 0 011.5 0v1.5h.75a3 3 0 013 3v11.25a3 3 0 01-3 3H5.25a3 3 0 01-3-3V7.5a3 3 0 013-3h.75V3a.75.75 0 01.75-.75zm13.5 9H3.75v8.25a1.5 1.5 0 001.5 1.5h13.5a1.5 1.5 0 001.5-1.5V11.25z" clip-rule="evenodd" /></svg>',
                    'camera' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 9a3.75 3.75 0 100 7.5 3.75 3.75 0 000-7.5z" /><path fill-rule="evenodd" d="M9.344 3.071a2.25 2.25 0 012.247 0L12 3.321l.409-.25a2.25 2.25 0 012.247 0L16.29 4.12h1.46a3 3 0 013 3v11.25a3 3 0 01-3 3H6.25a3 3 0 01-3-3V7.12a3 3 0 013-3h1.46l1.634-1.049zM12 7.5a5.25 5.25 0 100 10.5 5.25 5.25 0 000-10.5z" clip-rule="evenodd" /></svg>',
                    'chat-bubble-left' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.901 48.901 0 01-3.476.383.39.39 0 00-.297.17l-2.755 4.133a.75.75 0 01-1.248 0l-2.755-4.133a.39.39 0 00-.297-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z" clip-rule="evenodd" /></svg>',
                    'heart' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M11.645 20.91l-.007-.003-.022-.012a15.247 15.247 0 01-.383-.218 25.18 25.18 0 01-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0112 5.052 5.5 5.5 0 0116.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 01-4.244 3.17 15.247 15.247 0 01-.383.219l-.022.012-.007.004-.003.001a.752.75 0 01-.704 0l-.003-.001z" /></svg>',
                    'eye' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 15a3 3 0 100-6 3 3 0 000 6z" /><path fill-rule="evenodd" d="M1.323 11.447C2.811 6.976 7.028 3.75 12.001 3.75c4.97 0 9.185 3.223 10.675 7.69.12.362.12.752 0 1.113-1.487 4.471-5.705 7.697-10.677 7.697-4.97 0-9.186-3.223-10.675-7.69a1.762 1.762 0 010-1.113zM17.25 12a5.25 5.25 0 11-10.5 0 5.25 5.25 0 0110.5 0z" clip-rule="evenodd" /></svg>',
                    'lock-closed' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M12 1.5a5.25 5.25 0 00-5.25 5.25v3a3 3 0 00-3 3v6.75a3 3 0 003 3h10.5a3 3 0 003-3v-6.75a3 3 0 00-3-3v-3A5.25 5.25 0 0012 1.5zm-3.75 5.25a3.75 3.75 0 117.5 0v3h-7.5v-3z" clip-rule="evenodd" /></svg>',
                    'lock-open' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M15.75 5.25a5.25 5.25 0 0110.5 0v2.25a.75.75 0 01-1.5 0v-2.25a3.75 3.75 0 10-7.5 0v3H9v-3a5.25 5.25 0 015.25-5.25zM4.5 9a3 3 0 00-3 3v6.75a3 3 0 003 3h10.5a3 3 0 003-3v-6.75a3 3 0 00-3-3H4.5z" clip-rule="evenodd" /></svg>',
                    'map-pin' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M11.54 22.351l.07.04.028.016a.76.76 0 00.723 0l.028-.015.071-.041a16.975 16.975 0 001.144-.742 19.58 19.58 0 002.683-2.282c1.944-1.99 3.963-4.98 3.963-8.827a8.25 8.25 0 00-16.5 0c0 3.846 2.02 6.837 3.963 8.827a19.58 19.58 0 002.682 2.282 16.975 16.975 0 001.145.742zM12 13.5a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" /></svg>',
                    'moon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M9.528 1.718a.75.75 0 01.162.819A8.97 8.97 0 009 6a9 9 0 009 9 8.97 8.97 0 003.463-.69.75.75 0 01.981.98 10.503 10.503 0 01-9.694 6.46c-5.799 0-10.5-4.701-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 01.818.162z" clip-rule="evenodd" /></svg>',
                    'sun' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 12a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM18.894 6.166a.75.75 0 00-1.06-1.06l-1.591 1.59a.75.75 0 101.06 1.061l1.591-1.59zM21.75 12a.75.75 0 01-.75.75h-2.25a.75.75 0 010-1.5H21a.75.75 0 01.75.75zM17.834 18.894a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 10-1.061 1.06l1.59 1.591zM12 18.75a.75.75 0 01.75.75V21a.75.75 0 01-1.5 0v-1.5a.75.75 0 01.75-.75zM6.166 18.894a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 00-1.061 1.06l1.59 1.591zM5.25 12a.75.75 0 01-.75.75H2.25a.75.75 0 010-1.5H4.5a.75.75 0 01.75.75zM6.166 5.106a.75.75 0 010 1.06l-1.591 1.59a.75.75 0 01-1.06-1.06l1.59-1.591a.75.75 0 011.061 0z" /></svg>',
                    'star' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.007 5.404.433c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.433 2.082-5.006z" clip-rule="evenodd" /></svg>',
                    'cog' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path fill-rule="evenodd" d="M11.078 2.25c-.917 0-1.699.663-1.85 1.567L9.05 4.889c-.02.12-.115.26-.297.348a7.493 7.493 0 00-.986.57c-.166.115-.334.11-.414.03L6.463 5.01a1.875 1.875 0 00-2.652 0l-.746.747a1.875 1.875 0 000 2.652l.827.827c.08.08.085.248-.03.414a7.496 7.493 0 00-.57.986c-.088.182-.228.277-.348.297L1.517 11.23a1.875 1.875 0 00-1.567 1.85v1.059c0 .917.663 1.699 1.567 1.85l1.059.176c.12.02.26.115.348.297.16.333.35.663.57.986.115.166.11.334.03.414l-.827.827a1.875 1.875 0 000 2.652l.747.747a1.875 1.875 0 002.652 0l.827-.827c.08-.08.248-.085.414.03.333.16.663.35.986.57.166.115.277.228.297.348l.176 1.059c.151.904.933 1.567 1.85 1.567h1.059c.917 0 1.699-.663 1.85-1.567l.176-1.059c.02-.12.115-.26.297-.348a7.493 7.493 0 00.986-.57c.166-.115.334-.11.414-.03l.827.827a1.875 1.875 0 002.652 0l.747-.747a1.875 1.875 0 000-2.652l-.827-.827c-.08-.08-.085-.248.03-.414a7.493 7.493 0 00.57-.986c.088-.182.228-.277.348-.297l1.059-.176a1.875 1.875 0 001.567-1.85V12.23c0-.917-.663-1.699-1.567-1.85l-1.059-.176c-.12-.02-.26-.115-.348-.297a7.493 7.493 0 00-.57-.986c-.115-.166-.11-.334-.03-.414l.827-.827a1.875 1.875 0 000-2.652l-.747-.747a1.875 1.875 0 00-2.652 0l-.827.827c-.08.08-.248.085-.414-.03a7.493 7.493 0 00-.986-.57c-.166-.115-.277-.228-.297-.348l-.176-1.059a1.875 1.875 0 00-1.85-1.567h-1.059zM12 15.75a3.75 3.75 0 100-7.5 3.75 3.75 0 000 7.5z" clip-rule="evenodd" /></svg>',


                    // Social Media Icons
                    'discord' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19.54 0c1.356 0 2.46 1.104 2.46 2.46v19.08c0 1.356-1.104 2.46-2.46 2.46H4.46C3.104 24 2 22.896 2 21.54V2.46C2 1.104 3.104 0 4.46 0h15.08zM8.02 15.66c.216 0 .396-.18.396-.396v-1.188c0-.216-.18-.396-.396-.396H6.832v1.98h1.188zm5.544-1.98c-1.356 0-2.46-1.104-2.46-2.46s1.104-2.46 2.46-2.46 2.46 1.104 2.46 2.46-1.104 2.46-2.46 2.46zm-5.544 0c-1.356 0-2.46-1.104-2.46-2.46s1.104-2.46 2.46-2.46 2.46 1.104 2.46 2.46-1.104 2.46-2.46 2.46z"/></svg>',
                    'dribbble' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm-2.625 6c-.54 0-.828.419-.938.634l-.068.135a2.25 2.25 0 01-1.33 1.332l-.136.068c-.215.11-.634.398-.634.938v.281c0 .54.419.828.634.938l.135.068a2.25 2.25 0 011.332 1.33l.068.136c.11.215.398.634.938.634h.281c.54 0 .828-.419.938-.634l.068-.135a2.25 2.25 0 011.33-1.332l.136-.068c.215-.11.634-.398.634-.938v-.281c0-.54-.419-.828-.634-.938l-.135-.068a2.25 2.25 0 01-1.332-1.33l-.068-.136c-.11-.215-.398-.634-.938-.634h-.281z" clip-rule="evenodd" /></svg>',
                    'facebook' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M22.675 0H1.325C.593 0 0 .593 0 1.325v21.351C0 23.407.593 24 1.325 24H12.82v-9.294H9.692v-3.622h3.128V8.413c0-3.1 1.893-4.788 4.659-4.788 1.325 0 2.463.099 2.795.143v3.24l-1.918.001c-1.504 0-1.795.715-1.795 1.763v2.313h3.587l-.467 3.622h-3.12V24h6.116c.732 0 1.325-.593 1.325-1.325V1.325C24 .593 23.407 0 22.675 0z"/></svg>',
                    'github' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm-.625 14.625v-2.25H11.25v2.25H9.75v-4.5h1.5v1.5h.125a2.25 2.25 0 012.125-1.125c1.313 0 2.25 1.063 2.25 2.625v3.75h-1.5v-3.375c0-.844-.375-1.5-1.125-1.5s-1.125.656-1.125 1.5v3.375h-1.5z" clip-rule="evenodd" /></svg>',
                    'instagram' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.85s-.011 3.585-.069 4.85c-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07s-3.585-.012-4.85-.07c-3.252-.148-4.771-1.691-4.919-4.919-.058-1.265-.07-1.645-.07-4.85s.011-3.585.07-4.85c.148-3.225 1.664-4.771 4.919-4.919 1.266-.058 1.644-.07 4.85-.07zm0-2.163C8.75 0 8.35.012 7.05.072 2.69.272.273 2.69.073 7.05.012 8.35 0 8.75 0 12s.012 3.65.072 4.95c.2 4.358 2.618 6.777 6.98 6.98C8.35 23.988 8.75 24 12 24s3.65-.012 4.95-.072c4.358-.2 6.777-2.618 6.98-6.98C23.988 15.65 24 15.25 24 12s-.012-3.65-.072-4.95c-.2-4.358-2.618-6.777-6.98-6.98C15.65.012 15.25 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.88 1.44 1.44 0 000-2.88z"/></svg>',
                    'linkedin' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 114.128 0c0 1.14-.931 2.065-2.065 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.225 0z"/></svg>',
                    'pinterest' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12c0 4.91 3.067 9.083 7.373 10.938-.035-.85.03-1.848.267-2.723.238-.867.925-3.003.925-3.003s-.24-.48-.24-1.188c0-1.116.685-1.95 1.536-1.95.722 0 1.065.539 1.065 1.188 0 .722-.457 1.796-.696 2.793-.204.85.426 1.536 1.267 1.536 1.512 0 2.684-1.996 2.684-4.873 0-2.583-1.815-4.482-4.148-4.482-2.827 0-4.482 2.115-4.482 4.316 0 .85.326 1.765.733 2.296.08.102.093.188.067.288-.08.312-.267 1.065-.326 1.267-.04.144-.127.188-.267.102-1.08-.638-1.756-2.618-1.756-4.043 0-3.418 2.457-6.52 7.028-6.52 3.696 0 6.206 2.618 6.206 5.922 0 3.696-2.316 6.52-5.523 6.52-1.08 0-2.115-.539-2.457-1.188l-.539-1.95s-.355 1.41-.426 1.656c-.24.814-.946 1.95-1.396 2.618C9.52 21.464 10.743 22 12 22c5.373 0 10-4.627 10-10S17.373 2 12 2"/></svg>',
                    'reddit' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12c0 4.887 2.923 9.055 7.031 10.938.033-.85-.01-1.848.248-2.723.23-.867.893-3.003.893-3.003s-.225-.45-.225-1.116c0-1.045.64-1.83 1.44-1.83.675 0 .99.504.99 1.116 0 .675-.424 1.683-.649 2.618-.184.795.394 1.44.893 1.44 1.406 0 2.502-1.87 2.502-4.567 0-2.418-1.691-4.195-3.868-4.195-2.64 0-4.195 1.983-4.195 4.043 0 .795.304 1.65.684 2.148.075.094.084.173.064.267-.075.29-.248.99-.304 1.188-.035.134-.117.173-.248.094-1.008-.597-1.637-2.455-1.637-3.784 0-3.195 2.29-6.096 6.556-6.096 3.45 0 5.783 2.455 5.783 5.545 0 3.45-2.164 6.096-5.148 6.096-.99 0-1.983-.504-2.29-1.116l-.504-1.83s-.333 1.32-.394 1.545c-.225.765-.893 1.83-1.29 2.455C9.37 21.41 10.59 22 12 22c5.373 0 10-4.627 10-10S17.373 2 12 2"/></svg>',
                    'telegram' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12c0 6.627-5.373 12-12 12S0 18.627 0 12 5.373 0 12 0s12 5.373 12 12zm-3.37-2.213L18.195 18.23a.75.75 0 01-1.127.35l-4.148-3.08-1.983 1.905c-.33.315-.765.37-1.155.15l.33-3.66 7.49-6.78a.75.75 0 00-.975-1.155l-9.184 5.78-3.645-1.133a.75.75 0 01.37-1.423l15.352-5.74a.75.75 0 01.956.956z"/></svg>',
                    'tiktok' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><!--!Font Awesome Free v7.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2025 Fonticons, Inc.--><path d="M448.5 209.9c-44 .1-87-13.6-122.8-39.2l0 178.7c0 33.1-10.1 65.4-29 92.6s-45.6 48-76.6 59.6-64.8 13.5-96.9 5.3-60.9-25.9-82.7-50.8-35.3-56-39-88.9 2.9-66.1 18.6-95.2 40-52.7 69.6-67.7 62.9-20.5 95.7-16l0 89.9c-15-4.7-31.1-4.6-46 .4s-27.9 14.6-37 27.3-14 28.1-13.9 43.9 5.2 31 14.5 43.7 22.4 22.1 37.4 26.9 31.1 4.8 46-.1 28-14.4 37.2-27.1 14.2-28.1 14.2-43.8l0-349.4 88 0c-.1 7.4 .6 14.9 1.9 22.2 3.1 16.3 9.4 31.9 18.7 45.7s21.3 25.6 35.2 34.6c19.9 13.1 43.2 20.1 67 20.1l0 87.4z"/></svg>',
                    'twitter' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M24 4.557c-.883.392-1.832.656-2.828.775 1.017-.609 1.798-1.574 2.165-2.724-.951.564-2.005.974-3.127 1.195-.897-.957-2.178-1.555-3.594-1.555-3.179 0-5.515 2.966-4.797 6.045-4.091-.205-7.719-2.165-10.148-5.144-1.29 2.213-.669 5.108 1.523 6.574-.806-.026-1.566-.247-2.229-.616v.064c0 2.298 1.634 4.218 3.799 4.658-.69.188-1.426.23-2.168.087.625 1.933 2.443 3.344 4.6 3.385-1.794 1.402-4.062 2.235-6.53 2.235-.424 0-.84-.025-1.249-.074 2.308 1.478 5.051 2.34 8.021 2.34 9.619 0 14.89-7.986 14.89-14.891 0-.226-.005-.452-.015-.676.983-.706 1.833-1.583 2.522-2.62z"/></svg>',
                    'xbox' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm6.75 16.5l-1.5-1.5-3.75 3.75-3.75-3.75-1.5 1.5L12 19.5l6.75-3zM7.5 7.5l1.5-1.5L12 9l3-3 1.5 1.5L12 12l-4.5-4.5z"/></svg>',
                    'twitch' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm6.75 16.5l-1.5-1.5-3.75 3.75-3.75-3.75-1.5 1.5L12 19.5l6.75-3zM7.5 7.5l1.5-1.5L12 9l3-3 1.5 1.5L12 12l-4.5-4.5z"/></svg>',
                    'vimeo' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm6.75 16.5l-1.5-1.5-3.75 3.75-3.75-3.75-1.5 1.5L12 19.5l6.75-3zM7.5 7.5l1.5-1.5L12 9l3-3 1.5 1.5L12 12l-4.5-4.5z"/></svg>',
                    'youtube' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0C.488 3.45.029 5.722 0 12c.029 6.278.488 8.55 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.538 4.385-8.816-.029-6.278-.488-8.55-4.385-8.816zM9.75 15.3V8.7l6.3 3.3-6.3 3.3z"/></svg>',
                    'whatsapp' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zM9.51 7.23c.24-.04.48-.06.72-.06.27 0 .52.02.76.06.24.04.38.2.33.45l-.42 1.63c-.04.16-.18.29-.35.32-1.05.2-1.8.9-2.25 1.81-.04.09-.13.15-.22.15s-.18-.06-.22-.15c-.45-.91-1.2-1.61-2.25-1.81-.17-.03-.31-.16-.35-.32l-.42-1.63c-.05-.25.09-.41.33-.45.24-.04.49-.06.76-.06s.48.02.72.06zm4.98 0c.24-.04.48-.06.72-.06.27 0 .52.02.76.06.24.04.38.2.33.45l-.42 1.63c-.04.16-.18.29-.35.32-1.05.2-1.8.9-2.25 1.81-.04.09-.13.15-.22.15s-.18-.06-.22-.15c-.45-.91-1.2-1.61-2.25-1.81-.17-.03-.31-.16-.35-.32l-.42-1.63c-.05-.25.09-.41.33-.45.24-.04.49-.06.76-.06s.48.02.72.06z"/></svg>',
                    'netflix' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M14.463 24h-4.926L8.435 9.243V24H3.344V0h5.09l1.101 14.757L15.564 0h5.09v24h-5.09v-9.243L14.463 24z"/></svg>',
                    'spotify' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm4.593 17.15c-.173.273-.5.372-.774.2-1.824-1.116-4.11-1.36-6.837-.743-.326.074-.639-.144-.713-.47-.074-.326.144-.639.47-.713 2.94-.675 5.485-.395 7.525.867.273.16.372.486.2.762zm1.02-2.73c-.225.352-.639.462-.99.237-2.072-1.29-5.228-1.664-7.72-0.913-.372.112-.762-.1-.874-.471-.112-.372.1-.762.472-.874 2.827-.84 6.27-.424 8.653 1.05.35.225.462.639.237.99zM12 5.645c4.618 0 8.355 3.736 8.355 8.355 0 1.23-.267 2.4-.747 3.465-.24-.493-.574-.933-.974-1.305-1.92-1.815-4.83-2.31-8.28-1.29-.405.12-.81-.09-1.02-.45-.21-.36.03-.84.45-1.02 3.84-1.14 7.23-.57 9.6 1.5.494.42.914.933 1.246 1.515C20.088 16.575 20.355 15.6 20.355 14c0-4.618-3.737-8.355-8.355-8.355S3.645 9.382 3.645 14c0 3.195 1.785 5.97 4.38 7.35.33.18.705.105.945-.18.24-.285.18-.705-.105-.945-2.22-1.2-3.66-3.48-3.66-6.075 0-3.84 3.105-6.945 6.945-6.945z"/></svg>',
                    'amazon-prime' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zM7.5 18V6h6c3.313 0 6 2.687 6 6s-2.687 6-6 6H7.5zm3-9v6h3c1.656 0 3-1.344 3-3s-1.344-3-3-3h-3z"/></svg>',
                ],
                'fontFamily' => [
                    'sans' => [
                        'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont',
                        '"Segoe UI"', 'Roboto', '"Helvetica Neue"', 'Arial', '"Noto Sans"',
                        'sans-serif', '"Apple Color Emoji"', '"Segoe UI Emoji"', '"Segoe UI Symbol"',
                        '"Noto Color Emoji"',
                    ],
                    'serif' => ['ui-serif', 'Georgia', 'Cambria', '"Times New Roman"', 'Times', 'serif'],
                    'mono' => [
                        'ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas',
                        '"Liberation Mono"', '"Courier New"', 'monospace',
                    ],
                    // Example of adding a custom font (ensure the font is loaded via @font-face or system)
                    'display' => ['"Oswald"', 'sans-serif'], // Example: Oswald font
                    'body' => ['"Open Sans"', 'sans-serif'],   // Example: Open Sans font
                ],
                'maskImage' => [
                    'none' => 'none',
                    'to-t' => 'linear-gradient(to top, black, transparent)',
                    'to-r' => 'linear-gradient(to right, black, transparent)',
                    'to-b' => 'linear-gradient(to bottom, black, transparent)',
                    'to-l' => 'linear-gradient(to left, black, transparent)',
                    'to-tr' => 'linear-gradient(to top right, black, transparent)',
                    'to-br' => 'linear-gradient(to bottom right, black, transparent)',
                    'to-bl' => 'linear-gradient(to bottom left, black, transparent)',
                    'to-tl' => 'linear-gradient(to top left, black, transparent)',
                    'to-t-r' => 'linear-gradient(to top right, black, transparent)',
                    'to-b-r' => 'linear-gradient(to bottom right, black, transparent)',
                    'to-b-l' => 'linear-gradient(to bottom left, black, transparent)',
                    'to-t-l' => 'linear-gradient(to top left, black, transparent)',
                    'linear' => 'linear-gradient(black, transparent)',
                    'conic' => 'conic-gradient(black, transparent)',
                    'repeating-linear' => 'repeating-linear-gradient(black, transparent)',
                    'repeating-conic' => 'repeating-conic-gradient(black, transparent)',
                    'repeating-radial' => 'repeating-radial-gradient(black, transparent)',
                    'radial' => 'radial-gradient(black, transparent)',
                ],
                'aspectRatio' => ['auto' => 'auto', 'square' => '1 / 1', 'video' => '16 / 9'],
                'flexBasis' => ['spacing', 'auto' => 'auto', 'full' => '100%'],
                'gridTemplateColumns' => [
                    'none' => 'none', '1' => 'repeat(1, minmax(0, 1fr))', '2' => 'repeat(2, minmax(0, 1fr))', '3' => 'repeat(3, minmax(0, 1fr))', '4' => 'repeat(4, minmax(0, 1fr))', '5' => 'repeat(5, minmax(0, 1fr))', '6' => 'repeat(6, minmax(0, 1fr))', '7' => 'repeat(7, minmax(0, 1fr))', '8' => 'repeat(8, minmax(0, 1fr))', '9' => 'repeat(9, minmax(0, 1fr))', '10' => 'repeat(10, minmax(0, 1fr))', '11' => 'repeat(11, minmax(0, 1fr))', '12' => 'repeat(12, minmax(0, 1fr))',
                ],
                'gridTemplateRows' => [
                     'none' => 'none', '1' => 'repeat(1, minmax(0, 1fr))', '2' => 'repeat(2, minmax(0, 1fr))', '3' => 'repeat(3, minmax(0, 1fr))', '4' => 'repeat(4, minmax(0, 1fr))', '5' => 'repeat(5, minmax(0, 1fr))', '6' => 'repeat(6, minmax(0, 1fr))',
                ],
                'translate' => ['spacing', '1/2' => '50%', '1/3' => '33.333333%', '2/3' => '66.666667%', '1/4' => '25%', '3/4' => '75%', 'full' => '100%'],
                'rotate' => ['0'=>'0deg','1'=>'1deg','2'=>'2deg','3'=>'3deg','6'=>'6deg','12'=>'12deg','45'=>'45deg','90'=>'90deg','180'=>'180deg'],
                'scale' => ['0'=>'0','50'=>'.5','75'=>'.75','90'=>'.9','95'=>'.95','100'=>'1','105'=>'1.05','110'=>'1.1','125'=>'1.25','150'=>'1.5'],
                'skew' => ['0'=>'0deg','1'=>'1deg','2'=>'2deg','3'=>'3deg','6'=>'6deg','12'=>'12deg', 'DEFAULT' => '0deg'],
                'ringWidth' => [
                    'DEFAULT' => '3px',
                    '0' => '0px', '1' => '1px', '2' => '2px', '4' => '4px', '8' => '8px',
                ],
                'ringColor' => [
                    'theme' => 'colors', // Allows using colors like ring-blue-500
                    'DEFAULT' => ['theme' => 'colors.blue.500'], // Default color for the plain 'ring' class
                ],
                'ringOpacity' => [
                    'theme' => 'opacity', // Allows using opacity scale like ring-opacity-50
                    'DEFAULT' => '0.5',
                ],
                'ringOffsetWidth' => [
                    '0' => '0px', '1' => '1px', '2' => '2px', '4' => '4px', '8' => '8px',
                ],
                'ringOffsetColor' => ['colors'], // References theme.colors
            ],
            'variants' => [
                // Existing Variants
                'hover' => ':hover', 
                'focus' => ':focus', 
                'active' => ':active', 
                'disabled' => ':disabled',
                'visited' => ':visited', 
                'checked' => ':checked',

                // Structural Pseudo-classes
                'first' => ':first-child',
                'last' => ':last-child',
                'odd' => ':nth-child(odd)',
                'even' => ':nth-child(even)',
                'only' => ':only-child',
                'empty' => ':empty',
                'first-of-type' => ':first-of-type',
                'last-of-type' => ':last-of-type',
                'only-of-type' => ':only-of-type',
                'nth-child' => ['type' => 'selector_transform', 'transform' => fn($s) => ":nth-child({$s})"],
                'nth-last-child' => ['type' => 'selector_transform', 'transform' => fn($s) => ":nth-last-child({$s})"],
                'nth-of-type' => ['type' => 'selector_transform', 'transform' => fn($s) => ":nth-of-type({$s})"],
                'nth-last-of-type' => ['type' => 'selector_transform', 'transform' => fn($s) => ":nth-last-of-type({$s})"],

                // Pseudo-elements
                'before' => '::before',
                'after' => '::after',
                'placeholder' => '::placeholder',
                'file' => '::file-selector-button',
                'marker' => '::marker',
                'selection' => '::selection',

                // Media Queries & System Preferences
                'dark' => ['.dark &', null], 
                'portrait' => [null, '@media (orientation: portrait)'],
                'landscape' => [null, '@media (orientation: landscape)'],
                'motion-safe' => [null, '@media (prefers-reduced-motion: no-preference)'],
                'motion-reduce' => [null, '@media (prefers-reduced-motion: reduce)'],
                'print' => [null, '@media print'],
                'screen' => [null, '@media screen'],
                'hover-hover' => [null, '@media (hover: hover)'],
                'hover-none' => [null, '@media (hover: none)'],
                'focus-within' => ':focus-within',
                'focus-visible' => ':focus-visible',
                'focus-warning' => ':focus-within',
                'focus-invalid' => ':invalid',
                'focus-valid' => ':valid',
                'focus-open' => ['type' => 'selector_transform', 'transform' => fn($s) => ":focus-within[open] {$s}"],
                'focus-checked' => ['type' => 'selector_transform', 'transform' => fn($s) => ":focus-within[aria-checked='true'] {$s}"],
                'focus-disabled' => ['type' => 'selector_transform', 'transform' => fn($s) => ":focus-within[aria-disabled='true'] {$s}"],
                'focus-expanded' => ['type' => 'selector_transform', 'transform' => fn($s) => ":focus-within[aria-expanded='true'] {$s}"],
                'focus-hidden' => ['type' => 'selector_transform', 'transform' => fn($s) => ":focus-within[aria-hidden='false'] {$s}"],
                'focus-pressed' => ['type' => 'selector_transform', 'transform' => fn($s) => ":focus-within[aria-pressed='true'] {$s}"],
                'focus-selected' => ['type' => 'selector_transform', 'transform' => fn($s) => ":focus-within[aria-selected='true'] {$s}"],

                // Group State Variants
                'group-open' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group[open] {$s}"],
                'group-closed' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group[open='false'] {$s}"],
                'group-hover' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:hover {$s}"],
                'group-focus' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus {$s}"],
                'group-required' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:required {$s}"],
                'group-active' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:active {$s}"],
                'group-disabled' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:disabled {$s}"],
                'group-checked' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:checked {$s}"],
                'group-visited' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:visited {$s}"],
                'group-focus-within' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus-within {$s}"],
                'group-first' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:first-child {$s}"],
                'group-last' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:last-child {$s}"],
                'group-invalid' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:invalid {$s}"],
                'group-valid' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:valid {$s}"],
                'group-focus-warning' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus-within {$s}"],
                'group-focus-visible' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus-visible {$s}"],
                'group-focus-invalid' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus-within:invalid {$s}"],
                'group-focus-valid' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus-within:valid {$s}"],
                'group-focus-open' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus-within[open] {$s}"],
                'group-focus-checked' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus-within[aria-checked='true'] {$s}"],
                'group-focus-disabled' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus-within[aria-disabled='true'] {$s}"],
                'group-focus-expanded' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus-within[aria-expanded='true'] {$s}"],
                'group-focus-hidden' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus-within[aria-hidden='false'] {$s}"],
                'group-focus-pressed' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus-within[aria-pressed='true'] {$s}"],
                'group-focus-selected' => ['type' => 'selector_transform', 'transform' => fn($s) => ".group:focus-within[aria-selected='true'] {$s}"],

                // Peer State Variants
                'peer-checked' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:checked ~ {$s}"],
                'peer-focus' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus ~ {$s}"],
                'peer-hover' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:hover ~ {$s}"],
                'peer-active' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:active ~ {$s}"],
                'peer-disabled' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:disabled ~ {$s}"],
                'peer-invalid' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:invalid ~ {$s}"],
                'peer-valid' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:valid ~ {$s}"],
                'peer-visited' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:visited ~ {$s}"],
                'peer-required' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:required ~ {$s}"],
                'peer-first' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:first-child ~ {$s}"],
                'peer-last' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:last-child ~ {$s}"],
                'peer-odd' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:nth-child(odd) ~ {$s}"],
                'peer-even' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:nth-child(even) ~ {$s}"],
                'peer-only' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:only-child ~ {$s}"],
                'peer-empty' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:empty ~ {$s}"],
                'peer-first-of-type' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:first-of-type ~ {$s}"],
                'peer-last-of-type' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:last-of-type ~ {$s}"],
                'peer-nth-child' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:nth-child({$s}) ~ {$s}"],
                'peer-nth-last-child' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:nth-last-child({$s}) ~ {$s}"],
                'peer-nth-of-type' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:nth-of-type({$s}) ~ {$s}"],
                'peer-nth-last-of-type' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:nth-last-of-type({$s}) ~ {$s}"],
                'peer-focus-within' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus-within ~ {$s}"],
                'peer-focus-visible' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus-visible ~ {$s}"],
                'peer-focus-warning' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus-within ~ {$s}"],
                'peer-focus-invalid' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus-within:invalid ~ {$s}"],
                'peer-focus-valid' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus-within:valid ~ {$s}"],
                'peer-focus-open' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus-within[open] ~ {$s}"],
                'peer-focus-checked' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus-within[aria-checked='true'] ~ {$s}"],
                'peer-focus-disabled' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus-within[aria-disabled='true'] ~ {$s}"],
                'peer-focus-expanded' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus-within[aria-expanded='true'] ~ {$s}"],
                'peer-focus-hidden' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus-within[aria-hidden='false'] ~ {$s}"],
                'peer-focus-pressed' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus-within[aria-pressed='true'] ~ {$s}"],
                'peer-focus-selected' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:focus-within[aria-selected='true'] ~ {$s}"],
                'peer-placeholder-shown' => ['type' => 'selector_transform', 'transform' => fn($s) => ".peer:placeholder-shown ~ {$s}"],

                // Attribute Selectors (ARIA, open/closed)
                'open' => ['type' => 'attribute', 'attribute' => '[open]'],
                'aria-checked' => ['type' => 'attribute', 'attribute' => '[aria-checked="true"]'],
                'aria-disabled' => ['type' => 'attribute', 'attribute' => '[aria-disabled="true"]'],
                'aria-expanded' => ['type' => 'attribute', 'attribute' => '[aria-expanded="true"]'],
                'aria-hidden' => ['type' => 'attribute', 'attribute' => '[aria-hidden="false"]'],
                'aria-pressed' => ['type' => 'attribute', 'attribute' => '[aria-pressed="true"]'],
                'aria-selected' => ['type' => 'attribute', 'attribute' => '[aria-selected="true"]'],

                // Arbitrary selector patterns as before
                // 'arbitrary_selector_pattern' => '/^\[(?:&(.+?)|@(.+?))\]$/',
                // 'arbitrary_selector_parent_pattern' => '/^\[(.+?)_&\]$/',
                'arbitrary_variant_pattern' => '/^\[(.*?)\]$/',
                // 'arbitrary_variant_pattern' => '/^\[(?:(@[^\]]+)|(&[^\]]*)|([^\]]+_&))\]$/',
            ],
            'bsToTwMap' => [
                // ==========================
                // CONTENT & TYPOGRAPHY
                // ==========================
                'lead' => 'text-xl font-light text-slate-600',
                'display-1' => 'text-6xl font-light leading-tight',
                'display-2' => 'text-5xl font-light leading-tight',
                'display-3' => 'text-4xl font-light leading-tight',
                'display-4' => 'text-3xl font-light leading-tight',
                'display-5' => 'text-2xl font-light leading-tight',
                'display-6' => 'text-xl font-light leading-tight',
                'h1' => 'text-5xl font-medium leading-tight mb-2',
                'h2' => 'text-4xl font-medium leading-tight mb-2',
                'h3' => 'text-3xl font-medium leading-tight mb-2',
                'h4' => 'text-2xl font-medium leading-tight mb-2',
                'h5' => 'text-xl font-medium leading-tight mb-2',
                'h6' => 'text-base font-medium leading-tight mb-2',
                'small' => 'text-sm font-light',
                'mark' => 'bg-yellow-200 p-1',
                'blockquote' => 'mb-4 text-lg italic border-l-4 border-slate-300 pl-4',
                'blockquote-footer' => 'block mt-2 text-sm text-slate-500',
                'figure' => 'inline-block',
                'figure-img' => 'mb-2 leading-none max-w-full h-auto rounded',
                'figure-caption' => 'text-sm text-slate-500 text-center',
                // Text Transform
                'text-lowercase'  => 'lowercase',
                'text-uppercase'  => 'uppercase',
                'text-capitalize' => 'capitalize',

                // Font Styles & Weights
                'fst-italic'      => 'italic',
                'fst-normal'      => 'not-italic',
                'fw-bold'         => 'font-bold',
                'fw-bolder'       => 'font-extrabold',
                'fw-semibold'     => 'font-semibold',
                'fw-medium'       => 'font-medium',
                'fw-normal'       => 'font-normal',
                'fw-light'        => 'font-light',
                'fw-lighter'      => 'font-thin',
                'font-monospace'  => 'font-mono',

                // Line Height
                'lh-1'    => 'leading-none',
                'lh-sm'   => 'leading-tight',
                'lh-base' => 'leading-normal',
                'lh-lg'   => 'leading-loose',

                // Text Decoration
                'text-decoration-underline'    => 'underline',
                'text-decoration-line-through' => 'line-through',
                'text-decoration-none'         => 'no-underline',
                
                // Text Reset
                'text-reset' => 'text-inherit',
                // === Text Wrapping & Overflow ===
                'text-wrap'   => 'whitespace-normal',
                'text-nowrap' => 'whitespace-nowrap',
                'text-break'  => 'break-words break-all',
                'text-neutral-content' => 'text-[hsl(var(--nc))]',
                
                // Stretched Link
                'stretched-link' => 'after:absolute after:inset-0 after:z-10 after:content-[""]',

                // Images
                'img-fluid' => 'max-w-full h-auto',
                'img-thumbnail' => 'p-1 bg-white border border-slate-200 rounded shadow-sm max-w-full h-auto',

                // Tables
                'table' => 'w-full mb-4 align-top border-collapse text-left',
                'table-sm' => 'text-sm [&_th]:p-1 [&_td]:p-1',
                'table-bordered' => 'border border-slate-200 [&_th]:border [&_th]:border-slate-200 [&_td]:border [&_td]:border-slate-200',
                'table-borderless' => '[&_th]:border-0 [&_td]:border-0',
                'table-striped' => '[&>tbody>tr:nth-of-type(odd)]:bg-slate-50',
                'table-hover' => '[&>tbody>tr]:hover:bg-slate-100',
                'table-active' => 'bg-slate-100',
                'table-dark' => 'bg-slate-900 text-white',
                'table-responsive' => 'block w-full overflow-x-auto',

                // ==========================
                // 3. FORMS
                // ==========================
                'form-label' => 'block mb-2 font-medium text-slate-700',
                'form-text' => 'mt-1 text-sm text-slate-500',
                'form-control' => 'block w-full px-3 py-2 text-base font-normal text-slate-700 bg-white bg-clip-padding border border-slate-300 rounded transition ease-in-out m-0 focus:text-slate-700 focus:bg-white focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20',
                'form-control-plaintext' => 'block w-full pt-2 pb-2 mb-0 leading-normal bg-transparent border-transparent border-0',
                'form-control-sm' => 'px-2 py-1 text-sm rounded',
                'form-control-lg' => 'px-4 py-3 text-lg rounded-lg',
                'form-select' => 'block w-full px-3 py-2 text-base font-normal text-slate-700 bg-white border border-slate-300 rounded transition ease-in-out m-0 focus:text-slate-700 focus:bg-white focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 appearance-none', // appearance-none added for custom arrow handle via pattern if needed
                'form-select-sm' => 'pt-1 pb-1 pl-2 pr-8 text-sm rounded',
                'form-select-lg' => 'pt-3 pb-3 pl-4 pr-8 text-lg rounded-lg',
                'form-check' => 'block min-h-[1.5rem] pl-[1.5em] mb-2',
                'form-check-input' => 'float-left -ml-[1.5em] mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500',
                'form-check-label' => 'inline-block text-slate-800',
                'form-check-inline' => 'inline-block mr-4',
                'form-floating' => 'relative',
                'input-group-text' => 'flex items-center px-3 py-2 text-base font-normal text-slate-700 text-center whitespace-nowrap bg-slate-100 border border-slate-300 rounded-md',
                'input-group-lg' => '[&>*]:text-lg [&>*]:px-4 [&>*]:py-3 [&>.btn]:px-6 [&>.form-select]:px-4 [&>.form-select]:py-3',
                'input-group-sm' => '[&>*]:text-sm [&>*]:px-2 [&>*]:py-1 [&>.btn]:px-3 [&>.form-select]:px-2 [&>.form-select]:py-1',
                'pagination-lg' => '[&>.page-link]:px-6 [&>.page-link]:py-3 [&>.page-link]:text-lg',
                'pagination-sm' => '[&>.page-link]:px-2 [&>.page-link]:py-1 [&>.page-link]:text-sm',
                'list-group-numbered' => '[&>.list-group-item]:list-decimal [&>.list-group-item]:list-inside',
                'list-group-flush' => '[&>.list-group-item]:border-x-0 [&>.list-group-item]:rounded-none',
                
                // --- Alerts ---
                'alert-dismissible' => 'relative pr-12', // Space for close button

                'was-validated' => '', // Marker class, no direct style
                'needs-validation' => '', // Marker class, no direct style

                // Feedback messages
                'valid-feedback' => 'hidden w-full mt-1 text-sm text-green-600',
                'invalid-feedback' => 'hidden w-full mt-1 text-sm text-red-600',
                'valid-tooltip' => 'absolute z-10 hidden w-max max-w-full p-2 mt-1 text-sm text-white bg-green-600 rounded-md',
                'invalid-tooltip' => 'absolute z-10 hidden w-max max-w-full p-2 mt-1 text-sm text-white bg-red-600 rounded-md',

                // Validation States (applied to form controls)
                'is-valid' => '[--tw-ring-color:theme(colors.green.500)] border-green-500 focus:border-green-500 focus:ring-green-500/25',
                'is-invalid' => '[--tw-ring-color:theme(colors.red.500)] border-red-500 focus:border-red-500 focus:ring-red-500/25',

                // --- Dropdowns ---
                'dropdown-menu-dark' => 'bg-slate-800 border-slate-700 [&>.dropdown-item]:text-white [&>.dropdown-item]:hover:bg-slate-700',
                'dropdown-menu-end' => 'right-0 left-auto',
                'dropdown-menu-start' => 'left-0 right-auto',

                // === FLOAT (Base Utilities) ===
                'float-start' => 'float-left',
                'float-end'   => 'float-right',
                'float-none'  => 'float-none',

                // ==========================
                // 4. COMPONENTS
                // ==========================
                
                // Accordion
                'accordion' => 'rounded border border-slate-200',
                'accordion-item' => 'border-b border-slate-200 last:border-b-0',
                'accordion-header' => 'mb-0',
                'accordion-body' => 'py-4 px-5',

                // Alerts
                'alert' => 'relative p-4 mb-4 border rounded-lg flex justify-start items-start gap-2',
                'alert-heading' => 'text-xl font-bold mb-1',
                'alert-link' => 'font-bold underline',
                'alert-dismissible' => 'pr-12 relative', 

                // Badge
                'rounded-pill' => 'rounded-full',

                // Breadcrumb
                'breadcrumb' => 'flex flex-wrap list-none p-3 bg-slate-100 rounded mb-4',
                'breadcrumb-item' => 'flex items-center text-slate-600',

                // Buttons (Base styles, specific variants in dynamic patterns)
                'btn' => [
                    'base' => 'inline-block px-4 py-2 font-medium text-center align-middle cursor-pointer select-none border rounded-md',
                    'transition' => 'transition-all duration-150 ease-in-out',
                    'states' => [
                        'hover' => 'scale-105', 'active' => 'scale-95',
                        'disabled' => 'pointer-events-none opacity-65',
                        'focus' => 'outline-none! z-10 ring-2 ring-ring ring-offset-0',
                    ],
                ],
                'btn-group' => [
                    'base' => 'relative inline-flex align-middle',
                    'pseudo' => [
                        ' > .btn:not(:first-child)' => 'rounded-l-none -ml-px',
                        ' > .btn:not(:last-child)' => 'rounded-r-none',
                        ' > .btn:hover' => 'z-10',
                        ' > .btn:focus' => 'z-10 ring-2 ring-ring ring-offset-0',
                    ],
                ],
                'btn-items' => 'inline-flex items-center justify-center gap-2 whitespace-nowrap transition-all duration-200 ease-out focus:outline-none! focus:ring-2 focus:ring-offset-0',
                'btn-responsive' => 'btn-xs sm:btn-sm md:btn-md lg:btn-lg xl:btn-xl',

                // Cards
                'card' => 'relative flex flex-col min-w-0 break-words bg-white bg-clip-border border border-slate-200 rounded-lg shadow-sm',
                'card-body' => 'flex-auto p-5',
                'card-title' => 'mb-3 text-xl font-medium tracking-tight text-slate-900',
                'card-subtitle' => 'mt-[-0.375rem] mb-2 text-sm text-slate-500',
                'card-text' => 'mb-4 text-slate-600',
                'card-link' => 'text-blue-600 hover:text-blue-800 hover:underline ml-4 first:ml-0',
                'card-header' => 'px-5 py-3 bg-slate-50 border-b border-slate-200 rounded-t-lg',
                'card-footer' => 'px-5 py-3 bg-slate-50 border-t border-slate-200 rounded-b-lg',
                'card-img-top' => 'w-full rounded-t-lg',
                'card-group' => 'flex flex-col sm:flex-row',
                
                // Carousel
                'carousel' => 'relative',
                'carousel-inner' => 'relative w-full overflow-hidden',
                'carousel-item' => 'relative float-left w-full mr-[-100%] backface-hidden transition-transform duration-600 ease-in-out',
                'carousel-active' => 'block',
                'collapsing' => 'h-0 overflow-hidden transition-height duration-350 ease',

                // Dropdowns
                'dropdown' => 'relative',
                'dropdown-menu' => 'absolute z-50 hidden float-left min-w-[10rem] py-2 m-0 text-base text-left list-none bg-white bg-clip-padding border border-slate-200 rounded shadow-lg mt-1',
                'dropdown-item' => 'block w-full py-1 px-4 clear-both font-normal text-slate-700 whitespace-nowrap bg-transparent border-0 hover:bg-slate-100 hover:text-slate-900',
                'dropdown-divider' => 'h-0 my-2 overflow-hidden border-t border-slate-200',
                'dropdown-header' => 'block px-4 py-2 text-sm text-slate-500',

                // List Group
                'list-group' => 'flex flex-col pl-0 mb-0 rounded border border-slate-200',
                'list-group-item' => 'relative block px-4 py-3 bg-white border-b border-slate-200 last:border-b-0 text-slate-800',
                'list-group-item-action' => 'w-full text-left hover:bg-slate-50 hover:text-slate-900 cursor-pointer',
                'list-group-flush' => 'border-0 rounded-none',
                'list-group-numbered' => 'list-decimal list-inside',

                // Modal
                'modal' => 'fixed top-0 left-0 hidden w-full h-full outline-none overflow-x-hidden overflow-y-auto z-50 bg-black/50', // Added backdrop color
                'modal-dialog' => 'relative w-auto pointer-events-none my-7 mx-auto sm:max-w-lg',
                'modal-dialog-centered' => 'flex items-center min-h-screen',
                'modal-dialog-scrollable' => '[&>.modal-content]:max-h-[calc(100vh-3.5rem)] [&>.modal-content]:overflow-hidden [&>.modal-content>.modal-body]:overflow-y-auto',
                'modal-content' => 'relative flex flex-col w-full pointer-events-auto bg-card text-foreground bg-clip-padding border border-border rounded-lg shadow-xl outline-none',
                'modal-header' => 'flex flex-shrink-0 items-center justify-between p-4 border-b border-border rounded-t-lg',
                'modal-title' => 'text-xl font-semibold leading-normal text-foreground',
                'modal-body' => 'relative flex-auto p-4',
                'modal-footer' => 'flex flex-wrap flex-shrink-0 items-center justify-end p-4 border-t border-border rounded-b-lg gap-2',
                'modal-sm' => 'sm:max-w-sm',
                'modal-lg' => 'sm:max-w-3xl',
                'modal-xl' => 'sm:max-w-5xl',
                'modal-fullscreen' => 'fixed inset-0 w-full h-full max-w-full m-0 rounded-none border-0',
                '.modal-header .btn-close' => 'dark:invert dark:grayscale',

                // Navs & Tabs
                'nav' => 'flex flex-wrap list-none pl-0 mb-0',
                'nav-link' => 'block px-4 py-2 text-blue-600 hover:text-blue-800 hover:bg-slate-100 transition-colors rounded',
                'nav-link.active' => 'text-slate-900 bg-slate-100 font-medium',
                'nav-link.disabled' => 'text-slate-400 pointer-events-none cursor-default',
                'nav-tabs' => 'border-b border-slate-200',
                'nav-pills' => 'gap-1',
                'nav-fill' => 'justify-between [&>.nav-item]:flex-auto [&>.nav-item]:text-center',
                'nav-justified' => 'justify-between [&>.nav-item]:flex-grow [&>.nav-item]:text-center',
                'tab-content' => 'mt-4',

                // Navbar
                // 'navbar' => 'relative flex flex-wrap items-center justify-between py-2 px-4 bg-white shadow-sm',
                'navbar-brand' => 'pt-1 pb-1 text-xl whitespace-nowrap text-slate-900 font-semibold',
                'navbar-nav' => 'flex flex-col pl-0 mb-0 list-none md:flex-row',
                'navbar-toggler' => 'py-1 px-2 text-xl bg-transparent border border-transparent rounded transition focus:outline-none',
                'navbar-collapse' => 'flex-basis-full flex-grow items-center',
                
                // Offcanvas
                'offcanvas' => 'fixed bottom-0 flex flex-col max-w-full bg-white bg-clip-padding shadow-sm outline-none transition duration-300 ease-in-out text-slate-900 z-[1045]',
                'offcanvas-start' => 'top-0 left-0 w-80 border-r border-slate-200 transform -translate-x-full',
                'offcanvas-end' => 'top-0 right-0 w-80 border-l border-slate-200 transform translate-x-full',
                'offcanvas-top' => 'top-0 left-0 right-0 h-[30vh] border-b border-slate-200 transform -translate-y-full',
                'offcanvas-bottom' => 'bottom-0 left-0 right-0 h-[30vh] border-t border-slate-200 transform translate-y-full',
                'offcanvas-header' => 'flex items-center justify-between p-4',
                'offcanvas-title' => 'mb-0 leading-tight font-semibold',
                'offcanvas-body' => 'flex-grow p-4 overflow-y-auto',

                // Pagination
                'pagination' => 'flex list-none rounded-md',
                'pagination-lg' => '[&_.page-link]:px-6 [&_.page-link]:py-3 [&_.page-link]:text-lg',
                'pagination-sm' => '[&_.page-link]:px-2 [&_.page-link]:py-1 [&_.page-link]:text-sm',
                'page-item' => 'first:[&>.page-link]:rounded-l-md last:[&>.page-link]:rounded-r-md',
                'page-link' => [
                    'base' => 'relative block px-3 py-1.5 text-primary bg-background border border-muted transition-colors duration-150 ease-in-out -ml-px',
                    'states' => [
                        'hover' => 'z-10 bg-muted/50 border-muted text-primary-hover',
                        'focus' => 'z-10 outline-none ring-2 ring-primary/25',
                    ],
                ],
                '.page-item.active > .page-link' => 'z-10 bg-primary border-primary text-white pointer-events-none',
                '.page-item.disabled > .page-link' => 'text-muted-foreground pointer-events-none bg-background border-muted',

                // Placeholders
                'placeholder' => 'inline-block min-h-[1em] align-middle cursor-wait bg-current opacity-50',
                'placeholder-glow' => 'animate-pulse',
                'placeholder-wave' => 'relative overflow-hidden after:content-[""] after:absolute after:inset-0 after:bg-gradient-to-r after:from-transparent after:via-white/30 after:to-transparent after:animate-[placeholder-wave_2s_linear_infinite]',
                
                // Skeleton (daisyUI style)
                'skeleton' => 'cursor-default bg-muted animate-pulse rounded-md min-h-[1em] w-full',

                // Progress
                'progress' => 'flex h-4 overflow-hidden text-xs bg-slate-200 rounded-full',
                '/^progress-(primary|secondary|success|warning|error|info|accent|danger)$/' => [
                    'base' => '[&::-webkit-progress-value]:bg-{1} [&::-moz-progress-bar]:bg-{1} bg-{1}',
                ],
                'progress-bar' => 'flex flex-col justify-center overflow-hidden text-white text-center whitespace-nowrap bg-blue-600 transition-all duration-500',
                'progress-bar-striped' => 'bg-[length:1rem_1rem] bg-gradient-to-br from-white/15 via-white/15 to-transparent',
                'progress-bar-animated' => 'animate-[progress-bar-stripes_1s_linear_infinite]',

                // Spinners
                'spinner-border' => 'inline-block w-8 h-8 border-4 border-current border-r-transparent rounded-full animate-spin animate-infinite align-[-0.125em] motion-reduce:animate-[spin_1.5s_linear_infinite]',
                'spinner-border-sm' => 'w-4 h-4 border-2',
                'spinner-grow' => 'inline-block w-8 h-8 bg-current rounded-full opacity-0 animate-infinite align-[-0.125em] animate-[spinner-grow_0.75s_linear_infinite]',
                'spinner-grow-sm' => 'w-4 h-4',

                // Toasts
                'toast' => 'w-full max-w-xs pointer-events-auto bg-white bg-clip-padding border border-slate-200 shadow-lg rounded text-sm',
                'toast-header' => 'flex items-center py-2 px-3 text-slate-700 bg-slate-100 border-b border-slate-200 rounded-t',
                'toast-body' => 'p-3 break-words text-slate-800',

                // Tooltips
                'tooltip' => 'absolute z-[1080] block font-normal leading-normal text-xs text-left no-underline break-words opacity-0',
                'tooltip-inner' => 'max-w-[200px] p-2 text-white text-center bg-black rounded',

                // ==========================
                // 5. HELPERS & UTILITIES
                // ==========================
                'ratio' => 'relative w-full block [&>iframe]:absolute [&>iframe]:inset-0 [&>iframe]:w-full [&>iframe]:h-full [&>video]:absolute [&>video]:inset-0 [&>video]:w-full [&>video]:h-full [&>embed]:absolute [&>embed]:inset-0 [&>embed]:w-full [&>embed]:h-full [&>object]:absolute [&>object]:inset-0 [&>object]:w-full [&>object]:h-full',
                'sticky-top' => 'sticky top-0 z-[1020]',
                'hstack' => 'flex flex-row items-center',
                'vstack' => 'flex flex-col flex-1',
                'text-truncate' => 'overflow-hidden text-ellipsis whitespace-nowrap',
                'visually-hidden' => 'sr-only',
                'visually-hidden-focusable' => 'not-sr-only',
                'stretched-link' => 'after:absolute after:inset-0 after:z-10 after:content-[""]',
                'text-break' => 'break-words break-all',
                'text-center' => 'text-center',
                'text-start' => 'text-left',
                'text-end' => 'text-right',
                'text-nowrap' => 'whitespace-nowrap',
                'text-decoration-none' => 'no-underline',
                'text-reset' => 'text-inherit',
                'list-unstyled' => 'list-none pl-0',
                'list-inline' => 'list-none pl-0',
                'list-inline-item' => 'inline-block mr-2',
                'link-primary' => 'text-blue-600 hover:text-blue-800 underline',
                'link-secondary' => 'text-slate-600 hover:text-slate-800 underline',
                
                // Visibility & Display
                'visible' => 'visible', 'invisible' => 'invisible',
                'd-none' => 'hidden', 'd-inline' => 'inline', 'd-inline-block' => 'inline-block', 'd-block' => 'block',
                'd-inline-flex' => 'inline-flex',
                'd-grid' => 'grid', 'd-table' => 'table', 'd-table-row' => 'table-row', 'd-table-cell' => 'table-cell',
                'd-flex' => 'flex', 
                
                // Visually Hidden (Screen Reader Only)
                'visually-hidden' => 'sr-only', 
                // Visually Hidden Focusable (Show on focus, e.g. Skip Links)
                'visually-hidden-focusable' => 'sr-only focus:not-sr-only',

                'focus-ring' => 'focus:outline-none focus:ring-4 focus:ring-primary/25',
                'focus-ring-primary'   => 'focus:outline-none focus:ring-4 focus:ring-primary/25',
                'focus-ring-secondary' => 'focus:outline-none focus:ring-4 focus:ring-secondary/25',
                'focus-ring-success'   => 'focus:outline-none focus:ring-4 focus:ring-success/25',
                'focus-ring-danger'    => 'focus:outline-none focus:ring-4 focus:ring-danger/25',
                'focus-ring-warning'   => 'focus:outline-none focus:ring-4 focus:ring-warning/25',
                'focus-ring-info'      => 'focus:outline-none focus:ring-4 focus:ring-info/25',
                'focus-ring-accent'    => 'focus:outline-none focus:ring-4 focus:ring-accent/25',
                'focus-ring-error'     => 'focus:outline-none focus:ring-4 focus:ring-error/25',

                'focus-ring-success'   => 'focus:outline-none focus:ring-4 focus:ring-success/25',
                'focus-ring-danger'    => 'focus:outline-none focus:ring-4 focus:ring-danger/25',
                'focus-ring-warning'   => 'focus:outline-none focus:ring-4 focus:ring-warning/25',
                'focus-ring-info'      => 'focus:outline-none focus:ring-4 focus:ring-info/25',
                'focus-ring-light'     => 'focus:outline-none focus:ring-4 focus:ring-light/25',
                'focus-ring-dark'      => 'focus:outline-none focus:ring-4 focus:ring-dark/25',

                // Horizontal Rule (hr class)
                'hr' => 'my-4 border-0 border-t border-current opacity-25 w-[1em]',
                // Vertical Rule (vr class)
                'vr' => 'inline-block self-stretch w-px min-h-[1em] bg-current opacity-25 align-middle mx-1',

                // Positioning
                // Position Type
                'position-static'   => 'static',
                'position-relative' => 'relative',
                'position-absolute' => 'absolute',
                'position-fixed'    => 'fixed',
                'position-sticky'   => 'sticky',
                // Top / Bottom / Start (Left) / End (Right)
                'top-0'    => 'top-0',
                'top-50'   => 'top-1/2',
                'top-100'  => 'top-full',
                'bottom-0'   => 'bottom-0',
                'bottom-50'  => 'bottom-1/2',
                'bottom-100' => 'bottom-full',
                'start-0'    => 'left-0',
                'start-50'   => 'left-1/2',
                'start-100'  => 'left-full',
                'end-0'      => 'right-0',
                'end-50'     => 'right-1/2',
                'end-100'    => 'right-full',
                // Translate (Centering)
                'translate-middle'   => '-translate-x-1/2 -translate-y-1/2',
                'translate-middle-x' => '-translate-x-1/2',
                'translate-middle-y' => '-translate-y-1/2',
                
                // Sizing
                'w-25'   => 'w-1/4',
                'w-50'   => 'w-1/2',
                'w-75'   => 'w-3/4',
                'w-100'  => 'w-full',
                'w-auto' => 'w-auto',
                // Height
                'h-25'   => 'h-1/4',
                'h-50'   => 'h-1/2',
                'h-75'   => 'h-3/4',
                'h-100'  => 'h-full',
                'h-auto' => 'h-auto',
                // Max Width & Height
                'mw-100' => 'max-w-full',
                'mh-100' => 'max-h-full',
                // Viewport Sizing
                'min-vw-100' => 'min-w-full',
                'min-vh-100' => 'min-h-screen',
                'vw-100'     => 'w-full',
                'vh-100'     => 'h-screen',
                
                // Flexbox
                'flex-row' => 'flex-row', 'flex-column' => 'flex-col', 'flex-row-reverse' => 'flex-row-reverse', 'flex-column-reverse' => 'flex-col-reverse',
                'flex-wrap' => 'flex-wrap', 'flex-nowrap' => 'flex-nowrap', 'flex-wrap-reverse' => 'flex-wrap-reverse',
                'justify-content-start' => 'justify-start', 'justify-content-end' => 'justify-end', 'justify-content-center' => 'justify-center',
                'justify-content-between' => 'justify-between', 'justify-content-around' => 'justify-around', 'justify-content-evenly' => 'justify-evenly',
                'align-items-start' => 'items-start', 'align-items-end' => 'items-end', 'align-items-center' => 'items-center', 'align-items-baseline' => 'items-baseline', 'align-items-stretch' => 'items-stretch',
                'align-content-start' => 'content-start', 'align-content-end' => 'content-end', 'align-content-center' => 'content-center', 'align-content-between' => 'content-between', 'align-content-around' => 'content-around', 'align-content-stretch' => 'content-stretch',
                'align-self-auto' => 'self-auto', 'align-self-start' => 'self-start', 'align-self-end' => 'self-end', 'align-self-center' => 'self-center', 'align-self-baseline' => 'self-baseline', 'align-self-stretch' => 'self-stretch',
                'flex-fill' => 'flex-1', 'flex-grow-0' => 'grow-0', 'flex-grow-1' => 'grow', 'flex-shrink-0' => 'shrink-0', 'flex-shrink-1' => 'shrink',
                
                // Float
                'float-start' => 'float-left', 'float-end' => 'float-right', 'float-none' => 'float-none',
                
                // Interactions
                'user-select-all' => 'select-all', 'user-select-auto' => 'select-auto', 'user-select-none' => 'select-none',
                'pe-none' => 'pointer-events-none', 'pe-auto' => 'pointer-events-auto',
                
                // Object Fit
                'object-fit-contain' => 'object-contain', 'object-fit-cover' => 'object-cover', 'object-fit-fill' => 'object-fill', 'object-fit-scale' => 'object-scale-down', 'object-fit-none' => 'object-none',
                
                // Opacity
                'opacity-0' => 'opacity-0', 'opacity-25' => 'opacity-25', 'opacity-50' => 'opacity-50', 'opacity-75' => 'opacity-75', 'opacity-100' => 'opacity-100',
                
                // Overflow
                'overflow-auto' => 'overflow-auto', 'overflow-hidden' => 'overflow-hidden', 'overflow-visible' => 'overflow-visible', 'overflow-scroll' => 'overflow-scroll',
                
                // Shadow
                'shadow-sm' => 'shadow-sm', 'shadow' => 'shadow', 'shadow-md' => 'shadow-md', 'shadow-lg' => 'shadow-lg', 'shadow-none' => 'shadow-none',
                
                // Borders
                'border' => 'border border-slate-200',
                'border-0' => 'border-0',
                'border-top' => 'border-t border-slate-200', 'border-top-0' => 'border-t-0',
                'border-end' => 'border-r border-slate-200', 'border-end-0' => 'border-r-0',
                'border-bottom' => 'border-b border-slate-200', 'border-bottom-0' => 'border-b-0',
                'border-start' => 'border-l border-slate-200', 'border-start-0' => 'border-l-0',
                'rounded' => 'rounded', 'rounded-0' => 'rounded-none', 'rounded-1' => 'rounded-sm', 'rounded-2' => 'rounded', 'rounded-3' => 'rounded-md', 'rounded-4' => 'rounded-lg', 'rounded-5' => 'rounded-xl',
                'rounded-circle' => 'rounded-full', 'rounded-pill' => 'rounded-full',
                'rounded-top' => 'rounded-t', 'rounded-end' => 'rounded-r', 'rounded-bottom' => 'rounded-b', 'rounded-start' => 'rounded-l',
                
                // Gap
                'gap-0' => 'gap-0', 'gap-1' => 'gap-1', 'gap-2' => 'gap-2', 'gap-3' => 'gap-4', 'gap-4' => 'gap-6', 'gap-5' => 'gap-8',

            ],
            
            'dynamicBsToTwPatterns' => [
                // --- Layout ---
                // Columns: col-md-4 -> md:w-4/12
                '/^table-(primary|secondary|success|danger|warning|info|light|dark)$/' => [
                    'base' => 'bg-{1}/25 text-{1}-900 border-{1}/30', // হালকা ব্যাকগ্রাউন্ড, গাঢ় টেক্সট
                    'dark' => [
                        'base' => 'dark:bg-{1}/20 dark:text-{1}-100 dark:border-{1}/20', // ডার্ক মোড সাপোর্ট
                    ]
                ],
                '/^table-active$/' => 'bg-slate-100 dark:bg-slate-800',
                // dynamicBsToTwPatterns এর ভেতরে
                '/^(sm|md|lg|xl|xxl):stats-horizontal$/' => '{1}:grid-flow-col',
                '/^(sm|md|lg|xl|xxl):stats-vertical$/'   => '{1}:grid-flow-row',
                // --- Utilities Responsive ---
                // Flex Direction, Wrap, Justify, Align
                '/^flex-(sm|md|lg|xl|xxl)-(row|row-reverse|col|col-reverse)$/' => '{1}:flex-{2}',
                '/^ratio-(\d+(?:\.\d+)?)x(\d+(?:\.\d+)?)$/' => function($m) {
                    $width = $m[1];
                    $height = $m[2];
                    return "aspect-[{$width}/{$height}]";
                },
                '/^justify-content-(sm|md|lg|xl|xxl)-(start|end|center|between|around|evenly)$/' => '{1}:justify-{2}',
                '/^align-items-(sm|md|lg|xl|xxl)-(start|end|center|baseline|stretch)$/' => '{1}:items-{2}',
                // Text Align Responsive
                '/^text-(sm|md|lg|xl|xxl)-start$/'  => '{1}:text-left',
                '/^text-(sm|md|lg|xl|xxl)-end$/'    => '{1}:text-right',
                '/^text-(sm|md|lg|xl|xxl)-center$/' => '{1}:text-center',
                '/^text-(start|end|center)$/' => function($m) {
                    $map = ['start' => 'text-left', 'end' => 'text-right', 'center' => 'text-center'];
                    return $map[$m[1]] ?? '';
                },
                
                // --- Colors & Backgrounds ---
                // Text Colors: text-primary, text-danger
                '/^text-(primary|secondary|success|danger|warning|info|light|dark|muted|white|black-50|white-50)$/' => 'text-{1}',
                '/^text-bg-(light|warning)$/' => 'bg-{1} text-slate-900',
                '/^text-bg-(primary|secondary|success|danger|info|dark)$/' => 'bg-{1} text-white',
                
                // Fallback for any other colors
                '/^text-bg-([a-z]+)$/' => 'bg-{1} text-white',
                '/^fs-([1-6])$/' => function($m) {
                    $map = [
                        '1' => 'text-6xl', // calc(1.375rem + 1.5vw) -> approx big
                        '2' => 'text-5xl',
                        '3' => 'text-4xl',
                        '4' => 'text-3xl',
                        '5' => 'text-xl',
                        '6' => 'text-base',
                    ];
                    return $map[$m[1]] ?? 'text-base';
                },

                // === Text Colors & Opacity (text-white-50, text-black-50) ===
                '/^text-(black|white)-50$/' => 'text-{1}/50',

                // === Text Opacity (Advanced: text-opacity-*) ===
                '/^text-opacity-(\d+)$/' => 'text-opacity-{1}',

                // Link Opacity (link-opacity-50, link-opacity-50-hover)
                '/^link-opacity-(\d+)(?:-(hover))?$/' => function($m) {
                    $opacity = $m[1];
                    $hover = isset($m[2]) ? 'hover:' : '';
                    return "{$hover}text-opacity-{$opacity}";
                },

                
                '/^link-offset-(\d+)(?:-(hover))?$/' => function($m) {
                    $hover = isset($m[2]) ? 'hover:' : '';
                    return "{$hover}underline-offset-{$m[1]}";
                },

                '/^link-(?!(?:underline|opacity|offset|body))(.+)$/' => function($m) {
                    $color = $m[1];
                    if ($color === 'light') return 'text-slate-100 hover:text-slate-300';
                    if ($color === 'dark')  return 'text-slate-900 hover:text-slate-700';
                    return "text-{$color} hover:text-{$color}/80"; 
                },

                '/^link-body-emphasis$/' => 'text-slate-950 font-bold hover:text-slate-950',
                
                '/^link-underline$/' => 'underline',

                '/^bg-(primary|secondary|success|danger|warning|info|light|dark|transparent)$/' => 'bg-{1}',
                '/^bg-gradient$/' => 'bg-gradient-to-b from-white/15 to-transparent',
                '/^bg-body-(.+)$/' => function($m) {
                    $color = $m[1];
                    $bsMap = [
                        // 'secondary' => 'slate-100',
                        // 'tertiary'  => 'slate-200',
                        // 'emphasis'  => 'slate-300',
                    ];
                    if (isset($bsMap[$color])) {
                        return 'bg-' . $bsMap[$color];
                    }
                    return 'bg-' . $color;
                },


                // --- Components: Buttons (Consolidated & Fixed Focus) ---
                '/^btn$/' => [
                    'base' => 'inline-flex items-center justify-center font-medium transition-all duration-200 cursor-pointer select-none px-4 py-2 rounded-md border text-center disabled:opacity-50 disabled:pointer-events-none',
                    'states' => [
                        'focus' => 'outline-none! ring-2 ring-ring/50 ring-offset-0 z-10',
                        'active' => 'transform scale-95',
                    ],
                ],
                '/^btn-xs$/' => 'px-2 py-1 text-xs rounded-sm',
                '/^btn-sm$/' => 'px-3 py-1.5 text-sm rounded',
                '/^btn-lg$/' => 'px-6 py-3 text-lg rounded-lg',
                '/^btn-xl$/' => 'px-8 py-4 text-xl rounded-xl',
                
                '/^btn-soft$/'    => 'border-transparent',
                '/^btn-dash$/'    => 'bg-transparent border-2 border-dashed',
                '/^btn-ghost$/'   => 'bg-transparent border-transparent hover:bg-opacity-20',
                '/^btn-link$/'    => 'bg-transparent border-transparent underline hover:bg-transparent text-primary',
                
                // Solid Button Colors
                '/^btn-(primary|secondary|accent|info|success|warning|error|danger|neutral|dark|light)$/' => [
                    'base' => 'bg-{1} text-white border-{1} shadow-sm',
                    'states' => [
                        'hover' => 'opacity-90',
                        'focus' => 'ring-2 ring-{1}/50 ring-offset-0',
                        'active' => 'opacity-100', // Removed shadow-inner to prevent ring override
                    ],
                ],

                // btn-outline overrides solid backgrounds
                '/^btn-outline$/' => 'bg-transparent! border-2 shadow-none',
                '/^btn-outline-(primary|secondary|accent|info|success|warning|error|danger|neutral|dark|light)$/' => [
                    'base' => 'bg-transparent! text-{1} border-{1} hover:bg-{1} hover:text-white',
                    'states' => [
                        'focus' => 'ring-2 ring-{1}/50 ring-offset-0',
                    ],
                    'important' => ['background-color']
                ],
                
                '/^btn-soft-(primary|secondary|accent|info|success|warning|error|danger|neutral)$/' => 'bg-{1}/20 text-{1} hover:bg-{1}/30',
                
                // Shapes
                '/^btn-square$/' => 'p-0 w-10 h-10',
                '/^btn-circle$/' => 'p-0 w-10 h-10 rounded-full',
                '/^btn-block$/'  => 'w-full',
                '/^btn-wide$/'   => 'px-16',
                '/^btn-group-vertical$/' => 'inline-flex flex-col [&>*:not(:first-child)]:-mt-px [&>*:not(:first-child)]:rounded-t-none [&>*:not(:last-child)]:rounded-b-none',
                


                // --- Components: Alerts ---
                '/^alert-(primary|secondary|accent|success|danger|error|warning|info|light|dark)$/' => [
                    'base' => 'bg-{1}/10 border-{1}/20 text-{1}-800',
                    'pseudo' => [
                        ' a' => 'text-{1}-900 font-bold',
                        ' .alert-heading' => 'text-{1}-900',
                    ],
                    // ডার্ক মোডের জন্য বিশেষ স্টাইল
                    'dark' => [
                        'base' => 'dark:bg-{1}/20 dark:border-{1}/30 dark:text-{1}-200',
                        'pseudo' => [
                            ' a' => 'dark:text-{1}-100',
                            ' .alert-heading' => 'dark:text-{1}-100',
                        ]
                    ]
                ],

                // --- Components: Progress (daisyUI style) ---
                '/^progress$/' => 'relative w-full appearance-none overflow-hidden h-2 rounded-full bg-muted',
                '/^progress-(primary|secondary|accent|success|warning|info|error)$/' => [
                    'base' => 'text-{1}',
                    'pseudo' => [
                        '::-webkit-progress-value' => 'bg-{1}',
                        '::-moz-progress-bar' => 'bg-{1}',
                    ],
                ],

                
                // --- Components: Skeleton (daisyUI style) ---
                '/^skeleton$/' => 'cursor-default bg-muted animate-pulse rounded-md min-h-[1em] w-full',
                '/^skeleton-text$/' => 'skeleton h-4 w-full mb-2 last:w-3/4',
                '/^skeleton-avatar$/' => 'skeleton h-12 w-12 rounded-full',
                '/^skeleton-rect$/' => 'skeleton h-32 w-full',



                // --- Components: Badges ---
                '/^badge text-bg-([a-z]+)$/' => [
                    'base' => 'bg-{1} text-white px-2 py-0.5 rounded text-xs font-semibold',
                ],
                '/^bg-(primary|secondary|success|danger|warning|info|light|dark)$/' => 'bg-{1}',

                // --- Borders ---
                '/^border-(primary|secondary|success|danger|warning|info|light|dark|white)$/' => 'border-{1}',
                '/^border-(top|bottom|start|end)-0$/' => 'border-{d_map_bs_axis:{1}}-0',
                
                // --- Utilities ---
                // BS spacing to TW spacing mapping (0->0, 1->1, 2->2, 3->4, 4->6, 5->8)
                '/^bs-(m|p)-([0-5])$/' => '{1}-{d_map_spacing:{2}}',
                '/^bs-(m|p)([tbselhxy])-([0-5]|auto)$/' => '{1}{d_map_bs_axis:{2}}-{d_map_spacing:{3}}',
                '/^visible$/'   => ['base' => 'visibility: visible !important'],
                '/^invisible$/' => ['base' => 'visibility: hidden !important'],

                // === FLEXBOX UTILITIES (COMPLETE & RESPONSIVE) ===

                // 1. Flex Direction (row, row-reverse, column, column-reverse)
                // .flex-row, .flex-sm-row-reverse, .flex-md-column, etc.
                '/^flex(?:-(sm|md|lg|xl|xxl))?-(row|row-reverse|column|column-reverse)$/' => function($m) {
                    $bp = !empty($m[1]) ? $m[1].':' : '';
                    $dir = str_replace('column', 'col', $m[2]); // column -> col
                    return "{$bp}flex-{$dir}";
                },

                // 2. Justify Content (start, end, center, between, around, evenly)
                // .justify-content-start, .justify-content-md-between
                '/^justify-content(?:-(sm|md|lg|xl|xxl))?-(start|end|center|between|around|evenly)$/' => function($m) {
                    $bp = !empty($m[1]) ? $m[1].':' : '';
                    $val = match($m[2]) {
                        'start' => 'start', 'end' => 'end', 'center' => 'center',
                        'between' => 'between', 'around' => 'around', 'evenly' => 'evenly',
                        default => 'start'
                    };
                    return "{$bp}justify-{$val}";
                },

                // 3. Align Items (start, end, center, baseline, stretch)
                // .align-items-center, .align-items-lg-stretch
                '/^align-items(?:-(sm|md|lg|xl|xxl))?-(start|end|center|baseline|stretch)$/' => function($m) {
                    $bp = !empty($m[1]) ? $m[1].':' : '';
                    return "{$bp}items-{$m[2]}";
                },

                // 4. Align Self (start, end, center, baseline, stretch)
                // .align-self-end, .align-self-xl-center
                '/^align-self(?:-(sm|md|lg|xl|xxl))?-(auto|start|end|center|baseline|stretch)$/' => function($m) {
                    $bp = !empty($m[1]) ? $m[1].':' : '';
                    return "{$bp}self-{$m[2]}";
                },

                // 5. Align Content (start, end, center, between, around, stretch)
                // .align-content-start, .align-content-sm-between
                '/^align-content(?:-(sm|md|lg|xl|xxl))?-(start|end|center|between|around|stretch)$/' => function($m) {
                    $bp = !empty($m[1]) ? $m[1].':' : '';
                    return "{$bp}content-{$m[2]}";
                },

                // 6. Flex Wrap (wrap, nowrap, wrap-reverse)
                // .flex-wrap, .flex-md-nowrap
                '/^flex(?:-(sm|md|lg|xl|xxl))?-(wrap|nowrap|wrap-reverse)$/' => function($m) {
                    $bp = !empty($m[1]) ? $m[1].':' : '';
                    return "{$bp}flex-{$m[2]}";
                },

                // 7. Flex Fill (flex: 1 1 auto)
                // .flex-fill, .flex-md-fill
                '/^flex(?:-(sm|md|lg|xl|xxl))?-fill$/' => function($m) {
                    $bp = !empty($m[1]) ? $m[1].':' : '';
                    return "{$bp}flex-auto {$bp}w-full"; // Closest equivalent
                },

                // 8. Flex Grow & Shrink (0 or 1)
                // .flex-grow-1, .flex-sm-shrink-0
                '/^flex(?:-(sm|md|lg|xl|xxl))?-(grow|shrink)-([01])$/' => function($m) {
                    $bp = !empty($m[1]) ? $m[1].':' : '';
                    $type = $m[2]; // grow or shrink
                    $val = $m[3];  // 0 or 1
                    
                    // Tailwind: grow, grow-0, shrink, shrink-0
                    // BS: flex-grow-1 -> grow, flex-grow-0 -> grow-0
                    $twVal = ($val === '1') ? '' : '-0';
                    return "{$bp}{$type}{$twVal}";
                },

                // 9. Order (0-5, first, last)
                // .order-1, .order-md-5, .order-first
                '/^order(?:-(sm|md|lg|xl|xxl))?-(first|last|[0-5])$/' => function($m) {
                    $bp = !empty($m[1]) ? $m[1].':' : '';
                    $val = $m[2];
                    return "{$bp}order-{$val}";
                },

                '/^(sm|md|lg|xl|xxl):join-horizontal$/' => '{1}:flex-row',
                '/^(sm|md|lg|xl|xxl):join-vertical$/'   => '{1}:flex-col',


            ],
            'corePlugins' => [
                'preflight' => true,
                'container' => true,
                'minify' => true,
                'bs2tw' => true,
            ]
        ];
    }

    private function getValidCssColorKeywords(): array {
        // A subset of common CSS color keywords.
        // You can expand this list based on CSS specifications.
        return [
            'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure',
            'beige', 'bisque', 'black', 'blanchedalmond', 'blue',
            'blueviolet', 'brown', 'burlywood', 'cadetblue', 'chartreuse',
            'chocolate', 'coral', 'cornflowerblue', 'cornsilk', 'crimson',
            'cyan', 'darkblue', 'darkcyan', 'darkgoldenrod', 'darkgray',
            'darkgreen', 'darkgrey', 'darkkhaki', 'darkmagenta',
            'darkolivegreen', 'darkorange', 'darkorchid', 'darkred',
            'darksalmon', 'darkseagreen', 'darkslateblue', 'darkslategray',
            'darkslategrey', 'darkturquoise', 'darkviolet', 'deeppink',
            'deepskyblue', 'dimgray', 'dimgrey', 'dodgerblue', 'firebrick',
            'floralwhite', 'forestgreen', 'fuchsia', 'gainsboro',
            'ghostwhite', 'gold', 'goldenrod', 'gray', 'green',
            'greenyellow', 'grey', 'honeydew', 'hotpink', 'indianred',
            'indigo', 'ivory', 'khaki', 'lavender', 'lavenderblush',
            'lawngreen', 'lemonchiffon', 'lightblue', 'lightcoral',
            'lightcyan', 'lightgoldenrodyellow', 'lightgray', 'lightgreen',
            'lightgrey', 'lightpink', 'lightsalmon', 'lightseagreen',
            'lightskyblue', 'lightslategray', 'lightslategrey',
            'lightsteelblue', 'lightyellow', 'lime', 'limegreen', 'linen',
            'magenta', 'maroon', 'mediumaquamarine', 'mediumblue',
            'mediumorchid', 'mediumpurple', 'mediumseagreen',
            'mediumslateblue', 'mediumspringgreen', 'mediumturquoise',
            'mediumvioletred', 'midnightblue', 'mintcream', 'mistyrose',
            'moccasin', 'navajowhite', 'navy', 'oldlace', 'olive',
            'olivedrab', 'orange', 'orangered', 'orchid', 'palegoldenrod',
            'palegreen', 'paleturquoise', 'palevioletred', 'papayawhip',
            'peachpuff', 'peru', 'pink', 'plum', 'powderblue', 'purple',
            'rebeccapurple', 'red', 'rosybrown', 'royalblue', 'saddlebrown',
            'salmon', 'sandybrown', 'seagreen', 'seashell', 'sienna',
            'silver', 'skyblue', 'slateblue', 'slategray', 'slategrey',
            'snow', 'springgreen', 'steelblue', 'tan', 'teal', 'thistle',
            'tomato', 'transparent', 'turquoise', 'violet', 'wheat', 'white',
            'whitesmoke', 'yellow', 'yellowgreen', 'currentcolor' // Added currentcolor
        ];
    }
    
    private function getPseudoMap(): array {
        return [
            'hover' => ':hover', 'focus' => ':focus', 'focusWithin'  => ':focus-within',
            'focusVisible' => ':focus-visible', 'active' => ':active', 'visited' => ':visited',
            'disabled' => ':disabled', 'checked' => ':checked', 'first' => ':first-child',
            'last'  => ':last-child', 'odd' => ':nth-child(odd)', 'even'  => ':nth-child(even)',
            'only'  => ':only-child', 'before' => '::before', 'after' => '::after',
            'placeholder' => '::placeholder', 'marker' => '::marker', 'selection' => '::selection',
            'hoverBefore' => ':hover::before', 'hoverAfter'  => ':hover::after',
            'focusBefore' => ':focus::before', 'focusAfter'  => ':focus::after',
        ];
    }

    public function registerUtilityHandler(string $pattern, callable|string $handlerMethod, int $priority = 0): void {
        $this->utilityHandlers[] = ['pattern' => $pattern, 'handler' => $handlerMethod, 'priority' => $priority];
    }

    private function initializeUtilityHandlers(): void {
        $handlers = [
            ['pattern' => '/^debug-ui$/', 'handler' => 'handleDebugUi', 'priority' => 1],
            // Layout
            ['pattern' => '/^container-type-([a-z]+)$/', 'handler' => 'handleContainerType', 'priority' => 5],
            ['pattern' => '/^container-name-([a-zA-Z0-9-]+)$/', 'handler' => 'handleContainerName', 'priority' => 5],
            ['pattern' => '/^container(?:-(sm|md|lg|xl|2xl|xxl|fluid))?(?:-\[(.+)\])?$/', 'handler' => 'handleContainer', 'priority' => 6],
            ['pattern' => '/^(absolute|relative|fixed|static|sticky)$/', 'handler' => 'handlePosition'],
            ['pattern' => '/^(hidden|block|inline-block|inline|flex|inline-flex|grid|inline-grid|contents|table|table-(caption|column|column-group|footer-group|header-group|row|row-group)|flow-root)$/', 'handler' => 'handleDisplay', 'priority' => 10],
            ['pattern' => '/^(top|right|bottom|left|inset(?:-[xy])?)-(.+)/', 'handler' => 'handlePositionPlacement'],
            ['pattern' => '/^-(top|right|bottom|left|inset(?:-[xy])?)-(.+)/', 'handler' => 'handlePositionPlacement'], // Negative
            ['pattern' => '/^-?z-(.+)$/', 'handler' => 'handleZIndex', 'priority' => 10],
            ['pattern' => '/^outline-(none|white|black|transparent|current)$/', 'handler' => 'handleOutlineStyleOrColorKeyword'], // For outline-none and basic color keywords
            ['pattern' => '/^outline(?:-(solid|dashed|dotted|double))?$/', 'handler' => 'handleOutlineStyle'], // outline, outline-solid
            ['pattern' => '/^outline-offset-(.+)/', 'handler' => 'handleOutlineOffset'],
            ['pattern' => '/^outline-(.+)/', 'handler' => 'handleOutlineColorOrWidth'], // outline-red-500 or outline-2 or outline-[color]
            ['pattern' => '/^overflow-([xy]-)?(auto|hidden|clip|visible|scroll)$/', 'handler' => 'handleOverflow', 'priority' => 10],
            ['pattern' => '/^offset(?:-(sm|md|lg|xl|xxl))?-(\d{1,2})$/', 'handler' => 'handleOffset', 'priority' => 100],
            ['pattern' => '/^box-decoration-(slice|clone)$/', 'handler' => 'handleBoxDecorationBreak', 'priority' => 10],

            // Flexbox & Grid
            ['pattern' => '/^flex-(row|row-reverse|col|col-reverse)$/', 'handler' => 'handleFlexDirection'],
            ['pattern' => '/^flex-(wrap|wrap-reverse|nowrap)$/', 'handler' => 'handleFlexWrap'],
            ['pattern' => '/^items-(start|end|center|baseline|stretch)$/', 'handler' => 'handleAlignItems'],
            ['pattern' => '/^justify-(start|end|center|between|around|evenly)$/', 'handler' => 'handleJustifyContent'],
            ['pattern' => '/^content-(start|end|center|between|around|evenly|baseline|stretch)$/', 'handler' => 'handleAlignContent'],
            ['pattern' => '/^self-(auto|start|end|center|stretch|baseline)$/', 'handler' => 'handleAlignSelf'], 
            ['pattern' => '/^justify-self-(auto|start|end|center|stretch)$/', 'handler' => 'handleJustifySelf'], 
            ['pattern' => '/^place-content-(start|end|center|between|around|evenly|baseline|stretch)$/', 'handler' => 'handlePlaceContent'],
            ['pattern' => '/^place-items-(start|end|center|baseline|stretch|auto)$/', 'handler' => 'handlePlaceItems'],
            ['pattern' => '/^place-self-(auto|start|end|center|stretch)$/', 'handler' => 'handlePlaceSelf'],
            ['pattern' => '/^flex-(1|auto|initial|none)$/', 'handler' => 'handleFlex'],
            ['pattern' => '/^grow(?:-(0))?$/', 'handler' => 'handleFlexGrow'], 
            ['pattern' => '/^shrink(?:-(0))?$/', 'handler' => 'handleFlexShrink'],
            ['pattern' => '/^basis-(.+)/', 'handler' => 'handleFlexBasis'],
            ['pattern' => '/^order-(first|last|none|\d+)$/', 'handler' => 'handleOrder'], 
            ['pattern' => '/^order-\[(.+)\]$/', 'handler' => 'handleOrderArbitrary'],
            ['pattern' => '/^gap-([xy]-)?(.+)/', 'handler' => 'handleGap'],
            ['pattern' => '/^grid-cols-(.+)/', 'handler' => 'handleGridTemplateColumns'],
            ['pattern' => '/^grid-rows-(.+)/', 'handler' => 'handleGridTemplateRows'],
            ['pattern' => '/^grid-flow-(row|col|dense|row-dense|col-dense)$/', 'handler' => 'handleGridAutoFlow'],
            ['pattern' => '/^grid-areas-\[(.+)\]$/', 'handler' => 'handleGridTemplateAreas', 'priority' => 10],
            ['pattern' => '/^grid-in-([a-zA-Z0-9_-]+)$/', 'handler' => 'handleGridArea', 'priority' => 10],
            ['pattern' => '/^grid-cols-fluid(?:-(.+))?$/', 'handler' => 'handleGridColsFluid', 'priority' => 9],
            ['pattern' => '/^bs-grid$/', 'handler' => 'handleCssGridContainer', 'priority' => 5],
            ['pattern' => '/^g-col(?:-(sm|md|lg|xl|xxl))?-(\d{1,2})$/', 'handler' => 'handleCssGridColumn', 'priority' => 140],
            ['pattern' => '/^g-start(?:-(sm|md|lg|xl|xxl))?-(\d{1,2})$/', 'handler' => 'handleCssGridStart', 'priority' => 140],
            ['pattern' => '/^g-end(?:-(sm|md|lg|xl|xxl))?-(\d{1,2})$/', 'handler' => 'handleCssGridEnd', 'priority' => 140],
            ['pattern' => '/^g-row(?:-(sm|md|lg|xl|xxl))?-(\d{1,2})$/', 'handler' => 'handleCssGridRow', 'priority' => 135],
            ['pattern' => '/^g-row-(start|end)(?:-(sm|md|lg|xl|xxl))?-(\d{1,2})$/', 'handler' => 'handleCssGridRowPlacement', 'priority' => 135],
            ['pattern' => '/^col-(auto|span-\d+|start-\d+|end-\d+|span-full)$/', 'handler' => 'handleGridColumn'],
            ['pattern' => '/^col-(auto|span-\d+|start-\d+|end-\d+|span-full)$/', 'handler' => 'handleGridColumn'],
            ['pattern' => '/^col(?:-(sm|md|lg|xl|xxl))?(?:-(\d{1,2}|auto))?$/', 'handler' => 'handleColumn', 'priority' => 100],
            ['pattern' => '/^row-(auto|span-\d+|start-\d+|end-\d+|span-full)$/', 'handler' => 'handleGridRow'],
            ['pattern' => '/^row-cols-((sm|md|lg|xl|xxl)-)?(auto|\d+)$/', 'handler' => 'handleRowCols', 'priority' => 110],
            ['pattern' => '/^row$/', 'handler' => 'handleRow', 'priority' => 110],
            ['pattern' => '/^auto-cols-(auto|min|max|fr)$/', 'handler' => 'handleGridAutoColumns'],
            ['pattern' => '/^auto-rows-(auto|min|max|fr)$/', 'handler' => 'handleGridAutoRows'],
            ['pattern' => '/^cols(?:-(sm|md|lg|xl|xxl))?-(\d{1,2})$/', 'handler' => 'handleGridColumnCount', 'priority' => 150],
            ['pattern' => '/^rows(?:-(sm|md|lg|xl|xxl))?-(\d{1,2})$/', 'handler' => 'handleGridRowCount', 'priority' => 150],
            ['pattern' => '/^align-(baseline|top|middle|bottom|text-top|text-bottom|sub|super)$/', 'handler' => 'handleVerticalAlign', 'priority' => 10],
            ['pattern' => '/^object(?:-fit)?(?:-(sm|md|lg|xl|xxl))?-(contain|cover|fill|scale|scale-down|none)$/', 'handler' => 'handleObjectFit', 'priority' => 10],
            ['pattern' => '/^float(?:-(sm|md|lg|xl|xxl))?-(start|end|none|left|right)$/', 'handler' => 'handleFloat', 'priority' => 10],
            ['pattern' => '/^(fixed|sticky)(?:-(sm|md|lg|xl|xxl))?-(top|bottom)$/', 'handler' => 'handlePositionUtility', 'priority' => 10],
            ['pattern' => '/^clearfix$/', 'handler' => 'handleClearfix', 'priority' => 10],
            ['pattern' => '/^list(?:-(row|col-wrap|col-grow))?$/', 'handler' => 'handleList', 'priority' => 150],
            ['pattern' => '/^stat(?:s)?(?:-(horizontal|vertical))?s?$/', 'handler' => 'handleStat', 'priority' => 150],
            ['pattern' => '/^table-zebra$/', 'handler' => 'handleTableZebra', 'priority' => 150],
            ['pattern' => '/^divider(?:-(.+))?$/', 'handler' => 'handleDivider', 'priority' => 150],
            ['pattern' => '/^stack(?:-(top|bottom|start|end))?$/', 'handler' => 'handleStack', 'priority' => 150],
            ['pattern' => '/^bento-(grid|box)(?:-(.+))?$/', 'handler' => 'handleBento', 'priority' => 150],
            ['pattern' => '/^soft-ui(?:-(inset))?$/', 'handler' => 'handleSoftUi', 'priority' => 150],

            // Spacing
            ['pattern' => '/^(p|pt|pr|pb|pl|ps|pe|px|py|m|mt|mr|mb|ml|ms|me|mx|my)-(.+)/', 'handler' => 'handleSpacing', 'priority' => 20],
            ['pattern' => '/^-(p|pt|pr|pb|pl|ps|pe|px|py|m|mt|mr|mb|ml|ms|me|mx|my)-(.+)/', 'handler' => 'handleSpacing', 'priority' => 20],
            ['pattern' => '/^g([xy])?(?:-(sm|md|lg|xl|xxl))?-([0-5])$/', 'handler' => 'handleGutters', 'priority' => 110],
            ['pattern' => '/^m(s|e|x|t|b|y|l|r)(?:-(sm|md|lg|xl|xxl))?-auto$/', 'handler' => 'handleMarginAuto', 'priority' => 110],
            ['pattern' => '/^space-([xy])-(.+)$/', 'handler' => 'handleSpaceBetween', 'priority' => 15],
            ['pattern' => '/^space-([xy])-reverse$/', 'handler' => 'handleSpaceReverse', 'priority' => 16],
            ['pattern' => '/^d(?:-(sm|md|lg|xl|xxl|print))?-(none|inline|inline-block|block|grid|inline-grid|table|table-row|table-cell|flex|inline-flex)$/', 'handler' => 'handleBootstrapDisplay', 'priority' => 100],

            // Sizing
            ['pattern' => '/^(w|h|min-w|max-w|min-h|max-h)-(.+)/', 'handler' => 'handleSizing'],
            ['pattern' => '/^(min-|max-)?size-(.+)$/', 'handler' => 'handleUnifiedSize'],

            // Typography
            ['pattern' => '/^text-clamp[\(\[](.+)[\)\]]$/', 'handler' => 'handleTextClamp', 'priority' => 2],
            ['pattern' => '/^text-(xs|sm|base|lg|xl|2xl|3xl|4xl|5xl|6xl|7xl|8xl|9xl)$/', 'handler' => 'handleFontSizeKeyword'],
            ['pattern' => '/^text-\(length:(.+)\)$/', 'handler' => 'handleTextLength', 'priority' => 4],
            ['pattern' => '/^text-(left|center|right|justify|start|end)$/', 'handler' => 'handleTextAlign'],
            ['pattern' => '/^text-\[(.+)\]$/', 'handler' => 'handleFontSizeArbitrary'],
            ['pattern' => '/^text-(.+)/', 'handler' => 'handleTextColor'], 
            ['pattern' => '/^font-(thin|extralight|light|normal|medium|semibold|bold|extrabold|black)$/', 'handler' => 'handleFontWeight'],
            ['pattern' => '/^font-(.+)$/', 'handler' => 'handleFontFamily'],
            ['pattern' => '/^leading-(none|tight|snug|normal|relaxed|loose|\d+)$/', 'handler' => 'handleLineHeight'],
            ['pattern' => '/^leading-\[(.+)\]$/', 'handler' => 'handleLineHeightArbitrary'],
            ['pattern' => '/^tracking-(tighter|tight|normal|wide|wider|widest)$/', 'handler' => 'handleLetterSpacingKeyword'],
            ['pattern' => '/^tracking-\[(.+)\]$/', 'handler' => 'handleLetterSpacingArbitrary'],
            ['pattern' => '/^(underline|overline|line-through|no-underline)$/', 'handler' => 'handleTextDecorationLine'],
            ['pattern' => '/^decoration-(solid|double|dotted|dashed|wavy)$/', 'handler' => 'handleTextDecorationStyle'],
            ['pattern' => '/^decoration-(auto|from-font|0|1|2|4|8)$/', 'handler' => 'handleTextDecorationThicknessKeyword'],
            ['pattern' => '/^decoration-\[(.+)\]$/', 'handler' => 'handleTextDecorationThicknessArbitraryOrColor'], 
            ['pattern' => '/^underline-offset-(auto|0|1|2|4|8)$/', 'handler' => 'handleTextUnderlineOffsetKeyword'],
            ['pattern' => '/^underline-offset-\[(.+)\]$/', 'handler' => 'handleTextUnderlineOffsetArbitrary'],
            ['pattern' => '/^(uppercase|lowercase|capitalize|normal-case)$/', 'handler' => 'handleTextTransform'],
            ['pattern' => '/^(truncate|text-ellipsis|text-clip)$/', 'handler' => 'handleTextOverflow'],
            ['pattern' => '/^line-clamp-(none|\d+)$/', 'handler' => 'handleLineClamp', 'priority' => 15],
            ['pattern' => '/^whitespace-(normal|nowrap|pre|pre-line|pre-wrap|break-spaces)$/', 'handler' => 'handleWhitespace'],
            ['pattern' => '/^break-(normal|words|all|keep)$/', 'handler' => 'handleWordBreak'],
            ['pattern' => '/^(not-)?italic$/', 'handler' => 'handleFontStyle', 'priority' => 10],
            ['pattern' => '/^antialiased|subpixel-antialiased$/', 'handler' => 'handleFontSmoothing'],
            ['pattern' => '/^text-transparent$/', 'handler' => 'handleTextTransparent', 'priority' => 5],
            ['pattern' => '/^text-opacity-(.+)/', 'handler' => 'handleTextOpacity', 'priority' => 5],
            ['pattern' => '/^((?:\[.+?\]|[a-zA-Z0-9-]+))-link$/', 'handler' => 'handleLink'],
            ['pattern' => '/^link$/', 'handler' => 'handleLink'],
            ['pattern' => '/^(normal-nums|ordinal|slashed-zero|lining-nums|oldstyle-nums|proportional-nums|tabular-nums|diagonal-fractions|stacked-fractions)$/', 'handler' => 'handleFontVariantNumeric', 'priority' => 10],
            ['pattern' => '/^indent-(.+)$/', 'handler' => 'handleTextIndent', 'priority' => 10],
            ['pattern' => '/^text-(balance|pretty)$/', 'handler' => 'handleTextWrap', 'priority' => 10],
            ['pattern' => '/^text-stroke-(.+)$/', 'handler' => 'handleTextStroke', 'priority' => 11],
            ['pattern' => '/^link-underline-(.+)$/', 'handler' => 'handleLinkUnderlineColor', 'priority' => 15],
            ['pattern' => '/^input-group$/', 'handler' => 'handleInputGroup', 'priority' => 120],
            ['pattern' => '/^has-validation$/', 'handler' => 'handleHasValidation', 'priority' => 120],
            ['pattern' => '/^fab(?:-(flower))?$/', 'handler' => 'handleFab', 'priority' => 150],
            ['pattern' => '/^swap(?:-(rotate|flip|active))?$/', 'handler' => 'handleSwap', 'priority' => 150],
            ['pattern' => '/^badge(?:-(.+))?$/', 'handler' => 'handleBadge', 'priority' => 150],
            ['pattern' => '/^chat(?:-(start|end|image|header|footer|bubble))?(?:-(primary|secondary|accent|info|success|warning|error|neutral))?$/', 'handler' => 'handleChat', 'priority' => 150],
            ['pattern' => '/^kbd(?:-(xs|sm|md|lg|xl))?$/', 'handler' => 'handleKbd', 'priority' => 150],
            
            // Backgrounds
            ['pattern' => '/^bg-\[url\(([\'"]?)(?P<url>.+?)\1\)\]$/', 'handler' => 'handleBackgroundImage', 'priority' => 2],
            ['pattern' => '/^bg-\[url\((.+)\]$/', 'handler' => 'handleBackgroundImageUrl', 'priority' => 2],
            ['pattern' => '/^bg-img\[([\'"]?)(.+?)\1\]$/', 'handler' => 'handleBackgroundImage', 'priority' => 15],
            ['pattern' => '/^bg-\[(.+gradient\(.+)\]$/', 'handler' => 'handleBackgroundArbitraryGradient', 'priority' => 3],
            ['pattern' => '/^bg-glass(?:-(.+))?$/', 'handler' => 'handleGlassBackground', 'priority' => 4],
            ['pattern' => '/^bg-mesh-([a-zA-Z0-9-]+?)(?:-(light|dark))?$/', 'handler' => 'handleMeshBackground', 'priority' => 5],
            ['pattern' => '/^bg-mesh-(.+)$/', 'handler' => 'handleMeshBackground', 'priority' => 5],
            ['pattern' => '/^bg-clip-(border|padding|content|text)$/', 'handler' => 'handleBackgroundClip', 'priority' => 8],
            ['pattern' => '/^bg-origin-(border|padding|content)$/', 'handler' => 'handleBackgroundOrigin', 'priority' => 8],
            ['pattern' => '/^bg-(fixed|local|scroll)$/', 'handler' => 'handleBackgroundAttachment', 'priority' => 10],
            ['pattern' => '/^bg-(no-repeat|repeat|repeat-x|repeat-y|repeat-round|repeat-space)$/', 'handler' => 'handleBackgroundRepeat', 'priority' => 10],
            ['pattern' => '/^bg-(bottom|center|left|right|top|left-bottom|left-top|right-bottom|right-top)$/', 'handler' => 'handleBackgroundPosition', 'priority' => 10],
            ['pattern' => '/^bg-(auto|cover|contain)$/', 'handler' => 'handleBackgroundSize', 'priority' => 10],
            ['pattern' => '/^bg-gradient-to-([rltb]{1,2})$/', 'handler' => 'handleGradientDirection', 'priority' => 15],
            ['pattern' => '/^bg-(grid|dots)(?:-(.+))?$/', 'handler' => 'handleBgPatterns', 'priority' => 15],
            ['pattern' => '/^(from|via|to)-(.+?)(?:\/(?:(\d{1,3})(?!%)|\[\.?(\d+)\]))?(?:-(\d+%))?$/', 'handler' => 'handleGradientColorStop', 'priority' => 16],
            ['pattern' => '/^(from|via|to)-(.+)$/', 'handler' => 'handleGradientColorStop', 'priority' => 16],
            ['pattern' => '/^bg-blend-(normal|multiply|screen|overlay|darken|lighten|color-dodge|color-burn|hard-light|soft-light|difference|exclusion|hue|saturation|color|luminosity)$/', 'handler' => 'handleBackgroundBlendMode', 'priority' => 25],
            ['pattern' => '/^bg-blend-(.+)$/', 'handler' => 'handleBackgroundBlendMode', 'priority' => 25],
            ['pattern' => '/^bg-opacity-(.+)$/', 'handler' => 'handleBackgroundOpacity', 'priority' => 26],
            ['pattern' => '/^bg-retro-grid$/', 'handler' => 'handleRetroGridBackground', 'priority' => 5],
            ['pattern' => '/^gradient-blobs$/', 'handler' => 'handleGradientBlobs', 'priority' => 5],
            ['pattern' => '/^bg-(.+)$/', 'handler' => 'handleBackgroundColor', 'priority' => 30],

            // Borders
            ['pattern' => '/^border(?:-(t|r|b|l|x|y|s|e))?(?:-(.+))?$/', 'handler' => 'handleBorder', 'priority' => 10], 
            ['pattern' => '/^border-(solid|dashed|dotted|double|hidden|none)$/', 'handler' => 'handleBorderStyle'],
            ['pattern' => '/^border-gradient(?:-to-([rltb]{1,2}))?$/', 'handler' => 'handleBorderGradient', 'priority' => 18],
            ['pattern' => '/^border-conic-glow(.*)$/', 'handler' => 'handleBorderConicGlow', 'priority' => 15],
            ['pattern' => '/^rounded(?:-(t|r|b|l|s|e|ss|se|es|ee|tl|tr|br|bl))?(?:-(.+))?$/', 'handler' => 'handleBorderRadius'],
            ['pattern' => '/^divide-([xy])(?:-(reverse))?(?:-(.+))?$/', 'handler' => 'handleDivideWidthOrColor'],
            ['pattern' => '/^divide-(solid|dashed|dotted|double|none)$/', 'handler' => 'handleDivideStyle'],

            // Glassmorphism
            ['pattern' => '/^glass-effect(?:-(.+))?$/', 'handler' => 'handleGlassEffect', 'priority' => 30],
            ['pattern' => '/^glass-glow(?:-(.+))?$/', 'handler' => 'handleGlassGlow', 'priority' => 20],
            ['pattern' => '/^glass-tilt$/', 'handler' => 'handleGlassTilt', 'priority' => 20],
            ['pattern' => '/^glass-noise$/', 'handler' => 'handleGlassNoise', 'priority' => 20],

            // Effects
            ['pattern' => '/^shadow(?:-(.+))?$/', 'handler' => 'handleBoxShadow'],
            ['pattern' => '/^opacity-(.+)/', 'handler' => 'handleOpacity'],
            ['pattern' => '/^mix-blend-(.+)/', 'handler' => 'handleMixBlendMode'], 
            ['pattern' => '/^bg-blend-(.+)/', 'handler' => 'handleBackgroundBlendMode'],
            ['pattern' => '/^(shine|glow|glow-bright|pulse)-(.+)$/', 'handler' => 'handleCornerGlow', 'priority' => 15],

            // Filters
            ['pattern' => '/^blur(?:-(.+))?$/', 'handler' => 'handleBlur'],
            ['pattern' => '/^brightness-(.+)/', 'handler' => 'handleBrightness'],
            ['pattern' => '/^contrast-(.+)/', 'handler' => 'handleContrast'],
            ['pattern' => '/^drop-shadow(?:-(.+))?$/', 'handler' => 'handleDropShadow'],
            ['pattern' => '/^grayscale(?:-(0|DEFAULT))?$/', 'handler' => 'handleGrayscale'], 
            ['pattern' => '/^hue-rotate-(.+)/', 'handler' => 'handleHueRotate'],
            ['pattern' => '/^invert(?:-(0|DEFAULT))?$/', 'handler' => 'handleInvert'],
            ['pattern' => '/^saturate-(.+)/', 'handler' => 'handleSaturate'],
            ['pattern' => '/^sepia(?:-(0|DEFAULT))?$/', 'handler' => 'handleSepia'],
            ['pattern' => '/^(blur|brightness|contrast|grayscale|hue-rotate|invert|saturate|sepia|drop-shadow)(?:-(.+))?$/', 'handler' => 'handleFilter', 'priority' => 15],
            ['pattern' => '/^backdrop-(blur|brightness|contrast|grayscale|hue-rotate|invert|opacity|saturate|sepia)(?:-(.+))?$/', 'handler' => 'handleBackdropFilter', 'priority' => 15],
            ['pattern' => '/^mask-(.+)$/', 'handler' => 'handleMasking', 'priority' => 15],
            ['pattern' => '/^isolation-(isolate|auto)$/', 'handler' => 'handleIsolation', 'priority' => 15],
            ['pattern' => '/^filter-url-\[(.+)\]$/', 'handler' => 'handleFilterUrl', 'priority' => 15],
            ['pattern' => '/^mask(?:-(.+))?$/', 'handler' => 'handleMask', 'priority' => 150],

            // Transforms
            ['pattern' => '/^-?(translate-[xy])-(.+)$/', 'handler' => 'handleTransformTranslate', 'priority' => 25],
            ['pattern' => '/^-?rotate-(.+)$/', 'handler' => 'handleTransformRotate', 'priority' => 25],
            ['pattern' => '/^-?skew-[xy]-(.+)$/', 'handler' => 'handleTransformSkew', 'priority' => 25],
            ['pattern' => '/^scale(?:-([xy]))?(?:-(.+))?$/', 'handler' => 'handleTransformScale', 'priority' => 25],
            ['pattern' => '/^origin-([a-z-]+(?:-[a-z-]+)?)$/', 'handler' => 'handleTransformOrigin', 'priority' => 26],
            ['pattern' => '/^perspective-(.+)$/', 'handler' => 'handlePerspective', 'priority' => 26],
            ['pattern' => '/^perspective-origin-(.+)$/', 'handler' => 'handlePerspectiveOrigin', 'priority' => 26],
            ['pattern' => '/^backface-(visible|hidden)$/', 'handler' => 'handleBackfaceVisibility', 'priority' => 26],
            ['pattern' => '/^transform(?:-(gpu|none))?$/', 'handler' => 'handleTransformBase', 'priority' => 29],

            // Animations
            ['pattern' => '/^(?:animate|anm)-([a-z-]+(?:\[.+\])?)$/', 'handler' => 'handleAnimationProperty', 'priority' => 40],
            ['pattern' => '/^(?:animate|anm)-(infinite|forwards|backwards|both|running|paused|normal|reverse|alternate|alternate-reverse)$/', 'handler' => 'handleAnimationKeywords', 'priority' => 42],
            ['pattern' => '/^(?:animate|anm)-(.+)$/', 'handler' => 'handleAnimation', 'priority' => 45],
            ['pattern' => '/^animate-text-gradient$/', 'handler' => 'handleTextGradientAnim', 'priority' => 45],
            ['pattern' => '/^animate-marquee$/', 'handler' => 'handleMarquee', 'priority' => 45],
            ['pattern' => '/^animate-typing(?:-\[(.+?)\])?$/', 'handler' => 'handleTypingAnim', 'priority' => 45],
            ['pattern' => '/^view-transition-\[(.+)\]$/', 'handler' => 'handleViewTransition', 'priority' => 10],
            ['pattern' => '/^border-beam$/', 'handler' => 'handleBorderBeam', 'priority' => 150],
            ['pattern' => '/^animate-glitch$/', 'handler' => 'handleGlitch', 'priority' => 45],
            ['pattern' => '/^bg-starfield$/', 'handler' => 'handleStarfield', 'priority' => 15],
            ['pattern' => '/^animate-reveal-(up|down|left|right)$/', 'handler' => 'handleRevealAnim', 'priority' => 45],
            ['pattern' => '/^bg-grain$/', 'handler' => 'handleBgGrain', 'priority' => 10],
            ['pattern' => '/^btn-shine$/', 'handler' => 'handleBtnShine', 'priority' => 150],
            ['pattern' => '/^text-glimmer$/', 'handler' => 'handleTextGlimmer', 'priority' => 45],
            ['pattern' => '/^card-spotlight$/', 'handler' => 'handleCardSpotlight', 'priority' => 150],

            // Transitions
            ['pattern' => '/^transition(?:-(.+))?$/', 'handler' => 'handleTransitionProperty', 'priority' => 30],
            ['pattern' => '/^duration-(.+)$/', 'handler' => 'handleTransitionDuration', 'priority' => 31],
            ['pattern' => '/^ease-(.+)$/', 'handler' => 'handleTransitionTimingFunction', 'priority' => 31],
            ['pattern' => '/^delay-(.+)$/', 'handler' => 'handleTransitionDelay', 'priority' => 31],

            // Interactivity
            ['pattern' => '/^appearance-none$/', 'handler' => 'handleAppearance'],
            ['pattern' => '/^accent-(.+)/', 'handler' => 'handleAccentColor'],
            ['pattern' => '/^cursor-([a-zA-Z0-9-]+)$/', 'handler' => 'handleCursor'], 
            ['pattern' => '/^pointer-events-(none|auto)$/', 'handler' => 'handlePointerEvents'],
            ['pattern' => '/^resize(?:-(x|y|none))?$/', 'handler' => 'handleResize'],
            ['pattern' => '/^scroll-smooth$/', 'handler' => 'handleScrollBehavior'],
            ['pattern' => '/^scroll-m([trblxyse]?)-(.+)/', 'handler' => 'handleScrollMargin'], 
            ['pattern' => '/^scroll-p([trblxyse]?)-(.+)/', 'handler' => 'handleScrollPadding'],
            ['pattern' => '/^select-(none|text|all|auto)$/', 'handler' => 'handleUserSelect'],
            ['pattern' => '/^will-change-(auto|scroll|contents|transform)$/', 'handler' => 'handleWillChange'],
            ['pattern' => '/^touch-(auto|none|pan-x|pan-y|pan-left|pan-right|pan-up|pan-down|pinch-zoom|manipulation)$/', 'handler' => 'handleTouchAction'],
            ['pattern' => '/^snap-(none|x|y|both)$/', 'handler' => 'handleScrollSnapType'],
            ['pattern' => '/^snap-mandatory$/', 'handler' => 'handleScrollSnapStrictness'],
            ['pattern' => '/^snap-proximity$/', 'handler' => 'handleScrollSnapStrictness'],
            ['pattern' => '/^snap-(start|end|center|align-none)$/', 'handler' => 'handleScrollSnapAlign'],
            ['pattern' => '/^spinner-(hide|show)$/', 'handler' => 'handleInputSpinner', 'priority' => 10],
            ['pattern' => '/^join(?:-(vertical|horizontal))?$/', 'handler' => 'handleJoin', 'priority' => 160],
            ['pattern' => '/^collapse(?:-(arrow|plus|open|close))?$/', 'handler' => 'handleCollapse', 'priority' => 150],
            ['pattern' => '/^breadcrumbs$/', 'handler' => 'handleBreadcrumbs', 'priority' => 150],
            ['pattern' => '/^dock(?:-(xs|sm|md|lg|xl|label|active))?$/', 'handler' => 'handleDock', 'priority' => 150],
            ['pattern' => '/^menu(?:-(xs|sm|md|lg|xl|horizontal|vertical|title|disabled|active|focus))?s?$/', 'handler' => 'handleMenu', 'priority' => 150],
            ['pattern' => '/^tab(?:s)?(?:-(.+))?$/', 'handler' => 'handleTabs', 'priority' => 150],
            ['pattern' => '/^navbar(?:-(start|center|end))?$/', 'handler' => 'handleNavbar', 'priority' => 150],
            ['pattern' => '/^tooltip(?:-(.+))?$/', 'handler' => 'handleTooltip', 'priority' => 150],
            ['pattern' => '/^drawer(?:-(.+))?$/', 'handler' => 'handleDrawer', 'priority' => 150],
            ['pattern' => '/^hero(?:-(.+))?$/', 'handler' => 'handleHero', 'priority' => 150],
            ['pattern' => '/^btm-nav(?:-(.+))?$/', 'handler' => 'handleBtmNav', 'priority' => 150],
            ['pattern' => '/^file-input(?:-(.+))?$/', 'handler' => 'handleFileInput', 'priority' => 150],
            ['pattern' => '/^timeline(?:-(.+))?$/', 'handler' => 'handleTimeline', 'priority' => 150],
            ['pattern' => '/^mockup-(browser|window|phone)$/', 'handler' => 'handleMockup', 'priority' => 150],
            ['pattern' => '/^artboard(?:-(.+))?$/', 'handler' => 'handleArtboard', 'priority' => 150],
            ['pattern' => '/^diff$/', 'handler' => 'handleDiff', 'priority' => 150],
            ['pattern' => '/^theme-controller$/', 'handler' => 'handleThemeController', 'priority' => 150],
            ['pattern' => '/^label(?:-(.+))?$/', 'handler' => 'handleLabel', 'priority' => 150],
            ['pattern' => '/^scrollbar(?:-(thin|none|thumb|track))?(?:-(.+))?$/', 'handler' => 'handleScrollbar', 'priority' => 10],
            ['pattern' => '/^scrollbar-hide$/', 'handler' => 'handleScrollbarHide', 'priority' => 10],
            ['pattern' => '/^steps(?:-(vertical|horizontal))?$/', 'handler' => 'handleStepsContainer', 'priority' => 150],
            ['pattern' => '/^step(?:-([a-zA-Z0-9-]+))?$/', 'handler' => 'handleStepItem', 'priority' => 140],
            ['pattern' => '/^loading(?:-(spinner|dots|ring|ball|bars|infinity|xs|sm|md|lg|xl))?$/', 'handler' => 'handleLoading', 'priority' => 150],
            ['pattern' => '/^radial-progress(?:-(xs|sm|md|lg|xl))?$/', 'handler' => 'handleRadialProgress', 'priority' => 150],
            ['pattern' => '/^skeleton(?:-(text))?$/', 'handler' => 'handleSkeleton', 'priority' => 150],
            ['pattern' => '/^indicator(?:-(item|start|center|end|top|middle|bottom))?$/', 'handler' => 'handleIndicator', 'priority' => 150],
            ['pattern' => '/^hover-lift$/', 'handler' => 'handleHoverLift', 'priority' => 150],

            // Components
            ['pattern' => '/^carousel(?:-(.+))?$/', 'handler' => 'handleCarousel', 'priority' => 150],
            ['pattern' => '/^toast(?:-(.+))?$/', 'handler' => 'handleToastPos', 'priority' => 150],
            
            // Utilities
            ['pattern' => '/^columns-(.+)$/', 'handler' => 'handleColumns', 'priority' => 10],
            ['pattern' => '/^break-(inside|before|after)-(auto|avoid|all|page|left|right|column)$/', 'handler' => 'handleBreak', 'priority' => 10],

            // SVG
            ['pattern' => '/^fill-(.+)/', 'handler' => 'handleFill'],
            ['pattern' => '/^stroke-(.+)/', 'handler' => 'handleSvgStroke'], 
            ['pattern' => '/^stroke-dasharray-(.+)/', 'handler' => 'handleStrokeDashArray'], // Basic
            ['pattern' => '/^stroke-dashoffset-(.+)/', 'handler' => 'handleStrokeDashOffset'], // Basic

            // SVG Icons
            ['pattern' => '/^icon-(.+)$/', 'handler' => 'handleIcon', 'priority' => 10],
            ['pattern' => '/^status(?:-(.+))?$/', 'handler' => 'handleStatus', 'priority' => 150],

            // Accessibility
            ['pattern' => '/^sr-only|not-sr-only|screen-reader-only$/', 'handler' => 'handleAccessibility'],
            ['pattern' => '/^forced-color-adjust-(none|auto)$/', 'handler' => 'handleForcedColorAdjust'],
            
            // SCROLL REVEAL UTILITIES
            ['pattern' => '/^reveal-(fade|up|down|left|right)$/', 'handler' => 'handleScrollReveal', 'priority' => 30],

            // Aspect Ratio
            ['pattern' => '/^aspect-(square|video)$/', 'handler' => 'handleAspectRatio', 'priority' => 10],
            ['pattern' => '/^aspect-(.+)/', 'handler' => 'handleAspectRatio'],

            // Caret Color
            ['pattern' => '/^caret-(.+)$/', 'handler' => 'handleCaretColor', 'priority' => 10],

            // Hyphens
            ['pattern' => '/^hyphens-(none|manual|auto)$/', 'handler' => 'handleHyphens', 'priority' => 10],

            // Ring utilities
            ['pattern' => '/^ring-inset$/', 'handler' => 'handleRingInset', 'priority' => 18],
            ['pattern' => '/^ring-offset-(.+)$/', 'handler' => 'handleRingOffset', 'priority' => 19],
            ['pattern' => '/^ring-opacity-(.+)$/', 'handler' => 'handleRingOpacity', 'priority' => 20],
            ['pattern' => '/^ring(?:-(.+))?$/', 'handler' => 'handleRingWidthAndColor', 'priority' => 21],
            ['pattern' => '/^focus-ring(?:-(.+))?$/', 'handler' => 'handleBootstrapFocusRing', 'priority' => 22],

            // TABLES GROUP
            ['pattern' => '/^border-(collapse|separate)$/', 'handler' => 'handleBorderCollapse', 'priority' => 10],
            ['pattern' => '/^border-spacing(?:-([xy]))?-(.+)/', 'handler' => 'handleBorderSpacing', 'priority' => 10],
            ['pattern' => '/^table-(auto|fixed)$/', 'handler' => 'handleTableLayout', 'priority' => 10],
            ['pattern' => '/^caption-(top|bottom)$/', 'handler' => 'handleCaptionSide', 'priority' => 10],
            ['pattern' => '/^empty-cells-(show|hide)$/', 'handler' => 'handleEmptyCells', 'priority' => 10],

            // List styles
            ['pattern' => '/^list-(none|disc|decimal|circle|square|georgian|armenian|cjk-ideographic|hebrew|hiragana|katakana|hiragana-iroha|katakana-iroha|lower-alpha|lower-greek|lower-latin|lower-roman|upper-alpha|upper-latin|upper-roman)$/', 'handler' => 'handleListStyleType', 'priority' => 10],
            ['pattern' => '/^list-(inside|outside)$/', 'handler' => 'handleListStylePosition', 'priority' => 10],
            ['pattern' => '/^list-image-(none|\[url\((.+)\)\])$/', 'handler' => 'handleListStyleImage', 'priority' => 10],

            // Forms
            ['pattern' => '/^form-(.+)$/', 'handler' => 'handleFormElement', 'priority' => 10],
            ['pattern' => '/^form-floating$/', 'handler' => 'handleFormFloating', 'priority' => 10],
            ['pattern' => '/^accordion-button$/', 'handler' => 'handleAccordionButton', 'priority' => 120],
            ['pattern' => '/^avatar(?:-(group|online|offline|placeholder))?$/', 'handler' => 'handleAvatar', 'priority' => 150],
            ['pattern' => '/^checkbox(?:-(.+))?$/', 'handler' => 'handleCheckbox', 'priority' => 150],
            ['pattern' => '/^radio(?:-(.+))?$/', 'handler' => 'handleRadio', 'priority' => 150],
            ['pattern' => '/^range(?:-(.+))?$/', 'handler' => 'handleRange', 'priority' => 150],
            ['pattern' => '/^rating(?:-(.+))?$/', 'handler' => 'handleRating', 'priority' => 150],
            ['pattern' => '/^select(?:-(.+))?$/', 'handler' => 'handleSelect', 'priority' => 150],
            ['pattern' => '/^input(?:-(.+))?$/', 'handler' => 'handleInput', 'priority' => 150],
            ['pattern' => '/^textarea(?:-(.+))?$/', 'handler' => 'handleTextarea', 'priority' => 150],
            ['pattern' => '/^toggle(?:-(.+))?$/', 'handler' => 'handleToggle', 'priority' => 150],
            ['pattern' => '/^validator(?:-(hint))?$/', 'handler' => 'handleValidator', 'priority' => 160],

            // REVEAL TRANSITION UTILITIES
            ['pattern' => '/^transition-reveal$/', 'handler' => 'handleTransitionRevealBase', 'priority' => 25],
            ['pattern' => '/^reveal-bg-(.+)$/', 'handler' => 'handleRevealBackground', 'priority' => 26],
            ['pattern' => '/^reveal-duration-in-(.+)$/', 'handler' => 'handleRevealDurationIn', 'priority' => 26],
            ['pattern' => '/^reveal-duration-out-(.+)$/', 'handler' => 'handleRevealDurationOut', 'priority' => 26],

            // Arbitrary Content (Crucial for after:content-['On'])
            ['pattern' => '/^content-\[\'([^\']*)\'\]$/', 'handler' => 'handleArbitraryContent', 'priority' => 15],
            ['pattern' => '/^content-\[\"([^\"]*)\"\]$/', 'handler' => 'handleArbitraryContent', 'priority' => 15],

            // Arbitrary variants and properties
            ['pattern' => '/^property-\[(.+)\]$/', 'handler' => 'handleDynamicPropertyRegistration', 'priority' => 1],
            ['pattern' => '/^(?:([a-zA-Z0-9-]+):)?\[(.+:.+)\]$/', 'handler' => 'handleArbitraryProperty', 'priority' => 100],
            ['pattern' => '/^\[(.+)\]:(.+)$/', 'handler' => 'handleArbitraryVariantAndProperty', 'priority' => 1000],

            // Visibility Handler
            ['pattern' => '/^(in)?visible$/', 'handler' => 'handleVisibility', 'priority' => 10],
            ['pattern' => '/^decoration-opacity-(\d+)$/', 'handler' => 'handleDecorationOpacity', 'priority' => 10],
            ['pattern' => '/^underline-offset-(\d+)$/', 'handler' => 'handleUnderlineOffset', 'priority' => 10],
        ];
        
        foreach($handlers as $h) {
            $this->registerUtilityHandler($h['pattern'], $h['handler'], $h['priority'] ?? 0);
        }

        usort($this->utilityHandlers, fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    public function processHtml(string $html): string {
        $classes = $this->getClassesFromHtml($html);
        return $this->generateCss($classes);
    }

    public function processCss(string $cssContent): string {
        $processedCss = $this->parseApplyDirectives($cssContent);        
        $classesInCss = $this->getClassesFromCss($cssContent);
        $utilityCss = $this->generateCss($classesInCss);
        return $processedCss . "\n\n" . $utilityCss;
    }

    private function initializeVariantOrder(): void {
        // অগ্রাধিকারের ক্রম: ছোট সংখ্যা মানে বেশি অগ্রাধিকার (আগে সাজানো হবে এবং CSS-এ বাইরের দিকে থাকবে)
        $this->variantOrder = [
            // --- ক্যাটাগরি ১: বেস ব্রেকপয়েন্ট (সবচেয়ে কম স্পেসিফিক, সবার আগে আসবে) ---
            // (এই অংশটি নিচে ডায়নামিকভাবে যোগ করা হবে)
            
            // --- ক্যাটাগরি ২: কনটেক্সট (যেমন ডার্ক মোড) ---
            'dark' => 20,

            // --- ক্যাটাগরি ৩: সিস্টেম প্রেফারেন্স মিডিয়া কোয়েরি ---
            'print' => 40, 
            'screen' => 40,
            'portrait' => 40, 
            'landscape' => 40,
            'motion-safe' => 40, 
            'motion-reduce' => 40,
            'hover-hover' => 40, // for @media (hover: hover)
            'hover-none' => 40,  // for @media (hover: none)

            // --- ক্যাটাগরি ৪: স্টেটফুল প্যারেন্ট সিলেক্টর (Group ও Peer) ---
            // Group states
            'group-hover' => 60, 
            'group-focus' => 60, 
            'group-active' => 60, 
            'group-disabled' => 60,
            'group-checked' => 60, 
            'group-visited' => 60,
            'group-focus-within' => 60,
            'group-focus-visible' => 60,
            'group-first' => 60,
            'group-last' => 60,
            'group-invalid' => 60,
            'group-valid' => 60,
            'group-focus-warning' => 60,
            'group-focus-checked' => 60,
            'group-focus-disabled' => 60,
            'group-focus-expanded' => 60,
            'group-focus-hidden' => 60,
            'group-focus-pressed' => 60,
            'group-focus-selected' => 60,
            'group-open' => 60,

            // Peer states
            'peer-checked' => 61, // group থেকে সামান্য বেশি অগ্রাধিকার
            'peer-focus' => 61, 
            'peer-hover' => 61, 
            'peer-active' => 61,
            'peer-disabled' => 61,
            'peer-invalid' => 61,
            'peer-placeholder-shown' => 61,

            // --- ক্যাটাগরি ৫: ইন্টার‍্যাকশন স্টেট ও ফর্ম স্টেট ---
            'hover' => 80, 
            'focus' => 80, 
            'focus-within' => 80, 
            'focus-visible' => 80,
            'active' => 80, 
            'visited' => 80, 
            'checked' => 80,
            'disabled' => 80,
            'invalid' => 80,
            'valid' => 80,
            'required' => 80,
            'optional' => 80,
            
            // --- ক্যাটাগরি ৬: স্ট্রাকচারাল ও অ্যাট্রিবিউট সিলেক্টর ---
            // Structural Pseudo-classes
            'first' => 100, 
            'last' => 100, 
            'odd' => 100, 
            'even' => 100,
            'only' => 100, 
            'empty' => 100,
            'first-of-type' => 100, 
            'last-of-type' => 100,
            'only-of-type' => 100,
            'nth-child' => 100,
            'nth-last-child' => 100,
            'nth-of-type' => 100,
            'nth-last-of-type' => 100,
            
            // Attribute Selectors
            'open' => 150, 
            'aria-checked' => 150, 
            'aria-disabled' => 150, 
            'aria-expanded' => 150,
            'aria-hidden' => 150, 
            'aria-pressed' => 150, 
            'aria-selected' => 150,

            // --- ক্যাটাগরি ৭: Pseudo-elements (এগুলো সিলেক্টরের শেষে যুক্ত হয়) ---
            'before' => 200, 
            'after' => 200, 
            'placeholder' => 200, 
            'file' => 200, 
            'marker' => 200, 
            'selection' => 200,

            // --- ক্যাটাগরি ৮: Arbitrary Variants (সবচেয়ে কম অগ্রাধিকার) ---
            // 'arbitrary_selector_pattern' => 999,
            // 'arbitrary_selector_parent_pattern' => 999,
            'arbitrary_variant_pattern' => 999,
        ];

        // --- ডায়নামিক পার্ট: Breakpoint-গুলোর জন্য অগ্রাধিকার সেট করা ---
        // এদের অগ্রাধিকার সবচেয়ে বেশি (ছোট সংখ্যা = 10) হওয়া উচিত
        $breakpointPriority = 10;
        if (isset($this->config['theme']['screens'])) {
            foreach (array_keys($this->config['theme']['screens']) as $screen) {
                $this->variantOrder[$screen] = $breakpointPriority;
                $this->variantOrder['max-' . $screen] = $breakpointPriority; // max-width ভ্যারিয়েন্টের জন্যও
            }
        }
    }

    public function generateCss(array $classes): string {
        $this->neededKeyframes = [];
        $this->generatedUtilitySignatures = [];
        $this->preflightAdded = false;
        $this->layerCss = ['base' => [], 'components' => [], 'utilities' => []];
        $safelist = $this->config['safelist'] ?? [];

        $this->layerCss['base'][] = $this->getPreflightCss();
        $this->layerCss['base'][] = $this->getThemeCssVariables();
        $this->layerCss['base'][] = $this->getFormsBaseStyles();
        $this->layerCss['base'][] = $this->getTypographyBaseStyles();

        $allClasses = array_unique(array_merge($classes, $safelist));
        $uniqueClasses = array_unique($allClasses);
        sort($uniqueClasses);

        $cssRulesByLayer = ['base' => [], 'components' => [], 'utilities' => []];
        $mediaQueriesByLayer = ['base' => [], 'components' => [], 'utilities' => []];

        $hasContainerClass = false;
        $containerOriginalClassName = 'container'; // Default
        if ($this->config['prefix']) {
            $containerOriginalClassName = $this->config['prefix'] . 'container';
        }
        if (in_array($containerOriginalClassName, $uniqueClasses)) {
            $hasContainerClass = true;
        }

        foreach ($uniqueClasses as $className) {
            $classToParse = $this->config['prefix'] && strpos($className, $this->config['prefix']) === 0
                ? substr($className, strlen($this->config['prefix']))
                : $className;
            
            // Handle prose and form plugin classes that add to base/components layer directly
            // and don't need further processing for media queries via regular utility pipeline
            $formClassPrefix = $this->config['forms']['classPrefix'] ?? 'form-';
            if (($this->config['forms']['strategy'] ?? 'class') === 'class' && strpos($classToParse, $formClassPrefix) === 0) {
                $formStyle = $this->handleFormElement($classToParse);
                if ($formStyle) {
                    $mainSelector = '.' . $this->escapeClassNameForSelector($className);
                    $pseudoStyles = [];
                    if (is_array($formStyle)) {
                        foreach ($formStyle as $key => $styleValue) {
                            if (strpos($key, '_') === 0 && str_ends_with($key, 'Styles')) {
                                $pseudoKey = lcfirst(substr($key, 1, -strlen('Styles')));
                                if (!empty($styleValue) && is_array($styleValue)) $pseudoStyles[$pseudoKey] = $styleValue;
                                unset($formStyle[$key]);
                            }
                        }
                    }
                    if(!empty($formStyle)) $cssRulesByLayer['components'][$mainSelector] = array_merge($cssRulesByLayer['components'][$mainSelector] ?? [], $formStyle);
                    foreach($pseudoStyles as $pk => $ps){
                        $pseudoSuffix = '';
                        if($pk === 'focus') $pseudoSuffix = ':focus';
                        elseif($pk === 'checked') $pseudoSuffix = ':checked';
                        // Add other pseudo mappings
                        if($pseudoSuffix) $cssRulesByLayer['components'][$mainSelector.$pseudoSuffix] = array_merge($cssRulesByLayer['components'][$mainSelector.$pseudoSuffix] ?? [], $ps);
                    }
                    $this->generatedUtilitySignatures[$className] = true; // Mark as processed
                }
                continue; // Skip further processing for this class
            }
            if (strpos($classToParse, $this->config['typography']['className'] ?? 'prose') === 0) {
                $this->generatedUtilitySignatures[$className] = true; // Prose styles are already in base layer
                continue;
            }


            $this->parseClass($classToParse, $className, $cssRulesByLayer, $mediaQueriesByLayer);
        }
        
        // Add container's screen-specific max-widths and paddings
        if ($hasContainerClass && ($this->config['corePlugins']['container'] ?? true)) {
            $containerConfig = $this->config['theme']['container'] ?? [];
            $screensToUse = $containerConfig['screens'] ?? $this->config['theme']['screens'] ?? [];
            $containerSelector = '.' . $this->escapeClassNameForSelector($containerOriginalClassName);
            $containerPaddingConfig = $containerConfig['padding'] ?? null;
            $targetLayerForContainer = 'components'; // Container styles go to components layer

            foreach ($screensToUse as $screenKey => $screenValueFromConfig) {
                if (!isset($this->config['theme']['screens'][$screenKey])) continue;

                $screenSpecificStyles = [];
                $maxWidthForThisScreen = $screenValueFromConfig;
                
                if ($maxWidthForThisScreen) {
                    $parsedMaxWidth = $this->parseNumericValue($maxWidthForThisScreen, 'maxWidth', ['defaultUnit' => 'px', 'allowArbitrary' => false]);
                    if ($parsedMaxWidth) {
                        $screenSpecificStyles['max-width'] = $parsedMaxWidth;
                    }
                }

                if (is_array($containerPaddingConfig) && isset($containerPaddingConfig[$screenKey])) {
                    $screenPadding = $this->parseNumericValue($containerPaddingConfig[$screenKey], 'spacing');
                    if ($screenPadding) {
                        $screenSpecificStyles['padding-left'] = $screenPadding;
                        $screenSpecificStyles['padding-right'] = $screenPadding;
                    }
                }
                
                if (!empty($screenSpecificStyles)) {
                    // $screenKey here is 'sm', 'md', etc.
                    if (!isset($mediaQueriesByLayer[$targetLayerForContainer][$screenKey])) {
                        $mediaQueriesByLayer[$targetLayerForContainer][$screenKey] = [];
                    }
                    $mediaQueriesByLayer[$targetLayerForContainer][$screenKey][$containerSelector] = array_merge(
                        $mediaQueriesByLayer[$targetLayerForContainer][$screenKey][$containerSelector] ?? [],
                        $screenSpecificStyles
                    );
                }
            }
        }

        foreach ($cssRulesByLayer as $layer => $rules) {
            if (!empty($rules)) {
                $this->layerCss[$layer][] = $this->buildCssRulesToString($rules);
            }
        }
        foreach ($mediaQueriesByLayer as $layer => $queries) {
            if (!empty($queries)) {
                $this->layerCss[$layer][] = $this->buildMediaQueriesToString($queries);
            }
        }

        return $this->buildFinalCssOutput();
    }

    private function smartSplit($string, $separator) {
        // 1. Find URLs and replace them with tokens
        $urlPattern = '/https?:\/\/[^\s"\'<>]+/i';
        $urls = [];
        $stringWithTokens = preg_replace_callback($urlPattern, function($matches) use (&$urls) {
            $token = '__URL_TOKEN_' . count($urls) . '__';
            $urls[$token] = $matches[0];
            return $token;
        }, $string);

        // 2. Split by separator
        $parts = explode($separator, $stringWithTokens);

        // 3. Restore URLs
        foreach ($parts as &$part) {
            foreach ($urls as $token => $url) {
                $part = str_replace($token, $url, $part);
            }
        }

        return $parts;
    }

    private function parseClass(string $classNameWithPotentialImportant, string $originalClassNameForSelector, array &$cssRulesByLayer, array &$mediaQueriesByLayer): void {
        $isImportant = str_ends_with($classNameWithPotentialImportant, '!');
        $className = $isImportant ? rtrim($classNameWithPotentialImportant, '!') : $classNameWithPotentialImportant;

        if (isset($this->generatedUtilitySignatures[$originalClassNameForSelector])) {
            return;
        }

        // --- ধাপ ১: ক্লাস পার্স করা ---
        $parts = preg_split("/(?<!\[){$this->config['separator']}(?![^\[]*\])/", $className);
        $baseClassPart = array_pop($parts);
        $modifiers = $parts;

        // --- ধাপ ২: ভ্যারিয়েন্ট সর্টিং ---
        $variantOrder = $this->config['variantOrder'] ?? [];
        usort($modifiers, function ($a, $b) use ($variantOrder) {
            $getPriority = function ($modifier) use ($variantOrder) {
                if (str_starts_with($modifier, '@')) return 30; // Container queries
                if (isset($this->config['theme']['screens'][str_replace('max-', '', $modifier)])) return 35; // Breakpoints
                return $variantOrder[$modifier] ?? 99;
            };
            return $getPriority($a) <=> $getPriority($b);
        });
        
        // --- ধাপ ৩: হ্যান্ডলার দিয়ে স্টাইল তৈরি করা ---
        $generatedStyle = null;
        $targetLayer = 'utilities';
        $query = null;
        $customSelector = null;

        foreach ($this->utilityHandlers as $handlerConfig) {
            if (preg_match($handlerConfig['pattern'], $baseClassPart, $matches)) {
                $handlerMethod = $handlerConfig['handler'];
                $styleData = is_string($handlerMethod) ? $this->$handlerMethod($baseClassPart, $matches) : call_user_func($handlerMethod, $baseClassPart, $matches);
                
                if ($styleData !== null) {
                    $targetLayer = $styleData['layer'] ?? 'utilities';
                    
                    if (is_array($styleData)) {
                        $query = $styleData['query'] ?? null;
                        $customSelector = $styleData['_customSelector'] ?? null;
                        $generatedStyle = $styleData['style'] ?? $styleData;
                    } else {
                        $generatedStyle = $styleData;
                    }
                    break;
                }
            }
        }

        // হ্যান্ডলার থেকে সরাসরি CSS স্ট্রিং আসলে বা কোনো স্টাইল না থাকলে রিটার্ন করুন
        if (empty($generatedStyle) || is_string($generatedStyle)) {
            if(is_string($generatedStyle)) $this->layerCss[$targetLayer][] = $generatedStyle;
            $this->generatedUtilitySignatures[$originalClassNameForSelector] = true;
            return;
        }
        $this->generatedUtilitySignatures[$originalClassNameForSelector] = true;

        // --- ধাপ ৪: সিলেক্টর এবং কোয়েরি নির্ধারণ ---
        $selector = $customSelector ?? '.' . $this->escapeClassNameForSelector($this->config['prefix'] . $originalClassNameForSelector);
        
        // কাস্টম কীগুলো মূল স্টাইল থেকে মুছে ফেলুন
        if(isset($generatedStyle['_customSelector'])) unset($generatedStyle['_customSelector']);
        if(isset($generatedStyle['query'])) unset($generatedStyle['query']);
        if(isset($generatedStyle['layer'])) unset($generatedStyle['layer']);

        // মডিফায়ার লুপ (hover, focus, md:, ইত্যাদি)
        foreach ($modifiers as $modKey) {
            $variantConfig = $this->config['variants'][$modKey] ?? null;

            // ক. Media & Container Queries
            if ($query === null) {
                // ১. Container Queries (Auto scale based on parent div)
                if (str_starts_with($modKey, '@')) {
                    $breakpoint = substr($modKey, 1); // remove '@'
                    if (isset($this->config['theme']['screens'][$breakpoint])) {
                        $query = "@container (min-width: {$this->config['theme']['screens'][$breakpoint]})";
                    } elseif (preg_match('/^\[(.+)\]$/', $breakpoint, $arbMatch)) {
                        $query = "@container (min-width: {$arbMatch[1]})";
                    }
                }
                // ২. Standard Min-Width Media Queries (sm:, md:, lg:)
                elseif (isset($this->config['theme']['screens'][$modKey])) {
                    $query = "@media (min-width: {$this->config['theme']['screens'][$modKey]})";
                }
                // ৩. Max-Width Media Queries (max-sm:, max-md:)
                elseif (preg_match('/^max-([a-zA-Z0-9-]+)$/', $modKey, $maxMatches)) {
                    $bpName = $maxMatches[1];
                    if (isset($this->config['theme']['screens'][$bpName])) {
                        $bpValue = $this->config['theme']['screens'][$bpName];
                        // Tailwind logic: Subtract 0.02px for max-width overlap prevention
                        $maxWidth = (float)filter_var($bpValue, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) - 0.02;
                        $unit = preg_replace('/[0-9.-]+/', '', $bpValue) ?: 'px';
                        $query = "@media (max-width: {$maxWidth}{$unit})";
                    }
                }
            }
            
            // খ. Standard & Arbitrary Variants (hover, focus, [&>p], etc.)
            // এই ভ্যারিয়েন্টগুলো সব সময় অ্যাপ্লাই হবে, কারণ এগুলো সিলেক্টর পরিবর্তন করে, কোয়েরি নয়।
            if ($variantConfig) {
                if (is_string($variantConfig)) {
                    $selector .= $variantConfig;
                } elseif (is_array($variantConfig)) {
                    if (isset($variantConfig['type']) && $variantConfig['type'] === 'selector_transform') {
                        $selector = call_user_func($variantConfig['transform'], $selector);
                    } else {
                        // Handle [selector_suffix_or_template, media_query]
                        $selPart = $variantConfig[0] ?? null;
                        $queryPart = $variantConfig[1] ?? null;
                        
                        if ($selPart) {
                            if (str_contains($selPart, '&')) {
                                $selector = str_replace('&', $selector, $selPart);
                            } else {
                                $selector .= $selPart;
                            }
                        }
                        if ($queryPart && $query === null) {
                            $query = $queryPart;
                        }
                    }
                }
            }
            elseif (str_starts_with($modKey, '[')) {
                $content = str_replace('_', ' ', trim($modKey, '[]'));
                if (str_contains($content, '&')) {
                    $selector = str_replace('&', $selector, $content);
                } else {
                    $selector = $content . ' ' . $selector;
                }
            }
        }

        // --- ধাপ ৫: CSS রুলগুলো যোগ করা ---
        $applyImportant = $isImportant || ($this->config['important'] === true);
        if (is_string($this->config['important'])) {
            $selector = $this->config['important'] . ' ' . $selector;
        }

        $baseProperties = [];
        $pseudoStyles = [];
        $childSelectorStyles = [];

        foreach ($generatedStyle as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_')) {
                $specialKey = lcfirst(substr($key, 1, -6));
                if ($specialKey === 'childSelector') $childSelectorStyles = array_merge_recursive($childSelectorStyles, $value);
                else $pseudoStyles[$specialKey] = array_merge($pseudoStyles[$specialKey] ?? [], $value);
            } else {
                $baseProperties[$key] = $value;
            }
        }

        $addRule = function(string $sel, array $props) use (&$cssRulesByLayer, &$mediaQueriesByLayer, $targetLayer, $query, $applyImportant) {
            if (empty($props)) return;
            if ($applyImportant) {
                foreach ($props as $k => &$v) { if (is_string($v) && !str_starts_with($k, '--')) $v .= ' !important'; }
                unset($v);
            }
            
            if ($query) {
                $mediaQueriesByLayer[$targetLayer][$query][$sel] = array_merge_recursive($mediaQueriesByLayer[$targetLayer][$query][$sel] ?? [], $props);
            } else {
                $cssRulesByLayer[$targetLayer][$sel] = array_merge_recursive($cssRulesByLayer[$targetLayer][$sel] ?? [], $props);
            }
        };

        if (!empty($baseProperties)) $addRule($selector, $baseProperties);
        
        $pseudoMap = $this->getPseudoMap();
        foreach ($pseudoStyles as $key => $props) {
            if (isset($pseudoMap[$key]) && !empty($props)) {
                $addRule($selector . $pseudoMap[$key], $props);
            }
        }
        foreach ($childSelectorStyles as $childSuffix => $props) {
            $addRule($selector . ' ' . trim($childSuffix), $props);
        }
    }

    private function applyModifiersToStyle(string $originalClassName, array $styles, array $modifiers): array {
        $selector = '.' . $this->escapeClassNameForSelector($this->config['prefix'] . $originalClassName);
        $query = null;

        // ভ্যারিয়েন্টগুলোকে তাদের প্রায়োরিটি অনুযায়ী সাজানো
        usort($modifiers, function ($a, $b) {
            $orderA = $this->variantOrder[$a] ?? (strpbrk($a, '[:@') !== false ? 50 : 99);
            $orderB = $this->variantOrder[$b] ?? (strpbrk($b, '[:@') !== false ? 50 : 99);
            return $orderA <=> $orderB;
        });

        foreach ($modifiers as $modKey) {
            $variantConfig = $this->config['variants'][$modKey] ?? null;
            
            if (isset($this->config['theme']['screens'][$modKey])) {
                $query = "@media (min-width: {$this->config['theme']['screens'][$modKey]})";
            } elseif (str_starts_with($modKey, 'max-') && isset($this->config['theme']['screens'][substr($modKey, 4)])) {
                $breakpointValue = $this->config['theme']['screens'][substr($modKey, 4)];
                $maxWidth = (float)filter_var($breakpointValue, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) - 0.02;
                $unit = preg_replace('/[0-9.-]+/', '', $breakpointValue) ?: 'px';
                $query = "@media (max-width: {$maxWidth}{$unit})";
            } elseif ($variantConfig) {
                if (is_string($variantConfig)) {
                    $selector .= $variantConfig;
                } elseif (is_array($variantConfig)) {
                    if (isset($variantConfig['type']) && $variantConfig['type'] === 'selector_transform') {
                        $selector = call_user_func($variantConfig['transform'], $selector);
                    } elseif (isset($variantConfig[0]) && str_contains($variantConfig[0], '&')) {
                        $selector = str_replace('&', $selector, $variantConfig[0]);
                    }
                }
            } elseif (str_starts_with($modKey, '[')) {
                $content = str_replace('_', ' ', trim($modKey, '[]'));
                if (str_starts_with($content, '@')) {
                    $query = $content;
                } elseif (str_contains($content, '&')) {
                    $selector = str_replace('&', $selector, $content);
                } else {
                    $selector = $content . $selector;
                }
            }
        }
        return ['selector' => $selector, 'query' => $query, 'styles' => $styles];
    }

    private function applyModifierToRule(array $rule, string $modKey): ?array {
        $variantConfig = $this->config['variants'][$modKey] ?? null;

        // মিডিয়া কোয়েরি
        if (isset($this->config['theme']['screens'][$modKey])) {
            $rule['query'] = "@media (min-width: {$this->config['theme']['screens'][$modKey]})";
            return $rule;
        }

        // অন্যান্য ভ্যারিয়েন্ট
        if ($variantConfig) {
            if (is_string($variantConfig)) {
                $rule['selector'] .= $variantConfig;
            } elseif (is_array($variantConfig)) {
                if (isset($variantConfig['type']) && $variantConfig['type'] === 'selector_transform') {
                    $rule['selector'] = call_user_func($variantConfig['transform'], $rule['selector']);
                } elseif (isset($variantConfig[0]) && str_contains($variantConfig[0], '&')) {
                    $rule['selector'] = str_replace('&', $rule['selector'], $variantConfig[0]);
                }
            }
            return $rule;
        }
        
        // Arbitrary ভ্যারিয়েন্ট
        if (str_starts_with($modKey, '[')) {
            $arbitraryContent = str_replace('_', ' ', trim($modKey, '[]'));
            if (str_starts_with($arbitraryContent, '@')) {
                $rule['query'] = $arbitraryContent;
            } elseif (str_contains($arbitraryContent, '&')) {
                $rule['selector'] = str_replace('&', $rule['selector'], $arbitraryContent);
            } else {
                $rule['selector'] = $arbitraryContent . $rule['selector'];
            }
            return $rule;
        }

        return null; // যদি কোনো ভ্যালিড মডিফায়ার না হয়
    }

    private function wrapWithMediaIfNeeded(string $class, string $css): string {
        foreach ($this->mediaScreens as $prefix => $minWidth) {
            if (str_starts_with($class, $prefix . $this->config['separator'])) {
                return "@media (min-width: $minWidth) {\n$css\n}\n";
            }
        }
        return $css;
    }

    private function handleContainer(string $baseClassPart, array $matches): ?array {
        $type = $matches[1] ?? 'default'; // sm, md, lg, fluid...
        $arbitrarySize = $matches[2] ?? null; // [1000px]

        // --- Configuration Merging (BS > TW > Custom) ---
        $twScreens = $this->config['theme']['screens'] ?? [];
        $bsBreakpoints = ['sm'=>'576px', 'md'=>'768px', 'lg'=>'992px', 'xl'=>'1200px', 'xxl'=>'1400px'];
        $bsMaxWidths = ['sm'=>'540px', 'md'=>'720px', 'lg'=>'960px', 'xl'=>'1140px', 'xxl'=>'1320px'];
        
        $customContainerConfig = $this->config['theme']['container'] ?? [];
        
        // Final breakpoints and max-widths to use
        $finalBreakpoints = array_merge($twScreens, $bsBreakpoints, $customContainerConfig['screens'] ?? []);
        $finalMaxWidths = array_merge($twScreens, $bsMaxWidths, $customContainerConfig['screens'] ?? []);
        
        // --- Arbitrary Size Handling ---
        if ($arbitrarySize) {
            $size = str_replace('_', ' ', $arbitrarySize);
            return [
                'layer' => 'components',
                'style' => ['width'=>'100%', 'max-width'=>$size, 'margin-left'=>'auto', 'margin-right'=>'auto', 'padding-left'=>'1rem', 'padding-right'=>'1rem']
            ];
        }

        // --- Standard Container Logic ---
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = "{$selector} {\n";
        $css .= "  width: 100%;\n";
        $css .= "  padding-right: var(--bs-gutter-x, 0.75rem);\n";
        $css .= "  padding-left: var(--bs-gutter-x, 0.75rem);\n";
        $css .= "  margin-right: auto;\n";
        $css .= "  margin-left: auto;\n";
        $css .= "}\n";

        if ($type === 'fluid') {
            return ['layer' => 'components', 'style' => $css];
        }
        
        $startBreakpoint = ($type === 'default') ? 'sm' : $type;
        $shouldApply = false;

        // Sort breakpoints to ensure correct order
        uksort($finalBreakpoints, fn($a, $b) => (int)filter_var($finalBreakpoints[$a], FILTER_SANITIZE_NUMBER_INT) <=> (int)filter_var($finalBreakpoints[$b], FILTER_SANITIZE_NUMBER_INT));

        foreach ($finalBreakpoints as $bpKey => $bpValue) {
            if ($bpKey === $startBreakpoint) {
                $shouldApply = true;
            }
            if ($shouldApply && isset($finalMaxWidths[$bpKey])) {
                $width = $finalMaxWidths[$bpKey];
                $css .= "@media (min-width: {$bpValue}) {\n";
                $css .= "  {$selector} { max-width: {$width}; }\n";
                $css .= "}\n";
            }
        }
        
        return ['layer' => 'components', 'style' => $css];
    }
    private function handleDebugUi(string $baseClassPart, array $matches): ?array {
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = <<<CSS
        /* Adds a visible outline and faint background to EVERY child element for debugging layouts */
        {$selector}, {$selector} * {
            outline: 1px solid hsla(348, 100%, 54%, 0.5) !important;
            background: hsla(210, 100%, 50%, 0.05) !important;
        }
        CSS;
        return ['layer' => 'utilities', 'style' => $css];
    }
    private function handleContainerType(string $baseClassPart, array $matches): ?array {
        $type = $matches[1]; // size, inline-size, normal
        if (in_array($type, ['size', 'inline-size', 'normal'])) {
            return ['container-type' => str_replace('-', ' ', $type)];
        }
        return null;
    }
    private function handleContainerName(string $baseClassPart, array $matches): ?array {
        $name = $matches[1];
        return ['container-name' => $name];
    }
    private function handlePosition(string $classPart, array $matches): ?array { return ['position' => $matches[1]]; }
    private function handleDisplay(string $classPart, array $matches): ?array {
        $displayValue = $matches[1]; // The matched display value (e.g., "block", "flex", "hidden")

        // Handle the 'hidden' class specifically to add !important
        if ($displayValue === 'hidden') {
            return ['display' => 'none'];
        }

        // For other display values, just return the display property
        // The global 'important' config or '!' suffix will handle !important for them if needed.
        return ['display' => $displayValue];
    }
    
    private function handleZIndex(string $baseClassPart, array $matches): ?array {
        $valuePart = $matches[1];
        $isNegative = str_starts_with($baseClassPart, '-');

        // ১. Arbitrary Value হ্যান্ডলিং (e.g., z-[9999])
        if (str_starts_with($valuePart, '[') && str_ends_with($valuePart, ']')) {
            $arbitraryValue = trim($valuePart, '[]');
            $finalValue = str_replace('_', ' ', $arbitraryValue);
            return ['z-index' => ($isNegative ? '-' : '') . $finalValue];
        }

        // ২. থিম থেকে ভ্যালু খোঁজা (e.g., z-10, z-modal, z-auto)
        $themeValue = $this->lookupThemeValue('zIndex', $valuePart);
        if ($themeValue !== null) {
            return ['z-index' => ($isNegative ? '-' : '') . $themeValue];
        }

        // ৩. সরাসরি নিউমেরিক ভ্যালু (e.g., z-1, z-99)
        if (is_numeric($valuePart)) {
            return ['z-index' => ($isNegative ? '-' : '') . $valuePart];
        }

        return null;
    }

    private function handleOutlineStyleOrColorKeyword(string $baseClassPart, array $matches): ?array {
        $value = $matches[1];
        if ($value === 'none') {
            // Tailwind's outline-none also sets focus ring to transparent effectively
            $styles = ['outline' => '2px solid transparent', 'outline-offset' => '0px'];
            // If you want outline-none to also affect focus state (like a reset)
            // $styles['_focusStyles'] = ['outline' => '2px solid transparent', 'outline-offset' => '2px'];
            return $styles;
        }
        $color = $this->parseColorValue($value);
        return $color ? ['outline-color' => $color] : null;
    }
    private function handleOutlineStyle(string $baseClassPart, array $matches): ?array {
        $style = $matches[1] ?? 'solid'; // Default to solid if 'outline' class
        return ['outline-style' => $style];
    }
    private function handleOutlineOffset(string $baseClassPart, array $matches): ?array {
        $val = $this->parseNumericValue($matches[1], 'outlineOffset', ['defaultUnit' => 'px']);
        return $val ? ['outline-offset' => $val] : null;
    }
    private function handleOutlineColorOrWidth(string $baseClassPart, array $matches): ?array {
        $valuePart = $matches[1];
        // Try as color
        $color = $this->parseColorValue($valuePart);
        if($color) return ['outline-color' => $color];
        // Try as width
        $width = $this->parseNumericValue($valuePart, 'outlineWidth', ['defaultUnit' => 'px']);
        if($width) return ['outline-width' => $width];
        return null;
    }

    private function handleOverflow(string $baseClassPart, array $matches): ?array {
        $axis = $matches[1] ? rtrim($matches[1], '-') : null; // 'x' or 'y' or null
        $value = $matches[2]; // 'auto', 'hidden', etc.

        if ($axis) {
            // For overflow-x-hidden, overflow-y-scroll, etc.
            return ["overflow-{$axis}" => $value];
        } else {
            // For overflow-hidden, overflow-scroll, etc.
            return ['overflow' => $value];
        }
    }

    private function handleOffset(string $baseClassPart, array $matches): ?array {
        $breakpoint = $matches[1] ?? null; // sm, md...
        $size = (int)($matches[2] ?? 0);     // 1-12

        if ($size < 0 || $size >= 12) {
            return null; // Invalid offset size
        }

        // Calculate percentage for margin-left
        $percentage = round(($size / 12) * 100, 6) . '%';
        $styles = ['margin-left' => $percentage];
        
        // যদি রেসপন্সিভ হয়, মিডিয়া কোয়েরি সহ রিটার্ন করুন
        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            return [
                'layer' => 'utilities',
                'query' => "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})",
                'style' => $styles,
            ];
        }

        // Base offset (e.g., .offset-4)
        return $styles;
    }

    private function handleBoxDecorationBreak(string $baseClassPart, array $matches): ?array {
        $value = $matches[1]; // 'slice' or 'clone'

        if ($value === 'slice' || $value === 'clone') {
            return [
                '-webkit-box-decoration-break' => $value,
                'box-decoration-break'         => $value,
            ];
        }

        return null;
    }

    private function handlePositionPlacement(string $baseClassPart, array $matches): ?array {
        $isNegative = strpos($baseClassPart, '-') === 0;
        // Regex: '/^(?:(-))?(top|right|bottom|left|inset(?:-[xy])?)-(.+)/' (hypothetical combined regex)
        // Current separate regexes:
        // Positive: '/^(top|right|bottom|left|inset(?:-[xy])?)-(.+)/' -> $matches[1] = type, $matches[2] = value
        // Negative: '/^-(top|right|bottom|left|inset(?:-[xy])?)-(.+)/' -> $matches[1] = type, $matches[2] = value

        $type = $matches[1]; 
        $valueKey = $matches[2];

        $cssValue = $this->parseNumericValue($valueKey, 'inset', ['allowNegative' => $isNegative]);
        if ($cssValue === null) {
            // Try parsing as 'spacing' if 'inset' fails (e.g. for top-1, left-2)
            $cssValue = $this->parseNumericValue($valueKey, 'spacing', ['allowNegative' => $isNegative]);
            if ($cssValue === null) return null;
        }

        if ($isNegative && $cssValue !== 'auto' && $cssValue !== '0px' && $cssValue !== '0' ) {
            if (strpos($cssValue, '-') !== 0 && (is_numeric(substr($cssValue, 0, 1)) || $cssValue[0] === '.' || strpos($cssValue, 'var(') === 0 || strpos($cssValue, 'calc(') === 0) ) { 
                $cssValue = '-' . $cssValue;
            }
        }
        
        $properties = [];
        if ($type === 'inset') $properties = ['top' => $cssValue, 'right' => $cssValue, 'bottom' => $cssValue, 'left' => $cssValue];
        elseif ($type === 'inset-x') $properties = ['left' => $cssValue, 'right' => $cssValue];
        elseif ($type === 'inset-y') $properties = ['top' => $cssValue, 'bottom' => $cssValue];
        else $properties = [$type => $cssValue]; 
        
        return $properties;
    }
    private function handleFlexDirection(string $classPart, array $matches): ?array { return ['flex-direction' => str_replace('col', 'column', $matches[1])]; }
    private function handleFlexWrap(string $classPart, array $matches): ?array { return ['flex-wrap' => $matches[1]]; }
    private function handleAlignItems(string $classPart, array $matches): ?array { $map = ['start'=>'flex-start', 'end'=>'flex-end']; return ['align-items' => $map[$matches[1]] ?? $matches[1]]; }
    private function handleJustifyContent(string $classPart, array $matches): ?array { $map = ['start'=>'flex-start', 'end'=>'flex-end', 'between'=>'space-between', 'around'=>'space-around', 'evenly'=>'space-evenly']; return ['justify-content' => $map[$matches[1]] ?? $matches[1]]; }
    private function handleAlignContent(string $classPart, array $matches): ?array { $map = ['start'=>'flex-start', 'end'=>'flex-end', 'between'=>'space-between', 'around'=>'space-around', 'evenly'=>'space-evenly']; return ['align-content' => $map[$matches[1]] ?? $matches[1]]; }
    private function handleVerticalAlign(string $baseClassPart, array $matches): ?array {
        $alignment = $matches[1];
        return ['vertical-align' => $alignment];
    }
    private function handleObjectFit(string $baseClassPart, array $matches): ?array {
        $breakpoint = $matches[1] ?? null;
        $value = $matches[2];
        if ($value === 'scale') $value = 'scale-down';

        $style = ['object-fit' => $value];

        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            return [
                'layer' => 'utilities',
                'query' => "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})",
                'style' => $style
            ];
        }
        return ['object-fit' => $value];
    }
    private function handleFloat(string $baseClassPart, array $matches): ?array {
        $breakpoint = $matches[1] ?? null;
        $dir = $matches[2];

        $cssValue = match($dir) {
            'start' => 'left',
            'end'   => 'right',
            'left'  => 'left',
            'right' => 'right',
            'none'  => 'none',
            default => 'none'
        };

        $style = ['float' => $cssValue];

        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            return [
                'layer' => 'utilities',
                'query' => "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})",
                'style' => $style
            ];
        }

        return $style;
    }
    private function handlePositionUtility(string $baseClassPart, array $matches): ?array {
        $type = $matches[1];       // fixed or sticky
        $breakpoint = $matches[2]; // sm, md... or empty
        $placement = $matches[3];  // top or bottom

        $styles = [
            'position' => $type,
            $placement => '0',
            'z-index' => ($type === 'fixed' ? '1030' : '1020'),
        ];

        // Fixed elements usually span full width
        if ($type === 'fixed') {
            $styles['left'] = '0';
            $styles['right'] = '0';
        }

        // If responsive breakpoint exists, wrap in media query
        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            return [
                'layer' => 'utilities',
                'query' => "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})",
                'style' => $styles
            ];
        }

        // Base style (no breakpoint)
        return $styles;
    }
    private function handleClearfix(string $baseClassPart, array $matches): ?array {
        return [
            '_afterStyles' => [
                'display' => 'block',
                'content' => '""',
                'clear'   => 'both',
            ]
        ];
    }
    private function handleList(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base'; // row, col-wrap, col-grow, or 'base'
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        $css = '';
        switch ($modifier) {
            case 'base':
                $css = <<<CSS
                {$selector} {
                    display: flex;
                    flex-direction: column;
                    list-style-type: none; /* ul/ol এর জন্য */
                    padding: 0;
                }
                CSS;
                break;

            case 'row':
                $css = <<<CSS
                {$selector} {
                    display: grid;
                    grid-auto-flow: column;
                    grid-auto-columns: max-content;
                    grid-template-columns: auto 1fr;
                    align-items: center;
                    gap: 1rem;
                    padding: 1rem;
                }
                {$selector} > :nth-child(2) {
                    justify-self: start;
                    grid-column: 2 / -2;
                }
                CSS;
                break;

            case 'col-grow':
                $css = <<<CSS
                /* Reset default grow on second child */
                .list-row > :nth-child(2) {
                    grid-column: auto;
                }
                /* Apply grow to the element with this class */
                {$selector} {
                    grid-column: 2 / span 1;
                }
                CSS;
                break;
                
            case 'col-wrap':
                $css = <<<CSS
                /* Allow this column to span all columns and wrap */
                {$selector} {
                    grid-column: 1 / -1;
                    margin-top: 0.5rem; /* mt-2 */
                }
                CSS;
                break;
        }

        return !empty($css) ? ['layer' => 'components', 'style' => $css] : null;
    }
    private function handleStat(string $baseClassPart, array $matches): ?array {
        $type = str_starts_with($baseClassPart, 'stats') ? 'stats' : 'stat';
        $modifier = $matches[1] ?? null; // horizontal or vertical
        
        $styles = [];

        // --- .stats কন্টেইনার ---
        if ($type === 'stats') {
            $styles = [
                'display' => 'inline-grid',
                'gap' => '1rem',
                'grid-auto-flow' => 'column',
                'place-items' => 'center',
                'width' => '100%',
                'align-content' => 'center',
                'justify-content' => 'center',
                'align-items' => 'center',
                'justify-items' => 'stretch',
            ];
            
            // Modifier handling
            if ($modifier === 'vertical') {
                $styles['grid-auto-flow'] = 'row';
            } elseif ($modifier === 'horizontal') {
                $styles['grid-auto-flow'] = 'column';
            }
            return ['layer' => 'components', 'style' => $styles];
        }

        // --- .stat আইটেম ---
        if ($type === 'stat') {
            $css = <<<CSS
            .stat {
                display: grid;
                grid-template-columns: 1fr auto;
                padding: 1rem 1.5rem;
                border-color: hsl(var(--b2, var(--border)));
                border-width: 1px;
                border-radius: var(--rounded-box, 1rem);
            }
            .stat-title {
                grid-column-start: 1;
                font-size: 0.875rem; /* text-sm */
                opacity: 0.6;
            }
            .stat-value {
                grid-column-start: 1;
                font-size: 2.25rem; /* text-4xl */
                font-weight: 800; /* extrabold */
            }
            .stat-desc {
                grid-column-start: 1;
                font-size: 0.875rem;
                opacity: 0.6;
            }
            .stat-figure {
                grid-column: 2;
                grid-row: 1 / span 3;
                justify-self: end;
                align-self: center;
            }
            .stat-actions {
                grid-column-start: 1;
                margin-top: 1rem;
                display: flex;
                gap: 0.5rem;
            }
            .stats .stat:not(:first-child) {
                 border-left-width: 1px;
            }
            .stats.stats-vertical .stat:not(:first-child) {
                 border-left-width: 0;
                 border-top-width: 1px;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }
        
        return null;
    }
    private function handleTableZebra(string $baseClassPart, array $matches): ?array {
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        // আপনার থিমের muted কালারটি নেওয়া হচ্ছে
        $bgColor = $this->resolveThemeValue(['theme' => 'colors.muted'], 'hsl(210 40% 96.1%)'); // Fallback light gray
        $bgColorWithOpacity = $this->convertColorToRgba($bgColor, 0.5); // 50% opacity

        $css = <<<CSS
        /* --- Table Zebra Striping --- */
        {$selector} tbody tr:nth-child(even) {
            background-color: {$bgColorWithOpacity};
        }
        CSS;

        return ['layer' => 'components', 'style' => $css];
    }
    private function handleDivider(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        
        if ($baseClassPart === 'divider') {
            $css = <<<CSS
            .divider {
                display: flex; align-items: center; white-space: nowrap;
                margin: 1rem 0; --divider-color: hsl(var(--b2, var(--border)) / 0.6);
            }
            .divider::before, .divider::after {
                content: ""; flex-grow: 1; height: 1px;
                background-color: var(--divider-color);
            }
            .divider:not(:empty)::before { margin-right: 0.75rem; margin-left: 0.75rem; }
            .divider:not(:empty)::after { margin-left: 0.75rem; margin-right: 0.75rem; }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        if (in_array($modifier, ['start', 'end'])) {
            return match ($modifier) {
                'start' => ['style' => ['_beforeStyles' => ['flex-grow' => '0', 'width' => '1rem'], '_afterStyles' => ['flex-grow' => '1', 'width' => '1rem']]],
                'end' => ['style' => ['_beforeStyles' => ['flex-grow' => '1', 'width' => '1rem'], '_afterStyles' => ['flex-grow' => '0', 'width' => '1rem']]],
            };
        }
        
        if ($modifier === 'horizontal') {
            return ['style' => [
                'flex-direction' => 'column', 'align-items' => 'center', 'justify-content' => 'center', 'flex' => 'auto', 'width' => 'min-content', 'height' => 'auto',
                'margin' => '0 1rem',
                '_beforeStyles' => ['height' => 'auto', 'width' => '1px', 'margin-bottom' => '0.75rem', 'background-color' => 'var(--divider-color)'],
                '_afterStyles' => ['height' => 'auto', 'width' => '1px', 'margin-top' => '0.75rem', 'background-color' => 'var(--divider-color)'],
            ]];
        }
        if ($modifier === 'vertical') return [];

        $colorValue = $this->parseColorValue($modifier);
        if ($colorValue) {
            return ['style' => ['--divider-color' => $colorValue]];
        }

        return null;
    }
    private function handleStack(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        
        // --- Base .stack Styles ---
        if ($baseClassPart === 'stack') {
            $css = <<<CSS
            .stack {
                display: inline-grid;
                place-items: center; /* Default center */

                /* Default transform direction variables */
                --stack-translate-x: 0px;
                --stack-translate-y: -10px; /* Default: stack upwards */
                --stack-scale: 0.95;
                margin: 1rem; 
            }
            .stack > * {
                grid-area: 1 / 1;
                transition: all 0.2s ease-out;
                opacity: 1;
            }
            .stack > :nth-child(1) { z-index: 30; }
            .stack > :nth-child(2) { 
                z-index: 20; 
                transform: scale(var(--stack-scale)) 
                           translateX(var(--stack-translate-x)) 
                           translateY(var(--stack-translate-y));
                opacity: 0.9;
            }
            .stack > :nth-child(3) {
                z-index: 10;
                transform: scale(calc(var(--stack-scale) - 0.05)) 
                           translateX(calc(var(--stack-translate-x) * 2)) 
                           translateY(calc(var(--stack-translate-y) * 2));
                opacity: 0.8;
            }
            .stack:hover > * {
                transform: scale(1) translate(0, 0);
                opacity: 1;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }
        
        // --- Modifier Handler (Placement) ---
        if ($modifier) {
            $styles = [];
            switch ($modifier) {
                case 'top':    $styles = ['--stack-translate-y' => '10px']; break; // Stack downwards
                case 'bottom': $styles = ['--stack-translate-y' => '-10px']; break; // Stack upwards (default)
                case 'start':  $styles = ['--stack-translate-x' => '10px']; break; // Stack rightwards
                case 'end':    $styles = ['--stack-translate-x' => '-10px']; break; // Stack leftwards
            }
            return !empty($styles) ? ['style' => $styles] : null;
        }
        return null;
    }

    private function handleBento(string $baseClassPart, array $matches): ?array {
        $type = $matches[1]; // grid or box
        $modifier = $matches[2] ?? null;
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        // Bento Grid Container
        if ($type === 'grid') {
            $css = <<<CSS
            {$selector} {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(var(--bento-min, 250px), 1fr));
                grid-auto-rows: var(--bento-row, 250px);
                gap: 1rem;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // Bento Box Item
        if ($type === 'box') {
            if ($modifier === null) {
                // Default Box
                $css = <<<CSS
                {$selector} {
                    background-color: hsl(var(--b1, var(--card)));
                    border-radius: var(--rounded-box, 1.5rem);
                    border: 1px solid hsl(var(--b3, var(--border)));
                    padding: 1.5rem;
                    overflow: hidden;
                    display: flex;
                    flex-direction: column;
                    transition: transform 0.3s ease, box-shadow 0.3s ease;
                }
                {$selector}:hover {
                    transform: scale(0.98);
                }
                CSS;
                return ['layer' => 'components', 'style' => $css];
            }
            
            // Box Span Modifiers (bento-box-2x2, bento-box-wide, bento-box-tall)
            if ($modifier === '2x2') return ['layer' => 'components', 'style' => "{$selector} { grid-column: span 2; grid-row: span 2; }"];
            if ($modifier === 'wide') return ['layer' => 'components', 'style' => "{$selector} { grid-column: span 2; grid-row: span 1; }"];
            if ($modifier === 'tall') return ['layer' => 'components', 'style' => "{$selector} { grid-column: span 1; grid-row: span 2; }"];
        }
        return null;
    }

    private function handleSoftUi(string $baseClassPart, array $matches): ?array {
        $type = $matches[1] ?? 'outset'; // default or inset
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        if ($type === 'outset') {
            $css = <<<CSS
            {$selector} {
                background-color: hsl(var(--b2, var(--background)));
                border-radius: var(--rounded-box, 1rem);
                /* Light shadow top-left, Dark shadow bottom-right */
                box-shadow: 
                    8px 8px 16px hsl(var(--b3, var(--border)) / 0.5), 
                    -8px -8px 16px hsl(var(--b1, var(--background)) / 0.8);
                transition: box-shadow 0.2s ease-in-out;
            }
            {$selector}:active {
                box-shadow: 
                    inset 8px 8px 16px hsl(var(--b3, var(--border)) / 0.5), 
                    inset -8px -8px 16px hsl(var(--b1, var(--background)) / 0.8);
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        if ($type === 'inset') {
            $css = <<<CSS
            {$selector} {
                background-color: hsl(var(--b2, var(--background)));
                border-radius: var(--rounded-box, 1rem);
                box-shadow: 
                    inset 8px 8px 16px hsl(var(--b3, var(--border)) / 0.5), 
                    inset -8px -8px 16px hsl(var(--b1, var(--background)) / 0.8);
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }
        
        return null;
    }

    private function handleAlignSelf(string $classPart, array $matches): ?array { $map = ['start'=>'flex-start', 'end'=>'flex-end']; return ['align-self' => $map[$matches[1]] ?? $matches[1]]; }
    private function handleJustifySelf(string $classPart, array $matches): ?array { $map = ['start'=>'flex-start', 'end'=>'flex-end']; return ['justify-self' => $map[$matches[1]] ?? $matches[1]]; }
    private function handlePlaceContent(string $classPart, array $matches): ?array { $map = ['start'=>'flex-start', 'end'=>'flex-end', 'between'=>'space-between', 'around'=>'space-around', 'evenly'=>'space-evenly']; return ['place-content' => $map[$matches[1]] ?? $matches[1]]; }
    private function handlePlaceItems(string $classPart, array $matches): ?array { $map = ['start'=>'flex-start', 'end'=>'flex-end']; return ['place-items' => $map[$matches[1]] ?? $matches[1]]; }
    private function handlePlaceSelf(string $classPart, array $matches): ?array { $map = ['start'=>'flex-start', 'end'=>'flex-end']; return ['place-self' => $map[$matches[1]] ?? $matches[1]]; }
    private function handleFlex(string $classPart, array $matches): ?array { $map = ['1'=>'1 1 0%', 'auto'=>'1 1 auto', 'initial'=>'0 1 auto', 'none'=>'none']; return ['flex' => $map[$matches[1]] ?? null];}
    private function handleFlexGrow(string $classPart, array $matches): ?array { return ['flex-grow' => $matches[1] ?? '1'];}
    private function handleFlexShrink(string $classPart, array $matches): ?array { return ['flex-shrink' => $matches[1] ?? '1'];}
    private function handleFlexBasis(string $classPart, array $matches): ?array { $val = $this->parseNumericValue($matches[1], 'flexBasis', ['defaultUnit' => '%']); return $val ? ['flex-basis' => $val] : null; }
    private function handleOrder(string $classPart, array $matches): ?array { $map=['first'=>'-9999','last'=>'9999','none'=>'0']; return ['order' => $map[$matches[1]] ?? $matches[1]];}
    private function handleOrderArbitrary(string $classPart, array $matches): ?array { return ['order' => $matches[1]];}
    private function handleGridAutoFlow(string $classPart, array $matches): ?array { return ['grid-auto-flow' => str_replace('-', ' ', $matches[1])]; }
    private function handleGridAutoColumns(string $baseClassPart, array $matches): ?array { $map = ['auto'=>'auto', 'min'=>'min-content', 'max'=>'max-content', 'fr'=>'minmax(0, 1fr)']; return isset($map[$matches[1]]) ? ['grid-auto-columns' => $map[$matches[1]]] : null; }
    private function handleGridAutoRows(string $baseClassPart, array $matches): ?array { $map = ['auto'=>'auto', 'min'=>'min-content', 'max'=>'max-content', 'fr'=>'minmax(0, 1fr)']; return isset($map[$matches[1]]) ? ['grid-auto-rows' => $map[$matches[1]]] : null; }
    private function handleGridColumnCount(string $baseClassPart, array $matches): ?array {
        $breakpoint = $matches[1] ?? null;
        $count = $matches[2];

        if (!is_numeric($count) || $count < 1) return null;
        
        $styles = ['--bs-columns' => $count];

        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            return [
                'query' => "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})",
                'style' => $styles
            ];
        }

        return $styles;
    }
    private function handleGridRowCount(string $baseClassPart, array $matches): ?array {
        $breakpoint = $matches[1] ?? null;
        $count = $matches[2];

        if (!is_numeric($count) || $count < 1) return null;
        
        $styles = ['--bs-rows' => $count];

        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            return [
                'query' => "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})",
                'style' => $styles
            ];
        }

        return $styles;
    }
    private function handleGap(string $baseClassPart, array $matches): ?array { $axis = $matches[1] ? rtrim($matches[1], '-') : null; $valueKey = $matches[2]; $cssValue = $this->parseNumericValue($valueKey, 'spacing'); if ($cssValue === null) return null; if ($axis === 'x') return ['column-gap' => $cssValue]; if ($axis === 'y') return ['row-gap' => $cssValue]; return ['gap' => $cssValue]; }
    private function handleGridTemplateColumns(string $baseClassPart, array $matches): ?array { $valueKey = $matches[1]; if ($valueKey === 'subgrid') return ['grid-template-columns' => 'subgrid']; if (strpos($valueKey, '[') === 0 && strpos($valueKey, ']') === strlen($valueKey)-1) { return ['grid-template-columns' => str_replace('_', ' ', trim($valueKey, '[]'))]; } $val = $this->lookupThemeValue('gridTemplateColumns', $valueKey); return $val ? ['grid-template-columns' => $val] : null; }
    private function handleGridTemplateRows(string $baseClassPart, array $matches): ?array { $valueKey = $matches[1]; if ($valueKey === 'subgrid') return ['grid-template-rows' => 'subgrid']; if (strpos($valueKey, '[') === 0 && strpos($valueKey, ']') === strlen($valueKey)-1) { return ['grid-template-rows' => str_replace('_', ' ', trim($valueKey, '[]'))]; } $val = $this->lookupThemeValue('gridTemplateRows', $valueKey); return $val ? ['grid-template-rows' => $val] : null; }
    private function handleGridColumn(string $baseClassPart, array $matches): ?array { $value = $matches[1]; if ($value === 'auto') return ['grid-column' => 'auto']; if (strpos($value, 'span-') === 0) { $span = substr($value, strlen('span-')); if ($span === 'full') return ['grid-column' => '1 / -1']; return is_numeric($span) ? ['grid-column' => "span {$span} / span {$span}"] : null; } if (strpos($value, 'start-') === 0 && is_numeric(substr($value, strlen('start-')))) return ['grid-column-start' => substr($value, strlen('start-'))]; if (strpos($value, 'end-') === 0 && is_numeric(substr($value, strlen('end-')))) return ['grid-column-end' => substr($value, strlen('end-'))]; return null; }
    private function handleColumn(string $baseClassPart, array $matches): ?array {
        $breakpoint = $matches[1] ?? null;
        $size = $matches[2] ?? null;

        $styles = [
            'width' => '100%',
            'padding-right' => 'calc(var(--bs-gutter-x) * .5)',
            'padding-left'  => 'calc(var(--bs-gutter-x) * .5)',
        ];
        
        if (!$size) { // Plain .col
            $styles['flex-grow'] = 1;
            $styles['flex-basis'] = 0;
            $styles['max-width'] = '100%';
        } else {
            $styles['flex-shrink'] = 0;
            if ($size === 'auto') {
                $styles['width'] = 'auto';
            } elseif (is_numeric($size)) {
                $styles['width'] = round(((int)$size / 12) * 100, 8) . '%';
            }
        }

        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            return [ 'query' => "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})", 'style' => $styles ];
        }
        return $styles;
    }

    private function handleGridTemplateAreas(string $baseClassPart, array $matches): ?array {
        $areasString = $matches[1];
        $rows = explode('_', $areasString);
        
        $formattedRows = [];
        foreach ($rows as $row) {
            $areasInRow = explode('-', $row);
            $formattedRows[] = '"' . implode(' ', $areasInRow) . '"';
        }
        
        $cssValue = implode(' ', $formattedRows);

        return ['grid-template-areas' => $cssValue];
    }

    private function handleGridArea(string $baseClassPart, array $matches): ?array {
        $areaName = $matches[1];
        return ['grid-area' => $areaName];
    }

    private function handleGridColsFluid(string $baseClassPart, array $matches): ?array {
        $size = $matches[1] ?? 'md';

        $minWidth = match($size) {
            'xs' => '150px',
            'sm' => '200px',
            'md' => '250px',
            'lg' => '300px',
            'xl' => '350px',
            '2xl'=> '400px',
            default => null
        };

        if ($minWidth === null) {
            if (str_starts_with($size, '[') && str_ends_with($size, ']')) {
                $minWidth = str_replace('_', ' ', trim($size, '[]'));
            } else {
                $minWidth = $this->parseNumericValue($size, 'spacing');
            }
        }

        if (!$minWidth) return null;

        $css = "repeat(auto-fit, minmax(min(100%, {$minWidth}), 1fr))";

        return ['grid-template-columns' => $css];
    }

    private function handleCssGridContainer(string $baseClassPart, array $matches): ?array {
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        $css = <<<CSS
        {$selector} {
            display: grid;
            grid-template-rows: repeat(var(--bs-rows, 1), 1fr);
            grid-template-columns: repeat(var(--bs-columns, 12), 1fr);
            gap: var(--bs-gap, 1.5rem);
        }
        CSS;

        return ['layer' => 'components', 'style' => $css];
    }

    private function handleCssGridColumn(string $baseClassPart, array $matches): ?array {
        $breakpoint = $matches[1] ?? null;
        $span = $matches[2];

        if (!is_numeric($span) || $span < 1) return null;
        
        // --- মূল পরিবর্তন এখানে ---
        $styles = ['grid-column' => "auto / span {$span}"];

        // কাস্টম সিলেক্টর: .grid > .g-col-*
        $customSelector = '.grid > .' . $this->escapeClassNameForSelector($baseClassPart);

        $result = [
            'style' => $styles,
            '_customSelector' => $customSelector
        ];

        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            $result['query'] = "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})";
        }

        return $result;
    }

    private function handleCssGridStart(string $baseClassPart, array $matches): ?array {
        $breakpoint = $matches[1] ?? null;
        $startLine = $matches[2];

        if (!is_numeric($startLine) || $startLine < 1) return null;

        $styles = ['grid-column-start' => $startLine];

        $customSelector = '.grid > .' . $this->escapeClassNameForSelector($baseClassPart);

        $result = [
            'style' => $styles,
            '_customSelector' => $customSelector
        ];

        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            $result['query'] = "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})";
        }

        return $result;
    }

    private function handleCssGridEnd(string $baseClassPart, array $matches): ?array {
        $breakpoint = $matches[1] ?? null;
        $endLine = $matches[2];

        if (!is_numeric($endLine) || $endLine < 1) {
            return null;
        }

        $styles = ['grid-column-end' => $endLine];
        
        $customSelector = '.grid > .' . $this->escapeClassNameForSelector($baseClassPart);

        $result = [
            'style' => $styles,
            '_customSelector' => $customSelector
        ];

        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            $result['query'] = "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})";
        }

        return $result;
    }

    private function handleCssGridRow(string $baseClassPart, array $matches): ?array {
        $breakpoint = $matches[1] ?? null;
        $span = $matches[2];

        if (!is_numeric($span) || $span < 1) return null;
        
        $styles = ['grid-row' => "span {$span} / span {$span}"];

        // কাস্টম সিলেক্টর: .grid > .g-row-*
        $customSelector = '.grid > .' . $this->escapeClassNameForSelector($baseClassPart);

        $result = [
            'style' => $styles,
            '_customSelector' => $customSelector
        ];

        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            $result['query'] = "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})";
        }

        return $result;
    }

    private function handleCssGridRowPlacement(string $baseClassPart, array $matches): ?array {
        $type = $matches[1]; // start or end
        $breakpoint = $matches[2] ?? null;
        $line = $matches[3];

        if (!is_numeric($line) || $line < 1) return null;

        $styles = ["grid-row-{$type}" => $line];

        $customSelector = '.grid > .' . $this->escapeClassNameForSelector($baseClassPart);

        $result = [
            'style' => $styles,
            '_customSelector' => $customSelector
        ];

        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            $result['query'] = "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})";
        }

        return $result;
    }
    private function handleGridRow(string $baseClassPart, array $matches): ?array { $value = $matches[1]; if ($value === 'auto') return ['grid-row' => 'auto']; if (strpos($value, 'span-') === 0) { $span = substr($value, strlen('span-')); if ($span === 'full') return ['grid-row' => '1 / -1']; return is_numeric($span) ? ['grid-row' => "span {$span} / span {$span}"] : null; } if (strpos($value, 'start-') === 0 && is_numeric(substr($value, strlen('start-')))) return ['grid-row-start' => substr($value, strlen('start-'))]; if (strpos($value, 'end-') === 0 && is_numeric(substr($value, strlen('end-')))) return ['grid-row-end' => substr($value, strlen('end-'))]; return null; }
    private function handleRowCols(string $baseClassPart, array $matches): ?array {
        $breakpoint = $matches[1] ?? null;
        $count = $matches[2] ?? null;

        if ($count === null && preg_match('/^row-cols-(\d+|auto)$/', $baseClassPart, $baseMatches)) {
            $count = $baseMatches[1];
        }
        if ($count === null) return null;

        $styles = [];
        if ($count === 'auto') {
            $styles['flex'] = '0 0 auto';
            $styles['width'] = 'auto';
        } elseif (is_numeric($count) && $count > 0) {
            $width = round((1 / (int)$count) * 100, 8) . '%';
            $styles['flex'] = "0 0 {$width}";
            $styles['width'] = $width;
        } else { return null; }

        $result = [
            'style' => $styles,
            '_customSelector' => '.' . $this->escapeClassNameForSelector($baseClassPart) . ' > .col'
        ];

        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            $result['query'] = "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})";
        }
        return $result;
    }
    private function handleRow(string $baseClassPart, array $matches): ?array {
        return [
            'display' => 'flex',
            'flex-wrap' => 'wrap',
            '--bs-gutter-x' => '1.5rem',
            '--bs-gutter-y' => '0',
            'margin-top' => 'calc(-1 * var(--bs-gutter-y))',
            'margin-right' => 'calc(-0.5 * var(--bs-gutter-x))',
            'margin-left' => 'calc(-0.5 * var(--bs-gutter-x))',
        ];
    }
    private function handleSpacing(string $baseClassPart, array $matches): ?array {
        $isNegative = strpos($baseClassPart, '-') === 0;
        
        $type = $matches[1]; 
        $valueKey = $matches[2];

        $cssValue = $this->parseNumericValue($valueKey, 'spacing', ['allowNegative' => $isNegative]);
        if ($cssValue === null) {
            return null;
        }

        if ($isNegative && $cssValue !== 'auto' && $cssValue !== '0px' && $cssValue !== '0' ) {
            if (strpos($cssValue, '-') !== 0 && (is_numeric(substr($cssValue, 0, 1)) || $cssValue[0] === '.' || strpos($cssValue, 'var(') === 0 || strpos($cssValue, 'calc(') === 0) ) { 
                $cssValue = '-' . $cssValue;
            }
        }
        
        $properties = [];
        $map = [
            'p'  => ['padding'], 'pt' => ['padding-top'], 'pr' => ['padding-right'], 'pb' => ['padding-bottom'], 'pl' => ['padding-left'], 'ps' => ['padding-inline-start'], 'pe' => ['padding-inline-end'],
            'px' => ['padding-left', 'padding-right'], 'py' => ['padding-top', 'padding-bottom'],
            'ps' => ['padding-inline-start'],
            'pe' => ['padding-inline-end'],
            'm'  => ['margin'], 'mt' => ['margin-top'], 'mr' => ['margin-right'], 'mb' => ['margin-bottom'], 'ml' => ['margin-left'], 'ms' => ['margin-inline-start'], 'me' => ['margin-inline-end'],
            'mx' => ['margin-left', 'margin-right'], 'my' => ['margin-top', 'margin-bottom'],
            'ms' => ['margin-inline-start'],
            'me' => ['margin-inline-end']
        ];

        if (isset($map[$type])) {
            foreach ($map[$type] as $prop) {
                $properties[$prop] = $cssValue;
            }
            return $properties;
        }
        return null;
    }
    private function handleGutters(string $baseClassPart, array $matches): ?array {
        $axis = $matches[1] ?? '';
        $breakpoint = $matches[2] ?? null;
        $size = $matches[3];

        $spacingMap = [ '0'=>'0', '1'=>'1', '2'=>'2', '3'=>'4', '4'=>'6', '5'=>'12' ];
        if (!isset($spacingMap[$size])) return null;
        
        $tailwindSpacing = $this->lookupThemeValue('spacing', $spacingMap[$size]);
        if ($tailwindSpacing === null) return null;
        
        $styles = [];
        if ($axis === 'x' || $axis === '') $styles['--bs-gutter-x'] = $tailwindSpacing;
        if ($axis === 'y' || $axis === '') $styles['--bs-gutter-y'] = $tailwindSpacing;
        
        $customSelector = '.row.' . $this->escapeClassNameForSelector($baseClassPart);

        $result = [
            'style' => $styles,
            '_customSelector' => $customSelector
        ];

        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            $result['query'] = "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})";
        }

        return $result;
    }
    private function handleMarginAuto(string $baseClassPart, array $matches): ?array {
        $direction = $matches[1];    // s, e, x, t, b, y, l, r
        $breakpoint = $matches[2] ?? null; // sm, md, lg, xl, xxl or null

        $styles = [];

        switch ($direction) {
            case 's': case 'l': $styles['margin-left'] = 'auto'; break;
            case 'e': case 'r': $styles['margin-right'] = 'auto'; break;
            case 'x': $styles['margin-left'] = 'auto'; $styles['margin-right'] = 'auto'; break;
            case 't': $styles['margin-top'] = 'auto'; break;
            case 'b': $styles['margin-bottom'] = 'auto'; break;
            case 'y': $styles['margin-top'] = 'auto'; $styles['margin-bottom'] = 'auto'; break;
            default: return null;
        }
        
        // যদি রেসপন্সিভ হয়, মিডিয়া কোয়েরি সহ রিটার্ন করুন
        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            return [
                'layer' => 'utilities',
                'query' => "@media (min-width: {$this->config['theme']['screens'][$breakpoint]})",
                'style' => $styles,
            ];
        }

        // Base class (e.g., ms-auto)
        return $styles;
    }
    private function handleSizing(string $baseClassPart, array $matches): ?array { $propertyType = $matches[1]; $valueKey = $matches[2]; $cssPropertyMap = [ 'w' => 'width', 'h' => 'height', 'min-w' => 'min-width', 'max-w' => 'max-width', 'min-h' => 'min-height', 'max-h' => 'max-height', ]; if (!isset($cssPropertyMap[$propertyType])) return null; $cssProperty = $cssPropertyMap[$propertyType]; $themeSection = ''; if ($propertyType === 'w') $themeSection = 'width'; elseif ($propertyType === 'h') $themeSection = 'height'; elseif ($propertyType === 'min-w') $themeSection = 'minWidth'; elseif ($propertyType === 'max-w') $themeSection = 'maxWidth'; elseif ($propertyType === 'min-h') $themeSection = 'minHeight'; elseif ($propertyType === 'max-h') $themeSection = 'maxHeight'; $cssValue = $this->parseNumericValue($valueKey, $themeSection); if ($cssValue === null) return null; return [$cssProperty => $cssValue]; }
    private function handleUnifiedSize(string $baseClassPart, array $matches): ?array {
        $prefix = $matches[1];
        $valueKey = $matches[2];
        $cssValue = $this->parseNumericValue($valueKey, 'spacing');
        if ($cssValue === null) {
            $cssValue = $this->parseNumericValue($valueKey, 'width');
        }
        if ($cssValue === null) {
            $cssValue = $this->parseNumericValue($valueKey, 'height');
        }

        if ($cssValue !== null) {
            $widthProp = $prefix . 'width';
            $heightProp = $prefix . 'height';
            
            return [
                $widthProp => $cssValue,
                $heightProp => $cssValue,
            ];
        }

        return null;
    }
    private function handleFontSizeKeyword(string $baseClassPart, array $matches): ?array { $sizeKey = $matches[1]; $themeEntry = $this->lookupThemeValue('fontSize', $sizeKey, true); if (is_array($themeEntry) && isset($themeEntry[0])) { $styles = ['font-size' => $themeEntry[0]]; if (isset($themeEntry[1]['lineHeight'])) { $styles['line-height'] = $themeEntry[1]['lineHeight']; } return $styles; } elseif (is_string($themeEntry)) { return ['font-size' => $themeEntry]; } return null; }
    private function handleTextClamp(string $baseClassPart, array $matches): ?array {
        $clampArgs = $matches[1];
        $clampArgs = str_replace('_', ' ', $clampArgs);
        $clampArgs = preg_replace('/,(?!\s)/', ', ', $clampArgs);
        $finalValue = "clamp({$clampArgs})";
        return ['font-size' => $finalValue];
    }
    private function handleFontSizeArbitrary(string $baseClassPart, array $matches): ?array {
        $arbitraryValue = $matches[1];
        if (str_starts_with($arbitraryValue, 'length:') || str_starts_with($arbitraryValue, 'size:')) {
            $propValue = substr($arbitraryValue, strpos($arbitraryValue, ':') + 1);
            return ['font-size' => $propValue];
        }
        if (preg_match("/^theme\(fontSize\.([^)]+)\)$/", $arbitraryValue, $themeRefMatch)) {
            return $this->handleFontSizeKeyword('', [$baseClassPart, $themeRefMatch[1]]);
        }
        $parts = explode('/', $arbitraryValue);
        $fontSize = $this->parseNumericValue('[' . $parts[0] . ']', 'fontSize', ['defaultUnit' => '']);

        if (!$fontSize) return null;
        
        $styles = ['font-size' => $fontSize];
        if (isset($parts[1])) {
            $lineHeight = $this->parseNumericValue('[' . $parts[1] . ']', 'lineHeight', ['defaultUnit' => '']);
            if ($lineHeight) $styles['line-height'] = $lineHeight;
        }
        return $styles;
    }

    private function handleTextLength(string $baseClassPart, array $matches): ?array {
        $value = $matches[1];

        $fontSize = null;
        $lineHeight = null;
        
        if (str_contains($value, '/')) {
            list($fontSizePart, $lineHeightPart) = explode('/', $value, 2);
            
            $fontSize = $this->parseNumericValue("[{$fontSizePart}]", 'fontSize', ['defaultUnit' => 'px']);
            $lineHeight = $this->parseNumericValue("[{$lineHeightPart}]", 'lineHeight', ['defaultUnit' => '']);
        } else {
            $fontSize = $this->parseNumericValue("[{$value}]", 'fontSize', ['defaultUnit' => 'px']);
        }
        
        if ($fontSize === null) {
            return null;
        }

        $styles = ['font-size' => $fontSize];

        if ($lineHeight === null) {
            if (preg_match('/^(\d*\.?\d+)(rem|em|px)$/', $fontSize, $sizeMatches)) {
                $numericSize = (float)$sizeMatches[1];
                $unit = $sizeMatches[2];
                
                if ($unit === 'rem' || $unit === 'em') {
                    $lineHeight = round($numericSize * 1.5, 4); 
                } else {
                    $lineHeight = round($numericSize * 1.5) . 'px';
                }
            } else {
                $lineHeight = '1.5';
            }
        }
        
        if ($lineHeight !== null) {
            $styles['line-height'] = $lineHeight;
        }

        return $styles;
    }
    
    private function handleTextAlign(string $baseClassPart, array $matches): ?array { return ['text-align' => $matches[1]]; }
    private function handleTextColor(string $baseClassPart, array $matches): ?array {
        $valuePart = $matches[1];
        // Arbitrary var() এর জন্য বিশেষ চেক
        if (strpos($valuePart, '[var(') === 0) {
            $arbitraryValue = trim($valuePart, '[]');
            return ['color' => $arbitraryValue];
        }
        $styles = [];
        $styles['--tw-text-opacity'] = '1'; // Default text opacity

        $opacityFromColorClassShorthand = null;
        $colorKey = $valuePart;

        if (preg_match('/^([a-zA-Z0-9-]+(?:\[.+\])?|\w+)(?:\/(?:(\d{1,3})(?!%)|\[\.?(\d+)\]))$/', $valuePart, $opacityMatches)) {
            $colorKey = $opacityMatches[1];
            $opacityValStr = $opacityMatches[2] ?? $opacityMatches[3] ?? null;
            if ($opacityValStr !== null) {
                $opacityFromColorClassShorthand = (isset($opacityMatches[3])) ? (float)("0." . $opacityValStr) : (intval($opacityValStr) / 100);
                $opacityFromColorClassShorthand = round(max(0, min(1, $opacityFromColorClassShorthand)), 2);
                $styles['--tw-text-opacity'] = (string)$opacityFromColorClassShorthand;
            }
        }
        $colorString = $this->parseColorValue($colorKey);

        if ($colorString) {
            if (strpos($colorString, 'var(') !== false || strtolower($colorString) === 'transparent' || strtolower($colorString) === 'currentcolor' || strtolower($colorString) === 'inherit') {
                $styles['color'] = $colorString;
            } elseif (preg_match('/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $colorString, $hexMatch)) {
                $hex = $hexMatch[1];
                if (strlen($hex) == 3) { $r = hexdec($hex[0].$hex[0]); $g = hexdec($hex[1].$hex[1]); $b = hexdec($hex[2].$hex[2]); }
                else { $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2)); }
                $styles['color'] = "rgb({$r} {$g} {$b} / var(--tw-text-opacity))";
            } elseif (preg_match('/^rgb\((\d+,\s*\d+,\s*\d+)\)$/', $colorString, $rgbMatches)) {
                $styles['color'] = "rgb({$rgbMatches[1]} / var(--tw-text-opacity))";
            } else {
                $styles['color'] = $colorString;
            }
            return $styles;
        }
        return null;
    }
    private function handleTextOpacity(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1];
        $opacityValue = $this->parseNumericValue($valueKey, 'opacity', ['numericIsPercentage' => true, 'defaultUnit'=>'', 'allowArbitrary'=>true]);
        return $opacityValue !== null ? ['--tw-text-opacity' => $opacityValue] : null;
    }
    private function handleFontWeight(string $baseClassPart, array $matches): ?array { $weightKey = $matches[1]; $weightValue = $this->lookupThemeValue('fontWeight', $weightKey); return $weightValue ? ['font-weight' => $weightValue] : null; }
    private function handleFontFamily(string $baseClassPart, array $matches): ?array {
        $fontKeyOrArbitrary = $matches[1]; // e.g., "sans", "serif", "display", or "[My_Custom_Font,sans-serif]"

        $fontStack = null;

        // Check for arbitrary font family: font-[Arial,sans-serif] or font-['My_Font_With_Spaces',_sans-serif]
        if (strpos($fontKeyOrArbitrary, '[') === 0 && str_ends_with($fontKeyOrArbitrary, ']')) {
            $arbitraryValue = trim($fontKeyOrArbitrary, '[]');
            // Replace underscores used for spaces within font names back to spaces
            // e.g., ['Font_Name_With_Spaces',_sans-serif] -> "Font Name With Spaces", sans-serif
            $fontStackString = preg_replace_callback(
                '/\'([^\']+)\'|([a-zA-Z0-9-]+)/', // Matches quoted font names or unquoted ones
                function ($m) {
                    if (!empty($m[1])) { // Quoted font name
                        return '"' . str_replace('_', ' ', $m[1]) . '"';
                    } elseif (!empty($m[2])) { // Unquoted font name
                        return str_replace('_', ' ', $m[2]);
                    }
                    return '';
                },
                $arbitraryValue
            );
            // Ensure commas are followed by a space if not already
            $fontStackString = preg_replace('/,(?!\s)/', ', ', $fontStackString);
            return ['font-family' => $fontStackString];
        }

        // Lookup predefined font family from theme
        if (isset($this->config['theme']['fontFamily'][$fontKeyOrArbitrary])) {
            $fontStack = $this->config['theme']['fontFamily'][$fontKeyOrArbitrary];
            if (is_array($fontStack)) {
                // Ensure font names with spaces are quoted
                $processedStack = array_map(function ($fontName) {
                    if (strpos($fontName, ' ') !== false && strpos($fontName, '"') !== 0 && strpos($fontName, "'") !== 0) {
                        return '"' . $fontName . '"';
                    }
                    return $fontName;
                }, $fontStack);
                return ['font-family' => implode(', ', $processedStack)];
            } elseif (is_string($fontStack)) { // If a single font string is defined in theme
                return ['font-family' => $fontStack];
            }
        }
        
        return null; // Font family not found
    }
    private function handleLineHeight(string $baseClassPart, array $matches): ?array { $valueKey = $matches[1]; $val = $this->lookupThemeValue('lineHeight', $valueKey) ?? (is_numeric($valueKey) ? $this->parseNumericValue($valueKey, 'lineHeight', ['defaultUnit'=>'']) : null); return $val ? ['line-height' => $val] : null; }
    private function handleLineHeightArbitrary(string $baseClassPart, array $matches): ?array { $val = $this->parseNumericValue('['.$matches[1].']', 'lineHeight', ['defaultUnit'=>'']); return $val ? ['line-height' => $val] : null; }
    private function handleLetterSpacingKeyword(string $baseClassPart, array $matches): ?array { $val = $this->lookupThemeValue('letterSpacing', $matches[1]); return $val ? ['letter-spacing' => $val] : null; }
    private function handleLetterSpacingArbitrary(string $baseClassPart, array $matches): ?array { $val = $this->parseNumericValue('['.$matches[1].']', 'letterSpacing', ['defaultUnit'=>'em']); return $val ? ['letter-spacing' => $val] : null; }
    private function handleTextDecorationLine(string $baseClassPart, array $matches): ?array { return ['text-decoration-line' => $matches[1]]; }
    private function handleTextDecorationStyle(string $baseClassPart, array $matches): ?array { return ['text-decoration-style' => $matches[1]]; }
    private function handleTextDecorationThicknessKeyword(string $baseClassPart, array $matches): ?array { $val = $this->lookupThemeValue('textDecorationThickness', $matches[1]); return $val ? ['text-decoration-thickness' => $val] : null; }
    private function handleTextDecorationThicknessArbitraryOrColor(string $baseClassPart, array $matches): ?array { $valuePart = $matches[1]; $color = $this->parseColorValue($valuePart); if ($color) return ['text-decoration-color' => $color]; if(strpos($valuePart, '[') === 0) { $thickness = $this->parseNumericValue($valuePart, 'textDecorationThickness', ['defaultUnit'=>'px']); if ($thickness) return ['text-decoration-thickness' => $thickness]; } return null; }
    private function handleTextUnderlineOffsetKeyword(string $baseClassPart, array $matches): ?array { $val = $this->lookupThemeValue('textUnderlineOffset', $matches[1]); return $val ? ['text-underline-offset' => $val] : null; }
    private function handleTextUnderlineOffsetArbitrary(string $baseClassPart, array $matches): ?array { $val = $this->parseNumericValue('['.$matches[1].']', 'textUnderlineOffset', ['defaultUnit'=>'px']); return $val ? ['text-underline-offset' => $val] : null; }
    private function handleTextTransform(string $baseClassPart, array $matches): ?array { return ['text-transform' => $matches[1]]; }
    private function handleTextOverflow(string $baseClassPart, array $matches): ?array { $val = $matches[1]; if ($val === 'truncate') return ['overflow' => 'hidden', 'text-overflow' => 'ellipsis', 'white-space' => 'nowrap']; if ($val === 'text-ellipsis') return ['text-overflow' => 'ellipsis']; if ($val === 'text-clip') return ['text-overflow' => 'clip']; return null; }
    private function handleLineClamp(string $baseClassPart, array $matches): ?array {
        $value = $matches[1];

        if ($value === 'none') {
            // line-clamp-none ব্যবহার করে line-clamp ইফেক্ট তুলে দেওয়া হয়
            return [
                'overflow' => 'visible',
                'display' => 'block',
                '-webkit-box-orient' => 'horizontal',
                '-webkit-line-clamp' => 'none',
            ];
        }

        if (is_numeric($value) && (int)$value > 0) {
            $numberOfLines = (int)$value;
            // line-clamp প্রয়োগ করার জন্য প্রয়োজনীয় CSS প্রপার্টিগুলো
            return [
                'overflow' => 'hidden',
                'display' => '-webkit-box',
                '-webkit-box-orient' => 'vertical',
                '-webkit-line-clamp' => $numberOfLines,
            ];
        }

        return null;
    }
    private function handleWhitespace(string $baseClassPart, array $matches): ?array { return ['white-space' => $matches[1]]; }
    private function handleWordBreak(string $baseClassPart, array $matches): ?array {
        $val = $matches[1];
        if ($val === 'normal') return ['overflow-wrap' => 'normal', 'word-break' => 'normal'];
        if ($val === 'words') return ['overflow-wrap' => 'break-word'];
        if ($val === 'all') return ['word-break' => 'break-all'];
        if ($val === 'keep') return ['word-break' => 'keep-all'];
        return null;
    }
    private function handleFontStyle(string $baseClassPart, array $matches): ?array {
        if ($baseClassPart === 'italic') {
            return ['font-style' => 'italic'];
        } elseif ($baseClassPart === 'not-italic') {
            return ['font-style' => 'normal'];
        }
        return null;
    }
    private function handleFontSmoothing(string $baseClassPart, array $matches): ?array {  if ($baseClassPart === 'antialiased') { return ['-webkit-font-smoothing' => 'antialiased', '-moz-osx-font-smoothing' => 'grayscale']; } elseif ($baseClassPart === 'subpixel-antialiased') { return ['-webkit-font-smoothing' => 'auto', '-moz-osx-font-smoothing' => 'auto']; } return null;}
    private function handleLink(string $baseClassPart, array $matches): ?array{
        // Determine the variant name from the class (e.g., 'primary', 'info', '[#ff0000]', or 'DEFAULT')
        $variantName = null;
        if (preg_match('/^\[(.+?)\]-link$/', $baseClassPart, $arbitraryMatches)) {
            $variantName = '[' . $arbitraryMatches[1] . ']';
        } elseif (isset($matches[1]) && $matches[1] !== '') {
            $variantName = $matches[1];
        } elseif ($baseClassPart === 'link') {
            $variantName = 'DEFAULT';
        }

        if ($variantName === null) {
            return null;
        }

        $baseColor = null;
        $hoverColor = null;
        $focusColor = null;
        $activeColor = null;

        if (strpos($variantName, '[') === 0) {
            // --- Arbitrary Color --- e.g., class="[#9933ff]-link"
            $parsedColor = $this->parseColorValue($variantName);
            if ($parsedColor) {
                $baseColor = $parsedColor;
                // For arbitrary colors, a common hover effect is a slight opacity change if no other color is defined
                // A more advanced approach would be to calculate a darker/lighter shade.
                // For now, let's keep it simple: no color change, but underline will be added.
                $hoverColor = $baseColor;
            }
        } elseif (isset($this->config['theme']['link'][$variantName])) {
            // --- Predefined Theme Variant --- e.g., class="info-link"
            $linkConfig = $this->lookupThemeValue('link', $variantName);
            if (is_array($linkConfig)) {
                // Resolve the color string (e.g., #hex, rgb(), hsl(), var() itself) from the theme config
                $baseColor = $this->resolveThemeValue($linkConfig['color'] ?? null);
                // Fallback chain for hover/focus/active: use its own key -> or hover key -> or base color key.
                $hoverColor = $this->resolveThemeValue($linkConfig['hover'] ?? $linkConfig['color'] ?? null);
                $focusColor = $this->resolveThemeValue($linkConfig['focus'] ?? $linkConfig['hover'] ?? $linkConfig['color'] ?? null);
                $activeColor = $this->resolveThemeValue($linkConfig['active'] ?? $linkConfig['hover'] ?? $linkConfig['color'] ?? null);
            }
        } else {
            // --- Direct Color Name as Variant --- e.g., class="red-500-link"
            $potentialColor = $this->parseColorValue($variantName);
            if ($potentialColor) {
                $baseColor = $potentialColor;
                $hoverColor = $potentialColor; // No specific hover defined, use the same color
            }
        }

        if ($baseColor === null) {
            return null; // Exit if no valid color found for the link
        }
        
        // --- Build the CSS rules directly as a string ---
        $mainSelector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $cssString = "";

        // Base Styles
        $cssString .= "{$mainSelector} {\n";
        $cssString .= "  color: {$baseColor};\n";
        $cssString .= "  text-decoration-line: none;\n"; // No underline by default
        $cssString .= "  transition-property: color, text-decoration-color, opacity;\n";
        $cssString .= "  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);\n";
        $cssString .= "  transition-duration: 150ms;\n";
        $cssString .= "  cursor: pointer;\n";
        $cssString .= "}\n";

        // Hover Styles
        if ($hoverColor) {
            $cssString .= "{$mainSelector}:hover {\n";
            $cssString .= "  color: {$hoverColor};\n";
            $cssString .= "  text-decoration-line: underline;\n";
            $cssString .= "  cursor: pointer;\n";
            $cssString .= "}\n";
        }

        // Focus Styles
        if ($focusColor) {
            $focusSelector = $focusColor === $hoverColor ? "{$mainSelector}:focus" : "{$mainSelector}:focus";
            if($focusColor !== $hoverColor || !isset($styles['_hoverStyles'])){ // Avoid duplicate rules if focus=hover
                $cssString .= "{$focusSelector} {\n";
                $cssString .= "  color: {$focusColor};\n";
                $cssString .= "  text-decoration-line: underline;\n";
                $cssString .= "  cursor: pointer;\n";
                $cssString .= "}\n";
            }
        }

        // Active Styles
        if ($activeColor) {
            $activeSelector = $activeColor === $hoverColor ? "{$mainSelector}:active" : "{$mainSelector}:active";
            if($activeColor !== $hoverColor || !isset($styles['_hoverStyles'])){ // Avoid duplicate rules if active=hover
                $cssString .= "{$activeSelector} {\n";
                $cssString .= "  color: {$activeColor};\n";
                $cssString .= "  cursor: pointer;\n";
                $cssString .= "}\n";
            }
        }

        // Return as a structured array with the generated CSS string
        return ['layer' => 'components', 'style' => $cssString];
    }

    private function handleFontVariantNumeric(string $baseClassPart, array $matches): ?array {
        $variantKey = $matches[1];
        
        // 'normal-nums' ক্লাসকে 'normal' ভ্যারিয়েন্টে ম্যাপ করা হচ্ছে
        if ($variantKey === 'normal-nums') {
            $variantKey = 'normal';
        }

        $cssValue = $this->lookupThemeValue('fontVariantNumeric', $variantKey);

        if ($cssValue) {
            // CSS ভেরিয়েবল ব্যবহার করে একাধিক ভ্যারিয়েন্ট একসাথে কাজ করানো যায়
            // যেমন: tabular-nums slashed-zero
            $varName = '--tw-numeric-' . str_replace('-nums', '', $variantKey);
            
            return [
                $varName => $cssValue,
                'font-variant-numeric' => 'var(--tw-ordinal) var(--tw-slashed-zero) var(--tw-lining) var(--tw-oldstyle) var(--tw-proportional) var(--tw-tabular) var(--tw-diagonal-fractions) var(--tw-stacked-fractions)',
            ];
        }
        
        return null;
    }
    
    private function handleTextIndent(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1];
        $indentValue = $this->parseNumericValue($valueKey, 'textIndent');

        if ($indentValue !== null) {
            return ['text-indent' => $indentValue];
        }
        
        return null;
    }
    
    private function handleTextWrap(string $baseClassPart, array $matches): ?array {
        $value = $matches[1]; // 'balance' or 'pretty'
        return ['text-wrap' => $value];
    }

    private function handleTextStroke(string $baseClassPart, array $matches): ?array {
        $valuePart = $matches[1];
        
        // প্রথমে চেক করা হচ্ছে এটি একটি প্রস্থ (width) কিনা
        $strokeWidth = $this->parseNumericValue($valuePart, 'textStrokeWidth');
        if ($strokeWidth !== null) {
            return [
                '-webkit-text-stroke-width' => $strokeWidth,
                'text-stroke-width' => $strokeWidth,
            ];
        }

        // যদি প্রস্থ না হয়, তাহলে এটি একটি রঙ (color) হিসেবে পার্স করার চেষ্টা করা হবে
        $strokeColor = $this->parseColorValue($valuePart);
        if ($strokeColor !== null) {
            return [
                '-webkit-text-stroke-color' => $strokeColor,
                'text-stroke-color' => $strokeColor,
            ];
        }

        return null;
    }

    private function handleLinkUnderlineColor(string $baseClassPart, array $matches): ?array {
        $colorKey = $matches[1]; // e.g., 'primary', 'red-500', 'opacity-50'

        // ১. যদি এটি 'opacity-*' হয়, তবে এটি কালার নয়, তাই স্কিপ করুন (handleDecorationOpacity এটি দেখবে)
        if (str_starts_with($colorKey, 'opacity-')) {
            return null;
        }

        // ২. কালার পার্স করার চেষ্টা করুন
        $colorValue = $this->parseColorValue($colorKey);

        if ($colorValue !== null) {
            // কালারের সাথে অপাসিটি ভেরিয়েবল যুক্ত করা (যাতে link-underline-opacity-* কাজ করে)
            $finalColor = $this->convertColorWithOpacityVar($colorValue, '--tw-decoration-opacity');
            
            return [
                'text-decoration-color' => $finalColor,
                // ডিফল্ট ডেকোরেশন স্টাইল সেট করা (যদি না থাকে)
                'text-decoration-line' => 'underline' 
            ];
        }

        return null;
    }

    private function handleInputGroup(string $baseClassPart, array $matches): ?array {
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        $css = <<<CSS
        /* --- Base Input Group --- */
        {$selector} {
            position: relative;
            display: flex;
            flex-wrap: nowrap;
            align-items: stretch;
            width: 100%;
        }
        /* --- Border Radius Handling --- */
        /* Target direct children, but EXCLUDE dropdown menus */
        {$selector} > :not(.dropdown-menu):not(:last-child) {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        {$selector} > :not(.dropdown-menu):not(:first-child) {
            margin-left: -1px;
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }

        /* --- Floating Label Inside Input Group --- */
        /* This is the key fix: Target the input INSIDE the floating container */
        {$selector} > .form-floating:not(:first-child) .form-control,
        {$selector} > .form-floating:not(:first-child) .form-select {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        {$selector} > .form-floating:not(:last-child) .form-control,
        {$selector} > .form-floating:not(:last-child) .form-select {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }

        /* --- Focus State --- */
        {$selector} > .form-control:focus,
        {$selector} > .form-select:focus,
        {$selector} > .btn:focus,
        {$selector} > .form-floating:focus-within {
            z-index: 10;
        }
        CSS;

        return ['layer' => 'components', 'style' => $css];
    }

    private function handleHasValidation(string $baseClassPart, array $matches): ?array {
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        // বুটস্ট্র্যাপের ভ্যালিডেশন লজিক (Sibling Selector ব্যবহার করে)
        $css = <<<CSS
        /* --- Form Validation State Handling --- */
        
        /* Show feedback when the sibling input is invalid */
        {$selector} .form-control:invalid ~ .invalid-feedback,
        {$selector} .form-control:invalid ~ .invalid-tooltip,
        {$selector} .form-select:invalid ~ .invalid-feedback,
        {$selector} .form-select:invalid ~ .invalid-tooltip {
            display: block;
        }

        /* Show feedback when the sibling input is valid (after interaction) */
        {$selector} .form-control:valid ~ .valid-feedback,
        {$selector} .form-control:valid ~ .valid-tooltip,
        {$selector} .form-select:valid ~ .valid-feedback,
        {$selector} .form-select:valid ~ .valid-tooltip {
            display: block;
        }

        /* --- Input Group with Floating Label Validation --- */
        /* Ensure the feedback message is positioned correctly */
        {$selector}.input-group .form-floating.is-invalid ~ .invalid-feedback {
            display: block;
        }
        {$selector}.input-group .form-floating.is-valid ~ .valid-feedback {
            display: block;
        }
        CSS;

        return ['layer' => 'components', 'style' => $css];
    }

    private function handleFab(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? null;
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        // --- Base FAB (Vertical Stack) ---
        if ($modifier === null) {
            $css = <<<CSS
            /* --- FAB Container --- */
            {$selector} {
                position: fixed; bottom: 1rem; right: 1rem; z-index: 50;
                display: flex; flex-direction: column-reverse;
                align-items: flex-end; gap: 0.5rem;
                pointer-events: none;
            }
            
            /* --- Button Visibility & Placement Logic --- */
            /* ট্রিগার এবং ক্লোজ বাটন দুটোই একই জায়গায় থাকবে */
            {$selector} > [tabindex],
            {$selector} > .fab-close {
                display: grid; /* Ensures centering of icon/text inside */
                place-items: center;
                pointer-events: auto;
                transition: transform 0.2s ease-out, opacity 0.2s ease-out;
            }

            /* .fab-close কে .fab এর ভেতরে absolute পজিশন দেওয়া হয়েছে */
            {$selector} > .fab-close {
                position: absolute;
                bottom: 0;
                right: 0;
            }

            /* ডিফল্ট অবস্থায় ট্রিগার বাটন দেখা যাবে, ক্লোজ বাটন হাইড থাকবে */
            {$selector} > .fab-close {
                opacity: 0; transform: scale(0);
            }

            /* FAB খোলা হলে ট্রিগার বাটন হাইড হবে, ক্লোজ বাটন দেখা যাবে */
            {$selector}:focus-within > [tabindex] {
                opacity: 0; transform: scale(0);
            }
            {$selector}:focus-within > .fab-close {
                opacity: 1; transform: scale(1);
            }

            /* --- Action Buttons Logic (Excluding close button) --- */
            {$selector} > *:not([tabindex]):not(.fab-close) {
                visibility: hidden; opacity: 0; transform: scale(0.8);
                transition: transform 0.2s ease-out, opacity 0.2s ease-out, visibility 0.2s;
            }
            {$selector}:focus-within > *:not([tabindex]):not(.fab-close) {
                visibility: visible; opacity: 1; transform: scale(1);
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- FAB Flower (Auto-calculating & Bulletproof) ---
        if ($modifier === 'flower') {
            $css = <<<CSS
            {$selector} {
                position: fixed; bottom: 1rem; right: 1rem;
                z-index: 50; display: grid; place-items: center;
                
                /* --- Base Variables --- */
                --fab-button-size: 1.5rem; /* Main button size (e.g., btn-lg) */
                --fab-items: 1;
                
                /* --- Auto-calculated Radius --- */
                /* Radius grows with item count to prevent overlap */
                --fab-radius: calc(var(--fab-button-size) * 1.2 + (var(--fab-items) - 1) * 1.5rem);

                /* --- Spreading Angle (0 to 90 degrees) --- */
                --fab-angle-start: 30deg;
                --fab-angle-end: -90deg;
            }
            {$selector} > * {
                grid-area: 1 / 1; transform-origin: center;
                transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.15s ease-out;
            }
            {$selector} > [tabindex] { z-index: 10; pointer-events: auto; }
            
            /* --- States and Positions --- */
            {$selector} > .fab-main-action { opacity: 0; transform: scale(0); pointer-events: none; }
            {$selector}:focus-within > [tabindex] { opacity: 0; transform: scale(0); pointer-events: none; }
            {$selector}:focus-within > .fab-main-action { opacity: 1; transform: scale(1); pointer-events: auto; }
            {$selector} > *:not([tabindex]):not(.fab-main-action) { transform: scale(0.5); opacity: 0; pointer-events: none; }

            {$selector}:focus-within > *:not([tabindex]):not(.fab-main-action) {
                --total-angle: calc(var(--fab-angle-end) - var(--fab-angle-start));
                --angle-per-item: calc(var(--total-angle) / (var(--fab-items) - 1));
                --rotation: calc(var(--fab-angle-start) + var(--i) * var(--angle-per-item));
                transform: rotate(var(--rotation)) translateY(calc(var(--fab-radius) * -1)) rotate(calc(-1 * var(--rotation)));
                opacity: 1; pointer-events: auto;
            }
            
            /* --- Auto-detect Item Count & Set Index --- */
            {$selector}:has(> :nth-child(3):not([tabindex]):not(.fab-main-action)) { --fab-items: 2; }
            {$selector}:has(> :nth-child(4):not([tabindex]):not(.fab-main-action)) { --fab-items: 3; }
            {$selector}:has(> :nth-child(5):not([tabindex]):not(.fab-main-action)) { --fab-items: 4; }
            {$selector}:has(> :nth-child(6):not([tabindex]):not(.fab-main-action)) { --fab-items: 5; }
            {$selector} > *:not([tabindex]):not(.fab-main-action):nth-of-type(1) { --i: 0; }
            {$selector} > *:not([tabindex]):not(.fab-main-action):nth-of-type(2) { --i: 1; }
            {$selector} > *:not([tabindex]):not(.fab-main-action):nth-of-type(3) { --i: 2; }
            {$selector} > *:not([tabindex]):not(.fab-main-action):nth-of-type(4) { --i: 3; }
            {$selector} > *:not([tabindex]):not(.fab-main-action):nth-of-type(5) { --i: 4; }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }
        return null;
    }

    private function handleSwap(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? null; // rotate, flip, active, or null
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        if ($modifier === null) {
            $css = <<<CSS
            /* --- Base Swap Component --- */
            {$selector} {
                display: inline-grid;
                place-content: center;
                user-select: none;
                cursor: pointer;
                
                /* --- মূল ফিক্স এখানে --- */
                /* এটি নিশ্চিত করবে যে কন্টেইনারটি প্রয়োজনের চেয়ে বেশি জায়গা নেবে না */
                width: min-content; 
                height: min-content;
            }
            
            /* All children are in the same grid cell */
            {$selector} > * {
                grid-column-start: 1;
                grid-row-start: 1;
                transition: transform 0.3s ease-out, opacity 0.2s ease-out;
            }
            
            /* Hidden checkbox controls the state */
            {$selector} input {
                appearance: none;
                position: absolute;
                width: auto;
                height: auto;
                cursor: pointer;
            }
            
            /* Default state: show swap-off */
            {$selector} .swap-on {
                opacity: 0;
                transform: scale(0.9);
            }
            {$selector} .swap-off {
                opacity: 1;
                transform: scale(1);
            }
            
            /* Checked/Active state: show swap-on */
            {$selector} input:checked ~ .swap-on,
            {$selector}.swap-active .swap-on {
                opacity: 1;
                transform: scale(1);
            }
            {$selector} input:checked ~ .swap-off,
            {$selector}.swap-active .swap-off {
                opacity: 0;
                transform: scale(0.9);
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- Modifier Styles ---
        $modifierCss = '';
        switch ($modifier) {
            case 'rotate':
                $modifierCss = <<<CSS
                /* --- Swap Rotate Effect --- */
                {$selector} .swap-on { transform: rotate(-45deg) scale(0.9); }
                {$selector} .swap-off { transform: rotate(0deg) scale(1); }
                {$selector} input:checked ~ .swap-on,
                {$selector}.swap-active .swap-on { transform: rotate(0deg) scale(1); }
                {$selector} input:checked ~ .swap-off,
                {$selector}.swap-active .swap-off { transform: rotate(45deg) scale(0.9); }
                CSS;
                break;
            
            case 'flip':
                $modifierCss = <<<CSS
                /* --- Swap Flip Effect --- */
                {$selector} { perspective: 1000px; }
                {$selector} .swap-on { transform: rotateY(90deg) scale(0.9); }
                {$selector} .swap-off { transform: rotateY(0deg) scale(1); }
                {$selector} input:checked ~ .swap-on,
                {$selector}.swap-active .swap-on { transform: rotateY(0deg) scale(1); }
                {$selector} input:checked ~ .swap-off,
                {$selector}.swap-active .swap-off { transform: rotateY(-90deg) scale(0.9); }
                CSS;
                break;
            
            case 'active':
                // .swap-active এর জন্য আলাদা কোনো CSS জেনারেট করার দরকার নেই,
                // কারণ বেস স্টাইলের সিলেক্টরগুলোই (`.swap.swap-active`) এটি হ্যান্ডেল করে।
                return ['style' => '/* This class is a state trigger */'];
        }

        return !empty($modifierCss) ? ['layer' => 'components', 'style' => $modifierCss] : null;
    }

    private function handleBadge(string $baseClassPart, array $matches): ?array {
        $variantString = $matches[1] ?? 'default';
        $parts = explode('-', $variantString);
        
        $styles = [];
        $color = 'neutral';
        $size = 'md';
        $type = 'solid'; // solid, outline, soft, dash, ghost

        // --- ডিফল্ট স্টাইল ---
        $styles = [
            'display' => 'inline-flex', 'align-items' => 'center',
            'justify-content' => 'center', 'gap' => '0.25rem', // আইকন এবং টেক্সটের মধ্যে গ্যাপ
            'border-width' => '1px', 'font-weight' => '600',
            'white-space' => 'nowrap',
            'border-radius' => '1.9rem', // daisyUI default
        ];

        // --- ক্লাস পার্সিং লজিক ---
        foreach ($parts as $part) {
            match($part) {
                'xs', 'sm', 'md', 'lg', 'xl' => $size = $part,
                'outline', 'soft', 'dash', 'ghost' => $type = $part,
                default => $color = $part
            };
        }
        if ($variantString === 'default') $color = 'neutral';

        // --- সাইজ অনুযায়ী স্টাইল (প্যাডিং বাড়ানো হয়েছে) ---
        match($size) {
            'xs' => $styles = array_merge($styles, ['padding' => '0 0.4rem', 'font-size' => '0.7rem', 'height' => '1rem']),
            'sm' => $styles = array_merge($styles, ['padding' => '0 0.5rem', 'font-size' => '0.75rem', 'height' => '1.25rem']),
            'md' => $styles = array_merge($styles, ['padding' => '0 0.7rem', 'font-size' => '0.875rem', 'height' => '1.5rem']),
            'lg' => $styles = array_merge($styles, ['padding' => '0 0.9rem', 'font-size' => '1rem', 'height' => '1.75rem']),
            'xl' => $styles = array_merge($styles, ['padding' => '0 1.2rem', 'font-size' => '1.125rem', 'height' => '2rem']),
        };

        // --- কালার ম্যাপিং ---
        $colorMap = [
            'primary' => 'primary', 'secondary' => 'secondary', 'accent' => 'accent',
            'info' => 'info', 'success' => 'success', 'warning' => 'warning', 'error' => 'destructive', 'danger' => 'destructive',
            'neutral' => 'neutral'
        ];
        $c = $colorMap[$color] ?? $color; // Use direct color name if not in map

        // --- টাইপ অনুযায়ী স্টাইল ---
        switch ($type) {
            case 'solid':
                $styles['background-color'] = "hsl(var(--{$c}))";
                $styles['color'] = "hsl(var(--{$c}-foreground))";
                $styles['border-color'] = "hsl(var(--{$c}))";
                break;
            case 'outline':
                $styles['background-color'] = 'transparent';
                $styles['color'] = "hsl(var(--{$c}))";
                $styles['border-color'] = "hsl(var(--{$c}))";
                break;
            case 'soft':
                $styles['background-color'] = "hsl(var(--{$c}))/0.2";
                $styles['color'] = "hsl(var(--{$c}))";
                $styles['border-color'] = 'transparent';
                break;
            case 'dash':
                $styles['background-color'] = 'transparent';
                $styles['border-style'] = 'dashed';
                $styles['color'] = "hsl(var(--{$c}))";
                $styles['border-color'] = "hsl(var(--{$c}))";
                break;
            case 'ghost':
                $styles['background-color'] = 'transparent';
                $styles['color'] = 'hsl(var(--bc, var(--foreground)))'; // Use base content color
                $styles['border-color'] = 'transparent';
                break;
        }

        return ['layer' => 'components', 'style' => $styles];
    }

    private function handleChat(string $baseClassPart, array $matches): ?array {
        $part = $matches[1] ?? 'base';
        $color = $matches[2] ?? null;
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        if (!$color) {
            $styles = match ($part) {
                'base' => [
                    'display' => 'grid',
                    'gap' => '0.5rem 1rem',
                    // Default for chat-start
                    'grid-template-columns' => 'auto 1fr', 
                ],
                'start' => [
                    'grid-template-columns' => 'auto 1fr',
                    'justify-items' => 'start',
                    'grid-template-areas' => '"image header" "image bubble" "image footer"',
                ],
                'end' => [
                    // --- মূল পরিবর্তন এখানে ---
                    'grid-template-columns' => '1fr auto',
                    'justify-items' => 'end',
                    'grid-template-areas' => '"header image" "bubble image" "footer image"',
                ],
                'image' => ['grid-area' => 'image'],
                'header' => ['grid-area' => 'header', 'font-size' => '0.875rem', 'opacity' => '0.7'],
                'footer' => ['grid-area' => 'footer', 'font-size' => '0.875rem', 'opacity' => '0.7'],
                'bubble' => [
                    'grid-area' => 'bubble', 'position' => 'relative',
                    'width' => 'fit-content', 'max-width' => '70%',
                    'padding' => '1rem', 'border-radius' => 'var(--rounded-box, 1rem)',
                    'background-color' => 'hsl(var(--b2, var(--muted)))',
                    'color' => 'hsl(var(--bc, var(--muted-foreground)))',
                ],
                default => null,
            };
            return $styles ? ['layer' => 'components', 'style' => $styles] : null;
        }
        // --- Chat Bubble Colors ---
        if ($part === 'bubble' && $color) {
            $colorMap = [
                'primary' => 'primary', 'secondary' => 'secondary', 'accent' => 'accent',
                'info' => 'info', 'success' => 'success', 'warning' => 'warning', 'error' => 'destructive', 'danger' => 'destructive',
                'neutral' => 'neutral-focus' // daisyUI uses a darker neutral for bubble
            ];
            $c = $colorMap[$color] ?? $color;

            $colorStyles = [
                'background-color' => "hsl(var(--{$c}))",
                'color' => "hsl(var(--{$c}-foreground))",
            ];
            return ['layer' => 'components', 'style' => $colorStyles];
        }

        return null;
    }

    private function handleKbd(string $baseClassPart, array $matches): ?array {
        $size = $matches[1] ?? 'md'; // xs, sm, md, lg, xl
        
        $styles = [
            'display' => 'inline-flex',
            'align-items' => 'center',
            'justify-content' => 'center',
            'border-radius' => '0.375rem', /* rounded-md */
            'border-width' => '1px',
            'border-bottom-width' => '2px',
            'background-color' => 'hsl(var(--b2, var(--muted)))', /* daisyUI base-200 */
            'border-color' => 'hsl(var(--b3, var(--border)))',     /* daisyUI base-300 */
            'color' => 'hsl(var(--bc, var(--foreground)))',       /* daisyUI base-content */
            'font-family' => 'monospace',
        ];

        // --- সাইজ অনুযায়ী স্টাইল ---
        switch ($size) {
            case 'xs':
                $styles = array_merge($styles, ['padding' => '0.25rem 0.5rem', 'font-size' => '0.7rem', 'min-height' => '1.2rem', 'min-width' => '1.2rem']);
                break;
            case 'sm':
                $styles = array_merge($styles, ['padding' => '0.375rem 0.75rem', 'font-size' => '0.75rem', 'min-height' => '1.5rem', 'min-width' => '1.5rem']);
                break;
            case 'md':
                $styles = array_merge($styles, ['padding' => '0.5rem 1rem', 'font-size' => '0.875rem', 'min-height' => '2rem', 'min-width' => '2rem']);
                break;
            case 'lg':
                $styles = array_merge($styles, ['padding' => '0.75rem 1.5rem', 'font-size' => '1rem', 'min-height' => '2.5rem', 'min-width' => '2.5rem']);
                break;
            case 'xl':
                $styles = array_merge($styles, ['padding' => '1rem 2rem', 'font-size' => '1.125rem', 'min-height' => '3rem', 'min-width' => '3rem']);
                break;
        }

        return ['layer' => 'components', 'style' => $styles];
    }

    private function handleBackgroundColor(string $baseClassPart, array $matches): ?array {
        $valuePart = $matches[1];

        $colorString = $this->parseColorValue($valuePart);

        if ($colorString !== null) {
            $styles = [];
            if (str_starts_with($colorString, 'rgba') || str_starts_with($colorString, 'hsla')) {
                $styles['background-color'] = $colorString;
            } else {
                $styles['--tw-bg-opacity'] = '1';
                $styles['background-color'] = $this->convertColorWithOpacityVar($colorString, '--tw-bg-opacity');
            }
            return $styles;
        }
        return null;
    }

    private function convertColorWithOpacityVar(string $colorString, string $opacityVarName): string {
        // If color already has alpha, or is transparent/current, don't apply opacity var
        if (strpos($colorString, 'rgba(') === 0 || strpos($colorString, 'hsla(') === 0 || in_array(strtolower($colorString), ['transparent', 'inherit', 'currentColor'])) {
            return $colorString;
        }
        
        // Check if it's already a var that might be HSL components
        if (strpos($colorString, 'hsl(var(') === 0) {
            // It's semantic, assuming it's already set up correctly and will handle opacity
            return $colorString;
        }

        // Convert hex/rgb to rgb() with opacity var
        if (preg_match('/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $colorString, $hexMatch)) {
            $hex = $hexMatch[1];
            if (strlen($hex) == 3) { $r = hexdec($hex[0].$hex[0]); $g = hexdec($hex[1].$hex[1]); $b = hexdec($hex[2].$hex[2]); }
            else { $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2)); }
            return "rgb({$r} {$g} {$b} / var({$opacityVarName}, 1))";
        }
        
        if (preg_match('/^rgb\((\d+,\s*\d+,\s*\d+)\)$/', $colorString, $rgbMatches)) {
            return "rgb({$rgbMatches[1]} / var({$opacityVarName}, 1))";
        }
        
        // Fallback for named CSS colors etc.
        return $colorString;
    }

    // Helper function to convert CSS color names to RGB array (simplified example)
    private function colorNameToRgb(string $name): ?array {
        $map = [
            'red' => [255, 0, 0],
            'green' => [0, 128, 0],
            'blue' => [0, 0, 255],
            'yellow' => [255, 255, 0], 
            'purple' => [128, 0, 128], 
            'orange' => [255, 165, 0],
            // Add many more common names
        ];
        return $map[strtolower($name)] ?? null;
    }
    private function handleBackgroundOpacity(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1]; // e.g., 50, 75, or arbitrary like [0.65]

        $opacityValue = null;
        if (strpos($valueKey, '[') === 0 && str_ends_with($valueKey, ']')) {
            // Arbitrary value like bg-opacity-[.55]
            $arbitraryVal = trim($valueKey, '[]');
            if (is_numeric($arbitraryVal) && (float)$arbitraryVal >= 0 && (float)$arbitraryVal <= 1) {
                $opacityValue = (string)(float)$arbitraryVal;
            } elseif (str_ends_with($arbitraryVal, '%')) {
                $num = (float)rtrim($arbitraryVal, '%');
                if ($num >= 0 && $num <= 100) {
                    $opacityValue = (string)($num / 100);
                }
            }
        } else {
            // Theme lookup
            $opacityValue = $this->lookupThemeValue('opacity', $valueKey);
        }

        if ($opacityValue !== null) {
            return ['--tw-bg-opacity' => $opacityValue];
        }
        return null;
    }

    private function handleBackgroundImageUrl(string $baseClassPart, array $matches): ?array {
        $urlValue = $matches[1];
        // কোটেশন এবং অন্যান্য অক্ষর ঠিক করা
        $urlValue = trim($urlValue, '\'"');
        $urlValue = str_replace(['(', ')'], ['\(', '\)'], $urlValue); // ব্র্যাকেট এস্কেপ করা (যদিও url() এর ভেতরে এটি সাধারণত প্রয়োজন হয় না)
        
        return ['background-image' => "url('{$urlValue}')"];
    }

    // bg-[radial-gradient(...)]-এর জন্য একটি ডেডিকেটেড হ্যান্ডলার
    private function handleBackgroundArbitraryGradient(string $baseClassPart, array $matches): ?array {
        $gradientValue = str_replace('_', ' ', $matches[1]);
        return ['background' => $gradientValue];
    }
    
    private function handleBackgroundSize(string $baseClassPart, array $matches): ?array {
        return ['background-size' => $matches[1]];
    }
    private function handleBackgroundPosition(string $baseClassPart, array $matches): ?array {
        return ['background-position' => str_replace('-', ' ', $matches[1])];
    }
    private function handleBackgroundRepeat(string $baseClassPart, array $matches): ?array {
        return ['background-repeat' => str_replace('repeat-', 'repeat ', $matches[1])]; // repeat-x -> repeat x
    }
    private function handleBackgroundAttachment(string $baseClassPart, array $matches): ?array {
        return ['background-attachment' => $matches[1]];
    }
    private function handleBackgroundOrigin(string $baseClassPart, array $matches): ?array {
        return ['background-origin' => $matches[1] . '-box'];
    }

    private function handleGlassBackground(string $baseClassPart, array $matches): ?array {
        $variantKey = $matches[1] ?? 'DEFAULT';
        
        $colorConfigKey = 'glass' . ($variantKey !== 'DEFAULT' ? '-' . $variantKey : '');
        $glassConfig = $this->lookupThemeValue('colors', $colorConfigKey);
        
        if (is_array($glassConfig)) {
            $styles = [];
            
            if (isset($glassConfig['DEFAULT'])) {
                $styles['background-color'] = $glassConfig['DEFAULT'];
            }
            if (isset($glassConfig['border'])) {
                $styles['border'] = '1px solid ' . $glassConfig['border'];
            }
            if (str_contains($colorConfigKey, 'apple')) {
                $styles['box-shadow'] = 'inset 0 1px 1px 0 rgba(255, 255, 255, 0.1)';
            }
            $blurValue = $this->resolveThemeValue($glassConfig['blur'] ?? ['theme' => 'blur.DEFAULT'], '8px');
            $styles['--tw-backdrop-blur'] = "blur({$blurValue})";
            
            $styles['backdrop-filter'] = $this->buildBackdropFilterFunctionString();
            $styles['-webkit-backdrop-filter'] = $this->buildBackdropFilterFunctionString();

            return ['layer' => 'utilities', 'style' => $styles];
        } 
        
        return null;
    }

    private function handleMeshBackground(string $baseClassPart, array $matches): ?array {
        $meshName = $matches[1];
        $forceMode = $matches[2] ?? null;

        $meshConfig = $this->lookupThemeValue('mesh', $meshName);
        if (!$meshConfig || !isset($meshConfig['light']) || !isset($meshConfig['dark'])) {
            return null;
        }

        // --- ধাপ ১: অ্যানিমেশন কনফিগারেশন পড়ুন ---
        $animationConfig = $meshConfig['animation'] ?? false;
        $animationShorthand = 'none';

        if (is_array($animationConfig)) {
            $name = $animationConfig['name'] ?? 'none';
            if ($name !== 'none') {
                $duration  = $animationConfig['duration']  ?? '30s';
                $timing    = $animationConfig['timing']    ?? 'ease-in-out';
                $iteration = $animationConfig['iteration'] ?? 'infinite';
                $direction = $animationConfig['direction'] ?? 'alternate';
                $animationShorthand = "{$name} {$duration} {$timing} {$iteration} {$direction}";
                if (isset($this->config['theme']['keyframes'][$name])) {
                    $this->neededKeyframes[$name] = $this->config['theme']['keyframes'][$name];
                }
            }
        }

        // --- ধাপ ২: CSS স্ট্রিং তৈরি করুন ---
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $pseudoSelector = $selector . '::before';
        $bgColor = $this->lookupThemeValue('colors', 'background') ?? '#000';
        
        $cssString = "";

        // ক. মূল এলিমেন্টের বেস স্টাইল
        $cssString .= "/* Mesh container for '{$baseClassPart}' */\n";
        $cssString .= "{$selector} {\n";
        $cssString .= "  position: relative;\n";
        // $cssString .= "  overflow: hidden;\n";
        $cssString .= "  z-index: 0;\n";
        $cssString .= "  background-color: {$bgColor};\n"; 
        $cssString .= "}\n\n";

        // খ. সিউডো-এলিমেন্টের সাধারণ স্টাইল
        $cssString .= "/* Pseudo-element for the mesh gradient of '{$baseClassPart}' */\n";
        $cssString .= "{$pseudoSelector} {\n";
        $cssString .= "  content: '';\n";
        $cssString .= "  position: absolute;\n";
        $cssString .= "  z-index: -1;\n";
        $cssString .= "  inset: -250px; /* ব্লার ইফেক্টের জন্য অতিরিক্ত জায়গা আরও বাড়ানো হলো */\n";
        $cssString .= "  opacity: 0.8; /* অপাসিটি কিছুটা কমানো হলো, যা পারফরম্যান্সে সাহায্য করতে পারে */\n";
        
        // পারফরম্যান্স উন্নতি: will-change ব্যবহার করে ব্রাউজারকে অপটিমাইজ করার জন্য ইঙ্গিত দেওয়া
        $cssString .= "  will-change: transform;\n";

        // ডিফল্ট অ্যানিমেশন এবং ফিল্টার
        $cssString .= "  filter: blur(90px);\n";
        $cssString .= "  animation: {$animationShorthand};\n";
        $cssString .= "}\n\n";
        
        // গ. Reduced Motion মোডের জন্য বিশেষ স্টাইল
        // এখানে অ্যানিমেশন বন্ধ করে দেওয়া হবে এবং একটি সহজতর transform প্রয়োগ করা হবে।
        $cssString .= "/* Performance: Disable animation for users who prefer reduced motion */\n";
        $cssString .= "@media (prefers-reduced-motion: reduce) {\n";
        $cssString .= "  {$pseudoSelector} {\n";
        $cssString .= "    animation: none;\n";
        // একটি স্থির, সুন্দর অবস্থানের জন্য একটি ডিফল্ট transform যোগ করা হলো
        $cssString .= "    transform: translate(10%, -10%);\n";
        $cssString .= "  }\n";
        $cssString .= "}\n\n";

        // ঘ. body ট্যাগের জন্য বিশেষ স্টাইল
        $cssString .= "/* Special overrides for when mesh is applied to the body tag */\n";
        $cssString .= "body{$selector} {\n";
        $cssString .= "  /* body-র জন্য overflow: hidden; ব্যবহার করা যাবে না, তাই auto রাখা হয়েছে */\n";
        $cssString .= "}\n\n";
        $cssString .= "body{$pseudoSelector} {\n";
        $cssString .= "  position: fixed;\n";
        $cssString .= "  inset: 0;\n";
        $cssString .= "}\n\n";
        
        // ঙ. মোড অনুযায়ী ব্যাকগ্রাউন্ড ইমেজ সেট করা (light/dark)
        $cssString .= "/* Gradient definitions for '{$baseClassPart}' */\n";
        if ($forceMode === 'light') {
            $cssString .= "{$pseudoSelector} { background-image: {$meshConfig['light']}; }\n";
        } elseif ($forceMode === 'dark') {
            $cssString .= "{$pseudoSelector} { background-image: {$meshConfig['dark']}; }\n";
        } else {
            $cssString .= "{$pseudoSelector} { background-image: {$meshConfig['light']}; }\n";
            $cssString .= ".dark {$pseudoSelector} { background-image: {$meshConfig['dark']}; }\n";
        }
        
        return ['layer' => 'components', 'style' => $cssString];
    }

    private function handleRetroGridBackground(string $baseClassPart, array $matches): ?array {
        // --- ১. প্রয়োজনীয় কীফ্রেমগুলো রেজিস্টার করুন ---
        $this->neededKeyframes['grid-pan'] = $this->config['theme']['keyframes']['grid-pan'] ?? '{ 0% { background-position: 0 0; } 100% { background-position: 0 100%; } }';
        // গ্লো অ্যানিমেশনের জন্য কীফ্রেম, এখন --accent ভেরিয়েবল ব্যবহার করছে
        $this->neededKeyframes['horizon-pulse'] = $this->config['theme']['keyframes']['horizon-pulse'] ?? '{ 0%, 100% { box-shadow: 0 0 15px 4px hsl(var(--accent)), 0 0 25px 8px hsl(var(--accent)); opacity: 0.7; } 50% { box-shadow: 0 0 25px 7px hsl(var(--accent)), 0 0 40px 12px hsl(var(--accent)); opacity: 1; } }';
        
        // --- ২. CSS স্ট্রিং তৈরি করুন ---
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        $cssString = "";

        // মূল এলিমেন্টের বেস স্টাইল
        $cssString .= "/* Base styles for the retro grid container */\n";
        $cssString .= "{$selector} {\n";
        $cssString .= "  position: relative;\n";
        $cssString .= "  overflow: hidden;\n";
        $cssString .= "  transform-style: preserve-3d;\n";
        $cssString .= "  perspective: 300px;\n";
        $cssString .= "  background-color: hsl(var(--background));\n"; 
        $cssString .= "}\n\n";

        // ::before সিউডো-এলিমেন্ট (3D গ্রিড)
        $cssString .= "/* The perspective grid using ::before */\n";
        $cssString .= "{$selector}::before {\n";
        $cssString .= "  content: '';\n";
        $cssString .= "  position: absolute;\n";
        $cssString .= "  inset: 0;\n";
        $cssString .= "  width: 100%;\n";
        $cssString .= "  height: 160%;\n";
        $cssString .= "  top: -50%;\n";
        $cssString .= "  transform-origin: center bottom;\n";
        $cssString .= "  transform: rotateX(70deg);\n";
        $cssString .= "  z-index: 1;\n";
        
        // সলিড কালার ব্যবহার করা হচ্ছে (কোনো স্বচ্ছতা ছাড়া)
        $cssString .= "  background-image: \n";
        $cssString .= "    linear-gradient(to right, hsl(var(--primary)) 1px, transparent 1px),\n";
        $cssString .= "    linear-gradient(to bottom, hsl(var(--primary)) 1px, transparent 1px);\n";
        
        $cssString .= "  background-size: 50px 50px;\n";
        $cssString .= "  border-right: 1px solid hsl(var(--primary));\n";
        $cssString .= "  border-bottom: 1px solid hsl(var(--primary));\n";
        $cssString .= "  animation: grid-pan 12s linear infinite;\n";
        $cssString .= "}\n\n";
        
        // ::after সিউডো-এলিমেন্ট (দিগন্তের আভা)
        $cssString .= "/* The glowing horizon using ::after */\n";
        $cssString .= "{$selector}::after {\n";
        $cssString .= "  content: '';\n";
        $cssString .= "  position: absolute;\n";
        $cssString .= "  top: 55%;\n";
        $cssString .= "  left: -10%;\n";
        $cssString .= "  right: -10%;\n";
        $cssString .= "  height: 1px;\n";
        $cssString .= "  z-index: 2;\n";
        $cssString .= "  background: hsl(var(--accent));\n";
        $cssString .= "  filter: blur(12px);\n";
        $cssString .= "  animation: horizon-pulse 5s ease-in-out infinite alternate;\n";
        $cssString .= "}\n\n";

        return ['layer' => 'components', 'style' => $cssString];
    }

    private function handleGlassEffect(string $baseClassPart, array $matches): ?array {
        $variantKey = $matches[1] ?? 'DEFAULT';
        
        // Fallback to DEFAULT if the specific variant doesn't exist.
        $defaultConfig = $this->config['theme']['glassEffect']['DEFAULT'] ?? [];
        $effectConfig = $this->config['theme']['glassEffect'][$variantKey] ?? null;
        
        // If no specific config for the variant is found, use the DEFAULT config.
        if ($effectConfig === null) {
            $effectConfig = $defaultConfig;
        }

        $baseSelector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $rules = [];

        // --- Merge Styles from Default and Variant Configs ---
        $baseStyles = array_merge($defaultConfig['base'] ?? [], $effectConfig['base'] ?? []);
        $varStyles = array_merge($defaultConfig['vars'] ?? [], $effectConfig['vars'] ?? []);
        
        // --- Build Rules Array ---

        // 1. Base Styles for the element itself, including any variant-specific CSS variables.
        if (!empty($baseStyles) || !empty($varStyles)) {
            $rules[$baseSelector] = array_merge($baseStyles, $varStyles);
        }
        
        // --- SPECIAL CASE: Handle 'lens-effect' structure ---
        if ($variantKey === 'lens-effect') {
            // For lens effect, we use ::before for the blurred background and ::after for the masked foreground.
            $unblurredLayerStyles = array_merge($defaultConfig['foreground'] ?? [], $effectConfig['foreground'] ?? []);
            $blurredLayerStyles = array_merge($defaultConfig['background'] ?? [], $effectConfig['background'] ?? []);
            
            if (!empty($blurredLayerStyles)) {
                $rules[$baseSelector . '::before'] = $blurredLayerStyles;
                // Main element must be transparent to see the pseudo-elements.
                if (!isset($rules[$baseSelector])) $rules[$baseSelector] = [];
                $rules[$baseSelector]['background'] = 'transparent';
            }
            if (!empty($unblurredLayerStyles)) {
                $rules[$baseSelector . '::after'] = $unblurredLayerStyles;
            }

        } else {
            // --- STANDARD GLASS EFFECT structure (glow and border) ---
            $glowStyles = array_merge($defaultConfig['glow'] ?? [], $effectConfig['glow'] ?? []);
            $borderStyles = array_merge($defaultConfig['border'] ?? [], $effectConfig['border'] ?? []);

            if (!empty($glowStyles)) {
                $rules[$baseSelector . '::before'] = $glowStyles;
                // If the glow has an animated background (like aurora), ensure the main element is transparent
                if (isset($glowStyles['animation'])) {
                    if (!isset($rules[$baseSelector])) $rules[$baseSelector] = [];
                    $rules[$baseSelector]['background'] = 'transparent';
                }
            }
            if (!empty($borderStyles)) {
                $rules[$baseSelector . '::after'] = $borderStyles;
            }
        }
        
        // --- Handle Hover effects (for all variants that define them) ---
        $hoverStyles = array_merge($defaultConfig['hover'] ?? [], $effectConfig['hover'] ?? []);
        $hoverGlowStyles = array_merge($defaultConfig['hover-glow'] ?? [], $effectConfig['hover-glow'] ?? []);

        if (!empty($hoverStyles)) {
            $rules[$baseSelector . ':hover'] = $hoverStyles;
        }
        if (!empty($hoverGlowStyles)) {
            // This targets the glow pseudo-element on hover
            $rules[$baseSelector . ':hover::before'] = $hoverGlowStyles;
        }
        
        // Convert the rules array to a single CSS string
        $cssString = $this->buildCssRulesToString($rules);
        
        // Inject the generated CSS block into the 'components' layer
        if (!empty(trim($cssString))) {
        $this->layerCss['components'][] = $cssString;
        }
        
        return null; // Stop further processing by parseClass
    }

    private function handleGlassGlow(string $baseClassPart, array $matches): ?array {
        $glowConfig = $this->config['theme']['glassGlow'] ?? null;
        if (!$glowConfig) return null;
        
        // Returns styles for the ::before pseudo-element's :hover state
        if (isset($glowConfig['hover-glow'])) {
            return ['_hoverBeforeStyles' => $glowConfig['hover-glow']];
        }
        return null;
    }

    private function handleGlassTilt(string $baseClassPart, array $matches): ?array {
        $tiltConfig = $this->config['the me']['glassTilt'] ?? null;
        if (!$tiltConfig) return null;

        // Returns styles for the base element and for its :hover state
        $baseStyles = $tiltConfig['base'] ?? [];
        $hoverStyles = $tiltConfig['hover'] ?? [];

        $styles = $baseStyles;
        if (!empty($hoverStyles)) {
            $styles['_hoverStyles'] = $hoverStyles;
        }
        return $styles;
    }

    private function handleGlassNoise(string $baseClassPart, array $matches): ?array {
        $noiseConfig = $this->config['theme']['glassNoise'] ?? null;
        if (!$noiseConfig) return null;

        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart) . '::after';

        // Build the styles for the noise overlay
        $styles = [
            'content' => $noiseConfig['content'] ?? '""',
            'position' => $noiseConfig['position'] ?? 'absolute',
            'inset' => $noiseConfig['inset'] ?? '0',
            'z-index' => $noiseConfig['z-index'] ?? '2',
            'border-radius' => $noiseConfig['border-radius'] ?? 'inherit',
            'pointer-events' => $noiseConfig['pointer-events'] ?? 'none',
            // --- মূল পরিবর্তন এখানে ---
            // Combine the noise image with the existing background (which is the border gradient)
            // We assume the ::after already has a background from glass-effect's border config.
            // We prepend the noise image to it.
            'background-image' => 'var(--glass-noise-image, ' . $noiseConfig['background-image'] . '), var(--glass-border-gradient, linear-gradient(135deg, var(--glass-border-from), var(--glass-border-to)))',
            'background-blend-mode' => 'var(--glass-noise-blend-mode, soft-light), normal', // Blend the noise, keep border normal
            'background-repeat' => 'repeat, no-repeat', // Repeat noise, don't repeat border
            'background-position' => 'center, center', // Adjust as needed
            'background-size' => 'auto, cover',      // Adjust as needed
            'opacity' => 'var(--glass-noise-opacity, 0.08)',
        ];

        // Build the full CSS rule string
        $cssString = $this->buildCssRuleString($selector, $styles);
        
        // Inject the generated CSS block into the 'utilities' layer (as it's a utility)
        if (!empty(trim($cssString))) {
        $this->layerCss['utilities'][] = $cssString;
        }
        
        // Return null because we have handled the CSS injection directly.
        return null; 
    }

    private function handleBackdropBlur(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1] ?? 'DEFAULT';
        $val = $this->parseNumericValue($valueKey, 'blur', ['defaultUnit' => 'px', 'allowArbitrary'=>true]);
        if ($val === null && $valueKey === 'DEFAULT') $val = $this->lookupThemeValue('blur', 'DEFAULT');

        if ($val !== null) {
            $vars = ['--tw-backdrop-blur' => "blur({$val})"];
            $styles = array_merge($vars, ['-webkit-backdrop-filter' => $this->buildBackdropFilterFunctionString($vars), 'backdrop-filter' => $this->buildBackdropFilterFunctionString($vars)]);
            return $styles;
        }
        return null;
    }
    private function handleBorderStyle(string $baseClassPart, array $matches): ?array { return ['border-style' => $matches[1]]; }
    private function handleBorderGradient(string $baseClassPart, array $matches): ?array {
        $directionKey = $matches[1] ?? 'r';
        $directionMap = [ 't' => 'to top', 'tr' => 'to top right', 'r' => 'to right', 'br' => 'to bottom right', 'b' => 'to bottom', 'bl' => 'to bottom left', 'l' => 'to left', 'tl' => 'to top left' ];
        $direction = $directionMap[$directionKey] ?? 'to right';
        $this->neededKeyframes['border-spin'] = $this->config['theme']['keyframes']['border-spin'] ?? '{ 100% { transform: rotate(360deg); } }';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $cssString = "";
        $cssString .= "{$selector} {\n";
        $cssString .= "  position: relative; z-index: 0;\n";
        $cssString .= "  padding: 1px;\n";
        $cssString .= "  overflow: hidden;\n";
        $cssString .= "}\n\n";
        $cssString .= "{$selector}::before {\n";
        $cssString .= "  content: '';\n";
        $cssString .= "  position: absolute;\n";
        $cssString .= "  z-index: -1;\n";
        $cssString .= "  inset: -100%;\n";
        $cssString .= "  background: conic-gradient(from 180deg at 50% 50%, hsl(var(--primary)) 0deg, hsl(var(--accent)) 180deg, hsl(var(--destructive)) 360deg);\n";
        $cssString .= "  animation: border-spin 4s linear infinite;\n";
        $cssString .= "}\n\n";
        $cssString .= "{$selector}::after {\n";
        $cssString .= "  content: '';\n";
        $cssString .= "  position: absolute;\n";
        $cssString .= "  z-index: -2;\n";
        $cssString .= "  inset: 0;\n";
        $cssString .= "  background: hsl(var(--background));\n";
        $cssString .= "  border-radius: inherit;\n";
        $cssString .= "}\n\n";
        $cssString .= "{$selector}[style*='--tw-gradient-stops']::before {\n";
        $cssString .= "  background: linear-gradient({$direction}, var(--tw-gradient-stops));\n";
        $cssString .= "}\n";
        return ['layer' => 'components', 'style' => $cssString];
    }

    private function handleBorderConicGlow(string $baseClassPart, array $matches): ?array {
        // --- ধাপ ক: রঙ, হাইলাইট এবং প্রস্থ পার্স করা ---
        // Regex: /border-conic-glow(.*)$/
        $configString = $matches[1] ?? '';
        
        $baseColorVariant = 'primary';
        $highlightColorVariant = 'white'; // ডিফল্ট হাইলাইট রঙ
        $borderWidth = '2px';

        // যদি কোনো কনফিগারেশন থাকে (যেমন: -secondary/transparent-2)
        if (!empty($configString)) {
            // প্রথমে প্রস্থ (width) খুঁজে বের করুন
            if (preg_match('/-(\d+)$/', $configString, $widthMatch)) {
                $borderWidth = $widthMatch[1] . 'px';
                // কনফিগারেশন স্ট্রিং থেকে প্রস্থের অংশটি সরিয়ে দিন
                $configString = substr($configString, 0, -strlen($widthMatch[0]));
            }

            // এখন রঙ এবং হাইলাইট আলাদা করুন
            if (!empty($configString)) {
                // '-' সরিয়ে দিন (যেমন: -secondary/transparent -> secondary/transparent)
                $colorConfig = ltrim($configString, '-');
                
                if (str_contains($colorConfig, '/')) {
                    list($baseColorVariant, $highlightColorVariant) = explode('/', $colorConfig, 2);
                } else {
                    $baseColorVariant = $colorConfig;
                }
            }
        }

        // --- ধাপ খ: রঙ পার্স করা ---
        $baseColor = $this->parseColorValue($baseColorVariant) ?? 'hsl(var(--primary))';
        $highlightColor = $this->parseColorValue($highlightColorVariant) ?? 'white';
        
        // --- ধাপ গ: প্রয়োজনীয় @property এবং @keyframes রেজিস্টার করা ---
        $this->neededProperties['angle-to-the-dangle'] = $this->config['theme']['properties']['angle-to-the-dangle'];
        $this->neededKeyframes['rotateColors'] = $this->config['theme']['keyframes']['rotateColors'];

        // --- ধাপ ঘ: স্টাইল তৈরি করা ---
        $styles = [
            'position' => 'relative', 'z-index' => '0',
            'background-clip' => 'padding-box',
        ];

        $styles['_beforeStyles'] = [
            'content' => '""', 'position' => 'absolute', 'inset' => '0', 'z-index' => '-1',
            'border-radius' => 'inherit',
            'padding' => $borderWidth,
            'background' => "conic-gradient(from var(--angle-to-the-dangle), {$baseColor}, {$highlightColor} 20%, {$baseColor})",
            '-webkit-mask' => 'linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0)',
            'mask' => 'linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0)',
            '-webkit-mask-composite' => 'xor',
            'mask-composite' => 'exclude',
            'animation' => 'rotateColors 2s linear infinite',
        ];
        
        return ['layer' => 'components', 'style' => $styles];
    }

    private function handleBorder(string $baseClassPart, array $matches): ?array { $sideIndicator = $matches[1] ?? null; $valueOrKeyword = $matches[2] ?? 'DEFAULT'; $properties = []; $sides = []; if ($sideIndicator) { $logicalMap = ['s' => ['border-inline-start'], 'e' => ['border-inline-end']]; $physicalMap = [ 't' => ['border-top'], 'r' => ['border-right'], 'b' => ['border-bottom'], 'l' => ['border-left'], 'x' => ['border-left', 'border-right'], 'y' => ['border-top', 'border-bottom'] ]; if(isset($logicalMap[$sideIndicator])) $sides = $logicalMap[$sideIndicator]; else if (isset($physicalMap[$sideIndicator])) $sides = $physicalMap[$sideIndicator]; } else { $sides = ['border']; } $colorValue = $this->parseColorValue($valueOrKeyword); if ($colorValue) { foreach($sides as $s) $properties[$s.'-color'] = $colorValue; return !empty($properties) ? $properties : null; } $widthValue = $this->parseNumericValue($valueOrKeyword, 'borderWidth', ['defaultUnit' => 'px']); if ($widthValue !== null) { foreach($sides as $s) $properties[$s.'-width'] = $widthValue; return !empty($properties) ? $properties : null; } if ($baseClassPart === 'border' && $valueOrKeyword === 'DEFAULT') { $defaultWidth = $this->lookupThemeValue('borderWidth', 'DEFAULT'); if($defaultWidth) { foreach($sides as $s) $properties[$s.'-width'] = $defaultWidth; return $properties;} } if ($baseClassPart === 'border-none') return ['border-style' => 'none']; return null; }
    private function handleDivideWidthOrColor(string $baseClassPart, array $matches): ?array {
        $axis = $matches[1]; // x or y
        $isReverse = isset($matches[2]) && $matches[2] === 'reverse';
        $valueOrColor = $matches[3] ?? 'DEFAULT';
        $styles = [];

        $selectorSuffix = $axis === 'x' ? ($isReverse ? ' > :not([hidden]) ~ :not([hidden])' : ' > :not([hidden]) ~ :not([hidden])') // Tailwind handles reverse with space-x-reverse etc.
                        : ($isReverse ? ' > :not([hidden]) ~ :not([hidden])' : ' > :not([hidden]) ~ :not([hidden])');

        // Try as color
        $color = $this->parseColorValue($valueOrColor);
        if ($color) {
            $styles['border-color'] = $color;
        } else { // Try as width
            $width = $this->parseNumericValue($valueOrColor, 'borderWidth', ['defaultUnit' => 'px']);
            if ($width === null && $valueOrColor === 'DEFAULT') {
                $width = $this->lookupThemeValue('borderWidth', 'DEFAULT');
            }
            if ($width !== null) {
                if ($axis === 'x') {
                    if($isReverse) $styles['--tw-divide-x-reverse'] = '1'; else $styles['--tw-divide-x-reverse'] = '0';
                    $styles['border-right-width'] = "calc({$width} * var(--tw-divide-x-reverse))";
                    $styles['border-left-width'] = "calc({$width} * calc(1 - var(--tw-divide-x-reverse)))";
                } else { // y
                    if($isReverse) $styles['--tw-divide-y-reverse'] = '1'; else $styles['--tw-divide-y-reverse'] = '0';
                    $styles['border-top-width'] = "calc({$width} * calc(1 - var(--tw-divide-y-reverse)))";
                    $styles['border-bottom-width'] = "calc({$width} * var(--tw-divide-y-reverse))";
                }
            } else {
                return null; // Invalid value
            }
        }
        // The actual application of border to children needs a selector transformation or specific handling.
        // This is a simplified representation. Real Tailwind uses `> :not([hidden]) ~ :not([hidden])`
        // For simplicity here, we'll return the properties that would be applied to the children.
        // This would ideally be part of a selector transformation rather than direct properties on the parent.
        // For now, let's assume the styles are meant for the direct children.
        // This is a placeholder for the complex selector logic.
        return $styles; // These would apply to children via complex selectors.
    }
    private function handleDivideStyle(string $baseClassPart, array $matches): ?array {
        return ['border-style' => $matches[1]]; // Similar to handleBorder, would apply to children.
    }
    
    private function handleBorderRadius(string $baseClassPart, array $matches): ?array {
        // $matches[0] is the full class like 'rounded-tl-2xl' or 'rounded' or 'rounded-lg'
        // $matches[1] is the side indicator (t, r, b, l, tl, tr, br, bl, s, e, ss, se, es, ee) or null
        // $matches[2] is the size key (sm, md, lg, xl, 2xl, 3xl, full, none, or arbitrary like [10px]) or null if baseClassPart is just "rounded"

        $cornerIndicator = $matches[1] ?? null;
        $valueKey = $matches[2] ?? 'DEFAULT'; // If only "rounded", $valueKey becomes "DEFAULT"

        // Handle plain "rounded" which maps to 'DEFAULT' in theme.
        // The regex '/^rounded(?:-(t|r|b|l|s|e|ss|se|es|ee|tl|tr|br|bl))?(?:-(.+))?$/'
        // when matching "rounded", $matches[1] and $matches[2] will not be set.
        // So, if $baseClassPart is exactly "rounded", $valueKey should be 'DEFAULT'.
        if ($baseClassPart === 'rounded' && $cornerIndicator === null && ($matches[2] ?? null) === null) {
            $valueKey = 'DEFAULT';
        } elseif (($matches[2] ?? null) === null && $cornerIndicator !== null && $baseClassPart === 'rounded-' . $cornerIndicator) {
            // Handles cases like rounded-t, rounded-r, without explicit size, should use DEFAULT size
            $valueKey = 'DEFAULT';
        }


        $radiusValue = $this->parseNumericValue($valueKey, 'borderRadius', ['defaultUnit' => 'px', 'allowArbitrary' => true]);
        
        // If parseNumericValue didn't find it (e.g. it's a keyword like 'md' or 'full' directly)
        // and it wasn't an arbitrary value, try direct lookup in borderRadius theme.
        if ($radiusValue === null && !(strpos($valueKey, '[') === 0 && str_ends_with($valueKey, ']'))) {
            $themeLookup = $this->lookupThemeValue('borderRadius', $valueKey, true);
            if (is_string($themeLookup)) {
                $radiusValue = $themeLookup;
            }
        }

        if ($radiusValue === null) {
            // If still null, and valueKey was 'DEFAULT', re-check specifically for DEFAULT
            if ($valueKey === 'DEFAULT') {
                $radiusValue = $this->lookupThemeValue('borderRadius', 'DEFAULT', true);
            }
            if ($radiusValue === null) return null; // Could not resolve radius value
        }

        $properties = [];
        $map = [
            't'  => ['border-top-left-radius', 'border-top-right-radius'],
            'r'  => ['border-top-right-radius', 'border-bottom-right-radius'],
            'b'  => ['border-bottom-left-radius', 'border-bottom-right-radius'],
            'l'  => ['border-top-left-radius', 'border-bottom-left-radius'],
            'tl' => ['border-top-left-radius'],
            'tr' => ['border-top-right-radius'],
            'br' => ['border-bottom-right-radius'],
            'bl' => ['border-bottom-left-radius'],
            // Logical corners (Tailwind v3.3+)
            's'  => ['border-start-start-radius', 'border-end-start-radius'], // Top-start, Bottom-start (for LTR)
            'e'  => ['border-start-end-radius', 'border-end-end-radius'],     // Top-end, Bottom-end (for LTR)
            'ss' => ['border-start-start-radius'], // Top-left in LTR, Top-right in RTL
            'se' => ['border-start-end-radius'],   // Top-right in LTR, Top-left in RTL
            'es' => ['border-end-start-radius'],   // Bottom-left in LTR, Bottom-right in RTL
            'ee' => ['border-end-end-radius'],     // Bottom-right in LTR, Bottom-left in RTL
        ];

        if ($cornerIndicator && isset($map[$cornerIndicator])) {
            foreach ($map[$cornerIndicator] as $prop) {
                $properties[$prop] = $radiusValue;
            }
        } elseif (!$cornerIndicator) { // Apply to all corners (e.g., rounded-md, rounded-full, rounded)
            $properties['border-radius'] = $radiusValue;
        } else {
            // This case might occur if the regex matched a side indicator but it's not in our map (e.g. invalid side)
            return null;
        }
        
        return !empty($properties) ? $properties : null;
    }

    private function handleBoxShadow(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1] ?? 'DEFAULT';
        $styles = [];

        if (strpos($valueKey, '[') === 0 && strpos($valueKey, ']') === strlen($valueKey)-1) { // shadow-[...]
            $styles['box-shadow'] = str_replace('_', ' ', trim($valueKey, '[]'));
        } else {
            $val = $this->lookupThemeValue('boxShadow', $valueKey);
            if (is_array($val)) { // If theme returns an array of shadows
                $styles['box-shadow'] = implode(', ', $val);
            } elseif (is_string($val)) {
                $styles['box-shadow'] = $val;
            } else {
                return null;
            }
        }
        // Tailwind's shadow utilities often use CSS variables for colors and opacity
        // For a more complete implementation, you might need to set --tw-shadow-color etc.
        // and then use box-shadow: var(--tw-shadow);
        // This simplified version directly sets the box-shadow property.
        $styles['--tw-shadow'] = $styles['box-shadow']; // For compatibility if other utilities expect --tw-shadow
        $styles['--tw-shadow-colored'] = '0 0 #0000'; // Placeholder for colored shadows

        return $styles;
    }
    private function handleOpacity(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1];

        if (strpos($valueKey, '[') === 0 && str_ends_with($valueKey, ']')) {
            $val = trim($valueKey, '[]');
            return (is_numeric($val) || str_ends_with($val, '%')) ? ['opacity' => $val] : null;
        }

        $themeVal = $this->lookupThemeValue('opacity', $valueKey);
        if ($themeVal !== null) {
            return ['opacity' => $themeVal];
        }

        if (is_numeric($valueKey)) {
            $opacity = (float)$valueKey;
            if ($opacity > 1) {
                $opacity = $opacity / 100;
            }
            return ['opacity' => (string)$opacity];
        }

        return null;
    }
    private function handleMixBlendMode(string $baseClassPart, array $matches): ?array { $modes = ['normal','multiply','screen','overlay','darken','lighten','color-dodge','color-burn','hard-light','soft-light','difference','exclusion','hue','saturation','color','luminosity','plus-lighter']; if(in_array($matches[1], $modes)) return ['mix-blend-mode' => $matches[1]]; return null; }
    private function handleBackgroundBlendMode(string $baseClassPart, array $matches): ?array { $modes = ['normal','multiply','screen','overlay','darken','lighten','color-dodge','color-burn','hard-light','soft-light','difference','exclusion','hue','saturation','color','luminosity']; if(in_array($matches[1], $modes)) return ['background-blend-mode' => $matches[1]]; return null; }
    
    private function handleCornerGlow(string $baseClassPart, array $matches): ?array {
        // --- ধাপ ক: পার্সিং (Regex-এর উপর নির্ভর না করে PHP দিয়ে) ---
        $parts = explode('-', $baseClassPart);
        $type = array_shift($parts); // shine, glow, etc.

        // ক্লাস স্ট্রিং-এর শেষ থেকে পার্স করা শুরু করুন
        $cornerOrSize = array_pop($parts);
        $size = null;
        $corner = null;

        if (is_numeric($cornerOrSize)) {
            $size = $cornerOrSize;
            $corner = array_pop($parts);
        } else {
            $corner = $cornerOrSize;
        }
        
        $colorVariant = implode('-', $parts);
        if (empty($colorVariant)) {
            $colorVariant = 'primary';
        }
        
        // --- ধাপ খ: রঙ এবং HSL ভেরিয়েবল নির্ধারণ (সবচেয়ে গুরুত্বপূর্ণ অংশ) ---
        $hslVarName = null;
        $hslFallback = '0 91% 63%'; // ডিফল্ট লাল রঙের ফলব্যাক

        // চেক করুন এটি একটি সেমান্টিক থিম কীওয়ার্ড কিনা (primary, destructive, etc.)
        if (isset($this->config['presets']['default']['--' . $colorVariant])) {
            $hslVarName = "--{$colorVariant}";
        } else {
            // অন্যথায়, এটিকে সাধারণ রঙ হিসেবে পার্স করার চেষ্টা করুন
            $colorString = $this->parseColorValue($colorVariant);
            if ($colorString) {
                $hslFallback = $this->convertColorToHslComponentsString($colorString) ?? $hslFallback;
            } else {
                return null; // যদি রঙটি অবৈধ হয়
            }
        }

        // --- ধাপ গ: প্রয়োজনীয় কীফ্রেম রেজিস্টার করা ---
        $this->neededKeyframes['corner-glow'] = $this->config['theme']['keyframes']['corner-glow'] ?? '{ 0% { opacity: 0; transform: scale(0.85); } 100% { opacity: 1; transform: scale(1); } }';
        $this->neededKeyframes['glow-pulse-nc'] = $this->config['theme']['keyframes']['glow-pulse-nc'] ?? '{ 0%, 100% { opacity: 0.7; transform: scale(1); } 50% { opacity: 1; transform: scale(1.02); } }';

        // --- ধাপ ঘ: একটিমাত্র স্টাইল অ্যারে তৈরি করা ---
        $styles = [];
        
        // `--glow-color-hsl`-এর মান এখন হয় একটি ভেরিয়েবল, অথবা একটি স্থির HSL মান
        if ($hslVarName) {
            $styles['--glow-color-hsl'] = "var({$hslVarName})";
        } else {
            $styles['--glow-color-hsl'] = $hslFallback;
        }

        $gradientPosition = match($corner) {
            'tr' => 'top right', 'tl' => 'top left', 'br' => 'bottom right', 'bl' => 'bottom left',
            't' => 'top', 'b' => 'bottom', 'l' => 'left', 'r' => 'right', default => 'center',
        };

        switch ($type) {
            case 'shine':
                $styles = array_merge($styles, [
                    'position' => 'absolute', 'inset' => '0', 'border-radius' => 'inherit', 'z-index' => '3', 'pointer-events' => 'none',
                    'padding' => $size ? $size . 'px' : '1px',
                    'background' => "radial-gradient(circle 350px at {$gradientPosition}, hsla(var(--glow-color-hsl) / 0.7), transparent 35%)",
                    '-webkit-mask' => 'linear-gradient(white, white) content-box, linear-gradient(white, white)',
                    'mask-composite' => 'exclude', 'animation' => 'corner-glow 2s 0.1s ease-out both',
                ]);
                $styles['-webkit-mask-composite'] = 'xor';
                break;

            case 'glow':
                $styles = array_merge($styles, [
                    'position' => 'absolute',
                    'inset' => $size ? -($size * 3) . 'px' : '-15px',
                    'border-radius' => 'inherit', 'z-index' => '1', 'pointer-events' => 'none',
                    'background' => "radial-gradient(ellipse 80% 80% at {$gradientPosition}, hsla(var(--glow-color-hsl) / 0.36), transparent 40%)",
                    'filter' => 'blur(' . ($size ? ($size * 5) . 'px' : '20px') . ')',
                    'animation' => 'corner-glow 1.5s 0.2s ease-out both',
                ]);
                break;
            
            case 'glow-bright':
                $styles = array_merge($styles, [
                    'position' => 'absolute', 'inset' => '0', 'border-radius' => 'inherit', 'z-index' => '2', 'pointer-events' => 'none',
                    'padding' => $size ? $size . 'px' : '2px',
                    'background' => "radial-gradient(circle 200px at {$gradientPosition}, hsla(var(--glow-color-hsl) / 0.8), transparent 45%)",
                    '-webkit-mask' => 'linear-gradient(white, white) content-box, linear-gradient(white, white)',
                    'mask-composite' => 'exclude',
                    'filter' => 'blur(' . ($size ? ($size * 1.5) . 'px' : '3px') . ')',
                    'mix-blend-mode' => 'plus-lighter',
                    'animation' => 'corner-glow 1.8s 0s ease-out both',
                ]);
                $styles['-webkit-mask-composite'] = 'xor';
                break;
                
            case 'pulse':
                $styles = array_merge($styles, [
                    'position' => 'absolute',
                    'inset' => $size ? -($size * 4) . 'px' : '-20px',
                    'border-radius' => 'inherit', 'z-index' => '1', 'pointer-events' => 'none',
                    'background' => "radial-gradient(ellipse 70% 70% at {$gradientPosition}, hsla(var(--glow-color-hsl) / 0.5), transparent 50%)",
                    'filter' => 'blur(' . ($size ? ($size * 6) . 'px' : '30px') . ')',
                    'animation' => 'glow-pulse-nc 4s ease-in-out infinite alternate',
                ]);
                break;
        }

        return ['layer' => 'components', 'style' => $styles];
    }

    private function buildFilterFunctionString(array $currentVars): string {
        $filters = [
            'blur' => '--tw-blur', 'brightness' => '--tw-brightness', 'contrast' => '--tw-contrast',
            'grayscale' => '--tw-grayscale', 'hue-rotate' => '--tw-hue-rotate', 'invert' => '--tw-invert',
            'saturate' => '--tw-saturate', 'sepia' => '--tw-sepia', 'drop-shadow' => '--tw-drop-shadow'
        ];
        $activeFilters = [];
        foreach($filters as $func => $varName){
            // Check if the var has a meaningful value (not just empty string or placeholder space)
            if(isset($currentVars[$varName]) && trim($currentVars[$varName]) !== ''){
                 $activeFilters[] = "{$func}(var({$varName}))";
            }
        }
        return implode(' ', $activeFilters);
    }

    private function handleBlur(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1] ?? 'DEFAULT';
        $val = $this->parseNumericValue($valueKey, 'blur', ['defaultUnit' => 'px', 'allowArbitrary'=>true]);
        if ($val === null && $valueKey === 'DEFAULT') $val = $this->lookupThemeValue('blur', 'DEFAULT');
        if ($val !== null) {
            $vars = ['--tw-blur' => $val];
            return array_merge($vars, ['filter' => $this->buildFilterFunctionString($vars)]);
        } return null;
    }
    private function handleBrightness(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1];
        $val = $this->parseNumericValue($valueKey, 'brightness', ['numericIsPercentage' => true, 'defaultUnit' => '', 'allowArbitrary'=>true]);
        if ($val !== null) {
            $vars = ['--tw-brightness' => $val];
            return array_merge($vars, ['filter' => $this->buildFilterFunctionString($vars)]);
        } return null;
    }
    private function handleContrast(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1];
        $val = $this->parseNumericValue($valueKey, 'contrast', ['numericIsPercentage' => true, 'defaultUnit' => '', 'allowArbitrary'=>true]);
         if ($val !== null) {
            $vars = ['--tw-contrast' => $val];
            return array_merge($vars, ['filter' => $this->buildFilterFunctionString($vars)]);
        } return null;
    }
    
    // In class TailwindPHP:
    private function handleDropShadow(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1] ?? 'DEFAULT';
        $currentVars = [/* Collect other --tw-filter-* vars if needed */]; // Placeholder
        $val = null;

        if (strpos($valueKey, '[') === 0 && strpos($valueKey, ']') === strlen($valueKey)-1) {
            $val = str_replace('_', ' ', trim($valueKey, '[]'));
        } else {
            $themeVal = $this->lookupThemeValue('dropShadow', $valueKey);
            if (is_array($themeVal)) {
                $val = implode(' ', $themeVal); // drop-shadow can take multiple values separated by space
            } elseif (is_string($themeVal)) {
                $val = $themeVal;
            }
        }

        if ($val !== null) {
            $currentVars['--tw-drop-shadow'] = $val; // It should be like drop-shadow(values)
            // Ensure the value itself is a valid drop-shadow function or multiple functions
            // Example: 'drop-shadow(0 1px 1px rgb(0 0 0 / 0.05))'
            // If $val from theme is just '0 1px 1px rgb(0 0 0 / 0.05)', wrap it:
            if (strpos($val, 'drop-shadow(') !== 0) {
                $currentVars['--tw-drop-shadow'] = "drop-shadow({$val})";
            }

            // We need to ensure that --tw-filter is defined to use this CSS var
            // The filter property should be composed of all active filter functions
            $filterProperties = ['--tw-blur'=>' ', '--tw-brightness'=>' ', '--tw-contrast'=>' ', '--tw-grayscale'=>' ', '--tw-hue-rotate'=>' ', '--tw-invert'=>' ', '--tw-saturate'=>' ', '--tw-sepia'=>' ']; // Default empty values
            $mergedVars = array_merge($filterProperties, $currentVars);

            return array_merge($mergedVars, ['filter' => $this->buildFilterFunctionString($mergedVars)]);
        }
        return null;
    }

    private function handleGrayscale(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1] ?? 'DEFAULT'; // DEFAULT means 100%, 0 means 0%
        $val = ($valueKey === '0') ? '0%' : '100%';
        if (preg_match('/^\[(\d+%?)\]$/', $valueKey, $arbMatch)) $val = $arbMatch[1];
        $vars = ['--tw-grayscale' => "grayscale({$val})"];
        return array_merge($vars, ['filter' => $this->buildFilterFunctionString($vars)]);
    }
    private function handleHueRotate(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1]; $isNegative = str_starts_with($valueKey, '-'); if($isNegative) $valueKey = substr($valueKey, 1);
        $val = $this->parseNumericValue($valueKey, 'hueRotate', ['defaultUnit' => 'deg', 'allowArbitrary'=>true]); // Theme might store '15deg' or just '15'
        if ($val !== null) {
            $vars = ['--tw-hue-rotate' => ($isNegative ? '-' : '') . $val];
            return array_merge($vars, ['filter' => $this->buildFilterFunctionString($vars)]);
        } return null;
    }
    private function handleInvert(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1] ?? 'DEFAULT'; $val = ($valueKey === '0') ? '0%' : '100%';
        if (preg_match('/^\[(\d+%?)\]$/', $valueKey, $arbMatch)) $val = $arbMatch[1];
        $vars = ['--tw-invert' => "invert({$val})"];
        return array_merge($vars, ['filter' => $this->buildFilterFunctionString($vars)]);
    }
    private function handleSaturate(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1];
        $val = $this->parseNumericValue($valueKey, 'saturate', ['numericIsPercentage' => true, 'defaultUnit' => '', 'allowArbitrary'=>true]);
        if ($val !== null) {
            $vars = ['--tw-saturate' => $val];
            return array_merge($vars, ['filter' => $this->buildFilterFunctionString($vars)]);
        } return null;
    }
    private function handleSepia(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1] ?? 'DEFAULT'; $val = ($valueKey === '0') ? '0%' : '100%';
        if (preg_match('/^\[(\d+%?)\]$/', $valueKey, $arbMatch)) $val = $arbMatch[1];
        $vars = ['--tw-sepia' => "sepia({$val})"];
        return array_merge($vars, ['filter' => $this->buildFilterFunctionString($vars)]);
    }

    private function buildBackdropFilterFunctionString(): string {
        $filterFunctions = [
            'var(--tw-backdrop-blur,)',
            'var(--tw-backdrop-brightness,)',
            'var(--tw-backdrop-contrast,)',
            'var(--tw-backdrop-grayscale,)',
            'var(--tw-backdrop-hue-rotate,)',
            'var(--tw-backdrop-invert,)',
            'var(--tw-backdrop-opacity,)',
            'var(--tw-backdrop-saturate,)',
            'var(--tw-backdrop-sepia,)',
            'var(--tw-backdrop-filter-arbitrary,)',
        ];
        return implode(' ', $filterFunctions);
    }

    private function handleBackdropFilter(string $baseClassPart, array $matches): ?array {
        $filterType = $matches[1];
        $valueKey = $matches[2] ?? 'DEFAULT';
        
        $cssVar = "--tw-backdrop-{$filterType}";
        $cssFunctionWithValue = null;

        switch ($filterType) {
            case 'blur':
                $val = $this->parseNumericValue($valueKey, 'blur', ['defaultUnit' => 'px', 'allowArbitrary' => true]);
                if ($val !== null) $cssFunctionWithValue = "blur({$val})";
                break;
            case 'brightness': case 'contrast': case 'saturate': case 'opacity':
                $val = $this->parseNumericValue($valueKey, $filterType, ['numericIsRaw' => true, 'allowArbitrary' => true]);
                if ($val !== null) $cssFunctionWithValue = "{$filterType}({$val})";
                break;
            case 'grayscale': case 'invert': case 'sepia':
                $val = $this->parseNumericValue($valueKey, 'opacity', ['numericIsRaw' => true, 'allowArbitrary' => true]);
                if ($val === null && $valueKey === 'DEFAULT') $val = '1';
                elseif ($val === null && $valueKey === '0') $val = '0';
                if ($val !== null) $cssFunctionWithValue = "{$filterType}({$val})";
                break;
            case 'hue-rotate':
                $isNegative = str_starts_with($valueKey, '-');
                if ($isNegative) $valueKey = substr($valueKey, 1);
                $val = $this->parseNumericValue($valueKey, 'hueRotate', ['defaultUnit' => 'deg', 'allowArbitrary' => true]);
                if ($val !== null) $cssFunctionWithValue = "hue-rotate(" . ($isNegative ? '-' : '') . $val . ")";
                break;
        }

        if ($cssFunctionWithValue !== null) {
            return [
                $cssVar => $cssFunctionWithValue,
                'backdrop-filter' => $this->buildBackdropFilterFunctionString(),
                '-webkit-backdrop-filter' => $this->buildBackdropFilterFunctionString(),
            ];
        }
        return null;
    }

    private function handleFilter(string $baseClassPart, array $matches): ?array {
        $filterType = $matches[1];
        $valueKey = $matches[2] ?? 'DEFAULT';
        
        $cssVar = "--tw-{$filterType}";
        $cssFunctionWithValue = null;

        switch ($filterType) {
            case 'blur':
                $val = $this->parseNumericValue($valueKey, 'blur', ['defaultUnit' => 'px', 'allowArbitrary' => true]);
                if ($val !== null) $cssFunctionWithValue = "blur({$val})";
                break;
            case 'brightness': case 'contrast': case 'saturate': case 'opacity':
                $val = $this->parseNumericValue($valueKey, $filterType, ['numericIsRaw' => true, 'allowArbitrary' => true]);
                if ($val !== null) $cssFunctionWithValue = "{$filterType}({$val})";
                break;
            case 'grayscale': case 'invert': case 'sepia':
                $val = $this->parseNumericValue($valueKey, 'opacity', ['numericIsRaw' => true, 'allowArbitrary' => true]);
                if ($val === null && $valueKey === 'DEFAULT') $val = '1';
                elseif ($val === null && $valueKey === '0') $val = '0';
                if ($val !== null) $cssFunctionWithValue = "{$filterType}({$val})";
                break;
            case 'hue-rotate':
                $isNegative = str_starts_with($valueKey, '-');
                if ($isNegative) $valueKey = substr($valueKey, 1);
                $val = $this->parseNumericValue($valueKey, 'hueRotate', ['defaultUnit' => 'deg', 'allowArbitrary' => true]);
                if ($val !== null) $cssFunctionWithValue = "hue-rotate(" . ($isNegative ? '-' : '') . $val . ")";
                break;
        }

        if ($cssFunctionWithValue !== null) {
            return [
                $cssVar => $cssFunctionWithValue,
                'filter' => $this->buildFilterFunctionString(),
            ];
        }
        return null;
    }

    private function handleMasking(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1];

        // 1. Handle arbitrary values like mask-[linear-gradient(to_top,black,transparent)]
        if (strpos($valueKey, '[') === 0 && str_ends_with($valueKey, ']')) {
            $arbitraryValue = trim($valueKey, '[]');
            
            // Replace underscores with spaces for CSS functions
            $cssValue = str_replace('_', ' ', $arbitraryValue);

            // A basic check to see if it's a valid CSS image value
            if (str_contains($cssValue, 'gradient') || str_contains($cssValue, 'url(')) {
                return [
                    '-webkit-mask-image' => $cssValue,
                    'mask-image' => $cssValue,
                    // It's good practice to set these defaults when setting mask-image
                    '-webkit-mask-repeat' => 'no-repeat',
                    'mask-repeat' => 'no-repeat',
                    '-webkit-mask-size' => 'cover',
                    'mask-size' => 'cover',
                ];
            }
        }

        // 2. Lookup predefined mask values from theme config
        $predefinedMask = $this->lookupThemeValue('maskImage', $valueKey);

        if ($predefinedMask !== null) {
            return [
                '-webkit-mask-image' => $predefinedMask,
                'mask-image' => $predefinedMask,
                '-webkit-mask-repeat' => 'no-repeat',
                'mask-repeat' => 'no-repeat',
                '-webkit-mask-size' => 'cover',
                'mask-size' => 'cover',
            ];
        }

        return null;
    }

    private function handleIsolation(string $baseClassPart, array $matches): ?array {
        return ['isolation' => $matches[1]];
    }

    private function handleFilterUrl(string $baseClassPart, array $matches): ?array {
        $value = trim($matches[1]);

        if ($value === 'none') {
            return ['filter' => 'none'];
        }

        $cssValue = str_replace('_', ' ', $value);

        $finalFilterValue = '';

        if (str_starts_with($cssValue, 'url(')) {
            $finalFilterValue = $cssValue;
        } 
        elseif (str_starts_with($cssValue, 'data:image/svg+xml')) {
            $finalFilterValue = "url('{$cssValue}')";
        }
        elseif (str_starts_with($cssValue, '/') || str_starts_with($cssValue, './') || str_starts_with($cssValue, '../')) {
            $finalFilterValue = "url('{$cssValue}')";
        }
        else {
            if (!str_starts_with($cssValue, '#')) {
                $cssValue = '#' . $cssValue;
            }
            $finalFilterValue = "url({$cssValue})";
        }
        
        return [
            'filter' => $finalFilterValue,
            'isolation' => 'isolate', 
        ];
    }

    private function handleMask(string $baseClassPart, array $matches): ?array {
        $shape = $matches[1] ?? 'base';
        if ($shape === 'base') {
            return [
                '-webkit-mask-size' => 'contain', 'mask-size' => 'contain',
                '-webkit-mask-repeat' => 'no-repeat', 'mask-repeat' => 'no-repeat',
                '-webkit-mask-position' => 'center', 'mask-position' => 'center',
            ];
        }
        $svgContent = $this->config['theme']['icons'][$shape] ?? null;

        if (!$svgContent) {
            $clipPath = match ($shape) {
                // Basic Shapes
                'squircle' => 'inset(0% 0% 0% 0% round 1.9rem)', // daisyUI's specific curve
                'circle' => 'circle(50% at 50% 50%)',
                'square' => 'polygon(0% 0%, 100% 0%, 100% 100%, 0% 100%)',
                'diamond' => 'polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%)',
                'pentagon' => 'polygon(50% 0%, 100% 38%, 82% 100%, 18% 100%, 0% 38%)',

                // Polygons
                'hexagon' => 'polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%)',
                'hexagon-2' => 'polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%)',
                'decagon' => 'polygon(50% 0%, 80.9% 19.1%, 100% 50%, 80.9% 80.9%, 50% 100%, 19.1% 80.9%, 0% 50%, 19.1% 19.1%)',
                'octagon' => 'polygon(30% 0%, 70% 0%, 100% 30%, 100% 70%, 70% 100%, 30% 100%, 0% 70%, 0% 30%)',

                // Stars
                'star' => 'polygon(50% 0%, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%)',
                'star-2' => 'polygon(50% 0%, 63% 38%, 100% 38%, 69% 59%, 82% 100%, 50% 75%, 18% 100%, 31% 59%, 0% 38%, 37% 38%)',

                // Triangles
                'triangle' => 'polygon(50% 0%, 0% 100%, 100% 100%)',
                'triangle-2' => 'polygon(0% 0%, 100% 0%, 50% 100%)',
                'triangle-3' => 'polygon(100% 0%, 0% 50%, 100% 100%)',
                'triangle-4' => 'polygon(0% 0%, 0% 100%, 100% 50%)',

                // Heart (Complex Path)
                'heart' => "path('M10,21.25c-5.52,0-10-4.48-10-10S4.48,1.25,10,1.25c2.76,0,5.26,1.12,7.07,2.93c1.81-1.81,4.31-2.93,7.07-2.93c5.52,0,10,4.48,10,10c0,5.52-4.48,10-10,10c-2.76,0-5.26-1.12-7.07-2.93C15.26,20.13,12.76,21.25,10,21.25Z')",
                
                // Half Masks
                'half-1' => 'inset(0 50% 0 0)',
                'half-2' => 'inset(0 0 0 50%)',
                
                // Arrow
                'arrow' => 'polygon(0% 33%, 66% 33%, 66% 0%, 100% 50%, 66% 100%, 66% 66%, 0% 66%)',

                // Message Bubble
                'bubble' => 'polygon(0% 0%, 100% 0%, 100% 75%, 75% 75%, 75% 100%, 50% 75%, 0% 75%)',
                
                // Cross
                'cross' => 'polygon(20% 0, 0 20%, 30% 50%, 0 80%, 20% 100%, 50% 70%, 80% 100%, 100% 80%, 70% 50%, 100% 20%, 80% 0, 50% 30%)',

                default => null,
            };
            return $clipPath ? ['clip-path' => $clipPath] : null;
        }
        $svgForMask = preg_replace('/fill="[^"]+"/', 'fill="black"', $svgContent);
        if (!str_contains($svgForMask, 'fill=')) {
            $svgForMask = preg_replace('/<svg([^>]+)>/', '<svg$1 fill="black">', $svgForMask);
        }
        
        $encodedSvg = rawurlencode($svgForMask);
        $dataUri = "url(\"data:image/svg+xml;charset=utf-8,{$encodedSvg}\")";

        return [
            '-webkit-mask-image' => $dataUri,
            'mask-image' => $dataUri,
        ];
    }

    private function buildTransitionFunctionString(): string {
        return implode(', ', [
            'var(--tw-transition-property, all) ' .
            'var(--tw-transition-duration, 150ms) ' .
            'var(--tw-transition-timing-function, cubic-bezier(0.4, 0, 0.2, 1)) ' .
            'var(--tw-transition-delay, 0s)'
        ]);
    }

    private function handleTransitionProperty(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1] ?? 'DEFAULT';
        
        if (str_starts_with($valueKey, '[')) {
            $arbitraryValue = trim($valueKey, '[]');
            $cssValue = str_replace('_', ' ', $arbitraryValue);
            
            // Shorthand ভ্যালু চেক করা হচ্ছে
            if (preg_match('/(\d|ease|linear|cubic-bezier)/', $cssValue)) {
                return ['transition' => $cssValue];
            } else {
                // শুধুমাত্র প্রপার্টি লিস্ট
                return [
                    '--tw-transition-property' => str_replace(',', ', ', $cssValue),
                    'transition' => $this->buildTransitionFunctionString(),
                ];
            }
        }

        // কীওয়ার্ড-ভিত্তিক ভ্যালু
        $propertyValue = $this->lookupThemeValue('transitionProperty', $valueKey);
        if ($propertyValue === null && !in_array($valueKey, ['DEFAULT', 'all'])) {
            $propertyValue = str_starts_with($valueKey, '--') ? $valueKey : $this->camelToKebab($valueKey);
        }

        if ($propertyValue !== null) {
            return [
                '--tw-transition-property' => $propertyValue,
                'transition' => $this->buildTransitionFunctionString(),
            ];
        }

        return null;
    }

    private function handleTransitionDuration(string $baseClassPart, array $matches): ?array {
        // ক্লাস থেকে মান পার্স করা হচ্ছে (যেমন '500' বা '[10s]')
        $val = $this->parseNumericValue($matches[1], 'transitionDuration', ['defaultUnit' => 'ms']);
        
        // যদি কোনো ভ্যালিড মান না পাওয়া যায়, তাহলে null রিটার্ন করুন
        if ($val === null) {
            return null;
        }

        // সঠিক CSS ভেরিয়েবলগুলো সেট করা হচ্ছে
        return [
            '--tw-transition-duration' => $val,
            '--tw-animation-duration' => $val,
            
            // কম্পোজেবিলিটি বজায় রাখার জন্য উভয় shorthand প্রপার্টি আপডেট করা হচ্ছে
            'transition' => $this->buildTransitionFunctionString(),
            'animation' => $this->buildAnimationFunctionString(),
        ];
    }

    private function handleDuration(string $baseClassPart, array $matches): ?array {
        $val = $this->parseNumericValue($matches[1], 'transitionDuration', ['defaultUnit' => 'ms']);
        if ($val === null) return null;

        return [
            '--tw-transition-duration' => $val,
            '--tw-animation-duration' => $val,
            'transition' => $this->buildTransitionFunctionString(),
            'animation' => $this->buildAnimationFunctionString(),
        ];
    }
    
    private function handleTransitionTimingFunction(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1];
        $val = null;
        if (str_starts_with($valueKey, '[')) {
            $val = str_replace('_', ' ', trim($valueKey, '[]'));
        } else {
            $val = $this->lookupThemeValue('transitionTimingFunction', $valueKey);
        }
        
        if ($val === null) return null;

        return [
            '--tw-transition-timing-function' => $val,
            '--tw-animation-timing-function' => $val,
            'transition' => $this->buildTransitionFunctionString(),
            'animation' => $this->buildAnimationFunctionString(),
        ];
    }
    
    private function handleTransitionDelay(string $baseClassPart, array $matches): ?array {
        $val = $this->parseNumericValue($matches[1], 'transitionDelay', ['defaultUnit' => 'ms']);
        return $val ? [
            '--tw-transition-delay' => $val,
            'transition' => $this->buildTransitionFunctionString(),
        ] : null;
    }

    private function handleAnimationProperty(string $baseClassPart, array $matches): ?array {
        $keyAndValue = $matches[1];

        $keywordMap = [
            'forwards' => ['--tw-animation-fill-mode' => 'forwards'],
            'backwards' => ['--tw-animation-fill-mode' => 'backwards'],
            'both' => ['--tw-animation-fill-mode' => 'both'],
            'running' => ['--tw-animation-play-state' => 'running'],
            'paused' => ['--tw-animation-play-state' => 'paused'],
            'normal' => ['--tw-animation-direction' => 'normal'],
            'reverse' => ['--tw-animation-direction' => 'reverse'],
            'alternate' => ['--tw-animation-direction' => 'alternate'],
            'alternate-reverse' => ['--tw-animation-direction' => 'alternate-reverse'],
            'infinite' => ['--tw-animation-iteration-count' => 'infinite'],
        ];

        if (isset($keywordMap[$keyAndValue])) {
            $styles = $keywordMap[$keyAndValue];
            $styles['animation'] = $this->buildAnimationFunctionString();
            return $styles;
        }

        $parts = explode('-', $keyAndValue, 2);
        if (count($parts) < 2) return null;
        
        $property = $parts[0];
        $valueKey = $parts[1];

        $cssVar = null;
        $cssValue = null;

        switch ($property) {
            case 'duration':
                $cssVar = '--tw-animation-duration';
                $cssValue = $this->parseNumericValue($valueKey, 'animationDuration', ['defaultUnit' => 'ms']);
                break;
            case 'delay':
                $cssVar = '--tw-animation-delay';
                $cssValue = $this->parseNumericValue($valueKey, 'animationDelay', ['defaultUnit' => 'ms']);
                break;
            case 'ease':
                $cssVar = '--tw-animation-timing-function';
                $cssValue = $this->lookupThemeValue('transitionTimingFunction', $valueKey);
                break;
            case 'repeat':
                $cssVar = '--tw-animation-iteration-count';
                $cssValue = $this->parseNumericValue($valueKey, '', ['numericIsRaw' => true]);
                break;
        }

        if ($cssVar && $cssValue !== null) {
            return [
                $cssVar => $cssValue,
                'animation' => $this->buildAnimationFunctionString(),
            ];
        }

        return null;
    }

    private function handleAnimationKeywords(string $baseClassPart, array $matches): ?array {
        $keyword = $matches[1];
        $propertyMap = [
            'infinite' => ['animation-iteration-count' => 'infinite'],
            'forwards' => ['animation-fill-mode' => 'forwards'],
            'backwards' => ['animation-fill-mode' => 'backwards'],
            'both' => ['animation-fill-mode' => 'both'],
            'running' => ['animation-play-state' => 'running'],
            'paused' => ['animation-play-state' => 'paused'],
            'normal' => ['animation-direction' => 'normal'],
            'reverse' => ['animation-direction' => 'reverse'],
            'alternate' => ['animation-direction' => 'alternate'],
            'alternate-reverse' => ['animation-direction' => 'alternate-reverse'],
        ];

        if (isset($propertyMap[$keyword])) {
            return $propertyMap[$keyword];
        }

        return null;
    }

    private function handleAnimationIterationCount(string $baseClassPart, array $matches): ?array {
        $value = $matches[1];
        $iterationCount = '';

        switch ($value) {
            case 'infinite': $iterationCount = 'infinite'; break;
            case 'once': $iterationCount = '1'; break;
            case 'twice': $iterationCount = '2'; break;
            case 'thrice': $iterationCount = '3'; break;
            default:
                if (strpos($value, 'repeat-') === 0) {
                    $countPart = substr($value, strlen('repeat-'));
                    if (is_numeric($countPart)) {
                        $iterationCount = $countPart;
                    } elseif (strpos($countPart, '[') === 0 && str_ends_with($countPart, ']')) {
                        $iterationCount = trim($countPart, '[]');
                    }
                }
                break;
        }
        return !empty($iterationCount) ? ['--tw-animation-iteration-count' => $iterationCount] : null;
    }

    private function handleAnimationDuration(string $baseClassPart, array $matches): ?array {
        $value = $this->parseNumericValue($matches[1], 'animationDuration', ['defaultUnit' => 'ms', 'allowArbitrary' => true]);
        return $value ? ['animation-duration' => $value] : null;
    }
    private function handleAnimationDelay(string $baseClassPart, array $matches): ?array {
        $value = $this->parseNumericValue($matches[1], 'animationDelay', ['defaultUnit' => 'ms', 'allowArbitrary' => true]);
        return $value ? ['animation-delay' => $value] : null;
    }
    private function handleAnimationTimingFunction(string $valueKey): ?array {
        if (str_starts_with($valueKey, '[')) {
            $val = str_replace('_', ' ', trim($valueKey, '[]'));
            if (str_contains($val, 'cubic-bezier') || str_contains($val, 'steps')) {
                return ['animation-timing-function' => $val];
            }
        } else {
            $val = $this->lookupThemeValue('animationTimingFunction', $valueKey);
            return $val ? ['animation-timing-function' => $val] : null;
        }
        return null;
    }
    private function handleAnimationIterationCountKeyword(string $baseClassPart, array $matches): ?array {
        $keyword = $matches[1];
        $valueMap = ['once' => '1', 'twice' => '2', 'thrice' => '3', 'infinite' => 'infinite'];
        return isset($valueMap[$keyword]) ? ['animation-iteration-count' => $valueMap[$keyword]] : null;
    }
    private function handleAnimationIterationCountNumeric(string $baseClassPart, array $matches): ?array {
        $value = $matches[1];
        if (strpos($value, '[') === 0 && str_ends_with($value, ']')) {
            $value = trim($value, '[]');
        }
        return is_numeric($value) ? ['animation-iteration-count' => $value] : null;
    }
    private function handleAnimationDirection(string $baseClassPart, array $matches): ?array {
        $direction = $matches[1];
        $validDirections = ['normal', 'reverse', 'alternate', 'alternate-reverse'];
        return in_array($direction, $validDirections) ? ['animation-direction' => $direction] : null;
    }
    private function handleAnimationFillMode(string $baseClassPart, array $matches): ?array {
        $fillMode = $matches[1];
        $validFillModes = ['none', 'forwards', 'backwards', 'both'];
        return in_array($fillMode, $validFillModes) ? ['animation-fill-mode' => $fillMode] : null;
    }
    private function handleAnimationPlayState(string $baseClassPart, array $matches): ?array {
        $playState = $matches[1];
        $validPlayStates = ['running', 'paused'];
        return in_array($playState, $validPlayStates) ? ['animation-play-state' => $playState] : null;
    }

    private function buildAnimationFunctionString(): string {
        return implode(' ', [
            'var(--tw-animation-name,)',
            'var(--tw-animation-duration, 1s)',
            'var(--tw-animation-timing-function, cubic-bezier(0.4, 0, 0.2, 1))',
            'var(--tw-animation-delay, 0s)',
            'var(--tw-animation-iteration-count, 1)',
            'var(--tw-animation-direction, normal)',
            'var(--tw-animation-fill-mode, none)',
            'var(--tw-animation-play-state, running)',
        ]);
    }

    private function handleAnimation(string $baseClassPart, array $matches): ?array {
        $animationKey = $matches[1];

        if (str_starts_with($animationKey, '[')) {
            $animationValue = str_replace('_', ' ', trim($animationKey, '[]'));
            $parts = explode(' ', $animationValue);
            
            if (isset($this->config['theme']['keyframes'][$parts[0]])) {
                $this->neededKeyframes[$parts[0]] = $this->config['theme']['keyframes'][$parts[0]];
            }
            
            return ['animation' => $animationValue];
        }
        
        $animationName = $this->lookupThemeValue('animation', $animationKey);

        if ($animationName) {
            if ($animationName === 'none') {
                return ['animation' => 'none'];
            }
            
            if (isset($this->config['theme']['keyframes'][$animationName])) {
                $this->neededKeyframes[$animationName] = $this->config['theme']['keyframes'][$animationName];
            }

            return [
                '--tw-animation-name' => $animationName,
                'animation' => $this->buildAnimationFunctionString(),
            ];
        }
        return null;
    }

    private function handleTextGradientAnim(string $baseClassPart, array $matches): ?array {
        $this->neededKeyframes['text-gradient-flow'] = '{ 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }';
        
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        $css = <<<CSS
        {$selector} {
            background-size: 200% auto;
            color: transparent;
            -webkit-background-clip: text;
            background-clip: text;
            animation: text-gradient-flow 3s linear infinite;
        }
        CSS;
        return ['layer' => 'utilities', 'style' => $css];
    }
    
    private function handleTransformBase(string $classPart, array $matches): ?array {
        if ($classPart === 'transform' || $classPart === 'transform-gpu') {
            return ['transform' => $this->buildTransformFunctionString($classPart === 'transform-gpu')];
        } elseif ($classPart === 'transform-none') {
            return ['transform' => 'none'];
        }
        return null;
    }

    private function handleTransformTranslate(string $baseClassPart, array $matches): ?array {
        $axisWithPrefix = $matches[1];
        $valueKey = $matches[2];
        $isNegative = str_starts_with($baseClassPart, '-');
        
        $axis = substr($axisWithPrefix, -1);
        
        $value = $this->parseNumericValue($valueKey, 'translate');
        if ($value === null) return null;
        
        $finalValue = ($isNegative && $value !== '0') ? '-' . $value : $value;

        if ($finalValue !== null) {
            return [
                "--tw-translate-{$axis}" => $finalValue,
                'transform' => $this->buildTransformFunctionString(),
            ];
        }

        return null;
    }

    private function buildTransformFunctionString(bool $useGpu = false): string {
        $translateFunc = $useGpu 
            ? 'translate3d(var(--tw-translate-x, 0), var(--tw-translate-y, 0), 0)' 
            : 'translate(var(--tw-translate-x, 0), var(--tw-translate-y, 0))';

        $transforms = [
            $translateFunc,
            'rotate(var(--tw-rotate, 0))',
            'skewX(var(--tw-skew-x, 0))',
            'skewY(var(--tw-skew-y, 0))',
            'scaleX(var(--tw-scale-x, 1))',
            'scaleY(var(--tw-scale-y, 1))',
            'var(--tw-transform-arbitrary,)',
        ];
        
        return trim(implode(' ', $transforms));
    }

    private function handleTransformRotate(string $classPart, array $matches): ?array { $valueKey = $matches[1]; $isNegative = str_starts_with($valueKey, '-'); if($isNegative) $valueKey = substr($valueKey, 1); $value = $this->lookupThemeValue('rotate', $valueKey); if($value === null && (is_numeric($valueKey) || (str_ends_with($valueKey, 'deg') && is_numeric(rtrim($valueKey,'deg')))) ) $value = is_numeric($valueKey) ? $valueKey . 'deg' : $valueKey; if ($value === null && preg_match('/^\[(.+)\]$/', $valueKey, $arb)) $value = $arb[1]; if ($value === null) return null; return ['--tw-rotate' => ($isNegative ? '-' : '') . $value];}
    
    private function handleTransformSkew(string $classPart, array $matches): ?array {
        $axis = $matches[1];
        $valueKey = $matches[2] ?? 'DEFAULT'; 
        
        $isNegative = str_starts_with($classPart, '-');
        
        $value = $this->lookupThemeValue('skew', $valueKey);
        
        if ($value === null) {
            if (is_numeric($valueKey) || (str_ends_with($valueKey, 'deg') && is_numeric(rtrim($valueKey, 'deg')))) {
                $value = is_numeric($valueKey) ? $valueKey . 'deg' : $valueKey;
            } elseif (preg_match('/^\[(.+)\]$/', $valueKey, $arb)) {
                $value = str_replace('_', ' ', $arb[1]);
            }
        }

        if ($value === null) {
            return null;
        }

        return [
            "--tw-skew-{$axis}" => ($isNegative ? '-' : '') . $value,
            'transform' => $this->buildTransformFunctionString(),
        ];
    }
    
    private function handleTransformScale(string $baseClassPart, array $matches): ?array {
        $axis = $matches[1] ?? null;
        $valueKey = $matches[2] ?? 'DEFAULT';
        $value = $this->lookupThemeValue('scale', $valueKey);
        
        if ($value === null && is_numeric($valueKey)) {
            $value = (float)$valueKey / 100;
        }

        if ($value === null) {
            if (preg_match('/^\[(\d+\.?\d*?%?)\]$/', $valueKey, $arbMatch)) {
                $arbVal = $arbMatch[1];
                $value = str_ends_with($arbVal, '%') ? (float)rtrim($arbVal, '%') / 100 : (float)$arbVal;
            } else {
                return null;
            }
        }
        
        $styles = [];
        if ($axis === 'x') {
            $styles['--tw-scale-x'] = (string)$value;
        } elseif ($axis === 'y') {
            $styles['--tw-scale-y'] = (string)$value;
        } else {
            $styles['--tw-scale-x'] = (string)$value;
            $styles['--tw-scale-y'] = (string)$value;
        }

        $styles['transform'] = $this->buildTransformFunctionString();

        return $styles;
    }

    private function handleTransformOrigin(string $classPart, array $matches): ?array { $val = str_replace('-', ' ', $matches[1]); return ['transform-origin' => $val];}

    private function handlePerspective(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1];
        
        if ($valueKey === 'none') {
            return ['perspective' => 'none'];
        }

        $perspectiveValue = $this->parseNumericValue($valueKey, 'perspective', ['defaultUnit' => 'px']);
        
        if ($perspectiveValue !== null) {
            return ['perspective' => $perspectiveValue];
        }
        
        return null;
    }

    private function handlePerspectiveOrigin(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1];
        
        if (str_starts_with($valueKey, '[')) {
            $arbitraryValue = trim($valueKey, '[]');
            $cssValue = str_replace('_', ' ', $arbitraryValue);
            return ['perspective-origin' => $cssValue];
        }

        $originValue = $this->lookupThemeValue('perspectiveOrigin', $valueKey);
        
        if ($originValue !== null) {
            return ['perspective-origin' => $originValue];
        }

        return null;
    }

    private function handleBackfaceVisibility(string $baseClassPart, array $matches): ?array { return ['backface-visibility' => $matches[1]]; }
    private function handleAppearance(string $classPart, array $matches): ?array { return ['appearance' => 'none']; }
    private function handleAccentColor(string $classPart, array $matches): ?array { $valuePart = $matches[1]; if ($valuePart === 'auto') return ['accent-color' => 'auto']; $color = $this->parseColorValue($valuePart); return $color ? ['accent-color' => $color] : null; }
    private function handleCursor(string $classPart, array $matches): ?array { return ['cursor' => str_replace('-', ' ', $matches[1])]; }
    private function handlePointerEvents(string $classPart, array $matches): ?array { return ['pointer-events' => $matches[1]]; }
    private function handleResize(string $classPart, array $matches): ?array { $val = $matches[1] ?? 'both'; return ['resize' => $val === 'none' ? 'none' : $val]; }
    private function handleScrollBehavior(string $classPart, array $matches): ?array { return ['scroll-behavior' => 'smooth']; }
    private function handleScrollMargin(string $baseClassPart, array $matches): ?array { $type = 'scroll-' . ($matches[1] ? 'margin-' . str_replace(['x', 'y', 's', 'e'], ['left', 'top', 'inline-start', 'inline-end'], rtrim($matches[1],'-')) : 'margin'); $value = $this->parseNumericValue($matches[2], 'spacing', ['allowNegative'=>true]); if($value === null) return null; if($matches[1] === 'x-') return ['scroll-margin-left'=>$value, 'scroll-margin-right'=>$value]; if($matches[1] === 'y-') return ['scroll-margin-top'=>$value, 'scroll-margin-bottom'=>$value]; return [$type => $value];}
    private function handleScrollPadding(string $baseClassPart, array $matches): ?array { $type = 'scroll-' . ($matches[1] ? 'padding-' . str_replace(['x', 'y', 's', 'e'], ['left', 'top', 'inline-start', 'inline-end'], rtrim($matches[1],'-')) : 'padding'); $value = $this->parseNumericValue($matches[2], 'spacing'); if($value === null) return null; if($matches[1] === 'x-') return ['scroll-padding-left'=>$value, 'scroll-padding-right'=>$value]; if($matches[1] === 'y-') return ['scroll-padding-top'=>$value, 'scroll-padding-bottom'=>$value]; return [$type => $value];}
    private function handleUserSelect(string $baseClassPart, array $matches): ?array { return ['user-select' => $matches[1]]; }
    private function handleWillChange(string $baseClassPart, array $matches): ?array { return ['will-change' => $matches[1]]; }
    private function handleTouchAction(string $baseClassPart, array $matches): ?array {
        $value = $matches[1]; // e.g., 'auto', 'none', 'pan-y'

        // Tailwind-এর মানগুলো সরাসরি CSS মানের সাথে মিলে যায়,
        // তাই আমাদের কোনো বিশেষ ম্যাপিং-এর প্রয়োজন নেই।
        $validValues = [
            'auto',
            'none',
            'pan-x',
            'pan-y',
            'pan-left',
            'pan-right',
            'pan-up',
            'pan-down',
            'pinch-zoom',
            'manipulation'
        ];

        if (in_array($value, $validValues)) {
            // --tw-touch-action ভেরিয়েবল সেট করা হচ্ছে, যা টাচ অ্যাকশনের কম্পোজিবিলিটি বাড়ায়।
            // যদিও touch-action সরাসরি কম্পোজ করা যায় না,
            // এই ভেরিয়েবলটি ডিবাগিং বা কাস্টমাইজেশনের জন্য সহায়ক হতে পারে।
            return [
                '--tw-touch-action' => $value,
                'touch-action' => 'var(--tw-touch-action)'
            ];
        }

        return null; // যদি কোনো বৈধ মান না পাওয়া যায়
    }
    private function handleScrollSnapType(string $baseClassPart, array $matches): ?array {
        $value = $matches[1];
        $propertyValue = ($value === 'none') ? 'none' : $value . ' var(--tw-scroll-snap-strictness)';
        return [
            'scroll-snap-type' => $propertyValue,
            '--tw-scroll-snap-strictness' => 'proximity', // Default strictness
        ];
    }

    private function handleScrollSnapStrictness(string $baseClassPart, array $matches): ?array {
        $value = str_replace('snap-', '', $baseClassPart); // 'mandatory' or 'proximity'
        return ['--tw-scroll-snap-strictness' => $value];
    }
    
    private function handleScrollSnapAlign(string $baseClassPart, array $matches): ?array {
        $value = str_replace('align-', '', $matches[1]); // 'start', 'end', 'center', or 'none'
        return ['scroll-snap-align' => $value];
    }

    private function handleInputSpinner(string $baseClassPart, array $matches): ?array {
        $action = $matches[1];
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $cssString = "";

        if ($action === 'hide') {
            // Firefox-এর জন্য স্পিনার লুকানো
            $cssString .= "/* Hide spinner for Firefox */\n";
            $cssString .= "{$selector} {\n";
            $cssString .= "  -moz-appearance: textfield;\n";
            $cssString .= "}\n\n";

            // WebKit (Chrome, Safari, Edge)-এর জন্য স্পিনার লুকানো
            $cssString .= "/* Hide spinner for WebKit browsers */\n";
            $cssString .= "{$selector}::-webkit-outer-spin-button,\n";
            $cssString .= "{$selector}::-webkit-inner-spin-button {\n";
            $cssString .= "  -webkit-appearance: none;\n";
            $cssString .= "  margin: 0;\n";
            $cssString .= "}\n";

        } elseif ($action === 'show') {
            // Firefox-এর জন্য স্পিনার দেখানো (ডিফল্ট মানে ফিরিয়ে আনা)
            $cssString .= "/* Force show spinner for Firefox */\n";
            $cssString .= "{$selector} {\n";
            $cssString .= "  -moz-appearance: number-input; /* Firefox-এর ডিফল্ট */\n";
            $cssString .= "}\n\n";

            // WebKit-এর জন্য স্পিনার দেখানো (ডিফল্ট মানে ফিরিয়ে আনা)
            $cssString .= "/* Force show spinner for WebKit browsers */\n";
            $cssString .= "{$selector}::-webkit-outer-spin-button,\n";
            $cssString .= "{$selector}::-webkit-inner-spin-button {\n";
            $cssString .= "  -webkit-appearance: auto; /* WebKit-এর ডিফল্ট */\n";
            $cssString .= "  margin: revert; /* `revert` মানটি ব্রাউজারের ডিফল্টে ফিরিয়ে নিয়ে যায় */\n";
            $cssString .= "}\n";
        }
        
        return ['layer' => 'utilities', 'style' => $cssString];
    }

    private function handleJoin(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'horizontal';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        $css = <<<CSS
        /* --- Base Join Component --- */
        {$selector} {
            display: inline-flex;
            border-radius: var(--rounded-btn, 0.5rem); /* Default daisyUI border radius */
        }
        {$selector} > .join-item, {$selector} > .btn, {$selector} > .input, {$selector} > .select {
            border-radius: 0; /* Reset child radius */
        }

        /* --- Horizontal Join (Default) --- */
        {$selector}:not(.join-vertical) > *:not(:first-child) {
            margin-left: -1px;
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        {$selector}:not(.join-vertical) > *:not(:last-child) {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }

        /* --- Vertical Join --- */
        .join-vertical {
            flex-direction: column;
        }
        .join-vertical > *:not(:first-child) {
            margin-top: -1px;
            border-top-left-radius: 0;
            border-top-right-radius: 0;
        }
        .join-vertical > *:not(:last-child) {
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
        }
        
        /* First and Last item radius */
        {$selector} > *:first-child:not(:last-child) { /* Only if not single item */
             /* Radius is handled by individual direction rules */
        }
        {$selector} > *:last-child:not(:first-child) {
             /* Radius is handled by individual direction rules */
        }
        CSS;

        if ($modifier === 'vertical') {
             $css = str_replace(':not(.join-vertical)', '.join-vertical', $css);
        }
        
        return ['layer' => 'components', 'style' => $css];
    }

    private function handleCollapse(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? null;
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        // --- Base .collapse Styles ---
        if ($modifier === null) {
            $css = <<<CSS
            {$selector} {
                position: relative; /* z-index এর জন্য আবশ্যক */
                display: grid;
                border-radius: var(--rounded-box, 1rem);
                grid-template-rows: max-content 0fr;
                grid-template-columns: minmax(0, 1fr);
                transition: grid-template-rows 0.2s ease-out;
                background-color: hsl(var(--card));
                color: hsl(var(--card-foreground));
                width: 100%;
                overflow-x: hidden;
            }

            /* --- Z-Index Fix --- */
            {$selector} > input[type="radio"], {$selector} > input[type="checkbox"] {
                appearance: none; opacity: 0;
                position: absolute;
                top: 0; left: 0;
                width: 100%;
                height: 3.75rem; /* শুধুমাত্র title এর উচ্চতা পর্যন্ত */
                cursor: pointer;
                z-index: 10; /* title এর উপরে থাকবে */
            }
            {$selector} .collapse-title, details{$selector} > summary {
                display: flex; align-items: center; cursor: pointer;
                padding: 1rem 1.5rem; min-height: 3.75rem;
                grid-area: 1 / 1;
                position: relative; /* z-index এর জন্য */
                z-index: 1; /* input এর নিচে থাকবে */
            }

            {$selector} .collapse-content {
                grid-area: 2 / 1; overflow: hidden; min-height: 0;
                padding: 0 1rem;
                transition: padding 0.2s ease-out;
            }

            /* --- Open State Logic --- */
            {$selector}.collapse-open,
            {$selector}:has(> input:checked),
            details{$selector}[open] {
                grid-template-rows: max-content 1fr;
            }
            {$selector}.collapse-open .collapse-content,
            {$selector}:has(> input:checked) .collapse-content,
            details{$selector}[open] .collapse-content {
                padding-bottom: 1rem;
                padding-top: 1rem;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- Modifier Styles (arrow, plus) ---
        if ($modifier === 'arrow' || $modifier === 'plus') {
            // আইকনের কালার থিম থেকে নেওয়া হচ্ছে
            $iconColor = rawurlencode($this->resolveThemeValue(['theme' => 'colors.foreground'], '#000'));
            $activeIconColor = rawurlencode($this->resolveThemeValue(['theme' => 'colors.primary'], '#0c63e4'));

            $iconSvg = ($modifier === 'arrow') 
                ? "url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='{$iconColor}'%3e%3cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e\")"
                : "url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='{$iconColor}'%3e%3cpath d='M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z'/%3e%3c/svg%3e\")";
            
            $activeIconSvg = ($modifier === 'arrow') ? str_replace($iconColor, $activeIconColor, $iconSvg) : $iconSvg;
            $rotation = ($modifier === 'arrow') ? '-180deg' : '45deg';
            
            $modifierCss = <<<CSS
            {$selector} .collapse-title {
                padding-right: 2.5rem;
            }
            {$selector} .collapse-title::after {
                content: ""; display: block; width: 1.25rem; height: 1.25rem;
                margin-left: auto; background-image: {$iconSvg};
                background-size: contain; transition: transform 0.2s ease-out;
            }
            {$selector}.collapse-open .collapse-title,
            {$selector}:has(> input:checked) .collapse-title,
            details[open]{$selector} > summary {
                background-color: hsl(var(--muted));
                color: hsl(var(--primary));
            }
            {$selector}.collapse-open .collapse-title::after,
            {$selector}:has(> input:checked) .collapse-title::after,
            details[open]{$selector} > summary::after {
                transform: rotate({$rotation});
                background-image: {$activeIconSvg};
            }
            CSS;
            return ['layer' => 'components', 'style' => $modifierCss];
        }
        return null;
    }

    private function handleBreadcrumbs(string $baseClassPart, array $matches): ?array {
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        $css = <<<CSS
        /* --- Breadcrumbs Component (daisyUI Logic) --- */
        {$selector} {
            max-width: 100%;
            overflow-x: auto; /* Handles long lists */
        }

        {$selector} > ul {
            display: flex;
            align-items: center;
            list-style-type: none;
            margin: 0;
            padding: 0;
            white-space: nowrap;
        }

        /* Add divider before each list item (except the first one) */
        {$selector} > ul > li:not(:first-child)::before {
            content: ">";
            display: inline-block;
            margin: 0 0.5rem; /* mx-2 */
            opacity: 0.6;
        }

        /* Styling for links and spans inside list items */
        {$selector} > ul > li > a,
        {$selector} > ul > li > span {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem; /* gap-2 */
        }
        {$selector} > ul > li > a {
            cursor: pointer;
        }
        {$selector} > ul > li > a:hover {
            text-decoration: underline;
        }
        CSS;

        return ['layer' => 'components', 'style' => $css];
    }

    private function handleDock(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base'; // xs, sm, label, active, or 'base'
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        $styles = [];

        // --- Modifier & Part Handling ---
        switch ($modifier) {
            case 'base':
                return [
                    'layer' => 'components',
                    'style' => <<<CSS
                    .dock {
                        position: fixed;
                        bottom: 1rem;
                        left: 50%;
                        transform: translateX(-50%);
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        gap: 0.5rem;
                        padding: 0.5rem;
                        background-color: hsl(var(--b1, var(--card)));
                        color: hsl(var(--bc, var(--card-foreground)));
                        border-radius: 1.8rem; /* rounded-dock */
                        z-index: 50;
                        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.06); /* shadow-md */
                    }
                    .dock > button, .dock > a {
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        gap: 0.25rem; /* gap-1 */
                        padding: 0.5rem; /* p-2 */
                        border-radius: 1rem; /* rounded-box */
                        color: hsl(var(--bc, var(--foreground)));
                        transition: all 0.2s ease-out;
                    }
                    .dock > button:hover, .dock > a:hover {
                        background-color: hsl(var(--b3, var(--muted)));
                    }
                    CSS
                ];

            // --- Sizing ---
            case 'xs': return ['style' => ['padding' => '0.25rem', 'border-radius' => '1.2rem', 'gap' => '0.25rem']];
            case 'sm': return ['style' => ['padding' => '0.375rem', 'border-radius' => '1.5rem', 'gap' => '0.375rem']];
            case 'md': return []; // Default, no extra style needed
            case 'lg': return ['style' => ['padding' => '0.75rem', 'border-radius' => '2rem', 'gap' => '0.75rem']];
            case 'xl': return ['style' => ['padding' => '1rem', 'border-radius' => '2.5rem', 'gap' => '1rem']];

            // --- Parts ---
            case 'label':
                return ['style' => ['font-size' => '0.75rem', 'margin-top' => '0.125rem']];

            // --- States ---
            case 'active':
                // This will be applied to the button/a tag
                return ['style' => [
                    'background-color' => 'hsl(var(--p, var(--primary)))',
                    'color' => 'hsl(var(--pc, var(--primary-foreground)))',
                ]];
        }

        return null;
    }

    private function handleMenu(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        // --- Base .menu and Parts Styles ---
        if ($modifier === 'base') {
            $css = <<<CSS
            /* --- Base Menu Component (Final Corrected Version) --- */
            {$selector} {
                display: flex; flex-direction: column;
                padding: 0.5rem; gap: 0.25rem;
                font-size: 0.875rem;
                color: hsl(var(--bc, var(--foreground)));
            }
            
            /* --- All List Items --- */
            {$selector} li {
                display: flex; flex-direction: column;
                align-items: stretch;
            }

            /* --- All Clickable Items --- */
            {$selector} li > *:not(ul):not(details):not(div):not(h2):not(.menu-title),
            {$selector} li > details > summary {
                display: flex; align-items: center; gap: 0.75rem;
                padding: 0.75rem 1rem; border-radius: var(--rounded-btn, 0.5rem);
                cursor: pointer; user-select: none; text-align: left;
                outline: none; transition: background-color 0.2s, color 0.2s;
            }

            /* --- Hover & Focus States for Direct Children --- */
            {$selector} > li > *:not(h2):not(.menu-title):not(.disabled):hover,
            {$selector} > li > details > summary:not(.disabled):hover {
                background-color: hsla(var(--bc, var(--foreground)), 0.1);
            }
            
            /* --- Hover & Focus for Submenu Items --- */
            {$selector} li > ul > li > *:not(h2):not(.menu-title):not(.disabled):hover,
            {$selector} li > ul > li > details > summary:not(.disabled):hover {
                background-color: hsla(var(--bc, var(--foreground)), 0.1);
            }

            /* --- Hover & Focus States (Targeting specific items) --- */
            {$selector} li > a:hover,
            {$selector} li > button:hover,
            {$selector} li > details > summary:hover {
                background-color: hsl(var(--b2, var(--muted)));
            }
            
            /* --- Active Item Style --- */
            {$selector} li > .menu-active {
                background-color: hsl(var(--p, var(--primary)));
                color: hsl(var(--pc, var(--primary-foreground)));
            }

            /* --- Submenu Indentation (The Key Fix) --- */
            /* 1st Level Submenu */
            {$selector} > li ul {
                padding-left: 1rem;
            }
            {$selector} > li > ul {
                padding-left: 1rem;
            }
            /* 2nd Level (and deeper) Submenu */
            {$selector} ul ul {
                padding-left: 1rem;
            }
            {$selector} ul > ul {
                padding-left: 1rem;
            }
            /* 3rd Level (and deeper) Submenu */
            {$selector} ul ul ul {
                padding-left: 1rem;
            }
            {$selector} ul > ul > ul {
                padding-left: 1rem;
            }
            /* 4th Level (and deeper) Submenu */
            {$selector} ul ul ul ul {
                padding-left: 1rem;
            }
            {$selector} ul > ul > ul > ul {
                padding-left: 1rem;
            }
            /* 5th Level (and deeper) Submenu */
            {$selector} ul ul ul ul ul {
                padding-left: 1rem;
            }
            {$selector} ul > ul > ul > ul > ul {
                padding-left: 1rem;
            }
            /* 6th Level (and deeper) Submenu */
            {$selector} ul ul ul ul ul ul {
                padding-left: 1rem;
            }
            {$selector} ul > ul > ul > ul > ul > ul {
                padding-left: 1rem;
            }
            /* 7th Level (and deeper) Submenu */
            {$selector} ul ul ul ul ul ul ul {
                padding-left: 1rem;
            }
            {$selector} ul > ul > ul > ul > ul > ul > ul {
                padding-left: 1rem;
            }
            /* 8th Level (and deeper) Submenu */
            {$selector} ul ul ul ul ul ul ul ul {
                padding-left: 1rem;
            }
            {$selector} ul > ul > ul > ul > ul > ul > ul > ul {
                padding-left: 1rem;
            }
            /* 9th Level (and deeper) Submenu */
            {$selector} ul ul ul ul ul ul ul ul ul {
                padding-left: 1rem;
            }
            {$selector} ul > ul > ul > ul > ul > ul > ul > ul > ul {
                padding-left: 1rem;
            }
            /* 10th Level (and deeper) Submenu */
            {$selector} ul ul ul ul ul ul ul ul ul ul {
                padding-left: 1rem;
            }
            {$selector} ul > ul > ul > ul > ul > ul > ul > ul > ul > ul {
                padding-left: 1rem;
            }
            
            /* --- Details/Summary Icon --- */
            {$selector} li > details > summary { position: relative; }
            {$selector} li > details > summary::after {
                content: ""; display: block; position: absolute; right: 0.75rem;
                width: 0.5rem; height: 0.5rem; border-bottom: 2px solid currentColor;
                border-right: 2px solid currentColor; transform: rotate(45deg);
                transition: transform 0.2s ease-out;
            }
            {$selector} li > details[open] > summary::after {
                transform: rotate(225deg);
            }

            /* --- Horizontal Menu (Code remains the same) --- */
            {$selector} .menu-horizontal { flex-direction: row; }
            {$selector} .menu-horizontal li { display: flex; }
            {$selector} .menu-horizontal li > details { position: relative; }
            {$selector} .menu-horizontal li > details > ul {
                position: absolute;
                top: 100%;
                left: 0;
                background-color: hsl(var(--b1, var(--card)));
                padding: 0.5rem;
                border-radius: var(--rounded-box, 1rem);
                box-shadow: var(--tw-shadow, 0 1px 3px 0 rgba(0,0,0,0.1));
                min-width: 12rem; /* w-48 */
                z-index: 10;
            }
            /* Hide submenu in horizontal menu */
            {$selector} .menu-horizontal li > details:not([open]) > ul {
                display: none;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }
        
        // --- Modifier & Part Styles ---
        $styles = [];
        switch ($modifier) {
            // ... (Sizing & Direction code remains the same)
            case 'xs': return ['style' => ['font-size' => '0.75rem', 'padding' => '0.25rem']];
            case 'sm': return ['style' => ['font-size' => '0.875rem', 'padding' => '0.375rem']];
            case 'lg': return ['style' => ['font-size' => '1.125rem', 'padding' => '0.75rem']];
            case 'xl': return ['style' => ['font-size' => '1.25rem', 'padding' => '1rem']];
            case 'horizontal': return ['style' => ['flex-direction' => 'row']];
            case 'vertical': return ['style' => ['flex-direction' => 'column']];
                
            case 'title':
                return ['style' => [ 'padding' => '0.5rem 1rem', 'font-size' => '0.75rem', 'font-weight' => '700', 'opacity' => '0.4', 'cursor' => 'auto', 'color' => 'hsl(var(--bc, var(--foreground)))' ]];
            case 'disabled':
                return ['style' => ['cursor' => 'not-allowed', 'opacity' => '0.4', 'background-color' => 'transparent !important', 'color' => 'hsl(var(--b2, var(--muted)))' ]];
            case 'active':
                return ['style' => [ 'background-color' => 'hsl(var(--p, var(--primary)))', 'color' => 'hsl(var(--pc, var(--primary-foreground)))' ]];
            case 'focus':
                return ['style' => [ 'background-color' => 'hsl(var(--b3, var(--muted)))' ]];
        }

        return !empty($styles) ? ['layer' => 'components', 'style' => $styles] : null;
    }

    private function handleTabs(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? null;
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        // --- 1. Container Styles (tabs, tabs-boxed, tabs-bordered) ---
        if ($baseClassPart === 'tabs') {
            return ['layer' => 'components', 'style' => "{$selector} { display: flex; flex-wrap: wrap; align-items: flex-end; }"];
        }
        if ($baseClassPart === 'tabs-boxed') {
            return ['layer' => 'components', 'style' => "{$selector} { background-color: hsl(var(--b2, var(--muted))); padding: 0.25rem; border-radius: var(--rounded-box, 1rem); }"];
        }
        if ($baseClassPart === 'tabs-bordered') {
            return ['layer' => 'components', 'style' => "{$selector} { border-bottom: 1px solid hsl(var(--b3, var(--border))); }"];
        }

        // --- 2. Base Tab Styles (.tab) ---
        if ($baseClassPart === 'tab') {
            $css = <<<CSS
            {$selector} {
                position: relative; display: inline-flex; cursor: pointer; align-items: center; justify-content: center;
                text-align: center; user-select: none; appearance: none;
                height: 2rem; font-size: 0.875rem; line-height: 1.25rem; padding: 0 1rem;
                color: hsl(var(--bc, var(--foreground)) / 0.5);
                --tab-color: hsl(var(--bc, var(--foreground)));
            }
            {$selector}:hover { color: hsl(var(--bc, var(--foreground)) / 0.8); }
            /* Bordered Style Base */
            .tabs-bordered {$selector} { border-bottom-color: transparent; border-bottom-width: 2px; margin-bottom: -1px; }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- 3. Active State (.tab-active) ---
        if ($baseClassPart === 'tab-active') {
            $css = <<<CSS
            /* Default Active */
            {$selector} {
                color: var(--tab-color);
                font-weight: 600;
            }
            /* Boxed Active */
            .tabs-boxed {$selector} {
                background-color: var(--tab-color);
                color: hsl(var(--b1, var(--background)));
                border-radius: var(--rounded-btn, 0.5rem);
            }
            /* Boxed Active Custom Color text fix */
            .tabs-boxed .tab-primary, .tabs-boxed .tab-secondary, .tabs-boxed .tab-accent, 
            .tabs-boxed .tab-info, .tabs-boxed .tab-success, .tabs-boxed .tab-warning, .tabs-boxed .tab-error {
                color: hsl(var(--pc, var(--primary-foreground)));
            }
            /* Bordered Active */
            .tabs-bordered {$selector} {
                border-bottom-color: var(--tab-color);
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        if (in_array($modifier, ['xs', 'sm', 'md', 'lg'])) {
            $styles = match($modifier) {
                'xs' => ['height' => '1.5rem', 'font-size' => '0.75rem', 'padding' => '0 0.5rem'],
                'sm' => ['height' => '1.75rem', 'font-size' => '0.875rem', 'padding' => '0 0.75rem'],
                'md' => ['height' => '2rem', 'font-size' => '0.875rem', 'padding' => '0 1rem'],
                'lg' => ['height' => '3rem', 'font-size' => '1.125rem', 'padding' => '0 1.25rem'],
            };
            return ['layer' => 'components', 'style' => $styles];
        }

        if ($modifier) {
            $colorMap = [
                'primary' => 'p', 'secondary' => 's', 'accent' => 'a',
                'info' => 'in', 'success' => 'su', 'warning' => 'wa', 'error' => 'er', 'danger' => 'er'
            ];
            
            if (isset($colorMap[$modifier])) {
                $c = $colorMap[$modifier];
                return ['layer' => 'components', 'style' => [ '--tab-color' => "hsl(var(--{$c}))" ]];
            }

            $colorValue = $this->parseColorValue($modifier);
            if ($colorValue) {
                return ['layer' => 'components', 'style' => [ '--tab-color' => $colorValue ]];
            }
        }

        return null;
    }

    private function handleNavbar(string $baseClassPart, array $matches): ?array {
        $part = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        $css = '';
        switch ($part) {
            case 'base':
                $css = <<<CSS
                /* --- Base Navbar --- */
                {$selector} {
                    display: flex; /* Changed to flex for simplicity and broader support */
                    align-items: center;
                    gap: 0.5rem;
                    height: 4rem;
                    padding: 0 1rem;
                    width: 100%;
                }
                CSS;
                break;

            case 'start':
                // DaisyUI তে .navbar-start width: 50% নেয় এবং justify-start করে
                $css = <<<CSS
                {$selector} {
                    flex-grow: 1;
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    justify-content: flex-start;
                }
                CSS;
                break;

            case 'center':
                $css = <<<CSS
                {$selector} {
                    flex-shrink: 0; /* Don't shrink */
                }
                /* Responsive: Hide on smaller screens if start/end exists */
                @media (max-width: 1023px) {
                    .navbar-start:not(:empty) ~ {$selector},
                    .navbar-end:not(:empty) ~ {$selector} {
                        display: none;
                    }
                }
                CSS;
                break;

            case 'end':
                $css = <<<CSS
                {$selector} {
                    flex-grow: 1;
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    justify-content: flex-end;
                }
                CSS;
                break;
        }

        return !empty($css) ? ['layer' => 'components', 'style' => $css] : null;
    }

    private function handleTooltip(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        if ($modifier === 'base') {
            $css = <<<CSS
            {$selector} {
                position: relative; display: inline-block;
                --tooltip-color: hsl(var(--n, var(--neutral)));
                --tooltip-text-color: hsl(var(--nc, var(--neutral-foreground)));
            }
            {$selector}::before, {$selector}::after {
                position: absolute; opacity: 0; transition: all 0.2s ease-in-out;
                pointer-events: none; z-index: 50;
            }
            {$selector}::after {
                content: attr(data-tip); background-color: var(--tooltip-color);
                color: var(--tooltip-text-color); border-radius: 0.25rem;
                padding: 0.25rem 0.5rem; font-size: 0.875rem; white-space: nowrap;
            }
            {$selector}::before {
                content: ""; border-style: solid; border-width: 5px; border-color: transparent;
            }
            /* Default position: Top */
            {$selector}:not(.tooltip-bottom):not(.tooltip-left):not(.tooltip-right)::after {
                bottom: 100%; left: 50%; transform: translateX(-50%) translateY(-0.25rem);
            }
            {$selector}:not(.tooltip-bottom):not(.tooltip-left):not(.tooltip-right)::before {
                bottom: 100%; left: 50%; transform: translateX(-50%); border-top-color: var(--tooltip-color);
            }
            {$selector}:hover::before, {$selector}:hover::after, {$selector}.tooltip-open::before, {$selector}.tooltip-open::after {
                opacity: 1;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // Positions
        if ($modifier === 'bottom') return ['layer' => 'components', 'style' => "{$selector}::after { top: 100%; left: 50%; transform: translateX(-50%) translateY(0.25rem); } {$selector}::before { top: 100%; left: 50%; transform: translateX(-50%); border-bottom-color: var(--tooltip-color); }"];
        if ($modifier === 'left') return ['layer' => 'components', 'style' => "{$selector}::after { top: 50%; right: 100%; transform: translateY(-50%) translateX(-0.25rem); } {$selector}::before { top: 50%; right: 100%; transform: translateY(-50%); border-left-color: var(--tooltip-color); }"];
        if ($modifier === 'right') return ['layer' => 'components', 'style' => "{$selector}::after { top: 50%; left: 100%; transform: translateY(-50%) translateX(0.25rem); } {$selector}::before { top: 50%; left: 100%; transform: translateY(-50%); border-right-color: var(--tooltip-color); }"];
        if ($modifier === 'open') return null; // Handled in base

        // Colors
        $colorValue = $this->parseColorValue($modifier);
        if (!$colorValue) {
            $cMap = ['primary'=>'p', 'secondary'=>'s', 'accent'=>'a', 'info'=>'in', 'success'=>'su', 'warning'=>'wa', 'error'=>'er'];
            if (isset($cMap[$modifier])) {
                $colorValue = "hsl(var(--{$cMap[$modifier]}))";
                $textColor = "hsl(var(--{$cMap[$modifier]}c))";
                return ['layer' => 'components', 'style' => ["--tooltip-color" => $colorValue, "--tooltip-text-color" => $textColor]];
            }
        }
        if ($colorValue) return ['layer' => 'components', 'style' => ["--tooltip-color" => $colorValue, "--tooltip-text-color" => "white"]];

        return null;
    }

    private function handleDrawer(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        if ($modifier === 'base') {
            $css = <<<CSS
            {$selector} { position: relative; display: grid; grid-auto-columns: max-content auto; width: 100%; }
            {$selector}-toggle { position: absolute; height: 0; width: 0; appearance: none; opacity: 0; }
            {$selector}-content { grid-column-start: 2; grid-row-start: 1; min-width: 0; }
            {$selector}-side { pointer-events: none; position: fixed; inset: 0; overflow: hidden; z-index: 99; }
            {$selector}-overlay { display: block; position: absolute; inset: 0; background-color: transparent; transition: background-color 0.3s; cursor: pointer; }
            {$selector}-toggle:checked ~ {$selector}-side { pointer-events: auto; visibility: visible; }
            {$selector}-toggle:checked ~ {$selector}-side > {$selector}-overlay { background-color: rgba(0,0,0,0.4); }
            {$selector}-side > *:not({$selector}-overlay) { transform: translateX(-100%); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); position: absolute; height: 100vh; }
            {$selector}-toggle:checked ~ {$selector}-side > *:not({$selector}-overlay) { transform: translateX(0%); }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        if ($modifier === 'end') {
            $css = <<<CSS
            {$selector} {$selector}-toggle ~ {$selector}-side > *:not({$selector}-overlay) { transform: translateX(100%); right: 0; left: auto; }
            {$selector} {$selector}-toggle:checked ~ {$selector}-side > *:not({$selector}-overlay) { transform: translateX(0%); }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }
        return null;
    }

    private function handleHero(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        if ($modifier === 'base') {
            $css = <<<CSS
            {$selector} { display: grid; place-items: center; width: 100%; background-position: center; background-size: cover; }
            {$selector} > * { grid-column-start: 1; grid-row-start: 1; }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }
        if ($modifier === 'content') return ['layer' => 'components', 'style' => "{$selector} { z-index: 0; display: flex; align-items: center; justify-content: center; max-width: 80rem; padding: 1rem; flex-direction: column; gap: 1rem; text-align: center; }"];
        if ($modifier === 'overlay') return ['layer' => 'components', 'style' => "{$selector} { background-color: hsl(var(--n, var(--neutral)) / 0.5); width: 100%; height: 100%; }"];
        return null;
    }

    private function handleBtmNav(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        if ($modifier === 'base') {
            $css = <<<CSS
            {$selector} { position: fixed; bottom: 0; left: 0; right: 0; display: flex; width: 100%; background-color: hsl(var(--b1, var(--background))); color: hsl(var(--bc, var(--foreground))); z-index: 9; box-shadow: 0 -1px 3px rgba(0,0,0,0.1); height: 4rem; }
            {$selector} > * { display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; padding: 0.5rem; cursor: pointer; border-color: currentColor; background: transparent; }
            {$selector} > *:hover, {$selector} > .active { background-color: hsl(var(--b2, var(--muted))); border-top-width: 2px; }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }
        return null;
    }

    private function handleFileInput(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        if ($modifier === 'base') {
            $css = <<<CSS
            {$selector} {
                height: 3rem; flex-shrink: 1; padding-right: 1rem;
                font-size: 0.875rem; line-height: 2;
                border: 1px solid hsl(var(--b3, var(--border)));
                border-radius: var(--rounded-btn, 0.5rem);
                background-color: hsl(var(--b1, var(--background)));
                color: hsl(var(--bc, var(--foreground)));
                overflow: hidden; cursor: pointer;
            }
            {$selector}::file-selector-button {
                margin-right: 1rem; display: inline-flex; height: 100%; align-items: center;
                border-style: solid; border-width: 0; cursor: pointer;
                background-color: hsl(var(--n, var(--neutral)));
                color: hsl(var(--nc, var(--neutral-foreground)));
                padding-left: 1rem; padding-right: 1rem; text-transform: uppercase; font-weight: 600;
                transition: background-color 0.2s;
            }
            {$selector}:focus { outline: 2px solid hsl(var(--bc)); outline-offset: 0px; }
            {$selector}::file-selector-button:hover { background-color: hsl(var(--n, var(--neutral)) / 0.8); }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // Sizes
        if (in_array($modifier, ['xs', 'sm', 'md', 'lg'])) {
            $h = match($modifier) { 'xs'=>'1.5rem', 'sm'=>'2rem', 'md'=>'3rem', 'lg'=>'4rem' };
            $fs = match($modifier) { 'xs'=>'0.75rem', 'sm'=>'0.875rem', 'md'=>'0.875rem', 'lg'=>'1.125rem' };
            return ['layer' => 'components', 'style' => "{$selector} { height: {$h}; font-size: {$fs}; }"];
        }

        // Colors (primary, secondary, etc.)
        $colorValue = $this->parseColorValue($modifier);
        if (!$colorValue) {
            $cMap = ['primary'=>'p', 'secondary'=>'s', 'accent'=>'a', 'info'=>'in', 'success'=>'su', 'warning'=>'wa', 'error'=>'er'];
            if (isset($cMap[$modifier])) {
                $c = $cMap[$modifier];
                $css = <<<CSS
                {$selector} { border-color: hsl(var(--{$c})); }
                {$selector}:focus { outline-color: hsl(var(--{$c})); }
                {$selector}::file-selector-button { background-color: hsl(var(--{$c})); color: hsl(var(--{$c}c, var(--primary-foreground))); }
                {$selector}::file-selector-button:hover { background-color: hsl(var(--{$c}) / 0.8); }
                CSS;
                return ['layer' => 'components', 'style' => $css];
            }
        }

        return null;
    }

    private function handleTimeline(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        if ($modifier === 'base') {
            $css = <<<CSS
            {$selector} { display: flex; flex-direction: column; width: 100%; }
            {$selector} li { display: grid; grid-template-columns: var(--timeline-col-start, minmax(0, 1fr)) auto var(--timeline-col-end, minmax(0, 1fr)); grid-template-rows: var(--timeline-row-start, minmax(0, 1fr)) auto var(--timeline-row-end, minmax(0, 1fr)); align-items: center; margin: 0; }
            {$selector} li > hr { width: 100%; border-width: 0px; height: 0.25rem; background-color: hsl(var(--b3, var(--border))); margin: 0; }
            {$selector} hr:first-child { grid-column-start: 1; grid-row-start: 2; }
            {$selector} hr:last-child { grid-column-start: 3; grid-row-start: 2; }
            {$selector}-start { grid-column-start: 1; grid-column-end: 2; grid-row-start: 1; grid-row-end: 4; justify-self: end; margin: 0.25rem 1rem; }
            {$selector}-middle { grid-column-start: 2; grid-row-start: 2; }
            {$selector}-end { grid-column-start: 3; grid-column-end: 4; grid-row-start: 1; grid-row-end: 4; justify-self: start; margin: 0.25rem 1rem; }
            {$selector} .timeline-middle svg { width: 1.25rem; height: 1.25rem; color: hsl(var(--bc, var(--foreground))); }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }
        
        if ($modifier === 'vertical') {
            $css = <<<CSS
            {$selector} { flex-direction: column; }
            {$selector} li { grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); grid-template-rows: minmax(0, 1fr) auto minmax(0, 1fr); }
            {$selector} li > hr { height: 100%; width: 0.25rem; justify-self: center; }
            {$selector} hr:first-child { grid-column-start: 2; grid-row-start: 1; }
            {$selector} hr:last-child { grid-column-start: 2; grid-row-start: 3; }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }
        return null;
    }

    private function handleMockup(string $baseClassPart, array $matches): ?array {
        $type = $matches[1];
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = "";

        switch ($type) {
            // --- 1. Window Mockup (Basic OS Window) ---
            case 'window':
                $css = <<<CSS
                {$selector} {
                    position: relative; overflow: hidden; display: flex; flex-direction: column;
                    border-radius: var(--rounded-box, 1rem);
                    border: 1px solid hsl(var(--b3, var(--border)));
                    background-color: hsl(var(--b1, var(--background)));
                }
                {$selector}::before {
                    content: ""; display: block; margin-bottom: 1rem; padding-top: 1rem;
                    height: 0.75rem; width: 0.75rem; border-radius: 9999px;
                    background-color: #ff5f56; box-shadow: 1.25rem 0 0 #ffbd2e, 2.5rem 0 0 #27c93f;
                    margin-left: 1.25rem; opacity: 0.8;
                }
                CSS;
                break;

            // --- 2. Browser Mockup (With Toolbar & Address Bar) ---
            case 'browser':
                $css = <<<CSS
                {$selector} {
                    position: relative; overflow: hidden; display: flex; flex-direction: column;
                    border-radius: var(--rounded-box, 1rem);
                    border: 1px solid hsl(var(--b3, var(--border)));
                    background-color: hsl(var(--b1, var(--background)));
                }
                {$selector} .mockup-browser-toolbar {
                    display: inline-flex; align-items: center; gap: 1rem; padding: 0.75rem 1.25rem;
                    background-color: hsl(var(--b2, var(--muted))); border-bottom: 1px solid hsl(var(--b3, var(--border)));
                }
                {$selector} .mockup-browser-toolbar::before {
                    content: ""; display: inline-block; height: 0.75rem; width: 0.75rem; border-radius: 9999px;
                    background-color: #ff5f56; box-shadow: 1.25rem 0 0 #ffbd2e, 2.5rem 0 0 #27c93f; opacity: 0.8;
                }
                {$selector} .mockup-browser-toolbar .input {
                    display: block; height: 2rem; flex-grow: 1; padding-left: 1.5rem;
                    background-color: hsl(var(--b1, var(--background))); border-radius: 1.9rem;
                    color: hsl(var(--bc, var(--foreground))); border: none; font-size: 0.875rem;
                }
                CSS;
                break;

            // --- 3. Phone Mockup (With Notch) ---
            case 'phone':
                $css = <<<CSS
                {$selector} {
                    display: inline-block; position: relative; overflow: hidden;
                    border-radius: 2.5rem; border: 0.5rem solid hsl(var(--n, #000));
                    background-color: hsl(var(--b1, var(--background)));
                    width: 320px; height: 600px; margin: 0 auto; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
                }
                /* The Notch */
                {$selector}::before {
                    content: ""; position: absolute; top: 0; left: 50%; transform: translateX(-50%);
                    width: 40%; height: 1.5rem; background-color: hsl(var(--n, #000));
                    border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem; z-index: 10;
                }
                /* Camera Dot */
                {$selector}::after {
                    content: ""; position: absolute; top: 0.3rem; left: 50%; transform: translateX(-50%);
                    width: 0.5rem; height: 0.5rem; border-radius: 50%; background-color: #1a1a1a; z-index: 11;
                    box-shadow: inset 0 0 2px rgba(255,255,255,0.2);
                }
                CSS;
                break;

            // --- 4. Tablet Mockup (iPad Style) ---
            case 'tablet':
                $css = <<<CSS
                {$selector} {
                    display: inline-block; position: relative; overflow: hidden;
                    border-radius: 2rem; border: 0.75rem solid hsl(var(--n, #000));
                    background-color: hsl(var(--b1, var(--background)));
                    width: 768px; height: 1024px; max-width: 100%; margin: 0 auto;
                    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
                }
                /* Front Camera */
                {$selector}::before {
                    content: ""; position: absolute; top: 0.25rem; left: 50%; transform: translateX(-50%);
                    width: 0.4rem; height: 0.4rem; border-radius: 50%; background-color: #2a2a2a; z-index: 10;
                }
                CSS;
                break;

            // --- 5. Monitor/iMac Mockup (With Bottom Stand) ---
            case 'monitor':
                $css = <<<CSS
                {$selector} {
                    display: flex; flex-direction: column; position: relative; margin: 0 auto;
                    width: 100%; max-width: 960px;
                }
                {$selector} .display {
                    position: relative; overflow: hidden; border-radius: 1rem;
                    border: 0.5rem solid hsl(var(--n, #000)); border-bottom-width: 3rem;
                    background-color: hsl(var(--b1, var(--background))); aspect-ratio: 16/9;
                    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); z-index: 2;
                }
                /* iMac Apple Logo Fake Dot */
                {$selector} .display::after {
                    content: ""; position: absolute; bottom: -1.75rem; left: 50%; transform: translateX(-50%);
                    width: 0.5rem; height: 0.5rem; border-radius: 50%; background-color: rgba(255,255,255,0.1);
                }
                /* The Stand */
                {$selector}::after {
                    content: ""; display: block; width: 20%; height: 4rem; margin: 0 auto;
                    background: linear-gradient(to bottom, hsl(var(--b3, #ccc)), hsl(var(--b2, #aaa)));
                    border-bottom-left-radius: 0.5rem; border-bottom-right-radius: 0.5rem;
                    clip-path: polygon(15% 0%, 85% 0%, 100% 100%, 0% 100%); z-index: 1; margin-top: -0.5rem;
                }
                CSS;
                break;

            // --- 6. Code Mockup (Terminal/Code Editor) ---
            case 'code':
                $css = <<<CSS
                {$selector} {
                    position: relative; overflow: hidden; overflow-x: auto;
                    border-radius: var(--rounded-box, 1rem);
                    background-color: #2a323c; color: #a6adbb; direction: ltr;
                    padding: 1.25rem 0; min-width: 18rem; font-family: monospace;
                    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
                }
                {$selector}::before {
                    content: ""; display: block; margin-bottom: 1rem;
                    height: 0.75rem; width: 0.75rem; border-radius: 9999px;
                    background-color: #ff5f56; box-shadow: 1.25rem 0 0 #ffbd2e, 2.5rem 0 0 #27c93f;
                    margin-left: 1.25rem; opacity: 0.8;
                }
                {$selector} pre {
                    padding-right: 1.25rem; margin: 0; display: block; width: 100%;
                }
                {$selector} pre::before {
                    content: attr(data-prefix); display: inline-block; width: 2rem;
                    text-align: right; opacity: 0.5; margin-right: 1rem;
                }
                {$selector} pre:empty::before { content: ""; }
                /* Success and Error lines */
                {$selector} pre.text-success { color: hsl(var(--su, #36d399)); }
                {$selector} pre.text-warning { color: hsl(var(--wa, #fbbd23)); }
                {$selector} pre.text-error { color: hsl(var(--er, #f87272)); }
                {$selector} pre.bg-warning { color: hsl(var(--wac, #000)); }
                CSS;
                break;
        }

        return $css ? ['layer' => 'components', 'style' => $css] : null;
    }

    private function handleDiff(string $baseClassPart, array $matches): ?array {
        $css = <<<CSS
        .diff {
            position: relative; display: grid; width: 100%; overflow: hidden;
            border-radius: var(--rounded-box, 1rem); place-content: center;
        }
        .diff-item-1, .diff-item-2 {
            grid-column-start: 1; grid-row-start: 1; overflow: hidden;
            width: 100%; height: 100%; object-fit: cover;
        }
        .diff-item-1 {
            z-index: 1; clip-path: polygon(0 0, var(--diff-resizer-w, 50%) 0, var(--diff-resizer-w, 50%) 100%, 0 100%);
        }
        .diff-item-2 {
            z-index: 0;
        }
        .diff-resizer {
            position: relative; z-index: 2; grid-column-start: 1; grid-row-start: 1;
            appearance: none; background: transparent; border: none; outline: none;
            width: 100%; height: 100%; cursor: ew-resize; resize: horizontal;
        }
        .diff-resizer::-webkit-slider-thumb {
            appearance: none; width: 0.25rem; height: 100vh; background: white;
            box-shadow: 0 0 0 1px rgba(0,0,0,0.1);
        }
        .diff-resizer::-moz-range-thumb {
            appearance: none; width: 0.25rem; height: 100vh; background: white; border: none;
            box-shadow: 0 0 0 1px rgba(0,0,0,0.1);
        }
        CSS;
        return ['layer' => 'components', 'style' => $css];
    }

    private function handleThemeController(string $baseClassPart, array $matches): ?array {
        $css = <<<CSS
        .theme-controller {
            opacity: 0; position: absolute; width: 0; height: 0;
            z-index: -1; pointer-events: none;
        }
        /* When used with swap */
        .swap .theme-controller {
            position: absolute; width: 100%; height: 100%;
            opacity: 0; cursor: pointer; pointer-events: auto; z-index: 10;
        }
        CSS;
        return ['layer' => 'components', 'style' => $css];
    }

    private function handleArtboard(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        if ($modifier === 'base') {
            return ['layer' => 'components', 'style' => "{$selector} { width: 100%; background-color: hsl(var(--b1, var(--background))); color: hsl(var(--bc, var(--foreground))); }"];
        }

        if ($modifier === 'demo') {
            return ['layer' => 'components', 'style' => "{$selector} { display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-radius: var(--rounded-box, 1rem); }"];
        }

        // Expanded Screen Sizes
        $sizes = [
            // DaisyUI Default Phones
            'phone-1' => ['width' => '320px', 'height' => '568px'],
            'phone-2' => ['width' => '375px', 'height' => '667px'],
            'phone-3' => ['width' => '414px', 'height' => '736px'],
            'phone-4' => ['width' => '375px', 'height' => '812px'],
            'phone-5' => ['width' => '414px', 'height' => '896px'],
            'phone-6' => ['width' => '390px', 'height' => '844px'],

            // Generic Devices
            'phone'   => ['width' => '390px', 'height' => '844px'],
            'tab'     => ['width' => '768px', 'height' => '1024px'],
            'tablet'  => ['width' => '820px', 'height' => '1180px'],
            'laptop'  => ['width' => '1366px', 'height' => '768px'],
            'desktop' => ['width' => '1920px', 'height' => '1080px'],
            'monitor' => ['width' => '2560px', 'height' => '1440px'],

            'xs'  => ['width' => '320px', 'height' => '568px'],
            'sm'  => ['width' => '640px', 'height' => '960px'],
            'md'  => ['width' => '768px', 'height' => '1024px'],
            'lg'  => ['width' => '1024px', 'height' => '768px'],
            'xl'  => ['width' => '1280px', 'height' => '800px'],
            '2xl' => ['width' => '1536px', 'height' => '864px'],
            '3xl' => ['width' => '1920px', 'height' => '1080px'],
            '4xl' => ['width' => '2560px', 'height' => '1440px'],
            '5xl' => ['width' => '3840px', 'height' => '2160px'],
        ];

        if (isset($sizes[$modifier])) {
            $w = $sizes[$modifier]['width'];
            $h = $sizes[$modifier]['height'];
            
            $css = <<<CSS
            /* Default Vertical/Normal view */
            {$selector} { width: {$w}; height: {$h}; }
            
            /* Add .horizontal class to swap width and height (Landscape mode) */
            {$selector}.horizontal { width: {$h}; height: {$w}; }
            CSS;
            
            return ['layer' => 'components', 'style' => $css];
        }

        return null;
    }

    private function handleLabel(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        if ($modifier === 'base') {
            $css = <<<CSS
            .form-control { display: flex; flex-direction: column; }
            {$selector} {
                display: flex; user-select: none; align-items: center; justify-content: space-between;
                padding: 0.5rem 0.25rem; cursor: pointer;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        if ($modifier === 'text') {
            $css = <<<CSS
            {$selector} { font-size: 0.875rem; line-height: 1.25rem; color: hsl(var(--bc, var(--foreground))); }
            .label-text-alt { font-size: 0.75rem; line-height: 1rem; color: hsl(var(--bc, var(--foreground)) / 0.6); }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        return null;
    }

    private function handleScrollbar(string $baseClassPart, array $matches): ?array {
        $part = $matches[1] ?? 'base';
        $colorValue = $matches[2] ?? null;
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        // .scrollbar (Base defaults)
        if ($part === 'base') {
            $css = <<<CSS
            {$selector}::-webkit-scrollbar { width: 8px; height: 8px; }
            {$selector}::-webkit-scrollbar-track { background: transparent; }
            {$selector}::-webkit-scrollbar-thumb { background: hsl(var(--b3, var(--border))); border-radius: 9999px; }
            {$selector}::-webkit-scrollbar-thumb:hover { background: hsl(var(--bc, var(--muted-foreground))); }
            CSS;
            return ['layer' => 'utilities', 'style' => $css];
        }

        // .scrollbar-thin
        if ($part === 'thin') {
            $css = <<<CSS
            {$selector} { scrollbar-width: thin; }
            {$selector}::-webkit-scrollbar { width: 4px; height: 4px; }
            CSS;
            return ['layer' => 'utilities', 'style' => $css];
        }

        // .scrollbar-none (hide)
        if ($part === 'none') {
            $css = <<<CSS
            {$selector} { scrollbar-width: none; -ms-overflow-style: none; }
            {$selector}::-webkit-scrollbar { display: none; }
            CSS;
            return ['layer' => 'utilities', 'style' => $css];
        }

        // .scrollbar-thumb-color or .scrollbar-track-color
        if ($colorValue) {
            $color = $this->parseColorValue($colorValue);
            
            // Fallback for semantic colors (primary, secondary)
            if (!$color) {
                $cMap = ['primary'=>'p', 'secondary'=>'s', 'accent'=>'a', 'info'=>'in', 'success'=>'su', 'warning'=>'wa', 'error'=>'er'];
                if (isset($cMap[$colorValue])) {
                    $color = "hsl(var(--{$cMap[$colorValue]}))";
                }
            }

            if ($color) {
                if ($part === 'thumb') {
                    return ['layer' => 'utilities', 'style' => "{$selector}::-webkit-scrollbar-thumb { background-color: {$color}; border-radius: 9999px; }"];
                }
                if ($part === 'track') {
                    return ['layer' => 'utilities', 'style' => "{$selector}::-webkit-scrollbar-track { background-color: {$color}; border-radius: 9999px; }"];
                }
            }
        }

        return null;
    }

    private function handleScrollbarHide(string $baseClassPart, array $matches): ?array {
        $css = <<<CSS
        .scrollbar-hide {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }
        .scrollbar-hide::-webkit-scrollbar {
            display: none; /* Chrome, Safari and Opera */
        }
        CSS;
        return ['layer' => 'utilities', 'style' => $css];
    }

    private function handleStepsContainer(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'horizontal'; // vertical or horizontal
        
        $direction = ($modifier === 'vertical') ? 'column' : 'row';
        $styles = [
            'display' => 'inline-flex',
            'flex-direction' => $direction,
            'min-width' => '100%',
        ];

        if ($modifier === 'vertical') {
            $styles['align-items'] = 'flex-start';
            $styles['gap'] = '2rem';
        }

        return ['layer' => 'components', 'style' => $styles];
    }

    private function handleStepItem(string $baseClassPart, array $matches): ?array {
        $color = $matches[1] ?? null;
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        // --- কালার ক্লাস (e.g., .step-primary, .step-blue) ---
        if ($color) {
            // ১. প্রথমে daisyUI/BS এর সেমান্টিক কালার ম্যাপ চেষ্টা করুন
            $colorMap = [
                'primary'=>'p', 'secondary'=>'s', 'accent'=>'a', 'info'=>'in', 
                'success'=>'su', 'warning'=>'wa', 'error'=>'er', 'danger'=>'er', 'neutral'=>'n'
            ];
            $c = $colorMap[$color] ?? null;
            
            if ($c) {
                // যদি সেমান্টিক কালার হয়, তবে ভেরিয়েবল ব্যবহার করুন
                return ['layer' => 'components', 'style' => "{$selector}, {$selector}::before, {$selector}::after { --st: hsl(var(--{$c})); --sc: hsl(var(--{$c}-content, var(--pc))); }"];
            }

            // ২. যদি সেমান্টিক না হয়, তবে সাধারণ কালার হিসেবে পার্স করার চেষ্টা করুন
            $colorValue = $this->parseColorValue($color);
            if ($colorValue) {
                // সরাসরি কালার ভ্যালু ব্যবহার করুন
                // কন্টেন্ট কালারের জন্য একটি ফলব্যাক (সাদা)
                return ['layer' => 'components', 'style' => "{$selector}, {$selector}::before, {$selector}::after { --st: {$colorValue}; --sc: white; }"];
            }
            
            return null; // যদি কোনো ভ্যালিড কালার না হয়
        }

        // --- বেস .step ক্লাস (অপরিবর্তিত) ---
        $css = <<<CSS
        {$selector} {
            position: relative;
            display: flex;
            flex: 1;
            align-items: center;
            gap: 0.5rem;
            --st: hsl(var(--b3, var(--border)));
            --sc: hsl(var(--b3c, var(--foreground)));
        }
        .steps:not(.steps-vertical) {$selector} {
             flex-direction: column;
             text-align: center;
             min-width: 4rem;
        }
        .steps-vertical {$selector} {
            text-align: left;
            gap: 0.75rem;
        }

        /* Step marker */
        {$selector}::before {
            content: attr(data-content, "");
            display: flex; align-items: center; justify-content: center;
            position: relative; /* z-index কাজ করার জন্য */
            z-index: 10; /* লাইন এর উপরে থাকবে */
            width: 2rem; height: 2rem;
            border-radius: 9999px;
            background-color: var(--st);
            color: var(--sc);
            font-weight: 700;
            flex-shrink: 0;
        }
        
        /* Connecting line */
        {$selector}:not(:last-child)::after {
            content: "";
            position: absolute;
            background-color: var(--st);
            z-index: 1; /* মার্কার এর পেছনে থাকবে */
        }

        /* Horizontal line */
        .steps:not(.steps-vertical) {$selector}:not(:last-child)::after {
            height: 2px;
            width: 100%;
            top: 1rem; /* Marker এর সেন্টারে */
            left: 50%;
        }
        
        /* Vertical line */
        .steps-vertical {$selector}:not(:last-child)::after {
            width: 2px;
            height: 100%;
            left: 1rem; /* Marker এর সেন্টারে */
            top: 2rem; /* Marker এর নিচ থেকে শুরু */
        }
        CSS;
        return ['layer' => 'components', 'style' => $css];
    }

    private function handleLoading(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        // --- Base .loading Styles ---
        if ($baseClassPart === 'loading') {
            return ['style' => ['display' => 'inline-block', 'width' => '2rem', 'height' => '2rem']];
        }

        $styles = [];
        switch ($modifier) {
            // --- Sizes ---
            case 'xs': return ['style' => ['width' => '1rem', 'height' => '1rem']];
            case 'sm': return ['style' => ['width' => '1.5rem', 'height' => '1.5rem']];
            case 'md': return ['style' => ['width' => '2rem', 'height' => '2rem']];
            case 'lg': return ['style' => ['width' => '3rem', 'height' => '3rem']];
            case 'xl': return ['style' => ['width' => '4rem', 'height' => '4rem']];

            // --- Animation Types ---
            case 'spinner':
                $this->neededKeyframes['spin'] = $this->config['theme']['keyframes']['spin'];
                return ['style' => ['border' => '2px solid currentColor', 'border-right-color' => 'transparent', 'border-radius' => '9999px', 'animation' => 'spin 1s linear infinite']];
            case 'dots':
                $this->neededKeyframes['loading-dots'] = $this->config['theme']['keyframes']['loading-dots'];
                return ['style' => ['background-image' => 'radial-gradient(circle .3rem,currentColor 98%,#0000),radial-gradient(circle .3rem,currentColor 98%,#0000),radial-gradient(circle .3rem,currentColor 98%,#0000)', 'background-size' => '100% 100%', 'background-repeat' => 'no-repeat', 'animation' => 'loading-dots 1.5s infinite']];
            case 'ring':
                $this->neededKeyframes['loading-ring'] = $this->config['theme']['keyframes']['loading-ring'];
                return ['style' => ['border-radius' => '9999px', 'border' => '.2rem solid transparent', 'border-top-color' => 'currentColor', 'animation' => 'loading-ring 1s linear infinite']];
            case 'ball':
                $this->neededKeyframes['loading-ball'] = $this->config['theme']['keyframes']['loading-ball'];
                return ['style' => ['border-radius' => '9999px', 'background-color' => 'currentColor', 'animation' => 'loading-ball 2s infinite']];
            case 'bars':
                $this->neededKeyframes['loading-bars'] = $this->config['theme']['keyframes']['loading-bars'];
                return ['style' => ['background-image' => 'linear-gradient(currentColor,currentColor),linear-gradient(currentColor,currentColor),linear-gradient(currentColor,currentColor)', 'background-size' => '30% 100%', 'background-repeat' => 'no-repeat', 'animation' => 'loading-bars 1s infinite']];
            case 'infinity':
                $this->neededKeyframes['loading-infinity'] = $this->config['theme']['keyframes']['loading-infinity'];
                return ['style' => ['animation' => 'loading-infinity 3s linear infinite', 'clip-path' => 'polygon(50% -10%,100% 50%,50% 110%,50% 60%,0 50%,50% 40%)', 'background-color' => 'currentColor']];
        }

        return null;
    }

    private function handleRadialProgress(string $baseClassPart, array $matches): ?array {
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        $this->neededProperties['value'] = [
            'syntax' => '"<integer>"',
            'initial-value' => '0',
            'inherits' => 'false',
        ];

        $css = <<<CSS
        /* --- Radial Progress (Bulletproof Custom - Text Fixed) --- */
        {$selector} {
            position: relative;
            display: inline-grid;
            place-content: center;
            border-radius: 9999px;
            vertical-align: middle;

            /* Variables */
            --value: 0;
            --size: 5rem;
            --thickness: calc(var(--size) / 10);
            
            width: var(--size);
            height: var(--size);

            /* Track (background circle) */
            background: hsl(var(--b2, var(--base-200)) / 0.4); /* Light track, daisyUI style */

            /* Progress ring */
            background-image: conic-gradient(
                hsl(var(--p, var(--primary))) calc(var(--value) * 3.6deg), 
                transparent 0deg
            );

            /* Donut hole mask - only on the background (not on text) */
            -webkit-mask-image: radial-gradient(farthest-side, transparent calc(99% - var(--thickness)), black calc(100% - var(--thickness)));
            mask-image: radial-gradient(farthest-side, transparent calc(99% - var(--thickness)), black calc(100% - var(--thickness)));
        }

        /* Inner text - Overlay on top, fully visible, no rotation */
        {$selector} > * {
            grid-area: 1 / 1;
            z-index: 10;
            color: hsl(var(--bc, var(--base-content))); /* Strong text color for visibility on any bg */
            font-weight: 700;
            font-size: 1.25rem;
            text-shadow: 0 1px 2px hsl(var(--b1, var(--base-100)) / 0.5); /* Better readability */
            pointer-events: none; /* Optional: text clickable na hole */
        }

        /* Optional: If you want colored text matching primary (like daisyUI) */
        {$selector}.text-primary-content > * {
            color: hsl(var(--pc, var(--primary-content)));
        }
        CSS;

        return ['layer' => 'components', 'style' => $css];
    }

    private function handleSkeleton(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? null;
        
        if ($modifier === null) {
            $this->neededKeyframes['pulse-alt'] = $this->config['theme']['keyframes']['pulse-alt'];
            return [
                'style' => [
                    'background-color' => 'hsl(var(--b2, var(--muted)))',
                    'animation' => 'pulse-alt 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                ],
                'layer' => 'components'
            ];
        }

        if ($modifier === 'text') {
            $this->neededKeyframes['shimmer'] = $this->config['theme']['keyframes']['shimmer'];
            
            return [
                'style' => [
                    'color' => 'transparent',
                    'background-image' => 'linear-gradient(90deg, transparent, hsl(var(--bc, var(--foreground))) 20%, transparent 40%)',
                    'background-size' => '200% 100%',
                    'background-clip' => 'text',
                    '-webkit-background-clip' => 'text',
                    'animation' => 'shimmer 2s linear infinite', // এখন এটি কাজ করবে
                ],
                'layer' => 'components'
            ];
        }
        
        return null;
    }

    private function handleIndicator(string $baseClassPart, array $matches): ?array {
        $part = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        // --- Base .indicator Styles ---
        if ($part === 'base') {
            return ['style' => ['position' => 'relative', 'display' => 'inline-block']];
        }

        // --- .indicator-item ---
        if ($part === 'item') {
            $css = <<<CSS
            {$selector} {
                position: absolute;
                z-index: 10;
                --indicator-top: 0;
                --indicator-right: 0;
                --indicator-bottom: auto;
                --indicator-left: auto;
                --tw-translate-x: 50%;
                --tw-translate-y: -50%;
                top: var(--indicator-top);
                right: var(--indicator-right);
                bottom: var(--indicator-bottom);
                left: var(--indicator-left);
                transform: translateX(var(--tw-translate-x)) translateY(var(--tw-translate-y));
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }
        
        // --- Placement Modifiers ---
        $styles = [];
        switch ($part) {
            // Horizontal
            case 'start':
                $styles = ['--indicator-right' => 'auto', '--indicator-left' => '0', '--tw-translate-x' => '-50%'];
                break;
            case 'center':
                $styles = ['--indicator-right' => 'auto', '--indicator-left' => '50%', '--tw-translate-x' => '-50%'];
                break;
            case 'end':
                $styles = ['--indicator-right' => '0', '--indicator-left' => 'auto', '--tw-translate-x' => '50%'];
                break;
            
            // Vertical
            case 'top':
                $styles = ['--indicator-top' => '0', '--indicator-bottom' => 'auto', '--tw-translate-y' => '-50%'];
                break;
            case 'middle':
                $styles = ['--indicator-top' => '50%', '--indicator-bottom' => 'auto', '--tw-translate-y' => '-50%'];
                break;
            case 'bottom':
                $styles = ['--indicator-top' => 'auto', '--indicator-bottom' => '0', '--tw-translate-y' => '50%'];
                break;

            default: return null;
        }

        return !empty($styles) ? ['layer' => 'components', 'style' => $styles] : null;
    }

    private function handleMarquee(string $baseClassPart, array $matches): ?array {
        $this->neededKeyframes['marquee'] = '{ 0% { transform: translateX(0%); } 100% { transform: translateX(-100%); } }';
        
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = <<<CSS
        /* Wrapper style */
        {$selector} {
            display: flex;
            overflow: hidden;
            user-select: none;
            gap: 1rem;
        }
        /* Animation applies to the direct children */
        {$selector} > * {
            flex-shrink: 0;
            display: flex;
            justify-content: space-around;
            min-width: 100%;
            gap: 1rem;
            /* Default 20s, user can override with duration-X */
            animation: marquee var(--tw-animation-duration, 20s) linear infinite;
        }
        /* Pause on hover for better UX */
        {$selector}:hover > * {
            animation-play-state: paused;
        }
        CSS;
        return ['layer' => 'utilities', 'style' => $css];
    }

    private function handleBgPatterns(string $baseClassPart, array $matches): ?array {
        $type = $matches[1]; // grid or dots
        $colorValue = $matches[2] ?? null;
        
        // Auto-adapting color using modern CSS color-mix (Supports both Light & Dark out of the box)
        $color = 'color-mix(in srgb, currentColor 15%, transparent)'; 
        
        if ($colorValue) {
            $parsedColor = $this->parseColorValue($colorValue);
            if (!$parsedColor) {
                $cMap = ['primary'=>'p', 'secondary'=>'s', 'accent'=>'a', 'base-200'=>'b2', 'base-300'=>'b3'];
                if (isset($cMap[$colorValue])) $parsedColor = "hsl(var(--{$cMap[$colorValue]}))";
            }
            if ($parsedColor) {
                // Wrap in color-mix to give it a nice 15% opacity so it looks like a faint pattern
                $color = "color-mix(in srgb, {$parsedColor} 15%, transparent)";
            }
        }

        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        if ($type === 'dots') {
            $css = <<<CSS
            {$selector} {
                background-image: radial-gradient({$color} 1.5px, transparent 1.5px);
                background-size: 24px 24px;
            }
            CSS;
            return ['layer' => 'utilities', 'style' => $css];
        }

        if ($type === 'grid') {
            $css = <<<CSS
            {$selector} {
                background-image: linear-gradient(to right, {$color} 1px, transparent 1px),
                                  linear-gradient(to bottom, {$color} 1px, transparent 1px);
                background-size: 40px 40px;
            }
            CSS;
            return ['layer' => 'utilities', 'style' => $css];
        }
        return null;
    }

    private function handleTypingAnim(string $baseClassPart, array $matches): ?array {
        // Get character count if provided (e.g., animate-typing-[28]), default is 40
        $chars = $matches[1] ?? '40'; 
        $chars = preg_replace('/[^0-9]/', '', $chars); // Remove 'ch' if user typed [28ch]
        if (empty($chars)) $chars = '40';
        
        // Create a unique keyframe for this specific character length
        $kfName = "typing-{$chars}";
        $this->neededKeyframes[$kfName] = "{ from { width: 0; } to { width: {$chars}ch; } }";
        $this->neededKeyframes['blink-caret'] = '{ from, to { border-color: transparent } 50% { border-color: currentColor; } }';
        
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = <<<CSS
        {$selector} {
            display: inline-block;
            overflow: hidden; 
            white-space: nowrap; 
            border-right: 0.15em solid currentColor; /* The typewriter cursor */
            width: {$chars}ch; /* Locks the exact width */
            
            /* Animation timing matches the character count perfectly */
            animation: 
                {$kfName} var(--tw-animation-duration, 3.5s) steps({$chars}, end),
                blink-caret .75s step-end infinite;
        }
        CSS;
        return ['layer' => 'utilities', 'style' => $css];
    }

    private function handleViewTransition(string $baseClassPart, array $matches): ?array {
        $name = str_replace('_', '-', trim($matches[1], '[]'));
        return ['view-transition-name' => $name];
    }

    private function handleBorderBeam(string $baseClassPart, array $matches): ?array {
        $this->neededKeyframes['border-beam-spin'] = '{ 100% { transform: translate(-50%, -50%) rotate(360deg); } }';
        
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = <<<CSS
        {$selector} {
            position: relative; overflow: hidden; z-index: 0;
            border-radius: inherit; /* Inherit radius from parent */
        }
        {$selector}::before {
            content: ""; position: absolute; z-index: -2;
            left: 50%; top: 50%; transform: translate(-50%, -50%);
            width: 250%; height: 250%;
            background: conic-gradient(from 90deg at 50% 50%, transparent 70%, hsl(var(--p, var(--primary))) 100%);
            animation: border-beam-spin 4s linear infinite;
        }
        {$selector}::after {
            content: ""; position: absolute; z-index: -1;
            inset: 2px; /* Border thickness */
            background-color: hsl(var(--b1, var(--background)));
            border-radius: inherit;
        }
        CSS;
        return ['layer' => 'components', 'style' => $css];
    }

    private function handleGlitch(string $baseClassPart, array $matches): ?array {
        $this->neededKeyframes['glitch-anim-1'] = '{ 0% { clip-path: inset(20% 0 80% 0); transform: translate(-2px, 1px); } 20% { clip-path: inset(60% 0 10% 0); transform: translate(2px, -1px); } 40% { clip-path: inset(40% 0 50% 0); transform: translate(-2px, 2px); } 60% { clip-path: inset(80% 0 5% 0); transform: translate(2px, -2px); } 80% { clip-path: inset(10% 0 70% 0); transform: translate(-1px, 1px); } 100% { clip-path: inset(30% 0 50% 0); transform: translate(1px, -1px); } }';
        $this->neededKeyframes['glitch-anim-2'] = '{ 0% { clip-path: inset(10% 0 60% 0); transform: translate(2px, -1px); } 20% { clip-path: inset(30% 0 20% 0); transform: translate(-2px, 1px); } 40% { clip-path: inset(70% 0 10% 0); transform: translate(2px, -2px); } 60% { clip-path: inset(20% 0 50% 0); transform: translate(-2px, 2px); } 80% { clip-path: inset(50% 0 30% 0); transform: translate(1px, -1px); } 100% { clip-path: inset(5% 0 80% 0); transform: translate(-1px, 1px); } }';

        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = <<<CSS
        {$selector} {
            position: relative; display: inline-block;
        }
        {$selector}::before, {$selector}::after {
            content: attr(data-text); position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background-color: transparent;
        }
        {$selector}::before {
            left: 2px; text-shadow: -1px 0 red; animation: glitch-anim-1 2s infinite linear alternate-reverse;
        }
        {$selector}::after {
            left: -2px; text-shadow: -1px 0 blue; animation: glitch-anim-2 3s infinite linear alternate-reverse;
        }
        CSS;
        return ['layer' => 'utilities', 'style' => $css];
    }

    private function handleStarfield(string $baseClassPart, array $matches): ?array {
        $this->neededKeyframes['starfield-move'] = '{ 0% { background-position: 0% 0%, 0% 0%, 0% 0%; } 100% { background-position: -500px -500px, -1000px -1000px, -1500px -1500px; } }';
        
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = <<<CSS
        {$selector} {
            background-color: #000;
            background-image: 
                radial-gradient(1px 1px at 20px 30px, #fff, rgba(0,0,0,0)),
                radial-gradient(1px 1px at 40px 70px, #fff, rgba(0,0,0,0)),
                radial-gradient(2px 2px at 90px 40px, #fff, rgba(0,0,0,0));
            background-size: 200px 200px, 300px 300px, 400px 400px;
            animation: starfield-move 10s linear infinite;
        }
        CSS;
        return ['layer' => 'utilities', 'style' => $css];
    }

    private function handleRevealAnim(string $baseClassPart, array $matches): ?array {
        $direction = $matches[1]; // up, down, left, right
        
        // Define keyframes dynamically based on direction
        $kfName = "reveal-{$direction}";
        $translate = match($direction) {
            'up' => 'translateY(100%)',
            'down' => 'translateY(-100%)',
            'left' => 'translateX(100%)',
            'right' => 'translateX(-100%)',
        };

        $this->neededKeyframes[$kfName] = "{ 0% { transform: {$translate}; opacity: 0; } 100% { transform: translate(0); opacity: 1; } }";
        
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = <<<CSS
        /* Wrapper must hide overflow */
        {$selector} {
            display: inline-flex;
            overflow: hidden;
            vertical-align: top;
        }
        /* The actual content that animates */
        {$selector} > * {
            display: inline-block;
            animation: {$kfName} var(--tw-animation-duration, 0.8s) cubic-bezier(0.77, 0, 0.175, 1) forwards;
            animation-delay: var(--tw-animation-delay, 0s);
        }
        CSS;
        return ['layer' => 'utilities', 'style' => $css];
    }

    private function handleBgGrain(string $baseClassPart, array $matches): ?array {
        // Steps animation gives it that realistic 24fps film look
        $this->neededKeyframes['noise-anim'] = '{ 0%, 100% { transform: translate(0,0); } 10% { transform: translate(-5%,-10%); } 20% { transform: translate(-15%,5%); } 30% { transform: translate(7%,-25%); } 40% { transform: translate(-5%,25%); } 50% { transform: translate(-15%,10%); } 60% { transform: translate(15%,0%); } 70% { transform: translate(0%,15%); } 80% { transform: translate(3%,35%); } 90% { transform: translate(-10%,10%); } }';
        
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = <<<CSS
        {$selector} {
            position: relative; overflow: hidden;
            background-color: hsl(var(--b1, var(--background)));
        }
        {$selector}::after {
            content: ""; position: absolute; inset: -200%; z-index: 10; pointer-events: none;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E");
            opacity: 0.06; mix-blend-mode: overlay;
            animation: noise-anim 0.5s steps(2) infinite;
        }
        CSS;
        return ['layer' => 'utilities', 'style' => $css];
    }

    private function handleBtnShine(string $baseClassPart, array $matches): ?array {
        $this->neededKeyframes['shine-sweep'] = '{ 0% { background-position: 200% center; } 100% { background-position: -200% center; } }';
        
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = <<<CSS
        {$selector} {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 0.5rem 1.5rem; border-radius: var(--rounded-btn, 0.5rem);
            font-weight: 500; transition: transform 0.2s, box-shadow 0.2s;
            /* A dark button with a white glossy sweep passing through */
            background: linear-gradient(110deg, 
                hsl(var(--n, 215 28% 17%)) 40%, 
                hsl(var(--nc, 0 0% 100%) / 0.2) 50%, 
                hsl(var(--n, 215 28% 17%)) 60%
            );
            background-size: 200% auto;
            color: hsl(var(--nc, 0 0% 100%));
            border: 1px solid hsl(var(--b3, var(--border)) / 0.3);
            animation: shine-sweep 3s linear infinite;
            cursor: pointer;
        }
        {$selector}:hover {
            transform: scale(1.02);
            box-shadow: 0 0 20px hsl(var(--bc, var(--foreground)) / 0.1);
        }
        CSS;
        return ['layer' => 'components', 'style' => $css];
    }

    private function handleTextGlimmer(string $baseClassPart, array $matches): ?array {
        $this->neededKeyframes['text-glimmer-anim'] = '{ 0% { background-position: 100% center; } 100% { background-position: -100% center; } }';
        
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = <<<CSS
        {$selector} {
            display: inline-block;
            background-image: linear-gradient(
                90deg, 
                hsl(var(--bc, var(--foreground)) / 0.3) 0%, 
                hsl(var(--bc, var(--foreground))) 50%, 
                hsl(var(--bc, var(--foreground)) / 0.3) 100%
            );
            background-size: 200% auto;
            color: transparent;
            -webkit-background-clip: text;
            background-clip: text;
            animation: text-glimmer-anim 3s linear infinite;
        }
        CSS;
        return ['layer' => 'utilities', 'style' => $css];
    }

    private function handleCardSpotlight(string $baseClassPart, array $matches): ?array {
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = <<<CSS
        {$selector} {
            position: relative; overflow: hidden;
            border-radius: var(--rounded-box, 1rem);
            background-color: hsl(var(--b1, var(--card)));
            border: 1px solid hsl(var(--b3, var(--border)) / 0.2);
            z-index: 1;
        }
        /* Moving spotlight on the border */
        {$selector}::before {
            content: ""; position: absolute; inset: -1px; z-index: -1;
            background: conic-gradient(from var(--angle-to-the-dangle, 0deg) at 50% 50%, 
                transparent 0%, transparent 80%, hsl(var(--p, var(--primary))) 100%);
            border-radius: inherit;
            animation: rotateColors 4s linear infinite;
        }
        /* Inner card background to hide inner spotlight */
        {$selector}::after {
            content: ""; position: absolute; inset: 1px; z-index: -1;
            background-color: hsl(var(--b1, var(--card)));
            border-radius: calc(var(--rounded-box, 1rem) - 1px);
        }
        CSS;
        
        // Register the property if not already done by border-conic-glow
        $this->neededProperties['angle-to-the-dangle'] = $this->config['theme']['properties']['angle-to-the-dangle'];
        $this->neededKeyframes['rotateColors'] = $this->config['theme']['keyframes']['rotateColors'];

        return ['layer' => 'components', 'style' => $css];
    }

    private function handleHoverLift(string $baseClassPart, array $matches): ?array {
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = <<<CSS
        {$selector} {
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: transform, box-shadow;
        }
        {$selector}:hover {
            transform: translateY(-0.35rem) scale(1.01);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            z-index: 10;
        }
        CSS;
        return ['layer' => 'utilities', 'style' => $css];
    }

    private function handleColumns(string $baseClassPart, array $matches): ?array {
        $value = $matches[1];
        
        if (is_numeric($value) && $value >= 1 && $value <= 12) {
            return ['column-count' => $value];
        }
        
        $widths = [
            '3xs' => '16rem', '2xs' => '18rem', 'xs' => '20rem', 'sm' => '24rem', 
            'md' => '28rem', 'lg' => '32rem', 'xl' => '36rem', '2xl' => '42rem', 
            '3xl' => '48rem', '4xl' => '56rem', '5xl' => '64rem', '6xl' => '72rem', '7xl' => '80rem'
        ];
        
        if (isset($widths[$value])) {
            return ['column-width' => $widths[$value]];
        }
        
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $val = trim($value, '[]');
            return (is_numeric($val)) ? ['column-count' => $val] : ['column-width' => $val];
        }

        return null;
    }

    private function handleBreak(string $baseClassPart, array $matches): ?array {
        $type = $matches[1]; // inside, before, after
        $value = $matches[2]; // avoid, auto, etc.
        
        return ["break-{$type}" => $value];
    }

    private function handleCarousel(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        // Base Carousel Container
        if ($modifier === 'base') {
            $css = <<<CSS
            {$selector} {
                display: inline-flex; overflow-x: scroll; scroll-snap-type: x mandatory;
                scroll-behavior: smooth; -ms-overflow-style: none; scrollbar-width: none;
            }
            {$selector}::-webkit-scrollbar { display: none; }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // Modifiers
        if ($modifier === 'vertical') {
            return ['layer' => 'components', 'style' => "{$selector} { flex-direction: column; overflow-y: scroll; scroll-snap-type: y mandatory; }"];
        }
        
        if ($modifier === 'item') {
            return ['layer' => 'components', 'style' => "{$selector} { box-sizing: content-box; display: flex; flex: none; scroll-snap-align: start; }"];
        }
        
        // Snap Alignments for Items
        if ($modifier === 'center') return ['layer' => 'components', 'style' => "{$selector} { scroll-snap-align: center; }"];
        if ($modifier === 'end') return ['layer' => 'components', 'style' => "{$selector} { scroll-snap-align: end; }"];

        return null;
    }

    private function handleToastPos(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        // Override existing bootstrap toast if needed, or add wrapper functionality
        if ($modifier === 'base') {
            $css = <<<CSS
            {$selector} {
                position: fixed; display: flex; min-width: fit-content; flex-direction: column;
                white-space: nowrap; gap: 0.5rem; padding: 1rem; z-index: 9999;
                /* Default to bottom end */
                bottom: 0; right: 0;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // Positions
        $styles = [];
        if (str_contains($modifier, 'top')) { $styles['top'] = '0'; $styles['bottom'] = 'auto'; }
        if (str_contains($modifier, 'bottom')) { $styles['bottom'] = '0'; $styles['top'] = 'auto'; }
        if (str_contains($modifier, 'start')) { $styles['left'] = '0'; $styles['right'] = 'auto'; }
        if (str_contains($modifier, 'end')) { $styles['right'] = '0'; $styles['left'] = 'auto'; }
        if (str_contains($modifier, 'center')) {
            if (str_contains($modifier, 'top') || str_contains($modifier, 'bottom')) {
                $styles['left'] = '50%'; $styles['transform'] = 'translateX(-50%)';
            } else {
                $styles['top'] = '50%'; $styles['transform'] = 'translateY(-50%)';
            }
        }
        if ($modifier === 'center') {
            $styles['top'] = '50%'; $styles['left'] = '50%'; $styles['transform'] = 'translate(-50%, -50%)';
        }

        return !empty($styles) ? ['layer' => 'components', 'style' => $styles] : null;
    }

    private function handleCheckbox(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        
        // --- Base .checkbox Styles (Updated for Outline) ---
        if ($baseClassPart === 'checkbox') {
            // টিক চিহ্নের SVG এখন currentColor ব্যবহার করবে
            $checkIcon = "url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='currentColor'%3e%3cpath d='M12.207 4.793a1 1 0 010 1.414l-5 5a1 1 0 01-1.414 0l-2-2a1 1 0 011.414-1.414L6.5 9.086l4.293-4.293a1 1 0 011.414 0z'/%3e%3c/svg%3e\")";

            $css = <<<CSS
            .checkbox {
                appearance: none;
                width: 1.5rem; height: 1.5rem;
                border-radius: var(--rounded-btn, 0.5rem);
                /* ডিফল্ট বর্ডার কালার */
                border: 2px solid hsl(var(--bc, var(--border)));
                background-color: transparent; /* আনচেক অবস্থায় شفاف */
                transition: background-color 0.2s, border-color 0.2s;
            }
            .checkbox:checked {
                background-image: {$checkIcon};
                background-size: 100%;
                background-repeat: no-repeat;
                background-position: center;
                /* ডিফল্ট checked কালার (সলিড) */
                background-color: hsl(var(--bc, var(--border)));
                border-color: hsl(var(--bc, var(--border)));
                color: hsl(var(--b1, var(--card))); /* টিক চিহ্নের কালার */
            }
            /* ... (indeterminate and disabled styles) ... */
            .checkbox:indeterminate {
                background-color: hsl(var(--bc, var(--border)));
                border-color: hsl(var(--bc, var(--border)));
                color: hsl(var(--b1, var(--card))); /* টিক চিহ্নের কালার */
            }
            .checkbox:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- Modifier Handler (Size or Color) ---
        
        // Size modifier handling (unchanged)
        $sizes = ['xs', 'sm', 'md', 'lg', 'xl'];
        if (in_array($modifier, $sizes)) {
            return match ($modifier) {
                'xs' => ['style' => ['width' => '1rem', 'height' => '1rem']],
                'sm' => ['style' => ['width' => '1.25rem', 'height' => '1.25rem']],
                'lg' => ['style' => ['width' => '1.75rem', 'height' => '1.75rem']],
                'xl' => ['style' => ['width' => '2rem', 'height' => '2rem']],
                default => [],
            };
        }

        // Color modifier handling
        $colorKey = $modifier;
        $colorValue = $this->parseColorValue($colorKey);
        
        if ($colorValue) {
            $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
            
            // Check brightness to determine checkmark color (black or white)
            $checkmarkColor = $this->isColorDark($colorValue) ? 'white' : 'black';

            // আনচেক অবস্থায় বর্ডার কালার এবং চেক অবস্থায় ব্যাকগ্রাউন্ড কালার সেট করা
            return [
                'layer' => 'components',
                'style' => [
                    'border-color' => $colorValue, // Unchecked state
                    '_checkedStyles' => [
                        'background-color' => $colorValue,
                        'border-color' => $colorValue,
                        'color' => $checkmarkColor, // Auto checkmark color
                    ]
                ]
            ];
        }

        return null;
    }
    private function isColorDark(string $color): bool {
        // Step 1: Resolve theme variables and color keywords to a hex/rgb value
        $resolvedColor = $this->parseColorValue($color);
        if (!$resolvedColor) {
            // Fallback for named colors not in theme
            $color = strtolower($color);
            // This is a simplified map, a full map would be very large
            $keywordMap = ['black' => '#000000', 'white' => '#ffffff', 'red' => '#ff0000', 'green' => '#008000', 'blue' => '#0000ff'];
            if(isset($keywordMap[$color])) {
                $resolvedColor = $keywordMap[$color];
            } else {
                return true; // Default to dark if color is un-parsable
            }
        }

        // Step 2: Extract R, G, B values from any format
        $r = $g = $b = 0;

        if (preg_match('/^#([a-f0-9]{3}|[a-f0-9]{6})$/i', $resolvedColor, $m)) {
            $hex = $m[1];
            if (strlen($hex) == 3) {
                $r = hexdec($hex[0].$hex[0]); $g = hexdec($hex[1].$hex[1]); $b = hexdec($hex[2].$hex[2]);
            } else {
                $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
            }
        } elseif (preg_match('/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/i', $resolvedColor, $m)) {
            $r = (int)$m[1]; $g = (int)$m[2]; $b = (int)$m[3];
        } elseif (preg_match('/^hsl\((\d+),\s*(\d+)%,\s*(\d+)%\)$/i', $resolvedColor, $m)) {
            // HSL to RGB conversion
            $h = (int)$m[1] / 360; $s = (int)$m[2] / 100; $l = (int)$m[3] / 100;
            if ($s == 0) {
                $r = $g = $b = $l;
            } else {
                $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
                $p = 2 * $l - $q;
                $hue2rgb = function ($p, $q, $t) {
                    if ($t < 0) $t += 1;
                    if ($t > 1) $t -= 1;
                    if ($t < 1/6) return $p + ($q - $p) * 6 * $t;
                    if ($t < 1/2) return $q;
                    if ($t < 2/3) return $p + ($q - $p) * (2/3 - $t) * 6;
                    return $p;
                };
                $r = $hue2rgb($p, $q, $h + 1/3);
                $g = $hue2rgb($p, $q, $h);
                $b = $hue2rgb($p, $q, $h - 1/3);
            }
            $r = round($r * 255); $g = round($g * 255); $b = round($b * 255);
        } else {
            return true; // Fallback: Assume dark for unknown formats
        }
        
        // Step 3: Calculate luminance and return result
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        return $luminance < 0.5;
    }

    private function handleRadio(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        
        // --- Base .radio Styles ---
        if ($baseClassPart === 'radio') {
            $css = <<<CSS
            .radio {
                appearance: none;
                position: relative; /* ::before এর জন্য */
                width: 1.5rem; height: 1.5rem;
                border-radius: 9999px;
                border: 2px solid hsl(var(--bc, var(--border)));
                background-color: transparent;
                transition: background-color 0.2s, border-color 0.2s;
            }

            /* ::before দিয়ে মাঝখানের ডট তৈরি করা */
            .radio::before {
                content: "";
                position: absolute;
                top: 50%; left: 50%;
                transform: translate(-50%, -50%) scale(0); /* ডিফল্টভাবে হাইড থাকবে */
                width: 0.75rem; height: 0.75rem;
                border-radius: 9999px;
                background-color: hsl(var(--bc, var(--border))); /* ডিফল্ট কালার */
                transition: transform 0.2s ease-out;
            }

            .radio:checked {
                /* Checked অবস্থায় কোনো ব্যাকগ্রাউন্ড পরিবর্তন নেই */
            }
            
            /* Checked অবস্থায় ::before কে দেখানো */
            .radio:checked::before {
                transform: translate(-50%, -50%) scale(1);
            }

            .radio:disabled { opacity: 0.5; cursor: not-allowed; }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- Modifier Handler (Size or Color) ---
        $isSize = in_array($modifier, ['xs', 'sm', 'md', 'lg', 'xl']);
        if ($isSize) {
            return match ($modifier) {
                'xs' => ['style' => ['width' => '1rem', 'height' => '1rem']],
                'sm' => ['style' => ['width' => '1.25rem', 'height' => '1.25rem']],
                'lg' => ['style' => ['width' => '1.75rem', 'height' => '1.75rem']],
                'xl' => ['style' => ['width' => '2rem', 'height' => '2rem']],
                default => [],
            };
        }
        
        // Color Modifier
        $colorKey = $modifier;
        $colorValue = $this->parseColorValue($colorKey);
        
        if ($colorValue) {
            $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
            return [
                'layer' => 'components',
                'style' => [
                    'border-color' => $colorValue, // Unchecked state border
                    '_beforeStyles' => [
                        'background-color' => $colorValue, // Dot color
                    ],
                    '_checkedStyles' => [
                        'border-color' => $colorValue, // Checked state border
                    ]
                ]
            ];
        }
        return null;
    }

    private function handleRange(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        if ($baseClassPart === 'range') {
            $css = <<<CSS
            {$selector} {
                appearance: none; -webkit-appearance: none;
                width: 100%; 
                background-color: transparent;
                cursor: pointer; vertical-align: middle;
                overflow: hidden; /* Clips the shadow bar */
                
                /* Default Variables */
                --range-shdw: hsl(var(--p, var(--primary)));
                --range-track: hsl(var(--bc, var(--foreground)) / 0.1);
                --thumb-bg: #ffffff;
                --thumb-size: 1.25rem;
                --track-h: 0.5rem;
                --thumb-border: 2px;
                
                /* Height of the input must match thumb size to prevent edge cutting */
                height: var(--thumb-size);
                border-radius: 9999px; /* Ensures the whole container is rounded */
            }
            {$selector}:focus { outline: none; }

            /* --- TRACK (Webkit) --- */
            {$selector}::-webkit-slider-runnable-track {
                width: 100%; 
                height: var(--track-h);
                background-color: var(--range-track);
                border-radius: 9999px;
            }
            
            /* --- THUMB (Webkit) --- */
            {$selector}::-webkit-slider-thumb {
                appearance: none; -webkit-appearance: none;
                width: var(--thumb-size); height: var(--thumb-size);
                background-color: var(--thumb-bg);
                border-radius: 9999px;
                border: var(--thumb-border) solid var(--range-shdw);
                
                /* Center thumb vertically relative to track */
                margin-top: calc((var(--track-h) / 2) - (var(--thumb-size) / 2));
                
                /* The Magic Fill: Huge shadow that stays within the rounded input */
                box-shadow: 
                    0 0 0 var(--thumb-border) var(--range-shdw) inset, /* Inner border logic */
                    calc(-100vw - (var(--thumb-size) / 2)) 0 0 100vw var(--range-shdw);
                
                transition: background-color 0.2s, border-color 0.2s;
            }

            /* --- TRACK (Firefox) --- */
            {$selector}::-moz-range-track {
                width: 100%; height: var(--track-h);
                background-color: var(--range-track);
                border-radius: 9999px;
            }
            
            /* --- THUMB (Firefox) --- */
            {$selector}::-moz-range-thumb {
                width: var(--thumb-size); height: var(--thumb-size);
                background-color: var(--thumb-bg);
                border: var(--thumb-border) solid var(--range-shdw);
                border-radius: 9999px;
            }

            /* --- PROGRESS (Firefox Native) --- */
            {$selector}::-moz-range-progress {
                background-color: var(--range-shdw);
                height: var(--track-h);
                border-radius: 9999px;
            }

            /* --- Theme Specific Adjustments --- */
            .dark {$selector} {
                --thumb-bg: hsl(var(--b1)); /* Match dark theme background */
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- Precise Size Map (Proportionally Balanced) ---
        $sizeMap = [
            'xs' => ['thumb' => '1rem',    'track' => '0.25rem', 'border' => '1px'],
            'sm' => ['thumb' => '1.25rem', 'track' => '0.4rem',  'border' => '2px'],
            'md' => ['thumb' => '1.5rem',  'track' => '0.5rem',  'border' => '2px'],
            'lg' => ['thumb' => '2rem',    'track' => '0.75rem', 'border' => '3px'],
        ];

        if (isset($sizeMap[$modifier])) {
            $s = $sizeMap[$modifier];
            return ['style' => [
                '--thumb-size' => $s['thumb'], 
                '--track-h' => $s['track'],
                '--thumb-border' => $s['border']
            ]];
        }
        
        // --- Dynamic Colors ---
        $colorValue = $this->parseColorValue($modifier);
        if (!$colorValue) {
            $cMap = ['primary'=>'p', 'secondary'=>'s', 'accent'=>'a', 'info'=>'in', 'success'=>'su', 'warning'=>'wa', 'error'=>'er', 'neutral'=>'n'];
            if (isset($cMap[$modifier])) {
                $colorValue = "hsl(var(--{$cMap[$modifier]}))";
            }
        }
        
        if ($colorValue) {
            return ['style' => ['--range-shdw' => $colorValue]];
        }

        return null;
    }

    private function handleRating(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        
        // --- Base .rating Styles ---
        if ($baseClassPart === 'rating') {
            $css = <<<CSS
            /* --- Rating Component (Full Logic) --- */
            .rating {
                position: relative; display: inline-flex;
                font-size: 1.5rem; /* md size */
                direction: rtl;
            }
            .rating input {
                appearance: none; -webkit-appearance: none;
                cursor: pointer; width: 1em; height: 1em;
            }

            /* --- Color & Opacity Logic --- */
            /* 1. Unselected State */
            .rating input {
                background-color: hsl(var(--b2, var(--muted)));
                opacity: 0.25;
            }

            /* 2. Hover State */
            .rating:hover input {
                background-color: hsl(var(--b3, var(--border))); /* Hover unselected color */
                opacity: 0.5;
            }
            /* Reset opacity for stars after the hovered one */
            .rating input:hover ~ input {
                background-color: hsl(var(--b2, var(--muted)));
                opacity: 0.25;
            }

            /* 3. Checked State */
            .rating input:checked {
                background-color: hsl(var(--wa, var(--warning))); /* Default: warning color */
                opacity: 1;
            }
            .rating input:checked ~ input {
                background-color: hsl(var(--wa, var(--warning)));
                opacity: 1;
            }
            
            /* --- Half-star Logic --- */
            .rating.rating-half input.mask-half-1:checked,
            .rating.rating-half input.mask-half-1:checked ~ input,
            .rating.rating-half input.mask-half-2:checked,
            .rating.rating-half input.mask-half-2:checked ~ input {
                opacity: 1;
            }

            /* --- Read-only Logic --- */
            .rating [aria-current=true] ~ * {
                background-color: hsl(var(--b2, var(--muted)));
                opacity: 0.25;
            }
            .rating [aria-current=true] {
                background-color: hsl(var(--wa, var(--warning)));
                opacity: 1;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- Modifier Styles ---
        switch ($modifier) {
            case 'xs': return ['style' => ['font-size' => '1rem']];
            case 'sm': return ['style' => ['font-size' => '1.25rem']];
            case 'lg': return ['style' => ['font-size' => '2rem']];
            case 'xl': return ['style' => ['font-size' => '2.5rem']];
            case 'hidden': return ['style' => ['width' => '0']];
        }
        
        return null;
    }

    private function handleSelect(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        // --- Base .select Styles ---
        if ($baseClassPart === 'select') {
            $arrowSvg = rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none'><path d='M7 7l3 3 3-3' stroke='currentColor' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");

            $css = <<<CSS
            {$selector} {
                display: inline-flex;
                cursor: pointer;
                user-select: none;
                appearance: none;
                -webkit-appearance: none;
                padding-right: 2.5rem; /* Space for arrow */
                height: 3rem; /* md size */
                padding-left: 1rem;
                font-size: 0.875rem;
                line-height: 1.25rem;
                border-radius: var(--rounded-btn, 0.5rem);
                border: 1px solid hsl(var(--b3, var(--border)));
                background-color: hsl(var(--b1, var(--card)));
                background-image: url("data:image/svg+xml,{$arrowSvg}");
                background-position: right 0.75rem center;
                background-repeat: no-repeat;
                background-size: 1.5em 1.5em;
                color: hsl(var(--bc, var(--foreground)));
            }
            {$selector}:focus {
                outline: 2px solid hsl(var(--bc));
                outline-offset: 0px;
            }
            {$selector}[disabled] {
                opacity: 0.5;
                cursor: not-allowed;
                border-color: hsl(var(--b2, var(--muted)));
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- Modifier Handler (Size, Color, Ghost) ---
        $isSize = in_array($modifier, ['xs', 'sm', 'md', 'lg', 'xl']);
        if ($isSize) {
            return match ($modifier) {
                'xs' => ['style' => ['height' => '1.5rem', 'padding-left' => '0.5rem', 'font-size' => '0.75rem']],
                'sm' => ['style' => ['height' => '2rem', 'padding-left' => '0.75rem', 'font-size' => '0.875rem']],
                'lg' => ['style' => ['height' => '3.5rem', 'padding-left' => '1.25rem', 'font-size' => '1.125rem']],
                'xl' => ['style' => ['height' => '4rem', 'padding-left' => '1.5rem', 'font-size' => '1.25rem']],
                default => [], // md is default
            };
        }

        if ($modifier === 'ghost') {
            return ['style' => [
                'background-color' => 'transparent',
                'border-color' => 'transparent',
                '_focusStyles' => ['background-color' => 'transparent', 'border-color' => 'transparent', 'color' => 'hsl(var(--bc))'],
            ]];
        }

        // Color Modifier
        $colorKey = $modifier;
        $colorValue = $this->parseColorValue($colorKey);
        
        if ($colorValue) {
            return ['style' => [
                'border-color' => $colorValue,
                '_focusStyles' => [
                    'outline-color' => $colorValue,
                    'border-color' => $colorValue,
                ]
            ]];
        }

        return null;
    }

    private function handleInput(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        
        // --- Base .input Styles ---
        if ($baseClassPart === 'input') {
            $css = <<<CSS
            .input {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                height: 3rem; /* md size */
                padding: 0 1rem;
                font-size: 0.875rem;
                border-radius: var(--rounded-btn, 0.5rem);
                border: 1px solid hsl(var(--b3, var(--border)));
                background-color: hsl(var(--b1, var(--card)));
                color: hsl(var(--bc, var(--foreground)));
                transition: border-color 0.2s ease-in-out;
            }
            /* Style for the actual input tag inside a styled label/div */
            .input > input {
                flex-grow: 1;
                background-color: transparent;
                border: none;
                outline: none;
                padding: 0;
            }
            .input:focus-within {
                outline: 2px solid hsl(var(--bc));
                outline-offset: 0px;
            }
            .input[disabled] {
                opacity: 0.5;
                cursor: not-allowed;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- Modifier Handler (Size, Color, Ghost) ---
        $isSize = in_array($modifier, ['xs', 'sm', 'md', 'lg', 'xl']);
        if ($isSize) {
            return match ($modifier) {
                'xs' => ['style' => ['height' => '1.5rem', 'padding-left' => '0.5rem', 'font-size' => '0.75rem']],
                'sm' => ['style' => ['height' => '2rem', 'padding-left' => '0.75rem', 'font-size' => '0.875rem']],
                'lg' => ['style' => ['height' => '3.5rem', 'padding-left' => '1.25rem', 'font-size' => '1.125rem']],
                'xl' => ['style' => ['height' => '4rem', 'padding-left' => '1.5rem', 'font-size' => '1.25rem']],
                default => [], // md
            };
        }

        if ($modifier === 'ghost') {
            return ['style' => ['background-color' => 'transparent', 'border-color' => 'transparent', '_focusStyles' => ['background-color' => 'transparent']]];
        }
        
        // Color Modifier
        $colorKey = $modifier;
        $colorValue = $this->parseColorValue($colorKey);
        
        if ($colorValue) {
            return ['style' => [
                'border-color' => $colorValue,
                '_focusStyles' => ['outline-color' => $colorValue, 'border-color' => $colorValue],
            ]];
        }

        return null;
    }

    private function handleTextarea(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        
        // --- Base .textarea Styles ---
        if ($baseClassPart === 'textarea') {
            $css = <<<CSS
            .textarea {
                display: block; /* Takes full width by default */
                width: 100%;
                min-height: 5rem; /* Default height */
                padding: 0.75rem 1rem;
                font-size: 0.875rem;
                border-radius: var(--rounded-btn, 0.5rem);
                border: 1px solid hsl(var(--b3, var(--border)));
                background-color: hsl(var(--b1, var(--card)));
                color: hsl(var(--bc, var(--foreground)));
                transition: border-color 0.2s ease-in-out;
                resize: vertical;
            }
            .textarea:focus {
                outline: 2px solid hsl(var(--bc));
                outline-offset: 0px;
            }
            .textarea[disabled] {
                opacity: 0.5;
                cursor: not-allowed;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- Modifier Handler (Size, Color, Ghost) ---
        $isSize = in_array($modifier, ['xs', 'sm', 'md', 'lg', 'xl']);
        if ($isSize) {
            return match ($modifier) {
                'xs' => ['style' => ['min-height' => '3rem', 'padding' => '0.5rem', 'font-size' => '0.75rem']],
                'sm' => ['style' => ['min-height' => '4rem', 'padding' => '0.625rem 0.875rem', 'font-size' => '0.875rem']],
                'lg' => ['style' => ['min-height' => '6rem', 'padding' => '1rem 1.25rem', 'font-size' => '1.125rem']],
                'xl' => ['style' => ['min-height' => '8rem', 'padding' => '1.25rem 1.5rem', 'font-size' => '1.25rem']],
                default => [], // md
            };
        }

        if ($modifier === 'ghost') {
            return ['style' => ['background-color' => 'transparent', 'border-color' => 'transparent', '_focusStyles' => ['background-color' => 'transparent']]];
        }
        
        // Color Modifier
        $colorKey = $modifier;
        $colorValue = $this->parseColorValue($colorKey);
        
        if ($colorValue) {
            return ['style' => [
                'border-color' => $colorValue,
                '_focusStyles' => ['outline-color' => $colorValue, 'border-color' => $colorValue],
            ]];
        }

        return null;
    }

    private function handleToggle(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? 'base';
        
        // --- Base .toggle Styles ---
        if ($baseClassPart === 'toggle') {
            $css = <<<CSS
            .toggle {
                appearance: none; -webkit-appearance: none;
                height: 1.5rem; width: 2.75rem; /* md size */
                border-radius: 9999px;
                background-color: hsl(var(--b2, var(--muted)));
                border: 1px solid hsl(var(--b2, var(--muted)));
                transition: background-color 0.2s, border-color 0.2s;
                position: relative;
                --tglbg: hsl(var(--p, var(--primary))); /* Default checked color */
                --h: 1.5rem; /* Height variable */
                --w: 2.75rem; /* Width variable */
                --handle-offset: 0.2rem;
                --handle-size: calc(var(--h) - var(--handle-offset) * 2.5);
                --handle-translate: calc(var(--w) - var(--h));
            }
            .toggle::before {
                content: "";
                position: absolute;
                top: var(--handle-offset);
                left: var(--handle-offset);
                height: var(--handle-size);
                width: var(--handle-size);
                border-radius: 9999px;
                background-color: hsl(var(--b1, var(--card)));
                transition: transform 0.2s ease-out;
            }

            .toggle:checked {
                background-color: var(--tglbg);
                border-color: var(--tglbg);
            }
            .toggle:checked::before {
                transform: translateX(var(--handle-translate));
            }

            .toggle:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- Modifier Handler (Size or Color) ---
        $isSize = in_array($modifier, ['xs', 'sm', 'md', 'lg', 'xl']);

        if ($isSize) {
            return match ($modifier) {
                'xs' => ['style' => ['--h' => '1rem', '--w' => '1.75rem', '--handle-offset' => '0.125rem']],
                'sm' => ['style' => ['--h' => '1.25rem', '--w' => '2.25rem', '--handle-offset' => '0.125rem']],
                'lg' => ['style' => ['--h' => '1.75rem', '--w' => '3.25rem', '--handle-offset' => '0.25rem']],
                'xl' => ['style' => ['--h' => '2rem', '--w' => '3.75rem', '--handle-offset' => '0.25rem']],
                default => [], // md
            };
        }
        
        // Color Modifier
        $colorKey = $modifier;
        $colorValue = null;

        $colorMap = ['primary'=>'p', 'secondary'=>'s', 'accent'=>'a', 'info'=>'in', 'success'=>'su', 'warning'=>'wa', 'error'=>'er', 'danger' => 'er', 'neutral'=>'n'];
        if (isset($colorMap[$colorKey])) {
            $c = $colorMap[$colorKey];
            $colorValue = "hsl(var(--{$c}))";
        } else {
            $colorValue = $this->parseColorValue($colorKey);
        }
        
        if ($colorValue) {
            return ['style' => ['--tglbg' => $colorValue]];
        }

        return null;
    }

    private function handleValidator(string $baseClassPart, array $matches): ?array {
        $part = $matches[1] ?? 'base';
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        $css = '';
        switch ($part) {
            case 'base':
                // --- Validation Icons (SVG) ---
                $successIcon = rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'><path fill='hsl(var(--su, var(--success)))' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.64 1.4-.28 1.1.7l-4 4.6c-.43.5-.8.4-1.1.1z'/></svg>");
                $errorIcon = rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' fill='hsl(var(--er, var(--error)))' viewBox='0 0 12 12' width='12' height='12'><path d='M9.42 2.58a1 1 0 0 0-1.41 0L6 4.59 4 2.58a1 1 0 0 0-1.41 1.41L4.59 6 2.58 8a1 1 0 1 0 1.41 1.41L6 7.41l2 2a1 1 0 0 0 1.41-1.41L7.41 6l2-2a1 1 0 0 0 0-1.41z'/></svg>");
                
                $css = <<<CSS
                /* --- Validator Base Styles --- */
                /* Base state (before interaction) */
                {$selector}:placeholder-shown:not(:focus),
                {$selector}:user-invalid:not(:focus) {
                    border-color: hsl(var(--b3, var(--border)));
                    background-image: none;
                }
                
                /* Valid state */
                {$selector}:valid:not(:placeholder-shown) {
                    border-color: hsl(var(--su, var(--success)));
                    background-image: url("data:image/svg+xml,{$successIcon}");
                }
                
                /* Invalid state */
                {$selector}:invalid {
                    border-color: hsl(var(--er, var(--error)));
                    background-image: url("data:image/svg+xml,{$errorIcon}");
                }

                /* Common styles for icons */
                {$selector}:valid:not(:placeholder-shown),
                {$selector}:invalid {
                    background-repeat: no-repeat;
                    background-position: right 0.75rem center;
                    background-size: 1rem;
                }

                /* Show hint for invalid state */
                {$selector}:invalid ~ .validator-hint,
                {$selector}:invalid + .validator-hint {
                    visibility: visible;
                    height: auto;
                    margin-top: 0.25rem;
                }
                CSS;
                break;

            case 'hint':
                $css = <<<CSS
                {$selector} {
                    visibility: hidden;
                    height: 0;
                    overflow: hidden;
                    font-size: 0.875rem;
                    color: hsl(var(--er, var(--error)));
                }
                {$selector}.hidden { display: none; }
                CSS;
                break;
        }

        return !empty($css) ? ['layer' => 'components', 'style' => $css] : null;
    }

    private function handleFill(string $baseClassPart, array $matches): ?array { $color = $this->parseColorValue($matches[1]); return $color ? ['fill' => $color] : null; }
    private function handleSvgStroke(string $baseClassPart, array $matches): ?array {
        $valuePart = $matches[1];
        
        // প্রথমে রঙ হিসেবে পার্স করার চেষ্টা করা হচ্ছে
        $color = $this->parseColorValue($valuePart);
        if ($color) {
            return ['stroke' => $color];
        }
        
        // যদি রঙ না হয়, তাহলে প্রস্থ হিসেবে পার্স করার চেষ্টা করা হচ্ছে
        // 'borderWidth' theme থেকে মান নেওয়া হচ্ছে
        $width = $this->parseNumericValue($valuePart, 'borderWidth');
        if ($width !== null) {
            return ['stroke-width' => $width];
        }
        
        // Tailwind-এ stroke-0, stroke-1, stroke-2 সরাসরি সংখ্যা হিসেবেও কাজ করে
        if (is_numeric($valuePart)) {
            return ['stroke-width' => $valuePart];
        }

        return null;
    }
    private function handleStrokeDashArray(string $baseClassPart, array $matches): ?array { $val = $this->parseNumericValue($matches[1], '', ['numericIsRaw' => true, 'allowArbitrary' => true]); return $val ? ['stroke-dasharray' => $val] : null;}
    private function handleIcon(string $baseClassPart, array $matches): ?array {
        $iconName = $matches[1];

        if (!isset($this->config['theme']['icons'][$iconName])) {
            return null;
        }

        $svgContent = $this->config['theme']['icons'][$iconName];
        $svgContentClean = preg_replace('/ class="[^"]*"/', '', $svgContent);

        // Ensure a fill or stroke of currentColor for the mask to work
        if (strpos($svgContentClean, 'fill="currentColor"') === false && 
            strpos($svgContentClean, 'stroke="currentColor"') === false &&
            strpos($svgContentClean, 'fill=') === false && 
            strpos($svgContentClean, 'stroke=') === false) {
            // Add fill="currentColor" by default if no fill or stroke is specified
            $svgContentClean = str_replace('<svg ', '<svg fill="currentColor" ', $svgContentClean);
        }

        $encodedSvg = rawurlencode($svgContentClean);
        $dataUri = "url(\"data:image/svg+xml;charset=utf-8,{$encodedSvg}\")";

        // Default styles for an icon class.
        // Users MUST apply sizing utilities like w-5 h-5.
        // These styles aim for better inline alignment with text.
        $styles = [
            'display' => 'inline-block',
            // width and height should be set by utility classes (e.g., w-5 h-5)
            // This ensures the icon scales correctly with those utilities.
            // Setting a default 1em here can conflict if the SVG's viewBox isn't square
            // or if the user expects precise pixel control from w-* h-*.
            'vertical-align' => 'middle', // A common starting point for vertical alignment.        
            '-webkit-mask-image' => $dataUri,
            'mask-image' => $dataUri,
            '-webkit-mask-repeat' => 'no-repeat',
            'mask-repeat' => 'no-repeat',
            '-webkit-mask-size' => 'contain', // Ensures the icon fits within its given w/h box, maintaining aspect ratio
            'mask-size' => 'contain',
            'background-color' => 'currentColor', // This is the color that will "fill" the mask
        ];

        return $styles;
    }
    private function handleStatus(string $baseClassPart, array $matches): ?array {
        $variantString = $matches[1] ?? 'default';
        $parts = explode('-', $variantString);
        
        $styles = [];
        $colorKey = 'neutral';
        $size = 'md';

        // --- ডিফল্ট স্টাইল (মার্বেল ইফেক্ট সহ) ---
        $styles = [
            'display' => 'inline-block',
            'border-radius' => '9999px',
            'vertical-align' => 'middle',
            'border-width' => '1px',
            'border-color' => 'rgba(255, 255, 255, 0.2)',
            'box-shadow' => '0 1px 2px 0 rgba(0, 0, 0, 0.1), inset 0 1px 1px 0 rgba(255, 255, 255, 0.2)',
            'background-image' => 'radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.4), rgba(255, 255, 255, 0) 70%)',
            'box-shadow' => 'inset 0 2px 4px 0 rgba(255, 255, 255, 0.38), inset 0 -2px 4px 0 rgba(0, 0, 0, 0.04)',
        ];

        // --- ক্লাস পার্সিং লজিক ---
        foreach ($parts as $part) {
            if (in_array($part, ['xs', 'sm', 'md', 'lg', 'xl'])) {
                $size = $part;
            } else {
                $colorKey = $part;
            }
        }
        if ($variantString === 'default') $colorKey = 'neutral';

        // --- সাইজ অনুযায়ী স্টাইল ---
        switch ($size) {
            // ... (সাইজের কোড অপরিবর্তিত)
            case 'xs': $styles['width'] = '0.4rem'; $styles['height'] = '0.4rem'; break;
            case 'sm': $styles['width'] = '0.6rem'; $styles['height'] = '0.6rem'; break;
            case 'md': $styles['width'] = '0.8rem'; $styles['height'] = '0.8rem'; break;
            case 'lg': $styles['width'] = '1rem'; $styles['height'] = '1rem'; break;
            case 'xl': $styles['width'] = '1.2rem'; $styles['height'] = '1.2rem'; break;
        }

        // --- অটোমেটিক কালার হ্যান্ডলিং ---
        $colorValue = $this->parseColorValue($colorKey);
        
        // যদি parseColorValue না পায়, তবে থিমের semantic color গুলো চেষ্টা করুন
        if (!$colorValue) {
            $semanticMap = [
                'primary' => 'primary', 'secondary' => 'secondary', 'accent' => 'accent',
                'info' => 'info', 'success' => 'success', 'warning' => 'warning', 'error' => 'destructive',
                'neutral' => 'neutral'
            ];
            if (isset($semanticMap[$colorKey])) {
                 $colorValue = $this->resolveThemeValue(['theme' => 'colors.' . $semanticMap[$colorKey]]);
            }
        }

        if ($colorValue) {
            $styles['background-color'] = $colorValue;
        } else {
            // ফলব্যাক কালার
            $styles['background-color'] = '#808080'; // Gray
        }

        return ['layer' => 'components', 'style' => $styles];
    }
    private function handleStrokeDashOffset(string $baseClassPart, array $matches): ?array { $val = $this->parseNumericValue($matches[1], '', ['numericIsRaw' => true, 'allowArbitrary' => true]); return $val ? ['stroke-dashoffset' => $val] : null;}
    private function handleAccessibility(string $classPart, array $matches): ?array { 
        if ($classPart === 'sr-only' || $classPart === 'screen-reader-only') { 
            return ['position' => 'absolute', 'width' => '1px', 'height' => '1px', 'padding' => '0', 'margin' => '-1px', 'overflow' => 'hidden', 'clip' => 'rect(0, 0, 0, 0)', 'white-space' => 'nowrap', 'border-width' => '0']; 
        } elseif ($classPart === 'not-sr-only') { 
            return ['position' => 'static', 'width' => 'auto', 'height' => 'auto', 'padding' => '0', 'margin' => '0', 'overflow' => 'visible', 'clip' => 'auto', 'white-space' => 'normal']; 
        } 
        return null;
    }
    
    private function handleForcedColorAdjust(string $baseClassPart, array $matches): ?array { 
        return ['forced-color-adjust' => $matches[1]]; 
    }

    private function handleScrollReveal(string $baseClassPart, array $matches): ?array {
        $type = $matches[1];
        $kfName = "reveal-scroll-{$type}";
        
        $styles = match($type) {
            'fade'  => ['from' => 'opacity: 0;', 'to' => 'opacity: 1;'],
            'up'    => ['from' => 'opacity: 0; transform: translateY(30px);', 'to' => 'opacity: 1; transform: translateY(0);'],
            'down'  => ['from' => 'opacity: 0; transform: translateY(-30px);', 'to' => 'opacity: 1; transform: translateY(0);'],
            'left'  => ['from' => 'opacity: 0; transform: translateX(30px);', 'to' => 'opacity: 1; transform: translateX(0);'],
            'right' => ['from' => 'opacity: 0; transform: translateX(-30px);', 'to' => 'opacity: 1; transform: translateX(0);'],
        };

        $this->neededKeyframes[$kfName] = "{ from { {$styles['from']} } to { {$styles['to']} } }";
        
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $css = "{$selector} { animation: {$kfName} linear both; animation-timeline: view(); animation-range: entry 10% cover 30%; }";
        return ['layer' => 'utilities', 'style' => $css];
    }
    private function handleCaretColor(string $baseClassPart, array $matches): ?array {
        $colorValue = $this->parseColorValue($matches[1]);
        return $colorValue ? ['caret-color' => $colorValue] : null;
    }

    private function handleHyphens(string $baseClassPart, array $matches): ?array {
        return ['hyphens' => $matches[1], '-webkit-hyphens' => $matches[1]];
    }

    private function handleAspectRatio(string $classPart, array $matches): ?array {
        $valuePart = $matches[1];
        // 1. Check for predefined keywords in theme
        if (isset($this->config['theme']['aspectRatio'][$valuePart])) {
            return ['aspect-ratio' => $this->config['theme']['aspectRatio'][$valuePart]];
        }
        // 2. Handle arbitrary values like [16/9]
        if (preg_match('/^\[(.+)\]$/', $valuePart, $arbitraryMatch)) {
            $val = str_replace('_', ' ', $arbitraryMatch[1]);
            // Validate it's a ratio (e.g., 1/1) or numeric
            if (preg_match('/^(\d+(\.\d+)?)\s*\/\s*(\d+(\.\d+)?)$/', $val) || is_numeric($val) || strpos($val, 'var(') === 0) {
                return ['aspect-ratio' => $val];
            }
        }
        return null;
    }
    
    private function handleDynamicPropertyRegistration(string $baseClassPart, array $matches): ?array {
        $configString = $matches[1];

        // সিনট্যাক্স: --name:syntax|initial-value|inherits
        // উদাহরণ: property-[--bg-hue:angle|0deg|true]

        $parts = explode(':', $configString, 2);
        if (count($parts) !== 2) return null; // ভুল সিনট্যাক্স

        $propertyName = trim($parts[0]);
        if (!str_starts_with($propertyName, '--')) return null; // অবশ্যই কাস্টম প্রপার্টি হতে হবে

        $propertyConfig = [];
        $values = explode('|', $parts[1]);

        if (isset($values[0])) $propertyConfig['syntax'] = '"' . trim($values[0]) . '"';
        if (isset($values[1])) $propertyConfig['initial-value'] = trim($values[1]);
        if (isset($values[2])) $propertyConfig['inherits'] = trim($values[2]) === 'true' ? 'true' : 'false';

        // ডিফল্ট মান সেট করা
        if (!isset($propertyConfig['inherits'])) $propertyConfig['inherits'] = 'false';

        // neededProperties অ্যারেতে রেজিস্টার করুন
        $this->neededProperties[substr($propertyName, 2)] = $propertyConfig;

        // এই ক্লাসটি কোনো ভিজ্যুয়াল CSS তৈরি করেবিধা করে না, শুধু @property রেজিস্টার করে।
        // তাই আমরা একটি খালি স্টাইল অ্যারে রিটার্ন করব।
        return ['style' => []];
    }

    private function handleArbitraryContent(string $baseClassPart, array $matches): ?array {
        // Specifically for content-['...']
        $content = str_replace('_', ' ', $matches[1]);
        return ['content' => '"' . str_replace('"', '\\"', $content) . '"'];
    }

    private function handleArbitraryProperty(string $baseClassPart, array $matches): ?array {
        $variant = $matches[1] ?? null;
        $content = $matches[2];

        list($prop, $rawVal) = explode(':', $content, 2);
        $prop = trim($prop);
        $rawVal = trim($rawVal);

        if ($rawVal === '') return null;

        // --- Special check for content ---
        if ($prop === 'content') {
             // Remove wrapping quotes if present
             $cleanVal = trim($rawVal, "'\"");
             // Replace underscores with spaces
             $cleanVal = str_replace('_', ' ', $cleanVal);
             return ['content' => '"' . str_replace('"', '\\"', $cleanVal) . '"'];
        }

        // --- চূড়ান্ত এবং উন্নত `_` (আন্ডারস্কোর) হ্যান্ডলিং ---
        $length = strlen($rawVal);
        $processedVal = '';
        $inUrl = false;
        $parenDepth = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $rawVal[$i];

            // url(...) ফাংশনের শুরু এবং শেষ ট্র্যাক করা
            if (substr($rawVal, $i, 4) === 'url(') {
                $inUrl = true;
                $parenDepth = 1;
                $processedVal .= 'url(';
                $i += 3;
                continue;
            }
            
            if ($inUrl) {
                if ($char === '(') $parenDepth++;
                if ($char === ')') $parenDepth--;
                if ($parenDepth === 0) $inUrl = false;
            }

            // যদি আন্ডারস্কোর হয় এবং আমরা url()-এর ভেতরে না থাকি, তাহলে এটিকে স্পেসে পরিণত করুন
            if ($char === '_' && !$inUrl) {
                $processedVal .= ' ';
            } else {
                $processedVal .= $char;
            }
        }
        // --- শেষ হ্যান্ডলিং ---

        // --- কাস্টম টোকেনাইজার (এখন $processedVal-এর উপর কাজ করবে) ---
        // (আপনার বিদ্যমান টোকেনাইজার কোডটি এখানে অপরিবর্তিত থাকবে)
        $length = strlen($processedVal);
        $resolvedVal = '';
        $i = 0;

        while ($i < $length) {
            $char = $processedVal[$i];
            
            if (substr($processedVal, $i, 6) === 'theme(') {
                $start = $i + 6;
                $end = strpos($processedVal, ')', $start);
                if ($end !== false) {
                    $path = trim(substr($processedVal, $start, $end - $start), "'\"");
                    
                    $resolved = $this->resolveThemeValue(['theme' => $path]);
                    
                    $depth = 0;
                    while(is_array($resolved) && isset($resolved['theme']) && $depth < 10) {
                        $resolved = $this->resolveThemeValue($resolved);
                        $depth++;
                    }
                    
                    if (is_array($resolved) && isset($resolved[0])) $resolved = $resolved[0];
                    
                    $resolvedVal .= is_string($resolved) ? $resolved : substr($processedVal, $i, $end - $i + 1);
                    $i = $end + 1;
                    continue;
                }
            }
            
            $resolvedVal .= $char;
            $i++;
        }
        
        $styles = [];

        // --- ধাপ ৩: কম্পোজেবল প্রপার্টি এবং চূড়ান্ত স্টাইল তৈরি ---
        if ($prop === 'transform') {
            $styles['--tw-transform-arbitrary'] = $resolvedVal;
            $styles['transform'] = $this->buildTransformFunctionString();
        }
        elseif ($prop === 'filter') {
            $styles['--tw-filter-arbitrary'] = $resolvedVal;
            $styles['filter'] = $this->buildFilterFunctionString();
        } 
        elseif ($prop === 'backdrop-filter') {
            $styles['--tw-backdrop-filter-arbitrary'] = $resolvedVal;
            $styles['backdrop-filter'] = $this->buildBackdropFilterFunctionString();
            $styles['-webkit-backdrop-filter'] = $this->buildBackdropFilterFunctionString();
        } 
        elseif (str_starts_with($prop, 'animation')) {
            if ($prop === 'animation') {
                $parts = explode(' ', $resolvedVal);
                if (count($parts) > 0 && isset($this->config['theme']['keyframes'][$parts[0]])) {
                    $this->neededKeyframes[$parts[0]] = $this->config['theme']['keyframes'][$parts[0]];
                }
                $styles['animation'] = $resolvedVal;
            } else {
                $cssVar = '--tw-' . $prop;
                $styles[$cssVar] = $resolvedVal;
                $styles['animation'] = $this->buildAnimationFunctionString();
            }
        } else {
            // অন্যান্য সব সাধারণ প্রপার্টি
            $styles[$prop] = $resolvedVal;
        }

        // --- ধাপ গ: যদি কোনো ভ্যারিয়েন্ট থাকে, তাহলে pseudo-style হিসেবে রিটার্ন করুন ---
        if ($variant) {
            $pseudoKey = '_' . $variant . 'Styles';
            return [$pseudoKey => $styles];
        }

        // যদি কোনো ভ্যারিয়েন্ট না থাকে, তাহলে সাধারণ স্টাইল হিসেবে রিটার্ন করুন
        return $styles;
    }

    private function handleArbitraryVariantAndProperty(string $baseClassPart, array $matches): ?array {
        $variantPart = $matches[1]; // e.g., &>p or --my-var:red_& or @media...
        $utilityPart = $matches[2]; // e.g., text-red-500

        // ১. ইউটিলিটির জন্য স্টাইল তৈরি করুন
        $utilityStyle = null;
        foreach ($this->utilityHandlers as $handlerConfig) {
            // Avoid infinite recursion
            if ($handlerConfig['handler'] === 'handleArbitraryVariantAndProperty') continue;
            
            if (preg_match($handlerConfig['pattern'], $utilityPart, $utilityMatches)) {
                $handlerMethod = $handlerConfig['handler'];
                $utilityStyle = is_string($handlerMethod) ? $this->$handlerMethod($utilityPart, $utilityMatches, []) : call_user_func($handlerMethod, $utilityPart, $utilityMatches, []);
                if ($utilityStyle !== null) break;
            }
        }

        if ($utilityStyle === null || !is_array($utilityStyle)) {
            return null;
        }

        // ২. ভ্যারিয়েন্ট অনুযায়ী সিলেক্টর বা রুল তৈরি করুন
        $baseSelector = '.' . $this->escapeClassNameForSelector($baseClassPart);

        // কেস: [--my-var:red]_&
        if (str_ends_with($variantPart, '_&')) {
            $rule = rtrim($variantPart, '_&');
            list($prop, $val) = array_map('trim', explode(':', $rule, 2));
            
            $wrapperSelector = '.' . $this->escapeClassNameForSelector($variantPart);
            
            // একটি সম্পূর্ণ CSS ব্লক তৈরি করে সরাসরি আউটপুটে যোগ করুন
            $childCss = $this->buildCssRulesToString([$baseSelector => $utilityStyle]);
            $finalCss = "{$wrapperSelector} { {$prop}: {$val}; }\n";
            $finalCss .= "{$wrapperSelector} {$childCss}"; // Note: This might not be right, selector needs to be specific.
            
            // This approach is complex. A better way:
            // We can't easily modify another class from here.
            // Let's assume the base class is applied to the parent.
            list($prop, $val) = array_map('trim', explode(':', str_replace('_', ' ', rtrim($variantPart, '_&'))));
            return [$prop => $val]; // This will apply the style to the element with the class.
        }
        
        // কেস: [&_p]
        if (str_starts_with($variantPart, '&')) {
            $selectorSuffix = str_replace('_', ' ', substr($variantPart, 1));
            return ['_childSelectorStyles' => [$selectorSuffix => $utilityStyle]];
        }

        // অন্যান্য arbitrary ভ্যারিয়েন্টের জন্য ভবিষ্যতে লজিক যোগ করা যেতে পারে
        
        return null;
    }
    private function handleRingOffset(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1];
        $styles = [];
        
        $colorValue = $this->parseColorValue($valueKey);
        if ($colorValue !== null) {
            $styles['--tw-ring-offset-color'] = $colorValue;
            return $styles;
        }
        
        $widthValue = $this->parseNumericValue($valueKey, 'ringOffsetWidth', ['defaultUnit' => 'px']);
        if ($widthValue !== null) {
            $styles['--tw-ring-offset-width'] = $widthValue;
            return $styles;
        }
        
        return null;
    }

    private function handleRingOpacity(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1];
        $opacityValue = $this->parseNumericValue($valueKey, 'ringOpacity', ['numericIsPercentage' => true]);
        
        if ($opacityValue !== null) {
            return ['--tw-ring-opacity' => (string)$opacityValue];
        }
        return null;
    }

    private function handleRingWidthAndColor(string $baseClassPart, array $matches): ?array {
        $valueKey = $matches[1] ?? 'DEFAULT';
        $styles = $this->handleRingBase();

        $colorValue = $this->parseColorValue($valueKey);
        if ($colorValue !== null) {
            $styles['--tw-ring-color'] = $this->convertColorWithOpacityVar($colorValue, '--tw-ring-opacity');
            // Tailwind's ring-{color} utilities do NOT set a width.
            // A width is only set by 'ring' or 'ring-{width}'.
        } else {
            $widthValue = $this->parseNumericValue($valueKey, 'ringWidth', ['defaultUnit' => 'px']);
            if ($widthValue === null && $valueKey === 'DEFAULT') {
                $widthValue = $this->lookupThemeValue('ringWidth', 'DEFAULT') ?? '3px';
            }

            if ($widthValue !== null) {
                $styles['--tw-ring-width'] = $widthValue;
            } else {
                return null;
            }
        }
        return $styles;
    }

    private function handleRingBase(): array {
        $defaultColor = $this->resolveThemeValue($this->config['theme']['ringColor']['DEFAULT'] ?? ['theme' => 'colors.blue.500'], '#3b82f680');
        
        return [
            '--tw-ring-offset-shadow' => 'var(--tw-ring-inset, /*!*/ /*!*/) 0 0 0 var(--tw-ring-offset-width, 0px) var(--tw-ring-offset-color, #fff)',
            '--tw-ring-shadow' => "var(--tw-ring-inset, /*!*/ /*!*/) 0 0 0 calc(var(--tw-ring-width, 0px) + var(--tw-ring-offset-width, 0px)) var(--tw-ring-color, {$defaultColor})",
            'box-shadow' => 'var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow, 0 0 #0000)',
        ];
    }

    private function handleRingInset(string $baseClassPart, array $matches): ?array {
        return ['--tw-ring-inset' => 'inset'];
    }

    private function handleBootstrapFocusRing(string $baseClassPart, array $matches): ?array {
        $variant = $matches[1] ?? 'primary'; // No color means default to 'primary'
        $width = '0.25rem'; // Bootstrap default
        $opacity = 0.25;

        $colorHex = null;

        // A. Directly look up from theme colors
        $themeColor = $this->lookupThemeValue('colors', $variant);
        
        if (is_array($themeColor)) {
            $colorHex = $themeColor['600'] ?? $themeColor['DEFAULT'] ?? $themeColor['500'] ?? array_values($themeColor)[0] ?? null;
        } elseif (is_string($themeColor)) {
            $colorHex = $themeColor;
        }

        // B. If not in theme, it might be an arbitrary value or hex
        if (!$colorHex) {
            $colorHex = $this->parseColorValue($variant);
        }
        
        // C. Fallback for the base .focus-ring class
        if (!$colorHex && empty($matches[1])) {
            $colorHex = '#0d6efd'; // Bootstrap's default blue
        }

        if (!$colorHex) return null; // Exit if color cannot be resolved
        
        // D. Convert color to RGBA with opacity
        $shadowColor = $this->convertColorToRgba($colorHex, $opacity);
        if (!$shadowColor) {
            return null; // Exit if color conversion fails
        }

        // E. Return the styles under the ':focus' pseudo-class
        return [
            '_focusStyles' => [
                'outline' => 'none',
                'box-shadow' => "0 0 0 {$width} {$shadowColor}",
            ]
        ];
    }

    private function handleGradientDirection(string $baseClassPart, array $matches): ?array {
        $directionKey = $matches[1];
        $directionMap = [
            't' => 'to top', 'tr' => 'to top right', 'r' => 'to right', 'br' => 'to bottom right',
            'b' => 'to bottom', 'bl' => 'to bottom left', 'l' => 'to left', 'tl' => 'to top left',
        ];
        $direction = $directionMap[$directionKey] ?? null;
        if (!$direction) return null;

        // Initialize/reset gradient stop variables for this specific gradient direction utility
        // The from/via/to utilities will populate these.
        return [
            '--tw-gradient-from' => $this->resolveThemeValue(['theme' => 'colors.transparent'], 'transparent') . ' var(--tw-gradient-from-position, )',
            '--tw-gradient-to' => $this->resolveThemeValue(['theme' => 'colors.transparent'], 'transparent') . ' var(--tw-gradient-to-position, )',
            // For multiple via stops, a more complex system is needed.
            // For now, let's assume --tw-gradient-stops will be composed from --tw-gradient-from and --tw-gradient-to by default.
            // And via stops will insert themselves into --tw-gradient-stops.
            '--tw-gradient-stops' => 'var(--tw-gradient-from), var(--tw-gradient-to)',
            'background-image' => "linear-gradient({$direction}, var(--tw-gradient-stops))",
        ];
    }

    private function handleGradientColorStop(string $baseClassPart, array $matches): ?array {
        $type = $matches[1]; // from, via, or to
        $colorAndPositionKey = $matches[2]; // e.g., pink-500, pink-500/50, pink-500-10% or [theme...]-20%
        $opacitySuffix = $matches[3] ?? null; // e.g., 50 (from /50) - this part of regex might need adjustment based on previous versions.

        $colorKey = $colorAndPositionKey;
        $position = null;

        // Extract position like -10% or -[10%] from the end of colorAndPositionKey
        if (preg_match('/(?:-|\/|\[\.?)([0-9]{1,3}%?)]?$/', $colorAndPositionKey, $posMatches)) {
            $matchedPositionString = $posMatches[0];
            // Check if it's an opacity suffix like /50, not a position
            if (strpos($matchedPositionString, '/') !== 0 || ($opacitySuffix !== null && $opacitySuffix == substr($matchedPositionString, 1))) {
                // It was likely an opacity, not a position, or position is handled differently
            } else {
                $positionValue = str_replace(['-', '[', ']'], '', $posMatches[1]); // Clean up 10% or 10
                if(str_ends_with($positionValue, '%')) {
                    $position = $this->lookupThemeValue('gradientColorStopPositions', $positionValue) ?? $positionValue;
                } else if (is_numeric($positionValue) && ($positionValue >=0 && $positionValue <=100)) { // For arbitrary percentage without % sign
                    $position = $positionValue . '%';
                }

                if ($position) {
                    $colorKey = substr($colorAndPositionKey, 0, -strlen($matchedPositionString));
                }
            }
        }
        
        // Re-parse color after potentially stripping position
        $color = $this->parseColorValue($colorKey . ($opacitySuffix ? '/' . $opacitySuffix : ''));
        if (!$color) {
            // Try parsing without opacity suffix if the combined version failed
            $color = $this->parseColorValue($colorKey);
            if (!$color) return null;
        }

        $styles = [];
        $currentGradientStops = [ // Default structure for gradient stops
            'from' => 'var(--tw-gradient-from, transparent'.($position && $type === 'from' ? ' '.$position : '').')',
            'vias' => [], // Array to hold via stops
            'to' => 'var(--tw-gradient-to, transparent'.($position && $type === 'to' ? ' '.$position : '').')'
        ];

        switch ($type) {
            case 'from':
                $styles['--tw-gradient-from'] = $color . ($position ? " {$position}" : '');
                $currentGradientStops['from'] = $styles['--tw-gradient-from'];
                break;
            case 'to':
                $styles['--tw-gradient-to'] = $color . ($position ? " {$position}" : '');
                $currentGradientStops['to'] = $styles['--tw-gradient-to'];
                break;
            case 'via':
                // This is a simplified approach for one 'via'.
                // A full implementation would add to an array of via stops.
                $styles['--tw-gradient-via-1'] = $color . ($position ? " {$position}" : ''); // For a single via
                $currentGradientStops['vias'][] = $styles['--tw-gradient-via-1'];
                break;
            default:
                return null;
        }

        // Reconstruct --tw-gradient-stops based on currently known from, via(s), and to.
        // This logic needs to be robust. When a 'from' class is processed, 'via' and 'to' might not be known yet.
        // The CSS variables ensure that if they are not set, they fall back to transparent or their defaults.

        $fromStop = $styles['--tw-gradient-from'] ?? 'var(--tw-gradient-from, transparent var(--tw-gradient-from-position))';
        $toStop   = $styles['--tw-gradient-to']   ?? 'var(--tw-gradient-to, transparent var(--tw-gradient-to-position))';
        
        // Simplistic reconstruction:
        $stopsArray = [];
        if(isset($styles['--tw-gradient-from'])) $stopsArray[] = $styles['--tw-gradient-from'];
        else $stopsArray[] = 'var(--tw-gradient-from, transparent var(--tw-gradient-from-position, 0%))';

        // If via stops are defined, they should replace the direct path from 'from' to 'to'.
        // This logic needs to be smarter if we are to support multiple `via` stops correctly
        // and have them ordered by their positions.
        if (isset($styles['--tw-gradient-via-1'])) {
            $stopsArray[] = $styles['--tw-gradient-via-1'];
        }
        // Add other via stops if supporting multiple via classes: --tw-gradient-via-2, etc.

        if(isset($styles['--tw-gradient-to'])) $stopsArray[] = $styles['--tw-gradient-to'];
        else $stopsArray[] = 'var(--tw-gradient-to, transparent var(--tw-gradient-to-position, 100%))';
        
        $styles['--tw-gradient-stops'] = implode(', ', $stopsArray);

        
        return $styles;
    }

    private function handleGradientColorStopPosition(string $baseClassPart, array $matches): ?array {
        $type = $matches[1]; // from, via, to
        $positionValue = $matches[2] . '%'; // e.g. 10%
        $position = $this->lookupThemeValue('gradientColorStopPositions', $positionValue) ?? $positionValue;
        if (!$position) return null;

        $varName = '';
        switch($type) {
            case 'from': $varName = '--tw-gradient-from-position'; break;
            case 'to':   $varName = '--tw-gradient-to-position';   break;
            case 'via':  $varName = '--tw-gradient-via-1-position'; break; // Simplified for one via
            default: return null;
        }
        // This utility only sets the position variable. The color variable should be set by another class.
        // The final --tw-gradient-stops needs to be rebuilt if these are used dynamically.
        // This is complex to manage without knowing all classes on an element at once.
        return [$varName => $position];
    }

    private function handleGradientBlobs(string $baseClassPart, array $matches): ?array {
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $cssString = "";
        $cssString .= "/* Base container for animated gradient blobs */\n";
        $cssString .= "{$selector} {\n";
        $cssString .= "  position: absolute; inset: 0;\n";
        $cssString .= "  overflow: hidden;\n";
        $cssString .= "  pointer-events: none;\n";
        $cssString .= "  filter: blur(50px);\n";
        $cssString .= "}\n\n";
        $cssString .= "/* Common styles for all direct child blobs */\n";
        $cssString .= "{$selector} > div {\n";
        $cssString .= "  position: absolute;\n";
        $cssString .= "  border-radius: 9999px; /* full rounded */\n";
        $cssString .= "  mix-blend-mode: plus-lighter;\n";
        $cssString .= "  will-change: transform;\n";
        $cssString .= "  opacity: 0.7;\n";
        $cssString .= "}\n\n";
        return ['layer' => 'components', 'style' => trim($cssString)];
    }


    private function handleGradientBackground(string $baseClassPart, array $matches): ?array {
        $presetName = $matches[1] ?? null;
        $colorName = $matches[2] ?? null;
        $directionKey = $matches[3] ?? null;

        $fromColor = null;
        $viaColor = null;
        $toColor = null;
        $direction = 'to bottom';

        if ($presetName) {
            $gradientConfig = $this->lookupThemeValue('gradients', $presetName);
            if (!$gradientConfig) return null;

            $fromColor = $this->resolveThemeValue($gradientConfig['from']);
            $viaColor = $this->resolveThemeValue($gradientConfig['via'] ?? null);
            $toColor = $this->resolveThemeValue($gradientConfig['to']);

        } elseif ($colorName) {
            $fromColor = $this->parseColorValue($colorName);
            if (!$fromColor) return null;
            $toColor = $this->convertColorToRgba($fromColor, 0);
            if ($toColor === null) {
                $toColor = 'transparent';
            }

        } else {
            return null;
        }

        if ($directionKey) {
            $directionMap = [ 't' => 'to top', 'tr' => 'to top right', 'r' => 'to right', 'br' => 'to bottom right', 'b' => 'to bottom', 'bl' => 'to bottom left', 'l' => 'to left', 'tl' => 'to top left' ];
            if (isset($directionMap[$directionKey])) {
                $direction = $directionMap[$directionKey];
            } elseif (str_ends_with($directionKey, 'deg')) {
                $direction = $directionKey;
            }
        }

        $styles = [
            '--tw-gradient-from' => "{$fromColor} var(--tw-gradient-from-position)",
            '--tw-gradient-to' => "{$toColor} var(--tw-gradient-to-position)",
        ];

        $stops = ['var(--tw-gradient-from)'];
        if ($viaColor) {
            $styles['--tw-gradient-via-1'] = "{$viaColor} var(--tw-gradient-via-1-position)";
            $stops[] = 'var(--tw-gradient-via-1)';
        }
        $stops[] = 'var(--tw-gradient-to)';
        
        $styles['--tw-gradient-stops'] = implode(', ', $stops);
        $styles['background-image'] = "linear-gradient({$direction}, var(--tw-gradient-stops))";

        return $styles;
    }

    private function handleVisibility(string $baseClassPart, array $matches): ?array {
        if ($baseClassPart === 'visible') {
            return ['visibility' => 'visible'];
        } elseif ($baseClassPart === 'invisible') {
            return ['visibility' => 'hidden'];
        }
        return null;
    }

    private function handleDecorationOpacity(string $baseClassPart, array $matches): ?array {
        $opacity = (float)$matches[1];
        $decimalOpacity = $opacity / 100;
        return ['--tw-decoration-opacity' => (string)$decimalOpacity];
    }

    private function handleUnderlineOffset(string $baseClassPart, array $matches): ?array {
        $offsetKey = $matches[1];

        $map = [
            '1' => '0.125em',
            '2' => '0.25em',
            '3' => '0.375em',
        ];

        $value = $map[$offsetKey] ?? null;

        if ($value === null && is_numeric($offsetKey)) {
            $value = $offsetKey . 'px';
        }

        return $value ? ['text-underline-offset' => $value] : null;
    }

    private function getThemeCssVariables(): string {
        $css = "";
        $presets = $this->config['presets'] ?? [];

        // Helper to extract HSL components "H S% L% [/ A]" from a full hsl() or hsla() string
        $extractHslComponentsFromString = function(string $hslFunctionString): ?string {
            if (preg_match('/^hsla?\(\s*([\d.]+)\s*,\s*([\d.]+%)\s*,\s*([\d.]+%)(?:\s*,\s*([0-9.]+))?\s*\)$/i', $hslFunctionString, $matches) ||
                preg_match('/^hsla?\(\s*([\d.]+)\s+([\d.]+%)\s+([\d.]+%)(?:\s*\/\s*([0-9.]+))?\s*\)$/i', $hslFunctionString, $matches)
            ){
                $h = $matches[1]; $s = $matches[2]; $l = $matches[3]; $a = $matches[4] ?? null;
                return trim("{$h} {$s} {$l}" . ($a !== null ? " / {$a}" : ""));
            }
            return null;
        };

        // --- ১. Default Theme (Light Theme) ---
        // এখানে আমরা :root এর পাশাপাশি data-theme='light' যুক্ত করে দিয়েছি
        if (isset($presets['default'])) {
            $css .= ":root, [data-theme='light'], [data-theme='default'] {\n";
            foreach ($presets['default'] as $varName => $valueConfig) {
                $resolvedValue = null;
                if (isset($valueConfig['raw']) && is_string($valueConfig['raw'])) {
                    if (preg_match('/^[\d.]+ [\d.]+% [\d.]+%(?:\s*\/\s*[\d.]+)?$/', $valueConfig['raw'])) {
                        $resolvedValue = $valueConfig['raw'];
                    } elseif (($components = $extractHslComponentsFromString($valueConfig['raw'])) !== null) {
                        $resolvedValue = $components;
                    } else {
                        $resolvedValue = $this->convertColorToHslComponentsString($valueConfig['raw'], null);
                    }
                } elseif (isset($valueConfig['theme'])) {
                    $resolvedValue = $this->resolveThemeValue($valueConfig, null, false, null, true);
                } elseif (is_string($valueConfig)){
                    $css .= "  {$varName}: {$valueConfig};\n";
                    continue;
                }
                if ($resolvedValue && is_string($resolvedValue)) $css .= "  {$varName}: {$resolvedValue};\n";
            }
            $css .= "}\n\n";
        }

        // --- ২. Other Themes (Dark, Cyberpunk, Dracula, etc.) ---
        foreach ($presets as $themeName => $themeVars) {
            if ($themeName === 'default' || !is_array($themeVars)) continue;

            // এখানে ক্লিনভাবে match ব্যবহার করা হলো
            $selector = match($themeName) {
                'dark' => "html.dark, [data-theme='dark'], [data-mode='dark']",
                default => "html.theme-{$themeName}, [data-theme='{$themeName}']"
            };
            
            $css .= "{$selector} {\n";
            foreach ($themeVars as $varName => $valueConfig) {
                $resolvedValue = null;
                if (isset($valueConfig['raw']) && is_string($valueConfig['raw'])) {
                    if (preg_match('/^[\d.]+ [\d.]+% [\d.]+%(?:\s*\/\s*[\d.]+)?$/', $valueConfig['raw'])) {
                        $resolvedValue = $valueConfig['raw'];
                    } elseif (($components = $extractHslComponentsFromString($valueConfig['raw'])) !== null) {
                        $resolvedValue = $components;
                    } else {
                        $resolvedValue = $this->convertColorToHslComponentsString($valueConfig['raw'], null, ($themeName === 'dark'));
                    }
                } elseif (isset($valueConfig['theme'])) {
                    $resolvedValue = $this->resolveThemeValue($valueConfig, null, ($themeName === 'dark'), null, true);
                } elseif (is_string($valueConfig)){
                    $css .= "  {$varName}: {$valueConfig};\n";
                    continue;
                }
                if ($resolvedValue && is_string($resolvedValue)) $css .= "  {$varName}: {$resolvedValue};\n";
            }
            $css .= "}\n\n";
        }
        
        return $css;
    }
    
    private function handleSpaceBetween(string $baseClassPart, array $matches): ?array {
        $axis = $matches[1]; // 'x' or 'y'
        $valueKey = $matches[2];

        $cssValue = $this->parseNumericValue($valueKey, 'spacing', ['allowNegative' => true]);
        if ($cssValue === null || $cssValue === '0' || $cssValue === '0px') {
            return null;
        }
        
        // Create a special key that indicates this style should be applied to child elements
        // The key includes the selector for clarity, to be handled by parseClass
        $childSelector = '> :not([hidden]) ~ :not([hidden])';

        if ($axis === 'x') {
            return [
                '_childSelectorStyles' => [
                    $childSelector => [
                        '--tw-space-x-reverse' => '0',
                        'margin-right' => "calc({$cssValue} * var(--tw-space-x-reverse))",
                        'margin-left' => "calc({$cssValue} * calc(1 - var(--tw-space-x-reverse)))",
                    ]
                ]
            ];
        } elseif ($axis === 'y') {
            return [
                '_childSelectorStyles' => [
                    $childSelector => [
                        '--tw-space-y-reverse' => '0',
                        'margin-top' => "calc({$cssValue} * calc(1 - var(--tw-space-y-reverse)))",
                        'margin-bottom' => "calc({$cssValue} * var(--tw-space-y-reverse))",
                    ]
                ]
            ];
        }
        return null;
    }

    private function handleSpaceReverse(string $baseClassPart, array $matches): ?array {
        $axis = $matches[1]; // 'x' or 'y'
        
        $childSelector = '> :not([hidden]) ~ :not([hidden])';

        if ($axis === 'x') {
            return ['_childSelectorStyles' => [$childSelector => ['--tw-space-x-reverse' => '1']]];
        } elseif ($axis === 'y') {
            return ['_childSelectorStyles' => [$childSelector => ['--tw-space-y-reverse' => '1']]];
        }
        return null;
    }

    private function handleBootstrapDisplay(string $baseClassPart, array $matches): ?array {
        $breakpoint = $matches[1] ?? ''; // sm, md, lg, print...
        $displayValue = $matches[2];     // none, block...

        $cssDisplay = ($displayValue === 'none') ? 'none' : $displayValue;
        $style = ['display' => $cssDisplay];

        // 1. Print Media Query
        if ($breakpoint === 'print') {
            return [
                'layer' => 'utilities',
                'query' => '@media print',
                'style' => $style
            ];
        }

        // 2. Responsive Breakpoints
        if ($breakpoint && isset($this->config['theme']['screens'][$breakpoint])) {
            $screen = $this->config['theme']['screens'][$breakpoint];
            return [
                'layer' => 'utilities',
                'query' => "@media (min-width: {$screen})",
                'style' => $style
            ];
        }

        // 3. Base Style (No breakpoint)
        return $style;
    }

    private function handleBackgroundImage(string $baseClassPart, array $matches): ?array {
        $urlValue = null;
        // --- Method 1: Check for the new `bg-img['...']` syntax ---
        // The pattern is '/^bg-img\[([\'"]?)(.+?)\1\]$/'
        if (str_starts_with($baseClassPart, 'bg-img[')) {
            // $matches[2] will contain the URL without quotes.
            if (isset($matches[2])) {
                $urlValue = $matches[2];
            } else { // Fallback parsing if regex groups fail (should not happen with correct regex)
                $content = substr($baseClassPart, strlen('bg-img['), -1);
                $urlValue = trim($content, '\'"');
            }
        }
        
        // --- Method 2: Check for standard Tailwind `bg-[url(...)]` syntax ---
        // The pattern is '/^bg-\[url\(([\'"]?)(?P<url>.+?)\1\)\]$/'
        elseif (str_starts_with($baseClassPart, 'bg-[url(')) {
            // The named capture group 'url' holds the value.
            if (isset($matches['url'])) {
                $urlValue = $matches['url'];
            }
        }

        // --- Generate CSS if a URL was successfully extracted ---
        if ($urlValue !== null && trim($urlValue) !== '') {
            // Sanitize any single quotes that might be inside the URL itself, just to be safe
            $sanitizedUrl = str_replace("'", "\\'", $urlValue);
            
            return ['background-image' => "url('{$sanitizedUrl}')"];
        }

        return null;
    }

    private function handleBackgroundProperties(string $baseClassPart, array $matches): ?array {
        $valuePart = $baseClassPart; // For this handler, the whole class part is the value

        // Size Keywords
        $bgSizeMap = ['bg-auto' => 'auto', 'bg-cover' => 'cover', 'bg-contain' => 'contain'];
        if (isset($bgSizeMap[$valuePart])) {
            return ['background-size' => $bgSizeMap[$valuePart]];
        }

        // Position Keywords
        $bgPositionMap = [
            'bg-bottom' => 'bottom', 'bg-center' => 'center', 'bg-left' => 'left', 
            'bg-right' => 'right', 'bg-top' => 'top',
            'bg-left-bottom' => 'left bottom', 'bg-left-top' => 'left top',
            'bg-right-bottom' => 'right bottom', 'bg-right-top' => 'right top',
        ];
        if (isset($bgPositionMap[$valuePart])) {
            return ['background-position' => $bgPositionMap[$valuePart]];
        }
        
        // Repeat Keywords
        $bgRepeatMap = [
            'bg-repeat' => 'repeat', 'bg-no-repeat' => 'no-repeat', 
            'bg-repeat-x' => 'repeat-x', 'bg-repeat-y' => 'repeat-y',
            'bg-repeat-round' => 'round', 'bg-repeat-space' => 'space',
        ];
        if (isset($bgRepeatMap[$valuePart])) {
            return ['background-repeat' => $bgRepeatMap[$valuePart]];
        }

        // Attachment Keywords
        $bgAttachmentMap = ['bg-fixed' => 'fixed', 'bg-local' => 'local', 'bg-scroll' => 'scroll'];
        if (isset($bgAttachmentMap[$valuePart])) {
            return ['background-attachment' => $bgAttachmentMap[$valuePart]];
        }

        return null; // Not a recognized background property keyword
    }

    // --- Background Clip & Text Transparent ---
    private function handleBackgroundClip(string $baseClassPart, array $matches): ?array {
        $value = $matches[1]; // border, padding, content, text
        $propertyValue = $value === 'text' ? 'text' : $value . '-box';
        if ($value === 'text') {
            return [
                '-webkit-background-clip' => 'text', // Vendor prefix for wider compatibility
                'background-clip' => 'text'
            ];
        }
        return ['background-clip' => $propertyValue];
    }

    private function handleTextTransparent(string $baseClassPart, array $matches): ?array {
        return ['color' => 'transparent'];
    }

    private function handleBorderCollapse(string $baseClassPart, array $matches): ?array {
        $value = $matches[1]; // 'collapse' or 'separate'
        if ($value === 'collapse') {
             // For border-collapse, also reset border-spacing variables to ensure consistency
            return [
                'border-collapse' => 'collapse',
                '--tw-border-spacing-x' => '0',
                '--tw-border-spacing-y' => '0',
                'border-spacing' => 'var(--tw-border-spacing-x, 0) var(--tw-border-spacing-y, 0)',
            ];
        }
        return ['border-collapse' => 'separate'];
    }

    private function handleBorderSpacing(string $baseClassPart, array $matches): ?array {
        $axis = $matches[1] ? rtrim($matches[1], '-') : null; // 'x' or 'y' or null
        $valueKey = $matches[2];

        $cssValue = $this->parseNumericValue($valueKey, 'spacing');
        if ($cssValue === null) {
            return null;
        }

        $styles = [];
        
        // This is the modern Tailwind approach using CSS variables
        if ($axis === 'x') {
            $styles['--tw-border-spacing-x'] = $cssValue;
        } elseif ($axis === 'y') {
            $styles['--tw-border-spacing-y'] = $cssValue;
        } else {
            // If no axis is specified, set both
            $styles['--tw-border-spacing-x'] = $cssValue;
            $styles['--tw-border-spacing-y'] = $cssValue;
        }
        
        // Always define the final `border-spacing` property using the variables.
        // This ensures that if a user has `border-spacing-x-4` and `border-spacing-y-8`,
        // both are respected and composed into the final property.
        // We set default fallbacks for the variables in case only one axis is defined.
        $styles['border-spacing'] = 'var(--tw-border-spacing-x, 0) var(--tw-border-spacing-y, 0)';

        return $styles;
    }

    private function handleTableLayout(string $baseClassPart, array $matches): ?array {
        $value = $matches[1]; // 'auto' or 'fixed'
        return ['table-layout' => $value];
    }

    private function handleCaptionSide(string $baseClassPart, array $matches): ?array {
        $value = $matches[1]; // 'top' or 'bottom'
        return ['caption-side' => $value];
    }
    
    private function handleEmptyCells(string $baseClassPart, array $matches): ?array {
        $value = $matches[1]; // 'show', 'hide', or 'inherit'
        return ['empty-cells' => $value];
    }

    private function handleListStyleType(string $baseClassPart, array $matches): ?array {
        $value = $matches[1]; // e.g., 'disc', 'decimal', 'upper-roman'
        
        // Tailwind uses 'list-alpha' for 'lower-alpha' in some versions,
        // but explicit names are clearer. Here we support many common types.
        $validTypes = [
            'none', 'disc', 'decimal', 'circle', 'square', 'georgian', 'armenian',
            'cjk-ideographic', 'hebrew', 'hiragana', 'katakana', 'hiragana-iroha',
            'katakana-iroha', 'lower-alpha', 'lower-greek', 'lower-latin',
            'lower-roman', 'upper-alpha', 'upper-latin', 'upper-roman'
        ];

        if (in_array($value, $validTypes)) {
            return ['list-style-type' => $value];
        }

        return null;
    }

    private function handleListStylePosition(string $baseClassPart, array $matches): ?array {
        $value = $matches[1]; // 'inside' or 'outside'
        return ['list-style-position' => $value];
    }

    private function handleListStyleImage(string $baseClassPart, array $matches): ?array {
        $value = $matches[1]; // 'none' or the arbitrary url part `[url(...)]`
        
        if ($value === 'none') {
            return ['list-style-image' => 'none'];
        }

        // Check for arbitrary URL format: [url(path/to/image.png)]
        if (preg_match('/^\[url\((.+)\)\]$/', $value, $urlMatches)) {
            $url = $urlMatches[1];
            // It's good practice to wrap the URL in quotes if it's not already.
            $formattedUrl = "url({$url})";
            return ['list-style-image' => $formattedUrl];
        }

        return null;
    }

    private function parseColorValue(string $value): ?string {
        // Priority 0: Direct keywords that don't need further processing or special var handling
        if (in_array(strtolower($value), ['inherit', 'currentcolor', 'transparent'])) {
            return strtolower($value);
        }

        // Priority 1: Arbitrary value in brackets: color-[...]
        if (preg_match('/^\[(.+)\]$/', $value, $matches)) {
            $arbitraryContent = $matches[1];
            $arbitraryColor = str_replace('_', ' ', $arbitraryContent); // Allow space with underscore

            // 1a. Arbitrary theme reference: [theme(colors.blue.500)] or [theme(colors.blue.500)/50]
            if (preg_match("/^theme\((['\"]?)colors\.([^'\"]+)\1\)(?:\/(?:(\d{1,3})(?!%)|\[\.?(\d+)\]))?$/", $arbitraryColor, $themeMatches)) {
                $colorKeyFromTheme = str_replace('-', '.', $themeMatches[2]);
                $baseColor = $this->lookupThemeValue('colors', $colorKeyFromTheme);

                if (is_string($baseColor)) {
                    $opacityValRawArb = $themeMatches[3] ?? $themeMatches[4] ?? null;
                    if ($opacityValRawArb !== null) {
                        $opacityArb = (isset($themeMatches[4]))
                            ? (float)("0." . $opacityValRawArb) // For /[.5]
                            : (intval($opacityValRawArb) / 100);   // For /50
                        $opacityArb = round(max(0, min(1, $opacityArb)), 2);
                        return $this->convertColorToRgba($baseColor, $opacityArb);
                    }
                    return $baseColor; // Return base color if no opacity defined in arbitrary theme ref
                }
                return null; // Theme color not found
            }

            // 1b. CSS variable usage inside brackets (direct or with hsl/hsla/rgb/rgba)
            if (preg_match('/^var\((--[a-zA-Z0-9-]+)\)$/', $arbitraryColor, $varMatches)) return $arbitraryColor; // e.g. [var(--my-color)]
            if (preg_match('/^hsl\(var\((--[a-zA-Z0-9-]+)\)\)$/', $arbitraryColor, $hslVarMatches)) return $arbitraryColor; // e.g. [hsl(var(--primary))]
            if (preg_match('/^hsla\(var\((--[a-zA-Z0-9-]+)\),\s*([0-9.]+)\)$/', $arbitraryColor, $hslaVarMatches)) return $arbitraryColor; // e.g. [hsla(var(--primary),0.5)]
            if (preg_match('/^rgba?\(var\((--[a-zA-Z0-9-]+)\)(?:,\s*([0-9.]+))?\)$/', $arbitraryColor, $rgbVarMatches)) { // e.g. [rgb(var(--primary-rgb))] or [rgba(var(--primary-rgb),0.5)]
                if (isset($rgbVarMatches[2])) {
                    return "rgba(var({$rgbVarMatches[1]}), {$rgbVarMatches[2]})";
                }
                return "rgb(var({$rgbVarMatches[1]}))";
            }

            // 1c. Standard color formats inside brackets, potentially with opacity shorthand
            // Examples: [#fff], [rgb(0,0,0)], [hsl(0,0%,100%)], [#fff/30], [rgb(0,0,0)/.7]
            if (preg_match('/^(.+?)(?:\/(?:(\d{1,3})(?!%)|\[\.?(\d+)\]))$/', $arbitraryColor, $opacityInArbitraryMatches)) {
                $colorPartInArbitrary = $opacityInArbitraryMatches[1];
                $opacityValRawInArbitrary = $opacityInArbitraryMatches[2] ?? $opacityInArbitraryMatches[3] ?? null;
                $opacityInArbitrary = null;

                if ($opacityValRawInArbitrary !== null) {
                    $opacityInArbitrary = (isset($opacityInArbitraryMatches[3]))
                        ? (float)("0." . $opacityValRawInArbitrary)
                        : (intval($opacityValRawInArbitrary) / 100);
                    $opacityInArbitrary = round(max(0, min(1, $opacityInArbitrary)), 2);
                }

                if (preg_match('/^(#|rgb|rgba|hsl|hsla)/', $colorPartInArbitrary) || in_array(strtolower($colorPartInArbitrary), $this->getValidCssColorKeywords())) {
                    if ($opacityInArbitrary !== null) {
                        return $this->convertColorToRgba($colorPartInArbitrary, $opacityInArbitrary);
                    }
                    return $colorPartInArbitrary; // No opacity shorthand, use color as is
                }
            } else {
                // No opacity shorthand, check for standard color formats
                if (preg_match('/^(#|rgb|rgba|hsl|hsla)/', $arbitraryColor) || in_array(strtolower($arbitraryColor), $this->getValidCssColorKeywords())) {
                    return $arbitraryColor;
                }
            }
            return null; // Unrecognized arbitrary format if not matched above
        }

        // Priority 2: Direct CSS variable usage OR HSL/RGB with CSS variable (outside brackets)
        if (preg_match('/^var\((--[a-zA-Z0-9-]+)\)$/', $value, $matches)) return $value;
        if (preg_match('/^hsl\(var\((--[a-zA-Z0-9-]+)\)\)$/', $value, $matches)) return "hsl(var({$matches[1]}))";
        if (preg_match('/^hsla\(var\((--[a-zA-Z0-9-]+)\),\s*([0-9.]+)\)$/', $value, $matches)) return "hsla(var({$matches[1]}), {$matches[2]})";
        if (preg_match('/^rgba?\(var\((--[a-zA-Z0-9-]+)\)(?:,\s*([0-9.]+))?\)$/', $value, $matches)) {
            return isset($matches[2]) ? "rgba(var({$matches[1]}), {$matches[2]})" : "rgb(var({$matches[1]}))";
        }

        // Priority 3: Theme color name with optional opacity shorthand (e.g., red-500/50, primary/75)
        // Regex updated to be more specific for color names before the slash
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9.-]*)(?:\/(?:(\d{1,3})(?!%)|\[\.?(\d+)\]))$/', $value, $opacityMatches)) {
            $colorKey = $opacityMatches[1];
            $opacityValRaw = $opacityMatches[2] ?? $opacityMatches[3] ?? null;
            $opacity = null;

            if ($opacityValRaw !== null) {
                $opacity = (isset($opacityMatches[3]))
                    ? (float)("0." . $opacityValRaw)
                    : (intval($opacityValRaw) / 100);
                $opacity = round(max(0, min(1, $opacity)), 2);
            }
            
            $baseColor = $this->lookupThemeValue('colors', $colorKey);
            
            if (is_string($baseColor)) {
                if ($opacity !== null) {
                    // If baseColor is already a CSS variable like "hsl(var(--primary))", append alpha for hsla
                    if (strpos($baseColor, 'var(') === 0 && preg_match('/^hsl\(var\((--[a-zA-Z0-9-]+)\)\)$/', $baseColor, $varMatch)) {
                        return "hsla(var({$varMatch[1]}), {$opacity})";
                    }
                    if (strpos($baseColor, 'var(') === 0 && preg_match('/^rgb\(var\((--[a-zA-Z0-9-]+)\)\)$/', $baseColor, $varMatch)) {
                        return "rgba(var({$varMatch[1]}), {$opacity})";
                    }
                    // Otherwise, try to convert to rgba with the new alpha
                    $converted = $this->convertColorToRgba($baseColor, $opacity);
                    // Fallback to baseColor if conversion fails but opacity was explicitly requested.
                    // This might result in just the base color if it's a keyword that can't be rgba-fied with alpha.
                    return $converted ?? $baseColor; 
                }
                return $baseColor; // Return color as is if no opacity shorthand
            }
            return null; // Color key not found in theme
        }
        
        // Priority 4: Handle direct theme color lookup (e.g., red-500, primary)
        $themeColor = $this->lookupThemeValue('colors', $value);
        if (is_string($themeColor)) {
            return $themeColor; // Could be hex, rgb, hsl, or hsl(var(--...))
        }

        // Priority 5: Handle CSS color keywords (e.g., red, blue)
        // (transparent, currentcolor, inherit are handled at the top)
        if (in_array(strtolower($value), $this->getValidCssColorKeywords()) && 
            !in_array(strtolower($value), ['inherit', 'currentcolor', 'transparent'])) {
            return strtolower($value);
        }

        // Priority 6: Handle direct hex, rgb, rgba, hsl, hsla values (without brackets)
        if (preg_match('/^(#|rgb|rgba|hsl|hsla)/', $value)) {
            return $value;
        }
        
        return null; // Color format not recognized
    }

    private function convertColorToRgba(?string $color, float $alpha): ?string { // Mark $color as nullable
        if ($color === null) {
            return null; // Return null if color is null
        }
        if (strpos($color, '#') === 0) {
            $hex = substr($color, 1);
            if (strlen($hex) == 3) {
                $r = hexdec($hex[0] . $hex[0]); $g = hexdec($hex[1] . $hex[1]); $b = hexdec($hex[2] . $hex[2]);
            } elseif (strlen($hex) == 6 || strlen($hex) == 8) {
                $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
                if (strlen($hex) == 8 && $alpha == 1) { // Use alpha from 8-digit hex if provided and alpha override is 1
                    $alpha = round(hexdec(substr($hex, 6, 2)) / 255, 2);
                }
            } else {
                return null; // Invalid hex
            }
            return "rgba({$r}, {$g}, {$b}, " . round($alpha, 2) . ")";
        }
        // Handle rgb() and convert to rgba()
        if (preg_match('/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/', $color, $rgbMatches)) {
            return "rgba({$rgbMatches[1]}, {$rgbMatches[2]}, {$rgbMatches[3]}, " . round($alpha, 2) . ")";
        }
        // If it's already rgba, try to respect its alpha if passed alpha is 1, otherwise override
        if (preg_match('/^rgba\((\d+),\s*(\d+),\s*(\d+),\s*([0-9.]+)\)$/', $color, $rgbaMatches)) {
            $currentAlpha = (float)$rgbaMatches[4];
            return "rgba({$rgbaMatches[1]}, {$rgbaMatches[2]}, {$rgbaMatches[3]}, " . ($alpha == 1 ? round($currentAlpha,2) : round($alpha, 2)) . ")";
        }
        // For CSS named colors, we can't easily apply alpha without a mapping to RGB.
        // Returning null or the original color without alpha might be options.
        // For simplicity, if it's a known keyword but can't apply alpha, return null or original.
        if (in_array(strtolower($color), $this->getValidCssColorKeywords())) {
            // Cannot reliably apply alpha to all keywords without an RGB map.
            // Consider returning the color as is, or null if alpha must be applied.
            return ($alpha == 1) ? $color : null; // Only return if alpha is full, otherwise can't apply
        }

        return null; // Cannot convert or apply alpha
    }

    private function parseNumericValue(string $valueKey, string $themeSectionName, array $options = []): ?string {
        $allowNegative = $options['allowNegative'] ?? false;
        $defaultUnit = $options['defaultUnit'] ?? 'rem'; 
        $numericIsPercentage = $options['numericIsPercentage'] ?? false;
        $numericIsRaw = $options['numericIsRaw'] ?? false;
        $allowArbitrary = $options['allowArbitrary'] ?? true;

        $originalValueKey = $valueKey;
        $isArbitraryNegative = false;
        $isNegativePrefix = false;

        if ($themeSectionName === 'transitionDuration' || $themeSectionName === 'animationDuration' || $themeSectionName === 'transitionDelay' || $themeSectionName === 'animationDelay') {
            if ($allowArbitrary && str_starts_with($valueKey, '[')) {
                $arbitraryValue = trim($valueKey, '[]');
                if (preg_match('/^\d*\.?\d+(s|ms|m)$/i', $arbitraryValue)) {
                    return ($isArbitraryNegative ? '-' : '') . $arbitraryValue;
                }
            }
            if (preg_match('/^\d*\.?\d+(s|ms|m)$/i', $valueKey)) {
                return ($isArbitraryNegative ? '-' : '') . $valueKey;
            }
        }

        if ($allowNegative && strpos($valueKey, '-') === 0) {
            $potentialKeyWithoutMinus = substr($valueKey, 1);
            $directNegativeThemeHit = $this->lookupThemeValue($themeSectionName, $valueKey, true);
            $positiveThemeHit = $this->lookupThemeValue($themeSectionName, $potentialKeyWithoutMinus, true);

            if ($directNegativeThemeHit !== null && !is_array($directNegativeThemeHit)) { // Ensure direct hit is not an array
                $isArbitraryNegative = false; 
            } elseif (($positiveThemeHit !== null && !is_array($positiveThemeHit)) || strpos($valueKey, '-[') === 0 || is_numeric($potentialKeyWithoutMinus) || strpos($potentialKeyWithoutMinus, '/') !== false ) {
                if (strpos($valueKey, '-[') === 0) $valueKey = '[' . substr($valueKey, 2);
                else $valueKey = substr($valueKey, 1);
                $isArbitraryNegative = true;
            }
        }


        if ($allowArbitrary && str_starts_with($valueKey, '[')) {
            $arbitraryValue = trim($valueKey, '[]');
            $cssValue = str_replace('_', ' ', $arbitraryValue);
            $cssFunctions = ['clamp(', 'min(', 'max(', 'calc(', 'var('];
            foreach ($cssFunctions as $func) {
                if (str_starts_with($cssValue, $func)) {
                    return ($isNegativePrefix ? '-' : '') . $cssValue;
                }
            }
            if (preg_match('/^(\-?\d*\.?\d+)(px|rem|em|%|vw|vh|vmin|vmax|ch|ex|cm|mm|in|pt|pc|deg|rad|grad|turn|s|ms|m|fr)?$/i', $cssValue)) {
                $val = ($isNegativePrefix && !str_starts_with($cssValue, '-') ? '-' : '') . $cssValue;
                // If it's just a number in brackets like [10], apply default unit
                if (is_numeric($cssValue) && $defaultUnit && !str_contains($cssValue, '.')) {
                     return $val . $defaultUnit;
                }
                return $val;
            }
            return null;
        }

        $themeValue = $this->lookupThemeValue($themeSectionName, $valueKey);
        if ($themeValue !== null && !is_array($themeValue)) {
            $finalValue = $themeValue;
            if (is_numeric($finalValue) && !$numericIsRaw && $defaultUnit) {
                $finalValue .= $defaultUnit;
            }
            return ($isNegativePrefix && !str_starts_with($finalValue, '-') ? '-' : '') . $finalValue;
        }

        if ($allowArbitrary && preg_match('/^\[(.+)\]$/', $valueKey, $arbitraryMatch)) {
            $val = str_replace('_', ' ', $arbitraryMatch[1]); 
            if (preg_match('/^(\-?\d*\.?\d+)(px|rem|em|%|vw|vh|vmin|vmax|ch|ex|cm|mm|in|pt|pc|deg|rad|grad|turn|s|ms|m|fr)?$/i', $val) || 
                strpos($val, 'calc(') === 0 || strpos($val, 'var(') === 0 || $val === 'auto' || strpos($val, 'minmax(') === 0 || 
                strpos($val, 'repeat(') === 0 || strpos($val, 'fit-content(') === 0) {
                return ($isArbitraryNegative && $val !== '0' && $val !== 'auto' && strpos($val, '-') !== 0 ? '-' : '') . $val;
            }
            return null; 
        }

        $keywordValues = ['auto', 'full', 'screen', 'min', 'max', 'fit', 'none', 'DEFAULT']; 
        if (in_array($valueKey, $keywordValues) || ($themeSectionName === 'borderWidth' && $valueKey === 'DEFAULT')) {
            $resolvedKeyword = $this->lookupThemeValue($themeSectionName, $valueKey, true); 
            if ($resolvedKeyword && !is_array($resolvedKeyword)) { // Ensure keyword is not an array
                return ($isArbitraryNegative && $resolvedKeyword !=='0' && $resolvedKeyword !=='0px' && strpos($resolvedKeyword, '-') !== 0 ? '-' : '') . $resolvedKeyword;
            }
            if ($valueKey === 'full') return ($isArbitraryNegative ? '-' : '') . '100%';
            if ($valueKey === 'screen') return ($isArbitraryNegative ? '-' : '') . (($themeSectionName === 'width' || $themeSectionName === 'maxWidth') ? '100vw' : '100vh');
            if ($valueKey === 'DEFAULT' && $themeSectionName === 'borderWidth') { 
                $bwDefault = $this->lookupThemeValue('borderWidth', 'DEFAULT');
                return ($isArbitraryNegative && is_string($bwDefault) && $bwDefault !== '0' && $bwDefault !== '0px' ? '-' : '') . $bwDefault;
            }
            if ($valueKey !== 'DEFAULT') return ($isArbitraryNegative && $valueKey !== 'auto' && $valueKey !== '0' ? '-' : '') . $valueKey;
        }

        $themeValueResult = $this->lookupThemeValue($themeSectionName, $valueKey);
        $actualThemeValue = null;

        if (is_array($themeValueResult) && $themeSectionName === 'fontSize' && isset($themeValueResult[0]) && is_string($themeValueResult[0])) {
            // For fontSize, if it's an array like ['size', {options}], use the first element.
            $actualThemeValue = $themeValueResult[0];
        } elseif (is_string($themeValueResult)) {
            $actualThemeValue = $themeValueResult;
        }

        if ($actualThemeValue !== null) {
            $valueToProcess = $actualThemeValue;
            if (is_numeric($valueToProcess) && $valueToProcess != 0 && !$numericIsRaw && $defaultUnit && !preg_match('/(px|rem|em|%|vw|vh|deg|rad|grad|turn|s|ms|fr)$/i', $valueToProcess)) {
                $valueToProcess .= $defaultUnit;
            }
            if ($numericIsPercentage && is_numeric($valueToProcess)) {
                $valueToProcess = (string)((float)$valueToProcess / 100); // Ensure float division
            }
            return ($isArbitraryNegative && $valueToProcess !== '0' && $valueToProcess !== '0px' && strpos((string)$valueToProcess, '-') !== 0 ? '-' : '') . $valueToProcess;
        }

        if (preg_match('/^(\d+)\/(\d+)$/', $valueKey, $fractionMatch)) {
            $numerator = (float)$fractionMatch[1]; $denominator = (float)$fractionMatch[2];
            if ($denominator != 0) {
                $percentage = rtrim(rtrim(number_format(($numerator / $denominator * 100), 6), '0'), '.') . '%';
                return ($isArbitraryNegative ? '-' : '') . $percentage;
            }
        }
        
        if ($originalValueKey === 'px' && $themeSectionName === 'spacing') return ($isArbitraryNegative ? '-' : '') . '1px';

        if (is_numeric($valueKey)) { 
            $numericVal = (float)$valueKey; // ফ্লোট হিসেবে পার্স করুন
            
            if ($numericIsPercentage) {
                return (string)($numericVal / 100);
            }
            if ($numericIsRaw || $numericVal == 0) {
                return (string)$numericVal;
            }
            // ডিফল্ট ইউনিট যোগ করুন
            return ($isArbitraryNegative ? '-' : '') . $numericVal . $defaultUnit;
        }
        return null; 
    }

    private function lookupThemeValue(string $themeSection, string $keyPath, bool $exactMatchOnly = false, bool $isDarkModeContext = false): string|array|null {
        $configSection = $this->config['theme'][$themeSection] ?? null;
        if (!$configSection) return null;

        // Handle direct semantic color with light/dark (e.g., colors.primary)
        if ($themeSection === 'colors' && isset($configSection[$keyPath]['light']) && isset($configSection[$keyPath]['dark'])) {
            $mode = $isDarkModeContext ? 'dark' : 'light';
            $colorRef = $configSection[$keyPath][$mode];
            // The value of light/dark can itself be a theme reference
            return $this->resolveThemeValue($colorRef, null, $isDarkModeContext);
        }

        // Handle cases where a theme section references another (e.g., 'width' => ['spacing', ...])
        if (is_array($configSection)) {
            foreach($configSection as $k => $item) {
                if(is_string($item) && $k !== $item && isset($this->config['theme'][$item]) && $themeSection !== $item) {
                    $referencedValue = $this->lookupThemeValue($item, $keyPath, $exactMatchOnly, $isDarkModeContext);
                    if ($referencedValue !== null) return $referencedValue;
                }
            }
        }

        if (isset($configSection[$keyPath])) {
            $value = $configSection[$keyPath];

            // If this is a semantic color definition itself (e.g., 'primary' which is an array)
            if ($themeSection === 'colors' && is_array($value) && (isset($value['DEFAULT']) || isset($value['light']) || isset($value['dark']))) {
                $modeToUse = $isDarkModeContext ? 'dark' : 'light';
                $colorToResolve = $value[$modeToUse] ?? $value['DEFAULT'] ?? null;

                if ($colorToResolve) {
                    // The value for light/dark can itself be another theme reference or a direct color string
                    if (is_array($colorToResolve) && isset($colorToResolve['theme'])) {
                        return $this->resolveThemeValue($colorToResolve, null, $isDarkModeContext); // Recursive call
                    } elseif (is_string($colorToResolve)) {
                        return $colorToResolve;
                    }
                }
                // Fallback if light/dark specific not found but DEFAULT exists
                if (isset($value['DEFAULT'])) {
                    if (is_array($value['DEFAULT']) && isset($value['DEFAULT']['theme'])) {
                        return $this->resolveThemeValue($value['DEFAULT'], null, $isDarkModeContext);
                    } elseif (is_string($value['DEFAULT'])) {
                        return $value['DEFAULT'];
                    }
                }
                return null; // No suitable color found in semantic structure
            }

            // For other types like fontSize
            if ($themeSection === 'fontSize' && is_array($value)) return $value;
            return is_scalar($value) ? (string)$value : (is_array($value) && $themeSection !== 'colors' ? $value : null);
        }
        
        if ($exactMatchOnly) return null; 

        // For palette colors like 'red-500' -> ['colors']['red']['500']
        if ($themeSection === 'colors' && strpos($keyPath, '-') !== false) {
            $parts = explode('-', $keyPath, 2);
            if (count($parts) === 2) {
                $colorName = $parts[0]; $shade = $parts[1];
                if (isset($configSection[$colorName]) && is_array($configSection[$colorName]) && isset($configSection[$colorName][$shade])) {
                    $value = $configSection[$colorName][$shade];
                    return is_scalar($value) ? (string)$value : null;
                }
            }
        }
        
        if (str_contains($keyPath, '.')) {
            $keys = explode('.', $keyPath);
            $current = $configSection;
            $pathExists = true;
            foreach ($keys as $key) {
                if (!is_array($current) || !isset($current[$key])) {
                    $pathExists = false;
                    break;
                }
                $current = $current[$key];
            } 
            if ($pathExists) {
                if ($themeSection === 'fontSize' && is_array($current)) return $current;
                return is_scalar($current) ? (string)$current : null;
            }
        }
        
        // ধাপ খ: যদি ডট-নোটেশনে না পাওয়া যায়, তাহলে হাইফেন-নোটেশন পার্স করার চেষ্টা করুন (যেমন: red-500)
        if ($themeSection === 'colors' && str_contains($keyPath, '-')) {
            $parts = explode('-', $keyPath);
            $shade = array_pop($parts);
            $colorName = implode('-', $parts);
            
            if (!empty($colorName) && isset($configSection[$colorName]) && is_array($configSection[$colorName]) && isset($configSection[$colorName][$shade])) {
                $value = $configSection[$colorName][$shade];
                return is_scalar($value) ? (string)$value : null;
            }
        }
        
        // যদি কোনো কিছুই না পাওয়া যায়
        return null;
    }

    private function escapeClassNameForSelector(string $className): string { $escaped = preg_replace_callback( '/[^a-zA-Z0-9\-_]/u', function ($matches) { $char = $matches[0]; $simpleEscapeChars = ['!', '#', '$', '%', '&', '\'', '(', ')', '*', '+', ',', '.', '/', ':', ';', '<', '=', '>', '?', '@', '[', '\\', ']', '^', '`', '{', '|', '}', '~']; if (in_array($char, $simpleEscapeChars)) { return '\\' . $char; } return '\\' . $char; }, $className ); if (preg_match('/^[0-9]/', $escaped)) { $escaped = '\\3' . substr($escaped, 0, 1) . ' ' . substr($escaped, 1); } elseif (preg_match('/^-[0-9]/', $escaped)) { $escaped = '\\-' . substr($escaped, 1); } return $escaped; }
    
    private function getBaseTableCss(): string {
        if (!($this->config['corePlugins']['baseTables'] ?? true)) {
            return "";
        }

        return <<<CSS
        /* --- Premium Base Table Styles (Class-less) --- */
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.875rem; /* text-sm */
            line-height: 1.25rem;
            color: hsl(var(--bc, var(--foreground)));
        }
        table caption {
            padding: 0.75rem;
            text-align: left;
            color: hsl(var(--bc, var(--muted-foreground)));
            caption-side: bottom;
        }
        thead {
            background-color: hsl(var(--b2, var(--muted)) / 0.5);
            border-bottom: 2px solid hsl(var(--b3, var(--border)));
        }
        thead th {
            font-weight: 600;
            padding: 0.75rem 1rem;
            color: hsl(var(--bc, var(--foreground)));
        }
        tbody tr {
            border-bottom: 1px solid hsl(var(--b2, var(--border)));
            transition: background-color 0.15s ease;
        }
        tbody tr:last-child {
            border-bottom: 0;
        }
        tbody tr:hover {
            background-color: hsl(var(--b2, var(--muted)) / 0.3);
        }
        tbody td {
            padding: 0.75rem 1rem;
            vertical-align: middle;
        }
        /* Tables inside .prose (Markdown) are handled by typography plugin */
        CSS;
    }

    private function getPreflightCss(): string { if (!($this->config['corePlugins']['preflight'] ?? true) || $this->preflightAdded) { return ""; } $this->preflightAdded = true; return <<<CSS
*, ::before, ::after { box-sizing: border-box; border-width: 0; border-style: solid; border-color: currentColor; --tw-ring-offset-width: 0px; } html { line-height: 1.5; -webkit-text-size-adjust: 100%; -moz-tab-size: 4; tab-size: 4; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"; } body { margin: 0; line-height: inherit; } hr { height: 0; color: inherit; border-top-width: 1px; } h1, h2, h3, h4, h5, h6 { font-size: inherit; font-weight: inherit; } a { color: inherit; text-decoration: inherit; } b, strong { font-weight: bolder; } code, kbd, samp, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 1em; } small { font-size: 80%; } sub, sup { font-size: 75%; line-height: 0; position: relative; vertical-align: baseline; } sub { bottom: -0.25em; } sup { top: -0.5em; } table { text-indent: 0; border-color: inherit; border-collapse: collapse; } button, input, optgroup, select, textarea { font-family: inherit; font-size: 100%; font-weight: inherit; line-height: inherit; color: inherit; margin: 0; padding: 0; } button, select { text-transform: none; } button, [type='button'], [type='reset'], [type='submit'] { -webkit-appearance: button; background-color: transparent; background-image: none; } :-moz-focusring { outline: auto; } :-moz-ui-invalid { box-shadow: none; } progress { vertical-align: baseline; } ::-webkit-inner-spin-button, ::-webkit-outer-spin-button { height: auto; } [type='search'] { -webkit-appearance: textfield; outline-offset: 0px; } ::-webkit-search-decoration { -webkit-appearance: none; } ::-webkit-file-upload-button { -webkit-appearance: button; font: inherit; } summary { display: list-item; } blockquote, dl, dd, h1, h2, h3, h4, h5, h6, hr, figure, p, pre { margin: 0; } fieldset { margin: 0; padding: 0; } legend { padding: 0; } ol, ul, menu { list-style: none; margin: 0; padding: 0; } textarea { resize: vertical; } input::placeholder, textarea::placeholder { opacity: 1; color: #9ca3af; } button, [role="button"] { cursor: pointer; } :disabled { cursor: default; } img, svg, video, canvas, audio, iframe, embed, object { display: block; vertical-align: middle; } img, video { max-width: 100%; height: auto; } [hidden] { display: none !important; } .pace{-webkit-pointer-events:none;pointer-events:none;-webkit-user-select:none;-moz-user-select:none;user-select:none}.pace-inactive{display:none}.pace .pace-progress{background:hsl(var(--primary));position:fixed;z-index:2000;top:0;right:100%;width:100%;height:3px;box-shadow:0 0 10px hsl(var(--primary)),0 0 5px hsl(var(--primary))} ::-webkit-scrollbar{width:15px}::-webkit-scrollbar-track{background-color:hsl(var(--background));border-radius:4px;box-shadow:inset 0 0 3px grey}::-webkit-scrollbar-thumb{background-color:hsl(var(--primary));border-radius:6px;border:3px solid #fff0;background-clip:content-box}::-webkit-scrollbar-thumb:hover{background-color:hsl(var(--primary-hover))}::-webkit-scrollbar-corner{background-color:#fff0} 
CSS;
    }

    private function getFormsBaseStyles(): string {
        $formsConfig = $this->config['forms'] ?? [];
        
        // যদি baseStyles false থাকে, তবে কোনো CSS জেনারেট হবে না।
        if (!($formsConfig['baseStyles'] ?? false)) {
            return "";
        }

        $classStyles = $formsConfig['classStyles'] ?? [];
        $strategy = $formsConfig['strategy'] ?? 'class';
        $prefix = $formsConfig['classPrefix'] ?? 'form-';

        $css = "/* --- Base Form Styles (Auto-generated) --- */\n";

        // === ধাপ ১: প্রয়োজনীয় সমস্ত ভেরিয়েবল সংজ্ঞায়িত করুন ===
        $borderColorDefault = $this->resolveThemeValue($formsConfig['defaultBorderColor'], '#d1d5db'); // fallback gray-300
        $ringColorDefault = $this->resolveThemeValue($formsConfig['defaultRingColor'], '#2563eb');     // fallback blue-600
        $checkboxRadioColor = $this->resolveThemeValue($formsConfig['defaultCheckboxRadioColor'], '#4f46e5'); // fallback indigo-600
        
        $spacing2 = $this->lookupThemeValue('spacing', '2') ?? '0.5rem';
        $spacing3 = $this->lookupThemeValue('spacing', '3') ?? '0.75rem';
        $spacing4 = $this->lookupThemeValue('spacing', '4') ?? '1rem';
        $spacing10 = $this->lookupThemeValue('spacing', '10') ?? '2.5rem';
        
        $fontSizeBase = $this->lookupThemeValue('fontSize', 'base')[0] ?? '1rem';
        $lineHeightBase = $this->lookupThemeValue('fontSize', 'base')[1]['lineHeight'] ?? '1.5rem';
        $borderRadius = $this->lookupThemeValue('borderRadius', 'DEFAULT') ?? '0.375rem';

        // === Text Inputs, Textarea, Multiselect ===
        // শুধুমাত্র যদি কাস্টম স্টাইল ডিফাইন করা না থাকে
        if (empty($classStyles['input']) && empty($classStyles['textarea']) && empty($classStyles['multiselect'])) {
            $textInputSelectors = ($strategy === 'base') 
                ? "[type='text'],[type='email'],[type='url'],[type='password'],[type='number'],[type='date'],[type='datetime-local'],[type='month'],[type='search'],[type='tel'],[type='time'],[type='week'],textarea,[multiple]" 
                : ".{$prefix}input, .{$prefix}textarea, .{$prefix}multiselect";

            $textInputBase = [
                'appearance' => 'none', 'border-width' => '1px', 'border-color' => $borderColorDefault,
                'border-radius' => $borderRadius, 'width' => '100%', 'padding' => "{$spacing2} {$spacing3}",
                'font-size' => $fontSizeBase, 'line-height' => $lineHeightBase,
                'background-color' => '#fff', 'color' => 'inherit',
            ];
            $textInputFocus = [
                'outline' => '2px solid transparent', 'outline-offset' => '0px',
                'border-color' => $ringColorDefault, 'box-shadow' => "0 0 0 1px {$ringColorDefault}",
            ];
            
            $css .= $this->buildCssRuleString($textInputSelectors, $textInputBase);
            $css .= $this->buildCssRuleString("{$textInputSelectors}:focus", $textInputFocus);
        }

        // === Select ===
        if (empty($classStyles['select'])) {
            $selectSelector = ($strategy === 'base') ? "select:not([multiple])" : ".{$prefix}select";
            $arrowSvg = rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'><path stroke='#6b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/></svg>");
            
            $css .= $this->buildCssRuleString($selectSelector, [
                'background-image' => "url(\"data:image/svg+xml,{$arrowSvg}\")",
                'background-position' => "right {$spacing2} center", 'background-repeat' => 'no-repeat',
                'background-size' => '1.5em 1.5em', 'padding-right' => $spacing10,
            ]);
        }

        // === Checkbox ===
        if (empty($classStyles['checkbox'])) {
            $checkboxSelector = ($strategy === 'base') ? "[type='checkbox']" : ".{$prefix}checkbox";
            $checkIcon = rawurlencode("<svg viewBox='0 0 16 16' fill='white' xmlns='http://www.w3.org/2000/svg'><path d='M12.207 4.793a1 1 0 010 1.414l-5 5a1 1 0 01-1.414 0l-2-2a1 1 0 011.414-1.414L6.5 9.086l4.293-4.293a1 1 0 011.414 0z'/></svg>");
            
            $css .= $this->buildCssRuleString($checkboxSelector, [
                'appearance' => 'none', 'padding' => '0', 'height' => $spacing4, 'width' => $spacing4,
                'color' => $checkboxRadioColor, 'border-color' => $borderColorDefault, 'border-width' => '1px',
                'border-radius' => '0.25rem', 'background-color' => '#fff',
            ]);
            $css .= $this->buildCssRuleString("{$checkboxSelector}:checked", [
                'border-color' => 'transparent', 'background-color' => 'currentColor', 'background-size' => '100% 100%',
                'background-image' => "url(\"data:image/svg+xml,{$checkIcon}\")",
            ]);
        }

        // === Radio ===
        if (empty($classStyles['radio'])) {
            $radioSelector = ($strategy === 'base') ? "[type='radio']" : ".{$prefix}radio";
            $radioIcon = rawurlencode("<svg viewBox='0 0 16 16' fill='white' xmlns='http://www.w3.org/2000/svg'><circle cx='8' cy='8' r='3'/></svg>");
            
            $css .= $this->buildCssRuleString($radioSelector, [
                'appearance' => 'none', 'padding' => '0', 'height' => $spacing4, 'width' => $spacing4,
                'color' => $checkboxRadioColor, 'border-color' => $borderColorDefault, 'border-width' => '1px',
                'border-radius' => '9999px', 'background-color' => '#fff',
            ]);
            $css .= $this->buildCssRuleString("{$radioSelector}:checked", [
                'border-color' => 'transparent', 'background-color' => 'currentColor', 'background-size' => '100% 100%',
                'background-image' => "url(\"data:image/svg+xml,{$radioIcon}\")",
            ]);
        }

        return $css;
    }
    
    private function handleFormElement(string $baseClassPart, array $matches): ?array {
        $formsConfig = $this->config['forms'] ?? [];
        if (($formsConfig['strategy'] ?? 'class') !== 'class') {
            return null; // শুধুমাত্র 'class' স্ট্র্যাটেজিতে কাজ করবে
        }

        $prefix = $formsConfig['classPrefix'] ?? 'form-';
        $classType = substr($baseClassPart, strlen($prefix));

        $stylesConfig = $formsConfig['classStyles'][$classType] ?? null;
        if (empty($stylesConfig)) return null; // যদি কোনো কাস্টম স্টাইল না থাকে

        $finalStyles = [];

        // _extends লজিক
        if (isset($stylesConfig['_extends'])) {
            $parentType = $stylesConfig['_extends'];
            $parentStyles = $formsConfig['classStyles'][$parentType] ?? [];
            $finalStyles = array_replace_recursive($parentStyles, $stylesConfig);
            unset($finalStyles['_extends']);
        } else {
            $finalStyles = $stylesConfig;
        }

        // থিম ভ্যালু রিজলভ করা
        array_walk_recursive($finalStyles, function(&$value) {
            if (is_array($value) && isset($value['theme'])) {
                $resolved = $this->resolveThemeValue($value);
                if (is_string($resolved)) $value = $resolved;
            }
        });

        return ['layer' => 'components', 'style' => $finalStyles];
    }

    private function handleFormFloating(string $baseClassPart, array $matches): ?array {
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        $transition = 'opacity .1s ease-in-out, transform .1s ease-in-out';

        $css = <<<CSS
        {$selector} {
            position: relative;
        }

        /* Input/Select/Textarea inside a floating label container */
        {$selector} > .form-control,
        {$selector} > .form-select {
            height: calc(3.5rem + 2px); /* Default height */
            line-height: 1.25;
            padding-top: 1.625rem;
            padding-bottom: 0.625rem;
        }

        /* Label styling */
        {$selector} > label {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            padding: 1rem 0.75rem;
            pointer-events: none;
            border: 1px solid transparent;
            transform-origin: 0 0;
            transition: {$transition};
            color: hsl(var(--bc, var(--foreground)) / 0.6);
        }
        
        /* --- The "FLOAT" logic --- */
        /* When input has value OR is focused OR it's a select */
        {$selector} > .form-control:not(:placeholder-shown) ~ label,
        {$selector} > .form-control:focus ~ label,
        {$selector} > .form-select ~ label {
            opacity: .65;
            transform: scale(.85) translateY(-.5rem) translateX(.15rem);
        }

        /* --- Special handling for .form-control-plaintext --- */
        {$selector} > .form-control-plaintext {
             padding-top: 1.625rem;
             padding-bottom: 0.625rem;
        }
        
        /* Make placeholder invisible */
        {$selector} > .form-control::placeholder {
            color: transparent;
        }
        CSS;

        return ['layer' => 'components', 'style' => $css];
    }

    private function handleAccordionButton(string $baseClassPart, array $matches): ?array {
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        // বুটস্ট্র্যাপের ডিফল্ট SVG আইকন (URL Encoded)
        $icon = "url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23212529'%3e%3cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e\")";
        $activeIcon = "url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%230c63e4'%3e%3cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e\")";

        $css = <<<CSS
        /* --- Base Accordion Button --- */
        {$selector} {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
            padding: 1rem 1.25rem;
            font-size: 1rem;
            color: #212529;
            text-align: left;
            background-color: #fff;
            border: 0;
            border-radius: 0;
            overflow-anchor: none;
            transition: all 0.15s ease-in-out;
        }

        /* --- Icon Handling for Collapsed State (Default) --- */
        {$selector}.collapsed::after {
            background-image: {$icon};
            transform: rotate(0deg);
        }

        /* --- Icon Handling for Active/Open State (:not(.collapsed)) --- */
        {$selector}:not(.collapsed)::after {
            background-image: {$activeIcon};
            transform: rotate(-180deg);
        }
        
        {$selector}::after {
             flex-shrink: 0;
             width: 1.25rem;
             height: 1.25rem;
             margin-left: auto;
             content: "";
             background-repeat: no-repeat;
             background-size: 1.25rem;
             transition: transform 0.2s ease-in-out;
        }

        /* --- Active/Open State Styling --- */
        {$selector}:not(.collapsed) {
            color: #0c63e4;
            background-color: #e7f1ff;
            box-shadow: inset 0 -1px 0 rgba(0, 0, 0, 0.125);
        }

        /* --- Focus State --- */
        {$selector}:focus {
            z-index: 3;
            border-color: #86b7fe;
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        CSS;

        return ['layer' => 'components', 'style' => $css];
    }

    private function handleAvatar(string $baseClassPart, array $matches): ?array {
        $modifier = $matches[1] ?? null; // group, online, offline, placeholder, or null
        $selector = '.' . $this->escapeClassNameForSelector($baseClassPart);
        
        // --- Base .avatar Styles ---
        if ($modifier === null) {
            $css = <<<CSS
            {$selector} {
                display: inline-flex;
                position: relative;
            }
            /* ভেতরের div বা img এর জন্য */
            {$selector} > div, {$selector} > img {
                display: block;
                aspect-ratio: 1 / 1;
                overflow: hidden;
            }
            CSS;
            return ['layer' => 'components', 'style' => $css];
        }

        // --- Modifier Styles ---
        $modifierCss = '';
        switch ($modifier) {
            case 'group':
                $modifierCss = <<<CSS
                /* --- Avatar Group --- */
                {$selector} {
                    display: flex;
                    align-items: center;
                }
                /* avatar */
                {$selector} > .avatar {
                    /* ডাইনামিক ব্যাকগ্রাউন্ড কালার, যা থিম অনুযায়ী অটো চেঞ্জ হবে */
                    border: 4px solid hsl(var(--b1, var(--background)));
                    border-radius: 9999px;
                    overflow: hidden;
                }
                CSS;
                break;
            
            case 'online':
            case 'offline':
                // Online হলে Success(Green) কালার, Offline হলে Muted(Gray) কালার
                $color = ($modifier === 'online') ? 'hsl(var(--su, var(--success)))' : 'hsl(var(--b3, var(--muted)))'; 
                $modifierCss = <<<CSS
                /* --- Presence Indicator (Online/Offline) --- */
                {$selector}::before {
                    content: '';
                    position: absolute;
                    z-index: 10;
                    bottom: 10%;
                    right: 10%;
                    transform: translate(50%, 50%);
                    width: 0.75rem; /* 12px */
                    height: 0.75rem;
                    border-radius: 9999px;
                    background-color: {$color};
                    /* ডাইনামিক বর্ডার কালার (থিমের ব্যাকগ্রাউন্ডের সাথে মিশে যাবে) */
                    border: 2px solid hsl(var(--b1, var(--background)));
                }
                CSS;
                break;
            
            case 'placeholder':
                $modifierCss = <<<CSS
                /* --- Avatar Placeholder --- */
                {$selector} > div {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                CSS;
                break;
        }

        return !empty($modifierCss) ? ['layer' => 'components', 'style' => $modifierCss] : null;
    }

    private function handleTransitionRevealBase(string $baseClassPart, array $matches): ?array {
        return [
            'layer' => 'components',
            'style' => [
                'position' => 'relative', 'overflow' => 'hidden', 'z-index' => '0',
                '_beforeStyles' => [
                    'content' => '""', 'position' => 'absolute', 'inset' => '0', 'z-index' => '-1',
                    'opacity' => '0',
                    'transition' => 'opacity var(--tw-reveal-duration-out, 1s) ease-in-out',
                    
                    // --- নতুন এবং উন্নত: গ্র্যাডিয়েন্ট ভেরিয়েবল ব্যবহার ---
                    // ডিফল্টরূপে এটি একটি সলিড রঙ হবে, যা --tw-reveal-bg থেকে আসবে
                    'background' => 'var(--tw-reveal-bg, transparent)',
                    // যদি from/via/to ক্লাস ব্যবহার করা হয়, তাহলে background-image এটি ওভাররাইড করবে
                    'background-image' => 'var(--tw-reveal-gradient, var(--tw-reveal-bg, transparent))',
                ],
                '_hoverBeforeStyles' => [
                    'opacity' => '1',
                    'transition-duration' => 'var(--tw-reveal-duration-in, 150ms)',
                ],
            ]
        ];
    }

    private function handleRevealBackground(string $baseClassPart, array $matches): ?array {
        $value = $matches[1];
        
        // --- কেস ১: গ্র্যাডিয়েন্ট ক্লাস (from-*, to-*, via-*, gradient-to-*) ---
        if (str_starts_with($value, 'from-') || str_starts_with($value, 'to-') || str_starts_with($value, 'via-') || str_starts_with($value, 'gradient-to-')) {
            
            $gradientStyles = [];
            
            if (str_starts_with($value, 'gradient-to-')) {
                // handleGradientDirection-কে সঠিক $matches দিয়ে কল করা হচ্ছে
                $dirMatches = [$value, substr($value, 12)]; // একটি সিমুলেটেড $matches অ্যারে
                $dirStyles = $this->handleGradientDirection("bg-{$value}", $dirMatches);
                if ($dirStyles) {
                    unset($dirStyles['background-image']);
                    $gradientStyles = array_merge($gradientStyles, $dirStyles);
                }
            } else {
                // handleGradientColorStop-কে সঠিক $matches দিয়ে কল করা হচ্ছে
                $stopParts = explode('-', $value, 2);
                $stopMatches = [$value, $stopParts[0], $stopParts[1] ?? '']; // একটি সিমুলেটেড $matches অ্যারে
                $stopStyles = $this->handleGradientColorStop($value, $stopMatches);
                if ($stopStyles) {
                    $gradientStyles = array_merge($gradientStyles, $stopStyles);
                }
            }

            if (!empty($gradientStyles)) {
                // --- এই অংশটি এখন নতুন --tw-gradient-* ভেরিয়েবলের উপর নির্ভর করবে ---
                $direction = 'var(--tw-gradient-direction, to bottom)';
                $stops = 'var(--tw-gradient-stops, var(--tw-gradient-from), var(--tw-gradient-to))';
                
                // background-image এখন --tw-reveal-gradient ভেরিয়েবলের মাধ্যমে সেট হবে
                $gradientStyles['--tw-reveal-gradient'] = "linear-gradient({$direction}, {$stops})";
                return ['_beforeStyles' => $gradientStyles];
            }
        }

        // --- কেস ২: সলিড রঙ বা Arbitrary Background ---
        $backgroundValue = null;
        if (str_starts_with($value, '[')) {
            $backgroundValue = str_replace('_', ' ', trim($value, '[]'));
        } else {
            $bgStyle = $this->handleBackgroundColor("bg-{$value}", [$value, $value]);
            if ($bgStyle && isset($bgStyle['background-color'])) {
                $backgroundValue = $bgStyle['background-color'];
            }
        }
        
        return $backgroundValue ? ['_beforeStyles' => ['--tw-reveal-bg' => $backgroundValue]] : null;
    }

    private function handleRevealDurationIn(string $baseClassPart, array $matches): ?array {
        $duration = $this->parseNumericValue($matches[1], 'transitionDuration', ['defaultUnit' => 'ms']);
        return $duration ? ['_hoverBeforeStyles' => ['--tw-reveal-duration-in' => $duration]] : null;
    }

    private function handleRevealDurationOut(string $baseClassPart, array $matches): ?array {
        $duration = $this->parseNumericValue($matches[1], 'transitionDuration', ['defaultUnit' => 'ms']);
        return $duration ? ['_beforeStyles' => ['--tw-reveal-duration-out' => $duration]] : null;
    }

    private function convertColorToHslComponentsString(?string $colorString, ?float $alphaOverride = null): ?string {
        if ($colorString === null || trim($colorString) === '') {
            return null;
        }

        $r = $g = $b = null;
        $a_parsed = null; // Alpha parsed from the input string

        // 1. Check if it's already HSL(A) components string "H S% L% [/ A]"
        if (preg_match('/^([\d.]+)\s+([\d.]+%)\s+([\d.]+%)(?:\s*\/\s*([0-9.]+))?$/i', $colorString, $matches)) {
            $h_val = round((float)$matches[1], 1);
            $s_val = round((float)rtrim($matches[2], '%'), 1);
            $l_val = round((float)rtrim($matches[3], '%'), 1);
            $a_from_string = isset($matches[4]) ? round((float)$matches[4], 3) : null;
            
            $finalAlpha = $alphaOverride ?? $a_from_string;
            return trim("{$h_val} {$s_val}% {$l_val}%" . ($finalAlpha !== null ? " / {$finalAlpha}" : ""));
        }

        // 2. Parse full hsl() or hsla() string
        if (preg_match('/^hsl\(\s*([\d.]+)\s*,\s*([\d.]+%)\s*,\s*([\d.]+%)\s*\)$/i', $colorString, $hslMatches)) {
            $h_val = round((float)$hslMatches[1], 1);
            $s_val = round((float)rtrim($hslMatches[2], '%'), 1);
            $l_val = round((float)rtrim($hslMatches[3], '%'), 1);
            $hslComp = "{$h_val} {$s_val}% {$l_val}%";
            return $alphaOverride !== null ? $hslComp . " / " . round($alphaOverride, 3) : $hslComp;
        } elseif (preg_match('/^hsla\(\s*([\d.]+)\s*,\s*([\d.]+%)\s*,\s*([\d.]+%)\s*,\s*([0-9.]+)\s*\)$/i', $colorString, $hslaMatches)) {
            $h_val = round((float)$hslaMatches[1], 1);
            $s_val = round((float)rtrim($hslaMatches[2], '%'), 1);
            $l_val = round((float)rtrim($hslaMatches[3], '%'), 1);
            $a_from_string = round((float)$hslaMatches[4], 3);
            $finalAlpha = $alphaOverride ?? $a_from_string;
            return "{$h_val} {$s_val}% {$l_val}% / {$finalAlpha}";
        }

        // 3. Parse #hex
        if (preg_match('/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/i', $colorString, $hexMatch)) {
            $hex = $hexMatch[1];
            if (strlen($hex) == 3) {
                $r = hexdec($hex[0] . $hex[0]); $g = hexdec($hex[1] . $hex[1]); $b = hexdec($hex[2] . $hex[2]);
            } elseif (strlen($hex) >= 6) {
                $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
                if (strlen($hex) == 8) $a_parsed = round(hexdec(substr($hex, 6, 2)) / 255, 3);
            } else { return null; }
        }
        // 4. Parse rgb() or rgba()
        elseif (preg_match('/^rgb\((\d+)\s*,\s*(\d+)\s*,\s*(\d+)\)$/i', $colorString, $rgbMatches)) { // Simpler regex for rgb
            $r = (int)$rgbMatches[1]; $g = (int)$rgbMatches[2]; $b = (int)$rgbMatches[3];
        } elseif (preg_match('/^rgba\((\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*([0-9.]+)\)$/i', $colorString, $rgbaMatches)) { // Simpler regex for rgba
            $r = (int)$rgbaMatches[1]; $g = (int)$rgbaMatches[2]; $b = (int)$rgbaMatches[3];
            $a_parsed = round((float)$rgbaMatches[4], 3);
        }
        // 5. Handle CSS Color Keywords (requires a map)
        else {
            $keywordRgb = $this->keywordToRgb(strtolower($colorString));
            if ($keywordRgb) {
                $r = $keywordRgb[0]; $g = $keywordRgb[1]; $b = $keywordRgb[2];
                // Keywords don't have inherent alpha, unless it's 'transparent'
                if (strtolower($colorString) === 'transparent') $a_parsed = 0.0;
            } else {
                return null; // Unrecognized format
            }
        }

        // If RGB values were successfully parsed
        if ($r !== null && $g !== null && $b !== null) {
            // RGB to HSL component conversion
            $r_norm = $r / 255; $g_norm = $g / 255; $b_norm = $b / 255;
            $max = max($r_norm, $g_norm, $b_norm); $min = min($r_norm, $g_norm, $b_norm);
            $h_hsl = $s_hsl = $l_hsl = ($max + $min) / 2;

            if ($max == $min) {
                $h_hsl = $s_hsl = 0; // achromatic
            } else {
                $d = $max - $min;
                $s_hsl = $l_hsl > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
                switch ($max) {
                    case $r_norm: $h_hsl = ($g_norm - $b_norm) / $d + ($g_norm < $b_norm ? 6 : 0); break;
                    case $g_norm: $h_hsl = ($b_norm - $r_norm) / $d + 2; break;
                    case $b_norm: $h_hsl = ($r_norm - $g_norm) / $d + 4; break;
                }
                $h_hsl /= 6;
            }
            
            $h_val = round($h_hsl * 360, 1);
            $s_val = round($s_hsl * 100, 1);
            $l_val = round($l_hsl * 100, 1);

            $finalAlpha = $alphaOverride ?? $a_parsed;

            return trim("{$h_val} {$s_val}% {$l_val}%" . ($finalAlpha !== null ? " / " . round($finalAlpha, 3) : ""));
        }
        return null; // Should not be reached if logic is correct
    }
    // Helper to convert CSS color keywords to RGB array
    private function keywordToRgb(string $keyword): ?array {
        // This map needs to be comprehensive for all supported keywords
        $map = [
            'black' => [0,0,0], 'white' => [255,255,255],
            'red' => [255,0,0], 'green' => [0,128,0], 'blue' => [0,0,255],
            'yellow' => [255,255,0], 'cyan' => [0,255,255], 'magenta' => [255,0,255],
            'silver' => [192,192,192], 'gray' => [128,128,128], 'maroon' => [128,0,0],
            'olive' => [128,128,0], 'purple' => [128,0,128], 'teal' => [0,128,128],
            'navy' => [0,0,128],
            // Add more... from your getValidCssColorKeywords list ideally
        ];
        if (strtolower($keyword) === 'transparent') return [0,0,0]; // Transparent can be represented as black with alpha 0 for HSL conversion
        if (strtolower($keyword) === 'currentcolor' || strtolower($keyword) === 'inherit') return null; // Cannot convert these to fixed HSL

        return $map[strtolower($keyword)] ?? null;
    }

    private function resolveThemeValue(array|string $configPath, ?string $fallback = null, bool $isDarkModeContext = false, ?float $alphaOverride = null, bool $resolveToHslColorComponents = false ): string|array|null { // Can return string (color/value) or array (for fontSize)

        $resolvedValue = null;

        if (is_string($configPath)) {
            $resolvedValue = $configPath; // Direct value (e.g., "#fff", "1rem", "var(--foo)")
        } elseif (isset($configPath['theme'])) { // Theme reference like ['theme' => 'colors.blue.500']
            $pathParts = explode('.', $configPath['theme']);
            $category = array_shift($pathParts);
            $keyPath = implode('.', $pathParts);
            
            // or another ['theme' => ...] array if it's a semantic color pointing to another.
            $valueFromTheme = $this->lookupThemeValue($category, $keyPath, false, $isDarkModeContext);

            if (is_array($valueFromTheme) && isset($valueFromTheme['theme'])) {
                return $this->resolveThemeValue($valueFromTheme, $fallback, $isDarkModeContext, $alphaOverride, $resolveToHslColorComponents);
            }
            // If it's a fontSize array, return it directly if no HSL/alpha processing is needed.
            elseif (is_array($valueFromTheme) && $category === 'fontSize') {
                if ($alphaOverride === null && !$resolveToHslColorComponents) {
                    return $valueFromTheme;
                }
                // If HSL/alpha is requested for fontSize, it's likely a misuse; take the first element if string.
                $resolvedValue = (isset($valueFromTheme[0]) && is_string($valueFromTheme[0])) ? $valueFromTheme[0] : null;
            }
            // If it's a string value from theme (e.g., a hex code, HSL string, or a var())
            elseif (is_string($valueFromTheme)) {
                $resolvedValue = $valueFromTheme;
            }
            // If it's an array but not fontSize (e.g. boxShadow), and no HSL/alpha processing, return as is.
            elseif (is_array($valueFromTheme) && $alphaOverride === null && !$resolveToHslColorComponents) {
                return $valueFromTheme;
            }
        }

        if ($resolvedValue === null) $resolvedValue = $fallback;
        if ($resolvedValue === null) return null;


        // At this point, $resolvedValue should be a string (a color value or a CSS variable string)
        // or we have already returned an array for fontSize.
        if (!is_string($resolvedValue)) {
            // If it's still an array here, it means it wasn't fontSize and wasn't resolved to a string,
            // and HSL/alpha processing was requested. This is an ambiguous state.
            return $fallback; // Or null, or handle as error
        }

        // If we need to resolve to HSL components (e.g., for setting CSS vars like --primary: H S% L%)
        if ($resolveToHslColorComponents) {
            // convertColorToHslComponentsString now takes alpha and returns "H S% L% / A" if alpha is present
            $hslComponents = $this->convertColorToHslComponentsString($resolvedValue, $alphaOverride);
            return $hslComponents ?? $fallback; // Fallback if conversion fails
        }

        // If we need to apply an alpha override to a resolved color string (and not return HSL components)
        if ($alphaOverride !== null) {
            // If the resolved value is already a CSS variable that represents HSL components
            // e.g., var(--primary-hsl) which might be defined as "221.2 83.2% 53.3%"
            if (preg_match('/^var\((--[a-zA-Z0-9-]+)\)$/', $resolvedValue, $varMatch)) {
                // We assume the variable $varMatch[1] holds HSL components.
                // So we construct hsla(var(--var-name), alpha_value)
                return "hsla(var({$varMatch[1]}), " . round($alphaOverride, 2) . ")";
            }
            // For direct color strings (hex, rgb, hsl, named colors)
            $colorWithAlpha = $this->convertColorToRgba($resolvedValue, $alphaOverride);
            return $colorWithAlpha ?? $resolvedValue; // Fallback
        }
        
        // Default: return the resolved value as is (could be hex, rgb(), hsl(), var(), etc.)
        return $resolvedValue;
    }

    // Helper to build a CSS rule string from an array of properties
    private function buildCssRuleString(string $selector, array $properties): string {
        $css = "{$selector} {\n";
        foreach ($properties as $prop => $value) {
            if ($value !== null) { // Avoid adding properties with null values
                $css .= "  {$this->camelToKebab($prop)}: {$value};\n";
            }
        }
        $css .= "}\n";
        return $css;
    }

    // Method to generate prose styles (called from generateCss or buildFinalCssOutput)
    private function getTypographyBaseStyles(): string {
        $proseConfig = $this->config['typography'] ?? null;
        if (!$proseConfig || !isset($proseConfig['className'])) return "";

        $outputCss = "";
        $baseClassName = '.' . $this->escapeClassNameForSelector($proseConfig['className']);

        // 1. Apply base CSS variables for .prose (light mode defaults)
        $baseCssVariables = [];
        if (isset($proseConfig['cssVariables']['DEFAULT']) && is_array($proseConfig['cssVariables']['DEFAULT'])) {
            foreach ($proseConfig['cssVariables']['DEFAULT'] as $varName => $varValueConfig) {
                if (strpos($varName, '--tw-prose-invert-') === false) { // Only non-invert for base
                    $resolvedVal = $this->resolveThemeValue($varValueConfig, null, false);
                    if ($resolvedVal !== null) $baseCssVariables[$varName] = $resolvedVal;
                }
            }
        }
        if (!empty($baseCssVariables)) {
            $outputCss .= $this->buildCssRuleString($baseClassName, $baseCssVariables);
        }
        
        // 2. Compile and add DEFAULT element styles under .prose
        if (isset($proseConfig['elements']['DEFAULT']) && is_array($proseConfig['elements']['DEFAULT'])) {
            $elementRules = $this->compileProseElementStyles($baseClassName, $proseConfig['elements']['DEFAULT'], false);
            if(!empty($elementRules)) $outputCss .= $this->buildCssRulesToString($elementRules);
        }

        // 3. Compile and add styles for .dark .prose
        $darkProseSelector = '.dark ' . $baseClassName;
        $darkCssVariablesToApply = [];
        // Define the --tw-prose-invert-* variables themselves within .dark .prose context
        if (isset($proseConfig['cssVariables']['DEFAULT']) && is_array($proseConfig['cssVariables']['DEFAULT'])) {
            foreach ($proseConfig['cssVariables']['DEFAULT'] as $varName => $varValueConfig) {
                if (strpos($varName, '--tw-prose-invert-') === 0) {
                    $resolvedVal = $this->resolveThemeValue($varValueConfig, null, true); // true for isDarkMode
                    if ($resolvedVal !== null) $darkCssVariablesToApply[$varName] = $resolvedVal;
                }
            }
        }
        // Map main prose vars to their invert counterparts
        if (isset($proseConfig['elements']['DEFAULT']['dark']) && is_array($proseConfig['elements']['DEFAULT']['dark'])) {
            foreach ($proseConfig['elements']['DEFAULT']['dark'] as $cssVarLikeProp => $targetInvertVarString) {
                if (strpos($cssVarLikeProp, '--tw-prose-') === 0 && is_string($targetInvertVarString) && strpos($targetInvertVarString, 'var(--tw-prose-invert-') === 0) {
                    $darkCssVariablesToApply[$cssVarLikeProp] = $targetInvertVarString;
                }
            }
        }
        if (!empty($darkCssVariablesToApply)) {
            $outputCss .= $this->buildCssRuleString($darkProseSelector, $darkCssVariablesToApply);
        }
        
        // Element styles specific to dark mode (if defined in elements.DEFAULT.dark)
        if (isset($proseConfig['elements']['DEFAULT']['dark']) && is_array($proseConfig['elements']['DEFAULT']['dark'])) {
            $darkElementSpecificStyles = [];
            foreach($proseConfig['elements']['DEFAULT']['dark'] as $elementOrSelector => $styles){
                if(strpos($elementOrSelector, '--tw-prose-') !== 0){ // Avoid reprocessing var mappings
                    $darkElementSpecificStyles[$elementOrSelector] = $styles;
                }
            }
            if(!empty($darkElementSpecificStyles)){
                $darkElementRules = $this->compileProseElementStyles($darkProseSelector, $darkElementSpecificStyles, true);
                if(!empty($darkElementRules)) $outputCss .= $this->buildCssRulesToString($darkElementRules);
            }
        }

        // 4. Process modifiers (e.g., .prose-sm, .prose-lg, .prose-invert)
        foreach (($proseConfig['modifiers'] ?? []) as $modifierName => $modifierConfigArray) {
            if (!is_array($modifierConfigArray)) continue;

            $modifierSelector = $baseClassName . ($modifierName === 'DEFAULT' ? '' : '-' . $this->escapeClassNameForSelector($modifierName));
            
            $modifierScopedCssVariables = [];
            if (isset($modifierConfigArray['css']) && is_array($modifierConfigArray['css'])) {
                foreach ($modifierConfigArray['css'] as $varName => $varValueConfig) {
                    $resolvedVal = $this->resolveThemeValue($varValueConfig, null, ($modifierName === 'invert'));
                    if($resolvedVal !== null) $modifierScopedCssVariables[$varName] = $resolvedVal;
                }
            }
            if(!empty($modifierScopedCssVariables)){
                $outputCss .= $this->buildCssRuleString($modifierSelector, $modifierScopedCssVariables);
            }

            $elementOverrides = $this->compileProseElementStyles($modifierSelector, $modifierConfigArray, ($modifierName === 'invert'));
            if(!empty($elementOverrides)) $outputCss .= $this->buildCssRulesToString($elementOverrides);

            if (isset($modifierConfigArray['dark']) && is_array($modifierConfigArray['dark'])) {
                $darkModifierSelector = '.dark ' . $modifierSelector;
                $darkModifierCssVars = [];
                if (isset($modifierConfigArray['dark']['css']) && is_array($modifierConfigArray['dark']['css'])) {
                    foreach($modifierConfigArray['dark']['css'] as $varName => $varValueConfig){
                        $resolvedVal = $this->resolveThemeValue($varValueConfig, null, true);
                        if($resolvedVal !== null) $darkModifierCssVars[$varName] = $resolvedVal;
                    }
                }
                if(!empty($darkModifierCssVars)){
                    $outputCss .= $this->buildCssRuleString($darkModifierSelector, $darkModifierCssVars);
                }
                $darkElementOverrides = $this->compileProseElementStyles($darkModifierSelector, $modifierConfigArray['dark'], true);
                if(!empty($darkElementOverrides)) $outputCss .= $this->buildCssRulesToString($darkElementOverrides);
            }
        }
        return $outputCss;
    }

    private function compileProseElementStyles(string $baseSelector, array $elementStylesConfig, bool $isDarkModeContext): array {
        $compiledRules = [];
        foreach ($elementStylesConfig as $elementOrSelector => $styles) {
            // Skip special keys or non-array style definitions
            if ($elementOrSelector === 'dark' || $elementOrSelector === 'DEFAULT' || $elementOrSelector === 'css' || !is_array($styles)) {
                continue;
            }

            $fullSelector = '';
            // Selector generation logic:
            // & refers to the $baseSelector itself (e.g., .prose)
            if (strpos($elementOrSelector, '&') === 0) {
                $fullSelector = str_replace('&', $baseSelector, $elementOrSelector);
            } 
            // Direct child or descendant combinators, pseudo-classes/elements attached to $baseSelector
            elseif (strpos($elementOrSelector, '>') === 0 || strpos($elementOrSelector, '+') === 0 || strpos($elementOrSelector, '~') === 0 || strpos($elementOrSelector, '[') === 0 || strpos($elementOrSelector, ':') === 0 ) {
                $fullSelector = $baseSelector . $elementOrSelector;
            } 
            // Standard element tags or nested selectors within $baseSelector
            else {
                $fullSelector = $baseSelector . ' ' . $elementOrSelector;
            }
                
            $cssProps = []; // Stores direct properties for $fullSelector
            // $pseudoClassStyles was a good idea, but let's try to generate selectors directly

            foreach ($styles as $prop => $val) {
                $kebabProp = $this->camelToKebab($prop);

                // Handle nested pseudo-classes/elements like ':hover', '::before' within an element's styles
                if (strpos($prop, ':') === 0 || strpos($prop, '::') === 0) {
                    $nestedSelector = $fullSelector . $prop; // e.g., .prose a:hover
                    if (is_array($val)) { // Ensure $val is an array of properties for the pseudo-selector
                        $nestedCssProps = [];
                        foreach ($val as $nestedProp => $nestedValConfig) {
                            // Resolve value for the nested pseudo-selector's property
                            $resolvedNestedVal = $this->resolveThemeValue($nestedValConfig, null, $isDarkModeContext, null, false);
                            if ($resolvedNestedVal !== null) {
                                $kebabNestedProp = $this->camelToKebab($nestedProp);
                                // Apply hsl(var(...)) for color properties if value is a prose variable
                                if (is_string($resolvedNestedVal) && strpos($resolvedNestedVal, '--tw-prose-') !== false && 
                                    ($kebabNestedProp === 'color' || $kebabNestedProp === 'background-color' || strpos($kebabNestedProp, '-color') !== false)) {
                                    $nestedCssProps[$kebabNestedProp] = "hsl(var({$resolvedNestedVal}))";
                                } else {
                                    $nestedCssProps[$kebabNestedProp] = $resolvedNestedVal;
                                }
                            }
                        }
                        if (!empty($nestedCssProps)) {
                            $compiledRules[$nestedSelector] = array_merge($compiledRules[$nestedSelector] ?? [], $nestedCssProps);
                        }
                    }
                } else { // Regular property for $fullSelector
                    // Resolve the value (could be direct, theme reference, or CSS variable for prose)
                    $resolvedVal = $this->resolveThemeValue($val, null, $isDarkModeContext, null, false);

                    if (is_array($resolvedVal) && $prop === 'fontSize' && isset($resolvedVal[0])) {
                        $cssProps[$kebabProp] = $resolvedVal[0]; // Font size value
                        if (isset($resolvedVal[1]['lineHeight'])) { // Associated line height
                            // Resolve line height, it might be a keyword or a numeric value
                            $lineHeightLookupKey = $resolvedVal[1]['lineHeight'];
                            $lineHeightVal = $this->lookupThemeValue('lineHeight', $lineHeightLookupKey) ?? $lineHeightLookupKey;
                            $cssProps['line-height'] = $lineHeightVal;
                        }
                    } elseif (is_string($resolvedVal)) {
                        // If the property is color-related and its value is a prose CSS variable, wrap with hsl()
                        // This assumes the CSS variable itself stores HSL components.
                        if (strpos($resolvedVal, 'var(--tw-prose-') === 0 && 
                            ($kebabProp === 'color' || $kebabProp === 'background-color' || strpos($kebabProp, '-color') !== false)) {
                            // If the var itself is already wrapped (e.g. var(--tw-prose-links) resolved to 'hsl(var(--actual-hsl-var))')
                            // then this re-wrapping is redundant.
                            // Better to ensure resolveThemeValue for prose variables returns the HSL components.
                            // And getThemeCssVariables sets --tw-prose-links: H S% L%;
                            // So here, it should be: color: hsl(var(--tw-prose-links));
                            
                            // Let's assume $resolvedVal is the *name* of the variable here, if $val was ['var'=>'--tw-prose-links']
                            // If $val was already 'var(--tw-prose-links)', then $resolvedVal is 'var(--tw-prose-links)'
                            
                            // If $val was ['theme' => 'colors.gray.700'], $resolvedVal would be '#374151'
                            // If $val was '--tw-prose-body' (string literal from config), $resolvedVal is '--tw-prose-body'
                            // And we need to make it `hsl(var(--tw-prose-body))`
                            
                            // If $resolvedVal IS the variable name (e.g., --tw-prose-body)
                            if (strpos($resolvedVal, '--tw-prose-') === 0) {
                                $cssProps[$kebabProp] = "hsl(var({$resolvedVal}))";
                            } else {
                                // If $resolvedVal is already a complete CSS value (e.g. a hex code resolved from theme)
                                // and it's a color prop, we still want to use the prose variable system if possible.
                                // This part is tricky. The config structure for `elements` is key.
                                // If elements.DEFAULT.p.color is 'var(--tw-prose-body)', then $resolvedVal will be 'var(--tw-prose-body)'
                                // and the previous `if` will catch it.
                                $cssProps[$kebabProp] = $resolvedVal;
                            }

                        } else {
                            $cssProps[$kebabProp] = $resolvedVal;
                        }
                    }
                }
            }
            if (!empty($cssProps)) {
                $compiledRules[$fullSelector] = array_merge($compiledRules[$fullSelector] ?? [], $cssProps);
            }
        }
        return $compiledRules;
    }
    
    private function camelToKebab(string $string): string {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $string));
    }

    private function buildCssRulesToString(array $cssRules): string {
        $string = "";
        ksort($cssRules);
        foreach ($cssRules as $selector => $properties) {
            $string .= "{$selector} {\n";
            ksort($properties);
            foreach ($properties as $prop => $value) {
                if ($value !== null) {
                    if (is_array($value)) {
                        print_r($value);
                        // This case should ideally be handled by the utility generator.
                        // Log this or decide on a default conversion (e.g., implode).
                        // For now, let's skip it to prevent errors, but this indicates a bug in a handler.
                        // error_log("Warning: CSS property '{$prop}' for selector '{$selector}' received an array value: " . json_encode($value));
                        continue; 
                    }
                    $string .= "  {$prop}: {$value};\n";
                }
            }
            $string .= "}\n";
        }
        return $string;
    }
    private function buildMediaQueriesToString(array $mediaQueries): string {
        $string = "";

        // Custom sort to ensure media queries are ordered correctly (min-width mobile-first)
        uksort($mediaQueries, function ($a, $b) {
            $aMinWidth = PHP_INT_MAX;
            $bMinWidth = PHP_INT_MAX;

            // Extract min-width from media query string
            if (preg_match('/min-width:\s*(\d+\.?\d*)/', $a, $matchesA)) {
                $aMinWidth = (float) $matchesA[1];
            }
            if (preg_match('/min-width:\s*(\d+\.?\d*)/', $b, $matchesB)) {
                $bMinWidth = (float) $matchesB[1];
            }

            // Handle max-width queries by giving them a lower precedence (sorting them after min-width)
            if (strpos($a, 'max-width') !== false && strpos($b, 'min-width') !== false) return 1;
            if (strpos($a, 'min-width') !== false && strpos($b, 'max-width') !== false) return -1;
            
            if ($aMinWidth !== $bMinWidth) {
                return $aMinWidth <=> $bMinWidth;
            }

            // If min-widths are the same or not present, fallback to string comparison
            return strcmp($a, $b);
        });

        foreach ($mediaQueries as $mediaConditionWithAt => $rules) {
            if (!empty($rules)) {
                $string .= "{$mediaConditionWithAt} {\n";
                $string .= $this->buildCssRulesToString($rules);
                $string .= "}\n";
            }
        }
        return $string;
    }

    private function getClassesFromCss(string $cssContent): array {
        // এটি একটি সহজ পার্সার, শুধুমাত্র ইউটিলিটি ক্লাস খুঁজবে, @apply নয়
        preg_match_all('/\.([a-zA-Z0-9\-_:\[\]\(\)\/.,%]+)/', $cssContent, $matches);
        return $matches[1] ?? [];
    }

    private function parseApplyDirectives(string $cssContent): string {
        $outputCss = '';
        // Regex to find CSS rules containing @apply
        preg_match_all('/([^{]+)\{([^}]+@apply[^}]+)\}/s', $cssContent, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $selector = trim($match[1]);
            $body = trim($match[2]);

            $properties = [];
            $applyLine = '';

            // Separate @apply line from other properties
            $lines = explode(';', $body);
            foreach ($lines as $line) {
                if (strpos(trim($line), '@apply') === 0) {
                    $applyLine = trim($line);
                } elseif (trim($line) !== '') {
                    list($prop, $val) = explode(':', $line, 2);
                    $properties[trim($prop)] = trim($val);
                }
            }
            
            // Extract utility classes from the @apply line
            $utilitiesString = trim(substr($applyLine, strlen('@apply')));
            $utilityClasses = preg_split('/\s+/', $utilitiesString);

            $generatedStyles = [];
            $pseudoStyles = [];

            // Generate styles for each utility class
            foreach ($utilityClasses as $className) {
                if (empty($className)) continue;
                
                // এই মেথডটি parseClass থেকে রিফ্যাক্টর করা একটি helper মেথড
                $style = $this->getStyleForClass($className); 

                if ($style) {
                    // সিউডো-স্টাইল (যেমন hover:, focus:) এবং বেস স্টাইল আলাদা করুন
                    foreach ($style['styles'] as $key => $value) {
                        if (str_starts_with($key, '_')) {
                            $pseudoKey = lcfirst(substr($key, 1, -6)); // _hoverStyles -> hover
                            $pseudoStyles[$pseudoKey] = array_merge($pseudoStyles[$pseudoKey] ?? [], $value);
                        } else {
                            $generatedStyles[$key] = $value;
                        }
                    }
                }
            }

            // মূল সিলেক্টরের জন্য CSS তৈরি করুন
            $finalStyles = array_merge($generatedStyles, $properties);
            if (!empty($finalStyles)) {
                $outputCss .= $this->buildCssRuleString($selector, $finalStyles);
            }

            // সিউডো-সিলেক্টরগুলোর জন্য CSS তৈরি করুন
            $pseudoMap = $this->getPseudoMap(); // Helper to get all pseudo-class mappings
            foreach ($pseudoStyles as $key => $props) {
                if (isset($pseudoMap[$key]) && !empty($props)) {
                    $outputCss .= $this->buildCssRuleString($selector . $pseudoMap[$key], $props);
                }
            }
        }
        
        return $outputCss;
    }

    private function getStyleForClass(string $className): ?array {
        $parts = preg_split("/(?<!\[){$this->config['separator']}(?![^\[]*\])/", $className);
        $baseClassPart = array_pop($parts);
        $modifiers = $parts;

        // হ্যান্ডলার খুঁজে স্টাইল তৈরি করুন
        foreach ($this->utilityHandlers as $handlerConfig) {
            if (preg_match($handlerConfig['pattern'], $baseClassPart, $matches)) {
                $handlerMethod = $handlerConfig['handler'];
                $styleData = is_string($handlerMethod) ? $this->$handlerMethod($baseClassPart, $matches, $modifiers) : call_user_func($handlerMethod, $baseClassPart, $matches, $modifiers);
                
                if ($styleData !== null) {
                    $generatedStyle = is_array($styleData) && isset($styleData['style']) ? $styleData['style'] : $styleData;
                    if (is_string($generatedStyle)) continue; // @apply স্ট্রিং রিটার্ন করা হ্যান্ডলার সাপোর্ট করে না

                    // ভ্যারিয়েন্ট প্রয়োগ করুন
                    return $this->applyModifiersToStyle('temp-selector', $generatedStyle, $modifiers, false);
                }
            }
        }
        return null;
    }

    private function buildFinalCssOutput(bool $modular = false): string {
        $output = "";

        // --- ধাপ ১: @property রুল যোগ করা (সবার আগে) ---
        if (!empty($this->neededProperties)) {
            $output .= "/* CSS @property Rules */\n";
            foreach ($this->neededProperties as $name => $props) {
                $output .= "@property --{$name} {\n";
                foreach ($props as $key => $value) {
                    $output .= "  {$this->camelToKebab($key)}: {$value};\n";
                }
                $output .= "}\n";
            }
            $output .= "\n";
        }

        // --- ধাপ ২: প্রতিটি লেয়ারের জন্য CSS তৈরি এবং একত্রিত করা ---
        foreach ($this->config['layers'] as $layerName) {
            if ($modular === true && $layerName === 'base') {
                continue;
            }

            if (empty($this->layerCss[$layerName])) {
                continue;
            }

            $layerContent = "";
            $mediaQueries = [];

            // ক. বেস রুল এবং মিডিয়া কোয়েরি আলাদা করা
            foreach ($this->layerCss[$layerName] as $cssBlock) {
                if (preg_match('/^@(media|container|supports)/', trim($cssBlock), $matches)) {
                    // মিডিয়া কোয়েরি ব্লককে তার কন্ডিশনসহ সংরক্ষণ করা
                    preg_match('/^(@[^{]+)\s*\{([\s\S]*)\}/', $cssBlock, $mqMatches);
                    if (count($mqMatches) === 3) {
                        $condition = trim($mqMatches[1]);
                        $rules = trim($mqMatches[2]);
                        if (!isset($mediaQueries[$condition])) {
                            $mediaQueries[$condition] = "";
                        }
                        $mediaQueries[$condition] .= $rules . "\n";
                    }
                } else {
                    // বেস রুল (মিডিয়া কোয়েরি ছাড়া)
                    $layerContent .= $cssBlock . "\n";
                }
            }

            // খ. লেয়ার ব্লকের আউটপুট তৈরি করা
            if (!empty(trim($layerContent)) || !empty($mediaQueries)) {
                $output .= "@layer {$layerName} {\n";

                // বেস রুলগুলো যোগ করা
                if (!empty(trim($layerContent))) {
                    $output .= rtrim($layerContent) . "\n";
                }

                // মিডিয়া কোয়েরিগুলো সর্ট করে যোগ করা
                if (!empty($mediaQueries)) {
                    // উন্নত সর্টিং লজিক
                    uksort($mediaQueries, function ($a, $b) {
                        $getPriority = function ($query) {
                            if (str_contains($query, 'min-width')) return 1;
                            if (str_contains($query, 'max-width')) return 2;
                            if (str_contains($query, '@container')) return 3;
                            return 4; // অন্যান্য
                        };
                        
                        $priorityA = $getPriority($a);
                        $priorityB = $getPriority($b);
                        if ($priorityA !== $priorityB) return $priorityA <=> $priorityB;
                        
                        // একই প্রায়োরিটি হলে, স্ট্রিং হিসেবে তুলনা করা
                        return strcmp($a, $b);
                    });

                    foreach ($mediaQueries as $condition => $rules) {
                        $output .= "  {$condition} {\n";
                        $output .= "    " . rtrim(str_replace("\n", "\n    ", $rules)) . "\n";
                        $output .= "  }\n";
                    }
                }

                $output .= "}\n\n";
            }
        }
        
        // --- ধাপ ৩: কীফ্রেম যোগ করা (সাধারণত base লেয়ারে থাকে, তবে শেষেও রাখা যায়) ---
        if (!empty($this->neededKeyframes)) {
            // কীফ্রেমগুলো একটি লেয়ারের ভেতরে রাখা ভালো অভ্যাস
            $output .= "@layer base {\n";
            $output .= "  /* Keyframes */\n";
            foreach ($this->neededKeyframes as $name => $frames) {
                $output .= "  @keyframes {$name} {$frames}\n";
            }
            $output .= "}\n";
        }
        
        return $output;
    }

    private function minifyCss(string $css): string {
        // 1. Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        
        // 2. Remove tabs, newlines, and multiple spaces
        $css = str_replace(["\r\n", "\r", "\n", "\t"], '', $css);
        $css = preg_replace('/\s+/', ' ', $css);

        // 3. Remove spaces around operators, but be careful with `calc()`
        // This regex is safer because it avoids removing spaces around `+` and `-` which are crucial for calc()
        $css = preg_replace('/\s*([,;{}:])\s*/', '\1', $css);

        // 4. Remove trailing semicolons before closing brace
        $css = str_replace(';}', '}', $css);
        
        // 5. Remove empty rules
        $css = preg_replace('/[^{}]+\{\s*\}/', '', $css);
        
        return trim($css);
    }

    public function buildCss(bool $modular = false): string {
        // --- ধাপ ১: রিসোর্স রিসেট এবং ক্লাস সংগ্রহ ---
        $this->resetBuildState();
        
        $fullHtml = implode("\n", $this->htmlContents);
        $classesFromSource = $this->getClassesFromHtml($fullHtml);
        $safelist = $this->config['safelist'] ?? [];
        $allInitialClasses = array_unique(array_merge($classesFromSource, $safelist));

        // --- ধাপ ২: Bootstrap ক্লাসগুলোকে @apply রুলে রূপান্তর ---
        $cssFromBs = '';
        $tailwindClassesInjectedByBs = [];

        if ($this->config['corePlugins']['bs2tw'] ?? false) {
            // convertBootstrapToCss helper ফাংশনটি কল করা হচ্ছে
            $cssFromBs = $this->convertBootstrapToCss($allInitialClasses, $tailwindClassesInjectedByBs);
        }
        
        // --- ধাপ ৩: সমস্ত ইউটিলিটি ক্লাস একত্রিত করা ---
        $allUtilityClasses = array_unique(array_merge($allInitialClasses, $tailwindClassesInjectedByBs));

        // --- ধাপ ৪: সমস্ত @apply রুল প্রসেস করা (সঠিক সিনট্যাক্স) ---
        // ভুল সিনট্যাক্সটি এখানে ঠিক করা হয়েছে
        $fullCssToParseForApply = implode("\n", $this->cssContents) . "\n" . $cssFromBs;
        $cssFromApply = $this->parseApplyDirectives($fullCssToParseForApply);

        // --- ধাপ ৫: বেস এবং কম্পোনেন্ট লেয়ার তৈরি করা ---
        if ($modular === false) {
            $this->layerCss['base'][] = $this->getPreflightCss();
            $this->layerCss['base'][] = $this->getThemeCssVariables();
            $this->layerCss['base'][] = $this->getFormsBaseStyles();
            $this->layerCss['base'][] = $this->getTypographyBaseStyles();
            $this->layerCss['base'][] = $this->getBaseTableCss();
        }
        
        if (!empty(trim($cssFromApply))) {
            $this->layerCss['components'][] = "/* Styles from @apply & Bootstrap Compatibility */\n" . $cssFromApply;
        }

        // --- ধাপ ৬: সমস্ত ইউটিলিটি ক্লাস থেকে CSS জেনারেট করা ---
        $cssRulesByLayer = ['utilities' => []];
        $mediaQueriesByLayer = ['utilities' => []];
        
        foreach ($allUtilityClasses as $className) {
            // যে Bootstrap ক্লাসগুলো ইতিমধ্যে @apply-তে রূপান্তরিত হয়েছে, সেগুলোকে আর পার্স করার দরকার নেই।
            // if ($this->isBootstrapClass($className)) {
            //     continue;
            // }
            $this->parseClass($className, $className, $cssRulesByLayer, $mediaQueriesByLayer);
        }

        foreach ($cssRulesByLayer as $layer => $rules) {
            if (!empty($rules)) $this->layerCss[$layer][] = $this->buildCssRulesToString($rules);
        }
        foreach ($mediaQueriesByLayer as $layer => $queries) {
            if (!empty($queries)) $this->layerCss[$layer][] = $this->buildMediaQueriesToString($queries);
        }
        
        // --- ধাপ ৭: চূড়ান্ত CSS আউটপুট ---
        $finalCss = $this->buildFinalCssOutput($modular);

        if ($this->config['corePlugins']['minify'] ?? false) {
            $finalCss = $this->minifyCss($finalCss);
        }
        
        // --- ধাপ ৮: পরবর্তী বিল্ডের জন্য রিসোর্স পরিষ্কার করা ---
        $this->htmlContents = [];
        $this->cssContents = [];

        return $finalCss;
    }

    private function isBootstrapClass(string $className): bool {
        if (isset($this->bsToTwMap[$className])) {
            return true;
        }
        foreach ($this->dynamicBsToTwPatterns as $pattern => $template) {
            if (preg_match($pattern, $className)) {
                return true;
            }
        }
        return false;
    }

    private function resetBuildState(): void {
        $this->neededKeyframes = [];
        $this->neededProperties = [];
        $this->generatedUtilitySignatures = [];
        $this->preflightAdded = false;
        $this->layerCss = ['base' => [], 'components' => [], 'utilities' => []];
    }

    private function convertBootstrapToCss(array $allClasses, array &$tailwindClassesInjectedByBs): string {
        $cssRules = []; 

        // Helper Maps for dynamic patterns resolution
        $map = [
            'grid' => ['1'=>'1', '2'=>'2', '3'=>'3', '4'=>'4', '5'=>'5', '6'=>'6', '7'=>'7', '8'=>'8', '9'=>'9', '10'=>'10', '11'=>'11', '12'=>'12'],
            'spacing' => ['0'=>'0', '1'=>'1', '2'=>'2', '3'=>'4', '4'=>'6', '5'=>'8', 'auto'=>'auto'],
            'bs_axis' => ['t'=>'t', 'b'=>'b', 's'=>'l', 'e'=>'r', 'start'=>'l', 'end'=>'r', 'top'=>'t', 'bottom'=>'b', 'x'=>'x', 'y'=>'y'],
            'rounded' => ['0'=>'none', '1'=>'sm', '2'=>'md', '3'=>'lg', '4'=>'xl', '5'=>'2xl'],
            'rounded_pos' => ['top'=>'t-md', 'bottom'=>'b-md', 'start'=>'l-md', 'end'=>'r-md', 'circle'=>'full', 'pill'=>'full'],
            'text_align' => ['start'=>'left', 'end'=>'right', 'center'=>'center'],
            'display' => ['none'=>'hidden', 'inline'=>'inline', 'inline-block'=>'inline-block', 'block'=>'block', 'table'=>'table', 'flex'=>'flex', 'grid'=>'grid']
        ];

        // --- Core Processor Function ---
        $processConfig = function(string $classSelector, array|string $config) use (&$cssRules, &$tailwindClassesInjectedByBs) {
            // Check for complex selectors (like '.foo > .bar') or simple tag selectors (like 'h1')
            $isComplexSelector = preg_match('/[ >~+:]/', $classSelector) || in_array($classSelector, ['h1','h2','h3','h4','h5','h6','p','table','img','figure','hr']);

            // If it's complex or a tag, use it as is. Otherwise, treat it as a class name.
            $selector = $isComplexSelector ? $classSelector : '.' . $this->escapeClassNameForSelector($classSelector);
            
            // Normalize config to array structure
            $configArray = is_string($config) ? ['base' => $config] : $config;

            // 1. Base Styles
            if (!empty($configArray['base'])) {
                $cssRules[$selector][] = $configArray['base'];
                $tailwindClassesInjectedByBs = array_merge($tailwindClassesInjectedByBs, $this->smartSplit($configArray['base'], ' '));
            }

            // 2. Transitions (Added to base selector)
            if (!empty($configArray['transition'])) {
                $cssRules[$selector][] = $configArray['transition'];
                $tailwindClassesInjectedByBs = array_merge($tailwindClassesInjectedByBs, $this->smartSplit($configArray['transition'], ' '));
            }

            // 3. States (hover, focus, active, disabled)
            foreach ($configArray['states'] ?? [] as $state => $utilities) {
                // Tailwind style pseudo handling (e.g., .btn:hover)
                $cssRules["{$selector}:{$state}"][] = $utilities;
                $tailwindClassesInjectedByBs = array_merge($tailwindClassesInjectedByBs, $this->smartSplit($utilities, ' '));
            }

            // 4. Advanced Pseudo Selectors (::before, ::after, > child)
            foreach ($configArray['pseudo'] ?? [] as $pseudoSelector => $utilities) {
                // Combine parent selector with pseudo (e.g., .btn::after)
                $fullSelector = str_starts_with($pseudoSelector, ' ') ? $selector . $pseudoSelector : $selector . $pseudoSelector;
                $cssRules[$fullSelector][] = $utilities;
                $tailwindClassesInjectedByBs = array_merge($tailwindClassesInjectedByBs, $this->smartSplit($utilities, ' '));
            }

            // 5. Dark Mode Support
            if (isset($configArray['dark']['base'])) {
                $darkSelector = '.dark ' . $selector;
                $cssRules[$darkSelector][] = $configArray['dark']['base'];
                $tailwindClassesInjectedByBs = array_merge($tailwindClassesInjectedByBs, $this->smartSplit($configArray['dark']['base'], ' '));
            }
        };

        // --- Main Processing Loop ---
        foreach ($allClasses as $class) {
            // ১. স্ট্যাটিক ম্যাপ চেকিং (Fastest)
            if (isset($this->bsToTwMap[$class])) {
                $processConfig($class, $this->bsToTwMap[$class]);
                continue;
            }

            // ২. ডাইনামিক প্যাটার্ন চেকিং (Regex)
            foreach ($this->dynamicBsToTwPatterns as $pattern => $template) {
                if (preg_match($pattern, $class, $matches)) {

                    if ($template instanceof \Closure) {
                        // Call the closure with matches to get the result (string or array)
                        $resolvedConfig = $template($matches);
                        $processConfig($class, $resolvedConfig);
                        break;
                    }
                    
                    // Recursive function to replace placeholders {1}, {d_map_name:1} in the template
                    $resolveTemplate = function($item) use ($matches, $map) {
                        if (!is_string($item)) return $item;

                        return preg_replace_callback('/\{(?:d_map_(\w+):)?(\d+)\}/', function($m) use ($matches, $map) {
                            $matchIndex = (int)$m[2];
                            $value = $matches[$matchIndex] ?? '';
                            
                            // Check if mapping is required
                            if (!empty($m[1]) && isset($map[$m[1]])) {
                                return $map[$m[1]][$value] ?? $value;
                            }
                            
                            return $value;
                        }, $item);
                    };

                    // Handle String Template directly
                    if (is_string($template)) {
                        $resolvedConfig = $resolveTemplate($template);
                        $processConfig($class, $resolvedConfig);
                    } 
                    // Handle Array Configuration (deeply nested)
                    elseif (is_array($template)) {
                        // Use array_walk_recursive logic manually to preserve keys structure
                        $resolvedConfig = $template;
                        array_walk_recursive($resolvedConfig, function(&$value) use ($resolveTemplate) {
                            $value = $resolveTemplate($value);
                        });
                        $processConfig($class, $resolvedConfig);
                    }
                    
                    break; // Stop after first match to prioritize order
                }
            }
        }
        
        // --- Generate Final CSS String using @apply ---
        $cssFromBs = '';
        foreach ($cssRules as $selector => $utilitiesList) {
            // Flatten unique utilities
            $mergedUtilities = [];
            foreach ($utilitiesList as $utils) {
                $mergedUtilities = array_merge($mergedUtilities, $this->smartSplit($utils, ' '));
            }
            $uniqueUtilities = implode(' ', array_unique(array_filter($mergedUtilities)));
            
            if (!empty($uniqueUtilities)) {
                $cssFromBs .= "{$selector} { @apply {$uniqueUtilities}; }\n";
            }
        }
        
        return $cssFromBs;
    }

}

?>