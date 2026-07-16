<script setup>
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
            <i class="bi bi-box-arrow-in-right text-xs text-slate-400"></i>
            {{ fm.contextItem?.type === 'dir' ? 'Open Folder' : (fm.isEditableFile(fm.contextItem?.name) ? 'Edit File' : 'Open File') }}
        </button>
        <button v-if="fm.contextItem?.type === 'dir'" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('set-root')">
            <i class="bi bi-pin-angle text-xs text-slate-400"></i>
            Set as Root Folder
        </button>
        <button v-if="fm.contextItem?.type === 'file' && fm.isEditableFile(fm.contextItem?.name)" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('open-tab')">
            <i class="bi bi-window-stack text-xs text-slate-400"></i>
            Edit in New Tab
        </button>
        <button v-if="fm.contextItem?.type === 'file'" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('download')">
            <i class="bi bi-download text-xs text-slate-400"></i>
            Download
        </button>

        <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('rename')">
            <i class="bi bi-input-cursor-text text-xs text-slate-400"></i>
            Rename
        </button>
        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('permissions')">
            <i class="bi bi-shield-lock text-xs text-slate-400"></i>
            Permissions
        </button>
        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('move')">
            <i class="bi bi-arrows-move text-xs text-slate-400"></i>
            Move
        </button>
        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('zip')">
            <i class="bi bi-file-earmark-zip text-xs text-slate-400"></i>
            Create Zip
        </button>
        <button v-if="fm.contextZip" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="fm.triggerContextAction('unzip')">
            <i class="bi bi-file-earmark-arrow-up text-xs text-slate-400"></i>
            Extract Zip
        </button>

        <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20" @click="fm.triggerContextAction('delete')">
            <i class="bi bi-trash text-xs"></i>
            Delete
        </button>
    </div>
</template>
