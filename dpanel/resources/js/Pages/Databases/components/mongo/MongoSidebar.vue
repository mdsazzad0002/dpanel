<script setup>
import { computed, ref, watch } from 'vue';
import { formatBytes } from './useMongoAdmin';

// The database / collection tree, like phpMyAdmin's left panel.
const props = defineProps({
    databases: { type: Array, default: () => [] },
    db: { type: String, default: '' },
    collection: { type: String, default: '' },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['select', 'new-database', 'refresh']);

const search = ref('');
const open = ref({});
const showSystem = ref(false);

watch(() => props.db, (db) => { if (db) open.value = { ...open.value, [db]: true }; }, { immediate: true });

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.databases
        .filter((database) => showSystem.value || !database.system || database.name === props.db)
        .map((database) => {
            const nameHit = !term || database.name.toLowerCase().includes(term);
            const collections = nameHit ? database.collections : database.collections.filter((c) => c.name.toLowerCase().includes(term));
            return { ...database, collections, hidden: !nameHit && collections.length === 0 };
        })
        .filter((database) => !database.hidden);
});

const toggle = (name) => { open.value = { ...open.value, [name]: !open.value[name] }; };
</script>

<template>
    <aside class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between gap-2 border-b border-slate-200 px-3 py-2 dark:border-slate-800">
            <span class="text-sm font-semibold">Databases</span>
            <span class="flex items-center gap-1">
                <button type="button" title="New database" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-slate-100" @click="emit('new-database')"><i class="bi bi-plus-lg"></i></button>
                <button type="button" title="Refresh" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-slate-100" @click="emit('refresh')"><i class="bi bi-arrow-clockwise" :class="{ 'animate-spin': loading }"></i></button>
            </span>
        </div>
        <div class="p-2">
            <input v-model="search" type="search" placeholder="Filter…" class="w-full rounded-md border-slate-300 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-950" />
        </div>
        <nav class="max-h-[65vh] overflow-y-auto px-1 pb-2 text-sm">
            <div v-for="database in visible" :key="database.name">
                <div
                    class="group flex cursor-pointer items-center gap-1 rounded-md px-2 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-800"
                    :class="db === database.name && !collection ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300' : ''"
                >
                    <button type="button" class="w-4 text-slate-400" :aria-label="open[database.name] ? 'Collapse' : 'Expand'" @click="toggle(database.name)">
                        <i class="bi" :class="open[database.name] ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                    </button>
                    <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-left" @click="emit('select', database.name, ''); open[database.name] = true">
                        <i class="bi bi-database text-amber-500"></i>
                        <span class="truncate font-medium">{{ database.name }}</span>
                        <span class="ml-auto shrink-0 text-[11px] text-slate-400">{{ formatBytes(database.size) }}</span>
                    </button>
                </div>
                <div v-if="open[database.name] || search" class="ml-6 border-l border-slate-200 pl-1 dark:border-slate-800">
                    <button
                        v-for="item in database.collections"
                        :key="item.name"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-md px-2 py-1 text-left hover:bg-slate-100 dark:hover:bg-slate-800"
                        :class="db === database.name && collection === item.name ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300' : 'text-slate-600 dark:text-slate-300'"
                        @click="emit('select', database.name, item.name)"
                    >
                        <i class="bi" :class="item.type === 'view' ? 'bi-eye text-violet-500' : 'bi-table text-slate-400'"></i>
                        <span class="truncate">{{ item.name }}</span>
                        <span class="ml-auto shrink-0 text-[11px] text-slate-400">{{ item.type === 'view' ? 'view' : item.count }}</span>
                    </button>
                    <p v-if="!database.collections.length" class="px-2 py-1 text-xs text-slate-400">No collections</p>
                </div>
            </div>
            <p v-if="!visible.length && !loading" class="px-3 py-4 text-center text-xs text-slate-400">No databases found.</p>
        </nav>
        <label class="flex items-center gap-2 border-t border-slate-200 px-3 py-2 text-xs text-slate-500 dark:border-slate-800">
            <input v-model="showSystem" type="checkbox" class="rounded border-slate-300 dark:border-slate-700" />
            Show admin, config and local
        </label>
    </aside>
</template>
