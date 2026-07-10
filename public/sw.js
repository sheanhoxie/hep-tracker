const CACHE = 'hep-shell-v1';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

// Cache-first for immutable same-origin assets (Vite builds are content-hashed).
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== self.location.origin) return;
    if (!url.pathname.startsWith('/build/') && !url.pathname.startsWith('/thumbs/') && !url.pathname.startsWith('/icons/')) return;

    event.respondWith(
        caches.open(CACHE).then(async (cache) => {
            const hit = await cache.match(event.request);
            if (hit) return hit;
            const response = await fetch(event.request);
            if (response.ok) await cache.put(event.request, response.clone());
            return response;
        }),
    );
});

// Payload shape comes from WebPushMessage (title/body/icon/tag/data).
self.addEventListener('push', (event) => {
    const payload = event.data ? event.data.json() : {};
    event.waitUntil(
        self.registration.showNotification(payload.title ?? 'HEP', {
            body: payload.body ?? '',
            icon: payload.icon ?? '/icons/icon-192.png',
            tag: payload.tag,
            data: payload.data ?? {},
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url ?? '/today';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            const existing = clients.find((c) => new URL(c.url).origin === self.location.origin);
            if (existing) return existing.focus().then((c) => c.navigate(url));
            return self.clients.openWindow(url);
        }),
    );
});
