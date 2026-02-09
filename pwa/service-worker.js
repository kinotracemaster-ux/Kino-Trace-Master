// Servicio worker simple para cachear los recursos estáticos.
const CACHE_NAME = 'kino-cache-v1';
const urlsToCache = [
  '/',
  '/index.php',
  '/assets/css/styles.css'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(urlsToCache))
  );
});

self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request).then(response => {
      return response || fetch(event.request);
    })
  );
});