<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const props = defineProps({
    fm: {
        type: Object,
        required: true,
    },
});

const BTN_BASE = 'border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';

// One primary action per row, matching what double-click does.
function rowAction(item) {
    if (item.type === 'dir') {
        return { kind: 'open', label: t('fileManager.table.open'), icon: 'bi-folder2-open', className: 'border-amber-200 text-amber-700 hover:bg-amber-50 dark:border-amber-800/60 dark:text-amber-400 dark:hover:bg-amber-900/20' };
    }
    if (props.fm.isPreviewable(item.name)) {
        return { kind: 'view', label: t('fileManager.table.view'), icon: 'bi-eye', className: 'border-sky-200 text-sky-700 hover:bg-sky-50 dark:border-sky-800/60 dark:text-sky-400 dark:hover:bg-sky-900/20' };
    }
    if (props.fm.isEditableFile(item.name)) {
        return { kind: 'edit', label: t('fileManager.table.edit'), icon: 'bi-pencil-square', className: BTN_BASE };
    }
    return { kind: 'download', label: t('fileManager.table.download'), icon: 'bi-download', className: BTN_BASE };
}

function runRowAction(item) {
    const action = rowAction(item);
    if (action.kind === 'open') props.fm.openPath(item.path);
    else if (action.kind === 'download') props.fm.downloadItem(item);
    else props.fm.openFileInEditor(item.path);
}
</script>

<template>
    <div class="flex-1 overflow-auto p-3" @contextmenu.prevent="fm.openBackgroundContextMenu($event)">
        <div class="overflow-x-auto rounded-2xl border border-slate-200/80 bg-white/80 shadow-[0_12px_40px_-18px_rgba(15,23,42,0.25)] backdrop-blur dark:border-slate-800/70 dark:bg-slate-900/70 dark:shadow-none">
            <table class="min-w-full text-left text-sm">
                <thead class="sticky top-0 z-10 border-b border-slate-200/80 bg-white/90 backdrop-blur dark:border-slate-800/70 dark:bg-slate-900/90">
                    <tr>
                        <th class="w-10 px-3 py-3">
                            <input type="checkbox" :checked="fm.isAllItemsSelected" @change="fm.toggleSelectAll($event.target.checked)" />
                        </th>
                        <th class="cursor-pointer px-3 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 select-none" @click="fm.toggleSort('name')">
                            <span class="flex items-center gap-1">{{ t('fileManager.table.name') }} <i :class="['bi text-[10px]', fm.getSortIcon('name')]"></i></span>
                        </th>
                        <th class="w-24 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ t('fileManager.table.action') }}</th>
                        <th class="hidden cursor-pointer px-3 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 select-none sm:table-cell" @click="fm.toggleSort('type')">
                            <span class="flex items-center gap-1">{{ t('fileManager.table.type') }} <i :class="['bi text-[10px]', fm.getSortIcon('type')]"></i></span>
                        </th>
                        <th class="hidden cursor-pointer px-3 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 select-none md:table-cell" @click="fm.toggleSort('size')">
                            <span class="flex items-center gap-1">{{ t('fileManager.table.size') }} <i :class="['bi text-[10px]', fm.getSortIcon('size')]"></i></span>
                        </th>
                        <th class="hidden px-3 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 lg:table-cell">{{ t('fileManager.table.perm') }}</th>
                        <th class="hidden cursor-pointer px-3 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 select-none xl:table-cell" @click="fm.toggleSort('modified')">
                            <span class="flex items-center gap-1">{{ t('fileManager.table.modified') }} <i :class="['bi text-[10px]', fm.getSortIcon('modified')]"></i></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(item, itemIndex) in (Array.isArray(fm.filteredItems) ? fm.filteredItems.filter((entry) => entry && entry.path) : [])"
                        :key="item?.path || `item-${itemIndex}`"
                        data-fm-row
                        class="group cursor-pointer border-b border-slate-100/80 transition-all duration-100 hover:bg-sky-50/70 dark:border-slate-800/70 dark:hover:bg-slate-800/50"
                        :class="[
                            fm.activeItemPath === item.path ? 'bg-sky-50 ring-1 ring-inset ring-sky-400/30 dark:bg-sky-900/20 dark:ring-sky-500/30' : '',
                            fm.isItemSelected(item.path) && fm.activeItemPath !== item.path ? 'bg-sky-50/60 dark:bg-sky-900/10' : '',
                            fm.moveDragTargetPath === item.path ? 'bg-emerald-50/80 dark:bg-emerald-900/20' : '',
                        ]"
                        draggable="true"
                        @click="fm.handleItemClick(item, $event)"
                        @dblclick="item.type === 'dir' ? fm.openPath(item.path) : fm.openFileInEditor(item.path)"
                        @contextmenu.prevent.stop="fm.openContextMenu(item, $event)"
                        @dragstart="fm.handleItemDragStart(item, $event)"
                        @dragend="fm.handleItemDragEnd"
                        @dragover="item.type === 'dir' ? fm.handleFolderTargetDragOver(item.path, $event) : null"
                        @dragleave="item.type === 'dir' ? fm.handleFolderTargetDragLeave(item.path) : null"
                        @drop="item.type === 'dir' ? fm.handleFolderTargetDrop(item.path, $event) : null"
                    >
                        <td class="px-3 py-0.5" @click.stop>
                            <input type="checkbox" :checked="fm.isItemSelected(item.path)" @change="fm.toggleSelectPath(item.path, $event.target.checked, $event)" />
                        </td>
                        <td class="px-3 py-0.5 font-medium">
                            <div class="flex items-center gap-2.5">
                                <div class="flex h-5 w-5 shrink-0 items-center justify-center rounded-lg transition-colors" :class="item.type === 'dir' ? 'bg-amber-100 text-amber-600 dark:bg-amber-900/30' : 'bg-slate-100 text-slate-500 dark:bg-slate-800'">
                                    <i class="bi text-sm" :class="fm.iconClassForItem(item)"></i>
                                </div>
                                <span class="truncate" :class="fm.nameClassForItem(item)">{{ item.name }}</span>
                                <span v-if="fm.isTabDirty(item.path)" class="text-[10px] font-semibold text-amber-600">*</span>
                            </div>
                            <div class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400 sm:hidden">
                                {{ fm.typeLabelForItem(item) }} &middot;
                                <span v-if="item.type === 'dir' && item.size == null">
                                    <button v-if="fm.folderSizeLoadingPath !== item.path" type="button" class="underline decoration-dotted" @click.stop="fm.calculateFolderSize(item)">{{ t('fileManager.table.calculate') }}</button>
                                    <span v-else>{{ t('fileManager.table.calculating') }}</span>
                                </span>
                                <span v-else>{{ fm.formatBytes(item.size) }}</span>
                            </div>
                        </td>
                        <td class="px-3 py-0.5 text-left" @click.stop @dblclick.stop>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium transition"
                                :class="rowAction(item).className"
                                :title="rowAction(item).label"
                                @click="runRowAction(item)"
                            >
                                <i class="bi" :class="rowAction(item).icon"></i>
                                <span class="hidden sm:inline">{{ rowAction(item).label }}</span>
                            </button>
                        </td>
                        <td class="hidden px-3 py-0.5 sm:table-cell">
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                                {{ fm.typeLabelForItem(item) }}
                            </span>
                        </td>
                        <td class="hidden px-3 py-0.5 text-slate-600 md:table-cell dark:text-slate-400">
                            <span v-if="item.type === 'dir' && item.size == null">
                                <button
                                    v-if="fm.folderSizeLoadingPath !== item.path"
                                    type="button"
                                    class="text-xs text-sky-600 underline decoration-dotted hover:text-sky-700 dark:text-sky-400"
                                    @click.stop="fm.calculateFolderSize(item)"
                                >
                                    {{ t('fileManager.table.calculate') }}
                                </button>
                                <span v-else class="text-xs text-slate-400">{{ t('fileManager.table.calculating') }}</span>
                            </span>
                            <span v-else>{{ fm.formatBytes(item.size) }}</span>
                        </td>
                        <td class="hidden px-3 py-0.5 font-mono text-xs text-slate-500 lg:table-cell">{{ item.permissions }}</td>
                        <td class="hidden px-3 py-0.5 text-xs text-slate-500 xl:table-cell">{{ item.modified_at ? new Date(item.modified_at).toLocaleString() : '-' }}</td>
                    </tr>
                    <tr v-if="fm.filteredItems.length === 0">
                        <td colspan="7" class="px-4 py-16 text-center">
                            <i class="bi bi-inbox text-4xl text-slate-300 dark:text-slate-600"></i>
                            <p class="mt-3 text-sm text-slate-500">{{ t('fileManager.table.empty') }}</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
