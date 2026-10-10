<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import PortShareForm from './PortShareForm.vue';

/** Manage page section: what serves this website — PHP/Docker, an app, or a port. */
const props = defineProps({
    website: { type: Object, required: true },
});

const pushToast = inject('pushToast', () => {});
const { panelRoute, requestJson } = usePanelApi();

const data = ref(null);
const loading = ref(true);
const saving = ref(false);
const formError = ref('');
const editing = ref(null); // null = closed, 'new', or a share row

const shares = computed(() => data.value?.shares ?? []);
const rootShare = computed(() => shares.value.find((s) => s.path_prefix === '/' && s.enabled));
const baseLabel = computed(() => (String(props.website.runtime || 'php') === 'docker' ? 'Docker container' : 'PHP'));
const projectOf = (share) => data.value?.projects.find((p) => p.id === share.app_project_id);
const targetLabel = (share) => {
    const project = projectOf(share);
    return project ? `${project.runtime === 'node' ? 'Node.js' : 'Python'} app ${project.name}` : 'Local port';
};
const publicUrl = (share) => `${props.website.enable_ssl ? 'https' : 'http'}://${props.website.domain}${share.path_prefix === '/' ? '/' : share.path_prefix}`;

const load = async () => {
    loading.value = true;
    try {
        data.value = (await requestJson(panelRoute('websites.port-shares.index', { id: props.website.id }), { method: 'GET' })).data;
    } catch (error) {
        pushToast(error.message || 'Could not load port shares.');
    } finally {
        loading.value = false;
    }
};
onMounted(load);

const run = async (url, method, body = {}) => {
    const result = await requestJson(url, { method, body });
    data.value = result.data;
    pushToast(result.message, result.live ? 'success' : 'error');
};

const save = async (payload) => {
    saving.value = true;
    formError.value = '';
    try {
        if (editing.value === 'new') {
            await run(panelRoute('websites.port-shares.store', { id: props.website.id }), 'POST', payload);
        } else {
            await run(panelRoute('port-shares.update', { share: editing.value.id }), 'PUT', payload);
        }
        editing.value = null;
    } catch (error) {
        formError.value = error.message || 'Could not save.';
    } finally {
        saving.value = false;
    }
};

const toggle = (share) => run(panelRoute('port-shares.toggle', { share: share.id }), 'PATCH').catch((e) => pushToast(e.message));
const remove = (share) => {
    if (!confirm(`Stop sharing ${publicUrl(share)}?`)) return;
    run(panelRoute('port-shares.destroy', { share: share.id }), 'DELETE').catch((e) => pushToast(e.message));
};
const open = (share) => { formError.value = ''; editing.value = share; };
</script>

<template>
    <section class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 font-semibold"><i class="bi bi-ethernet text-blue-600"></i>Port Share</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Serve this domain, or a path of it, from a Node.js app, a Python app, or any local port.
                </p>
            </div>
            <button v-if="editing === null" @click="open('new')" :disabled="loading" class="rounded-md bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                <i class="bi bi-plus-lg mr-1"></i>Share
            </button>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
            <span class="text-slate-500 dark:text-slate-400">Domain root served by</span>
            <span class="rounded-full px-2 py-0.5 font-medium" :class="rootShare ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' : 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200'">
                {{ rootShare ? `${targetLabel(rootShare)} (port ${rootShare.target_port})` : baseLabel }}
            </span>
        </div>

        <PortShareForm
            v-if="editing !== null && data"
            class="mt-4"
            :share="editing === 'new' ? null : editing"
            :website="data.website"
            :projects="data.projects"
            :listening="data.listening"
            :saving="saving"
            :error="formError"
            @submit="save"
            @cancel="editing = null"
        />

        <p v-if="loading" class="mt-4 text-sm text-slate-500"><i class="bi bi-arrow-repeat inline-block animate-spin"></i> Loading…</p>
        <ul v-else-if="shares.length" class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-700 dark:border-slate-700">
            <li v-for="share in shares" :key="share.id" class="flex items-start gap-3 px-4 py-3" :class="{ 'opacity-60': !share.enabled }">
                <i class="bi mt-0.5 text-slate-400" :class="projectOf(share)?.runtime === 'python' ? 'bi-filetype-py' : (projectOf(share) ? 'bi-hexagon' : 'bi-ethernet')"></i>
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-1 font-mono text-xs">
                        <a :href="publicUrl(share)" target="_blank" rel="noopener" class="truncate text-blue-600 hover:underline dark:text-blue-400">{{ publicUrl(share) }}</a>
                        <i class="bi bi-arrow-right text-slate-400"></i>
                        <span class="text-slate-500 dark:text-slate-400">127.0.0.1:{{ share.target_port }}</span>
                    </p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ targetLabel(share) }}<template v-if="projectOf(share)?.status === 'stopped'"> · <span class="text-amber-600">app stopped</span></template><template v-if="share.strip_prefix"> · path stripped</template>
                    </p>
                </div>
                <button
                    @click="toggle(share)"
                    role="switch"
                    :aria-checked="share.enabled"
                    :title="share.enabled ? 'Disable' : 'Enable'"
                    class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 rounded-full transition"
                    :class="share.enabled ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'"
                >
                    <span class="absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition" :class="share.enabled ? 'left-[18px]' : 'left-0.5'"></span>
                </button>
                <button @click="open(share)" title="Edit" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-700 dark:hover:text-slate-200"><i class="bi bi-pencil"></i></button>
                <button @click="remove(share)" title="Delete" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"><i class="bi bi-trash"></i></button>
            </li>
        </ul>
        <p v-else-if="editing === null" class="mt-4 text-sm text-slate-500 dark:text-slate-400">Nothing shared. The whole site is served by {{ baseLabel }}.</p>
    </section>
</template>
