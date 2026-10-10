<script setup>
import { computed } from 'vue';

const props = defineProps({
    report: { type: Object, required: true },
});
defineEmits(['inspect']);

const headers = computed(() => Object.entries(props.report.http.headers || {}).sort(([a], [b]) => a.localeCompare(b)));
const meta = computed(() => props.report.meta);
const statusClass = (status) => (status >= 200 && status < 300 ? 'text-emerald-600 dark:text-emerald-400' : status >= 300 && status < 400 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400');
const card = 'rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800';
const title = 'border-b border-slate-100 px-4 py-3 text-sm font-semibold dark:border-slate-700';
</script>

<template>
    <div class="space-y-4">
        <section :class="card">
            <h2 :class="title">Redirect chain</h2>
            <ol class="divide-y divide-slate-100 text-sm dark:divide-slate-700">
                <li v-for="(hop, i) in report.http.chain" :key="i" class="flex items-center gap-3 px-4 py-2">
                    <span class="w-10 shrink-0 font-mono font-semibold" :class="statusClass(hop.status)">{{ hop.status || '—' }}</span>
                    <span class="min-w-0 flex-1 truncate font-mono text-xs">{{ hop.url }}</span>
                    <span v-if="hop.time_ms" class="shrink-0 text-xs text-slate-400">{{ hop.time_ms }} ms</span>
                </li>
            </ol>
        </section>

        <section v-if="meta?.canonical && meta.canonical !== report.final_url" :class="card" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
            <span class="font-medium">Canonical</span>
            <span class="min-w-0 flex-1 truncate font-mono text-xs">{{ meta.canonical }}</span>
            <button type="button" @click="$emit('inspect', meta.canonical)" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-700">Inspect canonical</button>
        </section>

        <section :class="card">
            <h2 :class="title">Response headers</h2>
            <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-700">
                <div v-for="[name, value] in headers" :key="name" class="grid gap-1 px-4 py-1.5 sm:grid-cols-[16rem_1fr]">
                    <dt class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ name }}</dt>
                    <dd class="break-all font-mono text-xs">{{ value }}</dd>
                </div>
            </dl>
        </section>

        <section v-if="meta" :class="card">
            <h2 :class="title">Page signals</h2>
            <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-700">
                <div v-for="row in [
                    ['Title', meta.title],
                    ['Description', meta.description],
                    ['Language', meta.lang],
                    ['Meta robots', meta.robots],
                    ['Words', meta.words],
                    ['Links', `${meta.links.internal} internal · ${meta.links.external} external · ${meta.links.nofollow} nofollow`],
                    ['Structured data', meta.schema_types.join(', ')],
                ]" :key="row[0]" class="grid gap-1 px-4 py-2 sm:grid-cols-[12rem_1fr]">
                    <dt class="text-slate-500 dark:text-slate-400">{{ row[0] }}</dt>
                    <dd class="break-words">{{ row[1] === '' || row[1] === null ? '—' : row[1] }}</dd>
                </div>
            </dl>
        </section>

        <section v-if="meta?.hreflang?.length" :class="card">
            <h2 :class="title">hreflang</h2>
            <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-700">
                <div v-for="h in meta.hreflang" :key="h.lang + h.url" class="grid gap-1 px-4 py-1.5 sm:grid-cols-[8rem_1fr]">
                    <dt class="font-mono text-xs">{{ h.lang }}</dt>
                    <dd class="truncate font-mono text-xs text-slate-500 dark:text-slate-400">{{ h.url }}</dd>
                </div>
            </dl>
        </section>

        <section v-if="report.robots" :class="card">
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-700">
                <h2 class="text-sm font-semibold">robots.txt</h2>
                <span class="font-mono text-xs" :class="statusClass(report.robots.status)">HTTP {{ report.robots.status || '—' }}</span>
            </div>
            <pre v-if="report.robots.content" class="max-h-80 overflow-auto px-4 py-3 font-mono text-xs leading-5">{{ report.robots.content }}</pre>
            <p v-else class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">No robots.txt content.</p>
        </section>
    </div>
</template>
