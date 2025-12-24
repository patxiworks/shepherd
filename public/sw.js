// This is the service worker file.

const CACHE_NAME = 'pastores-cache-v1';

// This is the "install" event that fires when the service worker is first installed.
self.addEventListener('install', (event) => {
  // The service worker is installing.
  console.log('Service Worker: Installing...');
  // We don't pre-cache any assets here, but we could.
  // Caching will happen on demand as the user navigates the app.
});

// This is the "activate" event. It's a good place to clean up old caches.
self.addEventListener('activate', (event) => {
  console.log('Service Worker: Activating...');
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cache) => {
          if (cache !== CACHE_NAME) {
            console.log('Service Worker: Clearing old cache:', cache);
            return caches.delete(cache);
          }
        })
      );
    })
  );
  return self.clients.claim();
});

// The "fetch" event intercepts all network requests made by the app.
self.addEventListener('fetch', (event) => {
  // We only want to cache GET requests.
  if (event.request.method !== 'GET') {
    return;
  }

  // We are using a "cache-first" strategy.
  // The service worker will first check if a response for the request is in the cache.
  // If it is, it serves the cached response.
  // If not, it fetches the resource from the network, serves it, and also saves a copy in the cache for next time.
  event.respondWith(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.match(event.request).then((response) => {
        // If the resource is in the cache, return it.
        if (response) {
          return response;
        }

        // If not in cache, fetch from the network.
        return fetch(event.request).then((networkResponse) => {
          // Clone the response because it's a stream and can only be consumed once.
          const responseToCache = networkResponse.clone();
          
          // Don't cache opaque responses (from third-party CDNs without CORS) or non-ok responses.
          if (networkResponse.type === 'opaque' || !networkResponse.ok) {
            return networkResponse;
          }

          // Save the network response to the cache for future requests.
          cache.put(event.request, responseToCache);
          
          return networkResponse;
        });
      }).catch(() => {
        // If both cache and network fail (e.g., offline and not cached),
        // you could return a fallback offline page here.
      });
    })
  );
});