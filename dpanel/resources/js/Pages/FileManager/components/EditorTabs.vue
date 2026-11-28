<script setup>
// The editor's tab strip. Every open buffer gets a tab; the dot that marks
// unsaved work turns into the close button on hover, as in VS Code.
import { useI18n } from 'vue-i18n';
import { iconClassForItem } from '../helpers/fileIcons';

const { t } = useI18n();

defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div
        v-if="fm.openFiles.length"
        class="flex shrink-0 items-stretch overflow-x-auto border-b border-slate-200/80 bg-slate-100/90 dark:border-white/10 dark:bg-[#252526]"
    >
        <button
            type="button"
            class="flex shrink-0 items-center gap-2 border-r border-slate-200/80 px-3 py-2 text-xs transition dark:border-white/10"
            :class="fm.activeFilePath
                ? 'border-t-2 border-t-transparent text-slate-500 hover:bg-white/60 dark:text-slate-400 dark:hover:bg-white/5'
                : 'border-t-2 border-t-sky-500 bg-white font-medium text-slate-800 dark:bg-[#1e1e1e] dark:text-slate-100'"
            :title="t('fileManager.editor.filesTabTitle')"
            @click="fm.showFilesTab()"
        >
            <i class="bi bi-folder2-open text-sm text-amber-500"></i>
            <span>{{ t('fileManager.editor.filesTab') }}</span>
        </button>

        <div
            v-for="file in fm.openFiles"
            :key="file.path"
            class="group flex shrink-0 cursor-pointer items-center gap-2 border-r border-slate-200/80 px-3 py-2 text-xs transition dark:border-white/10"
            :class="file.path === fm.activeFilePath
                ? 'border-t-2 border-t-sky-500 bg-white text-slate-800 dark:bg-[#1e1e1e] dark:text-slate-100'
                : 'border-t-2 border-t-transparent text-slate-500 hover:bg-white/60 dark:text-slate-400 dark:hover:bg-white/5'"
            :title="file.path"
            @click="fm.activateTab(file.path)"
            @auxclick.middle.prevent="fm.closeTab(file.path)"
        >
            <i class="bi text-sm" :class="iconClassForItem({ type: 'file', name: file.name })"></i>
            <span class="max-w-[14rem] truncate">{{ file.name }}</span>
            <span v-if="file.readonly" class="text-[10px] uppercase tracking-wide text-amber-600 dark:text-amber-400">
                {{ t('fileManager.editor.readonly') }}
            </span>

            <button
                type="button"
                class="relative flex h-4 w-4 shrink-0 items-center justify-center rounded text-slate-400 transition hover:bg-slate-200 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-slate-100"
                :title="t('fileManager.editor.closeTab', { name: file.name })"
                :aria-label="t('fileManager.editor.closeTab', { name: file.name })"
                @click.stop="fm.closeTab(file.path)"
            >
                <span
                    v-if="fm.isTabDirty(file.path)"
                    class="h-2 w-2 rounded-full bg-amber-400 group-hover:hidden"
                ></span>
                <i
                    class="bi bi-x text-sm leading-none"
                    :class="fm.isTabDirty(file.path) ? 'hidden group-hover:inline' : ''"
                ></i>
            </button>
        </div>
    </div>
</template>
