<script setup>
import { computed } from 'vue';

const props = defineProps({
    stats: { type: Object, default: null },
    summary: { type: Object, default: null },
    user: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
    showServer: { type: Boolean, default: false },
});

const initials = computed(() => String(props.user?.name ?? 'U')
    .split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase() ?? '').join('') || 'U');

const formatLoginTime = (value) => value
    ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : 'Not recorded';

const percentOf = (used, total) => (Number(total) > 0 ? Math.min(100, Math.round((Number(used || 0) / Number(total)) * 100)) : 0);
const loadBar = (p) => (p < 50 ? 'bg-emerald-500' : p < 75 ? 'bg-amber-500' : 'bg-red-500');

const rows = computed(() => [
    { label: 'Package', value: props.summary ? (props.summary.package_name ?? 'None') : null },
    { label: 'Last login', value: props.summary ? (props.summary.last_login_ip ?? 'Not recorded') : null, mono: true, sub: props.summary ? formatLoginTime(props.summary.last_login_at) : '' },
    { label: 'Hostname', value: props.stats ? props.stats.hostname : null },
    { label: 'Server IP', value: props.stats ? props.stats.server_ip : null, mono: true },
    { label: 'OS', value: props.stats ? props.stats.os : null },
    { label: 'Uptime', value: props.stats ? props.stats.uptime : null },
]);

const load = computed(() => {
    const s = props.stats ?? {};
    const cpu = Math.round(Number(s.cpu_load_percent ?? 0));
    const mem = percentOf(s.memory_used_mb, s.memory_total_mb);
    const disk = percentOf(s.disk_used_gb, s.disk_total_gb);
    return [
        { label: 'CPU', percent: cpu, detail: `${s.cpu_cores ?? '—'} cores` },
        { label: 'Memory', percent: mem, detail: `${s.memory_used_mb ?? 0} / ${s.memory_total_mb ?? 0} MB` },
        { label: 'Disk', percent: disk, detail: `${s.disk_used_gb ?? 0} / ${s.disk_total_gb ?? 0} GB` },
    ];
});
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900">
        <!-- Login-screen palette: dark navy, emerald/sky glow, faint grid. -->
        <header class="relative overflow-hidden bg-[#03060f] px-4 py-4 text-slate-100 sm:px-5">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.22),transparent_45%),radial-gradient(circle_at_bottom_right,rgba(59,130,246,0.18),transparent_40%)]"></div>
            <div class="pointer-events-none absolute inset-0 opacity-40 [background-image:linear-gradient(rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.03)_1px,transparent_1px)] [background-size:32px_32px]"></div>
            <p class="relative font-mono text-[10px] uppercase tracking-[0.28em] text-emerald-300/80">General information</p>
            <div class="relative mt-3 flex items-center gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-400 to-cyan-400 text-sm font-bold text-slate-950">{{ initials }}</span>
                <div class="min-w-0">
                    <p class="truncate font-semibold text-slate-50">{{ user?.name ?? 'User' }}</p>
                    <p class="truncate text-xs text-slate-300">{{ user?.email }}</p>
                </div>
            </div>
            <div v-if="roles.length" class="relative mt-3 flex flex-wrap gap-1.5">
                <span v-for="role in roles" :key="role" class="rounded-full border border-emerald-400/30 bg-emerald-400/10 px-2 py-0.5 text-[11px] font-medium capitalize text-emerald-200">{{ role.replace('_', ' ') }}</span>
            </div>
        </header>

        <dl class="divide-y divide-slate-100 dark:divide-white/[0.06]">
            <div v-for="row in rows" :key="row.label" class="flex items-start justify-between gap-4 px-4 py-2.5 sm:px-5">
                <dt class="shrink-0 pt-0.5 font-mono text-[10px] uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">{{ row.label }}</dt>
                <dd class="min-w-0 text-right">
                    <template v-if="row.value !== null">
                        <span :class="['block truncate text-sm font-medium text-slate-900 dark:text-slate-100', row.mono && 'font-mono']" :title="row.value">{{ row.value || '—' }}</span>
                        <span v-if="row.sub" class="block truncate text-[11px] text-slate-500 dark:text-slate-400">{{ row.sub }}</span>
                    </template>
                    <span v-else class="mt-1 inline-block h-3.5 w-24 animate-pulse rounded bg-slate-100 dark:bg-white/[0.06]"></span>
                </dd>
            </div>
        </dl>

        <div v-if="showServer" class="space-y-3 border-t border-slate-200 px-4 py-4 sm:px-5 dark:border-white/10">
            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Server load</p>
            <div v-for="item in load" :key="item.label">
                <div class="flex items-baseline justify-between gap-2 text-sm">
                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ item.label }}</span>
                    <span v-if="stats" class="truncate text-xs text-slate-500 dark:text-slate-400"><span class="font-semibold text-slate-900 dark:text-white">{{ item.percent }}%</span> · {{ item.detail }}</span>
                    <span v-else class="h-3 w-20 animate-pulse rounded bg-slate-100 dark:bg-white/[0.06]"></span>
                </div>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/[0.06]">
                    <div :class="['h-full rounded-full transition-all duration-700', loadBar(item.percent)]" :style="{ width: (stats ? item.percent : 0) + '%' }"></div>
                </div>
            </div>
        </div>
    </section>
</template>
