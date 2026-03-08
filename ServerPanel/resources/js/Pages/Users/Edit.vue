<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
    assignableRoles: {
        type: Array,
        default: () => [],
    },
    resellers: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    name: props.user.name ?? '',
    email: props.user.email ?? '',
    password: '',
    password_confirmation: '',
    role: props.user.role ?? (props.assignableRoles[0] ?? 'general'),
    reseller_id: props.user.reseller_id ?? '',
    disk_space_mb_limit: props.user.disk_space_mb_limit ?? '',
    mail_accounts_limit: props.user.mail_accounts_limit ?? '',
    databases_limit: props.user.databases_limit ?? '',
    bandwidth_gb_limit: props.user.bandwidth_gb_limit ?? '',
    websites_limit: props.user.websites_limit ?? '',
});

const submit = () => {
    form.patch(route('users.manage.update', props.user.id));
};
</script>

<template>
    <Head title="Edit User" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Edit User</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Update user profile and limits.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="route('users.manage')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to Users
                </Link>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submit">
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
                        <InputLabel for="password" value="New Password (Optional)" />
                        <TextInput id="password" type="password" v-model="form.password" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>
                    <div>
                        <InputLabel for="password_confirmation" value="Confirm Password" />
                        <TextInput id="password_confirmation" type="password" v-model="form.password_confirmation" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="role" value="Role" />
                        <select id="role" v-model="form.role" class="mt-1 block w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="role in assignableRoles" :key="role" :value="role">{{ role }}</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.role" />
                    </div>
                    <div>
                        <InputLabel for="disk_space_mb_limit" value="Disk Space Limit (MB)" />
                        <TextInput id="disk_space_mb_limit" v-model="form.disk_space_mb_limit" type="number" min="0" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.disk_space_mb_limit" />
                    </div>
                    <div>
                        <InputLabel for="mail_accounts_limit" value="Email Accounts Limit" />
                        <TextInput id="mail_accounts_limit" v-model="form.mail_accounts_limit" type="number" min="0" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.mail_accounts_limit" />
                    </div>
                    <div>
                        <InputLabel for="databases_limit" value="Databases Limit" />
                        <TextInput id="databases_limit" v-model="form.databases_limit" type="number" min="0" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.databases_limit" />
                    </div>
                    <div>
                        <InputLabel for="bandwidth_gb_limit" value="Bandwidth Limit (GB)" />
                        <TextInput id="bandwidth_gb_limit" v-model="form.bandwidth_gb_limit" type="number" min="0" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.bandwidth_gb_limit" />
                    </div>
                    <div>
                        <InputLabel for="websites_limit" value="Websites Limit" />
                        <TextInput id="websites_limit" v-model="form.websites_limit" type="number" min="0" class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="form.errors.websites_limit" />
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
                        <PrimaryButton :disabled="form.processing">Update User</PrimaryButton>
                    </div>
                </form>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
