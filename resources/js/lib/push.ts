import { router } from '@inertiajs/vue3';

function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = window.atob(base64);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

/**
 * Register the service worker and subscribe this device to web push.
 * Safe to call repeatedly; it no-ops once subscribed or when unsupported.
 */
export async function enablePush(): Promise<void> {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return;
    }

    const vapidKey = document.querySelector<HTMLMetaElement>(
        'meta[name="vapid-public-key"]',
    )?.content;

    if (!vapidKey) {
        return;
    }

    try {
        const registration = await navigator.serviceWorker.register('/sw.js');

        if (Notification.permission === 'denied') {
            return;
        }

        if (Notification.permission === 'default') {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                return;
            }
        }

        const existing = await registration.pushManager.getSubscription();
        const subscription =
            existing ??
            (await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidKey),
            }));

        const json = subscription.toJSON();
        router.post(
            '/push/subscriptions',
            { endpoint: json.endpoint, keys: json.keys },
            { preserveState: true, preserveScroll: true, only: [] },
        );
    } catch {
        // Push is a nicety; never let it break the page.
    }
}
