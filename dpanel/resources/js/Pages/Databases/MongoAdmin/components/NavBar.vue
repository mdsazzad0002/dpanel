<script setup>
import { preview } from '../lib/mongoJson';

// The mongo-express top bar: a breadcrumb back up the tree and a quick
// jump to any database.
defineProps({
    location: { type: Object, required: true },
    databases: { type: Array, default: () => [] },
    version: { type: String, default: null },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['go', 'refresh']);

const docLabel = (doc) => (doc === 'new' ? 'New document' : preview(doc, 32));
const jump = (event) => {
    if (event.target.value) emit('go', event.target.value);
};
</script>

<template>
    <nav class="flex flex-wrap items-center gap-3 rounded-xl bg-slate-900 px-4 py-3 text-sm text-slate-200 shadow dark:bg-slate-950 dark:ring-1 dark:ring-slate-800">
        <button type="button" class="flex items-center gap-2 font-semibold text-white" @click="emit('go')">
            <span class="flex h-7 w-7 items-center justify-center rounded-md bg-emerald-600"><i class="bi bi-database-fill"></i></span>
            Mongo Admin
        </button>
        <ol class="flex min-w-0 flex-1 flex-wrap items-center gap-1 text-slate-400">
            <li v-if="location.db" class="flex items-center gap-1">
                <i class="bi bi-chevron-right text-[10px]"></i>
                <button type="button" class="rounded px-1.5 py-0.5 hover:bg-slate-800 hover:text-white" :class="!location.collection && 'text-white'" @click="emit('go', location.db)">{{ location.db }}</button>
            </li>
            <li v-if="location.collection" class="flex items-center gap-1">
                <i class="bi bi-chevron-right text-[10px]"></i>
                <button type="button" class="rounded px-1.5 py-0.5 hover:bg-slate-800 hover:text-white" :class="!location.doc && 'text-white'" @click="emit('go', location.db, location.collection)">{{ location.collection }}</button>
            </li>
            <li v-if="location.doc" class="flex min-w-0 items-center gap-1">
                <i class="bi bi-chevron-right text-[10px]"></i>
                <span class="truncate rounded px-1.5 py-0.5 font-mono text-xs text-white">{{ docLabel(location.doc) }}</span>
            </li>
        </ol>
        <div class="flex items-center gap-2">
            <span v-if="version" class="hidden rounded bg-slate-800 px-2 py-1 font-mono text-xs text-emerald-300 sm:inline">v{{ version }}</span>
            <select :value="location.db" class="rounded-md border-slate-700 bg-slate-800 py-1 pl-2 pr-8 text-xs text-slate-100" aria-label="Jump to database" @change="jump">
                <option value="">Databases…</option>
                <option v-for="db in databases" :key="db.name" :value="db.name">{{ db.name }}</option>
            </select>
            <button type="button" :disabled="loading" class="rounded-md bg-slate-800 px-2 py-1 text-xs hover:bg-slate-700 disabled:opacity-60" title="Refresh" @click="emit('refresh')">
                <i class="bi bi-arrow-clockwise" :class="loading && 'animate-spin inline-block'"></i>
            </button>
        </div>
    </nav>
</template>
