<script setup>
import { computed } from 'vue';

const props = defineProps({
    stats: { type: Object, default: null },
    summary: { type: Object, default: null },
    showServer: { type: Boolean, default: false },
});

const percentOf = (used, total) => (Number(total) > 0 ? Math.min(100, Math.round((Number(used || 0) / Number(total)) * 100)) : 0);
const isUnlimited = (limit) => limit === null || limit === undefined;
const formatMb = (mb) => {
    const value = Number(mb || 0);
    return value >= 1024 ? `${(value / 1024).toFixed(1)} GB` : `${Math.round(value)} MB`;
};

// Server load reads green → amber → red; quotas use the login's emerald → cyan until they fill up.
const loadBar = (p) => (p < 50 ? 'bg-emerald-500' : p < 75 ? 'bg-amber-500' : 'bg-red-500');
const quotaBar = (p) => (p >= 90 ? 'bg-red-500' : p >= 75 ? 'bg-amber-500' : 'bg-gradient-to-r from-emerald-400 to-cyan-400');
const loadText = (p) => (p < 50 ? 'text-slate-900 dark:text-slate-50' : p < 75 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400');

const quotaCell = (label, icon, used, limit, format = (v) => Number(v || 0)) => {
    const percent = isUnlimited(limit) ? 0 : percentOf(used, limit);
    return {
        label,
        icon,
        value: format(used),
        sub: isUnlimited(limit) ? 'of unlimited' : `of ${format(limit)}`,
        percent,
        bar: quotaBar(percent),
        text: 'text-slate-900 dark:text-slate-50',
        ready: props.summary !== null,
    };
};

const cells = computed(() => {
    const s = props.stats ?? {};
    const a = props.summary ?? {};
    const server = [];

    if (props.showServer) {
        const cpu = Math.round(Number(s.cpu_load_percent ?? 0));
        const mem = percentOf(s.memory_used_mb, s.memory_total_mb);
        const disk = percentOf(s.disk_used_gb, s.disk_total_gb);
        server.push(
            { label: 'CPU', icon: 'bi-cpu', value: `${cpu}%`, sub: `${s.cpu_cores ?? '—'} cores`, percent: cpu, bar: loadBar(cpu), text: loadText(cpu), ready: props.stats !== null },
            { label: 'Memory', icon: 'bi-memory', value: `${mem}%`, sub: `${formatMb(s.memory_used_mb)} / ${formatMb(s.memory_total_mb)}`, percent: mem, bar: loadBar(mem), text: loadText(mem), ready: props.stats !== null },
            { label: 'Disk', icon: 'bi-hdd', value: `${disk}%`, sub: `${s.disk_used_gb ?? 0} / ${s.disk_total_gb ?? 0} GB`, percent: disk, bar: loadBar(disk), text: loadText(disk), ready: props.stats !== null },
        );
    } else {
        server.push(quotaCell('Web disk', 'bi-hdd', a.disk_used_mb, a.disk_limit_mb, formatMb));
    }

    return [
        ...server,
        quotaCell('Websites', 'bi-globe', a.websites_used, a.websites_limit),
        quotaCell('Databases', 'bi-database', a.databases_used, a.databases_limit),
        quotaCell('Mailboxes', 'bi-envelope', a.mailboxes_used, a.mailboxes_limit),
    ];
});

// Viewport breakpoints, but the sidebar eats 288px from md up — so md steps back to 3 columns.
const columns = computed(() => (cells.value.length === 6
    ? 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-6'
    : 'grid-cols-2 lg:grid-cols-4'));
</script>

<template>
    <section aria-label="Overview" :class="['grid gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 dark:border-white/10 dark:bg-white/10', columns]">
        <div v-for="cell in cells" :key="cell.label" class="min-w-0 bg-white p-3.5 dark:bg-slate-900 sm:p-4">
            <div class="flex items-center justify-between gap-2">
                <span class="truncate font-mono text-[10px] uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">{{ cell.label }}</span>
                <i :class="['bi shrink-0 text-sm text-emerald-500 dark:text-emerald-400', cell.icon]"></i>
            </div>
            <template v-if="cell.ready">
                <p :class="['mt-1.5 truncate text-xl font-bold leading-tight sm:text-2xl', cell.text]">{{ cell.value }}</p>
                <p class="truncate text-[11px] text-slate-500 dark:text-slate-400">{{ cell.sub }}</p>
            </template>
            <template v-else>
                <div class="mt-2 h-6 w-14 animate-pulse rounded bg-slate-100 dark:bg-white/[0.06]"></div>
                <div class="mt-1.5 h-3 w-20 animate-pulse rounded bg-slate-100 dark:bg-white/[0.06]"></div>
            </template>
            <div class="mt-2.5 h-1 overflow-hidden rounded-full bg-slate-100 dark:bg-white/[0.06]">
                <div :class="['h-full rounded-full transition-all duration-700', cell.bar]" :style="{ width: (cell.ready ? cell.percent : 0) + '%' }"></div>
            </div>
        </div>
    </section>
</template>
