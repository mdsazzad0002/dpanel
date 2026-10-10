<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PythonAppCard from './components/PythonAppCard.vue';
import PythonAppFormPanel from './components/PythonAppFormPanel.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

defineProps({
    projects: { type: Array, default: () => [] },
    owners: { type: Array, default: () => [] },
    versions: { type: Array, default: () => [] },
});

const panelOpen = ref(false);
const editing = ref(null);
const openCreate = () => { editing.value = null; panelOpen.value = true; };
const openEdit = (app) => { editing.value = app; panelOpen.value = true; };
</script>

<template>
    <Head title="Python Apps" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Python Apps</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Django, Flask, FastAPI… run from any folder of your home directory.</p>
                </div>
                <button @click="openCreate" :disabled="!owners.length" class="rounded-md bg-sky-600 px-3 py-2 text-sm text-white hover:bg-sky-700 disabled:opacity-50">
                    <i class="bi bi-plus-lg mr-1"></i>New Python app
                </button>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="!projects.length" class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-600 dark:bg-slate-800">
                <i class="bi bi-filetype-py text-3xl text-slate-400"></i>
                <p class="mt-2 text-sm font-medium">{{ owners.length ? 'No Python apps yet' : 'No home directory available' }}</p>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ owners.length ? 'Create one, then publish it from Rules → Port Share.' : 'Apps run inside a website user\'s home. Create a website first.' }}
                </p>
                <button v-if="owners.length" @click="openCreate" class="mt-4 rounded-md bg-sky-600 px-3 py-2 text-sm text-white hover:bg-sky-700">New Python app</button>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <PythonAppCard v-for="app in projects" :key="app.id" :app="app" :panel-route="panelRoute" @edit="openEdit" />
            </div>
        </div>

        <PythonAppFormPanel :show="panelOpen" :app="editing" :owners="owners" :versions="versions" :panel-route="panelRoute" @close="panelOpen = false" />
    </AuthenticatedLayout>
</template>
