<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

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
});

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};

const serviceLinks = computed(() => [
    { label: 'File Manager', href: route('websites.filemanager', props.website.id), description: 'Browse and edit files' },
    { label: 'Cron Jobs', href: route('websites.cronjobs.index', props.website.id), description: 'Setup scheduled tasks' },
    { label: 'Email Accounts', href: route('emails.list'), description: 'Manage mailbox services' },
    { label: 'Databases', href: route('databases.list'), description: 'Manage database services' },
    { label: 'DNS Records', href: route('dns.records'), description: 'Manage DNS entries' },
    { label: 'PHP Manager', href: route('php.manager'), description: 'Manage PHP versions and modules' },
    { label: 'Security', href: route('security.manager'), description: 'Firewall and SSH settings' },
    { label: 'Terminal', href: route('terminal.index'), description: 'Run server commands' },
]);
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
            <div class="flex justify-end gap-2">
                <Link :href="route('websites.filemanager', website.id)" class="rounded-md border border-emerald-300 px-3 py-2 text-sm text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-400">
                    Open File Manager
                </Link>
                <Link :href="route('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to Website List
                </Link>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Services</h2>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <a
                        v-for="service in serviceLinks"
                        :key="service.label"
                        :href="service.href"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="rounded-lg border border-slate-200 p-3 transition hover:border-blue-300 hover:bg-blue-50/50 dark:border-slate-700 dark:hover:border-blue-700 dark:hover:bg-blue-900/20"
                    >
                        <p class="text-sm font-semibold">{{ service.label }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ service.description }}</p>
                    </a>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Connections</p>
                    <p class="mt-2 text-2xl font-semibold">{{ metrics.connections_current }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Pending Jobs</p>
                    <p class="mt-2 text-2xl font-semibold">{{ metrics.jobs_pending }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Databases</p>
                    <p class="mt-2 text-2xl font-semibold">{{ metrics.databases_count }}</p>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Disk Usage</p>
                    <p class="mt-2 text-2xl font-semibold">{{ metrics.disk_used_mb }} MB</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Limit: {{ metrics.disk_limit_mb }} MB</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">CPU Usage</p>
                    <p class="mt-2 text-2xl font-semibold">{{ metrics.cpu_usage_percent }}%</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">RAM Usage</p>
                    <p class="mt-2 text-2xl font-semibold">{{ metrics.ram_usage_mb }} MB</p>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Usage History (Last 12 hours)</h2>
                <div class="mt-3 overflow-x-auto">
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
                            <tr v-for="point in histories.points" :key="point.time" class="border-t border-slate-200 dark:border-slate-800">
                                <td class="px-3 py-2">{{ point.time }}</td>
                                <td class="px-3 py-2">{{ point.connections }}</td>
                                <td class="px-3 py-2">{{ point.jobs }}</td>
                                <td class="px-3 py-2">{{ point.databases }}</td>
                                <td class="px-3 py-2">{{ point.disk }}</td>
                                <td class="px-3 py-2">{{ point.cpu }}</td>
                                <td class="px-3 py-2">{{ point.ram }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Website Activity</h2>
                <div class="mt-3 space-y-2">
                    <div v-for="item in activities" :key="item.label" class="rounded-md border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ item.label }}</p>
                        <p class="text-sm break-all">
                            {{
                                item.label === 'Status'
                                    ? item.value
                                    : formatDate(item.value)
                            }}
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
