<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

// Logs of a container or a stack: pick how many lines, follow them live,
// filter, wrap, copy or download. `fetch(lines)` resolves to the log text.
const props = defineProps({
    fetch: { type: Function, required: true },
    // Changing it (another container or service) reloads from scratch.
    source: { type: String, required: true },
    filename: { type: String, default: 'docker.log' },
    errorText: { type: Function, default: (e) => e?.response?.data?.message || e?.message || 'Could not load logs.' },
});

const lines = ref(200);
const text = ref('');
const error = ref('');
const loading = ref(false);
const follow = ref(false);
const wrap = ref(true);
const filter = ref('');
const copied = ref(false);
const box = ref(null);
let timer = null;

const atBottom = () => !box.value || box.value.scrollHeight - box.value.scrollTop - box.value.clientHeight < 40;

const load = async ({ quiet = false } = {}) => {
    if (loading.value) return;
    const stick = atBottom();
    if (!quiet) loading.value = true;
    error.value = '';
    try {
        text.value = await props.fetch(lines.value);
        if (stick || !quiet) {
            await nextTick();
            if (box.value) box.value.scrollTop = box.value.scrollHeight;
        }
    } catch (e) {
        error.value = props.errorText(e);
        follow.value = false;
    } finally {
        loading.value = false;
    }
};

const shown = computed(() => {
    const needle = filter.value.trim().toLowerCase();
    if (!needle) return text.value;
    return text.value.split('\n').filter((line) => line.toLowerCase().includes(needle)).join('\n');
});
const matchCount = computed(() => (filter.value.trim() ? (shown.value ? shown.value.split('\n').length : 0) : null));

const lineClass = (line) => {
    if (/\b(error|fatal|panic|exception|failed)\b/i.test(line)) return 'text-red-300';
    if (/\b(warn|warning)\b/i.test(line)) return 'text-amber-300';
    return '';
};

watch(follow, (on) => {
    window.clearInterval(timer);
    if (on) timer = window.setInterval(() => load({ quiet: true }), 3000);
});
watch(() => props.source, () => {
    text.value = '';
    filter.value = '';
    load();
}, { immediate: true });
onBeforeUnmount(() => window.clearInterval(timer));

const copy = async () => {
    try {
        await navigator.clipboard.writeText(shown.value);
        copied.value = true;
        window.setTimeout(() => { copied.value = false; }, 1500);
    } catch {
        copied.value = false;
    }
};

const download = () => {
    const url = URL.createObjectURL(new Blob([text.value], { type: 'text/plain' }));
    const a = Object.assign(document.createElement('a'), { href: url, download: props.filename });
    a.click();
    URL.revokeObjectURL(url);
};

const btn = 'rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800';
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-2">
            <select v-model.number="lines" class="rounded-md border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800" aria-label="Lines" @change="load()">
                <option :value="100">Last 100 lines</option>
                <option :value="200">Last 200 lines</option>
                <option :value="1000">Last 1000 lines</option>
                <option :value="5000">Last 5000 lines</option>
            </select>
            <input v-model="filter" type="search" placeholder="Filter lines" class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 text-xs sm:max-w-xs dark:border-slate-700 dark:bg-slate-800" />
            <span v-if="matchCount !== null" class="text-xs text-slate-500">{{ matchCount }} match{{ matchCount === 1 ? '' : 'es' }}</span>
            <div class="ml-auto flex flex-wrap gap-1">
                <label class="inline-flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1 text-xs dark:border-slate-700" :class="follow ? 'border-emerald-400 text-emerald-700 dark:text-emerald-300' : ''">
                    <input v-model="follow" type="checkbox" /> Live
                </label>
                <button type="button" :class="btn" @click="wrap = !wrap">{{ wrap ? 'No wrap' : 'Wrap' }}</button>
                <button type="button" :class="btn" :disabled="!text" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</button>
                <button type="button" :class="btn" :disabled="!text" @click="download"><i class="bi bi-download"></i></button>
                <button type="button" :class="btn" :disabled="loading" @click="load()">{{ loading ? 'Loading…' : 'Reload' }}</button>
            </div>
        </div>
        <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ error }}</div>
        <div ref="box" class="max-h-[55vh] min-h-[12rem] overflow-auto rounded-md bg-slate-950 p-3 font-mono text-xs leading-relaxed text-slate-100">
            <template v-if="shown">
                <div v-for="(line, i) in shown.split('\n')" :key="i" :class="[lineClass(line), wrap ? 'whitespace-pre-wrap break-all' : 'whitespace-pre']">{{ line }}</div>
            </template>
            <div v-else class="text-slate-400">{{ loading ? 'Loading…' : (filter ? 'No line matches.' : 'No log output.') }}</div>
        </div>
    </div>
</template>
