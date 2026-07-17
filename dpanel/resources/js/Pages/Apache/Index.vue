<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    apache: { type: Object, default: () => ({}) },
    nginx: { type: Object, default: () => ({}) },
    websites: { type: Array, default: () => [] },
    selectedWebsiteId: { type: [String, Number], default: null },
    selectedWebsite: { type: Object, default: () => null },
    vhostPreview: {
        type: Object,
        default: () => ({
            apache: { path: '', exists: false, source: '', content: '' },
            nginx: { path: '', exists: false, source: '', content: '' },
        }),
    },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const csrfToken = computed(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const syncMessage = ref('');
const syncMessageType = ref('success');
const syncLoading = ref(false);

const actionForm = useForm({
    action: 'test',
});

const syncForm = useForm({});

const runAction = (action) => {
    actionForm.action = action;
    actionForm.post(panelRoute('apache.action'));
};

const syncSharedWebsites = () => {
    syncForm.post(panelRoute('apache.sync-shared-websites'));
};

const syncSelectedWebsiteVhost = async () => {
    if (!selectedWebsite?.id || syncLoading.value) {
        return;
    }

    syncMessage.value = '';
    syncLoading.value = true;

    try {
        const response = await fetch(panelRoute('websites.vhost.sync', { id: selectedWebsite.id }), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken.value,
            },
            body: JSON.stringify({}),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw data;
        }

        syncMessageType.value = String(data.type || 'success');
        syncMessage.value = String(data.message || 'Live vhost synced successfully.');
    } catch (error) {
        syncMessageType.value = 'error';
        syncMessage.value = String(error?.message || error?.errors?.vhost_sync || 'Live vhost sync failed.');
    } finally {
        syncLoading.value = false;
    }
};

const selectedWebsiteIdInput = ref(String(props.selectedWebsiteId ?? props.selectedWebsite?.id ?? ''));
watch(
    () => props.selectedWebsiteId,
    (value) => {
        selectedWebsiteIdInput.value = String(value ?? props.selectedWebsite?.id ?? '');
    },
);

const loadWebsitePreview = (websiteId) => {
    const normalizedId = String(websiteId ?? '').trim();
    selectedWebsiteIdInput.value = normalizedId;

    router.get(
        panelRoute('apache.index'),
        normalizedId ? { website_id: normalizedId } : {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const viewSelectedWebsitePreview = () => {
    loadWebsitePreview(selectedWebsiteIdInput.value);
};

const websitesCount = computed(() => props.websites.length);
const activeWebsitesCount = computed(() =>
    props.websites.filter((item) => String(item.status || '').toLowerCase() !== 'disabled').length,
);

const formatStatus = (value) => {
    const text = String(value || 'unknown').toLowerCase();
    if (!text) return 'Unknown';
    return text.charAt(0).toUpperCase() + text.slice(1);
};

const statusTone = (value) => {
    const text = String(value || '').toLowerCase();
    if (text === 'running' || text === 'active') {
        return 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300';
    }
    if (text === 'stopped' || text === 'inactive' || text === 'failed') {
        return 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300';
    }

    return 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200';
};

const boolTone = (flag) => (flag
    ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
    : 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300');
</script>

<template>
    <Head title="Apache + Nginx Setup" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Apache + Nginx Setup</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Service controls, config validation, and shared website vhost generation for both web servers.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 whitespace-pre-line">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 whitespace-pre-line">
                {{ page.props.flash.error }}
            </div>
            <div v-if="syncMessage" :class="syncMessageType === 'success'
                ? 'rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 whitespace-pre-line'
                : 'rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 whitespace-pre-line'">
                {{ syncMessage }}
            </div>

            <section class="relative overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-cyan-50 via-white to-indigo-50 p-5 dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-800">
                <div class="pointer-events-none absolute -right-8 -top-10 h-28 w-28 rounded-full bg-cyan-300/20 blur-xl dark:bg-cyan-900/30" />
                <div class="pointer-events-none absolute -left-8 bottom-0 h-24 w-24 rounded-full bg-blue-300/20 blur-xl dark:bg-blue-900/30" />

                <div class="relative grid gap-4 lg:grid-cols-[1.6fr_1fr]">
                    <div>
                        <h2 class="text-base font-semibold">Web Server Stack Overview</h2>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Manage Apache actions, monitor Nginx setup details, and sync shared vhost configuration from one place.</p>
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <span class="rounded-full border px-3 py-1 text-xs font-medium" :class="statusTone(apache.service_status)">
                                Apache: {{ formatStatus(apache.service_status) }}
                            </span>
                            <span class="rounded-full border px-3 py-1 text-xs font-medium" :class="statusTone(nginx.service_status)">
                                Nginx: {{ formatStatus(nginx.service_status) }}
                            </span>
                            <span class="rounded-full border px-3 py-1 text-xs font-medium" :class="boolTone(apache.shared_vhost_exists)">
                                Shared VHost: {{ apache.shared_vhost_exists ? 'Present' : 'Missing' }}
                            </span>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                        <div class="rounded-xl border border-slate-200 bg-white/80 p-3 backdrop-blur dark:border-slate-700 dark:bg-slate-900/70">
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Total Websites</p>
                            <p class="mt-1 text-2xl font-semibold">{{ websitesCount }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white/80 p-3 backdrop-blur dark:border-slate-700 dark:bg-slate-900/70">
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Active Websites</p>
                            <p class="mt-1 text-2xl font-semibold">{{ activeWebsitesCount }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Apache Runtime</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-xs text-slate-500 dark:text-slate-400">OS</p>
                            <p class="mt-1 text-lg font-semibold">{{ apache.os_family || '-' }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Service</p>
                            <p class="mt-1 text-lg font-semibold">{{ apache.service_name || '-' }}</p>
                        </div>
                    </div>
                    <p class="mt-3 break-all text-xs text-slate-500">Apache binary: {{ apache.httpd_path || '-' }}</p>
                    <p class="mt-1 break-all text-xs text-slate-500">Main config: {{ apache.main_conf || '-' }}</p>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Nginx Runtime</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-xs text-slate-500 dark:text-slate-400">OS</p>
                            <p class="mt-1 text-lg font-semibold">{{ nginx.os_family || '-' }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Service</p>
                            <p class="mt-1 text-lg font-semibold">{{ nginx.service_name || '-' }}</p>
                        </div>
                    </div>
                    <p class="mt-3 break-all text-xs text-slate-500">Nginx binary: {{ nginx.binary_path || '-' }}</p>
                    <p class="mt-1 break-all text-xs text-slate-500">Main config: {{ nginx.main_conf || '-' }}</p>
                    <p class="mt-1 break-all text-xs text-slate-500">Sites available: {{ nginx.sites_available_path || '-' }}</p>
                    <p class="mt-1 break-all text-xs text-slate-500">Sites enabled: {{ nginx.sites_enabled_path || '-' }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ nginx.include_hint || '-' }}</p>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Apache Actions</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700" :disabled="actionForm.processing" @click="runAction('test')">Test Config</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="actionForm.processing" @click="runAction('reload')">Reload</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="actionForm.processing" @click="runAction('restart')">Restart</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="actionForm.processing" @click="runAction('start')">Start</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="actionForm.processing" @click="runAction('stop')">Stop</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="actionForm.processing" @click="runAction('status')">Status</button>
                    <button type="button" class="rounded-md border border-amber-300 px-3 py-2 text-sm text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-400" :disabled="actionForm.processing" @click="runAction('renew_ssl')">Renew SSL</button>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Shared Websites Config</h2>
                <p class="mt-2 break-all text-xs text-slate-500">Target file: {{ apache.shared_vhost_file || '-' }}</p>
                <p class="mt-1 text-xs text-slate-500">Last generated: {{ apache.shared_vhost_last_modified || '-' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ apache.include_hint }}</p>
                <div class="mt-3">
                    <button type="button" class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700" :disabled="syncForm.processing" @click="syncSharedWebsites">
                        {{ syncForm.processing ? 'Syncing...' : 'Sync Shared Websites' }}
                    </button>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">VHost Config Render</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <select
                            v-model="selectedWebsiteIdInput"
                            class="min-w-[16rem] rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                        >
                            <option value="" disabled>Select website for preview</option>
                            <option v-for="site in websites" :key="site.id" :value="String(site.id)">
                                {{ site.domain }} (PHP {{ site.php_version || '-' }})
                            </option>
                        </select>
                        <button
                            type="button"
                            class="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="!selectedWebsiteIdInput"
                            @click="viewSelectedWebsitePreview"
                        >
                            Load Config
                        </button>
                        <Link
                            v-if="selectedWebsite?.id"
                            :href="panelRoute('websites.manage', { id: selectedWebsite.id })"
                            class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                        >
                            Open Website Manage
                        </Link>
                        <button
                            v-if="selectedWebsite?.id"
                            type="button"
                            :disabled="syncLoading"
                            class="rounded-md border border-violet-300 px-3 py-2 text-sm text-violet-700 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-violet-700 dark:text-violet-300 dark:hover:bg-violet-900/20"
                            @click="syncSelectedWebsiteVhost"
                        >
                            Sync Selected VHost
                        </button>
                    </div>
                </div>
                <p class="mt-2 text-xs text-slate-500">
                    Selected website: <span class="font-medium">{{ selectedWebsite?.domain || '-' }}</span>
                </p>

                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 dark:border-slate-700">
                        <div class="border-b border-slate-200 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:text-slate-300">
                            <div class="flex items-center justify-between gap-2">
                                <p class="font-semibold">Apache</p>
                                <span
                                    class="rounded-full border px-2 py-0.5"
                                    :class="vhostPreview.apache.exists
                                        ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                                        : 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300'"
                                >
                                    {{ vhostPreview.apache.exists ? 'Config Found' : 'Preview Generated' }}
                                </span>
                            </div>
                            <p class="mt-1 break-all">{{ vhostPreview.apache.path || '-' }}</p>
                            <p>{{ vhostPreview.apache.source || '-' }}</p>
                        </div>
                        <pre class="max-h-80 overflow-auto bg-slate-950 p-3 text-xs text-slate-100"><code>{{ vhostPreview.apache.content || '# Apache config is not available yet.' }}</code></pre>
                    </div>
                    <div class="rounded-lg border border-slate-200 dark:border-slate-700">
                        <div class="border-b border-slate-200 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:text-slate-300">
                            <div class="flex items-center justify-between gap-2">
                                <p class="font-semibold">Nginx</p>
                                <span
                                    class="rounded-full border px-2 py-0.5"
                                    :class="vhostPreview.nginx.exists
                                        ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                                        : 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300'"
                                >
                                    {{ vhostPreview.nginx.exists ? 'Config Found' : 'Preview Generated' }}
                                </span>
                            </div>
                            <p class="mt-1 break-all">{{ vhostPreview.nginx.path || '-' }}</p>
                            <p>{{ vhostPreview.nginx.source || '-' }}</p>
                        </div>
                        <pre class="max-h-80 overflow-auto bg-slate-950 p-3 text-xs text-slate-100"><code>{{ vhostPreview.nginx.content || '# Nginx config is not available yet.' }}</code></pre>
                    </div>
                </div>
            </section>

            <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Domain</th>
                            <th class="px-4 py-3">Root Path</th>
                            <th class="px-4 py-3">PHP</th>
                            <th class="px-4 py-3">SSL</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Config</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="site in websites" :key="site.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">{{ site.domain }}</td>
                            <td class="px-4 py-3 break-all font-mono text-xs">{{ site.root_path }}</td>
                            <td class="px-4 py-3">{{ site.php_version || '-' }}</td>
                            <td class="px-4 py-3">{{ site.enable_ssl ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-3">{{ site.status }}</td>
                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    class="rounded-md border border-blue-300 px-2 py-1 text-xs text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20"
                                    @click="loadWebsitePreview(site.id)"
                                >
                                    View Config
                                </button>
                            </td>
                        </tr>
                        <tr v-if="websites.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-slate-500">No websites found for shared vhost generation.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
