<script setup>
// One row's live DNS result in the Mail DNS Guide table.
defineProps({
    check: { type: Object, default: null },
    checking: { type: Boolean, default: false },
});

const badge = {
    pass: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    warn: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    fail: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};
const label = { pass: 'OK', warn: 'Check', fail: 'Wrong / missing' };
</script>

<template>
    <span v-if="checking" class="text-xs text-slate-500">Checking…</span>
    <span v-else-if="!check" class="text-xs text-slate-400">Not checked</span>
    <div v-else class="min-w-56 max-w-sm">
        <span :class="badge[check.status]" class="inline-block rounded px-2 py-0.5 text-xs font-semibold">{{ label[check.status] }}</span>
        <p v-if="check.found" class="mt-1 break-all font-mono text-xs text-slate-500">Found: {{ check.found }}</p>
        <p v-else-if="check.status !== 'pass'" class="mt-1 text-xs text-slate-500">Found: nothing</p>
        <p v-if="check.hint" class="mt-1 text-xs text-slate-700 dark:text-slate-300">{{ check.hint }}</p>
    </div>
</template>
