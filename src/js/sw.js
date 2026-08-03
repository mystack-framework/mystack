const CACHE_NAME = 'phjs-titanium-auto-v23';
const PRECACHE_ASSETS = [];

self.addEventListener('install', e => {
    self.skipWaiting();
    e.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return Promise.allSettled(PRECACHE_ASSETS.map(asset => cache.add(asset)));
        })
    );
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys => Promise.all(
            keys.map(k => k !== CACHE_NAME && caches.delete(k))
        ))
    );
    self.clients.claim();
});

// Background Sync Support (Communication with phjs.js)
self.addEventListener('sync', e => {
    if (e.tag === 'phjs-sync') {
        e.waitUntil(self.clients.matchAll().then(clients => {
            clients.forEach(c => c.postMessage({ type: 'SYNC_NOW', origin: 'BackgroundSyncAPI' }));
        }));
    }
});

// Optional Web Push receiver. It is inert until a valid push subscription
// exists, so the existing cache/offline behaviour is unchanged.
self.addEventListener('push', e => {
    let data = {};
    try { data = e.data ? e.data.json() : {}; } catch (error) { data = { message: e.data ? e.data.text() : '' }; }
    const title = data.title || data.project || 'Notification';
    const options = {
        body: String(data.message || data.body || ''),
        tag: data.id || undefined,
        data: { url: data.url || data.click || '/' },
        icon: data.icon || undefined,
        badge: data.badge || undefined,
        renotify: true
    };
    e.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', e => {
    e.notification.close();
    const target = e.notification.data && e.notification.data.url;
    if (!target) return;
    e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
        const existing = list.find(client => client.url === target && 'focus' in client);
        if (existing) return existing.focus();
        return clients.openWindow(target);
    }));
});

/* ==========================================================
   ULTIMATE AUTO-SYNC & FETCH ENGINE
   Caches EVERYTHING: Pages, APIs, Images, Videos, Docs, etc.
========================================================== */
self.addEventListener('fetch', e => {
    // Only GET requests can be cached
    if (e.request.method !== 'GET') return;

    const url = new URL(e.request.url);

    // Cross-origin requests have their own CORS/cache policy. Let the browser
    // handle them directly instead of turning failures into FetchEvent errors.
    if (url.origin !== self.location.origin) return;

    // Sensitive pages and private tracker data must not enter Cache Storage.
    if (/(?:^|\/)(?:login|logout|admin\/dashboard|teacher\/dashboard|student\/dashboard|parent\/dashboard|_phjs\/network-info)(?:\/|$)/.test(url.pathname)) {
        return;
    }

    // Dynamic API data must remain fresh. Real files such as .json and
    // .webmanifest still use the normal one-network-load asset cache.
    const accept = e.request.headers.get('accept') || '';
    const hasFileExtension = /\/[^/]+\.[a-z0-9]{1,16}$/i.test(url.pathname);
    if (/(?:^|\/)api(?:\/|$)/.test(url.pathname) ||
        (accept.includes('application/json') && !hasFileExtension) ||
        e.request.headers.has('authorization')) {
        return;
    }

    // Handle Range Requests for Media (Video/Audio Backgrounds)
    if (e.request.headers.get('range')) {
        e.respondWith(handleRangeRequest(e.request));
        return;
    }

    // 1. Navigation Strategy (HTML Pages): Cache-First, network only on first load.
    if (e.request.mode === 'navigate') {
        e.respondWith(cacheFirst(e.request).catch(async () => {
            const scopePage = await caches.match(self.registration.scope);
            return scopePage || generateOfflineResponse();
        }));
        return;
    }

    // 2. Every other safe same-origin file: cache-first, with no background
    // refetch. The cache write is awaited so it cannot be lost if the worker
    // is stopped immediately after returning the response.
    e.respondWith(cacheFirst(e.request).catch(() => Response.error()));
});

async function putInCache(request, response) {
    const cacheControl = response?.headers?.get('Cache-Control') || '';
    if (!response || !response.ok || response.status !== 200 || /\bno-store\b/i.test(cacheControl)) {
        return;
    }

    try {
        const cache = await caches.open(CACHE_NAME);
        await cache.put(request, response.clone());
    } catch (e) {
        // Unsupported/Vary:* responses still reach the page normally.
    }
}

async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) return cached;

    const response = await fetch(request);
    await putInCache(request, response);
    return response;
}

/**
 * Partial Content Handler (Crucial for Video/Audio backgrounds)
 */
async function handleRangeRequest(request) {
    const cache = await caches.open(CACHE_NAME);
    const fullHeaders = new Headers(request.headers);
    fullHeaders.delete('range');
    fullHeaders.delete('if-range');

    const fullRequest = new Request(request.url, {
        method: 'GET',
        headers: fullHeaders,
        mode: request.mode,
        credentials: request.credentials,
        redirect: request.redirect,
        referrer: request.referrer,
        referrerPolicy: request.referrerPolicy,
        integrity: request.integrity
    });

    let fullResponse = await cache.match(fullRequest, { ignoreVary: true });
    if (!fullResponse) {
        fullResponse = await fetch(fullRequest);
        if (!fullResponse.ok || fullResponse.status !== 200) return fetch(request);
        await putInCache(fullRequest, fullResponse);
    }

    try {
        const range = request.headers.get('range') || '';
        const match = /^bytes=(\d*)-(\d*)$/i.exec(range.trim());
        const buffer = await fullResponse.arrayBuffer();
        const size = buffer.byteLength;
        if (!match || size === 0) return fetch(request);

        let start;
        let end;
        if (match[1] === '') {
            const suffixLength = Number(match[2]);
            if (!Number.isFinite(suffixLength) || suffixLength <= 0) {
                return new Response(null, {
                    status: 416,
                    headers: { 'Content-Range': `bytes */${size}` }
                });
            }
            start = Math.max(size - suffixLength, 0);
            end = size - 1;
        } else {
            start = Number(match[1]);
            end = match[2] === '' ? size - 1 : Math.min(Number(match[2]), size - 1);
        }

        if (!Number.isFinite(start) || !Number.isFinite(end) || start < 0 || start > end || start >= size) {
            return new Response(null, {
                status: 416,
                headers: { 'Content-Range': `bytes */${size}` }
            });
        }

        const headers = new Headers(fullResponse.headers);
        headers.delete('Content-Encoding');
        headers.set('Content-Range', `bytes ${start}-${end}/${size}`);
        headers.set('Content-Length', String(end - start + 1));
        headers.set('Accept-Ranges', 'bytes');

        return new Response(buffer.slice(start, end + 1), {
            status: 206,
            statusText: 'Partial Content',
            headers
        });
    } catch (e) {
        return fetch(request);
    }
}

/**
 * Titanium OS Offline Fallback
 */
function generateOfflineResponse() {
    return new Response(`
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8"><title>Offline | PHJS</title>
        <style>
            body { font-family: sans-serif; background: #0b0e14; color: #fff; height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; }
            h1 { color: #3b82f6; font-size: 2rem; margin: 0; }
            p { color: #94a3b8; margin-top: 10px; }
            .btn { margin-top: 20px; padding: 10px 30px; background: #3b82f6; color: #fff; border: none; border-radius: 5px; cursor: pointer; }
        </style>
    </head>
    <body>
        <h1>Connection Lost</h1>
        <p>Operating in autonomous mode. Changes will sync when online.</p>
        <button class="btn" onclick="location.reload()">Retry Connection</button>
    </body>
    </html>`, { headers: { 'Content-Type': 'text/html' } });
}
