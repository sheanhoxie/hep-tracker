<script setup lang="ts">
import ExerciseForm, { type ExerciseFormFields } from '@/components/ExerciseForm.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { TransitionRoot } from '@headlessui/vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

interface MediaRow {
    id: number;
    kind: string;
    url: string;
}

interface ExerciseProps {
    slug: string;
    canonical_name: string;
    description: string | null;
    steps: string[];
    body_region: string | null;
    movement_pattern: string | null;
    laterality: 'bilateral' | 'unilateral' | 'alternating';
    equipment: string[];
    cues: string[];
    media: MediaRow[];
    prescribed: boolean;
}

const props = defineProps<{ exercise: ExerciseProps }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Exercises', href: '/admin/exercises' },
    { title: props.exercise.canonical_name, href: `/admin/exercises/${props.exercise.slug}/edit` },
];

const page = usePage<SharedData>();

const form = useForm<ExerciseFormFields>({
    canonical_name: props.exercise.canonical_name,
    description: props.exercise.description ?? '',
    steps: [...props.exercise.steps],
    body_region: props.exercise.body_region ?? '',
    movement_pattern: props.exercise.movement_pattern ?? '',
    laterality: props.exercise.laterality,
    equipment: [...props.exercise.equipment],
    cues: [...props.exercise.cues],
});

const submit = () => {
    form.patch(`/admin/exercises/${props.exercise.slug}`, { preserveScroll: true });
};

// ── Media ────────────────────────────────────────────────────────────────

interface MediaFormFields {
    kind: string;
    file: File | null;
    url: string;
    [key: string]: File | string | null;
}

const mediaKinds = [
    { kind: 'video', accept: 'video/mp4,video/webm,video/quicktime', hint: 'mp4 / webm / mov · ≤ 100MB' },
    { kind: 'image', accept: 'image/svg+xml,image/png,image/jpeg', hint: 'svg / png / jpg · ≤ 2MB' },
] as const;

const mediaForms = {
    video: useForm<MediaFormFields>({ kind: 'video', file: null, url: '' }),
    image: useForm<MediaFormFields>({ kind: 'image', file: null, url: '' }),
};
const mediaMode = ref<Record<string, 'file' | 'url'>>({ video: 'file', image: 'file' });
const uploadFailure = ref<string | null>(null);
const uploading = ref(false);

const currentMedia = (kind: string): MediaRow | undefined => props.exercise.media.find((m) => m.kind === kind);

const pickFile = (kind: 'video' | 'image', event: Event) => {
    const input = event.target as HTMLInputElement;
    mediaForms[kind].file = input.files?.[0] ?? null;
};

const submitMedia = (kind: 'video' | 'image') => {
    uploadFailure.value = null;
    uploading.value = true;
    const form = mediaForms[kind];
    form.transform((data) => (mediaMode.value[kind] === 'file' ? { kind: data.kind, file: data.file } : { kind: data.kind, url: data.url })).post(
        `/admin/exercises/${props.exercise.slug}/media`,
        {
            preserveScroll: true,
            onSuccess: () => form.reset(),
            onFinish: () => {
                uploading.value = false;
            },
        },
    );
};

const removeMedia = (media: MediaRow) => {
    router.delete(`/admin/exercises/${props.exercise.slug}/media/${media.id}`, { preserveScroll: true });
};

// nginx 413 responses are raw HTML that never reach Laravel validation —
// swallow the Inertia modal and show a friendly message instead (OV-A/C24).
let unlistenInvalid: (() => void) | null = null;
onMounted(() => {
    unlistenInvalid = router.on('invalid', (event) => {
        if (uploading.value) {
            event.preventDefault();
            uploadFailure.value = 'Upload failed — the file may be too large for the server.';
        }
    });
});
onUnmounted(() => unlistenInvalid?.());

// ── Danger zone ──────────────────────────────────────────────────────────

const destroying = ref(false);
const destroy = () => {
    destroying.value = true;
    router.delete(`/admin/exercises/${props.exercise.slug}`, {
        onFinish: () => {
            destroying.value = false;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="exercise.canonical_name" />

        <div class="flex max-w-2xl flex-col gap-10 p-4">
            <form class="flex flex-col gap-6" @submit.prevent="submit">
                <h1 class="text-xl font-semibold">{{ exercise.canonical_name }}</h1>
                <ExerciseForm :form="form" :laterality-frozen="exercise.prescribed" />
                <div class="flex items-center gap-4">
                    <Button :disabled="form.processing">Save</Button>
                    <TransitionRoot
                        :show="form.recentlySuccessful"
                        enter="transition ease-in-out"
                        enter-from="opacity-0"
                        leave="transition ease-in-out"
                        leave-to="opacity-0"
                    >
                        <p class="text-sm text-muted-foreground">Saved.</p>
                    </TransitionRoot>
                </div>
            </form>

            <section class="flex flex-col gap-6">
                <h2 class="text-lg font-semibold">Media</h2>
                <p v-if="uploadFailure" class="rounded-lg border border-amber-500/50 bg-amber-500/10 p-3 text-sm text-amber-700">
                    {{ uploadFailure }}
                </p>

                <div
                    v-for="{ kind, accept, hint } in mediaKinds"
                    :key="kind"
                    class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                >
                    <div class="flex items-center justify-between">
                        <Label class="capitalize">{{ kind }}</Label>
                        <span class="text-xs text-muted-foreground">{{ hint }}</span>
                    </div>

                    <template v-if="currentMedia(kind)">
                        <video v-if="kind === 'video'" :src="currentMedia(kind)!.url" controls preload="metadata" class="max-h-48 rounded-lg" />
                        <img
                            v-else
                            :src="currentMedia(kind)!.url"
                            :alt="`${exercise.canonical_name} ${kind}`"
                            class="max-h-32 self-start rounded-lg"
                        />
                        <div class="flex items-center justify-between text-xs text-muted-foreground">
                            <span class="truncate">{{ currentMedia(kind)!.url }}</span>
                            <Button type="button" variant="ghost" size="sm" @click="removeMedia(currentMedia(kind)!)">Remove</Button>
                        </div>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">None yet.</p>

                    <form class="flex flex-col gap-2" @submit.prevent="submitMedia(kind)">
                        <div class="flex gap-3 text-sm">
                            <label class="flex items-center gap-1.5"> <input v-model="mediaMode[kind]" type="radio" value="file" /> Upload </label>
                            <label class="flex items-center gap-1.5"> <input v-model="mediaMode[kind]" type="radio" value="url" /> URL </label>
                        </div>
                        <input
                            v-if="mediaMode[kind] === 'file'"
                            type="file"
                            :accept="accept"
                            class="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-sm"
                            @change="pickFile(kind, $event)"
                        />
                        <Input v-else v-model="mediaForms[kind].url" type="url" placeholder="https://…" />
                        <InputError :message="mediaForms[kind].errors.file" />
                        <InputError :message="mediaForms[kind].errors.url" />
                        <div>
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                :disabled="
                                    mediaForms[kind].processing || (mediaMode[kind] === 'file' ? !mediaForms[kind].file : !mediaForms[kind].url)
                                "
                            >
                                {{ currentMedia(kind) ? 'Replace' : 'Add' }} {{ kind }}
                            </Button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="flex flex-col gap-3 rounded-xl border border-red-500/30 p-4">
                <h2 class="text-lg font-semibold text-red-600">Danger zone</h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        exercise.prescribed
                            ? 'This exercise is prescribed in a program version — remove it via a program revision before deleting.'
                            : 'Deletes the exercise, its cues, aliases and media files. Cannot be undone.'
                    }}
                </p>
                <InputError :message="(page.props.errors as Record<string, string>).exercise" />
                <div>
                    <Button type="button" variant="destructive" :disabled="exercise.prescribed || destroying" @click="destroy">
                        Delete exercise
                    </Button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
