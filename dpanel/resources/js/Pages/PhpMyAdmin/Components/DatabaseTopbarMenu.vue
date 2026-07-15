<script setup>
import { computed, ref, watch, onMounted, nextTick } from 'vue';

const props = defineProps({
    server: {
        type: Object,
        default: () => ({}),
    },
    selectedDatabase: {
        type: String,
        default: '',
    },
    selectedTable: {
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

const emit = defineEmits(['toggle-theme', 'overview-select', 'toolbar-action', 'navigate']);

const overviewTabs = [
    { label: 'Databases', icon: 'bi-server' },
    { label: 'SQL', icon: 'bi-filetype-sql' },
    { label: 'Transfer', icon: 'bi-arrow-left-right' },
    { label: 'Status', icon: 'bi-activity' },
    { label: 'User accounts', icon: 'bi-people' },
    { label: 'Settings', icon: 'bi-gear' },
    { label: 'Replication', icon: 'bi-diagram-3' },
    { label: 'Variables', icon: 'bi-sliders' },
    { label: 'Charsets', icon: 'bi-fonts' },
];

const compactMenuItems = [
    { label: 'Browse', key: 'browse', icon: 'bi-table', shortcut: 'B' },
    { label: 'Structure', key: 'structure', icon: 'bi-diagram-3', shortcut: 'S' },
    { label: 'Search', key: 'search', icon: 'bi-search', shortcut: 'F' },
    { label: 'Create', key: 'create', icon: 'bi-plus-circle-dotted', shortcut: 'N' },
    { label: 'Insert', key: 'insert', icon: 'bi-plus-square', shortcut: 'I' },
    { label: 'SQL', key: 'sql', icon: 'bi-filetype-sql', shortcut: 'Q' },
    { label: 'Export', key: 'export', icon: 'bi-box-arrow-up-right', shortcut: 'E' },
    { label: 'Import', key: 'import', icon: 'bi-box-arrow-in-down-left', shortcut: 'M' },
    { label: 'Operations', key: 'operations', icon: 'bi-tools', shortcut: 'O' },
];

const tabRefs = ref({});
const indicatorStyle = ref({});

const topbarMenuItems = computed(() => {
    return props.headerMode === 'overview'
        ? overviewTabs.map((tab) => ({
            label: tab.label,
            icon: tab.icon,
            active: tab.label === props.overviewActiveTab,
            action: () => emit('overview-select', tab.label),
        }))
        : compactMenuItems.map((item) => ({
            ...item,
            active: item.key === props.activeAction,
            action: () => emit('toolbar-action', item.key),
        }));
});

const updateIndicator = async () => {
    await nextTick();
    const activeIndex = topbarMenuItems.value.findIndex(item => item.active);
    if (activeIndex >= 0 && tabRefs.value[activeIndex]) {
        const el = tabRefs.value[activeIndex];
        indicatorStyle.value = {
            left: el.offsetLeft + 'px',
            width: el.offsetWidth + 'px',
        };
    }
};

watch(
    () => [props.overviewActiveTab, props.activeAction, props.headerMode],
    () => updateIndicator(),
    { immediate: true }
);

onMounted(() => {
    updateIndicator();
});

const setTabRef = (el, index) => {
    if (el) tabRefs.value[index] = el;
};
</script>

<template>
    <div class="relative overflow-hidden border border-slate-800 bg-[#0b1220]">
        <div class="relative flex overflow-x-auto" style="scrollbar-gutter: stable;">
            <div
                class="absolute bottom-0 h-0.5 bg-cyan-400 transition-all duration-300 ease-out"
                :style="indicatorStyle"
            ></div>

            <button
                v-for="(item, index) in topbarMenuItems"
                :key="item.label"
                :ref="(el) => setTabRef(el, index)"
                type="button"
                class="group relative inline-flex items-center gap-2 whitespace-nowrap px-4 py-2.5 text-sm font-medium transition-all duration-200"
                :class="item.active
                    ? 'bg-blue-500/10 text-cyan-200'
                    : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'"
                @click="item.action()"
            >
                <i :class="['bi text-sm transition-transform duration-200 group-hover:scale-110', item.icon]"></i>
                <span>{{ item.label }}</span>
                <span
                    v-if="item.shortcut"
                    class="ml-1 hidden rounded border border-slate-700 px-1 py-0.5 text-[9px] text-slate-500 opacity-0 transition-opacity group-hover:opacity-100"
                >
                    {{ item.shortcut }}
                </span>
            </button>
        </div>
    </div>
</template>
