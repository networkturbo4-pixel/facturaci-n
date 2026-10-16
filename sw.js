const CACHE_NAME = 'factusys-pwa-v3.5';
const OFFLINE_URL = './offline.html';

const PRECACHE_ASSETS = [
    './offline.html',
    './css/style.css',
    './js/main.js',
    './uploads/branding/default-favicon.png',
    './uploads/branding/default-pwa-192.png',
    './uploads/branding/default-pwa-512.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_ASSETS).catch(() => {});
        }).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    if (event.data && event.data.type === 'CLEAR_CACHE') {
        event.waitUntil(
            caches.keys().then((names) => Promise.all(names.map((n) => caches.delete(n))))
        );
    }
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);

    // Do not cache API calls or dynamic manifest directly
    if (url.pathname.includes('/api/') || url.pathname.endsWith('manifest.php')) {
        return;
    }

    // Navigation requests (HTML/PHP views): Network First, fallback to offline.html
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req)
                .then((networkResp) => {
                    return networkResp;
                })
                .catch(() => {
                    return caches.match(OFFLINE_URL);
                })
        );
        return;
    }

    // Static assets (CSS, JS, Images, Fonts): Stale-While-Revalidate
    if (
        req.destination === 'style' ||
        req.destination === 'script' ||
        req.destination === 'image' ||
        req.destination === 'font'
    ) {
        event.respondWith(
            caches.match(req).then((cachedResp) => {
                const fetchPromise = fetch(req)
                    .then((networkResp) => {
                        if (networkResp && networkResp.status === 200) {
                            const clone = networkResp.clone();
                            caches.open(CACHE_NAME).then((cache) => cache.put(req, clone));
                        }
                        return networkResp;
                    })
                    .catch(() => cachedResp);
                return cachedResp || fetchPromise;
            })
        );
    }
});
