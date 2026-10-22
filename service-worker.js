const CACHE_NAME = 'paint-project-manager-v3.6-mobile-height-100-v5-20261022';
const APP_SHELL = ['./','./index.html','./manifest.webmanifest','./icon-192.png','./icon-512.png','./apple-touch-icon.png'];
self.addEventListener('install', event => { self.skipWaiting(); event.waitUntil(caches.open(CACHE_NAME).then(c => c.addAll(APP_SHELL))); });
self.addEventListener('activate', event => { event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', event => {
 if (event.request.method !== 'GET') return;
 event.respondWith(fetch(event.request).then(response => { const copy=response.clone(); caches.open(CACHE_NAME).then(c=>c.put(event.request,copy)); return response; }).catch(()=>caches.match(event.request).then(r=>r||caches.match('./index.html'))));
});
