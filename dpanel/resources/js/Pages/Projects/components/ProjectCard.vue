<script setup>
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    panelRoute: { type: Function, required: true },
});
const emit = defineEmits(['edit']);

const busy = ref('');
const live = ref(null);

const control = (action) => {
    busy.value = action;
    router.post(props.panelRoute('projects.control', { project: props.project.id }), { action }, {
        preserveScroll: true,
        onFinish: () => { busy.value = ''; live.value = null; },
    });
};

const checkStatus = async () => {
    busy.value = 'status';
    try {
        const response = await fetch(props.panelRoute('projects.status', { project: props.project.id }), { headers: { Accept: 'application/json' } });
        const json = await response.json();
        live.value = json.success ? json.data : { error: json.message };
    } catch (error) {
        live.value = { error: String(error) };
    } finally {
        busy.value = '';
    }
};

const remove = () => {
    const shares = props.project.shares.length ? ` Its ${props.project.shares.length} port share(s) are removed too.` : '';
    if (!confirm(`Delete project "${props.project.name}"? The process is stopped; files stay in place.${shares}`)) return;
    router.delete(props.panelRoute('projects.destroy', { project: props.project.id }), { preserveScroll: true });
};
</script>

<template>
    <div class="flex flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
        <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-700">
            <div class="min-w-0">
                <p class="flex items-center gap-2 truncate font-semibold">
                    <i :class="project.runtime === 'node' ? 'bi bi-hexagon text-emerald-600' : 'bi bi-filetype-py text-sky-600'"></i>
                    {{ project.name }}
                </p>
                <p class="mt-0.5 truncate font-mono text-xs text-slate-500 dark:text-slate-400">{{ project.working_directory }}</p>
            </div>
            <span
                class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                :class="project.status === 'running' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300'"
            >{{ project.status === 'running' ? 'Running' : 'Stopped' }}</span>
        </div>

        <dl class="grid grid-cols-3 gap-2 px-4 py-3 text-xs">
            <div><dt class="text-slate-500 dark:text-slate-400">Port</dt><dd class="font-mono">127.0.0.1:{{ project.port }}</dd></div>
            <div><dt class="text-slate-500 dark:text-slate-400">Version</dt><dd>{{ project.version || 'default' }}</dd></div>
            <div><dt class="text-slate-500 dark:text-slate-400">User</dt><dd class="truncate">{{ project.site_owner }}</dd></div>
            <div class="col-span-3"><dt class="text-slate-500 dark:text-slate-400">Runs</dt><dd class="truncate font-mono">{{ project.start_command || project.entry_file }}</dd></div>
        </dl>

        <div class="px-4 pb-3 text-xs">
            <p class="mb-1 text-slate-500 dark:text-slate-400">Shared on</p>
            <div v-if="project.shares.length" class="flex flex-wrap gap-1">
                <a v-for="share in project.shares" :key="share.id" :href="share.url" target="_blank" rel="noopener" class="rounded bg-blue-50 px-2 py-0.5 font-mono text-blue-700 hover:underline dark:bg-blue-900/30 dark:text-blue-300" :class="{ 'opacity-50': !share.enabled }">{{ share.url }}</a>
            </div>
            <a v-else :href="panelRoute('port-shares.index')" class="text-blue-600 hover:underline dark:text-blue-400">Not public yet — share it on a website</a>
        </div>

        <p v-if="live" class="mx-4 mb-3 rounded bg-slate-50 px-2 py-1 text-xs dark:bg-slate-900">
            <template v-if="live.error"><span class="text-red-600">{{ live.error }}</span></template>
            <template v-else>systemd: <b>{{ live.active_state }}</b> · port {{ live.listening ? 'listening' : 'not listening' }}</template>
        </p>

        <div class="mt-auto flex flex-wrap items-center gap-1 border-t border-slate-100 px-3 py-2 dark:border-slate-700">
            <button v-if="project.status !== 'running'" @click="control('start')" :disabled="!!busy" class="rounded px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:text-emerald-400 dark:hover:bg-emerald-900/30"><i class="bi bi-play-fill"></i> Start</button>
            <button v-else @click="control('stop')" :disabled="!!busy" class="rounded px-2 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50 disabled:opacity-50 dark:text-amber-400 dark:hover:bg-amber-900/30"><i class="bi bi-stop-fill"></i> Stop</button>
            <button @click="control('restart')" :disabled="!!busy" class="rounded px-2 py-1 text-xs font-medium hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-700"><i class="bi bi-arrow-clockwise" :class="{ 'inline-block animate-spin': busy === 'restart' || busy === 'start' }"></i> Restart</button>
            <button @click="checkStatus" :disabled="!!busy" class="rounded px-2 py-1 text-xs font-medium hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-700"><i class="bi bi-activity"></i> Status</button>
            <span class="flex-1"></span>
            <button @click="emit('edit', project)" title="Edit" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-700 dark:hover:text-slate-200"><i class="bi bi-pencil"></i></button>
            <button @click="remove" title="Delete" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"><i class="bi bi-trash"></i></button>
        </div>
    </div>
</template>
