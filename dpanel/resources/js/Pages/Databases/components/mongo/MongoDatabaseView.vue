<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import MongoCommand from './MongoCommand.vue';
import { formatBytes } from './useMongoAdmin';

// One database: its stats and collections, plus the command runner.
const props = defineProps({
    database: { type: Object, required: true },
    run: { type: Function, required: true },
    busy: { type: String, default: '' },
});

const emit = defineEmits(['open', 'new-collection', 'rename', 'drop-collection', 'drop-database']);

const stats = ref(null);

const loadStats = async () => {
    stats.value = null;
    try {
        stats.value = (await props.run({ action: 'command', db: props.database.name, command: { dbStats: 1 } }, { key: 'stats' })).result;
    } catch {
        stats.value = null;
    }
};

onMounted(loadStats);
watch(() => props.database.name, loadStats);

const num = (value) => Number(value?.$numberLong ?? value?.$numberDouble ?? value ?? 0);
const cards = computed(() => (stats.value ? [
    { label: 'Collections', value: num(stats.value.collections) },
    { label: 'Views', value: num(stats.value.views) },
    { label: 'Documents', value: num(stats.value.objects).toLocaleString() },
    { label: 'Data size', value: formatBytes(num(stats.value.dataSize)) },
    { label: 'Storage', value: formatBytes(num(stats.value.storageSize)) },
    { label: 'Indexes', value: `${num(stats.value.indexes)} · ${formatBytes(num(stats.value.indexSize))}` },
] : []));
</script>

<template>
    <div class="space-y-4">
        <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="flex items-center gap-2 text-base font-semibold"><i class="bi bi-database text-amber-500"></i>{{ database.name }}</h2>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700" @click="emit('new-collection')"><i class="bi bi-plus-lg"></i> New collection</button>
                    <button v-if="!database.system" type="button" class="rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950/30" @click="emit('drop-database')"><i class="bi bi-trash"></i> Drop database</button>
                </div>
            </div>
            <div v-if="cards.length" class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div v-for="card in cards" :key="card.label" class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-950">
                    <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ card.label }}</p>
                    <p class="mt-0.5 text-sm font-semibold">{{ card.value }}</p>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-2">Collection</th>
                            <th class="px-4 py-2">Type</th>
                            <th class="px-4 py-2 text-right">Documents</th>
                            <th class="px-4 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="item in database.collections" :key="item.name" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-4 py-2">
                                <button type="button" class="font-medium text-blue-600 hover:underline dark:text-blue-300" @click="emit('open', item.name)">{{ item.name }}</button>
                            </td>
                            <td class="px-4 py-2 text-slate-500">{{ item.type }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ item.type === 'view' ? '—' : Number(item.count).toLocaleString() }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <button type="button" class="rounded px-2 py-1 text-xs hover:bg-slate-100 dark:hover:bg-slate-800" @click="emit('open', item.name)"><i class="bi bi-search"></i> Browse</button>
                                <button type="button" class="rounded px-2 py-1 text-xs hover:bg-slate-100 dark:hover:bg-slate-800" @click="emit('rename', item.name)"><i class="bi bi-pencil"></i> Rename</button>
                                <button type="button" class="rounded px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/30" @click="emit('drop-collection', item.name)"><i class="bi bi-trash"></i> Drop</button>
                            </td>
                        </tr>
                        <tr v-if="!database.collections.length">
                            <td colspan="4" class="px-4 py-6 text-center text-slate-400">No collections yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <MongoCommand :db="database.name" :run="run" />
    </div>
</template>
