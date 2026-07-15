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
const userRoles = computed(() => page.props.auth?.roles ?? []);

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
    if (s === 'running' || s === 'active' || s === 'online') return 'bg-emerald-500';
    if (s === 'stopped' || s === 'inactive' || s === 'offline') return 'bg-red-500';
    return 'bg-amber-500';
};

const services = computed(() => {
    const svc = dashboardStats.value.services || {};
    return [
        { name: 'Apache', status: svc.apache, icon: 'bi-hdd-network' },
        { name: 'Nginx', status: svc.nginx, icon: 'bi-globe' },
        { name: 'MySQL', status: svc.database, icon: 'bi-database' },
        { name: 'Redis', status: svc.redis, icon: 'bi-memory' },
        { name: 'Postfix', status: svc.mail, icon: 'bi-envelope' },
        { name: 'Dovecot', status: svc.dovecot, icon: 'bi-inbox' },
    ].filter(s => s.status);
});

const quickActions = [
    { label: 'New Website', icon: 'bi-globe', route: 'websites.create', color: 'from-blue-500 to-blue-600' },
    { label: 'New Email', icon: 'bi-envelope-plus', route: 'emails.create', color: 'from-violet-500 to-violet-600' },
    { label: 'New Database', icon: 'bi-database-add', route: 'databases.create', color: 'from-amber-500 to-amber-600' },
    { label: 'DNS Records', icon: 'bi-diagram-3', route: 'dns.records', color: 'from-cyan-500 to-cyan-600' },
    { label: 'Monitoring', icon: 'bi-activity', route: 'monitoring.index', color: 'from-emerald-500 to-emerald-600' },
    { label: 'Security', icon: 'bi-shield-lock', route: 'security.manager', color: 'from-red-500 to-red-600' },
];
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

            <!-- Stats Cards (3 items) -->
            <section class="grid gap-4 sm:grid-cols-3">
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
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Quick Actions</h2>
                <div class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-6">
                    <Link
                        v-for="action in quickActions"
                        :key="action.label"
                        :href="panelRoute(action.route)"
                        class="group flex flex-col items-center gap-2 rounded-xl p-4 transition-all hover:-translate-y-1 hover:shadow-lg"
                    >
                        <div :class="['flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br text-white shadow-md transition-all group-hover:scale-110', action.color]">
                            <i :class="['bi text-xl', action.icon]"></i>
                        </div>
                        <span class="text-xs font-medium text-slate-700 dark:text-slate-300">{{ action.label }}</span>
                    </Link>
                </div>
            </div>

            <!-- Services -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
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
    </AuthenticatedLayout>
</template>
