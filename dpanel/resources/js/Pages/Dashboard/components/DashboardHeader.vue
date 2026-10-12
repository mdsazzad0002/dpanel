<script setup>
import { computed } from 'vue';

defineProps({
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
</script>

<template>
    <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">Dashboard</h1>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-400/30 bg-emerald-400/10 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Online
                </span>
            </div>
            <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ greeting }}{{ userName ? `, ${userName}` : '' }}</p>
        </div>

        <div class="flex shrink-0 items-center gap-1.5">
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
