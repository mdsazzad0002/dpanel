<script setup>
import { computed, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import { validCollectionName, validDatabaseName } from './useMongoAdmin';

// Asks for a new database (with its first collection, since MongoDB only
// keeps a database that has one), a new collection, or a collection rename.
const props = defineProps({
    show: { type: Boolean, default: false },
    mode: { type: String, default: 'collection' }, // database | collection | rename
    db: { type: String, default: '' },
    current: { type: String, default: '' },
    saving: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['save', 'close']);

const database = ref('');
const collection = ref('');

watch(() => props.show, (show) => {
    if (!show) return;
    database.value = '';
    collection.value = props.mode === 'rename' ? props.current : '';
});

const title = computed(() => ({
    database: 'New database',
    collection: `New collection in ${props.db}`,
    rename: `Rename ${props.db}.${props.current}`,
}[props.mode]));

const problem = computed(() => {
    if (props.mode === 'database' && database.value && !validDatabaseName(database.value)) {
        return 'Database names can\'t contain / \\ . " $ or spaces (max 63 characters).';
    }
    if (collection.value && !validCollectionName(collection.value)) {
        return 'Collection names can\'t contain $ or start with "system.".';
    }
    return '';
});

const ready = computed(() => !problem.value && collection.value
    && (props.mode !== 'database' || database.value)
    && (props.mode !== 'rename' || collection.value !== props.current));

const submit = () => {
    if (!ready.value) return;
    emit('save', { db: props.mode === 'database' ? database.value : props.db, collection: collection.value });
};
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form class="p-5" @submit.prevent="submit">
            <h3 class="text-base font-semibold">{{ title }}</h3>
            <div class="mt-4 space-y-3">
                <label v-if="mode === 'database'" class="block text-sm">
                    <span class="text-slate-600 dark:text-slate-300">Database name</span>
                    <input v-model.trim="database" type="text" autofocus class="mt-1 w-full rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" />
                </label>
                <label class="block text-sm">
                    <span class="text-slate-600 dark:text-slate-300">{{ mode === 'database' ? 'First collection' : mode === 'rename' ? 'New name' : 'Collection name' }}</span>
                    <input v-model.trim="collection" type="text" :autofocus="mode !== 'database'" class="mt-1 w-full rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" />
                </label>
                <p v-if="mode === 'database'" class="text-xs text-slate-500 dark:text-slate-400">MongoDB keeps a database only while it has a collection, so it starts with one.</p>
            </div>
            <p v-if="problem || error" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ problem || error }}</p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('close')">Cancel</button>
                <button type="submit" :disabled="!ready || saving" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ saving ? 'Saving…' : mode === 'rename' ? 'Rename' : 'Create' }}
                </button>
            </div>
        </form>
    </Modal>
</template>
