<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { TransitionRoot } from '@headlessui/vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface GridRow {
    exercise_id: number;
    exercise_name: string;
    exercise_slug: string;
    sets: number | null;
    reps: number | null;
    hold_sec: number | null;
    eccentric_sec: number | null;
    rest_sec: number | null;
    per_side: boolean;
    to_fatigue: boolean;
    duration_sec: number | null;
    frequency_kind: 'daily' | 'per_week' | 'as_needed';
    times_per_week: number | null;
    time_of_day: string | null;
    sort_order: number | null;
    notes: string | null;
}

const props = defineProps<{
    program_title: string;
    base_version_no: number;
    effective_from: string;
    source_note: string | null;
    rows: GridRow[];
    exercises: Array<{ id: number; name: string }>;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Program', href: '/admin/program' }];

const numericFields = ['sets', 'reps', 'hold_sec', 'eccentric_sec', 'rest_sec', 'duration_sec', 'times_per_week', 'sort_order'] as const;

const form = useForm({
    base_version_no: props.base_version_no,
    source_note: '',
    rows: props.rows.map((r) => ({ ...r })),
});

const savedVersion = ref<number | null>(null);

const submit = () => {
    const target = Number(form.base_version_no) + 1;
    form.transform((data) => ({
        base_version_no: data.base_version_no,
        source_note: data.source_note || null,
        rows: data.rows.map((row) => ({
            exercise_id: row.exercise_id,
            per_side: row.per_side,
            to_fatigue: row.to_fatigue,
            frequency_kind: row.frequency_kind,
            time_of_day: row.time_of_day,
            notes: row.notes,
            ...Object.fromEntries(numericFields.map((f) => [f, row[f] === null || String(row[f]) === '' ? null : Number(row[f])])),
        })),
    })).put('/admin/program', {
        preserveScroll: true,
        onSuccess: () => {
            savedVersion.value = target;
            // props are refreshed by the redirect — resync the grid so the next
            // save carries the new base instead of a stale one
            form.defaults({ base_version_no: props.base_version_no, source_note: '', rows: props.rows.map((r) => ({ ...r })) });
            form.reset();
        },
    });
};

const removeRow = (i: number) => {
    form.rows = form.rows.filter((_, idx) => idx !== i);
};

const addExerciseId = ref<number | ''>('');
const available = computed(() => {
    const used = new Set(form.rows.map((r) => r.exercise_id));
    return props.exercises.filter((e) => !used.has(e.id));
});

const addRow = () => {
    const exercise = props.exercises.find((e) => e.id === addExerciseId.value);
    if (!exercise) return;
    form.rows = [
        ...form.rows,
        {
            exercise_id: exercise.id,
            exercise_name: exercise.name,
            exercise_slug: '',
            sets: null,
            reps: null,
            hold_sec: null,
            eccentric_sec: null,
            rest_sec: null,
            per_side: false,
            to_fatigue: false,
            duration_sec: null,
            frequency_kind: 'daily',
            times_per_week: null,
            time_of_day: null,
            sort_order: Math.max(0, ...form.rows.map((r) => r.sort_order ?? 0)) + 1,
            notes: null,
        },
    ];
    addExerciseId.value = '';
};

const rowErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key.startsWith('rows.'))
        .map(([key, message]) => {
            const idx = Number(key.split('.')[1]);
            const name = form.rows[idx]?.exercise_name ?? `row ${idx + 1}`;
            return `${name}: ${message}`;
        }),
);

const selectClass =
    'flex h-8 rounded-md border border-input bg-background px-2 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
const cellClass = 'h-8 w-16 px-2 text-sm';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Program" />

        <div class="flex flex-col gap-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">{{ program_title }}</h1>
                <p class="text-sm text-muted-foreground">
                    v{{ base_version_no }} · effective {{ effective_from }} — saving creates a new version effective today. Completed work carries
                    over.
                </p>
            </div>

            <p
                v-if="form.errors.base_version_no"
                class="flex items-center justify-between rounded-lg border border-amber-500/50 bg-amber-500/10 p-3 text-sm text-amber-700"
            >
                {{ form.errors.base_version_no }}
                <Button type="button" variant="outline" size="sm" @click="router.reload()">Reload</Button>
            </p>

            <TransitionRoot
                :show="form.recentlySuccessful"
                enter="transition ease-in-out"
                enter-from="opacity-0"
                leave="transition ease-in-out"
                leave-to="opacity-0"
            >
                <p class="rounded-lg border border-green-600/40 bg-green-600/10 p-3 text-sm text-green-700">
                    Revision v{{ savedVersion }} is live (effective today).
                </p>
            </TransitionRoot>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full min-w-[1100px] text-left text-sm">
                    <thead class="border-b bg-muted/40 text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="p-2">Exercise</th>
                            <th class="p-2">Freq</th>
                            <th class="p-2">×/wk</th>
                            <th class="p-2">Sets</th>
                            <th class="p-2">Reps</th>
                            <th class="p-2">Hold s</th>
                            <th class="p-2">Ecc s</th>
                            <th class="p-2">Rest s</th>
                            <th class="p-2">Dur s</th>
                            <th class="p-2">Side</th>
                            <th class="p-2">Fatigue</th>
                            <th class="p-2">Time</th>
                            <th class="p-2">Notes</th>
                            <th class="p-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in form.rows" :key="row.exercise_id" class="border-b last:border-0">
                            <td class="p-2 font-medium">{{ row.exercise_name }}</td>
                            <td class="p-2">
                                <select v-model="row.frequency_kind" :class="selectClass">
                                    <option value="daily">daily</option>
                                    <option value="per_week">per week</option>
                                    <option value="as_needed">as needed</option>
                                </select>
                            </td>
                            <td class="p-2">
                                <Input
                                    v-model="row.times_per_week"
                                    type="number"
                                    min="1"
                                    max="7"
                                    :class="cellClass"
                                    :disabled="row.frequency_kind !== 'per_week'"
                                />
                            </td>
                            <td class="p-2"><Input v-model="row.sets" type="number" min="1" :class="cellClass" /></td>
                            <td class="p-2"><Input v-model="row.reps" type="number" min="1" :class="cellClass" /></td>
                            <td class="p-2"><Input v-model="row.hold_sec" type="number" min="1" :class="cellClass" /></td>
                            <td class="p-2"><Input v-model="row.eccentric_sec" type="number" min="1" :class="cellClass" /></td>
                            <td class="p-2"><Input v-model="row.rest_sec" type="number" min="0" :class="cellClass" /></td>
                            <td class="p-2"><Input v-model="row.duration_sec" type="number" min="1" :class="cellClass" /></td>
                            <td class="p-2 text-center"><input v-model="row.per_side" type="checkbox" /></td>
                            <td class="p-2 text-center"><input v-model="row.to_fatigue" type="checkbox" /></td>
                            <td class="p-2">
                                <select v-model="row.time_of_day" :class="selectClass">
                                    <option :value="null">–</option>
                                    <option value="morning">morning</option>
                                    <option value="evening">evening</option>
                                    <option value="any">any</option>
                                </select>
                            </td>
                            <td class="p-2"><Input v-model="row.notes" :class="'h-8 w-40 px-2 text-sm'" /></td>
                            <td class="p-2">
                                <Button type="button" variant="ghost" size="icon" @click="removeRow(i)"><Trash2 class="h-4 w-4" /></Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-end gap-3">
                <div class="grid gap-2">
                    <Label for="add-exercise">Add exercise</Label>
                    <select id="add-exercise" v-model="addExerciseId" :class="selectClass + ' h-9 min-w-56'">
                        <option value="" disabled>Pick an exercise…</option>
                        <option v-for="e in available" :key="e.id" :value="e.id">{{ e.name }}</option>
                    </select>
                </div>
                <Button type="button" variant="outline" :disabled="addExerciseId === ''" @click="addRow"><Plus class="h-4 w-4" /> Add</Button>
            </div>

            <div v-if="rowErrors.length > 0" class="rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-700">
                <p class="font-medium">Fix before saving:</p>
                <ul class="mt-1 list-inside list-disc">
                    <li v-for="(err, i) in rowErrors" :key="i">{{ err }}</li>
                </ul>
            </div>

            <div class="flex max-w-xl items-end gap-3">
                <div class="grid flex-1 gap-2">
                    <Label for="source_note">Revision note</Label>
                    <Input id="source_note" v-model="form.source_note" placeholder="e.g. the PT's dosage corrections, July visit" />
                    <InputError :message="form.errors.source_note" />
                </div>
                <Button :disabled="form.processing" @click="submit">Save as v{{ base_version_no + 1 }}</Button>
            </div>
        </div>
    </AppLayout>
</template>
