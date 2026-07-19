<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    metrics: {
        type: Object,
        default: () => ({}),
    },
    activities: {
        type: Array,
        default: () => [],
    },
});
const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const csrfToken = computed(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const actionMessage = ref('');
const actionMessageType = ref('success');
const actionLoading = ref(false);

const toNumber = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const formatDate = (value) => {
    if (!value) return '-';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    const now = new Date();
    const diffMs = now - parsed;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const statusValue = computed(() => String(props.website?.status ?? 'unknown').toLowerCase());
const statusLabel = computed(() => {
    const value = statusValue.value;
    if (!value) return 'Unknown';
    return value.charAt(0).toUpperCase() + value.slice(1);
});

const statusDot = computed(() => {
    if (statusValue.value === 'live') return 'bg-emerald-500';
    if (statusValue.value === 'disabled') return 'bg-red-500';
    if (statusValue.value === 'partial') return 'bg-amber-500';
    return 'bg-slate-400';
});

const statusClass = computed(() => {
    if (statusValue.value === 'live') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
    }
    if (statusValue.value === 'disabled') {
        return 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400';
    }
    if (statusValue.value === 'partial') {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
    }
    return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
});

const sslEnabled = computed(() => Boolean(props.website?.enable_ssl));
const scheme = computed(() => (sslEnabled.value ? 'https' : 'http'));
const webServerHref = computed(() => panelRoute('websites.web-server', { id: props.website.id }));

const serviceLinks = computed(() => [
    { label: 'WordPress Installer', icon: 'bi-wordpress', color: 'blue', href: panelRoute('websites.wordpress.manager', { id: props.website.id }), description: 'Install and manage WordPress' },
    { label: 'SSL Manager', icon: 'bi-shield-lock', color: 'emerald', href: panelRoute('websites.ssl', { id: props.website.id }), description: 'Issue and check SSL certificates' },
    { label: 'Usage Details', icon: 'bi-graph-up', color: 'violet', href: panelRoute('websites.usage', { id: props.website.id }), description: 'Detailed usage history' },
    { label: 'Apache + Nginx', icon: 'bi-hdd-network', color: 'cyan', href: webServerHref.value, description: 'Web server configuration' },
    { label: 'Redis Cache', icon: 'bi-lightning', color: 'amber', href: panelRoute('websites.redis-cache.index', { id: props.website.id }), description: 'Per-website cache isolation' },
    { label: 'File Manager', icon: 'bi-folder2-open', color: 'indigo', href: panelRoute('websites.filemanager', { id: props.website.id }), description: 'Browse and edit files' },
    { label: 'Cron Jobs', icon: 'bi-clock-history', color: 'rose', href: panelRoute('websites.cronjobs.index', { id: props.website.id }), description: 'Scheduled tasks' },
    { label: 'Email Accounts', icon: 'bi-envelope', color: 'pink', href: panelRoute('emails.list'), description: 'Mailbox services' },
    { label: 'Databases', icon: 'bi-database', color: 'orange', href: panelRoute('databases.list'), description: 'Database management' },
    { label: 'DNS Records', icon: 'bi-diagram-3', color: 'teal', href: panelRoute('dns.records'), description: 'DNS entries' },
    { label: 'PHP Manager', icon: 'bi-braces', color: 'indigo', href: panelRoute('php.manager'), description: 'PHP versions & modules' },
    { label: 'Security', icon: 'bi-shield-check', color: 'red', href: panelRoute('security.manager'), description: 'Firewall & SSH' },
]);

const serviceColorClasses = {
    blue: 'bg-blue-500/10 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
    emerald: 'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
    violet: 'bg-violet-500/10 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400',
    cyan: 'bg-cyan-500/10 text-cyan-600 dark:bg-cyan-500/15 dark:text-cyan-400',
    amber: 'bg-amber-500/10 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
    indigo: 'bg-indigo-500/10 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400',
    rose: 'bg-rose-500/10 text-rose-600 dark:bg-rose-500/15 dark:text-rose-400',
    pink: 'bg-pink-500/10 text-pink-600 dark:bg-pink-500/15 dark:text-pink-400',
    orange: 'bg-orange-500/10 text-orange-600 dark:bg-orange-500/15 dark:text-orange-400',
    teal: 'bg-teal-500/10 text-teal-600 dark:bg-teal-500/15 dark:text-teal-400',
    red: 'bg-red-500/10 text-red-600 dark:bg-red-500/15 dark:text-red-400',
};

const quickActions = computed(() => [
    { label: 'Edit Website', icon: 'bi-pencil-square', href: panelRoute('websites.edit', { id: props.website.id }), color: 'slate', method: 'get' },
    { label: 'Sync VHost', icon: 'bi-arrow-repeat', action: 'syncVhost', color: 'blue' },
    { label: 'Clear Cache', icon: 'bi-trash3', href: panelRoute('websites.project-cache.clear', { id: props.website.id }), color: 'red', method: 'post' },
    { label: 'File Manager', icon: 'bi-folder2-open', href: panelRoute('websites.filemanager', { id: props.website.id }), color: 'emerald', method: 'get' },
    { label: 'Back to List', icon: 'bi-arrow-left', href: panelRoute('websites.list'), color: 'slate', method: 'get' },
]);

const quickActionColorClasses = {
    slate: 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-700',
    blue: 'border-blue-200 bg-blue-50/50 text-blue-700 hover:border-blue-300 hover:bg-blue-50 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-400 dark:hover:border-blue-700',
    red: 'border-red-200 bg-red-50/50 text-red-700 hover:border-red-300 hover:bg-red-50 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400 dark:hover:border-red-700',
    emerald: 'border-emerald-200 bg-emerald-50/50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400 dark:hover:border-emerald-700',
};

const metrics = computed(() => [
    { label: 'Connections', value: toNumber(props.metrics.connections_current), icon: 'bi-people', color: 'blue' },
    { label: 'Active Jobs', value: toNumber(props.metrics.jobs_pending), icon: 'bi-list-task', color: 'amber' },
    { label: 'Databases', value: toNumber(props.metrics.databases_count), icon: 'bi-database', color: 'violet' },
    { label: 'Disk Usage', value: `${toNumber(props.metrics.disk_used_mb).toFixed(1)} MB`, icon: 'bi-hdd', color: 'emerald', sub: `Files: ${toNumber(props.metrics.file_count)}` },
]);

const metricColorClasses = {
    blue: 'bg-blue-500/10 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
    amber: 'bg-amber-500/10 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
    violet: 'bg-violet-500/10 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400',
    emerald: 'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
};

const liveSiteUrl = computed(() => {
    const domain = String(props.website?.domain || '').trim();
    if (!domain) return '';
    return `${scheme.value}://${domain}`;
});

const managementPreviewUrl = computed(() => {
    if (!props.website?.id) return '';
    return panelRoute('websites.preview', { id: props.website.id });
});

const copyToClipboard = (text) => {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    }
};

const syncVhost = async () => {
    if (actionLoading.value) {
        return;
    }

    actionMessage.value = '';
    actionLoading.value = true;

    try {
        const response = await fetch(panelRoute('websites.vhost.sync', { id: props.website.id }), {
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

        actionMessageType.value = String(data.type || 'success');
        actionMessage.value = String(data.message || 'Live vhost synced successfully.');
    } catch (error) {
        actionMessageType.value = 'error';
        actionMessage.value = String(error?.message || error?.errors?.vhost_sync || 'Live vhost sync failed.');
    } finally {
        actionLoading.value = false;
    }
};
</script>

<template>
    <Head title="Manage Website" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Website Management</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Tools and configuration for {{ website.domain }}.</p>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Flash Messages -->
            <div v-if="page.props.flash?.success" class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">
                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" /></svg>
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400">
                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" /></svg>
                {{ page.props.flash.error }}
            </div>
            <div v-if="actionMessage" :class="actionMessageType === 'success'
                ? 'flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
                : 'flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400'">
                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current">
                    <path v-if="actionMessageType !== 'success'" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                    <path v-else d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14.2-4.6-4.6 1.4-1.4L11 13.4l5.2-5.2 1.4 1.4-6.6 6.6z" />
                </svg>
                <span>{{ actionMessage }}</span>
            </div>

            <!-- Hero Section -->
            <section class="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                <!-- Background decorations -->
                <div class="pointer-events-none absolute -right-20 -top-20 h-60 w-60 rounded-full bg-gradient-to-br from-blue-400/10 to-indigo-400/10 blur-3xl dark:from-blue-500/5 dark:to-indigo-500/5"></div>
                <div class="pointer-events-none absolute -left-16 bottom-0 h-40 w-40 rounded-full bg-gradient-to-tr from-cyan-400/10 to-blue-400/10 blur-3xl dark:from-cyan-500/5 dark:to-blue-500/5"></div>

                <div class="relative p-6 lg:p-8">
                    <div class="grid gap-6 lg:grid-cols-[1.5fr_1fr]">
                        <!-- Left: Website Info -->
                        <div class="space-y-5">
                            <!-- Status Badges -->
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium" :class="statusClass">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="statusDot"></span>
                                    {{ statusLabel }}
                                </span>
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-400">
                                    <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" /></svg>
                                    PHP {{ website.php_version || '-' }}
                                </span>
                                <span v-if="sslEnabled" class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">
                                    <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" /></svg>
                                    SSL
                                </span>
                            </div>

                            <!-- Domain -->
                            <div>
                                <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-50">{{ website.domain || '-' }}</h2>
                                <p class="mt-1.5 flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
                                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current opacity-50"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z" /></svg>
                                    <span class="font-medium text-slate-700 dark:text-slate-300">{{ website.root_path || '-' }}</span>
                                </p>
                            </div>

                            <!-- URL Cards -->
                            <div class="grid gap-3 sm:grid-cols-2">
                                <!-- Panel Preview -->
                                <div class="group rounded-xl border border-slate-200 bg-slate-50/50 p-3 transition dark:border-slate-700/80 dark:bg-slate-800/30">
                                    <div class="flex items-center justify-between">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Panel Preview</p>
                                        <a
                                            v-if="managementPreviewUrl"
                                            :href="managementPreviewUrl"
                                            class="inline-flex items-center gap-1 text-[11px] font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                                        >
                                            Open
                                            <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current"><path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z" /></svg>
                                        </a>
                                    </div>
                                    <div class="mt-2 flex items-center gap-2">
                                        <input
                                            :value="managementPreviewUrl"
                                            type="text"
                                            readonly
                                            class="min-w-0 flex-1 truncate rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-600 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-400"
                                        />
                                        <button
                                            v-if="managementPreviewUrl"
                                            type="button"
                                            class="shrink-0 rounded-lg border border-slate-200 bg-white p-1.5 text-slate-400 transition hover:text-blue-600 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-500 dark:hover:text-blue-400"
                                            @click="copyToClipboard(managementPreviewUrl)"
                                            title="Copy URL"
                                        >
                                            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z" /></svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Live Website -->
                                <div class="group rounded-xl border border-slate-200 bg-slate-50/50 p-3 transition dark:border-slate-700/80 dark:bg-slate-800/30">
                                    <div class="flex items-center justify-between">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Live Website</p>
                                        <a
                                            v-if="liveSiteUrl"
                                            :href="liveSiteUrl"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300"
                                        >
                                            Open
                                            <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current"><path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z" /></svg>
                                        </a>
                                    </div>
                                    <div class="mt-2 flex items-center gap-2">
                                        <input
                                            :value="liveSiteUrl"
                                            type="text"
                                            readonly
                                            class="min-w-0 flex-1 truncate rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-600 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-400"
                                        />
                                        <button
                                            v-if="liveSiteUrl"
                                            type="button"
                                            class="shrink-0 rounded-lg border border-slate-200 bg-white p-1.5 text-slate-400 transition hover:text-emerald-600 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-500 dark:hover:text-emerald-400"
                                            @click="copyToClipboard(liveSiteUrl)"
                                            title="Copy URL"
                                        >
                                            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z" /></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Quick Actions -->
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Quick Actions</p>
                            <div class="mt-3 space-y-2">
                                <Link
                                    v-for="action in quickActions.filter((item) => !item.action)"
                                    :key="action.label"
                                    :href="action.href"
                                    :method="action.method"
                                    as="button"
                                    :class="[
                                        'flex w-full items-center gap-3 rounded-xl border px-3.5 py-2.5 text-left text-[13px] font-medium transition-all duration-150',
                                        quickActionColorClasses[action.color] || quickActionColorClasses.slate,
                                    ]"
                                >
                                    <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current opacity-70"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" /></svg>
                                    {{ action.label }}
                                </Link>
                                <button
                                    v-for="action in quickActions.filter((item) => item.action === 'syncVhost')"
                                    :key="action.label"
                                    type="button"
                                    :disabled="actionLoading"
                                    :class="[
                                        'flex w-full items-center gap-3 rounded-xl border px-3.5 py-2.5 text-left text-[13px] font-medium transition-all duration-150 disabled:cursor-not-allowed disabled:opacity-60',
                                        quickActionColorClasses[action.color] || quickActionColorClasses.slate,
                                    ]"
                                    @click="syncVhost"
                                >
                                    <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current opacity-70"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" /></svg>
                                    {{ action.label }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Metrics Cards -->
            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="metric in metrics"
                    :key="metric.label"
                    class="group rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm transition hover:shadow-md dark:border-slate-800/80 dark:bg-slate-900/50"
                >
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{{ metric.label }}</p>
                            <p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ metric.value }}</p>
                            <p v-if="metric.sub" class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">{{ metric.sub }}</p>
                        </div>
                        <div :class="['flex h-10 w-10 items-center justify-center rounded-xl transition', metricColorClasses[metric.color]]">
                            <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current opacity-80"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" /></svg>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Services + Activity -->
            <section class="grid gap-4 xl:grid-cols-3">
                <!-- Services Grid -->
                <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm xl:col-span-2 dark:border-slate-800/80 dark:bg-slate-900/50">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Services</h2>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ serviceLinks.length }} tools</span>
                    </div>
                    <div class="mt-4 grid gap-2.5 sm:grid-cols-2 xl:grid-cols-3">
                        <Link
                            v-for="service in serviceLinks"
                            :key="service.label"
                            :href="service.href"
                            class="group flex items-center gap-3 rounded-xl border border-slate-100 bg-white p-3 transition-all duration-150 hover:-translate-y-0.5 hover:border-slate-200 hover:shadow-md dark:border-slate-800 dark:bg-slate-800/50 dark:hover:border-slate-700 dark:hover:shadow-lg"
                        >
                            <div :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition', serviceColorClasses[service.color]]">
                                <i :class="['bi text-base', service.icon]"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[13px] font-semibold text-slate-800 group-hover:text-slate-950 dark:text-slate-200 dark:group-hover:text-white">{{ service.label }}</p>
                                <p class="mt-0.5 truncate text-[11px] text-slate-400 dark:text-slate-500">{{ service.description }}</p>
                            </div>
                            <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-500 dark:text-slate-600 dark:group-hover:text-slate-400"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" /></svg>
                        </Link>
                    </div>
                </div>

                <!-- Activity Timeline -->
                <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Activity</h2>
                    <div class="mt-4 space-y-0">
                        <div v-if="activities.length === 0" class="flex flex-col items-center py-6 text-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800">
                                <svg viewBox="0 0 24 24" class="h-6 w-6 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <p class="mt-3 text-sm font-medium text-slate-500 dark:text-slate-400">No activity yet</p>
                        </div>
                        <div
                            v-for="(item, index) in activities"
                            :key="item.label"
                            class="relative flex gap-3 pb-4 last:pb-0"
                        >
                            <!-- Timeline line -->
                            <div class="relative flex flex-col items-center">
                                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-500/10 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400">
                                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><circle cx="12" cy="12" r="4" /></svg>
                                </div>
                                <div v-if="index < activities.length - 1" class="mt-1 h-full w-px bg-slate-200 dark:bg-slate-700"></div>
                            </div>
                            <div class="min-w-0 flex-1 pt-0.5">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{{ item.label }}</p>
                                <p class="mt-0.5 truncate text-[13px] font-medium text-slate-700 dark:text-slate-300">
                                    {{
                                        item.label === 'Request Created' || item.label === 'Request Updated'
                                            ? formatDate(item.value)
                                            : (item.value || '-')
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
