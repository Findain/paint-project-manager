/* Paint Project Manager V3.8 Rev AA updater worker.
   Change BUILD_ID for every future published build. */
const BUILD_ID='ppm-v38-rev-aa-updater-1';
self.addEventListener('install',event=>{
  event.waitUntil(Promise.resolve());
});
self.addEventListener('message',event=>{
  if(event.data&&event.data.type==='SKIP_WAITING')self.skipWaiting();
});
self.addEventListener('activate',event=>{
  event.waitUntil(
    caches.keys()
      .then(keys=>Promise.all(keys.filter(key=>key.startsWith('ppm-')).map(key=>caches.delete(key))))
      .then(()=>self.clients.claim())
  );
});
/* No fetch handler. GitHub Pages serves the application directly. */
