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
    { label: 'Server', value: props.stats?.hostname, icon: 'bi-hdd-rack' },
    { label: 'IP Address', value: props.stats?.server_ip, icon: 'bi-globe', mono: true },
    { label: 'OS', value: props.stats?.os, icon: 'bi-pc-display' },
    { label: 'Uptime', value: props.stats?.uptime, icon: 'bi-clock-history' },
]);
</script>

<template>
    <section class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-800 p-5 text-white shadow-sm sm:p-6">
        <div class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/10 blur-2xl"></div>
        <div class="pointer-events-none absolute -bottom-20 left-1/3 h-48 w-48 rounded-full bg-indigo-400/20 blur-2xl"></div>

        <div class="relative">
            <p class="text-sm text-blue-100">{{ greeting }}{{ userName ? ',' : '' }}</p>
            <h2 class="mt-0.5 truncate text-xl font-bold sm:text-2xl">{{ userName || 'Welcome back' }}</h2>
            <p v-if="userEmail" class="mt-1 truncate text-sm text-blue-100/80">
                <i class="bi bi-envelope mr-1"></i>{{ userEmail }}
            </p>
        </div>

        <dl class="relative mt-5 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
            <div v-for="fact in facts" :key="fact.label" class="flex min-w-0 items-center gap-2.5 rounded-xl bg-white/10 px-3 py-2.5 ring-1 ring-white/15 backdrop-blur-sm">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white/15">
                    <i :class="['bi', fact.icon]"></i>
                </span>
                <div class="min-w-0">
                    <dt class="text-[11px] uppercase tracking-wide text-blue-100/80">{{ fact.label }}</dt>
                    <dd v-if="stats" :class="['truncate text-sm font-semibold', fact.mono && 'font-mono']" :title="fact.value">{{ fact.value || '—' }}</dd>
                    <dd v-else class="mt-1 h-3.5 w-20 animate-pulse rounded bg-white/25"></dd>
                </div>
            </div>
        </dl>
    </section>
</template>
