const CACHE_NAME = 'codeia-v1';
const urlsToCache = ['/'];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(urlsToCache))
    );
});

self.addEventListener('fetch', event => {
    // Modo "Red Primero" para que la IA nunca responda con caché viejo
    event.respondWith(
        fetch(event.request).catch(() => caches.match(event.request))
    );
});