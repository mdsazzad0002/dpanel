<script setup>
defineProps({
    result: { type: Object, default: null },
});
</script>

<template>
    <div v-if="result" class="space-y-2">
        <div
            class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold"
            :class="result.ok
                ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
                : 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400'"
        >
            <i class="bi" :class="result.ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill'"></i>
            {{ result.ok ? 'All checks passed' : 'Check failed' }}
        </div>
        <ol class="space-y-1.5">
            <li v-for="(step, index) in result.steps" :key="index" class="flex gap-2 text-sm">
                <i class="bi mt-0.5 shrink-0" :class="step.ok ? 'bi-check-lg text-emerald-600' : 'bi-x-lg text-red-600'"></i>
                <div class="min-w-0">
                    <p class="font-medium">{{ step.label }}</p>
                    <p class="break-all font-mono text-xs text-slate-500">{{ step.detail }}</p>
                </div>
            </li>
        </ol>
    </div>
</template>
