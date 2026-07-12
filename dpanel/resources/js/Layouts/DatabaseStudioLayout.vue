<script setup>
const props = defineProps({
    server: {
        type: Object,
        default: () => ({}),
    },
    title: {
        type: String,
        default: 'Database Studio',
    },
});
</script>

<template>
    <div class="h-screen overflow-hidden bg-[#f5f6f7] text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div class="absolute inset-0 -z-10 overflow-hidden">
            <div class="absolute inset-x-0 top-0 h-16 border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"></div>
        </div>

        <div class="flex h-full min-h-0 flex-col">
            <main class="min-h-0 flex-1 overflow-hidden p-0">
                <slot />
            </main>
        </div>
    </div>
</template>
