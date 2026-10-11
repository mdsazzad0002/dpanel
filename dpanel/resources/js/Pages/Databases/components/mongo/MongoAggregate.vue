<script setup>
import { ref, watch } from 'vue';
import CodeArea from '@/Pages/Docker/components/CodeArea.vue';
import MongoJsonView from './MongoJsonView.vue';
import { parseMongo } from './mongoJson';

// Runs an aggregation pipeline and shows the result documents.
const props = defineProps({
    db: { type: String, required: true },
    collection: { type: String, required: true },
    run: { type: Function, required: true },
});

const starter = '[\n  { $match: {} },\n  { $group: { _id: null, count: { $sum: 1 } } }\n]';
const text = ref(starter);
const results = ref(null);
const limit = ref(500);
const error = ref('');
const running = ref(false);

watch(() => [props.db, props.collection], () => {
    text.value = starter;
    results.value = null;
    error.value = '';
});

const execute = async () => {
    error.value = '';
    let pipeline;
    try {
        pipeline = parseMongo(text.value);
    } catch (e) {
        error.value = e.message;
        return;
    }
    if (!Array.isArray(pipeline)) {
        error.value = 'The pipeline must be an array of stages: [ { $match: … }, … ].';
        return;
    }
    running.value = true;
    try {
        const data = await props.run({ action: 'aggregate', db: props.db, collection: props.collection, pipeline });
        results.value = data.documents || [];
        limit.value = data.limit || 500;
    } catch (e) {
        error.value = e.message;
        results.value = null;
    } finally {
        running.value = false;
    }
};
</script>

<template>
    <div class="space-y-3">
        <CodeArea v-model="text" :rows="10" label="Pipeline" @save="execute" />
        <div class="flex items-center justify-between gap-3">
            <p class="text-xs text-slate-500 dark:text-slate-400">Same as <code>db.{{ collection }}.aggregate([…])</code>. Shows up to {{ limit }} results. Ctrl+S runs it.</p>
            <button type="button" :disabled="running" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" @click="execute"><i class="bi bi-play-fill"></i> {{ running ? 'Running…' : 'Run' }}</button>
        </div>
        <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">{{ error }}</p>
        <template v-if="results">
            <p class="text-sm text-slate-500">{{ results.length }} result{{ results.length === 1 ? '' : 's' }}{{ results.length >= limit ? ` (first ${limit})` : '' }}</p>
            <div v-for="(doc, index) in results" :key="index" class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                <MongoJsonView :value="doc" max-height="22rem" />
            </div>
        </template>
    </div>
</template>
