<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { disablePush, enablePush, pushSupported } from '@/lib/push';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface RecentSession {
    id: number;
    date: string;
    status: 'planned' | 'active' | 'done' | 'abandoned';
    items_done: number;
    items_total: number;
}

interface PainPoint {
    date: string;
    value: number;
}

const props = defineProps<{
    due_count: number;
    weekly: { done: number; target: number };
    streak_days: number;
    pain_series: PainPoint[];
    recent_sessions: RecentSession[];
    reminder: { enabled: boolean; time: string } | null;
    vapid_public_key: string;
}>();

const reminderEnabled = ref<boolean>(props.reminder?.enabled ?? false);
const reminderTime = ref<string>(props.reminder?.time ?? '17:00');
const reminderError = ref<string>('');
const reminderBusy = ref<boolean>(false);

function saveReminderSettings(enabled: boolean): void {
    router.put('/reminder-settings', { enabled, time: reminderTime.value }, { preserveScroll: true });
}

async function toggleReminder(): Promise<void> {
    reminderError.value = '';
    reminderBusy.value = true;
    try {
        if (!reminderEnabled.value) {
            const granted = await enablePush(props.vapid_public_key);
            if (!granted) {
                reminderError.value = pushSupported()
                    ? 'Notifications are blocked — allow them in browser settings and try again.'
                    : 'This browser does not support push. On iPhone: install the app from the share sheet first.';
                return;
            }
            reminderEnabled.value = true;
        } else {
            await disablePush();
            reminderEnabled.value = false;
        }
        saveReminderSettings(reminderEnabled.value);
    } finally {
        reminderBusy.value = false;
    }
}

function onReminderTimeChange(): void {
    if (reminderEnabled.value) {
        saveReminderSettings(true);
    }
}

const painPoints = props.pain_series
    .map((point, i) => {
        const x = props.pain_series.length === 1 ? 50 : (i / (props.pain_series.length - 1)) * 100;
        const y = 30 - (point.value / 10) * 28;
        return `${x},${y}`;
    })
    .join(' ');

const latestPain = props.pain_series.at(-1) ?? null;

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const weeklyPct = props.weekly.target > 0 ? Math.round((props.weekly.done / props.weekly.target) * 100) : 0;

const statusClasses: Record<RecentSession['status'], string> = {
    done: 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400',
    active: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-400',
    planned: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-400',
    abandoned: 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-400',
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="grid auto-rows-min gap-4 md:grid-cols-3">
                <Link
                    href="/today"
                    class="flex flex-col justify-between rounded-xl border border-sidebar-border/70 p-5 transition-colors hover:border-primary/50 dark:border-sidebar-border"
                >
                    <div class="text-sm text-muted-foreground">Due today</div>
                    <div class="mt-2 text-4xl font-semibold">{{ due_count }}</div>
                    <div class="mt-2 text-sm text-primary underline underline-offset-4">Go to Today →</div>
                </Link>

                <div class="flex flex-col justify-between rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div class="text-sm text-muted-foreground">This week (weekly exercises)</div>
                    <div class="mt-2 text-4xl font-semibold">
                        {{ weekly.done }}<span class="text-xl text-muted-foreground">/{{ weekly.target }}</span>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-muted">
                        <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${weeklyPct}%` }" />
                    </div>
                </div>

                <div class="flex flex-col justify-between rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div class="text-sm text-muted-foreground">Streak</div>
                    <div class="mt-2 text-4xl font-semibold">
                        {{ streak_days }}<span class="text-xl text-muted-foreground"> day{{ streak_days === 1 ? '' : 's' }}</span>
                    </div>
                    <div class="mt-2 text-sm text-muted-foreground">{{ streak_days > 0 ? 'Keep it rolling 🔥' : 'Do one exercise to start a streak' }}</div>
                </div>
            </div>

            <div class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-medium uppercase tracking-wide text-muted-foreground">Pain trend</h2>
                    <span v-if="latestPain" class="text-sm text-muted-foreground">
                        latest <span class="font-semibold text-foreground">{{ latestPain.value }}/10</span> · {{ latestPain.date }}
                    </span>
                </div>
                <svg v-if="pain_series.length >= 2" viewBox="0 0 100 32" preserveAspectRatio="none" class="h-16 w-full text-primary">
                    <line x1="0" y1="30" x2="100" y2="30" stroke="currentColor" stroke-opacity="0.15" vector-effect="non-scaling-stroke" />
                    <polyline :points="painPoints" fill="none" stroke="currentColor" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                </svg>
                <p v-else class="text-muted-foreground">Log pain when completing a session to see a trend here.</p>
            </div>

            <div class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-medium uppercase tracking-wide text-muted-foreground">Daily reminder</h2>
                        <p class="mt-1 text-sm text-muted-foreground">A push notification when exercises are still due.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <input
                            v-if="reminderEnabled"
                            v-model="reminderTime"
                            data-testid="reminder-time"
                            type="time"
                            class="rounded-md border border-sidebar-border/70 bg-background px-2 py-1 text-sm dark:border-sidebar-border"
                            @change="onReminderTimeChange"
                        />
                        <button
                            data-testid="reminder-toggle"
                            :disabled="reminderBusy"
                            class="rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50"
                            :class="reminderEnabled ? 'bg-muted text-foreground' : 'bg-primary text-primary-foreground'"
                            @click="toggleReminder"
                        >
                            {{ reminderEnabled ? 'Turn off' : 'Turn on' }}
                        </button>
                    </div>
                </div>
                <p v-if="reminderError" class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ reminderError }}</p>
            </div>

            <div class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-muted-foreground">Recent sessions</h2>
                <p v-if="recent_sessions.length === 0" class="text-muted-foreground">
                    No sessions yet — head to <Link href="/today" class="text-primary underline underline-offset-4">Today</Link> and start one.
                </p>
                <ul v-else class="flex flex-col divide-y divide-sidebar-border/70">
                    <li v-for="s in recent_sessions" :key="s.id">
                        <Link :href="`/sessions/${s.id}`" class="flex items-center justify-between gap-3 py-3">
                            <span class="font-medium">{{ s.date }}</span>
                            <span class="text-sm text-muted-foreground">{{ s.items_done }}/{{ s.items_total }} exercises done</span>
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="statusClasses[s.status]">{{ s.status }}</span>
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
