<?php

/**
 * ============================================================================
 * Class: PHJS (PHP-JS Hybrid Bridge & NLP Engine)
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * PHJS is an eternal, ultimate hybrid bridge that translates human-readable 
 * Natural Language Processing (NLP) syntax directly into Alpine.js, HTMX, 
 * and Vanilla JS attributes.
 *
 * Features:
 * - Massive Domain Specific Language (DSL) Parser and NLP Omniscient Engine.
 * - 100% Compatibility with Alpine.js and HTMX modifiers out of the box.
 * - Fuzzy intent recognition (e.g., 'toast Hello', 'hide modal').
 * - Fluent JS builder for chaining frontend interactivity directly from PHP.
 * 
 * Usage Example:
 * ```php
 * // Write Alpine/HTMX logic in plain English
 * $buttonAttr = phjs("click of #submitBtn then show #loader");
 * 
 * // Generate Titanium chained JS
 * echo PHJS::gen('toast "Data Saved Successfully!"');
 * ```
 */



class PHJS {
    
    public static bool $debug = false;
    private static string $version = "999999999.0.0";

    // =================================================================
    // PART 1: DSL PARSER (Alpine + HTMX A-Z with Aliases)
    // =================================================================

    private static $map = [
        // ==================================================
        // ALPINE.JS CORE (ইন্টারঅ্যাকশন ও স্টেট ম্যানেজমেন্ট)
        // ==================================================
        'data'       => 'x-data', 'state' => 'x-data', 'ctx' => 'x-data', 'store' => 'x-data', 'local' => 'x-data', 'scope' => 'x-data', 'vm' => 'x-data', 'data-y' => 'y-data', // ডেটা বা স্টেট ডিক্লেয়ার করা
        'init'       => 'x-init', 'start' => 'x-init', 'begin' => 'x-init', 'load' => 'x-init', 'boot' => 'x-init', 'setup' => 'x-init', 'on-load' => 'x-init', 'init-y' => 'y-init', // লোড হওয়ার সাথে সাথে কিছু করা
        'show'       => 'x-show', 'visible' => 'x-show', 'display' => 'x-show', 'reveal' => 'x-show', 'appear' => 'x-show', 'block' => 'x-show', 'view' => 'x-show', 'show-y' => 'y-show', // কন্ডিশনাল ডিসপ্লে (display: none)
        'bind'       => 'x-bind', 'attr' => 'x-bind', 'prop' => 'x-bind', 'set' => 'x-bind', 'assign' => 'x-bind', 'dynamic' => 'x-bind', 'bind-y' => 'y-bind', // এইচটিএমএল অ্যাট্রিবিউট বা ক্লাস বাইন্ড করা
        'on'         => 'x-on',   'event' => 'x-on',  'listen' => 'x-on', 'handle' => 'x-on', 'when' => 'x-on', 'trigger-on' => 'x-on', 'on-y' => 'y-on', // ইভেন্ট লিসেনার (@click)
        'text'       => 'x-text', 'content' => 'x-text', 'txt' => 'x-text', 'string' => 'x-text', 'label' => 'x-text', 'msg' => 'x-text', 'value-text' => 'x-text', 'text-y' => 'y-text', // টেক্সট কন্টেন্ট সেট করা
        'html'       => 'x-html', 'raw' => 'x-html', 'inner' => 'x-html', 'markup' => 'x-html', 'dom' => 'x-html', 'render-html' => 'x-html', 'html-y' => 'y-html', // এইচটিএমএল কন্টেন্ট সেট করা
        'model'      => 'x-model', 'val' => 'x-model', 'input' => 'x-model', 'sync' => 'x-model', 'bind-val' => 'x-model', 'two-way' => 'x-model', 'model-y' => 'y-model', // ফর্ম ইনপুট বাইন্ডিং
        'modelable'  => 'x-modelable', 'exclude' => 'x-modelable', 'share' => 'x-modelable', 'modelable-y' => 'y-modelable', // কাস্টম ইনপুট বাইন্ডিং
        'for'        => 'x-for', 'loop' => 'x-for', 'each' => 'x-for', 'iterate' => 'x-for', 'repeat' => 'x-for', 'list' => 'x-for', 'map' => 'x-for', 'for-y' => 'y-for', // লুপ চালানো
        'transition' => 'x-transition', 'anim' => 'x-transition', 'animate' => 'x-transition', 'fade' => 'x-transition', 'motion' => 'x-transition', 'fx' => 'x-transition', 'transition-y' => 'y-transition', // এনিমেশন বা ট্রানজিশন
        'effect'     => 'x-effect', 'run' => 'x-effect', 'watch' => 'x-effect', 'react' => 'x-effect', 'autorun' => 'x-effect', 'calc' => 'x-effect', 'effect-y' => 'y-effect', // সাইড ইফেক্ট চালানো
        'ignore'     => 'x-ignore', 'skip' => 'x-ignore', 'no-alpine' => 'x-ignore', 'static' => 'x-ignore', 'plain' => 'x-ignore', 'ignore-y' => 'y-ignore', // আলপাইন ইগনোর করবে
        'ref'        => 'x-ref', 'name' => 'x-ref', 'key' => 'x-ref', 'reference' => 'x-ref', 'as' => 'x-ref', 'alias' => 'x-ref', 'ref-y' => 'y-ref', // এলিমেন্ট রেফারেন্স
        'cloak'      => 'x-cloak', 'hide-until-load' => 'x-cloak', 'wait' => 'x-cloak', 'stealth' => 'x-cloak', 'mask-load' => 'x-cloak', 'cloak-y' => 'y-cloak', // লোড না হওয়া পর্যন্ত লুকানো
        'teleport'   => 'x-teleport', 'move' => 'x-teleport', 'portal' => 'x-teleport', 'send-to' => 'x-teleport', 'render-in' => 'x-teleport', 'teleport-y' => 'y-teleport', // অন্য জায়গায় রেন্ডার করা
        'if'         => 'x-if', 'cond' => 'x-if', 'condition' => 'x-if', 'render-if' => 'x-if', 'exists' => 'x-if', 'template-if' => 'x-if', 'if-y' => 'y-if', // কন্ডিশনাল রেন্ডারিং (DOM রিমুভ)
        'id'         => 'x-id', 'uid' => 'x-id', 'unique' => 'x-id', 'generate-id' => 'x-id', 'auto-id' => 'x-id', 'id-y' => 'y-id', // ইউনিক আইডি জেনারেট

        // --- ALPINE PLUGINS (অফিসিয়াল প্লাগিনসমূহ) ---
        'mask'       => 'x-mask', 'format' => 'x-mask', 'pattern' => 'x-mask', 'input-mask' => 'x-mask', 'mask-y' => 'y-mask', // ইনপুট মাস্কিং
        'intersect'  => 'x-intersect', 'viewport' => 'x-intersect', 'in-view' => 'x-intersect', 'scroll-spy' => 'x-intersect', 'visible-in' => 'x-intersect', 'intersect-y' => 'y-intersect', // স্ক্রিনে আসলে ডিটেক্ট করা
        'resize'     => 'x-resize', 'on-resize' => 'x-resize', 'size-change' => 'x-resize', 'responsive' => 'x-resize', 'resize-y' => 'y-resize', // এলিমেন্ট রিসাইজ ডিটেক্ট
        'trap'       => 'x-trap', 'focus-trap' => 'x-trap', 'lock-focus' => 'x-trap', 'modal-focus' => 'x-trap', 'trap-y' => 'y-trap', // ফোকাস ট্র্যাপ (মোডালের জন্য)
        'collapse'   => 'x-collapse', 'accordion' => 'x-collapse', 'slide-toggle' => 'x-collapse', 'fold' => 'x-collapse', 'collapse-y' => 'y-collapse', // কলাপস এনিমেশন
        'anchor'     => 'x-anchor', 'position' => 'x-anchor', 'stick-to' => 'x-anchor', 'align-to' => 'x-anchor', 'anchor-y' => 'y-anchor', // পজিশনিং বা টুলটিপ
        'morph'      => 'x-morph', 'smooth-update' => 'x-morph', 'diff' => 'x-morph', 'morph-y' => 'y-morph', // ডম মফিং
        'sort'       => 'x-sort', 'order' => 'x-sort', 'drag-drop' => 'x-sort', 'draggable' => 'x-sort', 'reorder' => 'x-sort', 'sort-y' => 'y-sort', // ড্র্যাগ এন্ড ড্রপ সর্টিং
        'persist'    => 'x-data', // ডেটা লোকাল স্টোরেজে সেভ রাখা

        // --- HTMX CORE (সার্ভার রিকোয়েস্ট) ---
        'get'        => 'hx-get', 'fetch' => 'hx-get', 'load' => 'hx-get', 'read' => 'hx-get', 'retrieve' => 'hx-get', 'get-y' => 'hx-get', // GET রিকোয়েস্ট
        'post'       => 'hx-post', 'send' => 'hx-post', 'submit' => 'hx-post', 'create' => 'hx-post', 'write' => 'hx-post', 'post-y' => 'hx-post', // POST রিকোয়েস্ট
        'put'        => 'hx-put', 'update' => 'hx-put', 'replace-data' => 'hx-put', 'put-y' => 'hx-put', // PUT রিকোয়েস্ট
        'delete'     => 'hx-delete', 'remove' => 'hx-delete', 'destroy' => 'hx-delete', 'erase' => 'hx-delete', 'del' => 'hx-delete', 'delete-y' => 'hx-delete', // DELETE রিকোয়েস্ট
        'patch'      => 'hx-patch', 'modify' => 'hx-patch', 'edit' => 'hx-patch', 'partial-update' => 'hx-patch', 'patch-y' => 'hx-patch', // PATCH রিকোয়েস্ট

        // --- HTMX ATTRIBUTES (ফিচার ও কনফিগারেশন) ---
        'trigger'     => 'hx-trigger', 'when' => 'hx-trigger', 'on-event' => 'hx-trigger', 'listen-htmx' => 'hx-trigger', 'start-on' => 'hx-trigger', 'trigger-y' => 'hx-trigger', // কখন রিকোয়েস্ট যাবে
        'target'      => 'hx-target', 'dest' => 'hx-target', 'into' => 'hx-target', 'to' => 'hx-target', 'output' => 'hx-target', 'place-in' => 'hx-target', 'target-y' => 'hx-target', // রেসপন্স কোথায় বসবে
        'swap'        => 'hx-swap', 'render' => 'hx-swap', 'replace-method' => 'hx-swap', 'insert' => 'hx-swap', 'placement' => 'hx-swap', 'swap-y' => 'hx-swap', // কিভাবে বসবে (innerHTML/outerHTML)
        'select'      => 'hx-select', 'pick' => 'hx-select', 'extract' => 'hx-select', 'filter-response' => 'hx-select', 'choose' => 'hx-select', 'select-y' => 'hx-select', // রেসপন্স থেকে নির্দিষ্ট অংশ নেওয়া
        'vals'        => 'hx-vals', 'values' => 'hx-vals', 'data-htmx' => 'hx-vals', 'params-json' => 'hx-vals', 'payload' => 'hx-vals', 'vals-y' => 'hx-vals', // রিকোয়েস্টে এক্সট্রা ডাটা পাঠানো
        'indicator'   => 'hx-indicator', 'loading' => 'hx-indicator', 'spinner' => 'hx-indicator', 'loader' => 'hx-indicator', 'busy' => 'hx-indicator', 'indicator-y' => 'hx-indicator', // লোডিং ইন্ডিকেটর
        'push'        => 'hx-push-url', 'url' => 'hx-push-url', 'history-push' => 'hx-push-url', 'new-url' => 'hx-push-url', 'change-url' => 'hx-push-url', // ব্রাউজার URL আপডেট করা
        'confirm'     => 'hx-confirm', 'ask' => 'hx-confirm', 'sure' => 'hx-confirm', 'verify' => 'hx-confirm', 'dialog' => 'hx-confirm', // কনফার্মেশন ডায়ালগ
        'boost'       => 'hx-boost', 'spa' => 'hx-boost', 'link-boost' => 'hx-boost', 'ajaxify' => 'hx-boost', 'preload-links' => 'hx-boost', // সাধারণ লিংককে Ajax এ রূপান্তর
        'disable'     => 'hx-disable', 'off' => 'hx-disable', 'ignore-htmx' => 'hx-disable', 'no-htmx' => 'hx-disable', // HTMX বন্ধ করা
        'disabled-elt'=> 'hx-disabled-elt', 'disable-while-loading' => 'hx-disabled-elt', 'freeze' => 'hx-disabled-elt', 'lock' => 'hx-disabled-elt', // লোডিং এর সময় এলিমেন্ট ডিজেবল করা
        'disinherit'  => 'hx-disinherit', 'no-inherit' => 'hx-disinherit', 'stop-inherit' => 'hx-disinherit', 'break-chain' => 'hx-disinherit', // ইনহেরিটেন্স বন্ধ করা
        'encoding'    => 'hx-encoding', 'enc-type' => 'hx-encoding', 'multipart' => 'hx-encoding="multipart/form-data"', 'upload-mode' => 'hx-encoding', // ফাইল আপলোডের জন্য এনকোডিং
        'ext'         => 'hx-ext', 'extension' => 'hx-ext', 'plugins' => 'hx-ext', 'addon' => 'hx-ext', 'use' => 'hx-ext', // এক্সটেনশন লোড করা
        'headers'     => 'hx-headers', 'head' => 'hx-headers', 'req-headers' => 'hx-headers', // কাস্টম হেডার
        'history'     => 'hx-history', 'no-history' => 'hx-history="false"', 'save-history' => 'hx-history', // হিস্ট্রি কন্ট্রোল
        'history-elt' => 'hx-history-elt', 'snapshot' => 'hx-history-elt', 'cache-elt' => 'hx-history-elt', // হিস্ট্রি স্ন্যাপশট এলিমেন্ট
        'include'     => 'hx-include', 'with' => 'hx-include', 'send-also' => 'hx-include', 'combine' => 'hx-include', // অন্য ফর্মের ডাটা ইনক্লুড করা
        'inherit'     => 'hx-inherit', 'parent-attr' => 'hx-inherit', // প্যারেন্ট থেকে অ্যাট্রিবিউট নেওয়া
        'params'      => 'hx-params', 'fields' => 'hx-params', 'filter-params' => 'hx-params', 'only' => 'hx-params', // কোন প্যারামিটার যাবে তা ফিল্টার করা
        'preserve'    => 'hx-preserve', 'keep' => 'hx-preserve', 'static' => 'hx-preserve', 'no-change' => 'hx-preserve', 'persist-dom' => 'hx-preserve', // কন্টেন্ট সোয়াপ না করা
        'prompt'      => 'hx-prompt', 'ask-input' => 'hx-prompt', 'input-dialog' => 'hx-prompt', // ইউজার থেকে ইনপুট নেওয়া
        'replace-url' => 'hx-replace-url', 'url-replace' => 'hx-replace-url', 'swap-url' => 'hx-replace-url', // URL রিপ্লেস করা (হিস্ট্রি ছাড়া)
        'request'     => 'hx-request', 'config' => 'hx-request', 'settings' => 'hx-request', 'req-cfg' => 'hx-request', // রিকোয়েস্ট কনফিগারেশন
        'select-oob'  => 'hx-select-oob', 'pick-oob' => 'hx-select-oob', 'extract-oob' => 'hx-select-oob', // OOB সিলেক্ট করা
        'swap-oob'    => 'hx-swap-oob', 'oob' => 'hx-swap-oob', 'out-of-band' => 'hx-swap-oob', 'external-swap' => 'hx-swap-oob', // অন্য এলিমেন্ট সোয়াপ করা
        'sync'        => 'hx-sync', 'queue' => 'hx-sync', 'coordinate' => 'hx-sync', 'wait-for' => 'hx-sync', 'serialize' => 'hx-sync', // রিকোয়েস্ট সিঙ্ক্রোনাইজেশন
        'validate'    => 'hx-validate', 'check' => 'hx-validate', 'valid' => 'hx-validate', 'form-check' => 'hx-validate', // ভ্যালিডেশন ফোর্স করা
        'vars'        => 'hx-vars', // ভ্যারিয়েবল (Deprecated)

        // --- COMMON EXTENSIONS (জনপ্রিয় এক্সটেনশন শর্টকাট) ---
        'ws'          => 'hx-ws', 'websocket' => 'hx-ws', 'socket' => 'hx-ws', // WebSocket
        'sse'         => 'hx-sse', 'server-sent-events' => 'hx-sse', 'event-source' => 'hx-sse', // SSE
        'json-enc'    => 'hx-ext="json-enc"', 'json' => 'hx-ext="json-enc"', 'json-body' => 'hx-ext="json-enc"', // JSON এনকোডিং
        'morph-enc'   => 'hx-ext="morph"', 'morphing' => 'hx-ext="morph"', // Morphdom
        'method-override' => 'hx-ext="method-override"', 'method' => 'hx-ext="method-override"', // PUT/DELETE মেথড সাপোর্ট
        'preload'     => 'hx-ext="preload"', 'prefetch' => 'hx-ext="preload"', 'early-load' => 'hx-ext="preload"', // লিংক প্রিলোড

        // --- EVENT SHORTCUTS (HTMX Events) ---
        'after-swap'    => 'hx-on:htmx:after-swap', 'done' => 'hx-on:htmx:after-swap', 'swapped' => 'hx-on:htmx:after-swap', // সোয়াপ হওয়ার পর
        'before-swap'   => 'hx-on:htmx:before-swap', 'will-swap' => 'hx-on:htmx:before-swap', // সোয়াপ হওয়ার আগে
        'after-request' => 'hx-on:htmx:after-request', 'finished' => 'hx-on:htmx:after-request', 'complete' => 'hx-on:htmx:after-request', // রিকোয়েস্ট শেষ হলে
        'before-request'=> 'hx-on:htmx:before-request', 'starting' => 'hx-on:htmx:before-request', 'begin' => 'hx-on:htmx:before-request', // রিকোয়েস্ট শুরুর আগে
        'config-request'=> 'hx-on:htmx:config-request', 'setup-req' => 'hx-on:htmx:config-request', // কনফিগ মডিফাই
        'load-error'    => 'hx-on:htmx:load-error', 'fail' => 'hx-on:htmx:load-error', 'err' => 'hx-on:htmx:load-error', // লোড এরর
        'send-error'    => 'hx-on:htmx:send-error', 'net-err' => 'hx-on:htmx:send-error', // নেটওয়ার্ক এরর
        'response-error'=> 'hx-on:htmx:response-error', 'http-err' => 'hx-on:htmx:response-error', // সার্ভার এরর (4xx/5xx)
        'abort'         => 'hx-on:htmx:abort', 'cancel' => 'hx-on:htmx:abort', 'stop' => 'hx-on:htmx:abort', // রিকোয়েস্ট বাতিল

        // ==================================================
        // BASIC JS & DOM ATTRIBUTES (নতুন যুক্ত করা হয়েছে)
        // ==================================================
        'click'         => 'onclick', 'mouse-click' => 'onclick', 'tap' => 'onclick', 'click-y' => 'onclick',
        'change'        => 'onchange', 'input' => 'oninput', 'type' => 'oninput', 'modified' => 'onchange',
        'submit'        => 'onsubmit', 'enter' => 'onsubmit', 'send-form' => 'onsubmit',
        'load'          => 'onload', 'ready' => 'onload', 'render' => 'onload',
        'blur'          => 'onblur', 'focus-out' => 'onblur', 'leave' => 'onblur',
        'focus'         => 'onfocus', 'focus-in' => 'onfocus', 'active' => 'onfocus',
        'keyup'         => 'onkeyup', 'keydown' => 'onkeydown', 'keypress' => 'onkeypress',
        
        'class'         => 'class', 'css' => 'class', 'style-class' => 'class',
        'style'         => 'style', 'css-inline' => 'style',
        'src'           => 'src', 'source' => 'src', 'image' => 'src', 'url' => 'src',
        'href'          => 'href', 'link' => 'href', 'to' => 'href',
        'disabled'      => 'disabled', 'readonly' => 'readonly', 'required' => 'required',
        'placeholder'   => 'placeholder', 'hint' => 'placeholder',
        'value'         => 'value', 'val' => 'value', 'content-val' => 'value',
        'name'          => 'name', 'field' => 'name', 'name-y' => 'y-name',
        'type'          => 'type', 'input-type' => 'type',
        'alt'           => 'alt', 'description' => 'alt',
        'title'         => 'title', 'tooltip' => 'title',

        // --- PHJS APP Attributes ---
        'rules'       => 'x-rules', 'validate' => 'x-rules', 'check-rules' => 'x-rules', 'rules-y' => 'y-rules', // Validation
        'label'       => 'x-label', 'label-y' => 'y-label', // Field labeling
        'props'       => 'x-props', 'props-y' => 'y-props', // Custom props
        'macro'       => 'x-macro', 'macro-y' => 'y-macro', // UI Macros
        'active'      => 'x-active', 'active-class' => 'x-active', // Active link state
        'icon'        => 'x-icon', 'glyph' => 'x-icon', // Icon renderer
        'chart'       => 'x-chart', 'graph' => 'x-chart', // Visualization
        'parallax'    => 'x-parallax', 'scroll-effect' => 'x-parallax', // Parallax
        'wizard'      => 'x-wizard', 'step-form' => 'x-wizard', // Form wizards
        'skeleton'    => 'x-skeleton', 'loading-placeholder' => 'x-skeleton', // Skeletons
        'spin'        => 'x-spin', 'spin-y' => 'y-spin', 'rotate-anim' => 'x-spin', // Spinner logic
        'timeout'     => 'x-timeout', 'delay-run' => 'x-timeout', // Timeout directive
        'interval'    => 'x-interval', 'repeat-run' => 'x-interval', // Interval directive
        'loop'        => 'x-loop', 'loop-y' => 'y-loop', // Loop directive
        'media'       => 'x-media', 'player' => 'x-media', // Media container
        'bg'          => 'x-bg', 'background-media' => 'x-bg', // Background media
        'theme'       => 'data-theme', 'mode' => 'data-theme', // Theme switching
        'touch'       => 'x-touch-action', 'gesture' => 'x-touch-action', // Touch gestures
        'upload'      => 'x-upload', 'dropzone' => 'x-upload', // File uploader
        'drm'         => 'x-drm', 'secure-content' => 'x-drm', // DRM Protection
        'lazy'        => 'x-lazy', 'defer-load' => 'x-lazy', // Lazy loading
        'resume'      => 'x-resume', 'persist-state' => 'x-resume', // State resume
        'paginate'    => 'x-paginate', 'infinite-scroll' => 'x-paginate', // Pagination
        'sortable'    => 'x-sortable', 'reorder-list' => 'x-sortable', // Sortable lists
    ];

    /**
     * Smart Asset Manager
     */
    public static function assets(array $options = []): string {
        $defaults = [
            'htmx' => 'https://unpkg.com/htmx.org@1.9.12',
            'alpine' => 'https://unpkg.com/alpinejs@3.14.0/dist/cdn.min.js',
            'phjs' => 'phjs.js',
            'plugins' => ['collapse', 'intersect', 'focus', 'mask', 'morph', 'persist', 'anchor', 'sort']
        ];
        $config = array_merge($defaults, $options);
        $html = "<!-- PHJS Ecosystem Assets (v".self::$version.") -->\n<script src=\"{$config['htmx']}\"></script>\n";
        foreach ($config['plugins'] as $p) $html .= "<script defer src=\"https://unpkg.com/@alpinejs/{$p}@3.x.x/dist/cdn.min.js\"></script>\n";
        $html .= "<script defer src=\"{$config['alpine']}\"></script>\n<script src=\"{$config['phjs']}\"></script>\n";
        if (self::$debug) $html .= "<script>console.log('🚀 PHJS Debug Mode Active (v".self::$version.")');</script>\n";
        return $html;
    }

    /**
     * Fluent Entry Point
     */
    public static function js(): PHJS_Chain { return new PHJS_Chain(); }

    /**
     * Magic Static Caller for full JS support
     */
    public static function __callStatic($name, $args) {
        $params = array_map([self::class, 'encode'], $args);
        return "{$name}(" . implode(', ', $params) . ");";
    }

    /**
     * Smart HTML Renderer
     */
    public static function render(string $html): string {
        $buffer = ''; $len = strlen($html); $i = 0;
        while ($i < $len) {
            if ($html[$i] === '@' && isset($html[$i+1]) && $html[$i+1] === '[') {
                $contentStart = $i + 2; $bracketDepth = 1; $j = $contentStart; $dslContent = ''; $foundEnd = false;
                while ($j < $len) {
                    $char = $html[$j];
                    if ($char === '[') $bracketDepth++; elseif ($char === ']') {
                        $bracketDepth--; if ($bracketDepth === 0) { $foundEnd = true; break; }
                    }
                    $dslContent .= $char; $j++;
                }
                if ($foundEnd) {
                    $buffer .= self::parseDSL($dslContent);
                    $i = $j + 1; continue;
                } else { $buffer .= '@['; $i += 2; continue; }
            }
            $buffer .= $html[$i]; $i++;
        }
        return $buffer;
    }

    private static function parseDSL(string $dsl) {
        $attributes = [];
        $parts = self::smartSplit($dsl, '|');
        
        foreach ($parts as $part) {
            $part = trim($part); 
            if (empty($part)) continue;
            
            if (preg_match('/^([a-zA-Z0-9@\-_]+)\s*[:]\s*(.*)$/s', $part, $m)) {
                $key = trim($m[1]); 
                $val = trim($m[2]);
                $attrKey = self::resolveKey($key);
                
                if (!str_starts_with($val, '{') && !str_starts_with($val, '[') && preg_match('/^[a-zA-Z0-9_\-]+\s*=/', $val)) {
                    $obj = [];
                    $props = explode(',', $val);
                    foreach ($props as $prop) {
                        $p = explode('=', trim($prop), 2);
                        if (count($p) === 2) {
                            $k = trim($p[0]); $v = trim($p[1]);
                            $isNum = is_numeric($v);
                            $isBool = in_array(strtolower($v), ['true', 'false', 'null']);                            
                            $vStr = ($isNum || $isBool) ? $v : "'".addslashes($v)."'";
                            $obj[] = "{$k}: {$vStr}";
                        }
                    }
                    $finalVal = "{ " . implode(', ', $obj) . " }";
                    $attributes[] = "{$attrKey}=\"{$finalVal}\"";
                } else {
                    if (str_starts_with($val, '{') || str_starts_with($val, '[')) {
                        $attributes[] = "{$attrKey}='{$val}'";
                    } else {
                        $safeVal = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
                        $attributes[] = "{$attrKey}=\"{$safeVal}\"";
                    }
                }
            } else { 
                $attributes[] = self::resolveKey($part); 
            }
        }
        return implode(' ', $attributes);
    }

    private static function smartSplit($str, $separator) {
        $parts = []; $buffer = ''; $stack = 0; $inQuote = false; $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $char = $str[$i];
            if ($char === '{' || $char === '[' || $char === '(') $stack++;
            if ($char === '}' || $char === ']' || $char === ')') $stack--;
            if ($char === "'" || $char === '"') $inQuote = !$inQuote;
            if ($char === $separator && $stack === 0 && !$inQuote) { $parts[] = $buffer; $buffer = ''; } else { $buffer .= $char; }
        }
        if (!empty($buffer)) $parts[] = $buffer;
        return $parts;
    }

    private static function resolveKey($key) {
        if (isset(self::$map[$key])) return self::$map[$key];
        if (str_starts_with($key, '@') || str_starts_with($key, ':')) return $key;
        return $key;
    }

    public static function parse(string $dsl): string {
        return self::parseDSL($dsl);
    }

    // =================================================================
    // PART 2: JS BUILDER (Globals, Magics, DOM, APP Engine)
    // =================================================================

    // --- Alpine Globals ---
    public static function alpineData(string $name, array $obj): string { return "document.addEventListener('alpine:init', () => { Alpine.data('$name', () => (".self::encodeFunction($obj).")) });"; }
    public static function alpineStore(string $name, array $obj): string { return "document.addEventListener('alpine:init', () => { Alpine.store('$name', ".self::encodeFunction($obj).") });"; }
    public static function alpineBind(string $name, array $obj): string { return "document.addEventListener('alpine:init', () => { Alpine.bind('$name', () => (".self::encodeFunction($obj).")) });"; }

    // --- Magic Variables & Globals ---
    public static function el(): string { return '$el'; }
    public static function refs(string $name = ''): string { return $name ? "\$refs.$name" : '$refs'; }
    public static function store(string $name): string { return "\$store.$name"; }
    public static function watch(string $prop, string $callback): string { return "\$watch('$prop', $callback)"; }
    public static function dispatch(string $event, array $detail = []): string { return "\$dispatch('$event', ".self::encodeFunction($detail).")"; }
    public static function nextTick(string $callback): string { return "\$nextTick($callback)"; }
    public static function root(): string { return '$root'; }
    public static function data(): string { return '$data'; }
    public static function id(string $name): string { return "\$id('$name')"; }
    public static function state_magic(): string { return '$state'; }
    public static function params_magic(): string { return '$params'; }
    public static function route_magic(): string { return '$route'; }
    public static function ui_magic(): string { return '$ui'; }
    public static function os_magic(): string { return '$os'; }
    public static function t_magic(): string { return '$t'; }
    public static function router_magic(): string { return '$router'; }
    public static function clipboard_magic(): string { return '$clipboard'; }

    // --- HTMX JS API ---
    public static function hxProcess(string $sel): string { return "htmx.process(" . self::ele($sel) . ");"; }
    public static function hxTrigger(string $sel, string $event): string { return "htmx.trigger(" . self::ele($sel) . ", '$event');"; }
    public static function hxAjax(string $method, string $url, string $target): string { return "htmx.ajax('$method', '$url', '$target');"; }
    public static function hxRemove(string $sel): string { return "htmx.remove(" . self::ele($sel) . ");"; }
    public static function hxAddClass(string $sel, string $cls): string { return "htmx.addClass(" . self::ele($sel) . ", '$cls');"; }
    public static function hxRemoveClass(string $sel, string $cls): string { return "htmx.removeClass(" . self::ele($sel) . ", '$cls');"; }
    public static function hxToggleClass(string $sel, string $cls): string { return "htmx.toggleClass(" . self::ele($sel) . ", '$cls');"; }
    public static function hxConfig(array $config): string { return "htmx.config = Object.assign(htmx.config, ".self::encodeFunction($config).");"; }

    // --- Variables ---
    public static function const($name, $value = null): string { return self::declareVar('const', $name, $value); }
    public static function let($name, $value = null): string { return self::declareVar('let', $name, $value); }
    public static function var($name, $value = null): string { return self::declareVar('var', $name, $value); }
    private static function declareVar($type, $name, $value) {
        if (is_array($name)) {
            $lines = []; foreach ($name as $k => $v) $lines[] = "$type $k = " . self::encode($v) . ";";
            return implode("\n", $lines);
        } return "$type $name = " . self::encode($value) . ";";
    }

    // --- Console ---
    public static function log($msg): string { return "console.log(".self::encode($msg).");"; }
    public static function error($msg): string { return "console.error(".self::encode($msg).");"; }
    public static function warn($msg): string { return "console.warn(".self::encode($msg).");"; }
    public static function table($msg): string { return "console.table(".self::encode($msg).");"; }

    // --- Storage ---
    public static function localSet(string $key, $val): string { return "localStorage.setItem('$key', ".self::encode($val).");"; }
    public static function localGet(string $key): string { return "localStorage.getItem('$key')"; }
    public static function localRemove(string $key): string { return "localStorage.removeItem('$key');"; }
    public static function sessionSet(string $key, $val): string { return "sessionStorage.setItem('$key', ".self::encode($val).");"; }
    public static function sessionGet(string $key): string { return "sessionStorage.getItem('$key')"; }
    public static function cookieSet(string $name, string $value, int $days = 7): string { return "document.cookie = '$name=".urlencode($value)."; path=/; max-age=' + ($days * 86400);"; }

    // --- DOM ---
    private static function ele(string $sel): string { return str_starts_with($sel, '#') ? "document.getElementById('" . substr($sel, 1) . "')" : (in_array($sel, ['window','document','body']) ? $sel : "document.querySelector('$sel')"); }
    public static function html(string $sel, string $html): string { return self::ele($sel).".innerHTML = ".self::encode($html).";"; }
    public static function text(string $sel, string $text): string { return self::ele($sel).".innerText = ".self::encode($text).";"; }
    public static function val(string $sel, $val): string { return self::ele($sel).".value = ".self::encode($val).";"; }
    public static function addClass(string $sel, string $cls): string { return self::ele($sel).".classList.add('$cls');"; }
    public static function removeClass(string $sel, string $cls): string { return self::ele($sel).".classList.remove('$cls');"; }
    public static function toggleClass(string $sel, string $cls): string { return self::ele($sel).".classList.toggle('$cls');"; }
    public static function css(string $sel, string $prop, string $val): string { return self::ele($sel).".style.$prop = '$val';"; }
    public static function attr(string $sel, string $attr, string $val): string { return self::ele($sel).".setAttribute('$attr', '$val');"; }
    public static function remove(string $sel): string { return self::ele($sel).".remove();"; }

    // --- Events ---
    public static function event(string $sel, string $evt, string $code): string { return self::ele($sel) . ".addEventListener('$evt', function(e) { $code });"; }
    public static function onReady(string $code): string { return "document.addEventListener('DOMContentLoaded', function() { $code });"; }

    // --- Network ---
    public static function redirect(string $url): string { return "window.location.href = '$url';"; }
    public static function reload(): string { return "window.location.reload();"; }
    public static function alert($msg): string { return "alert(".self::encode($msg).");"; }
    public static function fetch(string $url, array $opts = []): string { return "fetch('$url', ".self::encodeFunction($opts).").then(r => r.json()).then(d => console.log(d));"; }
    public static function raw(string $code): string { return $code . ";"; }

    // --- APP JS Engine (PHJS Core) ---
    public static function appReady(string $code): string { return "APP.ready(function(APP) { $code });"; }
    public static function appNavigate(string $url): string { return "APP.navigate('$url');"; }
    public static function appLink(string $url): string { return "APP.link('$url')"; }
    public static function appApi(string $url): string { return "APP.api('$url')"; }
    public static function appRoutePath(string $url = ''): string { return "APP.getRoutePath('$url')"; }
    public static function appToast(string $msg, string $type = 'info'): string { return "APP.ui.toast(".self::encode($msg).", '$type');"; }
    public static function appModal(string $id, string $action = 'open'): string { return "APP.ui.modal('$id', '$action');"; }
    public static function appProgress(bool $start = true): string { return $start ? "APP.ui.progress.start();" : "APP.ui.progress.done();"; }
    public static function appTheme(string $name): string { return "APP.theme.set('$name');"; }
    public static function appThemeToggle(): string { return "APP.theme.toggle();"; }
    public static function appValidate(string $selector): string { return "APP.validate('$selector');"; }
    public static function appCheck(string $selector, ?string $successMsg = null): string { return "APP.check('$selector', " . ($successMsg ? self::encode($successMsg) : 'null') . ");"; }
    public static function appSeo(array $config): string { return "APP.seo.set(".self::encodeFunction($config).");"; }
    public static function appI18n(string $lang): string { return "APP.i18n.set('$lang');"; }
    public static function appStoreGet(string $name): string { return "APP.store('$name')"; }
    public static function appStoreSet(string $name, $value): string { return "APP.store('$name', ".self::encode($value).");"; }
    public static function appStoreDispatch(string $action, $payload = null): string { return "APP.store.dispatch('$action', ".self::encode($payload).");"; }
    public static function appDbStorageSet(string $key, $val): string { return "APP.storage.set('$key', ".self::encode($val).");"; }
    public static function appDbStorageGet(string $key): string { return "APP.storage.get('$key')"; }
    public static function appDbStorageDel(string $key): string { return "APP.storage.del('$key');"; }
    public static function appDbSync(string $namespace, string $url): string { return "APP.db.sync('$namespace', '$url');"; }
    public static function appRequest(string $url, array $opts = []): string { return "APP.request('$url', ".self::encodeFunction($opts).");"; }
    public static function appUpload(string $fileVar, string $endpoint, array $options = []): string { return "APP.uploader.upload($fileVar, '$endpoint', ".self::encodeFunction($options).");"; }
    public static function appSearch(string $indexName, string $query): string { return "APP.search.query('$indexName', ".self::encode($query).")"; }
    public static function appSearchIndex(string $indexName, array $data): string { return "APP.search.index('$indexName', ".self::encodeFunction($data).");"; }
    public static function appHardware(string $type, string $action = 'connect', array $args = []): string { return "APP.hardware.$type.$action(".self::encodeFunction($args).");"; }
    public static function appDrmProtect(string $selector, array $config = []): string { return "APP.drm.protect(document.querySelector('$selector'), ".self::encodeFunction($config).");"; }
    public static function appFsRead(string $accept = '.txt,.json,.md'): string { return "APP.fs.readFile('$accept')"; }
    public static function appFsSave(string $content, string $defaultName = 'export.txt'): string { return "APP.fs.saveFile(".self::encode($content).", '$defaultName')"; }
    public static function appMediaInit(string $selector, array $options = []): string { return "APP.media.init(document.querySelector('$selector'), ".self::encodeFunction($options).");"; }
    public static function appChartInit(string $selector, array $options = []): string { return "APP.charts.init(document.querySelector('$selector'), ".self::encodeFunction($options).");"; }
    public static function appWorker(string $task, array $data = []): string { return "APP.worker.run('$task', ".self::encodeFunction($data).");"; }
    public static function appInspector(): string { return "APP.inspector.toggle();"; }
    public static function appPalette(): string { return "APP.palette.toggle();"; }
    public static function appA11yTrap(string $selector): string { return "APP.a11y.trapFocus(document.querySelector('$selector'));"; }
    public static function appDesignSet(string $name, string $value): string { return "APP.design.set('$name', ".self::encode($value).");"; }
    public static function appDesignGet(string $name): string { return "APP.design.get('$name')"; }
    public static function appTimeFormat(string $dateVar = 'new Date()', string $pattern = 'YYYY-MM-DD HH:mm:ss'): string { return "APP.time.format($dateVar, '$pattern')"; }
    public static function appTimeAgo(string $dateVar): string { return "APP.time.ago($dateVar)"; }
    public static function appAuthTotp(string $secret): string { return "APP.auth.totp.getCode('$secret')"; }
    public static function appHeroUpdate(string $selector): string { return "APP.hero.update(document.querySelector('$selector'));"; }
    public static function appAnimateTo(string $selector, array $props, array $options = []): string { return "APP.animate.to('$selector', ".self::encodeFunction($props).", ".self::encodeFunction($options).");"; }
    public static function appAnimateSpring(string $selector, array $props): string { return "APP.animate.spring('$selector', ".self::encodeFunction($props).");"; }
    public static function appFontLoad(string $name, string $url): string { return "APP.font.load('$name', '$url');"; }
    public static function appAi(string $prompt, array $opts = []): string { return "APP.ai.prompt(".self::encode($prompt).", ".self::encodeFunction($opts).");"; }
    public static function appXrInit(array $opts = []): string { return "APP.xr.init(".self::encodeFunction($opts).");"; }
    public static function appPwaEnable(array $opts = []): string { return "APP.enablePWA(".self::encodeFunction($opts).");"; }
    public static function appHydrate(): string { return "APP.hydrate();"; }

    /**
     * Explicit JS Function Caller
     */
    public static function call(string $name, ...$args): string {
        $params = array_map([self::class, 'encode'], $args);
        return "{$name}(" . implode(', ', $params) . ");";
    }

    /**
     * Wrap JS in Script Tag
     */
    public static function script(string $js): string {
        return "<script>\n" . trim($js) . "\n</script>";
    }

    private static function encode($value) {
        if (is_numeric($value)) return $value;
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_null($value)) return 'null';
        if (is_string($value) && (str_starts_with($value, 'function') || str_contains($value, '=>'))) return $value;
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    private static function encodeFunction($arr) { return json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

    // =================================================================
    // PART 3: THE ULTIMATE HYBRID NLP ENGINE (v20 - THE OMNISCIENT)
    // =================================================================

    private static array $memory = []; 
    private static array $contextStack = []; 
    private static array $grammarRules = [];

    public static function gen(string $humanLanguage): string {
        $jsOutput = "";
        $statements = preg_split('/[;\n]+/', trim($humanLanguage));
        self::initGrammarRules();
        foreach ($statements as $stmt) {
            $stmt = trim(preg_replace('/\s+/', ' ', $stmt));
            if (empty($stmt)) continue;
            $stmt = self::normalizeStatement($stmt);
            $compiledLine = self::resolveStatement($stmt);
            if ($compiledLine !== null) $jsOutput .= "    " . $compiledLine . "\n";
            else {
                $err = "// 🚨 AI Engine failed to parse: {$stmt}";
                $jsOutput .= self::$debug ? "    console.error(".json_encode($err).");\n" : "    {$err}\n";
            }
        }
        while (!empty(self::$contextStack)) {
            $ctx = array_pop(self::$contextStack);
            $jsOutput .= "    " . (in_array($ctx, ['fetch','event','timer','loop']) ? "});\n" : "}\n");
        }
        self::$memory = []; self::$contextStack = [];
        return "(function() {\n" . $jsOutput . "})();";
    }

    private static function normalizeStatement(string $stmt): string {
        $synonyms = ['onchange'=>'when change', 'onclick'=>'when click', 'onsubmit'=>'when submit', 'press'=>'click', 'tap'=>'click', 'hit'=>'click', 'type in'=>'input', 'conceal'=>'hide', 'reveal'=>'show', 'destroy'=>'remove', 'wipe'=>'clear', 'write'=>'set', 'assign'=>'set', 'put'=>'set'];
        foreach ($synonyms as $from => $to) $stmt = preg_replace('/\b' . preg_quote($from, '/') . '\b/i', $to, $stmt);
        return $stmt;
    }

    private static function initGrammarRules(): void {
        if (!empty(self::$grammarRules)) return;
        
        $action = '(?:update|set|change|make|give|apply|assign|put|write|force)';
        $target = '(?:to|is|=|with|as|into|inside)';
        $event = '(click|change|input|hover|leave|mouse out|dabble click|double click|dblclick|submit|focus|blur|resize|scroll|load|keyup|keydown|drag|drop|swipe)';
        $propRegex = '(text|html|inner|content|value|val|color|bg|background|width|height|opacity|src|href|style|disabled|checked|display|margin|padding|border|radius|shadow|position|top|left|right|bottom)';

        $rules = [
            ['pattern' => '/^prevent\s+(?:default|action|reload)$/i', 'handler' => 'handlePreventDefault', 'priority' => 2],
            ['pattern' => '/^(.*?)\s+(?:and|also|,\s*)\s+(.*)$/i', 'handler' => 'handleChaining', 'priority' => 5],
            ['pattern' => '/^(?:show\s+)?(log|print|warn|error|alert)\s+(.+)$/i', 'handler' => 'handleUtils', 'priority' => 10],
            ['pattern' => '/^(go to|redirect|navigate to|jump to)\s+(.+)$/i', 'handler' => 'handleRedirect', 'priority' => 10],
            ['pattern' => '/^confirm\s+(.*)$/i', 'handler' => 'handleConfirm', 'priority' => 10],
            ['pattern' => '/^scroll\s+(?:to|until)\s+(.*)$/i', 'handler' => 'handleScroll', 'priority' => 11],
            ['pattern' => '/^(?:send\s+)?(get|post|put|delete|patch|fetch)(?:\s+request)?\s+(?:to\s+)?([\'"].*?[\'"]|\S+)(?:\s+(?:with|body|using|data)\s+(.+?))?(?:\s+then\s+(.*))?$/i', 'handler' => 'handleApiRequest', 'priority' => 20],
            ['pattern' => '/^(?:when|if|once)\s+(?:document|page|window|dom)\s+(?:ready|loaded)(?:\s+then\s+(.*))?$/i', 'handler' => 'handleDocReady', 'priority' => 25],
            ['pattern' => '/^(?:trigger|fire|emit)\s+([\'"]?[\w\-]+[\'"]?)\s+(?:on|at|for)\s+(.+)$/i', 'handler' => 'handleTriggerEvent', 'priority' => 26],
            ['pattern' => '/^(?:when|if|on)?\s*'.$event.'\s+(?:on|in|to|for)?\s*(.+?)(?:\s+(?:value|val|text|html))?(?:\s+then\s+(.*))?$/i', 'handler' => 'handleEvents', 'priority' => 30],
            ['pattern' => '/^(if|unless)\s+(.*?)(?:\s+then\s+(.*))?$/i', 'handler' => 'handleConditions', 'priority' => 35],
            ['pattern' => '/^(wait|delay|pause)\s+([\d\.]+)\s*(s|sec|ms)(?:\s+then\s+(.*))?$/i', 'handler' => 'handleTimeout', 'priority' => 40],
            ['pattern' => '/^(repeat|loop|interval)\s+every\s+([\d\.]+)\s*(s|sec|ms)(?:\s+then\s+(.*))?$/i', 'handler' => 'handleInterval', 'priority' => 41],
            ['pattern' => '/^wait\s+until\s+(.*?)\s+then\s+(.*)$/i', 'handler' => 'handlePolling', 'priority' => 42],
            ['pattern' => '/^(?:for each|every|foreach)\s+(.+?)(?:\s+as\s+(\w+))?(?:\s+then\s+(.*))?$/i', 'handler' => 'handleLoops', 'priority' => 45],
            ['pattern' => '/^'.$action.'?\s*(?:the\s+)?'.$propRegex.'\s*(?:of|in|on|from)?\s+(.+?)\s+'.$target.'\s+(.+)$/i', 'handler' => 'handleDomPropertyUpdate', 'priority' => 50],
            ['pattern' => '/^'.$propRegex.'\s*'.$target.'\s*(.+)$/i', 'handler' => 'handleImplicitDomUpdate', 'priority' => 55],
            ['pattern' => '/^(add|remove|toggle|switch)\s+(?:class\s+)?([\w\-\s]+)\s+(?:to|on|from|of)\s+(.*)$/i', 'handler' => 'handleClasses', 'priority' => 60],
            ['pattern' => '/^(hide|show|toggle|remove|delete|empty|clear)\s+(.+)$/i', 'handler' => 'handleDomEffects', 'priority' => 61],
            ['pattern' => '/^(highlight|blink|shake|pulse|slide|zoom|fade)\s+(.*)$/i', 'handler' => 'handleDomAnimations', 'priority' => 62],
            ['pattern' => '/^(let|var|const|remember|store)\s+(\w+)\s*(?:as|=|is|to)\s*(.+)$/i', 'handler' => 'handleVariables', 'priority' => 70],
            ['pattern' => '/^(increase|decrease|add|subtract)\s+(\w+|\S+)\s*(?:by|with)?\s*(.+)$/i', 'handler' => 'handleMathOp', 'priority' => 71],
            ['pattern' => '/^get\s+(text|html|value|val|property)\s+of\s+(.*?)\s+(?:save|store|keep)\s+as\s+(\w+)$/i', 'handler' => 'handleExtraction', 'priority' => 75],
            ['pattern' => '/^(?:vibrate|share|copy|fullscreen|pwa|install)\s+(.*)$/i', 'handler' => 'handleHardwareSystem', 'priority' => 80],
            ['pattern' => '/^(play|pause|stop|mute|unmute)\s+(?:video|audio|media)(?:\s+in)?\s+(.*)$/i', 'handler' => 'handleMediaControl', 'priority' => 85],
            ['pattern' => '/^(submit|clear|reset|validate|check)\s+(?:form)?\s+(.*)$/i', 'handler' => 'handleFormAction', 'priority' => 90],
            ['pattern' => '/^(?:save|set)\s+(?:to\s+)?(local|session|cookie)\s+(?:storage\s+)?key\s+([\'"]?[\w\-]+[\'"]?)\s+(?:as|to|=)\s+(.+)$/i', 'handler' => 'handleStorageSet', 'priority' => 95],
            ['pattern' => '/^get\s+(local|session|cookie)\s+(?:storage\s+)?key\s+([\'"]?[\w\-]+[\'"]?)\s+(?:save|store)\s+as\s+(\w+)$/i', 'handler' => 'handleStorageGet', 'priority' => 96],
            ['pattern' => '/^(.*)$/i', 'handler' => 'handleFuzzyIntent', 'priority' => 100],
        ];
        usort($rules, fn($a, $b) => $a['priority'] <=> $b['priority']);
        self::$grammarRules = $rules;
    }

    private static function resolveStatement(string $stmt): ?string {
        if (preg_match('/^(end|done|finish|close)$/i', $stmt)) {
            if (empty(self::$contextStack)) return null;
            $ctx = array_pop(self::$contextStack);
            return (in_array($ctx, ['fetch','event','timer','loop']) ? "});" : "}");
        }
        if (preg_match('/^(else|otherwise)$/i', $stmt)) return "} else {";
        foreach (self::$grammarRules as $rule) { if (preg_match($rule['pattern'], $stmt, $m)) return self::{$rule['handler']}($m); }
        return null;
    }

    // --- NLP HANDLERS ---
    private static function handlePreventDefault($m) { return "if(typeof e !== 'undefined') e.preventDefault();"; }
    private static function handleChaining($m) { $p1 = self::resolveStatement($m[1]); $p2 = self::resolveStatement($m[2]); return ($p1 && $p2) ? "{$p1} {$p2}" : null; }
    private static function handleUtils($m) { $type = (strtolower($m[1])==='alert')?'alert':'console.'.(in_array(strtolower($m[1]),['warn','error'])?strtolower($m[1]):'log'); return "{$type}(".self::evaluateMathAndLogic($m[2]).");"; }
    private static function handleRedirect($m) { return "window.location.href = ".self::parseValue($m[2]).";"; }
    private static function handleConfirm($m) { return "confirm(".self::evaluateMathAndLogic($m[1]).");"; }
    private static function handleScroll($m) {
        $t = strtolower(trim($m[1]));
        if ($t==='top') return "window.scrollTo({top:0, behavior:'smooth'});";
        if ($t==='bottom') return "window.scrollTo({top:document.body.scrollHeight, behavior:'smooth'});";
        return "document.querySelector('".self::mapSelector($t)."')?.scrollIntoView({behavior:'smooth'});";
    }
    private static function handleApiRequest($m) {
        $method = strtoupper($m[1]); $url = self::parseValue($m[2]); $payload = !empty($m[3]) ? self::parseValue($m[3]) : 'null';
        $js = "fetch($url, {method:'$method', body:$payload}).then(r=>r.json()).then(data=>{ ";
        if (!empty($m[4])) return $js . self::resolveStatement($m[4]) . " });";
        self::$contextStack[] = 'fetch'; return $js;
    }
    private static function handleDocReady($m) { $js = "document.addEventListener('DOMContentLoaded', ()=>{ "; if(!empty($m[1])) return $js . self::resolveStatement($m[1]) . " });"; self::$contextStack[]='event'; return $js; }
    private static function handleTriggerEvent($m) { return "document.querySelector('".self::mapSelector($m[2])."')?.dispatchEvent(new Event(".self::parseValue($m[1]).", {bubbles:true}));"; }
    private static function handleEvents($m) {
        $event = self::mapEvent($m[1]); $selector = self::mapSelector($m[2]);
        $js = "document.querySelectorAll('$selector').forEach(el => el.addEventListener('$event', async e => { let it=el; let value=el.value||el.innerText; let text=el.innerText; ";
        self::$contextStack[] = 'event';
        if (!empty($m[3])) {
            $res = $js . self::resolveStatement($m[3]) . " }));";
            array_pop(self::$contextStack);
            return $res;
        }
        return $js;
    }
    private static function handleConditions($m) {
        $cond = self::evaluateMathAndLogic($m[2]); 
        $js = (strtolower($m[1])==='unless'?"if(!($cond))":"if($cond)")." { ";
        self::$contextStack[] = 'if';
        if (!empty($m[3])) {
            $thenPart = $m[3];
            if (preg_match('/^(.*?)\s+else\s+(.*)$/i', $thenPart, $elseM)) {
                $res = $js . self::resolveStatement($elseM[1]) . " } else { " . self::resolveStatement($elseM[2]) . " }";
            } else {
                $res = $js . self::resolveStatement($thenPart) . " }";
            }
            array_pop(self::$contextStack);
            return $res;
        }
        return $js;
    }
    private static function handleTimeout($m) {
        $time = (float)$m[2] * (strtolower($m[3])==='ms'?1:1000); $js = "setTimeout(()=>{ ";
        self::$contextStack[] = 'timer';
        if(!empty($m[4])) {
            $res = $js . self::resolveStatement($m[4]) . " }, $time);";
            array_pop(self::$contextStack);
            return $res;
        }
        return $js;
    }
    private static function handleInterval($m) {
        $time = (float)$m[2] * (strtolower($m[3])==='ms'?1:1000); $js = "setInterval(()=>{ ";
        self::$contextStack[] = 'loop';
        if(!empty($m[4])) {
            $res = $js . self::resolveStatement($m[4]) . " }, $time);";
            array_pop(self::$contextStack);
            return $res;
        }
        return $js;
    }
    private static function handlePolling($m) { return "let _poll = setInterval(() => { if(".self::evaluateMathAndLogic($m[1]).") { clearInterval(_poll); ".self::resolveStatement($m[2])." } }, 50);"; }
    private static function handleLoops($m) {
        $sel = self::mapSelector($m[1]); $v = $m[2] ?? 'item'; $js = "document.querySelectorAll('$sel').forEach($v => { let it=$v; let value=it.value||it.innerText; ";
        self::$contextStack[] = 'loop';
        if(!empty($m[3])) {
            $res = $js . self::resolveStatement($m[3]) . " });";
            array_pop(self::$contextStack);
            return $res;
        }
        return $js;
    }
    private static function handleDomPropertyUpdate($m) {
        $sel = self::mapSelector($m[2]); $val = self::evaluateMathAndLogic($m[3]); $prop = self::mapDomProperty($m[1]);
        return "document.querySelectorAll('$sel').forEach(el => { ".str_replace('{VALUE_PLACEHOLDER}', $val, $prop)."; });";
    }
    private static function handleImplicitDomUpdate($m) {
        $prop = self::mapDomProperty($m[1]); $val = self::evaluateMathAndLogic($m[2]);
        $target = in_array('event', self::$contextStack) || in_array('loop', self::$contextStack) ? 'el' : 'document.body';
        return str_replace('el.', "$target.", str_replace('{VALUE_PLACEHOLDER}', $val, $prop)) . ";";
    }
    private static function handleClasses($m) {
        $op = strtolower($m[1]); if($op==='switch')$op='toggle'; $classes = explode(' ', trim($m[2])); $sel = self::mapSelector($m[3]);
        $js = implode('; ', array_map(fn($c) => "el.classList.$op('$c')", $classes));
        return "document.querySelectorAll('$sel').forEach(el => { $js; });";
    }
    private static function handleDomEffects($m) {
        $action = strtolower($m[1]); $sel = self::mapSelector($m[2]);
        $map = ['hide'=>"el.style.display='none'", 'show'=>"el.style.display=''", 'toggle'=>"el.style.display=(el.style.display==='none'?'':'none')", 'remove'=>"el.remove()", 'delete'=>"el.remove()", 'empty'=>"el.innerHTML=''", 'clear'=>"el.innerHTML=''; if(el.value!==undefined)el.value=''"];
        return "document.querySelectorAll('$sel').forEach(el => { ".($map[$action]??"")."; });";
    }
    private static function handleDomAnimations($m) {
        $effect = strtolower($m[1]); 
        $selRaw = trim($m[2]);
        $sel = self::mapSelector(preg_replace('/^(in|at|on|to)\s+/i', '', $selRaw));
        $map = [
            'highlight' => "el.style.background='yellow';setTimeout(()=>el.style.background='',800)", 
            'shake'     => "el.animate([{transform:'translateX(-5px)'},{transform:'translateX(5px)'}],{duration:100,iterations:3})", 
            'pulse'     => "el.animate([{transform:'scale(1)'},{transform:'scale(1.05)'},{transform:'scale(1)'}],{duration:300})", 
            'slide'     => "el.animate([{transform:'translateY(20px)',opacity:0},{transform:'translateY(0)',opacity:1}],{duration:400})", 
            'zoom'      => "el.animate([{transform:'scale(0.8)',opacity:0},{transform:'scale(1)',opacity:1}],{duration:400})", 
            'fade'      => "el.animate([{opacity:0},{opacity:1}],{duration:400})"
        ];
        return "document.querySelectorAll('$sel').forEach(el => { ".($map[$effect]??"")."; });";
    }
    private static function handleVariables($m) { self::$memory[] = $m[2]; return "let {$m[2]} = ".self::evaluateMathAndLogic($m[3]).";"; }
    private static function handleMathOp($m) {
        $target = $m[2]; $op = in_array(strtolower($m[1]), ['increase','add']) ? '+=' : '-='; $val = self::evaluateMathAndLogic($m[3]);
        if (in_array($target, self::$memory)) return "$target $op $val;";
        return "document.querySelectorAll('".self::mapSelector($target)."').forEach(el => { if(el.value!==undefined) el.value = Number(el.value) $op $val; else el.innerText = Number(el.innerText) $op $val; });";
    }
    private static function handleExtraction($m) {
        $prop = strtolower($m[1]); $sel = self::mapSelector($m[2]); $var = $m[3]; self::$memory[] = $var;
        $jsProp = ['text'=>'innerText', 'html'=>'innerHTML', 'value'=>'value', 'val'=>'value'][$prop] ?? 'value';
        return "let $var = document.querySelector('$sel')?.{$jsProp};";
    }
    private static function handleHardwareSystem($m) {
        $cmd = strtolower(trim($m[0]));
        if (preg_match('/vibrate(?:\s+phone)?(?:\s+for)?\s+(\d+)/i', $cmd, $vm)) return "if(navigator.vibrate) navigator.vibrate({$vm[1]});";
        if (str_contains($cmd, 'share')) return "if(navigator.share) navigator.share({title:document.title, url:window.location.href});";
        if (str_contains($cmd, 'clipboard') || str_contains($cmd, 'copy')) return "navigator.clipboard.writeText(".self::evaluateMathAndLogic(str_replace(['copy','to clipboard','clipboard'],'',$cmd)).");";
        if (str_contains($cmd, 'fullscreen')) return "document.documentElement.requestFullscreen();";
        if (str_contains($cmd, 'install')) return "if(window.deferredPrompt) window.deferredPrompt.prompt();";
        return "// Hardware command: $cmd";
    }
    private static function handleMediaControl($m) {
        $op = strtolower($m[1]); $selRaw = trim($m[2]);
        $sel = self::mapSelector(preg_replace('/^(in|at|on|to)\s+/i', '', $selRaw));
        $js = match($op) { 'play'=>"el.play()", 'pause'=>"el.pause()", 'stop'=>"el.pause();el.currentTime=0", 'mute'=>"el.muted=true", 'unmute'=>"el.muted=false" };
        return "document.querySelectorAll('$sel').forEach(el => { if(el.play) $js; });";
    }
    private static function handleFormAction($m) {
        $op = strtolower($m[1]); $sel = self::mapSelector($m[2]);
        return match($op) {
            'submit' => "document.querySelector('$sel')?.submit();",
            'clear', 'reset' => "document.querySelector('$sel')?.reset();",
            'validate' => "APP.validate('$sel');",
            'check' => "APP.check('$sel');"
        };
    }
    private static function handleStorageSet($m) {
        $type = strtolower($m[1]); $key = self::parseValue($m[2]); $val = self::evaluateMathAndLogic($m[3]);
        if ($type==='local') return "localStorage.setItem($key, $val);";
        if ($type==='session') return "sessionStorage.setItem($key, $val);";
        return "document.cookie = $key + '=' + $val + '; path=/';";
    }
    private static function handleStorageGet($m) {
        $type = strtolower($m[1]); $key = self::parseValue($m[2]); $var = $m[3]; self::$memory[] = $var;
        if ($type==='local') return "let $var = localStorage.getItem($key);";
        if ($type==='session') return "let $var = sessionStorage.getItem($key);";
        return "let $var = document.cookie.split('; ').find(row => row.startsWith($key + '='))?.split('=')[1];";
    }
    private static function handleFuzzyIntent($m) {
        $s = strtolower($m[1]);
        if (str_contains($s, 'toast')) return self::appToast(trim(str_replace('toast','',$s)));
        if (str_contains($s, 'modal')) return self::appModal(trim(str_replace(['modal','open','show'],'',$s)));
        if (str_contains($s, 'reload') || str_contains($s, 'refresh')) return "window.location.reload();";
        if (str_contains($s, 'go back')) return "window.history.back();";
        if (str_contains($s, 'go forward')) return "window.history.forward();";
        if (str_contains($s, 'vibrate')) return "if(navigator.vibrate) navigator.vibrate(200);";
        if (str_contains($s, 'copy')) return "navigator.clipboard.writeText(window.location.href);";
        return "// AI Fuzzy Intent: $s";
    }

    private static function evaluateMathAndLogic(string $expr): string {
        $expr = trim($expr); if ($expr==='') return "''";
        
        $placeholders = [];
        // Handle "property of selector"
        $expr = preg_replace_callback('/(text|html|inner|content|value|val)\s+of\s+([#\.\[][\w\-\[\]\="]+)/i', function($m) use (&$placeholders) {
            $prop = strtolower($m[1]);
            $sel = $m[2];
            $jsProp = ['text'=>'innerText', 'html'=>'innerHTML', 'inner'=>'innerHTML', 'content'=>'innerText', 'value'=>'value', 'val'=>'value'][$prop];
            $id = "__PHJS_VAL_" . count($placeholders) . "__";
            $placeholders[$id] = "document.querySelector('$sel')?.{$jsProp}";
            return $id;
        }, $expr);

        $operators = [' plus '=>' + ', ' minus '=>' - ', ' times '=>' * ', ' divided by '=>' / ', ' is greater than '=>' > ', ' is less than '=>' < ', ' is '=>' == ', ' equals '=>' == ', ' and '=>' && ', ' or '=>' || '];
        foreach ($operators as $h => $j) $expr = str_ireplace($h, $j, $expr);
        
        $expr = preg_replace_callback('/(__PHJS_VAL_\d+__|[#\.\[][\w\-\[\]\="]+|[a-zA-Z_\$][\w\$]*(\.[\w\$]+)*|[\'"].*?[\'"]|\d+)/', function($m) use ($placeholders) {
            if (isset($placeholders[$m[1]])) return $placeholders[$m[1]];
            return self::parseValue($m[1]);
        }, $expr);
        
        return $expr;
    }

    private static function parseValue(string $v): string {
        $v = trim($v);
        if (in_array(strtolower($v), ['it','this','me','current'])) return 'it';
        if (in_array(strtolower($v), ['true','false','null','undefined'])) return strtolower($v);
        if (preg_match('/^[a-zA-Z_\$][\w\$]*(\.[a-zA-Z_\$][\w\$]*)+$/', $v)) return $v;
        if (is_numeric($v) || preg_match('/^[\'"].*[\'"]$/', $v) || in_array($v, self::$memory)) return $v;
        if (preg_match('/^[#\.\[]/', $v)) return "(document.querySelector('$v')?.value || document.querySelector('$v')?.innerText || '')";
        return "'".addslashes($v)."'";
    }

    private static function mapSelector(string $s): string {
        $s = trim($s); if (in_array(strtolower($s), ['it','this'])) return 'it';
        if (preg_match('/^all\s+(.*)$/i', $s, $m)) return $m[1];
        if (preg_match('/^(parent|next|prev)\s+of\s+(.+)$/i', $s, $m)) {
            $base = self::mapSelector($m[2]);
            return match(strtolower($m[1])) { 'parent'=>"document.querySelector('$base')?.parentElement", 'next'=>"document.querySelector('$base')?.nextElementSibling", 'prev'=>"document.querySelector('$base')?.previousElementSibling" };
        }
        return $s;
    }

    private static function mapDomProperty(string $p): string {
        $p = strtolower(trim($p));
        $map = [
            'text'=>"el.innerText = {VALUE_PLACEHOLDER}", 
            'html'=>"el.innerHTML = {VALUE_PLACEHOLDER}", 
            'inner'=>"el.innerHTML = {VALUE_PLACEHOLDER}",
            'content'=>"el.innerText = {VALUE_PLACEHOLDER}",
            'val'=>"el.value = {VALUE_PLACEHOLDER}", 
            'value'=>"el.value = {VALUE_PLACEHOLDER}", 
            'color'=>"el.style.color = {VALUE_PLACEHOLDER}", 
            'bg'=>"el.style.backgroundColor = {VALUE_PLACEHOLDER}", 
            'width'=>"el.style.width = {VALUE_PLACEHOLDER}", 
            'height'=>"el.style.height = {VALUE_PLACEHOLDER}", 
            'opacity'=>"el.style.opacity = {VALUE_PLACEHOLDER}", 
            'display'=>"el.style.display = {VALUE_PLACEHOLDER}", 
            'src'=>"el.src = {VALUE_PLACEHOLDER}", 
            'href'=>"el.href = {VALUE_PLACEHOLDER}"
        ];
        return $map[$p] ?? "el.$p = {VALUE_PLACEHOLDER}";
    }

    private static function mapEvent(string $e): string {
        $map = ['hover'=>'mouseenter', 'leave'=>'mouseleave', 'mouse out'=>'mouseleave', 'press'=>'click', 'tap'=>'click', 'double click'=>'dblclick'];
        return $map[strtolower(trim($e))] ?? trim($e);
    }
}

/**
 * Helper class for method chaining (Titanium Upgrade)
 */
class PHJS_Chain {
    private array $buffer = [];
    public function __call($name, $args) {
        $res = forward_static_call_array(['PHJS', $name], $args);
        if (is_string($res)) $this->buffer[] = $res;
        return $this;
    }
    public function render(): string { return implode("\n", $this->buffer); }
    public function __toString(): string { return $this->render(); }
}

function tjs($dsl) { return PHJS::parse($dsl); }
function phjs($human) { return PHJS::gen($human); }
?>