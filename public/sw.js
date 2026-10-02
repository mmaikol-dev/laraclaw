/**
 * LaraClaw offline shell.
 *
 * Only immutable, content-hashed build assets are ever cached. Navigations,
 * Inertia page requests and API calls are deliberately left alone: they carry
 * per-user session data, so serving them from a shared cache leaks one user's
 * pages to another and pins stale payloads in place of live ones.
 */
const CACHE_NAME = 'laraclaw-v3';
const PRECACHE = ['/manifest.webmanifest', '/favicon.ico', '/favicon.svg', '/apple-touch-icon.png'];

const isCacheableAsset = (url) =>
    url.pathname.startsWith('/build/assets/') ||
    url.pathname.startsWith('/fonts/');

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_NAME)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))),
        ).then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin || !isCacheableAsset(url)) {
        return;
    }

    event.respondWith(
        caches.open(CACHE_NAME).then(async (cache) => {
            const cached = await cache.match(request);

            if (cached) {
                return cached;
            }

            const response = await fetch(request);

            if (response.ok && response.type === 'basic') {
                await cache.put(request, response.clone());
            }

            return response;
        }),
    );
});