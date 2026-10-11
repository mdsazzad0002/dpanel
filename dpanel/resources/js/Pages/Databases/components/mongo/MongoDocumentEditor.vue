<script setup>
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import CodeArea from '@/Pages/Docker/components/CodeArea.vue';
import { parseMongo } from './mongoJson';

// Edit or insert documents as shell syntax. Insert also takes an array of
// documents to add several at once.
const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: 'Document' },
    text: { type: String, default: '{\n  \n}' },
    allowMany: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['save', 'close']);

const draft = ref(props.text);
const parseError = ref('');

watch(() => [props.show, props.text], () => {
    if (props.show) {
        draft.value = props.text;
        parseError.value = '';
    }
});

const save = () => {
    parseError.value = '';
    let value;
    try {
        value = parseMongo(draft.value);
    } catch (e) {
        parseError.value = e.message;
        return;
    }
    const isObject = value && typeof value === 'object' && !Array.isArray(value);
    const isObjectList = props.allowMany && Array.isArray(value) && value.length > 0
        && value.every((item) => item && typeof item === 'object' && !Array.isArray(item));
    if (!isObject && !isObjectList) {
        parseError.value = props.allowMany ? 'Enter a document { … } or an array of documents [ { … }, … ].' : 'Enter a document { … }.';
        return;
    }
    emit('save', value);
};
</script>

<template>
    <Modal :show="show" max-width="4xl" @close="emit('close')">
        <div class="p-5">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-base font-semibold">{{ title }}</h3>
                <button type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" aria-label="Close" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
            </div>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Shell syntax works: <code>ObjectId("…")</code>, <code>ISODate("…")</code>, <code>NumberLong(…)</code>, unquoted keys, 'single quotes'. Ctrl+S saves.
            </p>
            <div class="mt-3">
                <CodeArea v-model="draft" :rows="20" @save="save" />
            </div>
            <p v-if="parseError || error" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ parseError || error }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('close')">Cancel</button>
                <button type="button" :disabled="saving" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" @click="save">
                    {{ saving ? 'Saving…' : 'Save' }}
                </button>
            </div>
        </div>
    </Modal>
</template>
