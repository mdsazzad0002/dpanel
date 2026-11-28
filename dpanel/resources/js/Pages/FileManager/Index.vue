<script setup>
defineOptions({
    layout: null,
});

import { Head } from '@inertiajs/vue3';
import { provide, ref } from 'vue';
import { useFileManager } from './composables/useFileManager';
import TopBar from './components/TopBar.vue';
import SidebarTree from './components/SidebarTree.vue';
import SelectionBar from './components/SelectionBar.vue';
import FileTable from './components/FileTable.vue';
import EditorTabs from './components/EditorTabs.vue';
import SearchPalette from './components/SearchPalette.vue';
import ContextMenu from './components/ContextMenu.vue';
import ToastStack from './components/ToastStack.vue';
import FileEditor from './components/FileEditor.vue';
import FilePreview from './components/FilePreview.vue';
import SqliteOffcanvas from './components/sqlite/SqliteOffcanvas.vue';
import UploadModal from './components/UploadModal.vue';
import RenameModal from './components/RenameModal.vue';
import PermissionModal from './components/PermissionModal.vue';
import MoveModal from './components/MoveModal.vue';
import CopyModal from './components/CopyModal.vue';
import ZipModal from './components/ZipModal.vue';
import UnzipModal from './components/UnzipModal.vue';
import TrashModal from './components/TrashModal.vue';
import CreateFolderModal from './components/CreateFolderModal.vue';
import CreateFileModal from './components/CreateFileModal.vue';
import SiteSettingsOffcanvas from './components/SiteSettingsOffcanvas.vue';
import WebsiteTerminal from '@/Components/WebsiteTerminal.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    website: { type: Object, required: true },
    basePath: { type: String, default: '' },
    browseSites: { type: Array, default: () => [] },
    rootFolder: { type: String, default: '' },
    currentPath: { type: String, default: '' },
    showHidden: { type: Boolean, default: false },
    openUploadTab: { type: Boolean, default: false },
    openEditorModal: { type: Boolean, default: false },
    openEditorPage: { type: Boolean, default: false },
    hasProjectArtisan: { type: Boolean, default: false },
    directoryTree: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    selectedFile: { type: Object, default: null },
    canUseTerminal: { type: Boolean, default: false },
    rootInspection: { type: Object, default: () => ({}) },
    databaseConnection: { type: Object, default: () => ({ available: false }) },
    connectedDatabase: { type: String, default: null },
});

const fm = useFileManager(props);

// The quick-action and development-settings panels are shared with the Manage
// page, where they report through an injected toast; here that is the file
// manager's own toast stack.
provide('pushToast', fm.pushToast);

const settingsOpen = ref(false);

// Mounted on first open and kept alive afterwards so closing the drawer
// doesn't drop the shell session.
const terminalMounted = ref(false);
const terminalOpen = ref(false);
const openTerminal = () => {
    terminalMounted.value = true;
    terminalOpen.value = true;
};
</script>

<template>
    <Head :title="t('fileManager.index.headTitle', { site: fm.websiteLabel })" />

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
                        {{ t('fileManager.index.dropTo') }}
                        <span class="font-mono text-blue-600 dark:text-blue-400">{{ fm.resolveDisplayPath(fm.props.currentPath) || '/' }}</span>
                    </div>
                </div>

                <EditorTabs :fm="fm" />

                <FileEditor :fm="fm" />

                <template v-if="!fm.activeFile">
                    <SelectionBar :fm="fm" />

                    <FileTable :fm="fm" />

                    <div class="flex items-center justify-between border-t border-slate-200/80 bg-white/80 px-4 py-1.5 text-[11px] text-slate-500 backdrop-blur dark:border-slate-800/70 dark:bg-slate-950/70 dark:text-slate-400">
                        <span>{{ t('fileManager.index.items', { count: fm.filteredItems.length }) }}</span>
                        <span v-if="fm.selectedCount" class="font-medium text-blue-600 dark:text-blue-400">{{ t('fileManager.selection.selected', { count: fm.selectedCount }) }}</span>
                    </div>
                </template>
            </section>
        </main>

        <SearchPalette :fm="fm" />
        <ContextMenu :fm="fm" />
        <FilePreview :fm="fm" />
        <SqliteOffcanvas :fm="fm" />
        <ToastStack :fm="fm" />

        <div class="fixed right-0 top-1/2 z-30 flex -translate-y-1/2 flex-col items-end gap-2">
            <button
                v-if="props.canUseTerminal"
                v-show="!terminalOpen"
                type="button"
                class="flex flex-col items-center gap-2 rounded-l-lg bg-slate-900 px-1.5 py-3 text-xs font-semibold tracking-wide text-slate-100 shadow-lg transition hover:bg-slate-800 hover:pr-2.5 dark:bg-slate-800 dark:hover:bg-slate-700"
                :title="t('fileManager.index.openTerminal')"
                @click="openTerminal"
            >
                <i class="bi bi-chevron-left text-[10px]"></i>
                <span class="rotate-180 [writing-mode:vertical-rl]">{{ t('fileManager.index.terminal') }}</span>
            </button>
            <button
                v-show="!settingsOpen"
                type="button"
                class="flex flex-col items-center gap-2 rounded-l-lg bg-blue-600 px-1.5 py-3 text-xs font-semibold tracking-wide text-white shadow-lg transition hover:bg-blue-500 hover:pr-2.5"
                :title="t('fileManager.settings.open')"
                @click="settingsOpen = true"
            >
                <i class="bi bi-chevron-left text-[10px]"></i>
                <span class="rotate-180 [writing-mode:vertical-rl]">{{ t('fileManager.settings.tab') }}</span>
            </button>
        </div>

        <SiteSettingsOffcanvas
            :show="settingsOpen"
            :website="props.website"
            :root-inspection="props.rootInspection"
            :database-connection="props.databaseConnection"
            :connected-database="props.connectedDatabase"
            @close="settingsOpen = false"
        />

        <template v-if="props.canUseTerminal && terminalMounted">
            <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition-opacity duration-200" leave-active-class="transition-opacity duration-200">
                <div v-show="terminalOpen" class="fixed inset-0 z-40 bg-black/40" @click="terminalOpen = false"></div>
            </Transition>
            <Transition enter-from-class="translate-x-full" leave-to-class="translate-x-full" enter-active-class="transition-transform duration-300 ease-out" leave-active-class="transition-transform duration-200 ease-in">
                <aside v-show="terminalOpen" class="fixed inset-y-0 right-0 z-40 w-full border-l border-slate-800 shadow-2xl md:w-[70vw]">
                    <WebsiteTerminal :website="props.website" fill>
                        <template #actions>
                            <button type="button" class="text-slate-400 hover:text-white" :title="t('fileManager.index.closeTerminal')" @click="terminalOpen = false">
                                <i class="bi bi-x-lg text-sm"></i>
                            </button>
                        </template>
                    </WebsiteTerminal>
                </aside>
            </Transition>
        </template>

        <div v-if="fm.modalType" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-2 sm:p-4">
            <div
                class="flex min-h-0 w-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-700 dark:bg-slate-900"
                :class="fm.modalType === 'permissions' ? 'max-w-3xl max-h-[90vh]' : 'max-w-2xl max-h-[90vh]'"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-base font-semibold">
                        {{
                            fm.modalType === 'create-folder' ? t('fileManager.index.titles.createFolder')
                                : fm.modalType === 'create-file' ? t('fileManager.index.titles.createFile')
                                : fm.modalType === 'rename' ? t('fileManager.index.titles.rename')
                                : fm.modalType === 'permissions' ? t('fileManager.index.titles.permissions')
                                : fm.modalType === 'move' ? t('fileManager.index.titles.move')
                                : fm.modalType === 'copy' ? t('fileManager.index.titles.copy')
                                : fm.modalType === 'zip' ? t('fileManager.index.titles.zip')
                                : fm.modalType === 'unzip' ? t('fileManager.index.titles.unzip')
                                : fm.modalType === 'trash' ? t('fileManager.index.titles.trash')
                                : t('fileManager.index.titles.upload')
                        }}
                    </h3>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="fm.closeModal">
                        <i class="bi bi-x-lg mr-1"></i>{{ t('common.close') }}
                    </button>
                </div>

                <CreateFolderModal :fm="fm" />
                <CreateFileModal :fm="fm" />
                <RenameModal :fm="fm" />
                <PermissionModal :fm="fm" />
                <MoveModal :fm="fm" />
                <CopyModal :fm="fm" />
                <ZipModal :fm="fm" />
                <UnzipModal :fm="fm" />
                <TrashModal :fm="fm" />
                <UploadModal :fm="fm" />
            </div>
        </div>
    </div>
</template>
