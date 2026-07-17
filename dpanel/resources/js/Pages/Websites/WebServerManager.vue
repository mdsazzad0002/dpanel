<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    vhostPreview: {
        type: Object,
        default: () => ({
            apache: { path: '', exists: false, source: '', content: '' },
            nginx: { path: '', exists: false, source: '', content: '' },
        }),
    },
    apacheServiceStatus: {
        type: String,
        default: 'unknown',
    },
    nginxServiceStatus: {
        type: String,
        default: 'unknown',
    },
    hasApacheVhost: {
        type: Boolean,
        default: false,
    },
    hasNginxVhost: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const csrfToken = computed(() => String(page.props.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''));
const syncMessage = ref('');
const syncMessageType = ref('success');
const syncLoading = ref(false);

const formatStatus = (value) => {
    const text = String(value || 'unknown').toLowerCase();
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

const apacheVhostTone = computed(() => (
    props.hasApacheVhost
        ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
        : 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300'
));

const nginxVhostTone = computed(() => (
    props.hasNginxVhost
        ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
        : 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300'
));

const syncVhost = async () => {
    if (syncLoading.value) {
        return;
    }

    syncLoading.value = true;
    syncMessage.value = '';

    try {
        const response = await fetch(panelRoute('websites.vhost.sync', { id: props.website.id }), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken.value,
            },
            body: JSON.stringify({}),
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok || payload?.type === 'error' || payload?.errors) {
            throw new Error(payload?.message || 'VHost sync failed.');
        }

        syncMessageType.value = 'success';
        syncMessage.value = payload?.message || 'VHost synced successfully.';
    } catch (error) {
        syncMessageType.value = 'error';
        syncMessage.value = error?.message || 'VHost sync failed.';
    } finally {
        syncLoading.value = false;
    }
};
</script>

<template>
    <Head title="Apache + Nginx Service" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Apache + Nginx Service</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Website-specific web server config for {{ website.domain || '-' }}.
                    </p>
                </div>
              
            </div>
        </template>

            <div class="mb-4 flex justify-end gap-2">
                <button
                    type="button"
                    :disabled="syncLoading"
                    @click="syncVhost"
                    class="rounded-md border border-violet-300 px-3 py-2 text-sm text-violet-700 hover:bg-violet-50 dark:border-violet-700 dark:text-violet-300 dark:hover:bg-violet-900/20"
                >
                    <span v-if="syncLoading">Syncing...</span>
                    <span v-else><i class="fa fa-sync"></i> Sync VHost</span>
                </button>
                <Link :href="panelRoute('websites.ssl', { id: website.id })" class="rounded-md border border-blue-300 px-3 py-2 text-sm text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20">
                    <i class="bi bi-shield-check mr-2"></i> Open SSL Manager
                </Link>
                 <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                   <i class="bi bi-arrow-left mr-2"></i> Back to Manage
                </Link>
            </div>

        <div class="space-y-6">
            <div v-if="syncMessage" class="rounded-md px-4 py-3 text-sm whitespace-pre-line" :class="syncMessageType === 'error' ? 'border border-red-200 bg-red-50 text-red-700' : 'border border-emerald-200 bg-emerald-50 text-emerald-700'">
                {{ syncMessage }}
            </div>
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 whitespace-pre-line">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 whitespace-pre-line">
                {{ page.props.flash.error }}
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full border px-3 py-1 text-xs font-medium" :class="statusTone(apacheServiceStatus)">
                        Apache: {{ formatStatus(apacheServiceStatus) }}
                    </span>
                    <span class="rounded-full border px-3 py-1 text-xs font-medium" :class="statusTone(nginxServiceStatus)">
                        Nginx: {{ formatStatus(nginxServiceStatus) }}
                    </span>
                    <span class="rounded-full border px-3 py-1 text-xs font-medium" :class="apacheVhostTone">
                        Apache VHost: {{ hasApacheVhost ? 'Configured' : 'Missing' }}
                    </span>
                    <span class="rounded-full border px-3 py-1 text-xs font-medium" :class="nginxVhostTone">
                        Nginx VHost: {{ hasNginxVhost ? 'Configured' : 'Missing' }}
                    </span>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Root Path</p>
                        <p class="mt-1 text-sm font-semibold break-all">{{ website.root_path || '-' }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">PHP Version</p>
                        <p class="mt-1 text-sm font-semibold">{{ website.php_version || '-' }}</p>
                    </div>
                </div>

            </section>


            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Current Config</h2>
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
                                    {{ vhostPreview.apache.exists ? 'Config Found' : 'Preview' }}
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
                                    {{ vhostPreview.nginx.exists ? 'Config Found' : 'Preview' }}
                                </span>
                            </div>
                            <p class="mt-1 break-all">{{ vhostPreview.nginx.path || '-' }}</p>
                            <p>{{ vhostPreview.nginx.source || '-' }}</p>
                        </div>
                        <pre class="max-h-80 overflow-auto bg-slate-950 p-3 text-xs text-slate-100"><code>{{ vhostPreview.nginx.content || '# Nginx config is not available yet.' }}</code></pre>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
