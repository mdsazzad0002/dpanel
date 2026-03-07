<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    snapshot: {
        type: Object,
        default: () => ({}),
    },
});

const liveSnapshot = ref({ ...(props.snapshot || {}) });
const refreshError = ref('');
let refreshTimer = null;

const refreshSnapshot = async () => {
    try {
        const response = await fetch(route('monitoring.snapshot'), {
            method: 'GET',
            headers: {
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        if (payload && payload.snapshot && typeof payload.snapshot === 'object') {
            liveSnapshot.value = payload.snapshot;
            refreshError.value = '';
        }
    } catch (error) {
        refreshError.value = 'Live refresh failed.';
    }
};

onMounted(() => {
    refreshTimer = window.setInterval(() => {
        refreshSnapshot();
    }, 1000);
});

onBeforeUnmount(() => {
    if (refreshTimer !== null) {
        window.clearInterval(refreshTimer);
        refreshTimer = null;
    }
});
</script>

<template>
    <Head title="Monitoring" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Monitoring</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Live server and panel metrics.</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Auto refresh: every 1 second.</p>
                <p v-if="refreshError" class="text-xs text-amber-600">{{ refreshError }}</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">CPU Load</p>
                    <p class="mt-2 text-2xl font-semibold">{{ liveSnapshot.cpu_load_percent ?? 0 }}%</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Memory</p>
                    <p class="mt-2 text-2xl font-semibold">{{ liveSnapshot.memory_used_mb ?? 0 }} MB</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">of {{ liveSnapshot.memory_total_mb ?? 0 }} MB</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Disk</p>
                    <p class="mt-2 text-2xl font-semibold">{{ liveSnapshot.disk_used_gb ?? 0 }} GB</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">of {{ liveSnapshot.disk_total_gb ?? 0 }} GB</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Updated</p>
                    <p class="mt-2 text-sm font-semibold">{{ liveSnapshot.updated_at ?? '-' }}</p>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Websites</p>
                    <p class="mt-2 text-2xl font-semibold">{{ liveSnapshot.websites_total ?? 0 }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Mailboxes</p>
                    <p class="mt-2 text-2xl font-semibold">{{ liveSnapshot.mailboxes_total ?? 0 }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">DB Requests</p>
                    <p class="mt-2 text-2xl font-semibold">{{ liveSnapshot.database_requests_total ?? 0 }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Cron Jobs</p>
                    <p class="mt-2 text-2xl font-semibold">{{ liveSnapshot.cron_jobs_total ?? 0 }}</p>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Services</h2>
                    <div class="mt-3 space-y-2">
                        <div v-for="(status, service) in liveSnapshot.services || {}" :key="service" class="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2 dark:border-slate-700">
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
                                <tr v-for="proc in liveSnapshot.topProcesses || []" :key="`${proc.pid}-${proc.name}`" class="border-t border-slate-200 dark:border-slate-800">
                                    <td class="px-3 py-2">{{ proc.pid }}</td>
                                    <td class="px-3 py-2">{{ proc.name }}</td>
                                    <td class="px-3 py-2">{{ proc.cpu }}</td>
                                    <td class="px-3 py-2">{{ proc.mem }}</td>
                                </tr>
                                <tr v-if="(liveSnapshot.topProcesses || []).length === 0">
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
