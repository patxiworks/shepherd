// The version of the cache.
const CACHE_VERSION = 2;
const CURRENT_CACHE = `main-${CACHE_VERSION}`;

// The files that will be cached when the service worker is installed.
const cachedFiles = [
  '/',
  '/login',
  '/manifest.json',
  '/pastores-192-192.png',
  '/pastores.scale-400.png',
  '/shepherd-bg.png',
  '/sj-bg3.png'
];

self.addEventListener('install', (evt) => {
  evt.waitUntil(
    caches.open(CURRENT_CACHE).then((cache) => {
      return cache.addAll(cachedFiles);
    })
  );
});

self.addEventListener('activate', (evt) => {
  evt.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CURRENT_CACHE) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
});

self.addEventListener('fetch', (evt) => {
  evt.respondWith(
    caches.open(CURRENT_CACHE).then(async (cache) => {
      const response = await cache.match(evt.request);
      // If the request is in the cache, respond with it. Otherwise, fetch it.
      return response || fetch(evt.request).then((fetchResponse) => {
        // Add the new response to the cache.
        cache.put(evt.request, fetchResponse.clone());
        return fetchResponse;
      });
    })
  );
});