<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const REFRESH_MS = 5000;

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const overview = ref(null);
const loading = ref(true);
const loadError = ref('');
const lastUpdated = ref(null);
const autoRefresh = ref(true);
const search = ref('');
const onlyBusy = ref(false);
const sortBy = ref('active');

const drafts = ref({});
const saving = ref({});
const rowMessage = ref({});
const rowError = ref({});

let timer = null;

const load = async () => {
    try {
        const response = await fetch(panelRoute('php.workers.data'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) throw new Error(payload.message || t('php.workers.loadFailed'));
        overview.value = payload.data;
        loadError.value = '';
        lastUpdated.value = new Date();
    } catch (error) {
        loadError.value = error?.message || t('php.workers.loadFailed');
    } finally {
        loading.value = false;
    }
};

const schedule = () => {
    clearTimeout(timer);
    if (!autoRefresh.value) return;
    timer = setTimeout(async () => {
        if (!document.hidden) await load();
        schedule();
    }, REFRESH_MS);
};

watch(autoRefresh, schedule);
onMounted(async () => {
    await load();
    schedule();
});
onBeforeUnmount(() => clearTimeout(timer));

const kbToMb = (kb) => Math.round((Number(kb) || 0) / 1024);
const formatMb = (kb) => {
    const mb = (Number(kb) || 0) / 1024;
    return mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${Math.round(mb)} MB`;
};

// One row per site user: the limit is set per user and applies to every PHP version it runs.
const sites = computed(() => {
    const groups = new Map();
    for (const pool of overview.value?.pools || []) {
        if (!groups.has(pool.owner)) {
            groups.set(pool.owner, {
                owner: pool.owner,
                versions: [],
                dedicated: false,
                custom: pool.custom,
                limits: new Set(),
                running: 0,
                active: 0,
                idle: 0,
                memoryKb: 0,
                hits: 0,
                lastHit: null,
            });
        }
        const site = groups.get(pool.owner);
        site.versions.push(pool.version);
        site.dedicated ||= pool.dedicated;
        if (pool.max_children) site.limits.add(pool.max_children);
        site.running += pool.running;
        site.active += pool.active;
        site.idle += pool.idle;
        site.memoryKb += pool.memory_kb;
        site.hits += pool.limit_hits_24h;
        if (pool.last_limit_hit && (!site.lastHit || pool.last_limit_hit > site.lastHit)) site.lastHit = pool.last_limit_hit;
    }
    return [...groups.values()].map((site) => ({
        ...site,
        limit: site.limits.size ? Math.max(...site.limits) : null,
        mixedLimits: site.limits.size > 1,
        versionsWithPools: site.versions.length,
    }));
});

const visibleSites = computed(() => {
    const term = search.value.trim().toLowerCase();
    const list = sites.value.filter((site) => {
        if (term && !site.owner.toLowerCase().includes(term)) return false;
        if (onlyBusy.value && site.running === 0 && site.hits === 0) return false;
        return true;
    });
    const sorters = {
        active: (a, b) => b.active - a.active || b.running - a.running || b.hits - a.hits,
        hits: (a, b) => b.hits - a.hits || b.active - a.active,
        memory: (a, b) => b.memoryKb - a.memoryKb,
        name: (a, b) => a.owner.localeCompare(b.owner),
    };
    return list.sort(sorters[sortBy.value] || sorters.active);
});

const totals = computed(() => sites.value.reduce((sum, site) => ({
    active: sum.active + site.active,
    running: sum.running + site.running,
    memoryKb: sum.memoryKb + site.memoryKb,
    hitSites: sum.hitSites + (site.hits > 0 ? 1 : 0),
    capacity: sum.capacity + (site.limit || 0) * site.versionsWithPools,
}), { active: 0, running: 0, memoryKb: 0, hitSites: 0, capacity: 0 }));

const averageWorkerKb = computed(() => overview.value?.average_worker_kb || 96 * 1024);
const headroomWorkers = computed(() => Math.floor((overview.value?.memory_available_kb || 0) / averageWorkerKb.value));

const draftFor = (site) => drafts.value[site.owner] ?? site.limit ?? '';
const setDraft = (site, value) => {
    drafts.value = { ...drafts.value, [site.owner]: value };
};
const isDirty = (site) => site.owner in drafts.value && Number(drafts.value[site.owner]) !== site.limit;

const extraMemoryKb = (site) => {
    const draft = Number(draftFor(site));
    if (!draft || !site.limit) return 0;
    return (draft - site.limit) * averageWorkerKb.value;
};

const usagePercent = (site) => {
    if (!site.limit) return 0;
    return Math.min(100, Math.round((site.active / site.limit) * 100));
};

const usageTone = (site) => {
    const percent = usagePercent(site);
    if (percent >= 100) return 'bg-red-500';
    if (percent >= 50) return 'bg-amber-500';
    return 'bg-emerald-500';
};

const save = async (site, value) => {
    const owner = site.owner;
    saving.value = { ...saving.value, [owner]: true };
    rowError.value = { ...rowError.value, [owner]: '' };
    rowMessage.value = { ...rowMessage.value, [owner]: '' };
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch(panelRoute('php.workers.update'), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            credentials: 'same-origin',
            body: JSON.stringify({ owner, max_children: value }),
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            const validation = payload.errors ? Object.values(payload.errors).flat().join(' ') : '';
            throw new Error(validation || payload.message || t('php.workers.saveFailed'));
        }
        const { [owner]: _removed, ...rest } = drafts.value;
        drafts.value = rest;
        rowMessage.value = { ...rowMessage.value, [owner]: payload.message || t('php.workers.saved') };
        // PHP-FPM reloads a couple of seconds after the answer.
        setTimeout(load, 3500);
    } catch (error) {
        rowError.value = { ...rowError.value, [owner]: error?.message || t('php.workers.saveFailed') };
    } finally {
        saving.value = { ...saving.value, [owner]: false };
    }
};

const saveDraft = (site) => {
    const value = Number(draftFor(site));
    const min = overview.value?.min_limit || 1;
    const max = overview.value?.max_limit || 50;
    if (!Number.isInteger(value) || value < min || value > max) {
        rowError.value = { ...rowError.value, [site.owner]: t('php.workers.range', { min, max }) };
        return;
    }
    save(site, value);
};

const resetToAuto = (site) => save(site, null);
</script>

<template>
    <Head :title="t('php.workers.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-lg font-semibold">{{ t('php.workers.title') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ t('php.workers.subtitle') }}
                    </p>
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <span v-if="lastUpdated" class="text-xs text-slate-500 dark:text-slate-400">
                        {{ t('php.workers.updated', { time: lastUpdated.toLocaleTimeString() }) }}
                    </span>
                    <label class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                        <input v-model="autoRefresh" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                        {{ t('php.workers.live') }}
                    </label>
                    <button
                        type="button"
                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                        @click="load"
                    >
                        {{ t('common.refresh') }}
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <div v-if="loadError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
                {{ loadError }}
            </div>

            <div v-if="overview" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('php.workers.busyNow') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">
                        {{ totals.active }} <span class="text-base font-normal text-slate-500">/ {{ t('php.workers.running', { n: totals.running }) }}</span>
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('php.workers.outOf', { capacity: totals.capacity, sites: sites.length }) }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('php.workers.memory') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{{ formatMb(totals.memoryKb) }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('php.workers.perWorker', { mb: kbToMb(averageWorkerKb) }) }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('php.workers.free') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">
                        {{ formatMb(overview.memory_available_kb) }}
                        <span class="text-base font-normal text-slate-500">/ {{ formatMb(overview.memory_total_kb) }}</span>
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('php.workers.room', { n: headroomWorkers }) }}</p>
                </div>
                <div class="rounded-2xl border p-4 shadow-sm"
                    :class="totals.hitSites
                        ? 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40'
                        : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900'"
                >
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('php.workers.ranOut') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{{ totals.hitSites }} <span class="text-base font-normal text-slate-500">{{ t('php.workers.sites') }}</span></p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('php.workers.autoLimit', { n: overview.automatic_limit }) }}</p>
                </div>
            </div>

            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('php.workers.search')"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm sm:w-64 dark:border-slate-700 dark:bg-slate-800"
                    />
                    <select v-model="sortBy" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="active">{{ t('php.workers.sort.active') }}</option>
                        <option value="hits">{{ t('php.workers.sort.hits') }}</option>
                        <option value="memory">{{ t('php.workers.sort.memory') }}</option>
                        <option value="name">{{ t('php.workers.sort.name') }}</option>
                    </select>
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input v-model="onlyBusy" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                        {{ t('php.workers.onlyBusy') }}
                    </label>
                </div>

                <div v-if="loading" class="px-6 py-12 text-center text-sm text-slate-500">{{ t('php.workers.loading') }}</div>
                <div v-else-if="!visibleSites.length" class="px-6 py-12 text-center text-sm text-slate-500">{{ t('php.workers.noMatch') }}</div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                            <tr>
                                <th class="px-5 py-3 font-medium">{{ t('php.workers.th.user') }}</th>
                                <th class="px-5 py-3 font-medium">{{ t('php.workers.th.busy') }}</th>
                                <th class="px-5 py-3 font-medium">{{ t('php.workers.th.mem') }}</th>
                                <th class="px-5 py-3 font-medium">{{ t('php.workers.th.hit') }}</th>
                                <th class="px-5 py-3 font-medium">{{ t('php.workers.th.limit') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="site in visibleSites" :key="site.owner" class="align-top">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ site.owner }}</p>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <span v-for="version in site.versions" :key="version" class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">PHP {{ version }}</span>
                                        <span v-if="site.dedicated" class="rounded bg-violet-100 px-1.5 py-0.5 text-xs text-violet-700 dark:bg-violet-950 dark:text-violet-300">{{ t('php.workers.ownMaster') }}</span>
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900 dark:text-white">
                                        {{ site.active }} <span class="font-normal text-slate-500">/ {{ site.running }}</span>
                                        <span v-if="site.limit" class="font-normal text-slate-400"> {{ t('php.workers.of', { n: site.limit }) }}</span>
                                    </p>
                                    <div class="mt-1.5 h-1.5 w-32 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                        <div class="h-full rounded-full transition-all" :class="usageTone(site)" :style="{ width: `${usagePercent(site)}%` }" />
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-slate-700 dark:text-slate-300">{{ formatMb(site.memoryKb) }}</td>
                                <td class="px-5 py-3">
                                    <span v-if="site.hits" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                        {{ site.hits }}×
                                    </span>
                                    <span v-else class="text-slate-400">0</span>
                                    <p v-if="site.lastHit" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('php.workers.last', { time: site.lastHit }) }}</p>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <input
                                            :value="draftFor(site)"
                                            type="number"
                                            :min="overview?.min_limit || 1"
                                            :max="overview?.max_limit || 50"
                                            class="w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800"
                                            @input="setDraft(site, $event.target.value)"
                                            @keydown.enter="saveDraft(site)"
                                        />
                                        <button
                                            v-if="isDirty(site)"
                                            type="button"
                                            :disabled="saving[site.owner]"
                                            class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                            @click="saveDraft(site)"
                                        >
                                            {{ saving[site.owner] ? t('php.manager.saving') : t('common.save') }}
                                        </button>
                                        <span v-if="site.custom" class="rounded bg-indigo-100 px-1.5 py-0.5 text-xs text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">{{ t('php.workers.custom') }}</span>
                                        <span v-else class="text-xs text-slate-500 dark:text-slate-400">{{ t('php.workers.auto') }}</span>
                                        <button
                                            v-if="site.custom && !isDirty(site)"
                                            type="button"
                                            :disabled="saving[site.owner]"
                                            class="text-xs text-slate-500 underline hover:text-slate-700 disabled:opacity-50 dark:text-slate-400 dark:hover:text-slate-200"
                                            @click="resetToAuto(site)"
                                        >
                                            {{ t('php.workers.reset') }}
                                        </button>
                                    </div>
                                    <p v-if="isDirty(site) && extraMemoryKb(site) > 0" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                        {{ t('php.workers.extra', { size: formatMb(extraMemoryKb(site) * site.versionsWithPools) }) }}
                                    </p>
                                    <p v-if="site.mixedLimits" class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ t('php.workers.mixed') }}</p>
                                    <p v-if="rowError[site.owner]" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ rowError[site.owner] }}</p>
                                    <p v-else-if="rowMessage[site.owner]" class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">{{ rowMessage[site.owner] }}</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
