<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const page = usePage();
const deleteForm = useForm({});
const panelToken = computed(() => String(page.props.panel?.token || ''));
const checkingId = ref('');
const checkStates = reactive({});

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

const checkPhpMyAdmin = async (item) => {
    checkingId.value = item.id;
    checkStates[item.id] = {
        type: 'info',
        message: 'Checking phpMyAdmin preflight...',
    };

    try {
        const response = await fetch(route('databases.phpmyadmin.check', { token: panelToken.value, id: item.id }), {
            headers: {
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        });

        const data = await response.json().catch(() => ({}));
        const databaseMessage = data?.checks?.database?.message || 'Database check unavailable.';
        const assetMessage = data?.checks?.assets?.message || 'Asset check unavailable.';
        const sessionMessage = data?.checks?.session || 'unknown';

        checkStates[item.id] = {
            type: response.ok ? 'success' : 'error',
            message: response.ok ? 'Preflight passed.' : (data?.message || 'Preflight failed.'),
            details: `Session: ${sessionMessage}. Database: ${databaseMessage} Assets: ${assetMessage}`,
        };
    } catch (error) {
        checkStates[item.id] = {
            type: 'error',
            message: 'Preflight request failed.',
            details: error?.message || 'Unknown error.',
        };
    } finally {
        checkingId.value = '';
    }
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
                                {{ item.assigned_user_name || item.assigned_user_email || 'Dpanel user' }}
                            </td>
                            <td class="px-4 py-3 font-medium">{{ item.database_name }}</td>
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
                                    <Link :href="route('databases.edit', item.id)" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        :disabled="checkingId === item.id"
                                        class="rounded-md border border-amber-300 px-2 py-1 text-xs text-amber-700 hover:bg-amber-50 disabled:opacity-50 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-900/20"
                                        @click="checkPhpMyAdmin(item)"
                                    >
                                        {{ checkingId === item.id ? 'Checking...' : 'Check' }}
                                    </button>
                                    <a
                                        :href="route('databases.phpmyadmin', { token: panelToken, id: item.id })"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="rounded-md border border-blue-300 px-2 py-1 text-xs text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20"
                                    >
                                        phpMyAdmin
                                    </a>
                                    <button
                                        :disabled="deleteForm.processing"
                                        class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-400"
                                        @click="deleteRequest(item.id)"
                                    >
                                        Delete
                                    </button>
                                    </div>
                                    <p
                                        v-if="checkStates[item.id]"
                                        class="max-w-md text-xs"
                                        :class="checkStates[item.id].type === 'success' ? 'text-emerald-600 dark:text-emerald-400' : checkStates[item.id].type === 'error' ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'"
                                    >
                                        {{ checkStates[item.id].message }}
                                        <span v-if="checkStates[item.id].details" class="block opacity-80">
                                            {{ checkStates[item.id].details }}
                                        </span>
                                    </p>
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
