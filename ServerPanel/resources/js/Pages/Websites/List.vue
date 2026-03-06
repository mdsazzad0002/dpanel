<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const deleteForm = useForm({});
const search = ref('');

const props = defineProps({
    websiteRequests: {
        type: Array,
        default: () => [],
    },
});

const filteredWebsites = computed(() => {
    const needle = search.value.trim().toLowerCase();
    if (!needle) return props.websiteRequests;

    return props.websiteRequests.filter((item) => {
        const haystack = [
            item.domain,
            item.root_path,
            item.php_version,
            item.status,
            item.enable_ssl ? 'yes' : 'no',
        ]
            .map((value) => String(value ?? '').toLowerCase())
            .join(' ');

        return haystack.includes(needle);
    });
});

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};

const deleteRequest = (id) => {
    if (!confirm('Delete this website request?')) return;

    deleteForm.delete(route('websites.destroy', id));
};
</script>

<template>
    <Head title="List Websites" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">List Website Requests</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Website requests list with root path, PHP version and status.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="w-full sm:max-w-xs">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search website..."
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                </div>
                <Link :href="route('websites.create')" class="inline-flex rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    Create Website
                </Link>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Domain</th>
                            <th class="px-4 py-3">Root Path</th>
                            <th class="px-4 py-3">PHP</th>
                            <th class="px-4 py-3">SSL</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in filteredWebsites" :key="item.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3 font-medium">{{ item.domain }}</td>
                            <td class="px-4 py-3">{{ item.root_path }}</td>
                            <td class="px-4 py-3">{{ item.php_version }}</td>
                            <td class="px-4 py-3">{{ item.enable_ssl ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-700">
                                    {{ item.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ formatDate(item.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <Link :href="route('websites.manage', item.id)" class="rounded-md border border-blue-300 px-2 py-1 text-xs text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-400">
                                        Manage
                                    </Link>
                                    <Link :href="route('websites.filemanager', item.id)" class="rounded-md border border-emerald-300 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-400">
                                        File Manager
                                    </Link>
                                    <Link :href="route('websites.edit', item.id)" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
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
                        <tr v-if="filteredWebsites.length === 0">
                            <td colspan="7" class="px-4 py-6 text-center text-slate-500">No website requests generated yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
