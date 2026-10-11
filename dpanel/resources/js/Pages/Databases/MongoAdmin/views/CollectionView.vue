<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Card from '../components/Card.vue';
import StatsTable from '../components/StatsTable.vue';
import QueryPanel from '../components/QueryPanel.vue';
import DocumentTable from '../components/DocumentTable.vue';
import Pagination from '../components/Pagination.vue';
import IndexesPanel from '../components/IndexesPanel.vue';
import { toShell, preview } from '../lib/mongoJson';
import { formatBytes, formatCount, validCollectionName } from '../lib/useMongoAdmin';
import { importDocuments, readImportFile } from '../lib/importDocuments';

// One collection, like mongo-express: new document / index, query, the
// documents with paging, delete what matched, rename, export / import,
// indexes and stats.
const props = defineProps({
    db: { type: String, required: true },
    collection: { type: String, required: true },
    readOnly: { type: Boolean, default: false },
    run: { type: Function, required: true },
    exportUrl: { type: Function, required: true },
});

const emit = defineEmits(['go', 'changed', 'notify']);

const tab = ref('documents');
const view = ref('table');
const query = ref({ filter: {}, sort: {}, projection: {} });
const skip = ref(0);
const limit = ref(20);
const documents = ref([]);
const total = ref(0);
const loading = ref(false);
const error = ref('');
const importing = ref('');
const fileInput = ref(null);
const renameTo = ref('');
const stats = ref(null);
const statsError = ref('');

const aggregating = computed(() => Array.isArray(query.value.pipeline));
const filtered = computed(() => Object.keys(query.value.filter || {}).length > 0);

const load = async () => {
    error.value = '';
    loading.value = true;
    try {
        if (aggregating.value) {
            const data = await props.run({ action: 'aggregate', db: props.db, collection: props.collection, pipeline: query.value.pipeline }, { key: 'find' });
            documents.value = data.documents || [];
            total.value = documents.value.length;
        } else {
            const data = await props.run({
                action: 'find', db: props.db, collection: props.collection,
                ...query.value, skip: skip.value, limit: limit.value,
            }, { key: 'find' });
            documents.value = data.documents || [];
            total.value = Number(data.total) || 0;
        }
    } catch (e) {
        error.value = e.message;
        documents.value = [];
        total.value = 0;
    } finally {
        loading.value = false;
    }
};

const loadStats = async () => {
    statsError.value = '';
    try {
        stats.value = (await props.run({ action: 'command', db: props.db, command: { collStats: props.collection } }, { key: 'coll_stats' })).result;
    } catch (e) {
        statsError.value = e.message;
    }
};

const reload = () => { load(); loadStats(); };
onMounted(reload);
watch(() => [props.db, props.collection], () => {
    query.value = { filter: {}, sort: {}, projection: {} };
    skip.value = 0;
    tab.value = 'documents';
    renameTo.value = '';
    reload();
});

const search = (next) => { query.value = next; skip.value = 0; load(); };
const setPage = (value) => { skip.value = value; load(); };
const setLimit = (value) => { limit.value = value; skip.value = 0; load(); };

const statsRows = computed(() => {
    const s = stats.value;
    if (!s) return [];
    return [
        ['Documents', formatCount(s.count)],
        ['Avg document size', formatBytes(s.avgObjSize)],
        ['Data size', formatBytes(s.size)],
        ['Storage size', formatBytes(s.storageSize)],
        ['Indexes', formatCount(s.nindexes)],
        ['Index size', formatBytes(s.totalIndexSize)],
        ['Capped', s.capped ? 'Yes' : 'No'],
        ['Namespace', s.ns || `${props.db}.${props.collection}`],
    ];
});

const open = (doc) => emit('go', props.db, props.collection, toShell(doc._id));
const newDocument = () => emit('go', props.db, props.collection, 'new');

const remove = async (doc) => {
    if (!confirm(`Delete document ${preview(doc._id, 40)}? This can't be undone.`)) return;
    try {
        await props.run({ action: 'delete', db: props.db, collection: props.collection, id: doc._id }, { notify: true });
        await load();
        emit('changed');
    } catch (e) {
        error.value = e.message;
    }
};

const deleteMatching = async () => {
    const count = total.value.toLocaleString();
    if (filtered.value) {
        if (!confirm(`Delete all ${count} documents matching the query?`)) return;
    } else if (prompt(`Delete ALL ${count} documents in ${props.collection}? Type the collection name to confirm.`) !== props.collection) {
        return;
    }
    try {
        await props.run({ action: 'delete_many', db: props.db, collection: props.collection, filter: query.value.filter || {} }, { notify: true });
        skip.value = 0;
        reload();
        emit('changed');
    } catch (e) {
        error.value = e.message;
    }
};

const exportCollection = () => {
    window.location.href = props.exportUrl(props.db, props.collection, aggregating.value ? {} : query.value.filter);
};

const importFile = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    error.value = '';
    try {
        const docs = readImportFile(await file.text());
        importing.value = `Importing ${docs.length.toLocaleString()} documents…`;
        const done = await importDocuments(props.run, props.db, props.collection, docs, (n, of) => {
            importing.value = `Imported ${n.toLocaleString()} of ${of.toLocaleString()}…`;
        });
        emit('notify', { type: 'success', text: `Imported ${done.toLocaleString()} documents.` });
    } catch (e) {
        error.value = `Import failed: ${e.message}`;
    } finally {
        importing.value = '';
        reload();
        emit('changed');
    }
};

const rename = async () => {
    const to = renameTo.value.trim();
    if (!validCollectionName(to) || to === props.collection) {
        error.value = 'Enter a new, valid collection name.';
        return;
    }
    try {
        await props.run({ action: 'rename_collection', db: props.db, collection: props.collection, to }, { notify: true });
        emit('changed');
        emit('go', props.db, to);
    } catch (e) {
        error.value = e.message;
    }
};

const drop = async () => {
    if (!confirm(`Drop collection ${props.db}.${props.collection} and all its documents?`)) return;
    try {
        await props.run({ action: 'drop_collection', db: props.db, collection: props.collection }, { notify: true });
        emit('changed');
        emit('go', props.db);
    } catch (e) {
        error.value = e.message;
    }
};

const tabClass = (name) => (tab.value === name
    ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-800 dark:text-white'
    : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200');
const tool = 'rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800';
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="font-mono text-lg font-semibold">{{ collection }}</h1>
                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">{{ formatCount(stats?.count ?? total) }} docs</span>
                <span v-if="readOnly" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">read only</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button v-if="!readOnly" type="button" class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700" @click="newDocument"><i class="bi bi-plus-lg"></i> New Document</button>
                <button v-if="!readOnly" type="button" :class="tool" @click="tab = 'indexes'"><i class="bi bi-lightning"></i> New Index</button>
                <button type="button" :class="tool" @click="exportCollection"><i class="bi bi-download"></i> Export</button>
                <button v-if="!readOnly" type="button" :class="tool" :disabled="!!importing" @click="fileInput.click()"><i class="bi bi-upload"></i> Import</button>
                <input ref="fileInput" type="file" accept=".json,.ndjson,.jsonl,application/json" class="hidden" @change="importFile" />
            </div>
        </div>

        <div class="inline-flex rounded-lg bg-slate-100 p-1 text-sm font-medium dark:bg-slate-950">
            <button v-for="[name, label] in [['documents', 'Documents'], ['indexes', 'Indexes'], ['tools', 'Tools & Stats']]" :key="name" type="button" class="rounded-md px-3 py-1.5" :class="tabClass(name)" @click="tab = name">{{ label }}</button>
        </div>

        <p v-if="importing" class="rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-700 dark:bg-sky-950/40 dark:text-sky-300">{{ importing }}</p>
        <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">{{ error }}</p>

        <template v-if="tab === 'documents'">
            <QueryPanel :collection="`${db}.${collection}`" :loading="loading" @search="search" />

            <Card :title="aggregating ? 'Pipeline results' : filtered ? 'Matching documents' : 'Documents'" icon="bi bi-file-earmark-text" flush>
                <template #badge>
                    <span v-if="loading" class="text-xs font-normal text-slate-400">Loading…</span>
                </template>
                <template #actions>
                    <button v-if="!readOnly && !aggregating && total > 0" type="button" class="rounded-md border border-red-200 px-2.5 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/40" @click="deleteMatching">
                        <i class="bi bi-trash"></i> Delete all {{ total.toLocaleString() }} {{ filtered ? 'matching' : '' }}
                    </button>
                    <div class="inline-flex overflow-hidden rounded-md border border-slate-300 text-xs dark:border-slate-700">
                        <button type="button" class="px-2.5 py-1" :class="view === 'table' ? 'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900' : 'hover:bg-slate-100 dark:hover:bg-slate-800'" @click="view = 'table'"><i class="bi bi-table"></i></button>
                        <button type="button" class="px-2.5 py-1" :class="view === 'json' ? 'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900' : 'hover:bg-slate-100 dark:hover:bg-slate-800'" @click="view = 'json'"><i class="bi bi-braces"></i></button>
                    </div>
                </template>
                <DocumentTable :documents="documents" :view="view" :editable="!readOnly && !aggregating" @open="open" @delete="remove" />
                <div v-if="!aggregating && total > 0" class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">
                    <Pagination :skip="skip" :limit="limit" :total="total" @page="setPage" @limit="setLimit" />
                </div>
            </Card>
        </template>

        <Card v-else-if="tab === 'indexes'" title="Indexes" icon="bi bi-lightning">
            <IndexesPanel :db="db" :collection="collection" :read-only="readOnly" :run="run" />
        </Card>

        <template v-else>
            <div class="grid gap-5 lg:grid-cols-2">
                <Card title="Tools" icon="bi bi-tools">
                    <div class="space-y-4 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" :class="tool" @click="exportCollection"><i class="bi bi-download"></i> Export JSON</button>
                            <button v-if="!readOnly" type="button" :class="tool" @click="fileInput.click()"><i class="bi bi-upload"></i> Import JSON / NDJSON</button>
                        </div>
                        <form v-if="!readOnly" class="flex flex-wrap items-end gap-2" @submit.prevent="rename">
                            <label class="text-xs text-slate-500">Rename collection
                                <input v-model.trim="renameTo" type="text" :placeholder="collection" class="mt-1 block w-56 rounded-md border-slate-300 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900" />
                            </label>
                            <button type="submit" :class="tool">Rename</button>
                        </form>
                        <div v-if="!readOnly" class="border-t border-slate-200 pt-4 dark:border-slate-800">
                            <button type="button" class="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700" @click="drop"><i class="bi bi-trash"></i> Drop collection</button>
                        </div>
                    </div>
                </Card>
                <StatsTable title="Collection Stats" :rows="statsRows" :error="statsError" />
            </div>
        </template>
    </div>
</template>
