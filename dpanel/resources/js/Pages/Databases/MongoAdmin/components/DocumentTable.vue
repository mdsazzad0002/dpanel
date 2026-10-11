<script setup>
import { computed } from 'vue';
import JsonView from './JsonView.vue';
import { preview } from '../lib/mongoJson';

// The documents as a table (one column per field, like mongo-express) or as
// JSON cards. A row opens the document; the trash icon deletes it.
const props = defineProps({
    documents: { type: Array, default: () => [] },
    view: { type: String, default: 'table' },
    editable: { type: Boolean, default: true },
});

const emit = defineEmits(['open', 'delete']);

const columns = computed(() => {
    const keys = new Set();
    props.documents.forEach((doc) => Object.keys(doc).forEach((key) => keys.add(key)));
    const list = [...keys];
    list.sort((a, b) => (a === '_id' ? -1 : b === '_id' ? 1 : 0));
    return list.slice(0, 30);
});

const cellClass = (value) => {
    if (value === undefined) return 'text-slate-300 dark:text-slate-600';
    if (value === null || typeof value === 'boolean') return 'text-violet-600 dark:text-violet-300';
    if (typeof value === 'number') return 'text-blue-700 dark:text-blue-300';
    if (typeof value === 'object') return 'text-pink-700 dark:text-pink-300';
    return 'text-slate-700 dark:text-slate-200';
};
const canOpen = (doc) => props.editable && doc._id !== undefined;
</script>

<template>
    <div v-if="!documents.length" class="px-4 py-10 text-center text-sm text-slate-400">No documents.</div>

    <div v-else-if="view === 'table'" class="overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead class="sticky top-0 bg-slate-50 text-left font-mono text-slate-500 dark:bg-slate-950">
                <tr class="border-b border-slate-200 dark:border-slate-800">
                    <th v-if="editable" class="w-10 px-3 py-2"></th>
                    <th v-for="column in columns" :key="column" class="whitespace-nowrap px-3 py-2 font-semibold">{{ column }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-mono dark:divide-slate-800">
                <tr v-for="(doc, i) in documents" :key="i" class="group hover:bg-emerald-50/60 dark:hover:bg-emerald-950/20" :class="canOpen(doc) && 'cursor-pointer'" @click="canOpen(doc) && emit('open', doc)">
                    <td v-if="editable" class="px-3 py-1.5">
                        <button v-if="doc._id !== undefined" type="button" class="rounded p-1 text-slate-400 opacity-60 hover:bg-red-50 hover:text-red-600 group-hover:opacity-100 dark:hover:bg-red-950/40" title="Delete" @click.stop="emit('delete', doc)"><i class="bi bi-trash"></i></button>
                    </td>
                    <td v-for="column in columns" :key="column" class="max-w-[18rem] truncate whitespace-nowrap px-3 py-1.5" :class="cellClass(doc[column])" :title="preview(doc[column], 400)">
                        {{ doc[column] === undefined ? '—' : preview(doc[column], 48) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-else class="divide-y divide-slate-100 dark:divide-slate-800">
        <div v-for="(doc, i) in documents" :key="i" class="group relative px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/30">
            <div v-if="editable && doc._id !== undefined" class="absolute right-3 top-2 flex gap-1 opacity-60 group-hover:opacity-100">
                <button type="button" class="rounded-md border border-slate-300 px-2 py-0.5 text-xs hover:bg-white dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('open', doc)"><i class="bi bi-pencil"></i> Edit</button>
                <button type="button" class="rounded-md border border-red-200 px-2 py-0.5 text-xs text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/40" @click="emit('delete', doc)"><i class="bi bi-trash"></i></button>
            </div>
            <JsonView :value="doc" max-height="22rem" />
        </div>
    </div>
</template>
