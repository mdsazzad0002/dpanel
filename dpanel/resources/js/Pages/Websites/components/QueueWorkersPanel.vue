<script setup>
import { inject, onMounted, reactive, ref } from 'vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
});

const pushToast = inject('pushToast');
const { panelRoute, requestJson } = usePanelApi();

const MAX_WORKERS = 10;
const defaults = () => ({
    queue: 'default',
    connection: '',
    processes: 1,
    tries: 3,
    timeout: 60,
    sleep: 3,
    memory: 128,
    enabled: true,
});

const workers = ref([]);
const statusError = ref('');
const loading = ref(false);
const busy = ref('');
const formOpen = ref(false);
const editingId = ref(null);
const form = reactive(defaults());
const logs = reactive({});

const applyState = (data) => {
    workers.value = Array.isArray(data?.workers) ? data.workers : [];
    statusError.value = String(data?.status_error || '');
};

const load = async () => {
    loading.value = true;
    try {
        applyState(await requestJson(panelRoute('websites.queue-workers.index', { id: props.website.id }), { method: 'GET' }));
    } catch (error) {
        pushToast?.(error?.message || 'Could not load queue workers.', 'error');
    } finally {
        loading.value = false;
    }
};

onMounted(load);

const openForm = (worker = null) => {
    Object.assign(form, defaults(), worker ? {
        queue: worker.queue || 'default',
        connection: worker.connection || '',
        processes: worker.processes,
        tries: worker.tries,
        timeout: worker.timeout,
        sleep: worker.sleep,
        memory: worker.memory,
        enabled: Boolean(worker.enabled),
    } : {});
    editingId.value = worker?.id ?? null;
    formOpen.value = true;
};

const closeForm = () => {
    formOpen.value = false;
    editingId.value = null;
};

// Every mutating endpoint answers with the fresh worker list, also on a
// failed start (the row is kept so the app can be fixed and retried).
const run = async (key, url, method, body = {}) => {
    if (busy.value) return false;
    busy.value = key;
    try {
        const data = await requestJson(url, { method, body });
        applyState(data);
        pushToast?.(data.message || 'Done.', 'success');
        return true;
    } catch (error) {
        pushToast?.(error?.message || 'Queue worker action failed.', 'error');
        await load();
        return false;
    } finally {
        busy.value = '';
    }
};

const save = async () => {
    const body = {
        ...form,
        queue: String(form.queue || '').replace(/\s+/g, ''),
        connection: String(form.connection || '').trim(),
    };
    const ok = editingId.value
        ? await run('save', panelRoute('websites.queue-workers.update', { id: props.website.id, worker: editingId.value }), 'PUT', body)
        : await run('save', panelRoute('websites.queue-workers.store', { id: props.website.id }), 'POST', body);
    if (ok) closeForm();
};

const toggleEnabled = (worker) => run(
    `toggle-${worker.id}`,
    panelRoute('websites.queue-workers.update', { id: props.website.id, worker: worker.id }),
    'PUT',
    { ...worker, connection: worker.connection || '', enabled: !worker.enabled },
);

const remove = (worker) => {
    if (!window.confirm(`Remove the "${worker.queue}" worker and stop its processes?`)) return;
    run(`delete-${worker.id}`, panelRoute('websites.queue-workers.destroy', { id: props.website.id, worker: worker.id }), 'DELETE');
};

const restartAll = () => run('restart', panelRoute('websites.queue-workers.restart', { id: props.website.id }), 'POST');

const toggleLogs = async (worker) => {
    if (logs[worker.id] !== undefined) {
        delete logs[worker.id];
        return;
    }
    logs[worker.id] = 'Loading…';
    try {
        const data = await requestJson(panelRoute('websites.queue-workers.logs', { id: props.website.id, worker: worker.id }), { method: 'GET' });
        logs[worker.id] = String(data.output || '').trim() || 'No log lines yet.';
    } catch (error) {
        logs[worker.id] = error?.message || 'Could not load logs.';
    }
};

const instanceClass = (state) => {
    if (state === 'active') return 'bg-emerald-500';
    if (state === 'activating' || state === 'reloading') return 'bg-amber-400';
    if (state === 'failed') return 'bg-red-500';
    return 'bg-slate-300 dark:bg-slate-600';
};

const workerSummary = (worker) => {
    const instances = Array.isArray(worker.instances) ? worker.instances : [];
    if (!worker.enabled) return { label: 'Disabled', className: 'border-slate-200 bg-slate-50 text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400' };
    const running = instances.filter((instance) => instance.active_state === 'active').length;
    if (instances.some((instance) => instance.active_state === 'failed')) {
        return { label: `${running}/${worker.processes} running · failing`, className: 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400' };
    }
    if (running >= worker.processes) {
        return { label: `${running}/${worker.processes} running`, className: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400' };
    }
    return { label: `${running}/${worker.processes} running`, className: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400' };
};

const inputClass = 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100';
const labelClass = 'block text-xs font-medium text-slate-600 dark:text-slate-300';
</script>

<template>
    <section class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                    <i class="bi bi-stack text-base text-rose-600 dark:text-rose-400"></i>
                    Queue Workers
                </h3>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Runs <code>php artisan queue:work</code> as background services. They start automatically when the server boots and restart if they crash.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" :disabled="Boolean(busy) || loading"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                    @click="load">
                    <i class="bi bi-arrow-repeat" :class="loading ? 'animate-spin' : ''"></i>
                    Refresh
                </button>
                <button v-if="workers.length" type="button" :disabled="Boolean(busy)"
                    class="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-700 transition hover:bg-amber-100 disabled:opacity-60 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400"
                    title="Re-apply every worker and restart it, e.g. after deploying new code"
                    @click="restartAll">
                    <i class="bi bi-arrow-clockwise"></i>
                    {{ busy === 'restart' ? 'Restarting…' : 'Restart all' }}
                </button>
                <button type="button" :disabled="Boolean(busy) || workers.length >= MAX_WORKERS"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60"
                    @click="openForm()">
                    <i class="bi bi-plus-lg"></i>
                    Add worker
                </button>
            </div>
        </div>

        <p v-if="statusError" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400">
            Live status unavailable: {{ statusError }}
        </p>

        <form v-if="formOpen" class="mt-4 rounded-xl border border-blue-200 bg-blue-50/40 p-4 dark:border-blue-900/60 dark:bg-blue-950/20" @submit.prevent="save">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ editingId ? 'Edit worker' : 'New worker' }}</h4>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2">
                    <label :class="labelClass" for="qw-queue">Queue(s)</label>
                    <input id="qw-queue" v-model="form.queue" type="text" required placeholder="default" :class="inputClass" />
                    <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Comma-separated, highest priority first, e.g. <code>high,default</code>.</p>
                </div>
                <div class="sm:col-span-2">
                    <label :class="labelClass" for="qw-connection">Connection (optional)</label>
                    <input id="qw-connection" v-model="form.connection" type="text" placeholder="From .env QUEUE_CONNECTION" :class="inputClass" />
                    <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">e.g. <code>redis</code> or <code>database</code>. Leave blank to use the app's default.</p>
                </div>
                <div>
                    <label :class="labelClass" for="qw-processes">Processes</label>
                    <input id="qw-processes" v-model.number="form.processes" type="number" min="1" max="16" required :class="inputClass" />
                </div>
                <div>
                    <label :class="labelClass" for="qw-tries">Tries</label>
                    <input id="qw-tries" v-model.number="form.tries" type="number" min="1" max="100" required :class="inputClass" />
                </div>
                <div>
                    <label :class="labelClass" for="qw-timeout">Timeout (seconds)</label>
                    <input id="qw-timeout" v-model.number="form.timeout" type="number" min="0" max="3600" required :class="inputClass" />
                </div>
                <div>
                    <label :class="labelClass" for="qw-memory">Memory limit (MB)</label>
                    <input id="qw-memory" v-model.number="form.memory" type="number" min="64" max="4096" required :class="inputClass" />
                </div>
                <div>
                    <label :class="labelClass" for="qw-sleep">Sleep when idle (seconds)</label>
                    <input id="qw-sleep" v-model.number="form.sleep" type="number" min="1" max="60" required :class="inputClass" />
                </div>
                <label class="flex items-center gap-2 self-end pb-2 text-xs font-medium text-slate-600 dark:text-slate-300">
                    <input v-model="form.enabled" type="checkbox" class="rounded border-slate-300 text-blue-600 dark:border-slate-600" />
                    Enabled (run now and on boot)
                </label>
            </div>
            <p class="mt-3 text-[11px] text-slate-500 dark:text-slate-400">
                Keep the timeout a few seconds below the connection's <code>retry_after</code> in <code>config/queue.php</code> (90 by default), or a slow job can run twice.
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" :disabled="busy === 'save'" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800" @click="closeForm">Cancel</button>
                <button type="submit" :disabled="busy === 'save'" class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-50">{{ busy === 'save' ? 'Saving…' : (editingId ? 'Save worker' : 'Add & start') }}</button>
            </div>
        </form>

        <div v-if="!workers.length && !formOpen" class="mt-4 rounded-xl border border-dashed border-slate-300 px-4 py-6 text-center text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">
            {{ loading ? 'Loading…' : 'No queue workers yet. Add one to process jobs dispatched by this app.' }}
        </div>

        <ul v-if="workers.length" class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
            <li v-for="worker in workers" :key="worker.id" class="p-3">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-sm font-medium text-slate-800 dark:text-slate-100">{{ worker.queue }}</span>
                            <span v-if="worker.connection" class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ worker.connection }}</span>
                            <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium" :class="workerSummary(worker).className">
                                {{ workerSummary(worker).label }}
                            </span>
                            <span v-if="worker.enabled && worker.instances?.length" class="flex items-center gap-1">
                                <span v-for="instance in worker.instances" :key="instance.instance"
                                    class="h-2 w-2 rounded-full" :class="instanceClass(instance.active_state)"
                                    :title="`Process #${instance.instance}: ${instance.active_state}${instance.enabled ? ', starts on boot' : ''}`"></span>
                            </span>
                        </div>
                        <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                            {{ worker.processes }} process{{ worker.processes === 1 ? '' : 'es' }}
                            · {{ worker.tries }} tries · {{ worker.timeout }}s timeout · {{ worker.memory }} MB · sleep {{ worker.sleep }}s
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <button type="button" :disabled="Boolean(busy)"
                            class="rounded-md px-2 py-1 text-xs font-medium transition disabled:opacity-50"
                            :class="worker.enabled ? 'text-amber-700 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-500/10' : 'text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10'"
                            @click="toggleEnabled(worker)">
                            <i class="bi" :class="worker.enabled ? 'bi-pause-fill' : 'bi-play-fill'"></i>
                            {{ busy === `toggle-${worker.id}` ? '…' : (worker.enabled ? 'Disable' : 'Enable') }}
                        </button>
                        <button type="button" class="rounded-md px-2 py-1 text-xs font-medium text-slate-600 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="toggleLogs(worker)">
                            <i class="bi bi-journal-text"></i> Logs
                        </button>
                        <button type="button" :disabled="Boolean(busy)" class="rounded-md px-2 py-1 text-xs font-medium text-slate-600 transition hover:bg-slate-100 disabled:opacity-50 dark:text-slate-300 dark:hover:bg-slate-800" @click="openForm(worker)">
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                        <button type="button" :disabled="Boolean(busy)" class="rounded-md px-2 py-1 text-xs font-medium text-red-600 transition hover:bg-red-50 disabled:opacity-50 dark:text-red-400 dark:hover:bg-red-500/10" @click="remove(worker)">
                            <i class="bi bi-trash"></i> {{ busy === `delete-${worker.id}` ? '…' : 'Remove' }}
                        </button>
                    </div>
                </div>
                <pre v-if="logs[worker.id] !== undefined" class="mt-3 max-h-64 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-950 p-3 text-[11px] leading-relaxed text-slate-200">{{ logs[worker.id] }}</pre>
            </li>
        </ul>
    </section>
</template>
