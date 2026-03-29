const CACHE_NAME = 'laraclaw-v1';
const APP_SHELL = ['/', '/manifest.webmanifest', '/favicon.ico', '/favicon.svg', '/apple-touch-icon.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }

                    return Promise.resolve(false);
                }),
            ),
        ),
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    const requestUrl = new URL(event.request.url);

    if (requestUrl.origin !== self.location.origin) {
        return;
    }

    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    const copy = response.clone();
                    void caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));

                    return response;
                })
                .catch(async () => {
                    const cached = await caches.match(event.request);

                    return cached || caches.match('/');
                }),
        );

        return;
    }

    event.respondWith(
        caches.match(event.request).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(event.request).then((response) => {
                if (! response || response.status !== 200 || response.type !== 'basic') {
                    return response;
                }

                const copy = response.clone();
                void caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));

                return response;
            });
        }),
    );
});
