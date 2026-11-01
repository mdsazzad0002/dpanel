<script setup>
import { Link } from '@inertiajs/vue3';
import { toneOf } from './toolTone';

defineProps({
    item: { type: Object, required: true },
    color: { type: String, default: 'emerald' },
    tabindex: { type: Number, default: 0 },
});
</script>

<template>
    <!-- Fixed height and a two-line label cap keep every tile in the grid the same size. -->
    <Link
        :href="item.href"
        :title="item.hint ? `${item.label} — ${item.hint}` : item.label"
        :tabindex="tabindex"
        class="group/tool flex h-16 min-w-0 items-center gap-3 rounded-xl px-3 transition hover:bg-emerald-400/[0.07] focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400"
    >
        <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-lg transition group-hover/tool:scale-105', toneOf(color)]">
            <i :class="item.iconClass"></i>
        </span>
        <span class="line-clamp-2 min-w-0 text-sm font-medium leading-snug text-sky-700 group-hover/tool:text-slate-950 group-hover/tool:underline dark:text-sky-300 dark:group-hover/tool:text-white">
            {{ item.label }}
        </span>
        <slot />
    </Link>
</template>
