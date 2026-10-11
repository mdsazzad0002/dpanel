<script setup>
import Card from './Card.vue';

// mongo-express style key / value stats, in two columns on wide screens.
defineProps({
    title: { type: String, required: true },
    icon: { type: String, default: 'bi bi-bar-chart' },
    rows: { type: Array, default: () => [] }, // [[label, value], …]
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
});
</script>

<template>
    <Card :title="title" :icon="icon" flush>
        <p v-if="error" class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">{{ error }}</p>
        <p v-else-if="loading && !rows.length" class="px-4 py-3 text-sm text-slate-400">Loading…</p>
        <dl v-else class="grid text-sm sm:grid-cols-2">
            <div v-for="[label, value] in rows" :key="label" class="flex justify-between gap-4 border-b border-slate-100 px-4 py-2 dark:border-slate-800">
                <dt class="text-slate-500 dark:text-slate-400">{{ label }}</dt>
                <dd class="truncate text-right font-mono text-slate-800 dark:text-slate-100">{{ value }}</dd>
            </div>
        </dl>
    </Card>
</template>
