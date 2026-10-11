<script setup>
// A titled panel, the building block of every Mongo Admin view.
defineProps({
    title: { type: String, default: '' },
    icon: { type: String, default: '' },
    flush: { type: Boolean, default: false },
});
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <header v-if="title || $slots.actions" class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2.5 dark:border-slate-800 dark:bg-slate-950/60">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
                <i v-if="icon" :class="icon" class="text-emerald-600 dark:text-emerald-400"></i>
                {{ title }}
                <slot name="badge" />
            </h2>
            <div class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
        </header>
        <div :class="flush ? '' : 'p-4'"><slot /></div>
    </section>
</template>
