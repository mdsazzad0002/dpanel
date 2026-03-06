<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    snapshot: {
        type: Object,
        default: () => ({}),
    },
});
</script>

<template>
    <Head title="Monitoring" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Monitoring</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Live server and panel metrics.</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">CPU Load</p>
                    <p class="mt-2 text-2xl font-semibold">{{ snapshot.cpu_load_percent ?? 0 }}%</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Memory</p>
                    <p class="mt-2 text-2xl font-semibold">{{ snapshot.memory_used_mb ?? 0 }} MB</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">of {{ snapshot.memory_total_mb ?? 0 }} MB</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Disk</p>
                    <p class="mt-2 text-2xl font-semibold">{{ snapshot.disk_used_gb ?? 0 }} GB</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">of {{ snapshot.disk_total_gb ?? 0 }} GB</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Updated</p>
                    <p class="mt-2 text-sm font-semibold">{{ snapshot.updated_at ?? '-' }}</p>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Websites</p>
                    <p class="mt-2 text-2xl font-semibold">{{ snapshot.websites_total ?? 0 }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Mailboxes</p>
                    <p class="mt-2 text-2xl font-semibold">{{ snapshot.mailboxes_total ?? 0 }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">DB Requests</p>
                    <p class="mt-2 text-2xl font-semibold">{{ snapshot.database_requests_total ?? 0 }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Cron Jobs</p>
                    <p class="mt-2 text-2xl font-semibold">{{ snapshot.cron_jobs_total ?? 0 }}</p>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Services</h2>
                    <div class="mt-3 space-y-2">
                        <div v-for="(status, service) in snapshot.services || {}" :key="service" class="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2 dark:border-slate-700">
                            <span class="text-sm font-medium uppercase">{{ service }}</span>
                            <span :class="status === 'running' ? 'text-emerald-600' : 'text-amber-600'" class="text-xs font-semibold">{{ status }}</span>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Top Processes</h2>
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-800">
                                <tr>
                                    <th class="px-3 py-2">PID</th>
                                    <th class="px-3 py-2">Name</th>
                                    <th class="px-3 py-2">CPU %</th>
                                    <th class="px-3 py-2">MEM %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="proc in snapshot.topProcesses || []" :key="`${proc.pid}-${proc.name}`" class="border-t border-slate-200 dark:border-slate-800">
                                    <td class="px-3 py-2">{{ proc.pid }}</td>
                                    <td class="px-3 py-2">{{ proc.name }}</td>
                                    <td class="px-3 py-2">{{ proc.cpu }}</td>
                                    <td class="px-3 py-2">{{ proc.mem }}</td>
                                </tr>
                                <tr v-if="(snapshot.topProcesses || []).length === 0">
                                    <td colspan="4" class="px-3 py-4 text-center text-slate-500">No process data available.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
