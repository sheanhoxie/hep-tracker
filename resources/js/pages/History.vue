<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface HistorySession {
    id: number;
    date: string;
    status: 'planned' | 'active' | 'done' | 'abandoned';
    items_done: number;
    items_total: number;
    pain: number | null;
    rpe: number | null;
    note: string | null;
}

defineProps<{
    sessions: HistorySession[];
    prev: string | null;
    next: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'History',
        href: '/history',
    },
];

const statusClasses: Record<HistorySession['status'], string> = {
    done: 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400',
    active: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-400',
    planned: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-400',
    abandoned: 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-400',
};
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Session history</h1>

            <p v-if="sessions.length === 0" class="text-muted-foreground">
                No sessions yet — head to <Link href="/today" class="text-primary underline underline-offset-4">Today</Link> and start one.
            </p>

            <ul v-else class="flex flex-col gap-2">
                <li v-for="s in sessions" :key="s.id">
                    <Link
                        :href="`/sessions/${s.id}`"
                        class="flex flex-col gap-1 rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:border-primary/50 dark:border-sidebar-border"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span class="font-medium">{{ s.date }}</span>
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-muted-foreground">{{ s.items_done }}/{{ s.items_total }} exercises</span>
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="statusClasses[s.status]">{{ s.status }}</span>
                            </div>
                        </div>
                        <div v-if="s.pain !== null || s.rpe !== null || s.note" class="flex flex-wrap items-center gap-2">
                            <span v-if="s.pain !== null" class="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-700 dark:bg-rose-950 dark:text-rose-400">
                                pain {{ s.pain }}/10
                            </span>
                            <span v-if="s.rpe !== null" class="rounded-full bg-violet-100 px-2.5 py-0.5 text-xs font-medium text-violet-700 dark:bg-violet-950 dark:text-violet-400">
                                RPE {{ s.rpe }}/10
                            </span>
                            <span v-if="s.note" class="text-xs italic text-muted-foreground">{{ s.note }}</span>
                        </div>
                    </Link>
                </li>
            </ul>

            <div v-if="prev || next" class="flex justify-between">
                <Link v-if="prev" :href="prev" class="text-sm text-primary underline underline-offset-4">← Newer</Link>
                <span v-else />
                <Link v-if="next" :href="next" class="text-sm text-primary underline underline-offset-4">Older →</Link>
            </div>
        </div>
    </AppLayout>
</template>
