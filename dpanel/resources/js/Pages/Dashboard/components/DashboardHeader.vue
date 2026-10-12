<script setup>
import { computed } from 'vue';

const props = defineProps({
    stats: { type: Object, default: null },
    userName: { type: String, default: '' },
});

// TODO: replace with the real GitHub repo / company / docs URLs.
const links = [
    { label: 'GitHub', href: 'https://github.com/mdsazzad0002/dpanel', icon: 'bi-github' },
    { label: 'Docs', href: 'https://dpanel.dengrweb.com', icon: 'bi-file-earmark-text' },
    { label: 'Company', href: 'https://dengrweb.com', icon: 'bi-building' },
];

const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
});

const facts = computed(() => [
    { label: 'Host', value: props.stats?.hostname, icon: 'bi-hdd-rack' },
    { label: 'IP', value: props.stats?.server_ip, icon: 'bi-globe', mono: true },
    { label: 'OS', value: props.stats?.os, icon: 'bi-pc-display' },
    { label: 'Uptime', value: props.stats?.uptime, icon: 'bi-clock-history' },
]);
</script>

<template>
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">Dashboard</h1>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-400/30 bg-emerald-400/10 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Online
                </span>
            </div>
            <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ greeting }}{{ userName ? `, ${userName}` : '' }}</p>
        </div>

        <div class="flex min-w-0 flex-wrap items-center gap-2">
            <span
                v-for="fact in facts"
                :key="fact.label"
                class="inline-flex max-w-full min-w-0 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs dark:border-white/10 dark:bg-slate-900"
                :title="fact.value"
            >
                <i :class="['bi shrink-0 text-emerald-500 dark:text-emerald-400', fact.icon]"></i>
                <span class="shrink-0 font-mono uppercase tracking-wider text-slate-400">{{ fact.label }}</span>
                <span v-if="stats" :class="['truncate font-medium text-slate-800 dark:text-slate-100', fact.mono && 'font-mono']">{{ fact.value || '—' }}</span>
                <span v-else class="h-3 w-14 animate-pulse rounded bg-slate-100 dark:bg-white/[0.06]"></span>
            </span>
            <span class="hidden h-6 w-px bg-slate-200 dark:bg-white/10 sm:block"></span>
            <a
                v-for="link in links"
                :key="link.label"
                :href="link.href"
                target="_blank"
                rel="noopener"
                :title="link.label"
                :aria-label="link.label"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:border-transparent hover:bg-gradient-to-br hover:from-emerald-400 hover:to-cyan-400 hover:text-slate-950 dark:border-white/10 dark:bg-slate-900 dark:text-slate-300"
            >
                <i :class="['bi', link.icon]"></i>
            </a>
        </div>
    </div>
</template>
