/*
 * dPanel service worker.
 *
 * The panel serves every page with no-store and carries a session token in
 * its URLs, so pages and API responses are never cached here — they always go
 * to the network. Only static, non-sensitive files are cached: the hashed
 * Vite build output (immutable), icons and the icon font. When a page navigation
 * fails because the device is offline, the precached offline page is shown.
 *
 * The build output is not only cached as it is asked for: once the panel is
 * open and idle, every JavaScript and CSS file in the Vite manifest is
 * downloaded in the background (warmBuildAssets). A page whose chunk has
 * never been opened then renders from the cache with no request of its own —
 * which is what makes the first visit to a section feel instant, and what
 * lets a reload work on a dropped connection. The files are content-hashed,
 * so a new release simply has new names; the old ones fall out of the cache
 * when the version below changes.
 */
const VERSION = 'v2';
const STATIC_CACHE = `dpanel-static-${VERSION}`;
const OFFLINE_URL = '/offline.html';

const PRECACHE = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/pwa/icon-192.png',
    '/pwa/icon-512.png',
];

const CACHEABLE_PREFIXES = ['/build/assets/', '/pwa/', '/assets/bs-icon/'];
// Room for the whole build (about 430 JS/CSS files) plus the icons, so the
// warm-up never evicts what it has only just downloaded.
const MAX_STATIC_ENTRIES = 600;

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
            .then(() => self.clients.claim())
            // Not awaited by the activation itself: the panel must not wait
            // for a background download to be controlled by this worker.
            .then(() => { warmBuildAssets(); }),
    );
});

// app.js asks for this once the page has settled, so the warm-up competes with
// nothing the user is waiting for.
self.addEventListener('message', (event) => {
    if (event.data?.type === 'warm-build-assets') {
        event.waitUntil(warmBuildAssets());
    }
});

const isCacheableStatic = (url) => url.origin === self.location.origin
    && CACHEABLE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));

const MANIFEST_URL = '/build/manifest.json';

/**
 * Every built JS and CSS file, from the Vite manifest: each entry's own file
 * plus the chunks and stylesheets it pulls in.
 */
const manifestAssets = async () => {
    const response = await fetch(MANIFEST_URL, { cache: 'no-cache' });
    if (!response.ok) {
        return [];
    }

    const manifest = await response.json();
    const files = new Set();

    Object.values(manifest).forEach((entry) => {
        [entry?.file, ...(entry?.css || [])].forEach((file) => {
            if (typeof file === 'string' && /\.(js|css)$/.test(file)) {
                files.add(`/build/${file}`);
            }
        });
    });

    return [...files];
};

/**
 * Fills the cache with the build output, a few files at a time so the panel's
 * own requests keep priority. Already-cached files are skipped, so this is
 * cheap on every run after the first.
 */
const warmBuildAssets = async () => {
    // Never on a connection the user is paying for by the megabyte, or one
    // too slow to spare the bandwidth: those visits fetch what they need.
    const connection = self.navigator?.connection;
    if (connection?.saveData || ['slow-2g', '2g'].includes(connection?.effectiveType)) {
        return;
    }

    const cache = await caches.open(STATIC_CACHE);
    let assets;

    try {
        assets = await manifestAssets();
    } catch (error) {
        return; // No manifest (dev server, or offline): nothing to warm.
    }

    // Newest-looking first is not worth the bother; order is as the manifest
    // lists them, which puts the entry bundle and its chunks early.
    const queue = assets.slice(0, MAX_STATIC_ENTRIES);
    const CONCURRENCY = 4;

    const worker = async () => {
        while (queue.length) {
            const path = queue.shift();
            const request = new Request(path, { cache: 'no-cache' });
            try {
                if (await cache.match(request)) {
                    continue;
                }
                const response = await fetch(request);
                if (response.ok) {
                    await cache.put(request, response.clone());
                }
            } catch (error) {
                // A file that will not download is simply fetched when needed.
            }
        }
    };

    await Promise.all(Array.from({ length: CONCURRENCY }, worker));
    await trimCache(cache);
};

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
