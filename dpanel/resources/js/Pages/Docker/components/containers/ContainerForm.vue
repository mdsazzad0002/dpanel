<script setup>
import { computed, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import { blankSpec, formToSpec, parseDockerRun, parseEnvLines, specToForm } from '../../composables/runSpec';

// One form for "Run a container", "Edit and recreate" and "Duplicate": the
// basics up front, the rest under "More options", and two shortcuts — paste a
// `docker run` command, or paste a .env file into the variables.
const props = defineProps({
    show: { type: Boolean, default: false },
    // 'run' | 'recreate' | 'duplicate'
    mode: { type: String, default: 'run' },
    initial: { type: Object, default: null },
    // Template notes shown above the form.
    note: { type: String, default: '' },
    networks: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['close', 'submit']);

const form = ref(specToForm(blankSpec()));
const advanced = ref(false);
const pasteOpen = ref(false);
const pasteText = ref('');
const pasteNote = ref('');
const envBulkOpen = ref(false);
const envBulk = ref('');

watch(() => [props.show, props.initial], ([show]) => {
    if (!show) return;
    form.value = specToForm(props.initial || blankSpec());
    const f = form.value;
    advanced.value = Boolean(f.network || f.hostname || f.memory || f.cpus || f.entrypoint || f.commandText || f.pull);
    pasteOpen.value = false;
    envBulkOpen.value = false;
    pasteNote.value = '';
}, { immediate: true });

const title = computed(() => ({
    run: 'Run a container',
    recreate: `Edit and recreate ${props.initial?.name || ''}`,
    duplicate: 'Duplicate container',
})[props.mode] || 'Run a container');
const submitLabel = computed(() => ({ run: 'Run container', recreate: 'Recreate container', duplicate: 'Run copy' })[props.mode]);
const userNetworks = computed(() => props.networks.filter((n) => !['bridge', 'host', 'none'].includes(n)));

const applyPaste = () => {
    const { spec, ignored } = parseDockerRun(pasteText.value);
    if (!spec.image) {
        pasteNote.value = 'No image found. Paste a full command such as: docker run -d -p 8080:80 nginx:alpine';
        return;
    }
    form.value = specToForm(spec);
    advanced.value = Boolean(spec.network || spec.hostname || spec.memory || spec.cpus || spec.entrypoint || spec.command.length);
    pasteOpen.value = false;
    pasteText.value = '';
    pasteNote.value = ignored.length ? `Filled in. Not supported here and left out: ${ignored.join(', ')}` : 'Filled in from the command. Check the values, then run.';
};

const applyEnvBulk = () => {
    for (const pair of parseEnvLines(envBulk.value)) {
        const existing = form.value.env.find((e) => e.key === pair.key);
        if (existing) existing.value = pair.value;
        else form.value.env.push(pair);
    }
    envBulk.value = '';
    envBulkOpen.value = false;
};

const revealed = ref(new Set());
const secretLike = (key) => /pass|secret|token|key/i.test(key);

const submit = () => {
    const spec = formToSpec(form.value);
    if (spec.ports.some((p) => p.public) && !confirm('A public port is open to the internet. Docker writes its own firewall rules, so the server firewall does not block it. Continue?')) return;
    if (props.mode === 'recreate' && !confirm(`Recreate ${props.initial?.name}? It is stopped and replaced; data in volumes is kept, anything else inside the container is lost. If the new one fails to start, the old one comes back.`)) return;
    emit('submit', spec);
};

const input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
const small = 'rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800';
const labelCls = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300';
</script>

<template>
    <Modal :show="show" max-width="4xl" @close="$emit('close')">
        <form class="flex max-h-[90vh] flex-col" @submit.prevent="submit">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 p-5 dark:border-slate-800">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold">{{ title }}</h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">The image is pulled first if it is not on the server. Ports listen on 127.0.0.1 only, unless you make them public.</p>
                </div>
                <button type="button" class="rounded-md px-2 py-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Close" @click="$emit('close')"><i class="bi bi-x-lg"></i></button>
            </div>

            <div class="flex-1 space-y-5 overflow-y-auto p-5">
                <div v-if="error" role="alert" class="whitespace-pre-line rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ error }}</div>
                <div v-if="note" class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800 dark:border-sky-900 dark:bg-sky-950/50 dark:text-sky-200" v-html="note"></div>

                <div v-if="mode !== 'recreate'" class="rounded-md border border-dashed border-slate-300 p-3 dark:border-slate-700">
                    <button type="button" class="flex w-full items-center justify-between text-left text-xs font-medium text-indigo-600 dark:text-indigo-400" @click="pasteOpen = !pasteOpen">
                        <span><i class="bi bi-clipboard-plus mr-1"></i>Have a <code>docker run</code> command? Paste it to fill this form</span>
                        <i :class="pasteOpen ? 'bi bi-chevron-up' : 'bi bi-chevron-down'"></i>
                    </button>
                    <div v-if="pasteOpen" class="mt-2 space-y-2">
                        <textarea v-model="pasteText" rows="3" placeholder="docker run -d --name redis -p 6379:6379 -v redis-data:/data redis:7-alpine" :class="input" class="font-mono text-xs"></textarea>
                        <button type="button" :class="small" :disabled="!pasteText.trim()" @click="applyPaste">Fill in the form</button>
                    </div>
                    <p v-if="pasteNote" class="mt-2 text-xs text-slate-600 dark:text-slate-300">{{ pasteNote }}</p>
                </div>

                <div class="grid gap-3 md:grid-cols-3">
                    <label class="text-sm">
                        <span :class="labelCls">Image</span>
                        <input v-model="form.image" type="text" required placeholder="nginx:alpine" :class="input" class="font-mono" autocomplete="off" />
                    </label>
                    <label class="text-sm">
                        <span :class="labelCls">Name {{ mode === 'recreate' ? '' : '(optional)' }}</span>
                        <input v-model="form.name" type="text" placeholder="my-app" :class="input" autocomplete="off" />
                    </label>
                    <label class="text-sm">
                        <span :class="labelCls">Restart</span>
                        <select v-model="form.restart" :class="input">
                            <option value="unless-stopped">Unless stopped (recommended)</option>
                            <option value="always">Always</option>
                            <option value="on-failure">On failure</option>
                            <option value="no">Never</option>
                        </select>
                    </label>
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <span :class="labelCls">Ports <span class="font-normal text-slate-400">server port → container port</span></span>
                        <button type="button" :class="small" @click="form.ports.push({ host: '', container: '', protocol: 'tcp', public: false })"><i class="bi bi-plus"></i> Add port</button>
                    </div>
                    <div v-for="(port, i) in form.ports" :key="'p' + i" class="mt-2 grid grid-cols-2 items-center gap-2 md:grid-cols-[1fr_1fr_6rem_auto_auto]">
                        <input v-model="port.host" type="number" min="1" max="65535" placeholder="Server port" :class="input" aria-label="Server port" />
                        <input v-model="port.container" type="number" min="1" max="65535" placeholder="Container port" :class="input" aria-label="Container port" />
                        <select v-model="port.protocol" :class="input" aria-label="Protocol">
                            <option value="tcp">TCP</option>
                            <option value="udp">UDP</option>
                        </select>
                        <label class="flex items-center gap-1 text-xs" :class="port.public ? 'text-amber-600 dark:text-amber-400' : ''"><input v-model="port.public" type="checkbox" /> Public</label>
                        <button type="button" :class="small" aria-label="Remove port" @click="form.ports.splice(i, 1)"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>

                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span :class="labelCls">Environment variables</span>
                        <div class="flex gap-1">
                            <button type="button" :class="small" @click="envBulkOpen = !envBulkOpen"><i class="bi bi-clipboard"></i> Paste .env</button>
                            <button type="button" :class="small" @click="form.env.push({ key: '', value: '' })"><i class="bi bi-plus"></i> Add variable</button>
                        </div>
                    </div>
                    <div v-if="envBulkOpen" class="mt-2 space-y-2">
                        <textarea v-model="envBulk" rows="4" placeholder="POSTGRES_PASSWORD=secret&#10;POSTGRES_DB=app" :class="input" class="font-mono text-xs"></textarea>
                        <button type="button" :class="small" :disabled="!envBulk.trim()" @click="applyEnvBulk">Add these variables</button>
                    </div>
                    <div v-for="(env, i) in form.env" :key="'e' + i" class="mt-2 grid grid-cols-[1fr_1fr_auto] items-center gap-2">
                        <input v-model="env.key" type="text" placeholder="NAME" :class="input" class="font-mono" aria-label="Variable name" />
                        <div class="relative">
                            <input v-model="env.value" :type="secretLike(env.key) && !revealed.has(i) ? 'password' : 'text'" placeholder="value" :class="input" class="pr-8" aria-label="Variable value" autocomplete="off" />
                            <button v-if="secretLike(env.key)" type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-slate-400" :aria-label="revealed.has(i) ? 'Hide' : 'Show'" @click="revealed.has(i) ? revealed.delete(i) : revealed.add(i); revealed = new Set(revealed)">
                                <i :class="revealed.has(i) ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                            </button>
                        </div>
                        <button type="button" :class="small" aria-label="Remove variable" @click="form.env.splice(i, 1)"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <span :class="labelCls">Volumes <span class="font-normal text-slate-400">keep data across recreates</span></span>
                        <button type="button" :class="small" @click="form.volumes.push({ source: '', target: '', read_only: false })"><i class="bi bi-plus"></i> Add volume</button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        A plain name (<code>my-data</code>) makes a named volume Docker manages. A folder in a website's home (<code>/home/site-user/docker/my-app</code>) can be edited with that website's File Manager.
                    </p>
                    <div v-for="(volume, i) in form.volumes" :key="'v' + i" class="mt-2 grid grid-cols-2 items-center gap-2 md:grid-cols-[1fr_1fr_auto_auto]">
                        <input v-model="volume.source" type="text" placeholder="my-data or /home/user/folder" :class="input" class="font-mono" aria-label="Volume or host folder" />
                        <input v-model="volume.target" type="text" placeholder="/path/in/container" :class="input" class="font-mono" aria-label="Path in container" />
                        <label class="flex items-center gap-1 text-xs"><input v-model="volume.read_only" type="checkbox" /> Read-only</label>
                        <button type="button" :class="small" aria-label="Remove volume" @click="form.volumes.splice(i, 1)"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>

                <div class="rounded-md border border-slate-200 dark:border-slate-800">
                    <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm font-medium" @click="advanced = !advanced">
                        <span>More options <span class="text-xs font-normal text-slate-500">network, limits, command</span></span>
                        <i :class="advanced ? 'bi bi-chevron-up' : 'bi bi-chevron-down'"></i>
                    </button>
                    <div v-if="advanced" class="grid gap-3 border-t border-slate-200 p-3 md:grid-cols-2 dark:border-slate-800">
                        <label class="text-sm">
                            <span :class="labelCls">Network</span>
                            <select v-model="form.network" :class="input">
                                <option value="">Default (bridge)</option>
                                <option v-for="n in userNetworks" :key="n" :value="n">{{ n }}</option>
                                <option v-if="form.network && !userNetworks.includes(form.network)" :value="form.network">{{ form.network }}</option>
                            </select>
                            <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">Containers on the same network reach each other by name. Create one under Networks.</span>
                        </label>
                        <label class="text-sm">
                            <span :class="labelCls">Extra names on that network</span>
                            <input v-model="form.aliasText" type="text" :disabled="!form.network" placeholder="db, database" :class="input" class="disabled:opacity-50" />
                        </label>
                        <label class="text-sm">
                            <span :class="labelCls">Memory limit</span>
                            <input v-model="form.memory" type="text" placeholder="e.g. 512m or 2g (blank: no limit)" :class="input" />
                        </label>
                        <label class="text-sm">
                            <span :class="labelCls">CPU limit</span>
                            <input v-model="form.cpus" type="text" inputmode="decimal" placeholder="e.g. 0.5 or 2 (blank: no limit)" :class="input" />
                        </label>
                        <label class="text-sm">
                            <span :class="labelCls">Command (replaces the image's)</span>
                            <input v-model="form.commandText" type="text" placeholder="e.g. redis-server --appendonly yes" :class="input" class="font-mono" />
                        </label>
                        <label class="text-sm">
                            <span :class="labelCls">Entrypoint</span>
                            <input v-model="form.entrypoint" type="text" placeholder="blank: the image's own" :class="input" class="font-mono" />
                        </label>
                        <label class="text-sm">
                            <span :class="labelCls">Hostname</span>
                            <input v-model="form.hostname" type="text" placeholder="blank: the container ID" :class="input" />
                        </label>
                        <label class="flex items-center gap-2 self-end pb-2 text-sm">
                            <input v-model="form.pull" type="checkbox" />
                            Always pull the newest image first
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 p-4 dark:border-slate-800">
                <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="$emit('close')">Cancel</button>
                <button type="submit" :disabled="!form.image.trim() || busy" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40">
                    <i v-if="busy" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>{{ busy ? 'Working… (pulling can take minutes)' : submitLabel }}
                </button>
            </div>
        </form>
    </Modal>
</template>
