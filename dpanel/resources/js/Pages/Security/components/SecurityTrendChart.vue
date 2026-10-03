<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    // { metric: 'score' | 'threats', label, max, points: [{ date, value }] }
    trend: { type: Object, required: true },
});

const box = ref(null);
const width = ref(600);
const height = 200;
const pad = { top: 12, right: 12, bottom: 24, left: 32 };
const hover = ref(null);
let observer = null;

onMounted(() => {
    observer = new ResizeObserver(([entry]) => {
        width.value = Math.max(240, Math.floor(entry.contentRect.width));
    });
    observer.observe(box.value);
});
onBeforeUnmount(() => observer?.disconnect());

const points = computed(() => props.trend.points || []);
const isBars = computed(() => props.trend.metric === 'threats');
const values = computed(() => points.value.map((point) => point.value).filter((value) => value !== null));
const hasData = computed(() => (isBars.value ? values.value.some((value) => value > 0) : values.value.length > 0));

// Round the top up to a tidy number so gridlines land on whole values.
const yMax = computed(() => {
    if (props.trend.max) return props.trend.max;
    const top = Math.max(4, ...values.value);
    const step = top <= 10 ? 2 : top <= 50 ? 10 : 10 ** Math.floor(Math.log10(top));
    return Math.ceil(top / step) * step;
});
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(yMax.value * f)));

const plotW = computed(() => width.value - pad.left - pad.right);
const plotH = height - pad.top - pad.bottom;
const slot = computed(() => plotW.value / Math.max(1, points.value.length));
const x = (index) => pad.left + slot.value * index + slot.value / 2;
const y = (value) => pad.top + plotH - (value / yMax.value) * plotH;

// Days without a value break the line instead of being drawn as zero.
const segments = computed(() => {
    const out = [];
    let current = [];
    points.value.forEach((point, index) => {
        if (point.value === null) {
            if (current.length) out.push(current);
            current = [];
            return;
        }
        current.push(`${x(index).toFixed(1)},${y(point.value).toFixed(1)}`);
    });
    if (current.length) out.push(current);
    return out;
});

const barWidth = computed(() => Math.max(2, Math.min(18, slot.value - 2)));

// About five date labels, whatever the width.
const labelEvery = computed(() => Math.max(1, Math.ceil(points.value.length / Math.max(2, Math.floor(plotW.value / 110)))));
const formatDay = (date) => new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });

const onMove = (event) => {
    const rect = event.currentTarget.getBoundingClientRect();
    const index = Math.floor((event.clientX - rect.left - pad.left) / slot.value);
    hover.value = index >= 0 && index < points.value.length ? index : null;
};

const hovered = computed(() => (hover.value === null ? null : points.value[hover.value]));
const latest = computed(() => [...points.value].reverse().find((point) => point.value !== null)?.value ?? null);
const tooltipLeft = computed(() => {
    if (hover.value === null) return 0;
    return Math.min(Math.max(x(hover.value), 70), width.value - 70);
});
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold">{{ trend.label }} <span class="text-sm font-normal text-slate-500 dark:text-slate-400">· last 30 days</span></h2>
            <p v-if="latest !== null && !isBars" class="text-sm text-slate-600 dark:text-slate-300">Now <span class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ latest }}</span></p>
            <p v-else-if="isBars" class="text-sm text-slate-600 dark:text-slate-300">Total <span class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ values.reduce((a, b) => a + b, 0) }}</span></p>
        </div>

        <div ref="box" class="relative mt-3">
            <svg
                :width="width"
                :height="height"
                class="block touch-none select-none"
                role="img"
                :aria-label="`${trend.label} over the last 30 days`"
                @mousemove="onMove"
                @mouseleave="hover = null"
            >
                <g class="text-slate-200 dark:text-slate-800">
                    <line v-for="tick in ticks" :key="tick" :x1="pad.left" :x2="width - pad.right" :y1="y(tick)" :y2="y(tick)" stroke="currentColor" stroke-width="1" />
                </g>
                <g class="fill-slate-500 text-[10px] dark:fill-slate-400">
                    <text v-for="tick in ticks" :key="`t${tick}`" :x="pad.left - 6" :y="y(tick) + 3" text-anchor="end">{{ tick }}</text>
                    <template v-for="(point, index) in points" :key="point.date">
                        <text v-if="index % labelEvery === 0" :x="x(index)" :y="height - 6" text-anchor="middle">{{ formatDay(point.date) }}</text>
                    </template>
                </g>

                <line v-if="hover !== null" :x1="x(hover)" :x2="x(hover)" :y1="pad.top" :y2="pad.top + plotH" class="text-slate-300 dark:text-slate-600" stroke="currentColor" stroke-width="1" />

                <g v-if="isBars" class="text-blue-600 dark:text-blue-400">
                    <template v-for="(point, index) in points" :key="`b${point.date}`">
                        <rect
                            v-if="point.value > 0"
                            :x="x(index) - barWidth / 2"
                            :y="y(point.value)"
                            :width="barWidth"
                            :height="pad.top + plotH - y(point.value)"
                            rx="2"
                            fill="currentColor"
                            :opacity="hover === null || hover === index ? 1 : 0.45"
                        />
                    </template>
                </g>
                <g v-else class="text-blue-600 dark:text-blue-400">
                    <polyline v-for="(segment, index) in segments" :key="`s${index}`" :points="segment.join(' ')" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                    <circle v-if="hovered?.value !== null && hovered" :cx="x(hover)" :cy="y(hovered.value)" r="4.5" fill="currentColor" class="stroke-white dark:stroke-slate-900" stroke-width="2" />
                </g>
            </svg>

            <div
                v-if="hovered"
                class="pointer-events-none absolute top-0 -translate-x-1/2 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800"
                :style="{ left: `${tooltipLeft}px` }"
            >
                <p class="text-slate-500 dark:text-slate-400">{{ formatDay(hovered.date) }}</p>
                <p class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ hovered.value ?? 'No score yet' }}<span v-if="hovered.value !== null && isBars" class="font-normal text-slate-500"> threats</span></p>
            </div>

            <p v-if="!hasData" class="absolute inset-0 flex items-center justify-center text-sm text-slate-500">
                {{ isBars ? 'No threats found in the last 30 days.' : 'Run a scan to start the score history.' }}
            </p>
        </div>

        <table class="sr-only">
            <caption>{{ trend.label }} by day</caption>
            <thead><tr><th>Date</th><th>{{ trend.label }}</th></tr></thead>
            <tbody>
                <tr v-for="point in points" :key="`r${point.date}`"><td>{{ point.date }}</td><td>{{ point.value ?? '—' }}</td></tr>
            </tbody>
        </table>
    </div>
</template>
