<script setup>
// One resource of one account: use, limit and a bar that turns amber at 80% and red at 100% (P6).
import { computed } from 'vue';

const props = defineProps({
    resource: { type: Object, required: true },
});

const format = (value, unit) => {
    if (value === null || value === undefined) return '—';
    if (unit === '%') return `${Math.round(value)}%`;
    return value >= 1024 ? `${(value / 1024).toFixed(1)} GB` : `${Math.round(value)} MB`;
};

const tone = computed(() => {
    const percent = props.resource.percent;
    if (percent === null) return 'bg-slate-300 dark:bg-slate-600';
    if (percent >= 100) return 'bg-red-500';
    if (percent >= 80) return 'bg-amber-500';
    return 'bg-emerald-500';
});
</script>

<template>
    <div class="min-w-[8rem]">
        <p class="text-sm">
            <span class="font-medium text-slate-900 dark:text-white">{{ format(resource.used, resource.unit) }}</span>
            <span class="text-slate-400"> / {{ resource.limit === null ? 'no limit' : format(resource.limit, resource.unit) }}</span>
        </p>
        <div v-if="resource.limit !== null" class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/[0.06]">
            <div class="h-full rounded-full" :class="tone" :style="{ width: `${Math.min(100, resource.percent ?? 0)}%` }"></div>
        </div>
    </div>
</template>
