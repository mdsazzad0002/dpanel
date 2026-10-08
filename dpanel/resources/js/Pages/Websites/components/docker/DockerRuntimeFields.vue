<script setup>
import { onMounted, ref, watch } from 'vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

// The Docker part of the runtime settings drawer. Either the site runs its
// own container (which image, the port it listens on, where the site's folder
// appears inside it, its variables), or the domain is sent to a port that a
// running compose stack already publishes. Edits the parent's object through v-model.
const model = defineModel({ type: Object, required: true });
const { panelRoute, requestJson } = usePanelApi();

const stacks = ref([]);
const stackPorts = ref([]);
const stacksError = ref('');

const loadStacks = async () => {
    stacksError.value = '';
    try {
        const data = await requestJson(panelRoute('docker.stacks.list'), { method: 'GET' });
        stacks.value = (data.data?.stacks || []).filter((s) => s.total > 0 || s.managed);
    } catch (error) {
        stacksError.value = error?.message || 'Could not load stacks.';
    }
};

// The ports the chosen stack publishes, so picking one is a click.
const loadPorts = async (name) => {
    stackPorts.value = [];
    if (!name) return;
    try {
        const data = await requestJson(panelRoute('docker.stacks.show'), { body: { name } });
        const seen = new Map();
        for (const p of data.data?.ports || []) seen.set(Number(p.published), { port: Number(p.published), service: p.service, target: p.target });
        for (const s of data.data?.services || []) {
            for (const p of s.publishers || []) {
                if (!seen.has(Number(p.published))) seen.set(Number(p.published), { port: Number(p.published), service: s.service, target: p.target });
            }
        }
        stackPorts.value = [...seen.values()].sort((a, b) => a.port - b.port);
        if (!model.value.target_port && stackPorts.value.length) model.value.target_port = stackPorts.value[0].port;
    } catch {
        stackPorts.value = [];
    }
};

watch(() => model.value.source, (source) => { if (source === 'port' && !stacks.value.length) loadStacks(); });
watch(() => model.value.stack, (name) => {
    model.value.target_port = '';
    loadPorts(name);
});
onMounted(() => {
    if (model.value.source === 'port') {
        loadStacks();
        loadPorts(model.value.stack);
    }
});

defineProps({
    rootPath: { type: String, default: '' },
});

const presets = [
    { label: 'Nginx (static site)', image: 'nginx:alpine', port: 80, target: '/usr/share/nginx/html' },
    { label: 'Apache httpd (static site)', image: 'httpd:alpine', port: 80, target: '/usr/local/apache2/htdocs' },
];

const applyPreset = (preset) => {
    model.value.image = preset.image;
    model.value.container_port = preset.port;
    model.value.mount_target = preset.target;
};

const addEnv = () => model.value.env.push({ key: '', value: '' });

const input = 'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100';
const label = 'block text-sm font-medium text-slate-700 dark:text-slate-200';
const hint = 'mt-1.5 text-xs text-slate-500 dark:text-slate-400';
</script>

<template>
    <div>
        <span :class="label">What serves this domain</span>
        <div class="mt-1.5 grid grid-cols-1 gap-2 sm:grid-cols-2">
            <button type="button" class="rounded-lg border px-3 py-2 text-left text-xs" :class="model.source !== 'port' ? 'border-sky-500 bg-sky-50 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300' : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'" @click="model.source = 'image'">
                <span class="block font-medium">Run an image</span>
                <span class="opacity-80">The panel runs one container for this site.</span>
            </button>
            <button type="button" class="rounded-lg border px-3 py-2 text-left text-xs" :class="model.source === 'port' ? 'border-sky-500 bg-sky-50 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300' : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'" @click="model.source = 'port'">
                <span class="block font-medium">Use a running stack or port</span>
                <span class="opacity-80">WordPress + MySQL, Kibana, any compose app.</span>
            </button>
        </div>
    </div>
    <template v-if="model.source === 'port'">
        <div>
            <label for="docker-stack" :class="label">Stack</label>
            <select id="docker-stack" v-model="model.stack" :class="input">
                <option value="">None: just a port on this server</option>
                <option v-for="s in stacks" :key="s.name" :value="s.name">{{ s.name }} ({{ s.total ? `${s.running}/${s.total} running` : 'not deployed' }})</option>
            </select>
            <p v-if="stacksError" class="mt-1.5 text-xs text-red-600">{{ stacksError }}</p>
            <p :class="hint">With a stack, this site's Start, Stop, Restart and Logs act on it. Create stacks under Docker → Stacks.</p>
        </div>
        <div>
            <label for="docker-target-port" :class="label">Server port to send the domain to</label>
            <div v-if="stackPorts.length" class="mt-1.5 flex flex-wrap gap-1.5">
                <button v-for="p in stackPorts" :key="p.port" type="button" class="rounded-full border px-2.5 py-1 text-xs" :class="Number(model.target_port) === p.port ? 'border-sky-500 bg-sky-50 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300' : 'border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-300'" @click="model.target_port = p.port">
                    {{ p.port }} · {{ p.service }}
                </button>
            </div>
            <input id="docker-target-port" v-model.number="model.target_port" type="number" min="1" max="65535" required placeholder="8085" :class="input" />
            <p :class="hint">The port the app publishes on the server, e.g. <code>"127.0.0.1:8085:80"</code> in a compose file means 8085. SSL, IP rules and redirects of this site keep working.</p>
        </div>
    </template>
    <template v-else>
    <div>
        <span :class="label">Start from</span>
        <div class="mt-1.5 flex flex-wrap gap-2">
            <button v-for="preset in presets" :key="preset.image" type="button"
                class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                @click="applyPreset(preset)">
                {{ preset.label }}
            </button>
        </div>
        <p :class="hint">Or enter any image below. Its Docker Hub page lists the port it listens on and the folder it reads.</p>
    </div>
    <div>
        <label for="docker-image" :class="label">Image</label>
        <input id="docker-image" v-model="model.image" type="text" required placeholder="nginx:alpine" :class="input" class="font-mono" />
        <p :class="hint">Pulled from Docker Hub when it is not on the server yet, e.g. <code>nginx:alpine</code> or <code>ghcr.io/owner/app:1.2</code>.</p>
    </div>
    <div>
        <label for="docker-container-port" :class="label">Port the app listens on</label>
        <input id="docker-container-port" v-model.number="model.container_port" type="number" min="1" max="65535" required placeholder="80" :class="input" />
        <p :class="hint">The port inside the container (often 80, 3000 or 8080). dPanel picks the server-side port and sends this domain to it.</p>
    </div>
    <div>
        <label for="docker-mount-target" :class="label">Site folder inside the container (optional)</label>
        <input id="docker-mount-target" v-model="model.mount_target" type="text" placeholder="/usr/share/nginx/html" :class="input" class="font-mono" />
        <p :class="hint">
            The site's root folder <code>{{ rootPath || '/home/…' }}</code> appears at this path in the container.
            Upload and edit its files with this website's File Manager, then restart the container. Leave blank to mount nothing.
        </p>
    </div>
    <div>
        <div class="flex items-center justify-between">
            <span :class="label">Environment variables</span>
            <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="addEnv">Add variable</button>
        </div>
        <div v-for="(env, i) in model.env" :key="i" class="mt-2 grid grid-cols-[1fr_1fr_auto] items-center gap-2">
            <input v-model="env.key" type="text" placeholder="NAME" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
            <input v-model="env.value" type="text" placeholder="value" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
            <button type="button" class="rounded-md px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10" aria-label="Remove variable" @click="model.env.splice(i, 1)">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <p :class="hint">Passwords and settings the image asks for. Values are stored encrypted.</p>
    </div>
    <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
        <input v-model="model.public" type="checkbox" class="mt-0.5" />
        <span>
            Also open its port to the internet
            <span class="block text-xs text-slate-500 dark:text-slate-400">Off: reachable only through this domain. On: also at http://server-ip:port. Docker opens the port itself, so the server firewall does not block it.</span>
        </span>
    </label>
    </template>
</template>
