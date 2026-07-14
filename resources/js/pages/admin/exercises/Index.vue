<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Plus, VideoOff } from 'lucide-vue-next';

interface ExerciseRow {
    slug: string;
    name: string;
    region: string | null;
    pattern: string | null;
    laterality: string;
    thumb: string | null;
    has_video: boolean;
    prescribed: boolean;
}

defineProps<{ exercises: ExerciseRow[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Exercises', href: '/admin/exercises' }];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Exercises" />

        <div class="flex flex-col gap-4 p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Exercises</h1>
                <Button as-child>
                    <Link href="/admin/exercises/create"><Plus class="h-4 w-4" /> New exercise</Link>
                </Button>
            </div>

            <ul class="flex flex-col gap-2">
                <li
                    v-for="ex in exercises"
                    :key="ex.slug"
                    class="flex items-center gap-3 rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border"
                >
                    <img v-if="ex.thumb" :src="ex.thumb" :alt="ex.name" class="h-12 w-12 shrink-0 rounded-lg" />
                    <div v-else class="h-12 w-12 shrink-0 rounded-lg bg-muted" />
                    <div class="min-w-0 flex-1">
                        <Link :href="`/admin/exercises/${ex.slug}/edit`" class="font-medium hover:underline">{{ ex.name }}</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                            <span v-if="ex.region" class="rounded-full border px-2 py-0.5">{{ ex.region }}</span>
                            <span v-if="ex.pattern" class="rounded-full border px-2 py-0.5">{{ ex.pattern }}</span>
                            <span class="rounded-full border px-2 py-0.5">{{ ex.laterality }}</span>
                            <span v-if="ex.prescribed" class="rounded-full border border-green-600/40 px-2 py-0.5 text-green-600">prescribed</span>
                        </div>
                    </div>
                    <span v-if="!ex.has_video" class="flex items-center gap-1 text-xs text-amber-600" title="No demo video">
                        <VideoOff class="h-4 w-4" /> no video
                    </span>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
