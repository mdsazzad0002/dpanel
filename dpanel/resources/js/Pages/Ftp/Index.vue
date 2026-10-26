<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import FtpTabs from '@/Pages/Ftp/components/FtpTabs.vue';
import { securityLabel } from '@/Pages/Ftp/ftp';

const props = defineProps({
    websites: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    connection: { type: Object, default: () => ({}) },
    selectedWebsiteId: { type: String, default: '' },
});
const page = usePage();
const { panelRoute, requestJson } = usePanelApi();

const websiteIds = computed(() => new Set(props.websites.map((site) => String(site.id))));
const rows = ref([...(props.accounts || [])]);
const websiteFilter = ref(websiteIds.value.has(props.selectedWebsiteId) ? props.selectedWebsiteId : '');
const search = ref('');
// Per-account busy set, so a slow action on one account never locks the rest.
const busyIds = reactive(new Set());
const message = ref('');
const error = ref('');

const filteredRows = computed(() => {
    const term = search.value.trim().toLowerCase();
    return rows.value.filter((account) => {
        if (websiteFilter.value && String(account.website_id) !== websiteFilter.value) return false;
        if (!term) return true;
        return [account.username, account.domain, account.directory].some((value) => String(value || '').toLowerCase().includes(term));
    });
});
const accountCounts = computed(() => rows.value.reduce((counts, account) => {
    const id = String(account.website_id);
    counts[id] = (counts[id] || 0) + 1;
    return counts;
}, {}));
const siteCount = computed(() => Object.keys(accountCounts.value).length);
const formatDate = (value) => (value ? new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '');

const report = (text, failed = false) => { message.value = failed ? '' : text; error.value = failed ? text : ''; };
const remove = async (account) => {
    if (busyIds.has(account.id) || !confirm(`Delete FTP account ${account.username}? Client access will stop immediately.`)) return;
    busyIds.add(account.id); report('');
    try {
        const data = await requestJson(panelRoute('ftp.destroy', { account: account.id }), { method: 'DELETE' });
        rows.value = rows.value.filter((row) => row.id !== account.id); report(data.message);
    } catch (exception) { report(exception?.message || 'Unable to delete FTP account.', true); }
    finally { busyIds.delete(account.id); }
};
</script>

<template>
    <Head title="FTP Accounts" />
    <AuthenticatedLayout>
        <template #header>
            <div><h1 class="text-lg font-semibold">FTP Accounts</h1><p class="text-sm text-slate-500 dark:text-slate-400">Every FTP account across your websites.</p></div>
        </template>
        <div class="space-y-5">
            <FtpTabs active="accounts" />
            <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">{{ page.props.flash.success }}</div>
            <div v-if="message" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">{{ message }}</div>
            <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400">{{ error }}</div>

            <section class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                <div><p class="text-xs uppercase text-slate-500">Host</p><p class="mt-1 font-mono text-sm">{{ connection.host }}</p></div>
                <div><p class="text-xs uppercase text-slate-500">Port</p><p class="mt-1 font-mono text-sm">{{ connection.port }}</p></div>
                <div><p class="text-xs uppercase text-slate-500">Security</p><p class="mt-1 text-sm">{{ securityLabel(connection) }}</p></div>
                <div><p class="text-xs uppercase text-slate-500">Accounts</p><p class="mt-1 text-sm">{{ rows.length }} on {{ siteCount }} {{ siteCount === 1 ? 'website' : 'websites' }}</p></div>
            </section>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-col gap-3 border-b border-slate-200 p-5 dark:border-slate-800 md:flex-row md:items-center md:justify-between">
                    <h2 class="font-semibold">Accounts <span class="text-sm font-normal text-slate-500">({{ filteredRows.length }})</span></h2>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <select v-model="websiteFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950">
                            <option value="">All websites</option>
                            <option v-for="site in websites" :key="site.id" :value="site.id">{{ site.domain }} ({{ accountCounts[site.id] || 0 }})</option>
                        </select>
                        <input v-model="search" type="search" placeholder="Search username, domain or path" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950 sm:w-72" />
                        <Link :href="panelRoute('ftp.create', websiteFilter ? { website: websiteFilter } : {})" class="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"><i class="bi bi-plus-lg"></i>New account</Link>
                    </div>
                </div>
                <div v-if="!filteredRows.length" class="p-8 text-center text-sm text-slate-500">{{ rows.length ? 'No FTP accounts match this filter.' : 'No FTP accounts yet.' }}</div>
                <div v-for="account in filteredRows" :key="account.id" class="flex flex-col gap-3 border-b border-slate-200 p-5 last:border-0 dark:border-slate-800 lg:flex-row lg:items-center lg:justify-between">
                    <div class="grid min-w-0 flex-1 gap-1 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,2fr)] lg:items-center lg:gap-4">
                        <Link :href="panelRoute('ftp.show', { account: account.id })" class="truncate font-mono text-sm font-semibold hover:text-indigo-600">{{ account.username }}</Link>
                        <Link :href="panelRoute('websites.manage', { id: account.website_id })" class="inline-flex min-w-0 items-center gap-1 text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"><i class="bi bi-globe2"></i><span class="truncate">{{ account.domain || 'Unknown website' }}</span></Link>
                        <p class="break-all text-xs text-slate-500">{{ account.directory }} <span v-if="account.created_at" class="text-slate-400">· {{ formatDate(account.created_at) }}</span></p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <Link :href="panelRoute('ftp.show', { account: account.id })" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700"><i class="bi bi-gear"></i> Manage</Link>
                        <button class="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-600 disabled:opacity-50 dark:border-red-800" :disabled="busyIds.has(account.id)" @click="remove(account)">{{ busyIds.has(account.id) ? 'Deleting…' : 'Delete' }}</button>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
