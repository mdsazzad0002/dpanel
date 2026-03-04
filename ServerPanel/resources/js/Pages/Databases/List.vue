<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const deleteForm = useForm({});

defineProps({
    databaseRequests: {
        type: Array,
        default: () => [],
    },
});

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};



const deleteRequest = (id) => {
    if (!confirm('Delete this database request?')) return;

    deleteForm.delete(route('databases.destroy', id));
};
</script>

<template>
    <Head title="List Databases" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">List Databases</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">View and manage database requests.</p>
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
                <Link :href="route('databases.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    Create Database
                </Link>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Domain</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">User</th>
                           <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in databaseRequests" :key="item.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">{{ item.domain || '-' }}</td>
                            <td class="px-4 py-3 font-medium">{{ item.database_name }}</td>
                            <td class="px-4 py-3">{{ item.database_user }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-700">
                                    {{ item.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ formatDate(item.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <Link :href="route('databases.edit', item.id)" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                        Edit
                                    </Link>
                                    <button
                                        :disabled="deleteForm.processing"
                                        class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-400"
                                        @click="deleteRequest(item.id)"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="databaseRequests.length === 0">
                            <td colspan="10" class="px-4 py-6 text-center text-slate-500">No database requests found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
