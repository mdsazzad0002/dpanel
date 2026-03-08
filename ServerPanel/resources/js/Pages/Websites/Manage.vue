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
    histories: {
        type: Object,
        default: () => ({ points: [] }),
    },
    activities: {
        type: Array,
        default: () => [],
    },
    vhostPreview: {
        type: Object,
        default: () => ({
            apache: { path: '', exists: false, source: '', content: '' },
            nginx: { path: '', exists: false, source: '', content: '' },
        }),
    },
    wordpressVersions: {
        type: Array,
        default: () => ['latest'],
    },
});
const page = usePage();

const toNumber = (value) => {
    const normalized = Number(value ?? 0);
    return Number.isFinite(normalized) ? normalized : 0;
};

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};

const statusValue = computed(() => String(props.website?.status ?? 'unknown').toLowerCase());
const statusLabel = computed(() => {
    const value = statusValue.value;
    if (!value) return 'Unknown';
    return value.charAt(0).toUpperCase() + value.slice(1);
});

const statusClass = computed(() => {
    if (statusValue.value === 'live') {
        return 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300';
    }
    if (statusValue.value === 'disabled') {
        return 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300';
    }
    if (statusValue.value === 'partial') {
        return 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
    }

    return 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200';
});

const sslEnabled = computed(() => Boolean(props.website?.enable_ssl));
const scheme = computed(() => (sslEnabled.value ? 'https' : 'http'));
const installerValue = computed(() => String(props.website?.app_installer ?? 'none').toLowerCase());
const websiteWordPressVersion = computed(() => {
    const normalized = String(props.website?.wordpress_version ?? 'latest').trim().toLowerCase();
    return normalized === '' ? 'latest' : normalized;
});
const installerLabel = computed(() => {
    if (installerValue.value !== 'wordpress') {
        return 'Starter Files';
    }

    return websiteWordPressVersion.value === 'latest'
        ? 'WordPress (Latest)'
        : `WordPress (${websiteWordPressVersion.value})`;
});
const isWordPressInstalled = computed(() => installerValue.value === 'wordpress');
const installerClass = computed(() => (
    installerValue.value === 'wordpress'
        ? 'border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-700 dark:bg-blue-900/20 dark:text-blue-300'
        : 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'
));
const availableWordPressVersions = computed(() => {
    const list = Array.isArray(props.wordpressVersions) ? props.wordpressVersions : [];
    const normalized = list
        .map((version) => String(version || '').trim().toLowerCase())
        .filter((version) => version === 'latest' || /^\d+\.\d+(\.\d+)?$/.test(version));

    return Array.from(new Set(['latest', websiteWordPressVersion.value, ...normalized]));
});
const selectedWordPressVersion = ref(websiteWordPressVersion.value);

const serviceLinks = computed(() => [
    { label: 'Redis Cache', short: 'RC', href: route('websites.redis-cache.index', props.website.id), description: 'Per-website cache isolation' },
    { label: 'File Manager', short: 'FM', href: route('websites.filemanager', props.website.id), description: 'Browse and edit files' },
    { label: 'Cron Jobs', short: 'CJ', href: route('websites.cronjobs.index', props.website.id), description: 'Setup scheduled tasks' },
    { label: 'Email Accounts', short: 'EM', href: route('emails.list'), description: 'Manage mailbox services' },
    { label: 'Databases', short: 'DB', href: route('databases.list'), description: 'Manage database services' },
    { label: 'DNS Records', short: 'DNS', href: route('dns.records'), description: 'Manage DNS entries' },
    { label: 'PHP Manager', short: 'PHP', href: route('php.manager'), description: 'Manage PHP versions and modules' },
    { label: 'Security', short: 'SEC', href: route('security.manager'), description: 'Firewall and SSH settings' },
    { label: 'Terminal', short: 'TERM', href: route('terminal.index'), description: 'Run server commands' },
]);

const browseTarget = ref(
    props.website.domain ? `${props.website.enable_ssl ? 'https' : 'http'}://${props.website.domain}` : '',
);

const browseUrl = computed(() => {
    const value = (browseTarget.value ?? '').trim();
    if (!value) return '';
    if (/^https?:\/\//i.test(value)) return value;
    return `http://${value}`;
});

const currentBaseUrl = computed(() => {
    if (typeof window === 'undefined') return '';
    const { origin, pathname } = window.location;
    const publicIndex = pathname.toLowerCase().indexOf('/public');
    const basePath = publicIndex >= 0 ? pathname.slice(0, publicIndex + 7) : '';

    return `${origin}${basePath}`;
});

const managementPreviewUrl = computed(() => {
    const base = currentBaseUrl.value;
    if (!base || !props.website?.id) return '';

    return `${base}/websites/${props.website.id}/preview`;
});

const liveSiteUrl = computed(() => {
    const domain = String(props.website?.domain || '').trim();
    if (!domain) return '';

    return `${scheme.value}://${domain}`;
});

const diskUsedMb = computed(() => toNumber(props.metrics?.disk_used_mb));
const diskLimitMb = computed(() => toNumber(props.metrics?.disk_limit_mb));
const diskUsagePercent = computed(() => {
    if (diskLimitMb.value <= 0) return 0;
    return Math.max(0, Math.min(100, Math.round((diskUsedMb.value / diskLimitMb.value) * 100)));
});

const cpuUsagePercent = computed(() => Math.max(0, Math.min(100, toNumber(props.metrics?.cpu_usage_percent))));

const historyPoints = computed(() => {
    if (!Array.isArray(props.histories?.points)) return [];
    return props.histories.points;
});
</script>

<template>
    <Head title="Manage Website" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Website Management</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">History and resource usage for {{ website.domain }}.</p>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <section class="relative overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-sky-50 via-white to-indigo-50 p-6 dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-800">
                <div class="pointer-events-none absolute -right-12 -top-16 h-40 w-40 rounded-full bg-blue-300/20 blur-2xl dark:bg-blue-900/30" />
                <div class="pointer-events-none absolute -left-10 bottom-0 h-32 w-32 rounded-full bg-cyan-300/20 blur-2xl dark:bg-cyan-900/20" />

                <div class="relative grid gap-6 lg:grid-cols-[1.5fr_1fr]">
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full border px-3 py-1 text-xs font-medium" :class="statusClass">{{ statusLabel }}</span>
                            <span
                                class="rounded-full border px-3 py-1 text-xs font-medium"
                                :class="sslEnabled
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                                    : 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'"
                            >
                                {{ sslEnabled ? 'SSL Enabled' : 'SSL Disabled' }}
                            </span>
                            <span class="rounded-full border border-blue-300 bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700 dark:border-blue-700 dark:bg-blue-900/20 dark:text-blue-300">
                                PHP {{ website.php_version || '-' }}
                            </span>
                            <span class="rounded-full border px-3 py-1 text-xs font-medium" :class="installerClass">
                                {{ installerLabel }}
                            </span>
                        </div>

                        <div>
                            <h2 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ website.domain || '-' }}</h2>
                            <p class="mt-1 break-all text-sm text-slate-600 dark:text-slate-300">
                                Root path: <span class="font-medium">{{ website.root_path || '-' }}</span>
                            </p>
                        </div>

                        <div class="grid gap-3">
                            <div class="rounded-xl border border-slate-200 bg-white/80 p-3 backdrop-blur dark:border-slate-700 dark:bg-slate-900/60">
                                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Panel Preview URL</p>
                                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <input
                                        :value="managementPreviewUrl"
                                        type="text"
                                        readonly
                                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                                    />
                                    <a
                                        v-if="managementPreviewUrl"
                                        :href="managementPreviewUrl"
                                        class="inline-flex shrink-0 rounded-md border border-indigo-300 px-3 py-2 text-sm text-indigo-700 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-900/20"
                                    >
                                        Open Preview
                                    </a>
                                </div>
                            </div>

                            <div class="rounded-xl border border-slate-200 bg-white/80 p-3 backdrop-blur dark:border-slate-700 dark:bg-slate-900/60">
                                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Live Website URL</p>
                                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <input
                                        :value="liveSiteUrl"
                                        type="text"
                                        readonly
                                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                                    />
                                    <a
                                        v-if="liveSiteUrl"
                                        :href="liveSiteUrl"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex shrink-0 rounded-md border border-emerald-300 px-3 py-2 text-sm text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-900/20"
                                    >
                                        Open Live
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="rounded-xl border border-slate-200 bg-white/80 p-4 backdrop-blur dark:border-slate-700 dark:bg-slate-900/60">
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Quick Actions</p>
                            <div class="mt-3 grid gap-2">
                                <div class="rounded-md border border-blue-200/80 p-2 dark:border-blue-800/80">
                                    <div class="flex flex-col gap-2">
                                        <select
                                            v-model="selectedWordPressVersion"
                                            :disabled="isWordPressInstalled"
                                            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800"
                                        >
                                            <option v-for="version in availableWordPressVersions" :key="version" :value="version">
                                                {{ version === 'latest' ? 'Latest Stable' : version }}
                                            </option>
                                        </select>
                                        <Link
                                            :href="route('websites.wordpress.install', website.id)"
                                            method="post"
                                            as="button"
                                            :data="{ wordpress_version: selectedWordPressVersion }"
                                            :disabled="isWordPressInstalled"
                                            class="rounded-md border px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60"
                                            :class="isWordPressInstalled
                                                ? 'border-slate-300 text-slate-500 dark:border-slate-700 dark:text-slate-400'
                                                : 'border-blue-300 text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20'"
                                        >
                                            {{ isWordPressInstalled ? 'WordPress Installed' : 'One-Click WordPress Install' }}
                                        </Link>
                                    </div>
                                </div>
                                <Link
                                    :href="route('websites.vhost.sync', website.id)"
                                    method="post"
                                    as="button"
                                    class="rounded-md border border-violet-300 px-3 py-2 text-sm text-violet-700 hover:bg-violet-50 dark:border-violet-700 dark:text-violet-300 dark:hover:bg-violet-900/20"
                                >
                                    Sync VHost
                                </Link>
                                <Link
                                    :href="route('websites.project-cache.clear', website.id)"
                                    method="post"
                                    as="button"
                                    class="rounded-md border border-rose-300 px-3 py-2 text-sm text-rose-700 hover:bg-rose-50 dark:border-rose-700 dark:text-rose-300 dark:hover:bg-rose-900/20"
                                >
                                    Project Cache Clear
                                </Link>
                                <Link :href="route('apache.index')" class="rounded-md border border-cyan-300 px-3 py-2 text-sm text-cyan-700 hover:bg-cyan-50 dark:border-cyan-700 dark:text-cyan-300 dark:hover:bg-cyan-900/20">
                                    Apache + Nginx Setup
                                </Link>
                                <Link :href="route('websites.filemanager', website.id)" class="rounded-md border border-emerald-300 px-3 py-2 text-sm text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-400 dark:hover:bg-emerald-900/20">
                                    Open File Manager
                                </Link>
                                <Link :href="route('websites.redis-cache.index', website.id)" class="rounded-md border border-amber-300 px-3 py-2 text-sm text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-400 dark:hover:bg-amber-900/20">
                                    Redis Cache
                                </Link>
                                <Link :href="route('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                    Back to Website List
                                </Link>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-white/80 p-4 backdrop-blur dark:border-slate-700 dark:bg-slate-900/60">
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Custom Browser</p>
                            <input
                                v-model.trim="browseTarget"
                                type="text"
                                placeholder="example.com"
                                class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                            />
                            <a
                                :href="browseUrl || '#'"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-2 inline-flex w-full justify-center rounded-md border px-3 py-2 text-sm"
                                :class="browseUrl
                                    ? 'border-blue-300 text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20'
                                    : 'pointer-events-none border-slate-300 text-slate-400 dark:border-slate-700 dark:text-slate-500'"
                            >
                                Open URL
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Connections</p>
                    <p class="mt-2 text-2xl font-semibold">{{ toNumber(metrics.connections_current) }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Active visitors connected now.</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Pending Jobs</p>
                    <p class="mt-2 text-2xl font-semibold">{{ toNumber(metrics.jobs_pending) }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Background tasks waiting in queue.</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Databases</p>
                    <p class="mt-2 text-2xl font-semibold">{{ toNumber(metrics.databases_count) }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Database instances linked to this site.</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Disk Usage</p>
                    <p class="mt-2 text-2xl font-semibold">{{ diskUsedMb }} MB</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Limit: {{ diskLimitMb }} MB</p>
                    <div class="mt-3 h-2 rounded-full bg-slate-200 dark:bg-slate-700">
                        <div class="h-2 rounded-full bg-blue-500 transition-all" :style="{ width: `${diskUsagePercent}%` }" />
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">CPU Usage</p>
                    <p class="mt-2 text-2xl font-semibold">{{ cpuUsagePercent }}%</p>
                    <div class="mt-3 h-2 rounded-full bg-slate-200 dark:bg-slate-700">
                        <div class="h-2 rounded-full bg-cyan-500 transition-all" :style="{ width: `${cpuUsagePercent}%` }" />
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">RAM Usage</p>
                    <p class="mt-2 text-2xl font-semibold">{{ toNumber(metrics.ram_usage_mb) }} MB</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Current memory used by this workload.</p>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Services</h2>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ serviceLinks.length }} tools connected</span>
                    </div>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <Link
                            v-for="service in serviceLinks"
                            :key="service.label"
                            :href="service.href"
                            class="group rounded-xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-3 transition hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-sm dark:border-slate-700 dark:from-slate-900 dark:to-slate-800 dark:hover:border-blue-700"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="inline-flex h-8 min-w-8 items-center justify-center rounded-md bg-blue-100 px-2 text-[11px] font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                    {{ service.short }}
                                </span>
                                <span class="text-[11px] text-slate-400 transition group-hover:text-blue-600 dark:text-slate-500 dark:group-hover:text-blue-300">Open</span>
                            </div>
                            <p class="mt-3 text-sm font-semibold">{{ service.label }}</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ service.description }}</p>
                        </Link>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Website Activity</h2>
                    <div class="mt-4 space-y-3">
                        <div v-if="activities.length === 0" class="rounded-lg border border-dashed border-slate-300 p-3 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                            No activity available for this website.
                        </div>
                        <div v-for="item in activities" :key="item.label" class="relative rounded-lg border border-slate-200 p-3 pl-5 dark:border-slate-700">
                            <span class="absolute left-2 top-4 h-2 w-2 rounded-full bg-blue-500" />
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ item.label }}</p>
                            <p class="mt-1 text-sm break-all">
                                {{
                                    item.label === 'Request Created' || item.label === 'Request Updated' || item.label === 'Last File Change'
                                        ? formatDate(item.value)
                                        : (item.value || '-')
                                }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Usage History (Last 12 hours)</h2>
                <div class="mt-3 overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-2">Time</th>
                                <th class="px-3 py-2">Connections</th>
                                <th class="px-3 py-2">Jobs</th>
                                <th class="px-3 py-2">Databases</th>
                                <th class="px-3 py-2">Disk MB</th>
                                <th class="px-3 py-2">CPU %</th>
                                <th class="px-3 py-2">RAM MB</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="point in historyPoints" :key="point.time" class="border-t border-slate-200 dark:border-slate-800">
                                <td class="px-3 py-2">{{ point.time }}</td>
                                <td class="px-3 py-2">{{ point.connections }}</td>
                                <td class="px-3 py-2">{{ point.jobs }}</td>
                                <td class="px-3 py-2">{{ point.databases }}</td>
                                <td class="px-3 py-2">{{ point.disk }}</td>
                                <td class="px-3 py-2">{{ point.cpu }}</td>
                                <td class="px-3 py-2">{{ point.ram }}</td>
                            </tr>
                            <tr v-if="historyPoints.length === 0">
                                <td colspan="7" class="px-3 py-5 text-center text-slate-500 dark:text-slate-400">
                                    No usage history points available.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">VHost Config Render</h2>
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
                                    {{ vhostPreview.apache.exists ? 'Config Found' : 'Not Found' }}
                                </span>
                            </div>
                            <p class="break-all">{{ vhostPreview.apache.path || '-' }}</p>
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
                                    {{ vhostPreview.nginx.exists ? 'Config Found' : 'Not Found' }}
                                </span>
                            </div>
                            <p class="break-all">{{ vhostPreview.nginx.path || '-' }}</p>
                            <p>{{ vhostPreview.nginx.source || '-' }}</p>
                        </div>
                        <pre class="max-h-80 overflow-auto bg-slate-950 p-3 text-xs text-slate-100"><code>{{ vhostPreview.nginx.content || '# Nginx config is not available yet.' }}</code></pre>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
