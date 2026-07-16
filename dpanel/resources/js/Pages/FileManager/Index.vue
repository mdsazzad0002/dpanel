<script setup>
defineOptions({
    layout: null,
});

import { Head } from '@inertiajs/vue3';
import { useFileManager } from './composables/useFileManager';
import TopBar from './components/TopBar.vue';
import SidebarTree from './components/SidebarTree.vue';
import SelectionBar from './components/SelectionBar.vue';
import FileTable from './components/FileTable.vue';
import ContextMenu from './components/ContextMenu.vue';
import ToastStack from './components/ToastStack.vue';
import FileEditor from './components/FileEditor.vue';
import UploadModal from './components/UploadModal.vue';
import RenameModal from './components/RenameModal.vue';
import PermissionModal from './components/PermissionModal.vue';
import MoveModal from './components/MoveModal.vue';
import ZipModal from './components/ZipModal.vue';
import UnzipModal from './components/UnzipModal.vue';
import CreateFolderModal from './components/CreateFolderModal.vue';
import CreateFileModal from './components/CreateFileModal.vue';

const props = defineProps({
    website: { type: Object, required: true },
    basePath: { type: String, default: '' },
    rootFolder: { type: String, default: '' },
    currentPath: { type: String, default: '' },
    showHidden: { type: Boolean, default: false },
    openUploadTab: { type: Boolean, default: false },
    openEditorModal: { type: Boolean, default: false },
    openEditorPage: { type: Boolean, default: false },
    directoryTree: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    selectedFile: { type: Object, default: null },
});

const fm = useFileManager(props);
</script>

<template>
    <Head :title="`File Manager - ${fm.websiteLabel}`" />

    <div class="relative isolate flex h-screen flex-col overflow-hidden bg-[radial-gradient(circle_at_top_left,rgba(59,130,246,0.10),transparent_30%),radial-gradient(circle_at_bottom_right,rgba(16,185,129,0.08),transparent_28%),linear-gradient(180deg,#f8fafc_0%,#eef2ff_100%)] font-sans text-slate-800 dark:bg-[radial-gradient(circle_at_top_left,rgba(59,130,246,0.10),transparent_24%),radial-gradient(circle_at_bottom_right,rgba(16,185,129,0.06),transparent_26%),linear-gradient(180deg,#0f172a_0%,#020617_100%)] dark:text-slate-200">
        <div class="pointer-events-none absolute -left-20 top-16 h-64 w-64 rounded-full bg-sky-300/20 blur-3xl dark:bg-sky-900/20"></div>
        <div class="pointer-events-none absolute -right-16 bottom-24 h-72 w-72 rounded-full bg-emerald-300/10 blur-3xl dark:bg-emerald-900/10"></div>

        <TopBar :fm="fm" />

        <main class="flex flex-1 overflow-hidden">
            <SidebarTree :fm="fm" />

            <div
                v-if="fm.isMobile && fm.sidebarOpen"
                class="fixed inset-0 z-30 bg-black/40"
                @click="fm.closeSidebar()"
            ></div>

            <section
                class="flex min-w-0 flex-1 flex-col overflow-hidden"
                @dragenter.prevent="fm.handleTableDragEnter"
                @dragover.prevent="fm.handleTableDragOver"
                @dragleave.prevent="fm.handleTableDragLeave"
                @drop.prevent="fm.handleTableDrop"
            >
                <div v-if="fm.tableDragActive" class="pointer-events-none absolute inset-0 z-20 flex items-center justify-center rounded-xl border-2 border-dashed border-blue-500 bg-blue-500/10">
                    <div class="rounded-xl bg-white/95 px-6 py-4 text-center text-sm font-semibold text-blue-700 shadow-lg dark:bg-slate-900/95 dark:text-blue-300">
                        <i class="bi bi-cloud-arrow-up text-2xl mb-2 block text-blue-400"></i>
                        Drop files to upload to
                        <span class="font-mono text-blue-600 dark:text-blue-400">{{ fm.resolveDisplayPath(fm.props.currentPath) || '/' }}</span>
                    </div>
                </div>

                <SelectionBar :fm="fm" />

                <div class="border-b border-slate-200/80 bg-white/40 px-3 py-2 md:hidden dark:border-slate-800/70 dark:bg-slate-900/30">
                    <div class="relative">
                        <i class="bi bi-search pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                        <input
                            v-model="fm.searchQuery"
                            type="text"
                            class="w-full rounded-xl border border-slate-200/80 bg-white/80 py-2 pl-8 pr-3 text-sm shadow-sm outline-none transition focus:border-sky-400 focus:bg-white dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-300 dark:focus:bg-slate-800"
                            placeholder="Search files..."
                        >
                    </div>
                </div>

                <FileTable :fm="fm" />

                <div class="flex items-center justify-between border-t border-slate-200/80 bg-white/80 px-4 py-1.5 text-[11px] text-slate-500 backdrop-blur dark:border-slate-800/70 dark:bg-slate-950/70 dark:text-slate-400">
                    <span>{{ fm.filteredItems.length }} items</span>
                    <span v-if="fm.selectedCount" class="font-medium text-blue-600 dark:text-blue-400">{{ fm.selectedCount }} selected</span>
                </div>
            </section>
        </main>

        <ContextMenu :fm="fm" />
        <ToastStack :fm="fm" />

        <div v-if="fm.modalType" class="fixed inset-0 z-50 flex bg-black/40 p-2 sm:p-4" :class="fm.modalType === 'editor' ? 'items-stretch justify-stretch p-0 sm:p-0' : 'items-center justify-center'">
            <div
                v-if="fm.modalType !== 'editor'"
                class="flex min-h-0 w-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-700 dark:bg-slate-900"
                :class="fm.modalType === 'permissions' ? 'max-w-3xl max-h-[90vh]' : 'max-w-2xl max-h-[90vh]'"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-base font-semibold">
                        {{
                            fm.modalType === 'create-folder' ? 'Create Folder'
                                : fm.modalType === 'create-file' ? 'Create File'
                                : fm.modalType === 'rename' ? 'Rename Item'
                                : fm.modalType === 'permissions' ? 'Change Permissions'
                                : fm.modalType === 'move' ? 'Move Item'
                                : fm.modalType === 'zip' ? 'Create Zip'
                                : fm.modalType === 'unzip' ? 'Extract Zip'
                                : 'Upload File'
                        }}
                    </h3>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="fm.closeModal">
                        <i class="bi bi-x-lg mr-1"></i>Close
                    </button>
                </div>

                <CreateFolderModal :fm="fm" />
                <CreateFileModal :fm="fm" />
                <RenameModal :fm="fm" />
                <PermissionModal :fm="fm" />
                <MoveModal :fm="fm" />
                <ZipModal :fm="fm" />
                <UnzipModal :fm="fm" />
                <UploadModal :fm="fm" />
            </div>

            <FileEditor v-else :fm="fm" />
        </div>
    </div>
</template>
