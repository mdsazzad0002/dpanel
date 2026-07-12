<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    server: {
        type: Object,
        default: () => ({}),
    },
    selectedDatabase: {
        type: String,
        default: '',
    },
    headerMode: {
        type: String,
        default: 'overview',
    },
    overviewActiveTab: {
        type: String,
        default: 'About',
    },
    activeAction: {
        type: String,
        default: '',
    },
    theme: {
        type: String,
        default: 'light',
    },
    dashboardHref: {
        type: String,
        default: '',
    },
    logoutHref: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['toggle-theme', 'overview-select', 'toolbar-action']);

const overviewTabs = [
    { label: 'Databases', icon: 'bi bi-server' },
    { label: 'SQL', icon: 'bi bi-filetype-sql' },
    { label: 'Transfer', icon: 'bi bi-arrow-left-right' },
    { label: 'Status', icon: 'bi bi-activity' },
    { label: 'User accounts', icon: 'bi bi-people' },
    { label: 'Settings', icon: 'bi bi-gear' },
    { label: 'Replication', icon: 'bi bi-diagram-3' },
    { label: 'Variables', icon: 'bi bi-sliders' },
    { label: 'Charsets', icon: 'bi bi-fonts' },
    { label: 'More', icon: 'bi bi-three-dots' },
];

const compactMenuItems = [
    { label: 'Browse', key: 'browse', icon: 'bi bi-table' },
    { label: 'Structure', key: 'structure', icon: 'bi bi-diagram-3' },
    { label: 'Search', key: 'search', icon: 'bi bi-search' },
    { label: 'Create', key: 'create', icon: 'bi bi-plus-circle-dotted' },
    { label: 'Insert', key: 'insert', icon: 'bi bi-plus-square' },
    { label: 'SQL', key: 'sql', icon: 'bi bi-filetype-sql' },
    { label: 'Export', key: 'export', icon: 'bi bi-box-arrow-up-right' },
    { label: 'Import', key: 'import', icon: 'bi bi-box-arrow-in-down-left' },
    { label: 'Operations', key: 'operations', icon: 'bi bi-tools' },
];

const connectionLabel = computed(() => {
    const database = props.selectedDatabase || props.server?.current_database || 'Database Studio';
    const host = props.server?.host || '127.0.0.1';
    const port = props.server?.port || '3306';

    return {
        database,
        portInfo: `${host}:${port}`,
    };
});

const topbarMenuItems = computed(() => {
    const items = props.headerMode === 'overview'
        ? overviewTabs.map((tab) => ({
            label: tab.label,
            icon: tab.icon,
            hint: 'Switch overview tab',
            active: tab.label === props.overviewActiveTab,
            action: () => emit('overview-select', tab.label),
        }))
        : compactMenuItems.map((item) => ({
            ...item,
            active: item.key === props.activeAction,
            action: () => emit('toolbar-action', item.key),
        }));

    if (props.overviewActiveTab === 'Databases') {
        items.unshift({
            label: 'Open Databases',
            icon: 'bi bi-server',
            active: false,
            action: () => emit('overview-select', 'Databases'),
        });
    }

    return items;
});
</script>

<template>
    <div class="mb-3 ">
        <div class="flex items-center justify-between gap-3 border-b border-slate-300 bg-white px-2  dark:border-slate-800 dark:bg-slate-900">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-blue-700 dark:text-blue-300">
                        {{ connectionLabel.database }}
                    </span>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400">
                        {{ connectionLabel.portInfo }}
                    </span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Link
                    v-if="dashboardHref"
                    :href="dashboardHref"
                    class="border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    Back to DPanel
                </Link>
                <Link
                    v-if="logoutHref"
                    :href="logoutHref"
                    method="post"
                    as="button"
                    class="border border-rose-300 bg-rose-50 px-2.5 py-1 text-[11px] font-medium text-rose-700 shadow-sm transition hover:bg-rose-100 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-200 dark:hover:bg-rose-950/50"
                >
                    Logout
                </Link>
                <button
                    type="button"
                    class="border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-700 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                    @click="emit('toggle-theme')"
                >
                    {{ theme === 'dark' ? 'Light' : 'Dark' }}
                </button>
            </div>
        </div>

        <div class="overflow-x-auto border border-slate-100 bg-white dark:border-slate-800 dark:bg-slate-900" style="scrollbar-gutter: stable;">
            <div class="flex w-max min-w-full ">
                <button
                    v-for="item in topbarMenuItems"
                    :key="item.label"
                    type="button"
                    class="inline-flex min-w-max items-center gap-2 border-r px-3 py-1.5 text-left text-xs font-medium transition"
                    :class="item.active
                        ? 'border-blue-500 bg-white text-blue-700 dark:border-blue-400 dark:bg-slate-900 dark:text-blue-300'
                        : 'border-slate-300 bg-slate-50 text-slate-700 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300 dark:hover:border-blue-700 dark:hover:text-blue-300'"
                    @click="item.action()"
                >
                    <i :class="item.icon || 'bi bi-dot'" class="text-[12px]"></i>
                    <span>{{ item.label }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
