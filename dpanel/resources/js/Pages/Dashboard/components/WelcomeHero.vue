<script setup>
import { computed } from 'vue';

const props = defineProps({
    stats: { type: Object, default: null },
    userName: { type: String, default: '' },
    userEmail: { type: String, default: '' },
});

const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
});

const facts = computed(() => [
    { label: 'Server', value: props.stats?.hostname, icon: 'bi-hdd-rack', accent: 'text-emerald-300' },
    { label: 'IP Address', value: props.stats?.server_ip, icon: 'bi-globe', accent: 'text-sky-300', mono: true },
    { label: 'OS', value: props.stats?.os, icon: 'bi-pc-display', accent: 'text-cyan-300' },
    { label: 'Uptime', value: props.stats?.uptime, icon: 'bi-clock-history', accent: 'text-emerald-300' },
]);
</script>

<template>
    <!-- Same dark glass + emerald/cyan palette as the login screen (GuestLayout). -->
    <section class="relative overflow-hidden rounded-2xl border border-white/10 bg-[#03060f] p-4 text-slate-100 shadow-[0_20px_60px_rgba(0,0,0,0.25)] sm:rounded-3xl sm:p-6 lg:p-8">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.18),transparent_40%),radial-gradient(circle_at_bottom_right,rgba(59,130,246,0.16),transparent_35%),linear-gradient(160deg,rgba(4,18,15,0.98),rgba(4,10,20,0.92))]"></div>
        <div class="pointer-events-none absolute inset-0 opacity-40 [background-image:linear-gradient(rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.03)_1px,transparent_1px)] [background-size:48px_48px]"></div>
        <div class="pointer-events-none absolute -left-20 -top-20 h-56 w-56 rounded-full bg-emerald-500/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-24 right-0 h-64 w-64 rounded-full bg-sky-500/10 blur-3xl"></div>

        <div class="relative flex flex-col gap-1">
            <p class="font-mono text-[11px] uppercase tracking-[0.28em] text-emerald-300/80 sm:text-xs">{{ greeting }}</p>
            <h2 class="truncate text-xl font-semibold tracking-tight text-slate-50 sm:text-2xl lg:text-3xl">
                {{ userName || 'Welcome back' }}
            </h2>
            <p v-if="userEmail" class="flex min-w-0 items-center gap-1.5 text-sm text-slate-300">
                <i class="bi bi-envelope shrink-0 text-emerald-300/80"></i>
                <span class="truncate">{{ userEmail }}</span>
            </p>
            <div class="mt-3 h-px bg-gradient-to-r from-emerald-400/50 via-white/10 to-transparent"></div>
        </div>

        <dl class="relative mt-4 grid grid-cols-1 gap-2 min-[400px]:grid-cols-2 sm:gap-3 xl:grid-cols-4">
            <div v-for="fact in facts" :key="fact.label" class="flex min-w-0 items-center gap-3 rounded-xl border border-white/10 bg-white/[0.05] px-3 py-2.5 sm:rounded-2xl sm:px-4 sm:py-3">
                <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white/[0.06]', fact.accent]">
                    <i :class="['bi', fact.icon]"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <dt :class="['font-mono text-[10px] uppercase tracking-[0.2em]', fact.accent]">{{ fact.label }}</dt>
                    <dd v-if="stats" :class="['truncate text-sm font-semibold text-slate-100', fact.mono && 'font-mono']" :title="fact.value">{{ fact.value || '—' }}</dd>
                    <dd v-else class="mt-1 h-3.5 w-20 animate-pulse rounded bg-white/15"></dd>
                </div>
            </div>
        </dl>
    </section>
</template>
