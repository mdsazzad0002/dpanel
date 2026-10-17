<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: { type: Object, required: true },
    redis: { type: Object, required: true },
    isAdmin: { type: Boolean, default: false },
});

const { panelRoute, requestJson } = usePanelApi();
const r = (name, params = {}) => panelRoute(name, { id: props.website.id, ...params });

const typeClasses = {
    string: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    hash: 'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
    list: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    set: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    zset: 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
    stream: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-950 dark:text-cyan-300',
};
const typeClass = (type) => typeClasses[type] || 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';

const formatBytes = (bytes) => {
    if (bytes === null || bytes === undefined) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = Number(bytes);
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
};
const formatTtl = (ttl) => {
    if (ttl === -1) return 'No expiry';
    if (ttl < 0) return 'Expired';
    const d = Math.floor(ttl / 86400), h = Math.floor((ttl % 86400) / 3600), m = Math.floor((ttl % 3600) / 60), s = ttl % 60;
    return [d && `${d}d`, h && `${h}h`, m && `${m}m`, (!d && !h) && `${s}s`].filter(Boolean).join(' ');
};

// ---- Namespace stats ----
const stats = ref(null);
const statsLoading = ref(false);
const loadStats = async () => {
    statsLoading.value = true;
    try { stats.value = await requestJson(r('websites.redis-explorer.stats'), { method: 'GET' }); }
    catch (e) { error.value = e.message; }
    finally { statsLoading.value = false; }
};

// ---- Key list ----
const keys = ref([]);
const cursor = ref('0');
const search = ref('');
const typeFilter = ref('');
const loading = ref(false);
const error = ref('');
const selected = ref([]);
const hasMore = computed(() => cursor.value !== '0');
const visibleKeys = computed(() => (typeFilter.value ? keys.value.filter((k) => k.type === typeFilter.value) : keys.value));
const allSelected = computed(() => visibleKeys.value.length > 0 && visibleKeys.value.every((k) => selected.value.includes(k.name)));

const loadKeys = async (reset = true) => {
    loading.value = true;
    error.value = '';
    try {
        const data = await requestJson(r('websites.redis-explorer.keys', {
            search: search.value || undefined,
            cursor: reset ? '0' : cursor.value,
        }), { method: 'GET' });
        const seen = new Set(reset ? [] : keys.value.map((k) => k.name));
        const fresh = data.keys.filter((k) => !seen.has(k.name));
        keys.value = reset ? fresh : [...keys.value, ...fresh];
        cursor.value = data.cursor;
        if (reset) selected.value = [];
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
};

let searchTimer = null;
watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadKeys(true), 350);
});

const toggleAll = () => {
    const names = visibleKeys.value.map((k) => k.name);
    selected.value = allSelected.value ? selected.value.filter((n) => !names.includes(n)) : [...new Set([...selected.value, ...names])];
};

const deleteKeys = async (names) => {
    if (!names.length || !confirm(`Delete ${names.length} key(s) from ${props.website.domain}? This cannot be undone.`)) return;
    try {
        const data = await requestJson(r('websites.redis-explorer.destroy'), { body: { names } });
        notice.value = `Deleted ${data.deleted} key(s).`;
        keys.value = keys.value.filter((k) => !names.includes(k.name));
        selected.value = selected.value.filter((n) => !names.includes(n));
        if (detail.value && names.includes(detail.value.name)) detail.value = null;
        loadStats();
    } catch (e) {
        error.value = e.message;
    }
};

// ---- Key detail ----
const detail = ref(null);
const detailLoading = ref(false);
const detailError = ref('');
const viewMode = ref('pretty');
const editing = ref(false);
const editValue = ref('');
const ttlInput = ref('');
const notice = ref('');
const copied = ref(false);

const openKey = async (name, nextCursor = '0') => {
    detailLoading.value = true;
    detailError.value = '';
    if (nextCursor === '0') { editing.value = false; }
    try {
        const data = await requestJson(r('websites.redis-explorer.show', { name, cursor: nextCursor }), { method: 'GET' });
        if (nextCursor !== '0' && detail.value?.name === name) {
            data.items = [...(detail.value.items || []), ...(data.items || [])];
        }
        detail.value = data;
        ttlInput.value = data.ttl > 0 ? String(data.ttl) : '';
        viewMode.value = data.value?.pretty ? 'pretty' : 'raw';
        const row = keys.value.find((k) => k.name === name);
        if (row) row.ttl = data.ttl;
    } catch (e) {
        detailError.value = e.message;
        detail.value = null;
    } finally {
        detailLoading.value = false;
    }
};

const startEdit = () => {
    editValue.value = detail.value.value.raw;
    editing.value = true;
};
const saveValue = async () => {
    try {
        await requestJson(r('websites.redis-explorer.update'), { body: { name: detail.value.name, value: editValue.value } });
        notice.value = 'Value saved.';
        editing.value = false;
        openKey(detail.value.name);
    } catch (e) {
        detailError.value = e.message;
    }
};
const saveTtl = async (persist = false) => {
    const ttl = persist ? null : parseInt(ttlInput.value, 10);
    if (!persist && !(ttl > 0)) { detailError.value = 'Enter a TTL in seconds.'; return; }
    try {
        await requestJson(r('websites.redis-explorer.ttl'), { body: { name: detail.value.name, ttl } });
        notice.value = persist ? 'Expiry removed.' : `TTL set to ${formatTtl(ttl)}.`;
        openKey(detail.value.name);
    } catch (e) {
        detailError.value = e.message;
    }
};
const copyValue = async () => {
    const v = detail.value?.value;
    if (!v) return;
    try {
        await navigator.clipboard.writeText(viewMode.value === 'pretty' && v.pretty ? v.pretty : v.raw);
        copied.value = true;
        window.setTimeout(() => { copied.value = false; }, 1500);
    } catch { /* clipboard blocked */ }
};
const memberText = (value) => value.pretty || value.raw;

watch(notice, (value) => { if (value) window.setTimeout(() => { if (notice.value === value) notice.value = ''; }, 3000); });

const refreshAll = () => { loadKeys(true); loadStats(); if (detail.value) openKey(detail.value.name); };

onMounted(() => { loadKeys(true); loadStats(); });
</script>

<template>
    <Head :title="`Redis Explorer - ${website.domain}`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Redis Explorer</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Browse and inspect the Redis keys stored by {{ website.domain }}.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="min-w-0 text-xs text-slate-500 dark:text-slate-400">
                    <span class="font-mono">{{ redis.host }}:{{ redis.port }}</span> · DB {{ redis.database }} · prefix
                    <code class="break-all rounded bg-slate-100 px-1.5 py-0.5 text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ redis.prefix }}</code>
                </div>
                <div class="flex gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="refreshAll">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                    <Link :href="r('websites.redis-cache.index')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Redis Setup</Link>
                    <Link :href="r('websites.manage')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Back to Website</Link>
                </div>
            </div>

            <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ error }}</div>
            <div v-if="notice" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">{{ notice }}</div>

            <!-- Stats -->
            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Keys</p>
                    <p class="mt-1 text-2xl font-semibold">{{ statsLoading && !stats ? '…' : (stats?.namespace.key_count ?? 0).toLocaleString() }}<span v-if="stats && !stats.namespace.complete" class="text-sm text-slate-500">+</span></p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Memory used</p>
                    <p class="mt-1 text-2xl font-semibold">{{ statsLoading && !stats ? '…' : formatBytes(stats?.namespace.memory_bytes ?? 0) }}</p>
                    <p v-if="stats && !stats.namespace.complete" class="text-xs text-slate-500">First {{ stats.namespace.key_count.toLocaleString() }} keys</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 lg:col-span-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Key types</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <button
                            v-for="(count, type) in stats?.namespace.types || {}"
                            :key="type"
                            type="button"
                            :class="[typeClass(type), typeFilter === type ? 'ring-2 ring-offset-1 ring-slate-400 dark:ring-offset-slate-900' : '']"
                            class="rounded-full px-2.5 py-1 text-xs font-medium"
                            :title="`Show only ${type} keys`"
                            @click="typeFilter = typeFilter === type ? '' : type"
                        >{{ type }} · {{ count.toLocaleString() }}</button>
                        <span v-if="stats && !Object.keys(stats.namespace.types).length" class="text-sm text-slate-500">No keys yet</span>
                    </div>
                </div>
            </section>

            <section v-if="isAdmin && stats?.server" class="flex flex-wrap gap-x-6 gap-y-1 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                <span class="font-semibold uppercase tracking-wide text-slate-500">Server (admin)</span>
                <span>Redis {{ stats.server.version }}</span>
                <span>Used {{ stats.server.used_memory_human }}</span>
                <span>Peak {{ stats.server.used_memory_peak_human }}</span>
                <span>Max {{ stats.server.maxmemory_human === '0B' ? 'unlimited' : stats.server.maxmemory_human }}</span>
                <span>Policy {{ stats.server.maxmemory_policy }}</span>
                <span>Fragmentation {{ stats.server.fragmentation_ratio }}</span>
            </section>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <!-- Key list -->
                <section class="flex min-h-[28rem] flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div class="space-y-2 border-b border-slate-200 p-3 dark:border-slate-800">
                        <div class="relative">
                            <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Search keys (use * for pattern)"
                                class="w-full rounded-md border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm dark:border-slate-700 dark:bg-slate-950"
                            >
                        </div>
                        <div class="flex items-center justify-between text-xs text-slate-500">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" :checked="allSelected" class="rounded" @change="toggleAll"> Select all
                            </label>
                            <span>{{ visibleKeys.length }} shown<span v-if="typeFilter"> · {{ typeFilter }}</span></span>
                            <button
                                type="button"
                                :disabled="!selected.length"
                                class="rounded bg-red-600 px-2.5 py-1 font-medium text-white disabled:opacity-40"
                                @click="deleteKeys([...selected])"
                            >Delete ({{ selected.length }})</button>
                        </div>
                    </div>

                    <ul class="max-h-[36rem] flex-1 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                        <li
                            v-for="k in visibleKeys"
                            :key="k.name"
                            :class="detail?.name === k.name ? 'bg-blue-50 dark:bg-blue-950/40' : 'hover:bg-slate-50 dark:hover:bg-slate-800/50'"
                            class="flex cursor-pointer items-center gap-2 px-3 py-2"
                            @click="openKey(k.name)"
                        >
                            <input v-model="selected" type="checkbox" :value="k.name" class="rounded" @click.stop>
                            <span :class="typeClass(k.type)" class="w-14 shrink-0 rounded px-1.5 py-0.5 text-center text-[10px] font-semibold uppercase">{{ k.type }}</span>
                            <span class="min-w-0 flex-1 truncate font-mono text-xs" :title="k.name">{{ k.name }}</span>
                            <span class="shrink-0 text-[11px] text-slate-400">{{ k.ttl === -1 ? '∞' : formatTtl(k.ttl) }}</span>
                        </li>
                        <li v-if="!loading && !visibleKeys.length" class="px-3 py-10 text-center text-sm text-slate-500">
                            {{ search ? 'No keys match this search.' : 'No keys found for this website.' }}
                        </li>
                    </ul>

                    <div class="border-t border-slate-200 p-2 text-center dark:border-slate-800">
                        <span v-if="loading" class="text-xs text-slate-500">Loading…</span>
                        <button v-else-if="hasMore" type="button" class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400" @click="loadKeys(false)">Load more keys</button>
                        <span v-else class="text-xs text-slate-400">End of keys</span>
                    </div>
                </section>

                <!-- Key detail -->
                <section class="min-h-[28rem] rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div v-if="detailLoading && !detail" class="p-10 text-center text-sm text-slate-500">Loading key…</div>
                    <div v-else-if="!detail" class="flex h-full flex-col items-center justify-center p-10 text-center text-sm text-slate-500">
                        <i class="bi bi-hdd-stack text-3xl text-slate-300 dark:text-slate-600"></i>
                        <p class="mt-2">{{ detailError || 'Select a key to view its value.' }}</p>
                    </div>
                    <div v-else class="flex h-full flex-col">
                        <div class="space-y-3 border-b border-slate-200 p-4 dark:border-slate-800">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <span :class="typeClass(detail.type)" class="rounded px-2 py-0.5 text-xs font-semibold uppercase">{{ detail.type }}</span>
                                    <p class="mt-2 break-all font-mono text-sm font-medium">{{ detail.name }}</p>
                                </div>
                                <button type="button" class="shrink-0 rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950" @click="deleteKeys([detail.name])">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </div>
                            <dl class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                                <div><dt class="text-slate-500">TTL</dt><dd class="font-medium">{{ formatTtl(detail.ttl) }}</dd></div>
                                <div><dt class="text-slate-500">Memory</dt><dd class="font-medium">{{ formatBytes(detail.memory_bytes) }}</dd></div>
                                <div><dt class="text-slate-500">{{ detail.type === 'string' ? 'Length' : 'Items' }}</dt><dd class="font-medium">{{ detail.type === 'string' ? formatBytes(detail.size) : detail.size.toLocaleString() }}</dd></div>
                                <div><dt class="text-slate-500">Encoding</dt><dd class="font-medium">{{ detail.encoding || '—' }}</dd></div>
                            </dl>
                            <div class="flex flex-wrap items-center gap-2">
                                <input v-model="ttlInput" type="number" min="1" placeholder="TTL seconds" class="w-32 rounded-md border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-950">
                                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="saveTtl(false)">Set TTL</button>
                                <button v-if="detail.ttl !== -1" type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="saveTtl(true)">Remove expiry</button>
                                <span v-if="detailLoading" class="text-xs text-slate-500">Refreshing…</span>
                            </div>
                            <p v-if="detailError" class="text-xs text-red-600">{{ detailError }}</p>
                        </div>

                        <!-- String value -->
                        <div v-if="detail.type === 'string'" class="flex flex-1 flex-col p-4">
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="rounded bg-slate-100 px-2 py-0.5 font-medium dark:bg-slate-800">{{ detail.value.format }}</span>
                                    <template v-if="detail.value.pretty && !editing">
                                        <button type="button" :class="viewMode === 'pretty' ? 'font-semibold text-blue-600 dark:text-blue-400' : 'text-slate-500'" @click="viewMode = 'pretty'">Pretty</button>
                                        <button type="button" :class="viewMode === 'raw' ? 'font-semibold text-blue-600 dark:text-blue-400' : 'text-slate-500'" @click="viewMode = 'raw'">Raw</button>
                                    </template>
                                    <span v-if="detail.truncated" class="text-amber-600">Showing first {{ formatBytes(detail.value.raw.length) }}</span>
                                </div>
                                <div class="flex gap-2">
                                    <button v-if="!editing" type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="copyValue">{{ copied ? 'Copied!' : 'Copy' }}</button>
                                    <button v-if="detail.editable && !editing" type="button" class="rounded-md bg-blue-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-blue-700" @click="startEdit">Edit</button>
                                    <template v-if="editing">
                                        <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs dark:border-slate-700" @click="editing = false">Cancel</button>
                                        <button type="button" class="rounded-md bg-blue-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-blue-700" @click="saveValue">Save</button>
                                    </template>
                                </div>
                            </div>
                            <textarea v-if="editing" v-model="editValue" class="min-h-[22rem] flex-1 rounded-lg border border-slate-300 bg-slate-950 p-3 font-mono text-xs text-slate-100 dark:border-slate-700"></textarea>
                            <pre v-else class="max-h-[32rem] flex-1 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-950 p-3 font-mono text-xs leading-5 text-slate-100">{{ viewMode === 'pretty' && detail.value.pretty ? detail.value.pretty : detail.value.raw }}</pre>
                        </div>

                        <!-- Collection values -->
                        <div v-else class="flex-1 overflow-auto p-4">
                            <table class="min-w-full text-left text-xs">
                                <thead class="sticky top-0 bg-slate-50 text-slate-500 dark:bg-slate-800">
                                    <tr>
                                        <th v-if="detail.type === 'list'" class="w-14 px-2 py-2">#</th>
                                        <th v-if="detail.type === 'hash'" class="px-2 py-2">Field</th>
                                        <th v-if="detail.type === 'zset'" class="w-24 px-2 py-2">Score</th>
                                        <th v-if="detail.type === 'stream'" class="w-40 px-2 py-2">ID</th>
                                        <th class="px-2 py-2">Value</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="(item, i) in detail.items" :key="i" class="align-top">
                                        <td v-if="detail.type === 'list'" class="px-2 py-1.5 text-slate-400">{{ item.index }}</td>
                                        <td v-if="detail.type === 'hash'" class="break-all px-2 py-1.5 font-mono font-medium">{{ item.field }}</td>
                                        <td v-if="detail.type === 'zset'" class="px-2 py-1.5 font-mono">{{ item.score }}</td>
                                        <td v-if="detail.type === 'stream'" class="px-2 py-1.5 font-mono">{{ item.id }}</td>
                                        <td class="px-2 py-1.5">
                                            <pre class="max-h-48 overflow-auto whitespace-pre-wrap break-all font-mono">{{ memberText(item.value) }}</pre>
                                            <span v-if="item.value.truncated" class="text-[10px] text-amber-600">truncated</span>
                                        </td>
                                    </tr>
                                    <tr v-if="!detail.items?.length"><td colspan="2" class="px-2 py-6 text-center text-slate-500">Empty</td></tr>
                                </tbody>
                            </table>
                            <div v-if="detail.next_cursor !== '0'" class="mt-3 text-center">
                                <button type="button" :disabled="detailLoading" class="text-sm font-medium text-blue-600 hover:underline disabled:opacity-50 dark:text-blue-400" @click="openKey(detail.name, detail.next_cursor)">
                                    Load more ({{ detail.items.length }} of {{ detail.size.toLocaleString() }})
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
