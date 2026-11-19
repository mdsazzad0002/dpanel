<script setup>
import Offcanvas from '@/Components/Offcanvas.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import InputError from '@/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

/**
 * Create/edit one share: a website path and what answers it — a Node.js
 * app, a Python app, or any local port.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    share: { type: Object, default: null },
    websiteId: { type: String, default: '' },
    websites: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    listening: { type: Array, default: () => [] },
    panelRoute: { type: Function, required: true },
    canRemote: { type: Boolean, default: false },
    lbPolicies: { type: Array, default: () => ['round_robin', 'ip_hash', 'failover'] },
});
const emit = defineEmits(['close']);

const sources = computed(() => [
    { value: 'node', label: t('rules.portShares.form.src.node'), icon: 'bi bi-hexagon' },
    { value: 'python', label: t('rules.portShares.form.src.python'), icon: 'bi bi-filetype-py' },
    { value: 'rust', label: t('rules.portShares.form.src.rust'), icon: 'bi bi-gear-wide-connected' },
    { value: 'port', label: t('rules.portShares.form.src.port'), icon: 'bi bi-ethernet' },
    ...(props.canRemote || props.share?.target_type === 'remote'
        ? [{ value: 'remote', label: t('rules.portShares.form.src.remote'), icon: 'bi bi-diagram-3' }]
        : []),
]);
const isAppSource = (source) => !['port', 'remote'].includes(source);
const appsOf = (source) => props.projects.filter((p) => p.runtime === source);

const remoteDefaults = () => ({ targets: [''], lb_policy: 'round_robin', preserve_host: true, verify_tls: true, timeout_seconds: 30 });
const form = useForm({ website_id: '', path_prefix: '/', source: 'node', app_project_id: '', target_port: '', strip_prefix: false, ...remoteDefaults() });
const editing = computed(() => !!props.share);

watch(() => props.show, (open) => {
    if (!open) return;
    form.clearErrors();
    // Not form.reset(): a successful submit moves the form's defaults.
    if (props.share) {
        const s = props.share;
        const project = props.projects.find((p) => p.id === s.app_project_id);
        const remote = s.target_type === 'remote';
        Object.assign(form, {
            website_id: s.website_id,
            path_prefix: s.path_prefix,
            source: remote ? 'remote' : (project ? project.runtime : 'port'),
            app_project_id: s.app_project_id ?? '',
            target_port: s.target_port ?? '',
            strip_prefix: s.strip_prefix,
            targets: remote && s.targets?.length ? [...s.targets] : [''],
            lb_policy: s.lb_policy || 'round_robin',
            preserve_host: s.preserve_host ?? true,
            verify_tls: s.verify_tls ?? true,
            timeout_seconds: s.timeout_seconds || 30,
        });
        return;
    }
    const source = appsOf('node').length ? 'node' : (appsOf('python').length ? 'python' : (appsOf('rust').length ? 'rust' : 'port'));
    Object.assign(form, { website_id: props.websiteId, path_prefix: '/', source, app_project_id: appsOf(source)[0]?.id ?? '', target_port: '', strip_prefix: false, ...remoteDefaults() });
});

const pickSource = (source) => {
    form.source = source;
    if (isAppSource(source) && !appsOf(source).some((p) => p.id === form.app_project_id)) {
        form.app_project_id = appsOf(source)[0]?.id ?? '';
    }
};

const websiteOptions = computed(() => props.websites.map((w) => ({ value: w.id, label: w.domain })));
const website = computed(() => props.websites.find((w) => w.id === form.website_id));
const cleanPath = computed(() => '/' + String(form.path_prefix || '').replace(/^\/+|\/+$/g, ''));
const port = computed(() => (form.source === 'port' ? form.target_port : appsOf(form.source).find((p) => p.id === form.app_project_id)?.port));
const publicUrl = computed(() => (website.value
    ? `${website.value.enable_ssl ? 'https' : 'http'}://${website.value.domain}${cleanPath.value}`
    : `https://your-site.com${cleanPath.value}`));
const filledTargets = computed(() => form.targets.map((x) => String(x || '').trim()).filter(Boolean));
const upstream = computed(() => {
    const path = form.strip_prefix || cleanPath.value === '/' ? '/' : cleanPath.value;
    if (form.source === 'remote') {
        const first = filledTargets.value[0] || 'http://192.168.0.10:80';
        const more = filledTargets.value.length > 1 ? ` (+${filledTargets.value.length - 1})` : '';
        return `${first.replace(/\/+$/, '')}${path}${more}`;
    }
    return `127.0.0.1:${port.value || '…'}${path}`;
});
const canSubmit = computed(() => !!form.website_id && (form.source === 'remote' ? filledTargets.value.length > 0 : !!port.value));
const addTarget = () => { if (form.targets.length < 16) form.targets.push(''); };
const removeTarget = (index) => { form.targets.splice(index, 1); if (!form.targets.length) form.targets.push(''); };
const targetError = computed(() => form.errors.targets || Object.entries(form.errors).find(([key]) => key.startsWith('targets.'))?.[1]);
const baseLabel = computed(() => (website.value?.runtime === 'docker' ? 'Docker' : 'PHP'));
const appsPage = computed(() => ({ python: 'apps.python.index', rust: 'apps.rust.index' }[form.source] || 'apps.node.index'));
const sourceLabel = computed(() => ({ node: 'Node.js', python: 'Python', rust: 'Rust' }[form.source] || ''));

const submit = () => {
    form.transform((data) => ({
        ...data,
        app_project_id: isAppSource(data.source) ? data.app_project_id : null,
        target_port: data.source === 'port' ? data.target_port : null,
        strip_prefix: cleanPath.value !== '/' && data.strip_prefix,
        targets: data.source === 'remote' ? filledTargets.value : null,
    }));
    const options = { preserveScroll: true, onSuccess: () => emit('close') };
    if (editing.value) {
        form.put(props.panelRoute('rules.port-shares.update', { share: props.share.id }), options);
    } else {
        form.post(props.panelRoute('rules.port-shares.store'), options);
    }
};
</script>

<template>
    <Offcanvas
        :show="show"
        width="lg"
        :title="editing ? t('rules.portShares.form.editTitle') : t('rules.portShares.create')"
        :subtitle="t('rules.portShares.form.subtitle')"
        @close="emit('close')"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ t('rules.portShares.form.website') }}</label>
                <SearchableSelect
                    v-model="form.website_id"
                    :options="websiteOptions"
                    :disabled="editing"
                    :placeholder="t('rules.portShares.form.selectWebsite')"
                    :search-placeholder="t('rules.portShares.form.searchDomains')"
                />
                <InputError :message="form.errors.website_id" class="mt-1" />
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">{{ t('rules.portShares.form.path') }}</label>
                <input v-model="form.path_prefix" type="text" :placeholder="t('rules.portShares.form.pathPh')" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><i18n-t keypath="rules.portShares.form.pathHelp" scope="global"><template #root><code>/</code></template><template #example><code>/api</code></template><template #base>{{ baseLabel }}</template></i18n-t></p>
                <InputError :message="form.errors.path_prefix" class="mt-1" />
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">{{ t('rules.portShares.form.servedBy') }}</label>
                <div class="mb-2 grid grid-cols-3 gap-2">
                    <button
                        v-for="option in sources"
                        :key="option.value"
                        type="button"
                        @click="pickSource(option.value)"
                        class="flex items-center justify-center gap-1.5 rounded-lg border px-2 py-2 text-xs font-medium sm:text-sm"
                        :class="form.source === option.value ? 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : 'border-slate-300 dark:border-slate-600'"
                    ><i :class="option.icon"></i>{{ option.label }}</button>
                </div>

                <template v-if="form.source === 'remote'">
                    <div class="space-y-2">
                        <div v-for="(target, index) in form.targets" :key="index" class="flex items-center gap-2">
                            <input
                                v-model="form.targets[index]"
                                type="text"
                                :placeholder="index === 0 ? 'http://192.168.0.106:80' : 'http://192.168.0.107:80'"
                                class="min-w-0 flex-1 rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900"
                            />
                            <button type="button" @click="removeTarget(index)" :title="t('rules.portShares.form.removeBackend')" class="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <button type="button" @click="addTarget" :disabled="form.targets.length >= 16" class="text-sm font-medium text-blue-600 hover:underline disabled:opacity-50 dark:text-blue-400"><i class="bi bi-plus-lg"></i> {{ t('rules.portShares.form.addBackend') }}</button>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('rules.portShares.form.backendsHelp') }}</p>
                        <InputError :message="targetError" class="mt-1" />
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">{{ t('rules.portShares.form.policy') }}</label>
                            <select v-model="form.lb_policy" :disabled="filledTargets.length < 2" class="w-full rounded-lg border-slate-300 text-sm disabled:opacity-60 dark:border-slate-600 dark:bg-slate-900">
                                <option v-for="policy in lbPolicies" :key="policy" :value="policy">{{ t(`rules.portShares.form.policies.${policy}`) }}</option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t(`rules.portShares.form.policyHelp.${form.lb_policy}`) }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">{{ t('rules.portShares.form.timeout') }}</label>
                            <input v-model.number="form.timeout_seconds" type="number" min="5" max="300" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                            <InputError :message="form.errors.timeout_seconds" class="mt-1" />
                        </div>
                    </div>

                    <label class="mt-3 flex items-start gap-2 text-sm">
                        <input v-model="form.preserve_host" type="checkbox" class="mt-0.5 rounded border-slate-300" />
                        <span>{{ t('rules.portShares.form.preserveHost') }}<span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('rules.portShares.form.preserveHostHelp') }}</span></span>
                    </label>
                    <label class="mt-2 flex items-start gap-2 text-sm">
                        <input v-model="form.verify_tls" type="checkbox" class="mt-0.5 rounded border-slate-300" />
                        <span>{{ t('rules.portShares.form.verifyTls') }}<span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('rules.portShares.form.verifyTlsHelp') }}</span></span>
                    </label>
                </template>
                <template v-else-if="form.source !== 'port'">
                    <select v-if="appsOf(form.source).length" v-model="form.app_project_id" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                        <option v-for="p in appsOf(form.source)" :key="p.id" :value="p.id">{{ p.status === 'running' ? t('rules.portShares.form.appOption', { name: p.name, port: p.port }) : t('rules.portShares.form.appOptionStopped', { name: p.name, port: p.port }) }}</option>
                    </select>
                    <p v-else class="text-sm text-slate-500 dark:text-slate-400">
                        <i18n-t keypath="rules.portShares.form.noApps" scope="global"><template #runtime>{{ sourceLabel }}</template><template #link><a :href="panelRoute(appsPage)" class="text-blue-600 hover:underline dark:text-blue-400">{{ t('rules.portShares.form.createOne') }}</a></template></i18n-t>
                    </p>
                    <InputError :message="form.errors.app_project_id" class="mt-1" />
                </template>
                <template v-else>
                    <input v-model.number="form.target_port" type="number" min="1" max="65535" placeholder="3000" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <InputError :message="form.errors.target_port" class="mt-1" />
                    <div v-if="listening.length" class="mt-2 flex flex-wrap items-center gap-1">
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ t('rules.portShares.form.listening') }}</span>
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

            <label v-if="cleanPath !== '/'" class="flex items-start gap-2 text-sm">
                <input v-model="form.strip_prefix" type="checkbox" class="mt-0.5 rounded border-slate-300" />
                <span>{{ t('rules.portShares.form.strip') }}<span class="block text-xs text-slate-500 dark:text-slate-400"><i18n-t keypath="rules.portShares.form.stripHelp" scope="global"><template #root><code>/</code></template></i18n-t></span></span>
            </label>

            <div class="rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-800">
                <p class="mb-1 font-medium text-slate-600 dark:text-slate-300">{{ t('rules.portShares.form.preview') }}</p>
                <p class="flex flex-wrap items-center gap-1 font-mono text-slate-500 dark:text-slate-400">
                    <span>{{ publicUrl }}…</span><i class="bi bi-arrow-right"></i><span>{{ upstream }}…</span>
                </p>
                <p class="mt-2 text-slate-500 dark:text-slate-400">{{ t('rules.portShares.form.previewNote') }}</p>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 pt-4 dark:border-slate-700">
                <button type="button" @click="emit('close')" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-600">{{ t('common.cancel') }}</button>
                <button type="submit" :disabled="form.processing || !canSubmit" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ editing ? t('common.save') : t('rules.portShares.form.deploy') }}
                </button>
            </div>
        </form>
    </Offcanvas>
</template>
