<script setup>
import { nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Runs one command at a time inside the container (`sh -c`), like a terminal
// without interactive programs. ↑/↓ walk the history; each run is logged.
const props = defineProps({
    container: { type: Object, required: true },
    exec: { type: Function, required: true },
    errorText: { type: Function, required: true },
});

const command = ref('');
const user = ref('');
const workdir = ref('');
const running = ref(false);
const entries = ref([]);
const history = ref([]);
const cursor = ref(-1);
const box = ref(null);
const input = ref(null);

const quick = [
    { get label() { return t('docker.console.quick.files'); }, cmd: 'ls -la' },
    { get label() { return t('docker.console.quick.variables'); }, cmd: 'env | sort' },
    { get label() { return t('docker.console.quick.processes'); }, cmd: 'ps aux 2>/dev/null || ps' },
    { get label() { return t('docker.console.quick.disk'); }, cmd: 'df -h' },
    { get label() { return t('docker.console.quick.os'); }, cmd: 'cat /etc/os-release' },
    { get label() { return t('docker.console.quick.network'); }, cmd: 'cat /etc/hosts; (ip addr 2>/dev/null || ifconfig 2>/dev/null)' },
];

watch(() => props.container?.id, () => {
    entries.value = [];
    command.value = '';
});

const run = async (text = command.value) => {
    const cmd = text.trim();
    if (!cmd || running.value) return;
    running.value = true;
    history.value = [cmd, ...history.value.filter((h) => h !== cmd)].slice(0, 50);
    cursor.value = -1;
    command.value = '';
    const entry = { cmd, output: '', code: null, timedOut: false, error: '' };
    entries.value.push(entry);
    try {
        const result = await props.exec(props.container.name, cmd, user.value.trim(), workdir.value.trim());
        entry.output = result.output;
        entry.code = result.exit_code;
        entry.timedOut = result.timed_out;
    } catch (e) {
        entry.error = props.errorText(e);
    } finally {
        running.value = false;
        entries.value = [...entries.value];
        await nextTick();
        if (box.value) box.value.scrollTop = box.value.scrollHeight;
        input.value?.focus();
    }
};

const onKey = (event) => {
    if (event.key === 'ArrowUp' && history.value.length) {
        event.preventDefault();
        cursor.value = Math.min(cursor.value + 1, history.value.length - 1);
        command.value = history.value[cursor.value];
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        cursor.value = Math.max(cursor.value - 1, -1);
        command.value = cursor.value === -1 ? '' : history.value[cursor.value];
    } else if (event.key === 'l' && event.ctrlKey) {
        event.preventDefault();
        entries.value = [];
    }
};

const field = 'rounded-md border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <div class="space-y-2">
        <div v-if="container.state !== 'running'" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            {{ t('docker.console.notRunning', { state: container.state }) }}
        </div>
        <div class="flex flex-wrap items-center gap-1">
            <span class="mr-1 text-xs text-slate-500">{{ t('docker.console.quickLabel') }}</span>
            <button v-for="q in quick" :key="q.label" type="button" :disabled="running || container.state !== 'running'" class="rounded-full border border-slate-300 px-2.5 py-0.5 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="run(q.cmd)">{{ q.label }}</button>
            <button v-if="entries.length" type="button" class="ml-auto text-xs text-slate-500 hover:underline" @click="entries = []">{{ t('docker.console.clear') }}</button>
        </div>
        <div ref="box" class="max-h-[50vh] min-h-[12rem] overflow-auto rounded-md bg-slate-950 p-3 font-mono text-xs text-slate-100" @click="input?.focus()">
            <p v-if="!entries.length" class="text-slate-400">{{ t('docker.console.help') }}</p>
            <div v-for="(entry, i) in entries" :key="i" class="mb-3">
                <div class="text-emerald-400"><span class="select-none text-slate-500">{{ container.name }} $ </span>{{ entry.cmd }}</div>
                <pre v-if="entry.output" class="whitespace-pre-wrap break-all">{{ entry.output }}</pre>
                <div v-if="entry.error" class="text-red-300">{{ entry.error }}</div>
                <div v-else-if="entry.timedOut" class="text-amber-300">{{ t('docker.console.timedOut') }}</div>
                <div v-else-if="entry.code !== null && entry.code !== 0" class="text-amber-300">{{ t('docker.console.exitCode', { code: entry.code }) }}</div>
                <div v-else-if="entry.code === null" class="text-slate-400">{{ t('docker.console.running') }}</div>
            </div>
        </div>
        <form class="flex gap-2" @submit.prevent="run()">
            <input ref="input" v-model="command" type="text" :disabled="container.state !== 'running'" placeholder="ls -la /app" autocomplete="off" spellcheck="false" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800" @keydown="onKey" />
            <button type="submit" :disabled="running || !command.trim()" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-40 dark:bg-slate-100 dark:text-slate-900">{{ running ? t('docker.console.runningBtn') : t('docker.console.run') }}</button>
        </form>
        <details class="text-xs text-slate-500">
            <summary class="cursor-pointer">{{ t('docker.console.advanced') }}</summary>
            <div class="mt-2 flex flex-wrap gap-2">
                <input v-model="user" type="text" :placeholder="t('docker.console.user')" :class="field" />
                <input v-model="workdir" type="text" placeholder="/folder" :class="field" />
            </div>
        </details>
    </div>
</template>
