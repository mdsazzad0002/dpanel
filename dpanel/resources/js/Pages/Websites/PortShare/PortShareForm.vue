<script setup>
import { computed, reactive, watch } from 'vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

/**
 * One share's settings: a website path and what answers it — a Node.js app,
 * a Python app, or any local port. Common to every source type.
 */
const props = defineProps({
    share: { type: Object, default: null },
    website: { type: Object, required: true },
    projects: { type: Array, default: () => [] },
    listening: { type: Array, default: () => [] },
    saving: { type: Boolean, default: false },
    error: { type: String, default: '' },
});
const emit = defineEmits(['submit', 'cancel']);
const { panelRoute } = usePanelApi();

const sources = [
    { value: 'node', label: 'Node.js app', icon: 'bi bi-hexagon' },
    { value: 'python', label: 'Python app', icon: 'bi bi-filetype-py' },
    { value: 'port', label: 'Any local port', icon: 'bi bi-ethernet' },
];

const form = reactive({ path_prefix: '/', source: 'node', app_project_id: '', target_port: '', strip_prefix: false });
const appsOf = (source) => props.projects.filter((p) => p.runtime === source);

watch(() => props.share, (share) => {
    if (share) {
        const project = props.projects.find((p) => p.id === share.app_project_id);
        Object.assign(form, {
            path_prefix: share.path_prefix,
            source: project ? project.runtime : 'port',
            app_project_id: share.app_project_id ?? '',
            target_port: share.target_port,
            strip_prefix: share.strip_prefix,
        });
        return;
    }
    const source = appsOf('node').length ? 'node' : (appsOf('python').length ? 'python' : 'port');
    Object.assign(form, { path_prefix: '/', source, app_project_id: appsOf(source)[0]?.id ?? '', target_port: '', strip_prefix: false });
}, { immediate: true });

const pickSource = (source) => {
    form.source = source;
    if (source !== 'port' && !appsOf(source).some((p) => p.id === form.app_project_id)) {
        form.app_project_id = appsOf(source)[0]?.id ?? '';
    }
};

const cleanPath = computed(() => '/' + String(form.path_prefix || '').replace(/^\/+|\/+$/g, ''));
const port = computed(() => (form.source === 'port' ? form.target_port : appsOf(form.source).find((p) => p.id === form.app_project_id)?.port));
const publicUrl = computed(() => `${props.website.enable_ssl ? 'https' : 'http'}://${props.website.domain}${cleanPath.value}`);
const upstream = computed(() => `127.0.0.1:${port.value || '…'}${form.strip_prefix || cleanPath.value === '/' ? '/' : cleanPath.value}`);
const appsPage = computed(() => (form.source === 'python' ? 'apps.python.index' : 'apps.node.index'));

const submit = () => emit('submit', {
    path_prefix: form.path_prefix,
    source: form.source,
    app_project_id: form.source === 'port' ? null : form.app_project_id,
    target_port: form.source === 'port' ? form.target_port : null,
    strip_prefix: cleanPath.value !== '/' && form.strip_prefix,
});
</script>

<template>
    <form class="space-y-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-900/40" @submit.prevent="submit">
        <div>
            <label class="mb-1 block text-sm font-medium">Path</label>
            <input v-model="form.path_prefix" type="text" placeholder="/ or /api" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><code>/</code> hands the whole website to the app. A path like <code>/api</code> sends only that part; the rest stays on {{ website.runtime === 'docker' ? 'Docker' : 'PHP' }}.</p>
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium">Served by</label>
            <div class="mb-2 grid grid-cols-3 gap-2">
                <button
                    v-for="option in sources"
                    :key="option.value"
                    type="button"
                    @click="pickSource(option.value)"
                    class="flex items-center justify-center gap-1.5 rounded-lg border px-2 py-2 text-xs font-medium sm:text-sm"
                    :class="form.source === option.value ? 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : 'border-slate-300 bg-white dark:border-slate-600 dark:bg-slate-800'"
                ><i :class="option.icon"></i>{{ option.label }}</button>
            </div>

            <template v-if="form.source !== 'port'">
                <select v-if="appsOf(form.source).length" v-model="form.app_project_id" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                    <option v-for="p in appsOf(form.source)" :key="p.id" :value="p.id">{{ p.name }} — port {{ p.port }}{{ p.status === 'running' ? '' : ' (stopped)' }}</option>
                </select>
                <p v-else class="text-sm text-slate-500 dark:text-slate-400">
                    No {{ form.source === 'node' ? 'Node.js' : 'Python' }} apps yet.
                    <a :href="panelRoute(appsPage)" class="text-blue-600 hover:underline dark:text-blue-400">Create one</a>, or share a local port.
                </p>
            </template>
            <template v-else>
                <input v-model.number="form.target_port" type="number" min="1" max="65535" placeholder="3000" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                <div v-if="listening.length" class="mt-2 flex flex-wrap items-center gap-1">
                    <span class="text-xs text-slate-500 dark:text-slate-400">Listening now:</span>
                    <button
                        v-for="row in listening.slice(0, 24)"
                        :key="row.port"
                        type="button"
                        @click="form.target_port = row.port"
                        :title="row.owner"
                        class="rounded bg-white px-1.5 py-0.5 font-mono text-xs hover:bg-blue-100 dark:bg-slate-700 dark:hover:bg-blue-900/40"
                        :class="{ 'ring-1 ring-blue-500': form.target_port === row.port }"
                    >{{ row.port }}</button>
                </div>
            </template>
        </div>

        <label v-if="cleanPath !== '/'" class="flex items-start gap-2 text-sm">
            <input v-model="form.strip_prefix" type="checkbox" class="mt-0.5 rounded border-slate-300" />
            <span>Strip the path before forwarding<span class="block text-xs text-slate-500 dark:text-slate-400">On for apps that expect to live at <code>/</code>.</span></span>
        </label>

        <p class="flex flex-wrap items-center gap-1 rounded-lg bg-white px-3 py-2 font-mono text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">
            <span>{{ publicUrl }}…</span><i class="bi bi-arrow-right"></i><span>{{ upstream }}…</span>
        </p>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div class="flex justify-end gap-2">
            <button type="button" @click="emit('cancel')" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-800">Cancel</button>
            <button type="submit" :disabled="saving || !port" class="rounded-md bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                <i v-if="saving" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>{{ share ? 'Save' : 'Share' }}
            </button>
        </div>
    </form>
</template>
