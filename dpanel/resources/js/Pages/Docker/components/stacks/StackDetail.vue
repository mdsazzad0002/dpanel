<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import CodeArea from '../CodeArea.vue';
import LogViewer from '../LogViewer.vue';

// One stack: its services with per-service controls, logs, the compose file
// to edit and redeploy, and how to put it on a domain.
const props = defineProps({
    stack: { type: Object, default: null },
    busy: { type: String, default: '' },
    message: { type: Object, default: null },
    api: { type: Object, required: true },
});

const emit = defineEmits(['close', 'action', 'save']);

const tab = ref('services');
const logService = ref('');
const edit = ref({ compose: '', env: '' });

watch(() => props.stack?.name, (name, old) => {
    if (name && name !== old) {
        tab.value = 'services';
        logService.value = '';
    }
});
watch(() => [props.stack?.compose, props.stack?.env], () => {
    edit.value = { compose: props.stack?.compose || '', env: props.stack?.env || '' };
}, { immediate: true });

const dirty = computed(() => props.stack && (edit.value.compose !== props.stack.compose || edit.value.env !== props.stack.env));
const services = computed(() => props.stack?.services || []);
const deployed = computed(() => services.value.length > 0);
const running = computed(() => services.value.filter((s) => s.state === 'running').length);
const serviceNames = computed(() => [...new Set([...(props.stack?.declared_services || []), ...services.value.map((s) => s.service)])].sort());
// Ports a website can sit in front of: published on 127.0.0.1 (or anywhere).
const webPorts = computed(() => {
    const seen = new Map();
    for (const p of props.stack?.ports || []) seen.set(String(p.published), { service: p.service, port: Number(p.published), target: p.target, public: p.public });
    for (const s of services.value) {
        for (const p of s.publishers) {
            if (!seen.has(String(p.published))) seen.set(String(p.published), { service: s.service, port: p.published, target: p.target, public: !String(p.host_ip).startsWith('127.') });
        }
    }
    return [...seen.values()].sort((a, b) => a.port - b.port);
});

const tabs = computed(() => [
    { key: 'services', label: 'Services', icon: 'bi bi-boxes' },
    { key: 'logs', label: 'Logs', icon: 'bi bi-journal-text' },
    ...(props.stack?.managed ? [{ key: 'compose', label: 'Compose file', icon: 'bi bi-file-earmark-code' }] : [{ key: 'compose', label: 'Compose file (read-only)', icon: 'bi bi-file-earmark-code' }]),
    { key: 'domain', label: 'Put on a domain', icon: 'bi bi-globe' },
]);

const fetchLogs = (lines) => props.api.post('docker.stacks.logs', { name: props.stack.name, service: logService.value, lines }).then(({ data }) => data.data.logs);
const act = (action, service = '') => emit('action', action, props.stack, service);
const save = (deploy) => emit('save', { name: props.stack.name, ...edit.value, deploy });

const stateDot = (state) => ({ running: 'bg-emerald-500', restarting: 'bg-amber-500 animate-pulse', paused: 'bg-amber-500' }[state] || 'bg-slate-400');
const btn = 'inline-flex items-center gap-1 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800';
const small = 'rounded border border-slate-300 px-1.5 py-0.5 text-[11px] hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800';
const spinning = (key) => props.busy === key;
</script>

<template>
    <Modal :show="!!stack" max-width="5xl" @close="$emit('close')">
        <div v-if="stack" class="flex max-h-[92vh] flex-col">
            <div class="border-b border-slate-200 p-5 dark:border-slate-800">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="truncate text-base font-semibold"><i class="bi bi-stack mr-1 text-violet-600"></i>{{ stack.name }}</h2>
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] dark:bg-slate-800">{{ deployed ? `${running}/${services.length} running` : 'not deployed' }}</span>
                            <span v-if="!stack.managed" class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">started outside the panel</span>
                        </div>
                        <p class="mt-0.5 truncate font-mono text-[11px] text-slate-500">{{ stack.managed ? stack.dir : stack.config_files }}</p>
                    </div>
                    <button type="button" class="rounded-md px-2 py-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Close" @click="$emit('close')"><i class="bi bi-x-lg"></i></button>
                </div>

                <div class="mt-3 flex flex-wrap gap-1.5">
                    <button v-if="stack.managed" type="button" :disabled="!!busy" :class="[btn, 'border-indigo-300 text-indigo-700 dark:border-indigo-800 dark:text-indigo-300']" title="Create or update its containers from the compose file" @click="act('up')">
                        <i class="bi bi-rocket-takeoff" :class="spinning('up') ? 'animate-pulse' : ''"></i>{{ spinning('up') ? 'Deploying…' : 'Deploy' }}
                    </button>
                    <button v-if="deployed && running < services.length" type="button" :disabled="!!busy" :class="btn" @click="act('start')"><i class="bi bi-play-fill"></i>Start</button>
                    <button v-if="running" type="button" :disabled="!!busy" :class="btn" @click="act('stop')"><i class="bi bi-stop-fill"></i>Stop</button>
                    <button v-if="deployed" type="button" :disabled="!!busy" :class="btn" @click="act('restart')"><i class="bi bi-arrow-repeat" :class="spinning('restart') ? 'inline-block animate-spin' : ''"></i>Restart</button>
                    <button v-if="stack.managed" type="button" :disabled="!!busy" :class="btn" title="Pull the newest images and recreate what changed" @click="act('update')"><i class="bi bi-cloud-arrow-down" :class="spinning('update') ? 'animate-pulse' : ''"></i>{{ spinning('update') ? 'Updating…' : 'Update images' }}</button>
                    <button v-if="deployed" type="button" :disabled="!!busy" :class="btn" title="Stop and remove its containers and network; volumes are kept" @click="act('down')"><i class="bi bi-power"></i>Down</button>
                    <button type="button" :disabled="!!busy" :class="[btn, 'border-red-300 text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950']" @click="act('remove')"><i class="bi bi-trash"></i>{{ stack.managed ? 'Delete' : 'Take down' }}</button>
                </div>

                <div v-if="message" role="status" class="mt-3 whitespace-pre-line rounded-md border px-3 py-2 text-xs" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'">{{ message.text }}</div>
                <ul v-if="stack.warnings?.length" class="mt-3 space-y-1 rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    <li v-for="w in stack.warnings" :key="w"><i class="bi bi-exclamation-triangle mr-1"></i>{{ w }}</li>
                </ul>

                <nav class="-mb-5 mt-4 flex gap-1 overflow-x-auto" aria-label="Stack sections">
                    <button v-for="t in tabs" :key="t.key" type="button" class="inline-flex shrink-0 items-center gap-1 border-b-2 px-3 py-2 text-xs font-medium" :class="tab === t.key ? 'border-indigo-500 text-indigo-600 dark:text-indigo-300' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'" @click="tab = t.key">
                        <i :class="t.icon"></i>{{ t.label }}<span v-if="t.key === 'compose' && dirty" class="ml-1 h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                    </button>
                </nav>
            </div>

            <div class="flex-1 overflow-y-auto p-5">
                <template v-if="tab === 'services'">
                    <p v-if="!deployed" class="rounded-md border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700">
                        Not running yet. <button v-if="stack.managed" type="button" class="text-indigo-600 hover:underline dark:text-indigo-400" @click="act('up')">Deploy it</button>
                        <span v-if="serviceNames.length"> to start {{ serviceNames.join(', ') }}.</span>
                    </p>
                    <ul v-else class="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                        <li v-for="s in services" :key="s.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-3 py-2.5">
                            <div class="min-w-0 flex-1 basis-48">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full" :class="stateDot(s.state)"></span>
                                    <span class="font-medium">{{ s.service }}</span>
                                    <span v-if="s.health" class="rounded bg-slate-100 px-1.5 text-[10px] dark:bg-slate-800" :class="s.health === 'healthy' ? 'text-emerald-600' : 'text-amber-600'">{{ s.health }}</span>
                                </div>
                                <div class="mt-0.5 truncate pl-4 font-mono text-[11px] text-slate-500">{{ s.image }} · {{ s.status }}</div>
                            </div>
                            <div class="flex flex-wrap gap-1 text-[11px]">
                                <span v-for="p in s.publishers.filter((x) => x.host_ip !== '::')" :key="`${p.host_ip}:${p.published}`" class="rounded bg-slate-100 px-1.5 py-0.5 font-mono dark:bg-slate-800" :class="String(p.host_ip).startsWith('127.') ? '' : 'text-amber-700 dark:text-amber-300'">{{ String(p.host_ip).startsWith('127.') ? '' : 'public:' }}{{ p.published }}→{{ p.target }}</span>
                            </div>
                            <div class="flex gap-1">
                                <button v-if="s.state !== 'running'" type="button" :disabled="!!busy" :class="small" @click="act('start', s.service)">Start</button>
                                <button v-else type="button" :disabled="!!busy" :class="small" @click="act('stop', s.service)">Stop</button>
                                <button type="button" :disabled="!!busy" :class="small" @click="act('restart', s.service)">Restart</button>
                                <button type="button" :class="small" @click="logService = s.service; tab = 'logs'">Logs</button>
                            </div>
                        </li>
                    </ul>
                    <p class="mt-3 text-xs text-slate-500">Services reach each other by service name on the stack's own network, e.g. <code>{{ serviceNames[1] || 'db' }}</code> as the database host.</p>
                </template>

                <template v-if="tab === 'logs'">
                    <div class="mb-2 flex flex-wrap items-center gap-1">
                        <span class="text-xs text-slate-500">Service:</span>
                        <button v-for="name in ['', ...serviceNames]" :key="name || 'all'" type="button" class="rounded-full px-2.5 py-0.5 text-xs" :class="logService === name ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300'" @click="logService = name">{{ name || 'All' }}</button>
                    </div>
                    <LogViewer :fetch="fetchLogs" :source="`${stack.name}/${logService}`" :filename="`${stack.name}${logService ? '-' + logService : ''}.log`" :error-text="api.errorText" />
                </template>

                <template v-if="tab === 'compose'">
                    <div v-if="stack.managed" class="grid gap-4 lg:grid-cols-[2fr_1fr]">
                        <CodeArea v-model="edit.compose" label="docker-compose.yml" accept=".yml,.yaml" :rows="24" @save="save(false)" />
                        <div>
                            <CodeArea v-model="edit.env" label="Variables (.env)" accept=".env,text/plain" :rows="10" @save="save(false)" />
                            <p class="mt-2 text-xs text-slate-500">Saved files are checked with <code>docker compose config</code> first; a broken file never replaces a working one.</p>
                        </div>
                    </div>
                    <div v-else>
                        <p class="mb-2 text-xs text-slate-500">This stack was started from the shell, so the panel only shows its file. Start, stop, restart and take down work.</p>
                        <pre class="max-h-[60vh] overflow-auto rounded-md bg-slate-950 p-3 font-mono text-xs text-slate-100">{{ stack.compose || 'The compose file could not be read.' }}</pre>
                    </div>
                </template>

                <template v-if="tab === 'domain'">
                    <div class="space-y-4 text-sm">
                        <p>Open this stack on your own domain, with SSL, by putting a website in front of one of its ports.</p>
                        <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ports you can use</h3>
                            <ul v-if="webPorts.length" class="mt-2 space-y-1">
                                <li v-for="p in webPorts" :key="p.port" class="flex flex-wrap items-center gap-2">
                                    <code class="rounded bg-slate-100 px-1.5 dark:bg-slate-800">{{ p.port }}</code>
                                    <span class="text-slate-500">→ {{ p.service }} (container port {{ p.target }})</span>
                                    <span v-if="p.public" class="text-xs text-amber-600">also public on the server IP</span>
                                </li>
                            </ul>
                            <p v-else class="mt-2 text-slate-500">This stack publishes no port. Add one to the web service, e.g. <code>ports: ["127.0.0.1:8090:80"]</code>, and deploy.</p>
                        </div>
                        <ol class="list-decimal space-y-2 pl-5">
                            <li>Create a website for the domain under <strong>Websites</strong> (or open an existing one).</li>
                            <li>On its <strong>Manage</strong> page open <strong>Runtime settings</strong>, choose <strong>Docker</strong>, then <strong>Use a running stack or port</strong>.</li>
                            <li>Pick stack <code>{{ stack.name }}</code> and the port{{ webPorts[0] ? ` (${webPorts[0].port})` : '' }}. Save: the domain now opens the app, and SSL works as for any site.</li>
                        </ol>
                        <Link :href="api.panelRoute('websites.list')" class="inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Go to Websites</Link>
                    </div>
                </template>
            </div>

            <div v-if="tab === 'compose' && stack.managed" class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 p-4 dark:border-slate-800">
                <span v-if="dirty" class="mr-auto text-xs text-amber-600">Unsaved changes</span>
                <button type="button" :disabled="!dirty || !!busy" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="edit = { compose: stack.compose, env: stack.env }">Discard</button>
                <button type="button" :disabled="!dirty || !!busy" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="save(false)">{{ busy === 'save' ? 'Checking…' : 'Save' }}</button>
                <button type="button" :disabled="!!busy" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40" @click="save(true)">
                    <i v-if="busy === 'deploy'" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>{{ busy === 'deploy' ? 'Deploying…' : 'Save & deploy' }}
                </button>
            </div>
        </div>
    </Modal>
</template>
