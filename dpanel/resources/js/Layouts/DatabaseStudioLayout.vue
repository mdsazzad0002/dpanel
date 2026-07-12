<script setup>
import { onMounted, ref } from 'vue';

const props = defineProps({
    server: {
        type: Object,
        default: () => ({}),
    },
    title: {
        type: String,
        default: 'Database Studio',
    },
    headerMode: {
        type: String,
        default: 'compact',
    },
    overviewActiveTab: {
        type: String,
        default: 'about',
    },
});

const emit = defineEmits(['overview-select', 'toolbar-action']);

const theme = ref('light');
const THEME_KEY = 'serverpanel-theme';

const applyTheme = (mode) => {
    if (typeof document === 'undefined') return;
    document.documentElement.classList.toggle('dark', mode === 'dark');
};

const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(THEME_KEY, theme.value);
    }
    applyTheme(theme.value);
};

const quickActions = [
    { label: 'Structure', key: 'structure' },
    { label: 'SQL', key: 'sql' },
    { label: 'Search', key: 'search' },
    { label: 'Query', key: 'query' },
    { label: 'Export', key: 'export' },
    { label: 'Import', key: 'import' },
    { label: 'Operations', key: 'operations' },
    { label: 'Routines', key: 'routines' },
    { label: 'Events', key: 'events' },
    { label: 'Triggers', key: 'triggers' },
    { label: 'More', key: 'more' },
];

const overviewTabs = [
    { label: 'Databases', icon: 'bi bi-server' },
    { label: 'SQL', icon: 'bi bi-filetype-sql' },
    { label: 'Status', icon: 'bi bi-activity' },
    { label: 'User accounts', icon: 'bi bi-people' },
    { label: 'Export', icon: 'bi bi-box-arrow-up-right' },
    { label: 'Import', icon: 'bi bi-box-arrow-in-down-left' },
    { label: 'Settings', icon: 'bi bi-gear' },
    { label: 'Replication', icon: 'bi bi-diagram-3' },
    { label: 'Variables', icon: 'bi bi-sliders' },
    { label: 'Charsets', icon: 'bi bi-fonts' },
    { label: 'More', icon: 'bi bi-three-dots' },
];

onMounted(() => {
    if (typeof window === 'undefined') return;

    const saved = window.localStorage.getItem(THEME_KEY);
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    theme.value = saved === 'dark' || saved === 'light' ? saved : (prefersDark ? 'dark' : 'light');
    applyTheme(theme.value);
});
</script>

<template>
    <div class="h-screen overflow-hidden bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div class="absolute inset-0 -z-10 overflow-hidden">
            <div class="absolute inset-x-0 top-0 h-72 bg-gradient-to-b from-cyan-500/15 to-transparent dark:from-cyan-500/20"></div>
            <div class="absolute left-1/2 top-20 h-96 w-96 -translate-x-1/2 rounded-full bg-cyan-500/8 blur-3xl dark:bg-cyan-500/10"></div>
        </div>

        <div class="flex h-full min-h-0 flex-col">
            <header class="border-b border-slate-200 bg-white/95 backdrop-blur dark:border-white/10 dark:bg-slate-950/90">
                <div class="px-4 py-2.5 lg:px-6 lg:py-2">
                    <div v-if="headerMode === 'overview'" class="flex flex-col gap-2">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2.5">
                                    <div class="grid h-8 w-8 place-items-center rounded-lg bg-cyan-500/10 ring-1 ring-cyan-400/20 dark:bg-cyan-500/15 dark:ring-cyan-400/30">
                                        <i class="bi bi-database-fill text-sm text-cyan-600 dark:text-cyan-300"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-cyan-600/80 dark:text-cyan-300/80">First-party database module</p>
                                        <h1 class="text-base font-semibold leading-tight">{{ title }}</h1>
                                    </div>
                                </div>
                            </div>

                            <button
                                type="button"
                                class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-100 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10"
                                @click="toggleTheme"
                            >
                                {{ theme === 'dark' ? 'Light Mode' : 'Dark Mode' }}
                            </button>
                        </div>

                        <div class="overflow-x-auto">
                            <div class="flex min-w-max items-stretch gap-0">
                                <button
                                    v-for="tab in overviewTabs"
                                    :key="tab.label"
                                    type="button"
                                    class="flex items-center gap-2 border border-slate-300 bg-slate-50 px-4 py-2 text-sm font-medium text-slate-700 first:rounded-l last:rounded-r hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900/60 dark:text-slate-200 dark:hover:bg-slate-800"
                                    :class="tab.label === overviewActiveTab ? 'bg-slate-200 text-slate-900 dark:bg-slate-700 dark:text-slate-50' : ''"
                                    @click="emit('overview-select', tab.label)"
                                >
                                    <i :class="tab.icon" class="text-[13px]"></i>
                                    <span>{{ tab.label }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-else class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2.5">
                                <div class="grid h-8 w-8 place-items-center rounded-lg bg-cyan-500/10 ring-1 ring-cyan-400/20 dark:bg-cyan-500/15 dark:ring-cyan-400/30">
                                    <i class="bi bi-database-fill text-sm text-cyan-600 dark:text-cyan-300"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-cyan-600/80 dark:text-cyan-300/80">First-party database module</p>
                                    <h1 class="text-base font-semibold leading-tight">{{ title }}</h1>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-1.5">
                            <button
                                type="button"
                                class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-100 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10"
                                @click="toggleTheme"
                            >
                                {{ theme === 'dark' ? 'Light Mode' : 'Dark Mode' }}
                            </button>

                            <div class="max-w-full overflow-x-auto">
                                <div class="flex min-w-max gap-1.5">
                                    <button
                                        v-for="action in quickActions"
                                        :key="action.key"
                                        type="button"
                                        class="rounded border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-100 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10"
                                        @click="emit('toolbar-action', action.key)"
                                    >
                                        {{ action.label }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="min-h-0 flex-1 overflow-hidden p-0">
                <slot />
            </main>
        </div>
    </div>
</template>
