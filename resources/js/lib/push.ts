import { xsrfToken } from '@/lib/offline-queue';

export interface PushKeys {
    p256dh: string;
    auth: string;
}

export function pushSupported(): boolean {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

function urlBase64ToUint8Array(base64: string): Uint8Array {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

async function send(method: 'POST' | 'DELETE', body: Record<string, unknown>): Promise<void> {
    const response = await fetch('/push-subscriptions', {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    if (!response.ok) {
        throw new Error(`push subscription ${method} failed: ${response.status}`);
    }
}

/** Prompts for permission, subscribes the browser, and stores the subscription server-side. */
export async function enablePush(vapidPublicKey: string): Promise<boolean> {
    if (!pushSupported()) return false;

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return false;

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey) as BufferSource,
    });

    const json = subscription.toJSON();
    if (!json.endpoint || !json.keys) return false;

    await send('POST', { endpoint: json.endpoint, keys: json.keys });
    return true;
}

/** Unsubscribes the browser and removes the subscription server-side. */
export async function disablePush(): Promise<void> {
    if (!pushSupported()) return;

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    if (!subscription) return;

    const endpoint = subscription.endpoint;
    await subscription.unsubscribe();
    await send('DELETE', { endpoint });
}
