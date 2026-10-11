<script setup>
import { onMounted, ref, watch } from 'vue';
import { parseMongoObject, toShell } from '../lib/mongoJson';

// List, create and drop a collection's indexes.
const props = defineProps({
    db: { type: String, required: true },
    collection: { type: String, required: true },
    readOnly: { type: Boolean, default: false },
    run: { type: Function, required: true },
});

const indexes = ref([]);
const loading = ref(false);
const error = ref('');
const form = ref({ keys: '{ email: 1 }', name: '', unique: false, sparse: false, ttl: '' });
const creating = ref(false);

const load = async () => {
    loading.value = true;
    error.value = '';
    try {
        indexes.value = (await props.run({ action: 'indexes', db: props.db, collection: props.collection })).indexes || [];
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
};

onMounted(load);
watch(() => [props.db, props.collection], load);

const options = (index) => {
    const skip = new Set(['v', 'key', 'name', 'ns']);
    return Object.entries(index).filter(([key]) => !skip.has(key));
};

const create = async () => {
    error.value = '';
    let keys;
    try {
        keys = parseMongoObject(form.value.keys, 'keys');
    } catch (e) {
        error.value = e.message;
        return;
    }
    const opts = {};
    if (form.value.name.trim()) opts.name = form.value.name.trim();
    if (form.value.unique) opts.unique = true;
    if (form.value.sparse) opts.sparse = true;
    if (String(form.value.ttl).trim() !== '') opts.expireAfterSeconds = Number(form.value.ttl);
    creating.value = true;
    try {
        await props.run({ action: 'create_index', db: props.db, collection: props.collection, keys, options: opts }, { notify: true });
        form.value = { keys: '{ email: 1 }', name: '', unique: false, sparse: false, ttl: '' };
        await load();
    } catch (e) {
        error.value = e.message;
    } finally {
        creating.value = false;
    }
};

const drop = async (name) => {
    if (!confirm(`Drop index ${name}?`)) return;
    try {
        await props.run({ action: 'drop_index', db: props.db, collection: props.collection, name }, { notify: true });
        await load();
    } catch (e) {
        error.value = e.message;
    }
};
</script>

<template>
    <div class="space-y-4">
        <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">{{ error }}</p>

        <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-800">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-950">
                    <tr>
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2">Keys</th>
                        <th class="px-4 py-2">Options</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="index in indexes" :key="index.name">
                        <td class="px-4 py-2 font-mono">{{ index.name }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ toShell(index.key).replace(/\s+/g, ' ') }}</td>
                        <td class="px-4 py-2">
                            <span v-for="[key, value] in options(index)" :key="key" class="mr-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] dark:bg-slate-800">{{ key }}{{ value === true ? '' : `: ${toShell(value).replace(/\s+/g, ' ')}` }}</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button v-if="!readOnly && index.name !== '_id_'" type="button" class="rounded px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/30" @click="drop(index.name)"><i class="bi bi-trash"></i> Drop</button>
                        </td>
                    </tr>
                    <tr v-if="!indexes.length && !loading"><td colspan="4" class="px-4 py-6 text-center text-slate-400">No indexes.</td></tr>
                </tbody>
            </table>
        </div>

        <form v-if="!readOnly" class="rounded-lg bg-slate-50 p-4 dark:bg-slate-950/40" @submit.prevent="create">
            <h3 class="text-sm font-semibold">Create index</h3>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <label class="block text-sm">
                    <span class="text-slate-600 dark:text-slate-300">Keys</span>
                    <input v-model="form.keys" type="text" spellcheck="false" class="mt-1 w-full rounded-md border-slate-300 font-mono text-sm dark:border-slate-700 dark:bg-slate-950" />
                    <span class="mt-1 block text-xs text-slate-500">1 ascending, -1 descending, "text", "2dsphere", "hashed"</span>
                </label>
                <label class="block text-sm">
                    <span class="text-slate-600 dark:text-slate-300">Name (optional)</span>
                    <input v-model="form.name" type="text" class="mt-1 w-full rounded-md border-slate-300 font-mono text-sm dark:border-slate-700 dark:bg-slate-950" />
                </label>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-4 text-sm">
                <label class="flex items-center gap-2"><input v-model="form.unique" type="checkbox" class="rounded border-slate-300 dark:border-slate-700" /> Unique</label>
                <label class="flex items-center gap-2"><input v-model="form.sparse" type="checkbox" class="rounded border-slate-300 dark:border-slate-700" /> Sparse</label>
                <label class="flex items-center gap-2">TTL seconds <input v-model="form.ttl" type="number" min="0" class="w-28 rounded-md border-slate-300 py-1 text-sm dark:border-slate-700 dark:bg-slate-950" /></label>
                <button type="submit" :disabled="creating" class="ml-auto rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">{{ creating ? 'Creating…' : 'Create index' }}</button>
            </div>
        </form>
    </div>
</template>
