<script setup>
import { usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;

const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    plan: {
        type: Object,
        required: true,
    },
    mailboxCount: {
        type: Number,
        default: 0,
    },
    totalStorageMb: {
        type: Number,
        default: 0,
    },
});

const form = useForm({
    name: props.plan.name ?? '',
    max_storage_mb: Number(props.plan.max_storage_mb ?? 1024),
    max_mailboxes: Number(props.plan.max_mailboxes ?? 5),
    allow_forwarding: props.plan.allow_forwarding ?? true,
    allow_aliases: props.plan.allow_aliases ?? false,
    priority_support: props.plan.priority_support ?? false,
    sort_order: Number(props.plan.sort_order ?? 0),
});

const submit = () => {
    form.patch(panelRoute('mail-plans.update', { id: props.plan.id }));
};

const formatStorage = (mb) => {
    if (mb >= 1024000) return `${(mb / 1024000).toFixed(1)} TB`;
    if (mb >= 1024) return `${(mb / 1024).toFixed(0)} GB`;
    return `${mb} MB`;
};
</script>

<template>
    <Head :title="`Edit Plan - ${plan.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Edit Plan: {{ plan.name }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Update plan details and feature limits.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="panelRoute('mail-plans.index')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to Plans
                </Link>
            </div>

            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Current Usage</h2>
                <div class="mt-2 flex gap-6 text-sm">
                    <div>
                        <span class="text-slate-600 dark:text-slate-300">Mailboxes:</span>
                        <span class="ml-1 font-medium">{{ mailboxCount }} / {{ plan.max_mailboxes >= 9999 ? 'unlimited' : plan.max_mailboxes }}</span>
                    </div>
                    <div>
                        <span class="text-slate-600 dark:text-slate-300">Storage Used:</span>
                        <span class="ml-1 font-medium">{{ formatStorage(totalStorageMb) }} / {{ formatStorage(plan.max_storage_mb) }}</span>
                    </div>
                </div>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">Plan Name</label>
                    <input v-model="form.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
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
                        Update Plan
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
