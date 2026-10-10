<script setup>
import Offcanvas from '@/Components/Offcanvas.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import InputError from '@/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    share: { type: Object, default: null },
    websiteId: { type: String, default: '' },
    websites: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    listening: { type: Array, default: () => [] },
    panelRoute: { type: Function, required: true },
});
const emit = defineEmits(['close']);

const blank = () => ({
    website_id: props.websiteId,
    path_prefix: '/',
    source: props.projects.length ? 'project' : 'port',
    app_project_id: props.projects[0]?.id ?? '',
    target_port: '',
    strip_prefix: false,
});
const form = useForm(blank());
const editing = computed(() => !!props.share);

watch(() => props.show, (open) => {
    if (!open) return;
    form.clearErrors();
    if (!props.share) {
        Object.assign(form, blank());
        return;
    }
    const s = props.share;
    Object.assign(form, {
        website_id: s.website_id,
        path_prefix: s.path_prefix,
        source: s.app_project_id ? 'project' : 'port',
        app_project_id: s.app_project_id ?? (props.projects[0]?.id ?? ''),
        target_port: s.target_port,
        strip_prefix: s.strip_prefix,
    });
});

const websiteOptions = computed(() => props.websites.map((w) => ({ value: w.id, label: w.domain })));
const website = computed(() => props.websites.find((w) => w.id === form.website_id));
const project = computed(() => props.projects.find((p) => p.id === form.app_project_id));
const port = computed(() => (form.source === 'project' ? project.value?.port : form.target_port));
const publicUrl = computed(() => {
    if (!website.value) return 'https://your-site.com/';
    const path = '/' + String(form.path_prefix || '').replace(/^\/+|\/+$/g, '');
    return `${website.value.enable_ssl ? 'https' : 'http'}://${website.value.domain}${path === '/' ? '/' : path}`;
});
const upstreamPath = computed(() => {
    const path = '/' + String(form.path_prefix || '').replace(/^\/+|\/+$/g, '');
    return form.strip_prefix || path === '/' ? '/' : `${path}`;
});

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    if (editing.value) {
        form.put(props.panelRoute('port-shares.update', { share: props.share.id }), options);
    } else {
        form.post(props.panelRoute('port-shares.store'), options);
    }
};
</script>

<template>
    <Offcanvas
        :show="show"
        width="lg"
        :title="editing ? 'Edit port share' : 'Share a local port'"
        subtitle="The edge gateway reverse-proxies the website path to 127.0.0.1:port."
        @close="emit('close')"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm font-medium">Website</label>
                <SearchableSelect
                    v-model="form.website_id"
                    :options="websiteOptions"
                    :disabled="editing"
                    placeholder="Select a website…"
                    search-placeholder="Search domains…"
                />
                <InputError :message="form.errors.website_id" class="mt-1" />
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Path</label>
                <input v-model="form.path_prefix" type="text" placeholder="/ or /api" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><code>/</code> sends the whole website to the port. A path like <code>/api</code> sends only that part; the rest stays on PHP.</p>
                <InputError :message="form.errors.path_prefix" class="mt-1" />
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Share</label>
                <div class="mb-2 grid grid-cols-2 gap-2">
                    <button
                        v-for="option in [{ value: 'project', label: 'A project', icon: 'bi bi-boxes' }, { value: 'port', label: 'Any local port', icon: 'bi bi-ethernet' }]"
                        :key="option.value"
                        type="button"
                        @click="form.source = option.value"
                        class="flex items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium"
                        :class="form.source === option.value ? 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : 'border-slate-300 dark:border-slate-600'"
                    ><i :class="option.icon"></i>{{ option.label }}</button>
                </div>

                <template v-if="form.source === 'project'">
                    <select v-if="projects.length" v-model="form.app_project_id" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }} — {{ p.runtime === 'node' ? 'Node.js' : 'Python' }} :{{ p.port }}{{ p.status === 'running' ? '' : ' (stopped)' }}</option>
                    </select>
                    <p v-else class="text-sm text-slate-500 dark:text-slate-400">No projects yet. Create one under Node &amp; Python Projects, or share a port directly.</p>
                    <InputError :message="form.errors.app_project_id" class="mt-1" />
                </template>
                <template v-else>
                    <input v-model.number="form.target_port" type="number" min="1" max="65535" list="listening-ports" placeholder="3000" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <datalist id="listening-ports">
                        <option v-for="row in listening" :key="row.port" :value="row.port">{{ row.owner }}</option>
                    </datalist>
                    <InputError :message="form.errors.target_port" class="mt-1" />
                    <div v-if="listening.length" class="mt-2 flex flex-wrap gap-1">
                        <span class="text-xs text-slate-500 dark:text-slate-400">Listening now:</span>
                        <button
                            v-for="row in listening.slice(0, 24)"
                            :key="row.port"
                            type="button"
                            @click="form.target_port = row.port"
                            :title="row.owner"
                            class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs hover:bg-blue-100 dark:bg-slate-700 dark:hover:bg-blue-900/40"
                            :class="{ 'ring-1 ring-blue-500': form.target_port === row.port }"
                        >{{ row.port }}</button>
                    </div>
                </template>
            </div>

            <label v-if="form.path_prefix && form.path_prefix.replace(/\//g, '') !== ''" class="flex items-start gap-2 text-sm">
                <input v-model="form.strip_prefix" type="checkbox" class="mt-0.5 rounded border-slate-300" />
                <span>Strip the path before forwarding<span class="block text-xs text-slate-500 dark:text-slate-400">On for apps that expect to live at <code>/</code>; off when the app already serves under this path.</span></span>
            </label>

            <div class="rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-800">
                <p class="mb-1 font-medium text-slate-600 dark:text-slate-300">Preview</p>
                <p class="flex flex-wrap items-center gap-1 font-mono text-slate-500 dark:text-slate-400">
                    <span>{{ publicUrl }}…</span>
                    <i class="bi bi-arrow-right"></i>
                    <span>127.0.0.1:{{ port || '…' }}{{ upstreamPath }}…</span>
                </p>
                <p class="mt-2 text-slate-500 dark:text-slate-400">SSL, IP rules, and redirects of the website still apply. Certificate checks under /.well-known/acme-challenge/ are never forwarded.</p>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-slate-700">
                <button type="button" @click="emit('close')" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600">Cancel</button>
                <button type="submit" :disabled="form.processing || !form.website_id || !port" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ editing ? 'Save' : 'Share' }}
                </button>
            </div>
        </form>
    </Offcanvas>
</template>
