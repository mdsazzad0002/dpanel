<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    role: {
        type: Object,
        required: true,
    },
    permissions: {
        type: Array,
        default: () => [],
    },
    systemRoles: {
        type: Array,
        default: () => [],
    },
    permissionPriorities: {
        type: Object,
        default: () => ({}),
    },
    assignablePermissions: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const isAdminRole = props.role.name === 'admin';

const form = useForm({
    name: props.role.name,
    permissions: isAdminRole ? [...props.permissions] : [...(props.role.permissions ?? [])],
});

const allChecked = computed(() => props.assignablePermissions.every((permission) => form.permissions.includes(permission)));
const layerGroups = computed(() => {
    return [
        { key: 'priority-1', title: 'Priority 1', priority: 1 },
        { key: 'priority-2', title: 'Priority 2', priority: 2 },
        { key: 'priority-3', title: 'Priority 3', priority: 3 },
    ]
        .map((group) => ({ ...group, permissions: props.permissions.filter((permission) => Number(props.permissionPriorities[permission] ?? 1) === group.priority) }))
        .filter((group) => group.permissions.length > 0);
});

const checkAll = () => {
    form.permissions = [...new Set([...form.permissions, ...props.assignablePermissions])];
};

const clearAll = () => {
    if (!isAdminRole) form.permissions = [];
};

const canAssign = (permission) => props.assignablePermissions.includes(permission) || form.permissions.includes(permission);

const submit = () => {
    form.patch(route('roles.manage.update', props.role.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Edit Role" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Edit Role</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Edit role permissions and save.</p>
                </div>
                <Link :href="route('roles.manage')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back To List
                </Link>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <form class="space-y-4" @submit.prevent="submit">
                    <div>
                        <InputLabel for="name" value="Role Name" />
                        <TextInput id="name" v-model="form.name" class="mt-1 block w-full cursor-not-allowed bg-slate-100 dark:bg-slate-800" readonly required />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Role name cannot be changed after creation.</p>
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <InputLabel value="Permissions" />
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Permissions are grouped in Priority 1 → Priority 2 → Priority 3 order.</p>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" class="rounded-md border border-blue-300 px-3 py-1.5 text-sm text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-950" @click="checkAll">
                                    {{ allChecked ? 'All Checked' : 'Check All' }}
                                </button>
                                <button v-if="!isAdminRole" type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="clearAll">Clear All</button>
                            </div>
                        </div>

                        <p v-if="isAdminRole" class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">Admin permissions are always checked and cannot be removed.</p>
                        <p v-else class="mt-3 rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-800 dark:bg-blue-950/40 dark:text-blue-300">You can assign any permission that your own account already has.</p>

                        <div class="mt-3 space-y-4">
                            <section v-for="group in layerGroups" :key="group.key" class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                <div class="mb-3 flex items-center justify-between">
                                    <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ group.title }}</h3>
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">Priority {{ group.priority }}</span>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    <label v-for="permission in group.permissions" :key="permission" class="flex items-center gap-2 rounded border border-slate-200 px-3 py-2 text-sm dark:border-slate-700">
                                        <input v-model="form.permissions" :value="permission" :disabled="isAdminRole || !canAssign(permission)" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-60" />
                                        <span>{{ permission }}</span>
                                    </label>
                                </div>
                            </section>
                        </div>
                        <InputError class="mt-2" :message="form.errors.permissions" />
                    </div>

                    <PrimaryButton :disabled="form.processing">Save</PrimaryButton>
                </form>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
