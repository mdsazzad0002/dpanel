<script setup>
defineProps({
    domain: { type: String, default: '' },
    checks: { type: Array, default: () => [] },
    error: { type: String, default: '' },
});

const badge = {
    pass: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    warn: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    fail: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};
const label = { pass: 'OK', warn: 'Check', fail: 'Missing / wrong' };
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="font-semibold">DNS check for {{ domain }}</h2>
        <p class="mt-1 text-sm text-slate-500">Live lookup against public DNS (Cloudflare and Google), the way Gmail and other mail servers see it. A record saved a moment ago can take a minute to appear.</p>

        <div v-if="error" class="mt-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ error }}</div>

        <ul v-else class="mt-4 divide-y divide-slate-200 rounded-md border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
            <li v-for="check in checks" :key="check.key" class="flex flex-wrap items-start gap-3 px-4 py-3">
                <span :class="badge[check.status]" class="w-32 shrink-0 rounded px-2 py-1 text-center text-xs font-semibold">{{ label[check.status] }}</span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium">{{ check.label }}</p>
                    <p v-if="check.found" class="mt-0.5 break-all font-mono text-xs text-slate-500">{{ check.found }}</p>
                    <p v-if="check.hint" class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ check.hint }}</p>
                </div>
            </li>
        </ul>
    </section>
</template>
