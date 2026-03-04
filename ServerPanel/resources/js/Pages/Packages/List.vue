<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const deleteForm = useForm({});

defineProps({
    packages: {
        type: Array,
        default: () => [],
    },
});

const deletePackage = (pkg) => {
    if (pkg.subscriptions_count > 0) return;
    if (!confirm(`Delete package "${pkg.name}"?`)) return;

    deleteForm.delete(route('packages.destroy', pkg.id));
};
</script>

<template>
    <Head title="List Packages" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">List Packages</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">All package plans and resource limits.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <div class="flex justify-end">
                <Link :href="route('packages.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    Create Package
                </Link>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Price</th>
                            <th class="px-4 py-3">Duration</th>
                            <th class="px-4 py-3">Mail</th>
                            <th class="px-4 py-3">Disk MB</th>
                            <th class="px-4 py-3">DB</th>
                            <th class="px-4 py-3">Files</th>
                            <th class="px-4 py-3">Assigned</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="pkg in packages" :key="pkg.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ pkg.name }}</p>
                                <p class="text-xs text-slate-500">{{ pkg.slug }}</p>
                            </td>
                            <td class="px-4 py-3">${{ pkg.price }}</td>
                            <td class="px-4 py-3">{{ pkg.duration_days }} days</td>
                            <td class="px-4 py-3">{{ pkg.mail_accounts_limit ?? 'Unlimited' }}</td>
                            <td class="px-4 py-3">{{ pkg.disk_space_mb_limit ?? 'Unlimited' }}</td>
                            <td class="px-4 py-3">{{ pkg.databases_limit ?? 'Unlimited' }}</td>
                            <td class="px-4 py-3">{{ pkg.files_limit ?? 'Unlimited' }}</td>
                            <td class="px-4 py-3">{{ pkg.subscriptions_count }}</td>
                            <td class="px-4 py-3">
                                <span :class="pkg.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'" class="rounded-full px-2 py-1 text-xs">
                                    {{ pkg.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <Link :href="route('packages.edit', pkg.id)" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                        Edit
                                    </Link>
                                    <button
                                        :disabled="pkg.subscriptions_count > 0 || deleteForm.processing"
                                        :title="pkg.subscriptions_count > 0 ? 'Cannot delete: package is assigned to users' : 'Delete package'"
                                        class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-700 dark:text-red-400"
                                        @click="deletePackage(pkg)"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="packages.length === 0">
                            <td colspan="10" class="px-4 py-6 text-center text-slate-500">No packages found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
