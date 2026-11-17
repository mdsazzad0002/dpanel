<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
    if (!confirm(t('packagePlans.list.deleteConfirm'))) return;
    deleteForm.delete(panelRoute('packages.destroy', { id }));
};

const formatStorage = (mb) => {
    if (mb >= 1024000) return `${(mb / 1024000).toFixed(1)} TB`;
    if (mb >= 1024) return `${(mb / 1024).toFixed(0)} GB`;
    return `${mb} MB`;
};

const formatMailboxes = (count) => {
    return count >= 9999 ? t('packagePlans.list.unlimited') : count;
};
</script>

<template>
    <Head :title="t('packagePlans.list.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('packagePlans.list.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('packagePlans.list.subtitle') }}</p>
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
                    {{ t('packagePlans.list.manageUsers') }}
                </Link>
                <Link :href="panelRoute('packages.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    {{ t('packagePlans.list.create') }}
                </Link>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">{{ t('packagePlans.list.th.package') }}</th>
                            <th class="px-4 py-3">{{ t('packagePlans.list.th.owner') }}</th>
                            <th class="px-4 py-3">{{ t('packagePlans.list.th.storage') }}</th>
                            <th class="px-4 py-3">{{ t('packagePlans.list.th.mailboxes') }}</th>
                            <th class="px-4 py-3">{{ t('packagePlans.list.th.features') }}</th>
                            <th class="px-4 py-3">{{ t('packagePlans.list.th.usage') }}</th>
                            <th class="px-4 py-3">{{ t('packagePlans.list.th.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="plan in plans" :key="plan.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ plan.name }}</div>
                                <div class="text-xs text-slate-500">{{ plan.slug }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm">{{ plan.owner?.name ?? t('packagePlans.list.admin') }}</div>
                                <div v-if="plan.owner?.email" class="text-xs text-slate-500">{{ plan.owner.email }}</div>
                            </td>
                            <td class="px-4 py-3">{{ formatStorage(plan.max_storage_mb) }}</td>
                            <td class="px-4 py-3 text-xs">
                                <div>{{ t('packagePlans.list.nMailboxes', { n: formatMailboxes(plan.max_mailboxes) }) }}</div>
                                <div>{{ t('packagePlans.list.sitesDbs', { sites: plan.max_websites, dbs: plan.max_databases }) }}</div>
                                <div>{{ t('packagePlans.list.bandwidth', { n: plan.max_bandwidth_gb }) }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <span v-if="plan.allow_forwarding" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">{{ t('packagePlans.list.forwarding') }}</span>
                                    <span v-if="plan.allow_aliases" class="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-700">{{ t('packagePlans.list.aliases') }}</span>
                                    <span v-if="plan.priority_support" class="rounded-full bg-violet-100 px-2 py-0.5 text-xs text-violet-700">{{ t('packagePlans.list.priority') }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm">{{ t('packagePlans.list.nMailboxes', { n: plan.mailbox_count }) }}</div>
                                <div class="text-xs text-slate-500">{{ t('packagePlans.list.used', { size: formatStorage(plan.total_storage_mb) }) }}</div>
                                <div class="text-xs text-slate-500">{{ t('packagePlans.list.assigned', { n: plan.assigned_users_count }) }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <Link v-if="plan.can_manage" :href="panelRoute('packages.edit', { id: plan.id })" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                        {{ t('packagePlans.list.edit') }}
                                    </Link>
                                    <button
                                        v-if="plan.can_manage"
                                        :disabled="deleteForm.processing || plan.mailbox_count > 0 || plan.assigned_users_count > 0"
                                        class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-400"
                                        @click="deletePlan(plan.id)"
                                    >
                                        {{ t('packagePlans.list.delete') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="plans.length === 0">
                            <td colspan="7" class="px-4 py-6 text-center text-slate-500">{{ t('packagePlans.list.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
