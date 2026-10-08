<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';

// The containers on this server: filter, select several for one action, and
// watch live CPU/RAM. A row opens the container's details.
const props = defineProps({
    containers: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    busy: { type: String, default: '' },
    stats: { type: Function, required: true },
});

const emit = defineEmits(['action', 'bulk', 'open']);

const search = ref('');
const filter = ref('all');
const selected = ref(new Set());
const live = ref(false);
const usage = ref({});
let timer = null;

const filters = computed(() => [
    { key: 'all', label: 'All', count: props.containers.length },
    { key: 'running', label: 'Running', count: props.containers.filter((c) => c.state === 'running').length },
    { key: 'stopped', label: 'Stopped', count: props.containers.filter((c) => c.state !== 'running').length },
    { key: 'stacks', label: 'In stacks', count: props.containers.filter((c) => c.project).length },
    { key: 'sites', label: 'Websites', count: props.containers.filter((c) => c.name.startsWith('dpanel-site-')).length },
].filter((f) => f.key === 'all' || f.count > 0));

const rows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return props.containers
        .filter((c) => ({
            all: true,
            running: c.state === 'running',
            stopped: c.state !== 'running',
            stacks: Boolean(c.project),
            sites: c.name.startsWith('dpanel-site-'),
        })[filter.value] ?? true)
        .filter((c) => !needle || `${c.name} ${c.image} ${c.id} ${c.project} ${c.ports}`.toLowerCase().includes(needle))
        .sort((a, b) => (a.state === 'running' ? 0 : 1) - (b.state === 'running' ? 0 : 1) || a.name.localeCompare(b.name));
});

// Drop selections that disappeared (removed, or filtered out).
watch(rows, (list) => {
    const ids = new Set(list.map((c) => c.id));
    selected.value = new Set([...selected.value].filter((id) => ids.has(id)));
});

const allSelected = computed(() => rows.value.length > 0 && rows.value.every((c) => selected.value.has(c.id)));
const toggleAll = () => { selected.value = allSelected.value ? new Set() : new Set(rows.value.map((c) => c.id)); };
const toggle = (id) => {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
};
const chosen = computed(() => props.containers.filter((c) => selected.value.has(c.id)));
const bulk = (action) => emit('bulk', action, chosen.value);

const pollStats = async () => {
    try {
        const list = await props.stats();
        usage.value = Object.fromEntries(list.map((s) => [s.name, s]));
    } catch {
        live.value = false;
    }
};
watch(live, (on) => {
    window.clearInterval(timer);
    if (on) {
        pollStats();
        timer = window.setInterval(pollStats, 5000);
    } else {
        usage.value = {};
    }
});
onBeforeUnmount(() => window.clearInterval(timer));

const percent = (text) => Math.min(100, parseFloat(String(text || '0')) || 0);
const shortPorts = (ports) => [...new Set(String(ports || '').split(',').map((p) => p.trim()).filter((p) => p && !p.startsWith('[::]')))];

const stateClass = (state) => ({
    running: 'bg-emerald-500',
    restarting: 'bg-amber-500 animate-pulse',
    paused: 'bg-amber-500',
    created: 'bg-sky-500',
}[state] || 'bg-slate-400');

const btn = 'inline-flex items-center justify-center rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800';
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-4 dark:border-slate-800">
            <div class="flex flex-wrap gap-1">
                <button v-for="f in filters" :key="f.key" type="button" class="rounded-full px-3 py-1 text-xs font-medium" :class="filter === f.key ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'" @click="filter = f.key">
                    {{ f.label }} <span class="opacity-60">{{ f.count }}</span>
                </button>
            </div>
            <div class="ml-auto flex w-full items-center gap-2 sm:w-auto">
                <label class="inline-flex shrink-0 items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300" title="CPU and memory, refreshed every 5 seconds">
                    <input v-model="live" type="checkbox" /> Live usage
                </label>
                <input v-model="search" data-docker-search type="search" placeholder="Find a container  ( / )" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm sm:w-64 dark:border-slate-700 dark:bg-slate-800" />
            </div>
        </div>

        <div v-if="selected.size" class="flex flex-wrap items-center gap-2 border-b border-indigo-200 bg-indigo-50 px-4 py-2 text-sm dark:border-indigo-900 dark:bg-indigo-950/40">
            <span class="font-medium">{{ selected.size }} selected</span>
            <button type="button" :class="btn" :disabled="busy === 'bulk'" @click="bulk('start')"><i class="bi bi-play-fill mr-1"></i>Start</button>
            <button type="button" :class="btn" :disabled="busy === 'bulk'" @click="bulk('stop')"><i class="bi bi-stop-fill mr-1"></i>Stop</button>
            <button type="button" :class="btn" :disabled="busy === 'bulk'" @click="bulk('restart')"><i class="bi bi-arrow-repeat mr-1"></i>Restart</button>
            <button type="button" :class="[btn, 'text-red-700 dark:text-red-300']" :disabled="busy === 'bulk'" @click="bulk('remove')"><i class="bi bi-trash mr-1"></i>Remove</button>
            <i v-if="busy === 'bulk'" class="bi bi-arrow-repeat inline-block animate-spin"></i>
            <button type="button" class="ml-auto text-xs text-slate-500 hover:underline" @click="selected = new Set()">Clear</button>
        </div>

        <div v-if="rows.length" class="hidden grid-cols-[2rem_minmax(0,2fr)_minmax(0,1.5fr)_minmax(0,1.3fr)_11.5rem] gap-3 px-4 py-2 text-xs font-medium text-slate-500 md:grid">
            <input type="checkbox" :checked="allSelected" aria-label="Select all" @change="toggleAll" />
            <span>Name</span><span>Image</span><span>{{ live ? 'Usage' : 'Ports' }}</span><span></span>
        </div>

        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="c in rows" :key="c.id" class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-3 gap-y-2 px-4 py-3 hover:bg-slate-50 md:grid-cols-[2rem_minmax(0,2fr)_minmax(0,1.5fr)_minmax(0,1.3fr)_11.5rem] md:items-center dark:hover:bg-slate-800/40">
                <input type="checkbox" class="mt-1 md:mt-0" :checked="selected.has(c.id)" :aria-label="`Select ${c.name}`" @change="toggle(c.id)" />
                <button type="button" class="min-w-0 text-left" @click="emit('open', c, 'overview')">
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 shrink-0 rounded-full" :class="stateClass(c.state)" :title="c.state"></span>
                        <span class="truncate font-medium hover:underline">{{ c.name }}</span>
                        <span v-if="c.project" class="shrink-0 rounded bg-violet-100 px-1.5 text-[10px] text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">{{ c.project }}</span>
                        <span v-else-if="c.name.startsWith('dpanel-site-')" class="shrink-0 rounded bg-sky-100 px-1.5 text-[10px] text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">website</span>
                    </div>
                    <div class="mt-0.5 truncate pl-4 text-xs text-slate-500">{{ c.status }}</div>
                </button>
                <div class="col-start-2 min-w-0 truncate font-mono text-xs text-slate-600 md:col-start-auto dark:text-slate-300" :title="c.image">{{ c.image }}</div>
                <div class="col-start-2 min-w-0 md:col-start-auto">
                    <template v-if="live && usage[c.name]">
                        <div class="flex items-center gap-2 text-xs">
                            <span class="w-10 text-slate-500">CPU</span>
                            <div class="h-1.5 flex-1 rounded bg-slate-100 dark:bg-slate-800"><div class="h-1.5 rounded bg-indigo-500" :style="{ width: `${percent(usage[c.name].cpu)}%` }"></div></div>
                            <span class="w-14 text-right font-mono">{{ usage[c.name].cpu }}</span>
                        </div>
                        <div class="mt-1 flex items-center gap-2 text-xs">
                            <span class="w-10 text-slate-500">RAM</span>
                            <div class="h-1.5 flex-1 rounded bg-slate-100 dark:bg-slate-800"><div class="h-1.5 rounded bg-emerald-500" :style="{ width: `${percent(usage[c.name].memory_percent)}%` }"></div></div>
                            <span class="w-14 truncate text-right font-mono" :title="usage[c.name].memory">{{ usage[c.name].memory.split(' / ')[0] }}</span>
                        </div>
                    </template>
                    <div v-else class="flex flex-wrap gap-1">
                        <span v-for="p in shortPorts(c.ports)" :key="p" class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] dark:bg-slate-800" :class="p.startsWith('0.0.0.0') ? 'text-amber-700 dark:text-amber-300' : ''">{{ p.replace('0.0.0.0:', 'public:').replace('127.0.0.1:', '') }}</span>
                        <span v-if="!c.ports" class="text-xs text-slate-400">no ports</span>
                    </div>
                </div>
                <div class="col-start-2 flex flex-wrap gap-1 md:col-start-auto md:justify-end">
                    <button v-if="c.state === 'paused'" type="button" :disabled="busy === c.id" :class="btn" title="Resume" @click="emit('action', 'unpause', c)"><i class="bi bi-play"></i></button>
                    <button v-else-if="c.state !== 'running'" type="button" :disabled="busy === c.id" :class="[btn, 'text-emerald-700 dark:text-emerald-300']" title="Start" @click="emit('action', 'start', c)"><i class="bi bi-play-fill"></i></button>
                    <button v-else type="button" :disabled="busy === c.id" :class="btn" title="Stop" @click="emit('action', 'stop', c)"><i class="bi bi-stop-fill"></i></button>
                    <button type="button" :disabled="busy === c.id" :class="btn" title="Restart" @click="emit('action', 'restart', c)"><i class="bi bi-arrow-repeat" :class="busy === c.id ? 'inline-block animate-spin' : ''"></i></button>
                    <button type="button" :class="btn" title="Logs" @click="emit('open', c, 'logs')"><i class="bi bi-journal-text"></i></button>
                    <button type="button" :class="btn" title="Console" @click="emit('open', c, 'console')"><i class="bi bi-terminal"></i></button>
                    <button type="button" :class="btn" title="Details" @click="emit('open', c, 'overview')"><i class="bi bi-three-dots"></i></button>
                </div>
            </li>
            <li v-if="!rows.length" class="px-4 py-10 text-center text-sm text-slate-500">
                <template v-if="loading">Loading…</template>
                <template v-else-if="search || filter !== 'all'">No container matches.</template>
                <template v-else>
                    No containers yet.
                    <slot name="empty" />
                </template>
            </li>
        </ul>
    </section>
</template>
