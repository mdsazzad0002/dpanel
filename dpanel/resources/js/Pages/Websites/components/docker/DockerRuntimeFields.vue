<script setup>
import { useI18n } from 'vue-i18n';
import { onMounted, ref, watch } from 'vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import { useServerBaseDir } from '@/composables/useServerBaseDir';

const { t } = useI18n();

const { baseDir } = useServerBaseDir();

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
        stacksError.value = error?.message || t('websites.docker.stacksFailed');
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
    { label: t('websites.docker.nginxPreset'), image: 'nginx:alpine', port: 80, target: '/usr/share/nginx/html' },
    { label: t('websites.docker.apachePreset'), image: 'httpd:alpine', port: 80, target: '/usr/local/apache2/htdocs' },
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
        <span :class="label">{{ t('websites.docker.serves') }}</span>
        <div class="mt-1.5 grid grid-cols-1 gap-2 sm:grid-cols-2">
            <button type="button" class="rounded-lg border px-3 py-2 text-left text-xs" :class="model.source !== 'port' ? 'border-sky-500 bg-sky-50 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300' : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'" @click="model.source = 'image'">
                <span class="block font-medium">{{ t('websites.docker.runImage') }}</span>
                <span class="opacity-80">{{ t('websites.docker.runImageHelp') }}</span>
            </button>
            <button type="button" class="rounded-lg border px-3 py-2 text-left text-xs" :class="model.source === 'port' ? 'border-sky-500 bg-sky-50 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300' : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'" @click="model.source = 'port'">
                <span class="block font-medium">{{ t('websites.docker.usePort') }}</span>
                <span class="opacity-80">{{ t('websites.docker.usePortHelp') }}</span>
            </button>
        </div>
    </div>
    <template v-if="model.source === 'port'">
        <div>
            <label for="docker-stack" :class="label">{{ t('websites.docker.stack') }}</label>
            <select id="docker-stack" v-model="model.stack" :class="input">
                <option value="">{{ t('websites.docker.noStack') }}</option>
                <option v-for="s in stacks" :key="s.name" :value="s.name">{{ s.name }} ({{ s.total ? t('websites.docker.stackRunning', { running: s.running, total: s.total }) : t('websites.docker.notDeployed') }})</option>
            </select>
            <p v-if="stacksError" class="mt-1.5 text-xs text-red-600">{{ stacksError }}</p>
            <p :class="hint">{{ t('websites.docker.stackHelp') }}</p>
        </div>
        <div>
            <label for="docker-target-port" :class="label">{{ t('websites.docker.targetPort') }}</label>
            <div v-if="stackPorts.length" class="mt-1.5 flex flex-wrap gap-1.5">
                <button v-for="p in stackPorts" :key="p.port" type="button" class="rounded-full border px-2.5 py-1 text-xs" :class="Number(model.target_port) === p.port ? 'border-sky-500 bg-sky-50 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300' : 'border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-300'" @click="model.target_port = p.port">
                    {{ p.port }} · {{ p.service }}
                </button>
            </div>
            <input id="docker-target-port" v-model.number="model.target_port" type="number" min="1" max="65535" required placeholder="8085" :class="input" />
            <i18n-t keypath="websites.docker.targetPortHelp" tag="p" :class="hint"><template #example><code>"127.0.0.1:8085:80"</code></template></i18n-t>
        </div>
    </template>
    <template v-else>
    <div>
        <span :class="label">{{ t('websites.docker.startFrom') }}</span>
        <div class="mt-1.5 flex flex-wrap gap-2">
            <button v-for="preset in presets" :key="preset.image" type="button"
                class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                @click="applyPreset(preset)">
                {{ preset.label }}
            </button>
        </div>
        <p :class="hint">{{ t('websites.docker.anyImage') }}</p>
    </div>
    <div>
        <label for="docker-image" :class="label">{{ t('websites.docker.image') }}</label>
        <input id="docker-image" v-model="model.image" type="text" required placeholder="nginx:alpine" :class="input" class="font-mono" />
        <i18n-t keypath="websites.docker.imageHelp" tag="p" :class="hint"><template #a><code>nginx:alpine</code></template><template #b><code>ghcr.io/owner/app:1.2</code></template></i18n-t>
    </div>
    <div>
        <label for="docker-container-port" :class="label">{{ t('websites.docker.containerPort') }}</label>
        <input id="docker-container-port" v-model.number="model.container_port" type="number" min="1" max="65535" required placeholder="80" :class="input" />
        <p :class="hint">{{ t('websites.docker.containerPortHelp') }}</p>
    </div>
    <div>
        <label for="docker-mount-target" :class="label">{{ t('websites.docker.mount') }}</label>
        <input id="docker-mount-target" v-model="model.mount_target" type="text" placeholder="/usr/share/nginx/html" :class="input" class="font-mono" />
        <i18n-t keypath="websites.docker.mountHelp" tag="p" :class="hint"><template #path><code>{{ rootPath || `${baseDir}/…` }}</code></template></i18n-t>
    </div>
    <div>
        <div class="flex items-center justify-between">
            <span :class="label">{{ t('websites.docker.env') }}</span>
            <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="addEnv">{{ t('websites.docker.addVar') }}</button>
        </div>
        <div v-for="(env, i) in model.env" :key="i" class="mt-2 grid grid-cols-[1fr_1fr_auto] items-center gap-2">
            <input v-model="env.key" type="text" placeholder="NAME" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 font-mono text-xs dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
            <input v-model="env.value" type="text" :placeholder="t('websites.docker.value')" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
            <button type="button" class="rounded-md px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10" :aria-label="t('websites.docker.removeVar')" @click="model.env.splice(i, 1)">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <p :class="hint">{{ t('websites.docker.envHelp') }}</p>
    </div>
    <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-200">
        <input v-model="model.public" type="checkbox" class="mt-0.5" />
        <span>
            {{ t('websites.docker.public') }}
            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('websites.docker.publicHelp') }}</span>
        </span>
    </label>
    </template>
</template>
