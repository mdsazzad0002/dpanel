<script setup>
import { computed, ref, nextTick, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    databases: {
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
    expandedDatabases: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['select-database', 'select-table', 'filter-change', 'toggle-database', 'table-action']);

const databaseName = (database) => (typeof database === 'string' ? database : String(database?.name || ''));

const databaseEntry = (database) => props.tablesByDatabase[databaseName(database)] || null;

const databaseTables = (database) => {
    const entry = databaseEntry(database);
    return Array.isArray(entry?.tables) ? entry.tables : [];
};

const tableCount = (database) => databaseTables(database).length;

const filteredDatabases = computed(() => {
    const needle = props.filterText.trim().toLowerCase();
    if (!needle) return props.databases;

    return props.databases.filter((database) => databaseName(database).toLowerCase().includes(needle));
});

const expandedDatabases = computed(() => new Set(props.expandedDatabases));
const tableFilters = ref({});

const isDatabaseExpanded = (database) => expandedDatabases.value.has(databaseName(database));

const tableFilterText = (database) => tableFilters.value[databaseName(database)] || '';

const setTableFilterText = (database, value) => {
    const name = databaseName(database);
    tableFilters.value = {
        ...tableFilters.value,
        [name]: value,
    };
};

const filteredTables = (database) => {
    const needle = tableFilterText(database).trim().toLowerCase();
    const tables = databaseTables(database);

    if (!needle) return tables;

    return tables.filter((table) => String(table?.name || '').toLowerCase().includes(needle));
};

const toggleDatabase = (database) => {
    emit('toggle-database', databaseName(database));
};

const handleDatabaseToggle = (database) => {
    toggleDatabase(database);
};

// Context menu state
const contextMenu = ref({ visible: false, x: 0, y: 0, table: '', database: '' });
const contextMenuRef = ref(null);

const showContextMenu = (event, table, database) => {
    event.preventDefault();
    contextMenu.value = {
        visible: true,
        x: event.clientX,
        y: event.clientY,
        table: table.name || table,
        database,
    };
};

const closeContextMenu = () => {
    contextMenu.value.visible = false;
};

const handleContextAction = (action) => {
    emit('table-action', { action, table: contextMenu.value.table, database: contextMenu.value.database });
    closeContextMenu();
};

onMounted(() => {
    document.addEventListener('click', closeContextMenu);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeContextMenu);
});
</script>

<template>
    <aside class="flex h-full min-h-0 flex-col overflow-hidden border-r border-slate-800 bg-[#0b1220]">
        <div class="border-b border-slate-800 bg-[#0f172a] px-3 py-3">
            <div class="flex items-center gap-2">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-blue-500 to-indigo-700 text-white shadow-lg shadow-blue-950/40">
                    <i class="bi bi-database text-sm"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-sm font-semibold tracking-tight text-slate-100">Database Studio</h2>
                        <span class="rounded-full border border-slate-700 bg-slate-900 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.16em] text-slate-400">Tree</span>
                    </div>
                    <p class="text-[10px] text-slate-500">{{ databases.length }} databases</p>
                </div>
            </div>
        </div>

        <div class="border-b border-slate-800 px-3 py-2">
            <div class="relative">
                <i class="bi bi-search pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-500"></i>
                <input
                    :value="filterText"
                    type="text"
                    class="w-full rounded-lg border border-slate-700 bg-[#111a2d] py-2 pl-8 pr-3 text-sm text-slate-100 outline-none placeholder:text-slate-500 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20"
                    placeholder="Filter databases..."
                    @input="emit('filter-change', $event.target.value)"
                >
            </div>
        </div>

        <div class="h-[calc(100vh-8rem)] overflow-y-auto overscroll-contain p-2">
            <div v-if="loading" class="flex items-center justify-center py-8">
                <div class="flex items-center gap-2 text-sm text-slate-400">
                    <i class="bi bi-arrow-repeat animate-spin"></i>
                    Loading...
                </div>
            </div>

            <div v-else class="">
                <div
                    v-for="database in filteredDatabases"
                    :key="databaseName(database)"
                    class="overflow-hidden rounded-sm px-1 transition-all duration-200"
                    :class="databaseName(database) === selectedDatabase ? 'bg-blue-500/10 ring-1 ring-blue-400/30' : ''"
                >
                    <div class="flex items-center gap-1  py-1">
                        <button
                            type="button"
                            class="flex  shrink-0 items-center justify-center rounded text-slate-500 transition-all duration-200 hover:bg-white/5 hover:text-slate-200"
                            @click="handleDatabaseToggle(database)"
                        >
                            <i :class="['bi text-xs transition-transform duration-200', isDatabaseExpanded(database) ? 'bi-chevron-down' : 'bi-chevron-right']"></i>
                        </button>

                        <button
                            type="button"
                            class="flex min-w-0 flex-1 items-center gap-2 rounded-md   text-left text-sm transition-colors hover:bg-white/5"
                            :class="databaseName(database) === selectedDatabase ? 'font-semibold text-cyan-200' : 'text-slate-300'"
                            @click="emit('select-database', databaseName(database))"
                        >
                            <i class="bi bi-database text-xs text-slate-500"></i>
                            <span class="truncate">{{ databaseName(database) }}</span>
                        </button>

                        <span
                            v-if="tableCount(database) > 0"
                            class="shrink-0 rounded-full border border-slate-700 bg-slate-900 px-1.5 py-0.5 text-[10px] font-medium text-slate-400"
                        >
                            {{ tableCount(database) }}
                        </span>
                    </div>

                    <!-- Tables List with smooth expand/collapse -->
                    <Transition name="expand">
                        <div
                            v-if="isDatabaseExpanded(database)"
                            class="border-t  border-slate-800 py-1 pl-2"
                        >
                            <div class="px-2 py-1">
                                <input
                                    :value="tableFilterText(database)"
                                    type="text"
                                    class="w-full rounded-md border border-slate-700 bg-[#111a2d] px-2 py-1.5 text-xs text-slate-100 outline-none placeholder:text-slate-500 focus:border-cyan-500"
                                    placeholder="Search tables..."
                                    @input="setTableFilterText(database, $event.target.value)"
                                >
                            </div>

                            <div class="">
                                <button
                                    v-for="table in filteredTables(database)"
                                    :key="table.name"
                                    type="button"
                                    class="group flex w-full items-center gap-2 rounded-md px-2 py-1 text-left text-sm transition-colors hover:bg-white/5"
                                    :class="table.name === selectedTable ? 'bg-cyan-500/10 font-medium text-cyan-200 ring-1 ring-cyan-400/20' : 'text-slate-400'"
                                    @click="emit('select-table', { database: databaseName(database), table: table.name })"
                                    @contextmenu="showContextMenu($event, table, databaseName(database))"
                                >
                                    <i class="bi bi-table text-xs text-slate-500"></i>
                                    <span class="truncate">{{ table.name }}</span>
                                    <span
                                        v-if="Number.isFinite(Number(table?.estimated_rows ?? 0))"
                                        class="ml-auto rounded-full border border-slate-700 bg-slate-900 px-1.5 py-0.5 text-[10px] font-medium text-slate-400"
                                    >
                                        ~{{ Number(table?.estimated_rows ?? 0).toLocaleString() }}
                                    </span>
                                    <i class="bi bi-three-dots-vertical text-[10px] text-slate-500 opacity-0 transition-opacity group-hover:opacity-100"></i>
                                </button>

                                <div v-if="filteredTables(database).length === 0 && tableFilterText(database)" class="px-2 py-2 text-xs text-slate-500">
                                    No tables match
                                </div>
                            </div>
                        </div>
                    </Transition>
                </div>

                <div v-if="filteredDatabases.length === 0" class="py-8 text-center text-sm text-slate-500">
                    <i class="bi bi-inbox text-2xl text-slate-600"></i>
                    <p class="mt-2">No databases found</p>
                </div>
            </div>
        </div>

        <!-- Context Menu -->
        <Teleport to="body">
            <div
                v-if="contextMenu.visible"
                ref="contextMenuRef"
                class="fixed z-50 min-w-[160px] rounded-lg border border-slate-700 bg-[#0b1220] py-1 shadow-[0_18px_40px_rgba(0,0,0,0.45)]"
                :style="{ left: contextMenu.x + 'px', top: contextMenu.y + 'px' }"
            >
                <div class="border-b border-slate-800 px-3 py-1.5">
                    <p class="text-[10px] font-semibold text-slate-500">{{ contextMenu.table }}</p>
                </div>
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-slate-300 hover:bg-white/5"
                    @click="handleContextAction('browse')"
                >
                    <i class="bi bi-table text-xs text-slate-500"></i> Browse
                </button>
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-slate-300 hover:bg-white/5"
                    @click="handleContextAction('structure')"
                >
                    <i class="bi bi-diagram-3 text-xs text-slate-500"></i> Structure
                </button>
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-slate-300 hover:bg-white/5"
                    @click="handleContextAction('insert')"
                >
                    <i class="bi bi-plus-square text-xs text-slate-500"></i> Insert
                </button>
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-slate-300 hover:bg-white/5"
                    @click="handleContextAction('sql')"
                >
                    <i class="bi bi-filetype-sql text-xs text-slate-500"></i> SQL
                </button>
                <div class="border-t border-slate-800"></div>
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-red-300 hover:bg-red-500/10"
                    @click="handleContextAction('drop')"
                >
                    <i class="bi bi-trash3 text-xs"></i> Drop
                </button>
            </div>
        </Teleport>
    </aside>
</template>

<style scoped>
.expand-enter-active,
.expand-leave-active {
    transition: all 0.2s ease;
    overflow: hidden;
}

.expand-enter-from,
.expand-leave-to {
    opacity: 0;
    max-height: 0;
}

.expand-enter-to,
.expand-leave-from {
    opacity: 1;
    max-height: 500px;
}
</style>
