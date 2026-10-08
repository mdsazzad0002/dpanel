<script setup>
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    container: { type: Object, default: null },
    fetchLogs: { type: Function, required: true },
    errorText: { type: Function, required: true },
});

defineEmits(['close']);

const lines = ref(200);
const logs = ref('');
const error = ref('');
const loading = ref(false);

const load = async () => {
    if (!props.container) return;
    loading.value = true;
    error.value = '';
    try {
        logs.value = await props.fetchLogs(props.container.id, lines.value);
    } catch (e) {
        error.value = props.errorText(e);
    } finally {
        loading.value = false;
    }
};

watch(() => props.container, (container) => {
    logs.value = '';
    if (container) load();
});
</script>

<template>
    <Modal :show="!!container" max-width="2xl" @close="$emit('close')">
        <div v-if="container" class="p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-base font-semibold">Logs: {{ container.name }}</h2>
                <div class="flex items-center gap-2">
                    <select v-model.number="lines" class="rounded-md border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800" @change="load">
                        <option :value="100">Last 100 lines</option>
                        <option :value="200">Last 200 lines</option>
                        <option :value="1000">Last 1000 lines</option>
                        <option :value="5000">Last 5000 lines</option>
                    </select>
                    <button type="button" :disabled="loading" class="rounded-md border border-slate-300 px-3 py-1 text-xs hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="load">
                        {{ loading ? 'Loading…' : 'Reload' }}
                    </button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="$emit('close')">Close</button>
                </div>
            </div>
            <div v-if="error" class="mt-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ error }}</div>
            <pre class="mt-3 max-h-[60vh] overflow-auto whitespace-pre-wrap break-all rounded-md bg-slate-950 p-4 font-mono text-xs text-slate-100">{{ logs || (loading ? 'Loading…' : 'No log output.') }}</pre>
        </div>
    </Modal>
</template>
