<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    roles: {
        type: Array,
        default: () => [],
    },
    systemRoles: {
        type: Array,
        default: () => [],
    },
    permissionGroups: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();

const isSystemRole = (name) => props.systemRoles.includes(name);

const panelOpen = ref(false);
const editingRole = ref(null);

const form = useForm({
    name: '',
    permissions: [],
});

const openCreatePanel = () => {
    editingRole.value = null;
    form.reset();
    form.clearErrors();
    panelOpen.value = true;
};

const openEditPanel = (role) => {
    editingRole.value = role;
    form.reset();
    form.clearErrors();
    form.name = role.name;
    form.permissions = [...role.permissions];
    panelOpen.value = true;
};

const closePanel = () => {
    panelOpen.value = false;
    editingRole.value = null;
};

const togglePermission = (permission) => {
    const index = form.permissions.indexOf(permission);
    if (index === -1) {
        form.permissions.push(permission);
    } else {
        form.permissions.splice(index, 1);
    }
};

const isGroupChecked = (permissions) => permissions.every((permission) => form.permissions.includes(permission));

const isGroupIndeterminate = (permissions) => !isGroupChecked(permissions) && permissions.some((permission) => form.permissions.includes(permission));

const toggleGroup = (permissions) => {
    if (isGroupChecked(permissions)) {
        form.permissions = form.permissions.filter((permission) => !permissions.includes(permission));
    } else {
        form.permissions = [...new Set([...form.permissions, ...permissions])];
    }
};

const submit = () => {
    if (editingRole.value) {
        form.patch(route('roles.manage.update', editingRole.value.id), {
            preserveScroll: true,
            onSuccess: closePanel,
        });
    } else {
        form.post(route('roles.manage.store'), {
            preserveScroll: true,
            onSuccess: closePanel,
        });
    }
};

const deleteRole = (role) => {
    if (! confirm(`Delete role "${role.name}"?`)) return;

    form.delete(route('roles.manage.destroy', role.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Manage Roles" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">Manage Roles</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Permissions come from a fixed system list; pick which apply to each role.</p>
                </div>
                <button type="button" @click="openCreatePanel" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm text-white hover:bg-indigo-500">
                    Create Role
                </button>
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
                            <td class="px-4 py-3 align-top">
                                {{ role.name }}
                                <span v-if="isSystemRole(role.name)" class="ml-2 rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-800">System</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="permission in role.permissions" :key="permission" class="rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-800">
                                        {{ permission }}
                                    </span>
                                    <span v-if="role.permissions.length === 0" class="text-xs text-slate-400">No permissions</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top">{{ role.users_count }}</td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex gap-2">
                                    <button type="button" @click="openEditPanel(role)" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                        Edit
                                    </button>
                                    <button
                                        v-if="!isSystemRole(role.name)"
                                        type="button"
                                        @click="deleteRole(role)"
                                        class="rounded-md border border-red-300 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="roles.length === 0">
                            <td colspan="4" class="px-4 py-6 text-center text-slate-500">No roles found.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <!-- Offcanvas: create/edit role -->
        <Teleport to="body">
            <div v-if="panelOpen" class="fixed inset-0 z-50 flex justify-end">
                <div class="absolute inset-0 bg-black/40" @click="closePanel"></div>

                <div class="relative flex h-full w-[70%] flex-col overflow-y-auto border-l border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                        <h2 class="text-base font-semibold">
                            {{ editingRole ? `Edit "${editingRole.name}"` : 'Create Role' }}
                        </h2>
                        <button type="button" @click="closePanel" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">✕</button>
                    </div>

                    <form @submit.prevent="submit" class="flex flex-1 flex-col overflow-y-auto">
                        <div class="flex-1 space-y-5 px-5 py-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium">Role name</label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    :disabled="editingRole && isSystemRole(editingRole.name)"
                                    class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-800 dark:disabled:bg-slate-900"
                                    placeholder="e.g. support_agent"
                                />
                                <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium">Permissions</label>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div
                                        v-for="(permissions, group) in permissionGroups"
                                        :key="group"
                                        class="rounded-lg border border-slate-200 p-3 dark:border-slate-800"
                                    >
                                        <label class="mb-1.5 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                            <input
                                                type="checkbox"
                                                :checked="isGroupChecked(permissions)"
                                                :indeterminate.prop="isGroupIndeterminate(permissions)"
                                                @change="toggleGroup(permissions)"
                                                class="rounded border-slate-300 dark:border-slate-700"
                                            />
                                            {{ group }}
                                        </label>
                                        <label
                                            v-for="permission in permissions"
                                            :key="permission"
                                            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="form.permissions.includes(permission)"
                                                @change="togglePermission(permission)"
                                                class="rounded border-slate-300 dark:border-slate-700"
                                            />
                                            {{ permission }}
                                        </label>
                                    </div>
                                </div>
                                <p v-if="form.errors.permissions" class="mt-1 text-xs text-red-600">{{ form.errors.permissions }}</p>
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-4 dark:border-slate-800">
                            <button type="button" @click="closePanel" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                Cancel
                            </button>
                            <button type="submit" :disabled="form.processing" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm text-white hover:bg-indigo-500 disabled:opacity-60">
                                Save
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
