<script setup>
import { computed } from 'vue';

const props = defineProps({
    connection: { type: Object, default: null },
    checking: { type: Boolean, default: false },
});

const badge = computed(() => {
    if (props.checking && !props.connection) return ['Checking…', 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'];
    return {
        connected: ['Connected', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'],
        pending: ['Change nameservers', 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200'],
        unregistered: ['Not registered', 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300'],
    }[props.connection?.status] || ['Unknown', 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'];
});
</script>

<template>
    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="badge[1]">{{ badge[0] }}</span>
</template>
