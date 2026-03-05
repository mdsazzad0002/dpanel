<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    website: { type: Object, required: true },
    basePath: { type: String, default: '' },
    currentPath: { type: String, default: '' },
    showHidden: { type: Boolean, default: false },
    openUploadTab: { type: Boolean, default: false },
    openEditorModal: { type: Boolean, default: false },
    openEditorPage: { type: Boolean, default: false },
    directoryTree: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    selectedFile: { type: Object, default: null },
});

const page = usePage();
const modalType = ref('');
const pathInput = ref(props.currentPath || '');
const activeItemPath = ref(props.selectedFile?.path || props.items?.[0]?.path || '');
const selectedPaths = ref([]);
const hiddenEnabled = ref(Boolean(props.showHidden));
const uploadDragActive = ref(false);
const sidebarSearch = ref('');
const originalEditorContent = ref(props.selectedFile?.content ?? '');

const createFolderForm = useForm({ path: props.currentPath, name: '' });
const createFileForm = useForm({ path: props.currentPath, name: '' });
const saveForm = useForm({ file_path: props.selectedFile?.path ?? '', content: props.selectedFile?.content ?? '' });
const deleteForm = useForm({ item_paths: [], current_path: props.currentPath });
const uploadForm = useForm({ path: props.currentPath, upload: null });
const permissionForm = useForm({ item_path: '', current_path: props.currentPath, permissions: '644' });
const renameForm = useForm({ item_path: '', current_path: props.currentPath, new_name: '' });
const zipForm = useForm({ current_path: props.currentPath, item_paths: [], zip_name: '' });
const unzipForm = useForm({ zip_path: '', current_path: props.currentPath });

watch(
    () => props.currentPath,
    (value) => {
        pathInput.value = value || '';
        selectedPaths.value = [];
    },
);

watch(
    () => props.showHidden,
    (value) => {
        hiddenEnabled.value = Boolean(value);
    },
);

watch(
    () => props.selectedFile,
    (value) => {
        saveForm.file_path = value?.path ?? '';
        saveForm.content = value?.content ?? '';
        originalEditorContent.value = value?.content ?? '';
        if (value?.path) {
            activeItemPath.value = value.path;
        }
    },
    { immediate: true },
);

watch(
    () => props.items,
    (list) => {
        if (!Array.isArray(list) || list.length === 0) {
            activeItemPath.value = '';
            return;
        }

        const exists = list.some((item) => item.path === activeItemPath.value);
        if (!exists) {
            activeItemPath.value = props.selectedFile?.path || list[0].path;
        }
    },
    { immediate: true },
);

onMounted(() => {
    if (props.openUploadTab) {
        modalType.value = 'upload';
    }

    if (props.openEditorModal && props.selectedFile && !props.openEditorPage) {
        modalType.value = 'editor';
    }
});

const activeItem = computed(() => props.items.find((item) => item.path === activeItemPath.value) || null);
const filteredItems = computed(() => {
    const keyword = sidebarSearch.value.trim().toLowerCase();
    if (!keyword) return props.items;

    return props.items.filter((item) => {
        const name = String(item.name || '').toLowerCase();
        const type = String(item.type || '').toLowerCase();
        return name.includes(keyword) || type.includes(keyword);
    });
});
const selectedItems = computed(() => props.items.filter((item) => selectedPaths.value.includes(item.path)));
const selectedCount = computed(() => selectedPaths.value.length);
const singleSelectedItem = computed(() => {
    if (selectedCount.value === 1) {
        return selectedItems.value[0] || null;
    }

    return activeItem.value;
});

const isZipSelected = computed(() => {
    const item = singleSelectedItem.value;
    return Boolean(item && item.type === 'file' && String(item.name).toLowerCase().endsWith('.zip'));
});

const isBusy = computed(
    () => createFolderForm.processing || createFileForm.processing || saveForm.processing || deleteForm.processing || uploadForm.processing || permissionForm.processing || renameForm.processing || zipForm.processing || unzipForm.processing,
);
const uploadProgressPercent = computed(() => Number(uploadForm.progress?.percentage || 0));
const uploadTaskComplete = computed(() => String(page.props.flash?.success || '').toLowerCase().includes('uploaded'));
const uploadDisplayPercent = computed(() => {
    if (uploadForm.processing) return uploadProgressPercent.value;
    if (uploadTaskComplete.value) return 100;
    return 0;
});
const isImageFile = computed(() => {
    const path = String(props.selectedFile?.path || '').toLowerCase();
    return /\.(png|jpe?g|gif|webp|bmp|svg)$/.test(path);
});
const imagePreviewUrl = computed(() => {
    const path = props.selectedFile?.path;
    if (!path) return '';

    return route('websites.filemanager.file.download', {
        id: props.website.id,
        file_path: path,
        inline: 1,
    });
});
const hasUnsavedChanges = computed(() => {
    if (!props.selectedFile || props.selectedFile.readonly) return false;

    return saveForm.content !== originalEditorContent.value;
});
const unsavedFilePath = computed(() => (hasUnsavedChanges.value ? props.selectedFile?.path ?? '' : ''));

const breadcrumbParts = computed(() => {
    const parts = String(props.currentPath || '').split('/').filter(Boolean);
    const crumbs = [{ label: 'home', path: '' }];
    let acc = '';

    parts.forEach((part) => {
        acc = acc ? `${acc}/${part}` : part;
        crumbs.push({ label: part, path: acc });
    });

    return crumbs;
});

const fmQuery = (extra = {}) => ({
    path: props.currentPath || '',
    show_hidden: hiddenEnabled.value ? 1 : 0,
    ...extra,
});

const confirmDiscardChanges = () => {
    if (!hasUnsavedChanges.value) return true;
    return confirm('You have unsaved changes. Continue without saving?');
};

const openPath = (path) => {
    if (!confirmDiscardChanges()) return;
    router.get(route('websites.filemanager', props.website.id), { path, show_hidden: hiddenEnabled.value ? 1 : 0 }, { preserveState: false, preserveScroll: true });
};

const openFileInEditor = (path, options = {}) => {
    if (!confirmDiscardChanges()) return;

    const { openInNewTab = false, useModal = true } = options;
    const parent = path.includes('/') ? path.split('/').slice(0, -1).join('/') : '';
    const query = {
        id: props.website.id,
        path: parent,
        file: path,
        show_hidden: hiddenEnabled.value ? 1 : 0,
        open_editor: useModal ? 1 : 0,
        editor_page: useModal ? 0 : 1,
    };

    if (openInNewTab) {
        window.open(route('websites.filemanager', query), '_blank');
        return;
    }

    router.get(route('websites.filemanager', props.website.id), query, { preserveState: false, preserveScroll: true });
};

const goFromPathInput = () => {
    openPath(pathInput.value || '');
};

const goParent = () => {
    if (!props.currentPath) {
        openPath('');
        return;
    }

    const parent = props.currentPath.split('/').slice(0, -1).join('/');
    openPath(parent);
};

const toggleHidden = () => {
    hiddenEnabled.value = !hiddenEnabled.value;
    router.get(route('websites.filemanager', props.website.id), fmQuery({}), { preserveState: false, preserveScroll: true });
};

const openEditorInNewTab = () => {
    const item = singleSelectedItem.value;
    if (!item || item.type !== 'file') return;
    openFileInEditor(item.path, { openInNewTab: true, useModal: false });
};

const handleItemClick = (item, event) => {
    if (!item) return;

    const multiSelect = Boolean(event?.ctrlKey || event?.metaKey);
    activeItemPath.value = item.path;

    if (multiSelect) {
        if (selectedPaths.value.includes(item.path)) {
            selectedPaths.value = selectedPaths.value.filter((entry) => entry !== item.path);
        } else {
            selectedPaths.value = [...selectedPaths.value, item.path];
        }
        return;
    }

    selectedPaths.value = [item.path];
};

const toggleSelectPath = (path, checked) => {
    if (checked) {
        if (!selectedPaths.value.includes(path)) {
            selectedPaths.value = [...selectedPaths.value, path];
        }
        return;
    }

    selectedPaths.value = selectedPaths.value.filter((entry) => entry !== path);
};

const toggleSelectAll = (checked) => {
    if (checked) {
        const visible = filteredItems.value.map((item) => item.path);
        selectedPaths.value = Array.from(new Set([...selectedPaths.value, ...visible]));
        return;
    }

    const visible = new Set(filteredItems.value.map((item) => item.path));
    selectedPaths.value = selectedPaths.value.filter((path) => !visible.has(path));
};

const openModal = (type) => {
    if (type === 'editor') {
        const item = singleSelectedItem.value;
        if (!item || item.type !== 'file') return;

        if (!props.selectedFile || props.selectedFile.path !== item.path) {
            openFileInEditor(item.path, { useModal: true });
            return;
        }

        saveForm.file_path = props.selectedFile.path;
        saveForm.content = props.selectedFile.content ?? '';
    }

    modalType.value = type;

    if (type === 'rename' && singleSelectedItem.value) {
        renameForm.item_path = singleSelectedItem.value.path;
        renameForm.current_path = props.currentPath;
        renameForm.new_name = singleSelectedItem.value.name;
    }

    if (type === 'permissions' && singleSelectedItem.value) {
        permissionForm.item_path = singleSelectedItem.value.path;
        permissionForm.current_path = props.currentPath;
        permissionForm.permissions = singleSelectedItem.value.permissions || '644';
    }

    if (type === 'zip') {
        zipForm.current_path = props.currentPath;
        zipForm.item_paths = selectedPaths.value.length ? [...selectedPaths.value] : singleSelectedItem.value ? [singleSelectedItem.value.path] : [];
        zipForm.zip_name = `archive-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}`;
    }

    if (type === 'unzip' && singleSelectedItem.value) {
        unzipForm.zip_path = singleSelectedItem.value.path;
        unzipForm.current_path = props.currentPath;
    }

    if (type === 'create-folder') {
        createFolderForm.path = props.currentPath;
        createFolderForm.name = '';
    }

    if (type === 'create-file') {
        createFileForm.path = props.currentPath;
        createFileForm.name = '';
    }

    if (type === 'upload') {
        uploadForm.path = props.currentPath;
        uploadForm.upload = null;
    }

};

const closeModal = () => {
    if (modalType.value === 'editor' && !confirmDiscardChanges()) return;
    modalType.value = '';
};

const submitCreateFolder = () => {
    createFolderForm.path = props.currentPath;
    createFolderForm.post(route('websites.filemanager.folder.store', props.website.id), {
        onSuccess: () => {
            closeModal();
        },
    });
};

const submitCreateFile = () => {
    createFileForm.path = props.currentPath;
    createFileForm.post(route('websites.filemanager.file.store', props.website.id), {
        onSuccess: () => {
            closeModal();
        },
    });
};

const submitRename = () => {
    renameForm.item_path = singleSelectedItem.value?.path || renameForm.item_path;
    renameForm.current_path = props.currentPath;
    renameForm.patch(route('websites.filemanager.item.rename', props.website.id), {
        onSuccess: () => {
            closeModal();
        },
    });
};

const submitPermissions = () => {
    permissionForm.item_path = singleSelectedItem.value?.path || permissionForm.item_path;
    permissionForm.current_path = props.currentPath;
    permissionForm.patch(route('websites.filemanager.permissions', props.website.id), {
        onSuccess: () => {
            closeModal();
        },
    });
};

const deleteSelected = () => {
    const targets = selectedPaths.value.length ? [...selectedPaths.value] : singleSelectedItem.value ? [singleSelectedItem.value.path] : [];
    if (!targets.length) return;
    if (!confirm(`Delete ${targets.length} item(s)?`)) return;

    deleteForm.item_paths = targets;
    deleteForm.current_path = props.currentPath;
    deleteForm.delete(route('websites.filemanager.item.delete', props.website.id));
};

const submitZip = () => {
    const targets = selectedPaths.value.length ? [...selectedPaths.value] : singleSelectedItem.value ? [singleSelectedItem.value.path] : [];
    if (!targets.length) return;

    zipForm.current_path = props.currentPath;
    zipForm.item_paths = targets;
    zipForm.post(route('websites.filemanager.zip', props.website.id), {
        onSuccess: () => {
            closeModal();
        },
    });
};

const submitUnzip = () => {
    const targetZip = singleSelectedItem.value?.path || unzipForm.zip_path;
    if (!targetZip) return;

    unzipForm.zip_path = targetZip;
    unzipForm.current_path = props.currentPath;
    unzipForm.post(route('websites.filemanager.unzip', props.website.id), {
        onSuccess: () => {
            closeModal();
        },
    });
};

const handleUploadChange = (event) => {
    uploadForm.upload = event.target?.files?.[0] ?? null;
};

const handleUploadDragOver = () => {
    uploadDragActive.value = true;
};

const handleUploadDragLeave = () => {
    uploadDragActive.value = false;
};

const handleUploadDrop = (event) => {
    uploadDragActive.value = false;
    uploadForm.upload = event.dataTransfer?.files?.[0] ?? null;
};

const submitUpload = () => {
    uploadForm.path = props.currentPath;
    uploadForm.post(route('websites.filemanager.upload', props.website.id), {
        forceFormData: true,
        onStart: () => {
            uploadForm.clearErrors();
        },
        onSuccess: () => {
            uploadDragActive.value = false;
        },
    });
};

const saveFile = () => {
    saveForm.file_path = props.selectedFile?.path ?? '';
    saveForm.patch(route('websites.filemanager.file.save', props.website.id), {
        onSuccess: () => {
            originalEditorContent.value = saveForm.content ?? '';
        },
    });
};

const editSelected = () => {
    const item = singleSelectedItem.value;
    if (!item) return;

    if (item.type === 'dir') {
        openPath(item.path);
        return;
    }

    openFileInEditor(item.path, { useModal: !props.openEditorPage });
};

const downloadSelected = () => {
    const item = singleSelectedItem.value;
    if (!item || item.type !== 'file') return;
    const url = route('websites.filemanager.file.download', { id: props.website.id, file_path: item.path });
    window.location.href = url;
};

const formatBytes = (bytes) => {
    if (bytes === null || bytes === undefined) return '-';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

</script>

<template>
    <Head :title="`File Manager - ${website.domain}`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">File Manager</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ website.domain }} (base: {{ basePath }})</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" title="Edit/Open" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="isBusy" @click="editSelected">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M12.146.854a.5.5 0 0 1 .708 0l2.292 2.292a.5.5 0 0 1 0 .708l-8.5 8.5L4 13l.646-2.646z"/><path d="M1 15h14v1H1z"/></svg>
                        <span class="sr-only">Edit/Open</span>
                    </button>
                    <button type="button" title="Rename" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="!singleSelectedItem || isBusy" @click="openModal('rename')">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M3.5 3a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 0 1H9v9h3a.5.5 0 0 1 0 1H4a.5.5 0 0 1 0-1h3v-9H4a.5.5 0 0 1-.5-.5"/></svg>
                        <span class="sr-only">Rename</span>
                    </button>
                    <button type="button" title="New File" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="isBusy" @click="openModal('create-file')">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5z"/><path d="M10 0v4a1 1 0 0 0 1 1h4"/><path d="M8 7v2H6v1h2v2h1v-2h2V9H9V7z"/></svg>
                        <span class="sr-only">New File</span>
                    </button>
                    <button type="button" title="New Folder" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="isBusy" @click="openModal('create-folder')">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M9.828 4a.5.5 0 0 1 .354.146L11 5h3a1 1 0 0 1 1 1v1H1V3a1 1 0 0 1 1-1h4z"/><path d="M1 8h14v5a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2z"/><path d="M8 9v2H6v1h2v2h1v-2h2v-1H9V9z"/></svg>
                        <span class="sr-only">New Folder</span>
                    </button>
                    <button type="button" title="Upload" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="isBusy" @click="openModal('upload')">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M7.5 1.5h1v7.793l1.646-1.647.708.708L8 11.207 5.146 8.354l.708-.708L7.5 9.293z"/><path d="M1 12.5A2.5 2.5 0 0 1 3.5 10h9A2.5 2.5 0 0 1 15 12.5V14H1z"/></svg>
                        <span class="sr-only">Upload</span>
                    </button>
                    <button type="button" title="Delete" class="rounded-md border border-red-300 px-3 py-2 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400" :disabled="(!singleSelectedItem && !selectedCount) || isBusy" @click="deleteSelected">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M5.5 5.5h1v6h-1zm4 0h1v6h-1z"/><path d="M14 3h-3.5l-1-1h-3l-1 1H2v1h12z"/><path d="M4 4h8l-.5 9a2 2 0 0 1-2 2h-3a2 2 0 0 1-2-2z"/></svg>
                        <span class="sr-only">Delete</span>
                    </button>
                    <button type="button" title="Zip" class="rounded-md border border-amber-300 px-3 py-2 text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300" :disabled="(!singleSelectedItem && !selectedCount) || isBusy" @click="openModal('zip')">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5z"/><path d="M10 0v4a1 1 0 0 0 1 1h4"/><path d="M7 6h2v1H7zm0 2h2v1H7zm0 2h2v1H7z"/></svg>
                        <span class="sr-only">Zip</span>
                    </button>
                    <button type="button" title="Unzip" class="rounded-md border border-emerald-300 px-3 py-2 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-400" :disabled="!isZipSelected || isBusy" @click="openModal('unzip')">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1 1 5l7 4 7-4z"/><path d="M1 7l7 4 7-4v5l-7 4-7-4z"/></svg>
                        <span class="sr-only">Unzip</span>
                    </button>
                    <button type="button" title="Permissions" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="!singleSelectedItem || isBusy" @click="openModal('permissions')">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0 2 2v5c0 4.5 2.9 7.2 6 9 3.1-1.8 6-4.5 6-9V2z"/><path d="M6.5 7V6a1.5 1.5 0 1 1 3 0v1h.5a1 1 0 0 1 1 1v2.5A1.5 1.5 0 0 1 9.5 12h-3A1.5 1.5 0 0 1 5 10.5V8a1 1 0 0 1 1-1z"/></svg>
                        <span class="sr-only">Permissions</span>
                    </button>
                    <button type="button" title="Download" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="!singleSelectedItem || singleSelectedItem.type !== 'file'" @click="downloadSelected">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M7.5 1.5h1v7.793l1.646-1.647.708.708L8 11.207 5.146 8.354l.708-.708L7.5 9.293z"/><path d="M1 14h14v1H1z"/></svg>
                        <span class="sr-only">Download</span>
                    </button>
                    <button type="button" title="Up One Level" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="isBusy" @click="goParent">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="m8 2-3 3h2v4h2V5h2z"/><path d="M2 12h12v2H2z"/></svg>
                        <span class="sr-only">Up One Level</span>
                    </button>
                    <button type="button" :title="hiddenEnabled ? 'Hide Dot Files' : 'Show Dot Files'" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="isBusy" @click="toggleHidden">
                        <svg v-if="hiddenEnabled" aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M13.359 11.238C14.52 10.15 15.37 8.89 16 8c-1.12-1.58-3.32-4.5-6.88-5.32l1.06 1.06a8.8 8.8 0 0 1 4.72 4.26 11.8 11.8 0 0 1-2.24 2.66z"/><path d="M11.297 13.176A8.7 8.7 0 0 1 8 13.5C3 13.5 0 8 0 8c.71-1.03 1.6-2.12 2.72-3.03l1.43 1.43A3 3 0 0 0 7.6 9.85z"/><path d="m14.854 15.146-14-14 .708-.708 14 14z"/></svg>
                        <svg v-else aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M8 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6"/></svg>
                        <span class="sr-only">{{ hiddenEnabled ? 'Hide Dot Files' : 'Show Dot Files' }}</span>
                    </button>
                    <Link :href="route('websites.list')" title="Back to Websites" class="ml-auto rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0m3.5 8.5h-6l2 2-.707.707L3.586 8l3.207-3.207.707.707-2 2h6z"/></svg>
                        <span class="sr-only">Back to Websites</span>
                    </Link>
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-12">
                <aside class="xl:col-span-4 space-y-3 rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Quick Path</p>
                            <button type="button" class="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="openPath('')">Root</button>
                        </div>
                        <div class="mb-2 flex flex-wrap items-center gap-1 text-[11px]">
                            <button v-for="crumb in breadcrumbParts" :key="crumb.path || 'home'" type="button" class="rounded-md border border-slate-300 px-2 py-1 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="openPath(crumb.path)">
                                {{ crumb.label }}
                            </button>
                        </div>
                        <div class="flex items-center gap-1">
                            <input v-model="pathInput" type="text" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800" placeholder="folder/subfolder" />
                            <button type="button" class="rounded-md bg-slate-800 px-2 py-1.5 text-[11px] font-medium text-white hover:bg-slate-700" :disabled="isBusy" @click="goFromPathInput">
                                Go
                            </button>
                        </div>
                    </div>

                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Directories</p>
                        <button type="button" class="mb-1 w-full rounded-md border border-slate-300 px-2 py-1 text-left text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="openPath('')">
                            / (root)
                        </button>
                        <div class="max-h-44 space-y-1 overflow-y-auto text-xs">
                            <div v-for="dir in directoryTree" :key="dir.path" class="space-y-1">
                                <button type="button" class="w-full rounded-md px-2 py-1 text-left hover:bg-slate-100 dark:hover:bg-slate-800" :class="currentPath === dir.path ? 'bg-blue-50 dark:bg-blue-900/20' : ''" @click="openPath(dir.path)">
                                    {{ dir.name }}
                                </button>
                                <div v-if="dir.children?.length" class="space-y-1 pl-4">
                                    <button v-for="child in dir.children" :key="child.path" type="button" class="w-full rounded-md px-2 py-1 text-left text-[11px] hover:bg-slate-100 dark:hover:bg-slate-800" :class="currentPath === child.path ? 'bg-blue-50 dark:bg-blue-900/20' : ''" @click="openPath(child.path)">
                                        {{ child.name }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Files</p>
                            <span class="text-[11px] text-slate-500">Sel: {{ selectedCount }}</span>
                        </div>
                        <input v-model="sidebarSearch" type="text" class="mb-2 w-full rounded-md border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800" placeholder="Filter files..." />
                        <div class="max-h-[26rem] overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-800">
                            <table class="min-w-full text-left text-[11px]">
                                <thead class="bg-slate-50 dark:bg-slate-800">
                                    <tr>
                                        <th class="px-2 py-1.5">
                                            <input type="checkbox" :checked="filteredItems.length > 0 && filteredItems.every((item) => selectedPaths.includes(item.path))" @change="toggleSelectAll($event.target.checked)" />
                                        </th>
                                        <th class="px-2 py-1.5">Name</th>
                                        <th class="px-2 py-1.5">Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="item in filteredItems"
                                        :key="`side-${item.path}`"
                                        class="cursor-pointer border-t border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/60"
                                        :class="selectedPaths.includes(item.path) || activeItemPath === item.path ? 'bg-blue-50 dark:bg-blue-900/20' : ''"
                                        @click="handleItemClick(item, $event)"
                                        @dblclick="item.type === 'dir' ? openPath(item.path) : openFileInEditor(item.path, { useModal: !openEditorPage })"
                                    >
                                        <td class="px-2 py-1.5" @click.stop>
                                            <input type="checkbox" :checked="selectedPaths.includes(item.path)" @change="toggleSelectPath(item.path, $event.target.checked)" />
                                        </td>
                                        <td class="px-2 py-1.5 font-medium">
                                            {{ item.name }}
                                            <span v-if="unsavedFilePath === item.path" class="ml-1 text-[10px] font-semibold text-amber-600">*</span>
                                        </td>
                                        <td class="px-2 py-1.5 uppercase">{{ item.type }}</td>
                                    </tr>
                                    <tr v-if="filteredItems.length === 0">
                                        <td colspan="3" class="px-3 py-4 text-center text-slate-500">No files.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </aside>

                <section class="xl:col-span-8 space-y-4">
                    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-3">Name</th>
                                <th class="px-3 py-3">Type</th>
                                <th class="px-3 py-3">Size</th>
                                <th class="px-3 py-3">Perm</th>
                                <th class="px-3 py-3">Modified</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in items"
                                :key="item.path"
                                class="cursor-pointer border-t border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/60"
                                :class="selectedPaths.includes(item.path) || activeItemPath === item.path ? 'bg-blue-50 dark:bg-blue-900/20' : ''"
                                @click="handleItemClick(item, $event)"
                                @dblclick="item.type === 'dir' ? openPath(item.path) : openFileInEditor(item.path, { useModal: !openEditorPage })"
                            >
                                <td class="px-3 py-2 font-medium">
                                    {{ item.name }}
                                    <span v-if="unsavedFilePath === item.path" class="ml-1 text-[10px] font-semibold text-amber-600">*</span>
                                </td>
                                <td class="px-3 py-2 uppercase text-xs">{{ item.type }}</td>
                                <td class="px-3 py-2">{{ formatBytes(item.size) }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ item.permissions }}</td>
                                <td class="px-3 py-2 text-xs text-slate-500">{{ item.modified_at ? new Date(item.modified_at).toLocaleString() : '-' }}</td>
                            </tr>
                            <tr v-if="items.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-slate-500">No files in this directory.</td>
                            </tr>
                        </tbody>
                        </table>
                    </div>

                   
                </section>
            </div>

            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>
        </div>

        <div v-if="modalType" class="fixed inset-0 z-50 flex bg-black/40 p-4" :class="modalType === 'editor' ? 'items-stretch justify-stretch' : 'items-center justify-center'">
            <div class="w-full rounded-xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-700 dark:bg-slate-900" :class="modalType === 'editor' ? 'h-full max-w-none' : 'max-w-lg'">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-base font-semibold">
                        {{
                            modalType === 'create-folder' ? 'Create Folder'
                                : modalType === 'create-file' ? 'Create File'
                                : modalType === 'rename' ? 'Rename Item'
                                : modalType === 'permissions' ? 'Change Permissions'
                                : modalType === 'zip' ? 'Create Zip'
                                : modalType === 'unzip' ? 'Extract Zip'
                                : modalType === 'editor' ? 'File Editor'
                                : 'Upload File'
                        }}
                    </h3>
                    <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="closeModal">Close</button>
                </div>

                <div v-if="modalType === 'create-folder'" class="space-y-3">
                    <input v-model="createFolderForm.name" type="text" placeholder="folder-name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <button type="button" :disabled="createFolderForm.processing || !createFolderForm.name" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitCreateFolder">
                        {{ createFolderForm.processing ? 'Creating...' : 'Create Folder' }}
                    </button>
                </div>

                <div v-else-if="modalType === 'create-file'" class="space-y-3">
                    <input v-model="createFileForm.name" type="text" placeholder="file-name.ext" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <button type="button" :disabled="createFileForm.processing || !createFileForm.name" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitCreateFile">
                        {{ createFileForm.processing ? 'Creating...' : 'Create File' }}
                    </button>
                </div>

                <div v-else-if="modalType === 'rename'" class="space-y-3">
                    <input v-model="renameForm.new_name" type="text" placeholder="new name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <button type="button" :disabled="renameForm.processing || !renameForm.new_name" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitRename">
                        {{ renameForm.processing ? 'Renaming...' : 'Rename' }}
                    </button>
                </div>

                <div v-else-if="modalType === 'permissions'" class="space-y-3">
                    <input v-model="permissionForm.permissions" type="text" placeholder="644" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <button type="button" :disabled="permissionForm.processing || !permissionForm.permissions" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitPermissions">
                        {{ permissionForm.processing ? 'Saving...' : 'Save Permission' }}
                    </button>
                </div>

                <div v-else-if="modalType === 'zip'" class="space-y-3">
                    <p class="text-xs text-slate-500">Selected items: {{ selectedCount || (singleSelectedItem ? 1 : 0) }}</p>
                    <input v-model="zipForm.zip_name" type="text" placeholder="archive-name.zip" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <button type="button" :disabled="zipForm.processing || (!selectedCount && !singleSelectedItem)" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitZip">
                        {{ zipForm.processing ? 'Creating Zip...' : 'Create Zip' }}
                    </button>
                </div>

                <div v-else-if="modalType === 'unzip'" class="space-y-3">
                    <p class="text-xs text-slate-500 break-all">Zip file: {{ unzipForm.zip_path }}</p>
                    <button type="button" :disabled="unzipForm.processing || !unzipForm.zip_path" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitUnzip">
                        {{ unzipForm.processing ? 'Extracting...' : 'Extract Zip' }}
                    </button>
                </div>

                <div v-else-if="modalType === 'upload'" class="space-y-3">
                    <div
                        class="rounded-lg border-2 border-dashed p-6 text-center transition"
                        :class="uploadDragActive ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-slate-300 dark:border-slate-700'"
                        @dragover.prevent="handleUploadDragOver"
                        @dragenter.prevent="handleUploadDragOver"
                        @dragleave.prevent="handleUploadDragLeave"
                        @drop.prevent="handleUploadDrop"
                    >
                        <p class="text-sm font-medium">Drag and drop file here</p>
                        <p class="mt-1 text-xs text-slate-500">or choose from your computer</p>
                        <input id="file-upload-input" type="file" class="mt-3 w-full text-sm" @change="handleUploadChange" />
                        <p v-if="uploadForm.upload" class="mt-2 break-all text-xs text-slate-600 dark:text-slate-300">
                            Selected: {{ uploadForm.upload.name }}
                        </p>
                    </div>
                    <button type="button" :disabled="uploadForm.processing || !uploadForm.upload" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitUpload">
                        {{ uploadForm.processing ? 'Uploading...' : 'Upload File' }}
                    </button>
                    <div v-if="uploadForm.processing || uploadTaskComplete" class="space-y-1">
                        <div class="flex items-center justify-between text-xs text-slate-500">
                            <span>{{ uploadTaskComplete ? 'Task Complete' : 'Upload progress' }}</span>
                            <span>{{ uploadDisplayPercent }}%</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded bg-slate-200 dark:bg-slate-700">
                            <div class="h-full transition-all" :class="uploadTaskComplete ? 'bg-emerald-600' : 'bg-blue-600'" :style="{ width: `${uploadDisplayPercent}%` }" />
                        </div>
                    </div>
                </div>

                <div v-else class="flex h-[calc(100%-3rem)] flex-col gap-3">
                    <p class="text-xs text-slate-500 break-all">
                        {{ selectedFile?.path }}
                    </p>
                    <p v-if="hasUnsavedChanges" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                        Unsaved changes
                    </p>
                    <p v-if="selectedFile?.message" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                        {{ selectedFile.message }}
                    </p>
                    <div v-if="isImageFile" class="min-h-0 flex-1 overflow-auto rounded-md border border-slate-300 p-2 dark:border-slate-700">
                        <img :src="imagePreviewUrl" alt="Preview" class="mx-auto max-h-full max-w-full object-contain" />
                    </div>
                    <textarea v-else v-model="saveForm.content" :readonly="selectedFile?.readonly" class="min-h-0 flex-1 rounded-md border border-slate-300 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800" />
                    <div class="flex flex-wrap gap-2">
                        <button type="button" :disabled="saveForm.processing || selectedFile?.readonly || isImageFile" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="saveFile">
                            {{ saveForm.processing ? 'Saving...' : 'Save File' }}
                        </button>
                        <button type="button" class="rounded-md border border-indigo-300 px-4 py-2 text-sm text-indigo-700 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-300" :disabled="!singleSelectedItem || singleSelectedItem.type !== 'file'" @click="openEditorInNewTab">
                            Open in New Tab
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>


