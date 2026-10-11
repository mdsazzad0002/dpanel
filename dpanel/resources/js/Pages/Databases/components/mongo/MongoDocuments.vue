<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import MongoJsonView from './MongoJsonView.vue';
import MongoDocumentEditor from './MongoDocumentEditor.vue';
import { parseMongo, parseMongoObject, preview, toShell } from './mongoJson';

// Browse one collection: query, page, view as JSON or a table, and edit,
// duplicate, delete, insert, import and export documents.
const props = defineProps({
    db: { type: String, required: true },
    collection: { type: String, required: true },
    readOnly: { type: Boolean, default: false },
    run: { type: Function, required: true },
    exportUrl: { type: Function, required: true },
});

const emit = defineEmits(['changed', 'notify']);

const filterText = ref('');
const sortText = ref('');
const projectionText = ref('');
const showOptions = ref(false);
const pageSize = ref(20);
const skip = ref(0);
const view = ref('list');

const documents = ref([]);
const total = ref(0);
const applied = ref({});
const loading = ref(false);
const error = ref('');

const editor = ref({ show: false, mode: 'edit', title: '', text: '', id: null, saving: false, error: '' });
const importing = ref('');
const fileInput = ref(null);

const parseOptions = () => ({
    filter: parseMongoObject(filterText.value, 'filter'),
    sort: parseMongoObject(sortText.value, 'sort'),
    projection: parseMongoObject(projectionText.value, 'projection'),
});

const load = async () => {
    error.value = '';
    let options;
    try {
        options = parseOptions();
    } catch (e) {
        error.value = e.message;
        return;
    }
    loading.value = true;
    try {
        const data = await props.run({
            action: 'find', db: props.db, collection: props.collection,
            ...options, skip: skip.value, limit: pageSize.value,
        }, { key: 'find' });
        documents.value = data.documents || [];
        total.value = Number(data.total) || 0;
        applied.value = options.filter;
    } catch (e) {
        error.value = e.message;
        documents.value = [];
    } finally {
        loading.value = false;
    }
};

const search = () => { skip.value = 0; load(); };
const reset = () => {
    filterText.value = '';
    sortText.value = '';
    projectionText.value = '';
    search();
};

onMounted(load);
watch(() => [props.db, props.collection], () => {
    filterText.value = '';
    sortText.value = '';
    projectionText.value = '';
    skip.value = 0;
    load();
});
watch(pageSize, search);

const from = computed(() => (total.value ? skip.value + 1 : 0));
const to = computed(() => Math.min(skip.value + documents.value.length, total.value));
const hasPrev = computed(() => skip.value > 0);
const hasNext = computed(() => skip.value + pageSize.value < total.value);
const page = (direction) => {
    skip.value = Math.max(0, skip.value + direction * pageSize.value);
    load();
};

const columns = computed(() => {
    const keys = new Set();
    documents.value.forEach((doc) => Object.keys(doc).forEach((key) => keys.add(key)));
    const list = [...keys];
    list.sort((a, b) => (a === '_id' ? -1 : b === '_id' ? 1 : 0));
    return list.slice(0, 25);
});

const filtered = computed(() => Object.keys(applied.value).length > 0);
const docId = (doc) => doc?._id;
const idLabel = (doc) => (doc?._id === undefined ? '(no _id)' : preview(doc._id, 40));

const openEditor = (mode, doc = null) => {
    const withoutId = doc ? Object.fromEntries(Object.entries(doc).filter(([key]) => key !== '_id')) : null;
    editor.value = {
        show: true,
        mode,
        id: mode === 'edit' ? docId(doc) : null,
        title: mode === 'edit' ? `Edit document ${idLabel(doc)}` : mode === 'duplicate' ? 'Duplicate document' : `Insert into ${props.collection}`,
        text: mode === 'edit' ? toShell(doc) : mode === 'duplicate' ? toShell(withoutId) : '{\n  \n}',
        saving: false,
        error: '',
    };
};

const saveEditor = async (value) => {
    editor.value.saving = true;
    editor.value.error = '';
    try {
        const op = editor.value.mode === 'edit'
            ? { action: 'replace', db: props.db, collection: props.collection, id: editor.value.id, document: value }
            : { action: 'insert', db: props.db, collection: props.collection, documents: value };
        await props.run(op, { notify: true });
        editor.value.show = false;
        await load();
        emit('changed');
    } catch (e) {
        editor.value.error = e.message;
    } finally {
        editor.value.saving = false;
    }
};

const remove = async (doc) => {
    if (!confirm(`Delete document ${idLabel(doc)}? This can't be undone.`)) return;
    try {
        await props.run({ action: 'delete', db: props.db, collection: props.collection, id: docId(doc) }, { notify: true });
        await load();
        emit('changed');
    } catch (e) {
        error.value = e.message;
    }
};

const deleteMatching = async () => {
    const question = filtered.value
        ? `Delete all ${total.value.toLocaleString()} documents matching the filter?`
        : `Delete ALL ${total.value.toLocaleString()} documents in ${props.collection}? Type the collection name to confirm.`;
    if (filtered.value) {
        if (!confirm(question)) return;
    } else if (prompt(question) !== props.collection) {
        return;
    }
    try {
        await props.run({ action: 'delete_many', db: props.db, collection: props.collection, filter: applied.value }, { notify: true });
        skip.value = 0;
        await load();
        emit('changed');
    } catch (e) {
        error.value = e.message;
    }
};

const copy = async (doc) => {
    try {
        await navigator.clipboard.writeText(toShell(doc));
        emit('notify', { type: 'success', text: 'Document copied.' });
    } catch {
        emit('notify', { type: 'error', text: 'Could not copy to clipboard.' });
    }
};

const exportCollection = () => {
    window.location.href = props.exportUrl(props.db, props.collection, applied.value);
};

// A JSON array, one document, or one document per line (mongoexport's default).
const readImportFile = (text) => {
    try {
        const value = JSON.parse(text);
        return Array.isArray(value) ? value : [value];
    } catch {
        // fall through
    }
    try {
        const value = parseMongo(text);
        return Array.isArray(value) ? value : [value];
    } catch {
        // fall through
    }
    return text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean).map((line, index) => {
        try {
            return JSON.parse(line);
        } catch {
            throw new Error(`Line ${index + 1} is not valid JSON.`);
        }
    });
};

const importFile = async (file) => {
    if (!file) return;
    error.value = '';
    let docs;
    try {
        docs = readImportFile(await file.text());
    } catch (e) {
        error.value = `Import failed: ${e.message}`;
        return;
    }
    if (!docs.length || docs.some((doc) => !doc || typeof doc !== 'object' || Array.isArray(doc))) {
        error.value = 'Import failed: the file must hold JSON documents (objects).';
        return;
    }

    // Small batches stay under the panel's upload limit.
    let done = 0;
    let batch = [];
    let size = 0;
    const flush = async () => {
        if (!batch.length) return;
        await props.run({ action: 'insert', db: props.db, collection: props.collection, documents: batch }, { key: 'import' });
        done += batch.length;
        importing.value = `Imported ${done.toLocaleString()} of ${docs.length.toLocaleString()}…`;
        batch = [];
        size = 0;
    };
    try {
        importing.value = `Importing ${docs.length.toLocaleString()} documents…`;
        for (const doc of docs) {
            const bytes = JSON.stringify(doc).length;
            if (batch.length >= 1000 || size + bytes > 3_000_000) await flush();
            batch.push(doc);
            size += bytes;
        }
        await flush();
        emit('notify', { type: 'success', text: `Imported ${done.toLocaleString()} documents.` });
    } catch (e) {
        error.value = `Import stopped after ${done.toLocaleString()} documents: ${e.message}`;
    } finally {
        importing.value = '';
        await load();
        emit('changed');
    }
};
</script>

<template>
    <div class="space-y-3">
        <form class="space-y-2" @submit.prevent="search">
            <div class="flex flex-col gap-2 sm:flex-row">
                <div class="flex min-w-0 flex-1 items-center rounded-md border border-slate-300 bg-white focus-within:ring-2 focus-within:ring-blue-500 dark:border-slate-700 dark:bg-slate-950">
                    <span class="pl-3 font-mono text-xs text-slate-400">filter</span>
                    <input v-model="filterText" type="text" spellcheck="false" placeholder="{ status: 'active', age: { $gt: 30 } }" class="min-w-0 flex-1 border-0 bg-transparent font-mono text-sm focus:ring-0" />
                </div>
                <div class="flex gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :class="{ 'bg-slate-100 dark:bg-slate-800': showOptions }" @click="showOptions = !showOptions">Options</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="reset">Reset</button>
                    <button type="submit" :disabled="loading" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"><i class="bi bi-search"></i> Find</button>
                </div>
            </div>
            <div v-if="showOptions" class="grid gap-2 sm:grid-cols-2">
                <div class="flex items-center rounded-md border border-slate-300 bg-white dark:border-slate-700 dark:bg-slate-950">
                    <span class="pl-3 font-mono text-xs text-slate-400">sort</span>
                    <input v-model="sortText" type="text" spellcheck="false" placeholder="{ createdAt: -1 }" class="min-w-0 flex-1 border-0 bg-transparent font-mono text-sm focus:ring-0" />
                </div>
                <div class="flex items-center rounded-md border border-slate-300 bg-white dark:border-slate-700 dark:bg-slate-950">
                    <span class="pl-3 font-mono text-xs text-slate-400">project</span>
                    <input v-model="projectionText" type="text" spellcheck="false" placeholder="{ name: 1, email: 1 }" class="min-w-0 flex-1 border-0 bg-transparent font-mono text-sm focus:ring-0" />
                </div>
            </div>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap gap-2">
                <template v-if="!readOnly">
                    <button type="button" class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700" @click="openEditor('insert')"><i class="bi bi-plus-lg"></i> Insert</button>
                    <button type="button" :disabled="!!importing" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="fileInput?.click()"><i class="bi bi-upload"></i> Import</button>
                    <input ref="fileInput" type="file" accept=".json,.jsonl,.ndjson,application/json" class="hidden" @change="importFile($event.target.files?.[0]); $event.target.value = ''" />
                </template>
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="exportCollection"><i class="bi bi-download"></i> Export{{ filtered ? ' matching' : '' }}</button>
                <button v-if="!readOnly && total > 0" type="button" class="rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950/30" @click="deleteMatching"><i class="bi bi-trash"></i> Delete {{ filtered ? 'matching' : 'all' }}</button>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <span class="text-slate-500 tabular-nums">{{ from.toLocaleString() }}–{{ to.toLocaleString() }} of {{ total.toLocaleString() }}</span>
                <select v-model.number="pageSize" class="rounded-md border-slate-300 py-1 text-sm dark:border-slate-700 dark:bg-slate-950">
                    <option v-for="size in [20, 50, 100, 200, 500]" :key="size" :value="size">{{ size }} / page</option>
                </select>
                <button type="button" :disabled="!hasPrev || loading" class="rounded-md border border-slate-300 px-2 py-1 disabled:opacity-40 dark:border-slate-700" aria-label="Previous page" @click="page(-1)"><i class="bi bi-chevron-left"></i></button>
                <button type="button" :disabled="!hasNext || loading" class="rounded-md border border-slate-300 px-2 py-1 disabled:opacity-40 dark:border-slate-700" aria-label="Next page" @click="page(1)"><i class="bi bi-chevron-right"></i></button>
                <span class="ml-1 inline-flex overflow-hidden rounded-md border border-slate-300 dark:border-slate-700">
                    <button type="button" title="JSON view" class="px-2 py-1" :class="view === 'list' ? 'bg-slate-200 dark:bg-slate-700' : ''" @click="view = 'list'"><i class="bi bi-braces"></i></button>
                    <button type="button" title="Table view" class="px-2 py-1" :class="view === 'table' ? 'bg-slate-200 dark:bg-slate-700' : ''" @click="view = 'table'"><i class="bi bi-table"></i></button>
                </span>
            </div>
        </div>

        <p v-if="importing" class="rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">{{ importing }}</p>
        <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">{{ error }}</p>

        <div v-if="loading && !documents.length" class="py-10 text-center text-sm text-slate-400">Loading…</div>
        <div v-else-if="!documents.length" class="rounded-xl border border-dashed border-slate-300 py-10 text-center text-sm text-slate-400 dark:border-slate-700">
            {{ filtered ? 'No documents match this filter.' : 'This collection is empty.' }}
        </div>

        <div v-else-if="view === 'list'" class="space-y-2" :class="{ 'opacity-60': loading }">
            <div v-for="(doc, index) in documents" :key="index" class="group rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-start gap-2 p-3">
                    <MongoJsonView :value="doc" max-height="22rem" class="min-w-0 flex-1" />
                    <div class="flex shrink-0 flex-col gap-1 opacity-60 transition group-hover:opacity-100">
                        <button v-if="!readOnly && doc._id !== undefined" type="button" title="Edit" class="rounded p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800" @click="openEditor('edit', doc)"><i class="bi bi-pencil"></i></button>
                        <button v-if="!readOnly" type="button" title="Duplicate" class="rounded p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800" @click="openEditor('duplicate', doc)"><i class="bi bi-copy"></i></button>
                        <button type="button" title="Copy" class="rounded p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800" @click="copy(doc)"><i class="bi bi-clipboard"></i></button>
                        <button v-if="!readOnly && doc._id !== undefined" type="button" title="Delete" class="rounded p-1.5 text-red-600 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/30" @click="remove(doc)"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900" :class="{ 'opacity-60': loading }">
            <table class="min-w-full text-xs">
                <thead class="bg-slate-50 text-left text-slate-500 dark:bg-slate-950">
                    <tr>
                        <th class="w-px px-2 py-2"></th>
                        <th v-for="column in columns" :key="column" class="whitespace-nowrap px-3 py-2 font-mono font-medium">{{ column }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="(doc, index) in documents" :key="index" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <td class="whitespace-nowrap px-2 py-1.5">
                            <button v-if="!readOnly && doc._id !== undefined" type="button" title="Edit" class="rounded p-1 hover:bg-slate-200 dark:hover:bg-slate-700" @click="openEditor('edit', doc)"><i class="bi bi-pencil"></i></button>
                            <button v-if="!readOnly && doc._id !== undefined" type="button" title="Delete" class="rounded p-1 text-red-600 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/30" @click="remove(doc)"><i class="bi bi-trash"></i></button>
                        </td>
                        <td v-for="column in columns" :key="column" class="max-w-[16rem] truncate whitespace-nowrap px-3 py-1.5 font-mono" :title="doc[column] === undefined ? '' : preview(doc[column], 500)">
                            <span v-if="doc[column] === undefined" class="text-slate-300 dark:text-slate-600">—</span>
                            <span v-else :class="typeof doc[column] === 'number' ? 'text-blue-700 dark:text-blue-300' : typeof doc[column] === 'string' ? '' : 'text-pink-700 dark:text-pink-300'">{{ preview(doc[column]) }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <MongoDocumentEditor
            :show="editor.show"
            :title="editor.title"
            :text="editor.text"
            :allow-many="editor.mode === 'insert'"
            :saving="editor.saving"
            :error="editor.error"
            @save="saveEditor"
            @close="editor.show = false"
        />
    </div>
</template>
