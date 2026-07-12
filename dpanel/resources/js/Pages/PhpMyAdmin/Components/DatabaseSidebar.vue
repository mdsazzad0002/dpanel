<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    databases: {
        type: Array,
        default: () => [],
    },
    expandedDatabases: {
        type: Array,
        default: () => [],
    },
    selectedDatabase: {
        type: String,
        default: '',
    },
    selectedTable: {
        type: String,
        default: '',
    },
    tablesByDatabase: {
        type: Object,
        default: () => ({}),
    },
    loading: {
        type: Boolean,
        default: false,
    },
    filterText: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['toggle-database', 'select-table', 'filter-change']);
const activeTab = ref('recent');

const filteredDatabases = computed(() => {
    const needle = props.filterText.trim().toLowerCase();
    if (!needle) return props.databases;

    return props.databases.filter((database) => {
        const name = String(database?.name || '').toLowerCase();
        return name.includes(needle);
    });
});

const isExpanded = (name) => props.expandedDatabases.includes(name);

const toggleTab = (tab) => {
    activeTab.value = tab;
};
</script>

<template>
    <aside class="flex h-full min-h-0 flex-col overflow-hidden rounded border border-slate-300 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <div class="border-b border-slate-200 px-3 py-2 dark:border-slate-700">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded px-2 py-1 text-xs font-semibold"
                        :class="activeTab === 'recent' ? 'bg-slate-200 text-slate-900 dark:bg-slate-700 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400'"
                        @click="toggleTab('recent')"
                    >
                        Recent
                    </button>
                    <button
                        type="button"
                        class="rounded px-2 py-1 text-xs font-semibold"
                        :class="activeTab === 'favorites' ? 'bg-slate-200 text-slate-900 dark:bg-slate-700 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400'"
                        @click="toggleTab('favorites')"
                    >
                        Favorites
                    </button>
                </div>

            </div>
        </div>

        <div class="border-b border-slate-200 p-2 dark:border-slate-700">
            <div class="relative">
                <input
                    :value="filterText"
                    type="text"
                    class="w-full rounded border border-slate-300 bg-white py-1.5 pl-3 pr-8 text-sm outline-none focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    placeholder="Type to filter databases, Enter to search"
                    @input="emit('filter-change', $event.target.value)"
                >
                <span class="pointer-events-none absolute right-2 top-1.5 text-xs text-slate-400">?</span>
            </div>
        </div>

        <div
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2 pr-1"
            style="scrollbar-gutter: stable;"
        >
            <div v-if="loading" class="rounded border border-dashed border-slate-300 p-3 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                Loading databases...
            </div>

            <div v-else class="space-y-2">
                <div
                    v-for="database in filteredDatabases"
                    :key="database.name"
                    class="rounded border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-950/40"
                >
                    <div class="flex items-center">
                        <button
                            type="button"
                            class="flex flex-1 items-center gap-2 px-3 py-2 text-left text-sm font-semibold"
                            :class="database.name === selectedDatabase ? 'bg-cyan-50 text-cyan-800 dark:bg-cyan-950/30 dark:text-cyan-200' : 'text-slate-700 dark:text-slate-200'"
                            @click="emit('toggle-database', database.name)"
                        >
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded border border-slate-300 bg-white text-xs text-slate-700 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200">
                                {{ isExpanded(database.name) ? '-' : '+' }}
                            </span>
                            <span class="truncate">{{ database.name }}</span>
                        </button>
                        <span
                            v-if="database.is_current"
                            class="mr-3 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"
                        >
                            current
                        </span>
                    </div>

                    <div v-if="isExpanded(database.name)" class="border-t border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-900">
                        <div class="mb-2 text-xs text-slate-500 dark:text-slate-400">
                            {{ (tablesByDatabase[database.name] || []).length }} tables
                        </div>
                        <div class="space-y-1">
                            <button
                                v-for="table in (tablesByDatabase[database.name] || [])"
                                :key="table.name"
                                type="button"
                                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-cyan-50 dark:hover:bg-cyan-950/20"
                                :class="table.name === selectedTable ? 'bg-cyan-50 text-cyan-800 dark:bg-cyan-950/20 dark:text-cyan-200' : 'text-slate-700 dark:text-slate-300'"
                                @click="emit('select-table', { database: database.name, table: table.name })"
                            >
                                <span class="text-slate-400">&gt;</span>
                                <span class="truncate">{{ table.name }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="filteredDatabases.length === 0" class="rounded border border-dashed border-slate-300 p-3 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                    No databases available.
                </div>
            </div>
        </div>
    </aside>
</template>
