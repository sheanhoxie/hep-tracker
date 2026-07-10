<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

interface LibraryExercise {
    slug: string;
    name: string;
    region: string;
    laterality: string;
    steps: string[];
    cues: string[];
    aliases: string[];
    thumb: string | null;
    video: string | null;
    dosage: string | null;
    frequency: 'daily' | 'per_week' | 'as_needed' | null;
    notes: string | null;
}

defineProps<{
    exercises: LibraryExercise[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Library',
        href: '/library',
    },
];

const playing = ref<string | null>(null);

const frequencyLabels: Record<string, string> = {
    daily: 'Daily',
    per_week: '3x / week',
    as_needed: 'As needed',
};
</script>

<template>
    <Head title="Library" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Exercise library</h1>
                <p class="text-sm text-muted-foreground">
                    All {{ exercises.length }} exercises from the program. Demo videos are open-source placeholders
                    (wger.de CC-BY-SA · Wikimedia Commons CC-BY) until real form videos are recorded.
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="ex in exercises"
                    :key="ex.slug"
                    class="flex flex-col rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                >
                    <div class="flex items-start gap-3">
                        <img v-if="ex.thumb" :src="ex.thumb" :alt="ex.name" class="h-16 w-16 shrink-0 rounded-xl" />
                        <div class="min-w-0">
                            <div class="font-medium leading-tight">{{ ex.name }}</div>
                            <div v-if="ex.aliases.length" class="mt-0.5 truncate text-xs italic text-muted-foreground">
                                “{{ ex.aliases.join('” · “') }}”
                            </div>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                <span class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ ex.region }}</span>
                                <span v-if="ex.frequency" class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                    {{ frequencyLabels[ex.frequency] }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div v-if="ex.dosage" class="mt-3 text-sm text-muted-foreground">{{ ex.dosage }}</div>
                    <p v-if="ex.cues.length" class="mt-1 text-xs italic text-muted-foreground">{{ ex.cues.join(' · ') }}</p>
                    <p v-if="ex.notes" class="mt-2 text-xs text-amber-600">{{ ex.notes }}</p>

                    <div class="mt-auto pt-3">
                        <video
                            v-if="playing === ex.slug && ex.video"
                            :src="ex.video"
                            controls
                            autoplay
                            playsinline
                            class="w-full rounded-lg"
                        />
                        <button
                            v-else-if="ex.video"
                            class="rounded-lg border border-sidebar-border/70 px-3 py-1.5 text-xs font-medium hover:border-primary/50"
                            @click="playing = ex.slug"
                        >
                            ▶ Demo video <span class="text-muted-foreground">(placeholder)</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
