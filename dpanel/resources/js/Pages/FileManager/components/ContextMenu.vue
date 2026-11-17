<script setup>
import { useI18n } from 'vue-i18n';

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
        v-if="fm.contextMenu.visible"
        data-fm-context-menu
        class="fixed z-[60] w-56 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900"
        :style="{ left: `${fm.contextMenu.x}px`, top: `${fm.contextMenu.y}px` }"
        @click.stop
    >
        <div class="border-b border-slate-200 px-3 py-2 dark:border-slate-700">
            <p class="truncate text-xs font-semibold text-slate-800 dark:text-slate-100">{{ fm.contextMenu.itemName }}</p>
            <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ fm.contextMenu.itemType }}</p>
        </div>

        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('open')">
            <i class="bi text-xs text-slate-400" :class="fm.contextItem?.type === 'file' && fm.isSqliteFile(fm.contextItem?.name) ? 'bi-database' : 'bi-box-arrow-in-right'"></i>
            {{ fm.contextItem?.type === 'dir' ? t('fileManager.context.openFolder') : fm.isSqliteFile(fm.contextItem?.name) ? t('fileManager.context.openSqlite') : (fm.isPreviewable(fm.contextItem?.name) ? t('fileManager.context.preview') : (fm.isEditableFile(fm.contextItem?.name) ? t('fileManager.context.editFile') : t('fileManager.context.openFile'))) }}
        </button>
        <button v-if="fm.contextItem?.type === 'file'" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('download')">
            <i class="bi bi-download text-xs text-slate-400"></i>
            {{ t('fileManager.context.download') }}
        </button>

        <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('rename')">
            <i class="bi bi-input-cursor-text text-xs text-slate-400"></i>
            {{ t('fileManager.context.rename') }}
        </button>
        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('permissions')">
            <i class="bi bi-shield-lock text-xs text-slate-400"></i>
            {{ t('fileManager.context.permissions') }}
        </button>
        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('move')">
            <i class="bi bi-arrows-move text-xs text-slate-400"></i>
            {{ t('fileManager.context.move') }}
        </button>
        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('copy')">
            <i class="bi bi-files text-xs text-slate-400"></i>
            {{ t('fileManager.context.copy') }}
        </button>
        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('zip')">
            <i class="bi bi-file-earmark-zip text-xs text-slate-400"></i>
            {{ t('fileManager.context.zip') }}
        </button>
        <button v-if="fm.contextArchive" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('unzip')">
            <i class="bi bi-file-earmark-arrow-up text-xs text-slate-400"></i>
            {{ t('fileManager.context.unzip') }}
        </button>

        <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20" @click="fm.triggerContextAction('delete')">
            <i class="bi bi-trash text-xs"></i>
            {{ t('fileManager.context.delete') }}
        </button>
    </div>
</template>
