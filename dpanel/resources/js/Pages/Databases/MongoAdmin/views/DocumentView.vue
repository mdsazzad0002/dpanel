<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Card from '../components/Card.vue';
import CodeArea from '@/Pages/Docker/components/CodeArea.vue';
import { parseMongo, preview, toShell } from '../lib/mongoJson';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// One document on its own page, like mongo-express: edit and save, delete,
// or duplicate it. `docId` 'new' inserts instead (an array adds several).
const props = defineProps({
    db: { type: String, required: true },
    collection: { type: String, required: true },
    docId: { type: String, required: true },
    readOnly: { type: Boolean, default: false },
    run: { type: Function, required: true },
});

const emit = defineEmits(['go', 'changed']);

const isNew = computed(() => props.docId === 'new');
const id = ref(null);
const draft = ref('');
const original = ref('');
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notFound = ref(false);

const dirty = computed(() => draft.value !== original.value);

const load = async () => {
    error.value = '';
    notFound.value = false;
    if (isNew.value) {
        id.value = null;
        draft.value = sessionStorage.getItem('mongo-admin-duplicate') || '{\n  \n}';
        sessionStorage.removeItem('mongo-admin-duplicate');
        original.value = '';
        return;
    }
    loading.value = true;
    try {
        id.value = parseMongo(props.docId);
        const data = await props.run({ action: 'find', db: props.db, collection: props.collection, filter: { _id: id.value }, limit: 1 }, { key: 'doc' });
        const doc = data.documents?.[0];
        if (!doc) {
            notFound.value = true;
            return;
        }
        draft.value = toShell(doc);
        original.value = draft.value;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
};
onMounted(load);
watch(() => [props.db, props.collection, props.docId], load);

const back = () => emit('go', props.db, props.collection);

const save = async () => {
    if (props.readOnly || saving.value) return;
    error.value = '';
    let value;
    try {
        value = parseMongo(draft.value);
    } catch (e) {
        error.value = e.message;
        return;
    }
    const isObject = (v) => v && typeof v === 'object' && !Array.isArray(v);
    if (!(isObject(value) || (isNew.value && Array.isArray(value) && value.length && value.every(isObject)))) {
        error.value = isNew.value ? t('databases.mongoAdmin.document.errNewShape') : t('databases.mongoAdmin.document.errShape');
        return;
    }
    saving.value = true;
    try {
        const op = isNew.value
            ? { action: 'insert', db: props.db, collection: props.collection, documents: value }
            : { action: 'replace', db: props.db, collection: props.collection, id: id.value, document: value };
        await props.run(op, { notify: true });
        original.value = draft.value;
        emit('changed');
        back();
    } catch (e) {
        error.value = e.message;
    } finally {
        saving.value = false;
    }
};

const remove = async () => {
    if (!confirm(t('databases.mongoAdmin.document.deleteConfirm', { id: preview(id.value, 40) }))) return;
    try {
        await props.run({ action: 'delete', db: props.db, collection: props.collection, id: id.value }, { notify: true });
        emit('changed');
        back();
    } catch (e) {
        error.value = e.message;
    }
};

const duplicate = () => {
    try {
        const doc = parseMongo(draft.value);
        delete doc._id;
        sessionStorage.setItem('mongo-admin-duplicate', toShell(doc));
    } catch {
        // Start the copy empty if the draft doesn't parse.
    }
    emit('go', props.db, props.collection, 'new');
};
</script>

<template>
    <Card :title="isNew ? t('databases.mongoAdmin.document.newTitle', { collection }) : t('databases.mongoAdmin.document.title', { id: preview(id, 48) })" :icon="isNew ? 'bi bi-file-earmark-plus' : 'bi bi-file-earmark-code'">
        <template #actions>
            <span v-if="dirty && !isNew" class="text-xs text-amber-600 dark:text-amber-400">{{ t('databases.mongoAdmin.document.unsaved') }}</span>
            <button v-if="!isNew && !readOnly && !notFound" type="button" class="rounded-md border border-slate-300 px-3 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="duplicate"><i class="bi bi-copy"></i> {{ t('databases.mongoAdmin.document.duplicate') }}</button>
            <button v-if="!isNew && !readOnly && !notFound" type="button" class="rounded-md border border-red-200 px-3 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/40" @click="remove"><i class="bi bi-trash"></i> {{ t('databases.mongoAdmin.document.delete') }}</button>
        </template>

        <p v-if="loading" class="py-6 text-center text-sm text-slate-400">{{ t('common.loading') }}</p>
        <div v-else-if="notFound" class="py-6 text-center text-sm text-slate-500">
            {{ t('databases.mongoAdmin.document.notFound') }}
            <button type="button" class="ml-2 text-emerald-600 hover:underline" @click="back">{{ t('databases.mongoAdmin.document.backTo', { collection }) }}</button>
        </div>
        <template v-else>
            <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">
                <i18n-t keypath="databases.mongoAdmin.document.shellHint" scope="global"><template #a><code>ObjectId("…")</code></template><template #b><code>ISODate("…")</code></template><template #c><code>NumberLong(…)</code></template></i18n-t>
                <template v-if="isNew"> {{ t('databases.mongoAdmin.document.pasteArray') }}</template>
                <template v-else> {{ t('databases.mongoAdmin.document.idFixed') }}</template>
                {{ t('databases.mongoAdmin.document.ctrlS') }}
            </p>
            <CodeArea v-model="draft" :rows="24" @save="save" />
            <p v-if="error" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="back">{{ t('common.back') }}</button>
                <button v-if="!readOnly" type="button" :disabled="saving || (!isNew && !dirty)" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50" @click="save">
                    <i class="bi bi-check-lg"></i> {{ saving ? t('databases.mongoAdmin.document.saving') : isNew ? t('databases.mongoAdmin.document.insert') : t('common.save') }}
                </button>
            </div>
        </template>
    </Card>
</template>
