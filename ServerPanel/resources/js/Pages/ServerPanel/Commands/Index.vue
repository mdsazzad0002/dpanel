<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    jobs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    servers: { type: Array, default: () => [] },
});

const filterForm = useForm({
    status: props.filters.status || '',
    risk: props.filters.risk || '',
    server_id: props.filters.server_id || '',
    q: props.filters.q || '',
});

const applyFilters = () => {
    router.get(route('commands.index'), filterForm.data(), { preserveState: true, preserveScroll: true });
};
</script>

<template>
    <Head title="Command History" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-lg font-semibold">Command History</h1></template>

        <div class="space-y-4">
            <form class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-5" @submit.prevent="applyFilters">
                <input v-model="filterForm.q" type="text" class="rounded border border-slate-300 px-3 py-2 text-sm" placeholder="Search command" />
                <select v-model="filterForm.status" class="rounded border border-slate-300 px-3 py-2 text-sm"><option value="">All Status</option><option>pending_approval</option><option>queued</option><option>running</option><option>success</option><option>failed</option><option>blocked</option></select>
                <select v-model="filterForm.risk" class="rounded border border-slate-300 px-3 py-2 text-sm"><option value="">All Risk</option><option>safe</option><option>approval_required</option><option>blocked</option></select>
                <select v-model="filterForm.server_id" class="rounded border border-slate-300 px-3 py-2 text-sm"><option value="">All Servers</option><option v-for="server in servers" :key="server.id" :value="server.id">{{ server.name }}</option></select>
                <button class="rounded bg-cyan-700 px-3 py-2 text-sm font-semibold text-white">Filter</button>
            </form>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full text-xs">
                    <thead class="bg-slate-50"><tr><th class="px-3 py-2 text-left">Command</th><th class="px-3 py-2 text-left">Server</th><th class="px-3 py-2 text-left">Risk</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Requested By</th><th class="px-3 py-2 text-left">Approved By</th><th class="px-3 py-2 text-left">Started</th><th class="px-3 py-2 text-left">Duration</th></tr></thead>
                    <tbody>
                        <tr v-for="job in jobs.data" :key="job.id" class="border-t border-slate-200">
                            <td class="px-3 py-2"><Link :href="route('commands.show', job.id)" class="font-mono text-cyan-700">{{ job.command }}</Link></td>
                            <td class="px-3 py-2">{{ job.server?.name }}</td>
                            <td class="px-3 py-2">{{ job.risk_level }}</td>
                            <td class="px-3 py-2">{{ job.status }}</td>
                            <td class="px-3 py-2">{{ job.requested_by?.email || '-' }}</td>
                            <td class="px-3 py-2">{{ job.approved_by?.email || '-' }}</td>
                            <td class="px-3 py-2">{{ job.started_at || '-' }}</td>
                            <td class="px-3 py-2">{{ job.started_at && job.finished_at ? 'completed' : '-' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
