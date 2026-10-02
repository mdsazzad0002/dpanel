<script setup>
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    domain: { type: String, required: true },
    statsUrl: { type: String, required: true },
});

const stats = ref(null);
const loading = ref(false);
const error = ref('');

const load = async () => {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(props.statsUrl, { headers: { Accept: 'application/json' } });
        stats.value = data;
    } catch (failure) {
        error.value = failure.response?.data?.message || 'Statistics are unavailable.';
    } finally {
        loading.value = false;
    }
};
defineExpose({ load });
onMounted(load);

const formatBytes = (bytes) => {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = Number(bytes) || 0;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }
    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
};

const site = computed(() => stats.value?.domain || {});
const served = computed(() => (site.value.hits || 0) + (site.value.stale || 0));
const cacheable = computed(() => served.value + (site.value.misses || 0));
const hitRatio = computed(() => (cacheable.value ? Math.round((served.value / cacheable.value) * 100) : null));
const tiles = computed(() => [
    { label: 'Hit ratio', value: hitRatio.value === null ? '—' : `${hitRatio.value}%`, note: 'of cacheable requests' },
    { label: 'Served from cache', value: served.value.toLocaleString(), note: `${formatBytes(site.value.bytes_served_from_cache)} not sent by your app` },
    { label: 'Fetched from your app', value: ((site.value.misses || 0) + (site.value.dynamic || 0)).toLocaleString(), note: `${(site.value.dynamic || 0).toLocaleString()} not cacheable` },
    { label: 'Cached now', value: (site.value.entries || 0).toLocaleString(), note: formatBytes(site.value.stored_bytes) },
]);
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold">Cache activity</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Counted since the edge gateway last started.</p>
            </div>
            <button type="button" :disabled="loading" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-50 dark:border-slate-700" @click="load">{{ loading ? 'Loading…' : 'Refresh' }}</button>
        </div>

        <p v-if="error" class="mt-4 text-sm text-amber-700 dark:text-amber-300">{{ error }}</p>
        <div v-else class="mt-4 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-slate-200 bg-slate-200 dark:border-slate-700 dark:bg-slate-700 lg:grid-cols-4">
            <div v-for="tile in tiles" :key="tile.label" class="bg-white px-4 py-3 dark:bg-slate-900">
                <p class="text-xs text-slate-500">{{ tile.label }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ stats ? tile.value : '—' }}</p>
                <p class="mt-0.5 text-xs text-slate-500">{{ stats ? tile.note : '' }}</p>
            </div>
        </div>

        <p class="mt-4 text-xs text-slate-500">
            Check a page with <code class="rounded bg-slate-100 px-1 py-0.5 dark:bg-slate-800">curl -sI https://{{ domain }}/ | grep x-dpanel-cache</code>:
            HIT and STALE came from the cache, MISS and EXPIRED were fetched and stored, DYNAMIC was not cacheable, BYPASS matched a bypass rule.
        </p>
    </section>
</template>
