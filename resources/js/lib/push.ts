import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * True once this device holds a push subscription that has been sent to the
 * server in this page session. Shared so the notification banner hides the
 * moment any caller (the banner, Play, Interview) finishes subscribing.
 */
export const pushSubscribed = ref(false);

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

function vapidPublicKey(): string | undefined {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="vapid-public-key"]')
            ?.content || undefined
    );
}

/** Whether this browser can subscribe to this app's web push at all. */
export function pushSupported(): boolean {
    return (
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window &&
        vapidPublicKey() !== undefined
    );
}

/** This device's existing push subscription, if it has one. */
export async function currentPushSubscription(): Promise<PushSubscription | null> {
    if (!pushSupported()) {
        return null;
    }

    try {
        const registration = await navigator.serviceWorker.getRegistration();

        return (await registration?.pushManager.getSubscription()) ?? null;
    } catch {
        return null;
    }
}

/**
 * Register the service worker and subscribe this device to web push.
 * Safe to call repeatedly; it no-ops once subscribed or when unsupported.
 * Resolves true when this device ends up subscribed.
 */
export async function enablePush(): Promise<boolean> {
    if (!pushSupported()) {
        return false;
    }

    const vapidKey = vapidPublicKey() as string;

    try {
        if (Notification.permission === 'denied') {
            return false;
        }

        // Ask before any other await: iPhone only shows the prompt while the
        // tap that asked for it is still the current gesture.
        if (Notification.permission === 'default') {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                return false;
            }
        }

        const registration = await navigator.serviceWorker.register('/sw.js');
        await navigator.serviceWorker.ready;

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

        pushSubscribed.value = true;

        return true;
    } catch {
        // Push is a nicety; never let it break the page.
        return false;
    }
}
