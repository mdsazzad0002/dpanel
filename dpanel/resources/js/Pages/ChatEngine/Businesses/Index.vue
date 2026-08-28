<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChatEngineDocsButton from '@/Components/ChatEngineDocsButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

defineProps({
    businesses: { type: Array, default: () => [] },
});

const deleteForm = useForm({});

const remove = (business) => {
    if (!confirm(`Delete business "${business.name}"? Its products and Q&A will also be removed; assigned channels will be unassigned.`)) return;
    deleteForm.delete(panelRoute('chat-engine.businesses.destroy', { business: business.id }));
};
</script>

<template>
    <Head title="Businesses" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">Businesses</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Train the AI on your products and Q&A, then assign chat channels to a business.</p>
                </div>
                <div class="flex items-center gap-2">
                    <ChatEngineDocsButton title="Businesses — Guide" :sections="['business-training', 'business-live-data']" />
                    <Link :href="panelRoute('chat-engine.businesses.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">+ Create Business</Link>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="!businesses.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                No businesses yet.
                <Link :href="panelRoute('chat-engine.businesses.create')" class="ml-1 text-blue-600 hover:underline">Create your first business</Link>.
            </div>

            <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="b in businesses" :key="b.id" class="flex flex-col rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <div>
                        <p class="font-medium leading-tight">{{ b.name }}</p>
                        <p v-if="b.industry" class="text-xs text-slate-500 dark:text-slate-400">{{ b.industry }}</p>
                        <p v-if="b.owner" class="mt-0.5 text-xs text-slate-400">Owner: {{ b.owner.name }}</p>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-2 text-center text-sm">
                        <div class="rounded-md bg-slate-50 py-2 dark:bg-slate-900/40">
                            <p class="text-base font-semibold">{{ b.products_count }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Products</p>
                        </div>
                        <div class="rounded-md bg-slate-50 py-2 dark:bg-slate-900/40">
                            <p class="text-base font-semibold">{{ b.channels_count }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Channels</p>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-1 items-end gap-2">
                        <Link :href="panelRoute('chat-engine.businesses.edit', { business: b.id })" class="flex-1 rounded-md bg-blue-600 px-3 py-1.5 text-center text-xs font-medium text-white hover:bg-blue-700">Manage</Link>
                        <button @click="remove(b)" class="rounded-md border border-red-300 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 dark:border-red-700" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
