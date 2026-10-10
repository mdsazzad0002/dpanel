<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ProjectCard from './components/ProjectCard.vue';
import ProjectFormPanel from './components/ProjectFormPanel.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    projects: { type: Array, default: () => [] },
    owners: { type: Array, default: () => [] },
    nodeVersions: { type: Array, default: () => [] },
    pythonVersions: { type: Array, default: () => [] },
});

const filter = ref('all');
const visible = computed(() => props.projects.filter((p) => filter.value === 'all' || p.runtime === filter.value));

const panelOpen = ref(false);
const editing = ref(null);
const openCreate = () => { editing.value = null; panelOpen.value = true; };
const openEdit = (project) => { editing.value = project; panelOpen.value = true; };
</script>

<template>
    <Head title="Node & Python Projects" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Node &amp; Python Projects</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Apps that run from any folder of your home directory. Make one public with Port Share.</p>
                </div>
                <button @click="openCreate" :disabled="!owners.length" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                    <i class="bi bi-plus-lg mr-1"></i>New project
                </button>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="projects.length" class="flex flex-wrap gap-2">
                <button
                    v-for="option in [{ value: 'all', label: 'All' }, { value: 'node', label: 'Node.js' }, { value: 'python', label: 'Python' }]"
                    :key="option.value"
                    @click="filter = option.value"
                    class="rounded-full border px-3 py-1 text-sm"
                    :class="filter === option.value ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white dark:border-slate-600 dark:bg-slate-800'"
                >{{ option.label }}</button>
            </div>

            <div v-if="!visible.length" class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-600 dark:bg-slate-800">
                <i class="bi bi-boxes text-3xl text-slate-400"></i>
                <p class="mt-2 text-sm font-medium">{{ owners.length ? 'No projects yet' : 'No home directory available' }}</p>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ owners.length ? 'Run a Node.js or Python app from your home directory, then share its port on a website.' : 'Projects run inside a website user\'s home. Create a website first.' }}
                </p>
                <button v-if="owners.length" @click="openCreate" class="mt-4 rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">New project</button>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <ProjectCard v-for="project in visible" :key="project.id" :project="project" :panel-route="panelRoute" @edit="openEdit" />
            </div>
        </div>

        <ProjectFormPanel
            :show="panelOpen"
            :project="editing"
            :owners="owners"
            :node-versions="nodeVersions"
            :python-versions="pythonVersions"
            :panel-route="panelRoute"
            @close="panelOpen = false"
        />
    </AuthenticatedLayout>
</template>
