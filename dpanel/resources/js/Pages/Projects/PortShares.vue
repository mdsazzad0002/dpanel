<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PortShareFormPanel from './components/PortShareFormPanel.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    websites: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    listening: { type: Array, default: () => [] },
});

const search = ref('');
const showAll = ref(false);
const shareCount = computed(() => props.websites.reduce((sum, w) => sum + w.shares.length, 0));
const visibleWebsites = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return props.websites
        .filter((w) => (showAll.value || needle || w.shares.length))
        .filter((w) => !needle || w.domain.toLowerCase().includes(needle))
        .sort((a, b) => (b.shares.length > 0) - (a.shares.length > 0));
});
const projectName = (id) => props.projects.find((p) => p.id === id)?.name;
const publicUrl = (website, share) => `${website.enable_ssl ? 'https' : 'http'}://${website.domain}${share.path_prefix === '/' ? '/' : share.path_prefix}`;

const panelOpen = ref(false);
const editing = ref(null);
const presetWebsite = ref('');
const openCreate = (websiteId = '') => { editing.value = null; presetWebsite.value = websiteId; panelOpen.value = true; };
const openEdit = (share) => { editing.value = share; panelOpen.value = true; };

const toggle = (share) => router.patch(panelRoute('port-shares.toggle', { share: share.id }), {}, { preserveScroll: true });
const remove = (website, share) => {
    if (!confirm(`Stop sharing ${publicUrl(website, share)}?`)) return;
    router.delete(panelRoute('port-shares.destroy', { share: share.id }), { preserveScroll: true });
};
</script>

<template>
    <Head title="Port Share" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Port Share</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Make a local port public on one of your websites through the reverse proxy.</p>
                </div>
                <button @click="openCreate()" :disabled="!websites.length" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                    <i class="bi bi-plus-lg mr-1"></i>Share a port
                </button>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="websites.length" class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <input v-model="search" type="search" placeholder="Search website…" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input v-model="showAll" type="checkbox" class="rounded border-slate-300" />
                    Show websites without shares
                </label>
                <span class="text-xs text-slate-500 dark:text-slate-400">{{ shareCount }} {{ shareCount === 1 ? 'share' : 'shares' }}</span>
            </div>

            <div v-if="!visibleWebsites.length" class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-600 dark:bg-slate-800">
                <i class="bi bi-ethernet text-3xl text-slate-400"></i>
                <p class="mt-2 text-sm font-medium">{{ websites.length ? 'Nothing shared yet' : 'No websites yet' }}</p>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ websites.length ? 'Pick a website and a path, then a project or any local port.' : 'Create a website first; its domain is where the port is published.' }}
                </p>
                <button v-if="websites.length" @click="openCreate()" class="mt-4 rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">Share a port</button>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div v-for="website in visibleWebsites" :key="website.id" class="flex flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-700">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ website.domain }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                <i class="bi" :class="website.enable_ssl ? 'bi-lock-fill text-emerald-500' : 'bi-unlock text-amber-500'"></i>
                                {{ website.enable_ssl ? 'SSL active' : 'No SSL' }} · {{ website.shares.length }} {{ website.shares.length === 1 ? 'share' : 'shares' }}
                            </p>
                        </div>
                        <button @click="openCreate(website.id)" class="shrink-0 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-medium hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-700">
                            <i class="bi bi-plus-lg"></i> Add share
                        </button>
                    </div>

                    <ul v-if="website.shares.length" class="divide-y divide-slate-100 dark:divide-slate-700">
                        <li v-for="share in website.shares" :key="share.id" class="flex items-start gap-3 px-4 py-3" :class="{ 'opacity-60': !share.enabled }">
                            <i class="bi mt-0.5 text-slate-400" :class="share.app_project_id ? 'bi-boxes' : 'bi-ethernet'"></i>
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-1 font-mono text-xs">
                                    <a :href="publicUrl(website, share)" target="_blank" rel="noopener" class="truncate text-blue-600 hover:underline dark:text-blue-400">{{ publicUrl(website, share) }}</a>
                                    <i class="bi bi-arrow-right text-slate-400"></i>
                                    <span class="text-slate-500 dark:text-slate-400">127.0.0.1:{{ share.target_port }}</span>
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    {{ share.app_project_id ? `Project ${projectName(share.app_project_id) || '#' + share.app_project_id}` : 'Local port' }}<template v-if="share.strip_prefix"> · path stripped</template>
                                </p>
                            </div>
                            <button
                                @click="toggle(share)"
                                role="switch"
                                :aria-checked="share.enabled"
                                :title="share.enabled ? 'Disable' : 'Enable'"
                                class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 rounded-full transition"
                                :class="share.enabled ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'"
                            >
                                <span class="absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition" :class="share.enabled ? 'left-[18px]' : 'left-0.5'"></span>
                            </button>
                            <button @click="openEdit(share)" title="Edit" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-700 dark:hover:text-slate-200"><i class="bi bi-pencil"></i></button>
                            <button @click="remove(website, share)" title="Delete" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"><i class="bi bi-trash"></i></button>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">Nothing shared on this website.</p>
                </div>
            </div>
        </div>

        <PortShareFormPanel
            :show="panelOpen"
            :share="editing"
            :website-id="presetWebsite"
            :websites="websites"
            :projects="projects"
            :listening="listening"
            :panel-route="panelRoute"
            @close="panelOpen = false"
        />
    </AuthenticatedLayout>
</template>
