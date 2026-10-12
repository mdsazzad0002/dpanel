<script setup>
import { computed } from 'vue';

const props = defineProps({
    summary: { type: Object, default: null },
});

const formatMb = (mb) => {
    const value = Number(mb || 0);
    return value >= 1024 ? `${(value / 1024).toFixed(2)} GB` : `${value.toFixed(value % 1 ? 2 : 0)} MB`;
};
const formatBandwidth = (gb) => {
    const value = Number(gb || 0);
    return value >= 1 ? `${value.toFixed(2)} GB` : `${(value * 1024).toFixed(2)} MB`;
};
const isUnlimited = (limit) => limit === null || limit === undefined;
const quotaPercent = (used, limit) => {
    if (isUnlimited(limit) || Number(limit) <= 0) return 0;
    return Math.min(100, Math.round((Number(used || 0) / Number(limit)) * 100));
};

// Bars use the login's emerald → cyan gradient, switching to amber/red as a quota fills up.
const barClass = (percent) => {
    if (percent >= 90) return 'bg-red-500';
    if (percent >= 75) return 'bg-amber-500';
    return 'bg-gradient-to-r from-emerald-400 to-cyan-400';
};

const quotas = computed(() => {
    const s = props.summary ?? {};
    return [
        { label: 'Websites', icon: 'bi-globe', used: s.websites_used, limit: s.websites_limit },
        { label: 'Website disk', icon: 'bi-hdd', used: s.disk_used_mb, limit: s.disk_limit_mb, format: formatMb },
        { label: 'Databases', icon: 'bi-database', used: s.databases_used, limit: s.databases_limit },
        { label: 'Mailboxes', icon: 'bi-envelope', used: s.mailboxes_used, limit: s.mailboxes_limit, note: `${formatMb(s.mail_storage_mb)} allocated storage` },
        { label: 'Bandwidth', icon: 'bi-activity', used: s.bandwidth_used_gb, limit: s.bandwidth_limit_gb, format: formatBandwidth, note: `${s.bandwidth_status ?? ''} · ${s.bandwidth_requests ?? 0} requests` },
    ].map((q) => {
        const format = q.format ?? ((v) => Number(v || 0));
        const percent = quotaPercent(q.used, q.limit);
        return {
            ...q,
            usedLabel: format(q.used),
            limitLabel: isUnlimited(q.limit) ? 'Unlimited' : format(q.limit),
            percent,
            barClass: barClass(percent),
            note: q.note ?? (isUnlimited(q.limit) ? 'No limit on this package' : `${percent}% used`),
        };
    });
});
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900">
        <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3.5 sm:px-5 dark:border-white/10">
            <h2 class="font-mono text-xs uppercase tracking-[0.22em] text-slate-500 dark:text-slate-400">Statistics</h2>
            <span v-if="summary" class="truncate text-[11px] text-slate-500 dark:text-slate-400">{{ summary.scope }}</span>
            <span v-else class="h-3 w-24 animate-pulse rounded bg-slate-100 dark:bg-white/[0.06]"></span>
        </header>

        <!-- Two columns while full width (sm, and lg before the xl right rail); one in the rail. -->
        <ul class="grid gap-px bg-slate-100 sm:grid-cols-2 md:grid-cols-1 lg:grid-cols-2 xl:grid-cols-1 dark:bg-white/[0.06]">
            <li v-for="quota in quotas" :key="quota.label" class="min-w-0 bg-white sm:last:col-span-2 md:last:col-span-1 lg:last:col-span-2 xl:last:col-span-1 px-4 py-3.5 sm:px-5 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-3">
                    <span class="flex min-w-0 items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
                        <i :class="['bi shrink-0 text-emerald-500 dark:text-emerald-400', quota.icon]"></i>
                        <span class="truncate">{{ quota.label }}</span>
                    </span>
                    <span v-if="summary" class="shrink-0 text-right text-sm font-semibold text-slate-900 dark:text-white">
                        {{ quota.usedLabel }} <span class="font-normal text-slate-400">/ {{ quota.limitLabel }}</span>
                    </span>
                    <span v-else class="h-4 w-20 animate-pulse rounded bg-slate-100 dark:bg-white/[0.06]"></span>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/[0.06]">
                    <div :class="['h-full rounded-full transition-all duration-700', quota.barClass]" :style="{ width: (summary ? quota.percent : 0) + '%' }"></div>
                </div>
                <p v-if="summary" class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">{{ quota.note }}</p>
            </li>
        </ul>

    </section>
</template>
