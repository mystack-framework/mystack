<?php

/**
 * ============================================================================
 * Class: PHML
 * Title: Markup & Layout DSL
 * ============================================================================
 * 
 * Advanced HTML Markup DSL for crafting layouts, partials, blocks, UI components, and managing head/body tags alongside asset composition.
 * 
 * Features:
 * - Fluent HTML Markup DSL.
 * - Layout, block, and partial composition.
 * - Automated head/body tag management.
 * - Asset compilation and injection.
 * 
 * Usage Example:
 * ```php
 * echo PHML::block('header', ['title' => 'Dashboard']);
 * echo PHML::tag('div', 'Content here', ['class' => 'container']);
 * ```
 * 
 * @package    MyStack
 * @author     Sakibur Rahman (@sakibweb)
 * @published  https://github.com/mystack-framework
 * @web        https://mystack-framework.github.io
 * @license    Apache License 2.0
 * ============================================================================
 */



class PHML {
    public static $flatAttrMap = null;
    public static $components = [];
    public static $sharedData = [];
    public static $treeCache = [];
    private static $layout = null;
    private static $blocks = [];

    // ==================================================
    // 1. TAG ALIASES (COMPLETE LIST)
    // ==================================================
    public static $tagAliases = [
        // Document Structure
        'html'      => ['html', 'root', 'doc'],
        'head'      => ['head', 'meta-head', 'top-head'],
        'body'      => ['body', 'content-body', 'main-body'],
        'title'     => ['title', 'page-title', 'tab-name'],
        'meta'      => ['meta', 'metadata', 'seo-tag'],
        'link'      => ['link', 'stylesheet', 'sheet', 'css_link', 'style_link', 'resource'],
        'style'     => ['style', 'css', 'css-block', 'custom-css'],
        'script'    => ['script', 'js', 'javascript', 'code-block'],
        
        // Sectioning & Layout
        'div'       => ['div', 'block', 'container', 'wrapper', 'd', 'dv', 'box', 'cont', 'wrap', 'group', 'area', 'zone', 'panel'],
        'span'      => ['span', 'sp', 's', 'text', 'txt', 'inline', 'in', 'phrase', 'word', 'char', 'lbl'],
        'main'      => ['main', 'content', 'primary-content'],
        'header'    => ['header', 'page_header', 'page_top', 'head-sec', 'top-bar', 'nav-header'],
        'footer'    => ['footer', 'foot', 'bottom-bar', 'page-footer', 'copyright-sec'],
        'nav'       => ['nav', 'navbar', 'navigation', 'menu-bar', 'links-area'],
        'section'   => ['section', 'sec', 'part', 'segment', 'division', 'chapter'],
        'article'   => ['article', 'art', 'post', 'story', 'entry'],
        'aside'     => ['aside', 'sidebar', 'side-panel', 'drawer'],
        'address'   => ['address', 'contact-info', 'location'],
        'h1'        => ['h1', 'head1', 'title1', 'main-title', 'h-one'],
        'h2'        => ['h2', 'head2', 'title2', 'sub-title', 'h-two', 'subtitle', 'sub-head'],
        'h3'        => ['h3', 'head3', 'title3', 'section-title', 'h-three'],
        'h4'        => ['h4', 'head4', 'title4', 'h-four'],
        'h5'        => ['h5', 'head5', 'title5', 'h-five'],
        'h6'        => ['h6', 'head6', 'title6', 'h-six'],
        
        // Text Content
        'p'         => ['p', 'para', 'paragraph', 'lines', 'desc', 'description', 'info', 'details', 'statement', 'note'],
        'a'         => ['a', 'anchor', 'link', 'url', 'href', 'go', 'to', 'ref', 'uri', 'path', 'hyperlink'],
        'pre'       => ['pre', 'preformatted', 'raw-text'],
        'code'      => ['code', 'snippet', 'syntax'],
        'blockquote'=> ['blockquote', 'quote', 'citation', 'excerpt'],
        'hr'        => ['hr', 'rule', 'horizontal_rule', 'divider', 'line', 'separator'],
        'vr'        => ['vr', 'vertical_rule', 'vertical_divider', 'vertical_line', 'vline', 'v-divider'],
        'br'        => ['br', 'break', 'line_break', 'newline', 'gap', 'enter'],
        
        // Inline Text Semantics
        'strong'    => ['strong', 'bld', 'bold', 'important'],
        'b'         => ['b', 'bold', 'bold-text'],
        'em'        => ['em', 'italic', 'emphasis'],
        'i'         => ['i', 'italic-text', 'icon'],
        'u'         => ['u', 'underline'],
        's'         => ['s', 'strike', 'deleted'],
        'small'     => ['small', 'tiny', 'fine-print'],
        'sub'       => ['sub', 'subscript'],
        'sup'       => ['sup', 'superscript'],
        'mark'      => ['mark', 'highlight'],
        'abbr'      => ['abbr', 'abbreviation', 'short'],
        
        // Lists
        'ul'        => ['ul', 'list', 'ulist', 'bullet_list', 'unordered', 'bullets', 'list-u', 'menu-list'],
        'ol'        => ['ol', 'orderedList', 'olist', 'ordered', 'numbered', 'list-o', 'steps'],
        'li'        => ['li', 'item', 'point', 'list-item', 'element', 'node', 'entry', 'row', 'line'],
        'dl'        => ['dl', 'description_list', 'dlist', 'desc-list', 'definitions'],
        'dt'        => ['dt', 'term', 'def-term'],
        'dd'        => ['dd', 'def', 'def-desc'],

        // Images & Media
        'img'       => ['img', 'image', 'pic', 'picture', 'photo', 'media', 'visual', 'figure', 'thumb', 'avatar'],
        'figure'    => ['figure', 'fig', 'illustration'],
        'figcaption'=> ['figcaption', 'figcap', 'caption', 'fig-desc'],
        'picture'   => ['picture', 'pic-set'],
        'source'    => ['source', 'media-src'],
        'video'     => ['video', 'vid', 'movie', 'clip'],
        'audio'     => ['audio', 'sound', 'track', 'music'],
        'iframe'    => ['iframe', 'frame', 'embed-page'],
        'svg'       => ['svg', 'vector', 'graphic'],
        'path'      => ['path', 'svg-path'],
        'circle'    => ['circle', 'svg-circle'],
        'rect'      => ['rect', 'svg-rect'],
        'line'      => ['line', 'svg-line'],
        'polyline'  => ['polyline', 'svg-polyline'],
        'polygon'   => ['polygon', 'svg-polygon'],
        'g'         => ['g', 'svg-group'],
        'filter'    => ['filter'],
        'feTurbulence'    => ['feTurbulence'],
        'feDisplacementMap'    => ['feDisplacementMap'],
        
        // Forms
        'form'      => ['form', 'f', 'frm', 'data-form', 'entry-form', 'input-group', 'action-form'],
        'input'     => ['input', 'inp', 'in', 'field', 'entry', 'textbox', 'type-in', 'data-entry', 'val-in', 'edit'],
        'textarea'  => ['textarea', 'ta', 'txt-area', 'long-text', 'comment-box'],
        'button'    => ['button', 'btn', 'bt', 'b', 'click', 'action', 'submit', 'trigger', 'press', 'push', 'cmd'],
        'select'    => ['select', 'dropdown', 'picker', 'chooser', 'options'],
        'option'    => ['option', 'opt', 'choice', 'selection'],
        'optgroup'  => ['optgroup', 'opt-group', 'choice-group'],
        'label'     => ['label', 'lbl', 'tag-name', 'field-label'],
        'fieldset'  => ['fieldset', 'field-group', 'form-set'],
        'legend'    => ['legend', 'field-title'],
        
        // Tables
        'table'     => ['table', 'tbl', 'grid', 'sheet', 'data-grid', 'spreadsheet', 'rows', 'cols'],
        'thead'     => ['thead', 'table-head', 'tbl-head'],
        'tbody'     => ['tbody', 'table-body', 'tbl-body'],
        'tfoot'     => ['tfoot', 'table-foot', 'tbl-foot'],
        'tr'        => ['tr', 'row', 'table-row', 'r', 'line-item'],
        'td'        => ['td', 'cell', 'table-cell', 'c', 'data-cell', 'col'],
        'th'        => ['th', 'head-cell', 'h-cell', 'title-cell'],
        'caption'   => ['caption', 'table-title'],
        'colgroup'  => ['colgroup', 'col-group'],
        'col'       => ['col', 'column'],

        // Interactive Elements
        'details'   => ['details', 'accordion', 'expandable'],
        'summary'   => ['summary', 'accordion-head'],
        'dialog'    => ['dialog', 'modal', 'popup', 'overlay'],
        
        // Special / Obscure
        'var'       => ['var', 'variable'],
        'template'  => ['template', 'tpl', 'tmpl', 'tmp', 'blueprint'],
    ];

    /**
     * Share data globally across all PHML renders.
     *
     * @param array $data Associative array of data to share.
     * @return void
     */
    public static function share(array $data) {
        self::$sharedData = array_merge(self::$sharedData, $data);
    }

    /**
     * Render DSL from a file.
     *
     * @param string $filePath Path to the PHML/DSL file.
     * @param array $localData Optional data to pass to the template.
     * @return string The rendered HTML.
     */
    public static function partial(string $filePath, array $localData = []): string {
        if (!file_exists($filePath)) {
            if (class_exists('PHDE', false) && PHDE::isDebug()) {
                error_log("PHML Error: Partial file '$filePath' not found.");
            }
            return "";
        }
        $dsl = file_get_contents($filePath);
        return self::render($dsl, $localData);
    }

    /**
     * Set the layout for the current page.
     *
     * @param string $dsl The PHML/DSL for the layout.
     * @param array $data Optional data for the layout.
     * @return void
     */
    public static function layout(string $dsl, array $data = []) {
        self::$layout = ['dsl' => $dsl, 'data' => $data];
    }

    /**
     * Define a block of content for a layout.
     *
     * @param string $name The name of the block.
     * @param string $content The HTML content of the block.
     * @return void
     */
    public static function block(string $name, string $content) {
        self::$blocks[$name] = $content;
    }

    /**
     * Output a block in a layout.
     *
     * @param string $name The name of the block.
     * @param string $default Optional default content if block is not defined.
     * @return string The block content or default.
     */
    public static function yieldBlock(string $name, string $default = ''): string {
        return self::$blocks[$name] ?? $default;
    }

    /**
     * Register a new UI component with optional assets.
     *
     * @param string $name Component tag name.
     * @param string|callable $template PHML string or callback function.
     * @param array $assets Optional associative array with 'css' and 'js' keys.
     * @return void
     */
    public static function component(string $name, $template, array $assets = []) {
        self::$components[$name] = [
            'template' => $template,
            'css' => $assets['css'] ?? $assets['style'] ?? null,
            'js' => $assets['js'] ?? $assets['script'] ?? null
        ];
    }

    /**
     * Check if a component exists.
     *
     * @param string $name Component name to check.
     * @return bool True if exists, false otherwise.
     */
    public static function hasComponent(string $name): bool {
        return isset(self::$components[$name]);
    }

    // ==================================================
    // 2. ATTRIBUTE ALIASES (HUGE LIST: Standard + HTMX + Alpine)
    // ==================================================
    public static $attrAliases = [
        // --- Standard HTML & Global ---
        'class'       => ['class', 'cls', 'c', 'style-class', 'css', 'classes', 'styling', 'theme', 'className'],
        'id'          => ['id', 'uid', 'i', 'identity', 'key', 'identifier', 'unique'],
        'name'        => ['name', 'n', 'nm', 'field-name', 'input-name', 'var-name'],
        'type'        => ['type', 't', 'kind', 'sort', 'category', 'input-type'],
        'value'       => ['value', 'v', 'val', 'content', 'data-val', 'input-val'],
        'placeholder' => ['placeholder', 'ph', 'hint', 'guide', 'text-hint', 'empty-text'],
        'src'         => ['src', 'source', 'url', 'link', 'path', 'file', 'location'],
        'href'        => ['href', 'link', 'url', 'go-to', 'target-url', 'destination', 'ref'],
        'style'       => ['style', 's', 'css-inline', 'inline-style', 'custom-css', 'paint', 'sty'],
        'disabled'    => ['disabled', 'dis', 'off', 'inactive', 'locked', 'readonly', 'blocked'],
        'required'    => ['required', 'req', 'must', 'needed', 'mandatory', 'force'],
        'checked'     => ['checked', 'chk', 'on', 'active', 'selected', 'ticked'],
        'readonly'    => ['readonly', 'ro', 'read-only', 'view-only'],
        'multiple'    => ['multiple', 'multi', 'many', 'array'],
        'autocomplete'=> ['autocomplete', 'ac', 'auto-fill', 'suggest'],
        'autofocus'   => ['autofocus', 'focus', 'auto-focus'],
        'pattern'     => ['pattern', 'regex', 'rule', 'validation', 'pat'],
        'min'         => ['min', 'minimum', 'low'],
        'max'         => ['max', 'maximum', 'high'],
        'step'        => ['step', 'increment', 'interval'],
        'rows'        => ['rows', 'lines', 'height-lines'],
        'cols'        => ['cols', 'width-chars'],
        'for'         => ['for', 'target-id', 'label-for'],
        'alt'         => ['alt', 'alternative', 'desc', 'img-desc'],
        'title'       => ['title', 'tip', 'tooltip', 'info'],
        'tabindex'    => ['tabindex', 'tab', 'order', 'focus-index', 'tabidx', 'tabIndex'],
        'role'        => ['role', 'aria-role', 'purpose'],
        'aria-label'  => ['aria-label', 'aria-txt', 'access-label'],
        'data-'       => ['data-', 'd-', 'dataset-', 'meta-'],
        
        // Forms
        'action'      => ['action', 'act', 'target-url'],
        'method'      => ['method', 'meth', 'http-verb'],
        'enctype'     => ['enctype', 'enc', 'encoding-type'],
        'novalidate'  => ['novalidate', 'noVal', 'skip-check'],
        'maxlength'   => ['maxlength', 'maxLen', 'limit-char'],
        'minlength'   => ['minlength', 'minLen', 'min-char'],
        
        // Media
        'width'       => ['width', 'w', 'wide'],
        'height'      => ['height', 'h', 'tall'],
        'controls'    => ['controls', 'ctrls', 'player-controls'],
        'autoplay'    => ['autoplay', 'autoPlay', 'auto-start'],
        'muted'       => ['muted', 'silent', 'no-sound'],
        
        // Tables
        'colspan'     => ['colspan', 'cspan', 'merge-col'],
        'rowspan'     => ['rowspan', 'rspan', 'merge-row'],

        // --- Basic Events (JS) ---
        'onclick'     => ['onclick', 'click', 'onClick', 'tap', 'mouse-click'],
        'onchange'    => ['onchange', 'change', 'onChange', 'modified'],
        'oninput'     => ['oninput', 'input', 'onInput', 'type'],
        'onsubmit'    => ['onsubmit', 'submit', 'onSubmit', 'enter', 'send-form'],
        'onload'      => ['onload', 'load', 'onLoad', 'ready', 'render'],
        'onblur'      => ['onblur', 'blur', 'focus-out', 'leave'],
        'onfocus'     => ['onfocus', 'focus', 'focus-in', 'active'],
        'onmouseover' => ['onmouseover', 'hover', 'onMouseOver', 'mouse-in'],
        'onmouseout'  => ['onmouseout', 'mouse-out', 'leave-hover'],
        'onkeyup'     => ['onkeyup', 'keyup', 'key-release'],
        'onkeydown'   => ['onkeydown', 'keydown', 'key-press'],

        // --- Alpine.js (A-Z Aliases) ---
        'x-data'      => ['x-data', 'data', 'state', 'ctx', 'store', 'local', 'scope', 'vm'],
        'x-init'      => ['x-init', 'init', 'start', 'begin', 'load', 'boot', 'setup', 'xInit'],
        'x-show'      => ['x-show', 'show', 'visible', 'display', 'reveal', 'appear', 'view', 'showIf', 'xShow'],
        'x-bind'      => ['x-bind', 'bind', 'attr', 'prop', 'set', 'assign', 'dynamic', ':', 'xBind'],
        'x-on'        => ['x-on', 'on', 'event', 'listen', 'handle', 'when', 'trigger-on', '@', 'xOn'],
        'x-text'      => ['x-text', 'text', 'content', 'txt', 'string', 'label', 'msg', 'xText'],
        'x-html'      => ['x-html', 'html', 'raw', 'inner', 'markup', 'dom', 'render-html', 'xHtml'],
        'x-model'     => ['x-model', 'model', 'val', 'input', 'sync', 'bind-val', 'two-way', 'xModel'],
        'x-for'       => ['x-for', 'for', 'loop', 'each', 'iterate', 'repeat', 'list', 'xFor', 'forEach'],
        'x-transition'=> ['x-transition', 'transition', 'anim', 'animate', 'fade', 'motion', 'fx', 'xTransition'],
        'x-effect'    => ['x-effect', 'effect', 'run', 'watch', 'react', 'autorun', 'calc', 'xEffect'],
        'x-ignore'    => ['x-ignore', 'ignore', 'skip', 'no-alpine', 'static', 'plain', 'xIgnore'],
        'x-ref'       => ['x-ref', 'ref', 'reference', 'as', 'alias', 'xRef', 'refName'],
        'x-cloak'     => ['x-cloak', 'cloak', 'hide-until-load', 'wait', 'stealth', 'xCloak'],
        'x-teleport'  => ['x-teleport', 'teleport', 'move', 'portal', 'send-to', 'render-in'],
        'x-if'        => ['x-if', 'if', 'cond', 'condition', 'render-if', 'exists', 'xIf'],
        'x-id'        => ['x-id', 'id-gen', 'uid-gen', 'unique', 'generate-id'],
        'x-mask'      => ['x-mask', 'mask', 'format', 'input-mask'],
        'x-intersect' => ['x-intersect', 'intersect', 'viewport', 'in-view', 'scroll-spy'],
        'x-resize'    => ['x-resize', 'resize', 'on-resize', 'size-change'],
        'x-trap'      => ['x-trap', 'trap', 'focus-trap', 'lock-focus'],
        'x-collapse'  => ['x-collapse', 'collapse', 'accordion', 'slide-toggle', 'fold'],
        'x-anchor'    => ['x-anchor', 'anchor', 'position', 'stick-to', 'align-to'],
        'x-morph'     => ['x-morph', 'morph', 'smooth-update', 'diff'],
        'x-sort'      => ['x-sort', 'sort', 'order', 'drag-drop', 'draggable'],

        // --- HTMX (A-Z Aliases) ---
        'hx-get'      => ['hx-get', 'get', 'fetch', 'load', 'read', 'retrieve', 'hxGet'],
        'hx-post'     => ['hx-post', 'post', 'send', 'submit', 'create', 'write', 'hxPost'],
        'hx-put'      => ['hx-put', 'put', 'update', 'replace-data', 'hxPut'],
        'hx-delete'   => ['hx-delete', 'delete', 'remove', 'destroy', 'erase', 'del', 'hxDelete'],
        'hx-patch'    => ['hx-patch', 'patch', 'modify', 'edit', 'partial-update', 'hxPatch'],
        'hx-trigger'  => ['hx-trigger', 'trigger', 'when', 'on-event', 'listen-htmx', 'start-on', 'hxTrigger'],
        'hx-target'   => ['hx-target', 'target', 'dest', 'into', 'to', 'output', 'place-in', 'hxTarget'],
        'hx-swap'     => ['hx-swap', 'swap', 'render', 'replace-method', 'insert', 'placement', 'hxSwap'],
        'hx-select'   => ['hx-select', 'select', 'pick', 'extract', 'filter-response', 'choose', 'hxSelect', 'selectContent'],
        'hx-vals'     => ['hx-vals', 'vals', 'values', 'data-htmx', 'params-json', 'payload', 'hxVals'],
        'hx-indicator'=> ['hx-indicator', 'indicator', 'loading', 'spinner', 'loader', 'busy', 'hxIndicator'],
        'hx-push-url' => ['hx-push-url', 'push', 'url-push', 'history-push', 'new-url', 'hxPushUrl', 'pushUrl'],
        'hx-confirm'  => ['hx-confirm', 'confirm', 'ask', 'sure', 'verify', 'dialog', 'hxConfirm'],
        'hx-boost'    => ['hx-boost', 'boost', 'spa', 'link-boost', 'ajaxify', 'preload-links', 'hxBoost'],
        'hx-disable'  => ['hx-disable', 'disable-htmx', 'off', 'ignore-htmx', 'no-htmx', 'hxDisable'],
        'hx-headers'  => ['hx-headers', 'headers', 'req-headers', 'hxHeaders'],
        'hx-history'  => ['hx-history', 'history', 'save-history'],
        'hx-include'  => ['hx-include', 'include', 'with', 'send-also', 'combine', 'hxInclude'],
        'hx-params'   => ['hx-params', 'params', 'fields', 'filter-params', 'only'],
        'hx-preserve' => ['hx-preserve', 'preserve', 'keep', 'static', 'no-change', 'hxPreserve'],
        'hx-request'  => ['hx-request', 'request', 'config', 'settings', 'req-cfg'],
        'hx-sync'     => ['hx-sync', 'sync', 'queue', 'coordinate', 'wait-for'],
        'hx-validate' => ['hx-validate', 'validate', 'check', 'valid', 'form-check'],
        'hx-ext'      => ['hx-ext', 'ext', 'extension', 'hxExt'],
        'hx-swap-oob' => ['hx-swap-oob', 'swap-oob', 'oob', 'out-of-band', 'hxSwapOob'],
        'hx-disabled-elt' => ['hx-disabled-elt', 'disabled-elt', 'hxDisableElt'],
    ];

    // PHP Reserved Keywords (For Function Names)
    public static $unsafeKeywords = [
        'link', 'header', 'dl', 'list', 'break', 'clone', 'continue', 'declare', 'default', 'die', 'do',
        'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch',
        'endwhile', 'eval', 'exit', 'final', 'finally', 'for', 'foreach', 'function', 'global', 'goto',
        'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset',
        'namespace', 'new', 'or', 'print', 'private', 'protected', 'public', 'require', 'require_once',
        'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield'
    ];

    /**
     * Get the flattened attribute alias map.
     *
     * @return array The flattened attribute map.
     */
    public static function getFlatAttrMap() {
        if (self::$flatAttrMap !== null) {
            return self::$flatAttrMap;
        }

        $map = [];
        foreach (self::$attrAliases as $canonical => $aliases) {
            $map[$canonical] = $canonical;
            
            foreach ($aliases as $alias) {
                $map[$alias] = $canonical;
            }
        }
        
        self::$flatAttrMap = $map;
        return $map;
    }

    /**
     * Main render function for PHML DSL.
     *
     * @param string $dsl The PHML/DSL string to render.
     * @param array $localData Optional data for the render context.
     * @return string The rendered HTML.
     */
    public static function render(string $dsl, array $localData = []): string {
        $context = array_merge($GLOBALS, self::$sharedData, $localData);
        $dsl = self::cleanInput($dsl);
        
        $hash = md5($dsl);
        if (isset(self::$treeCache[$hash])) {
            $tree = self::$treeCache[$hash];
        } else {
            $tree = self::buildTree($dsl);
            self::$treeCache[$hash] = $tree;
        }

        $content = self::buildHtml($tree, $context);

        if (self::$layout !== null) {
            $layout = self::$layout;
            self::$layout = null; // Reset for next call
            $layoutData = array_merge($context, $layout['data'], ['slot' => $content]);
            return self::render($layout['dsl'], $layoutData);
        }

        return $content;
    }

    /**
     * Clean the DSL input string.
     *
     * @param string $dsl The raw DSL string.
     * @return string The cleaned DSL string.
     */
    private static function cleanInput($dsl) {
        return trim(preg_replace('/\s+/', ' ', $dsl));
    }

    /**
     * Build the element tree from DSL.
     *
     * @param string $dsl The DSL string.
     * @return array The element tree.
     */
    private static function buildTree($dsl) {
        return self::parseGroup($dsl);
    }

    /**
     * Parse a group of elements separated by '+'.
     *
     * @param string $str The group string.
     * @return array List of parsed elements.
     */
    private static function parseGroup($str) {
        $elements = [];
        $parts = self::splitBalanced($str, '+');
        foreach ($parts as $part) $elements[] = self::parseElement($part);
        return $elements;
    }

    /**
     * Parse a single element string, including nesting and loops.
     *
     * @param string $str The element string.
     * @return array The parsed element node.
     */
    private static function parseElement($str) {
        $str = trim($str);
        if (str_starts_with($str, '(') && str_ends_with($str, ')')) {
            return self::parseGroup(substr($str, 1, -1));
        }

        // Handle Loops (e.g., li*5 or li*$items)
        $repeat = null;
        if (preg_match('/\*(\$?[a-zA-Z0-9_]+)$/', $str, $m)) {
            $repeat = $m[1];
            $str = substr($str, 0, -strlen($m[0]));
        }

        $parts = self::splitBalanced($str, '>');
        $parentStr = array_shift($parts);
        $childrenStr = implode('>', $parts);
        $node = self::parseTagString($parentStr);
        if (!empty($childrenStr)) {
            $node['children'] = self::parseGroup($childrenStr);
        }

        if ($repeat !== null) {
            return ['type' => 'loop', 'repeat' => $repeat, 'node' => $node];
        }

        return $node;
    }

    /**
     * Parse a tag string (e.g., div#id.class[attr=val]{content}).
     *
     * @param string $str The tag string.
     * @return array The parsed tag node.
     */
    private static function parseTagString($str) {
        $node = ['tag' => 'div', 'attrs' => [], 'content' => '', 'children' => []];
        
        // Handle Block Yields (e.g., {yield:content})
        if (preg_match('/\{yield:([a-zA-Z0-9_-]+)\}/', $str, $m)) {
            return ['type' => 'yield', 'name' => $m[1]];
        }

        if (preg_match('/\{((?:[^{}]|(?R))*)\}/s', $str, $m)) {
            $node['content'] = $m[1];
            $str = str_replace($m[0], '', $str);
        }

        if (preg_match_all('/\[(.*?)\]/', $str, $matches)) {
            foreach ($matches[1] as $attrStr) {
                $node['attrs'] = array_merge($node['attrs'], self::parseAttributes($attrStr));
            }
            $str = preg_replace('/\[.*?\]/', '', $str);
        }

        if (preg_match('/@\[(.*?)\]/', $str, $m)) {
            if (class_exists('PHJS')) {
                $dslResult = PHJS::parse($m[1]); 
                preg_match_all('/([a-zA-Z0-9:-]+)(?:=["\'](.*?)["\'])?/', $dslResult, $res, PREG_SET_ORDER);
                foreach ($res as $r) $node['attrs'][$r[1]] = $r[2] ?? true;
            }
            $str = str_replace($m[0], '', $str);
        }

        if (preg_match('/^([a-zA-Z0-9]+)/', $str, $m)) {
            $tagName = $m[0];
            if (self::hasComponent($tagName)) {
                $node['isComponent'] = true;
                $node['tag'] = $tagName;
            } else {
                $node['tag'] = self::resolveTagAlias($tagName);
            }
            $str = substr($str, strlen($tagName));
        }
        if (preg_match('/#([a-zA-Z0-9_-]+)/', $str, $m)) {
            if (!isset($node['attrs']['id'])) $node['attrs']['id'] = $m[1];
        }
        if (preg_match('/@([a-zA-Z0-9_-]+)/', $str, $m)) {
             if (!isset($node['attrs']['name'])) $node['attrs']['name'] = $m[1];
        }
        if (preg_match_all('/\.([a-zA-Z0-9_-]+)/', $str, $m)) {
            $classes = implode(' ', $m[1]);
            if (isset($node['attrs']['class'])) $node['attrs']['class'] .= ' ' . $classes;
            else $node['attrs']['class'] = $classes;
        }
        return $node;
    }

    /**
     * Resolve a tag alias to its canonical HTML tag.
     *
     * @param string $tag The tag name or alias.
     * @return string The canonical tag name.
     */
    private static function resolveTagAlias($tag) {
        foreach (self::$tagAliases as $realTag => $aliases) {
            if ($tag === $realTag || in_array($tag, $aliases)) return $realTag;
        }
        return $tag; 
    }

    /**
     * Resolve an attribute alias to its canonical HTML attribute name.
     *
     * @param string $key The attribute name or alias.
     * @return string The canonical attribute name.
     */
    private static function resolveAttrAlias($key) {
        $key = strtolower($key);

        if (isset(self::$attrAliases[$key])) return $key;
        
        $bestMatch = null;
        $shortestDistance = -1;

        foreach (self::$attrAliases as $canonical => $aliases) {
            $allNames = array_merge([$canonical], $aliases);
            
            if (in_array($key, $allNames)) {
                return $canonical;
            }

            foreach ($allNames as $name) {
                $lev = levenshtein($key, $name);
                
                if (strlen($key) > 3 && $lev <= 2) {
                    if ($shortestDistance < 0 || $lev < $shortestDistance) {
                        $bestMatch = $canonical;
                        $shortestDistance = $lev;
                    }
                }
            }
        }

        return $bestMatch ?? $key;
    }

    /**
     * Parse an attribute string (e.g., "id=foo, class=bar").
     *
     * @param string $str The attribute string.
     * @return array Associative array of attributes.
     */
    private static function parseAttributes($str) {
        $attrs = [];
        $pairs = explode(',', $str);
        foreach ($pairs as $pair) {
            $parts = explode('=', trim($pair), 2);
            $key = self::resolveAttrAlias(trim($parts[0])); 
            $val = isset($parts[1]) ? trim($parts[1], " '\"") : true;
            
            if ($key === 'class' && isset($attrs['class'])) $attrs['class'] .= ' ' . $val;
            else $attrs[$key] = $val;
        }
        return $attrs;
    }

    /**
     * Recursively build HTML from the element tree.
     *
     * @param array $tree The element tree.
     * @param array $context The data context for resolution.
     * @return string The generated HTML.
     */
    private static function buildHtml($tree, $context) {
        $html = '';
        if (isset($tree[0])) {
            foreach ($tree as $node) $html .= self::buildHtml($node, $context);
            return $html;
        }

        // Handle Yields
        if (isset($tree['type']) && $tree['type'] === 'yield') {
            return self::yieldBlock($tree['name']);
        }

        // Handle Loops
        if (isset($tree['type']) && $tree['type'] === 'loop') {
            $repeat = $tree['repeat'];
            $node = $tree['node'];
            $count = 0;
            $items = [];

            if (str_starts_with($repeat, '$')) {
                $varName = substr($repeat, 1);
                if (isset($context[$varName]) && is_array($context[$varName])) {
                    $items = $context[$varName];
                    $count = count($items);
                }
            } else {
                $count = (int)$repeat;
                $items = range(1, $count);
            }

            foreach ($items as $index => $item) {
                $loopContext = array_merge($context, [
                    'index' => $index,
                    'iteration' => $index + 1,
                    'item' => $item
                ]);
                if (is_array($item)) {
                    $loopContext = array_merge($loopContext, $item);
                }
                $html .= self::buildHtml($node, $loopContext);
            }
            return $html;
        }

        if (isset($tree['isComponent']) && $tree['isComponent']) {
            $compName = $tree['tag'];
            $compInfo = self::$components[$compName];
            $template = $compInfo['template'];
            
            // Push Scoped Assets
            if (!empty($compInfo['css'])) self::css($compInfo['css']);
            if (!empty($compInfo['js'])) self::js($compInfo['js']);

            $compData = array_merge($context, $tree['attrs']);
            
            $slotContent = self::resolveContent($tree['content'], $context);
            if (!empty($tree['children'])) {
                $slotContent .= self::buildHtml($tree['children'], $context);
            }
            $compData['slot'] = $slotContent;

            if (is_callable($template)) {
                return $template($compData);
            } else {
                return self::render($template, $compData);
            }
        }

        $tag = $tree['tag'];
        $attrs = '';
        foreach ($tree['attrs'] as $k => $v) {
            if ($v === true) $attrs .= " $k";
            else $attrs .= " $k=\"" . htmlspecialchars($v) . "\"";
        }
        $content = self::resolveContent($tree['content'], $context);
        if (!empty($tree['children'])) {
            $content .= self::buildHtml($tree['children'], $context);
        }
        return "<{$tag}{$attrs}>{$content}</{$tag}>";
    }

    /**
     * Resolve content string, handling variables and expressions.
     *
     * @param string $content The content string.
     * @param array $context The data context.
     * @return mixed The resolved content.
     */
    private static function resolveContent($content, $context) {
        $content = trim((string) $content);
        if (str_starts_with($content, '$')) {
            $varName = substr($content, 1);
            if (array_key_exists($varName, $context) && is_scalar($context[$varName])) {
                return htmlspecialchars((string) $context[$varName], ENT_QUOTES, 'UTF-8');
            }
            if (preg_match('/^([a-zA-Z0-9_]+)\[[\'"](.+?)[\'"]\]$/', $varName, $m)) {
                $arrName = $m[1];
                $key = $m[2];
                if (isset($context[$arrName]) && is_array($context[$arrName])) {
                    $value = $context[$arrName][$key] ?? '';
                    return is_scalar($value)
                        ? htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
                        : '';
                }
            }
            return '';
        }

        $length = strlen($content);
        if ($length >= 2) {
            $quote = $content[0];
            if (($quote === '"' || $quote === "'") && $content[$length - 1] === $quote) {
                $literal = substr($content, 1, -1);
                return $quote === '"'
                    ? stripcslashes($literal)
                    : str_replace(["\\\\", "\\'"], ["\\", "'"], $literal);
            }
        }

        return $content;
    }

    /**
     * Split a string by delimiter while respecting balanced brackets.
     *
     * @param string $str The string to split.
     * @param string $delimiter The delimiter.
     * @return array The split parts.
     */
    private static function splitBalanced($str, $delimiter) {
        $parts = []; $buffer = ''; $level = 0;
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];
            if (in_array($char, ['(', '[', '{'])) $level++;
            if (in_array($char, [')', ']', '}'])) $level--;
            if ($char === $delimiter && $level === 0) {
                $parts[] = trim($buffer); $buffer = '';
            } else $buffer .= $char;
        }
        if ($buffer !== '') $parts[] = trim($buffer);
        return $parts;
    }
    
    /**
     * Magic call for static tag functions and components.
     *
     * @param string $name Tag or component name.
     * @param array $arguments Arguments passed to the function.
     * @return string The generated HTML.
     */
    public static function __callStatic($name, $arguments) {
        if (self::hasComponent($name)) {
            $compData = [];
            $content = '';
            foreach ($arguments as $arg) {
                if (is_array($arg)) $compData = array_merge($compData, $arg);
                else $content .= $arg;
            }
            $compData['slot'] = $content;
            $template = self::$components[$name];
            return is_callable($template) ? $template($compData) : self::render($template, $compData);
        }

        $tagName = self::resolveTagAlias($name);
        
        if ($tagName === $name) {
            $bestMatch = null;
            $shortestDistance = -1;

            $allTags = [];
            foreach (self::$tagAliases as $canonical => $aliases) {
                $allTags[$canonical] = $canonical;
                foreach ($aliases as $alias) $allTags[$alias] = $canonical;
            }

            foreach ($allTags as $alias => $canonical) {
                $lev = levenshtein($name, $alias);
                
                if ($lev === 0) {
                    $bestMatch = $canonical;
                    break;
                }

                if ($lev <= 3 && ($shortestDistance < 0 || $lev < $shortestDistance)) {
                    $bestMatch = $canonical;
                    $shortestDistance = $lev;
                }
            }

            if ($bestMatch) {
                $tagName = $bestMatch;
                if (class_exists('PHDE', false) && PHDE::isDebug()) {
                    error_log("PHML Warning: Tag '$name' not found. Did you mean '$bestMatch'?");
                }
            }
        }

        $attributes = [];
        $content = '';
        
        foreach ($arguments as $arg) {
            if (is_array($arg)) {
                foreach ($arg as $k => $v) {
                    $realKey = self::resolveAttrAlias($k); 
                    if ($realKey === 'class' && isset($attributes['class'])) {
                        $attributes['class'] .= ' ' . $v;
                    } else {
                        $attributes[$realKey] = $v;
                    }
                }
            } elseif (is_string($arg) && str_starts_with($arg, '@[')) {
                if (preg_match('/@\[(.*?)\]/', $arg, $m)) {
                    if (class_exists('PHJS')) {
                        $dslResult = PHJS::parse($m[1]); 
                        preg_match_all('/([a-zA-Z0-9:-]+)(?:=["\'](.*?)["\'])?/', $dslResult, $res, PREG_SET_ORDER);
                        foreach ($res as $r) {
                            $attributes[$r[1]] = $r[2] ?? true;
                        }
                    }
                }
            } else {
                $content .= $arg;
            }
        }
        
        $attrStr = '';
        foreach ($attributes as $k => $v) {
            if ($v === true) $attrStr .= " $k";
            else $attrStr .= " $k=\"" . htmlspecialchars((string)$v) . "\"";
        }
        
        return "<{$tagName}{$attrStr}>{$content}</{$tagName}>";
    }




    const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 
        'track', 'wbr', 'feTurbulence', 'feDisplacementMap', 'path', 'circle', 'rect', 'line', 'polyline', 'stop'
    ];

    /**
     * PHML Constructor.
     *
     * @param string $tag Tag name.
     * @param array $attrs Attributes.
     * @param array $children Child elements.
     */
    public function __construct(
        protected string $tag, 
        protected array $attrs, 
        protected array $children
    ) {
        $this->tag = ltrim($this->tag, '_');
    }

    /**
     * Convert PHML object to HTML string.
     *
     * @return string Generated HTML.
     */
    public function __toString(): string {
        $html = '<' . $this->tag;
        
        foreach ($this->attrs as $k => $v) {
            if ($v === null || $v === false) {
                continue;
            }
            
            $key = htmlspecialchars((string)$k, ENT_QUOTES | ENT_HTML5);
            
            if ($v === true || $v === '') {
                $html .= ' ' . $key;
            } else {
                $value = htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5);
                $html .= ' ' . $key . '="' . $value . '"';
            }
        }

        if (in_array($this->tag, self::VOID_ELEMENTS) && empty($this->children)) {
            return $html . ' />';
        }

        $html .= '>';
        
        foreach ($this->children as $c) {
            $html .= $c;
        }

        return $html . '</' . $this->tag . '>';
    }


    private static $metaDefaults = [
        'charset'     => 'UTF-8',
        'viewport'    => 'width=device-width, initial-scale=1.0',
        'http-equiv'  => ['X-UA-Compatible', 'IE=edge'],
        'title' => '', 'description' => '', 'keywords' => '', 'author' => '',
        'url' => '', 'image' => '', 'image_alt' => '', 'type' => 'website', 'site_name' => '',
        'locale' => 'en_US', 'twitter_card' => 'summary_large_image', 'twitter_creator' => '',
        'twitter_site' => '', 'theme_color' => '#ffffff', 'manifest' => '', 'favicon' => '',
        'icon' => '', 'canonical' => '', 'robots' => 'index, follow', 'published' => '',
        'modified' => '', 'category' => '', 'fb_app_id' => '', 'google_verify' => '',
        'bing_verify' => '', 'yandex_verify' => ''
    ];

    private static $metaConfig = []; 
    private static $jsStack = [];
    private static $cssStack = [];
    private static $headStack = [];
    private static $footerStack = [];
    
    private static $htmlAttrs = ['lang' => 'en']; 
    private static $bodyAttrs = []; 

    private static $debugLog = [];

    private static $uiConfig = [];

    private static bool $autoAssets = true;
    private static bool $bufferStarted = false;

    /**
     * Initialize PHML output buffering.
     *
     * @return void
     */
    public static function init() {
        self::$metaConfig = self::$metaDefaults; 
        if (!self::$bufferStarted) {
            self::$bufferStarted = true;
            ob_start([self::class, 'process']);
        }
    }

    /**
     * Enable or disable the automatic application-script tag.
     * The application script registers the service worker when appropriate.
     */
    public static function autoAssets(bool $enabled = true): void {
        self::$autoAssets = $enabled;
    }

    /**
     * Alias for init().
     *
     * @return void
     */
    private static function initialize() {
        self::init();
    }

    /**
     * Alias for init().
     *
     * @return void
     */
    public static function use() {
        self::init();
    }

    /**
     * Set global meta configuration.
     *
     * @param array $config Associative array of meta settings.
     * @return void
     */
    public static function meta(array $config) {
        self::$metaConfig = array_merge(self::$metaConfig, $config);
    }

    /**
     * Set the page title.
     *
     * @param string $text The title text.
     * @return void
     */
    public static function title($text) { self::$metaConfig['title'] = $text; }


    /**
     * Flatten nested arguments into a single array.
     *
     * @param array $args The arguments array.
     * @return array The flattened array.
     */
    private static function flattenArgs($args) {
        $flat = [];
        foreach ($args as $arg) {
            if (is_array($arg)) {
                $flat = array_merge($flat, self::flattenArgs($arg));
            } else {
                $flat[] = $arg;
            }
        }
        return $flat;
    }

    /**
     * Add JavaScript code to the stack.
     *
     * @param string|array ...$codes JS code strings or arrays.
     * @return void
     */
    public static function js(...$codes) {
        self::$jsStack = array_merge(self::$jsStack, self::flattenArgs($codes));
    }

    /**
     * Add CSS code to the stack.
     *
     * @param string|array ...$codes CSS code strings or arrays.
     * @return void
     */
    public static function css(...$codes) {
        self::$cssStack = array_merge(self::$cssStack, self::flattenArgs($codes));
    }

    /**
     * Set UI configuration for PHCS.
     *
     * @param array $config UI configuration settings.
     * @return void
     */
    public static function uiConfig(array $config) {
        self::$uiConfig = array_merge(self::$uiConfig, $config);
    }

    /**
     * Add content to the head section stack.
     *
     * @param string|array ...$codes Head content strings or arrays.
     * @return void
     */
    public static function head(...$codes) {
        self::$headStack = array_merge(self::$headStack, self::flattenArgs($codes));
    }

    /**
     * Add content to the footer section stack.
     *
     * @param string|array ...$codes Footer content strings or arrays.
     * @return void
     */
    public static function footer(...$codes) {
        self::$footerStack = array_merge(self::$footerStack, self::flattenArgs($codes));
    }

    /**
     * Set attributes for the <html> tag.
     *
     * @param array $attributes Associative array of attributes.
     * @return void
     */
    public static function html(array $attributes) {
        self::$htmlAttrs = array_merge(self::$htmlAttrs, $attributes);
    }

    /**
     * Set attributes for the <body> tag.
     *
     * @param array $attributes Associative array of attributes.
     * @return void
     */
    public static function body(array $attributes) {
        if (isset($attributes['class']) && isset(self::$bodyAttrs['class'])) {
            self::$bodyAttrs['class'] .= ' ' . $attributes['class'];
        } else {
            self::$bodyAttrs = array_merge(self::$bodyAttrs, $attributes);
        }
    }

    /**
     * Clear the generated cache files.
     *
     * @return string Status message.
     */
    public static function clearCache() {
        $docRoot = str_replace(['\\', '//'], '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/'));
        if (class_exists('DIR') && method_exists('DIR', 'path')) {
            $cachePath = str_replace(['\\', '//'], '/', rtrim(DIR::path('cache'), '/'));
        } else {
            $cachePath = $docRoot . '/cache';
        }

        if (is_dir($cachePath)) {
            $patterns = [
                $cachePath . '/css/*.css',
                $cachePath . '/js/*.js',
            ];
            foreach ($patterns as $pattern) {
                foreach (glob($pattern) ?: [] as $file) {
                    if (is_file($file)) unlink($file);
                }
            }
            return "Cache Cleared.";
        }
        return "Cache Not Found.";
    }

    /**
     * Log a debug message (as HTML comment).
     *
     * @param string $msg The message to log.
     * @return void
     */
    private static function log($msg) {
        if (class_exists('PHDE', false) && PHDE::isDebug()) {
            self::$debugLog[] = "<!-- DEBUG: $msg -->";
        }
    }

    /**
     * Build an attribute string from an array.
     *
     * @param array $attrs Associative array of attributes.
     * @return string The formatted attribute string.
     */
    private static function buildAttributes($attrs) {
        $str = '';
        foreach ($attrs as $key => $val) {
            $str .= ' ' . $key . '="' . htmlspecialchars($val) . '"';
        }
        return $str;
    }

    /**
     * Get the last modification time of a file or directory.
     *
     * @param string $path The path to check.
     * @return int The modification timestamp.
     */
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

    /**
     * Sanitize a route name for use in filenames.
     *
     * @param string $name The raw route name.
     * @return string The sanitized name.
     */
    private static function sanitizeRouteName($name) {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', '-', $name);
        $name = trim(preg_replace('/-+/', '-', $name), '-');
        return $name ?: 'index';
    }

    /**
     * Build meta tags and other head injections.
     *
     * @return string The generated HTML meta tags.
     */
    private static function buildMetaTags() {
        $m = self::$metaConfig;
        $html = [];
        $escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        if ($m['charset']) $html[] = '<meta charset="' . $escape($m['charset']) . '">';
        if ($m['viewport']) $html[] = '<meta name="viewport" content="' . $escape($m['viewport']) . '">';
        if ($m['http-equiv']) $html[] = '<meta http-equiv="' . $escape($m['http-equiv'][0]) . '" content="' . $escape($m['http-equiv'][1]) . '">';
        if ($m['title']) $html[] = '<title>' . $escape($m['title']) . '</title>';
        if ($m['description']) $html[] = '<meta name="description" content="' . $escape($m['description']) . '">';
        if ($m['keywords']) $html[] = '<meta name="keywords" content="' . $escape($m['keywords']) . '">';
        if ($m['author']) $html[] = '<meta name="author" content="' . $escape($m['author']) . '">';
        if ($m['robots']) $html[] = '<meta name="robots" content="' . $escape($m['robots']) . '">';
        
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $rootUrl = rtrim(PHRO::root(), '/');
        $rootParts = parse_url($rootUrl);
        $origin = isset($rootParts['scheme'], $rootParts['host'])
            ? $rootParts['scheme'] . '://' . $rootParts['host']
                . (isset($rootParts['port']) ? ':' . $rootParts['port'] : '')
            : $rootUrl;
        $currentUrl = $m['url'] ?: rtrim($origin, '/') . '/' . ltrim($requestPath, '/');
        $canonical = $m['canonical'] ?: $currentUrl;
        $html[] = '<link rel="canonical" href="' . $escape($canonical) . '">';

        $html[] = '<meta property="og:type" content="' . $escape($m['type']) . '">';
        $html[] = '<meta property="og:url" content="' . $escape($currentUrl) . '">';
        $html[] = '<meta property="og:locale" content="' . $escape($m['locale']) . '">';
        if ($m['title']) $html[] = '<meta property="og:title" content="' . $escape($m['title']) . '">';
        if ($m['description']) $html[] = '<meta property="og:description" content="' . $escape($m['description']) . '">';
        if ($m['image']) $html[] = '<meta property="og:image" content="' . $escape($m['image']) . '">';
        if ($m['image_alt']) $html[] = '<meta property="og:image:alt" content="' . $escape($m['image_alt']) . '">';
        if ($m['site_name']) $html[] = '<meta property="og:site_name" content="' . $escape($m['site_name']) . '">';
        if ($m['fb_app_id']) $html[] = '<meta property="fb:app_id" content="' . $escape($m['fb_app_id']) . '">';
        
        $html[] = '<meta name="twitter:card" content="' . $escape($m['twitter_card']) . '">';
        if ($m['twitter_site']) $html[] = '<meta name="twitter:site" content="' . $escape($m['twitter_site']) . '">';
        if ($m['twitter_creator']) $html[] = '<meta name="twitter:creator" content="' . $escape($m['twitter_creator']) . '">';
        if ($m['title']) $html[] = '<meta name="twitter:title" content="' . $escape($m['title']) . '">';
        if ($m['description']) $html[] = '<meta name="twitter:description" content="' . $escape($m['description']) . '">';
        if ($m['image']) $html[] = '<meta name="twitter:image" content="' . $escape($m['image']) . '">';

        if ($m['theme_color']) $html[] = '<meta name="theme-color" content="' . $escape($m['theme_color']) . '">';
        if ($m['favicon']) $html[] = '<link rel="icon" href="' . $escape($m['favicon']) . '" sizes="any">';
        if ($m['icon']) $html[] = '<link rel="apple-touch-icon" href="' . $escape($m['icon']) . '">';
        if ($m['manifest']) $html[] = '<link rel="manifest" href="' . $escape($m['manifest']) . '">';

        if ($m['google_verify']) $html[] = '<meta name="google-site-verification" content="' . $escape($m['google_verify']) . '">';
        if ($m['bing_verify']) $html[] = '<meta name="msvalidate.01" content="' . $escape($m['bing_verify']) . '">';

        $schema = [
            "@context" => "https://schema.org",
            "@type" => ucfirst($m['type'] === 'article' ? 'Article' : 'WebPage'),
            "headline" => $m['title'],
            "description" => $m['description'],
            "url" => $currentUrl,
        ];
        if ($m['image']) $schema["image"] = $m['image'];
        if ($m['published']) $schema["datePublished"] = $m['published'];
        
        $html[] = '<script type="application/ld+json">' . json_encode(
            $schema,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . '</script>';
        if (self::$autoAssets) {
            $root = $escape(rtrim(PHRO::root(), '/'));
            $appSource = dirname(__DIR__) . '/src/js/PHJS-min.php';
            $appVersion = is_file($appSource) ? (string) filemtime($appSource) : '11.0.0';
            // Keep the parser moving so the following render-blocking stylesheet
            // is discovered immediately. PHJS initializes after HTML parsing.
            $html[] = '<script src="' . $root . '/app.js?v=' . rawurlencode($appVersion) . '" defer></script>';
        }
        
        return implode("\n    ", $html);
    }

    /**
     * Post-process HTML content for output buffering.
     * Handles asset generation (PHCS), meta tags, and injections.
     *
     * @param string $htmlContent The final HTML content.
     * @return string The processed HTML content.
     */
    public static function process($htmlContent) {
        self::$bufferStarted = false;
        if (trim($htmlContent) === '') return '';

        if (http_response_code() >= 400) return $htmlContent;

        $headers = headers_list();
        $isHtml = true; 
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') !== false) {
                if (stripos($header, 'text/html') === false) {
                    $isHtml = false;
                    break;
                }
            }
        }
        if (!$isHtml) return $htmlContent;

        $docRoot = str_replace(['\\', '//'], '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
        if (class_exists('DIR') && method_exists('DIR', 'path')) {
            $cachePath = str_replace(['\\', '//'], '/', rtrim(DIR::path('cache'), '/'));
        } else {
            $cachePath = $docRoot . '/cache';
        }

        if (!is_dir($cachePath)) {
            if (!mkdir($cachePath, 0777, true)) return $htmlContent;
        }

        $cssCachePath = $cachePath . '/css';
        $jsCachePath = $cachePath . '/js';
        foreach ([$cssCachePath, $jsCachePath] as $assetCachePath) {
            if (
                !is_dir($assetCachePath) &&
                !mkdir($assetCachePath, 0755, true) &&
                !is_dir($assetCachePath)
            ) {
                return $htmlContent;
            }
        }

        if (class_exists('DIR') && method_exists('DIR', 'link')) {
            $publicUrl = rtrim(DIR::link('cache'), '/');
        } else {
            $publicUrl = '/' . ltrim(str_replace($docRoot, '', $cachePath), '/');
        }
        $publicUrl = htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8');

        try {
            $routeName = (class_exists('PHRO') && is_callable(['PHRO', 'route'])) 
                ? (PHRO::route()["name"] ?? 'index') 
                : 'index';
        } catch (Throwable $e) {
            $routeName = 'index';
        }
        
        $fileId = self::sanitizeRouteName($routeName);

        $isHtmx = isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] === 'true';
        if ($isHtmx) {
            $fileId .= '-hx-' . md5($_SERVER['REQUEST_URI'] ?? '');
        }

        $htmlAttrStr = self::buildAttributes(self::$htmlAttrs);
        $bodyAttrStr = self::buildAttributes(self::$bodyAttrs);

        $fullScannableContent = $isHtmx ? $htmlContent : "<html $htmlAttrStr><body $bodyAttrStr>$htmlContent</body></html>";

        $cssFilePath = $cssCachePath . "/{$fileId}.css";
        $jsFilePath = $jsCachePath . "/{$fileId}.js";

        $cssExists = file_exists($cssFilePath);
        $jsExists = file_exists($jsFilePath);

        $shouldGenerate = !$cssExists || !$jsExists;
        $styleSources = [];
        if (preg_match_all(
            '/(?:class|x-[a-z0-9_.:-]+|hx-[a-z0-9_.:-]+)\s*=\s*(["\'])(.*?)\1/is',
            $fullScannableContent,
            $styleMatches,
            PREG_SET_ORDER
        )) {
            foreach ($styleMatches as $styleMatch) {
                $styleSources[] = trim($styleMatch[0]);
            }
        }
        sort($styleSources);
        $styleSignaturePayload = json_encode(
            [array_values(array_unique($styleSources)), self::$cssStack, self::$uiConfig],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        $styleSignature = hash('sha256', is_string($styleSignaturePayload) ? $styleSignaturePayload : implode('|', $styleSources));
        $styleMarker = "/* phcs-source:{$styleSignature} */";
        if (!$shouldGenerate && $cssExists) {
            $existingCssPrefix = (string) @file_get_contents($cssFilePath, false, null, 0, 128);
            if (!str_contains($existingCssPrefix, $styleMarker)) {
                $shouldGenerate = true;
            }
        }

        if (!$shouldGenerate) {
            $cacheTime = min(
                file_exists($cssFilePath) ? filemtime($cssFilePath) : PHP_INT_MAX,
                file_exists($jsFilePath) ? filemtime($jsFilePath) : PHP_INT_MAX
            );
            
            $rootPath = (class_exists('DIR') && method_exists('DIR', 'path')) ? DIR::path('root') : './';
            $routerFile = rtrim($rootPath, '/\\') . '/index.php';

            $pathsToCheck = [
                $routerFile,
                (class_exists('DIR') && method_exists('DIR', 'path')) ? DIR::path('app') : '',
                (class_exists('DIR') && method_exists('DIR', 'path')) ? DIR::path('component') : '',
                __FILE__,
                __DIR__ . '/PHUI.php',
                __DIR__ . '/PHCS.php',
                __DIR__ . '/PHJS.php',
            ];

            foreach ($pathsToCheck as $path) {
                if (!empty($path) && file_exists($path)) {
                    if (self::getLastModTime($path) > $cacheTime) {
                        $shouldGenerate = true;
                        break;
                    }
                }
            }
        }

        $finalCssContent = "";
        $finalJsContent = "";

        if ($shouldGenerate) {
            if (class_exists('PHCS')) {
                try {
                    PHCS::config(self::$uiConfig ?? []);
                    PHCS::HTML($fullScannableContent);
                    $generatedCss = PHCS::build(false);
                    if (!empty($generatedCss)) {
                        $finalCssContent .= $generatedCss;
                    }
                } catch (Throwable $e) {
                    self::log("PHCS Error: " . $e->getMessage());
                }
            }
            if (!empty(self::$cssStack)) {
                $finalCssContent .= "\n" . implode("\n", self::$cssStack);
            }

            $finalJsContent = implode("\n", self::$jsStack);

            $finalCssContent = trim($finalCssContent);
            $finalCssContent = $styleMarker . ($finalCssContent !== '' ? "\n" . $finalCssContent : '');
            if ($finalCssContent !== "") {
                $currentHash = $cssExists ? md5_file($cssFilePath) : '';
                $newHash = md5($finalCssContent);
                if ($currentHash !== $newHash) {
                    file_put_contents($cssFilePath, $finalCssContent, LOCK_EX);
                    clearstatcache(true, $cssFilePath);
                    self::log("CSS Updated ($fileId).");
                }
            } else {
                file_put_contents($cssFilePath, '', LOCK_EX);
                clearstatcache(true, $cssFilePath);
            }

            $finalJsContent = trim($finalJsContent);
            if ($finalJsContent !== "") {
                $currentJsHash = $jsExists ? md5_file($jsFilePath) : '';
                $newJsHash = md5($finalJsContent);
                if ($currentJsHash !== $newJsHash) {
                    file_put_contents($jsFilePath, $finalJsContent, LOCK_EX);
                    clearstatcache(true, $jsFilePath);
                    self::log("JS Updated ($fileId).");
                }
            } else {
                file_put_contents($jsFilePath, '', LOCK_EX);
                clearstatcache(true, $jsFilePath);
            }
        }

        $cssLinkTag = "";
        if (file_exists($cssFilePath) && filesize($cssFilePath) > 0) {
            $ver = md5_file($cssFilePath);
            $cssLinkTag = "<link rel='stylesheet' href='{$publicUrl}/css/{$fileId}.css?v={$ver}'>";
        }

        $autoScriptTag = "";
        if (file_exists($jsFilePath) && filesize($jsFilePath) > 0) {
            $ver = md5_file($jsFilePath);
            $autoScriptTag = "<script src='{$publicUrl}/js/{$fileId}.js?v={$ver}' defer></script>";
        }

        clearstatcache();

        if ($isHtmx) {
            self::log("HTMX Request.");
            
            if (class_exists('DOMDocument')) {
                $dom = new DOMDocument();
                libxml_use_internal_errors(true); 
                
                $encodedHtml = function_exists('mb_convert_encoding')
                    ? mb_convert_encoding($htmlContent, 'HTML-ENTITIES', 'UTF-8')
                    : '<?xml encoding="UTF-8">' . $htmlContent;
                $dom->loadHTML($encodedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                libxml_clear_errors();

                $xpath = new DOMXPath($dom);
                $nodes = $xpath->query('//*[@forhx="true"]');

                if ($nodes->length > 0) {
                    $partialHtml = "";
                    foreach ($nodes as $node) {
                        $partialHtml .= $dom->saveHTML($node);
                    }
                    return $partialHtml . "\n" . $cssLinkTag . "\n" . $autoScriptTag;
                }
            }
            
            if (class_exists('PHJS')) {
                $htmlContent = PHJS::render($htmlContent);
            }
            return $htmlContent . "\n" . $cssLinkTag . "\n" . $autoScriptTag;
        }

        $metaTags = self::buildMetaTags();
        $customHead = implode("\n", self::$headStack);
        $customFooter = implode("\n", self::$footerStack);
        $debugOutput = implode("\n", self::$debugLog);

        if (class_exists('PHJS')) {
            $htmlContent = PHJS::render($htmlContent);
        }

        if (stripos($htmlContent, '<html') !== false && stripos($htmlContent, '</head>') !== false) {
            
            $headInjections = "{$metaTags}\n{$cssLinkTag}\n{$customHead}\n{$debugOutput}";
            if (trim($headInjections) !== "") {
                $htmlContent = str_ireplace('</head>', $headInjections . "\n</head>", $htmlContent);
            }
            
            $bodyInjections = "{$autoScriptTag}\n{$customFooter}";
            if (trim($bodyInjections) !== "") {
                $htmlContent = str_ireplace('</body>', $bodyInjections . "\n</body>", $htmlContent);
            }
            
            return $htmlContent;
        }

        return "<!DOCTYPE html>
<html{$htmlAttrStr}>
<head>
    {$metaTags}
    {$cssLinkTag}
    {$customHead}
    {$debugOutput}
</head>
<body{$bodyAttrStr}>
    {$htmlContent}
    {$autoScriptTag}
    {$customFooter}
</body>
</html>";
    }
}

if (!function_exists('phml')) {
    function phml($dsl) { return PHML::render($dsl); }
}


function phml_internal_builder(string $tag, ...$args): PHML {
    $attribute_aliases = PHML::getFlatAttrMap();

    $parsed_attrs = [];
    $tag_name = $tag;

    if (isset($args[0]) && is_string($args[0]) && (str_starts_with($args[0], '.') || str_starts_with($args[0], '#'))) {
        $selector_string = array_shift($args);
        $full_selector = $tag_name . $selector_string;
        
        if (preg_match('/^([a-zA-Z0-9]+)/', $full_selector, $tag_match)) {
            $tag_name = $tag_match[1];
        }

        if (preg_match('/#([a-zA-Z0-9_-]+)/', $full_selector, $id_match)) {
            $parsed_attrs['id'] = $id_match[1];
        }

        if (preg_match_all('/\.([a-zA-Z0-9_-]+)/', $full_selector, $class_matches)) {
            $parsed_attrs['class'] = implode(' ', $class_matches[1]);
        }
    }

    $user_attrs = (isset($args[0]) && is_array($args[0])) ? array_shift($args) : [];
    
    $final_attrs = $parsed_attrs;
    
    foreach ($user_attrs as $key => $value) {
        if (is_int($key)) {
            if (is_string($value)) {
                $final_attrs[$value] = true;
            }
            continue;
        }

        $canonical_key = $attribute_aliases[strtolower($key)] ?? $key;
        
        if ($canonical_key === 'class' && isset($final_attrs['class'])) {
            $final_attrs['class'] .= ' ' . $value;
        } else {
            $final_attrs[$canonical_key] = $value;
        }
    }
    
    return new PHML($tag_name, $final_attrs, $args);
}

$tag_aliases = PHML::$tagAliases;

$unsafe_keywords = PHML::$unsafeKeywords;

$unsafe_lookup = array_flip($unsafe_keywords);

foreach ($tag_aliases as $canonical_name => $aliases) {
    foreach ($aliases as $alias) {
        if (!preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $alias)) {
            continue;
        }
        
        $alias_lower = strtolower($alias);
        $is_unsafe = isset($unsafe_lookup[$alias_lower]);

        if (!$is_unsafe) {
            if (!function_exists($alias)) {
                $code = "function $alias(...\$a): PHML { return phml_internal_builder('$canonical_name', ...\$a); }";
                eval($code);
            }
        }

        $prefixed_alias = '_' . $alias;
        if (!function_exists($prefixed_alias)) {
            $code = "function $prefixed_alias(...\$a): PHML { return phml_internal_builder('$canonical_name', ...\$a); }";
            eval($code);
        }
    }
}
?>
