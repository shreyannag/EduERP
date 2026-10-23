// EduERP Progressive Web App (PWA) Service Worker
// Version: 1.0.0

const CACHE_NAME = 'eduerp-shell-v1';
const PRECACHE_ASSETS = [
    '/offline.html',
    '/manifest.json',
    '/assets/icons/icon-192.png',
    '/assets/icons/icon-512.png',
    '/assets/icons/favicon.png',
    '/assets/css/custom.css',
    '/assets/js/app.js'
];

// 1. Install event: pre-cache offline shell
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_ASSETS).catch((err) => {
                console.warn('[PWA SW] Precache warning (some files optional):', err);
            });
        }).then(() => self.skipWaiting())
    );
});

// 2. Activate event: purge outdated caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cache) => {
                    if (cache !== CACHE_NAME) {
                        console.log('[PWA SW] Clearing old cache:', cache);
                        return caches.delete(cache);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// 3. Fetch event: Network-first for dynamic pages, Offline fallback for navigation
self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Only handle GET requests
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Navigation requests (HTML pages like dashboard, exams, login):
    // Network-first ensures students ALWAYS get fresh exams and live scores
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .catch(() => {
                    // If network fails (offline), return cached offline page
                    return caches.match('/offline.html');
                })
        );
        return;
    }

    // Static Assets (CSS, JS, Fonts, Images)
    if (
        request.destination === 'style' ||
        request.destination === 'script' ||
        request.destination === 'image' ||
        request.destination === 'font'
    ) {
        event.respondWith(
            caches.match(request).then((cachedResponse) => {
                if (cachedResponse) {
                    // Fetch fresh version in background (stale-while-revalidate)
                    fetch(request).then((networkResponse) => {
                        if (networkResponse && networkResponse.status === 200) {
                            caches.open(CACHE_NAME).then((cache) => {
                                cache.put(request, networkResponse);
                            });
                        }
                    }).catch(() => {});
                    return cachedResponse;
                }

                // If not in cache, fetch from network and cache it
                return fetch(request).then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200) {
                        const responseToCache = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(request, responseToCache);
                        });
                    }
                    return networkResponse;
                });
            })
        );
        return;
    }

    // Default: network with no-op catch
    event.respondWith(
        fetch(request).catch(() => caches.match(request))
    );
});
