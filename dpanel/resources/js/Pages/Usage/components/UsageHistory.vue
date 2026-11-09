<script setup>
// A week of one account's use, loaded when its row is opened.
import { usePage } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import Sparkline from './Sparkline.vue';

const props = defineProps({
    account: { type: Object, required: true },
});

const page = usePage();
const series = ref(null);
const error = ref('');

const charts = [
    { key: 'disk', metric: 'disk_mb', label: 'Disk (MB)' },
    { key: 'bandwidth', metric: 'bandwidth_mb', label: 'Bandwidth this month (MB)' },
    { key: 'memory', metric: 'memory_mb', label: 'Memory (MB)' },
    { key: 'cpu', metric: 'cpu_percent', label: 'CPU (%)' },
];
const limitOf = (key) => props.account.resources.find((resource) => resource.key === key)?.limit ?? null;

onMounted(async () => {
    try {
        const token = page.props.panel?.token;
        const { data } = await window.axios.get(route('usage.history', token ? { token, id: props.account.id } : { id: props.account.id }), { headers: { Accept: 'application/json' } });
        series.value = data.series;
    } catch (e) {
        error.value = e?.response?.data?.message || e?.message || 'History could not be loaded.';
    }
});
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <p v-if="error" class="text-sm text-red-600 sm:col-span-2">{{ error }}</p>
        <p v-else-if="!series" class="text-sm text-slate-500 sm:col-span-2">Loading the last seven days…</p>
        <template v-else>
            <div v-for="chart in charts" :key="chart.key">
                <p class="mb-1 text-xs uppercase tracking-wide text-slate-500">{{ chart.label }}</p>
                <Sparkline v-if="series[chart.metric]?.length" :points="series[chart.metric]" :limit="limitOf(chart.key)" />
                <p v-else class="text-xs text-slate-400">No readings in the last seven days.</p>
            </div>
            <p class="text-xs text-slate-500 sm:col-span-2">Hourly averages over seven days. The dashed line is the package limit.</p>
        </template>
    </div>
</template>
