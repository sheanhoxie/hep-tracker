<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';

const props = defineProps<{
    label: string;
    placeholder?: string;
}>();

const items = defineModel<string[]>({ required: true });

const add = () => {
    items.value = [...items.value, ''];
};
const remove = (i: number) => {
    items.value = items.value.filter((_, idx) => idx !== i);
};
const move = (i: number, delta: -1 | 1) => {
    const next = [...items.value];
    const [row] = next.splice(i, 1);
    next.splice(i + delta, 0, row);
    items.value = next;
};
const update = (i: number, value: string) => {
    items.value = items.value.map((item, idx) => (idx === i ? value : item));
};
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-center justify-between">
            <Label>{{ props.label }}</Label>
            <Button type="button" variant="outline" size="sm" @click="add"><Plus class="h-4 w-4" /> Add</Button>
        </div>
        <div v-for="(item, i) in items" :key="i" class="flex items-center gap-2">
            <Input :model-value="item" :placeholder="props.placeholder" class="flex-1" @update:model-value="(v) => update(i, String(v))" />
            <Button type="button" variant="ghost" size="icon" :disabled="i === 0" @click="move(i, -1)"><ArrowUp class="h-4 w-4" /></Button>
            <Button type="button" variant="ghost" size="icon" :disabled="i === items.length - 1" @click="move(i, 1)"
                ><ArrowDown class="h-4 w-4"
            /></Button>
            <Button type="button" variant="ghost" size="icon" @click="remove(i)"><Trash2 class="h-4 w-4" /></Button>
        </div>
    </div>
</template>
