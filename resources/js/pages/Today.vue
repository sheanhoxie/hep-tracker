<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';

interface DueItem {
    prescription_id: number;
    exercise_slug: string;
    exercise_name: string;
    thumb: string;
    dosage: string;
    cadence: 'daily' | 'weekly';
    remaining_this_week: number | null;
    notes: string | null;
}

const props = defineProps<{
    date: string;
    due: DueItem[];
    active_session_id: number | null;
}>();

function startSession(): void {
    router.post('/sessions');
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Today',
        href: '/today',
    },
];

const daily = props.due.filter((item) => item.cadence === 'daily');
const weekly = props.due.filter((item) => item.cadence === 'weekly');
</script>

<template>
    <Head title="Today" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Due today</h1>
                    <p class="text-sm text-muted-foreground">{{ date }}</p>
                </div>
                <Link
                    v-if="active_session_id"
                    :href="`/sessions/${active_session_id}`"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                >
                    Resume session
                </Link>
                <button
                    v-else-if="due.length > 0"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                    @click="startSession"
                >
                    Start session
                </button>
            </div>

            <p v-if="due.length === 0" class="text-muted-foreground">
                All done for today. 🎉
            </p>

            <section v-if="daily.length > 0" class="flex flex-col gap-2">
                <h2 class="text-sm font-medium uppercase tracking-wide text-muted-foreground">Daily</h2>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="item in daily"
                        :key="item.prescription_id"
                        class="flex items-center gap-3 rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border"
                    >
                        <img :src="item.thumb" :alt="item.exercise_name" class="h-12 w-12 shrink-0 rounded-lg" />
                        <div>
                            <div class="font-medium">{{ item.exercise_name }}</div>
                            <div class="text-sm text-muted-foreground">{{ item.dosage }}</div>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="weekly.length > 0" class="flex flex-col gap-2">
                <h2 class="text-sm font-medium uppercase tracking-wide text-muted-foreground">This week</h2>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="item in weekly"
                        :key="item.prescription_id"
                        class="flex items-center gap-3 rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border"
                    >
                        <img :src="item.thumb" :alt="item.exercise_name" class="h-12 w-12 shrink-0 rounded-lg" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <div class="font-medium">{{ item.exercise_name }}</div>
                                <div class="shrink-0 text-sm text-muted-foreground">{{ item.remaining_this_week }}x left this week</div>
                            </div>
                            <div class="text-sm text-muted-foreground">{{ item.dosage }}</div>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
