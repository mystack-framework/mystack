const CACHE_NAME = 'phjs-titanium-auto-v17';
const PRECACHE_ASSETS = ['/'];

self.addEventListener('install', e => {
    self.skipWaiting();
    e.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(PRECACHE_ASSETS).catch(() => {});
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

/* ==========================================================
   ULTIMATE AUTO-SYNC & FETCH ENGINE
   Caches EVERYTHING: Pages, APIs, Images, Videos, Docs, etc.
========================================================== */
self.addEventListener('fetch', e => {
    // Only GET requests can be cached
    if (e.request.method !== 'GET') return;

    const url = new URL(e.request.url);

    // Handle Range Requests for Media (Video/Audio Backgrounds)
    if (e.request.headers.get('range')) {
        e.respondWith(handleRangeRequest(e.request));
        return;
    }

    // 1. Navigation Strategy (HTML Pages): Network-First
    if (e.request.mode === 'navigate') {
        e.respondWith(
            fetch(e.request)
                .then(res => {
                    const copy = res.clone();
                    caches.open(CACHE_NAME).then(c => c.put(e.request, copy));
                    return res;
                })
                .catch(async () => {
                    const cached = await caches.match(e.request);
                    return cached || caches.match('/') || generateOfflineResponse();
                })
        );
        return;
    }

    // 2. Global Asset & API Strategy: Stale-While-Revalidate (Auto-Cache Everything)
    e.respondWith(
        caches.match(e.request).then(cached => {
            const networkFetch = fetch(e.request)
                .then(res => {
                    // Cache if status is 200 (Normal) or 0 (Opaque/Cross-Origin Assets)
                    if (res && (res.status === 200 || res.status === 0)) {
                        const copy = res.clone();
                        caches.open(CACHE_NAME).then(c => c.put(e.request, copy));
                    }
                    return res;
                })
                .catch(() => {
                    // Fallback for failed API/JSON requests
                    if (!cached && e.request.headers.get('accept')?.includes('json')) {
                        return new Response(JSON.stringify({ error: 'Offline', status: 408 }), {
                            status: 408,
                            headers: { 'Content-Type': 'application/json' }
                        });
                    }
                    return cached || Response.error(); // Return cached if available on network fail, else error
                });

            // Speed: Return cached version immediately, update in background
            // Reliability: If no cache, wait for network
            return cached || networkFetch;
        })
    );
});

/**
 * Partial Content Handler (Crucial for Video/Audio backgrounds)
 */
async function handleRangeRequest(request) {
    const cache = await caches.open(CACHE_NAME);
    const cachedResponse = await cache.match(request);
    
    if (cachedResponse) {
        try {
            const range = request.headers.get('range');
            const buffer = await cachedResponse.arrayBuffer();
            const start = Number(range.replace(/\D/g, ''));
            const end = buffer.byteLength - 1;
            
            return new Response(buffer.slice(start), {
                status: 206,
                statusText: 'Partial Content',
                headers: {
                    'Content-Range': `bytes ${start}-${end}/${buffer.byteLength}`,
                    'Accept-Ranges': 'bytes',
                    'Content-Type': cachedResponse.headers.get('Content-Type')
                }
            });
        } catch (e) { return fetch(request); }
    }
    return fetch(request);
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
        <h1>🛰️ Connection Lost</h1>
        <p>Operating in autonomous mode. Changes will sync when online.</p>
        <button class="btn" onclick="location.reload()">Retry Connection</button>
    </body>
    </html>`, { headers: { 'Content-Type': 'text/html' } });
}