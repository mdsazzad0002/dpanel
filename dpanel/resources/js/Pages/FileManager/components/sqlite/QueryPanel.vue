<script setup>
import { ref, watch } from 'vue';
import ResultGrid from './ResultGrid.vue';
import SqlAiAssistant from './SqlAiAssistant.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    run: { type: Function, required: true },
    initialSql: { type: String, default: '' },
    editing: { type: Boolean, default: false },
    // Set when an AI model is available: (prompt, currentSql) => Promise<string>.
    askAi: { type: Function, default: null },
});

const emit = defineEmits(['changed']);

const sql = ref(props.initialSql);
const result = ref(null);
const error = ref('');
const running = ref(false);

watch(() => props.initialSql, (value) => { if (!sql.value.trim()) sql.value = value; });

const execute = async () => {
    if (!sql.value.trim() || running.value) return;
    running.value = true;
    error.value = '';
    try {
        result.value = await props.run('query', { sql: sql.value });
        if (result.value.wrote) emit('changed');
    } catch (e) {
        error.value = e.message;
        result.value = null;
    } finally {
        running.value = false;
    }
};

const onGenerated = (generated) => {
    sql.value = generated;
    result.value = null;
    error.value = '';
};

const askWithContext = (prompt) => props.askAi(prompt, sql.value);

const onKey = (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        execute();
    }
};
</script>

<template>
    <div class="space-y-2">
        <SqlAiAssistant v-if="askAi" :ask="askWithContext" @generated="onGenerated" />
        <textarea v-model="sql" @keydown="onKey" rows="6" spellcheck="false"
            placeholder="SELECT * FROM users WHERE email LIKE '%@example.com' LIMIT 50"
            class="w-full rounded-md border border-slate-300 p-2 font-mono text-xs dark:border-slate-600 dark:bg-slate-900"></textarea>
        <div class="flex flex-wrap items-center gap-2">
            <button @click="execute" :disabled="running || !sql.trim()"
                class="rounded-md bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-50">
                <i class="bi" :class="running ? 'bi-arrow-repeat inline-block animate-spin' : 'bi-play-fill'"></i> {{ t('fileManager.sqlite.query.run') }}
            </button>
            <span v-if="editing" class="text-xs text-amber-600">{{ t('fileManager.sqlite.query.editingHint') }}</span>
            <span v-else class="text-xs text-slate-400">{{ t('fileManager.sqlite.query.readOnlyHint') }}</span>
            <span v-if="result" class="ml-auto text-xs text-slate-500">
                <template v-if="result.wrote"><span class="text-emerald-600">{{ t('fileManager.sqlite.query.changed', { count: result.changes.toLocaleString() }) }}</span> · </template>
                {{ t('fileManager.sqlite.query.rows', { count: result.row_count.toLocaleString(), ms: result.duration_ms }) }}
                <span v-if="result.truncated" class="text-amber-600">· {{ t('fileManager.sqlite.query.truncated', { count: result.max_rows }) }}</span>
            </span>
        </div>

        <div v-if="error" class="rounded-md border border-red-200 bg-red-50 p-3 font-mono text-xs text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">{{ error }}</div>

        <ResultGrid v-if="result && result.columns.length" :columns="result.columns" :rows="result.rows" class="max-h-[60vh]" />
    </div>
</template>
