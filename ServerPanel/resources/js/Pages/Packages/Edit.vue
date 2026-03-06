<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    package: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: props.package.name ?? '',
    slug: props.package.slug ?? '',
    description: props.package.description ?? '',
    price: props.package.price ?? 0,
    duration_days: props.package.duration_days ?? 30,
    is_active: !!props.package.is_active,
    mail_accounts_limit: props.package.mail_accounts_limit ?? '',
    disk_space_mb_limit: props.package.disk_space_mb_limit ?? '',
    databases_limit: props.package.databases_limit ?? '',
    files_limit: props.package.files_limit ?? '',
});

const submit = () => {
    form.patch(route('packages.update', props.package.id));
};
</script>

<template>
    <Head title="Edit Package" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Edit Package</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Update package details and resource limits.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="route('packages.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to Packages
                </Link>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm">Name</label>
                    <input v-model="form.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Slug</label>
                    <input v-model="form.slug" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.slug" class="mt-1 text-xs text-red-600">{{ form.errors.slug }}</p>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">Description</label>
                    <textarea v-model="form.description" rows="3" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Price</label>
                    <input v-model="form.price" type="number" min="0" step="0.01" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Duration (days)</label>
                    <input v-model="form.duration_days" type="number" min="1" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Mail Limit</label>
                    <input v-model="form.mail_accounts_limit" type="number" min="0" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Disk Limit (MB)</label>
                    <input v-model="form.disk_space_mb_limit" type="number" min="0" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Databases Limit</label>
                    <input v-model="form.databases_limit" type="number" min="0" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Files Limit</label>
                    <input v-model="form.files_limit" type="number" min="0" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div class="md:col-span-2 flex items-center gap-2">
                    <input id="is_active" v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                    <label for="is_active" class="text-sm">Active package</label>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        Update Package
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
