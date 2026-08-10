<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, ref, onMounted } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);
const dashboardStats = computed(() => page.props.dashboardStats ?? {});
const accountSummary = computed(() => page.props.accountSummary ?? {});
const canViewServerUsage = computed(() => page.props.canViewServerUsage === true);
const userRoles = computed(() => page.props.auth?.roles ?? []);
const userPermissions = computed(() => page.props.auth?.permissions ?? []);

const selectedService = ref(null);

const serverInfo = computed(() => ({
    hostname: dashboardStats.value.hostname,
    ip: dashboardStats.value.server_ip,
    os: dashboardStats.value.os,
    uptime: dashboardStats.value.uptime,
    cpuCores: dashboardStats.value.cpu_cores,
    totalMemory: dashboardStats.value.memory_total_mb,
    usedMemory: dashboardStats.value.memory_used_mb,
    totalDisk: dashboardStats.value.disk_total_gb,
    usedDisk: dashboardStats.value.disk_used_gb,
}));

const cpuPercent = computed(() => dashboardStats.value.cpu_load_percent ?? 0);
const memoryPercent = computed(() => {
    const used = dashboardStats.value.memory_used_mb ?? 0;
    const total = dashboardStats.value.memory_total_mb ?? 1;
    return Math.round((used / total) * 100);
});
const diskPercent = computed(() => {
    const used = dashboardStats.value.disk_used_gb ?? 0;
    const total = dashboardStats.value.disk_total_gb ?? 1;
    return Math.round((used / total) * 100);
});

const quotaPercent = (used, limit) => {
    if (limit === null || limit === undefined || Number(limit) <= 0) return 0;
    return Math.min(100, Math.round((Number(used || 0) / Number(limit)) * 100));
};
const formatMb = (mb) => {
    const value = Number(mb || 0);
    return value >= 1024 ? `${(value / 1024).toFixed(2)} GB` : `${value.toFixed(value % 1 ? 2 : 0)} MB`;
};
const formatBandwidth = (gb) => {
    const value = Number(gb || 0);
    return value >= 1 ? `${value.toFixed(2)} GB` : `${(value * 1024).toFixed(2)} MB`;
};
const quotaLabel = (used, limit, formatter = (value) => value) => (
    `${formatter(used)} / ${limit === null || limit === undefined ? 'Unlimited' : formatter(limit)}`
);
const formatLoginTime = (value) => value
    ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : 'Not recorded';

const getCircularProgress = (value) => {
    const circumference = 2 * Math.PI * 45;
    const offset = circumference - ((value ?? 0) / 100) * circumference;
    return { circumference, offset };
};

const getProgressColor = (value) => {
    const v = value ?? 0;
    if (v < 50) return 'from-emerald-500 to-emerald-400';
    if (v < 75) return 'from-amber-500 to-amber-400';
    return 'from-red-500 to-red-400';
};

const getProgressTextColor = (value) => {
    const v = value ?? 0;
    if (v < 50) return 'text-emerald-600 dark:text-emerald-400';
    if (v < 75) return 'text-amber-600 dark:text-amber-400';
    return 'text-red-600 dark:text-red-400';
};

const getStatusColor = (status) => {
    const s = String(status).toLowerCase();
    if (s === 'running' || s === 'active' || s === 'online' || s === 'mariadb' || s === 'mysql' || s === 'sqlite') return 'bg-emerald-500';
    if (s === 'stopped' || s === 'inactive' || s === 'offline') return 'bg-red-500';
    return 'bg-amber-500';
};

const services = computed(() => {
    const svc = dashboardStats.value.services || {};
    return [
        { name: 'Drust Gateway', status: svc.drust_gateway, icon: 'bi-lightning-charge' },
        { name: 'Drust API', status: svc.drust_api, icon: 'bi-cpu' },
        { name: 'MySQL', status: svc.database, icon: 'bi-database' },
        { name: 'Redis', status: svc.redis, icon: 'bi-memory' },
        { name: 'Postfix', status: svc.mail, icon: 'bi-envelope' },
        { name: 'Dovecot', status: svc.dovecot, icon: 'bi-inbox' },
    ].filter(s => s.status);
});

const rawToolCategories = [
    { name: 'Websites & Domains', icon: 'bi-globe2', color: 'blue', tools: [
        { label: 'Create Website', hint: 'Add a new site', icon: 'bi-plus-square', route: 'websites.create', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        { label: 'List Websites', hint: 'Manage hosted sites', icon: 'bi-window-stack', route: 'websites.list', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        { label: 'DNS Zones', hint: 'Manage DNS records', icon: 'bi-diagram-3', route: 'dns.zones', permissions: ['manage_dns'] },
        { label: 'Nameservers', hint: 'Configure nameservers', icon: 'bi-signpost-split', route: 'dns.nameservers', roles: ['admin', 'reseller'], permissions: ['manage_dns'] },
    ] },
    { name: 'Email', icon: 'bi-envelope', color: 'violet', tools: [
        { label: 'Create Email', hint: 'Create a mailbox', icon: 'bi-envelope-plus', route: 'emails.create', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
        { label: 'Email Accounts', hint: 'Manage mailboxes', icon: 'bi-envelope-open', route: 'emails.list', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
    ] },
    { name: 'Databases', icon: 'bi-database', color: 'amber', tools: [
        { label: 'Create Database', hint: 'Add a database', icon: 'bi-database-add', route: 'databases.create', roles: ['admin', 'reseller'], permissions: ['manage_databases'] },
        { label: 'List Databases', hint: 'Users and databases', icon: 'bi-table', route: 'databases.list', roles: ['admin', 'reseller'], permissions: ['manage_databases'] },
    ] },
    { name: 'Server & Files', icon: 'bi-hdd-stack', color: 'emerald', tools: [
        { label: 'PHP Manager', hint: 'Versions and settings', icon: 'bi-braces', route: 'php.manager', roles: ['admin', 'reseller'], permissions: ['manage_php'] },
        { label: 'Monitoring', hint: 'Resources and logs', icon: 'bi-activity', route: 'monitoring.index', roles: ['admin', 'reseller'], permissions: ['view_monitoring'] },
        { label: 'Backups', hint: 'Snapshots and restore', icon: 'bi-cloud-arrow-down', route: 'backups.index', roles: ['admin', 'reseller'], permissions: ['manage_backups'] },
        { label: 'Trash Backups', hint: 'Deleted site archives', icon: 'bi-trash3', route: 'trash-backups.index', permissions: ['manage_backups'] },
    ] },
    { name: 'Security & Account', icon: 'bi-shield-check', color: 'rose', tools: [
        { label: 'Security', hint: 'Firewall and SSH', icon: 'bi-shield-lock', route: 'security.manager', roles: ['admin', 'reseller'], permissions: ['manage_security'] },
        { label: 'Users', hint: 'Manage panel users', icon: 'bi-people', route: 'users.manage', roles: ['admin', 'reseller'] },
        { label: 'Packages', hint: 'Resource quotas', icon: 'bi-box-seam', route: 'packages.index', roles: ['admin', 'reseller', 'superadmin'], permissions: ['manage_packages'] },
        { label: 'My Profile', hint: 'Account and password', icon: 'bi-person-circle', route: 'profile.edit' },
    ] },
];

const toolCategories = computed(() => rawToolCategories.map((category) => ({
    ...category,
    tools: category.tools.filter((tool) => route().has(tool.route)
        && ((!tool.roles && !tool.permissions)
            || tool.roles?.some((role) => userRoles.value.includes(role))
            || tool.permissions?.some((permission) => userPermissions.value.includes(permission)))),
})).filter((category) => category.tools.length));

const categoryColors = {
    blue: 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300',
    violet: 'bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300',
    amber: 'bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300',
    emerald: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300',
    rose: 'bg-rose-100 text-rose-600 dark:bg-rose-900/40 dark:text-rose-300',
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Dashboard</h1>
                <p class="truncate text-sm text-slate-500 dark:text-slate-400">Server management overview</p>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Server Info Bar -->
            <div class="rounded-xl border border-slate-200 bg-gradient-to-r from-slate-50 to-white p-4 dark:border-slate-700 dark:from-slate-800 dark:to-slate-900">
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400">
                            <i class="bi bi-hdd-rack"></i>
                        </span>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Server</p>
                            <p class="font-medium text-slate-900 dark:text-slate-100">{{ serverInfo.hostname }}</p>
                        </div>
                    </div>
                    <div class="hidden h-8 w-px bg-slate-200 dark:bg-slate-700 sm:block"></div>
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400">
                            <i class="bi bi-globe"></i>
                        </span>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">IP Address</p>
                            <p class="font-medium text-slate-900 dark:text-slate-100">{{ serverInfo.ip }}</p>
                        </div>
                    </div>
                    <div class="hidden h-8 w-px bg-slate-200 dark:bg-slate-700 sm:block"></div>
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-400">
                            <i class="bi bi-pc-display"></i>
                        </span>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">OS</p>
                            <p class="font-medium text-slate-900 dark:text-slate-100">{{ serverInfo.os }}</p>
                        </div>
                    </div>
                    <div class="hidden h-8 w-px bg-slate-200 dark:bg-slate-700 sm:block"></div>
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-cyan-100 text-cyan-600 dark:bg-cyan-900/40 dark:text-cyan-400">
                            <i class="bi bi-clock-history"></i>
                        </span>
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Uptime</p>
                            <p class="font-medium text-slate-900 dark:text-slate-100">{{ serverInfo.uptime }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 items-start gap-6 md:grid-cols-12">
            <!-- Account package and usage -->
            <aside class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900 md:col-span-4 md:col-start-9 md:row-span-3 md:row-start-1 xl:col-span-3 xl:col-start-10">
                <div class="space-y-3 border-b border-slate-200 bg-slate-50 px-5 py-4 dark:border-slate-700 dark:bg-slate-800/70">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ accountSummary.scope }}</p>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ accountSummary.package_name ?? 'No package assigned' }}</h2>
                            <span v-if="accountSummary.package_name" class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                {{ accountSummary.package_owner }} package
                            </span>
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white px-4 py-2 dark:border-slate-700 dark:bg-slate-900">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Last login IP</p>
                        <p class="font-mono text-sm font-semibold text-slate-900 dark:text-white">{{ accountSummary.last_login_ip ?? 'Not recorded' }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ formatLoginTime(accountSummary.last_login_at) }}</p>
                    </div>
                </div>

                <div class="grid gap-px bg-slate-200 dark:bg-slate-700">
                    <div class="bg-white p-4 dark:bg-slate-900">
                        <div class="flex items-center justify-between"><span class="text-sm font-medium">Website disk</span><i class="bi bi-hdd text-blue-500"></i></div>
                        <p class="mt-2 text-lg font-bold">{{ quotaLabel(accountSummary.disk_used_mb, accountSummary.disk_limit_mb, formatMb) }}</p>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-blue-500" :style="{ width: quotaPercent(accountSummary.disk_used_mb, accountSummary.disk_limit_mb) + '%' }"></div></div>
                        <p class="mt-1 text-xs text-slate-500">{{ quotaPercent(accountSummary.disk_used_mb, accountSummary.disk_limit_mb) }}% used</p>
                    </div>
                    <div class="bg-white p-4 dark:bg-slate-900">
                        <div class="flex items-center justify-between"><span class="text-sm font-medium">Mailboxes</span><i class="bi bi-envelope text-violet-500"></i></div>
                        <p class="mt-2 text-lg font-bold">{{ quotaLabel(accountSummary.mailboxes_used, accountSummary.mailboxes_limit) }}</p>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-violet-500" :style="{ width: quotaPercent(accountSummary.mailboxes_used, accountSummary.mailboxes_limit) + '%' }"></div></div>
                        <p class="mt-1 text-xs text-slate-500">{{ formatMb(accountSummary.mail_storage_mb) }} allocated storage</p>
                    </div>
                    <div class="bg-white p-4 dark:bg-slate-900">
                        <div class="flex items-center justify-between"><span class="text-sm font-medium">Websites</span><i class="bi bi-globe text-emerald-500"></i></div>
                        <p class="mt-2 text-lg font-bold">{{ quotaLabel(accountSummary.websites_used, accountSummary.websites_limit) }}</p>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-emerald-500" :style="{ width: quotaPercent(accountSummary.websites_used, accountSummary.websites_limit) + '%' }"></div></div>
                        <p class="mt-1 text-xs text-slate-500">{{ quotaPercent(accountSummary.websites_used, accountSummary.websites_limit) }}% used</p>
                    </div>
                    <div class="bg-white p-4 dark:bg-slate-900">
                        <div class="flex items-center justify-between"><span class="text-sm font-medium">Databases</span><i class="bi bi-database text-amber-500"></i></div>
                        <p class="mt-2 text-lg font-bold">{{ quotaLabel(accountSummary.databases_used, accountSummary.databases_limit) }}</p>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-amber-500" :style="{ width: quotaPercent(accountSummary.databases_used, accountSummary.databases_limit) + '%' }"></div></div>
                        <p class="mt-1 text-xs text-slate-500">{{ quotaPercent(accountSummary.databases_used, accountSummary.databases_limit) }}% used</p>
                    </div>
                    <div class="bg-white p-4 dark:bg-slate-900">
                        <div class="flex items-center justify-between"><span class="text-sm font-medium">Bandwidth</span><i class="bi bi-activity text-rose-500"></i></div>
                        <p class="mt-2 text-lg font-bold">{{ quotaLabel(accountSummary.bandwidth_used_gb, accountSummary.bandwidth_limit_gb, formatBandwidth) }}</p>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-rose-500" :style="{ width: quotaPercent(accountSummary.bandwidth_used_gb, accountSummary.bandwidth_limit_gb) + '%' }"></div></div>
                        <p class="mt-1 text-xs text-slate-500">{{ accountSummary.bandwidth_status }} · {{ accountSummary.bandwidth_requests ?? 0 }} requests</p>
                    </div>
                </div>
            </aside>

            <!-- Stats Cards (3 items) -->
            <section v-if="canViewServerUsage" class="grid gap-4 sm:grid-cols-3 md:col-span-8 md:col-start-1 md:row-start-1 xl:col-span-9">
                <!-- CPU -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">CPU Usage</p>
                            <p :class="['mt-2 text-3xl font-bold', getProgressTextColor(cpuPercent)]">
                                {{ cpuPercent }}<span class="text-lg font-medium text-slate-500 dark:text-slate-400">%</span>
                            </p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ serverInfo.cpuCores }} Cores</p>
                        </div>
                        <div class="relative h-20 w-20">
                            <svg class="h-20 w-20 -rotate-90" viewBox="0 0 100 100">
                                <circle cx="50" cy="50" r="45" fill="none" stroke="currentColor" stroke-width="8" class="text-slate-100 dark:text-slate-800" />
                                <circle
                                    cx="50" cy="50" r="45" fill="none" stroke-width="8" stroke-linecap="round"
                                    :class="['transition-all duration-1000 ease-out', getProgressColor(cpuPercent)]"
                                    :stroke="'currentColor'"
                                    :stroke-dasharray="getCircularProgress(cpuPercent).circumference"
                                    :stroke-dashoffset="getCircularProgress(cpuPercent).offset"
                                />
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <i class="bi bi-cpu text-xl text-blue-500"></i>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div
                                :class="['h-full rounded-full bg-gradient-to-r transition-all duration-1000 ease-out', getProgressColor(cpuPercent)]"
                                :style="{ width: cpuPercent + '%' }"
                            ></div>
                        </div>
                    </div>
                </div>

                <!-- Memory -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Memory</p>
                            <p :class="['mt-2 text-3xl font-bold', getProgressTextColor(memoryPercent)]">
                                {{ memoryPercent }}<span class="text-lg font-medium text-slate-500 dark:text-slate-400">%</span>
                            </p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ serverInfo.usedMemory }} / {{ serverInfo.totalMemory }} MB</p>
                        </div>
                        <div class="relative h-20 w-20">
                            <svg class="h-20 w-20 -rotate-90" viewBox="0 0 100 100">
                                <circle cx="50" cy="50" r="45" fill="none" stroke="currentColor" stroke-width="8" class="text-slate-100 dark:text-slate-800" />
                                <circle
                                    cx="50" cy="50" r="45" fill="none" stroke-width="8" stroke-linecap="round"
                                    :class="['transition-all duration-1000 ease-out', getProgressColor(memoryPercent)]"
                                    :stroke="'currentColor'"
                                    :stroke-dasharray="getCircularProgress(memoryPercent).circumference"
                                    :stroke-dashoffset="getCircularProgress(memoryPercent).offset"
                                />
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <i class="bi bi-memory text-xl text-violet-500"></i>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div
                                :class="['h-full rounded-full bg-gradient-to-r transition-all duration-1000 ease-out', getProgressColor(memoryPercent)]"
                                :style="{ width: memoryPercent + '%' }"
                            ></div>
                        </div>
                    </div>
                </div>

                <!-- Disk -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Disk Usage</p>
                            <p :class="['mt-2 text-3xl font-bold', getProgressTextColor(diskPercent)]">
                                {{ diskPercent }}<span class="text-lg font-medium text-slate-500 dark:text-slate-400">%</span>
                            </p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ serverInfo.usedDisk }} / {{ serverInfo.totalDisk }} GB</p>
                        </div>
                        <div class="relative h-20 w-20">
                            <svg class="h-20 w-20 -rotate-90" viewBox="0 0 100 100">
                                <circle cx="50" cy="50" r="45" fill="none" stroke="currentColor" stroke-width="8" class="text-slate-100 dark:text-slate-800" />
                                <circle
                                    cx="50" cy="50" r="45" fill="none" stroke-width="8" stroke-linecap="round"
                                    :class="['transition-all duration-1000 ease-out', getProgressColor(diskPercent)]"
                                    :stroke="'currentColor'"
                                    :stroke-dasharray="getCircularProgress(diskPercent).circumference"
                                    :stroke-dashoffset="getCircularProgress(diskPercent).offset"
                                />
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <i class="bi bi-hdd text-xl text-emerald-500"></i>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div
                                :class="['h-full rounded-full bg-gradient-to-r transition-all duration-1000 ease-out', getProgressColor(diskPercent)]"
                                :style="{ width: diskPercent + '%' }"
                            ></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Quick Actions -->
            <div class="space-y-5 md:col-span-8 md:col-start-1 xl:col-span-9">
                <section v-for="category in toolCategories" :key="category.name" class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                    <div class="flex items-center gap-3 border-b border-slate-200 px-5 py-3 dark:border-slate-700">
                        <span :class="['flex h-9 w-9 items-center justify-center rounded-lg', categoryColors[category.color]]"><i :class="['bi', category.icon]"></i></span>
                        <h2 class="font-semibold text-slate-900 dark:text-white">{{ category.name }}</h2>
                    </div>
                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <Link v-for="tool in category.tools" :key="tool.route" :href="panelRoute(tool.route)" class="group flex items-center gap-3 border-b border-slate-100 p-4 transition hover:bg-slate-50 sm:border-r dark:border-slate-800 dark:hover:bg-slate-800/60">
                            <span :class="['flex h-11 w-11 shrink-0 items-center justify-center rounded-xl transition-transform group-hover:scale-105', categoryColors[category.color]]"><i :class="['bi text-lg', tool.icon]"></i></span>
                            <span class="min-w-0"><span class="block truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{{ tool.label }}</span><span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ tool.hint }}</span></span>
                        </Link>
                    </div>
                </section>
            </div>

            <!-- Services -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 md:col-span-8 md:col-start-1 xl:col-span-9">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Services</h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="service in services"
                        :key="service.name"
                        class="flex items-center justify-between rounded-xl border border-slate-200 p-4 transition-all hover:border-slate-300 hover:shadow-sm dark:border-slate-700 dark:hover:border-slate-600"
                    >
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800">
                                <i :class="['bi text-lg text-slate-600 dark:text-slate-300', service.icon]"></i>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ service.name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ service.status }}</p>
                            </div>
                        </div>
                        <span :class="[getStatusColor(service.status), 'h-2.5 w-2.5 rounded-full']"></span>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
