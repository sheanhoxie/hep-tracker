<script setup lang="ts">
import ExerciseForm, { type ExerciseFormFields } from '@/components/ExerciseForm.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Exercises', href: '/admin/exercises' },
    { title: 'New', href: '/admin/exercises/create' },
];

const form = useForm<ExerciseFormFields>({
    canonical_name: '',
    description: '',
    steps: [],
    body_region: '',
    movement_pattern: '',
    laterality: 'bilateral',
    equipment: [],
    cues: [],
});

const submit = () => {
    form.post('/admin/exercises');
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="New exercise" />

        <form class="flex max-w-2xl flex-col gap-6 p-4" @submit.prevent="submit">
            <h1 class="text-xl font-semibold">New exercise</h1>
            <ExerciseForm :form="form" :laterality-frozen="false" />
            <div>
                <Button :disabled="form.processing">Create</Button>
            </div>
        </form>
    </AppLayout>
</template>
