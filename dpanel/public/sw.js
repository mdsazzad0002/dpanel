/*
 * dPanel service worker.
 *
 * The panel serves every page with no-store and carries a session token in
 * its URLs, so pages and API responses are never cached here — they always go
 * to the network. Only static, non-sensitive files are cached: the hashed
 * Vite build output (immutable), icons and the icon font. When a page navigation
 * fails because the device is offline, the precached offline page is shown.
 */
const VERSION = 'v1';
const STATIC_CACHE = `dpanel-static-${VERSION}`;
const OFFLINE_URL = '/offline.html';

const PRECACHE = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/pwa/icon-192.png',
    '/pwa/icon-512.png',
];

const CACHEABLE_PREFIXES = ['/build/assets/', '/pwa/', '/assets/bs-icon/'];
const MAX_STATIC_ENTRIES = 300;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE.map((url) => new Request(url, { cache: 'reload' }))))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.startsWith('dpanel-') && key !== STATIC_CACHE)
                    .map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

const isCacheableStatic = (url) => url.origin === self.location.origin
    && CACHEABLE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));

const trimCache = async (cache) => {
    const keys = await cache.keys();
    for (let i = 0; i < keys.length - MAX_STATIC_ENTRIES; i += 1) {
        await cache.delete(keys[i]);
    }
};

const cacheFirst = async (request) => {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request);
    if (cached) {
        return cached;
    }

    const response = await fetch(request);
    if (response.ok) {
        cache.put(request, response.clone()).then(() => trimCache(cache)).catch(() => {});
    }

    return response;
};

const networkWithOfflineFallback = async (request) => {
    try {
        return await fetch(request);
    } catch (error) {
        const cached = await caches.match(OFFLINE_URL);
        if (cached) {
            return cached;
        }
        throw error;
    }
};

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Full page loads (not Inertia XHR visits, which are plain fetches).
    if (request.mode === 'navigate') {
        event.respondWith(networkWithOfflineFallback(request));
        return;
    }

    if (isCacheableStatic(url)) {
        event.respondWith(cacheFirst(request));
    }

    // Everything else (Inertia/JSON requests, downloads, webmail, phpMyAdmin,
    // Vite dev server) is left to the browser untouched.
});
