<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    icon: { type: String, required: true },
    percent: { type: Number, default: null },
    detail: { type: String, default: '' },
});

const RADIUS = 42;
const circumference = 2 * Math.PI * RADIUS;

const value = computed(() => Math.max(0, Math.min(100, Math.round(props.percent ?? 0))));
const offset = computed(() => circumference - (value.value / 100) * circumference);
const loading = computed(() => props.percent === null);

const tone = computed(() => {
    if (value.value < 50) return { ring: 'text-emerald-500 dark:text-emerald-400', text: 'text-emerald-600 dark:text-emerald-400', badge: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300', label: 'Healthy' };
    if (value.value < 75) return { ring: 'text-amber-500 dark:text-amber-400', text: 'text-amber-600 dark:text-amber-400', badge: 'bg-amber-500/10 text-amber-700 dark:text-amber-300', label: 'Moderate' };
    return { ring: 'text-red-500 dark:text-red-400', text: 'text-red-600 dark:text-red-400', badge: 'bg-red-500/10 text-red-700 dark:text-red-300', label: 'High' };
});
</script>

<template>
    <div class="flex min-w-0 items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-900">
        <div class="relative h-16 w-16 shrink-0">
            <svg class="h-full w-full -rotate-90" viewBox="0 0 100 100">
                <circle cx="50" cy="50" :r="RADIUS" fill="none" stroke="currentColor" stroke-width="9" class="text-slate-100 dark:text-white/[0.06]" />
                <circle
                    v-if="!loading"
                    cx="50" cy="50" :r="RADIUS" fill="none" stroke="currentColor" stroke-width="9" stroke-linecap="round"
                    :class="['transition-all duration-1000 ease-out', tone.ring]"
                    :stroke-dasharray="circumference"
                    :stroke-dashoffset="offset"
                />
            </svg>
            <div class="absolute inset-0 flex items-center justify-center">
                <i :class="['bi text-lg text-slate-500 dark:text-slate-400', icon]"></i>
            </div>
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                <p class="font-mono text-[11px] uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">{{ label }}</p>
                <span v-if="!loading" :class="['rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide', tone.badge]">{{ tone.label }}</span>
            </div>
            <template v-if="!loading">
                <p :class="['mt-0.5 text-2xl font-bold leading-tight', tone.text]">
                    {{ value }}<span class="text-sm font-medium text-slate-400">%</span>
                </p>
                <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ detail }}</p>
            </template>
            <template v-else>
                <div class="mt-1.5 h-6 w-16 animate-pulse rounded bg-slate-100 dark:bg-white/[0.06]"></div>
                <div class="mt-1.5 h-3 w-28 animate-pulse rounded bg-slate-100 dark:bg-white/[0.06]"></div>
            </template>
        </div>
    </div>
</template>
