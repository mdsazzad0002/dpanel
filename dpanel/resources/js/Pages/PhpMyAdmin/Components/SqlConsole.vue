<script setup>
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    panelRoute: {
        type: Function,
        required: true,
    },
    selectedDatabase: {
        type: String,
        default: '',
    },
    selectedTable: {
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
    historyOpenTrigger: {
        type: Number,
        default: 0,
    },
    plain: {
        type: Boolean,
        default: false,
    },
    dockOnly: {
        type: Boolean,
        default: false,
    },
    dockBottom: {
        type: String,
        default: 'var(--phpmyadmin-sql-history-height, 0px)',
    },
});

const emit = defineEmits(['executed', 'history-updated']);

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const sql = ref(props.initialSql || '');
const textareaRef = ref(null);
const executing = ref(false);
const queryResult = ref(null);
const queryError = ref('');
const historyEntries = ref([]);
const HISTORY_LIMIT = 20;
const HISTORY_PREFIX = 'serverpanel-phpmyadmin-sql-history';
const showTemplates = ref(false);
const expandedTable = ref(null);

const hasResult = computed(() => queryResult.value !== null);
const hasDatabaseContext = computed(() => Boolean(String(props.selectedDatabase || '').trim()) && normalizedTables.value.length > 0);
const hasSelectedTable = computed(() => Boolean(String(props.selectedTable || '').trim()));
const selectedTableName = computed(() => String(props.selectedTable || '').trim());
const historyKey = computed(() => HISTORY_PREFIX);

const keywords = [
    'SELECT', 'FROM', 'WHERE', 'ORDER BY', 'GROUP BY', 'HAVING',
    'LIMIT', 'OFFSET', 'INSERT INTO', 'VALUES', 'UPDATE', 'SET',
    'DELETE FROM', 'CREATE TABLE', 'ALTER TABLE', 'DROP TABLE',
    'TRUNCATE TABLE', 'SHOW TABLES', 'DESCRIBE', 'EXPLAIN',
    'JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN', 'ON',
    'AND', 'OR', 'NOT', 'IN', 'LIKE', 'BETWEEN', 'IS NULL', 'IS NOT NULL',
    'AS', 'DISTINCT', 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX',
    'ASC', 'DESC', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END',
];

const sqlTemplates = [
    { label: 'Select All', value: 'SELECT * FROM `table` LIMIT 100;', icon: 'bi-eye' },
    { label: 'Count Rows', value: 'SELECT COUNT(*) AS total FROM `table`;', icon: 'bi-123' },
    { label: 'Insert Row', value: 'INSERT INTO `table` (`col1`, `col2`) VALUES (\'val1\', \'val2\');', icon: 'bi-plus-circle' },
    { label: 'Update Rows', value: 'UPDATE `table` SET `column` = \'new_value\' WHERE condition;', icon: 'bi-pencil' },
    { label: 'Delete Rows', value: 'DELETE FROM `table` WHERE condition;', icon: 'bi-trash' },
    { label: 'Show Columns', value: 'SHOW COLUMNS FROM `table`;', icon: 'bi-list-columns' },
    { label: 'Show Index', value: 'SHOW INDEX FROM `table`;', icon: 'bi-key' },
    { label: 'Show Status', value: 'SHOW STATUS;', icon: 'bi-bar-chart' },
    { label: 'Show Variables', value: 'SHOW VARIABLES LIKE \'%timeout%\';', icon: 'bi-gear' },
    { label: 'Explain Query', value: 'EXPLAIN SELECT * FROM `table`;', icon: 'bi-info-circle' },
    { label: 'Create Table', value: 'CREATE TABLE `table_name` (\n  `id` INT AUTO_INCREMENT PRIMARY KEY,\n  `name` VARCHAR(255) NOT NULL,\n  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n);', icon: 'bi-table' },
    { label: 'Alter Column', value: 'ALTER TABLE `table` MODIFY `column` VARCHAR(255);', icon: 'bi-arrow-left-right' },
];

const normalizedTables = computed(() => (Array.isArray(props.schemaTables) ? props.schemaTables : []).map((table) => ({
    name: String(table?.name || ''),
    columns: Array.isArray(table?.columns) ? table.columns.map(c => typeof c === 'string' ? c : c.name || '') : [],
})));

const getContextAfterFrom = () => {
    const raw = String(sql.value || '');
    const upper = raw.toUpperCase();
    const fromIndex = upper.lastIndexOf('FROM');
    if (fromIndex === -1) return null;
    const afterFrom = raw.slice(fromIndex + 4).trim();
    const match = afterFrom.match(/`?(\w+)`?\s*$/);
    return match ? match[1].replace(/`/g, '') : null;
};

const suggestionItems = computed(() => {
    const raw = String(sql.value || '');
    const compact = raw.replace(/\s+/g, ' ').trim();
    const upper = compact.toUpperCase();
    const items = [];
    const add = (label, value, priority = 0) => {
        if (!value) return;
        if (items.some((item) => item.value === value)) return;
        items.push({ label, value, priority });
    };

    if (!compact) {
        add('SELECT * FROM table;', 'SELECT * FROM table;', 10);
        add('SHOW TABLES;', 'SHOW TABLES;', 9);
        add('DESCRIBE table;', 'DESCRIBE table;', 8);
        return items.slice(0, 8);
    }

    const lastWord = compact.split(/\s+/).pop()?.toUpperCase() || '';

    if (lastWord === 'SELECT' || upper.endsWith('SELECT')) {
        normalizedTables.value.forEach((table) => {
            add(`All columns from ${table.name}`, `SELECT ${table.columns.map(c => `\`${c}\``).join(', ')} FROM \`${table.name}\``, 10);
            add(`Count from ${table.name}`, `SELECT COUNT(*) AS total FROM \`${table.name}\``, 9);
        });
    }

    const tableAfterFrom = getContextAfterFrom();
    if (tableAfterFrom && (lastWord === 'FROM' || upper.endsWith('FROM'))) {
        normalizedTables.value.forEach((table) => {
            if (table.name.toLowerCase().startsWith(tableAfterFrom.toLowerCase())) {
                add(`Table: ${table.name}`, `\`${table.name}\``, 10);
                if (table.columns.length > 0) {
                    add(`All from ${table.name}`, `SELECT ${table.columns.slice(0, 5).map(c => `\`${c}\``).join(', ')} FROM \`${table.name}\` LIMIT 25`, 9);
                }
            }
        });
    }

    keywords.forEach((keyword) => {
        if (keyword.startsWith(lastWord) || upper.endsWith(keyword.split(' ')[0])) {
            add(keyword, keyword.endsWith(';') ? keyword : `${keyword} `, 5);
        }
    });

    normalizedTables.value.slice(0, 6).forEach((table) => {
        add(`FROM ${table.name}`, `FROM \`${table.name}\``, 4);
        add(`SELECT * FROM ${table.name}`, `SELECT * FROM \`${table.name}\` LIMIT 25;`, 4);
        if (table.columns.length > 0) {
            add(`DESCRIBE ${table.name}`, `DESCRIBE \`${table.name}\`;`, 3);
        }
    });

    return items.sort((a, b) => b.priority - a.priority).slice(0, 10);
});

const insertIntoEditor = async (text) => {
    const value = String(text || '');
    if (!value) return;

    const textarea = textareaRef.value;
    if (!textarea) {
        sql.value = `${sql.value}${value}`;
        return;
    }

    const start = textarea.selectionStart ?? sql.value.length;
    const end = textarea.selectionEnd ?? sql.value.length;
    const next = `${sql.value.slice(0, start)}${value}${sql.value.slice(end)}`;
    sql.value = next;

    await nextTick();
    const cursor = start + value.length;
    textarea.focus();
    textarea.setSelectionRange(cursor, cursor);
};

const formatSql = () => {
    let formatted = sql.value;
    const keywords = ['SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'ORDER BY', 'GROUP BY', 'HAVING', 'LIMIT', 'OFFSET', 'JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN', 'ON', 'INSERT INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE FROM', 'CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'TRUNCATE TABLE'];

    keywords.forEach(kw => {
        const regex = new RegExp(`\\b${kw.replace(' ', '\\s+')}\\b`, 'gi');
        formatted = formatted.replace(regex, `\n${kw}`);
    });

    formatted = formatted.replace(/,\s*/g, ',\n  ');
    formatted = formatted.replace(/^\n+/, '').trim();
    sql.value = formatted;
};

const clearEditor = () => {
    sql.value = '';
    queryResult.value = null;
    queryError.value = '';
    textareaRef.value?.focus();
};

const copyToClipboard = async () => {
    try {
        await navigator.clipboard.writeText(sql.value);
        if (props.notify) props.notify('SQL copied to clipboard');
    } catch {
        // Fallback
        const textarea = document.createElement('textarea');
        textarea.value = sql.value;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }
};

const exportToCsv = () => {
    if (!queryResult.value?.rows?.length) return;

    const headers = queryResult.value.columns.join(',');
    const rows = queryResult.value.rows.map(row =>
        queryResult.value.columns.map(col => {
            const val = String(row[col] ?? '');
            return val.includes(',') || val.includes('"') || val.includes('\n')
                ? `"${val.replace(/"/g, '""')}"`
                : val;
        }).join(',')
    );

    const csv = [headers, ...rows].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `query_result_${Date.now()}.csv`;
    link.click();
    URL.revokeObjectURL(url);
};

const exportToJson = () => {
    if (!queryResult.value?.rows?.length) return;

    const json = JSON.stringify(queryResult.value.rows, null, 2);
    const blob = new Blob([json], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `query_result_${Date.now()}.json`;
    link.click();
    URL.revokeObjectURL(url);
};

const toggleTableExpand = (tableName) => {
    expandedTable.value = expandedTable.value === tableName ? null : tableName;
};

const insertColumnIntoEditor = async (tableName, columnName) => {
    await insertIntoEditor(`\`${columnName}\``);
};

const handleKeydown = (event) => {
    if (event.ctrlKey || event.metaKey) {
        if (event.key === 'Enter') {
            event.preventDefault();
            executeSql();
        } else if (event.key === 'l') {
            event.preventDefault();
            clearEditor();
        } else if (event.key === 'f') {
            event.preventDefault();
            formatSql();
        }
    }

    if (event.key === 'Tab' && suggestionItems.value.length > 0) {
        event.preventDefault();
        insertIntoEditor(suggestionItems.value[0].value);
    }
};

const loadHistory = () => {
    if (typeof window === 'undefined') return;

    try {
        const raw = window.localStorage.getItem(historyKey.value);
        const parsed = raw ? JSON.parse(raw) : [];
        historyEntries.value = Array.isArray(parsed) ? parsed.slice(0, HISTORY_LIMIT) : [];
        emit('history-updated', historyEntries.value.slice(0, HISTORY_LIMIT));
    } catch {
        historyEntries.value = [];
        emit('history-updated', []);
    }
};

const saveHistory = () => {
    if (typeof window === 'undefined') return;

    try {
        window.localStorage.setItem(historyKey.value, JSON.stringify(historyEntries.value.slice(0, HISTORY_LIMIT)));
    } catch {
        // Ignore storage failures.
    }
    emit('history-updated', historyEntries.value.slice(0, HISTORY_LIMIT));
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
    if (!sql.value.trim()) return;
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
</script>

<template>
    <section
        class="relative flex min-h-0 flex-col"
        :class="[
            dockOnly ? 'fixed inset-x-0 z-30 h-auto p-0' : 'h-full p-5',
        plain ? 'bg-transparent shadow-none' : 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900',
        ]"
        :style="dockOnly ? { bottom: dockBottom } : undefined"
    >
        <div v-if="!dockOnly" class="flex min-h-0 flex-1 flex-col">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">SQL</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Execute SQL statements against the active database.
                        <span class="hidden sm:inline text-xs text-slate-400 ml-2">
                            Ctrl+Enter to execute | Ctrl+F to format | Ctrl+L to clear
                        </span>
                    </p>
                </div>
                <button
                    type="button"
                    class="shrink-0 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                    @click="showTemplates = !showTemplates"
                >
                    <i class="bi bi-file-earmark-code mr-1"></i>
                    Templates
                </button>
            </div>

            <div
                v-if="showTemplates"
                class="mb-3 rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-950"
            >
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Quick Templates</span>
                    <button type="button" class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300" @click="showTemplates = false">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="template in sqlTemplates"
                        :key="template.label"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-700 transition hover:border-cyan-300 hover:bg-cyan-50 hover:text-cyan-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-cyan-600 dark:hover:bg-slate-800"
                        :title="template.value"
                        @click="sql = template.value.replace(/`table`/g, `\`${selectedTableName || 'table_name'}\``)"
                    >
                        <i :class="`bi ${template.icon}`"></i>
                        {{ template.label }}
                    </button>
                </div>
            </div>

            <div
                class="pr-1"
                :class="hasDatabaseContext ? 'grid gap-3 lg:grid-cols-[minmax(0,1fr)_280px]' : ''"
            >
                <div>
                    <div class="relative">
                        <textarea
                            ref="textareaRef"
                            v-model="sql"
                            rows="6"
                            class="h-[stretch] w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-3 font-mono text-sm text-slate-800 outline-none transition focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            spellcheck="false"
                            placeholder="Type your SQL query here..."
                            @keydown="handleKeydown"
                        ></textarea>
                        <div class="absolute bottom-2 right-2 flex items-center gap-1">
                            <button
                                type="button"
                                class="rounded p-1.5 text-slate-400 transition hover:bg-slate-200 hover:text-slate-600 dark:hover:bg-slate-700"
                                title="Format SQL (Ctrl+F)"
                                @click="formatSql"
                            >
                                <i class="bi bi-code-slash text-sm"></i>
                            </button>
                            <button
                                type="button"
                                class="rounded p-1.5 text-slate-400 transition hover:bg-slate-200 hover:text-slate-600 dark:hover:bg-slate-700"
                                title="Copy to clipboard"
                                @click="copyToClipboard"
                            >
                                <i class="bi bi-clipboard text-sm"></i>
                            </button>
                            <button
                                type="button"
                                class="rounded p-1.5 text-slate-400 transition hover:bg-slate-200 hover:text-slate-600 dark:hover:bg-slate-700"
                                title="Clear (Ctrl+L)"
                                @click="clearEditor"
                            >
                                <i class="bi bi-x-lg text-sm"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <aside
                    v-if="hasDatabaseContext"
                    class="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-950"
                >
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                                Tables
                            </p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ props.selectedDatabase }}
                            </p>
                        </div>
                        <span class="text-[11px] text-slate-400 dark:text-slate-500">
                            {{ normalizedTables.length }}
                        </span>
                    </div>

                    <div class="max-h-[300px] space-y-1 overflow-auto pr-1">
                        <div
                            v-for="table in normalizedTables"
                            :key="table.name"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left text-xs transition"
                                :class="table.name === selectedTableName
                                    ? 'border-cyan-300 bg-cyan-50 text-cyan-800 dark:border-cyan-700 dark:bg-cyan-950/30 dark:text-cyan-200'
                                    : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-100 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800'"
                                @click="insertIntoEditor(`\`${table.name}\` `)"
                                @dblclick.prevent="toggleTableExpand(table.name)"
                            >
                                <span class="flex items-center gap-2">
                                    <i class="bi bi-table text-[10px] text-slate-400"></i>
                                    <span class="truncate font-medium">{{ table.name }}</span>
                                </span>
                                <span v-if="table.columns.length > 0" class="shrink-0 text-[10px] text-slate-400 dark:text-slate-500">
                                    <i class="bi bi-chevron-down text-[8px]" :class="expandedTable === table.name ? 'rotate-180' : ''"></i>
                                </span>
                            </button>
                            <div
                                v-if="expandedTable === table.name && table.columns.length > 0"
                                class="ml-3 mt-1 space-y-0.5 border-l border-slate-200 pl-2 dark:border-slate-700"
                            >
                                <button
                                    v-for="col in table.columns.slice(0, 10)"
                                    :key="col"
                                    type="button"
                                    class="flex w-full items-center gap-1.5 rounded px-2 py-1 text-left text-[11px] text-slate-600 transition hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                                    @click.stop="insertColumnIntoEditor(table.name, col)"
                                >
                                    <i class="bi bi-hash text-[9px] text-slate-400"></i>
                                    {{ col }}
                                </button>
                                <span v-if="table.columns.length > 10" class="block px-2 py-1 text-[10px] text-slate-400">
                                    +{{ table.columns.length - 10 }} more columns
                                </span>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>

            <div v-if="suggestionItems.length > 0" class="mt-3">
                <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">
                    Suggestions
                </span>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button
                        v-for="item in suggestionItems"
                        :key="item.value"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full border border-slate-300 bg-white px-3 py-1 text-xs text-slate-700 transition hover:border-cyan-300 hover:bg-cyan-50 hover:text-cyan-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-cyan-600 dark:hover:bg-slate-800"
                        @click="insertIntoEditor(item.value)"
                    >
                        <i v-if="item.value.includes('SELECT')" class="bi bi-eye text-[10px]"></i>
                        <i v-else-if="item.value.includes('INSERT')" class="bi bi-plus-circle text-[10px]"></i>
                        <i v-else-if="item.value.includes('UPDATE')" class="bi bi-pencil text-[10px]"></i>
                        <i v-else-if="item.value.includes('DELETE')" class="bi bi-trash text-[10px]"></i>
                        <i v-else class="bi bi-code text-[10px]"></i>
                        {{ item.label }}
                    </button>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-full bg-cyan-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-cyan-700 disabled:opacity-60"
                    :disabled="executing || !sql.trim()"
                    @click="executeSql"
                >
                    <i v-if="executing" class="bi bi-arrow-repeat animate-spin text-sm"></i>
                    <i v-else class="bi bi-play-fill text-sm"></i>
                    {{ executing ? 'Running...' : 'Execute' }}
                </button>
                <span class="text-xs text-slate-500 dark:text-slate-400">
                    Guarded by Laravel session and CSRF.
                </span>
            </div>

            <p
                v-if="queryError"
                class="mt-3 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300"
            >
                <i class="bi bi-exclamation-circle mr-1"></i>
                {{ queryError }}
            </p>

            <div v-if="hasResult" class="mt-4 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap gap-2 text-sm">
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950">
                            <span class="text-xs text-slate-500">Mode</span>
                            <span class="ml-2 font-medium text-slate-700 dark:text-slate-300">{{ queryResult.mode }}</span>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950">
                            <span class="text-xs text-slate-500">Duration</span>
                            <span class="ml-2 font-medium text-slate-700 dark:text-slate-300">{{ queryResult.duration_ms }} ms</span>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button
                            v-if="queryResult.mode === 'result' && queryResult.rows?.length > 0"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
                            @click="exportToCsv"
                        >
                            <i class="bi bi-download"></i>
                            CSV
                        </button>
                        <button
                            v-if="queryResult.mode === 'result' && queryResult.rows?.length > 0"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
                            @click="exportToJson"
                        >
                            <i class="bi bi-download"></i>
                            JSON
                        </button>
                    </div>
                </div>

                <div
                    v-if="queryResult.mode === 'statement'"
                    class="rounded-xl border border-lime-200 bg-lime-50 p-3 text-sm text-lime-800 dark:border-lime-900/50 dark:bg-lime-950/30 dark:text-lime-200"
                >
                    <i class="bi bi-check-circle mr-1"></i>
                    Affected rows: <strong>{{ queryResult.affected_rows }}</strong>
                </div>

                <div
                    v-if="queryResult.mode === 'result'"
                    class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800"
                >
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800">
                                <tr>
                                    <th class="min-w-[50px] px-4 py-3 font-semibold">{{ '#' }}</th>
                                    <th v-for="column in queryResult.columns" :key="column" class="min-w-[50px] px-4 py-3 font-semibold">
                                        {{ column }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, rowIndex) in queryResult.rows" :key="rowIndex" class="border-t border-slate-200 dark:border-slate-800">
                                    <td class="px-4 py-3 text-xs text-slate-400">{{ rowIndex + 1 }}</td>
                                    <td v-for="column in queryResult.columns" :key="column" class="max-w-[200px] px-4 py-3 align-top">
                                        <span
                                            class="block truncate rounded px-1 py-0.5"
                                            :class="row[column] === null ? 'italic text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-300'"
                                            :title="String(row[column] ?? '')"
                                        >
                                            {{ row[column] === null ? 'NULL' : row[column] }}
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="queryResult.rows.length === 0">
                                    <td
                                        :colspan="Math.max(queryResult.columns.length + 1, 1)"
                                        class="px-4 py-6 text-center text-slate-500 dark:text-slate-400"
                                    >
                                        Query returned no rows.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-slate-200 bg-slate-50 px-4 py-2 text-xs text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400">
                        {{ queryResult.rows.length }} row{{ queryResult.rows.length !== 1 ? 's' : '' }} returned
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
