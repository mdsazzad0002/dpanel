<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
});

const stats = reactive({}); // account id -> { categories, total_items, total_size, updated_at, loading, error }
const busy = reactive({}); // account id -> category key being cleared
const notice = ref('');
const noticeType = ref('success');
const refreshingAll = ref(false);
let noticeTimer = null;

const formatSize = (bytes) => {
    if (bytes === null || bytes === undefined) return '—';
    const value = Number(bytes || 0);
    if (value < 1024) return `${value} B`;
    if (value < 1024 ** 2) return `${(value / 1024).toFixed(1)} KB`;
    if (value < 1024 ** 3) return `${(value / 1024 ** 2).toFixed(1)} MB`;
    return `${(value / 1024 ** 3).toFixed(2)} GB`;
};

const flash = (message, type = 'success') => {
    notice.value = message;
    noticeType.value = type;
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => { notice.value = ''; }, 4000);
};

const headers = () => ({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
});

const applyStats = (id, payload) => {
    stats[id] = {
        categories: payload.categories || {},
        total_items: Number(payload.total_items || 0),
        total_size: Number(payload.total_size || 0),
        updated_at: payload.updated_at || null,
        loading: false,
        error: '',
    };
};

// Refreshes one account only.
const loadStats = async (account) => {
    const current = stats[account.id] || {};
    stats[account.id] = { ...current, loading: true, error: '' };
    try {
        const response = await fetch(route('web-trash.stats', { id: account.id }), { headers: headers(), credentials: 'same-origin' });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || `HTTP ${response.status}`);
        applyStats(account.id, payload);
    } catch (error) {
        stats[account.id] = { ...current, loading: false, error: error?.message || 'Failed to read folders.' };
    }
};

const refreshAll = async () => {
    if (refreshingAll.value) return;
    refreshingAll.value = true;
    // Small concurrency cap so many accounts don't flood the filemanager API.
    const queue = [...props.accounts];
    const worker = async () => {
        while (queue.length) await loadStats(queue.shift());
    };
    await Promise.all(Array.from({ length: Math.min(4, queue.length) }, worker));
    refreshingAll.value = false;
};

const categoryLabel = (key) => (key === 'all' ? 'all folders' : props.categories.find((c) => c.key === key)?.label || key);

const amountOf = (account, key) => {
    const s = stats[account.id];
    return {
        size: key === 'all' ? s?.total_size : s?.categories?.[key]?.size,
        items: key === 'all' ? s?.total_items : s?.categories?.[key]?.items,
    };
};

const clearFolder = async (account, key) => {
    const { size, items } = amountOf(account, key);
    if (!confirm(`Permanently delete ${items ?? 'all'} item(s) (${formatSize(size)}) from ${categoryLabel(key)} of ${account.domain}? This cannot be undone.`)) return;
    await runClear(account, key, true);
};

// Filters: user, package and a free-text account/domain search.
const NONE = '__none__';
const filterUser = ref('');
const filterPackage = ref('');
const search = ref('');
const optionsFrom = (idKey, nameKey) => {
    const map = new Map();
    let hasNone = false;
    props.accounts.forEach((a) => {
        if (a[idKey]) map.set(a[idKey], a[nameKey] || a[idKey]);
        else hasNone = true;
    });
    const list = [...map].map(([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label));
    return { list, hasNone };
};
const userOptions = computed(() => optionsFrom('user_id', 'user_name'));
const packageOptions = computed(() => optionsFrom('package_id', 'package_name'));
const matches = (value, filter) => filter === '' || (filter === NONE ? !value : value === filter);
const filteredAccounts = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.accounts.filter((a) => matches(a.user_id, filterUser.value)
        && matches(a.package_id, filterPackage.value)
        && (q === '' || a.site_owner.toLowerCase().includes(q) || a.domains.some((d) => d.toLowerCase().includes(q))));
});
const filtersActive = computed(() => Boolean(filterUser.value || filterPackage.value || search.value.trim()));
const resetFilters = () => { filterUser.value = ''; filterPackage.value = ''; search.value = ''; };

// Bulk: tick rows, pick a group, clear them one by one (each row updates as it finishes).
const selected = ref([]);
const bulkCategory = ref('all');
const bulkRunning = ref(false);
const bulkProgress = ref({ done: 0, total: 0 });
// Never keep rows selected that the current filter hides.
watch(filteredAccounts, (rows) => {
    const visible = new Set(rows.map((a) => a.id));
    selected.value = selected.value.filter((id) => visible.has(id));
});
const allSelected = computed(() => filteredAccounts.value.length > 0 && selected.value.length === filteredAccounts.value.length);
const toggleAll = () => {
    selected.value = allSelected.value ? [] : filteredAccounts.value.map((a) => a.id);
};
const bulkAmount = computed(() => props.accounts
    .filter((a) => selected.value.includes(a.id))
    .reduce((sum, a) => {
        const { size, items } = amountOf(a, bulkCategory.value);
        sum.size += Number(size || 0);
        sum.items += Number(items || 0);
        return sum;
    }, { size: 0, items: 0 }));

const bulkClear = async () => {
    const targets = props.accounts.filter((a) => selected.value.includes(a.id) && Number(amountOf(a, bulkCategory.value).items || 0) > 0);
    if (targets.length === 0) {
        flash('Nothing to clear in the selected accounts.', 'error');
        return;
    }
    if (!confirm(`Permanently delete ${bulkAmount.value.items} item(s) (${formatSize(bulkAmount.value.size)}) from ${categoryLabel(bulkCategory.value)} of ${targets.length} account(s)? This cannot be undone.`)) return;

    bulkRunning.value = true;
    bulkProgress.value = { done: 0, total: targets.length };
    let failed = 0;
    for (const account of targets) {
        if (!(await runClear(account, bulkCategory.value, false))) failed += 1;
        bulkProgress.value.done += 1;
    }
    bulkRunning.value = false;
    selected.value = [];
    flash(failed ? `Cleared ${targets.length - failed} account(s), ${failed} failed.` : `Cleared ${targets.length} account(s).`, failed ? 'error' : 'success');
};

const runClear = async (account, key, notify) => {
    busy[account.id] = key;
    try {
        const response = await fetch(route('web-trash.clear', { id: account.id }), {
            method: 'DELETE',
            headers: headers(),
            credentials: 'same-origin',
            body: JSON.stringify({ category: key }),
        });
        const payload = await response.json().catch(() => ({}));
        if (payload.categories) applyStats(account.id, payload); // only this row updates
        if (!response.ok) throw new Error(payload.message || `Clear failed with HTTP ${response.status}.`);
        if (notify) flash(`${account.domain}: ${payload.message || 'Cleared.'}`);
        return true;
    } catch (error) {
        if (notify) flash(error?.message || 'Clear failed.', 'error');
        else if (stats[account.id]) stats[account.id].error = error?.message || 'Clear failed.';
        return false;
    } finally {
        delete busy[account.id];
    }
};

const totals = computed(() => {
    const sum = { items: 0, size: 0, byCategory: {} };
    props.categories.forEach((c) => { sum.byCategory[c.key] = 0; });
    filteredAccounts.value.forEach((account) => {
        const s = stats[account.id];
        if (!s?.categories) return;
        sum.items += s.total_items;
        sum.size += s.total_size;
        props.categories.forEach((c) => { sum.byCategory[c.key] += Number(s.categories[c.key]?.size || 0); });
    });
    return sum;
});

// Table sorting: click a header to sort, click again to flip asc/desc.
const sortKey = ref('total');
const sortDir = ref('desc');
const setSort = (key) => {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDir.value = key === 'domain' ? 'asc' : 'desc';
    }
};
const sortIcon = (key) => (sortKey.value !== key ? 'bi bi-arrow-down-up opacity-30' : sortDir.value === 'asc' ? 'bi bi-sort-up' : 'bi bi-sort-down');
const sortValue = (account) => {
    if (sortKey.value === 'domain') return account.domain;
    const s = stats[account.id];
    if (!s?.categories) return -1; // not loaded yet: always lowest
    if (sortKey.value === 'total') return s.total_size;
    return Number(s.categories[sortKey.value]?.size || 0);
};
const sortedAccounts = computed(() => {
    const dir = sortDir.value === 'asc' ? 1 : -1;
    return [...filteredAccounts.value].sort((a, b) => {
        const av = sortValue(a);
        const bv = sortValue(b);
        const result = typeof av === 'string' ? av.localeCompare(bv) : av - bv;
        return (result || a.domain.localeCompare(b.domain)) * dir;
    });
});

onMounted(refreshAll);
onBeforeUnmount(() => clearTimeout(noticeTimer));
</script>

<template>
    <Head title="Web Trash" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-lg font-semibold">Web Trash</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Trash, leftover migration uploads and caches the panel creates in each account home.</p>
                </div>
                <button type="button" :disabled="refreshingAll" class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 disabled:opacity-60 dark:bg-white dark:text-slate-900" @click="refreshAll">
                    <i class="bi bi-arrow-clockwise" :class="refreshingAll ? 'animate-spin' : ''"></i>
                    {{ refreshingAll ? 'Refreshing...' : 'Refresh All' }}
                </button>
            </div>
        </template>

        <div v-if="notice" class="fixed bottom-5 right-5 z-50 max-w-sm rounded-lg border px-4 py-3 text-sm font-medium shadow-lg" :class="noticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'">
            {{ notice }}
        </div>

        <div class="space-y-5">
            <section class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:flex-wrap sm:items-end dark:border-slate-800 dark:bg-slate-900">
                <div class="min-w-[200px] flex-1">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Search</label>
                    <input v-model="search" type="search" placeholder="Domain or account..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div class="sm:w-56">
                    <label class="mb-1 block text-xs font-medium text-slate-500">User</label>
                    <select v-model="filterUser" class="w-full rounded-lg border border-slate-300 py-2 pl-3 pr-8 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">All users</option>
                        <option v-if="userOptions.hasNone" :value="NONE">Unassigned</option>
                        <option v-for="option in userOptions.list" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
                <div class="sm:w-56">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Package</label>
                    <select v-model="filterPackage" class="w-full rounded-lg border border-slate-300 py-2 pl-3 pr-8 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">All packages</option>
                        <option v-if="packageOptions.hasNone" :value="NONE">No package</option>
                        <option v-for="option in packageOptions.list" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
                <div class="flex items-center gap-3 text-sm text-slate-500">
                    <span>{{ filteredAccounts.length }} / {{ accounts.length }} account(s)</span>
                    <button v-if="filtersActive" type="button" class="font-medium text-red-600 hover:underline" @click="resetFilters">Reset</button>
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Reclaimable</p>
                    <p class="mt-2 text-2xl font-semibold">{{ formatSize(totals.size) }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ totals.items }} item(s)</p>
                </div>
                <div v-for="category in categories" :key="category.key" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" :title="category.hint">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ category.label }}</p>
                    <p class="mt-2 text-2xl font-semibold">{{ formatSize(totals.byCategory[category.key]) }}</p>
                    <p class="mt-1 truncate text-xs text-slate-400">{{ category.paths.join(', ') }}</p>
                </div>
            </section>

            <div v-if="selected.length" class="flex flex-col gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-red-900 dark:bg-red-950/40">
                <p class="text-sm font-medium text-red-700 dark:text-red-300">
                    {{ selected.length }} account(s) selected · {{ bulkAmount.items }} item(s) · {{ formatSize(bulkAmount.size) }}
                    <span v-if="bulkRunning"> · clearing {{ bulkProgress.done }}/{{ bulkProgress.total }}</span>
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <select v-model="bulkCategory" :disabled="bulkRunning" class="rounded-lg border border-slate-300 py-2 pl-3 pr-8 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="all">All folders</option>
                        <option v-for="category in categories" :key="category.key" :value="category.key">{{ category.label }}</option>
                    </select>
                    <button type="button" :disabled="bulkRunning" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60" @click="bulkClear">
                        <i :class="bulkRunning ? 'bi bi-arrow-repeat animate-spin' : 'bi bi-x-octagon'"></i>
                        {{ bulkRunning ? 'Clearing...' : 'Clear Selected' }}
                    </button>
                    <button type="button" :disabled="bulkRunning" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-white disabled:opacity-60 dark:text-slate-300 dark:hover:bg-slate-800" @click="selected = []">Cancel</button>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div v-if="accounts.length === 0" class="px-6 py-14 text-center">
                    <i class="bi bi-trash2 text-3xl text-slate-300"></i>
                    <p class="mt-3 font-medium">No websites</p>
                    <p class="mt-1 text-sm text-slate-500">Accounts appear here once you have hosted websites.</p>
                </div>
                <table v-else class="w-full min-w-[860px] text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr>
                            <th class="w-10 py-3 pl-4">
                                <input type="checkbox" :checked="allSelected" :indeterminate="selected.length > 0 && !allSelected" :disabled="bulkRunning" class="rounded border-slate-300 text-red-600 focus:ring-red-500" title="Select all" @change="toggleAll" />
                            </th>
                            <th class="px-4 py-3">
                                <button type="button" class="inline-flex items-center gap-1 font-semibold uppercase hover:text-slate-900 dark:hover:text-white" @click="setSort('domain')">
                                    Account <i :class="sortIcon('domain')"></i>
                                </button>
                            </th>
                            <th v-for="category in categories" :key="category.key" class="px-4 py-3" :title="category.hint">
                                <button type="button" class="inline-flex items-center gap-1 font-semibold uppercase hover:text-slate-900 dark:hover:text-white" @click="setSort(category.key)">
                                    {{ category.label }} <i :class="sortIcon(category.key)"></i>
                                </button>
                            </th>
                            <th class="px-4 py-3">
                                <button type="button" class="inline-flex items-center gap-1 font-semibold uppercase hover:text-slate-900 dark:hover:text-white" @click="setSort('total')">
                                    Total <i :class="sortIcon('total')"></i>
                                </button>
                            </th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        <tr v-for="account in sortedAccounts" :key="account.id" class="align-top" :class="selected.includes(account.id) ? 'bg-red-50/50 dark:bg-red-950/20' : ''">
                            <td class="py-3 pl-4">
                                <input v-model="selected" type="checkbox" :value="account.id" :disabled="bulkRunning" class="mt-1 rounded border-slate-300 text-red-600 focus:ring-red-500" />
                            </td>
                            <td class="max-w-[260px] px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <i class="bi bi-hdd" :class="stats[account.id]?.total_items ? 'text-red-500' : 'text-slate-400'"></i>
                                    <p class="truncate font-semibold">{{ account.domain }}</p>
                                    <span v-if="account.domains.length > 1" class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500 dark:bg-slate-800">+{{ account.domains.length - 1 }}</span>
                                </div>
                                <p class="mt-1 truncate text-xs text-slate-500">{{ account.home }}</p>
                                <div class="mt-1 flex flex-wrap gap-1 text-xs">
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300"><i class="bi bi-person"></i> {{ account.user_name || 'Unassigned' }}</span>
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300"><i class="bi bi-box"></i> {{ account.package_name || 'No package' }}</span>
                                </div>
                                <p v-if="stats[account.id]?.error" class="mt-1 text-xs text-red-500">{{ stats[account.id].error }}</p>
                            </td>
                            <td v-for="category in categories" :key="category.key" class="px-4 py-3">
                                <template v-if="!stats[account.id]?.categories">
                                    <span class="text-xs text-slate-400">{{ stats[account.id]?.loading ? 'Loading...' : '—' }}</span>
                                </template>
                                <div v-else class="flex items-center justify-between gap-2">
                                    <div>
                                        <p class="font-medium">{{ formatSize(stats[account.id].categories[category.key]?.size) }}</p>
                                        <p class="text-xs text-slate-500">{{ stats[account.id].categories[category.key]?.items ?? 0 }} item(s)</p>
                                    </div>
                                    <button type="button" :disabled="Boolean(busy[account.id]) || !stats[account.id].categories[category.key]?.items" class="shrink-0 rounded-md px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-red-950" @click="clearFolder(account, category.key)">
                                        <i v-if="busy[account.id] === category.key" class="bi bi-arrow-repeat inline-block animate-spin"></i>
                                        <template v-else>Clear</template>
                                    </button>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <template v-if="stats[account.id]?.categories">
                                    <p class="font-semibold">{{ formatSize(stats[account.id].total_size) }}</p>
                                    <p class="text-xs text-slate-500">{{ stats[account.id].total_items }} item(s)</p>
                                </template>
                                <span v-else class="text-xs text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-2">
                                    <button type="button" :disabled="stats[account.id]?.loading || Boolean(busy[account.id])" class="inline-flex items-center rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" title="Refresh this account" @click="loadStats(account)">
                                        <i class="bi bi-arrow-clockwise" :class="stats[account.id]?.loading ? 'animate-spin' : ''"></i>
                                    </button>
                                    <button type="button" :disabled="Boolean(busy[account.id]) || !stats[account.id]?.total_items" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40" @click="clearFolder(account, 'all')">
                                        <i :class="busy[account.id] === 'all' ? 'bi bi-arrow-repeat animate-spin' : 'bi bi-x-octagon'"></i>
                                        {{ busy[account.id] === 'all' ? 'Clearing...' : 'Clear All' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="sortedAccounts.length === 0">
                            <td :colspan="categories.length + 4" class="px-4 py-10 text-center text-sm text-slate-500">
                                No accounts match these filters. <button type="button" class="font-medium text-red-600 hover:underline" @click="resetFilters">Reset</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
