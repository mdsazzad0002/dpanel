<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const deleteForm = useForm({});
const panelToken = computed(() => String(page.props.panel?.token || ''));
const currentUserId = computed(() => Number(page.props.auth?.user?.id || 0));
const canOpenAllDatabases = computed(() => currentUserId.value === 1);
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

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

    deleteForm.delete(panelRoute('databases.destroy', { id }));
};

const openPhpMyAdmin = async (item) => {
    router.visit(panelRoute('phpmyadmin.index', { database: item.database_name }), {
        preserveScroll: true,
        preserveState: false,
    });
};

const openDatabaseStudio = async (item) => {
    await openPhpMyAdmin(item);
};

const openAllDatabaseStudio = () => {
    router.visit(panelRoute('phpmyadmin.index', { access: 'all' }), {
        preserveScroll: true,
        preserveState: false,
    });
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

            <div class="flex justify-end gap-2">
                <button
                    v-if="canOpenAllDatabases"
                    type="button"
                    class="rounded-md border border-cyan-300 bg-cyan-50 px-3 py-2 text-sm font-medium text-cyan-700 hover:bg-cyan-100 dark:border-cyan-700 dark:bg-cyan-950/30 dark:text-cyan-200 dark:hover:bg-cyan-950/50"
                    @click="openAllDatabaseStudio"
                >
                    All Database Access
                </button>
                <Link :href="panelRoute('databases.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    Create Database
                </Link>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Attached Website</th>
                            <th class="px-4 py-3">Owner</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">User</th>
                           <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in databaseRequests" :key="item.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-blue-100 px-2 py-1 text-xs text-blue-700">
                                    {{ item.domain || 'Not attached' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                {{ item.assigned_user_name || item.assigned_user_email || 'dPanel user' }}
                            </td>
                            <td class="px-4 py-3 font-medium">
                                <button
                                    type="button"
                                    class="text-left text-blue-700 hover:underline dark:text-blue-300"
                                    @click="openDatabaseStudio(item)"
                                >
                                    {{ item.database_name }}
                                </button>
                            </td>
                            <td class="px-4 py-3">{{ item.database_user }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-700">
                                    {{ item.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ formatDate(item.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center gap-2">
                                    <Link :href="panelRoute('databases.edit', { id: item.id })" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        class="rounded-md border border-blue-300 px-2 py-1 text-xs text-blue-700 hover:bg-blue-50 disabled:opacity-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20"
                                        @click="openPhpMyAdmin(item)"
                                    >
                                        Database Studio
                                    </button>
                                    <button
                                        :disabled="deleteForm.processing"
                                        class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-400"
                                        @click="deleteRequest(item.id)"
                                    >
                                        Delete
                                    </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="databaseRequests.length === 0">
                            <td colspan="11" class="px-4 py-6 text-center text-slate-500">No database requests found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
