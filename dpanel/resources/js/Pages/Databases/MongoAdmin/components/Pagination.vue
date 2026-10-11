<script setup>
import { computed } from 'vue';

// Page numbers under the documents, mongo-express style.
const props = defineProps({
    skip: { type: Number, required: true },
    limit: { type: Number, required: true },
    total: { type: Number, required: true },
});

const emit = defineEmits(['page', 'limit']);

const pages = computed(() => Math.max(1, Math.ceil(props.total / props.limit)));
const current = computed(() => Math.floor(props.skip / props.limit) + 1);
const visible = computed(() => {
    const start = Math.max(1, Math.min(current.value - 2, pages.value - 4));
    return Array.from({ length: Math.min(5, pages.value) }, (_, i) => start + i);
});
const goTo = (n) => emit('page', (Math.min(Math.max(n, 1), pages.value) - 1) * props.limit);
const btn = 'min-w-[2rem] rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800';
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
        <span>
            {{ total ? (skip + 1).toLocaleString() : 0 }}–{{ Math.min(skip + limit, total).toLocaleString() }} of {{ total.toLocaleString() }}
        </span>
        <div class="flex items-center gap-1">
            <button type="button" :class="btn" :disabled="current === 1" @click="goTo(1)"><i class="bi bi-chevron-double-left"></i></button>
            <button type="button" :class="btn" :disabled="current === 1" @click="goTo(current - 1)"><i class="bi bi-chevron-left"></i></button>
            <button v-for="n in visible" :key="n" type="button" :class="[btn, n === current && 'border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-600']" @click="goTo(n)">{{ n }}</button>
            <button type="button" :class="btn" :disabled="current === pages" @click="goTo(current + 1)"><i class="bi bi-chevron-right"></i></button>
            <button type="button" :class="btn" :disabled="current === pages" @click="goTo(pages)"><i class="bi bi-chevron-double-right"></i></button>
        </div>
        <label class="flex items-center gap-2">Per page
            <select :value="limit" class="rounded-md border-slate-300 py-1 pl-2 pr-7 text-xs dark:border-slate-700 dark:bg-slate-900" @change="emit('limit', Number($event.target.value))">
                <option v-for="n in [10, 20, 50, 100, 200]" :key="n" :value="n">{{ n }}</option>
            </select>
        </label>
    </div>
</template>
