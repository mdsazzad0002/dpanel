<script setup>
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="fm.modalType === 'editor'" class="flex min-h-0 flex-1 flex-col bg-slate-50 text-slate-800 dark:bg-[#1e1e1e] dark:text-slate-200">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200/80 bg-white/85 px-4 py-2.5 backdrop-blur dark:border-white/10 dark:bg-[#252526]">
            <div class="min-w-0">
                <div class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <i class="bi bi-file-earmark-code text-sky-400"></i>
                    <span class="truncate font-medium">{{ fm.editorFileName }}</span>
                    <span v-if="fm.hasUnsavedChanges" class="h-2 w-2 rounded-full bg-amber-400"></span>
                    <span v-if="fm.selectedFile?.readonly" class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-[10px] uppercase tracking-wide text-amber-700 dark:text-amber-300">Readonly</span>
                </div>
                <p class="mt-1 truncate text-[11px] text-slate-500 dark:text-slate-400">{{ fm.saveForm.file_path }}</p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <button type="button" class="rounded-md border border-slate-300/80 bg-white px-3 py-1.5 text-xs text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10" @click="fm.closeModal">Close</button>
                <button type="button" class="rounded-md bg-sky-600 px-4 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-50" :disabled="fm.saveInProgress || !fm.hasUnsavedChanges || fm.selectedFile?.readonly" @click="fm.submitSaveFile">
                    {{ fm.saveInProgress ? 'Saving...' : 'Save' }}
                </button>
            </div>
        </div>

        <div class="flex min-h-0 flex-1 overflow-hidden">
            <aside class="hidden w-64 shrink-0 flex-col border-r border-slate-200/80 bg-slate-100/80 lg:flex dark:border-white/10 dark:bg-[#1f1f1f]">
                <div class="border-b border-slate-200/80 px-4 py-3 dark:border-white/10">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Explorer</p>
                </div>
                <div class="space-y-4 px-4 py-4 text-sm">
                    <div>
                        <p class="text-[10px] uppercase tracking-[0.2em] text-slate-500 dark:text-slate-500">File</p>
                        <p class="mt-1 break-all font-mono text-xs text-slate-800 dark:text-slate-200">{{ fm.editorFileName }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-[0.2em] text-slate-500 dark:text-slate-500">Folder</p>
                        <p class="mt-1 break-all font-mono text-xs text-slate-800 dark:text-slate-200">{{ fm.editorFileFolder }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-[0.2em] text-slate-500 dark:text-slate-500">Lines</p>
                        <p class="mt-1 font-mono text-xs text-slate-800 dark:text-slate-200">{{ fm.editorLineCount }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-600 shadow-sm dark:border-white/10 dark:bg-black/20 dark:text-slate-300">
                        <p class="font-medium text-slate-800 dark:text-slate-100">Tips</p>
                        <ul class="mt-2 space-y-1 text-slate-500 dark:text-slate-400">
                            <li>Ctrl/Cmd+S to save</li>
                            <li>Use full-screen code editing</li>
                            <li>Keep files split by responsibility</li>
                        </ul>
                    </div>
                </div>
            </aside>

            <div class="flex min-w-0 flex-1 flex-col">
                <div class="flex items-center justify-between border-b border-slate-200/80 bg-slate-100/90 px-4 py-2 text-xs text-slate-600 dark:border-white/10 dark:bg-[#2d2d2d] dark:text-slate-300">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="rounded-md border border-slate-200 bg-white px-2 py-1 font-mono text-[11px] text-slate-700 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-slate-200">{{ fm.editorFileName }}</span>
                        <span class="truncate text-slate-500 dark:text-slate-400">{{ fm.editorFileFolder }}</span>
                    </div>
                    <div class="flex items-center gap-3 text-[11px] text-slate-500 dark:text-slate-400">
                        <span>{{ fm.hasUnsavedChanges ? 'Unsaved changes' : 'Saved' }}</span>
                        <span>{{ fm.editorLineCount }} lines</span>
                    </div>
                </div>

                <div class="editor-shell flex min-h-0 flex-1 bg-white dark:bg-[#1e1e1e]">
                    <div class="editor-gutter hidden w-12 shrink-0 border-r border-slate-200/80 bg-slate-50 py-4 pr-2 text-right font-mono text-[11px] leading-6 text-slate-400 sm:block dark:border-white/10 dark:bg-[#1e1e1e] dark:text-slate-500">
                        <div v-for="line in fm.editorLineCount" :key="line">{{ line }}</div>
                    </div>

                    <textarea
                        v-model="fm.saveForm.content"
                        class="editor-textarea min-h-0 flex-1 resize-none border-0 bg-transparent p-4 font-mono text-sm leading-6 text-slate-800 outline-none focus:ring-0 dark:text-slate-100"
                        spellcheck="false"
                        wrap="off"
                    ></textarea>
                </div>

                <div class="flex items-center justify-between border-t border-slate-200/80 bg-white px-4 py-2 text-[11px] text-slate-500 dark:border-white/10 dark:bg-[#252526] dark:text-slate-400">
                    <span>{{ fm.saveForm.file_path }}</span>
                    <span>{{ fm.hasUnsavedChanges ? 'Modified' : 'No changes' }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
