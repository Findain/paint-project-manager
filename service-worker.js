/* Paint Project Manager offline-first service worker — V4.0 Rev BM.
   Seasonal artwork is external and may be replaced without changing its filename.
   Every seasonal request is versioned, fetched network-first, and cached by its full
   URL so an older image cannot reappear from a path-only cache entry. */
const CACHE_NAME = 'ppm-v40-rev-bm';
const ASSET_VERSION = 'rev-bm';
const SHELL = 'index.html';
const OPTIONAL = ['manifest.webmanifest', 'icon-192.png', 'icon-512.png', 'apple-touch-icon.png'];
const SEASONAL = [
  'assets/seasons/fall-home-desktop.webp',
  'assets/seasons/fall-home-mobile.webp',
  'assets/seasons/fall-splash-desktop.webp',
  'assets/seasons/fall-splash-mobile.webp',
  'assets/seasons/thanksgiving-home-desktop.webp',
  'assets/seasons/thanksgiving-home-mobile.webp',
  'assets/seasons/thanksgiving-splash-desktop.webp',
  'assets/seasons/thanksgiving-splash-mobile.webp',
  'assets/seasons/christmas-home-desktop.webp',
  'assets/seasons/christmas-home-mobile.webp',
  'assets/seasons/christmas-splash-desktop.webp',
  'assets/seasons/christmas-splash-mobile.webp',
  'assets/seasons/winter-home-desktop.webp',
  'assets/seasons/winter-home-mobile.webp',
  'assets/seasons/winter-splash-desktop.webp',
  'assets/seasons/winter-splash-mobile.webp',
  'assets/seasons/stpatrick-home-desktop.webp',
  'assets/seasons/stpatrick-home-mobile.webp',
  'assets/seasons/stpatrick-splash-desktop.webp',
  'assets/seasons/stpatrick-splash-mobile.webp'
];
const NETWORK_WAIT_MS = 3000;
const CACHEABLE = /\.(?:png|jpe?g|webp|svg|ico|webmanifest|json)$/i;
const SEASONAL_PATH = '/assets/seasons/';
const scoped = path => new URL(path, self.registration.scope).href;
const versioned = path => `${path}${path.includes('?') ? '&' : '?'}v=${ASSET_VERSION}`;

async function warm(cache, path) {
  try {
    const request = new Request(scoped(path), { cache: 'reload' });
    const response = await fetch(request);
    if (response && response.ok) await cache.put(request, response);
  } catch (error) { /* A single optional asset must not block worker activation. */ }
}

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    const shell = await fetch(scoped(SHELL), { cache: 'reload' });
    if (!shell || !shell.ok) throw new Error('App shell unavailable');
    await cache.put(scoped(SHELL), shell);
    await Promise.all([
      ...OPTIONAL.map(path => warm(cache, path)),
      ...SEASONAL.map(path => warm(cache, versioned(path)))
    ]);
    await self.skipWaiting();
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

  if (url.pathname.includes(SEASONAL_PATH)) {
    event.respondWith((async () => {
      const cache = await caches.open(CACHE_NAME);
      const cached = await cache.match(request);
      try {
        const response = await fetch(request, { cache: 'no-store' });
        if (response && response.ok) event.waitUntil(cache.put(request, response.clone()));
        return response;
      } catch (error) {
        if (cached) return cached;
        throw error;
      }
    })());
    return;
  }

  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    const hit = await cache.match(request);
    if (hit) return hit;
    const response = await fetch(request);
    if (response && response.ok) event.waitUntil(cache.put(request, response.clone()));
    return response;
  })());
});
