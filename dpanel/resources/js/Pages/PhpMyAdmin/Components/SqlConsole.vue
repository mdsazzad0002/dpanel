<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps({
    panelRoute: {
        type: Function,
        required: true,
    },
    selectedDatabase: {
        type: String,
        default: '',
    },
    initialSql: {
        type: String,
        default: '',
    },
    notify: {
        type: Function,
        default: null,
    },
    schemaTables: {
        type: Array,
        default: () => [],
    },
    autoFocus: {
        type: Boolean,
        default: false,
    },
    plain: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['executed']);

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const sql = ref(props.initialSql || '');
const textareaRef = ref(null);
const executing = ref(false);
const queryResult = ref(null);
const queryError = ref('');
const historyEntries = ref([]);
const historyOpen = ref(false);
const historyHeight = ref(56);
const consoleShell = ref(null);
const resizingHistory = ref(false);
const HISTORY_LIMIT = 12;
const HISTORY_PREFIX = 'serverpanel-phpmyadmin-sql-history';
const HISTORY_OPEN_PREFIX = 'serverpanel-phpmyadmin-sql-history-open';
const HISTORY_HEIGHT_PREFIX = 'serverpanel-phpmyadmin-sql-history-height';
const HISTORY_COLLAPSED = 56;
const HISTORY_EXPANDED = 200;
const HISTORY_MIN = 56;
const HISTORY_MAX = 360;

const hasResult = computed(() => queryResult.value !== null);
const historyKey = computed(() => HISTORY_PREFIX);
const historyOpenKey = computed(() => HISTORY_OPEN_PREFIX);
const historyHeightKey = computed(() => HISTORY_HEIGHT_PREFIX);
const footerHeight = computed(() => `${Math.max(historyHeight.value, HISTORY_COLLAPSED)}px`);
const footerExpanded = computed(() => historyHeight.value > HISTORY_COLLAPSED);
const keywords = [
    'SELECT',
    'FROM',
    'WHERE',
    'ORDER BY',
    'GROUP BY',
    'LIMIT',
    'INSERT INTO',
    'UPDATE',
    'DELETE FROM',
    'SHOW TABLES',
    'DESCRIBE',
    'EXPLAIN',
    'CREATE TABLE',
    'ALTER TABLE',
    'DROP TABLE',
    'TRUNCATE TABLE',
];

const normalizedTables = computed(() => (Array.isArray(props.schemaTables) ? props.schemaTables : []).map((table) => ({
    name: String(table?.name || ''),
    columns: Array.isArray(table?.columns) ? table.columns : [],
})));

const suggestionItems = computed(() => {
    const raw = String(sql.value || '');
    const compact = raw.replace(/\s+/g, ' ').trim();
    const upper = compact.toUpperCase();
    const items = [];
    const add = (label, value) => {
        if (!value) return;
        if (items.some((item) => item.value === value)) return;
        items.push({ label, value });
    };

    if (!compact) {
        add('SELECT * FROM table;', 'SELECT * FROM table;');
        add('SHOW TABLES;', 'SHOW TABLES;');
        add('DESCRIBE table;', 'DESCRIBE table;');
    }

    keywords.forEach((keyword) => {
        if (!compact || keyword.startsWith(upper) || upper.includes(keyword.split(' ')[0])) {
            add(keyword, keyword.endsWith(';') ? keyword : `${keyword} `);
        }
    });

    normalizedTables.value.slice(0, 6).forEach((table) => {
        add(`FROM ${table.name}`, `FROM \`${table.name}\``);
        add(`SELECT * FROM ${table.name}`, `SELECT * FROM \`${table.name}\` LIMIT 25;`);
        if (table.columns.length > 0) {
            add(`DESCRIBE ${table.name}`, `DESCRIBE \`${table.name}\`;`);
            add(`SELECT columns from ${table.name}`, `SELECT ${table.columns.slice(0, 4).map((column) => `\`${column}\``).join(', ')} FROM \`${table.name}\` LIMIT 25;`);
        }
    });

    return items.slice(0, 8);
});

const loadHistory = () => {
    if (typeof window === 'undefined') return;

    try {
        const raw = window.localStorage.getItem(historyKey.value);
        const parsed = raw ? JSON.parse(raw) : [];
        historyEntries.value = Array.isArray(parsed) ? parsed.slice(0, HISTORY_LIMIT) : [];
    } catch {
        historyEntries.value = [];
    }
};

const saveHistory = () => {
    if (typeof window === 'undefined') return;

    try {
        window.localStorage.setItem(historyKey.value, JSON.stringify(historyEntries.value.slice(0, HISTORY_LIMIT)));
    } catch {
        // Ignore storage failures.
    }
};

const loadHistoryOpen = () => {
    if (typeof window === 'undefined') return;

    try {
        historyOpen.value = window.localStorage.getItem(historyOpenKey.value) === '1';
        if (historyOpen.value && historyHeight.value <= HISTORY_COLLAPSED) {
            historyHeight.value = HISTORY_EXPANDED;
        }
    } catch {
        historyOpen.value = false;
    }
};

const saveHistoryOpen = () => {
    if (typeof window === 'undefined') return;

    try {
        window.localStorage.setItem(historyOpenKey.value, historyOpen.value ? '1' : '0');
    } catch {
        // Ignore storage failures.
    }
};

const loadHistoryHeight = () => {
    if (typeof window === 'undefined') return;

    try {
        const saved = Number(window.localStorage.getItem(historyHeightKey.value));
        if (Number.isFinite(saved)) {
            historyHeight.value = Math.min(HISTORY_MAX, Math.max(HISTORY_MIN, saved));
        }
    } catch {
        historyHeight.value = HISTORY_COLLAPSED;
    }
};

const saveHistoryHeight = () => {
    if (typeof window === 'undefined') return;

    try {
        window.localStorage.setItem(historyHeightKey.value, String(historyHeight.value));
    } catch {
        // Ignore storage failures.
    }
};

const setHistoryHeight = (value) => {
    historyHeight.value = Math.min(HISTORY_MAX, Math.max(HISTORY_MIN, value));
    historyOpen.value = historyHeight.value > HISTORY_COLLAPSED;
    saveHistoryOpen();
    saveHistoryHeight();
};

const expandHistory = () => {
    setHistoryHeight(historyOpen.value ? HISTORY_COLLAPSED : HISTORY_EXPANDED);
};

const stopResizeHistory = () => {
    if (!resizingHistory.value) return;

    resizingHistory.value = false;
    window.removeEventListener('pointermove', handleHistoryResizeMove);
    window.removeEventListener('pointerup', stopResizeHistory);
    window.removeEventListener('pointercancel', stopResizeHistory);
};

function handleHistoryResizeMove(event) {
    if (!consoleShell.value) return;

    const rect = consoleShell.value.getBoundingClientRect();
    const nextHeight = rect.bottom - event.clientY;
    setHistoryHeight(nextHeight);
}

const startHistoryResize = (event) => {
    event.preventDefault();
    resizingHistory.value = true;
    handleHistoryResizeMove(event);
    window.addEventListener('pointermove', handleHistoryResizeMove);
    window.addEventListener('pointerup', stopResizeHistory);
    window.addEventListener('pointercancel', stopResizeHistory);
};

const summarizeSql = (value) => {
    const compact = String(value || '').replace(/\s+/g, ' ').trim();
    if (compact.length <= 64) return compact || 'SQL';
    return `${compact.slice(0, 64)}...`;
};

const focusEditor = async () => {
    if (!props.autoFocus) return;
    await nextTick();
    textareaRef.value?.focus?.();
};

watch(
    () => props.initialSql,
    (value) => {
        sql.value = String(value || '');
    }
);

watch(
    () => props.selectedDatabase,
    () => {
        loadHistory();
        loadHistoryHeight();
        loadHistoryOpen();
    },
    { immediate: true }
);

watch(
    () => props.autoFocus,
    (value) => {
        if (value) {
            void focusEditor();
        }
    },
    { immediate: true }
);

const executeSql = async () => {
    executing.value = true;
    queryError.value = '';
    queryResult.value = null;

    try {
        const response = await fetch(props.panelRoute('phpmyadmin.execute'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                sql: sql.value,
                database: props.selectedDatabase || '',
            }),
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || !data?.ok) {
            queryError.value = data?.message || 'Query failed.';
            if (props.notify) {
                props.notify(queryError.value);
            }
            return;
        }

        queryResult.value = data.result || null;
        emit('executed', queryResult.value);

        const nextEntry = {
            sql: sql.value,
            label: summarizeSql(sql.value),
            database: props.selectedDatabase || '',
            mode: data.result?.mode || 'statement',
            duration_ms: data.result?.duration_ms ?? null,
            created_at: new Date().toISOString(),
        };

        historyEntries.value = [nextEntry, ...historyEntries.value.filter((entry) => entry.sql !== nextEntry.sql)].slice(0, HISTORY_LIMIT);
        saveHistory();
    } catch (error) {
        queryError.value = error?.message || 'Query failed.';
        if (props.notify) {
            props.notify(queryError.value);
        }
    } finally {
        executing.value = false;
    }
};

const useHistoryEntry = (entry) => {
    sql.value = String(entry?.sql || '');
};

const toggleHistory = () => {
    expandHistory();
};

onMounted(() => {
    loadHistory();
    loadHistoryHeight();
    loadHistoryOpen();
});
</script>

<template>
    <section
        ref="consoleShell"
        class="relative flex h-full min-h-0 flex-col p-5"
        :class="plain ? 'bg-transparent shadow-none' : 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900'"
    >
        <div class="flex min-h-0 flex-1 flex-col" :style="{ paddingBottom: footerHeight }">
            <div class="mb-4">
                <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">SQL Console</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Execute a single SQL statement against the active database.
                </p>
            </div>

            <div class="min-h-0 flex-1 overflow-auto pr-1">
                <textarea
                    ref="textareaRef"
                    v-model="sql"
                    rows="10"
                    class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-3 font-mono text-sm text-slate-800 outline-none transition focus:border-cyan-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    spellcheck="false"
                />

                <div v-if="suggestionItems.length > 0" class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">Suggestions</span>
                    <button
                        v-for="item in suggestionItems"
                        :key="item.value"
                        type="button"
                        class="rounded-full border border-slate-300 bg-white px-3 py-1 text-xs text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                        @click="sql = item.value"
                    >
                        {{ item.label }}
                    </button>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="rounded-full bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-700 disabled:opacity-60"
                        :disabled="executing"
                        @click="executeSql"
                    >
                        {{ executing ? 'Running...' : 'Execute' }}
                    </button>
                    <span class="text-xs text-slate-500 dark:text-slate-400">
                        Guarded by Laravel session and CSRF.
                    </span>
                </div>

                <p v-if="queryError" class="mt-3 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                    {{ queryError }}
                </p>

                <div v-if="hasResult" class="mt-4 space-y-3">
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                            <p class="text-xs uppercase tracking-[0.14em] text-slate-500">Mode</p>
                            <p class="mt-1 font-medium">{{ queryResult.mode }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                            <p class="text-xs uppercase tracking-[0.14em] text-slate-500">Duration</p>
                            <p class="mt-1 font-medium">{{ queryResult.duration_ms }} ms</p>
                        </div>
                    </div>

                    <div v-if="queryResult.mode === 'statement'" class="rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-800">
                        Affected rows: <strong>{{ queryResult.affected_rows }}</strong>
                    </div>

                    <div v-if="queryResult.mode === 'result'" class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-slate-50 text-xs uppercase tracking-[0.14em] text-slate-500 dark:bg-slate-800">
                                    <tr>
                                        <th v-for="column in queryResult.columns" :key="column" class="px-4 py-3">{{ column }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, rowIndex) in queryResult.rows" :key="rowIndex" class="border-t border-slate-200 dark:border-slate-800">
                                        <td v-for="column in queryResult.columns" :key="column" class="max-w-[200px] px-4 py-3 align-top">
                                            <span class="block truncate" :title="String(row[column] ?? '')">
                                                {{ row[column] ?? 'NULL' }}
                                            </span>
                                        </td>
                                    </tr>
                                    <tr v-if="queryResult.rows.length === 0">
                                        <td :colspan="Math.max(queryResult.columns.length, 1)" class="px-4 py-6 text-center text-slate-500 dark:text-slate-400">
                                            Query returned no rows.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="absolute inset-x-0 bottom-0 border-t border-slate-200 bg-white/98 shadow-[0_-12px_30px_rgba(15,23,42,0.08)] backdrop-blur dark:border-slate-800 dark:bg-slate-950/95"
                :style="{ height: footerHeight }"
            >
                <div
                    class="flex h-3 cursor-row-resize items-center justify-center"
                    @pointerdown="startHistoryResize"
                    @dblclick="expandHistory"
                >
                    <span class="h-1 w-12 rounded-full bg-slate-300 dark:bg-slate-600"></span>
                </div>

                <div class="flex h-[calc(100%-0.75rem)] flex-col px-4 pb-3">
                    <button
                        type="button"
                        class="mb-2 flex items-center justify-between gap-3 text-left"
                        @click="toggleHistory"
                    >
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">SQL History</h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">Click to toggle 200px. Drag to resize.</p>
                        </div>
                        <span class="rounded-full border border-slate-300 bg-white px-2 py-1 text-[11px] text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                            {{ props.selectedDatabase || 'global' }}
                        </span>
                    </button>

                    <div v-if="historyOpen" class="min-h-0 flex-1 space-y-2 overflow-auto pr-1">
                        <button
                            v-for="entry in historyEntries"
                            :key="`${entry.created_at}-${entry.sql}`"
                            type="button"
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-xs text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                            @click="useHistoryEntry(entry)"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate font-medium">{{ entry.label }}</span>
                                <span class="shrink-0 rounded-full bg-cyan-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-cyan-700 dark:bg-cyan-950/50 dark:text-cyan-300">
                                    {{ entry.mode }}
                                </span>
                            </div>
                            <div class="mt-1 flex items-center justify-between gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                                <span class="truncate">{{ entry.database || 'global' }}</span>
                                <span v-if="entry.duration_ms !== null">{{ entry.duration_ms }} ms</span>
                            </div>
                        </button>

                        <div v-if="historyEntries.length === 0" class="rounded-lg border border-dashed border-slate-300 px-3 py-2 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">
                            No history yet.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
