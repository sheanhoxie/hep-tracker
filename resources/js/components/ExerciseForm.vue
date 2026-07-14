<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StringListInput from '@/components/StringListInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { InertiaForm } from '@inertiajs/vue3';

export interface ExerciseFormFields {
    canonical_name: string;
    description: string;
    steps: string[];
    body_region: string;
    movement_pattern: string;
    laterality: 'bilateral' | 'unilateral' | 'alternating';
    equipment: string[];
    cues: string[];
}

defineProps<{
    form: InertiaForm<ExerciseFormFields>;
    lateralityFrozen: boolean;
}>();
</script>

<!-- eslint-disable vue/no-mutating-props -- InertiaForm is a reactive proxy designed for two-way binding; the parent owns submission -->
<template>
    <div class="flex flex-col gap-6">
        <div class="grid gap-2">
            <Label for="canonical_name">Name</Label>
            <Input id="canonical_name" v-model="form.canonical_name" required placeholder="Eccentric Single-Leg Squat" />
            <InputError :message="form.errors.canonical_name" />
        </div>

        <div class="grid gap-2">
            <Label for="description">Description</Label>
            <textarea
                id="description"
                v-model="form.description"
                rows="3"
                class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            />
            <InputError :message="form.errors.description" />
        </div>

        <StringListInput v-model="form.steps" label="Form notes / steps" placeholder="e.g. keep hips level" />
        <InputError :message="form.errors.steps" />

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="body_region">Body region</Label>
                <Input id="body_region" v-model="form.body_region" placeholder="hip" />
                <InputError :message="form.errors.body_region" />
            </div>
            <div class="grid gap-2">
                <Label for="movement_pattern">Movement pattern</Label>
                <Input id="movement_pattern" v-model="form.movement_pattern" placeholder="hinge/bridge" />
                <InputError :message="form.errors.movement_pattern" />
            </div>
            <div class="grid gap-2">
                <Label for="laterality">Laterality</Label>
                <select
                    id="laterality"
                    v-model="form.laterality"
                    :disabled="lateralityFrozen"
                    class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <option value="bilateral">bilateral</option>
                    <option value="unilateral">unilateral</option>
                    <option value="alternating">alternating</option>
                </select>
                <p class="text-xs text-muted-foreground">
                    {{
                        lateralityFrozen ? 'Frozen while prescribed — revise the program to change it.' : 'Determines how sets expand in the player.'
                    }}
                </p>
                <InputError :message="form.errors.laterality" />
            </div>
        </div>

        <StringListInput v-model="form.equipment" label="Equipment" placeholder="e.g. loop band" />
        <InputError :message="form.errors.equipment" />

        <StringListInput v-model="form.cues" label="Stock cues" placeholder="e.g. knee tracks over toes" />
        <InputError :message="form.errors.cues" />
    </div>
</template>
