<script setup>
import { useAppProjectActions } from '@/composables/useAppProjectActions';

const props = defineProps({
    app: { type: Object, required: true },
    panelRoute: { type: Function, required: true },
});
const emit = defineEmits(['edit']);

const { busy, live, control, checkStatus, remove } = useAppProjectActions(() => props.app, props.panelRoute);

const badges = {
    running: ['Running', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'],
    starting: ['Building…', 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'],
    error: ['Failed', 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'],
};
const badge = (status) => badges[status] || ['Stopped', 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300'];
</script>

<template>
    <div class="flex flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
        <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-700">
            <div class="min-w-0">
                <p class="flex items-center gap-2 truncate font-semibold"><i class="bi bi-gear-wide-connected text-orange-600"></i>{{ app.name }}</p>
                <p class="mt-0.5 truncate font-mono text-xs text-slate-500 dark:text-slate-400">{{ app.working_directory }}</p>
            </div>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="badge(app.status)[1]">
                <i v-if="app.status === 'starting'" class="bi bi-arrow-repeat mr-0.5 inline-block animate-spin"></i>{{ badge(app.status)[0] }}
            </span>
        </div>

        <dl class="grid grid-cols-3 gap-2 px-4 py-3 text-xs">
            <div><dt class="text-slate-500 dark:text-slate-400">Port</dt><dd class="font-mono">{{ app.port }}</dd></div>
            <div><dt class="text-slate-500 dark:text-slate-400">Runtime</dt><dd>Cargo release</dd></div>
            <div><dt class="text-slate-500 dark:text-slate-400">User</dt><dd class="truncate">{{ app.site_owner }}</dd></div>
            <div class="col-span-3"><dt class="text-slate-500 dark:text-slate-400">Command</dt><dd class="truncate font-mono">{{ app.start_command || 'Cargo binary' }}</dd></div>
        </dl>

        <div class="px-4 pb-3 text-xs">
            <p class="mb-1 text-slate-500 dark:text-slate-400">Public on</p>
            <div v-if="app.shares.length" class="flex flex-wrap gap-1">
                <a v-for="share in app.shares" :key="share.id" :href="share.url" target="_blank" rel="noopener" class="rounded bg-blue-50 px-2 py-0.5 font-mono text-blue-700 hover:underline dark:bg-blue-900/30 dark:text-blue-300" :class="{ 'opacity-50': !share.enabled }">{{ share.url }}</a>
            </div>
            <p v-else class="text-slate-500 dark:text-slate-400">Not public. <a :href="panelRoute('rules.port-shares.index')" class="text-blue-600 hover:underline dark:text-blue-400">Publish it from Rules → Port Share</a>.</p>
        </div>

        <p v-if="app.status === 'starting'" class="mx-4 mb-3 rounded bg-amber-50 px-2 py-1 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
            Building with cargo in the background, then starting. The first build can take a few minutes.
        </p>
        <pre v-else-if="app.status === 'error' && app.last_error" class="mx-4 mb-3 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded bg-red-50 px-2 py-1 text-xs text-red-700 dark:bg-red-900/20 dark:text-red-300">{{ app.last_error }}</pre>

        <p v-if="live" class="mx-4 mb-3 rounded bg-slate-50 px-2 py-1 text-xs dark:bg-slate-900">
            <span v-if="live.error" class="text-red-600">{{ live.error }}</span>
            <template v-else>systemd: <b>{{ live.active_state }}</b> · port {{ live.listening ? 'listening' : 'not listening' }}</template>
        </p>

        <div class="mt-auto flex flex-wrap items-center gap-1 border-t border-slate-100 px-3 py-2 dark:border-slate-700">
            <button v-if="app.status !== 'running' && app.status !== 'starting'" @click="control('start')" :disabled="!!busy" class="rounded px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:text-emerald-400 dark:hover:bg-emerald-900/30"><i class="bi bi-play-fill"></i> Start</button>
            <button v-else @click="control('stop')" :disabled="!!busy" class="rounded px-2 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50 disabled:opacity-50 dark:text-amber-400 dark:hover:bg-amber-900/30"><i class="bi bi-stop-fill"></i> Stop</button>
            <button @click="control('restart')" :disabled="!!busy || app.status === 'starting'" class="rounded px-2 py-1 text-xs font-medium hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-700"><i class="bi bi-arrow-clockwise" :class="{ 'inline-block animate-spin': busy === 'restart' || busy === 'start' || app.status === 'starting' }"></i> Restart</button>
            <button @click="checkStatus" :disabled="!!busy" class="rounded px-2 py-1 text-xs font-medium hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-700"><i class="bi bi-activity"></i> Status</button>
            <span class="flex-1"></span>
            <button @click="emit('edit', app)" title="Edit" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-700 dark:hover:text-slate-200"><i class="bi bi-pencil"></i></button>
            <button @click="remove" title="Delete" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"><i class="bi bi-trash"></i></button>
        </div>
    </div>
</template>
