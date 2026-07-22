// Service worker: web push + notification deep-linking back into the PWA.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload = {};
    try {
        payload = event.data.json();
    } catch {
        payload = { title: 'The story moved', body: event.data.text() };
    }

    event.waitUntil(
        self.registration.showNotification(payload.title ?? 'The story moved', {
            body: payload.body ?? '',
            icon: payload.icon ?? '/icons/icon-192.png',
            badge: payload.badge ?? '/icons/badge-72.png',
            tag: payload.tag,
            data: payload.data ?? {},
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url ?? '/campaigns';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if (client.url.startsWith(self.registration.scope) && 'focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return self.clients.openWindow(url);
        }),
    );
});
