<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const form = useForm({
    name: '',
    industry: '',
    description: '',
});

const submit = () => {
    form.post(panelRoute('chat-engine.businesses.store'));
};
</script>

<template>
    <Head title="Create Business" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Create Business</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">After creating, add products and Q&A, then assign chat channels to it.</p>
            </div>
        </template>

        <div class="max-w-2xl">
            <div class="rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-800">
                <form @submit.prevent="submit" class="space-y-4 text-slate-800 dark:text-slate-100">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Business name</label>
                        <input v-model="form.name" type="text" class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" placeholder="e.g. Acme Bakery" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Industry (optional)</label>
                        <input v-model="form.industry" type="text" class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" placeholder="e.g. Bakery, SaaS, Real Estate" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Description (optional)</label>
                        <textarea v-model="form.description" rows="3" class="w-full rounded-md border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" placeholder="A short description of the business, for your own reference."></textarea>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">Create Business</button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
