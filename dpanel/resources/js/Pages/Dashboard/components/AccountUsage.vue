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
const formatLoginTime = (value) => value
    ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : 'Not recorded';

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
        <header class="relative overflow-hidden border-b border-slate-200 px-4 py-4 sm:px-5 dark:border-white/10">
            <div class="pointer-events-none absolute -right-10 -top-10 h-28 w-28 rounded-full bg-emerald-400/10 blur-2xl"></div>
            <template v-if="summary">
                <p class="relative font-mono text-[11px] uppercase tracking-[0.22em] text-emerald-600 dark:text-emerald-300/80">{{ summary.scope }}</p>
                <div class="relative mt-1 flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-50">{{ summary.package_name ?? 'No package assigned' }}</h2>
                    <span v-if="summary.package_name" class="rounded-full border border-emerald-400/30 bg-emerald-400/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-200">
                        {{ summary.package_owner }} package
                    </span>
                </div>
            </template>
            <template v-else>
                <div class="h-3 w-32 animate-pulse rounded bg-slate-100 dark:bg-white/[0.06]"></div>
                <div class="mt-2 h-5 w-44 animate-pulse rounded bg-slate-100 dark:bg-white/[0.06]"></div>
            </template>
        </header>

        <!-- Full-width below xl gets a second column; in the xl right rail it's a single list. -->
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

        <footer class="flex items-center gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5 dark:border-white/10 dark:bg-white/[0.03]">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-300">
                <i class="bi bi-shield-lock"></i>
            </span>
            <div class="min-w-0">
                <p class="font-mono text-[10px] uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Last login</p>
                <p class="truncate text-sm">
                    <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ summary?.last_login_ip ?? 'Not recorded' }}</span>
                    <span class="text-slate-500 dark:text-slate-400"> · {{ formatLoginTime(summary?.last_login_at) }}</span>
                </p>
            </div>
        </footer>
    </section>
</template>
