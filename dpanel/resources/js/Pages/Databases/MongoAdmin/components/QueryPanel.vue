<script setup>
import { ref, watch } from 'vue';
import { parseMongo, parseMongoObject } from '../lib/mongoJson';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// mongo-express's Simple and Advanced query, plus an aggregation pipeline.
// Emits `search` with { filter, sort, projection } or { pipeline }.
const props = defineProps({
    collection: { type: String, required: true },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['search']);

const TYPES = ['String', 'Contains', 'Number', 'Boolean', 'ObjectId', 'Date', 'Null', 'Exists'];

const blank = () => ({
    mode: 'simple',
    key: '', value: '', type: 'String',
    filter: '', sort: '', projection: '',
    pipeline: '[\n  { $match: {} },\n  { $limit: 20 }\n]',
});
const q = ref(blank());
const error = ref('');

watch(() => props.collection, () => { q.value = blank(); error.value = ''; });

const simpleFilter = () => {
    const { key, value, type } = q.value;
    if (!key.trim()) return {};
    const k = key.trim();
    switch (type) {
        case 'Number': {
            const n = Number(value);
            if (value.trim() === '' || !Number.isFinite(n)) throw new Error(t('databases.mongoAdmin.query.errNumber'));
            return { [k]: n };
        }
        case 'Boolean': return { [k]: /^(true|1|yes)$/i.test(value.trim()) };
        case 'ObjectId':
            if (!/^[0-9a-f]{24}$/i.test(value.trim())) throw new Error(t('databases.mongoAdmin.query.errObjectId'));
            return { [k]: { $oid: value.trim().toLowerCase() } };
        case 'Date': {
            const d = new Date(value);
            if (Number.isNaN(d.getTime())) throw new Error(t('databases.mongoAdmin.query.errDate'));
            return { [k]: { $date: d.toISOString() } };
        }
        case 'Null': return { [k]: null };
        case 'Exists': return { [k]: { $exists: true } };
        case 'Contains': return { [k]: { $regex: value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), $options: 'i' } };
        default: return { [k]: value };
    }
};

const search = () => {
    error.value = '';
    try {
        if (q.value.mode === 'aggregate') {
            const pipeline = parseMongo(q.value.pipeline);
            if (!Array.isArray(pipeline)) throw new Error(t('databases.mongoAdmin.query.errPipeline'));
            emit('search', { pipeline });
            return;
        }
        if (q.value.mode === 'simple') {
            emit('search', { filter: simpleFilter(), sort: {}, projection: {} });
            return;
        }
        emit('search', {
            filter: parseMongoObject(q.value.filter, 'query'),
            sort: parseMongoObject(q.value.sort, 'sort'),
            projection: parseMongoObject(q.value.projection, 'projection'),
        });
    } catch (e) {
        error.value = e.message;
    }
};

const reset = () => {
    const mode = q.value.mode;
    q.value = { ...blank(), mode: mode === 'aggregate' ? 'simple' : mode };
    error.value = '';
    emit('search', { filter: {}, sort: {}, projection: {} });
};

const tabClass = (mode) => (q.value.mode === mode
    ? 'border-emerald-600 text-emerald-700 dark:text-emerald-300'
    : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-200');
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex gap-4 border-b border-slate-200 px-4 text-sm font-medium dark:border-slate-800">
            <button v-for="mode in ['simple', 'advanced', 'aggregate']" :key="mode" type="button" class="-mb-px border-b-2 py-2.5" :class="tabClass(mode)" @click="q.mode = mode">{{ t(`databases.mongoAdmin.query.mode.${mode}`) }}</button>
        </div>
        <form class="space-y-3 p-4" @submit.prevent="search">
            <div v-if="q.mode === 'simple'" class="flex flex-wrap items-end gap-2">
                <label class="text-xs text-slate-500">{{ t('databases.mongoAdmin.query.field.key') }}
                    <input v-model="q.key" type="text" placeholder="field.path" class="mt-1 block w-44 rounded-md border-slate-300 py-1.5 font-mono text-sm dark:border-slate-700 dark:bg-slate-950" />
                </label>
                <label v-if="!['Null', 'Exists'].includes(q.type)" class="min-w-[12rem] flex-1 text-xs text-slate-500">{{ t('databases.mongoAdmin.query.field.value') }}
                    <input v-model="q.value" type="text" class="mt-1 block w-full rounded-md border-slate-300 py-1.5 font-mono text-sm dark:border-slate-700 dark:bg-slate-950" />
                </label>
                <label class="text-xs text-slate-500">{{ t('databases.mongoAdmin.query.field.type') }}
                    <select v-model="q.type" class="mt-1 block rounded-md border-slate-300 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-950">
                        <option v-for="type in TYPES" :key="type" :value="type">{{ t(`databases.mongoAdmin.query.type.${type}`) }}</option>
                    </select>
                </label>
            </div>

            <div v-else-if="q.mode === 'advanced'" class="grid gap-3 md:grid-cols-3">
                <label class="text-xs text-slate-500 md:col-span-3">{{ t('databases.mongoAdmin.query.field.query') }}
                    <textarea v-model="q.filter" rows="3" spellcheck="false" placeholder='{ status: "active", age: { $gte: 18 } }' class="mt-1 block w-full rounded-md border-slate-300 font-mono text-sm dark:border-slate-700 dark:bg-slate-950" @keydown.ctrl.enter.prevent="search"></textarea>
                </label>
                <label class="text-xs text-slate-500 md:col-span-2">{{ t('databases.mongoAdmin.query.field.projection') }}
                    <input v-model="q.projection" type="text" spellcheck="false" placeholder="{ name: 1, email: 1 }" class="mt-1 block w-full rounded-md border-slate-300 py-1.5 font-mono text-sm dark:border-slate-700 dark:bg-slate-950" />
                </label>
                <label class="text-xs text-slate-500">{{ t('databases.mongoAdmin.query.field.sort') }}
                    <input v-model="q.sort" type="text" spellcheck="false" placeholder="{ _id: -1 }" class="mt-1 block w-full rounded-md border-slate-300 py-1.5 font-mono text-sm dark:border-slate-700 dark:bg-slate-950" />
                </label>
            </div>

            <label v-else class="block text-xs text-slate-500">{{ t('databases.mongoAdmin.query.field.pipeline') }} <span class="text-slate-400">{{ t('databases.mongoAdmin.query.maxResults') }}</span>
                <textarea v-model="q.pipeline" rows="6" spellcheck="false" class="mt-1 block w-full rounded-md border-slate-300 font-mono text-sm dark:border-slate-700 dark:bg-slate-950" @keydown.ctrl.enter.prevent="search"></textarea>
            </label>

            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" :disabled="loading" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                    <i class="bi" :class="q.mode === 'aggregate' ? 'bi-play-fill' : 'bi-search'"></i> {{ q.mode === 'aggregate' ? t('databases.mongoAdmin.query.run') : t('databases.mongoAdmin.query.find') }}
                </button>
                <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="reset">{{ t('databases.mongoAdmin.query.reset') }}</button>
                <span v-if="q.mode !== 'simple'" class="text-xs text-slate-400">{{ t('databases.mongoAdmin.query.shellHint') }}</span>
                <p v-if="error" class="w-full text-sm text-red-600 dark:text-red-400">{{ error }}</p>
            </div>
        </form>
    </div>
</template>
