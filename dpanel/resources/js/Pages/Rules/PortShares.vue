<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PortShareFormPanel from './PortShare/PortShareFormPanel.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    websites: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    listening: { type: Array, default: () => [] },
    canRemote: { type: Boolean, default: false },
    lbPolicies: { type: Array, default: () => ['round_robin', 'ip_hash', 'failover'] },
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

const projectOf = (share) => props.projects.find((p) => p.id === share.app_project_id);
const isRemote = (share) => share.target_type === 'remote';
const targetLabel = (share) => {
    if (isRemote(share)) {
        return share.targets.length > 1
            ? t('rules.portShares.loadBalancer', { count: share.targets.length, policy: t(`rules.portShares.form.policies.${share.lb_policy}`) })
            : t('rules.portShares.remoteServer');
    }
    const project = projectOf(share);
    return project ? t('rules.portShares.appTarget', { runtime: project.runtime === 'node' ? 'Node.js' : 'Python', name: project.name }) : t('rules.portShares.localPort');
};
const targetIcon = (share) => (isRemote(share) ? 'bi-diagram-3' : null) || ({ node: 'bi-hexagon', python: 'bi-filetype-py' }[projectOf(share)?.runtime] || 'bi-ethernet');
const publicUrl = (website, share) => `${website.enable_ssl ? 'https' : 'http'}://${website.domain}${share.path_prefix === '/' ? '/' : share.path_prefix}`;
const rootLabel = (website) => {
    const root = website.shares.find((s) => s.path_prefix === '/' && s.enabled);
    if (root) return targetLabel(root);
    return website.runtime === 'docker' ? 'Docker' : 'PHP';
};

const panelOpen = ref(false);
const editing = ref(null);
const presetWebsite = ref('');
const openCreate = (websiteId = '') => { editing.value = null; presetWebsite.value = websiteId; panelOpen.value = true; };
const openEdit = (share) => { editing.value = share; panelOpen.value = true; };

const toggleSubdomains = (website) => router.patch(
    panelRoute('rules.port-shares.subdomains', { website: website.id }),
    { enabled: !website.serve_subdomains },
    { preserveScroll: true },
);
const toggle = (share) => router.patch(panelRoute('rules.port-shares.toggle', { share: share.id }), {}, { preserveScroll: true });
const remove = (website, share) => {
    if (!confirm(t('rules.portShares.stopConfirm', { url: publicUrl(website, share) }))) return;
    router.delete(panelRoute('rules.port-shares.destroy', { share: share.id }), { preserveScroll: true });
};
</script>

<template>
    <Head :title="t('rules.portShares.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">{{ t('rules.portShares.title') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('rules.portShares.subtitle') }}</p>
                </div>
                <button @click="openCreate()" :disabled="!websites.length" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                    <i class="bi bi-plus-lg mr-1"></i>{{ t('rules.portShares.create') }}
                </button>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="websites.length" class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <input v-model="search" type="search" :placeholder="t('rules.portShares.search')" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input v-model="showAll" type="checkbox" class="rounded border-slate-300" />
                    {{ t('rules.portShares.showAll') }}
                </label>
                <span class="text-xs text-slate-500 dark:text-slate-400">{{ shareCount === 1 ? t('rules.portShares.oneShare', { count: shareCount }) : t('rules.portShares.shares', { count: shareCount }) }}</span>
            </div>

            <div v-if="!visibleWebsites.length" class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-600 dark:bg-slate-800">
                <i class="bi bi-ethernet text-3xl text-slate-400"></i>
                <p class="mt-2 text-sm font-medium">{{ websites.length ? t('rules.portShares.nothing') : t('rules.portShares.noWebsites') }}</p>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ websites.length ? t('rules.portShares.nothingHelp') : t('rules.portShares.noWebsitesHelp') }}
                </p>
                <button v-if="websites.length" @click="openCreate()" class="mt-4 rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">{{ t('rules.portShares.create') }}</button>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div v-for="website in visibleWebsites" :key="website.id" class="flex flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-700">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ website.domain }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                <i class="bi" :class="website.enable_ssl ? 'bi-lock-fill text-emerald-500' : 'bi-unlock text-amber-500'"></i>
                                {{ website.enable_ssl ? t('rules.portShares.sslActive') : t('rules.portShares.noSsl') }} · {{ t('rules.portShares.rootBy', { label: rootLabel(website) }) }}
                            </p>
                        </div>
                        <button @click="openCreate(website.id)" class="shrink-0 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-medium hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-700">
                            <i class="bi bi-plus-lg"></i> {{ t('rules.portShares.add') }}
                        </button>
                    </div>

                    <div class="flex items-start gap-3 border-b border-slate-100 bg-slate-50/60 px-4 py-2.5 dark:border-slate-700 dark:bg-slate-900/30">
                        <button
                            @click="toggleSubdomains(website)"
                            role="switch"
                            :aria-checked="website.serve_subdomains"
                            :title="t('rules.portShares.subdomains.toggle')"
                            class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 rounded-full transition"
                            :class="website.serve_subdomains ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'"
                        >
                            <span class="absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition" :class="website.serve_subdomains ? 'left-[18px]' : 'left-0.5'"></span>
                        </button>
                        <div class="min-w-0 text-xs">
                            <p class="font-medium text-slate-700 dark:text-slate-200">
                                {{ t('rules.portShares.subdomains.label') }} <code class="font-mono">*.{{ website.domain }}</code>
                            </p>
                            <p class="mt-0.5 text-slate-500 dark:text-slate-400">{{ t('rules.portShares.subdomains.help', { domain: website.domain }) }}</p>
                            <p v-if="website.serve_subdomains && !website.ssl_wildcard" class="mt-1 text-amber-600 dark:text-amber-400">
                                <i class="bi bi-exclamation-triangle"></i> {{ t('rules.portShares.subdomains.needWildcardSsl') }}
                            </p>
                        </div>
                    </div>

                    <ul v-if="website.shares.length" class="divide-y divide-slate-100 dark:divide-slate-700">
                        <li v-for="share in website.shares" :key="share.id" class="flex items-start gap-3 px-4 py-3" :class="{ 'opacity-60': !share.enabled }">
                            <i class="bi mt-0.5 text-slate-400" :class="targetIcon(share)"></i>
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-1 font-mono text-xs">
                                    <a :href="publicUrl(website, share)" target="_blank" rel="noopener" class="truncate text-blue-600 hover:underline dark:text-blue-400">{{ publicUrl(website, share) }}</a>
                                    <i class="bi bi-arrow-right text-slate-400"></i>
                                    <span v-if="isRemote(share)" class="break-all text-slate-500 dark:text-slate-400">{{ share.targets.join(', ') }}</span>
                                    <span v-else class="text-slate-500 dark:text-slate-400">127.0.0.1:{{ share.target_port }}</span>
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    {{ targetLabel(share) }}<template v-if="projectOf(share)?.status === 'stopped'"> · <span class="text-amber-600">{{ t('rules.portShares.appStopped') }}</span></template><template v-if="share.strip_prefix"> · {{ t('rules.portShares.stripped') }}</template>
                                </p>
                            </div>
                            <button
                                @click="toggle(share)"
                                role="switch"
                                :aria-checked="share.enabled"
                                :title="share.enabled ? t('rules.portShares.disable') : t('rules.portShares.enable')"
                                class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 rounded-full transition"
                                :class="share.enabled ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'"
                            >
                                <span class="absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition" :class="share.enabled ? 'left-[18px]' : 'left-0.5'"></span>
                            </button>
                            <button @click="openEdit(share)" :title="t('rules.portShares.edit')" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-700 dark:hover:text-slate-200"><i class="bi bi-pencil"></i></button>
                            <button @click="remove(website, share)" :title="t('rules.portShares.delete')" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"><i class="bi bi-trash"></i></button>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('rules.portShares.noneShared', { label: rootLabel(website) }) }}</p>
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
            :can-remote="canRemote"
            :lb-policies="lbPolicies"
            :panel-route="panelRoute"
            @close="panelOpen = false"
        />
    </AuthenticatedLayout>
</template>
