<script setup>
import { usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;

const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const form = useForm({
    name: '',
    max_storage_mb: 1024,
    max_mailboxes: 5,
    allow_forwarding: true,
    allow_aliases: false,
    priority_support: false,
    sort_order: 0,
});

const submit = () => {
    form.post(panelRoute('mail-plans.store'));
};
</script>

<template>
    <Head title="Create Mail Plan" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Create Mail Plan</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Create a new subscription plan for mailbox accounts.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="panelRoute('mail-plans.index')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to Plans
                </Link>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">Plan Name</label>
                    <input v-model="form.name" type="text" placeholder="e.g. Basic, Pro, Enterprise" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm">Max Storage (MB)</label>
                    <input v-model.number="form.max_storage_mb" type="number" min="1" max="1048576" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p class="mt-1 text-xs text-slate-500">{{ form.max_storage_mb >= 1024 ? (form.max_storage_mb / 1024).toFixed(0) + ' GB' : form.max_storage_mb + ' MB' }}</p>
                    <p v-if="form.errors.max_storage_mb" class="mt-1 text-xs text-red-600">{{ form.errors.max_storage_mb }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm">Max Mailboxes</label>
                    <input v-model.number="form.max_mailboxes" type="number" min="1" max="99999" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.max_mailboxes" class="mt-1 text-xs text-red-600">{{ form.errors.max_mailboxes }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm">Sort Order</label>
                    <input v-model.number="form.sort_order" type="number" min="0" max="9999" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.sort_order" class="mt-1 text-xs text-red-600">{{ form.errors.sort_order }}</p>
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">Features</label>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.allow_forwarding" type="checkbox" class="rounded border-slate-300 text-blue-600 dark:border-slate-600" />
                            Allow Email Forwarding
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.allow_aliases" type="checkbox" class="rounded border-slate-300 text-blue-600 dark:border-slate-600" />
                            Allow Aliases
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.priority_support" type="checkbox" class="rounded border-slate-300 text-blue-600 dark:border-slate-600" />
                            Priority Support
                        </label>
                    </div>
                </div>

                <div class="md:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        Create Plan
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
