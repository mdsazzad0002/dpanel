<script setup>
// A small line of hourly values; a dashed line marks the limit when there is one.
import { computed } from 'vue';

const props = defineProps({
    points: { type: Array, default: () => [] }, // [[iso time, value], …]
    limit: { type: Number, default: null },
    width: { type: Number, default: 320 },
    height: { type: Number, default: 56 },
});

const max = computed(() => Math.max(1, props.limit ?? 0, ...props.points.map((point) => point[1])) * 1.05);
const x = (index) => (props.points.length < 2 ? props.width : (index / (props.points.length - 1)) * props.width);
const y = (value) => props.height - (value / max.value) * props.height;
const path = computed(() => props.points.map((point, index) => `${index ? 'L' : 'M'}${x(index).toFixed(1)},${y(point[1]).toFixed(1)}`).join(' '));
</script>

<template>
    <svg :viewBox="`0 0 ${width} ${height}`" preserveAspectRatio="none" class="h-14 w-full overflow-visible" role="img">
        <line v-if="limit" x1="0" :x2="width" :y1="y(limit)" :y2="y(limit)" class="stroke-red-400" stroke-width="1" stroke-dasharray="4 3" vector-effect="non-scaling-stroke" />
        <path v-if="points.length" :d="path" fill="none" class="stroke-emerald-500" stroke-width="1.5" vector-effect="non-scaling-stroke" />
    </svg>
</template>
