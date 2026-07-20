<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    permissions: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();

const form = useForm({
    name: '',
    permissions: [],
});

const submit = () => {
    form.post(route('roles.manage.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Create Role" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Create Role</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Add a new role and assign permissions.</p>
                </div>
                <Link :href="route('roles.manage')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Manage Roles
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
                        <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div>
                        <InputLabel value="Permissions" />
                        <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <label
                                v-for="permission in permissions"
                                :key="permission"
                                class="flex items-center gap-2 rounded border border-slate-200 px-3 py-2 text-sm dark:border-slate-700"
                            >
                                <input v-model="form.permissions" :value="permission" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" />
                                <span>{{ permission }}</span>
                            </label>
                        </div>
                        <InputError class="mt-2" :message="form.errors.permissions" />
                    </div>

                    <PrimaryButton :disabled="form.processing">Create Role</PrimaryButton>
                </form>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
