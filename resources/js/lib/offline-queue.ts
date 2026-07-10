export interface QueuedWrite {
    id: string;
    url: string;
    method: 'POST' | 'PATCH';
    body: Record<string, unknown>;
    queued_at: string;
}

const DB_NAME = 'hep-player';
const STORE = 'write-queue';

// Statuses that mean the write itself is bad and will never succeed.
// Everything else (419, 429, 5xx, network failure) is retried later.
const POISON_STATUSES = new Set([400, 403, 404, 422]);

function openDb(): Promise<IDBDatabase> {
    return new Promise<IDBDatabase>((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => {
            request.result.createObjectStore(STORE, { keyPath: 'id' });
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function withStore<T>(mode: IDBTransactionMode, run: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const db = await openDb();
    return new Promise<T>((resolve, reject) => {
        const transaction = db.transaction(STORE, mode);
        const request = run(transaction.objectStore(STORE));
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
        transaction.oncomplete = () => db.close();
    });
}

export async function enqueue(write: QueuedWrite): Promise<void> {
    await withStore('readwrite', (store) => store.put(write));
}

export async function pending(): Promise<QueuedWrite[]> {
    const writes = await withStore<QueuedWrite[]>('readonly', (store) => store.getAll() as IDBRequest<QueuedWrite[]>);
    return writes.sort((a, b) => a.queued_at.localeCompare(b.queued_at));
}

async function remove(id: string): Promise<void> {
    await withStore('readwrite', (store) => store.delete(id));
}

export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function drainOnce(): Promise<void> {
    for (const write of await pending()) {
        let response: Response;
        try {
            response = await fetch(write.url, {
                method: write.method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                redirect: 'manual',
                body: JSON.stringify(write.body),
            });
        } catch {
            return;
        }
        const delivered = response.ok || response.type === 'opaqueredirect';
        if (delivered || POISON_STATUSES.has(response.status)) {
            if (!delivered) {
                console.error('offline-queue: write rejected, dropping', { url: write.url, status: response.status });
            }
            await remove(write.id);
        } else {
            return;
        }
    }
}

let draining: Promise<number> | null = null;
let rerun = false;

/**
 * Drains the queue in order, single-flight: a flush() during an active drain
 * schedules one more pass instead of racing it. Returns the number of writes
 * still queued (0 = fully delivered).
 */
export function flush(): Promise<number> {
    if (draining) {
        rerun = true;
        return draining;
    }
    draining = (async () => {
        try {
            do {
                rerun = false;
                await drainOnce();
            } while (rerun);
            return (await pending()).length;
        } finally {
            draining = null;
        }
    })();
    return draining;
}
