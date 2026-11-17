<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import TableData from './TableData.vue';
import TableStructure from './TableStructure.vue';
import QueryPanel from './QueryPanel.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    fm: { type: Object, required: true },
});

const item = computed(() => props.fm.sqliteItem);

const schema = ref(null);
const error = ref('');
const loading = ref(false);
const selected = ref('');
const tab = ref('data');
const filter = ref('');
const showInternal = ref(false);
// Off on every open: changing data is something to switch on deliberately.
const editing = ref(false);

const tables = computed(() => (schema.value?.tables || [])
    .filter((tbl) => showInternal.value || !tbl.internal)
    .filter((tbl) => !filter.value.trim() || tbl.name.toLowerCase().includes(filter.value.trim().toLowerCase())));
const current = computed(() => schema.value?.tables.find((tbl) => tbl.name === selected.value) || null);
const quoted = (name) => `"${name.replace(/"/g, '""')}"`;
const querySeed = computed(() => (current.value ? `SELECT * FROM ${quoted(current.value.name)} LIMIT 100` : ''));

/** One call to the read-only SQLite endpoint for the open file. */
async function run(action, extra = {}) {
    try {
        const { data } = await axios.post(
            props.fm.panelRoute('websites.sqlite.run', props.fm.fileManagerRouteParams()),
            { file: item.value.path, action, write: editing.value && action === 'query', ...extra },
        );
        return data.data;
    } catch (e) {
        const errors = e.response?.data?.errors || {};
        throw new Error(Object.values(errors)[0]?.[0] || e.response?.data?.message || t('fileManager.sqlite.viewer.requestFailed'));
    }
}

/** Ask the AI Gateway for one statement; only available when schema says so. */
async function askAi(prompt, currentSql) {
    try {
        const { data } = await axios.post(
            props.fm.panelRoute('websites.sqlite.ai', props.fm.fileManagerRouteParams()),
            { file: item.value.path, prompt, current_sql: currentSql, editing: editing.value },
        );
        return data.data.sql;
    } catch (e) {
        const errors = e.response?.data?.errors || {};
        throw new Error(Object.values(errors)[0]?.[0] || e.response?.data?.message || t('fileManager.sqlite.viewer.aiFailed'));
    }
}

/** Server-side hash for the row editor's format menu. */
async function convert(format, text) {
    try {
        const { data } = await axios.post(
            props.fm.panelRoute('websites.sqlite.convert', props.fm.fileManagerRouteParams()),
            { format, value_json: JSON.stringify(text ?? '') },
        );
        return data.data.value;
    } catch (e) {
        const errors = e.response?.data?.errors || {};
        throw new Error(Object.values(errors)[0]?.[0] || e.response?.data?.message || t('fileManager.sqlite.viewer.convertFailed'));
    }
}

async function loadSchema({ quiet = false } = {}) {
    if (!item.value) return;
    loading.value = !quiet;
    error.value = '';
    try {
        schema.value = await run('schema');
        if (!selected.value || !schema.value.tables.some((tbl) => tbl.name === selected.value)) {
            selected.value = schema.value.tables.find((tbl) => !tbl.internal)?.name || schema.value.tables[0]?.name || '';
        }
        if (!selected.value) tab.value = 'sql';
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function toggleEditing() {
    editing.value = !editing.value;
}

// After a write: refresh row counts and new/dropped tables, keep the view.
const onChanged = () => loadSchema({ quiet: true });

function pick(name) {
    selected.value = name;
    if (tab.value === 'sql') tab.value = 'data';
}

function close() {
    props.fm.closeSqlite();
}

function formatBytes(n) {
    if (!n) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
    return `${(n / 1024 ** i).toFixed(i ? 1 : 0)} ${units[i]}`;
}

watch(() => item.value?.path, (path) => {
    schema.value = null;
    selected.value = '';
    tab.value = 'data';
    filter.value = '';
    editing.value = false;
    if (path) loadSchema();
});

function onKeydown(event) {
    if (item.value && event.key === 'Escape') close();
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition-opacity duration-200" leave-active-class="transition-opacity duration-200">
        <div v-if="item" class="fixed inset-0 z-40 bg-black/40" @click="close"></div>
    </Transition>
    <Transition enter-from-class="translate-x-full" leave-to-class="translate-x-full" enter-active-class="transition-transform duration-300 ease-out" leave-active-class="transition-transform duration-200 ease-in">
        <aside
            v-if="item"
            class="fixed inset-y-0 right-0 z-40 flex w-full flex-col border-l border-slate-200 bg-white shadow-2xl lg:w-[85vw] 2xl:w-[75vw] dark:border-slate-800 dark:bg-slate-950 text-slate-800 dark:text-slate-200 dark:[color-scheme:dark]"
        >
            <header class="flex items-center gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-500 dark:bg-slate-800">
                    <i class="bi bi-database"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 truncate text-sm font-semibold text-slate-800 dark:text-slate-100">
                        <span class="truncate">{{ item.name }}</span>
                        <span v-if="editing" class="shrink-0 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-normal text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ t('fileManager.sqlite.viewer.editing') }}</span>
                        <span v-else class="shrink-0 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-normal text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">{{ t('fileManager.sqlite.viewer.readOnly') }}</span>
                    </p>
                    <p class="truncate text-[11px] text-slate-500">{{ item.path }}</p>
                </div>
                <button v-if="schema" type="button" role="switch" :aria-checked="editing" @click="toggleEditing"
                    class="flex items-center gap-2 rounded-md px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                    :title="editing ? t('fileManager.sqlite.viewer.writeOn') : t('fileManager.sqlite.viewer.writeOff')">
                    <span class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                        :class="editing ? 'bg-amber-500' : 'bg-slate-300 dark:bg-slate-600'">
                        <span class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform"
                            :class="editing ? 'translate-x-[18px]' : 'translate-x-0.5'"></span>
                    </span>
                    {{ t('fileManager.sqlite.viewer.write') }}
                </button>
                <button type="button" @click="loadSchema()" :disabled="loading" :title="t('common.refresh')"
                    class="rounded-md p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-800">
                    <i class="bi bi-arrow-clockwise" :class="loading ? 'inline-block animate-spin' : ''"></i>
                </button>
                <button type="button" @click="close" :title="t('fileManager.sqlite.closeEsc')"
                    class="rounded-md p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>

            <div v-if="loading && !schema" class="flex flex-1 items-center justify-center text-sm text-slate-400">
                <i class="bi bi-arrow-repeat mr-2 inline-block animate-spin"></i> {{ t('fileManager.sqlite.viewer.opening') }}
            </div>

            <div v-else-if="error && !schema" class="m-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">
                {{ error }}
            </div>

            <div v-else-if="schema" class="flex min-h-0 flex-1 flex-col md:flex-row">
                <nav class="flex max-h-48 shrink-0 flex-col border-b border-slate-200 md:max-h-none md:w-60 md:border-b-0 md:border-r dark:border-slate-800">
                    <div class="p-2">
                        <input v-model="filter" type="text" :placeholder="t('fileManager.sqlite.viewer.filter')"
                            class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-900" />
                    </div>
                    <div class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2">
                        <button v-for="tbl in tables" :key="tbl.name" type="button" @click="pick(tbl.name)"
                            class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs"
                            :class="selected === tbl.name ? 'bg-blue-600 text-white' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'">
                            <i class="bi" :class="tbl.type === 'view' ? 'bi-eye' : 'bi-table'"></i>
                            <span class="min-w-0 flex-1 truncate font-mono">{{ tbl.name }}</span>
                            <span v-if="tbl.row_count !== null" class="shrink-0 opacity-70">{{ tbl.row_count.toLocaleString() }}</span>
                        </button>
                        <p v-if="!tables.length" class="px-2 py-3 text-center text-xs text-slate-400">{{ t('fileManager.sqlite.viewer.noTables') }}</p>
                    </div>
                    <div class="hidden space-y-1 border-t border-slate-200 p-3 text-[11px] text-slate-500 md:block dark:border-slate-800">
                        <label class="flex items-center gap-1.5">
                            <input v-model="showInternal" type="checkbox" /> {{ t('fileManager.sqlite.viewer.showInternal') }}
                        </label>
                        <p>{{ formatBytes(schema.file_size) }} · {{ schema.journal_mode }} · {{ schema.encoding }}</p>
                        <p>SQLite {{ schema.sqlite_version }} · user_version {{ schema.user_version }}</p>
                    </div>
                </nav>

                <section class="flex min-h-0 min-w-0 flex-1 flex-col">
                    <div class="flex flex-wrap items-center gap-1 border-b border-slate-200 px-3 py-2 dark:border-slate-800">
                        <h2 v-if="current" class="mr-3 truncate font-mono text-sm font-semibold text-slate-800 dark:text-slate-100">{{ current.name }}</h2>
                        <button v-for="tabItem in [{ id: 'data', label: t('fileManager.sqlite.viewer.tabData'), icon: 'bi-grid-3x3' }, { id: 'structure', label: t('fileManager.sqlite.viewer.tabStructure'), icon: 'bi-diagram-3' }, { id: 'sql', label: 'SQL', icon: 'bi-terminal' }]"
                            :key="tabItem.id" type="button" @click="tab = tabItem.id" :disabled="!current && tabItem.id !== 'sql'"
                            class="rounded-md px-3 py-1 text-xs disabled:opacity-40"
                            :class="tab === tabItem.id ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'">
                            <i class="bi" :class="tabItem.icon"></i> {{ tabItem.label }}
                        </button>
                    </div>

                    <div class="min-h-0 flex-1 overflow-auto p-3">
                        <TableData v-if="tab === 'data' && current" :table="current" :run="run" :editing="editing" :convert="convert" @changed="onChanged" />
                        <TableStructure v-else-if="tab === 'structure' && current" :table="current" :run="run" :editing="editing" @changed="onChanged" />
                        <QueryPanel v-show="tab === 'sql'" :run="run" :initial-sql="querySeed" :editing="editing" :ask-ai="schema?.ai_available ? askAi : null" @changed="onChanged" />
                    </div>
                </section>
            </div>
        </aside>
    </Transition>
</template>
