<script setup>
// A week of one account's use, loaded when its row is opened.
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import Sparkline from './Sparkline.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    account: { type: Object, required: true },
});

const page = usePage();
const series = ref(null);
const error = ref('');

const charts = computed(() => [
    { key: 'disk', metric: 'disk_mb', label: t('usage.history.charts.disk') },
    { key: 'bandwidth', metric: 'bandwidth_mb', label: t('usage.history.charts.bandwidth') },
    { key: 'memory', metric: 'memory_mb', label: t('usage.history.charts.memory') },
    { key: 'cpu', metric: 'cpu_percent', label: t('usage.history.charts.cpu') },
]);
const limitOf = (key) => props.account.resources.find((resource) => resource.key === key)?.limit ?? null;

onMounted(async () => {
    try {
        const token = page.props.panel?.token;
        const { data } = await window.axios.get(route('usage.history', token ? { token, id: props.account.id } : { id: props.account.id }), { headers: { Accept: 'application/json' } });
        series.value = data.series;
    } catch (e) {
        error.value = e?.response?.data?.message || e?.message || t('usage.history.failed');
    }
});
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <p v-if="error" class="text-sm text-red-600 sm:col-span-2">{{ error }}</p>
        <p v-else-if="!series" class="text-sm text-slate-500 sm:col-span-2">{{ t('usage.history.loading') }}</p>
        <template v-else>
            <div v-for="chart in charts" :key="chart.key">
                <p class="mb-1 text-xs uppercase tracking-wide text-slate-500">{{ chart.label }}</p>
                <Sparkline v-if="series[chart.metric]?.length" :points="series[chart.metric]" :limit="limitOf(chart.key)" />
                <p v-else class="text-xs text-slate-400">{{ t('usage.history.noReadings') }}</p>
            </div>
            <p class="text-xs text-slate-500 sm:col-span-2">{{ t('usage.history.note') }}</p>
        </template>
    </div>
</template>
