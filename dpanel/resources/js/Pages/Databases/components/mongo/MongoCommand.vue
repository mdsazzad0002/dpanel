<script setup>
import { ref } from 'vue';
import CodeArea from '@/Pages/Docker/components/CodeArea.vue';
import MongoJsonView from './MongoJsonView.vue';
import { parseMongoObject } from './mongoJson';

// Runs any database command (db.runCommand), like phpMyAdmin's SQL tab.
const props = defineProps({
    db: { type: String, required: true },
    run: { type: Function, required: true },
});

const presets = [
    { label: 'dbStats', text: '{ dbStats: 1, scale: 1024 }' },
    { label: 'listCollections', text: '{ listCollections: 1, nameOnly: true }' },
    { label: 'usersInfo', text: '{ usersInfo: 1 }' },
    { label: 'serverStatus', text: '{ serverStatus: 1 }' },
    { label: 'currentOp', text: '{ currentOp: 1, $all: false }' },
];

const text = ref('{ dbStats: 1, scale: 1024 }');
const result = ref(null);
const error = ref('');
const running = ref(false);

const execute = async () => {
    error.value = '';
    let command;
    try {
        command = parseMongoObject(text.value, 'command');
    } catch (e) {
        error.value = e.message;
        return;
    }
    if (!Object.keys(command).length) {
        error.value = 'Enter a command, e.g. { dbStats: 1 }.';
        return;
    }
    running.value = true;
    try {
        result.value = (await props.run({ action: 'command', db: props.db, command })).result;
    } catch (e) {
        error.value = e.message;
        result.value = null;
    } finally {
        running.value = false;
    }
};
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold">Run command on <span class="font-mono">{{ db }}</span></h3>
            <div class="flex flex-wrap gap-1">
                <button v-for="preset in presets" :key="preset.label" type="button" class="rounded border border-slate-300 px-2 py-0.5 font-mono text-[11px] hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="text = preset.text">{{ preset.label }}</button>
            </div>
        </div>
        <div class="mt-3">
            <CodeArea v-model="text" :rows="5" @save="execute" />
        </div>
        <div class="mt-2 flex items-center justify-between gap-3">
            <p class="text-xs text-slate-500 dark:text-slate-400">Same as <code>db.runCommand(…)</code> in mongosh. Ctrl+S runs it.</p>
            <button type="button" :disabled="running" class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" @click="execute">
                <i class="bi bi-play-fill"></i> {{ running ? 'Running…' : 'Run' }}
            </button>
        </div>
        <p v-if="error" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">{{ error }}</p>
        <div v-if="result" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-950">
            <MongoJsonView :value="result" max-height="28rem" />
        </div>
    </section>
</template>
