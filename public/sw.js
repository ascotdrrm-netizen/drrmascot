/*
 * ASCOT DRRMS service worker (docs/PLAN.md §1.2): makes the phone app open with
 * no signal. What it caches, on purpose:
 *   - the offline page, public hotlines and the app's Help, so emergency
 *     numbers and how-to guides are available;
 *   - built CSS/JS, fonts, icons and seals (they never change under one URL).
 * It never caches authenticated pages or any POST. A private report must not
 * remain readable in a shared browser's cache after logout.
 */
const VERSION = 'drrms-v3';
const PAGES = `${VERSION}-pages`;
const ASSETS = `${VERSION}-assets`;
const PRECACHE = ['/offline', '/app/hotlines', '/app/help', '/manifest.webmanifest', '/icons/icon-192.png', '/images/drrm-logo-256.png', '/images/ascot-logo-256.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(PAGES).then((cache) => cache.addAll(PRECACHE)).catch(() => {}));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => !key.startsWith(VERSION)).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

// "Update available — reload": the page asks the waiting worker to take over.
self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

const isAsset = (url) => /^\/(build|vendor|icons|images)\//.test(url.pathname);
const isCacheablePage = (url) => ['/offline', '/app/hotlines', '/app/help'].includes(url.pathname);

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    // Assets: cache first, fill the cache on the way.
    if (isAsset(url)) {
        event.respondWith(
            caches.match(request).then((hit) => hit || fetch(request).then((response) => {
                if (response.ok) caches.open(ASSETS).then((cache) => cache.put(request, response.clone()));
                return response;
            })),
        );
        return;
    }

    // Pages: network first; the last copy (or the offline page) when there's no signal.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok && isCacheablePage(url)) {
                        caches.open(PAGES).then((cache) => cache.put(request, response.clone()));
                    }
                    return response;
                })
                .catch(() => (isCacheablePage(url) ? caches.match(request) : Promise.resolve(null))
                    .then((hit) => hit || caches.match('/offline'))),
        );
    }
});
