/* Paint Project Manager emergency cache reset worker.
   This version intentionally does not intercept fetch requests.
   It activates immediately, removes old PPM Cache Storage entries,
   and lets GitHub Pages serve files directly from the network. */

const RESET_VERSION = 'ppm-v38-cache-reset-19';

self.addEventListener('install', event => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter(key => key.startsWith('ppm-'))
        .map(key => caches.delete(key))
    );
    await self.clients.claim();
  })());
});

self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

// No fetch handler by design. Requests pass directly to GitHub Pages.
