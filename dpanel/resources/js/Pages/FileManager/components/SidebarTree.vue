<script setup>
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Transition name="slide-left">
        <aside
            v-show="fm.sidebarOpen"
            class="flex w-60 shrink-0 flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"
            :class="fm.isMobile ? 'fixed inset-y-0 left-0 z-40 w-72 shadow-xl' : ''"
        >
            <button
                v-if="fm.isMobile"
                type="button"
                class="absolute right-2 top-2 rounded p-1 text-slate-400 hover:bg-slate-100 lg:hidden"
                @click="fm.closeSidebar()"
            >
                <i class="bi bi-x-lg text-sm"></i>
            </button>

            <div class="border-b border-slate-200 px-3 py-2 dark:border-slate-800">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Folders</p>
            </div>

            <div class="flex min-h-0 flex-1 flex-col p-2">
                <div class="mb-2 flex items-center gap-1">
                    <input v-model="fm.pathInput" type="text" class="flex-1 rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800" placeholder="Go to path..." />
                    <button type="button" class="shrink-0 rounded-md bg-blue-600 px-2 py-1.5 text-[11px] font-medium text-white hover:bg-blue-700" :disabled="fm.isBusy" @click="fm.goFromPathInput">
                        <i class="bi bi-arrow-right"></i>
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-0.5 overflow-y-auto rounded-lg text-xs">
                    <div v-for="(node, nodeIndex) in (Array.isArray(fm.treeRows) ? fm.treeRows.filter(Boolean) : [])" :key="node?.path || `tree-${nodeIndex}`">
                        <button
                            v-if="node && node.path"
                            type="button"
                            class="flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-left transition-all hover:bg-slate-100 dark:hover:bg-slate-800"
                            :class="[
                                fm.isTreeNodeActive(node.path) ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-900/20 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400',
                                fm.moveDragTargetPath === node.path ? 'bg-emerald-50 ring-1 ring-emerald-400 dark:bg-emerald-900/20' : '',
                            ]"
                            :title="node.path"
                            :style="{ paddingLeft: `${8 + (node.level * 12)}px` }"
                            @click="fm.openPath(node.path)"
                            @dragover.prevent.stop="fm.handleFolderTargetDragOver(node.path, $event)"
                            @dragleave="fm.handleFolderTargetDragLeave(node.path)"
                            @drop.prevent.stop="fm.handleFolderTargetDrop(node.path, $event)"
                        >
                            <span class="inline-flex h-3.5 w-3.5 items-center justify-center" @click.stop="node.hasChildren ? fm.toggleTreeNode(node.path) : null">
                                <i v-if="node.hasChildren" class="bi text-[8px]" :class="node.expanded ? 'bi-caret-down-fill' : 'bi-caret-right-fill'"></i>
                            </span>
                            <i class="bi bi-folder-fill text-xs text-amber-500"></i>
                            <span class="block truncate">{{ node.name }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="border-t border-slate-200 px-3 py-2 dark:border-slate-800">
                <button type="button" class="w-full rounded-md border border-slate-200 px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800" @click="fm.goRoot">
                    <i class="bi bi-house-door mr-1"></i>Go to Root
                </button>
            </div>
        </aside>
    </Transition>
</template>
