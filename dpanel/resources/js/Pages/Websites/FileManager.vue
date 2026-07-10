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
const modalType = ref('');
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
    { immediate: true },
);

onMounted(() => {
    if (props.openUploadTab) {
        modalType.value = 'upload';
    }

    if (props.openEditorModal && props.selectedFile && !props.openEditorPage) {
        modalType.value = 'editor';
    }

    window.addEventListener('click', closeContextMenu);
    window.addEventListener('scroll', closeContextMenu, true);
    window.addEventListener('keydown', handleGlobalKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('click', closeContextMenu);
    window.removeEventListener('scroll', closeContextMenu, true);
    window.removeEventListener('keydown', handleGlobalKeydown);
});

const openTreeAncestors = (path) => {
    const normalized = normalizePathValue(path);
    if (normalized === '') return;

    const next = { ...treeOpenState.value };
    const segments = normalized.split('/').filter(Boolean);
    let current = '';
    segments.forEach((segment) => {
        current = current ? `${current}/${segment}` : segment;
        next[current] = true;
    });
    treeOpenState.value = next;
};

const ensureTreeState = () => {
    treeOpenState.value = {};
    openTreeAncestors(props.currentPath);
};

watch(
    () => props.directoryTree,
    () => {
        ensureTreeState();
    },
    { immediate: true, deep: true },
);

watch(
    () => props.currentPath,
    (value) => {
        openTreeAncestors(value);
    },
    { immediate: true },
);

const treeRows = computed(() => {
    const rows = [];
    const walk = (nodes, level = 0) => {
        if (!Array.isArray(nodes)) return;

        nodes.forEach((node) => {
            const nodePath = normalizePathValue(node?.path);
            if (nodePath === '') return;

            const children = Array.isArray(node?.children) ? node.children : [];
            const hasChildren = Boolean(node?.has_children) || children.length > 0;
            const expanded = Boolean(treeOpenState.value[nodePath]);

            rows.push({
                name: String(node?.name || nodePath.split('/').pop() || '/'),
                path: nodePath,
                level,
                hasChildren,
                expanded,
            });

            if (hasChildren && expanded) {
                walk(children, level + 1);
            }
        });
    };

    walk(props.directoryTree, 0);
    return rows;
});

const isTreeNodeActive = (nodePath) => {
    const current = normalizePathValue(props.currentPath);
    const candidate = normalizePathValue(nodePath);
    if (candidate === '') return current === '';
    return current === candidate || current.startsWith(`${candidate}/`);
};

const toggleTreeNode = (nodePath) => {
    const normalized = normalizePathValue(nodePath);
    if (!normalized) return;

    treeOpenState.value = {
        ...treeOpenState.value,
        [normalized]: !Boolean(treeOpenState.value[normalized]),
    };
};

const imageExtensions = new Set(['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'ico']);
const archiveExtensions = new Set(['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz']);
const mediaExtensions = new Set(['mp4', 'm4v', 'mkv', 'webm', 'mov', 'avi', 'mp3', 'wav', 'ogg', 'flac']);
const codeExtensions = new Set(['php', 'js', 'ts', 'jsx', 'tsx', 'vue', 'html', 'htm', 'css', 'scss', 'less', 'json', 'xml', 'yml', 'yaml', 'md', 'sql', 'sh', 'py', 'rb', 'go', 'java', 'c', 'cpp', 'h', 'hpp', 'rs']);
const docExtensions = new Set(['txt', 'log', 'ini', 'env', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv']);
const permissionPresets = [
    { value: '0644', label: '0644', note: 'Files, safe default' },
    { value: '0600', label: '0600', note: 'Private files' },
    { value: '0640', label: '0640', note: 'Owner + group read' },
    { value: '0755', label: '0755', note: 'Public folders' },
    { value: '0750', label: '0750', note: 'Private folders' },
    { value: '0700', label: '0700', note: 'Owner only' },
    { value: '0775', label: '0775', note: 'Group writable' },
    { value: '0777', label: '0777', note: 'Dangerous' },
];

const itemExtension = (item) => {
    const name = String(item?.name || '').toLowerCase();
    const lastDot = name.lastIndexOf('.');
    if (lastDot <= 0 || lastDot === name.length - 1) return '';
    return name.slice(lastDot + 1);
};

const itemCategory = (item) => {
    if (item?.type === 'dir') return 'dir';

    const extension = itemExtension(item);
    if (imageExtensions.has(extension)) return 'image';
    if (archiveExtensions.has(extension)) return 'archive';
    if (mediaExtensions.has(extension)) return 'media';
    if (codeExtensions.has(extension)) return 'code';
    if (docExtensions.has(extension)) return 'doc';
    return 'file';
};

const normalizePermissionDigits = (value) => {
    const digits = String(value || '').replace(/[^0-7]/g, '').slice(0, 4);
    return digits;
};

const permissionDigits = computed(() => normalizePermissionDigits(permissionForm.permissions));
const permissionPreview = computed(() => {
    const digits = permissionDigits.value;
    if (!/^[0-7]{3,4}$/.test(digits)) {
        return null;
    }

    const body = digits.length === 4 ? digits.slice(1) : digits;
    const owner = Number(body[0] || 0);
    const group = Number(body[1] || 0);
    const other = Number(body[2] || 0);

    const toBits = (n) => [
        n & 4 ? 'r' : '-',
        n & 2 ? 'w' : '-',
        n & 1 ? 'x' : '-',
    ].join('');

    const symbolic = `${toBits(owner)}${toBits(group)}${toBits(other)}`;
    const dangerous = body === '777' || body === '666' || body === '776' || body === '767' || body === '707';

    return {
        display: digits.length === 4 ? digits : `0${digits}`,
        symbolic,
        dangerous,
        body,
    };
});

const permissionPreviewClass = computed(() => {
    if (!permissionPreview.value) {
        return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
    }

    return permissionPreview.value.dangerous
        ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300'
        : 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300';
});

const permissionCanSave = computed(() => /^[0-7]{3,4}$/.test(permissionDigits.value));

const permissionMatrix = computed(() => {
    const digits = permissionPreview.value?.body || '644';
    const owner = Number(digits[0] || 0);
    const group = Number(digits[1] || 0);
    const other = Number(digits[2] || 0);

    const labels = [
        { key: 'owner', label: 'Owner', value: owner },
        { key: 'group', label: 'Group', value: group },
        { key: 'other', label: 'Public', value: other },
    ];

    return labels.map((item) => ({
        ...item,
        read: Boolean(item.value & 4),
        write: Boolean(item.value & 2),
        execute: Boolean(item.value & 1),
    }));
});

const setPermissionPreset = (value) => {
    permissionForm.permissions = value;
};

const sanitizePermissionInput = () => {
    permissionForm.permissions = normalizePermissionDigits(permissionForm.permissions);
};

const iconClassForItem = (item) => {
    const category = itemCategory(item);

    switch (category) {
        case 'dir':
            return 'bi-folder-fill text-amber-500';
        case 'image':
            return 'bi-file-earmark-image text-emerald-500';
        case 'archive':
            return 'bi-file-earmark-zip text-orange-500';
        case 'media':
            return 'bi-file-earmark-play text-pink-500';
        case 'code':
            return 'bi-file-earmark-code text-indigo-500';
        case 'doc':
            return 'bi-file-earmark-text text-sky-500';
        default:
            return 'bi-file-earmark text-slate-500 dark:text-slate-300';
    }
};

const nameClassForItem = (item) => {
    const category = itemCategory(item);

    switch (category) {
        case 'dir':
            return 'text-amber-700 dark:text-amber-300';
        case 'image':
            return 'text-emerald-700 dark:text-emerald-300';
        case 'archive':
            return 'text-orange-700 dark:text-orange-300';
        case 'media':
            return 'text-pink-700 dark:text-pink-300';
        case 'code':
            return 'text-indigo-700 dark:text-indigo-300';
        case 'doc':
            return 'text-sky-700 dark:text-sky-300';
        default:
            return 'text-slate-700 dark:text-slate-200';
    }
};

const typeLabelForItem = (item) => {
    if (item?.type === 'dir') return 'Folder';
    const extension = itemExtension(item);
    return extension ? extension.toUpperCase() : 'File';
};

const activeItem = computed(() => props.items.find((item) => item.path === activeItemPath.value) || null);
const selectedItems = computed(() => props.items.filter((item) => selectedPaths.value.includes(item.path)));
const selectedCount = computed(() => selectedPaths.value.length);
const singleSelectedItem = computed(() => {
    if (selectedCount.value === 1) {
        return selectedItems.value[0] || null;
    }

    return activeItem.value;
});
const contextItem = computed(() => props.items.find((item) => item.path === contextMenu.value.itemPath) || null);
const contextZip = computed(() => {
    const item = contextItem.value;
    return Boolean(item && item.type === 'file' && String(item.name).toLowerCase().endsWith('.zip'));
});

const isZipSelected = computed(() => {
    const item = singleSelectedItem.value;
    return Boolean(item && item.type === 'file' && String(item.name).toLowerCase().endsWith('.zip'));
});

const isBusy = computed(
    () => createFolderForm.processing || createFileForm.processing || saveForm.processing || deleteForm.processing || uploadForm.processing || permissionForm.processing || renameForm.processing || zipForm.processing || unzipForm.processing || moveForm.processing,
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

const fmQuery = (extra = {}) => ({
    path: props.currentPath || '',
    show_hidden: hiddenEnabled.value ? 1 : 0,
    ...extra,
});

const confirmDiscardChanges = () => {
    if (!hasUnsavedChanges.value) return true;
    return confirm('You have unsaved changes. Continue without saving?');
};

const normalizePathInput = (value) => {
    const raw = String(value || '').trim().replace(/\\/g, '/');
    const base = normalizedBasePath();

    if (raw === '') return '';

    if (base && (raw === base || raw.startsWith(`${base}/`))) {
        return raw.slice(base.length).replace(/^\/+/, '');
    }

    return raw.replace(/^\/+/, '');
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
    openPath(normalizePathInput(pathInput.value));
};

const goParent = () => {
    if (!props.currentPath) {
        return;
    }

    const parent = props.currentPath.split('/').slice(0, -1).join('/');
    openPath(parent);
};

const goRoot = () => {
    pathInput.value = resolveDisplayPath('');
    openPath('');
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

const orderedItems = () => props.items;
const orderedPaths = () => orderedItems().map((item) => item.path);

const uniquePaths = (paths) => Array.from(new Set(paths));

const rangePathsForSelection = (targetPath) => {
    const ordered = orderedPaths();
    const anchor = selectionAnchorPath.value || activeItemPath.value;
    if (!anchor || ordered.length === 0) return [];

    const anchorIndex = ordered.indexOf(anchor);
    const targetIndex = ordered.indexOf(targetPath);
    if (anchorIndex < 0 || targetIndex < 0) return [];

    const start = Math.min(anchorIndex, targetIndex);
    const end = Math.max(anchorIndex, targetIndex);
    return ordered.slice(start, end + 1);
};

const applyRangeSelection = (targetPath, mergeWithSelection = false) => {
    const range = rangePathsForSelection(targetPath);
    if (range.length === 0) return false;

    selectedPaths.value = mergeWithSelection
        ? uniquePaths([...selectedPaths.value, ...range])
        : range;
    activeItemPath.value = targetPath;
    if (!selectionAnchorPath.value) {
        selectionAnchorPath.value = targetPath;
    }

    return true;
};

const ensureSingleSelection = (item) => {
    if (!item) return;
    activeItemPath.value = item.path;
    selectedPaths.value = [item.path];
    selectionAnchorPath.value = item.path;
};

const handleItemClick = (item, event) => {
    if (!item) return;

    closeContextMenu();
    const shiftSelect = Boolean(event?.shiftKey);
    const multiSelect = Boolean(event?.ctrlKey || event?.metaKey);
    activeItemPath.value = item.path;

    if (shiftSelect && applyRangeSelection(item.path, multiSelect)) {
        return;
    }

    if (multiSelect) {
        if (selectedPaths.value.includes(item.path)) {
            selectedPaths.value = selectedPaths.value.filter((entry) => entry !== item.path);
        } else {
            selectedPaths.value = [...selectedPaths.value, item.path];
        }
        if (selectedPaths.value.length === 0) {
            selectionAnchorPath.value = '';
        } else if (!selectionAnchorPath.value) {
            selectionAnchorPath.value = item.path;
        }
        return;
    }

    selectedPaths.value = [item.path];
    selectionAnchorPath.value = item.path;
};

const openContextMenu = (item, event) => {
    if (!item) return;
    event.preventDefault();
    event.stopPropagation();
    ensureSingleSelection(item);

    const menuWidth = 220;
    const menuHeight = 280;
    const viewportWidth = window.innerWidth || 1200;
    const viewportHeight = window.innerHeight || 800;
    const x = Math.max(8, Math.min(event.clientX, viewportWidth - menuWidth - 8));
    const y = Math.max(8, Math.min(event.clientY, viewportHeight - menuHeight - 8));

    contextMenu.value = {
        visible: true,
        x,
        y,
        itemPath: item.path,
        itemType: item.type,
        itemName: item.name,
    };
};

const triggerContextAction = (action) => {
    const item = contextItem.value;
    closeContextMenu();
    if (!item) return;

    ensureSingleSelection(item);
    if (action === 'open') {
        if (item.type === 'dir') {
            openPath(item.path);
            return;
        }

        openFileInEditor(item.path, { useModal: !props.openEditorPage });
        return;
    }

    if (action === 'open-tab') {
        if (item.type !== 'file') return;
        openFileInEditor(item.path, { openInNewTab: true, useModal: false });
        return;
    }

    if (action === 'download') {
        if (item.type !== 'file') return;
        const url = route('websites.filemanager.file.download', { id: props.website.id, file_path: item.path });
        window.location.href = url;
        return;
    }

    if (action === 'rename') {
        openModal('rename');
        return;
    }

    if (action === 'permissions') {
        openModal('permissions');
        return;
    }

    if (action === 'move') {
        openModal('move');
        return;
    }

    if (action === 'zip') {
        openModal('zip');
        return;
    }

    if (action === 'unzip') {
        if (!String(item.name || '').toLowerCase().endsWith('.zip')) return;
        openModal('unzip');
        return;
    }

    if (action === 'delete') {
        deleteSelected();
    }
};

const handleGlobalKeydown = (event) => {
    if (event.key === 'Escape') {
        closeContextMenu();
        return;
    }

    const key = String(event.key || '').toLowerCase();
    const isMultiSelectShortcut = Boolean(event.ctrlKey || event.metaKey) && key === 'a';
    if (!isMultiSelectShortcut) return;

    const target = event.target;
    if (
        target instanceof HTMLElement
        && (
            target.tagName === 'INPUT'
            || target.tagName === 'TEXTAREA'
            || target.tagName === 'SELECT'
            || target.isContentEditable
        )
    ) {
        return;
    }

    event.preventDefault();
    const allPaths = props.items.map((item) => item.path);
    selectedPaths.value = allPaths;
    activeItemPath.value = allPaths[0] || '';
    selectionAnchorPath.value = allPaths[0] || '';
};

const dragHasFiles = (event) => {
    const transferTypes = Array.from(event?.dataTransfer?.types || []);
    return transferTypes.includes('Files');
};

const handleTableDragEnter = (event) => {
    if (!dragHasFiles(event)) return;
    event.preventDefault();
    tableDragDepth.value += 1;
    tableDragActive.value = true;
};

const handleTableDragOver = (event) => {
    if (!dragHasFiles(event)) return;
    event.preventDefault();
    tableDragActive.value = true;
};

const handleTableDragLeave = (event) => {
    if (!dragHasFiles(event)) return;
    event.preventDefault();
    tableDragDepth.value = Math.max(0, tableDragDepth.value - 1);
    if (tableDragDepth.value === 0) {
        tableDragActive.value = false;
    }
};

const resetTableDragState = () => {
    tableDragDepth.value = 0;
    tableDragActive.value = false;
};

const toggleSelectPath = (path, checked, event = null) => {
    const shiftSelect = Boolean(event?.shiftKey);
    const multiSelect = Boolean(event?.ctrlKey || event?.metaKey);

    if (shiftSelect && applyRangeSelection(path, multiSelect || checked)) {
        return;
    }

    if (checked) {
        if (!selectedPaths.value.includes(path)) {
            selectedPaths.value = [...selectedPaths.value, path];
        }
        activeItemPath.value = path;
        if (!selectionAnchorPath.value) {
            selectionAnchorPath.value = path;
        }
        return;
    }

    selectedPaths.value = selectedPaths.value.filter((entry) => entry !== path);
    if (selectedPaths.value.length === 0) {
        selectionAnchorPath.value = '';
    }
};

const toggleSelectAll = (checked) => {
    const visibleItems = orderedItems();
    const visible = visibleItems.map((item) => item.path);

    if (checked) {
        selectedPaths.value = Array.from(new Set([...selectedPaths.value, ...visible]));
        if (!selectionAnchorPath.value && visible.length > 0) {
            selectionAnchorPath.value = visible[0];
        }
        return;
    }

    const visibleSet = new Set(visible);
    selectedPaths.value = selectedPaths.value.filter((path) => !visibleSet.has(path));
    if (selectedPaths.value.length === 0) {
        selectionAnchorPath.value = '';
    }
};

const currentMoveTargets = (fallbackPath = '') => {
    if (selectedPaths.value.length > 0) {
        return Array.from(new Set(selectedPaths.value));
    }

    if (singleSelectedItem.value?.path) {
        return [singleSelectedItem.value.path];
    }

    if (fallbackPath) {
        return [fallbackPath];
    }

    return [];
};

const submitMoveToPath = (destinationPath, itemPaths = null, { closeMoveModal = false } = {}) => {
    const normalizedDestination = normalizePathValue(destinationPath);
    const targets = Array.from(new Set((Array.isArray(itemPaths) ? itemPaths : currentMoveTargets()).filter((path) => normalizePathValue(path) !== '')));
    if (targets.length === 0) return;

    moveForm.current_path = props.currentPath;
    moveForm.destination_path = normalizedDestination;
    moveForm.item_path = '';
    moveForm.item_paths = targets;

    moveForm.patch(route('websites.filemanager.item.move', props.website.id), {
        preserveScroll: true,
        onSuccess: () => {
            if (closeMoveModal) {
                closeModal();
            }
        },
        onFinish: () => {
            draggingItemPaths.value = [];
            moveDragTargetPath.value = null;
        },
    });
};

const submitMove = () => {
    submitMoveToPath(moveForm.destination_path, null, { closeMoveModal: true });
};

const parseDraggedItemPaths = (event) => {
    const payload = String(event?.dataTransfer?.getData(INTERNAL_MOVE_MIME) || '').trim();
    if (payload !== '') {
        try {
            const parsed = JSON.parse(payload);
            if (Array.isArray(parsed)) {
                return parsed.map((path) => normalizePathValue(path)).filter((path) => path !== '');
            }
        } catch (error) {
            // Ignore malformed payload and fallback to local state.
        }
    }

    return draggingItemPaths.value.map((path) => normalizePathValue(path)).filter((path) => path !== '');
};

const hasInternalMoveDrag = (event) => {
    const transferTypes = Array.from(event?.dataTransfer?.types || []);
    return transferTypes.includes(INTERNAL_MOVE_MIME) || draggingItemPaths.value.length > 0;
};

const handleItemDragStart = (item, event) => {
    if (!item?.path) return;

    let targets = [];
    if (selectedPaths.value.includes(item.path)) {
        targets = currentMoveTargets(item.path);
    } else {
        targets = [item.path];
        selectedPaths.value = [item.path];
        selectionAnchorPath.value = item.path;
        activeItemPath.value = item.path;
    }

    draggingItemPaths.value = targets;

    if (event?.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData(INTERNAL_MOVE_MIME, JSON.stringify(targets));
        event.dataTransfer.setData('text/plain', targets.join('\n'));
    }
};

const handleItemDragEnd = () => {
    draggingItemPaths.value = [];
    moveDragTargetPath.value = null;
};

const handleFolderTargetDragOver = (targetPath, event) => {
    if (!hasInternalMoveDrag(event)) return;
    event.preventDefault();
    if (event?.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }
    moveDragTargetPath.value = normalizePathValue(targetPath);
};

const handleFolderTargetDragLeave = (targetPath) => {
    if (moveDragTargetPath.value === normalizePathValue(targetPath)) {
        moveDragTargetPath.value = null;
    }
};

const handleFolderTargetDrop = (targetPath, event) => {
    if (!hasInternalMoveDrag(event)) return;
    event.preventDefault();
    event.stopPropagation();

    const targets = parseDraggedItemPaths(event);
    submitMoveToPath(targetPath, targets);
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
        permissionForm.recursive = false;
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

    if (type === 'move') {
        moveForm.current_path = props.currentPath;
        moveForm.destination_path = props.currentPath || '';
        moveForm.item_path = '';
        moveForm.item_paths = currentMoveTargets();
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

const submitUpload = ({ fromDrop = false } = {}) => {
    uploadForm.path = props.currentPath;
    uploadForm.post(route('websites.filemanager.upload', props.website.id), {
        forceFormData: true,
        onStart: () => {
            uploadForm.clearErrors();
            if (fromDrop) {
                droppedUploadHint.value = `Uploading: ${uploadForm.upload?.name || 'file'}`;
            }
        },
        onSuccess: () => {
            uploadDragActive.value = false;
            if (fromDrop) {
                droppedUploadHint.value = 'Upload complete.';
            }
        },
        onError: () => {
            if (fromDrop) {
                droppedUploadHint.value = 'Upload failed.';
            }
        },
        onFinish: () => {
            if (fromDrop) {
                window.setTimeout(() => {
                    droppedUploadHint.value = '';
                }, 3500);
            }
        },
    });
};

const handleTableDrop = (event) => {
    if (!dragHasFiles(event)) return;
    event.preventDefault();
    resetTableDragState();

    const files = Array.from(event.dataTransfer?.files || []);
    if (files.length === 0) return;

    if (files.length > 1) {
        droppedUploadHint.value = `Dropped ${files.length} files. Uploading first file: ${files[0].name}`;
    }

    uploadForm.upload = files[0];
    submitUpload({ fromDrop: true });
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

    <div class="flex min-h-screen flex-col bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <header class="border-b border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-950/95">
            <div class="flex flex-wrap items-center gap-3 px-4 py-3 lg:px-6">
                <div class="min-w-0">
                    <h1 class="truncate text-lg font-semibold">File Manager</h1>
                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ website.domain }} - base {{ basePath }}</p>
                </div>
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <Link :href="route('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                        Back to Websites
                    </Link>
                    <button type="button" title="Edit/Open" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="isBusy" @click="editSelected">
                        <i aria-hidden="true" class="itc bi bi-pencil-square text-sm"></i>
                        <span class="sr-only">Edit/Open</span>
                    </button>
                    <button type="button" title="Rename" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="!singleSelectedItem || isBusy" @click="openModal('rename')">
                        <i aria-hidden="true" class="itc bi bi-input-cursor-text text-sm"></i>
                        <span class="sr-only">Rename</span>
                    </button>
                    <button type="button" title="Move" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="(!singleSelectedItem && !selectedCount) || isBusy" @click="openModal('move')">
                        <i aria-hidden="true" class="itc bi bi-arrows-move text-sm"></i>
                        <span class="sr-only">Move</span>
                    </button>
                    <button type="button" title="New File" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="isBusy" @click="openModal('create-file')">
                        <i aria-hidden="true" class="itc bi bi-file-earmark-plus text-sm"></i>
                        <span class="sr-only">New File</span>
                    </button>
                    <button type="button" title="New Folder" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="isBusy" @click="openModal('create-folder')">
                        <i aria-hidden="true" class="itc bi bi-folder-plus text-sm"></i>
                        <span class="sr-only">New Folder</span>
                    </button>
                    <button type="button" title="Upload" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="isBusy" @click="openModal('upload')">
                        <i aria-hidden="true" class="itc bi bi-cloud-arrow-up text-sm"></i>
                        <span class="sr-only">Upload</span>
                    </button>
                    <button type="button" title="Delete" class="rounded-md border border-red-300 px-3 py-2 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400" :disabled="(!singleSelectedItem && !selectedCount) || isBusy" @click="deleteSelected">
                        <i aria-hidden="true" class="itc bi bi-trash text-sm"></i>
                        <span class="sr-only">Delete</span>
                    </button>
                    <button type="button" title="Zip" class="rounded-md border border-amber-300 px-3 py-2 text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300" :disabled="(!singleSelectedItem && !selectedCount) || isBusy" @click="openModal('zip')">
                        <i aria-hidden="true" class="itc bi bi-file-earmark-zip text-sm"></i>
                        <span class="sr-only">Zip</span>
                    </button>
                    <button type="button" title="Unzip" class="rounded-md border border-emerald-300 px-3 py-2 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-400" :disabled="!isZipSelected || isBusy" @click="openModal('unzip')">
                        <i aria-hidden="true" class="itc bi bi-file-earmark-arrow-up text-sm"></i>
                        <span class="sr-only">Unzip</span>
                    </button>
                    <button type="button" title="Permissions" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="!singleSelectedItem || isBusy" @click="openModal('permissions')">
                        <i aria-hidden="true" class="itc bi bi-shield-lock text-sm"></i>
                        <span class="sr-only">Permissions</span>
                    </button>
                    <button type="button" title="Download" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="!singleSelectedItem || singleSelectedItem.type !== 'file'" @click="downloadSelected">
                        <i aria-hidden="true" class="itc bi bi-download text-sm"></i>
                        <span class="sr-only">Download</span>
                    </button>
                    <button type="button" title="Up One Level" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="isBusy || !currentPath" @click="goParent">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="m8 2-3 3h2v4h2V5h2z"/><path d="M2 12h12v2H2z"/></svg>
                        <span class="sr-only">Up One Level</span>
                    </button>
                    <button type="button" :title="hiddenEnabled ? 'Hide Dot Files' : 'Show Dot Files'" class="rounded-md border border-slate-300 px-3 py-2 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="isBusy" @click="toggleHidden">
                        <svg v-if="hiddenEnabled" aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M13.359 11.238C14.52 10.15 15.37 8.89 16 8c-1.12-1.58-3.32-4.5-6.88-5.32l1.06 1.06a8.8 8.8 0 0 1 4.72 4.26 11.8 11.8 0 0 1-2.24 2.66z"/><path d="M11.297 13.176A8.7 8.7 0 0 1 8 13.5C3 13.5 0 8 0 8c.71-1.03 1.6-2.12 2.72-3.03l1.43 1.43A3 3 0 0 0 7.6 9.85z"/><path d="m14.854 15.146-14-14 .708-.708 14 14z"/></svg>
                        <svg v-else aria-hidden="true" class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M8 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6"/></svg>
                        <span class="sr-only">{{ hiddenEnabled ? 'Hide Dot Files' : 'Show Dot Files' }}</span>
                    </button>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-hidden p-1">
            <div class="space-y-4">
                <div class="grid gap-4 xl:grid-cols-12">
                    <aside class="xl:col-span-4 flex h-[calc(100vh-5rem)] flex-col gap-3 rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Quick Path</p>
                            <button type="button" class="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="goRoot">Root</button>
                        </div>
                        <div class="flex items-center gap-1">
                            <input v-model="pathInput" type="text" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800" placeholder="folder/subfolder" />
                            <button type="button" class="rounded-md bg-slate-800 px-2 py-1.5 text-[11px] font-medium text-white hover:bg-slate-700" :disabled="isBusy" @click="goFromPathInput">
                                Go
                            </button>
                        </div>
                    </div>

                    <div class="flex min-h-0 flex-1 flex-col">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Directories</p>
                        <div class="min-h-0 flex-1 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-1 text-xs dark:border-slate-800">
                            <div v-for="node in treeRows" :key="`tree-${node.path}`">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-1 rounded-md px-2 py-1 text-left hover:bg-slate-100 dark:hover:bg-slate-800"
                                    :class="[
                                        isTreeNodeActive(node.path) ? 'bg-blue-50 dark:bg-blue-900/20' : '',
                                        moveDragTargetPath === node.path ? 'bg-emerald-50 ring-1 ring-emerald-400 dark:bg-emerald-900/20' : '',
                                    ]"
                                    :title="node.path"
                                    :style="{ paddingLeft: `${8 + (node.level * 14)}px` }"
                                    @click="openPath(node.path)"
                                    @dragover.prevent.stop="handleFolderTargetDragOver(node.path, $event)"
                                    @dragleave="handleFolderTargetDragLeave(node.path)"
                                    @drop.prevent.stop="handleFolderTargetDrop(node.path, $event)"
                                >
                                    <span class="inline-flex h-4 w-4 items-center justify-center" @click.stop="node.hasChildren ? toggleTreeNode(node.path) : null">
                                        <i v-if="node.hasChildren" class="bi text-[10px] text-slate-500" :class="node.expanded ? 'bi-caret-down-fill' : 'bi-caret-right-fill'"></i>
                                    </span>
                                    <i class="bi bi-folder-fill text-[12px] text-amber-500"></i>
                                    <span class="block truncate">{{ node.name }}</span>
                                </button>
                            </div>
                        </div>
                        <p class="mt-2 text-[11px] text-slate-500">Drag selected file/folder rows and drop on a folder to move.</p>
                    </div>

                    </aside>

                    <section
                        class="relative xl:col-span-8 space-y-4"
                        @dragenter.prevent="handleTableDragEnter"
                        @dragover.prevent="handleTableDragOver"
                        @dragleave.prevent="handleTableDragLeave"
                        @drop.prevent="handleTableDrop"
                    >
                    <div v-if="tableDragActive" class="pointer-events-none absolute inset-0 z-20 flex items-center justify-center rounded-xl border-2 border-dashed border-sky-500 bg-sky-500/10">
                        <div class="rounded-lg bg-white/90 px-5 py-3 text-center text-sm font-semibold text-sky-700 shadow-sm dark:bg-slate-900/90 dark:text-sky-300">
                            Drop file here to upload into
                            <span class="font-mono">{{ resolveDisplayPath(currentPath) || '/' }}</span>
                        </div>
                    </div>
                    <div v-if="droppedUploadHint" class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-700 dark:border-sky-800 dark:bg-sky-900/20 dark:text-sky-200">
                        {{ droppedUploadHint }}
                    </div>
                    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="w-10 px-3 py-3">
                                    <input type="checkbox" :checked="items.length > 0 && items.every((item) => selectedPaths.includes(item.path))" @change="toggleSelectAll($event.target.checked)" />
                                </th>
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
                                data-fm-row
                                class="cursor-pointer border-t border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/60"
                                :class="[
                                    selectedPaths.includes(item.path) || activeItemPath === item.path ? 'bg-blue-50 dark:bg-blue-900/20' : '',
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
                                <td class="px-3 py-2" @click.stop>
                                    <input type="checkbox" :checked="selectedPaths.includes(item.path)" @change="toggleSelectPath(item.path, $event.target.checked, $event)" />
                                </td>
                                <td class="px-3 py-2 font-medium">
                                    <span class="flex items-center gap-2">
                                        <i class="bi text-sm" :class="iconClassForItem(item)"></i>
                                        <span class="truncate" :class="nameClassForItem(item)">
                                            {{ item.name }}
                                        </span>
                                    </span>
                                    <span v-if="unsavedFilePath === item.path" class="ml-1 text-[10px] font-semibold text-amber-600">*</span>
                                </td>
                                <td class="px-3 py-2 uppercase text-xs">{{ typeLabelForItem(item) }}</td>
                                <td class="px-3 py-2">{{ formatBytes(item.size) }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ item.permissions }}</td>
                                <td class="px-3 py-2 text-xs text-slate-500">{{ item.modified_at ? new Date(item.modified_at).toLocaleString() : '-' }}</td>
                            </tr>
                            <tr v-if="items.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-slate-500">No files in this directory.</td>
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
        </main>

        <div
            v-if="contextMenu.visible"
            data-fm-context-menu
            class="fixed z-[60] w-56 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"
            :style="{ left: `${contextMenu.x}px`, top: `${contextMenu.y}px` }"
            @click.stop
        >
            <div class="border-b border-slate-200 px-3 py-2 dark:border-slate-700">
                <p class="truncate text-xs font-semibold text-slate-800 dark:text-slate-100">{{ contextMenu.itemName }}</p>
                <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ contextMenu.itemType }}</p>
            </div>

            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('open')">
                <i aria-hidden="true" class="itc bi bi-box-arrow-in-right text-xs"></i>
                {{ contextItem?.type === 'dir' ? 'Open Folder' : 'Open File' }}
            </button>
            <button v-if="contextItem?.type === 'file'" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('open-tab')">
                <i aria-hidden="true" class="itc bi bi-window-stack text-xs"></i>
                Open in New Tab
            </button>
            <button v-if="contextItem?.type === 'file'" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('download')">
                <i aria-hidden="true" class="itc bi bi-download text-xs"></i>
                Download
            </button>

            <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('rename')">
                <i aria-hidden="true" class="itc bi bi-input-cursor-text text-xs"></i>
                Rename
            </button>
            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('permissions')">
                <i aria-hidden="true" class="itc bi bi-shield-lock text-xs"></i>
                Permissions
            </button>
            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('move')">
                <i aria-hidden="true" class="itc bi bi-arrows-move text-xs"></i>
                Move
            </button>
            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('zip')">
                <i aria-hidden="true" class="itc bi bi-file-earmark-zip text-xs"></i>
                Create Zip
            </button>
            <button v-if="contextZip" type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="triggerContextAction('unzip')">
                <i aria-hidden="true" class="itc bi bi-file-earmark-arrow-up text-xs"></i>
                Extract Zip
            </button>

            <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>

            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/20" @click="triggerContextAction('delete')">
                <i aria-hidden="true" class="itc bi bi-trash text-xs"></i>
                Delete
            </button>
        </div>

        <div v-if="modalType" class="fixed inset-0 z-50 flex bg-black/40 p-4" :class="modalType === 'editor' ? 'items-stretch justify-stretch' : 'items-center justify-center'">
            <div
                class="w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-700 dark:bg-slate-900"
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

                <div v-else-if="modalType === 'move'" class="space-y-3">
                    <p class="text-xs text-slate-500">Selected items: {{ selectedCount || (singleSelectedItem ? 1 : 0) }}</p>
                    <input v-model="moveForm.destination_path" type="text" placeholder="destination folder path (empty = root)" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p class="text-xs text-slate-500">You can also drag selected rows and drop onto sidebar folders.</p>
                    <button type="button" :disabled="moveForm.processing || (!selectedCount && !singleSelectedItem)" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="submitMove">
                        {{ moveForm.processing ? 'Moving...' : 'Move' }}
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
    </div>
</template>
