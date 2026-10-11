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

const quotas = computed(() => {
    const s = props.summary ?? {};
    return [
        { label: 'Websites', icon: 'bi-globe', bar: 'bg-emerald-500', iconColor: 'text-emerald-500', used: s.websites_used, limit: s.websites_limit },
        { label: 'Website disk', icon: 'bi-hdd', bar: 'bg-blue-500', iconColor: 'text-blue-500', used: s.disk_used_mb, limit: s.disk_limit_mb, format: formatMb },
        { label: 'Databases', icon: 'bi-database', bar: 'bg-amber-500', iconColor: 'text-amber-500', used: s.databases_used, limit: s.databases_limit },
        { label: 'Mailboxes', icon: 'bi-envelope', bar: 'bg-violet-500', iconColor: 'text-violet-500', used: s.mailboxes_used, limit: s.mailboxes_limit, note: `${formatMb(s.mail_storage_mb)} allocated storage` },
        { label: 'Bandwidth', icon: 'bi-activity', bar: 'bg-rose-500', iconColor: 'text-rose-500', used: s.bandwidth_used_gb, limit: s.bandwidth_limit_gb, format: formatBandwidth, note: `${s.bandwidth_status ?? ''} · ${s.bandwidth_requests ?? 0} requests` },
    ].map((q) => {
        const format = q.format ?? ((v) => Number(v || 0));
        const percent = quotaPercent(q.used, q.limit);
        return {
            ...q,
            usedLabel: format(q.used),
            limitLabel: isUnlimited(q.limit) ? 'Unlimited' : format(q.limit),
            percent,
            barClass: percent >= 90 ? 'bg-red-500' : q.bar,
            note: q.note ?? (isUnlimited(q.limit) ? 'No limit on this package' : `${percent}% used`),
        };
    });
});
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
        <header class="border-b border-slate-200 px-5 py-4 dark:border-slate-700">
            <template v-if="summary">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ summary.scope }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ summary.package_name ?? 'No package assigned' }}</h2>
                    <span v-if="summary.package_name" class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                        {{ summary.package_owner }} package
                    </span>
                </div>
            </template>
            <template v-else>
                <div class="h-3 w-32 animate-pulse rounded bg-slate-100 dark:bg-slate-800"></div>
                <div class="mt-2 h-5 w-44 animate-pulse rounded bg-slate-100 dark:bg-slate-800"></div>
            </template>
        </header>

        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="quota in quotas" :key="quota.label" class="px-5 py-3.5">
                <div class="flex items-center justify-between gap-3">
                    <span class="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
                        <i :class="['bi', quota.icon, quota.iconColor]"></i>{{ quota.label }}
                    </span>
                    <span v-if="summary" class="truncate text-sm font-semibold text-slate-900 dark:text-white">
                        {{ quota.usedLabel }} <span class="font-normal text-slate-400">/ {{ quota.limitLabel }}</span>
                    </span>
                    <span v-else class="h-4 w-20 animate-pulse rounded bg-slate-100 dark:bg-slate-800"></span>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div :class="['h-full rounded-full transition-all duration-700', quota.barClass]" :style="{ width: (summary ? quota.percent : 0) + '%' }"></div>
                </div>
                <p v-if="summary" class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">{{ quota.note }}</p>
            </li>
        </ul>

        <footer class="flex items-center gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3 dark:border-slate-700 dark:bg-slate-800/60">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                <i class="bi bi-shield-lock"></i>
            </span>
            <div class="min-w-0">
                <p class="text-xs text-slate-500 dark:text-slate-400">Last login</p>
                <p class="truncate text-sm">
                    <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ summary?.last_login_ip ?? 'Not recorded' }}</span>
                    <span class="text-slate-500 dark:text-slate-400"> · {{ formatLoginTime(summary?.last_login_at) }}</span>
                </p>
            </div>
        </footer>
    </section>
</template>
