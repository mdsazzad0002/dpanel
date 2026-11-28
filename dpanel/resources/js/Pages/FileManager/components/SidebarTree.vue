<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    fm: {
        type: Object,
        required: true,
    },
});

const asideEl = ref(null);
const resizing = ref(false);

// Pointer capture keeps the drag alive over the file table and the editor,
// which would otherwise swallow the move events.
function startResize(event) {
    if (props.fm.isMobile) return;

    resizing.value = true;
    event.currentTarget.setPointerCapture?.(event.pointerId);
    window.addEventListener('pointermove', onResize);
    window.addEventListener('pointerup', stopResize, { once: true });
}

function onResize(event) {
    if (!resizing.value) return;

    const left = asideEl.value?.getBoundingClientRect().left ?? 0;
    props.fm.setSidebarWidth(event.clientX - left);
}

function stopResize() {
    if (!resizing.value) return;

    resizing.value = false;
    window.removeEventListener('pointermove', onResize);
    props.fm.persistSidebarWidth();
}

onBeforeUnmount(() => {
    window.removeEventListener('pointermove', onResize);
});
</script>

<template>
    <Transition name="slide-left">
        <aside
            v-show="fm.sidebarOpen"
            ref="asideEl"
            class="relative flex shrink-0 flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"
            :class="fm.isMobile ? 'fixed inset-y-0 left-0 z-40 w-72 shadow-xl' : ''"
            :style="fm.isMobile ? {} : { width: `${fm.sidebarWidth}px` }"
        >
            <button
                v-if="fm.isMobile"
                type="button"
                :aria-label="t('fileManager.sidebar.close')"
                class="absolute right-2 top-2 rounded p-1 text-slate-400 hover:bg-slate-100 lg:hidden"
                @click="fm.closeSidebar()"
            >
                <i class="bi bi-x-lg text-sm"></i>
            </button>

            <div class="border-b border-slate-200 px-3 py-2 dark:border-slate-800">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ t('fileManager.sidebar.folders') }}</p>
            </div>

            <div class="flex min-h-0 flex-1 flex-col p-2">
                <div class="mb-2 flex items-center gap-1">
                    <input v-model="fm.pathInput" type="text" class="min-w-0 flex-1 rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800" :placeholder="t('fileManager.sidebar.goToPath')" />
                    <button type="button" class="shrink-0 rounded-md bg-blue-600 px-2 py-1.5 text-[11px] font-medium text-white hover:bg-blue-700" :disabled="fm.isBusy" @click="fm.goFromPathInput">
                        <i class="bi bi-arrow-right"></i>
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-0.5 overflow-auto rounded-lg text-xs">
                    <button
                        type="button"
                        class="flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-left transition-all hover:bg-slate-100 dark:hover:bg-slate-800"
                        :class="[
                            !fm.currentPath ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-900/20 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400',
                            fm.moveDragTargetPath === '' ? 'bg-emerald-50 ring-1 ring-emerald-400 dark:bg-emerald-900/20' : '',
                        ]"
                        :title="fm.basePath"
                        @click="fm.goRoot"
                        @dragover.prevent.stop="fm.handleFolderTargetDragOver('', $event)"
                        @dragleave="fm.handleFolderTargetDragLeave('')"
                        @drop.prevent.stop="fm.handleFolderTargetDrop('', $event)"
                    >
                        <span class="inline-flex h-3.5 w-3.5 items-center justify-center"><i class="bi bi-house-door-fill text-blue-500"></i></span>
                        <span class="block truncate">{{ fm.ownerRootLabel }}</span>
                    </button>

                    <button
                        v-for="node in fm.treeRows"
                        :key="node.path"
                        type="button"
                        class="flex w-full items-center gap-1.5 rounded-md py-1.5 pr-2 text-left transition-all hover:bg-slate-100 dark:hover:bg-slate-800"
                        :class="[
                            fm.isTreeNodeActive(node.path) ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-900/20 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400',
                            fm.moveDragTargetPath === node.path ? 'bg-emerald-50 ring-1 ring-emerald-400 dark:bg-emerald-900/20' : '',
                        ]"
                        :title="node.path"
                        :style="{ paddingLeft: `${8 + (node.level * 12)}px` }"
                        @click="fm.openPath(node.path)"
                        @contextmenu.prevent.stop="fm.openTreeContextMenu(node, $event)"
                        @dragover.prevent.stop="fm.handleFolderTargetDragOver(node.path, $event)"
                        @dragleave="fm.handleFolderTargetDragLeave(node.path)"
                        @drop.prevent.stop="fm.handleFolderTargetDrop(node.path, $event)"
                    >
                        <span
                            class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded hover:bg-slate-200 dark:hover:bg-slate-700"
                            :role="node.hasChildren ? 'button' : undefined"
                            :aria-label="node.hasChildren ? t(node.expanded ? 'fileManager.sidebar.collapse' : 'fileManager.sidebar.expand', { name: node.name }) : undefined"
                            @click.stop="node.hasChildren ? fm.toggleTreeNode(node.path) : null"
                        >
                            <i v-if="node.loading" class="bi bi-arrow-repeat animate-spin text-[9px]"></i>
                            <i v-else-if="node.hasChildren" class="bi text-[8px]" :class="node.expanded ? 'bi-caret-down-fill' : 'bi-caret-right-fill'"></i>
                        </span>
                        <i class="bi shrink-0 text-xs text-amber-500" :class="node.expanded && node.hasChildren ? 'bi-folder2-open' : 'bi-folder-fill'"></i>
                        <span class="block truncate">{{ node.name }}</span>
                    </button>
                </div>
            </div>

            <div class="border-t border-slate-200 px-3 py-2 dark:border-slate-800">
                <button
                    v-if="fm.currentPath"
                    type="button"
                    class="mb-1 w-full rounded-md border border-slate-200 px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                    :class="fm.moveDragTargetPath === fm.parentPath ? 'border-emerald-400 bg-emerald-50 dark:bg-emerald-900/20' : ''"
                    :title="t('fileManager.sidebar.dropUp')"
                    @click="fm.goParent"
                    @dragover.prevent.stop="fm.handleFolderTargetDragOver(fm.parentPath, $event)"
                    @dragleave="fm.handleFolderTargetDragLeave(fm.parentPath)"
                    @drop.prevent.stop="fm.handleFolderTargetDrop(fm.parentPath, $event)"
                >
                    <i class="bi bi-arrow-up mr-1"></i>{{ t('fileManager.sidebar.up') }}
                </button>
                <button type="button" class="w-full rounded-md border border-slate-200 px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800" @click="fm.goRoot">
                    <i class="bi bi-house-door mr-1"></i>{{ t('fileManager.sidebar.root') }}
                </button>
            </div>

            <div
                v-if="!fm.isMobile"
                class="absolute inset-y-0 -right-1 z-10 w-2 cursor-col-resize touch-none"
                :class="resizing ? 'bg-sky-400/40' : 'hover:bg-sky-400/30'"
                role="separator"
                aria-orientation="vertical"
                :aria-label="t('fileManager.sidebar.resize')"
                @pointerdown.prevent="startResize"
                @dblclick="fm.setSidebarWidth(240); fm.persistSidebarWidth()"
            ></div>
        </aside>
    </Transition>
</template>
