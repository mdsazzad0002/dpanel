<script setup>
import { defineAsyncComponent, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const props = defineProps({
    fm: {
        type: Object,
        required: true,
    },
});

// CodeMirror is a few hundred kilobytes; it must not sit in the file
// manager's chunk, which loads on every visit to the page. This keeps it in
// a chunk of its own, fetched the first time a file is opened to edit.
const CodeEditor = defineAsyncComponent(() => import('./CodeEditor.vue'));

const cursor = ref({ line: 1, column: 1 });

// Off by default, like VS Code: code reads better unwrapped, and long log
// lines are the exception rather than the rule.
const wrapLines = ref(false);

function saveFromEditor() {
    if (props.fm.saveInProgress || !props.fm.hasUnsavedChanges || props.fm.activeFile?.readonly) return;

    props.fm.submitSaveFile();
}
</script>

<template>
    <div v-if="fm.activeFile" class="flex min-h-0 flex-1 flex-col bg-white dark:bg-[#1e1e1e]">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200/80 bg-white/85 px-4 py-2 backdrop-blur dark:border-white/10 dark:bg-[#252526]">
            <p class="min-w-0 truncate font-mono text-[11px] text-slate-500 dark:text-slate-400" :title="fm.editorPath">{{ fm.editorPath }}</p>

            <div class="flex shrink-0 items-center gap-2">
                <span v-if="fm.activeFile.readonly" class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-700 dark:text-amber-300">{{ t('fileManager.editor.readonly') }}</span>
                <button
                    type="button"
                    class="rounded-md border px-2 py-1.5 text-xs shadow-sm transition"
                    :class="wrapLines
                        ? 'border-sky-500/60 bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'
                        : 'border-slate-300/80 bg-white text-slate-600 hover:bg-slate-50 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 dark:hover:bg-white/10'"
                    :title="t('fileManager.editor.wrapLines')"
                    :aria-pressed="wrapLines"
                    @click="wrapLines = !wrapLines"
                >
                    <i class="bi bi-text-wrap"></i>
                </button>
                <button
                    type="button"
                    class="rounded-md bg-sky-600 px-4 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="fm.saveInProgress || !fm.hasUnsavedChanges || fm.activeFile.readonly"
                    :title="t('fileManager.editor.tipSave')"
                    @click="fm.submitSaveFile"
                >
                    {{ fm.saveInProgress ? t('fileManager.editor.saving') : t('common.save') }}
                </button>
            </div>
        </div>

        <div v-if="fm.activeFile.message" class="flex flex-wrap items-center justify-between gap-2 border-b border-amber-500/30 bg-amber-50 px-4 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
            <span>{{ fm.activeFile.message }}</span>
            <button
                v-if="fm.activeFile.partial && fm.activeFile.can_load_full"
                type="button"
                class="rounded-md border border-amber-500/40 bg-white px-3 py-1 font-medium text-amber-800 transition hover:bg-amber-100 dark:bg-white/5 dark:text-amber-200 dark:hover:bg-white/10"
                @click="fm.loadFullFile"
            >{{ t('fileManager.editor.openFull') }}</button>
        </div>

        <div class="flex min-h-0 flex-1 overflow-hidden">
            <CodeEditor
                :key="fm.activeFilePath"
                v-model="fm.editorContent"
                :file-name="fm.editorFileName"
                :readonly="!!fm.activeFile.readonly"
                :wrap="wrapLines"
                :goto-line="fm.activeFile.gotoLine || 0"
                @goto-done="fm.clearGotoLine(fm.activeFilePath)"
                @save="saveFromEditor"
                @cursor="cursor = $event"
                @toggle-wrap="wrapLines = !wrapLines"
            />
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-slate-200/80 bg-white/80 px-4 py-1.5 text-[11px] text-slate-500 backdrop-blur dark:border-white/10 dark:bg-[#252526] dark:text-slate-400">
            <span class="truncate">{{ fm.editorFileName }}</span>
            <div class="flex shrink-0 items-center gap-3">
                <span class="font-mono">{{ t('fileManager.editor.cursor', { line: cursor.line, column: cursor.column }) }}</span>
                <span>{{ t('fileManager.editor.lineCount', { count: fm.editorLineCount }) }}</span>
                <span>{{ fm.hasUnsavedChanges ? t('fileManager.editor.modified') : t('fileManager.editor.noChanges') }}</span>
            </div>
        </div>
    </div>
</template>
