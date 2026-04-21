/**
 * Solterra Field Backup — Service Worker
 *
 * Scope: /Solterra-Logistics-Portal/field_backup/
 *
 * Design notes:
 *  - Versioned cache name so a redeploy tonight (change BUILD_ID → redeploy)
 *    cleanly replaces old caches on next launch.
 *  - skipWaiting + clients.claim so new versions activate immediately
 *    without "update stuck waiting" states.
 *  - Narrow whitelist for cache-first: only known shell URLs. Everything
 *    else is pass-through fetch() — the app makes zero network calls at
 *    runtime, so there's nothing else to cache accidentally.
 *  - Isolated by directory from the existing portal service worker at
 *    /Solterra-Logistics-Portal/service-worker.js — that SW's scope is
 *    "/Solterra-Logistics-Portal/" and this SW's scope is
 *    "/Solterra-Logistics-Portal/field_backup/", so they cannot collide.
 */

const BUILD_ID = 'field-backup-20260413-2230';
const CACHE_NAME = 'solterra-' + BUILD_ID;

// Shell URLs — relative to the SW's location (/field_backup/)
// Note: all of these are cached on install so the app can launch offline.
const SHELL = [
    './',
    './index.html',
    './manifest.json',
    '../vendor/html5-qrcode/html5-qrcode.min.js',
    '../pictures/logo-192x192.png',
    '../pictures/logo-512x512.png',
    '../pictures/apple-touch-icon.png',
    '../pictures/apple-touch-icon-120x120.png',
    '../pictures/apple-touch-icon-152x152.png',
    '../pictures/favicon.png',
];

// Normalize shell URLs so cache.match() can find them regardless of how
// the fetch event URL is formatted.
function normalizeShellUrls() {
    return SHELL.map(function (path) {
        return new URL(path, self.location).href;
    });
}

self.addEventListener('install', function (event) {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            // Use addAll which is atomic — if any fetch fails, the whole
            // install fails and SW doesn't activate. That's what we want.
            return cache.addAll(SHELL);
        }).catch(function (err) {
            // Log but don't throw — if install fails, the app still works
            // from browser HTTP cache on subsequent loads. The SW just
            // won't provide guaranteed offline.
            console.warn('[field_backup sw] install cache.addAll failed:', err);
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (names) {
            return Promise.all(
                names.map(function (name) {
                    // Delete any old field-backup cache that isn't the current one
                    if (name.indexOf('solterra-field-backup-') === 0 && name !== CACHE_NAME) {
                        return caches.delete(name);
                    }
                    return null;
                })
            );
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (event) {
    // Only intercept GET requests
    if (event.request.method !== 'GET') return;

    const url = event.request.url;
    const shellUrls = normalizeShellUrls();

    // Cache-first ONLY for shell URLs. Everything else passes through.
    // Also allow cache-first for any request to the field_backup subtree
    // (in case we add more assets later).
    const isShell = shellUrls.indexOf(url) !== -1;
    const isFieldBackup = url.indexOf(new URL('./', self.location).href) === 0;
    const isVendorHtml5Qr = url.indexOf('vendor/html5-qrcode/') !== -1;
    const isPortalPicture = /\/pictures\/(logo-|apple-touch-icon|favicon)/.test(url);

    if (isShell || (isFieldBackup && !url.endsWith('sw.js'))) {
        event.respondWith(
            caches.match(event.request).then(function (cached) {
                if (cached) return cached;
                // Not in cache — fetch and cache for next time
                return fetch(event.request).then(function (response) {
                    if (response && response.status === 200) {
                        const copy = response.clone();
                        caches.open(CACHE_NAME).then(function (cache) {
                            cache.put(event.request, copy);
                        });
                    }
                    return response;
                }).catch(function () {
                    // Offline and not cached — return a minimal fallback
                    return new Response('Offline: this resource is not cached.', {
                        status: 503,
                        statusText: 'Service Unavailable',
                    });
                });
            })
        );
        return;
    }

    // Shared portal assets (vendored scanner, logo) — cache-first as well
    if (isVendorHtml5Qr || isPortalPicture) {
        event.respondWith(
            caches.match(event.request).then(function (cached) {
                if (cached) return cached;
                return fetch(event.request).then(function (response) {
                    if (response && response.status === 200) {
                        const copy = response.clone();
                        caches.open(CACHE_NAME).then(function (cache) {
                            cache.put(event.request, copy);
                        });
                    }
                    return response;
                }).catch(function () {
                    return new Response('', { status: 503 });
                });
            })
        );
        return;
    }

    // Everything else: pass through to network
    // (This app makes zero runtime API calls, so this branch is mostly
    // defensive — e.g. if the browser fetches a favicon from an odd path.)
});
