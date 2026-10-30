/* Paint Project Manager offline-first service worker.
   - Saves index.html (and the manifest/icons when present) on install, so the app
     can open offline from the first launch.
   - Online: always tries the network first so updates still arrive.
   - Slow or dead connection: falls back to the saved copy after 3 seconds.
   - Update-check and refresh requests are never saved (they would fill storage).
   - Only this worker touches Cache Storage. Walks, orders and settings live in
     localStorage and are never read or changed here. */
const CACHE_NAME = 'ppm-v38-offline-1';
const SHELL = 'index.html';
const OPTIONAL = ['manifest.webmanifest', 'icon-192.png', 'icon-512.png', 'apple-touch-icon.png'];
const NETWORK_WAIT_MS = 3000;
const CACHEABLE = /\.(?:png|jpe?g|svg|ico|webmanifest|json)$/i;
const scoped = path => new URL(path, self.registration.scope).href;

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    // The app shell is required. If it cannot be saved, install fails and retries later.
    const shell = await fetch(scoped(SHELL), { cache: 'no-cache' });
    if (!shell || !shell.ok) throw new Error('App shell unavailable');
    await cache.put(scoped(SHELL), shell);
    // Manifest and icons are best effort. A missing file must never block install.
    await Promise.all(OPTIONAL.map(async path => {
      try {
        const response = await fetch(scoped(path), { cache: 'no-cache' });
        if (response && response.ok) await cache.put(scoped(path), response);
      } catch (error) { /* optional */ }
    }));
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys
      .filter(key => (key.startsWith('ppm-') || key.startsWith('paint-project-manager-')) && key !== CACHE_NAME)
      .map(key => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (url.searchParams.has('updateCheck') || url.searchParams.has('refresh')) return;

  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      const cache = await caches.open(CACHE_NAME);
      const cached = await cache.match(scoped(SHELL));
      const fromNetwork = fetch(request, { cache: 'no-store' }).then(response => {
        if (response && response.ok) {
          event.waitUntil(cache.put(scoped(SHELL), response.clone()));
          return response;
        }
        if (cached) return cached;
        return response;
      });
      if (!cached) return fromNetwork;
      event.waitUntil(fromNetwork.catch(() => {}));
      return Promise.race([
        fromNetwork.catch(() => cached),
        new Promise(resolve => setTimeout(() => resolve(cached), NETWORK_WAIT_MS))
      ]);
    })());
    return;
  }

  if (!CACHEABLE.test(url.pathname)) return;
  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    const key = url.origin + url.pathname;
    const hit = await cache.match(key);
    if (hit) return hit;
    const response = await fetch(request);
    if (response && response.ok) event.waitUntil(cache.put(key, response.clone()));
    return response;
  })());
});
