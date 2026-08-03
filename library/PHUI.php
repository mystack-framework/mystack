<?php

/**
 * ============================================================================
 * Class: PHUI (PHP User Interface & Design Universe)
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * PHUI is a revolutionary, world-class A-Z component-based UI design system 
 * for the MyStack framework. It natively replaces complex templating engines 
 * (like Blade or Twig) and supports over 7000+ hyper-fidelity components.
 *
 * Features:
 * - Pure PHP Component Rendering with zero third-party dependencies.
 * - Anti-Recursion Crash Guards (Death-Loop protection up to 32 depth).
 * - Full integration with Tailwind CSS via PHCS and UI interactivity via PHJS.
 * - Placeholder Alias engine (`{{key|Default}}`) for dynamic slot injection.
 * 
 * Usage Example:
 * ```php
 * // Render a standard HTML element with attributes
 * echo PHUI::ui('html:button', ['class' => 'bg-blue-600 text-white', 'text' => 'Submit']);
 * 
 * // Render a complex, nested pre-built UI block
 * echo PHUI::ui('section:hero', ['title' => 'Welcome to MyStack', 'subtitle' => 'The Future of PHP']);
 *
 * // Semantic kit API with Tailwind, PHJS/app.js and HTMX integration
 * echo PHUI::element('button:primary', [
 *     'slot' => 'Save',
 *     'class' => 'w-full shadow-lg',
 *     'phjs' => ['click' => 'toast "Saved"'],
 *     'htmx' => ['post' => '/save', 'target' => '#result'],
 * ]);
 * ```
 */




class PHUI
{
    private static array $registry = [];
    private static bool $booted = false;
    private static array $recursionStack = [];
    private static int $maxRecursionDepth = 32; // রিকার্সন ক্র্যাশ গার্ড

    public static function ui(string $slug, array $data = []): string { return self::render($slug, $data); }
    public static function element(string $slug, array $data = []): string { return self::renderType('element', $slug, $data); }
    public static function section(string $slug, array $data = []): string { return self::renderType('section', $slug, $data); }
    public static function layout(string $slug, array $data = []): string { return self::renderType('layout', $slug, $data); }
    public static function page(string $slug, array $data = []): string { return self::renderType('page', $slug, $data); }

    public static function exists(string $slug): bool
    {
        if (!self::$booted) self::boot();
        return self::resolveSlug($slug) !== null;
    }

    public static function register(string $slug, string|callable $template, array $meta = [], bool $replace = false): bool
    {
        self::boot();
        $slug = self::normalizeSlug($slug);
        if ($slug === '' || (!$replace && isset(self::$registry[$slug]))) {
            return false;
        }
        self::$registry[$slug] = array_merge($meta, [
            'title' => (string) ($meta['title'] ?? ucwords(str_replace([':', '-', '_'], ' ', $slug))),
            is_callable($template) ? 'renderer' : 'template' => $template,
        ]);
        return true;
    }

    public static function registerMany(array $components, bool $replace = false): int
    {
        $registered = 0;
        foreach ($components as $slug => $definition) {
            if (is_string($definition) || is_callable($definition)) {
                $registered += self::register((string) $slug, $definition, [], $replace) ? 1 : 0;
                continue;
            }
            if (!is_array($definition)) continue;
            $template = $definition['renderer'] ?? $definition['template'] ?? null;
            if (!is_string($template) && !is_callable($template)) continue;
            unset($definition['renderer'], $definition['template']);
            $registered += self::register((string) $slug, $template, $definition, $replace) ? 1 : 0;
        }
        return $registered;
    }

    public static function alias(string $alias, string $target, bool $replace = false): bool
    {
        self::boot();
        $alias = self::normalizeSlug($alias);
        $resolvedTarget = self::resolveSlug($target);
        if ($alias === '' || $resolvedTarget === null || (!$replace && isset(self::$registry[$alias]))) {
            return false;
        }
        self::$registry[$alias] = self::$registry[$resolvedTarget];
        self::$registry[$alias]['alias_of'] = $resolvedTarget;
        return true;
    }

    public static function search(string $query = '', ?string $group = null, int $limit = 50): array
    {
        self::boot();
        $query = strtolower(trim($query));
        $group = $group !== null ? strtolower(trim($group, " \t\n\r\0\x0B:")) : null;
        $result = [];
        foreach (self::$registry as $slug => $meta) {
            if ($group !== null && !str_starts_with(strtolower($slug), $group . ':')) continue;
            $haystack = strtolower($slug . ' ' . (string) ($meta['title'] ?? '') . ' ' . (string) ($meta['description'] ?? ''));
            if ($query !== '' && !str_contains($haystack, $query)) continue;
            $result[$slug] = $meta;
            if (count($result) >= max(1, min($limit, 500))) break;
        }
        return $result;
    }

    public static function categories(): array
    {
        self::boot();
        $categories = [];
        foreach (array_keys(self::$registry) as $slug) {
            $category = explode(':', $slug, 2)[0];
            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }
        arsort($categories);
        return $categories;
    }

    public static function count(): int
    {
        self::boot();
        return count(self::$registry);
    }

    public static function attributes(array|string|null $attributes): string
    {
        if ($attributes === null) return '';
        if (is_string($attributes)) return trim(self::sanitizeDynamicHtml($attributes));
        $rendered = [];
        foreach ($attributes as $name => $value) {
            if (is_int($name) && is_string($value)) {
                $rendered[] = trim($value);
                continue;
            }
            $name = strtolower(trim((string) $name));
            if ($name === '' || !preg_match('/^[a-z_:][a-z0-9_.:-]*$/i', $name) || $value === false || $value === null) continue;
            if ($value === true) {
                $rendered[] = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            $rendered[] = htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '="' .
                htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
        }
        return implode(' ', array_filter($rendered));
    }

    /**
     * Inspect dynamic component content without changing the component template.
     * PHUI intentionally supports nested HTML, so the guard targets executable
     * payloads instead of escaping all markup and breaking existing components.
     */
    public static function check(string $value): array
    {
        $sanitized = self::sanitizeDynamicHtml($value);
        return [
            'status' => hash_equals($value, $sanitized),
            'safe' => hash_equals($value, $sanitized),
            'changed' => !hash_equals($value, $sanitized),
            'data' => $sanitized,
        ];
    }

    private static function sanitizeDynamicHtml(string $value): string
    {
        if ($value === '' || (!str_contains($value, '<') && !preg_match('/(?:javascript|vbscript|data\s*:\s*text\/html)\s*:/i', $value))) {
            return $value;
        }

        $patterns = [
            '/<\s*(script|iframe|object|embed|base)\b[^>]*>.*?<\s*\/\s*\1\s*>/is' => '',
            '/<\s*(script|iframe|object|embed|base)\b[^>]*\/?\s*>/is' => '',
            '/<\s*meta\b[^>]*http-equiv\s*=\s*(["\']?)\s*(?:refresh|content-security-policy)\s*\1[^>]*>/is' => '',
            '/\s+on[a-z0-9_-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i' => '',
            '/\s+srcdoc\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i' => '',
            '/\s+(href|src|action|formaction|xlink:href)\s*=\s*(["\'])\s*(?:javascript|vbscript|data\s*:\s*text\/html)\s*:[^"\']*\2/i' => ' $1="#"',
            '/\s+style\s*=\s*(["\'])[^"\']*(?:expression\s*\(|url\s*\(\s*["\']?\s*javascript\s*:)[^"\']*\1/i' => '',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $value);
    }

    public static function render(string $slug, array $data = []): string
    {
        if (!self::$booted) self::boot();
        $requestedSlug = $slug;
        $slug = self::resolveSlug($slug) ?? $slug;
        $item = self::$registry[$slug] ?? null;
        if (!$item) return "<!-- PHUI: Item '$requestedSlug' not found -->";

        // রিকার্সন ডেথ-লুপ গার্ড
        if (isset(self::$recursionStack[$slug])) {
            return "<!-- PHUI: Infinite recursion detected for '$slug' -->";
        }
        if (count(self::$recursionStack) >= self::$maxRecursionDepth) {
            return "<!-- PHUI: Max recursion depth reached -->";
        }

        self::$recursionStack[$slug] = true;

        $data = self::prepareRuntimeData($slug, $data);
        $usedKeys = ['class', 'style', 'attr', 'attrs', 'style_attr', 'attr_str', 'slot', 'text', 'tw', 'css', 'phjs', 'on', 'htmx', 'state'];
        if (isset($data['class']) && is_array($data['class'])) {
            $data['class'] = implode(' ', array_filter($data['class']));
        }
        $data['class'] = $data['class'] ?? '';

        if (isset($data['style'])) {
            if (is_array($data['style'])) {
                $styles = [];
                foreach ($data['style'] as $k => $v) { $styles[] = "$k: $v;"; }
                $data['style'] = implode(' ', $styles);
            }
            $data['style_attr'] = 'style="' . htmlspecialchars($data['style'], ENT_QUOTES, 'UTF-8') . '"';
        } else {
            $data['style_attr'] = ''; $data['style'] = '';
        }

        $isSlug = function($val) {
            if (!is_string($val) || empty($val)) return false;
            return !str_contains($val, '<') && str_contains($val, ':') && preg_match('/^[a-z0-9_-]+:[a-z0-9:-]+$/i', $val);
        };

        if (isset($item['renderer']) && is_callable($item['renderer'])) {
            try {
                $html = (string) call_user_func($item['renderer'], $data, $slug);
                self::trackTailwind($html);
                return $html;
            } finally {
                unset(self::$recursionStack[$slug]);
            }
        }

        $template = (string) ($item['template'] ?? '');

        // Advanced Nested Array Resolver with Aliases
        foreach ($data as $dKey => &$dValue) {
            if (in_array($dKey, ['attr', 'attrs'], true)) continue;
            if (is_array($dValue) && strpos($template, "{{" . $dKey . "}}") !== false) {
                $isAssociative = array_keys($dValue) !== range(0, count($dValue) - 1);
                $renderedStr = '';
                
                if (!$isAssociative || (isset($dValue[1]) && is_array($dValue[1]))) {
                    foreach ($dValue as $index => $item) {
                        if (is_array($item)) {
                            $iClass = $item['class'] ?? $item['css'] ?? '';
                            $iAttr  = $item['attr'] ?? $item['attributes'] ?? '';
                            $iLabel = $item['label'] ?? $item['text'] ?? $item['title'] ?? $item['name'] ?? $item['desc'] ?? '';
                            $iLink  = $item['link'] ?? $item['url'] ?? $item['href'] ?? '#';
                            $iSrc   = $item['src'] ?? $item['image'] ?? $item['img'] ?? $item['url'] ?? '';
                            $iIconKey = $item['icon'] ?? $item['logo'] ?? $item['svg'] ?? '';
                            $iIcon  = ($iIconKey && self::exists($iIconKey)) ? self::ui($iIconKey) : $iIconKey;
                            
                            // If it has a link but no specific format requested, build generic link/button
                            if ($iLink !== '#') {
                                $renderedStr .= "<a href=\"{$iLink}\" class=\"{$iClass}\" {$iAttr}>{$iIcon}{$iLabel}</a>";
                            } elseif ($iSrc !== '') {
                                $renderedStr .= "<img src=\"{$iSrc}\" class=\"{$iClass}\" alt=\"{$iLabel}\" {$iAttr}/>";
                            } else {
                                $renderedStr .= "<div class=\"{$iClass}\" {$iAttr}>{$iIcon}{$iLabel}</div>";
                            }
                        } elseif ($isSlug($item)) {
                            $renderedStr .= self::ui($item);
                        } else {
                            $renderedStr .= (string)$item;
                        }
                    }
                } else {
                    $iClass = $dValue['class'] ?? $dValue['css'] ?? '';
                    $iAttr  = $dValue['attr'] ?? $dValue['attributes'] ?? '';
                    $iLabel = $dValue['label'] ?? $dValue['text'] ?? $dValue['title'] ?? $dValue['name'] ?? $dValue['desc'] ?? '';
                    $iLink  = $dValue['link'] ?? $dValue['url'] ?? $dValue['href'] ?? '#';
                    $iSrc   = $dValue['src'] ?? $dValue['image'] ?? $dValue['img'] ?? $dValue['url'] ?? '';
                    $iIconKey = $dValue['icon'] ?? $dValue['logo'] ?? $dValue['svg'] ?? '';
                    $iIcon  = ($iIconKey && self::exists($iIconKey)) ? self::ui($iIconKey) : $iIconKey;
                    
                    if (strpos(strtolower($dKey), 'button') !== false || strpos(strtolower($dKey), 'btn') !== false || strpos(strtolower($dKey), 'link') !== false) {
                         $renderedStr = "<a href=\"{$iLink}\" class=\"{$iClass}\" {$iAttr}>{$iIcon}{$iLabel}</a>";
                    } elseif (strpos(strtolower($dKey), 'image') !== false || strpos(strtolower($dKey), 'img') !== false || strpos(strtolower($dKey), 'src') !== false) {
                         $renderedStr = "<img src=\"{$iSrc}\" class=\"{$iClass}\" alt=\"{$iLabel}\" {$iAttr}/>";
                    } elseif (strpos(strtolower($dKey), 'logo') !== false || strpos(strtolower($dKey), 'brand') !== false || strpos(strtolower($dKey), 'text') !== false) {
                         $renderedStr = "<div class=\"{$iClass}\" {$iAttr}>{$iIcon}{$iLabel}</div>";
                    } else {
                         $renderedStr = $iIcon . $iLabel;
                    }
                }
                $dValue = $renderedStr;
            }
        }
        unset($dValue);

        // নিরাপদ সাধারণ অ্যারে রেফারেন্স হ্যান্ডলিং (আগের লজিক)
        foreach ($data as $key => &$value) {
            if (in_array($key, ['attr', 'attrs'], true)) continue;
            if (is_array($value)) {
                $joined = '';
                foreach ($value as $val) {
                    if (is_array($val)) continue; // Skip complex nested arrays already processed or irrelevant
                    if ($isSlug($val)) $joined .= self::ui($val);
                    else $joined .= (string)$val;
                }
                $value = $joined;
            } elseif ($isSlug($value)) {
                $value = self::ui($value);
            }
        }
        unset($value);

        $html = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/', function($m) use ($data, &$usedKeys) {
            $parts = explode('|', $m[1]);
            $key = trim($parts[0]);
            $default = isset($parts[1]) ? trim($parts[1]) : '';
            $usedKeys[] = $key;
            if ($key === 'attr' || $key === 'attr_str') return 'attr_placeholder';
            if ($key === 'style_attr') return $data['style_attr'] ?? '';
            return self::sanitizeDynamicHtml(isset($data[$key]) ? (string) $data[$key] : $default);
        }, $template);

        // অতিরিক্ত অ্যাট্রিবিউট প্রসেসিং
        $extraAttrs = [];
        foreach ($data as $k => $v) {
            if (in_array($k, $usedKeys, true) || str_starts_with((string) $k, '_')) continue;
            $extraAttrs[$k] = $v;
        }
        $attrStr = trim(self::attributes($data['attr'] ?? null) . ' ' . self::attributes($extraAttrs));

        // রেজেক্স ইমপ্রুভমেন্ট (যেকোনো স্পেস বা নিউ-লাইন ট্রিমিং হ্যান্ডেল করবে)
        if (str_contains($html, 'attr_placeholder')) {
            $html = str_replace('attr_placeholder', $attrStr, $html);
        } elseif (!empty($attrStr)) {
            $html = preg_replace('/^\s*<([a-z0-9-]+)/i', '<$1 ' . $attrStr, $html);
        }

        if (str_contains($html, '@slot')) {
            $html = str_replace('@slot', self::sanitizeDynamicHtml((string) ($data['slot'] ?? '')), $html);
        }

        unset(self::$recursionStack[$slug]); // পপ স্ট্যাক
        self::trackTailwind($html);
        return $html;
    }

    private static function renderType(string $type, string $slug, array $data): string
    {
        $slug = self::normalizeSlug($slug);
        $candidates = match ($type) {
            'section' => [$slug, "section:$slug", "sect:$slug", "ui:section-$slug"],
            'layout' => [$slug, "layout:$slug", "shell:$slug", "ui:layout-$slug"],
            'page' => [$slug, "page:$slug", "section:$slug", "ui:page-$slug"],
            default => [$slug, "html:$slug", "ui:$slug"],
        };
        if ($type === 'element' && str_starts_with($slug, 'button:')) {
            $variant = substr($slug, 7);
            array_splice($candidates, 1, 0, ["btn:$variant", "ui:button-$variant"]);
        }
        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (self::exists($candidate)) return self::render($candidate, $data);
        }
        return "<!-- PHUI: {$type} '$slug' not found -->";
    }

    private static function prepareRuntimeData(string $slug, array $data): array
    {
        if (!array_key_exists('slot', $data) && array_key_exists('text', $data)) {
            $data['slot'] = $data['text'];
        }
        $classes = [];
        foreach (['class', 'tw', 'css'] as $classKey) {
            if (!isset($data[$classKey])) continue;
            $value = $data[$classKey];
            $classes[] = is_array($value) ? implode(' ', array_filter($value, 'is_scalar')) : (string) $value;
        }
        $data['class'] = trim(implode(' ', array_filter($classes)));

        $attributes = [];
        foreach (['attr', 'attrs'] as $key) {
            if (!isset($data[$key])) continue;
            if (is_array($data[$key])) {
                $attributes = array_merge($attributes, $data[$key]);
            } elseif (is_string($data[$key]) && trim($data[$key]) !== '') {
                $attributes[] = trim($data[$key]);
            }
        }
        $attributes['data-phui'] = $attributes['data-phui'] ?? $slug;

        if (isset($data['state']) && is_array($data['state'])) {
            $attributes['x-data'] = json_encode(
                $data['state'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }
        if (isset($data['on']) && is_array($data['on'])) {
            foreach ($data['on'] as $event => $expression) {
                if (preg_match('/^[a-z][a-z0-9_.:-]*$/i', (string) $event) && is_scalar($expression)) {
                    $attributes['x-on:' . strtolower((string) $event)] = (string) $expression;
                }
            }
        }
        if (isset($data['phjs'])) {
            $interactions = is_array($data['phjs']) ? $data['phjs'] : ['click' => $data['phjs']];
            foreach ($interactions as $event => $instruction) {
                if (!preg_match('/^[a-z][a-z0-9_.:-]*$/i', (string) $event) || !is_scalar($instruction)) continue;
                $attributes['x-on:' . strtolower((string) $event)] =
                    class_exists('PHJS') && is_callable(['PHJS', 'gen'])
                        ? PHJS::gen((string) $instruction)
                        : (string) $instruction;
            }
        }
        if (isset($data['htmx']) && is_array($data['htmx'])) {
            $htmxMap = [
                'get' => 'hx-get', 'post' => 'hx-post', 'put' => 'hx-put',
                'patch' => 'hx-patch', 'delete' => 'hx-delete', 'target' => 'hx-target',
                'trigger' => 'hx-trigger', 'swap' => 'hx-swap', 'select' => 'hx-select',
                'indicator' => 'hx-indicator', 'push-url' => 'hx-push-url',
            ];
            foreach ($data['htmx'] as $name => $value) {
                $attribute = $htmxMap[strtolower((string) $name)] ?? null;
                if ($attribute !== null && (is_scalar($value) || $value === null)) {
                    $attributes[$attribute] = $value;
                }
            }
        }

        $data['attr'] = $attributes;
        unset($data['attrs'], $data['tw'], $data['css'], $data['phjs'], $data['on'], $data['htmx'], $data['state']);
        return $data;
    }

    private static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        return preg_match('/^[a-z0-9][a-z0-9:_-]{0,159}$/', $slug) ? $slug : '';
    }

    private static function resolveSlug(string $slug): ?string
    {
        $slug = self::normalizeSlug($slug);
        if ($slug === '') return null;
        if (isset(self::$registry[$slug])) return $slug;
        $alternate = str_replace('.', ':', $slug);
        if (isset(self::$registry[$alternate])) return $alternate;
        if (str_starts_with($slug, 'button:')) {
            $buttonAlias = 'btn:' . substr($slug, 7);
            if (isset(self::$registry[$buttonAlias])) return $buttonAlias;
        }
        return null;
    }

    private static function trackTailwind(string $html): void
    {
        if ($html !== '' && class_exists('PHCS') && is_callable(['PHCS', 'HTML'])) {
            PHCS::HTML($html);
        }
    }

    public static function boot(): void { if (!self::$booted) { self::$booted = true; self::loadSemanticRegistry(); } }

    private static function loadSemanticRegistry(): void
    {
        $int = "ring-1 ring-offset-0 ring-border/20 m-0.5 transition-all duration-200";

        // --- 1. HTML PRIMITIVES ---
        $tags = ['a','abbr','acronym','address','article','aside','audio','b','base','blockquote','br','button','canvas','caption','cite','code','col','data','dd','del','details','dfn','dialog','div','dl','dt','em','embed','fieldset','figcaption','figure','footer','form','h1','h2','h3','h4','h5','h6','header','hr','i','iframe','img','input','ins','kbd','label','legend','li','main','mark','nav','ol','option','p','picture','pre','progress','q','s','samp','section','select','small','span','strong','sub','summary','sup','svg','table','tbody','td','template','textarea','tfoot','th','thead','time','tr','u','ul','var','video','wbr'];
        foreach ($tags as $tag) {
            $isVoid = in_array($tag, ['br','hr','img','input','link','meta','wbr']);
            self::$registry["html:$tag"] = [
                'title' => strtoupper($tag),
                'template' => $isVoid ? "<$tag class=\"{{class}}\" {{style_attr}} {{attr}}/>" : "<$tag class=\"{{class}}\" {{style_attr}} {{attr}}>@slot</$tag>"
            ];
        }

        // --- 2. DESIGN SYSTEM COMPONENTS (A-Z Sorted) ---
        $c = [
            'a' => ['title' => 'Anchor', 'template' => '<a href="{{href|#}}" class="{{class}}" {{style_attr}} {{attr}}>@slot</a>'],

            'abbr' => ['title' => 'Abbreviation', 'template' => '<abbr title="{{title}}" class="{{class}}" {{style_attr}} {{attr}}>@slot</abbr>'],

            'address' => ['title' => 'Address', 'template' => '<address class="{{class}}" {{style_attr}} {{attr}}>@slot</address>'],

            'alert:default' => ['title' => 'Default Alert', 'template' => '<div class="relative w-full rounded-lg border border-border bg-background p-4 text-foreground {{class}}" {{style_attr}} {{attr}}><h5 class="mb-1 font-medium leading-none tracking-tight">{{title}}</h5><div class="text-sm text-muted-foreground">{{desc}}</div></div>'],

            'alert:destructive' => ['title' => 'Destructive Alert', 'template' => '<div class="relative w-full rounded-lg border border-destructive/50 text-destructive dark:border-destructive bg-background p-4 {{class}}" {{style_attr}} {{attr}}><h5 class="mb-1 font-medium leading-none tracking-tight">{{title}}</h5><div class="text-sm opacity-90">{{desc}}</div></div>'],

            'article' => ['title' => 'Article', 'template' => '<article class="{{class}}" {{style_attr}} {{attr}}>@slot</article>'],

            'auth:oauth-buttons' => ['title' => 'OAuth Provider Button Grid', 'template' => '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 {{class}}" role="group" aria-label="{{label|Continue with another account}}" {{style_attr}} {{attr}}>@slot</div>'],

            'auth:oauth-provider-button' => ['title' => 'OAuth Provider Button', 'template' => '<a class="inline-flex min-h-12 w-full items-center justify-center gap-3 rounded-xl border border-border bg-background px-4 py-3 text-sm font-semibold text-foreground shadow-sm transition hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 {{class}}" {{style_attr}} {{attr}}><span class="flex h-5 w-5 items-center justify-center" aria-hidden="true">{{icon}}</span><span>{{label|Continue}}</span></a>'],

            'auth:oauth-callback' => ['title' => 'OAuth Callback Status', 'template' => '<div class="mx-auto flex max-w-md flex-col items-center gap-4 rounded-2xl border border-border bg-card p-8 text-center shadow-sm {{class}}" role="status" aria-live="polite" {{style_attr}} {{attr}}><div class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">{{icon|&#8635;}}</div><h2 class="text-xl font-bold text-foreground">{{title|Completing sign in}}</h2><p class="text-sm text-muted-foreground">{{message|Please wait while your account is verified.}}</p></div>'],

            'auth:account-linking' => ['title' => 'Linked Accounts Panel', 'template' => '<section class="rounded-2xl border border-border bg-card p-6 shadow-sm {{class}}" {{style_attr}} {{attr}}><div class="mb-5"><h2 class="text-lg font-bold text-foreground">{{title|Linked accounts}}</h2><p class="mt-1 text-sm text-muted-foreground">{{description|Manage the external accounts connected to your profile.}}</p></div><div class="divide-y divide-border">@slot</div></section>'],

            'auth:2fa-setup' => ['title' => 'Authenticator Setup Panel', 'template' => '<section class="mx-auto max-w-2xl rounded-3xl border border-border bg-card p-6 shadow-sm sm:p-8 {{class}}" {{style_attr}} {{attr}}><div class="mb-6"><span class="inline-flex rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">{{step|Security setup}}</span><h2 class="mt-3 text-2xl font-bold text-foreground">{{title|Set up an authenticator app}}</h2><p class="mt-2 text-sm text-muted-foreground">{{description|Scan the QR code, then enter the current code from your authenticator app.}}</p></div><div class="grid gap-6 md:grid-cols-[auto_1fr]"><div class="flex min-h-48 min-w-48 items-center justify-center rounded-2xl border border-border bg-white p-3">{{qr}}</div><div class="space-y-4"><div><p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Manual setup key</p><code class="mt-2 block break-all rounded-lg bg-muted p-3 font-mono text-sm text-foreground">{{secret}}</code></div>@slot</div></div></section>'],

            'auth:2fa-verify' => ['title' => 'Authenticator Code Verification', 'template' => '<form class="mx-auto max-w-md rounded-3xl border border-border bg-card p-6 shadow-sm sm:p-8 {{class}}" {{style_attr}} {{attr}}><div class="text-center"><div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-xl text-primary" aria-hidden="true">&#128274;</div><h2 class="mt-4 text-2xl font-bold text-foreground">{{title|Two-factor authentication}}</h2><p class="mt-2 text-sm text-muted-foreground">{{description|Enter the code from your authenticator app.}}</p></div><div class="mt-6 flex justify-center gap-2" data-2fa-input role="group" aria-label="Authenticator code"><input data-2fa-digit inputmode="numeric" autocomplete="one-time-code" maxlength="1" aria-label="Digit 1" class="h-12 w-10 rounded-lg border border-input bg-background text-center text-xl font-bold focus:outline-none focus:ring-2 focus:ring-ring"><input data-2fa-digit inputmode="numeric" maxlength="1" aria-label="Digit 2" class="h-12 w-10 rounded-lg border border-input bg-background text-center text-xl font-bold focus:outline-none focus:ring-2 focus:ring-ring"><input data-2fa-digit inputmode="numeric" maxlength="1" aria-label="Digit 3" class="h-12 w-10 rounded-lg border border-input bg-background text-center text-xl font-bold focus:outline-none focus:ring-2 focus:ring-ring"><input data-2fa-digit inputmode="numeric" maxlength="1" aria-label="Digit 4" class="h-12 w-10 rounded-lg border border-input bg-background text-center text-xl font-bold focus:outline-none focus:ring-2 focus:ring-ring"><input data-2fa-digit inputmode="numeric" maxlength="1" aria-label="Digit 5" class="h-12 w-10 rounded-lg border border-input bg-background text-center text-xl font-bold focus:outline-none focus:ring-2 focus:ring-ring"><input data-2fa-digit inputmode="numeric" maxlength="1" aria-label="Digit 6" class="h-12 w-10 rounded-lg border border-input bg-background text-center text-xl font-bold focus:outline-none focus:ring-2 focus:ring-ring"></div><div class="mt-6">@slot</div></form>'],

            'auth:recovery-codes' => ['title' => 'Authenticator Recovery Codes', 'template' => '<section class="rounded-2xl border border-warning/40 bg-warning/5 p-6 {{class}}" {{style_attr}} {{attr}}><div class="flex items-start gap-3"><span class="text-warning" aria-hidden="true">&#9888;</span><div><h3 class="font-bold text-foreground">{{title|Save your recovery codes}}</h3><p class="mt-1 text-sm text-muted-foreground">{{description|Each code works once. Store them somewhere safe and private.}}</p></div></div><div class="mt-5 grid grid-cols-1 gap-2 rounded-xl border border-border bg-background p-4 font-mono text-sm sm:grid-cols-2">@slot</div><div class="mt-4 flex flex-wrap gap-3">{{actions}}</div></section>'],

            'auth:recovery-input' => ['title' => 'Recovery Code Input', 'template' => '<div class="space-y-2 {{class}}" {{style_attr}} {{attr}}><label class="text-sm font-semibold text-foreground">{{label|Recovery code}}</label><input type="text" name="{{name|code}}" inputmode="text" autocomplete="one-time-code" spellcheck="false" placeholder="XXXXX-XXXXX" class="h-12 w-full rounded-xl border border-input bg-background px-4 font-mono uppercase tracking-widest text-foreground focus:outline-none focus:ring-2 focus:ring-ring"><p class="text-xs text-muted-foreground">{{help|Use one of the single-use codes saved during setup.}}</p></div>'],

            'auth:2fa-status' => ['title' => 'Authenticator Status Card', 'template' => '<div class="flex flex-col gap-4 rounded-2xl border border-border bg-card p-6 sm:flex-row sm:items-center sm:justify-between {{class}}" {{style_attr}} {{attr}}><div class="flex items-center gap-4"><span class="flex h-11 w-11 items-center justify-center rounded-full bg-{{tone|primary}}/10 text-{{tone|primary}}" aria-hidden="true">{{icon|&#128737;}}</span><div><h3 class="font-bold text-foreground">{{title|Authenticator app}}</h3><p class="text-sm text-muted-foreground">{{status|Not configured}}</p></div></div><div>{{action}}</div></div>'],

            'auth:2fa-disable' => ['title' => 'Disable Authenticator Confirmation', 'template' => '<section class="rounded-2xl border border-destructive/40 bg-destructive/5 p-6 {{class}}" {{style_attr}} {{attr}}><h3 class="font-bold text-destructive">{{title|Disable two-factor authentication?}}</h3><p class="mt-2 text-sm text-muted-foreground">{{description|Your account will have less protection. Confirm with a current code before continuing.}}</p><div class="mt-5">@slot</div></section>'],

            'aside' => ['title' => 'Aside', 'template' => '<aside class="{{class}}" {{style_attr}} {{attr}}>@slot</aside>'],

            'audio' => ['title' => 'Audio', 'template' => '<audio controls src="{{src}}" class="{{class}}" {{style_attr}} {{attr}}>@slot</audio>'],

            'b' => ['title' => 'Bold Text', 'template' => '<b class="{{class}}" {{style_attr}} {{attr}}>@slot</b>'],

            'badge:default' => ['title' => 'Default Badge', 'template' => '<div class="inline-flex items-center rounded-full border border-transparent bg-primary px-2.5 py-0.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/80 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 {{class}}" {{style_attr}} {{attr}}>{{label}}</div>'],

            'badge:destructive' => ['title' => 'Destructive Badge', 'template' => '<div class="inline-flex items-center rounded-full border border-transparent bg-destructive px-2.5 py-0.5 text-xs font-semibold text-destructive-foreground transition-colors hover:bg-destructive/80 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 {{class}}" {{style_attr}} {{attr}}>{{label}}</div>'],

            'badge:outline' => ['title' => 'Outline Badge', 'template' => '<div class="inline-flex items-center rounded-full border border-border text-foreground px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 {{class}}" {{style_attr}} {{attr}}>{{label}}</div>'],

            'badge:secondary' => ['title' => 'Secondary Badge', 'template' => '<div class="inline-flex items-center rounded-full border border-transparent bg-secondary px-2.5 py-0.5 text-xs font-semibold text-secondary-foreground transition-colors hover:bg-secondary/80 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 {{class}}" {{style_attr}} {{attr}}>{{label}}</div>'],

            'blockquote' => ['title' => 'Blockquote', 'template' => '<blockquote class="border-l-4 border-primary pl-4 italic {{class}}" {{style_attr}} {{attr}}>@slot</blockquote>'],

            'br' => ['title' => 'Line Break', 'template' => '<br/>'],

            'btn:destructive' => ['title' => 'Destructive Button', 'template' => '<button type="{{type|button}}" class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-50 bg-destructive text-destructive-foreground hover:bg-destructive/90 h-10 px-4 py-2 {{class}}" {{style_attr}} {{attr}}>{{label}}</button>'],

            'btn:ghost' => ['title' => 'Ghost Button', 'template' => '<button type="{{type|button}}" class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-50 hover:bg-accent hover:text-accent-foreground h-10 px-4 py-2 {{class}}" {{style_attr}} {{attr}}>{{label}}</button>'],

            'btn:group' => ['title' => 'Button Group Alternative', 'template' => '<div class="inline-flex rounded-md shadow-sm {{class}}" role="group" {{style_attr}} {{attr}}>{{slot}}</div>'],

            'btn:link' => ['title' => 'Link Button', 'template' => '<a href="{{link|#}}" class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-50 text-primary underline-offset-4 hover:underline h-10 px-4 py-2 {{class}}" {{style_attr}} {{attr}}>{{label}}</a>'],

            'btn:outline' => ['title' => 'Outline Button', 'template' => '<button type="{{type|button}}" class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-10 px-4 py-2 {{class}}" {{style_attr}} {{attr}}>{{label}}</button>'],

            'btn:primary' => ['title' => 'Primary Button', 'template' => '<button type="{{type|button}}" class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2 {{class}}" {{style_attr}} {{attr}}>{{label}}</button>'],

            'btn:secondary' => ['title' => 'Secondary Button', 'template' => '<button type="{{type|button}}" class="inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors bg-secondary text-secondary-foreground hover:bg-secondary/80 h-10 px-4 py-2 {{class}}" {{style_attr}} {{attr}}>{{label}}</button>'],

            'button' => ['title' => 'Base HTML Button', 'template' => '<button type="{{type|button}}" class="px-4 py-2 rounded bg-primary text-primary-foreground {{class}}" {{style_attr}} {{attr}}>@slot</button>'],

            'canvas' => ['title' => 'Canvas', 'template' => '<canvas class="{{class}}" {{style_attr}} {{attr}}></canvas>'],

            'caption' => ['title' => 'Caption', 'template' => '<caption class="{{class}}" {{style_attr}} {{attr}}>@slot</caption>'],

            'cite' => ['title' => 'Citation', 'template' => '<cite class="{{class}}" {{style_attr}} {{attr}}>@slot</cite>'],

            'code' => ['title' => 'Code Block', 'template' => '<code class="bg-muted px-1 rounded text-sm {{class}}" {{style_attr}} {{attr}}>@slot</code>'],

            'comp:avatar' => ['title' => 'Avatar Component', 'template' => '<span class="relative flex h-10 w-10 shrink-0 overflow-hidden rounded-full border border-border bg-muted {{class}}" {{style_attr}} {{attr}}><img class="aspect-square h-full w-full" src="{{src}}" alt="{{alt|Avatar}}"/></span>'],

            'comp:divider' => ['title' => 'Divider Component', 'template' => '<div class="relative {{class}}" {{style_attr}} {{attr}}><div class="absolute inset-0 flex items-center"><span class="w-full border-t border-border"></span></div><div class="relative flex justify-center text-xs uppercase"><span class="bg-background px-2 text-muted-foreground">{{label}}</span></div></div>'],

            'comp:kbd' => ['title' => 'Kbd Component', 'template' => '<kbd class="pointer-events-none inline-flex h-5 select-none items-center gap-1 rounded border border-border bg-muted px-1.5 font-mono text-[10px] font-medium text-muted-foreground opacity-100 {{class}}" {{style_attr}} {{attr}}><span class="text-xs">⌘</span>{{label}}</kbd>'],

            'comp:progress' => ['title' => 'Progress Component', 'template' => '<div class="relative h-4 w-full overflow-hidden rounded-full bg-secondary {{class}}" {{style_attr}} {{attr}}><div class="h-full w-full flex-1 bg-primary transition-all" style="transform: translateX(-{{offset|50}}%);"></div></div>'],

            'comp:spinner' => ['title' => 'Spinner Component', 'template' => '<svg class="animate-spin h-5 w-5 text-primary {{class}}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" {{style_attr}} {{attr}}><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>'],

            'data' => ['title' => 'Data Value', 'template' => '<data value="{{value}}" class="{{class}}" {{style_attr}} {{attr}}>@slot</data>'],

            'data:accordion' => ['title' => 'Data Accordion', 'template' => '<div class="w-full divide-y divide-border {{class}}" {{style_attr}} {{attr}}>{{items}}</div>'],

            'data:blog-section' => ['title' => 'Blog Section', 'template' => '<section class="py-24 {{class}}" {{style_attr}} {{attr}}><div class="container grid md:grid-cols-3 gap-12">@slot</div></section>'],

            'data:card' => ['title' => 'Data Card', 'template' => '<div class="rounded-xl border border-border bg-card text-card-foreground shadow-sm {{class}}" {{style_attr}} {{attr}}><div class="flex flex-col space-y-1.5 p-6"><h3 class="text-2xl font-semibold leading-none tracking-tight">{{title}}</h3><p class="text-sm text-muted-foreground">{{desc}}</p></div><div class="p-6 pt-0">{{content}}</div><div class="flex items-center p-6 pt-0">{{footer}}</div></div>'],

            'data:card-glass' => ['title' => 'Glass Card', 'template' => "<div class=\"bg-white/10 backdrop-blur-xl border border-white/20 p-10 rounded-[3rem] $int {{class}}\">@slot</div>"],

            'data:card-neon' => ['title' => 'Neon Card', 'template' => "<div class=\"bg-black border-primary shadow-[0_0_20px_rgba(var(--primary),0.3)] p-10 rounded-[3rem] $int {{class}}\">@slot</div>"],

            'data:content-section' => ['title' => 'Content Section', 'template' => '<section class="py-20 prose prose-neutral dark:prose-invert max-w-4xl mx-auto px-6 {{class}}" {{style_attr}} {{attr}}>@slot</section>'],

            'data:image-gallery' => ['title' => 'Image Gallery', 'template' => '<div class="grid grid-cols-2 md:grid-cols-4 gap-4 {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'data:jumbotron' => ['title' => 'Jumbotron', 'template' => '<div class="p-16 md:p-32 rounded-[3.5rem] bg-muted border text-center space-y-8 {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'data:list-group' => ['title' => 'List Group', 'template' => "<div class=\"divide-y border rounded-2xl overflow-hidden $int {{class}}\" {{style_attr}} {{attr}}>@slot</div>"],

            'data:order-history' => ['title' => 'Order History', 'template' => '<div class="space-y-6 {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'data:order-summary' => ['title' => 'Order Summary', 'template' => "<div class=\"p-10 border rounded-[2.5rem] bg-card space-y-6 $int {{class}}\" {{style_attr}} {{attr}}><h3 class=\"text-2xl font-black uppercase italic\">Summary</h3>@slot</div>"],

            'data:order-tracking' => ['title' => 'Order Tracking', 'template' => "<div class=\"p-10 border rounded-[2.5rem] bg-muted/20 {{class}}\" {{style_attr}} {{attr}}><div class=\"flex justify-between items-center mb-10 text-sm font-black uppercase tracking-widest\">@slot</div><div class=\"relative h-3 bg-muted rounded-full overflow-hidden shadow-inner\"><div class=\"absolute left-0 top-0 h-full bg-primary transition-all duration-1000\" style=\"width: {{percent|50}}%\"></div></div></div>"],

            'data:product-categories' => ['title' => 'Product Categories', 'template' => '<div class="flex gap-4 overflow-x-auto pb-6 no-scrollbar {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'data:product-filter' => ['title' => 'Product Filter', 'template' => '<div class="w-full space-y-10 {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'data:product-list' => ['title' => 'Product List', 'template' => '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8 {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'data:product-review' => ['title' => 'Product Review', 'template' => '<div class="py-10 border-b last:border-0 {{class}}" {{style_attr}} {{attr}}><div class="flex gap-4 mb-4">@slot</div></div>'],

            'data:product-view' => ['title' => 'Product View', 'template' => '<div class="grid lg:grid-cols-2 gap-16 {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'data:shopping-cart' => ['title' => 'Shopping Cart', 'template' => '<div class="space-y-8 {{class}}" {{style_attr}} {{attr}}><h2 class="text-3xl font-black italic uppercase tracking-tighter">Your Cart</h2><div class="divide-y border rounded-3xl bg-card overflow-hidden">@slot</div></div>'],

            'data:stat' => ['title' => 'Data Stat Card', 'template' => '<div class="rounded-xl border border-border bg-card text-card-foreground shadow-sm p-6 {{class}}" {{style_attr}} {{attr}}><div class="flex flex-row items-center justify-between space-y-0 pb-2"><h3 class="tracking-tight text-sm font-medium">{{title}}</h3><svg class="h-4 w-4 text-muted-foreground" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div><div class="text-2xl font-bold">{{value}}</div><p class="text-xs text-muted-foreground">{{subtext}}</p></div>'],

            'data:stats-section' => ['title' => 'Stats Section', 'template' => '<div class="grid grid-cols-2 lg:grid-cols-4 gap-12 py-20 {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'data:table' => ['title' => 'Table Layout', 'template' => "<div class=\"w-full overflow-hidden border rounded-2xl $int {{class}}\"><table class=\"w-full text-left text-sm\" {{style_attr}} {{attr}}>@slot</table></div>"],

            'data:table-advance' => ['title' => 'Advance Table', 'template' => "<div class=\"border rounded-3xl overflow-hidden shadow-xl $int {{class}}\"><div class=\"p-6 border-b flex justify-between items-center bg-card\">{{header}}</div><div class=\"overflow-x-auto\"><table class=\"w-full text-sm\">@slot</table></div><div class=\"p-4 border-t bg-muted/10\">{{footer}}</div></div>"],

            'data:table-structured' => ['title' => 'Structured Data Table', 'template' => '<div class="relative w-full overflow-auto {{class}}" {{style_attr}} {{attr}}><table class="w-full caption-bottom text-sm"><thead class="[&_tr]:border-b border-border"><tr class="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted">{{headers}}</tr></thead><tbody class="[&_tr:last-child]:border-0">{{rows}}</tbody></table></div>'],

            'data:video-gallery' => ['title' => 'Video Gallery', 'template' => '<div class="grid md:grid-cols-2 gap-8 {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'dd' => ['title' => 'Description Definition', 'template' => '<dd class="{{class}}" {{style_attr}} {{attr}}>@slot</dd>'],

            'del' => ['title' => 'Deleted Text', 'template' => '<del class="{{class}}" {{style_attr}} {{attr}}>@slot</del>'],

            'details' => ['title' => 'Details Accordion', 'template' => '<details class="{{class}}" {{style_attr}} {{attr}}><summary class="cursor-pointer font-bold">{{title}}</summary>@slot</details>'],

            'dfn' => ['title' => 'Definition Element', 'template' => '<dfn class="{{class}}" {{style_attr}} {{attr}}>@slot</dfn>'],

            'dialog' => ['title' => 'Dialog Box', 'template' => '<dialog class="rounded-lg shadow-xl p-6 bg-background {{class}}" {{style_attr}} {{attr}}>@slot</dialog>'],

            'div' => ['title' => 'Generic Container', 'template' => '<div class="{{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'dl' => ['title' => 'Description List', 'template' => '<dl class="{{class}}" {{style_attr}} {{attr}}>@slot</dl>'],

            'dt' => ['title' => 'Description Term', 'template' => '<dt class="font-bold {{class}}" {{style_attr}} {{attr}}>@slot</dt>'],

            'eco:cart' => ['title' => 'Shopping Cart Card', 'template' => '<div class="border border-border rounded-lg p-6 {{class}}"><h2 class="text-2xl font-bold mb-6">Your Cart</h2><div class="divide-y divide-border">{{items}}</div><div class="mt-6 flex justify-between font-bold"><span>Total</span><span>{{total}}</span></div><button class="w-full bg-primary text-primary-foreground py-3 mt-6 rounded">Checkout</button></div>'],

            'eco:product' => ['title' => 'Product Card', 'template' => '<div class="rounded-xl border border-border bg-card text-card-foreground shadow-sm overflow-hidden {{class}}" {{style_attr}} {{attr}}><img src="{{image}}" class="h-48 w-full object-cover"><div class="p-4"><h3 class="font-semibold text-lg">{{name}}</h3><p class="text-sm text-muted-foreground mt-1">{{desc}}</p><div class="mt-4 flex items-center justify-between"><span class="text-lg font-bold">${{price}}</span>{{action}}</div></div></div>'],

            'eco:product-list' => ['title' => 'E-commerce Grid', 'template' => '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 {{class}}">{{items}}</div>'],

            'em' => ['title' => 'Emphasized Text', 'template' => '<em class="{{class}}" {{style_attr}} {{attr}}>@slot</em>'],

            'embed' => ['title' => 'Embedded Object', 'template' => '<embed src="{{src}}" type="{{type}}" class="{{class}}" {{style_attr}} {{attr}}/>'],

            'fieldset' => ['title' => 'Fieldset', 'template' => '<fieldset class="border border-border p-4 rounded {{class}}" {{style_attr}} {{attr}}>@slot</fieldset>'],

            'figcaption' => ['title' => 'Figure Caption', 'template' => '<figcaption class="text-sm text-muted-foreground mt-2 {{class}}" {{style_attr}} {{attr}}>@slot</figcaption>'],

            'figure' => ['title' => 'Figure Element', 'template' => '<figure class="{{class}}" {{style_attr}} {{attr}}>@slot</figure>'],

            'footer' => ['title' => 'Base HTML Footer', 'template' => '<footer class="{{class}}" {{style_attr}} {{attr}}>@slot</footer>'],

            'footer:mega' => ['title' => 'Mega Footer', 'template' => '<footer class="bg-muted py-12 px-6 border-t border-border {{class}}"><div class="container mx-auto grid grid-cols-2 md:grid-cols-4 gap-8"><div><h4 class="font-bold mb-4">Company</h4>{{col1}}</div><div><h4 class="font-bold mb-4">Product</h4>{{col2}}</div><div><h4 class="font-bold mb-4">Resources</h4>{{col3}}</div><div><h4 class="font-bold mb-4">Support</h4>{{col4}}</div></div><div class="mt-12 text-center text-sm text-muted-foreground">© {{year|2024}} {{brand}}. All rights reserved.</div></footer>'],

            'footer:simple' => ['title' => 'Footer Simple', 'template' => '<footer class="border-t border-border bg-background py-6 md:px-8 md:py-0 {{class}}" {{style_attr}} {{attr}}><div class="container flex flex-col items-center justify-between gap-4 md:h-24 md:flex-row"><p class="text-center text-sm leading-loose text-muted-foreground md:text-left">Built by <a href="#" class="font-medium underline underline-offset-4">{{brand}}</a>. The source code is available on <a href="#" class="font-medium underline underline-offset-4">GitHub</a>.</p></div></footer>'],

            'form' => ['title' => 'Base HTML Form', 'template' => '<form action="{{action}}" method="{{method|POST}}" class="{{class}}" {{style_attr}} {{attr}}>@slot</form>'],

            'form:cantact' => ['title' => 'Cantact Form', 'template' => "<form class=\"p-10 border rounded-3xl bg-card space-y-6 $int {{class}}\" {{style_attr}} {{attr}}><h3 class=\"text-2xl font-bold uppercase tracking-tighter italic\">Cantact Us</h3>@slot</form>"],

            'form:checkbox' => ['title' => 'Form Checkbox', 'template' => '<div class="flex items-center space-x-2 {{class}}" {{style_attr}} {{attr}}><input type="checkbox" name="{{name}}" id="{{id}}" class="peer h-4 w-4 shrink-0 rounded-sm border border-primary ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground"><label for="{{id}}" class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 text-foreground">{{label}}</label></div>'],

            'form:checkout' => ['title' => 'Checkout Form Layout', 'template' => "<div class=\"grid lg:grid-cols-2 gap-12 {{class}}\" {{style_attr}} {{attr}}>@slot</div>"],

            'form:contact' => ['title' => 'Contact Form', 'template' => '<form class="space-y-4 {{class}}" {{attr}}><div class="grid grid-cols-2 gap-4"><input type="text" placeholder="Name" class="p-2 border border-border rounded"><input type="email" placeholder="Email" class="p-2 border border-border rounded"></div><textarea placeholder="Message" class="w-full p-2 border border-border rounded" rows="4"></textarea><button class="bg-primary text-primary-foreground px-4 py-2 rounded">Send Message</button></form>'],

            'form:contact-layout' => ['title' => 'Contact Form Wrapper', 'template' => "<form class=\"p-10 border rounded-3xl bg-card space-y-6 $int {{class}}\" {{style_attr}} {{attr}}><h3 class=\"text-2xl font-bold\">Send a Message</h3>@slot</form>"],

            'form:file' => ['title' => 'File Input Component', 'template' => '<div class="grid w-full items-center gap-1.5 {{class}}" {{style_attr}} {{attr}}><label class="text-sm font-medium leading-none text-foreground">{{label}}</label><input type="file" name="{{name}}" class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50"></div>'],

            'form:input' => ['title' => 'Standard Input Container', 'template' => '<div class="grid w-full items-center gap-1.5 {{class}}" {{style_attr}} {{attr}}><label class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 text-foreground">{{label}}</label><input type="{{type|text}}" name="{{name}}" placeholder="{{placeholder}}" value="{{value}}" class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-[0.5px] focus-visible:ring-ring focus-visible:ring-offset-0 disabled:cursor-not-allowed disabled:opacity-50"></div>'],

            'form:input-email' => ['title' => 'Email Input', 'template' => "<div class=\"space-y-2 {{class}}\"><label class=\"text-sm font-semibold ml-1\">{{label}}</label><input type=\"email\" placeholder=\"{{placeholder|name@example.com}}\" class=\"w-full h-12 px-4 rounded-xl border bg-background text-sm $int\" {{style_attr}} {{attr}}/></div>"],

            'form:input-password' => ['title' => 'Password Input', 'template' => "<div class=\"space-y-2 {{class}}\"><label class=\"text-sm font-semibold ml-1\">{{label}}</label><input type=\"password\" placeholder=\"••••••••\" class=\"w-full h-12 px-4 rounded-xl border bg-background text-sm $int\" {{style_attr}} {{attr}}/></div>"],

            'form:input-text' => ['title' => 'Text Input', 'template' => "<div class=\"space-y-2 {{class}}\"><label class=\"text-sm font-semibold ml-1\">{{label}}</label><input type=\"text\" placeholder=\"{{placeholder}}\" class=\"w-full h-12 px-4 rounded-xl border bg-background text-sm focus:ring-2 ring-primary/10 $int\" {{style_attr}} {{attr}}/></div>"],

            'form:login' => ['title' => 'Login Form Component', 'template' => '<div class="p-6 border border-border rounded-lg shadow-sm {{class}}"><h2 class="text-xl font-bold mb-4">Login</h2><div class="space-y-4"><input type="text" placeholder="Username" class="w-full p-2 border border-border rounded"><input type="password" placeholder="Password" class="w-full p-2 border border-border rounded"><button class="w-full bg-primary text-primary-foreground py-2 rounded">Login</button></div></div>'],

            'form:login-wrapper' => ['title' => 'Login Container Wrapper', 'template' => "<div class=\"p-10 border rounded-[2.5rem] bg-card shadow-2xl max-w-md w-full mx-auto space-y-6 $int {{class}}\" {{style_attr}} {{attr}}><h2 class=\"text-4xl font-black text-center\">Sign In</h2>@slot</div>"],

            'form:multi-select' => ['title' => 'Multi-select Component', 'template' => "<div class=\"space-y-2 {{class}}\"><label class=\"text-sm font-semibold ml-1\">{{label}}</label><div class=\"min-h-[3rem] w-full p-2 border rounded-xl bg-background flex flex-wrap gap-2 $int\">@slot<input class=\"flex-1 bg-transparent border-0 outline-none px-2 min-w-[100px] text-sm\"></div></div>"],

            'form:payment' => ['title' => 'Payment Form Wrapper', 'template' => "<div class=\"p-8 border rounded-3xl bg-card space-y-8 $int {{class}}\" {{style_attr}} {{attr}}><h3 class=\"text-xl font-black uppercase tracking-widest\">Payment</h3>@slot</div>"],

            'payment:gateway-selector' => ['title' => 'Payment Gateway Selector', 'template' => '<fieldset class="rounded-2xl border border-border bg-card p-6 {{class}}" {{style_attr}} {{attr}}><legend class="px-2 text-base font-bold text-foreground">{{title|Choose a payment method}}</legend><p class="mb-4 text-sm text-muted-foreground">{{description|You will continue through the selected provider securely.}}</p><div class="grid grid-cols-1 gap-3 sm:grid-cols-2">@slot</div></fieldset>'],

            'payment:gateway-option' => ['title' => 'Payment Gateway Option', 'template' => '<button type="button" class="group flex min-h-16 w-full items-center gap-3 rounded-xl border border-border bg-background p-4 text-left transition hover:border-primary/50 hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 {{class}}" {{style_attr}} {{attr}}><span class="flex h-9 w-12 items-center justify-center rounded-lg bg-white p-1" aria-hidden="true">{{logo}}</span><span class="min-w-0 flex-1"><span class="block font-semibold text-foreground">{{label|Payment provider}}</span><span class="block truncate text-xs text-muted-foreground">{{description|Secure hosted checkout}}</span></span><span class="text-muted-foreground" aria-hidden="true">&#8250;</span></button>'],

            'payment:checkout-summary' => ['title' => 'Secure Checkout Summary', 'template' => '<aside class="rounded-3xl border border-border bg-card p-6 shadow-sm {{class}}" {{style_attr}} {{attr}}><div class="flex items-center justify-between"><h2 class="text-xl font-bold text-foreground">{{title|Order summary}}</h2><span class="rounded-full bg-success/10 px-3 py-1 text-xs font-semibold text-success">{{badge|Secure}}</span></div><div class="mt-6 divide-y divide-border">{{items}}</div><div class="mt-6 space-y-3 border-t border-border pt-5">{{totals}}</div><div class="mt-6">@slot</div></aside>'],

            'payment:processing' => ['title' => 'Payment Processing State', 'template' => '<div class="mx-auto flex max-w-md flex-col items-center rounded-3xl border border-border bg-card p-8 text-center shadow-sm {{class}}" role="status" aria-live="polite" {{style_attr}} {{attr}}><svg class="h-10 w-10 animate-spin text-primary" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg><h2 class="mt-5 text-xl font-bold text-foreground">{{title|Processing payment}}</h2><p class="mt-2 text-sm text-muted-foreground">{{message|Do not close or refresh this page.}}</p></div>'],

            'payment:status' => ['title' => 'Payment Status Card', 'template' => '<div class="flex items-start gap-4 rounded-2xl border border-border bg-card p-6 {{class}}" role="status" aria-live="polite" {{style_attr}} {{attr}}><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-{{tone|primary}}/10 text-{{tone|primary}}" aria-hidden="true">{{icon|&#8230;}}</span><div class="min-w-0 flex-1"><h3 class="font-bold text-foreground">{{title|Payment status}}</h3><p class="mt-1 text-sm text-muted-foreground">{{message}}</p><p class="mt-2 truncate font-mono text-xs text-muted-foreground">{{reference}}</p></div>{{action}}</div>'],

            'payment:success' => ['title' => 'Payment Success Result', 'template' => '<section class="mx-auto max-w-lg rounded-3xl border border-success/30 bg-success/5 p-8 text-center {{class}}" {{style_attr}} {{attr}}><div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-success text-2xl text-white" aria-hidden="true">&#10003;</div><h2 class="mt-5 text-2xl font-bold text-foreground">{{title|Payment successful}}</h2><p class="mt-2 text-sm text-muted-foreground">{{message|Your payment was confirmed successfully.}}</p><div class="mt-6 rounded-xl border border-success/20 bg-background p-4 text-left">{{details}}</div><div class="mt-6">@slot</div></section>'],

            'payment:failed' => ['title' => 'Payment Failure Result', 'template' => '<section class="mx-auto max-w-lg rounded-3xl border border-destructive/30 bg-destructive/5 p-8 text-center {{class}}" role="alert" {{style_attr}} {{attr}}><div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-destructive text-2xl text-white" aria-hidden="true">&#10005;</div><h2 class="mt-5 text-2xl font-bold text-foreground">{{title|Payment was not completed}}</h2><p class="mt-2 text-sm text-muted-foreground">{{message|No confirmed charge was recorded. You can safely try again.}}</p><div class="mt-6">@slot</div></section>'],

            'payment:receipt' => ['title' => 'Payment Receipt', 'template' => '<article class="mx-auto max-w-2xl rounded-3xl border border-border bg-card p-6 shadow-sm sm:p-8 {{class}}" {{style_attr}} {{attr}}><header class="flex flex-col gap-3 border-b border-border pb-5 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-xs font-semibold uppercase tracking-widest text-muted-foreground">{{label|Payment receipt}}</p><h2 class="mt-1 text-2xl font-bold text-foreground">{{merchant}}</h2></div><div class="text-left sm:text-right"><p class="font-mono text-sm text-foreground">{{reference}}</p><p class="text-xs text-muted-foreground">{{date}}</p></div></header><div class="py-6">{{details}}</div><footer class="flex items-center justify-between border-t border-border pt-5 text-lg font-bold"><span>{{total_label|Total paid}}</span><span>{{total}}</span></footer></article>'],

            'payment:refund-status' => ['title' => 'Payment Refund Status', 'template' => '<div class="rounded-2xl border border-warning/30 bg-warning/5 p-5 {{class}}" {{style_attr}} {{attr}}><div class="flex items-center justify-between gap-4"><div><h3 class="font-bold text-foreground">{{title|Refund status}}</h3><p class="mt-1 text-sm text-muted-foreground">{{message}}</p></div><span class="rounded-full border border-warning/30 bg-background px-3 py-1 text-xs font-semibold text-warning">{{status|Pending}}</span></div><div class="mt-4">@slot</div></div>'],

            'payment:masked-method' => ['title' => 'Masked Payment Method', 'template' => '<div class="flex items-center gap-4 rounded-xl border border-border bg-background p-4 {{class}}" {{style_attr}} {{attr}}><span class="flex h-10 w-14 items-center justify-center rounded-lg bg-muted">{{logo}}</span><div class="min-w-0 flex-1"><p class="font-semibold text-foreground">{{brand|Payment method}} <span class="font-mono">{{masked|&#8226;&#8226;&#8226;&#8226;}}</span></p><p class="text-xs text-muted-foreground">{{description}}</p></div><div>{{action}}</div></div>'],

            'courier:selector' => ['title' => 'Courier Provider Selector', 'template' => '<fieldset class="rounded-2xl border border-border bg-card p-5 sm:p-6 {{class}}" {{style_attr}} {{attr}}><legend class="px-2 text-base font-bold text-foreground">{{title|Choose a courier}}</legend><p class="mb-4 text-sm text-muted-foreground">{{description|Select an available delivery service.}}</p><div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" role="radiogroup">@slot</div></fieldset>'],

            'courier:option' => ['title' => 'Courier Provider Option', 'template' => '<label class="group flex min-h-16 cursor-pointer items-center gap-3 rounded-xl border border-border bg-background p-4 transition hover:border-primary/50 hover:bg-muted has-[:checked]:border-primary has-[:checked]:ring-2 has-[:checked]:ring-primary/20 {{class}}" {{style_attr}} {{attr}}><input class="sr-only" type="radio" name="{{name|courier}}" value="{{value}}"><span class="flex h-10 w-12 shrink-0 items-center justify-center rounded-lg bg-white p-1">{{logo|&#128666;}}</span><span class="min-w-0 flex-1"><strong class="block text-sm text-foreground">{{label|Courier}}</strong><span class="block truncate text-xs text-muted-foreground">{{description}}</span></span><span aria-hidden="true" class="text-primary opacity-0 group-has-[:checked]:opacity-100">&#10003;</span></label>'],

            'courier:tracking-form' => ['title' => 'Courier Tracking Form', 'template' => '<form data-ph-courier-track action="{{action|/courier/track}}" method="post" class="rounded-2xl border border-border bg-card p-5 shadow-sm sm:p-6 {{class}}" {{style_attr}} {{attr}}><div class="flex flex-col gap-2"><label for="{{id|courier-tracking}}" class="font-semibold text-foreground">{{label|Track your shipment}}</label><p class="text-sm text-muted-foreground">{{description|Enter the tracking or consignment number supplied by the courier.}}</p></div><div class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]"><input id="{{id|courier-tracking}}" name="tracking_id" required maxlength="160" autocomplete="off" spellcheck="false" placeholder="{{placeholder|Tracking number}}" class="h-12 min-w-0 rounded-xl border border-input bg-background px-4 font-mono text-foreground focus:outline-none focus:ring-2 focus:ring-ring"><button type="submit" class="h-12 rounded-xl bg-primary px-6 font-semibold text-primary-foreground hover:bg-primary/90 disabled:cursor-wait disabled:opacity-60">{{button|Track}}</button></div><div data-ph-courier-result class="mt-5" aria-live="polite" aria-atomic="false">@slot</div></form>'],

            'courier:tracking-result' => ['title' => 'Courier Tracking Result', 'template' => '<section class="rounded-2xl border border-border bg-card p-5 {{class}}" role="status" aria-live="polite" {{style_attr}} {{attr}}><div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div class="min-w-0"><p class="text-xs font-semibold uppercase tracking-widest text-muted-foreground">{{courier|Courier}}</p><h2 class="mt-1 truncate font-mono text-lg font-bold text-foreground">{{tracking_id}}</h2></div><span class="inline-flex w-fit rounded-full bg-{{tone|primary}}/10 px-3 py-1 text-xs font-semibold text-{{tone|primary}}">{{status|Processing}}</span></div><p class="mt-4 text-sm text-muted-foreground">{{message}}</p><div class="mt-5">@slot</div></section>'],

            'courier:timeline' => ['title' => 'Courier Tracking Timeline', 'template' => '<ol class="relative ml-2 space-y-6 border-l border-border pl-6 {{class}}" aria-label="{{label|Shipment progress}}" {{style_attr}} {{attr}}>@slot</ol>'],

            'courier:timeline-event' => ['title' => 'Courier Tracking Timeline Event', 'template' => '<li class="relative {{class}}" {{style_attr}} {{attr}}><span class="absolute -left-[1.9rem] top-1 flex h-3 w-3 rounded-full bg-{{tone|primary}} ring-4 ring-background" aria-hidden="true"></span><div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between"><strong class="text-sm text-foreground">{{title|Shipment updated}}</strong><time datetime="{{datetime}}" class="text-xs text-muted-foreground">{{date}}</time></div><p class="mt-1 text-sm text-muted-foreground">{{description}}</p><p class="mt-1 text-xs text-muted-foreground">{{location}}</p></li>'],

            'courier:shipment-card' => ['title' => 'Courier Shipment Card', 'template' => '<article class="rounded-2xl border border-border bg-card p-5 shadow-sm {{class}}" {{style_attr}} {{attr}}><header class="flex items-start justify-between gap-4"><div class="min-w-0"><p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{courier|Courier}}</p><h3 class="mt-1 truncate font-mono font-bold text-foreground">{{tracking_id}}</h3></div><span class="rounded-full bg-{{tone|primary}}/10 px-3 py-1 text-xs font-semibold text-{{tone|primary}}">{{status}}</span></header><div class="mt-5 grid grid-cols-2 gap-4 text-sm"><div><span class="block text-xs text-muted-foreground">{{from_label|From}}</span><strong class="text-foreground">{{from}}</strong></div><div><span class="block text-xs text-muted-foreground">{{to_label|To}}</span><strong class="text-foreground">{{to}}</strong></div><div><span class="block text-xs text-muted-foreground">{{eta_label|Estimated delivery}}</span><strong class="text-foreground">{{eta}}</strong></div><div><span class="block text-xs text-muted-foreground">{{service_label|Service}}</span><strong class="text-foreground">{{service}}</strong></div></div><footer class="mt-5 border-t border-border pt-4">@slot</footer></article>'],

            'courier:rate-card' => ['title' => 'Courier Rate Option', 'template' => '<label class="flex cursor-pointer flex-col rounded-2xl border border-border bg-card p-5 transition hover:border-primary/50 has-[:checked]:border-primary has-[:checked]:ring-2 has-[:checked]:ring-primary/20 {{class}}" {{style_attr}} {{attr}}><div class="flex items-start justify-between gap-4"><div><input class="sr-only" type="radio" name="{{name|shipping_rate}}" value="{{value}}"><h3 class="font-bold text-foreground">{{service|Delivery service}}</h3><p class="mt-1 text-sm text-muted-foreground">{{courier}}</p></div><strong class="text-lg text-foreground">{{price}}</strong></div><div class="mt-4 flex items-center justify-between border-t border-border pt-4 text-xs text-muted-foreground"><span>{{eta}}</span><span>{{note}}</span></div></label>'],

            'courier:address-form' => ['title' => 'Courier Address Form', 'template' => '<fieldset data-ph-courier-address class="rounded-2xl border border-border bg-card p-5 sm:p-6 {{class}}" {{style_attr}} {{attr}}><legend class="px-2 font-bold text-foreground">{{title|Delivery address}}</legend><div class="grid gap-4 sm:grid-cols-2">{{identity}}</div><div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{{locations}}</div><div class="mt-4">{{address}}</div><div class="mt-5">@slot</div></fieldset>'],

            'courier:label-preview' => ['title' => 'Courier Label Preview', 'template' => '<figure class="overflow-hidden rounded-2xl border border-border bg-card {{class}}" {{style_attr}} {{attr}}><div class="flex min-h-64 items-center justify-center bg-muted/40 p-4">{{label}}</div><figcaption class="flex flex-col gap-3 border-t border-border p-4 sm:flex-row sm:items-center sm:justify-between"><div><strong class="block text-foreground">{{title|Shipping label}}</strong><span class="font-mono text-xs text-muted-foreground">{{tracking_id}}</span></div><div class="flex gap-2">@slot</div></figcaption></figure>'],

            'courier:pickup-card' => ['title' => 'Courier Pickup Summary', 'template' => '<section class="rounded-2xl border border-border bg-card p-5 {{class}}" {{style_attr}} {{attr}}><div class="flex items-start gap-4"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary" aria-hidden="true">&#128666;</span><div class="min-w-0 flex-1"><div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-bold text-foreground">{{title|Pickup scheduled}}</h3><span class="rounded-full bg-{{tone|primary}}/10 px-3 py-1 text-xs font-semibold text-{{tone|primary}}">{{status}}</span></div><p class="mt-1 text-sm text-muted-foreground">{{date}}</p><p class="mt-2 text-sm text-foreground">{{address}}</p><p class="mt-2 font-mono text-xs text-muted-foreground">{{reference}}</p></div></div><div class="mt-5 border-t border-border pt-4">@slot</div></section>'],

            'courier:error' => ['title' => 'Courier Error State', 'template' => '<div class="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm {{class}}" role="alert" {{style_attr}} {{attr}}><strong class="text-destructive">{{title|Courier request failed}}</strong><p class="mt-1 text-muted-foreground">{{message|Please verify the tracking number and try again.}}</p></div>'],

            'form:radio' => ['title' => 'Form Radio Button', 'template' => '<div class="flex items-center space-x-2 {{class}}" {{style_attr}} {{attr}}><input type="radio" name="{{name}}" value="{{value}}" id="{{id}}" class="aspect-square h-4 w-4 rounded-full border border-primary text-primary ring-offset-background focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50"><label for="{{id}}" class="text-sm font-medium leading-none text-foreground">{{label}}</label></div>'],

            'form:range' => ['title' => 'Range Slider Input', 'template' => "<div class=\"space-y-3 {{class}}\"><label class=\"text-sm font-semibold ml-1\">{{label}}</label><input type=\"range\" class=\"w-full h-2 bg-muted rounded-lg appearance-none cursor-pointer accent-primary $int\" {{style_attr}} {{attr}}></div>"],

            'form:registration' => ['title' => 'Registration Container Wrapper', 'template' => "<div class=\"p-10 border rounded-[2.5rem] bg-card shadow-2xl max-w-md w-full mx-auto space-y-6 $int {{class}}\" {{style_attr}} {{attr}}><h2 class=\"text-4xl font-black text-center\">Sign Up</h2>@slot</div>"],

            'form:search-bar' => ['title' => 'Search Bar Component', 'template' => "<div class=\"relative group w-full max-w-xl mx-auto {{class}}\" {{style_attr}} {{attr}}><span class=\"absolute left-4 top-1/2 -translate-y-1/2 text-muted-foreground\">🔍</span><input type=\"search\" placeholder=\"Search anything...\" class=\"w-full h-14 pl-12 pr-6 rounded-2xl border bg-background group-focus-within:ring-2 ring-primary/20 $int\"></div>"],

            'form:select' => ['title' => 'Structured Form Select', 'template' => '<div class="grid w-full items-center gap-1.5 {{class}}" {{style_attr}} {{attr}}><label class="text-sm font-medium leading-none text-foreground">{{label}}</label><select name="{{name}}" class="flex h-10 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">{{options}}</select></div>'],

            'form:select-layout' => ['title' => 'Slot-based Select Input', 'template' => "<div class=\"space-y-2 {{class}}\"><label class=\"text-sm font-semibold ml-1\">{{label}}</label><select class=\"w-full h-12 px-4 rounded-xl border bg-background text-sm appearance-none $int\" {{style_attr}} {{attr}}>@slot</select></div>"],

            'form:subscription' => ['title' => 'Newsletter Subscription Form', 'template' => "<form class=\"flex flex-col sm:flex-row gap-3 max-w-xl mx-auto {{class}}\" {{style_attr}} {{attr}}>@slot</form>"],

            'form:switch' => ['title' => 'Rounded Switch Toggle', 'template' => '<div class="flex items-center gap-4 w-max {{class}}" {{style_attr}} {{attr}}><label class="relative cursor-pointer inline-flex items-center h-6 leading-none"><input type="checkbox" name="{{name}}" value="{{value}}" role="switch" class="sr-only peer" {{checked}}><div class="w-10 h-6 bg-muted rounded-full peer-checked:bg-primary transition-colors"></div><div class="absolute left-1 top-[4px] w-4 h-4 bg-background rounded-full transition-transform peer-checked:translate-x-4 shadow-sm"></div></label><span class="text-sm font-medium text-foreground">{{label}}</span></div>'],

            'form:switch-outline' => ['title' => 'Outline Style Switch', 'template' => '<div class="flex items-center gap-4 w-max {{class}}" {{style_attr}} {{attr}}><label class="relative cursor-pointer inline-flex items-center h-6 leading-none"><input type="checkbox" name="{{name}}" value="{{value}}" role="switch" class="sr-only peer" {{checked}}><div class="w-10 h-6 border-2 border-input rounded-full peer-checked:border-primary transition-colors"></div><div class="absolute left-1.5 top-[6px] w-3 h-3 bg-muted-foreground rounded-full transition-transform peer-checked:translate-x-4 peer-checked:bg-primary"></div></label><span class="text-sm font-medium text-foreground">{{label}}</span></div>'],

            'form:switch-square' => ['title' => 'Square Style Switch', 'template' => '<div class="flex items-center gap-4 w-max {{class}}" {{style_attr}} {{attr}}><label class="relative cursor-pointer inline-flex items-center h-6 leading-none"><input type="checkbox" name="{{name}}" value="{{value}}" role="switch" class="sr-only peer" {{checked}}><div class="w-10 h-6 bg-muted rounded-md peer-checked:bg-primary transition-colors"></div><div class="absolute left-1 top-[4px] w-4 h-4 bg-background rounded-sm transition-transform peer-checked:translate-x-4 shadow-sm"></div></label><span class="text-sm font-medium text-foreground">{{label}}</span></div>'],

            'form:switch-text' => ['title' => 'Switch Toggle with Text labels', 'template' => '<div class="flex items-center gap-4 w-max {{class}}" {{style_attr}} {{attr}}><span class="text-sm font-medium text-foreground">{{label}}</span><label class="relative cursor-pointer inline-flex items-center h-7 leading-none"><input type="checkbox" name="{{name}}" value="{{value}}" class="sr-only peer" {{checked}}><div class="w-[53px] h-7 bg-muted rounded-full text-[9px] peer-checked:text-primary-foreground text-muted-foreground font-bold flex items-center px-[3px] peer-checked:bg-primary transition-all"></div><div class="absolute left-[3px] top-[2px] w-6 h-6 flex items-center justify-center bg-background border border-border rounded-full transition-transform peer-checked:translate-x-[23px] text-center"><span class="peer-checked:hidden text-[9px]">{{text_off|Off}}</span><span class="hidden peer-checked:inline-block text-[9px]">{{text_on|On}}</span></div></label></div>'],

            'form:switch-thin' => ['title' => 'Thin Track Switch Toggle', 'template' => '<div class="flex items-center gap-4 w-max {{class}}" {{style_attr}} {{attr}}><label class="relative cursor-pointer inline-flex items-center h-8 leading-none"><input type="checkbox" name="{{name}}" value="{{value}}" class="sr-only peer" {{checked}}><div class="w-11 h-3 bg-muted rounded-full peer-checked:bg-primary/40 transition-colors"></div><div class="absolute left-0 top-[4px] w-6 h-6 bg-white border border-muted-foreground rounded-full transition-transform peer-checked:translate-x-5 peer-checked:border-primary peer-checked:bg-primary shadow-sm"></div></label><span class="text-sm font-medium text-foreground">{{label}}</span></div>'],

            'form:tag-input' => ['title' => 'Tag Input Component', 'template' => "<div class=\"space-y-2 {{class}}\"><label class=\"text-sm font-semibold ml-1\">{{label}}</label><div class=\"flex flex-wrap gap-2 p-2 border rounded-xl bg-background $int\"><span class=\"inline-flex items-center gap-1.5 px-3 py-1 bg-primary/10 text-primary rounded-lg text-xs font-bold\">Tag <button class=\"hover:text-foreground\">×</button></span>@slot<input class=\"flex-1 bg-transparent border-0 outline-none px-2 min-w-[100px] text-sm\" placeholder=\"Add tags...\"></div></div>"],

            'form:textarea' => ['title' => 'Form Textarea Input', 'template' => '<div class="grid w-full items-center gap-1.5 {{class}}" {{style_attr}} {{attr}}><label class="text-sm font-medium leading-none text-foreground">{{label}}</label><textarea name="{{name}}" rows="{{rows|3}}" placeholder="{{placeholder}}" class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50">{{value}}</textarea></div>'],

            'form:textarea-layout' => ['title' => 'Slot-based Textarea Input', 'template' => "<div class=\"space-y-2 {{class}}\"><label class=\"text-sm font-semibold ml-1\">{{label}}</label><textarea placeholder=\"{{placeholder}}\" class=\"w-full min-h-[120px] px-4 py-3 rounded-xl border bg-background text-sm $int\" {{style_attr}} {{attr}}>{{value}}</textarea></div>"],

            'form:upload' => ['title' => 'File Drop & Upload Area', 'template' => "<div class=\"space-y-2 {{class}}\"><label class=\"text-sm font-semibold ml-1\">{{label}}</label><div class=\"relative h-32 border-2 border-dashed rounded-2xl flex items-center justify-center bg-muted/20 hover:bg-muted/40 transition-colors $int\"><input type=\"file\" class=\"absolute inset-0 opacity-0 cursor-pointer\" {{attr}}><div class=\"text-center\"><p class=\"text-sm font-medium\">Drop files here or click to upload</p></div></div></div>"],
            'form:editor' => ['title' => 'WYSIWYG Rich Text Editor', 'template' => '<div class="space-y-2 {{class}}" {{style_attr}} {{attr}}><label class="text-sm font-semibold ml-1">{{label}}</label><div class="border border-input rounded-xl overflow-hidden bg-background"><div class="flex flex-wrap items-center gap-1 border-b border-input p-2 bg-muted/20"><button type="button" onclick="document.execCommand(\'bold\',false,null)" class="p-1.5 rounded hover:bg-muted text-foreground" title="Bold"><b>B</b></button><button type="button" onclick="document.execCommand(\'italic\',false,null)" class="p-1.5 rounded hover:bg-muted text-foreground" title="Italic"><i>I</i></button><button type="button" onclick="document.execCommand(\'underline\',false,null)" class="p-1.5 rounded hover:bg-muted text-foreground" title="Underline"><u>U</u></button><span class="w-px h-4 bg-border mx-1"></span><button type="button" onclick="document.execCommand(\'insertOrderedList\',false,null)" class="p-1.5 rounded hover:bg-muted text-foreground" title="Numbered List">1.</button><button type="button" onclick="document.execCommand(\'insertUnorderedList\',false,null)" class="p-1.5 rounded hover:bg-muted text-foreground" title="Bulleted List">&bull;</button><span class="w-px h-4 bg-border mx-1"></span><button type="button" onclick="let url = prompt(\'Enter link URL:\'); if(url) document.execCommand(\'createLink\',false,url);" class="p-1.5 rounded hover:bg-muted text-foreground" title="Link">&#128279;</button></div><div class="p-4 min-h-[200px] max-h-[500px] overflow-y-auto outline-none" contenteditable="true" oninput="document.getElementById(\'{{id}}\').value = this.innerHTML">@slot</div><input type="hidden" name="{{name}}" id="{{id}}" value="{{value}}"></div></div>'],


            'header:center' => ['title' => 'Header Center', 'template' => '<header class="w-full border-b bg-background/95 backdrop-blur z-50 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4 flex h-16 items-center justify-between"><div class="font-bold text-xl">{{header_brand}}</div><nav class="hidden md:flex gap-6">{{header_items}}</nav><div class="flex gap-4">{{header_button}}</div></div></header>'],
            'header:left' => ['title' => 'Header Left', 'template' => '<header class="w-full border-b bg-background/95 backdrop-blur z-50 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4 flex h-16 items-center"><div class="font-bold text-xl mr-8">{{header_brand}}</div><nav class="hidden md:flex gap-6 flex-1">{{header_items}}</nav><div class="flex gap-4">{{header_button}}</div></div></header>'],
            'header:mega' => ['title' => 'Mega Header', 'template' => '<header class="w-full bg-primary text-primary-foreground z-50 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4 flex h-20 items-center justify-between"><div class="text-2xl font-black">{{header_brand}}</div><nav class="hidden lg:flex gap-8 text-sm uppercase tracking-wider font-semibold">{{header_items}}</nav><div>{{header_button}}</div></div></header>'],
            'footer:simple' => ['title' => 'Simple Footer', 'template' => '<footer class="border-t bg-muted/20 py-8 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4 flex flex-col md:flex-row justify-between items-center"><p class="text-sm text-muted-foreground">{{footer_copyright}}</p><nav class="flex gap-4 mt-4 md:mt-0 text-sm">{{footer_links}}</nav></div></footer>'],
            'footer:mega' => ['title' => 'Mega Footer', 'template' => '<footer class="border-t bg-background pt-16 pb-8 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4 grid grid-cols-1 md:grid-cols-4 gap-8 mb-12"><div><div class="font-bold text-2xl mb-4">{{footer_brand}}</div><p class="text-muted-foreground">{{footer_desc}}</p></div><div><h3 class="font-semibold mb-4">{{col1_title}}</h3><div class="flex flex-col gap-2">{{col1_items}}</div></div><div><h3 class="font-semibold mb-4">{{col2_title}}</h3><div class="flex flex-col gap-2">{{col2_items}}</div></div><div><h3 class="font-semibold mb-4">{{col3_title}}</h3><div class="flex flex-col gap-2">{{col3_items}}</div></div></div><div class="container mx-auto px-4 pt-8 border-t text-center text-sm text-muted-foreground">{{footer_copyright}}</div></footer>'],
            'sidebar:default' => ['title' => 'Default Sidebar', 'template' => '<aside class="w-64 h-screen border-r bg-card flex flex-col {{class}}" {{style_attr}} {{attr}}><div class="h-16 flex items-center px-6 border-b font-bold text-xl">{{sidebar_brand}}</div><nav class="flex-1 overflow-y-auto p-4 flex flex-col gap-2">{{sidebar_items}}</nav><div class="p-4 border-t">{{sidebar_footer}}</div></aside>'],
            'layout:dashboard' => ['title' => 'Dashboard Layout', 'template' => '<div class="flex h-screen w-full overflow-hidden bg-background {{class}}" {{style_attr}} {{attr}}>{{sidebar}}<div class="flex-1 flex flex-col overflow-hidden">{{header}}<main class="flex-1 overflow-y-auto p-6">@slot</main></div></div>'],
            'modal:center' => ['title' => 'Centered Modal', 'template' => '<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 {{class}}" {{style_attr}} {{attr}}><div class="bg-background rounded-2xl shadow-2xl w-full max-w-lg p-6 relative"><div class="flex justify-between items-center mb-4"><h3 class="text-xl font-bold">{{modal_title}}</h3><button class="text-muted-foreground hover:text-foreground">✕</button></div><div>@slot</div><div class="mt-6 flex justify-end gap-3">{{modal_actions}}</div></div></div>'],
            'drawer:right' => ['title' => 'Right Drawer', 'template' => '<div class="fixed inset-0 z-50 flex justify-end bg-black/50 backdrop-blur-sm {{class}}" {{style_attr}} {{attr}}><div class="bg-background w-full max-w-md h-full shadow-2xl flex flex-col"><div class="p-6 border-b flex justify-between items-center"><h3 class="font-bold text-xl">{{drawer_title}}</h3><button>✕</button></div><div class="p-6 flex-1 overflow-y-auto">@slot</div><div class="p-6 border-t">{{drawer_actions}}</div></div></div>'],
            'form:contact' => ['title' => 'Contact Form', 'template' => '<form class="space-y-6 bg-card p-8 rounded-3xl border shadow-sm {{class}}" {{style_attr}} {{attr}}><h3 class="text-2xl font-bold">{{form_title}}</h3><p class="text-muted-foreground mb-6">{{form_desc}}</p><div class="space-y-4">{{form_fields}}</div><div class="mt-6">{{form_button}}</div></form>'],
            'form:login' => ['title' => 'Login Form', 'template' => '<form class="w-full max-w-sm mx-auto space-y-6 bg-card p-8 rounded-3xl border shadow-sm {{class}}" {{style_attr}} {{attr}}><div class="text-center"><h3 class="text-2xl font-bold">{{form_title}}</h3><p class="text-muted-foreground mt-2">{{form_desc}}</p></div><div class="space-y-4">{{form_fields}}</div><div class="mt-6">{{form_button}}</div><div class="mt-4 text-center text-sm">{{form_footer}}</div></form>'],
            'form:register' => ['title' => 'Register Form', 'template' => '<form class="w-full max-w-md mx-auto space-y-6 bg-card p-8 rounded-3xl border shadow-sm {{class}}" {{style_attr}} {{attr}}><div class="text-center"><h3 class="text-2xl font-bold">{{form_title}}</h3><p class="text-muted-foreground mt-2">{{form_desc}}</p></div><div class="grid grid-cols-2 gap-4">{{form_row}}</div><div class="space-y-4">{{form_fields}}</div><div class="mt-6">{{form_button}}</div></form>'],
            'table:data' => ['title' => 'Data Table', 'template' => '<div class="w-full overflow-hidden border rounded-xl bg-card {{class}}" {{style_attr}} {{attr}}><div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead class="bg-muted/50 border-b"><tr>{{table_headers}}</tr></thead><tbody class="divide-y">{{table_rows}}</tbody></table></div></div>'],
            'ui:tabs' => ['title' => 'Tabs', 'template' => '<div class="w-full {{class}}" {{style_attr}} {{attr}}><div class="flex space-x-2 border-b mb-4">{{tab_headers}}</div><div>{{tab_content}}</div></div>'],
            'ui:accordion' => ['title' => 'Accordion', 'template' => '<div class="space-y-2 w-full {{class}}" {{style_attr}} {{attr}}>{{accordion_items}}</div>'],
            'ui:toast' => ['title' => 'Toast Notification', 'template' => '<div class="fixed bottom-4 right-4 z-50 flex items-center p-4 mb-4 text-foreground bg-card rounded-lg shadow-xl border {{class}}" role="alert" {{style_attr}} {{attr}}><div class="inline-flex items-center justify-center flex-shrink-0 w-8 h-8 rounded-lg {{icon_class}}">{{toast_icon}}</div><div class="ml-3 text-sm font-medium">{{toast_msg}}</div><button type="button" class="ml-auto -mx-1.5 -my-1.5 rounded-lg focus:ring-2 p-1.5 hover:bg-muted inline-flex h-8 w-8">✕</button></div>'],
            'ui:progress' => ['title' => 'Progress Bar', 'template' => '<div class="w-full bg-muted rounded-full h-2.5 {{class}}" {{style_attr}} {{attr}}><div class="bg-primary h-2.5 rounded-full" style="width: {{percent}}%"></div></div>'],
            'ui:avatar' => ['title' => 'Avatar', 'template' => '<img class="w-10 h-10 rounded-full border border-border object-cover {{class}}" src="{{src}}" alt="{{alt}}" {{style_attr}} {{attr}}/>'],
            'ui:badge' => ['title' => 'Badge', 'template' => '<span class="px-2.5 py-0.5 rounded-full text-xs font-semibold {{class}}" {{style_attr}} {{attr}}>{{label}}</span>'],
            'ui:spinner' => ['title' => 'Spinner', 'template' => '<svg class="animate-spin h-5 w-5 {{class}}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" {{style_attr}} {{attr}}><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>'],
            'section:hero' => ['title' => 'Hero Section', 'template' => '<section class="relative bg-background pt-24 pb-32 lg:pt-36 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4 text-center"><h1 class="text-4xl font-extrabold tracking-tight text-foreground sm:text-5xl md:text-6xl">{{hero_title}}</h1><p class="mx-auto mt-4 max-w-2xl text-lg text-muted-foreground">{{hero_desc}}</p><div class="mt-10 flex justify-center gap-4">{{hero_buttons}}</div></div></section>'],
            'section:pricing' => ['title' => 'Pricing Section', 'template' => '<section class="py-24 bg-background {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-16"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground">{{section_desc}}</p></div><div class="grid gap-8 lg:grid-cols-3 max-w-6xl mx-auto">{{pricing_cards}}</div></div></section>'],
            'section:feature' => ['title' => 'Feature Section', 'template' => '<section class="py-24 bg-muted/30 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-16"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground">{{section_desc}}</p></div><div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">{{feature_items}}</div></div></section>'],
            'section:testimonial' => ['title' => 'Testimonial Section', 'template' => '<section class="py-24 bg-muted/20 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-16"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2></div><div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">{{testimonial_items}}</div></div></section>'],
            'section:cta' => ['title' => 'CTA Section', 'template' => '<section class="bg-primary {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4 py-16 sm:py-24 lg:flex lg:items-center lg:justify-between"><h2 class="text-3xl font-extrabold tracking-tight text-primary-foreground sm:text-4xl"><span class="block">{{cta_title}}</span><span class="block text-primary-foreground/80">{{cta_subtitle}}</span></h2><div class="mt-8 flex lg:mt-0 lg:flex-shrink-0 gap-4">{{cta_buttons}}</div></div></section>'],
            'section:faq' => ['title' => 'FAQ Section', 'template' => '<section class="py-24 bg-background {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4 max-w-4xl"><div class="text-center mb-16"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground">{{section_desc}}</p></div><div class="space-y-6">{{faq_items}}</div></div></section>'],
            'section:blog' => ['title' => 'Blog Section', 'template' => '<section class="py-24 bg-background {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-16"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground">{{section_desc}}</p></div><div class="grid gap-8 md:grid-cols-2 lg:grid-cols-3">{{blog_posts}}</div></div></section>'],
            'section:stats' => ['title' => 'Stats Section', 'template' => '<section class="py-24 bg-primary text-primary-foreground {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="grid grid-cols-2 gap-8 md:grid-cols-4 text-center">{{stat_items}}</div></div></section>'],
            'ecommerce:product-list' => ['title' => 'Product List', 'template' => '<div class="grid grid-cols-1 gap-y-10 sm:grid-cols-2 gap-x-6 lg:grid-cols-3 xl:grid-cols-4 xl:gap-x-8 {{class}}" {{style_attr}} {{attr}}>{{products}}</div>'],
            'ecommerce:product-card' => ['title' => 'Product Card', 'template' => '<div class="group relative {{class}}" {{style_attr}} {{attr}}><div class="w-full min-h-80 bg-muted aspect-w-1 aspect-h-1 rounded-md overflow-hidden group-hover:opacity-75 lg:h-80 lg:aspect-none"><img src="{{product_image}}" alt="{{product_name}}" class="w-full h-full object-center object-cover"></div><div class="mt-4 flex justify-between"><div><h3 class="text-sm text-foreground"><a href="{{product_link}}"><span aria-hidden="true" class="absolute inset-0"></span>{{product_name}}</a></h3><p class="mt-1 text-sm text-muted-foreground">{{product_category}}</p></div><p class="text-sm font-medium text-foreground">{{product_price}}</p></div></div>'],
            'ecommerce:cart' => ['title' => 'Shopping Cart', 'template' => '<div class="flex h-full flex-col overflow-y-scroll bg-background shadow-xl {{class}}" {{style_attr}} {{attr}}><div class="flex-1 overflow-y-auto py-6 px-4 sm:px-6"><div class="flex items-start justify-between"><h2 class="text-lg font-medium text-foreground">Shopping cart</h2><div class="ml-3 flex h-7 items-center"><button type="button" class="-m-2 p-2 text-muted-foreground hover:text-foreground">✕</button></div></div><div class="mt-8"><div class="flow-root"><ul role="list" class="-my-6 divide-y divide-border">{{cart_items}}</ul></div></div></div><div class="border-t border-border py-6 px-4 sm:px-6"><div class="flex justify-between text-base font-medium text-foreground"><p>Subtotal</p><p>{{cart_total}}</p></div><p class="mt-0.5 text-sm text-muted-foreground">Shipping and taxes calculated at checkout.</p><div class="mt-6">{{checkout_button}}</div><div class="mt-6 flex justify-center text-sm text-center text-muted-foreground"><p>or <button type="button" class="text-primary font-medium hover:text-primary/80">Continue Shopping<span aria-hidden="true"> &rarr;</span></button></p></div></div></div>'],
            'ecommerce:checkout' => ['title' => 'Checkout Form', 'template' => '<div class="max-w-2xl mx-auto pt-16 pb-24 px-4 sm:px-6 lg:max-w-7xl lg:px-8 {{class}}" {{style_attr}} {{attr}}><h2 class="sr-only">Checkout</h2><form class="lg:grid lg:grid-cols-2 lg:gap-x-12 xl:gap-x-16"><div><div><h2 class="text-lg font-medium text-foreground">Contact information</h2><div class="mt-4">{{contact_fields}}</div></div><div class="mt-10 border-t border-border pt-10"><h2 class="text-lg font-medium text-foreground">Shipping information</h2><div class="mt-4 grid grid-cols-1 gap-y-6 sm:grid-cols-2 sm:gap-x-4">{{shipping_fields}}</div></div><div class="mt-10 border-t border-border pt-10"><h2 class="text-lg font-medium text-foreground">Payment</h2><div class="mt-4 grid grid-cols-1 gap-y-6 sm:grid-cols-2 sm:gap-x-4">{{payment_fields}}</div></div></div><div class="mt-10 lg:mt-0"><h2 class="text-lg font-medium text-foreground">Order summary</h2><div class="mt-4 bg-card border rounded-lg shadow-sm">{{order_summary}}</div></div></form></div>'],
            'section:team-v1' => ['title' => 'Team Variant 1', 'template' => '<section class="py-16 md:py-24 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl md:text-4xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground max-w-2xl mx-auto">{{section_desc}}</p></div><div class="grid gap-8 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">{{team_members}}</div></div></section>'],
            'section:team-card-v1' => ['title' => 'Team Card 1', 'template' => '<div class=" text-center {{class}}" {{style_attr}} {{attr}}><img class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-background shadow-md" src="{{image}}" alt="{{name}}"><h3 class="text-xl font-bold text-foreground">{{name}}</h3><p class="text-primary font-medium mb-4">{{role}}</p><div class="flex justify-center gap-3 text-muted-foreground">{{social_links}}</div></div>'],
            'section:team-v2' => ['title' => 'Team Variant 2', 'template' => '<section class="py-16 md:py-24 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl md:text-4xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground max-w-2xl mx-auto">{{section_desc}}</p></div><div class="grid gap-8 grid-cols-2 lg:grid-cols-4">{{team_members}}</div></div></section>'],
            'section:team-card-v2' => ['title' => 'Team Card 2', 'template' => '<div class=" text-center {{class}}" {{style_attr}} {{attr}}><img class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-background shadow-md" src="{{image}}" alt="{{name}}"><h3 class="text-xl font-bold text-foreground">{{name}}</h3><p class="text-primary font-medium mb-4">{{role}}</p><div class="flex justify-center gap-3 text-muted-foreground">{{social_links}}</div></div>'],
            'section:team-v3' => ['title' => 'Team Variant 3', 'template' => '<section class="py-16 md:py-24 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl md:text-4xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground max-w-2xl mx-auto">{{section_desc}}</p></div><div class="grid gap-8 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">{{team_members}}</div></div></section>'],
            'section:team-card-v3' => ['title' => 'Team Card 3', 'template' => '<div class=" text-left {{class}}" {{style_attr}} {{attr}}><img class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-background shadow-md" src="{{image}}" alt="{{name}}"><h3 class="text-xl font-bold text-foreground">{{name}}</h3><p class="text-primary font-medium mb-4">{{role}}</p><div class="flex justify-center gap-3 text-muted-foreground">{{social_links}}</div></div>'],
            'section:team-v4' => ['title' => 'Team Variant 4', 'template' => '<section class="py-16 md:py-24 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl md:text-4xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground max-w-2xl mx-auto">{{section_desc}}</p></div><div class="grid gap-8 grid-cols-2 lg:grid-cols-4">{{team_members}}</div></div></section>'],
            'section:team-card-v4' => ['title' => 'Team Card 4', 'template' => '<div class=" text-center {{class}}" {{style_attr}} {{attr}}><img class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-background shadow-md" src="{{image}}" alt="{{name}}"><h3 class="text-xl font-bold text-foreground">{{name}}</h3><p class="text-primary font-medium mb-4">{{role}}</p><div class="flex justify-center gap-3 text-muted-foreground">{{social_links}}</div></div>'],
            'section:team-v5' => ['title' => 'Team Variant 5', 'template' => '<section class="py-16 md:py-24 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl md:text-4xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground max-w-2xl mx-auto">{{section_desc}}</p></div><div class="grid gap-8 grid-cols-1 md:grid-cols-5">{{team_members}}</div></div></section>'],
            'section:team-card-v5' => ['title' => 'Team Card 5', 'template' => '<div class=" text-center {{class}}" {{style_attr}} {{attr}}><img class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-background shadow-md" src="{{image}}" alt="{{name}}"><h3 class="text-xl font-bold text-foreground">{{name}}</h3><p class="text-primary font-medium mb-4">{{role}}</p><div class="flex justify-center gap-3 text-muted-foreground">{{social_links}}</div></div>'],
            'section:team-v6' => ['title' => 'Team Variant 6', 'template' => '<section class="py-16 md:py-24 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl md:text-4xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground max-w-2xl mx-auto">{{section_desc}}</p></div><div class="grid gap-8 grid-cols-2 lg:grid-cols-4">{{team_members}}</div></div></section>'],
            'section:team-card-v6' => ['title' => 'Team Card 6', 'template' => '<div class="bg-muted/10 rounded-2xl p-6 shadow-sm border text-left {{class}}" {{style_attr}} {{attr}}><img class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-background shadow-md" src="{{image}}" alt="{{name}}"><h3 class="text-xl font-bold text-foreground">{{name}}</h3><p class="text-primary font-medium mb-4">{{role}}</p><div class="flex justify-center gap-3 text-muted-foreground">{{social_links}}</div></div>'],
            'section:team-v7' => ['title' => 'Team Variant 7', 'template' => '<section class="py-16 md:py-24 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl md:text-4xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground max-w-2xl mx-auto">{{section_desc}}</p></div><div class="grid gap-8 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">{{team_members}}</div></div></section>'],
            'section:team-card-v7' => ['title' => 'Team Card 7', 'template' => '<div class="bg-muted/10 rounded-2xl p-6 shadow-sm border text-center {{class}}" {{style_attr}} {{attr}}><img class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-background shadow-md" src="{{image}}" alt="{{name}}"><h3 class="text-xl font-bold text-foreground">{{name}}</h3><p class="text-primary font-medium mb-4">{{role}}</p><div class="flex justify-center gap-3 text-muted-foreground">{{social_links}}</div></div>'],
            'section:team-v8' => ['title' => 'Team Variant 8', 'template' => '<section class="py-16 md:py-24 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl md:text-4xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground max-w-2xl mx-auto">{{section_desc}}</p></div><div class="grid gap-8 grid-cols-2 lg:grid-cols-4">{{team_members}}</div></div></section>'],
            'section:team-card-v8' => ['title' => 'Team Card 8', 'template' => '<div class="bg-muted/10 rounded-2xl p-6 shadow-sm border text-center {{class}}" {{style_attr}} {{attr}}><img class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-background shadow-md" src="{{image}}" alt="{{name}}"><h3 class="text-xl font-bold text-foreground">{{name}}</h3><p class="text-primary font-medium mb-4">{{role}}</p><div class="flex justify-center gap-3 text-muted-foreground">{{social_links}}</div></div>'],
            'section:team-v9' => ['title' => 'Team Variant 9', 'template' => '<section class="py-16 md:py-24 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl md:text-4xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground max-w-2xl mx-auto">{{section_desc}}</p></div><div class="grid gap-8 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">{{team_members}}</div></div></section>'],
            'section:team-card-v9' => ['title' => 'Team Card 9', 'template' => '<div class="bg-muted/10 rounded-2xl p-6 shadow-sm border text-left {{class}}" {{style_attr}} {{attr}}><img class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-background shadow-md" src="{{image}}" alt="{{name}}"><h3 class="text-xl font-bold text-foreground">{{name}}</h3><p class="text-primary font-medium mb-4">{{role}}</p><div class="flex justify-center gap-3 text-muted-foreground">{{social_links}}</div></div>'],
            'section:team-v10' => ['title' => 'Team Variant 10', 'template' => '<section class="py-16 md:py-24 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl md:text-4xl font-bold text-foreground">{{section_title}}</h2><p class="mt-4 text-muted-foreground max-w-2xl mx-auto">{{section_desc}}</p></div><div class="grid gap-8 grid-cols-2 lg:grid-cols-4">{{team_members}}</div></div></section>'],
            'section:team-card-v10' => ['title' => 'Team Card 10', 'template' => '<div class="bg-muted/10 rounded-2xl p-6 shadow-sm border text-center {{class}}" {{style_attr}} {{attr}}><img class="w-32 h-32 rounded-full mx-auto mb-4 object-cover border-4 border-background shadow-md" src="{{image}}" alt="{{name}}"><h3 class="text-xl font-bold text-foreground">{{name}}</h3><p class="text-primary font-medium mb-4">{{role}}</p><div class="flex justify-center gap-3 text-muted-foreground">{{social_links}}</div></div>'],
            'section:newsletter-v1' => ['title' => 'Newsletter Variant 1', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="bg-card border shadow-sm rounded-[2rem] p-8 md:p-16 text-center max-w-3xl mx-auto"><div class="mb-8 lg:mb-0 lg:w-1/2"><h2 class="text-3xl md:text-4xl font-bold">{{section_title}}</h2><p class="mt-4 text-muted-foreground text-lg">{{section_desc}}</p></div><div class="w-full lg:w-1/2 lg:pl-12"><form class="flex flex-col sm:flex-row gap-3">{{form_fields}}</form><p class="mt-3 text-sm opacity-75">{{disclaimer}}</p></div></div></div></section>'],
            'section:newsletter-v2' => ['title' => 'Newsletter Variant 2', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="bg-primary text-primary-foreground rounded-[2rem] p-8 md:p-16 text-center max-w-3xl mx-auto"><div class="mb-8 lg:mb-0 lg:w-1/2"><h2 class="text-3xl md:text-4xl font-bold">{{section_title}}</h2><p class="mt-4 text-primary-foreground/80 text-lg">{{section_desc}}</p></div><div class="w-full lg:w-1/2 lg:pl-12"><form class="flex flex-col sm:flex-row gap-3">{{form_fields}}</form><p class="mt-3 text-sm opacity-75">{{disclaimer}}</p></div></div></div></section>'],
            'section:newsletter-v3' => ['title' => 'Newsletter Variant 3', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="bg-card border shadow-sm rounded-[2rem] p-8 md:p-16 text-center max-w-3xl mx-auto"><div class="mb-8 lg:mb-0 lg:w-1/2"><h2 class="text-3xl md:text-4xl font-bold">{{section_title}}</h2><p class="mt-4 text-muted-foreground text-lg">{{section_desc}}</p></div><div class="w-full lg:w-1/2 lg:pl-12"><form class="flex flex-col sm:flex-row gap-3">{{form_fields}}</form><p class="mt-3 text-sm opacity-75">{{disclaimer}}</p></div></div></div></section>'],
            'section:newsletter-v4' => ['title' => 'Newsletter Variant 4', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="bg-primary text-primary-foreground rounded-[2rem] p-8 md:p-16 text-center max-w-3xl mx-auto"><div class="mb-8 lg:mb-0 lg:w-1/2"><h2 class="text-3xl md:text-4xl font-bold">{{section_title}}</h2><p class="mt-4 text-primary-foreground/80 text-lg">{{section_desc}}</p></div><div class="w-full lg:w-1/2 lg:pl-12"><form class="flex flex-col sm:flex-row gap-3">{{form_fields}}</form><p class="mt-3 text-sm opacity-75">{{disclaimer}}</p></div></div></div></section>'],
            'section:newsletter-v5' => ['title' => 'Newsletter Variant 5', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="bg-card border shadow-sm rounded-[2rem] p-8 md:p-16 text-center max-w-3xl mx-auto"><div class="mb-8 lg:mb-0 lg:w-1/2"><h2 class="text-3xl md:text-4xl font-bold">{{section_title}}</h2><p class="mt-4 text-muted-foreground text-lg">{{section_desc}}</p></div><div class="w-full lg:w-1/2 lg:pl-12"><form class="flex flex-col sm:flex-row gap-3">{{form_fields}}</form><p class="mt-3 text-sm opacity-75">{{disclaimer}}</p></div></div></div></section>'],
            'section:newsletter-v6' => ['title' => 'Newsletter Variant 6', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="bg-primary text-primary-foreground rounded-[2rem] p-8 md:p-16 flex flex-col lg:flex-row lg:items-center lg:justify-between"><div class="mb-8 lg:mb-0 lg:w-1/2"><h2 class="text-3xl md:text-4xl font-bold">{{section_title}}</h2><p class="mt-4 text-primary-foreground/80 text-lg">{{section_desc}}</p></div><div class="w-full lg:w-1/2 lg:pl-12"><form class="flex flex-col sm:flex-row gap-3">{{form_fields}}</form><p class="mt-3 text-sm opacity-75">{{disclaimer}}</p></div></div></div></section>'],
            'section:newsletter-v7' => ['title' => 'Newsletter Variant 7', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="bg-card border shadow-sm rounded-[2rem] p-8 md:p-16 flex flex-col lg:flex-row lg:items-center lg:justify-between"><div class="mb-8 lg:mb-0 lg:w-1/2"><h2 class="text-3xl md:text-4xl font-bold">{{section_title}}</h2><p class="mt-4 text-muted-foreground text-lg">{{section_desc}}</p></div><div class="w-full lg:w-1/2 lg:pl-12"><form class="flex flex-col sm:flex-row gap-3">{{form_fields}}</form><p class="mt-3 text-sm opacity-75">{{disclaimer}}</p></div></div></div></section>'],
            'section:newsletter-v8' => ['title' => 'Newsletter Variant 8', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="bg-primary text-primary-foreground rounded-[2rem] p-8 md:p-16 flex flex-col lg:flex-row lg:items-center lg:justify-between"><div class="mb-8 lg:mb-0 lg:w-1/2"><h2 class="text-3xl md:text-4xl font-bold">{{section_title}}</h2><p class="mt-4 text-primary-foreground/80 text-lg">{{section_desc}}</p></div><div class="w-full lg:w-1/2 lg:pl-12"><form class="flex flex-col sm:flex-row gap-3">{{form_fields}}</form><p class="mt-3 text-sm opacity-75">{{disclaimer}}</p></div></div></div></section>'],
            'section:newsletter-v9' => ['title' => 'Newsletter Variant 9', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="bg-card border shadow-sm rounded-[2rem] p-8 md:p-16 flex flex-col lg:flex-row lg:items-center lg:justify-between"><div class="mb-8 lg:mb-0 lg:w-1/2"><h2 class="text-3xl md:text-4xl font-bold">{{section_title}}</h2><p class="mt-4 text-muted-foreground text-lg">{{section_desc}}</p></div><div class="w-full lg:w-1/2 lg:pl-12"><form class="flex flex-col sm:flex-row gap-3">{{form_fields}}</form><p class="mt-3 text-sm opacity-75">{{disclaimer}}</p></div></div></div></section>'],
            'section:newsletter-v10' => ['title' => 'Newsletter Variant 10', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="bg-primary text-primary-foreground rounded-[2rem] p-8 md:p-16 flex flex-col lg:flex-row lg:items-center lg:justify-between"><div class="mb-8 lg:mb-0 lg:w-1/2"><h2 class="text-3xl md:text-4xl font-bold">{{section_title}}</h2><p class="mt-4 text-primary-foreground/80 text-lg">{{section_desc}}</p></div><div class="w-full lg:w-1/2 lg:pl-12"><form class="flex flex-col sm:flex-row gap-3">{{form_fields}}</form><p class="mt-3 text-sm opacity-75">{{disclaimer}}</p></div></div></div></section>'],
            'section:content-v1' => ['title' => 'Content Variant 1', 'template' => '<section class="py-16 md:py-24 overflow-hidden {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="flex flex-col lg:flex-row items-center gap-16"><div class="w-full lg:w-1/2 "><img src="{{image}}" alt="{{title}}" class="w-full rounded-3xl shadow-xl"></div><div class="w-full lg:w-1/2"><h2 class="text-3xl md:text-4xl font-extrabold text-foreground tracking-tight mb-6">{{title}}</h2><div class="prose prose-lg dark:prose-invert text-muted-foreground space-y-6">{{content}}</div><div class="mt-8 flex gap-4">{{buttons}}</div></div></div></div></section>'],
            'section:content-v2' => ['title' => 'Content Variant 2', 'template' => '<section class="py-16 md:py-24 overflow-hidden {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="flex flex-col lg:flex-row items-center gap-16"><div class="w-full lg:w-1/2 lg:order-last"><img src="{{image}}" alt="{{title}}" class="w-full rounded-3xl shadow-xl"></div><div class="w-full lg:w-1/2"><h2 class="text-3xl md:text-4xl font-extrabold text-foreground tracking-tight mb-6">{{title}}</h2><div class="prose prose-lg dark:prose-invert text-muted-foreground space-y-6">{{content}}</div><div class="mt-8 flex gap-4">{{buttons}}</div></div></div></div></section>'],
            'section:content-v3' => ['title' => 'Content Variant 3', 'template' => '<section class="py-16 md:py-24 overflow-hidden {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="flex flex-col lg:flex-row items-center gap-16"><div class="w-full lg:w-1/2 "><img src="{{image}}" alt="{{title}}" class="w-full rounded-3xl shadow-xl"></div><div class="w-full lg:w-1/2"><h2 class="text-3xl md:text-4xl font-extrabold text-foreground tracking-tight mb-6">{{title}}</h2><div class="prose prose-lg dark:prose-invert text-muted-foreground space-y-6">{{content}}</div><div class="mt-8 flex gap-4">{{buttons}}</div></div></div></div></section>'],
            'section:content-v4' => ['title' => 'Content Variant 4', 'template' => '<section class="py-16 md:py-24 overflow-hidden {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="flex flex-col lg:flex-row items-center gap-16"><div class="w-full lg:w-1/2 lg:order-last"><img src="{{image}}" alt="{{title}}" class="w-full rounded-3xl shadow-xl"></div><div class="w-full lg:w-1/2"><h2 class="text-3xl md:text-4xl font-extrabold text-foreground tracking-tight mb-6">{{title}}</h2><div class="prose prose-lg dark:prose-invert text-muted-foreground space-y-6">{{content}}</div><div class="mt-8 flex gap-4">{{buttons}}</div></div></div></div></section>'],
            'section:content-v5' => ['title' => 'Content Variant 5', 'template' => '<section class="py-16 md:py-24 overflow-hidden {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="flex flex-col lg:flex-row items-center gap-16"><div class="w-full lg:w-1/2 "><img src="{{image}}" alt="{{title}}" class="w-full rounded-3xl shadow-xl"></div><div class="w-full lg:w-1/2"><h2 class="text-3xl md:text-4xl font-extrabold text-foreground tracking-tight mb-6">{{title}}</h2><div class="prose prose-lg dark:prose-invert text-muted-foreground space-y-6">{{content}}</div><div class="mt-8 flex gap-4">{{buttons}}</div></div></div></div></section>'],
            'section:content-v6' => ['title' => 'Content Variant 6', 'template' => '<section class="py-16 md:py-24 overflow-hidden {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="flex flex-col lg:flex-row items-center gap-16"><div class="w-full lg:w-1/2 lg:order-last"><img src="{{image}}" alt="{{title}}" class="w-full rounded-3xl shadow-xl"></div><div class="w-full lg:w-1/2"><h2 class="text-3xl md:text-4xl font-extrabold text-foreground tracking-tight mb-6">{{title}}</h2><div class="prose prose-lg dark:prose-invert text-muted-foreground space-y-6">{{content}}</div><div class="mt-8 flex gap-4">{{buttons}}</div></div></div></div></section>'],
            'section:content-v7' => ['title' => 'Content Variant 7', 'template' => '<section class="py-16 md:py-24 overflow-hidden {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="flex flex-col lg:flex-row items-center gap-16"><div class="w-full lg:w-1/2 "><img src="{{image}}" alt="{{title}}" class="w-full rounded-3xl shadow-xl"></div><div class="w-full lg:w-1/2"><h2 class="text-3xl md:text-4xl font-extrabold text-foreground tracking-tight mb-6">{{title}}</h2><div class="prose prose-lg dark:prose-invert text-muted-foreground space-y-6">{{content}}</div><div class="mt-8 flex gap-4">{{buttons}}</div></div></div></div></section>'],
            'section:content-v8' => ['title' => 'Content Variant 8', 'template' => '<section class="py-16 md:py-24 overflow-hidden {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="flex flex-col lg:flex-row items-center gap-16"><div class="w-full lg:w-1/2 lg:order-last"><img src="{{image}}" alt="{{title}}" class="w-full rounded-3xl shadow-xl"></div><div class="w-full lg:w-1/2"><h2 class="text-3xl md:text-4xl font-extrabold text-foreground tracking-tight mb-6">{{title}}</h2><div class="prose prose-lg dark:prose-invert text-muted-foreground space-y-6">{{content}}</div><div class="mt-8 flex gap-4">{{buttons}}</div></div></div></div></section>'],
            'section:content-v9' => ['title' => 'Content Variant 9', 'template' => '<section class="py-16 md:py-24 overflow-hidden {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="flex flex-col lg:flex-row items-center gap-16"><div class="w-full lg:w-1/2 "><img src="{{image}}" alt="{{title}}" class="w-full rounded-3xl shadow-xl"></div><div class="w-full lg:w-1/2"><h2 class="text-3xl md:text-4xl font-extrabold text-foreground tracking-tight mb-6">{{title}}</h2><div class="prose prose-lg dark:prose-invert text-muted-foreground space-y-6">{{content}}</div><div class="mt-8 flex gap-4">{{buttons}}</div></div></div></div></section>'],
            'section:content-v10' => ['title' => 'Content Variant 10', 'template' => '<section class="py-16 md:py-24 overflow-hidden {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="flex flex-col lg:flex-row items-center gap-16"><div class="w-full lg:w-1/2 lg:order-last"><img src="{{image}}" alt="{{title}}" class="w-full rounded-3xl shadow-xl"></div><div class="w-full lg:w-1/2"><h2 class="text-3xl md:text-4xl font-extrabold text-foreground tracking-tight mb-6">{{title}}</h2><div class="prose prose-lg dark:prose-invert text-muted-foreground space-y-6">{{content}}</div><div class="mt-8 flex gap-4">{{buttons}}</div></div></div></div></section>'],
            'section:gallery-v1' => ['title' => 'Gallery Variant 1', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2></div><div class="grid gap-4 grid-cols-1 sm:grid-cols-2 md:grid-cols-3">{{gallery_items}}</div></div></section>'],
            'section:gallery-item-v1' => ['title' => 'Gallery Item 1', 'template' => '<div class="group relative overflow-hidden rounded-xl {{class}}" {{style_attr}} {{attr}}><img src="{{image}}" alt="{{title}}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"><div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><h3 class="text-white font-bold text-lg">{{title}}</h3></div></div>'],
            'section:gallery-v2' => ['title' => 'Gallery Variant 2', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2></div><div class="grid gap-4 grid-cols-1 sm:grid-cols-2 md:grid-cols-3">{{gallery_items}}</div></div></section>'],
            'section:gallery-item-v2' => ['title' => 'Gallery Item 2', 'template' => '<div class="group relative overflow-hidden rounded-xl {{class}}" {{style_attr}} {{attr}}><img src="{{image}}" alt="{{title}}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"><div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><h3 class="text-white font-bold text-lg">{{title}}</h3></div></div>'],
            'section:gallery-v3' => ['title' => 'Gallery Variant 3', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2></div><div class="grid gap-4 grid-cols-2 md:grid-cols-4">{{gallery_items}}</div></div></section>'],
            'section:gallery-item-v3' => ['title' => 'Gallery Item 3', 'template' => '<div class="group relative overflow-hidden rounded-xl {{class}}" {{style_attr}} {{attr}}><img src="{{image}}" alt="{{title}}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"><div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><h3 class="text-white font-bold text-lg">{{title}}</h3></div></div>'],
            'section:gallery-v4' => ['title' => 'Gallery Variant 4', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2></div><div class="grid gap-4 grid-cols-1 sm:grid-cols-2 md:grid-cols-3">{{gallery_items}}</div></div></section>'],
            'section:gallery-item-v4' => ['title' => 'Gallery Item 4', 'template' => '<div class="group relative overflow-hidden rounded-xl {{class}}" {{style_attr}} {{attr}}><img src="{{image}}" alt="{{title}}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"><div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><h3 class="text-white font-bold text-lg">{{title}}</h3></div></div>'],
            'section:gallery-v5' => ['title' => 'Gallery Variant 5', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2></div><div class="grid gap-4 grid-cols-1 sm:grid-cols-2 md:grid-cols-3">{{gallery_items}}</div></div></section>'],
            'section:gallery-item-v5' => ['title' => 'Gallery Item 5', 'template' => '<div class="group relative overflow-hidden rounded-xl {{class}}" {{style_attr}} {{attr}}><img src="{{image}}" alt="{{title}}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"><div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><h3 class="text-white font-bold text-lg">{{title}}</h3></div></div>'],
            'section:gallery-v6' => ['title' => 'Gallery Variant 6', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2></div><div class="grid gap-4 grid-cols-2 md:grid-cols-4">{{gallery_items}}</div></div></section>'],
            'section:gallery-item-v6' => ['title' => 'Gallery Item 6', 'template' => '<div class="group relative overflow-hidden rounded-xl {{class}}" {{style_attr}} {{attr}}><img src="{{image}}" alt="{{title}}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"><div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><h3 class="text-white font-bold text-lg">{{title}}</h3></div></div>'],
            'section:gallery-v7' => ['title' => 'Gallery Variant 7', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2></div><div class="grid gap-4 grid-cols-1 sm:grid-cols-2 md:grid-cols-3">{{gallery_items}}</div></div></section>'],
            'section:gallery-item-v7' => ['title' => 'Gallery Item 7', 'template' => '<div class="group relative overflow-hidden rounded-xl {{class}}" {{style_attr}} {{attr}}><img src="{{image}}" alt="{{title}}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"><div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><h3 class="text-white font-bold text-lg">{{title}}</h3></div></div>'],
            'section:gallery-v8' => ['title' => 'Gallery Variant 8', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2></div><div class="grid gap-4 columns-1 sm:columns-2 md:columns-3 lg:columns-4 gap-4 space-y-4">{{gallery_items}}</div></div></section>'],
            'section:gallery-item-v8' => ['title' => 'Gallery Item 8', 'template' => '<div class="group relative overflow-hidden rounded-xl {{class}}" {{style_attr}} {{attr}}><img src="{{image}}" alt="{{title}}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"><div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><h3 class="text-white font-bold text-lg">{{title}}</h3></div></div>'],
            'section:gallery-v9' => ['title' => 'Gallery Variant 9', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2></div><div class="grid gap-4 columns-1 sm:columns-2 md:columns-3 lg:columns-4 gap-4 space-y-4">{{gallery_items}}</div></div></section>'],
            'section:gallery-item-v9' => ['title' => 'Gallery Item 9', 'template' => '<div class="group relative overflow-hidden rounded-xl {{class}}" {{style_attr}} {{attr}}><img src="{{image}}" alt="{{title}}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"><div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><h3 class="text-white font-bold text-lg">{{title}}</h3></div></div>'],
            'section:gallery-v10' => ['title' => 'Gallery Variant 10', 'template' => '<section class="py-16 {{class}}" {{style_attr}} {{attr}}><div class="container mx-auto px-4"><div class="text-center mb-12"><h2 class="text-3xl font-bold text-foreground">{{section_title}}</h2></div><div class="grid gap-4 columns-1 sm:columns-2 md:columns-3 lg:columns-4 gap-4 space-y-4">{{gallery_items}}</div></div></section>'],
            'section:gallery-item-v10' => ['title' => 'Gallery Item 10', 'template' => '<div class="group relative overflow-hidden rounded-xl {{class}}" {{style_attr}} {{attr}}><img src="{{image}}" alt="{{title}}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"><div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><h3 class="text-white font-bold text-lg">{{title}}</h3></div></div>'],
            'ui:breadcrumb-v1' => ['title' => 'Breadcrumb Variant 1', 'template' => '<nav class="flex text-sm text-muted-foreground {{class}}" aria-label="Breadcrumb" {{style_attr}} {{attr}}><ol class="inline-flex items-center space-x-1 md:space-x-3">{{breadcrumb_items}}</ol></nav>'],
            'ui:breadcrumb-item-v1' => ['title' => 'Breadcrumb Item 1', 'template' => '<li class="inline-flex items-center"><a href="{{link}}" class="inline-flex items-center hover:text-foreground transition-colors">{{label}}</a><span class="mx-2">&gt;</span></li>'],
            'ui:breadcrumb-v2' => ['title' => 'Breadcrumb Variant 2', 'template' => '<nav class="flex text-sm text-muted-foreground {{class}}" aria-label="Breadcrumb" {{style_attr}} {{attr}}><ol class="inline-flex items-center space-x-1 md:space-x-3">{{breadcrumb_items}}</ol></nav>'],
            'ui:breadcrumb-item-v2' => ['title' => 'Breadcrumb Item 2', 'template' => '<li class="inline-flex items-center"><a href="{{link}}" class="inline-flex items-center hover:text-foreground transition-colors">{{label}}</a><span class="mx-2">/</span></li>'],
            'ui:breadcrumb-v3' => ['title' => 'Breadcrumb Variant 3', 'template' => '<nav class="flex text-sm text-muted-foreground {{class}}" aria-label="Breadcrumb" {{style_attr}} {{attr}}><ol class="inline-flex items-center space-x-1 md:space-x-3">{{breadcrumb_items}}</ol></nav>'],
            'ui:breadcrumb-item-v3' => ['title' => 'Breadcrumb Item 3', 'template' => '<li class="inline-flex items-center"><a href="{{link}}" class="inline-flex items-center hover:text-foreground transition-colors">{{label}}</a><span class="mx-2">&gt;</span></li>'],
            'ui:breadcrumb-v4' => ['title' => 'Breadcrumb Variant 4', 'template' => '<nav class="flex text-sm text-muted-foreground {{class}}" aria-label="Breadcrumb" {{style_attr}} {{attr}}><ol class="inline-flex items-center space-x-1 md:space-x-3">{{breadcrumb_items}}</ol></nav>'],
            'ui:breadcrumb-item-v4' => ['title' => 'Breadcrumb Item 4', 'template' => '<li class="inline-flex items-center"><a href="{{link}}" class="inline-flex items-center hover:text-foreground transition-colors">{{label}}</a><span class="mx-2">/</span></li>'],
            'ui:breadcrumb-v5' => ['title' => 'Breadcrumb Variant 5', 'template' => '<nav class="flex text-sm text-muted-foreground {{class}}" aria-label="Breadcrumb" {{style_attr}} {{attr}}><ol class="inline-flex items-center space-x-1 md:space-x-3">{{breadcrumb_items}}</ol></nav>'],
            'ui:breadcrumb-item-v5' => ['title' => 'Breadcrumb Item 5', 'template' => '<li class="inline-flex items-center"><a href="{{link}}" class="inline-flex items-center hover:text-foreground transition-colors">{{label}}</a><span class="mx-2">&gt;</span></li>'],
            'ui:breadcrumb-v6' => ['title' => 'Breadcrumb Variant 6', 'template' => '<nav class="flex text-sm text-muted-foreground {{class}}" aria-label="Breadcrumb" {{style_attr}} {{attr}}><ol class="inline-flex items-center space-x-1 md:space-x-3">{{breadcrumb_items}}</ol></nav>'],
            'ui:breadcrumb-item-v6' => ['title' => 'Breadcrumb Item 6', 'template' => '<li class="inline-flex items-center"><a href="{{link}}" class="inline-flex items-center hover:text-foreground transition-colors">{{label}}</a><span class="mx-2">/</span></li>'],
            'ui:breadcrumb-v7' => ['title' => 'Breadcrumb Variant 7', 'template' => '<nav class="flex text-sm text-muted-foreground {{class}}" aria-label="Breadcrumb" {{style_attr}} {{attr}}><ol class="inline-flex items-center space-x-1 md:space-x-3">{{breadcrumb_items}}</ol></nav>'],
            'ui:breadcrumb-item-v7' => ['title' => 'Breadcrumb Item 7', 'template' => '<li class="inline-flex items-center"><a href="{{link}}" class="inline-flex items-center hover:text-foreground transition-colors">{{label}}</a><span class="mx-2">&gt;</span></li>'],
            'ui:breadcrumb-v8' => ['title' => 'Breadcrumb Variant 8', 'template' => '<nav class="flex text-sm text-muted-foreground {{class}}" aria-label="Breadcrumb" {{style_attr}} {{attr}}><ol class="inline-flex items-center space-x-1 md:space-x-3">{{breadcrumb_items}}</ol></nav>'],
            'ui:breadcrumb-item-v8' => ['title' => 'Breadcrumb Item 8', 'template' => '<li class="inline-flex items-center"><a href="{{link}}" class="inline-flex items-center hover:text-foreground transition-colors">{{label}}</a><span class="mx-2">/</span></li>'],
            'ui:breadcrumb-v9' => ['title' => 'Breadcrumb Variant 9', 'template' => '<nav class="flex text-sm text-muted-foreground {{class}}" aria-label="Breadcrumb" {{style_attr}} {{attr}}><ol class="inline-flex items-center space-x-1 md:space-x-3">{{breadcrumb_items}}</ol></nav>'],
            'ui:breadcrumb-item-v9' => ['title' => 'Breadcrumb Item 9', 'template' => '<li class="inline-flex items-center"><a href="{{link}}" class="inline-flex items-center hover:text-foreground transition-colors">{{label}}</a><span class="mx-2">&gt;</span></li>'],
            'ui:breadcrumb-v10' => ['title' => 'Breadcrumb Variant 10', 'template' => '<nav class="flex text-sm text-muted-foreground {{class}}" aria-label="Breadcrumb" {{style_attr}} {{attr}}><ol class="inline-flex items-center space-x-1 md:space-x-3">{{breadcrumb_items}}</ol></nav>'],
            'ui:breadcrumb-item-v10' => ['title' => 'Breadcrumb Item 10', 'template' => '<li class="inline-flex items-center"><a href="{{link}}" class="inline-flex items-center hover:text-foreground transition-colors">{{label}}</a><span class="mx-2">/</span></li>'],
            'ui:pagination-v1' => ['title' => 'Pagination Variant 1', 'template' => '<nav class="flex justify-center mt-8 {{class}}" aria-label="Page navigation" {{style_attr}} {{attr}}><ul class="inline-flex items-center -space-x-px ">{{pagination_items}}</ul></nav>'],
            'ui:pagination-item-v1' => ['title' => 'Pagination Item 1', 'template' => '<li><a href="{{link}}" class="px-4 py-2 leading-tight text-muted-foreground bg-background border border-border hover:bg-muted hover:text-foreground transition-colors {{class}}" {{style_attr}} {{attr}}>{{label}}</a></li>'],
            'ui:pagination-v2' => ['title' => 'Pagination Variant 2', 'template' => '<nav class="flex justify-center mt-8 {{class}}" aria-label="Page navigation" {{style_attr}} {{attr}}><ul class="inline-flex items-center -space-x-px ">{{pagination_items}}</ul></nav>'],
            'ui:pagination-item-v2' => ['title' => 'Pagination Item 2', 'template' => '<li><a href="{{link}}" class="px-4 py-2 leading-tight text-muted-foreground bg-background border border-border hover:bg-muted hover:text-foreground transition-colors {{class}}" {{style_attr}} {{attr}}>{{label}}</a></li>'],
            'ui:pagination-v3' => ['title' => 'Pagination Variant 3', 'template' => '<nav class="flex justify-center mt-8 {{class}}" aria-label="Page navigation" {{style_attr}} {{attr}}><ul class="inline-flex items-center -space-x-px ">{{pagination_items}}</ul></nav>'],
            'ui:pagination-item-v3' => ['title' => 'Pagination Item 3', 'template' => '<li><a href="{{link}}" class="px-4 py-2 leading-tight text-muted-foreground bg-background border border-border hover:bg-muted hover:text-foreground transition-colors {{class}}" {{style_attr}} {{attr}}>{{label}}</a></li>'],
            'ui:pagination-v4' => ['title' => 'Pagination Variant 4', 'template' => '<nav class="flex justify-center mt-8 {{class}}" aria-label="Page navigation" {{style_attr}} {{attr}}><ul class="inline-flex items-center -space-x-px ">{{pagination_items}}</ul></nav>'],
            'ui:pagination-item-v4' => ['title' => 'Pagination Item 4', 'template' => '<li><a href="{{link}}" class="px-4 py-2 leading-tight text-muted-foreground bg-background border border-border hover:bg-muted hover:text-foreground transition-colors {{class}}" {{style_attr}} {{attr}}>{{label}}</a></li>'],
            'ui:pagination-v5' => ['title' => 'Pagination Variant 5', 'template' => '<nav class="flex justify-center mt-8 {{class}}" aria-label="Page navigation" {{style_attr}} {{attr}}><ul class="inline-flex items-center -space-x-px ">{{pagination_items}}</ul></nav>'],
            'ui:pagination-item-v5' => ['title' => 'Pagination Item 5', 'template' => '<li><a href="{{link}}" class="px-4 py-2 leading-tight text-muted-foreground bg-background border border-border hover:bg-muted hover:text-foreground transition-colors {{class}}" {{style_attr}} {{attr}}>{{label}}</a></li>'],
            'ui:pagination-v6' => ['title' => 'Pagination Variant 6', 'template' => '<nav class="flex justify-center mt-8 {{class}}" aria-label="Page navigation" {{style_attr}} {{attr}}><ul class="inline-flex items-center -space-x-px border rounded-lg shadow-sm">{{pagination_items}}</ul></nav>'],
            'ui:pagination-item-v6' => ['title' => 'Pagination Item 6', 'template' => '<li><a href="{{link}}" class="px-4 py-2 leading-tight text-muted-foreground bg-background border border-border hover:bg-muted hover:text-foreground transition-colors {{class}}" {{style_attr}} {{attr}}>{{label}}</a></li>'],
            'ui:pagination-v7' => ['title' => 'Pagination Variant 7', 'template' => '<nav class="flex justify-center mt-8 {{class}}" aria-label="Page navigation" {{style_attr}} {{attr}}><ul class="inline-flex items-center -space-x-px border rounded-lg shadow-sm">{{pagination_items}}</ul></nav>'],
            'ui:pagination-item-v7' => ['title' => 'Pagination Item 7', 'template' => '<li><a href="{{link}}" class="px-4 py-2 leading-tight text-muted-foreground bg-background border border-border hover:bg-muted hover:text-foreground transition-colors {{class}}" {{style_attr}} {{attr}}>{{label}}</a></li>'],
            'ui:pagination-v8' => ['title' => 'Pagination Variant 8', 'template' => '<nav class="flex justify-center mt-8 {{class}}" aria-label="Page navigation" {{style_attr}} {{attr}}><ul class="inline-flex items-center -space-x-px border rounded-lg shadow-sm">{{pagination_items}}</ul></nav>'],
            'ui:pagination-item-v8' => ['title' => 'Pagination Item 8', 'template' => '<li><a href="{{link}}" class="px-4 py-2 leading-tight text-muted-foreground bg-background border border-border hover:bg-muted hover:text-foreground transition-colors {{class}}" {{style_attr}} {{attr}}>{{label}}</a></li>'],
            'ui:pagination-v9' => ['title' => 'Pagination Variant 9', 'template' => '<nav class="flex justify-center mt-8 {{class}}" aria-label="Page navigation" {{style_attr}} {{attr}}><ul class="inline-flex items-center -space-x-px border rounded-lg shadow-sm">{{pagination_items}}</ul></nav>'],
            'ui:pagination-item-v9' => ['title' => 'Pagination Item 9', 'template' => '<li><a href="{{link}}" class="px-4 py-2 leading-tight text-muted-foreground bg-background border border-border hover:bg-muted hover:text-foreground transition-colors {{class}}" {{style_attr}} {{attr}}>{{label}}</a></li>'],
            'ui:pagination-v10' => ['title' => 'Pagination Variant 10', 'template' => '<nav class="flex justify-center mt-8 {{class}}" aria-label="Page navigation" {{style_attr}} {{attr}}><ul class="inline-flex items-center -space-x-px border rounded-lg shadow-sm">{{pagination_items}}</ul></nav>'],
            'ui:pagination-item-v10' => ['title' => 'Pagination Item 10', 'template' => '<li><a href="{{link}}" class="px-4 py-2 leading-tight text-muted-foreground bg-background border border-border hover:bg-muted hover:text-foreground transition-colors {{class}}" {{style_attr}} {{attr}}>{{label}}</a></li>'],
            'ui:stepper-v1' => ['title' => 'Stepper Variant 1', 'template' => '<div class="flex flex-row items-center w-full {{class}}" {{style_attr}} {{attr}}>{{stepper_items}}</div>'],
            'ui:stepper-item-v1' => ['title' => 'Stepper Item 1', 'template' => '<div class="flex items-center {{class}}" {{style_attr}} {{attr}}><div class="flex items-center justify-center w-8 h-8 rounded-full border-2 border-primary bg-background text-primary font-bold z-10">{{step_num}}</div><div class="ml-4"><h4 class="font-semibold text-foreground">{{title}}</h4><p class="text-sm text-muted-foreground">{{desc}}</p></div></div>'],
            'ui:stepper-v2' => ['title' => 'Stepper Variant 2', 'template' => '<div class="flex flex-col space-y-4 {{class}}" {{style_attr}} {{attr}}>{{stepper_items}}</div>'],
            'ui:stepper-item-v2' => ['title' => 'Stepper Item 2', 'template' => '<div class="flex items-center {{class}}" {{style_attr}} {{attr}}><div class="flex items-center justify-center w-8 h-8 rounded-full border-2 border-primary bg-background text-primary font-bold z-10">{{step_num}}</div><div class="ml-4"><h4 class="font-semibold text-foreground">{{title}}</h4><p class="text-sm text-muted-foreground">{{desc}}</p></div></div>'],
            'ui:stepper-v3' => ['title' => 'Stepper Variant 3', 'template' => '<div class="flex flex-row items-center w-full {{class}}" {{style_attr}} {{attr}}>{{stepper_items}}</div>'],
            'ui:stepper-item-v3' => ['title' => 'Stepper Item 3', 'template' => '<div class="flex items-center {{class}}" {{style_attr}} {{attr}}><div class="flex items-center justify-center w-8 h-8 rounded-full border-2 border-primary bg-background text-primary font-bold z-10">{{step_num}}</div><div class="ml-4"><h4 class="font-semibold text-foreground">{{title}}</h4><p class="text-sm text-muted-foreground">{{desc}}</p></div></div>'],
            'ui:stepper-v4' => ['title' => 'Stepper Variant 4', 'template' => '<div class="flex flex-col space-y-4 {{class}}" {{style_attr}} {{attr}}>{{stepper_items}}</div>'],
            'ui:stepper-item-v4' => ['title' => 'Stepper Item 4', 'template' => '<div class="flex items-center {{class}}" {{style_attr}} {{attr}}><div class="flex items-center justify-center w-8 h-8 rounded-full border-2 border-primary bg-background text-primary font-bold z-10">{{step_num}}</div><div class="ml-4"><h4 class="font-semibold text-foreground">{{title}}</h4><p class="text-sm text-muted-foreground">{{desc}}</p></div></div>'],
            'ui:stepper-v5' => ['title' => 'Stepper Variant 5', 'template' => '<div class="flex flex-row items-center w-full {{class}}" {{style_attr}} {{attr}}>{{stepper_items}}</div>'],
            'ui:stepper-item-v5' => ['title' => 'Stepper Item 5', 'template' => '<div class="flex items-center {{class}}" {{style_attr}} {{attr}}><div class="flex items-center justify-center w-8 h-8 rounded-full border-2 border-primary bg-background text-primary font-bold z-10">{{step_num}}</div><div class="ml-4"><h4 class="font-semibold text-foreground">{{title}}</h4><p class="text-sm text-muted-foreground">{{desc}}</p></div></div>'],
            'ui:stepper-v6' => ['title' => 'Stepper Variant 6', 'template' => '<div class="flex flex-col space-y-4 {{class}}" {{style_attr}} {{attr}}>{{stepper_items}}</div>'],
            'ui:stepper-item-v6' => ['title' => 'Stepper Item 6', 'template' => '<div class="flex items-center {{class}}" {{style_attr}} {{attr}}><div class="flex items-center justify-center w-8 h-8 rounded-full border-2 border-primary bg-background text-primary font-bold z-10">{{step_num}}</div><div class="ml-4"><h4 class="font-semibold text-foreground">{{title}}</h4><p class="text-sm text-muted-foreground">{{desc}}</p></div></div>'],
            'ui:stepper-v7' => ['title' => 'Stepper Variant 7', 'template' => '<div class="flex flex-row items-center w-full {{class}}" {{style_attr}} {{attr}}>{{stepper_items}}</div>'],
            'ui:stepper-item-v7' => ['title' => 'Stepper Item 7', 'template' => '<div class="flex items-center {{class}}" {{style_attr}} {{attr}}><div class="flex items-center justify-center w-8 h-8 rounded-full border-2 border-primary bg-background text-primary font-bold z-10">{{step_num}}</div><div class="ml-4"><h4 class="font-semibold text-foreground">{{title}}</h4><p class="text-sm text-muted-foreground">{{desc}}</p></div></div>'],
            'ui:stepper-v8' => ['title' => 'Stepper Variant 8', 'template' => '<div class="flex flex-col space-y-4 {{class}}" {{style_attr}} {{attr}}>{{stepper_items}}</div>'],
            'ui:stepper-item-v8' => ['title' => 'Stepper Item 8', 'template' => '<div class="flex items-center {{class}}" {{style_attr}} {{attr}}><div class="flex items-center justify-center w-8 h-8 rounded-full border-2 border-primary bg-background text-primary font-bold z-10">{{step_num}}</div><div class="ml-4"><h4 class="font-semibold text-foreground">{{title}}</h4><p class="text-sm text-muted-foreground">{{desc}}</p></div></div>'],
            'ui:stepper-v9' => ['title' => 'Stepper Variant 9', 'template' => '<div class="flex flex-row items-center w-full {{class}}" {{style_attr}} {{attr}}>{{stepper_items}}</div>'],
            'ui:stepper-item-v9' => ['title' => 'Stepper Item 9', 'template' => '<div class="flex items-center {{class}}" {{style_attr}} {{attr}}><div class="flex items-center justify-center w-8 h-8 rounded-full border-2 border-primary bg-background text-primary font-bold z-10">{{step_num}}</div><div class="ml-4"><h4 class="font-semibold text-foreground">{{title}}</h4><p class="text-sm text-muted-foreground">{{desc}}</p></div></div>'],
            'ui:stepper-v10' => ['title' => 'Stepper Variant 10', 'template' => '<div class="flex flex-col space-y-4 {{class}}" {{style_attr}} {{attr}}>{{stepper_items}}</div>'],
            'ui:stepper-item-v10' => ['title' => 'Stepper Item 10', 'template' => '<div class="flex items-center {{class}}" {{style_attr}} {{attr}}><div class="flex items-center justify-center w-8 h-8 rounded-full border-2 border-primary bg-background text-primary font-bold z-10">{{step_num}}</div><div class="ml-4"><h4 class="font-semibold text-foreground">{{title}}</h4><p class="text-sm text-muted-foreground">{{desc}}</p></div></div>'],
            'ecommerce:product-view-v1' => ['title' => 'Product View Variant 1', 'template' => '<div class="container mx-auto px-4 py-16 flex flex-col lg:flex-row gap-12 {{class}}" {{style_attr}} {{attr}}><div class="lg:w-1/2"><img src="{{product_image}}" alt="{{product_title}}" class="w-full rounded-3xl shadow-lg"></div><div class="lg:w-1/2 space-y-6"><h1 class="text-3xl md:text-5xl font-bold text-foreground">{{product_title}}</h1><p class="text-3xl font-extrabold text-primary">{{product_price}}</p><div class="prose dark:prose-invert text-muted-foreground">{{product_desc}}</div><div class="pt-6 border-t">{{product_actions}}</div></div></div>'],
            'ecommerce:product-view-v2' => ['title' => 'Product View Variant 2', 'template' => '<div class="container mx-auto px-4 py-16 flex flex-col lg:flex-row-reverse gap-12 {{class}}" {{style_attr}} {{attr}}><div class="lg:w-1/2"><img src="{{product_image}}" alt="{{product_title}}" class="w-full rounded-3xl shadow-lg"></div><div class="lg:w-1/2 space-y-6"><h1 class="text-3xl md:text-5xl font-bold text-foreground">{{product_title}}</h1><p class="text-3xl font-extrabold text-primary">{{product_price}}</p><div class="prose dark:prose-invert text-muted-foreground">{{product_desc}}</div><div class="pt-6 border-t">{{product_actions}}</div></div></div>'],
            'ecommerce:product-view-v3' => ['title' => 'Product View Variant 3', 'template' => '<div class="container mx-auto px-4 py-16 flex flex-col lg:flex-row gap-12 {{class}}" {{style_attr}} {{attr}}><div class="lg:w-1/2"><img src="{{product_image}}" alt="{{product_title}}" class="w-full rounded-3xl shadow-lg"></div><div class="lg:w-1/2 space-y-6"><h1 class="text-3xl md:text-5xl font-bold text-foreground">{{product_title}}</h1><p class="text-3xl font-extrabold text-primary">{{product_price}}</p><div class="prose dark:prose-invert text-muted-foreground">{{product_desc}}</div><div class="pt-6 border-t">{{product_actions}}</div></div></div>'],
            'ecommerce:product-view-v4' => ['title' => 'Product View Variant 4', 'template' => '<div class="container mx-auto px-4 py-16 flex flex-col lg:flex-row-reverse gap-12 {{class}}" {{style_attr}} {{attr}}><div class="lg:w-1/2"><img src="{{product_image}}" alt="{{product_title}}" class="w-full rounded-3xl shadow-lg"></div><div class="lg:w-1/2 space-y-6"><h1 class="text-3xl md:text-5xl font-bold text-foreground">{{product_title}}</h1><p class="text-3xl font-extrabold text-primary">{{product_price}}</p><div class="prose dark:prose-invert text-muted-foreground">{{product_desc}}</div><div class="pt-6 border-t">{{product_actions}}</div></div></div>'],
            'ecommerce:product-view-v5' => ['title' => 'Product View Variant 5', 'template' => '<div class="container mx-auto px-4 py-16 flex flex-col lg:flex-row gap-12 {{class}}" {{style_attr}} {{attr}}><div class="lg:w-1/2"><img src="{{product_image}}" alt="{{product_title}}" class="w-full rounded-3xl shadow-lg"></div><div class="lg:w-1/2 space-y-6"><h1 class="text-3xl md:text-5xl font-bold text-foreground">{{product_title}}</h1><p class="text-3xl font-extrabold text-primary">{{product_price}}</p><div class="prose dark:prose-invert text-muted-foreground">{{product_desc}}</div><div class="pt-6 border-t">{{product_actions}}</div></div></div>'],
            'ecommerce:product-view-v6' => ['title' => 'Product View Variant 6', 'template' => '<div class="container mx-auto px-4 py-16 flex flex-col lg:flex-row-reverse gap-12 {{class}}" {{style_attr}} {{attr}}><div class="lg:w-1/2"><img src="{{product_image}}" alt="{{product_title}}" class="w-full rounded-3xl shadow-lg"></div><div class="lg:w-1/2 space-y-6"><h1 class="text-3xl md:text-5xl font-bold text-foreground">{{product_title}}</h1><p class="text-3xl font-extrabold text-primary">{{product_price}}</p><div class="prose dark:prose-invert text-muted-foreground">{{product_desc}}</div><div class="pt-6 border-t">{{product_actions}}</div></div></div>'],
            'ecommerce:product-view-v7' => ['title' => 'Product View Variant 7', 'template' => '<div class="container mx-auto px-4 py-16 flex flex-col lg:flex-row gap-12 {{class}}" {{style_attr}} {{attr}}><div class="lg:w-1/2"><img src="{{product_image}}" alt="{{product_title}}" class="w-full rounded-3xl shadow-lg"></div><div class="lg:w-1/2 space-y-6"><h1 class="text-3xl md:text-5xl font-bold text-foreground">{{product_title}}</h1><p class="text-3xl font-extrabold text-primary">{{product_price}}</p><div class="prose dark:prose-invert text-muted-foreground">{{product_desc}}</div><div class="pt-6 border-t">{{product_actions}}</div></div></div>'],
            'ecommerce:product-view-v8' => ['title' => 'Product View Variant 8', 'template' => '<div class="container mx-auto px-4 py-16 flex flex-col lg:flex-row-reverse gap-12 {{class}}" {{style_attr}} {{attr}}><div class="lg:w-1/2"><img src="{{product_image}}" alt="{{product_title}}" class="w-full rounded-3xl shadow-lg"></div><div class="lg:w-1/2 space-y-6"><h1 class="text-3xl md:text-5xl font-bold text-foreground">{{product_title}}</h1><p class="text-3xl font-extrabold text-primary">{{product_price}}</p><div class="prose dark:prose-invert text-muted-foreground">{{product_desc}}</div><div class="pt-6 border-t">{{product_actions}}</div></div></div>'],
            'ecommerce:product-view-v9' => ['title' => 'Product View Variant 9', 'template' => '<div class="container mx-auto px-4 py-16 flex flex-col lg:flex-row gap-12 {{class}}" {{style_attr}} {{attr}}><div class="lg:w-1/2"><img src="{{product_image}}" alt="{{product_title}}" class="w-full rounded-3xl shadow-lg"></div><div class="lg:w-1/2 space-y-6"><h1 class="text-3xl md:text-5xl font-bold text-foreground">{{product_title}}</h1><p class="text-3xl font-extrabold text-primary">{{product_price}}</p><div class="prose dark:prose-invert text-muted-foreground">{{product_desc}}</div><div class="pt-6 border-t">{{product_actions}}</div></div></div>'],
            'ecommerce:product-view-v10' => ['title' => 'Product View Variant 10', 'template' => '<div class="container mx-auto px-4 py-16 flex flex-col lg:flex-row-reverse gap-12 {{class}}" {{style_attr}} {{attr}}><div class="lg:w-1/2"><img src="{{product_image}}" alt="{{product_title}}" class="w-full rounded-3xl shadow-lg"></div><div class="lg:w-1/2 space-y-6"><h1 class="text-3xl md:text-5xl font-bold text-foreground">{{product_title}}</h1><p class="text-3xl font-extrabold text-primary">{{product_price}}</p><div class="prose dark:prose-invert text-muted-foreground">{{product_desc}}</div><div class="pt-6 border-t">{{product_actions}}</div></div></div>'],
            'ecommerce:filter-v1' => ['title' => 'Product Filter Variant 1', 'template' => '<div class="bg-card border rounded-2xl p-6 shadow-sm mb-8 grid grid-cols-1 md:grid-cols-4 gap-4 {{class}}" {{style_attr}} {{attr}}>{{filter_items}}</div>'],
            'ecommerce:filter-v2' => ['title' => 'Product Filter Variant 2', 'template' => '<div class="bg-card border rounded-2xl p-6 shadow-sm mb-8 grid grid-cols-1 md:grid-cols-4 gap-4 {{class}}" {{style_attr}} {{attr}}>{{filter_items}}</div>'],
            'ecommerce:filter-v3' => ['title' => 'Product Filter Variant 3', 'template' => '<div class="bg-card border rounded-2xl p-6 shadow-sm mb-8 grid grid-cols-1 md:grid-cols-4 gap-4 {{class}}" {{style_attr}} {{attr}}>{{filter_items}}</div>'],
            'ecommerce:filter-v4' => ['title' => 'Product Filter Variant 4', 'template' => '<div class="bg-card border rounded-2xl p-6 shadow-sm mb-8 grid grid-cols-1 md:grid-cols-4 gap-4 {{class}}" {{style_attr}} {{attr}}>{{filter_items}}</div>'],
            'ecommerce:filter-v5' => ['title' => 'Product Filter Variant 5', 'template' => '<div class="bg-card border rounded-2xl p-6 shadow-sm mb-8 grid grid-cols-1 md:grid-cols-4 gap-4 {{class}}" {{style_attr}} {{attr}}>{{filter_items}}</div>'],
            'ecommerce:filter-v6' => ['title' => 'Product Filter Variant 6', 'template' => '<div class="bg-card border rounded-2xl p-6 shadow-sm mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}>{{filter_items}}</div>'],
            'ecommerce:filter-v7' => ['title' => 'Product Filter Variant 7', 'template' => '<div class="bg-card border rounded-2xl p-6 shadow-sm mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}>{{filter_items}}</div>'],
            'ecommerce:filter-v8' => ['title' => 'Product Filter Variant 8', 'template' => '<div class="bg-card border rounded-2xl p-6 shadow-sm mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}>{{filter_items}}</div>'],
            'ecommerce:filter-v9' => ['title' => 'Product Filter Variant 9', 'template' => '<div class="bg-card border rounded-2xl p-6 shadow-sm mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}>{{filter_items}}</div>'],
            'ecommerce:filter-v10' => ['title' => 'Product Filter Variant 10', 'template' => '<div class="bg-card border rounded-2xl p-6 shadow-sm mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}>{{filter_items}}</div>'],
            'ecommerce:review-list-v1' => ['title' => 'Review List Variant 1', 'template' => '<div class="space-y-8 {{class}}" {{style_attr}} {{attr}}><h3 class="text-2xl font-bold border-b pb-4">{{section_title}}</h3><div class="grid gap-8 grid-cols-1">{{review_items}}</div></div>'],
            'ecommerce:review-item-v1' => ['title' => 'Review Item Variant 1', 'template' => '<div class="p-6 bg-muted/20 rounded-xl {{class}}" {{style_attr}} {{attr}}><div class="flex items-center gap-4 mb-4"><img src="{{avatar}}" alt="{{name}}" class="w-12 h-12 rounded-full"><div class="flex-1"></div><h4 class="font-bold">{{name}}</h4><p class="text-sm text-muted-foreground">{{date}}</p></div><div class="text-yellow-500 mb-2">{{rating_stars}}</div><p class="text-foreground">{{review_text}}</p></div>'],
            'ecommerce:review-list-v2' => ['title' => 'Review List Variant 2', 'template' => '<div class="space-y-8 {{class}}" {{style_attr}} {{attr}}><h3 class="text-2xl font-bold border-b pb-4">{{section_title}}</h3><div class="grid gap-8 grid-cols-1 md:grid-cols-2">{{review_items}}</div></div>'],
            'ecommerce:review-item-v2' => ['title' => 'Review Item Variant 2', 'template' => '<div class="p-6 bg-muted/20 rounded-xl {{class}}" {{style_attr}} {{attr}}><div class="flex items-center gap-4 mb-4"><img src="{{avatar}}" alt="{{name}}" class="w-12 h-12 rounded-full"><div class="flex-1"></div><h4 class="font-bold">{{name}}</h4><p class="text-sm text-muted-foreground">{{date}}</p></div><div class="text-yellow-500 mb-2">{{rating_stars}}</div><p class="text-foreground">{{review_text}}</p></div>'],
            'ecommerce:review-list-v3' => ['title' => 'Review List Variant 3', 'template' => '<div class="space-y-8 {{class}}" {{style_attr}} {{attr}}><h3 class="text-2xl font-bold border-b pb-4">{{section_title}}</h3><div class="grid gap-8 grid-cols-1">{{review_items}}</div></div>'],
            'ecommerce:review-item-v3' => ['title' => 'Review Item Variant 3', 'template' => '<div class="p-6 bg-muted/20 rounded-xl {{class}}" {{style_attr}} {{attr}}><div class="flex items-center gap-4 mb-4"><img src="{{avatar}}" alt="{{name}}" class="w-12 h-12 rounded-full"><div class="flex-1"></div><h4 class="font-bold">{{name}}</h4><p class="text-sm text-muted-foreground">{{date}}</p></div><div class="text-yellow-500 mb-2">{{rating_stars}}</div><p class="text-foreground">{{review_text}}</p></div>'],
            'ecommerce:review-list-v4' => ['title' => 'Review List Variant 4', 'template' => '<div class="space-y-8 {{class}}" {{style_attr}} {{attr}}><h3 class="text-2xl font-bold border-b pb-4">{{section_title}}</h3><div class="grid gap-8 grid-cols-1 md:grid-cols-2">{{review_items}}</div></div>'],
            'ecommerce:review-item-v4' => ['title' => 'Review Item Variant 4', 'template' => '<div class="p-6 bg-muted/20 rounded-xl {{class}}" {{style_attr}} {{attr}}><div class="flex items-center gap-4 mb-4"><img src="{{avatar}}" alt="{{name}}" class="w-12 h-12 rounded-full"><div class="flex-1"></div><h4 class="font-bold">{{name}}</h4><p class="text-sm text-muted-foreground">{{date}}</p></div><div class="text-yellow-500 mb-2">{{rating_stars}}</div><p class="text-foreground">{{review_text}}</p></div>'],
            'ecommerce:review-list-v5' => ['title' => 'Review List Variant 5', 'template' => '<div class="space-y-8 {{class}}" {{style_attr}} {{attr}}><h3 class="text-2xl font-bold border-b pb-4">{{section_title}}</h3><div class="grid gap-8 grid-cols-1">{{review_items}}</div></div>'],
            'ecommerce:review-item-v5' => ['title' => 'Review Item Variant 5', 'template' => '<div class="p-6 bg-muted/20 rounded-xl {{class}}" {{style_attr}} {{attr}}><div class="flex items-center gap-4 mb-4"><img src="{{avatar}}" alt="{{name}}" class="w-12 h-12 rounded-full"><div class="flex-1"></div><h4 class="font-bold">{{name}}</h4><p class="text-sm text-muted-foreground">{{date}}</p></div><div class="text-yellow-500 mb-2">{{rating_stars}}</div><p class="text-foreground">{{review_text}}</p></div>'],
            'ecommerce:review-list-v6' => ['title' => 'Review List Variant 6', 'template' => '<div class="space-y-8 {{class}}" {{style_attr}} {{attr}}><h3 class="text-2xl font-bold border-b pb-4">{{section_title}}</h3><div class="grid gap-8 grid-cols-1 md:grid-cols-2">{{review_items}}</div></div>'],
            'ecommerce:review-item-v6' => ['title' => 'Review Item Variant 6', 'template' => '<div class="p-6 bg-muted/20 rounded-xl {{class}}" {{style_attr}} {{attr}}><div class="flex items-center gap-4 mb-4"><img src="{{avatar}}" alt="{{name}}" class="w-12 h-12 rounded-full"><div class="flex-1"></div><h4 class="font-bold">{{name}}</h4><p class="text-sm text-muted-foreground">{{date}}</p></div><div class="text-yellow-500 mb-2">{{rating_stars}}</div><p class="text-foreground">{{review_text}}</p></div>'],
            'ecommerce:review-list-v7' => ['title' => 'Review List Variant 7', 'template' => '<div class="space-y-8 {{class}}" {{style_attr}} {{attr}}><h3 class="text-2xl font-bold border-b pb-4">{{section_title}}</h3><div class="grid gap-8 grid-cols-1">{{review_items}}</div></div>'],
            'ecommerce:review-item-v7' => ['title' => 'Review Item Variant 7', 'template' => '<div class="p-6 bg-muted/20 rounded-xl {{class}}" {{style_attr}} {{attr}}><div class="flex items-center gap-4 mb-4"><img src="{{avatar}}" alt="{{name}}" class="w-12 h-12 rounded-full"><div class="flex-1"></div><h4 class="font-bold">{{name}}</h4><p class="text-sm text-muted-foreground">{{date}}</p></div><div class="text-yellow-500 mb-2">{{rating_stars}}</div><p class="text-foreground">{{review_text}}</p></div>'],
            'ecommerce:review-list-v8' => ['title' => 'Review List Variant 8', 'template' => '<div class="space-y-8 {{class}}" {{style_attr}} {{attr}}><h3 class="text-2xl font-bold border-b pb-4">{{section_title}}</h3><div class="grid gap-8 grid-cols-1 md:grid-cols-2">{{review_items}}</div></div>'],
            'ecommerce:review-item-v8' => ['title' => 'Review Item Variant 8', 'template' => '<div class="p-6 bg-muted/20 rounded-xl {{class}}" {{style_attr}} {{attr}}><div class="flex items-center gap-4 mb-4"><img src="{{avatar}}" alt="{{name}}" class="w-12 h-12 rounded-full"><div class="flex-1"></div><h4 class="font-bold">{{name}}</h4><p class="text-sm text-muted-foreground">{{date}}</p></div><div class="text-yellow-500 mb-2">{{rating_stars}}</div><p class="text-foreground">{{review_text}}</p></div>'],
            'ecommerce:review-list-v9' => ['title' => 'Review List Variant 9', 'template' => '<div class="space-y-8 {{class}}" {{style_attr}} {{attr}}><h3 class="text-2xl font-bold border-b pb-4">{{section_title}}</h3><div class="grid gap-8 grid-cols-1">{{review_items}}</div></div>'],
            'ecommerce:review-item-v9' => ['title' => 'Review Item Variant 9', 'template' => '<div class="p-6 bg-muted/20 rounded-xl {{class}}" {{style_attr}} {{attr}}><div class="flex items-center gap-4 mb-4"><img src="{{avatar}}" alt="{{name}}" class="w-12 h-12 rounded-full"><div class="flex-1"></div><h4 class="font-bold">{{name}}</h4><p class="text-sm text-muted-foreground">{{date}}</p></div><div class="text-yellow-500 mb-2">{{rating_stars}}</div><p class="text-foreground">{{review_text}}</p></div>'],
            'ecommerce:review-list-v10' => ['title' => 'Review List Variant 10', 'template' => '<div class="space-y-8 {{class}}" {{style_attr}} {{attr}}><h3 class="text-2xl font-bold border-b pb-4">{{section_title}}</h3><div class="grid gap-8 grid-cols-1 md:grid-cols-2">{{review_items}}</div></div>'],
            'ecommerce:review-item-v10' => ['title' => 'Review Item Variant 10', 'template' => '<div class="p-6 bg-muted/20 rounded-xl {{class}}" {{style_attr}} {{attr}}><div class="flex items-center gap-4 mb-4"><img src="{{avatar}}" alt="{{name}}" class="w-12 h-12 rounded-full"><div class="flex-1"></div><h4 class="font-bold">{{name}}</h4><p class="text-sm text-muted-foreground">{{date}}</p></div><div class="text-yellow-500 mb-2">{{rating_stars}}</div><p class="text-foreground">{{review_text}}</p></div>'],
            'ecommerce:order-history-v1' => ['title' => 'Order History Variant 1', 'template' => '<div class="space-y-6 {{class}}" {{style_attr}} {{attr}}><h2 class="text-2xl font-bold">{{section_title}}</h2><div class="space-y-4">{{order_items}}</div></div>'],
            'ecommerce:order-item-v1' => ['title' => 'Order Item Variant 1', 'template' => '<div class="border rounded-2xl p-6 bg-card flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}><div><p class="text-sm text-muted-foreground">Order Number</p><p class="font-bold">{{order_number}}</p></div><div><p class="text-sm text-muted-foreground">Date</p><p class="font-medium">{{order_date}}</p></div><div><p class="text-sm text-muted-foreground">Total Amount</p><p class="font-bold text-primary">{{order_total}}</p></div><div><span class="px-3 py-1 rounded-full text-xs font-bold {{status_class}}">{{order_status}}</span></div><div>{{order_action}}</div></div>'],
            'ecommerce:order-history-v2' => ['title' => 'Order History Variant 2', 'template' => '<div class="space-y-6 {{class}}" {{style_attr}} {{attr}}><h2 class="text-2xl font-bold">{{section_title}}</h2><div class="space-y-4">{{order_items}}</div></div>'],
            'ecommerce:order-item-v2' => ['title' => 'Order Item Variant 2', 'template' => '<div class="border rounded-2xl p-6 bg-card flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}><div><p class="text-sm text-muted-foreground">Order Number</p><p class="font-bold">{{order_number}}</p></div><div><p class="text-sm text-muted-foreground">Date</p><p class="font-medium">{{order_date}}</p></div><div><p class="text-sm text-muted-foreground">Total Amount</p><p class="font-bold text-primary">{{order_total}}</p></div><div><span class="px-3 py-1 rounded-full text-xs font-bold {{status_class}}">{{order_status}}</span></div><div>{{order_action}}</div></div>'],
            'ecommerce:order-history-v3' => ['title' => 'Order History Variant 3', 'template' => '<div class="space-y-6 {{class}}" {{style_attr}} {{attr}}><h2 class="text-2xl font-bold">{{section_title}}</h2><div class="space-y-4">{{order_items}}</div></div>'],
            'ecommerce:order-item-v3' => ['title' => 'Order Item Variant 3', 'template' => '<div class="border rounded-2xl p-6 bg-card flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}><div><p class="text-sm text-muted-foreground">Order Number</p><p class="font-bold">{{order_number}}</p></div><div><p class="text-sm text-muted-foreground">Date</p><p class="font-medium">{{order_date}}</p></div><div><p class="text-sm text-muted-foreground">Total Amount</p><p class="font-bold text-primary">{{order_total}}</p></div><div><span class="px-3 py-1 rounded-full text-xs font-bold {{status_class}}">{{order_status}}</span></div><div>{{order_action}}</div></div>'],
            'ecommerce:order-history-v4' => ['title' => 'Order History Variant 4', 'template' => '<div class="space-y-6 {{class}}" {{style_attr}} {{attr}}><h2 class="text-2xl font-bold">{{section_title}}</h2><div class="space-y-4">{{order_items}}</div></div>'],
            'ecommerce:order-item-v4' => ['title' => 'Order Item Variant 4', 'template' => '<div class="border rounded-2xl p-6 bg-card flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}><div><p class="text-sm text-muted-foreground">Order Number</p><p class="font-bold">{{order_number}}</p></div><div><p class="text-sm text-muted-foreground">Date</p><p class="font-medium">{{order_date}}</p></div><div><p class="text-sm text-muted-foreground">Total Amount</p><p class="font-bold text-primary">{{order_total}}</p></div><div><span class="px-3 py-1 rounded-full text-xs font-bold {{status_class}}">{{order_status}}</span></div><div>{{order_action}}</div></div>'],
            'ecommerce:order-history-v5' => ['title' => 'Order History Variant 5', 'template' => '<div class="space-y-6 {{class}}" {{style_attr}} {{attr}}><h2 class="text-2xl font-bold">{{section_title}}</h2><div class="space-y-4">{{order_items}}</div></div>'],
            'ecommerce:order-item-v5' => ['title' => 'Order Item Variant 5', 'template' => '<div class="border rounded-2xl p-6 bg-card flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}><div><p class="text-sm text-muted-foreground">Order Number</p><p class="font-bold">{{order_number}}</p></div><div><p class="text-sm text-muted-foreground">Date</p><p class="font-medium">{{order_date}}</p></div><div><p class="text-sm text-muted-foreground">Total Amount</p><p class="font-bold text-primary">{{order_total}}</p></div><div><span class="px-3 py-1 rounded-full text-xs font-bold {{status_class}}">{{order_status}}</span></div><div>{{order_action}}</div></div>'],
            'ecommerce:order-history-v6' => ['title' => 'Order History Variant 6', 'template' => '<div class="space-y-6 {{class}}" {{style_attr}} {{attr}}><h2 class="text-2xl font-bold">{{section_title}}</h2><div class="space-y-4">{{order_items}}</div></div>'],
            'ecommerce:order-item-v6' => ['title' => 'Order Item Variant 6', 'template' => '<div class="border rounded-2xl p-6 bg-card flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}><div><p class="text-sm text-muted-foreground">Order Number</p><p class="font-bold">{{order_number}}</p></div><div><p class="text-sm text-muted-foreground">Date</p><p class="font-medium">{{order_date}}</p></div><div><p class="text-sm text-muted-foreground">Total Amount</p><p class="font-bold text-primary">{{order_total}}</p></div><div><span class="px-3 py-1 rounded-full text-xs font-bold {{status_class}}">{{order_status}}</span></div><div>{{order_action}}</div></div>'],
            'ecommerce:order-history-v7' => ['title' => 'Order History Variant 7', 'template' => '<div class="space-y-6 {{class}}" {{style_attr}} {{attr}}><h2 class="text-2xl font-bold">{{section_title}}</h2><div class="space-y-4">{{order_items}}</div></div>'],
            'ecommerce:order-item-v7' => ['title' => 'Order Item Variant 7', 'template' => '<div class="border rounded-2xl p-6 bg-card flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}><div><p class="text-sm text-muted-foreground">Order Number</p><p class="font-bold">{{order_number}}</p></div><div><p class="text-sm text-muted-foreground">Date</p><p class="font-medium">{{order_date}}</p></div><div><p class="text-sm text-muted-foreground">Total Amount</p><p class="font-bold text-primary">{{order_total}}</p></div><div><span class="px-3 py-1 rounded-full text-xs font-bold {{status_class}}">{{order_status}}</span></div><div>{{order_action}}</div></div>'],
            'ecommerce:order-history-v8' => ['title' => 'Order History Variant 8', 'template' => '<div class="space-y-6 {{class}}" {{style_attr}} {{attr}}><h2 class="text-2xl font-bold">{{section_title}}</h2><div class="space-y-4">{{order_items}}</div></div>'],
            'ecommerce:order-item-v8' => ['title' => 'Order Item Variant 8', 'template' => '<div class="border rounded-2xl p-6 bg-card flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}><div><p class="text-sm text-muted-foreground">Order Number</p><p class="font-bold">{{order_number}}</p></div><div><p class="text-sm text-muted-foreground">Date</p><p class="font-medium">{{order_date}}</p></div><div><p class="text-sm text-muted-foreground">Total Amount</p><p class="font-bold text-primary">{{order_total}}</p></div><div><span class="px-3 py-1 rounded-full text-xs font-bold {{status_class}}">{{order_status}}</span></div><div>{{order_action}}</div></div>'],
            'ecommerce:order-history-v9' => ['title' => 'Order History Variant 9', 'template' => '<div class="space-y-6 {{class}}" {{style_attr}} {{attr}}><h2 class="text-2xl font-bold">{{section_title}}</h2><div class="space-y-4">{{order_items}}</div></div>'],
            'ecommerce:order-item-v9' => ['title' => 'Order Item Variant 9', 'template' => '<div class="border rounded-2xl p-6 bg-card flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}><div><p class="text-sm text-muted-foreground">Order Number</p><p class="font-bold">{{order_number}}</p></div><div><p class="text-sm text-muted-foreground">Date</p><p class="font-medium">{{order_date}}</p></div><div><p class="text-sm text-muted-foreground">Total Amount</p><p class="font-bold text-primary">{{order_total}}</p></div><div><span class="px-3 py-1 rounded-full text-xs font-bold {{status_class}}">{{order_status}}</span></div><div>{{order_action}}</div></div>'],
            'ecommerce:order-history-v10' => ['title' => 'Order History Variant 10', 'template' => '<div class="space-y-6 {{class}}" {{style_attr}} {{attr}}><h2 class="text-2xl font-bold">{{section_title}}</h2><div class="space-y-4">{{order_items}}</div></div>'],
            'ecommerce:order-item-v10' => ['title' => 'Order Item Variant 10', 'template' => '<div class="border rounded-2xl p-6 bg-card flex flex-col md:flex-row md:items-center justify-between gap-4 {{class}}" {{style_attr}} {{attr}}><div><p class="text-sm text-muted-foreground">Order Number</p><p class="font-bold">{{order_number}}</p></div><div><p class="text-sm text-muted-foreground">Date</p><p class="font-medium">{{order_date}}</p></div><div><p class="text-sm text-muted-foreground">Total Amount</p><p class="font-bold text-primary">{{order_total}}</p></div><div><span class="px-3 py-1 rounded-full text-xs font-bold {{status_class}}">{{order_status}}</span></div><div>{{order_action}}</div></div>'],
            'h1' => ['title' => 'Heading 1', 'template' => '<h1 class="text-4xl font-black {{class}}" {{style_attr}} {{attr}}>@slot</h1>'],

            'h2' => ['title' => 'Heading 2', 'template' => '<h2 class="text-3xl font-bold {{class}}" {{style_attr}} {{attr}}>@slot</h2>'],

            'h3' => ['title' => 'Heading 3', 'template' => '<h3 class="text-2xl font-bold {{class}}" {{style_attr}} {{attr}}>@slot</h3>'],

            'h4' => ['title' => 'Heading 4', 'template' => '<h4 class="text-xl font-bold {{class}}" {{style_attr}} {{attr}}>@slot</h4>'],

            'h5' => ['title' => 'Heading 5', 'template' => '<h5 class="text-lg font-bold {{class}}" {{style_attr}} {{attr}}>@slot</h5>'],

            'h6' => ['title' => 'Heading 6', 'template' => '<h6 class="text-base font-bold {{class}}" {{style_attr}} {{attr}}>@slot</h6>'],

            'header' => ['title' => 'Base HTML Header', 'template' => '<header class="{{class}}" {{style_attr}} {{attr}}>@slot</header>'],

            'header:center' => ['title' => 'Header Center Navigation', 'template' => '<header class="sticky top-0 z-50 w-full border-b border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 {{class}}" {{style_attr}} {{attr}}><div class="container flex h-14 items-center justify-between"><div class="flex items-center gap-4"><a href="{{logo_link|#}}" class="flex items-center space-x-2"><img src="{{logo_src}}" class="h-6 w-auto" alt="Logo"><span class="font-bold sm:inline-block">{{brand}}</span></a></div><div class="hidden md:flex gap-6"><nav class="flex items-center space-x-6 text-sm font-medium">{{items}}</nav></div><div class="flex items-center gap-2">{{actions}}</div></div></header>'],

            'header:left' => ['title' => 'Header Left Navigation', 'template' => '<header class="sticky top-0 z-50 w-full border-b border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 {{class}}" {{style_attr}} {{attr}}><div class="container flex h-14 items-center"><div class="mr-4 hidden md:flex"><a href="{{logo_link|#}}" class="mr-6 flex items-center space-x-2"><img src="{{logo_src}}" class="h-6 w-auto"><span class="hidden font-bold sm:inline-block">{{brand}}</span></a><nav class="flex items-center space-x-6 text-sm font-medium">{{items}}</nav></div><div class="flex flex-1 items-center justify-between space-x-2 md:justify-end"><div class="w-full flex-1 md:w-auto md:flex-none">{{search}}</div><nav class="flex items-center gap-2">{{actions}}</nav></div></div></header>'],

            'header:navbar' => ['title' => 'Simple Navbar', 'template' => '<nav class="border-b border-border bg-background py-4 px-6 flex justify-between items-center {{class}}" {{attr}}><div class="font-bold text-xl">{{brand|Mystack}}</div><div class="flex gap-6">{{links}}</div><div class="flex gap-4">{{actions}}</div></nav>'],

            'hr' => ['title' => 'Horizontal Rule', 'template' => '<hr class="border-t border-border my-4 {{class}}" {{style_attr}} {{attr}}/>'],

            'i' => ['title' => 'Italic Text', 'template' => '<i class="{{class}}" {{style_attr}} {{attr}}>@slot</i>'],

            'iframe' => ['title' => 'Inline Frame', 'template' => '<iframe src="{{src}}" class="w-full h-64 border-0 {{class}}" {{style_attr}} {{attr}}></iframe>'],

            'img' => ['title' => 'Image Tag', 'template' => '<img src="{{src}}" alt="{{alt}}" class="max-w-full h-auto {{class}}" {{style_attr}} {{attr}}/>'],

            'input' => ['title' => 'Base HTML Input', 'template' => '<input type="{{type|text}}" name="{{name}}" value="{{value}}" placeholder="{{placeholder}}" class="border border-input rounded px-3 py-2 bg-background {{class}}" {{style_attr}} {{attr}}/>'],

            'ins' => ['title' => 'Inserted Text', 'template' => '<ins class="{{class}}" {{style_attr}} {{attr}}>@slot</ins>'],

            'kbd' => ['title' => 'Base HTML Keyboard Input', 'template' => '<kbd class="bg-muted border border-border px-1.5 py-0.5 rounded text-xs font-mono {{class}}" {{style_attr}} {{attr}}>@slot</kbd>'],

            'label' => ['title' => 'Form Label', 'template' => '<label class="text-sm font-medium leading-none {{class}}" {{style_attr}} {{attr}}>@slot</label>'],

            'legend' => ['title' => 'Fieldset Legend', 'template' => '<legend class="px-2 font-bold {{class}}" {{style_attr}} {{attr}}>@slot</legend>'],

            'li' => ['title' => 'List Item', 'template' => '<li class="{{class}}" {{style_attr}} {{attr}}>@slot</li>'],

            'main' => ['title' => 'Main Content Element', 'template' => '<main class="{{class}}" {{style_attr}} {{attr}}>@slot</main>'],

            'mark' => ['title' => 'Highlighted Text', 'template' => '<mark class="bg-yellow-200 text-black px-1 {{class}}" {{style_attr}} {{attr}}>@slot</mark>'],

            'nav' => ['title' => 'Base HTML Navigation Container', 'template' => '<nav class="{{class}}" {{style_attr}} {{attr}}>@slot</nav>'],

            'nav:breadcrumb' => ['title' => 'Breadcrumb Navigation', 'template' => '<nav class="flex items-center space-x-1 text-sm text-muted-foreground {{class}}" {{style_attr}} {{attr}}>{{items}}</nav>'],

            'nav:breadcrumb-item' => ['title' => 'Breadcrumb Navigation Item', 'template' => '<div class="flex items-center"><a href="{{link|#}}" class="hover:text-foreground transition-colors">{{label}}</a><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mx-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg></div>'],

            'nav:link' => ['title' => 'Navigation Link Element', 'template' => '<li><a href="{{link|#}}" class="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground {{class}}" {{style_attr}} {{attr}}>{{label}}</a></li>'],

            'nav:tabs' => ['title' => 'Interactive Tab Controller', 'template' => '<div x-data="{ tab: \'{{active|1}}\' }" class="w-full {{class}}" {{attr}}><div class="inline-flex h-10 items-center justify-center rounded-md bg-muted p-1 text-muted-foreground">{{headers}}</div><div class="mt-2 ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1">{{contents}}</div></div>'],

            'ol' => ['title' => 'Ordered List', 'template' => '<ol class="list-decimal pl-6 {{class}}" {{style_attr}} {{attr}}>@slot</ol>'],

            'overlay:drawer' => ['title' => 'Overlay Navigation Slide-Out Drawer', 'template' => '<div x-data="{ open: false }" @open-drawer.window="open = true" class="relative z-50 {{class}}" {{style_attr}} {{attr}}><div x-show="open" x-transition.opacity class="fixed inset-0 bg-background/80 backdrop-blur-sm"></div><div x-show="open" x-transition:enter="transform transition ease-in-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" class="fixed inset-y-0 right-0 z-50 h-full w-3/4 border-l border-border bg-background p-6 shadow-lg sm:max-w-sm"><div class="flex flex-col space-y-2 text-center sm:text-left"><h2 class="text-lg font-semibold text-foreground">{{title}}</h2><p class="text-sm text-muted-foreground">{{desc}}</p></div><div class="py-4">{{content}}</div><button @click="open = false" class="absolute right-4 top-4 rounded-sm opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg><span class="sr-only">Close</span></button></div></div>'],

            'overlay:modal' => ['title' => 'Overlay Modal Container', 'template' => '<div x-data="{ open: false }" @open-modal.window="open = true" class="relative z-50 {{class}}" {{style_attr}} {{attr}}><div x-show="open" x-transition.opacity class="fixed inset-0 bg-background/80 backdrop-blur-sm"></div><div x-show="open" class="fixed inset-0 z-50 flex items-center justify-center"><div @click.away="open = false" class="w-full max-w-lg border border-border bg-background p-6 shadow-lg rounded-lg sm:rounded-lg"><div class="flex flex-col space-y-1.5 text-center sm:text-left"><h2 class="text-lg font-semibold leading-none tracking-tight">{{title}}</h2><p class="text-sm text-muted-foreground">{{desc}}</p></div><div class="py-4">{{content}}</div><div class="flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2">{{actions}}</div></div></div></div>'],

            'p' => ['title' => 'Paragraph Text', 'template' => '<p class="leading-7 {{class}}" {{style_attr}} {{attr}}>@slot</p>'],

            'picture' => ['title' => 'Picture Container', 'template' => '<picture class="{{class}}" {{style_attr}} {{attr}}>@slot</picture>'],

            'pre' => ['title' => 'Preformatted Code Container', 'template' => '<pre class="bg-muted p-4 rounded-lg overflow-x-auto font-mono text-sm {{class}}" {{style_attr}} {{attr}}>@slot</pre>'],

            'progress' => ['title' => 'Base HTML Progress Indicator', 'template' => '<progress value="{{value}}" max="{{max|100}}" class="w-full h-2 rounded-full overflow-hidden bg-muted {{class}}" {{style_attr}} {{attr}}></progress>'],

            'q' => ['title' => 'Quotation Text', 'template' => '<q class="italic {{class}}" {{style_attr}} {{attr}}>@slot</q>'],

            's' => ['title' => 'Strikethrough Text', 'template' => '<s class="{{class}}" {{style_attr}} {{attr}}>@slot</s>'],

            'samp' => ['title' => 'Sample Output', 'template' => '<samp class="font-mono {{class}}" {{style_attr}} {{attr}}>@slot</samp>'],

            'sect:banner' => ['title' => 'Promo Announcement Banner', 'template' => '<div class="relative bg-primary px-6 py-4 text-primary-foreground flex items-center justify-center gap-6 overflow-hidden {{class}}" {{style_attr}} {{attr}}><div class="absolute inset-0 bg-white/10 skew-x-12 translate-x-1/2"></div><p class="relative font-bold uppercase tracking-widest italic">@slot</p></div>'],

            'sect:cta' => ['title' => 'Call To Action Section', 'template' => '<section class="py-24 px-6 {{class}}" {{style_attr}} {{attr}}><div class="bg-primary text-primary-foreground rounded-[4rem] p-16 md:p-32 text-center space-y-10 shadow-3xl shadow-primary/40"><h2 class="text-5xl md:text-8xl font-black uppercase tracking-tighter italic">{{title}}</h2>@slot</div></section>'],

            'sect:faq' => ['title' => 'Faq Section Container', 'template' => '<section class="py-32 max-w-4xl mx-auto px-6 {{class}}" {{style_attr}} {{attr}}><h2 class="text-5xl font-black text-center mb-20 italic uppercase tracking-tighter">The Answers</h2>@slot</section>'],

            'sect:feature' => ['title' => 'Feature Block Item', 'template' => "<div class=\"p-12 border-2 rounded-[3rem] bg-card hover:bg-muted/50 transition-colors $int {{class}}\" {{style_attr}} {{attr}}><div class=\"h-16 w-16 bg-primary/10 rounded-2xl mb-8 flex items-center justify-center text-3xl\">{{icon}}</div><h3 class=\"text-2xl font-black mb-4 uppercase italic tracking-tighter\">{{title}}</h3><p class=\"text-muted-foreground\">@slot</p></div>"],

            'sect:hero' => ['title' => 'Hero Section Centered', 'template' => '<section class="py-24 px-6 text-center {{class}}"><h1 class="text-5xl font-black mb-6">{{title}}</h1><p class="text-xl text-muted-foreground mb-10 max-w-2xl mx-auto">{{desc}}</p><div class="flex justify-center gap-4">{{actions}}</div></section>'],

            'sect:hero-display' => ['title' => 'Display Banner Bold Hero', 'template' => '<section class="space-y-6 pb-8 pt-6 md:pb-12 md:pt-10 lg:py-32 {{class}}" {{style_attr}} {{attr}}><div class="container flex max-w-[64rem] flex-col items-center gap-4 text-center"><a href="#" class="rounded-2xl bg-muted px-4 py-1.5 text-sm font-medium">{{badge_text}}</a><h1 class="font-heading text-3xl sm:text-5xl md:text-6xl lg:text-7xl font-black text-foreground">{{title}}</h1><p class="max-w-[42rem] leading-normal text-muted-foreground sm:text-xl sm:leading-8">{{desc}}</p><div class="space-x-4">{{actions}}</div></div></section>'],

            'sect:hero-italic' => ['title' => 'Italic Display Hero Section', 'template' => "<section class=\"py-32 lg:py-56 px-6 text-center {{class}}\" {{style_attr}} {{attr}}><h1 class=\"text-6xl md:text-[10rem] font-black leading-[0.8] tracking-tightest italic uppercase mb-12 shadow-primary/10\">{{title}}</h1><p class=\"text-2xl text-muted-foreground max-w-2xl mx-auto mb-16\">{{desc}}</p><div class=\"flex justify-center gap-8\">@slot</div></section>"],

            'sect:newsletter' => ['title' => 'Newsletter Opt-In Section', 'template' => '<section class="py-24 border-y bg-accent/5 {{class}}" {{style_attr}} {{attr}}><div class="container max-w-4xl text-center space-y-10"><h2 class="text-4xl font-black italic uppercase">Stay Connected</h2>@slot</div></section>'],

            'sect:pricing' => ['title' => 'Simple Multi-Card Pricing Section', 'template' => '<section class="py-20 px-6 {{class}}"><h2 class="text-3xl font-bold text-center mb-12">Simple Pricing</h2><div class="grid md:grid-cols-3 gap-8">{{cards}}</div></section>'],

            'sect:pricing-featured' => ['title' => 'Featured Plan Pricing Section', 'template' => '<section class="container flex flex-col gap-6 py-8 md:max-w-[64rem] md:py-12 lg:py-24 {{class}}" {{style_attr}} {{attr}}><div class="mx-auto flex w-full flex-col gap-4 md:max-w-[58rem]"><h2 class="font-heading text-3xl leading-[1.1] sm:text-3xl md:text-6xl font-black text-center">{{title}}</h2><p class="max-w-[85%] leading-normal text-muted-foreground sm:text-lg sm:leading-7 text-center mx-auto">{{desc}}</p></div><div class="grid w-full items-start gap-10 rounded-3xl border border-border bg-background p-10 md:grid-cols-[1fr_200px]"><div class="grid gap-6"><h3 class="text-xl font-bold sm:text-2xl">{{plan_name}}</h3><ul class="grid gap-3 text-sm text-muted-foreground sm:grid-cols-2">{{features}}</ul></div><div class="flex flex-col gap-4 text-center"><div class="space-y-2"><h4 class="font-bold text-5xl">${{price}}</h4><p class="text-sm text-muted-foreground">Billed {{period}}</p></div>{{action}}</div></div></section>'],

            'sect:pricing-layout' => ['title' => 'Structured Pricing Layout Block', 'template' => '<section class="py-32 bg-muted/30 {{class}}" {{style_attr}} {{attr}}><div class="container grid md:grid-cols-3 gap-10 items-end">@slot</div></section>'],

            'sect:team' => ['title' => 'Crew/Team Showcase Grid', 'template' => '<section class="py-32 {{class}}" {{style_attr}} {{attr}}><h2 class="text-5xl font-black text-center mb-24 italic uppercase tracking-tighter">The Crew</h2><div class="container grid sm:grid-cols-2 lg:grid-cols-4 gap-12">@slot</div></section>'],

            'sect:testimonials' => ['title' => 'Testimonials Layout Section', 'template' => '<section class="py-32 bg-card {{class}}" {{style_attr}} {{attr}}><div class="container grid md:grid-cols-3 gap-12">@slot</div></section>'],

            'sect:testimonials-grid' => ['title' => 'Simple Grid Testimonials Section', 'template' => '<section class="bg-muted py-20 px-6 {{class}}"><div class="grid md:grid-cols-3 gap-8">{{items}}</div></section>'],

            'section' => ['title' => 'Base HTML Section Container', 'template' => '<section class="{{class}}" {{style_attr}} {{attr}}>@slot</section>'],

            'select' => ['title' => 'Base HTML Dropdown Select', 'template' => '<select name="{{name}}" class="border border-input rounded px-3 py-2 bg-background {{class}}" {{style_attr}} {{attr}}>@slot</select>'],

            'shell:dashboard' => ['title' => 'Dashboard Shell Outer Wrapper', 'template' => '<div class="bg-muted/30 min-h-screen flex {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'shell:footer' => ['title' => 'Standard App Footer Shell', 'template' => '<footer class="border-t py-12 bg-card {{class}}" {{style_attr}} {{attr}}><div class="container grid grid-cols-2 md:grid-cols-4 gap-8">@slot</div></footer>'],

            'shell:mega-footer' => ['title' => 'Large Mega Footer Shell', 'template' => '<footer class="border-t py-20 bg-card {{class}}" {{style_attr}} {{attr}}><div class="container grid grid-cols-2 lg:grid-cols-5 gap-12">@slot</div></footer>'],

            'shell:mega-menu' => ['title' => 'Pop-out Mega Menu Container', 'template' => '<div class="p-10 bg-card border rounded-[2.5rem] shadow-2xl grid grid-cols-4 gap-12 min-w-[800px] {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'shell:navbar' => ['title' => 'App Navigation Bar Shell', 'template' => '<nav class="sticky top-0 z-50 w-full border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 {{class}}" {{style_attr}} {{attr}}><div class="container flex h-16 items-center justify-between">@slot</div></nav>'],

            'shell:navbar-glass' => ['title' => 'Frosted Glass Floating Navbar Shell', 'template' => '<nav class="fixed top-4 left-1/2 -translate-x-1/2 w-[95%] max-w-7xl rounded-2xl border bg-background/60 backdrop-blur-xl z-50 flex items-center h-16 px-6 {{class}}" {{style_attr}} {{attr}}>@slot</nav>'],

            'shell:navbar-mega' => ['title' => 'Expanded Mega Menu Navbar Shell', 'template' => '<nav class="w-full border-b bg-background h-20 flex items-center {{class}}" {{style_attr}} {{attr}}><div class="container flex justify-between items-center">@slot</div></nav>'],

            'shell:sidebar' => ['title' => 'Sidebar Application Shell Layout', 'template' => '<div class="flex min-h-screen w-full flex-col bg-muted/40 {{class}}" {{style_attr}} {{attr}}><aside class="fixed inset-y-0 left-0 z-10 hidden w-14 flex-col border-r border-border bg-background sm:flex"><nav class="flex flex-col items-center gap-4 px-2 py-4">{{sidebar_top}}</nav><nav class="mt-auto flex flex-col items-center gap-4 px-2 py-4">{{sidebar_bottom}}</nav></aside><div class="flex flex-col sm:gap-4 sm:py-4 sm:pl-14"><header class="sticky top-0 z-30 flex h-14 items-center gap-4 border-b border-border bg-background px-4 sm:static sm:h-auto sm:border-0 sm:bg-transparent sm:px-6">{{header}}</header><main class="grid flex-1 items-start gap-4 p-4 sm:px-6 sm:py-0 md:gap-8">{{content}}</main></div></div>'],

            'shell:sidebar-expanded' => ['title' => 'Wide Left Sidebar Drawer', 'template' => '<aside class="w-80 border-r bg-card h-screen p-8 fixed left-0 top-0 {{class}}" {{style_attr}} {{attr}}>@slot</aside>'],

            'shell:sidebar-layout' => ['title' => 'Classic Static Left Sidebar', 'template' => '<aside class="w-64 border-r bg-card h-screen p-6 fixed left-0 top-0 overflow-y-auto {{class}}" {{style_attr}} {{attr}}><div class="font-black text-2xl mb-8 tracking-tighter">{{brand}}</div><nav class="space-y-1">@slot</nav></aside>'],

            'shell:sidebar-mini' => ['title' => 'Icon-Only Mini Left Sidebar', 'template' => '<aside class="w-16 border-r bg-card h-screen flex flex-col items-center py-6 fixed left-0 top-0 {{class}}" {{style_attr}} {{attr}}>@slot</aside>'],

            'small' => ['title' => 'Small Annotations', 'template' => '<small class="text-sm font-medium leading-none {{class}}" {{style_attr}} {{attr}}>@slot</small>'],

            'span' => ['title' => 'Inline Container Span', 'template' => '<span class="{{class}}" {{style_attr}} {{attr}}>@slot</span>'],

            'strong' => ['title' => 'Strong/Bold text', 'template' => '<strong class="font-bold {{class}}" {{style_attr}} {{attr}}>@slot</strong>'],

            'sub' => ['title' => 'Subscript text', 'template' => '<sub class="{{class}}" {{style_attr}} {{attr}}>@slot</sub>'],

            'sup' => ['title' => 'Superscript text', 'template' => '<sup class="{{class}}" {{style_attr}} {{attr}}>@slot</sup>'],

            'svg' => ['title' => 'Scalable Vector Graphics', 'template' => '<svg class="{{class}}" {{style_attr}} {{attr}}>@slot</svg>'],

            'table' => ['title' => 'Base HTML Table tag', 'template' => '<table class="w-full border-collapse {{class}}" {{style_attr}} {{attr}}>@slot</table>'],

            'tbody' => ['title' => 'Table Body Wrapper', 'template' => '<tbody class="{{class}}" {{style_attr}} {{attr}}>@slot</tbody>'],

            'td' => ['title' => 'Table Data Cell', 'template' => '<td class="p-2 border border-border {{class}}" {{style_attr}} {{attr}}>@slot</td>'],

            'textarea' => ['title' => 'Base HTML Text Area', 'template' => '<textarea name="{{name}}" class="border border-input rounded px-3 py-2 bg-background {{class}}" {{style_attr}} {{attr}}>@slot</textarea>'],

            'tfoot' => ['title' => 'Table Footer Wrapper', 'template' => '<tfoot class="{{class}}" {{style_attr}} {{attr}}>@slot</tfoot>'],

            'th' => ['title' => 'Table Header Cell', 'template' => '<th class="p-2 border border-border font-bold text-left {{class}}" {{style_attr}} {{attr}}>@slot</th>'],

            'thead' => ['title' => 'Table Head Wrapper', 'template' => '<thead class="bg-muted {{class}}" {{style_attr}} {{attr}}>@slot</thead>'],

            'time' => ['title' => 'Time Representation', 'template' => '<time datetime="{{datetime}}" class="{{class}}" {{style_attr}} {{attr}}>@slot</time>'],

            'tr' => ['title' => 'Table Row Container', 'template' => '<tr class="border-b border-border {{class}}" {{style_attr}} {{attr}}>@slot</tr>'],

            'u' => ['title' => 'Underlined Text', 'template' => '<u class="{{class}}" {{style_attr}} {{attr}}>@slot</u>'],

            'ui:accordion' => ['title' => 'Styled Base Accordion', 'template' => "<div class=\"w-full divide-y border rounded-3xl overflow-hidden $int {{class}}\" {{style_attr}} {{attr}}>@slot</div>"],

            'ui:accordion-structured' => ['title' => 'Structured Item Accordion', 'template' => '<div class="w-full divide-y divide-border {{class}}" {{style_attr}} {{attr}}>{{slot}}</div>'],

            'ui:alert' => ['title' => 'Styled Base Alert Container', 'template' => '<div class="relative w-full rounded-[1.5rem] border p-6 flex gap-4 {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'ui:alert-structured' => ['title' => 'Structured Context Alert', 'template' => '<div class="relative w-full rounded-lg border p-4 {{type_class|bg-background border-border}} {{class}}" {{style_attr}} {{attr}}><h5 class="mb-1 font-medium leading-none tracking-tight">{{title}}</h5><div class="text-sm opacity-90">{{desc}}</div></div>'],

            'ui:avatar' => ['title' => 'Dynamic Rounded Avatar', 'template' => "<div class=\"relative flex h-14 w-14 shrink-0 overflow-hidden rounded-full border-2 border-border $int {{class}}\" {{style_attr}} {{attr}}><img class=\"aspect-square h-full w-full object-cover\" src=\"{{src|https://github.com/shadcn.png}}\"></div>"],

            'ui:avatar-status' => ['title' => 'Avatar with Status Badge indicator', 'template' => '<div class="relative flex h-10 w-10 shrink-0 overflow-hidden rounded-full border border-border bg-muted {{class}}" {{style_attr}} {{attr}}><img class="aspect-square h-full w-full" src="{{src}}" alt="{{alt|Avatar}}"/><span class="absolute bottom-0 right-0 h-3 w-3 rounded-full border-2 border-background bg-success {{status_class}}"></span></div>'],

            'ui:badge' => ['title' => 'Solid Primary Badge', 'template' => "<span class=\"inline-flex items-center rounded-lg px-3 py-1 text-xs font-black uppercase tracking-wider bg-primary text-primary-foreground $int {{class}}\" {{style_attr}} {{attr}}>@slot</span>"],

            'ui:badge-outline' => ['title' => 'Outline style Badge', 'template' => "<span class=\"inline-flex items-center rounded-lg px-3 py-1 text-xs font-black uppercase tracking-wider border-2 $int {{class}}\" {{style_attr}} {{attr}}>@slot</span>"],

            'ui:badge-secondary' => ['title' => 'Secondary Styled Badge', 'template' => "<span class=\"inline-flex items-center rounded-lg px-3 py-1 text-xs font-black uppercase tracking-wider bg-secondary text-secondary-foreground $int {{class}}\" {{style_attr}} {{attr}}>@slot</span>"],

            'ui:breadcrumbs' => ['title' => 'Breadcrumb Navigation List', 'template' => '<nav class="flex text-sm text-muted-foreground gap-3 font-medium {{class}}" {{style_attr}} {{attr}}>@slot</nav>'],

            'ui:button' => ['title' => 'Styled Generic Transition Button', 'template' => "<button class=\"inline-flex items-center justify-center rounded-xl px-6 py-3 font-bold transition-all active:scale-95 $int {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],

            'ui:button-accent' => ['title' => 'Accent Color Button', 'template' => "<button class=\"bg-accent text-accent-foreground hover:bg-accent/80 px-8 py-3 rounded-2xl font-black $int {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],

            'ui:button-ghost' => ['title' => 'Secondary Ghost Style Button', 'template' => "<button class=\"hover:bg-accent hover:text-accent-foreground px-8 py-3 rounded-2xl font-black $int {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],

            'ui:button-group' => ['title' => 'Segmented Button Group Box', 'template' => '<div class="inline-flex rounded-2xl border border-input overflow-hidden shadow-sm {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'ui:button-link' => ['title' => 'Text Anchor Action Link', 'template' => "<button class=\"text-primary underline-offset-4 hover:underline px-4 py-2 font-bold $int {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],

            'ui:button-outline' => ['title' => 'Secondary Outline Button', 'template' => "<button class=\"border-2 border-input bg-background hover:bg-accent hover:text-accent-foreground px-8 py-3 rounded-2xl font-black $int {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],

            'ui:button-primary' => ['title' => 'Styled Primary Shadow Button', 'template' => "<button class=\"bg-primary text-primary-foreground hover:bg-primary/90 px-8 py-3 rounded-2xl font-black shadow-lg shadow-primary/20 $int {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],

            'ui:button-secondary' => ['title' => 'Secondary Content Accent Button', 'template' => "<button class=\"bg-secondary text-secondary-foreground hover:bg-secondary/80 px-8 py-3 rounded-2xl font-black $int {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],

            'ui:dropdown' => ['title' => 'Relative Positioned Dropdown Container', 'template' => '<div class="relative inline-block {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'ui:modal' => ['title' => 'Standard Screen Centered Overlay Modal', 'template' => "<div class=\"fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-md p-6 {{class}}\" {{style_attr}} {{attr}}><div class=\"bg-background rounded-[3rem] shadow-[0_0_100px_rgba(0,0,0,0.5)] p-12 max-w-2xl w-full relative $int\">@slot</div></div>"],

            'ui:modal-alpine' => ['title' => 'Alpine.js Controlled Modal Window', 'template' => '<div x-data="{ open: false }" class="relative z-50 {{class}}" {{attr}}><div x-show="open" class="fixed inset-0 bg-background/80 backdrop-blur-sm"></div><div x-show="open" class="fixed inset-0 flex items-center justify-center p-4"><div class="w-full max-w-lg border border-border bg-background p-6 shadow-lg rounded-lg"><h2 class="text-lg font-bold">{{title}}</h2><div class="py-4">{{slot}}</div><div class="flex justify-end gap-2">{{actions}}</div></div></div></div>'],

            'ui:pagination' => ['title' => 'Centered Pagination Row Container', 'template' => '<nav class="flex justify-center items-center gap-2 py-16 {{class}}" {{style_attr}} {{attr}}>@slot</nav>'],

            'ui:popover' => ['title' => 'Relative Tooltip Popover Card', 'template' => "<div class=\"z-50 w-80 rounded-2xl border bg-card p-6 shadow-2xl $int {{class}}\" {{style_attr}} {{attr}}>@slot</div>"],

            'ui:progressbar' => ['title' => 'Dynamic Width Progress Bar', 'template' => '<div class="w-full bg-secondary rounded-full h-2.5 {{class}}" {{style_attr}} {{attr}}><div class="bg-primary h-2.5 rounded-full" style="width: {{value|progress|45}}%"></div></div>'],

            'ui:progressbar-wide' => ['title' => 'Wide Track Highlighted Progress Bar', 'template' => '<div class="w-full h-3 bg-muted rounded-full overflow-hidden {{class}}" {{style_attr}} {{attr}}><div class="h-full bg-primary transition-all duration-1000" style="width: {{progress|0}}%"></div></div>'],

            'ui:rating' => ['title' => 'Star Rating Indicator Group', 'template' => '<div class="flex gap-1 text-yellow-500 text-2xl {{class}}" {{style_attr}} {{attr}}>★ ★ ★ ★ ★ @slot</div>'],

            'ui:spinner' => ['title' => 'CSS Border Spinning Loader', 'template' => '<div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary {{class}}" {{style_attr}} {{attr}}></div>'],

            'ui:spinner-accent' => ['title' => 'CSS Accent Loader Indicator', 'template' => '<div class="animate-spin rounded-full border-4 border-primary border-t-transparent h-10 w-10 {{class}}" {{style_attr}} {{attr}}></div>'],

            'ui:stepper' => ['title' => 'Multi-step Progress Stepper Segment', 'template' => '<div class="flex items-center w-full gap-4 {{class}}" {{style_attr}} {{attr}}>@slot</div>'],

            'ui:tabs' => ['title' => 'Static Tabs Layout Wrapper', 'template' => '<div class="w-full space-y-8 {{class}}" {{style_attr}} {{attr}}><div class="flex border-b gap-10 px-4">{{nav}}</div><div class="p-2">@slot</div></div>'],

            'ui:tabs-alpine' => ['title' => 'Alpine.js Dynamic Tab Control Block', 'template' => '<div x-data="{ tab: \'{{active|1}}\' }" class="w-full {{class}}" {{attr}}><div class="flex border-b border-border">{{headers}}</div><div class="py-4">{{contents}}</div></div>'],

            'ui:toast' => ['title' => 'Floating Bottom Toast Notification Container', 'template' => "<div class=\"fixed bottom-8 right-8 z-[100] w-full max-w-sm rounded-[2rem] border bg-card p-6 shadow-2xl flex items-center gap-4 animate-in slide-in-from-right-full $int {{class}}\" {{style_attr}} {{attr}}>@slot</div>"],

            'ui:toast-notification' => ['title' => 'Structured Auto-Dismiss Style Toast Alert', 'template' => '<div class="fixed bottom-4 right-4 z-50 flex w-full max-w-xs flex-col gap-2 {{class}}" {{style_attr}} {{attr}}><div class="flex items-center gap-3 rounded-lg border border-border bg-background p-4 shadow-lg"><div class="flex-1 text-sm font-medium">{{label}}</div><button class="text-muted-foreground hover:text-foreground">✕</button></div></div>'],

            'ui:tooltip' => ['title' => 'Hover Style Tooltip Element', 'template' => "<div class=\"z-50 overflow-hidden rounded-xl border bg-popover px-4 py-2 text-sm text-popover-foreground shadow-xl $int {{class}}\" {{style_attr}} {{attr}}>@slot</div>"],

            'ul' => ['title' => 'Unordered List', 'template' => '<ul class="list-disc pl-6 {{class}}" {{style_attr}} {{attr}}>@slot</ul>'],

            'var' => ['title' => 'Mathematical Variable', 'template' => '<var class="font-mono italic {{class}}" {{style_attr}} {{attr}}>@slot</var>'],

            'video' => ['title' => 'Base HTML Video Media', 'template' => '<video controls src="{{src}}" class="w-full rounded {{class}}" {{style_attr}} {{attr}}>@slot</video>'],

            'wbr' => ['title' => 'Word Break Opportunity', 'template' => '<wbr/>'],
        ];

        $variants = [
            // --- BUTTON VARIANTS (নতুন স্টাইলসমূহ) ---
            'ui:button-lg' => ['title'=>'Large Button','template'=>"<button class=\"px-10 py-5 text-lg font-black bg-primary text-primary-foreground rounded-3xl $int {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],
            'ui:button-sm' => ['title'=>'Small Button','template'=>"<button class=\"px-4 py-2 text-xs font-bold bg-secondary text-secondary-foreground rounded-lg $int {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],
            'ui:button-destructive' => ['title'=>'Destructive Button','template'=>"<button class=\"bg-destructive text-destructive-foreground hover:bg-destructive/90 px-8 py-3 rounded-2xl font-black $int {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],
            'ui:button-retro' => ['title'=>'Retro Brutalist Button','template'=>"<button class=\"px-8 py-3 bg-yellow-300 text-black border-4 border-black font-black uppercase tracking-wider hover:translate-x-1 hover:translate-y-1 hover:shadow-none shadow-[4px_4px_0px_0px_rgba(0,0,0,1)] transition-all {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],
            'ui:button-cyber' => ['title'=>'Cyberpunk Button','template'=>"<button class=\"relative px-8 py-3 bg-cyan-400 text-black font-black uppercase tracking-widest clip-path-cyber border-r-4 border-b-4 border-pink-500 hover:bg-cyan-300 active:scale-95 transition-all {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],
            'ui:button-skeuo' => ['title'=>'Skeuomorphic Button','template'=>"<button class=\"px-8 py-3 bg-gradient-to-b from-white to-gray-100 text-gray-800 rounded-xl font-bold border border-gray-300/80 shadow-[0_1px_3px_rgba(0,0,0,0.1),inset_0_1px_0_rgba(255,255,255,0.8)] hover:bg-gray-50 active:shadow-[inset_0_2px_4px_rgba(0,0,0,0.1)] transition-all {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],
            'ui:button-glow' => ['title'=>'Glow Pulsate Button','template'=>"<button class=\"relative px-8 py-3 bg-primary text-primary-foreground rounded-xl font-bold shadow-[0_0_15px_rgba(var(--primary),0.5)] hover:shadow-[0_0_25px_rgba(var(--primary),0.8)] transition-all duration-300 {{class}}\" {{style_attr}} {{attr}}>@slot</button>"],

            // --- BADGE VARIANTS (নতুন স্টাইলসমূহ) ---
            'ui:badge-success' => ['title'=>'Success Badge','template'=>"<span class=\"bg-green-100 text-green-800 border border-green-200 px-3 py-1 rounded-full text-xs font-black uppercase tracking-widest $int {{class}}\">@slot</span>"],
            'ui:badge-warning' => ['title'=>'Warning Badge','template'=>"<span class=\"bg-yellow-100 text-yellow-800 border border-yellow-200 px-3 py-1 rounded-full text-xs font-black uppercase tracking-widest $int {{class}}\">@slot</span>"],
            'ui:badge-info' => ['title'=>'Info Badge','template'=>"<span class=\"bg-blue-100 text-blue-800 border border-blue-200 px-3 py-1 rounded-full text-xs font-black uppercase tracking-widest $int {{class}}\">@slot</span>"],
            'ui:badge-cyber' => ['title'=>'Cyberpunk Neon Badge','template'=>"<span class=\"px-2 py-0.5 border border-pink-500 text-pink-500 bg-pink-950/30 text-[10px] font-mono uppercase tracking-widest {{class}}\">@slot</span>"],
            'ui:badge-retro' => ['title'=>'Retro Thick Badge','template'=>"<span class=\"px-3 py-1 border-2 border-black bg-purple-300 text-black text-xs font-black uppercase tracking-wider shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] {{class}}\">@slot</span>"],

            // --- CARD VARIANTS (নতুন স্টাইলসমূহ) ---
            'data:card-glass' => ['title'=>'Glass Card','template'=>"<div class=\"bg-white/10 backdrop-blur-xl border border-white/20 p-10 rounded-[3rem] $int {{class}}\">@slot</div>"],
            'data:card-neon' => ['title'=>'Neon Card','template'=>"<div class=\"bg-black border-primary shadow-[0_0_20px_rgba(var(--primary),0.3)] p-10 rounded-[3rem] $int {{class}}\">@slot</div>"],
            'data:card-retro' => ['title'=>'Retro Brutalist Card','template'=>"<div class=\"bg-white border-4 border-black p-10 rounded-none shadow-[8px_8px_0px_0px_rgba(0,0,0,1)] transition-all hover:translate-x-1 hover:translate-y-1 hover:shadow-[4px_4px_0px_0px_rgba(0,0,0,1)] {{class}}\">@slot</div>"],
            'data:card-bento' => ['title'=>'Bento Grid Card','template'=>"<div class=\"bg-card border hover:border-primary/30 rounded-[2rem] p-8 flex flex-col justify-between hover:scale-[1.02] transition-all duration-300 shadow-sm {{class}}\">@slot</div>"],
            'data:card-gradient' => ['title'=>'Border Gradient Card','template'=>"<div class=\"p-[2px] rounded-[2.5rem] bg-gradient-to-r from-pink-500 via-purple-500 to-cyan-500 {{class}}\"><div class=\"bg-card rounded-[2.4rem] p-10 h-full w-full\">@slot</div></div>"],

            // --- FORM VARIANTS (নতুন স্টাইলসমূহ) ---
            'form:input-retro' => ['title'=>'Retro Input','template'=>"<div class=\"space-y-2 {{class}}\"><label class=\"text-sm font-black uppercase tracking-wider\">{{label}}</label><input type=\"text\" placeholder=\"{{placeholder}}\" class=\"w-full h-12 px-4 border-4 border-black bg-white text-black font-medium focus:outline-none focus:bg-yellow-50 shadow-[4px_4px_0px_0px_rgba(0,0,0,1)]\" {{style_attr}} {{attr}}/></div>"],
            'form:input-glass' => ['title'=>'Glassmorphic Input','template'=>"<div class=\"space-y-2 {{class}}\"><label class=\"text-xs font-semibold text-white/70 ml-1\">{{label}}</label><input type=\"text\" placeholder=\"{{placeholder}}\" class=\"w-full h-12 px-4 rounded-xl border border-white/20 bg-white/5 text-white placeholder:text-white/40 focus:bg-white/10 focus:outline-none transition-all\" {{style_attr}} {{attr}}/></div>"],
            'form:input-cyber' => ['title'=>'Cyberpunk Input','template'=>"<div class=\"space-y-2 {{class}}\"><label class=\"text-xs font-mono text-cyan-400 uppercase tracking-widest\">{{label}}</label><input type=\"text\" placeholder=\"{{placeholder}}\" class=\"w-full h-12 px-4 border-2 border-cyan-500/50 bg-black text-cyan-300 font-mono focus:border-pink-500 focus:outline-none focus:ring-1 focus:ring-pink-500\" {{style_attr}} {{attr}}/></div>"],

            // --- SHELL VARIANTS (নতুন স্টাইলসমূহ) ---
            'shell:sidebar-expanded-glass' => ['title'=>'Expanded Glass Sidebar','template'=>"<aside class=\"w-96 border-r bg-background/40 backdrop-blur-2xl h-screen p-12 fixed left-0 top-0 {{class}}\">@slot</aside>"],
            'shell:bento-layout' => ['title' => 'Bento Grid Layout', 'template' => "<div class=\"grid grid-cols-1 md:grid-cols-4 gap-6 p-6 auto-rows-[250px] {{class}}\" {{style_attr}} {{attr}}>@slot</div>"],

            // --- SPINNER & LOADER VARIANTS (নতুন স্টাইলসমূহ) ---
            'ui:spinner-dots' => ['title'=>'Dots Spinner','template'=>"<div class=\"flex gap-2 {{class}}\"><div class=\"h-2 w-2 bg-primary rounded-full animate-bounce\"></div><div class=\"h-2 w-2 bg-primary rounded-full animate-bounce [animation-delay:0.2s]\"></div><div class=\"h-2 w-2 bg-primary rounded-full animate-bounce [animation-delay:0.4s]\"></div></div>"],
            'ui:spinner-ping' => ['title'=>'Radar Pulse Loader','template'=>"<div class=\"relative h-12 w-12 {{class}}\"><span class=\"animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75\"></span><span class=\"relative inline-flex rounded-full h-12 w-12 bg-primary/40\"></span></div>"],
            'ui:spinner-grid' => ['title'=>'Grid Pulse Loader','template'=>"<div class=\"grid grid-cols-3 gap-1 h-9 w-9 {{class}}\"><div class=\"bg-primary rounded-full animate-pulse\"></div><div class=\"bg-primary rounded-full animate-pulse [animation-delay:0.2s]\"></div><div class=\"bg-primary rounded-full animate-pulse [animation-delay:0.4s]\"></div><div class=\"bg-primary rounded-full animate-pulse [animation-delay:0.2s]\"></div><div class=\"bg-primary rounded-full animate-pulse [animation-delay:0.4s]\"></div><div class=\"bg-primary rounded-full animate-pulse [animation-delay:0.6s]\"></div><div class=\"bg-primary rounded-full animate-pulse [animation-delay:0.4s]\"></div><div class=\"bg-primary rounded-full animate-pulse [animation-delay:0.6s]\"></div><div class=\"bg-primary rounded-full animate-pulse [animation-delay:0.8s]\"></div></div>"],
        ];
        
        $c = array_merge($c, $variants);
        self::$registry = array_merge(self::$registry, $c);

        // সেফ নেমস্পেস ম্যাপিং: কী-কলিশন প্রতিরোধে স্লাগ ভ্যালিডেশন
        foreach ($c as $slug => $meta) {
            self::$registry["ui:$slug"] = $meta;
            $parts = explode(':', $slug);
            if (count($parts) > 1) {
                $alias = "ui:{$parts[1]}";
                // কলিশন আটকাতে অলটারনেটিভ অ্যাসাইনমেন্ট সিকিউর করা হলো
                if (!isset(self::$registry[$alias])) {
                    self::$registry[$alias] = $meta;
                }
            }
        }
    }

    public static function catalog(): array { self::boot(); return self::$registry; }
}
?>
