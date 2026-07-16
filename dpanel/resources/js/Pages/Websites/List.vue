<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const deleteForm = useForm({});
const statusForm = useForm({ status: '' });
const search = ref('');
const statusFilter = ref('all');
const installerFilter = ref('all');
const currentPage = ref(1);
const perPage = 20;

const props = defineProps({
    websiteRequests: {
        type: Array,
        default: () => [],
    },
});

const filteredWebsites = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return props.websiteRequests.filter((item) => {
        const status = String(item.status ?? '').toLowerCase();
        const installer = String(item.app_installer ?? 'none').toLowerCase();
        if (statusFilter.value !== 'all' && status !== statusFilter.value) return false;
        if (installerFilter.value !== 'all' && installer !== installerFilter.value) return false;
        if (!needle) return true;
        const haystack = [
            item.domain, item.root_path, item.php_version, item.status,
            item.app_installer, item.created_by_label, item.assigned_reseller_name,
            item.assigned_user_name, item.enable_ssl ? 'yes' : 'no',
        ].map((v) => String(v ?? '').toLowerCase()).join(' ');
        return haystack.includes(needle);
    });
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredWebsites.value.length / perPage)));
const paginatedWebsites = computed(() => {
    const start = (currentPage.value - 1) * perPage;
    return filteredWebsites.value.slice(start, start + perPage);
});
const pageStart = computed(() => filteredWebsites.value.length === 0 ? 0 : (currentPage.value - 1) * perPage + 1);
const pageEnd = computed(() => Math.min(currentPage.value * perPage, filteredWebsites.value.length));

const stats = computed(() => {
    const all = props.websiteRequests;
    return {
        total: all.length,
        live: all.filter((w) => String(w.status ?? '').toLowerCase() === 'live').length,
        disabled: all.filter((w) => String(w.status ?? '').toLowerCase() === 'disabled').length,
        wordpress: all.filter((w) => String(w.app_installer ?? '').toLowerCase() === 'wordpress').length,
    };
});

watch([search, statusFilter, installerFilter], () => { currentPage.value = 1; });
watch(totalPages, (value) => { if (currentPage.value > value) currentPage.value = value; });

const formatDate = (value) => {
    if (!value) return '-';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    const now = new Date();
    const diffMs = now - parsed;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};

const deleteRequest = (id) => {
    if (!confirm('Delete this website?')) return;
    deleteForm.delete(panelRoute('websites.destroy', { id }));
};

const toggleStatus = (item) => {
    const current = String(item.status || '').toLowerCase();
    const next = current === 'disabled' ? 'enabled' : 'disabled';
    statusForm.status = next;
    statusForm.patch(panelRoute('websites.status.update', { id: item.id }), { preserveScroll: true });
};

const statusDot = (status) => {
    const v = String(status || '').toLowerCase();
    if (v === 'live') return 'bg-emerald-500';
    if (v === 'disabled') return 'bg-red-500';
    if (v === 'partial') return 'bg-amber-500';
    return 'bg-slate-400';
};

const statusClass = (status) => {
    const v = String(status || '').toLowerCase();
    if (v === 'live') return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
    if (v === 'disabled') return 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400';
    if (v === 'partial') return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
    return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
};

const installerLabel = (installer) => String(installer || 'none').toLowerCase() === 'wordpress' ? 'WordPress' : 'Starter';

const installerClass = (installer) => {
    const v = String(installer || 'none').toLowerCase();
    if (v === 'wordpress') return 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
    return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
};

const createdByLabel = (item) => String(item?.created_by_label || item?.assigned_reseller_name || item?.assigned_user_name || 'Admin');

const siteUrl = (item) => {
    const domain = String(item?.domain || '').trim();
    if (!domain) return '';
    return item?.enable_ssl ? `https://${domain}` : `http://${domain}`;
};

const copyUrl = (url) => {
    if (navigator.clipboard) navigator.clipboard.writeText(url);
};
</script>

<template>
    <Head title="Websites" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Websites</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Manage all your websites, domains and configurations.</p>
            </div>
        </template>

        <div class="space-y-5">
            <!-- Flash Messages -->
            <div v-if="page.props.flash?.success" class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">
                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" /></svg>
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400">
                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" /></svg>
                {{ page.props.flash.error }}
            </div>

            <!-- Stats Row -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Total</p>
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800">
                            <svg viewBox="0 0 24 24" class="h-4 w-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9" /></svg>
                        </div>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{ stats.total }}</p>
                </div>
                <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Live</p>
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-500/15">
                            <svg viewBox="0 0 24 24" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" /></svg>
                        </div>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ stats.live }}</p>
                </div>
                <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Disabled</p>
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-red-100 dark:bg-red-500/15">
                            <svg viewBox="0 0 24 24" class="h-4 w-4 text-red-600 dark:text-red-400" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z" /></svg>
                        </div>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-red-600 dark:text-red-400">{{ stats.disabled }}</p>
                </div>
                <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">WordPress</p>
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-500/15">
                            <svg viewBox="0 0 24 24" class="h-4 w-4 text-blue-600 dark:text-blue-400" fill="currentColor"><path d="M12 2C6.49 2 2 6.49 2 12s4.49 10 10 10 10-4.49 10-10S17.51 2 12 2zm-1.5 15.5v-1.1l1.4-3.3h.2l1.4 3.3V17.5h-3zm4.7-6.2c0-.5-.3-.8-.8-.8-.5 0-.8.3-.8.8 0 .5.3.8.8.8.5 0 .8-.3.8-.8zm2.8 6.2v-1l1.2-2.9c.1-.2.1-.3.1-.5 0-.6-.4-.9-1.1-.9H17l.5-2.2h2.7V11l-1.9 4.5h-1.3z" /></svg>
                        </div>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-blue-600 dark:text-blue-400">{{ stats.wordpress }}</p>
                </div>
            </div>

            <!-- Search + Filters -->
            <div class="flex flex-col gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm sm:flex-row sm:items-center dark:border-slate-800/80 dark:bg-slate-900/50">
                <div class="relative flex-1">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400">
                        <path fill-rule="evenodd" d="M8.5 3a5.5 5.5 0 104.19 9.07l3.12 3.12a.75.75 0 101.06-1.06l-3.12-3.12A5.5 5.5 0 008.5 3zm-4 5.5a4 4 0 118 0 4 4 0 01-8 0z" clip-rule="evenodd" />
                    </svg>
                    <input
                        v-model.trim="search"
                        type="text"
                        placeholder="Search by domain, path, PHP version..."
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-4 text-sm text-slate-700 placeholder:text-slate-400 focus:border-blue-400 focus:bg-white focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-blue-500 dark:focus:bg-slate-900 dark:focus:ring-blue-500/20"
                    />
                </div>
                <div class="flex items-center gap-2">
                    <select
                        v-model="statusFilter"
                        class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600 focus:border-blue-400 focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
                    >
                        <option value="all">All Status</option>
                        <option value="live">Live</option>
                        <option value="partial">Partial</option>
                        <option value="disabled">Disabled</option>
                    </select>
                    <select
                        v-model="installerFilter"
                        class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600 focus:border-blue-400 focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
                    >
                        <option value="all">All Types</option>
                        <option value="none">Starter</option>
                        <option value="wordpress">WordPress</option>
                    </select>
                    <Link
                        :href="panelRoute('websites.create')"
                        class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition hover:from-blue-500 hover:to-blue-400 hover:shadow-blue-500/30"
                    >
                        <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" /></svg>
                        Create
                    </Link>
                </div>
            </div>

            <!-- Website Cards -->
            <div class="space-y-3">
                <!-- Empty State -->
                <div v-if="paginatedWebsites.length === 0" class="flex flex-col items-center rounded-2xl border border-slate-200/80 bg-white py-16 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 dark:bg-slate-800">
                        <svg viewBox="0 0 24 24" class="h-8 w-8 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
                        </svg>
                    </div>
                    <p class="mt-4 text-sm font-medium text-slate-700 dark:text-slate-300">
                        {{ search || statusFilter !== 'all' || installerFilter !== 'all' ? 'No websites match your filters' : 'No websites yet' }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                        {{ search || statusFilter !== 'all' || installerFilter !== 'all' ? 'Try adjusting your search or filters' : 'Create your first website to get started' }}
                    </p>
                </div>

                <!-- Website Items -->
                <div
                    v-for="item in paginatedWebsites"
                    :key="item.id"
                    class="group rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm transition-all duration-150 hover:border-slate-300 hover:shadow-md dark:border-slate-800/80 dark:bg-slate-900/50 dark:hover:border-slate-700"
                >
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <!-- Left: Domain Info -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-indigo-500 text-sm font-bold text-white shadow-sm">
                                    {{ item.domain?.charAt(0)?.toUpperCase() || 'W' }}
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <Link
                                            :href="panelRoute('websites.manage', { id: item.id })"
                                            class="truncate text-[15px] font-semibold text-slate-900 transition hover:text-blue-600 dark:text-slate-100 dark:hover:text-blue-400"
                                        >
                                            {{ item.domain }}
                                        </Link>
                                        <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium" :class="statusClass(item.status)">
                                            <span class="h-1.5 w-1.5 rounded-full" :class="statusDot(item.status)"></span>
                                            {{ item.status }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium" :class="installerClass(item.app_installer)">
                                            {{ installerLabel(item.app_installer) }}
                                        </span>
                                    </div>
                                    <div class="mt-1 flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                                        <span class="inline-flex items-center gap-1">
                                            <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current opacity-50"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z" /></svg>
                                            {{ item.root_path || '-' }}
                                        </span>
                                        <span v-if="item.enable_ssl" class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                                            <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" /></svg>
                                            SSL
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Middle: Meta -->
                        <div class="flex flex-wrap items-center gap-4 text-xs text-slate-500 dark:text-slate-400">
                            <div class="flex items-center gap-1.5">
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current opacity-50"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z" /></svg>
                                PHP {{ item.php_version || '-' }}
                            </div>
                            <div class="flex items-center gap-1.5">
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current opacity-50"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" /></svg>
                                {{ createdByLabel(item) }}
                            </div>
                            <div class="flex items-center gap-1.5">
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current opacity-50"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z" /></svg>
                                {{ formatDate(item.created_at) }}
                            </div>
                        </div>

                        <!-- Right: Actions -->
                        <div class="flex items-center gap-1.5">
                            <Link
                                :href="panelRoute('websites.manage', { id: item.id })"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-[12px] font-medium text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-400 dark:hover:border-blue-700"
                            >
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 000-1.41l-2.34-2.34a1 1 0 00-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" /></svg>
                                Manage
                            </Link>
                            <Link
                                :href="panelRoute('websites.filemanager', { id: item.id })"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-[12px] font-medium text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400 dark:hover:border-emerald-700"
                            >
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M20 6h-8l-2-2H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm0 12H4V8h16v10z" /></svg>
                                Files
                            </Link>
                            <Link
                                :href="panelRoute('websites.edit', { id: item.id })"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-[12px] font-medium text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-600"
                            >
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.488.488 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z" /></svg>
                                Edit
                            </Link>
                            <button
                                type="button"
                                :disabled="statusForm.processing"
                                class="inline-flex items-center gap-1 rounded-lg border px-3 py-1.5 text-[12px] font-medium transition disabled:opacity-50"
                                :class="String(item.status || '').toLowerCase() === 'disabled'
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
                                    : 'border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400'"
                                @click="toggleStatus(item)"
                            >
                                {{ String(item.status || '').toLowerCase() === 'disabled' ? 'Enable' : 'Disable' }}
                            </button>
                            <button
                                :disabled="deleteForm.processing"
                                class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-[12px] font-medium text-red-700 transition hover:border-red-300 hover:bg-red-100 disabled:opacity-50 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400 dark:hover:border-red-700"
                                @click="deleteRequest(item.id)"
                            >
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" /></svg>
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="filteredWebsites.length > perPage" class="flex items-center justify-between rounded-2xl border border-slate-200/80 bg-white px-4 py-3 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Showing <span class="font-medium text-slate-700 dark:text-slate-300">{{ pageStart }}</span> to <span class="font-medium text-slate-700 dark:text-slate-300">{{ pageEnd }}</span> of <span class="font-medium text-slate-700 dark:text-slate-300">{{ filteredWebsites.length }}</span> websites
                </p>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-600"
                        :disabled="currentPage <= 1"
                        @click="currentPage--"
                    >
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" /></svg>
                        Prev
                    </button>
                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ currentPage }} / {{ totalPages }}</span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-600"
                        :disabled="currentPage >= totalPages"
                        @click="currentPage++"
                    >
                        Next
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" /></svg>
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
