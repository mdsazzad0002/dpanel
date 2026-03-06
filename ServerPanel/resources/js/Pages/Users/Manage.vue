<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    users: {
        type: Array,
        default: () => [],
    },
    assignableRoles: {
        type: Array,
        default: () => [],
    },
    packages: {
        type: Array,
        default: () => [],
    },
    resellers: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: props.assignableRoles.includes('admin') ? 'admin' : (props.assignableRoles[0] ?? 'general_user'),
    package_id: '',
    reseller_id: '',
});

const submit = () => {
    form.post(route('users.manage.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('name', 'email', 'password', 'password_confirmation'),
    });
};

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};
</script>

<template>
    <Head title="Manage Users" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Manage Users</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Create users with role and package assignment.</p>
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
                <h2 class="text-base font-semibold">Create User</h2>
                <form class="mt-4 grid gap-4 md:grid-cols-2" @submit.prevent="submit">
                    <div>
                        <InputLabel for="name" value="Name" />
                        <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput id="email" type="email" v-model="form.email" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div>
                        <InputLabel for="password" value="Password" />
                        <TextInput id="password" type="password" v-model="form.password" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>

                    <div>
                        <InputLabel for="password_confirmation" value="Confirm Password" />
                        <TextInput id="password_confirmation" type="password" v-model="form.password_confirmation" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.password_confirmation" />
                    </div>

                    <div>
                        <InputLabel for="role" value="Role" />
                        <select id="role" v-model="form.role" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="role in assignableRoles" :key="role" :value="role">{{ role }}</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.role" />
                    </div>

                    <div>
                        <InputLabel for="package_id" value="Package" />
                        <select id="package_id" v-model="form.package_id" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800">
                            <option value="">No package</option>
                            <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">
                                {{ pkg.name }} ({{ pkg.slug }})
                            </option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.package_id" />
                    </div>

                    <div v-if="form.role !== 'reseller'" class="md:col-span-2">
                        <InputLabel for="reseller_id" value="Reseller (Optional)" />
                        <select id="reseller_id" v-model="form.reseller_id" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800">
                            <option value="">None</option>
                            <option v-for="reseller in resellers" :key="reseller.id" :value="reseller.id">
                                {{ reseller.name }} ({{ reseller.email }})
                            </option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.reseller_id" />
                    </div>

                    <div class="md:col-span-2">
                        <PrimaryButton :disabled="form.processing">Create User</PrimaryButton>
                    </div>
                </form>
            </section>

            <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Role</th>
                            <th class="px-4 py-3">Package</th>
                            <th class="px-4 py-3">Reseller</th>
                            <th class="px-4 py-3">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users" :key="user.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">{{ user.name }}</td>
                            <td class="px-4 py-3">{{ user.email }}</td>
                            <td class="px-4 py-3">{{ user.roles.join(', ') || '-' }}</td>
                            <td class="px-4 py-3">{{ user.package?.name ?? '-' }}</td>
                            <td class="px-4 py-3">{{ user.reseller?.name ?? '-' }}</td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ formatDate(user.created_at) }}</td>
                        </tr>
                        <tr v-if="users.length === 0">
                            <td colspan="6" class="px-4 py-6 text-center text-slate-500">No users found.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
