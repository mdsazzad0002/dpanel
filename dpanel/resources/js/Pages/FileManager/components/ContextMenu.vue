<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    fm: {
        type: Object,
        required: true,
    },
});

const menuEl = ref(null);
const position = ref({ left: 0, top: 0 });

const isBackground = computed(() => props.fm.contextMenu.kind === 'background');
// Several rows selected: the menu acts on all of them, so the entries that
// only make sense for one item step aside.
const isMultiple = computed(() => !isBackground.value && props.fm.contextMenu.count > 1);

// Measured rather than guessed: the menu's height changes with the entries
// on show, and a guess puts it half off the screen.
async function place() {
    position.value = { left: props.fm.contextMenu.x, top: props.fm.contextMenu.y };
    await nextTick();

    const menu = menuEl.value;
    if (!menu) return;

    const { width, height } = menu.getBoundingClientRect();
    const margin = 8;

    position.value = {
        left: Math.max(margin, Math.min(props.fm.contextMenu.x, window.innerWidth - width - margin)),
        top: Math.max(margin, Math.min(props.fm.contextMenu.y, window.innerHeight - height - margin)),
    };
}

watch(
    () => [props.fm.contextMenu.visible, props.fm.contextMenu.x, props.fm.contextMenu.y, props.fm.contextMenu.kind],
    () => {
        if (props.fm.contextMenu.visible) place();
    },
);

const ITEM_CLASS = 'flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800';
</script>

<template>
    <div
        v-if="fm.contextMenu.visible"
        ref="menuEl"
        data-fm-context-menu
        class="fixed z-[60] max-h-[90vh] w-56 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900"
        :style="{ left: `${position.left}px`, top: `${position.top}px` }"
        @click.stop
        @contextmenu.prevent.stop
    >
        <div class="border-b border-slate-200 px-3 py-2 dark:border-slate-700">
            <p class="truncate text-xs font-semibold text-slate-800 dark:text-slate-100">
                {{ isMultiple ? t('fileManager.context.selectedItems', { count: fm.contextMenu.count }) : fm.contextMenu.itemName }}
            </p>
            <p class="text-[11px] uppercase tracking-wide text-slate-500">
                {{ isBackground ? t('fileManager.context.currentFolder') : fm.contextMenu.itemType }}
            </p>
        </div>

        <!-- Empty space in the listing: the folder on screen is the target. -->
        <template v-if="isBackground">
            <button type="button" :class="ITEM_CLASS" @click="fm.triggerBackgroundAction('create-folder')">
                <i class="bi bi-folder-plus text-xs text-amber-500"></i>
                {{ t('fileManager.quick.newFolder') }}
            </button>
            <button type="button" :class="ITEM_CLASS" @click="fm.triggerBackgroundAction('create-file')">
                <i class="bi bi-file-earmark-plus text-xs text-emerald-500"></i>
                {{ t('fileManager.quick.newFile') }}
            </button>
            <button type="button" :class="ITEM_CLASS" @click="fm.triggerBackgroundAction('upload')">
                <i class="bi bi-cloud-arrow-up text-xs text-blue-500"></i>
                {{ t('fileManager.quick.upload') }}
            </button>

            <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

            <button v-if="fm.filteredItems.length" type="button" :class="ITEM_CLASS" @click="fm.triggerBackgroundAction('select-all')">
                <i class="bi bi-check2-square text-xs text-slate-400"></i>
                {{ t('fileManager.context.selectAll') }}
            </button>
            <button type="button" :class="ITEM_CLASS" @click="fm.triggerBackgroundAction('refresh')">
                <i class="bi bi-arrow-clockwise text-xs text-slate-400"></i>
                {{ t('fileManager.context.refresh') }}
            </button>

            <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

            <button type="button" :class="ITEM_CLASS" @click="fm.triggerBackgroundAction('copy-path')">
                <i class="bi bi-clipboard text-xs text-slate-400"></i>
                {{ t('fileManager.context.copyPath') }}
            </button>
            <button type="button" :class="ITEM_CLASS" @click="fm.triggerBackgroundAction('copy-relative-path')">
                <i class="bi bi-clipboard-check text-xs text-slate-400"></i>
                {{ t('fileManager.context.copyRelativePath') }}
            </button>
        </template>

        <template v-else>
            <template v-if="!isMultiple">
                <button type="button" :class="ITEM_CLASS" @click="fm.triggerContextAction('open')">
                    <i class="bi text-xs text-slate-400" :class="fm.contextItem?.type === 'file' && fm.isSqliteFile(fm.contextItem?.name) ? 'bi-database' : 'bi-box-arrow-in-right'"></i>
                    {{ fm.contextItem?.type === 'dir' ? t('fileManager.context.openFolder') : fm.isSqliteFile(fm.contextItem?.name) ? t('fileManager.context.openSqlite') : (fm.isPreviewable(fm.contextItem?.name) ? t('fileManager.context.preview') : (fm.isEditableFile(fm.contextItem?.name) ? t('fileManager.context.editFile') : t('fileManager.context.openFile'))) }}
                </button>
                <button v-if="fm.contextItem?.type === 'file'" type="button" :class="ITEM_CLASS" @click="fm.triggerContextAction('download')">
                    <i class="bi bi-download text-xs text-slate-400"></i>
                    {{ t('fileManager.context.download') }}
                </button>

                <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

                <button type="button" :class="ITEM_CLASS" @click="fm.triggerContextAction('rename')">
                    <i class="bi bi-input-cursor-text text-xs text-slate-400"></i>
                    {{ t('fileManager.context.rename') }}
                </button>
                <button type="button" :class="ITEM_CLASS" @click="fm.triggerContextAction('permissions')">
                    <i class="bi bi-shield-lock text-xs text-slate-400"></i>
                    {{ t('fileManager.context.permissions') }}
                </button>
            </template>

            <div v-if="isMultiple" class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

            <button type="button" :class="ITEM_CLASS" @click="fm.triggerContextAction('move')">
                <i class="bi bi-arrows-move text-xs text-slate-400"></i>
                {{ t('fileManager.context.move') }}
            </button>
            <button type="button" :class="ITEM_CLASS" @click="fm.triggerContextAction('copy')">
                <i class="bi bi-files text-xs text-slate-400"></i>
                {{ t('fileManager.context.copy') }}
            </button>
            <button type="button" :class="ITEM_CLASS" @click="fm.triggerContextAction('zip')">
                <i class="bi bi-file-earmark-zip text-xs text-slate-400"></i>
                {{ t('fileManager.context.zip') }}
            </button>
            <button v-if="fm.contextArchive && !isMultiple" type="button" :class="ITEM_CLASS" @click="fm.triggerContextAction('unzip')">
                <i class="bi bi-file-earmark-arrow-up text-xs text-slate-400"></i>
                {{ t('fileManager.context.unzip') }}
            </button>

            <template v-if="!isMultiple">
                <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

                <button type="button" :class="ITEM_CLASS" @click="fm.triggerContextAction('copy-path')">
                    <i class="bi bi-clipboard text-xs text-slate-400"></i>
                    {{ t('fileManager.context.copyPath') }}
                </button>
                <button type="button" :class="ITEM_CLASS" @click="fm.triggerContextAction('copy-relative-path')">
                    <i class="bi bi-clipboard-check text-xs text-slate-400"></i>
                    {{ t('fileManager.context.copyRelativePath') }}
                </button>
            </template>

            <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20" @click="fm.triggerContextAction('delete')">
                <i class="bi bi-trash text-xs"></i>
                {{ isMultiple ? t('fileManager.context.deleteSelected', { count: fm.contextMenu.count }) : t('fileManager.context.delete') }}
            </button>
        </template>
    </div>
</template>
