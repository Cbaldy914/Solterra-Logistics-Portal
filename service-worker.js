const CACHE_NAME = "solterra-cache-v1";
const urlsToCache = [
  "/Solterra-Logistics-Portal/login.php",
  "/Solterra-Logistics-Portal/portal.css",
  "/Solterra-Logistics-Portal/pscripts.js"
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(urlsToCache);
    })
  );
});

self.addEventListener("fetch", (event) => {
  event.respondWith(
    caches.match(event.request).then((response) => {
      return response || fetch(event.request);
    })
  );
});
