<script setup>
defineOptions({
    layout: null,
});

import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const modalType = ref('');
const sidebarOpen = ref(true);
const rightSidebarOpen = ref(true);
const viewMode = ref('table'); // 'table' or 'grid'
const sortBy = ref('name');
const sortDir = ref('asc');
const searchQuery = ref('');
const isMobile = ref(false);

function checkMobile() {
    isMobile.value = window.innerWidth < 1024;
    if (isMobile.value) sidebarOpen.value = false;
}

function normalizePathValue(value) {
    return String(value || '').trim();
}

function normalizedBasePath() {
    return String(props.basePath || '').trim().replace(/\\/g, '/').replace(/\/+$/, '');
}

function resolveDisplayPath(relativePath = '') {
    const base = normalizedBasePath();
    const current = normalizePathValue(relativePath).replace(/^\/+/, '');

    if (!base) {
        return current;
    }

    return current ? `${base}/${current}` : base;
}

const pathInput = ref(resolveDisplayPath(props.currentPath));
const activeItemPath = ref(props.selectedFile?.path || props.items?.[0]?.path || '');
const selectedPaths = ref([]);
const selectionAnchorPath = ref('');
const hiddenEnabled = ref(Boolean(props.showHidden));
const uploadDragActive = ref(false);
const tableDragActive = ref(false);
const tableDragDepth = ref(0);
const droppedUploadHint = ref('');
const moveDragTargetPath = ref(null);
const draggingItemPaths = ref([]);
const originalEditorContent = ref(props.selectedFile?.content ?? '');
const treeOpenState = ref({});
const INTERNAL_MOVE_MIME = 'application/x-serverpanel-item-paths';
const contextMenu = ref({
    visible: false,
    x: 0,
    y: 0,
    itemPath: '',
    itemType: '',
    itemName: '',
});

function closeContextMenu() {
    if (!contextMenu.value.visible) return;
    contextMenu.value = {
        visible: false,
        x: 0,
        y: 0,
        itemPath: '',
        itemType: '',
        itemName: '',
    };
}

const createFolderForm = useForm({ path: props.currentPath, name: '' });
const createFileForm = useForm({ path: props.currentPath, name: '' });
const saveForm = useForm({ file_path: props.selectedFile?.path ?? '', content: props.selectedFile?.content ?? '' });
const deleteForm = useForm({ item_paths: [], current_path: props.currentPath });
const uploadForm = useForm({ path: props.currentPath, upload: null });
const permissionForm = useForm({ item_path: '', current_path: props.currentPath, permissions: '644', recursive: false });
const renameForm = useForm({ item_path: '', current_path: props.currentPath, new_name: '' });
const zipForm = useForm({ current_path: props.currentPath, item_paths: [], zip_name: '' });
const unzipForm = useForm({ zip_path: '', current_path: props.currentPath });
const moveForm = useForm({ item_path: '', item_paths: [], current_path: props.currentPath, destination_path: props.currentPath });

watch(
    () => props.currentPath,
    (value) => {
        pathInput.value = resolveDisplayPath(value);
        selectedPaths.value = [];
        selectionAnchorPath.value = '';
        closeContextMenu();
    },
    { immediate: true },
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
            selectedPaths.value = [];
            selectionAnchorPath.value = '';
            closeContextMenu();
            return;
        }

        const existingPaths = new Set(list.map((item) => item.path));
        selectedPaths.value = selectedPaths.value.filter((path) => existingPaths.has(path));
        if (selectionAnchorPath.value && !existingPaths.has(selectionAnchorPath.value)) {
            selectionAnchorPath.value = '';
        }

        const exists = list.some((item) => item.path === activeItemPath.value);
        if (!exists) {
            activeItemPath.value = props.selectedFile?.path || list[0].path;
        }

        if (contextMenu.value.visible) {
            const contextExists = list.some((item) => item.path === contextMenu.value.itemPath);
            if (!contextExists) {
                closeContextMenu();
            }
        }
    },
);

const isBusy = computed(() =>
    createFolderForm.processing || createFileForm.processing || saveForm.processing ||
    deleteForm.processing || uploadForm.processing || permissionForm.processing ||
    renameForm.processing || zipForm.processing || unzipForm.processing || moveForm.processing
);

const selectedCount = computed(() => selectedPaths.value.length);

const singleSelectedItem = computed(() => {
    if (selectedPaths.value.length !== 1) return null;
    return props.items.find((item) => item.path === selectedPaths.value[0]) || null;
});

const isZipSelected = computed(() => {
    if (!singleSelectedItem.value) return false;
    return singleSelectedItem.value.type === 'file' && String(singleSelectedItem.value.name || '').toLowerCase().endsWith('.zip');
});

const filteredItems = computed(() => {
    let list = [...(props.items || [])];

    if (searchQuery.value.trim()) {
        const q = searchQuery.value.toLowerCase();
        list = list.filter(item => item.name.toLowerCase().includes(q));
    }

    list.sort((a, b) => {
        if (a.type === 'dir' && b.type !== 'dir') return -1;
        if (a.type !== 'dir' && b.type === 'dir') return 1;

        let valA, valB;
        switch (sortBy.value) {
            case 'size': valA = a.size || 0; valB = b.size || 0; break;
            case 'modified': valA = a.modified_at || ''; valB = b.modified_at || ''; break;
            case 'type': valA = a.type || ''; valB = b.type || ''; break;
            default: valA = (a.name || '').toLowerCase(); valB = (b.name || '').toLowerCase();
        }

        if (valA < valB) return sortDir.value === 'asc' ? -1 : 1;
        if (valA > valB) return sortDir.value === 'asc' ? 1 : -1;
        return 0;
    });

    return list;
});

const treeRows = computed(() => {
    const rows = [];
    const walk = (nodes, level = 0) => {
        if (!Array.isArray(nodes)) return;
        for (const node of nodes) {
            const open = treeOpenState.value[node.path] ?? (level < 1);
            rows.push({ ...node, level, expanded: open });
            if (open && node.children) {
                walk(node.children, level + 1);
            }
        }
    };
    walk(props.directoryTree);
    return rows;
});

function toggleTreeNode(path) {
    treeOpenState.value = {
        ...treeOpenState.value,
        [path]: !(treeOpenState.value[path] ?? false),
    };
}

function isTreeNodeActive(path) {
    return resolveDisplayPath(props.currentPath) === path || String(props.currentPath || '').startsWith(path + '/');
}

function openPath(path) {
    router.get(panelRoute('websites.filemanager', { id: props.website.id, path }));
}

function goRoot() {
    openPath('');
}

function goParent() {
    const segments = String(props.currentPath || '').split('/').filter(Boolean);
    if (segments.length === 0) return;
    segments.pop();
    openPath(segments.join('/'));
}

function goFromPathInput() {
    const val = String(pathInput.value || '').trim().replace(/^\/+/, '').replace(/\/+$/, '');
    openPath(val);
}

function toggleHidden() {
    hiddenEnabled.value = !hiddenEnabled.value;
    const sep = props.currentPath.includes('?') ? '&' : '?';
    router.visit(`${window.location.pathname}${sep}show_hidden=${hiddenEnabled.value ? '1' : '0'}`, { preserveScroll: true, preserveState: true });
}

function toggleSelectAll(checked) {
    if (checked) {
        selectedPaths.value = filteredItems.value.map((item) => item.path);
    } else {
        selectedPaths.value = [];
    }
}

function toggleSelectPath(path, checked, event) {
    if (event?.shiftKey && selectionAnchorPath.value) {
        const allPaths = filteredItems.value.map((item) => item.path);
        const start = allPaths.indexOf(selectionAnchorPath.value);
        const end = allPaths.indexOf(path);
        if (start !== -1 && end !== -1) {
            const range = allPaths.slice(Math.min(start, end), Math.max(start, end) + 1);
            const set = new Set(selectedPaths.value);
            range.forEach((p) => set.add(p));
            selectedPaths.value = Array.from(set);
            return;
        }
    }

    if (checked) {
        selectionAnchorPath.value = path;
        selectedPaths.value = [...selectedPaths.value, path];
    } else {
        selectedPaths.value = selectedPaths.value.filter((p) => p !== path);
    }
}

function handleItemClick(item, event) {
    // Single click: only highlight row (set active), do NOT check checkbox
    activeItemPath.value = item.path;

    // Ctrl/Cmd+click: toggle checkbox
    if (event?.ctrlKey || event?.metaKey) {
        const checked = !selectedPaths.value.includes(item.path);
        toggleSelectPath(item.path, checked, event);
        return;
    }

    // Shift+click: range select checkboxes
    if (event?.shiftKey && selectionAnchorPath.value) {
        toggleSelectPath(item.path, true, event);
        return;
    }

    // Normal single click: just highlight, clear other selections
    selectedPaths.value = [];
    selectionAnchorPath.value = item.path;
}

function openContextMenu(item, event) {
    if (!selectedPaths.value.includes(item.path)) {
        selectedPaths.value = [item.path];
        activeItemPath.value = item.path;
    }

    const x = Math.min(event.clientX, window.innerWidth - 240);
    const y = Math.min(event.clientY, window.innerHeight - 400);

    contextMenu.value = {
        visible: true,
        x,
        y,
        itemPath: item.path,
        itemType: item.type,
        itemName: item.name,
    };
}

const contextItem = computed(() => props.items.find((item) => item.path === contextMenu.value.itemPath) || null);
const contextZip = computed(() => contextItem.value?.type === 'file' && String(contextItem.value?.name || '').toLowerCase().endsWith('.zip'));

function triggerContextAction(action) {
    const item = contextItem.value;
    closeContextMenu();
    if (!item) return;

    switch (action) {
        case 'open':
            item.type === 'dir' ? openPath(item.path) : openFileInEditor(item.path, { useModal: !props.openEditorPage });
            break;
        case 'open-tab':
            if (isEditableFile(item.name)) {
                window.open(panelRoute('websites.filemanager', { id: props.website.id, file_path: item.path }), '_blank');
            } else {
                pushToast(`Cannot edit "${item.name}". This file type is not supported for editing.`, 'info');
            }
            break;
        case 'download':
            downloadSelected();
            break;
        case 'rename':
            renameForm.item_path = item.path;
            renameForm.new_name = item.name;
            openModal('rename');
            break;
        case 'permissions':
            permissionForm.item_path = item.path;
            permissionForm.permissions = String(item.permissions || '644');
            openModal('permissions');
            break;
        case 'move':
            moveForm.item_path = item.path;
            moveForm.item_paths = selectedPaths.value.length ? selectedPaths.value : [item.path];
            moveForm.destination_path = props.currentPath;
            openModal('move');
            break;
        case 'zip':
            zipForm.item_paths = selectedPaths.value.length ? selectedPaths.value : [item.path];
            zipForm.zip_name = item.type === 'dir' ? `${item.name}.zip` : `${item.name.replace(/\.[^.]+$/, '')}.zip`;
            openModal('zip');
            break;
        case 'unzip':
            unzipForm.zip_path = item.path;
            openModal('unzip');
            break;
        case 'delete':
            deleteForm.item_paths = selectedPaths.value.length ? selectedPaths.value : [item.path];
            deleteSelected();
            break;
    }
}

function openModal(type) {
    modalType.value = type;
}

function closeModal() {
    modalType.value = '';
}

// Editable file extensions
const EDITABLE_EXTENSIONS = [
    'php', 'html', 'htm', 'css', 'scss', 'less', 'js', 'ts', 'jsx', 'tsx', 'vue',
    'json', 'xml', 'yml', 'yaml', 'env', 'txt', 'md', 'log', 'sql', 'sh', 'bash',
    'py', 'rb', 'go', 'rs', 'java', 'c', 'cpp', 'h', 'ini', 'conf', 'config',
    'htaccess', 'gitignore', 'dockerignore', 'editorconfig', 'eslintrc', 'prettierrc',
    'makefile', 'dockerfile', 'csv', 'tsv', 'svg', 'twig', 'blade.php', 'php',
    'bat', 'cmd', 'ps1', 'docker-compose', 'nginx', 'apache',
];

// Non-editable file extensions (binary/media)
const NON_EDITABLE_EXTENSIONS = [
    'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'tiff',
    'mp3', 'wav', 'ogg', 'flac', 'aac', 'wma',
    'mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'zip', 'tar', 'gz', 'rar', '7z',
    'exe', 'dll', 'so', 'dylib', 'bin',
    'ttf', 'otf', 'woff', 'woff2',
    'ai', 'psd', 'sketch', 'fig',
];

function isEditableFile(filename) {
    const name = String(filename || '').toLowerCase();
    const ext = name.split('.').pop() || '';

    // Check non-editable first
    if (NON_EDITABLE_EXTENSIONS.includes(ext)) return false;

    // Check editable
    if (EDITABLE_EXTENSIONS.includes(ext)) return true;

    // Special cases for files without extension or dotfiles
    const basename = name.split('/').pop() || '';
    if (basename === '.env' || basename === '.htaccess' || basename === 'makefile' || basename === 'dockerfile') return true;

    return false;
}

function openFileInEditor(path, options = {}) {
    const filename = path.split('/').pop() || '';

    if (!isEditableFile(filename)) {
        // Not an editable file - show message or download
        pushToast(`Cannot edit "${filename}". This file type is not supported for editing.`, 'info');
        return;
    }

    router.get(panelRoute('websites.filemanager', { id: props.website.id, file_path: path }));
}

function deleteSelected() {
    const paths = selectedPaths.value.length ? selectedPaths.value : (singleSelectedItem.value ? [singleSelectedItem.value.path] : []);
    if (!paths.length) return;

    if (!confirm(`Delete ${paths.length} item(s)?`)) return;

    deleteForm.item_paths = paths;
    deleteForm.current_path = props.currentPath;
    deleteForm.delete(panelRoute('websites.filemanager.item.delete', { id: props.website.id }), {
        onSuccess: () => {
            selectedPaths.value = [];
        },
    });
}

function downloadSelected() {
    if (!singleSelectedItem.value || singleSelectedItem.value.type !== 'file') return;
    window.location.href = panelRoute('websites.filemanager.file.download', { id: props.website.id, file_path: singleSelectedItem.value.path });
}

function submitCreateFolder() {
    createFolderForm.path = props.currentPath;
    createFolderForm.post(panelRoute('websites.filemanager.folder.store', { id: props.website.id }), {
        onSuccess: () => {
            createFolderForm.name = '';
            closeModal();
        },
    });
}

function submitCreateFile() {
    createFileForm.path = props.currentPath;
    createFileForm.post(panelRoute('websites.filemanager.file.store', { id: props.website.id }), {
        onSuccess: () => {
            createFileForm.name = '';
            closeModal();
        },
    });
}

function submitRename() {
    renameForm.current_path = props.currentPath;
    renameForm.post(panelRoute('websites.filemanager.item.rename', { id: props.website.id }), {
        onSuccess: () => {
            renameForm.new_name = '';
            closeModal();
        },
    });
}

function submitPermissions() {
    permissionForm.item_path = permissionForm.item_path;
    permissionForm.current_path = props.currentPath;
    permissionForm.post(panelRoute('websites.filemanager.permissions', { id: props.website.id }), {
        onSuccess: () => {
            closeModal();
        },
    });
}

function submitMove() {
    moveForm.current_path = props.currentPath;
    moveForm.post(panelRoute('websites.filemanager.item.move', { id: props.website.id }), {
        onSuccess: () => {
            moveForm.destination_path = props.currentPath;
            selectedPaths.value = [];
            closeModal();
        },
    });
}

function submitZip() {
    zipForm.current_path = props.currentPath;
    zipForm.post(panelRoute('websites.filemanager.zip', { id: props.website.id }), {
        onSuccess: () => {
            zipForm.zip_name = '';
            selectedPaths.value = [];
            closeModal();
        },
    });
}

function submitUnzip() {
    unzipForm.current_path = props.currentPath;
    unzipForm.post(panelRoute('websites.filemanager.unzip', { id: props.website.id }), {
        onSuccess: () => {
            closeModal();
        },
    });
}

function submitUpload() {
    uploadForm.path = props.currentPath;
    uploadForm.post(panelRoute('websites.filemanager.upload', { id: props.website.id }), {
        onSuccess: () => {
            uploadForm.upload = null;
            closeModal();
        },
    });
}

function submitSaveFile() {
    saveForm.post(panelRoute('websites.filemanager.file.save', { id: props.website.id }), {
        onSuccess: () => {
            originalEditorContent.value = saveForm.content;
        },
    });
}

const hasUnsavedChanges = computed(() => saveForm.content !== originalEditorContent.value);
const unsavedFilePath = computed(() => hasUnsavedChanges.value ? saveForm.file_path : '');

function handleEditorBeforeUnload(event) {
    if (hasUnsavedChanges.value) {
        event.preventDefault();
        event.returnValue = '';
    }
}

function iconClassForItem(item) {
    if (item.type === 'dir') return 'bi-folder-fill text-amber-500';
    const ext = String(item.name || '').split('.').pop()?.toLowerCase() || '';
    const map = {
        php: 'bi-filetype-php text-indigo-500',
        js: 'bi-filetype-js text-amber-500',
        ts: 'bi-filetype-tsx text-blue-600',
        jsx: 'bi-filetype-jsx text-cyan-500',
        tsx: 'bi-filetype-tsx text-cyan-600',
        vue: 'bi-filetype-vue text-emerald-500',
        html: 'bi-filetype-html text-orange-500',
        htm: 'bi-filetype-html text-orange-500',
        css: 'bi-filetype-css text-blue-500',
        scss: 'bi-filetype-scss text-pink-500',
        less: 'bi-filetype-css text-blue-400',
        json: 'bi-filetype-json text-amber-600',
        md: 'bi-filetype-md text-slate-600',
        txt: 'bi-file-earmark-text text-slate-500',
        log: 'bi-file-earmark-text text-slate-400',
        png: 'bi-file-earmark-image text-emerald-500',
        jpg: 'bi-file-earmark-image text-emerald-500',
        jpeg: 'bi-file-earmark-image text-emerald-500',
        gif: 'bi-file-earmark-image text-emerald-500',
        svg: 'bi-file-earmark-image text-emerald-500',
        webp: 'bi-file-earmark-image text-emerald-500',
        ico: 'bi-file-earmark-image text-emerald-500',
        zip: 'bi-file-earmark-zip text-amber-600',
        tar: 'bi-file-earmark-zip text-amber-600',
        gz: 'bi-file-earmark-zip text-amber-600',
        rar: 'bi-file-earmark-zip text-amber-600',
        '7z': 'bi-file-earmark-zip text-amber-600',
        pdf: 'bi-file-earmark-pdf text-red-500',
        doc: 'bi-file-earmark-word text-blue-600',
        docx: 'bi-file-earmark-word text-blue-600',
        xls: 'bi-file-earmark-excel text-green-600',
        xlsx: 'bi-file-earmark-excel text-green-600',
        csv: 'bi-filetype-csv text-green-500',
        ppt: 'bi-file-earmark-ppt text-orange-600',
        pptx: 'bi-file-earmark-ppt text-orange-600',
        sql: 'bi-database text-blue-500',
        db: 'bi-database text-blue-500',
        sqlite: 'bi-database text-blue-500',
        env: 'bi-file-earmark-lock text-slate-500',
        yml: 'bi-filetype-yml text-pink-500',
        yaml: 'bi-filetype-yml text-pink-500',
        xml: 'bi-filetype-xml text-orange-500',
        ini: 'bi-gear text-slate-500',
        conf: 'bi-gear text-slate-500',
        config: 'bi-gear text-slate-500',
        sh: 'bi-terminal text-emerald-600',
        bash: 'bi-terminal text-emerald-600',
        bat: 'bi-terminal text-slate-600',
        cmd: 'bi-terminal text-slate-600',
        ps1: 'bi-terminal text-blue-500',
        py: 'bi-filetype-py text-yellow-500',
        rb: 'bi-filetype-rb text-red-500',
        go: 'bi-filetype-go text-cyan-600',
        rs: 'bi-filetype-rs text-orange-600',
        java: 'bi-filetype-java text-red-600',
        c: 'bi-filetype-c text-blue-500',
        cpp: 'bi-filetype-cpp text-blue-500',
        h: 'bi-filetype-c text-blue-400',
        ai: 'bi-file-earmark-image text-orange-500',
        psd: 'bi-file-earmark-image text-blue-500',
        sketch: 'bi-file-earmark-image text-yellow-500',
        fig: 'bi-file-earmark-image text-purple-500',
        mp3: 'bi-file-earmark-music text-pink-500',
        wav: 'bi-file-earmark-music text-pink-500',
        mp4: 'bi-file-earmark-play text-purple-500',
        avi: 'bi-file-earmark-play text-purple-500',
        mov: 'bi-file-earmark-play text-purple-500',
        woff: 'bi-file-earmark-font text-slate-500',
        woff2: 'bi-file-earmark-font text-slate-500',
        ttf: 'bi-file-earmark-font text-slate-500',
        otf: 'bi-file-earmark-font text-slate-500',
    };
    return map[ext] || 'bi-file-earmark text-slate-400';
}

function nameClassForItem(item) {
    if (item.type === 'dir') return 'text-blue-600 dark:text-blue-400';
    return 'text-slate-800 dark:text-slate-200';
}

function typeLabelForItem(item) {
    if (item.type === 'dir') return 'folder';
    const ext = String(item.name || '').split('.').pop()?.toLowerCase() || '';
    return ext || 'file';
}

function formatBytes(bytes) {
    if (bytes === 0 || bytes == null) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function handleTableDragEnter(event) {
    if (event.dataTransfer?.types?.includes(INTERNAL_MOVE_MIME)) return;
    tableDragDepth.value++;
    tableDragActive.value = true;
}

function handleTableDragOver(event) {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
}

function handleTableDragLeave() {
    tableDragDepth.value--;
    if (tableDragDepth.value <= 0) {
        tableDragDepth.value = 0;
        tableDragActive.value = false;
    }
}

function handleTableDrop(event) {
    tableDragDepth.value = 0;
    tableDragActive.value = false;

    if (event.dataTransfer?.types?.includes(INTERNAL_MOVE_MIME)) return;

    const files = event.dataTransfer?.files;
    if (!files || files.length === 0) return;

    uploadForm.path = props.currentPath;
    uploadForm.upload = files[0];
    submitUpload();
}

function handleItemDragStart(item, event) {
    const paths = selectedPaths.value.includes(item.path) ? selectedPaths.value : [item.path];
    draggingItemPaths.value = paths;
    event.dataTransfer.setData(INTERNAL_MOVE_MIME, JSON.stringify(paths));
    event.dataTransfer.effectAllowed = 'move';
}

function handleItemDragEnd() {
    draggingItemPaths.value = [];
    moveDragTargetPath.value = null;
}

function handleFolderTargetDragOver(path, event) {
    event.preventDefault();
    event.stopPropagation();
    event.dataTransfer.dropEffect = 'move';
    moveDragTargetPath.value = path;
}

function handleFolderTargetDragLeave(path) {
    if (moveDragTargetPath.value === path) {
        moveDragTargetPath.value = null;
    }
}

function handleFolderTargetDrop(path, event) {
    event.preventDefault();
    event.stopPropagation();
    moveDragTargetPath.value = null;

    const rawData = event.dataTransfer?.getData(INTERNAL_MOVE_MIME);
    if (!rawData) return;

    let paths;
    try {
        paths = JSON.parse(rawData);
    } catch {
        return;
    }

    if (!Array.isArray(paths) || paths.length === 0) return;

    moveForm.item_path = paths[0];
    moveForm.item_paths = paths;
    moveForm.current_path = props.currentPath;
    moveForm.destination_path = path;
    submitMove();
}

function handleUploadDragOver(event) {
    event.preventDefault();
    uploadDragActive.value = true;
}

function handleUploadDragLeave() {
    uploadDragActive.value = false;
}

function handleUploadDrop(event) {
    uploadDragActive.value = false;
    const files = event.dataTransfer?.files;
    if (!files || files.length === 0) return;
    uploadForm.upload = files[0];
}

function handleUploadChange(event) {
    const files = event.target?.files;
    if (!files || files.length === 0) return;
    uploadForm.upload = files[0];
}

const uploadProgress = ref(0);
const uploadTaskComplete = ref(false);

const permissionPresets = [
    { value: '644', note: 'Owner RW, others R' },
    { value: '755', note: 'Owner All, others RX' },
    { value: '775', note: 'Group writable' },
    { value: '777', note: 'World writable' },
    { value: '600', note: 'Owner only' },
    { value: '640', note: 'Owner RW, group R' },
    { value: '700', note: 'Owner only (exec)' },
    { value: '0755', note: 'Leading zero' },
];

const permissionDigits = computed(() => {
    const digits = String(permissionForm.permissions || '').replace(/^0+/, '').slice(0, 3);
    return digits.length === 3 ? digits : '';
});

function sanitizePermissionInput(event) {
    const raw = String(event.target?.value || '');
    const sanitized = raw.replace(/[^0-7]/g, '').slice(0, 4);
    permissionForm.permissions = sanitized;
}

function setPermissionPreset(value) {
    permissionForm.permissions = value;
}

const permissionPreview = computed(() => {
    const digits = permissionDigits.value;
    if (digits.length !== 3) return null;

    const octToBin = (oct) => (+oct).toString(2).padStart(3, '0');
    const owner = octToBin(digits[0]);
    const group = octToBin(digits[1]);
    const world = octToBin(digits[2]);

    const toSym = (bin) => (bin[0] === '1' ? 'r' : '-') + (bin[1] === '1' ? 'w' : '-') + (bin[2] === '1' ? 'x' : '-');
    const symbolic = toSym(owner) + toSym(group) + toSym(world);
    const display = `0${digits}`;
    const dangerous = digits[2] === '7' || digits === '777';

    return {
        display,
        symbolic,
        owner: { read: owner[0] === '1', write: owner[1] === '1', execute: owner[2] === '1' },
        group: { read: group[0] === '1', write: group[1] === '1', execute: group[2] === '1' },
        world: { read: world[0] === '1', write: world[1] === '1', execute: world[2] === '1' },
        dangerous,
    };
});

const permissionPreviewClass = computed(() => {
    if (!permissionPreview.value) return 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-200';
    if (permissionPreview.value.dangerous) return 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200';
    return 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200';
});

const permissionMatrix = computed(() => {
    if (!permissionPreview.value) return [];
    return [
        { key: 'owner', label: 'Owner', ...permissionPreview.value.owner },
        { key: 'group', label: 'Group', ...permissionPreview.value.group },
        { key: 'world', label: 'World', ...permissionPreview.value.world },
    ];
});

const permissionCanSave = computed(() => permissionDigits.value.length === 3);

function toggleSort(field) {
    if (sortBy.value === field) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value = field;
        sortDir.value = 'asc';
    }
}

function getSortIcon(field) {
    if (sortBy.value !== field) return 'bi-arrow-down-up text-slate-400';
    return sortDir.value === 'asc' ? 'bi-arrow-up text-blue-500' : 'bi-arrow-down text-blue-500';
}

onMounted(() => {
    checkMobile();
    window.addEventListener('resize', checkMobile);
    window.addEventListener('click', closeContextMenu);
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeContextMenu();
            if (modalType.value) closeModal();
        }
    });
    if (props.openUploadTab) {
        openModal('upload');
    }
    if (props.openEditorModal) {
        modalType.value = 'editor';
    }
    if (props.selectedFile?.path) {
        activeItemPath.value = props.selectedFile.path;
    }
    window.addEventListener('beforeunload', handleEditorBeforeUnload);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', checkMobile);
    window.removeEventListener('click', closeContextMenu);
    window.removeEventListener('beforeunload', handleEditorBeforeUnload);
});
</script>

<template>
    <Head :title="`File Manager - ${website.name}`" />
    <div class="flex h-screen flex-col bg-slate-50 font-sans text-slate-800 dark:bg-slate-950 dark:text-slate-200">
        <!-- Top Bar -->
        <header class="flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-2 dark:border-slate-800 dark:bg-slate-900">
            <!-- Left: Logo with folder icon -->
            <div class="flex items-center gap-2">
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="flex items-center gap-2 rounded-lg px-2 py-1 transition-colors hover:bg-slate-100 dark:hover:bg-slate-800">
                    <img src="/sm_logo.png" alt="dPanel" class="h-6 w-auto" />
                    <div class="flex h-6 w-6 items-center justify-center rounded-md bg-blue-100 dark:bg-blue-900/30">
                        <i class="bi bi-folder-fill text-xs text-blue-600 dark:text-blue-400"></i>
                    </div>
                </Link>
            </div>

            <div class="h-5 w-px bg-slate-200 dark:bg-slate-700"></div>

            <!-- Center: Breadcrumb -->
            <div class="flex min-w-0 flex-1 items-center gap-2">
                <button
                    type="button"
                    class="rounded-md border border-slate-300 px-2 py-1.5 hover:bg-slate-100 lg:hidden dark:border-slate-700 dark:hover:bg-slate-800"
                    @click="sidebarOpen = !sidebarOpen"
                >
                    <i class="bi bi-list text-sm"></i>
                </button>

                <Link :href="panelRoute('websites.manage', { id: website.id })" class="flex items-center gap-1.5 text-sm font-semibold hover:underline">
                    {{ website.name }}
                </Link>
                <span class="text-slate-400">/</span>

                <div class="flex min-w-0 items-center gap-1 overflow-x-auto text-sm">
                    <button type="button" class="shrink-0 rounded px-1 py-0.5 hover:bg-slate-100 dark:hover:bg-slate-800" @click="goRoot">
                        <i class="bi bi-house-door text-xs"></i>
                    </button>
                    <template v-for="(segment, idx) in (currentPath || '').split('/').filter(Boolean)" :key="idx">
                        <i class="bi bi-chevron-right text-[10px] text-slate-400"></i>
                        <button
                            type="button"
                            class="shrink-0 truncate rounded px-1 py-0.5 hover:bg-slate-100 dark:hover:bg-slate-800"
                            @click="openPath((currentPath || '').split('/').filter(Boolean).slice(0, idx + 1).join('/'))"
                        >
                            {{ segment }}
                        </button>
                    </template>
                </div>
            </div>

            <!-- Right: Actions & Exit -->
            <div class="flex items-center gap-2">
                <!-- Search -->
                <div class="hidden items-center md:flex">
                    <div class="relative">
                        <i class="bi bi-search pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                        <input
                            v-model="searchQuery"
                            type="text"
                            class="w-40 rounded-lg border border-slate-200 bg-slate-50 py-1.5 pl-7 pr-2 text-xs outline-none focus:border-blue-400 focus:bg-white focus:ring-1 focus:ring-blue-400/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                            placeholder="Search files..."
                        >
                    </div>
                </div>

                <!-- View toggle -->
                <div class="hidden items-center gap-0.5 rounded-lg border border-slate-200 bg-slate-50 p-0.5 sm:flex dark:border-slate-700 dark:bg-slate-800">
                    <button
                        type="button"
                        class="rounded-md px-2 py-1 text-xs transition-all"
                        :class="viewMode === 'table' ? 'bg-white text-blue-600 shadow-sm dark:bg-slate-700 dark:text-blue-400' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'"
                        @click="viewMode = 'table'"
                    >
                        <i class="bi bi-list"></i>
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-2 py-1 text-xs transition-all"
                        :class="viewMode === 'grid' ? 'bg-white text-blue-600 shadow-sm dark:bg-slate-700 dark:text-blue-400' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'"
                        @click="viewMode = 'grid'"
                    >
                        <i class="bi bi-grid-3x3-gap"></i>
                    </button>
                </div>

                <!-- Right sidebar toggle -->
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 px-2 py-1.5 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                    @click="rightSidebarOpen = !rightSidebarOpen"
                >
                    <i class="bi bi-layout-sidebar-right text-sm"></i>
                </button>

                <!-- Exit / Back button -->
                <Link
                    :href="panelRoute('websites.manage', { id: website.id })"
                    class="flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600 transition-all hover:bg-red-100 hover:border-red-300 dark:border-red-900/50 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/30"
                >
                    <i class="bi bi-box-arrow-left text-sm"></i>
                    <span class="hidden sm:inline">Exit</span>
                </Link>
            </div>
        </header>

        <main class="flex flex-1 overflow-hidden">
            <!-- Left Sidebar: Directory Tree -->
            <Transition name="slide-left">
                <aside
                    v-show="sidebarOpen"
                    class="flex w-60 shrink-0 flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"
                    :class="isMobile ? 'fixed inset-y-0 left-0 z-40 w-72 shadow-xl' : ''"
                >
                    <button
                        v-if="isMobile"
                        type="button"
                        class="absolute right-2 top-2 rounded p-1 text-slate-400 hover:bg-slate-100 lg:hidden"
                        @click="sidebarOpen = false"
                    >
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>

                    <div class="border-b border-slate-200 px-3 py-2 dark:border-slate-800">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Folders</p>
                    </div>

                    <div class="flex min-h-0 flex-1 flex-col p-2">
                        <div class="mb-2 flex items-center gap-1">
                            <input v-model="pathInput" type="text" class="flex-1 rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5 text-xs outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800" placeholder="Go to path..." />
                            <button type="button" class="shrink-0 rounded-md bg-blue-600 px-2 py-1.5 text-[11px] font-medium text-white hover:bg-blue-700" :disabled="isBusy" @click="goFromPathInput">
                                <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>

                        <div class="min-h-0 flex-1 space-y-0.5 overflow-y-auto rounded-lg text-xs">
                            <div v-for="node in treeRows" :key="`tree-${node.path}`">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-left transition-all hover:bg-slate-100 dark:hover:bg-slate-800"
                                    :class="[
                                        isTreeNodeActive(node.path) ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-900/20 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400',
                                        moveDragTargetPath === node.path ? 'bg-emerald-50 ring-1 ring-emerald-400 dark:bg-emerald-900/20' : '',
                                    ]"
                                    :title="node.path"
                                    :style="{ paddingLeft: `${8 + (node.level * 12)}px` }"
                                    @click="openPath(node.path)"
                                    @dragover.prevent.stop="handleFolderTargetDragOver(node.path, $event)"
                                    @dragleave="handleFolderTargetDragLeave(node.path)"
                                    @drop.prevent.stop="handleFolderTargetDrop(node.path, $event)"
                                >
                                    <span class="inline-flex h-3.5 w-3.5 items-center justify-center" @click.stop="node.hasChildren ? toggleTreeNode(node.path) : null">
                                        <i v-if="node.hasChildren" class="bi text-[8px]" :class="node.expanded ? 'bi-caret-down-fill' : 'bi-caret-right-fill'"></i>
                                    </span>
                                    <i class="bi bi-folder-fill text-xs text-amber-500"></i>
                                    <span class="block truncate">{{ node.name }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-200 px-3 py-2 dark:border-slate-800">
                        <button type="button" class="w-full rounded-md border border-slate-200 px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800" @click="goRoot">
                            <i class="bi bi-house-door mr-1"></i>Go to Root
                        </button>
                    </div>
                </aside>
            </Transition>

            <!-- Mobile overlay -->
            <div
                v-if="isMobile && sidebarOpen"
                class="fixed inset-0 z-30 bg-black/40"
                @click="sidebarOpen = false"
            ></div>

            <!-- Main Content: Files -->
            <section
                class="flex min-w-0 flex-1 flex-col overflow-hidden"
                @dragenter.prevent="handleTableDragEnter"
                @dragover.prevent="handleTableDragOver"
                @dragleave.prevent="handleTableDragLeave"
                @drop.prevent="handleTableDrop"
            >
                <!-- Drag overlay -->
                <div v-if="tableDragActive" class="pointer-events-none absolute inset-0 z-20 flex items-center justify-center rounded-xl border-2 border-dashed border-blue-500 bg-blue-500/10">
                    <div class="rounded-xl bg-white/95 px-6 py-4 text-center text-sm font-semibold text-blue-700 shadow-lg dark:bg-slate-900/95 dark:text-blue-300">
                        <i class="bi bi-cloud-arrow-up text-2xl mb-2 block text-blue-400"></i>
                        Drop files to upload to
                        <span class="font-mono text-blue-600 dark:text-blue-400">{{ resolveDisplayPath(currentPath) || '/' }}</span>
                    </div>
                </div>

                <!-- Selection bar -->
                <div v-if="selectedCount > 0" class="flex items-center gap-2 border-b border-blue-200 bg-blue-50 px-4 py-2 text-sm dark:border-blue-800 dark:bg-blue-900/20">
                    <i class="bi bi-check2-circle text-blue-500"></i>
                    <span class="font-medium text-blue-700 dark:text-blue-300">{{ selectedCount }} selected</span>
                    <button type="button" class="ml-1 text-xs text-blue-600 hover:underline dark:text-blue-400" @click="selectedPaths = []">Clear</button>
                    <div class="ml-auto flex gap-1">
                        <button type="button" class="rounded-md px-2.5 py-1 text-xs font-medium hover:bg-blue-100 dark:hover:bg-blue-900/30" @click="openModal('move')">
                            <i class="bi bi-arrows-move mr-1"></i>Move
                        </button>
                        <button type="button" class="rounded-md px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20" @click="deleteSelected">
                            <i class="bi bi-trash mr-1"></i>Delete
                        </button>
                    </div>
                </div>

                <!-- Mobile search -->
                <div class="border-b border-slate-200 px-3 py-2 md:hidden dark:border-slate-800">
                    <div class="relative">
                        <i class="bi bi-search pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                        <input
                            v-model="searchQuery"
                            type="text"
                            class="w-full rounded-lg border border-slate-200 bg-slate-50 py-2 pl-8 pr-3 text-sm outline-none focus:border-blue-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                            placeholder="Search files..."
                        >
                    </div>
                </div>

                <!-- File listing -->
                <div class="flex-1 overflow-auto p-3">
                    <!-- Table View -->
                    <div v-if="viewMode === 'table'" class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <table class="min-w-full text-left text-sm">
                            <thead class="sticky top-0 z-10 border-b border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-800">
                                <tr>
                                    <th class="w-10 px-3 py-3">
                                        <input type="checkbox" :checked="items.length > 0 && items.every((item) => selectedPaths.includes(item.path))" @change="toggleSelectAll($event.target.checked)" />
                                    </th>
                                    <th class="cursor-pointer px-3 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 select-none" @click="toggleSort('name')">
                                        <span class="flex items-center gap-1">Name <i :class="['bi text-[10px]', getSortIcon('name')]"></i></span>
                                    </th>
                                    <th class="hidden cursor-pointer px-3 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 select-none sm:table-cell" @click="toggleSort('type')">
                                        <span class="flex items-center gap-1">Type <i :class="['bi text-[10px]', getSortIcon('type')]"></i></span>
                                    </th>
                                    <th class="hidden cursor-pointer px-3 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 select-none md:table-cell" @click="toggleSort('size')">
                                        <span class="flex items-center gap-1">Size <i :class="['bi text-[10px]', getSortIcon('size')]"></i></span>
                                    </th>
                                    <th class="hidden px-3 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 lg:table-cell">Perm</th>
                                    <th class="hidden cursor-pointer px-3 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 select-none xl:table-cell" @click="toggleSort('modified')">
                                        <span class="flex items-center gap-1">Modified <i :class="['bi text-[10px]', getSortIcon('modified')]"></i></span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="item in filteredItems"
                                    :key="item.path"
                                    data-fm-row
                                    class="group cursor-pointer border-b border-slate-100 transition-all duration-100 hover:bg-blue-50/50 dark:border-slate-800 dark:hover:bg-slate-800/40"
                                    :class="[
                                        activeItemPath === item.path ? 'bg-blue-50 ring-1 ring-inset ring-blue-400/40 dark:bg-blue-900/20 dark:ring-blue-500/30' : '',
                                        selectedPaths.includes(item.path) && activeItemPath !== item.path ? 'bg-blue-50/50 dark:bg-blue-900/10' : '',
                                        moveDragTargetPath === item.path ? 'bg-emerald-50 dark:bg-emerald-900/20' : '',
                                    ]"
                                    draggable="true"
                                    @click="handleItemClick(item, $event)"
                                    @dblclick="item.type === 'dir' ? openPath(item.path) : openFileInEditor(item.path, { useModal: !openEditorPage })"
                                    @contextmenu.prevent="openContextMenu(item, $event)"
                                    @dragstart="handleItemDragStart(item, $event)"
                                    @dragend="handleItemDragEnd"
                                    @dragover="item.type === 'dir' ? handleFolderTargetDragOver(item.path, $event) : null"
                                    @dragleave="item.type === 'dir' ? handleFolderTargetDragLeave(item.path) : null"
                                    @drop="item.type === 'dir' ? handleFolderTargetDrop(item.path, $event) : null"
                                >
                                    <td class="px-3 py-2.5" @click.stop>
                                        <input type="checkbox" :checked="selectedPaths.includes(item.path)" @change="toggleSelectPath(item.path, $event.target.checked, $event)" />
                                    </td>
                                    <td class="px-3 py-2.5 font-medium">
                                        <div class="flex items-center gap-2.5">
                                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-colors" :class="item.type === 'dir' ? 'bg-amber-100 text-amber-600 dark:bg-amber-900/30' : 'bg-slate-100 text-slate-500 dark:bg-slate-800'">
                                                <i class="bi text-sm" :class="iconClassForItem(item)"></i>
                                            </div>
                                            <span class="truncate" :class="nameClassForItem(item)">
                                                {{ item.name }}
                                            </span>
                                            <span v-if="unsavedFilePath === item.path" class="text-[10px] font-semibold text-amber-600">*</span>
                                        </div>
                                        <div class="mt-0.5 text-[11px] text-slate-500 sm:hidden">
                                            {{ typeLabelForItem(item) }} &middot; {{ formatBytes(item.size) }}
                                        </div>
                                    </td>
                                    <td class="hidden px-3 py-2.5 sm:table-cell">
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                                            {{ typeLabelForItem(item) }}
                                        </span>
                                    </td>
                                    <td class="hidden px-3 py-2.5 text-slate-600 md:table-cell dark:text-slate-400">{{ formatBytes(item.size) }}</td>
                                    <td class="hidden px-3 py-2.5 font-mono text-xs text-slate-500 lg:table-cell">{{ item.permissions }}</td>
                                    <td class="hidden px-3 py-2.5 text-xs text-slate-500 xl:table-cell">{{ item.modified_at ? new Date(item.modified_at).toLocaleString() : '-' }}</td>
                                </tr>
                                <tr v-if="filteredItems.length === 0">
                                    <td colspan="6" class="px-4 py-16 text-center">
                                        <i class="bi bi-inbox text-4xl text-slate-300 dark:text-slate-600"></i>
                                        <p class="mt-3 text-sm text-slate-500">{{ searchQuery ? 'No files match your search' : 'No files in this directory' }}</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Grid View -->
                    <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                        <button
                            v-for="item in filteredItems"
                            :key="item.path"
                            type="button"
                            class="group flex flex-col items-center rounded-xl border border-slate-200 bg-white p-4 text-center shadow-sm transition-all hover:border-blue-300 hover:shadow-md dark:border-slate-800 dark:bg-slate-900 dark:hover:border-blue-600"
                            :class="[
                                activeItemPath === item.path ? 'border-blue-400 bg-blue-50 ring-2 ring-blue-400/30 dark:bg-blue-900/20 dark:ring-blue-500/30' : '',
                                selectedPaths.includes(item.path) && activeItemPath !== item.path ? 'border-blue-300 bg-blue-50/50 dark:bg-blue-900/10' : '',
                            ]"
                            draggable="true"
                            @click="handleItemClick(item, $event)"
                            @dblclick="item.type === 'dir' ? openPath(item.path) : openFileInEditor(item.path, { useModal: !openEditorPage })"
                            @contextmenu.prevent="openContextMenu(item, $event)"
                            @dragstart="handleItemDragStart(item, $event)"
                            @dragend="handleItemDragEnd"
                            @dragover="item.type === 'dir' ? handleFolderTargetDragOver(item.path, $event) : null"
                            @dragleave="item.type === 'dir' ? handleFolderTargetDragLeave(item.path) : null"
                            @drop="item.type === 'dir' ? handleFolderTargetDrop(item.path, $event) : null"
                        >
                            <div class="flex h-14 w-14 items-center justify-center rounded-xl transition-colors" :class="item.type === 'dir' ? 'bg-amber-100 text-amber-600 dark:bg-amber-900/30' : 'bg-slate-100 text-slate-500 dark:bg-slate-800'">
                                <i class="bi text-2xl" :class="iconClassForItem(item)"></i>
                            </div>
                            <span class="mt-2.5 w-full truncate text-xs font-medium" :class="nameClassForItem(item)">{{ item.name }}</span>
                            <span class="mt-1 text-[10px] text-slate-400">{{ formatBytes(item.size) }}</span>
                        </button>
                    </div>
                </div>

                <!-- Status bar -->
                <div class="flex items-center justify-between border-t border-slate-200 bg-white px-4 py-1.5 text-[11px] text-slate-500 dark:border-slate-800 dark:bg-slate-900">
                    <span>{{ filteredItems.length }} items</span>
                    <span v-if="selectedCount" class="font-medium text-blue-600 dark:text-blue-400">{{ selectedCount }} selected</span>
                </div>
            </section>

            <!-- Right Sidebar: Quick Actions -->
            <Transition name="slide-right">
                <aside
                    v-show="rightSidebarOpen"
                    class="flex w-44 shrink-0 flex-col overflow-hidden border-l border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"
                >
                    <div class="shrink-0 border-b border-slate-200 px-2.5 py-1.5 dark:border-slate-800">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Quick Actions</p>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2">
                        <div class="space-y-1">
                            <!-- Upload -->
                            <button type="button" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[11px] font-medium transition-all hover:bg-blue-50 hover:text-blue-700 dark:hover:bg-blue-900/20 dark:hover:text-blue-400" :disabled="isBusy" @click="openModal('upload')">
                                <i class="bi bi-cloud-arrow-up text-xs text-blue-500"></i>
                                Upload
                            </button>

                            <!-- New File -->
                            <button type="button" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[11px] font-medium transition-all hover:bg-emerald-50 hover:text-emerald-700 dark:hover:bg-emerald-900/20 dark:hover:text-emerald-400" :disabled="isBusy" @click="openModal('create-file')">
                                <i class="bi bi-file-earmark-plus text-xs text-emerald-500"></i>
                                New File
                            </button>

                            <!-- New Folder -->
                            <button type="button" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[11px] font-medium transition-all hover:bg-amber-50 hover:text-amber-700 dark:hover:bg-amber-900/20 dark:hover:text-amber-400" :disabled="isBusy" @click="openModal('create-folder')">
                                <i class="bi bi-folder-plus text-xs text-amber-500"></i>
                                New Folder
                            </button>

                            <div class="border-t border-slate-100 dark:border-slate-800"></div>

                            <!-- Permissions -->
                            <button type="button" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[11px] font-medium transition-all hover:bg-purple-50 hover:text-purple-700 dark:hover:bg-purple-900/20 dark:hover:text-purple-400" :disabled="!singleSelectedItem || isBusy" @click="openModal('permissions')">
                                <i class="bi bi-shield-lock text-xs text-purple-500"></i>
                                Permissions
                            </button>

                            <!-- Download -->
                            <button type="button" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[11px] font-medium transition-all hover:bg-slate-100 dark:hover:bg-slate-800" :disabled="!singleSelectedItem || singleSelectedItem.type !== 'file'" @click="downloadSelected">
                                <i class="bi bi-download text-xs text-slate-500"></i>
                                Download
                            </button>

                            <div class="border-t border-slate-100 dark:border-slate-800"></div>

                            <!-- Zip -->
                            <button type="button" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[11px] font-medium transition-all hover:bg-amber-50 hover:text-amber-700 dark:hover:bg-amber-900/20 dark:hover:text-amber-400" :disabled="(!singleSelectedItem && !selectedCount) || isBusy" @click="openModal('zip')">
                                <i class="bi bi-file-earmark-zip text-xs text-amber-500"></i>
                                Zip
                            </button>

                            <!-- Unzip -->
                            <button type="button" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[11px] font-medium transition-all hover:bg-green-50 hover:text-green-700 dark:hover:bg-green-900/20 dark:hover:text-green-400" :disabled="!isZipSelected || isBusy" @click="openModal('unzip')">
                                <i class="bi bi-file-earmark-arrow-up text-xs text-green-500"></i>
                                Extract
                            </button>

                            <div class="border-t border-slate-100 dark:border-slate-800"></div>

                            <!-- Up One Level -->
                            <button type="button" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[11px] font-medium transition-all hover:bg-slate-100 disabled:opacity-40 dark:hover:bg-slate-800" :disabled="isBusy || !currentPath" @click="goParent">
                                <i class="bi bi-arrow-up text-xs text-slate-500"></i>
                                Up Level
                            </button>

                            <!-- Toggle Hidden -->
                            <button type="button" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[11px] font-medium transition-all hover:bg-slate-100 dark:hover:bg-slate-800" :disabled="isBusy" @click="toggleHidden">
                                <i :class="['bi text-xs', hiddenEnabled ? 'bi-eye-slash text-slate-500' : 'bi-eye text-slate-500']"></i>
                                {{ hiddenEnabled ? 'Hide Hidden' : 'Show Hidden' }}
                            </button>

                            <!-- Delete -->
                            <button type="button" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[11px] font-medium text-red-600 transition-all hover:bg-red-50 disabled:opacity-40 dark:text-red-400 dark:hover:bg-red-900/20" :disabled="(!singleSelectedItem && !selectedCount) || isBusy" @click="deleteSelected">
                                <i class="bi bi-trash text-xs"></i>
                                Delete
                            </button>
                        </div>
                    </div>

                    <!-- Selected item info -->
                    <div v-if="singleSelectedItem" class="shrink-0 border-t border-slate-200 p-2 dark:border-slate-800">
                        <p class="text-[9px] font-semibold uppercase tracking-wider text-slate-400">Selected</p>
                        <div class="mt-1 flex items-center gap-1.5">
                            <i class="bi text-xs" :class="iconClassForItem(singleSelectedItem)"></i>
                            <div class="min-w-0">
                                <p class="truncate text-[11px] font-medium">{{ singleSelectedItem.name }}</p>
                                <p class="text-[9px] text-slate-500">{{ formatBytes(singleSelectedItem.size) }}</p>
                            </div>
                        </div>
                    </div>
                </aside>
            </Transition>
        </main>

        <!-- Context Menu -->
        <div
            v-if="contextMenu.visible"
            data-fm-context-menu
            class="fixed z-[60] w-56 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900"
            :style="{ left: `${contextMenu.x}px`, top: `${contextMenu.y}px` }"
            @click.stop
        >
            <div class="border-b border-slate-200 px-3 py-2 dark:border-slate-700">
                <p class="truncate text-xs font-semibold text-slate-800 dark:text-slate-100">{{ contextMenu.itemName }}</p>
                <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ contextMenu.itemType }}</p>
            </div>

            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('open')">
                <i class="bi bi-box-arrow-in-right text-xs text-slate-400"></i>
                {{ contextItem?.type === 'dir' ? 'Open Folder' : (isEditableFile(contextItem?.name) ? 'Edit File' : 'Open File') }}
            </button>
            <button v-if="contextItem?.type === 'file' && isEditableFile(contextItem?.name)" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('open-tab')">
                <i class="bi bi-window-stack text-xs text-slate-400"></i>
                Edit in New Tab
            </button>
            <button v-if="contextItem?.type === 'file'" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('download')">
                <i class="bi bi-download text-xs text-slate-400"></i>
                Download
            </button>

            <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('rename')">
                <i class="bi bi-input-cursor-text text-xs text-slate-400"></i>
                Rename
            </button>
            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('permissions')">
                <i class="bi bi-shield-lock text-xs text-slate-400"></i>
                Permissions
            </button>
            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('move')">
                <i class="bi bi-arrows-move text-xs text-slate-400"></i>
                Move
            </button>
            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('zip')">
                <i class="bi bi-file-earmark-zip text-xs text-slate-400"></i>
                Create Zip
            </button>
            <button v-if="contextZip" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('unzip')">
                <i class="bi bi-file-earmark-arrow-up text-xs text-slate-400"></i>
                Extract Zip
            </button>

            <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20" @click="triggerContextAction('delete')">
                <i class="bi bi-trash text-xs"></i>
                Delete
            </button>
        </div>

        <!-- Modals -->
        <div v-if="modalType" class="fixed inset-0 z-50 flex bg-black/40 p-2 sm:p-4" :class="modalType === 'editor' ? 'items-stretch justify-stretch' : 'items-center justify-center'">
            <div
                class="w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-4 shadow-xl sm:p-5 dark:border-slate-700 dark:bg-slate-900"
                :class="modalType === 'editor'
                    ? 'h-full max-w-none'
                    : modalType === 'permissions'
                        ? 'max-w-3xl max-h-[90vh]'
                        : 'max-w-2xl max-h-[90vh]'"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-base font-semibold">
                        {{
                            modalType === 'create-folder' ? 'Create Folder'
                                : modalType === 'create-file' ? 'Create File'
                                : modalType === 'rename' ? 'Rename Item'
                                : modalType === 'permissions' ? 'Change Permissions'
                                : modalType === 'move' ? 'Move Item'
                                : modalType === 'zip' ? 'Create Zip'
                                : modalType === 'unzip' ? 'Extract Zip'
                                : modalType === 'editor' ? 'File Editor'
                                : 'Upload File'
                        }}
                    </h3>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="closeModal">
                        <i class="bi bi-x-lg mr-1"></i>Close
                    </button>
                </div>

                <!-- Create Folder -->
                <div v-if="modalType === 'create-folder'" class="space-y-3">
                    <input v-model="createFolderForm.name" type="text" placeholder="folder-name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <button type="button" :disabled="createFolderForm.processing || !createFolderForm.name" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitCreateFolder">
                        {{ createFolderForm.processing ? 'Creating...' : 'Create Folder' }}
                    </button>
                </div>

                <!-- Create File -->
                <div v-else-if="modalType === 'create-file'" class="space-y-3">
                    <input v-model="createFileForm.name" type="text" placeholder="file-name.ext" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <button type="button" :disabled="createFileForm.processing || !createFileForm.name" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitCreateFile">
                        {{ createFileForm.processing ? 'Creating...' : 'Create File' }}
                    </button>
                </div>

                <!-- Rename -->
                <div v-else-if="modalType === 'rename'" class="space-y-3">
                    <input v-model="renameForm.new_name" type="text" placeholder="new name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <button type="button" :disabled="renameForm.processing || !renameForm.new_name" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitRename">
                        {{ renameForm.processing ? 'Renaming...' : 'Rename' }}
                    </button>
                </div>

                <!-- Permissions -->
                <div v-else-if="modalType === 'permissions'" class="space-y-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/60">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Presets</p>
                            <span v-if="permissionPreview" class="rounded-full px-2 py-0.5 text-[11px]" :class="permissionPreview.dangerous ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'">
                                {{ permissionPreview.dangerous ? 'High risk' : 'Safe' }}
                            </span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <button
                                v-for="preset in permissionPresets"
                                :key="preset.value"
                                type="button"
                                class="rounded-md border px-3 py-2 text-left text-xs transition hover:shadow-sm"
                                :class="permissionDigits === preset.value
                                    ? 'border-blue-400 bg-blue-50 text-blue-700 dark:border-blue-600 dark:bg-blue-900/20 dark:text-blue-300'
                                    : preset.value === '777'
                                        ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300'
                                        : 'border-slate-300 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200'"
                                @click="setPermissionPreset(preset.value)"
                            >
                                <span class="block font-mono text-sm font-semibold">{{ preset.value }}</span>
                                <span class="block truncate text-[11px] opacity-80">{{ preset.note }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Custom Permission</label>
                        <input
                            v-model="permissionForm.permissions"
                            type="text"
                            inputmode="numeric"
                            maxlength="4"
                            placeholder="644"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm tracking-wider dark:border-slate-700 dark:bg-slate-800"
                            @input="sanitizePermissionInput"
                        />
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">Use 3 or 4 digits like `644`, `755`, or `0777`.</p>
                    </div>

                    <div class="rounded-lg border p-3 text-sm" :class="permissionPreviewClass">
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <p class="text-xs uppercase tracking-wide opacity-80">Live Preview</p>
                                <p class="font-mono text-lg font-semibold">{{ permissionPreview?.display || '---' }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs uppercase tracking-wide opacity-80">Symbolic</p>
                                <p class="font-mono text-base font-semibold">{{ permissionPreview?.symbolic || '---------'.slice(0, 9) }}</p>
                            </div>
                        </div>

                        <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                            <div v-for="cell in permissionMatrix" :key="cell.key" class="rounded-md border border-white/20 bg-white/40 px-2 py-2 dark:bg-black/10">
                                <p class="mb-1 font-semibold uppercase tracking-wide">{{ cell.label }}</p>
                                <div class="flex items-center justify-center gap-1 font-mono text-[11px]">
                                    <span :class="cell.read ? 'text-emerald-700 dark:text-emerald-300' : 'opacity-40'">R</span>
                                    <span :class="cell.write ? 'text-red-700 dark:text-red-300' : 'opacity-40'">W</span>
                                    <span :class="cell.execute ? 'text-blue-700 dark:text-blue-300' : 'opacity-40'">X</span>
                                </div>
                            </div>
                        </div>

                        <p v-if="permissionPreview?.dangerous" class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-950/20 dark:text-red-300">
                            Warning: this mode is world-writable or otherwise high-risk. Use carefully.
                        </p>
                    </div>

                    <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                        <input v-model="permissionForm.recursive" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-800" />
                        Apply recursively to subdirectories and files
                    </label>
                    <button type="button" :disabled="permissionForm.processing || !permissionCanSave" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitPermissions">
                        {{ permissionForm.processing ? 'Saving...' : (permissionForm.recursive ? 'Save Recursively' : 'Save Permission') }}
                    </button>
                </div>

                <!-- Move -->
                <div v-else-if="modalType === 'move'" class="space-y-3">
                    <p class="text-xs text-slate-500">Selected items: {{ selectedCount || (singleSelectedItem ? 1 : 0) }}</p>
                    <input v-model="moveForm.destination_path" type="text" placeholder="destination folder path (empty = root)" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p class="text-xs text-slate-500">You can also drag selected rows and drop onto sidebar folders.</p>
                    <button type="button" :disabled="moveForm.processing || (!selectedCount && !singleSelectedItem)" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitMove">
                        {{ moveForm.processing ? 'Moving...' : 'Move' }}
                    </button>
                </div>

                <!-- Zip -->
                <div v-else-if="modalType === 'zip'" class="space-y-3">
                    <p class="text-xs text-slate-500">Selected items: {{ selectedCount || (singleSelectedItem ? 1 : 0) }}</p>
                    <input v-model="zipForm.zip_name" type="text" placeholder="archive-name.zip" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <button type="button" :disabled="zipForm.processing || (!selectedCount && !singleSelectedItem)" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitZip">
                        {{ zipForm.processing ? 'Creating Zip...' : 'Create Zip' }}
                    </button>
                </div>

                <!-- Unzip -->
                <div v-else-if="modalType === 'unzip'" class="space-y-3">
                    <p class="text-xs text-slate-500 break-all">Zip file: {{ unzipForm.zip_path }}</p>
                    <button type="button" :disabled="unzipForm.processing || !unzipForm.zip_path" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitUnzip">
                        {{ unzipForm.processing ? 'Extracting...' : 'Extract Zip' }}
                    </button>
                </div>

                <!-- Upload -->
                <div v-else-if="modalType === 'upload'" class="space-y-3">
                    <div
                        class="rounded-lg border-2 border-dashed p-6 text-center transition"
                        :class="uploadDragActive ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-slate-300 dark:border-slate-700'"
                        @dragover.prevent="handleUploadDragOver"
                        @dragenter.prevent="handleUploadDragOver"
                        @dragleave.prevent="handleUploadDragLeave"
                        @drop.prevent="handleUploadDrop"
                    >
                        <i class="bi bi-cloud-arrow-up text-3xl text-slate-400"></i>
                        <p class="mt-2 text-sm font-medium">Drag and drop file here</p>
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
                            <span>{{ uploadTaskComplete ? 'Complete' : 'Uploading...' }}</span>
                            <span>{{ uploadProgress }}%</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                            <div class="h-full bg-blue-500 transition-all" :style="{ width: `${uploadProgress}%` }"></div>
                        </div>
                    </div>
                </div>

                <!-- Editor -->
                <div v-else-if="modalType === 'editor'" class="flex min-h-0 flex-1 flex-col">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="truncate text-xs text-slate-500">{{ saveForm.file_path }}</p>
                        <div class="flex gap-2">
                            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="closeModal">Close</button>
                            <button type="button" class="rounded-md bg-blue-600 px-4 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-60" :disabled="saveForm.processing || !hasUnsavedChanges" @click="submitSaveFile">
                                {{ saveForm.processing ? 'Saving...' : 'Save' }}
                            </button>
                        </div>
                    </div>
                    <textarea
                        v-model="saveForm.content"
                        class="min-h-0 flex-1 resize-none rounded-lg border border-slate-300 bg-white p-3 font-mono text-sm leading-relaxed dark:border-slate-700 dark:bg-slate-950"
                        spellcheck="false"
                    ></textarea>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.slide-left-enter-active,
.slide-left-leave-active {
    transition: transform 0.25s ease, opacity 0.25s ease;
}

.slide-left-enter-from,
.slide-left-leave-to {
    transform: translateX(-100%);
    opacity: 0;
}

.slide-right-enter-active,
.slide-right-leave-active {
    transition: transform 0.25s ease, opacity 0.25s ease;
}

.slide-right-enter-from,
.slide-right-leave-to {
    transform: translateX(100%);
    opacity: 0;
}
</style>
