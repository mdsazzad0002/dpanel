<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    roles: {
        type: Array,
        default: () => [],
    },
    systemRoles: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();

const isSystemRole = (name) => props.systemRoles.includes(name);
</script>

<template>
    <Head title="Manage Roles" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <div>
                    <h1 class="text-lg font-semibold">Manage Roles</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Role list only.</p>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Role</th>
                            <th class="px-4 py-3">Permissions</th>
                            <th class="px-4 py-3">Users</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="role in roles" :key="role.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">
                                {{ role.name }}
                                <span v-if="isSystemRole(role.name)" class="ml-2 rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-800">System</span>
                            </td>
                            <td class="px-4 py-3">{{ role.permissions.length }}</td>
                            <td class="px-4 py-3">{{ role.users_count }}</td>
                            <td class="px-4 py-3">
                                <Link :href="route('roles.manage.edit', role.id)" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                    Edit
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="roles.length === 0">
                            <td colspan="4" class="px-4 py-6 text-center text-slate-500">No roles found.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
