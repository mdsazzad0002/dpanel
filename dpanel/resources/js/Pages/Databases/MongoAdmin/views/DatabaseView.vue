<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Card from '../components/Card.vue';
import StatsTable from '../components/StatsTable.vue';
import { formatBytes, formatCount, validCollectionName } from '../lib/useMongoAdmin';

// One database, like mongo-express: its collections (view, export, drop),
// a form to add one, and the database stats.
const props = defineProps({
    database: { type: Object, default: null },
    name: { type: String, required: true },
    run: { type: Function, required: true },
    exportUrl: { type: Function, required: true },
    busy: { type: String, default: '' },
});

const emit = defineEmits(['go', 'changed']);

const newName = ref('');
const formError = ref('');
const stats = ref(null);
const statsError = ref('');
const filter = ref('');

const collections = computed(() => {
    const list = props.database?.collections || [];
    const q = filter.value.trim().toLowerCase();
    return q ? list.filter((c) => c.name.toLowerCase().includes(q)) : list;
});

const loadStats = async () => {
    statsError.value = '';
    try {
        stats.value = (await props.run({ action: 'command', db: props.name, command: { dbStats: 1 } }, { key: 'db_stats' })).result;
    } catch (e) {
        statsError.value = e.message;
    }
};
onMounted(loadStats);
watch(() => props.name, loadStats);
watch(() => props.database, loadStats);

const statsRows = computed(() => {
    const s = stats.value;
    if (!s) return [];
    return [
        ['Collections', formatCount(s.collections)],
        ['Views', formatCount(s.views)],
        ['Documents', formatCount(s.objects)],
        ['Avg document size', formatBytes(s.avgObjSize)],
        ['Data size', formatBytes(s.dataSize)],
        ['Storage size', formatBytes(s.storageSize)],
        ['Indexes', formatCount(s.indexes)],
        ['Index size', formatBytes(s.indexSize)],
        ['Total size', formatBytes(s.totalSize ?? s.storageSize)],
        ['FS used', s.fsUsedSize !== undefined ? formatBytes(s.fsUsedSize) : '—'],
    ];
});

const create = async () => {
    formError.value = '';
    if (!validCollectionName(newName.value)) {
        formError.value = 'Collection names can\'t be empty, contain $ or start with "system.".';
        return;
    }
    try {
        await props.run({ action: 'create_collection', db: props.name, collection: newName.value }, { notify: true, key: 'create_collection' });
        newName.value = '';
        emit('changed');
    } catch (e) {
        formError.value = e.message;
    }
};

const drop = async (collection) => {
    if (!confirm(`Drop collection ${props.name}.${collection.name} and all its documents?`)) return;
    try {
        await props.run({ action: 'drop_collection', db: props.name, collection: collection.name }, { notify: true });
        emit('changed');
    } catch (e) {
        formError.value = e.message;
    }
};

const dropDatabase = async () => {
    if (prompt(`Drop database "${props.name}" and everything in it? Type its name to confirm.`) !== props.name) return;
    try {
        await props.run({ action: 'drop_database', db: props.name }, { notify: true });
        emit('changed');
        emit('go');
    } catch (e) {
        formError.value = e.message;
    }
};
</script>

<template>
    <div class="space-y-6">
        <p v-if="!database" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            Database <b>{{ name }}</b> has no collections yet, so MongoDB doesn't list it. Create a collection below to keep it.
        </p>

        <Card :title="`Collections in ${name}`" icon="bi bi-collection" flush>
            <template #actions>
                <input v-model="filter" type="search" placeholder="Filter…" class="w-40 rounded-md border-slate-300 py-1 text-xs dark:border-slate-700 dark:bg-slate-900" />
                <button v-if="database && !database.system" type="button" class="rounded-md border border-red-200 px-3 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/40" @click="dropDatabase"><i class="bi bi-trash"></i> Drop database</button>
            </template>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr class="border-b border-slate-200 dark:border-slate-800">
                            <th class="w-24 px-4 py-2"></th>
                            <th class="px-4 py-2">Collection</th>
                            <th class="px-4 py-2 text-right">Documents</th>
                            <th class="px-4 py-2 text-right">Export</th>
                            <th class="w-24 px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="collection in collections" :key="collection.name" class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="px-4 py-2">
                                <button type="button" class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-700" @click="emit('go', name, collection.name)"><i class="bi bi-eye"></i> View</button>
                            </td>
                            <td class="px-4 py-2">
                                <button type="button" class="font-medium text-slate-800 hover:text-emerald-600 dark:text-slate-100" @click="emit('go', name, collection.name)">{{ collection.name }}</button>
                                <span v-if="collection.type !== 'collection'" class="ml-2 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">{{ collection.type }}</span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ formatCount(collection.count) }}</td>
                            <td class="px-4 py-2 text-right">
                                <a :href="exportUrl(name, collection.name)" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"><i class="bi bi-download"></i> JSON</a>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button v-if="!database?.system" type="button" class="rounded-md border border-red-200 px-3 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/40" @click="drop(collection)"><i class="bi bi-trash"></i> Del</button>
                            </td>
                        </tr>
                        <tr v-if="!collections.length"><td colspan="5" class="px-4 py-6 text-center text-slate-400">No collections.</td></tr>
                    </tbody>
                </table>
            </div>
            <form v-if="!database?.system" class="flex flex-wrap items-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-950/40" @submit.prevent="create">
                <label class="text-xs text-slate-500">Collection name
                    <input v-model.trim="newName" type="text" class="mt-1 block w-56 rounded-md border-slate-300 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900" />
                </label>
                <button type="submit" :disabled="busy === 'create_collection'" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"><i class="bi bi-plus-lg"></i> Create Collection</button>
                <p v-if="formError" class="w-full text-sm text-red-600 dark:text-red-400">{{ formError }}</p>
            </form>
        </Card>

        <StatsTable v-if="database" title="Database Stats" :rows="statsRows" :loading="busy === 'db_stats'" :error="statsError" />
    </div>
</template>
