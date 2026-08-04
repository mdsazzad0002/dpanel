<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const deleteForm = useForm({});
const panelToken = page.props.panel?.token;

const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

defineProps({
    plans: {
        type: Array,
        default: () => [],
    },
});

const deletePlan = (id) => {
    if (!confirm('Delete this package? It must not have assigned users or mailboxes.')) return;
    deleteForm.delete(panelRoute('packages.destroy', { id }));
};

const formatStorage = (mb) => {
    if (mb >= 1024000) return `${(mb / 1024000).toFixed(1)} TB`;
    if (mb >= 1024) return `${(mb / 1024).toFixed(0)} GB`;
    return `${mb} MB`;
};

const formatMailboxes = (count) => {
    return count >= 9999 ? 'Unlimited' : count;
};
</script>

<template>
    <Head title="Resource Packages" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Resource Packages</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Manage hard quotas assigned to domain users.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <div class="flex items-center justify-between">
                <Link :href="panelRoute('users.manage')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Manage Users
                </Link>
                <Link :href="panelRoute('packages.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    Create Package
                </Link>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Package</th>
                            <th class="px-4 py-3">Owner</th>
                            <th class="px-4 py-3">Storage</th>
                            <th class="px-4 py-3">Mailboxes</th>
                            <th class="px-4 py-3">Features</th>
                            <th class="px-4 py-3">Usage</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="plan in plans" :key="plan.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ plan.name }}</div>
                                <div class="text-xs text-slate-500">{{ plan.slug }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm">{{ plan.owner?.name ?? 'Admin' }}</div>
                                <div v-if="plan.owner?.email" class="text-xs text-slate-500">{{ plan.owner.email }}</div>
                            </td>
                            <td class="px-4 py-3">{{ formatStorage(plan.max_storage_mb) }}</td>
                            <td class="px-4 py-3 text-xs">
                                <div>{{ formatMailboxes(plan.max_mailboxes) }} mailboxes</div>
                                <div>{{ plan.max_websites }} websites · {{ plan.max_databases }} databases</div>
                                <div>{{ plan.max_bandwidth_gb }} GB bandwidth</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <span v-if="plan.allow_forwarding" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">Forwarding</span>
                                    <span v-if="plan.allow_aliases" class="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-700">Aliases</span>
                                    <span v-if="plan.priority_support" class="rounded-full bg-violet-100 px-2 py-0.5 text-xs text-violet-700">Priority Support</span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm">{{ plan.mailbox_count }} mailboxes</div>
                                <div class="text-xs text-slate-500">{{ formatStorage(plan.total_storage_mb) }} used</div>
                                <div class="text-xs text-slate-500">{{ plan.assigned_users_count }} assigned users</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <Link v-if="plan.can_manage" :href="panelRoute('packages.edit', { id: plan.id })" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                        Edit
                                    </Link>
                                    <button
                                        v-if="plan.can_manage"
                                        :disabled="deleteForm.processing || plan.mailbox_count > 0 || plan.assigned_users_count > 0"
                                        class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-400"
                                        @click="deletePlan(plan.id)"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="plans.length === 0">
                            <td colspan="7" class="px-4 py-6 text-center text-slate-500">No packages found. Create one to get started.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
