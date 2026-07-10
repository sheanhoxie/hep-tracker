<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { enqueue, flush, pending, type QueuedWrite } from '@/lib/offline-queue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// Offline-tolerant player island (Phase 4). Every write goes through the
// IndexedDB queue first, then flushes to the server — the UI reads server
// props merged with the queue, so it behaves identically offline.

interface SetQueueEntry {
    set_no: number;
    side: 'left' | 'right' | null;
    reps: number | null;
    hold_sec: number | null;
    eccentric_sec: number | null;
    duration_sec: number | null;
    rest_sec: number | null;
    to_fatigue: boolean;
}

interface SetEventDto {
    set_no: number;
    side: 'left' | 'right' | null;
    completed: boolean;
}

type ItemStatus = 'pending' | 'active' | 'done' | 'skipped';

interface ItemDto {
    id: number;
    status: ItemStatus;
    skip_reason: string | null;
    exercise: {
        slug: string;
        name: string;
        steps: string[];
        laterality: string;
        cues: string[];
        thumb: string | null;
        video: string | null;
    };
    dosage: string;
    notes: string | null;
    set_queue: SetQueueEntry[];
    set_events: SetEventDto[];
}

interface ObservationDto {
    kind: 'pain' | 'rpe' | 'note';
    value: number | null;
    text: string | null;
}

const props = defineProps<{
    session: {
        id: number;
        status: string;
        planned_date: string;
        started_at: string | null;
    };
    items: ItemDto[];
    observations: ObservationDto[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Today', href: '/today' },
    { title: 'Session', href: `/sessions/${props.session.id}` },
];

// --- offline queue state ---

const queued = ref<QueuedWrite[]>([]);
const online = ref<boolean>(navigator.onLine);

async function refreshQueue(): Promise<void> {
    queued.value = await pending();
}

async function syncNow(): Promise<void> {
    if (!navigator.onLine) return;
    const before = queued.value.length;
    const remaining = await flush();
    await refreshQueue();
    if (before > 0 && remaining === 0) {
        router.reload({ only: ['items', 'session', 'observations'] });
    }
}

async function pushWrite(id: string, url: string, method: 'POST' | 'PATCH', body: Record<string, unknown>): Promise<void> {
    await enqueue({ id, url, method, body, queued_at: new Date().toISOString() });
    await refreshQueue();
    await syncNow();
}

function handleOnline(): void {
    online.value = true;
    void syncNow();
}

function handleOffline(): void {
    online.value = false;
}

// --- server props merged with queued writes ---

function queuedSetEvents(itemId: number): SetEventDto[] {
    return queued.value
        .filter((write) => write.url === `/sessions/${props.session.id}/items/${itemId}/set-events`)
        .map((write) => ({
            set_no: write.body.set_no as number,
            side: (write.body.side ?? null) as SetEventDto['side'],
            completed: true,
        }));
}

function effectiveStatus(item: ItemDto): ItemStatus {
    const write = [...queued.value]
        .reverse()
        .find((candidate) => candidate.method === 'PATCH' && candidate.url === `/sessions/${props.session.id}/items/${item.id}`);
    return write ? (write.body.status as ItemStatus) : item.status;
}

const sessionStatus = computed<string>(() => {
    const write = [...queued.value]
        .reverse()
        .find((candidate) => candidate.method === 'PATCH' && candidate.url === `/sessions/${props.session.id}`);
    return write ? (write.body.status as string) : props.session.status;
});

function isLogged(item: ItemDto, entry: SetQueueEntry): boolean {
    const match = (event: SetEventDto): boolean => event.set_no === entry.set_no && event.side === entry.side;
    return item.set_events.some(match) || queuedSetEvents(item.id).some(match);
}

function isOpen(item: ItemDto): boolean {
    const status = effectiveStatus(item);
    return status === 'pending' || status === 'active';
}

const doneCount = computed<number>(() => props.items.filter((item) => !isOpen(item)).length);
const allDone = computed<boolean>(() => doneCount.value === props.items.length);

// --- player position ---

const currentIdx = ref<number>(0);
const current = computed<ItemDto | undefined>(() => props.items[currentIdx.value]);

function firstOpenIndex(): number {
    const idx = props.items.findIndex(isOpen);
    return idx === -1 ? 0 : idx;
}

function goTo(idx: number): void {
    currentIdx.value = Math.min(Math.max(idx, 0), props.items.length - 1);
}

function advance(): void {
    const after = props.items.findIndex((item, idx) => idx > currentIdx.value && isOpen(item));
    if (after !== -1) {
        currentIdx.value = after;
        return;
    }
    const anywhere = props.items.findIndex(isOpen);
    if (anywhere !== -1) {
        currentIdx.value = anywhere;
    }
}

// --- rest timer: stores a target timestamp, display recomputes from Date.now() ---

const restUntil = ref<number | null>(null);
const nowMs = ref<number>(Date.now());
let clockId: number | null = null;
let retryId: number | null = null;

const restRemaining = computed<number>(() => {
    if (restUntil.value === null) return 0;
    return Math.max(0, Math.ceil((restUntil.value - nowMs.value) / 1000));
});

// --- actions ---

function logSet(item: ItemDto, entry: SetQueueEntry): void {
    const key = crypto.randomUUID();
    void pushWrite(key, `/sessions/${props.session.id}/items/${item.id}/set-events`, 'POST', {
        client_key: key,
        set_no: entry.set_no,
        side: entry.side,
        reps_done: entry.reps,
        hold_sec_done: entry.hold_sec,
        occurred_at: new Date().toISOString(),
    });
    if (entry.rest_sec) {
        restUntil.value = Date.now() + entry.rest_sec * 1000;
    }
}

function setItemStatus(item: ItemDto, status: ItemStatus): void {
    const skip_reason = status === 'skipped' ? window.prompt('Why skip? (optional)') : null;
    void pushWrite(crypto.randomUUID(), `/sessions/${props.session.id}/items/${item.id}`, 'PATCH', { status, skip_reason }).then(advance);
}

const painValue = ref<string>('');
const rpeValue = ref<string>('');
const noteValue = ref<string>('');

const displayObservations = computed<ObservationDto[]>(() => {
    const queuedObs = queued.value
        .filter((write) => write.url === `/sessions/${props.session.id}/observations`)
        .map((write): ObservationDto => ({
            kind: write.body.kind as ObservationDto['kind'],
            value: (write.body.numeric_value ?? null) as number | null,
            text: (write.body.text_value ?? null) as string | null,
        }));
    return [...props.observations, ...queuedObs];
});

function logObservation(kind: ObservationDto['kind'], numeric_value: number | null, text_value: string | null): void {
    const key = crypto.randomUUID();
    void pushWrite(key, `/sessions/${props.session.id}/observations`, 'POST', {
        client_key: key,
        kind,
        numeric_value,
        text_value,
        occurred_at: new Date().toISOString(),
    });
}

function completeSession(): void {
    if (painValue.value !== '') logObservation('pain', Number(painValue.value), null);
    if (rpeValue.value !== '') logObservation('rpe', Number(rpeValue.value), null);
    if (noteValue.value.trim() !== '') logObservation('note', null, noteValue.value.trim());
    void pushWrite(crypto.randomUUID(), `/sessions/${props.session.id}`, 'PATCH', { status: 'done' });
}

function entryLabel(entry: SetQueueEntry): string {
    const parts = [`Set ${entry.set_no}`];
    if (entry.side) parts.push(entry.side);
    if (entry.reps) parts.push(`${entry.reps} reps`);
    if (entry.hold_sec) parts.push(`${entry.hold_sec}s hold`);
    if (entry.eccentric_sec) parts.push(`${entry.eccentric_sec}s down`);
    if (entry.duration_sec) parts.push(`${entry.duration_sec}s`);
    if (entry.to_fatigue) parts.push('to fatigue');
    return parts.join(' · ');
}

onMounted(async () => {
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    clockId = window.setInterval(() => {
        nowMs.value = Date.now();
    }, 250);
    retryId = window.setInterval(() => {
        if (queued.value.length > 0) void syncNow();
    }, 3000);
    await refreshQueue();
    currentIdx.value = firstOpenIndex();
    await syncNow();
});

onBeforeUnmount(() => {
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);
    if (clockId !== null) window.clearInterval(clockId);
    if (retryId !== null) window.clearInterval(retryId);
});
</script>

<template>
    <Head title="Session" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-4 p-4">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-xl font-semibold">Session — {{ session.planned_date }}</h1>
                    <p class="text-sm text-muted-foreground">{{ doneCount }} of {{ items.length }} exercises done</p>
                </div>
                <div class="flex items-center gap-2">
                    <span
                        v-if="!online || queued.length > 0"
                        data-testid="sync-badge"
                        class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-400"
                    >
                        {{ online ? `syncing ${queued.length}…` : `offline — ${queued.length} saved` }}
                    </span>
                    <span v-if="restRemaining > 0" class="rounded-full bg-sky-100 px-2.5 py-0.5 text-xs font-medium text-sky-700 dark:bg-sky-950 dark:text-sky-400">
                        rest {{ restRemaining }}s
                    </span>
                </div>
            </div>

            <!-- progress strip -->
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="(item, idx) in items"
                    :key="item.id"
                    class="h-8 w-8 rounded-full border text-xs font-medium"
                    :class="[
                        idx === currentIdx ? 'ring-2 ring-primary' : '',
                        !isOpen(item) ? 'border-green-600 bg-green-600/10 text-green-700 dark:text-green-400' : 'border-sidebar-border/70 text-muted-foreground',
                    ]"
                    :title="item.exercise.name"
                    @click="goTo(idx)"
                >
                    {{ idx + 1 }}
                </button>
            </div>

            <div v-if="sessionStatus !== 'active'" class="flex flex-col gap-2 rounded-xl border border-green-600/40 bg-green-600/10 p-4 text-sm">
                <p>Session {{ sessionStatus }}. <Link href="/today" class="underline">Back to Today</Link></p>
                <div v-if="displayObservations.length > 0" class="flex flex-wrap items-center gap-2">
                    <template v-for="(obs, i) in displayObservations" :key="i">
                        <span v-if="obs.kind === 'pain'" class="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-700 dark:bg-rose-950 dark:text-rose-400">
                            pain {{ obs.value }}/10
                        </span>
                        <span v-else-if="obs.kind === 'rpe'" class="rounded-full bg-violet-100 px-2.5 py-0.5 text-xs font-medium text-violet-700 dark:bg-violet-950 dark:text-violet-400">
                            RPE {{ obs.value }}/10
                        </span>
                        <span v-else class="text-xs italic text-muted-foreground">{{ obs.text }}</span>
                    </template>
                </div>
            </div>

            <div v-else-if="allDone" class="flex flex-col items-start gap-4 rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                <p class="font-medium">All exercises finished. 🎉</p>
                <div class="flex flex-wrap gap-4">
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="text-muted-foreground">Pain (0–10)</span>
                        <select v-model="painValue" data-testid="pain-select" class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2">
                            <option value="">—</option>
                            <option v-for="n in 11" :key="n" :value="String(n - 1)">{{ n - 1 }}</option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="text-muted-foreground">Effort / RPE (0–10)</span>
                        <select v-model="rpeValue" data-testid="rpe-select" class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2">
                            <option value="">—</option>
                            <option v-for="n in 11" :key="n" :value="String(n - 1)">{{ n - 1 }}</option>
                        </select>
                    </label>
                </div>
                <input
                    v-model="noteValue"
                    data-testid="session-note"
                    type="text"
                    placeholder="How did it go? (optional)"
                    class="w-full rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 text-sm"
                />
                <button
                    data-testid="complete-session"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                    @click="completeSession"
                >
                    Complete session
                </button>
            </div>

            <!-- current exercise card -->
            <div v-if="current" data-testid="player-card" class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-3">
                        <img v-if="current.exercise.thumb" :src="current.exercise.thumb" :alt="current.exercise.name" class="h-14 w-14 shrink-0 rounded-lg" />
                        <div>
                            <h2 class="text-lg font-semibold">{{ current.exercise.name }}</h2>
                            <p class="text-sm text-muted-foreground">{{ current.dosage }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <button class="rounded border px-2 py-1 text-sm" :disabled="currentIdx === 0" @click="goTo(currentIdx - 1)">‹</button>
                        <span class="px-1 text-xs text-muted-foreground">{{ currentIdx + 1 }}/{{ items.length }}</span>
                        <button class="rounded border px-2 py-1 text-sm" :disabled="currentIdx === items.length - 1" @click="goTo(currentIdx + 1)">›</button>
                    </div>
                </div>

                <video
                    v-if="current.exercise.video"
                    :src="current.exercise.video"
                    :poster="current.exercise.thumb ?? undefined"
                    controls
                    playsinline
                    preload="none"
                    class="mt-4 w-full rounded-lg"
                />

                <ol class="mt-4 list-decimal pl-5 text-sm text-muted-foreground">
                    <li v-for="step in current.exercise.steps" :key="step">{{ step }}</li>
                </ol>

                <p v-if="current.exercise.cues.length" class="mt-2 text-xs italic text-muted-foreground">
                    {{ current.exercise.cues.join(' · ') }}
                </p>

                <p v-if="current.notes" class="mt-2 text-xs text-amber-600">{{ current.notes }}</p>

                <div v-if="sessionStatus === 'active' && isOpen(current)" class="mt-4 flex flex-wrap gap-2">
                    <button
                        v-for="(entry, i) in current.set_queue"
                        :key="i"
                        class="rounded-lg border px-3 py-2 text-sm"
                        :class="isLogged(current, entry) ? 'border-green-600 text-green-600' : 'border-sidebar-border/70'"
                        :disabled="isLogged(current, entry)"
                        @click="logSet(current, entry)"
                    >
                        {{ isLogged(current, entry) ? '✓ ' : '' }}{{ entryLabel(entry) }}
                    </button>
                </div>

                <div v-if="sessionStatus === 'active'" class="mt-4 flex items-center justify-between">
                    <span class="text-xs uppercase tracking-wide text-muted-foreground">{{ effectiveStatus(current) }}</span>
                    <div v-if="isOpen(current)" class="flex gap-2">
                        <button data-testid="item-skip" class="rounded-lg border px-3 py-1.5 text-sm" @click="setItemStatus(current, 'skipped')">Skip</button>
                        <button
                            data-testid="item-done"
                            class="rounded-lg bg-primary px-4 py-1.5 text-sm font-medium text-primary-foreground"
                            @click="setItemStatus(current, 'done')"
                        >
                            Done
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
