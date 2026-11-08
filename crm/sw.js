// Zest CRM app shell: lets the phone install the CRM as an app. Data always comes live from the server — nothing private is cached.
const V = 'zest-shell-1';
const SHELL = ['assets/icons/icon-192.png', 'assets/logo-white.png', 'assets/mark.svg', 'offline.html'];
self.addEventListener('install', e => { e.waitUntil(caches.open(V).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(k => Promise.all(k.filter(x => x !== V).map(x => caches.delete(x)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const r = e.request; if (r.method !== 'GET' || new URL(r.url).origin !== location.origin) return;
  if (r.mode === 'navigate') { e.respondWith(fetch(r).catch(() => caches.match('offline.html'))); return; }   // pages: always live; offline note if no internet
  if (/\/assets\/(icons\/|logo|mark)/.test(r.url)) e.respondWith(caches.match(r).then(c => c || fetch(r)));
});
