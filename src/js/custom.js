(async function generateMaxLevelFP() {

    const FP_KEY = pre + 'hash';

    /* ===============================
       SHA-256 Helper
    =============================== */
    async function sha256(str) {

        // Native WebCrypto (Secure Context)
        if (window.crypto && window.crypto.subtle) {
            const data = new TextEncoder().encode(str);
            const hash = await crypto.subtle.digest('SHA-256', data);
            return Array.from(new Uint8Array(hash))
                .map(b => b.toString(16).padStart(2, '0'))
                .join('');
        }

        // Fallback (Pure JS SHA256)
        function rightRotate(value, amount) {
            return (value >>> amount) | (value << (32 - amount));
        }

        const mathPow = Math.pow;
        const maxWord = mathPow(2, 32);
        let result = '';

        let words = [];
        const strBitLength = str.length * 8;

        let hash = sha256.h = sha256.h || [];
        let k = sha256.k = sha256.k || [];
        let primeCounter = k.length;

        if (!primeCounter) {
            let isPrime = {};
            let candidate = 2;
            while (primeCounter < 64) {
                if (!isPrime[candidate]) {
                    for (let i = 0; i < 313; i += candidate) {
                        isPrime[i] = candidate;
                    }
                    hash[primeCounter] = (mathPow(candidate, .5) * maxWord) | 0;
                    k[primeCounter++] = (mathPow(candidate, 1 / 3) * maxWord) | 0;
                }
                candidate++;
            }
        }

        str += '\x80';
        while (str.length % 64 - 56) str += '\x00';

        for (let i = 0; i < str.length; i++) {
            const j = str.charCodeAt(i);
            words[i >> 2] |= j << ((3 - i) % 4) * 8;
        }

        words[words.length] = ((strBitLength / maxWord) | 0);
        words[words.length] = (strBitLength);

        for (let j = 0; j < words.length;) {

            let w = words.slice(j, j += 16);
            let oldHash = hash.slice(0);

            for (let i = 0; i < 64; i++) {

                let w15 = w[i - 15], w2 = w[i - 2];

                let a = hash[0], e = hash[4];
                let temp1 = hash[7]
                    + (rightRotate(e, 6) ^ rightRotate(e, 11) ^ rightRotate(e, 25))
                    + ((e & hash[5]) ^ ((~e) & hash[6]))
                    + k[i]
                    + (w[i] = (i < 16) ? w[i] :
                        (w[i - 16]
                            + (rightRotate(w15, 7) ^ rightRotate(w15, 18) ^ (w15 >>> 3))
                            + w[i - 7]
                            + (rightRotate(w2, 17) ^ rightRotate(w2, 19) ^ (w2 >>> 10))
                        ) | 0
                    );

                let temp2 = (rightRotate(a, 2) ^ rightRotate(a, 13) ^ rightRotate(a, 22))
                    + ((a & hash[1]) ^ (a & hash[2]) ^ (hash[1] & hash[2]));

                hash = [
                    (temp1 + temp2) | 0
                ].concat(hash);

                hash[4] = (hash[4] + temp1) | 0;
                hash.pop();
            }

            for (let i = 0; i < 8; i++) {
                hash[i] = (hash[i] + oldHash[i]) | 0;
            }
        }

        for (let i = 0; i < 8; i++) {
            for (let j = 3; j + 1; j--) {
                let b = (hash[i] >> (j * 8)) & 255;
                result += ((b < 16) ? 0 : '') + b.toString(16);
            }
        }

        return result;
    }

    /* ===============================
       IndexedDB Helper
    =============================== */
    async function openDB() {
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(FP_KEY, 1);

            req.onupgradeneeded = e => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains('fp')) {
                    db.createObjectStore('fp');
                }
            };

            req.onsuccess = e => resolve(e.target.result);
            req.onerror = e => reject(e);
        });
    }

    /* ===============================
       Try Load Cached
    =============================== */
    let masterHash = null;

    // 1. সব জায়গা থেকে চেষ্টা করা
    const sources = [
        () => localStorage.getItem(FP_KEY),
        () => {
            const match = document.cookie.match(new RegExp(FP_KEY + '=([^;]+)'));
            return match ? match[1] : null;
        },
        async () => {
            if (!window.indexedDB) return null;
            try {
                const db = await openDB();
                const tx = db.transaction('fp', 'readonly');
                const store = tx.objectStore('fp');
                return await new Promise(res => {
                    const req = store.get(FP_KEY);
                    req.onsuccess = e => res(e.target.result);
                    req.onerror = () => res(null);
                });
            } catch {
                return null;
            }
        }
    ];

    for (const getter of sources) {
        try {
            const value = await getter();
            if (value && /^[0-9a-f]{16}$/i.test(value)) {
                masterHash = value;
                break;  // প্রথম valid টা পেলেই থামো
            }
        } catch (e) { }
    }

    // 2. যদি কোনো জায়গায় পাওয়া যায় → sync করো বাকি জায়গায়
    if (masterHash) {
        try {
            // localStorage sync
            localStorage.setItem(FP_KEY, masterHash);

            // Cookie sync
            const isHTTPS = location.protocol === "https:";
            const secureAttr = isHTTPS ? "; Secure" : "";
            const sameSite = "; SameSite=Lax";
            const pathAttr = "; path=<?php echo PHCO::path(); ?>";
            document.cookie = `${FP_KEY}=${masterHash}${pathAttr}; max-age=315360000${secureAttr}${sameSite}`;

            // IndexedDB sync
            if (window.indexedDB) {
                const db = await openDB();
                const tx = db.transaction('fp', 'readwrite');
                tx.objectStore('fp').put(masterHash, FP_KEY);
                await new Promise(r => { tx.oncomplete = r; });
            }

        } catch { }

        return masterHash;
    }

    /* ===============================
       Collect Signals
    =============================== */

    const signals = {
        ua_full: navigator.userAgent || "",
        ua_data: "no_hints",
        languages: (navigator.languages || [navigator.language]).join('|'),
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "",
        screen_geom: `${screen.width}x${screen.height}x${screen.availLeft}x${screen.availTop}`,
        color_depth: screen.colorDepth + 'x' + screen.pixelDepth,
        concurrency: navigator.hardwareConcurrency || "",
        device_mem: navigator.deviceMemory || "",
        touch_points: navigator.maxTouchPoints || 0,
        vendor: navigator.vendor || "",
        product: navigator.product || "",
        productSub: navigator.productSub || "",
        oscpu: navigator.oscpu || "",
        mime_types: Array.from(navigator.mimeTypes || []).map(m => m.type).sort().join('|'),
        plugins: Array.from(navigator.plugins || []).map(p => p.name).sort().join('|'),
        canvas: "canvas_blocked",
        webgl: "webgl_blocked",
        audio: "audio_blocked",
        css_modern: "css_blocked"
    };

    /* ===============================
       UA High Entropy
    =============================== */
    try {
        if (navigator.userAgentData) {
            const hints = await navigator.userAgentData.getHighEntropyValues([
                "platform", "platformVersion", "architecture",
                "model", "fullVersionList", "mobile", "bitness", "wow64"
            ]);
            signals.ua_data = JSON.stringify(hints);
        }
    } catch { }

    /* ===============================
       Canvas (Safe Fallback)
    =============================== */
    try {
        let canvas, ctx;

        if (typeof OffscreenCanvas !== "undefined") {
            canvas = new OffscreenCanvas(512, 256);
            ctx = canvas.getContext("2d");
        } else {
            canvas = document.createElement("canvas");
            canvas.width = 512;
            canvas.height = 256;
            ctx = canvas.getContext("2d");
        }

        ctx.fillStyle = "#c0ffee";
        ctx.fillRect(0, 0, 512, 256);
        ctx.font = "bold 48px system-ui";
        ctx.fillStyle = "#ff1493";
        ctx.fillText("2030 FP Test", 20, 100);

        let dataUrl;

        if (canvas.convertToBlob) {
            const blob = await canvas.convertToBlob({ type: "image/png" });
            dataUrl = await new Promise(r => {
                const reader = new FileReader();
                reader.onload = () => r(reader.result);
                reader.readAsDataURL(blob);
            });
        } else {
            dataUrl = canvas.toDataURL();
        }

        signals.canvas = await sha256(dataUrl);

    } catch { }

    /* ===============================
       WebGL
    =============================== */
    try {
        const c = document.createElement("canvas");
        const gl =
            c.getContext("webgl2") ||
            c.getContext("webgl") ||
            c.getContext("experimental-webgl");

        if (gl) {
            const exts = gl.getSupportedExtensions() || [];
            const debug = gl.getExtension("WEBGL_debug_renderer_info");

            const info = [
                gl.getParameter(gl.VERSION),
                gl.getParameter(gl.RENDERER),
                gl.getParameter(gl.VENDOR),
                debug ? gl.getParameter(debug.UNMASKED_RENDERER_WEBGL) : "",
                exts.join("|")
            ].join("|");

            signals.webgl = await sha256(info);
        }
    } catch { }

    /* ===============================
       Audio
    =============================== */
    try {
        const AudioCtx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
        if (AudioCtx) {
            const ctx = new AudioCtx(1, 16000, 44100);
            const osc = ctx.createOscillator();
            osc.type = "sawtooth";
            osc.frequency.value = 1000;
            osc.connect(ctx.destination);
            osc.start();

            const buffer = await ctx.startRendering();
            const data = buffer.getChannelData(0).slice(0, 5000);
            signals.audio = await sha256(data.join(","));
        }
    } catch { }

    /* ===============================
       CSS Modern
    =============================== */
    try {
        const cssFlags = [
            CSS.supports("container-type: inline-size"),
            CSS.supports("accent-color: auto"),
            CSS.supports("selector(:has(*))"),
            CSS.supports("color-mix(in lch, red, blue)")
        ].map(v => v ? "1" : "0").join("");

        signals.css_modern = await sha256(cssFlags);
    } catch { }

    /* ===============================
       Stable Final Hash
    =============================== */

    const finalString = [
        signals.ua_full,
        signals.ua_data,
        signals.languages,
        signals.timezone,
        signals.screen_geom,
        signals.color_depth,
        signals.concurrency,
        signals.device_mem,
        signals.touch_points,
        signals.vendor,
        signals.product,
        signals.productSub,
        signals.oscpu,
        signals.mime_types,
        signals.plugins,
        signals.canvas,
        signals.webgl,
        signals.audio,
        signals.css_modern
    ].join('|');

    const fullHash = await sha256(finalString);
    const hash16 = fullHash.slice(0, 8) + fullHash.slice(-8);

    /* ===============================
       Store Persistently
    =============================== */
    try {
        localStorage.setItem(FP_KEY, hash16);
        const isHTTPS = location.protocol === "https:";
        const secureAttr = isHTTPS ? "; Secure" : "";
        const sameSite = "; SameSite=Lax";
        const pathAttr = "; path=<?php echo PHCO::path(); ?>";

        document.cookie = `${FP_KEY}=${hash16}${pathAttr}; max-age=315360000${secureAttr}${sameSite}`;

        if (window.indexedDB) {
            const db = await openDB();
            const tx = db.transaction('fp', 'readwrite');
            tx.objectStore('fp').put(hash16, FP_KEY);
        }
    } catch { }

    return hash16;

})();



// Global toast function
window.toast = (msg, type = 'success') => {
    let wrap = document.getElementById('toast-wrap');

    // যদি wrap খুঁজে না পায়, তবে নতুন তৈরি করে বডিতে যুক্ত করবে
    if (!wrap) {
        wrap = document.createElement('div');
        wrap.id = 'toast-wrap';
        document.body.appendChild(wrap);
    }

    const t = document.createElement('div');
    t.className = `toast ${type} animate__animated animate__fadeInUp animate__faster`;
    t.textContent = msg;
    wrap.appendChild(t);

    setTimeout(() => {
        t.classList.replace('animate__fadeInUp', 'animate__fadeOutDown');
        setTimeout(() => t.remove(), 500);
    }, 2800);
};

// Global modal trigger
window.modal = (config) => {
    window.dispatchEvent(new CustomEvent('modal-ctrl', { detail: config }));
};

document.addEventListener('alpine:init', () => {
    // --- Configuration & State ---
    const config = {
        defaultTarget: '#router-view',
        loadingClass: 'router-loading',
        activeClass: 'router-active', // Active link class
        cacheEnabled: true,
    };

    const cache = new Map();
    let currentController = null; // For aborting fetch

    // --- 1. x-view (The Container) ---
    Alpine.directive('view', (el) => {
        if (!el.id) el.id = 'router-view';
        window.AlpineRouterTarget = el;

        // Handle Browser Back/Forward
        window.addEventListener('popstate', (e) => {
            const url = e.state?.fetchUrl || window.location.pathname;
            navigate(url, el, 'inner', null, false, false);
        });
    });

    // --- 2. x-link (The Navigator) ---
    // modifiers: .outer, .prefetch, .nocache
    Alpine.directive('link', (el, { modifiers, expression }, { cleanup }) => {
        const fetchUrl = el.getAttribute('href');
        const browserUrl = el.getAttribute('data-url') || fetchUrl;
        const targetSelector = expression || (window.AlpineRouterTarget ? `#${window.AlpineRouterTarget.id}` : config.defaultTarget);
        const selectSelector = el.getAttribute('data-select');
        const swapMode = modifiers.includes('outer') ? 'outer' : 'inner';
        const useCache = !modifiers.includes('nocache');

        // Prefetch on hover
        if (modifiers.includes('prefetch') && config.cacheEnabled) {
            el.addEventListener('mouseenter', () => {
                if (!cache.has(fetchUrl)) {
                    fetch(fetchUrl).then(r => r.text()).then(html => cache.set(fetchUrl, html)).catch(() => { });
                }
            });
        }

        // Active Class Logic
        const updateActiveLinks = () => {
            const currentPath = window.location.pathname;
            document.querySelectorAll('[x-link]').forEach(link => {
                const linkHref = link.getAttribute('data-url') || link.getAttribute('href');
                if (linkHref === currentPath) link.classList.add(config.activeClass);
                else link.classList.remove(config.activeClass);
            });
        };
        // Initial check
        updateActiveLinks();
        window.addEventListener('popstate', updateActiveLinks);
        window.addEventListener('router:end', updateActiveLinks);

        // Click Handler
        const handleClick = (e) => {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.defaultPrevented) return;
            e.preventDefault();

            // Push History
            if (browserUrl !== window.location.pathname) {
                window.history.pushState({ path: browserUrl, fetchUrl: fetchUrl }, '', browserUrl);
            }

            navigate(fetchUrl, document.querySelector(targetSelector), swapMode, selectSelector, useCache, true);
        };

        el.addEventListener('click', handleClick);
        cleanup(() => {
            el.removeEventListener('click', handleClick);
            el.removeEventListener('mouseenter', () => { });
            window.removeEventListener('router:end', updateActiveLinks);
        });
    });

    // --- 3. x-spy (Scroll Spy) ---
    Alpine.directive('spy', (el, { expression }, { cleanup }) => {
        const urlToSet = expression;
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && entry.intersectionRatio > 0.5) {
                    if (window.location.pathname !== urlToSet) {
                        window.history.replaceState(history.state, '', urlToSet);
                        const title = el.getAttribute('data-title');
                        if (title) document.title = title;

                        // Active link update for spy
                        document.dispatchEvent(new CustomEvent('router:end'));
                    }
                }
            });
        }, { threshold: 0.6 });

        observer.observe(el);
        cleanup(() => observer.disconnect());
    });

    // --- Core Navigation Logic ---
    async function navigate(url, targetEl, swapMode, selectSelector, useCache, triggerEvents) {
        if (!targetEl) return console.error('Router: Target not found');

        // Trigger Start Event
        if (triggerEvents) document.dispatchEvent(new CustomEvent('router:start', { detail: { url } }));
        targetEl.classList.add(config.loadingClass);

        // Abort previous fetch if active
        if (currentController) currentController.abort();
        currentController = new AbortController();

        try {
            let htmlText;

            // Cache check
            if (config.cacheEnabled && useCache && cache.has(url)) {
                htmlText = cache.get(url);
            } else {
                const response = await fetch(url, { signal: currentController.signal });
                if (!response.ok) throw new Error('Network response was not ok');
                htmlText = await response.text();
                if (config.cacheEnabled && useCache) cache.set(url, htmlText);
            }

            // Parsing
            const parser = new DOMParser();
            const doc = parser.parseFromString(htmlText, 'text/html');

            // Select Content
            let newContent;
            if (selectSelector) {
                const selected = doc.querySelector(selectSelector);
                newContent = selected ? (swapMode === 'outer' ? selected : selected.innerHTML) : null;
            } else {
                const matchId = doc.querySelector(`#${targetEl.id}`);
                newContent = matchId ? (swapMode === 'outer' ? matchId : matchId.innerHTML) : doc.body.innerHTML;
            }

            if (!newContent) throw new Error('Content selector not found in target page');

            // Update Title
            if (doc.title) document.title = doc.title;

            // DOM Swap & Script Execution
            if (swapMode === 'outer' && newContent instanceof Element) {
                const fragment = processScripts(newContent);
                // We need to keep track of the new element to be the new target
                const newTarget = fragment.firstElementChild || fragment;
                targetEl.replaceWith(fragment);

                // Re-assign ID if needed for future navigation
                if (window.AlpineRouterTarget === targetEl) {
                    window.AlpineRouterTarget = document.getElementById(targetEl.id);
                }
            } else {
                targetEl.innerHTML = '';
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = newContent instanceof Element ? newContent.innerHTML : newContent;
                const fragment = processScripts(tempDiv);
                targetEl.appendChild(fragment);
            }

        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Router Error:', error);
                targetEl.innerHTML = `<div class="p-4 text-red-500">Failed to load content.</div>`;
            }
        } finally {
            targetEl.classList.remove(config.loadingClass);
            currentController = null;
            if (triggerEvents) document.dispatchEvent(new CustomEvent('router:end', { detail: { url } }));
        }
    }

    // Script Processor (Essential for Alpine to re-init)
    function processScripts(container) {
        const fragment = document.createDocumentFragment();
        while (container.firstChild) {
            let child = container.firstChild;
            if (child.tagName === 'SCRIPT') {
                const newScript = document.createElement('script');
                // Copy attributes
                Array.from(child.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                // Handle content/src
                if (child.src) newScript.src = child.src;
                else newScript.textContent = child.textContent;

                fragment.appendChild(newScript);
                container.removeChild(child);
            } else {
                fragment.appendChild(child);
            }
        }
        return fragment;
    }

    Alpine.data('app', () => ({
        modalData: { open: false, title: '', desc: '', template: null },

        init() {
            window.addEventListener('modal-ctrl', (e) => {
                this.modalData.open = true;
                this.modalData.template = e.detail.template || null;
                this.modalData.title = e.detail.title || 'নোটিশ';
                this.modalData.desc = e.detail.desc || '';

                if (this.modalData.template) {
                    this.$nextTick(() => {
                        const tpl = document.getElementById(this.modalData.template);
                        const target = document.getElementById('modal-inject');
                        if (tpl && target) {
                            target.innerHTML = tpl.innerHTML;
                        }
                    });
                }
            });
        }
    }));

    // --- PULL TO REFRESH DIRECTIVE ---
    Alpine.directive('ptr', (el, { expression }, { evaluateLater }) => {
        const refreshAction = evaluateLater(expression);
        const content = el.querySelector('.ptr-content');
        const loader = el.querySelector('.ptr-loader');

        // ১. টাচ ডিভাইস ডিটেকশন
        // 'ontouchstart' উইন্ডোতে আছে কিনা অথবা টাচ পয়েন্ট আছে কিনা চেক করে
        const isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

        // --- DESKTOP MODE (MOUSE) ---
        if (!isTouch) {
            // ডেস্কটপে হাইট অটো করে দেওয়া হচ্ছে যাতে পেজ স্ক্রল আটকাবে না
            el.style.height = 'auto';
            el.style.overflowY = 'visible';

            // লোডার লুকিয়ে রাখা
            if (loader) loader.style.display = 'none';

            // ইভেন্ট লিসেনার অ্যাড না করে বের হয়ে যাচ্ছি
            return;
        }

        // --- MOBILE / TABLET MODE (TOUCH) ---

        // মোবাইলে স্ক্রল কন্টেইনার হিসেবে কাজ করবে
        el.style.overflowY = 'auto';
        el.style.overscrollBehaviorY = 'contain';
        el.style.position = 'relative';

        let startY = 0;
        let pullDist = 0;
        let isRefreshing = false;
        const threshold = 60;

        if (!content) return;

        el.addEventListener('touchstart', (e) => {
            if (el.scrollTop <= 1 && !isRefreshing) {
                startY = e.touches[0].clientY;
                content.style.transition = 'none';
            }
        }, { passive: true });

        el.addEventListener('touchmove', (e) => {
            const y = e.touches[0].clientY;
            const dist = y - startY;

            if (el.scrollTop <= 1 && dist > 0 && !isRefreshing) {
                if (e.cancelable && dist > 5) {
                    e.preventDefault();
                    pullDist = dist < 200 ? dist / 2 : 100 + (dist - 200) / 4;

                    content.style.transform = `translateY(${pullDist}px)`;

                    if (loader) {
                        loader.style.opacity = Math.min(pullDist / threshold, 1);
                        loader.style.transform = `scale(${Math.min(pullDist / threshold, 1)})`;
                        const spinner = loader.querySelector('svg') || loader.querySelector('span:first-child');
                        if (spinner) spinner.style.transform = `rotate(${pullDist * 2}deg)`;
                    }
                }
            }
        }, { passive: false });

        const reset = () => {
            isRefreshing = false;
            pullDist = 0;
            content.style.transition = 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
            content.style.transform = 'translateY(0px)';
            if (loader) {
                loader.style.transition = 'opacity 0.3s';
                loader.style.opacity = 0;
                loader.style.transform = 'scale(0.8)';
                const spinner = loader.querySelector('svg') || loader.querySelector('span:first-child');
                if (spinner && spinner.classList) spinner.classList.remove('animate-spin'); // সেফটি চেক
            }
        };

        el.addEventListener('touchend', () => {
            if (!isRefreshing && pullDist > 0) {
                if (pullDist > threshold) {
                    isRefreshing = true;
                    content.style.transition = 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
                    content.style.transform = `translateY(60px)`;

                    if (loader) {
                        const spinner = loader.querySelector('svg') || loader.querySelector('span:first-child');
                        if (spinner && spinner.classList) spinner.classList.add('animate-spin');
                    }

                    refreshAction((done) => {
                        if (done && typeof done.then === 'function') {
                            done.then(() => reset());
                        } else {
                            setTimeout(() => reset(), 500);
                        }
                    });
                } else {
                    reset();
                }
            }
            startY = 0;
            pullDist = 0;
        });
    });

    // Tooltip directive
    Alpine.directive('tooltip', (el, { modifiers, expression }, { evaluateLater }) => {
        const getContent = evaluateLater(expression);
        getContent(content => {
            tippy(el, {
                content: content,
                placement: modifiers[0] || 'top',
                theme: 'dark',
                delay: [100, 50]
            });
        });
    });

    // Touch Gestures
    Alpine.directive('touch', (el, { value, modifiers, expression }, { evaluateLater }) => {
        if (!value) return;

        const gesture = value.toLowerCase().trim();

        const touchOptions = {};
        if (gesture.startsWith('swipe')) {
            touchOptions.swipe = {
                distance: 30,
                velocity: 0.3
            };
        }

        Alpine.nextTick(() => {
            const at = new AnyTouch(el, touchOptions);

            at.on(gesture, (e) => {
                if (['swipeleft', 'swiperight', 'swipeup', 'swipedown'].includes(gesture)) {
                    e.preventDefault?.();
                }

                const evaluate = evaluateLater(expression);
                evaluate((fn) => {
                    try {
                        fn.call(el, e);
                    } catch (err) {
                        // silent fail or log to error service if you have one
                    }
                });
            });
        });
    });
});

// Keep the legacy global entry point on PHJS's single persistent toast layer.
window.toast = (msg, type = 'success', time = 3500) => {
    if (window.APP?.ui?.toast) {
        return window.APP.ui.toast(msg, type, time);
    }

    // Very-early fallback until PHJS is ready; no external stylesheet required.
    const item = document.createElement('div');
    item.setAttribute('role', type === 'error' ? 'alert' : 'status');
    item.textContent = String(msg ?? '');
    item.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:2147483647;max-width:calc(100vw - 24px);padding:12px 20px;border-radius:8px;background:#1d4ed8;color:#fff;box-shadow:0 12px 35px rgba(0,0,0,.32);font:600 14px/1.45 system-ui,sans-serif;';
    (document.body || document.documentElement).appendChild(item);
    setTimeout(() => item.remove(), Math.max(1000, Number(time) || 3500));
    return item;
};
