import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { EDITABLE_EXTENSIONS } from '../constants/editableExtensions';
import { NON_EDITABLE_EXTENSIONS } from '../constants/nonEditableExtensions';
import { formatBytes, resolveDisplayPath } from '../helpers/fileUtils';
import { iconClassForItem, nameClassForItem, typeLabelForItem } from '../helpers/fileIcons';
import { getPermissionPreview } from '../helpers/permissions';

export function useFileManager(props) {
    const page = usePage();
    const panelToken = computed(() => String(page.props.panel?.token || ''));
    const panelRoute = (name, params = {}) => (
        panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
    );

    const fileManagerRoot = computed(() => String(props.rootFolder || '').trim().replace(/\\/g, '/').replace(/^\/+|\/+$/g, ''));
    function fileManagerRouteParams(extra = {}) {
        const params = { id: props.website.id, ...extra };

        if (fileManagerRoot.value !== '' && !Object.prototype.hasOwnProperty.call(extra, 'root')) {
            params.root = fileManagerRoot.value;
        }

        return params;
    }

    const websiteLabel = computed(() => String(props.website?.domain || props.website?.name || props.website?.id || 'Website'));
    const modalType = ref('');
    const sidebarOpen = ref(true);
    const sortBy = ref('name');
    const sortDir = ref('asc');
    const searchQuery = ref('');
    const isMobile = ref(false);
    const pathInput = ref(resolveDisplayPath(props.basePath, props.currentPath));
    const activeItemPath = ref(props.selectedFile?.path || props.items?.[0]?.path || '');
    const selectedPaths = ref([]);
    const selectionAnchorPath = ref('');
    const hiddenEnabled = ref(Boolean(props.showHidden));
    const uploadDragActive = ref(false);
    const uploadProgress = ref(0);
    const uploadTaskComplete = ref(false);
    const tableDragActive = ref(false);
    const tableDragDepth = ref(0);
    const droppedUploadHint = ref('');
    const moveDragTargetPath = ref(null);
    const draggingItemPaths = ref([]);
    const originalEditorContent = ref(props.selectedFile?.content ?? '');
    const treeOpenState = ref({});
    const saveInProgress = ref(false);
    const INTERNAL_MOVE_MIME = 'application/x-serverpanel-item-paths';
    const toasts = ref([]);
    let toastSeq = 0;

    function removeToast(id) {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }

    function pushToast(message, type = 'error') {
        if (!message) return;

        const id = `${Date.now()}-${toastSeq += 1}`;
        toasts.value.push({ id, message: String(message), type });

        window.setTimeout(() => {
            removeToast(id);
        }, 4500);
    }

    function checkMobile() {
        isMobile.value = window.innerWidth < 1024;
        if (isMobile.value) sidebarOpen.value = false;
    }

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

    function closeModal() {
        modalType.value = '';
    }

    function toggleSidebar() {
        sidebarOpen.value = !sidebarOpen.value;
    }

    function closeSidebar() {
        sidebarOpen.value = false;
    }

    function clearSelection() {
        selectedPaths.value = [];
    }

    function openModal(type) {
        modalType.value = type;
    }

    function goRoot() {
        openPath('');
    }

    function resetRootScope() {
        router.get(panelRoute('websites.filemanager', { id: props.website.id }));
    }

    function setScopeRoot(path) {
        router.get(panelRoute('websites.filemanager', fileManagerRouteParams({ root: path || '' })));
    }

    function openPath(path) {
        router.get(panelRoute('websites.filemanager', fileManagerRouteParams({ path })));
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
        router.patch(
            panelRoute('websites.filemanager.settings', fileManagerRouteParams()),
            {
                show_hidden: hiddenEnabled.value,
                path: props.currentPath,
                root: fileManagerRoot.value,
                file_path: props.selectedFile?.path ?? '',
            },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
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
        activeItemPath.value = item.path;

        if (event?.ctrlKey || event?.metaKey) {
            const checked = !selectedPaths.value.includes(item.path);
            toggleSelectPath(item.path, checked, event);
            return;
        }

        if (event?.shiftKey && selectionAnchorPath.value) {
            toggleSelectPath(item.path, true, event);
            return;
        }

        selectedPaths.value = [];
        selectionAnchorPath.value = item.path;
    }

    function handleWindowClick(event) {
        const target = event.target;
        if (!(target instanceof Element)) return;

        if (!target.closest('[data-fm-context-menu]')) {
            closeContextMenu();
        }
    }

    function handleWindowKeydown(event) {
        if (event.key === 'Escape') {
            closeContextMenu();
            if (modalType.value) closeModal();
        }
    }

    function toggleTreeNode(path) {
        treeOpenState.value = {
            ...treeOpenState.value,
            [path]: !(treeOpenState.value[path] ?? false),
        };
    }

    function isTreeNodeActive(path) {
        return resolveDisplayPath(props.basePath, props.currentPath) === path || String(props.currentPath || '').startsWith(path + '/');
    }

    const contextMenu = ref({
        visible: false,
        x: 0,
        y: 0,
        itemPath: '',
        itemType: '',
        itemName: '',
    });

    const contextItem = computed(() => props.items.find((item) => item.path === contextMenu.value.itemPath) || null);
    const contextZip = computed(() => contextItem.value?.type === 'file' && String(contextItem.value?.name || '').toLowerCase().endsWith('.zip'));

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

    function submitMove() {
        moveForm.current_path = props.currentPath;
        moveForm.patch(panelRoute('websites.filemanager.item.move', fileManagerRouteParams()), {
            onSuccess: () => {
                moveForm.destination_path = props.currentPath;
                selectedPaths.value = [];
                closeModal();
            },
        });
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

    function isEditableFile(filename) {
        const name = String(filename || '').toLowerCase();
        const ext = name.split('.').pop() || '';

        if (NON_EDITABLE_EXTENSIONS.includes(ext)) return false;
        if (EDITABLE_EXTENSIONS.includes(ext)) return true;

        const basename = name.split('/').pop() || '';
        if (basename === '.env' || basename === '.htaccess' || basename === 'makefile' || basename === 'dockerfile') return true;
        return false;
    }

    function openFileInEditor(path) {
        const filename = path.split('/').pop() || '';

        if (!isEditableFile(filename)) {
            pushToast(`Cannot edit "${filename}". This file type is not supported for editing.`, 'info');
            return;
        }

        router.get(panelRoute('websites.filemanager', fileManagerRouteParams({ file_path: path })));
    }

    function downloadSelected() {
        if (!singleSelectedItem.value || singleSelectedItem.value.type !== 'file') return;
        window.location.href = panelRoute('websites.filemanager.file.download', fileManagerRouteParams({ file_path: singleSelectedItem.value.path }));
    }

    function deleteSelected() {
        const paths = selectedPaths.value.length ? selectedPaths.value : (singleSelectedItem.value ? [singleSelectedItem.value.path] : []);
        if (!paths.length) return;

        if (!confirm(`Delete ${paths.length} item(s)?`)) return;

        deleteForm.item_paths = paths;
        deleteForm.current_path = props.currentPath;
        deleteForm.delete(panelRoute('websites.filemanager.item.delete', fileManagerRouteParams()), {
            onSuccess: () => {
                selectedPaths.value = [];
            },
        });
    }

    function submitCreateFolder() {
        createFolderForm.path = props.currentPath;
        createFolderForm.post(panelRoute('websites.filemanager.folder.store', fileManagerRouteParams()), {
            onSuccess: () => {
                createFolderForm.name = '';
                closeModal();
            },
        });
    }

    function submitCreateFile() {
        createFileForm.path = props.currentPath;
        createFileForm.post(panelRoute('websites.filemanager.file.store', fileManagerRouteParams()), {
            onSuccess: () => {
                createFileForm.name = '';
                closeModal();
            },
        });
    }

    function submitRename() {
        renameForm.current_path = props.currentPath;
        renameForm.patch(panelRoute('websites.filemanager.item.rename', fileManagerRouteParams()), {
            onSuccess: () => {
                renameForm.new_name = '';
                closeModal();
            },
        });
    }

    function submitPermissions() {
        permissionForm.current_path = props.currentPath;
        permissionForm.patch(panelRoute('websites.filemanager.permissions', fileManagerRouteParams()), {
            onSuccess: () => {
                closeModal();
            },
        });
    }

    function submitZip() {
        zipForm.current_path = props.currentPath;
        zipForm.post(panelRoute('websites.filemanager.zip', fileManagerRouteParams()), {
            onSuccess: () => {
                zipForm.zip_name = '';
                selectedPaths.value = [];
                closeModal();
            },
        });
    }

    function submitUnzip() {
        unzipForm.current_path = props.currentPath;
        unzipForm.post(panelRoute('websites.filemanager.unzip', fileManagerRouteParams()), {
            onSuccess: () => {
                closeModal();
            },
        });
    }

    function submitUpload() {
        uploadForm.path = props.currentPath;
        uploadProgress.value = 0;
        uploadTaskComplete.value = false;
        uploadForm.post(panelRoute('websites.filemanager.upload', fileManagerRouteParams()), {
            onProgress: (event) => {
                uploadProgress.value = Math.round(event?.percentage || 0);
            },
            onSuccess: () => {
                uploadForm.upload = null;
                uploadTaskComplete.value = true;
                uploadProgress.value = 100;
                closeModal();
            },
        });
    }

    function submitSaveFile() {
        if (saveInProgress.value || !hasUnsavedChanges.value) return;

        saveInProgress.value = true;
        window.axios
            .patch(panelRoute('websites.filemanager.file.save', fileManagerRouteParams()), {
                file_path: saveForm.file_path,
                content: saveForm.content,
            }, {
                headers: {
                    Accept: 'application/json',
                },
            })
            .then((response) => {
                originalEditorContent.value = saveForm.content;
                const message = response?.data?.message || 'File saved.';
                pushToast(message, 'success');
            })
            .catch((error) => {
                const message = error?.response?.data?.message || 'Failed to save file.';
                pushToast(message, 'error');
            })
            .finally(() => {
                saveInProgress.value = false;
            });
    }

    function triggerContextAction(action) {
        const item = contextItem.value;
        closeContextMenu();
        if (!item) return;

        switch (action) {
            case 'open':
                item.type === 'dir' ? openPath(item.path) : openFileInEditor(item.path);
                break;
            case 'open-tab':
                if (isEditableFile(item.name)) {
                    window.open(panelRoute('websites.filemanager', fileManagerRouteParams({ file_path: item.path })), '_blank');
                } else {
                    pushToast(`Cannot edit "${item.name}". This file type is not supported for editing.`, 'info');
                }
                break;
            case 'set-root':
                if (item.type === 'dir') {
                    setScopeRoot(item.path);
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

    function openPermissionsForSelection() {
        const item = singleSelectedItem.value;
        if (!item) return;

        permissionForm.item_path = item.path;
        permissionForm.permissions = String(item.permissions || '644');
        openModal('permissions');
    }

    function openZipForSelection() {
        const paths = selectedPaths.value.length ? selectedPaths.value : (singleSelectedItem.value ? [singleSelectedItem.value.path] : []);
        if (!paths.length) return;

        zipForm.item_paths = paths;
        const sourceItem = props.items.find((item) => item.path === paths[0]) || singleSelectedItem.value;
        zipForm.zip_name = sourceItem
            ? (sourceItem.type === 'dir'
                ? `${sourceItem.name}.zip`
                : `${sourceItem.name.replace(/\.[^.]+$/, '')}.zip`)
            : 'archive.zip';
        openModal('zip');
    }

    function openUnzipForSelection() {
        if (!isZipSelected.value || !singleSelectedItem.value) return;

        unzipForm.zip_path = singleSelectedItem.value.path;
        openModal('unzip');
    }

    function setPermissionPreset(value) {
        permissionForm.permissions = value;
    }

    function sanitizePermissionInput(event) {
        const raw = String(event.target?.value || '');
        const sanitized = raw.replace(/[^0-7]/g, '').slice(0, 4);
        permissionForm.permissions = sanitized;
    }

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

    const selectedCount = computed(() => selectedPaths.value.length);
    const selectedPathList = computed(() => Array.isArray(selectedPaths.value) ? selectedPaths.value : []);
    const isAllItemsSelected = computed(() => (
        filteredItems.value.length > 0 &&
        filteredItems.value.every((item) => selectedPathList.value.includes(item.path))
    ));
    const isItemSelected = (path) => selectedPathList.value.includes(path);
    const singleSelectedItem = computed(() => {
        if (selectedPaths.value.length !== 1) return null;
        return props.items.find((item) => item.path === selectedPaths.value[0]) || null;
    });
    const isZipSelected = computed(() => {
        if (!singleSelectedItem.value) return false;
        return singleSelectedItem.value.type === 'file' && String(singleSelectedItem.value.name || '').toLowerCase().endsWith('.zip');
    });

    const quickActionsGroups = computed(() => ([
        {
            label: 'Create',
            items: [
                { label: 'Upload', hint: 'Add files to this folder', icon: 'bi-cloud-arrow-up', className: 'text-blue-500', action: () => openModal('upload'), visible: true },
                { label: 'New File', hint: 'Create a blank file', icon: 'bi-file-earmark-plus', className: 'text-emerald-500', action: () => openModal('create-file'), visible: true },
                { label: 'New Folder', hint: 'Make a directory', icon: 'bi-folder-plus', className: 'text-amber-500', action: () => openModal('create-folder'), visible: true },
            ],
        },
        {
            label: 'File Ops',
            items: [
                { label: 'Permissions', hint: 'Change access mode', icon: 'bi-shield-lock', className: 'text-purple-500', action: () => openPermissionsForSelection(), visible: !!singleSelectedItem.value },
                { label: 'Download', hint: 'Save locally', icon: 'bi-download', className: 'text-slate-500', action: () => downloadSelected(), visible: !!singleSelectedItem.value && singleSelectedItem.value.type === 'file' },
                { label: 'Zip', hint: 'Compress selection', icon: 'bi-file-earmark-zip', className: 'text-amber-500', action: () => openZipForSelection(), visible: !!singleSelectedItem.value || selectedCount.value > 0 },
                { label: 'Extract', hint: 'Unpack archive', icon: 'bi-file-earmark-arrow-up', className: 'text-green-500', action: () => openUnzipForSelection(), visible: isZipSelected.value },
            ],
        },
        {
            label: 'Navigation',
            items: [
                { label: 'Up Level', hint: 'Go to parent folder', icon: 'bi-arrow-up', className: 'text-slate-500', action: () => goParent(), visible: !!props.currentPath },
                { label: hiddenEnabled.value ? 'Hide Hidden' : 'Show Hidden', hint: 'Toggle dotfiles', icon: hiddenEnabled.value ? 'bi-eye-slash' : 'bi-eye', className: 'text-slate-500', action: () => toggleHidden(), visible: true },
            ],
        },
        {
            label: 'Danger',
            items: [
                { label: 'Delete', hint: 'Remove selected item(s)', icon: 'bi-trash', className: 'text-red-500', action: () => deleteSelected(), danger: true, visible: !!singleSelectedItem.value || selectedCount.value > 0 },
            ],
        },
    ]).map((group) => ({
        ...group,
        items: Array.isArray(group.items) ? group.items.filter((item) => item.visible) : [],
    })));

    const filteredItems = computed(() => {
        let list = Array.isArray(props.items)
            ? props.items.filter((item) => item && typeof item === 'object' && Boolean(item.path))
            : [];

        if (searchQuery.value.trim()) {
            const q = searchQuery.value.toLowerCase();
            list = list.filter((item) => String(item.name || '').toLowerCase().includes(q));
        }

        list.sort((a, b) => {
            if (a.type === 'dir' && b.type !== 'dir') return -1;
            if (a.type !== 'dir' && b.type === 'dir') return 1;

            let valA;
            let valB;
            switch (sortBy.value) {
                case 'size':
                    valA = a.size || 0;
                    valB = b.size || 0;
                    break;
                case 'modified':
                    valA = a.modified_at || '';
                    valB = b.modified_at || '';
                    break;
                case 'type':
                    valA = a.type || '';
                    valB = b.type || '';
                    break;
                default:
                    valA = (a.name || '').toLowerCase();
                    valB = (b.name || '').toLowerCase();
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
                if (!node || typeof node !== 'object') continue;
                if (!node.path) continue;
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

    const hasUnsavedChanges = computed(() => saveForm.content !== originalEditorContent.value);
    const unsavedFilePath = computed(() => (hasUnsavedChanges.value ? saveForm.file_path : ''));
    const editorFileName = computed(() => saveForm.file_path.split('/').pop() || 'untitled');
    const editorFileFolder = computed(() => {
        const parts = String(saveForm.file_path || '').split('/').filter(Boolean);
        parts.pop();
        return parts.join('/') || '.';
    });
    const editorLineCount = computed(() => Math.max(1, String(saveForm.content || '').replace(/\n$/, '').split('\n').length));
    const permissionDigits = computed(() => {
        const digits = String(permissionForm.permissions || '').replace(/^0+/, '').slice(0, 3);
        return digits.length === 3 ? digits : '';
    });
    const permissionPreview = computed(() => getPermissionPreview(permissionForm.permissions));
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
    const isBusy = computed(() =>
        createFolderForm.processing || createFileForm.processing || saveInProgress.value ||
        deleteForm.processing || uploadForm.processing || permissionForm.processing ||
        renameForm.processing || zipForm.processing || unzipForm.processing || moveForm.processing
    );

    function handleEditorBeforeUnload(event) {
        if (hasUnsavedChanges.value) {
            event.preventDefault();
            event.returnValue = '';
        }
    }

    watch(
        () => props.currentPath,
        (value) => {
            pathInput.value = resolveDisplayPath(props.basePath, value);
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
            } else if (modalType.value === 'editor' && !props.openEditorModal) {
                closeModal();
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

    watch(
        () => props.selectedFile,
        (value) => {
            if (value?.path) {
                modalType.value = 'editor';
            } else if (modalType.value === 'editor' && !props.openEditorModal) {
                closeModal();
            }
        },
    );

    onMounted(() => {
        checkMobile();
        window.addEventListener('resize', checkMobile);
        window.addEventListener('click', handleWindowClick);
        window.addEventListener('keydown', handleWindowKeydown);
        if (props.openUploadTab) {
            openModal('upload');
        }
        if (props.openEditorModal || props.selectedFile?.path) {
            modalType.value = 'editor';
        }
        if (props.selectedFile?.path) {
            activeItemPath.value = props.selectedFile.path;
        }
        window.addEventListener('beforeunload', handleEditorBeforeUnload);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('resize', checkMobile);
        window.removeEventListener('click', handleWindowClick);
        window.removeEventListener('keydown', handleWindowKeydown);
        window.removeEventListener('beforeunload', handleEditorBeforeUnload);
    });

    return reactive({
        props,
        // props helpers
        panelRoute,
        fileManagerRouteParams,
        fileManagerRoot,
        websiteLabel,
        // ui state
        modalType,
        sidebarOpen,
        sortBy,
        sortDir,
        searchQuery,
        isMobile,
        pathInput,
        activeItemPath,
        selectedPaths,
        selectionAnchorPath,
        hiddenEnabled,
        uploadDragActive,
        uploadProgress,
        uploadTaskComplete,
        tableDragActive,
        tableDragDepth,
        droppedUploadHint,
        moveDragTargetPath,
        draggingItemPaths,
        originalEditorContent,
        treeOpenState,
        saveInProgress,
        contextMenu,
        toasts,
        // forms
        createFolderForm,
        createFileForm,
        saveForm,
        deleteForm,
        uploadForm,
        permissionForm,
        renameForm,
        zipForm,
        unzipForm,
        moveForm,
        // computed
        selectedCount,
        selectedPathList,
        isAllItemsSelected,
        isItemSelected,
        singleSelectedItem,
        isZipSelected,
        quickActionsGroups,
        filteredItems,
        treeRows,
        items: computed(() => props.items || []),
        directoryTree: computed(() => props.directoryTree || []),
        selectedFile: computed(() => props.selectedFile || null),
        contextItem,
        contextZip,
        hasUnsavedChanges,
        unsavedFilePath,
        editorFileName,
        editorFileFolder,
        editorLineCount,
        permissionDigits,
        permissionPreview,
        permissionPreviewClass,
        permissionMatrix,
        permissionCanSave,
        isBusy,
        // methods
        closeContextMenu,
        closeModal,
        toggleSidebar,
        closeSidebar,
        clearSelection,
        openModal,
        goRoot,
        resetRootScope,
        setScopeRoot,
        openPath,
        goParent,
        goFromPathInput,
        toggleHidden,
        toggleSelectAll,
        toggleSelectPath,
        handleItemClick,
        toggleTreeNode,
        isTreeNodeActive,
        openContextMenu,
        triggerContextAction,
        openPermissionsForSelection,
        openZipForSelection,
        openUnzipForSelection,
        setPermissionPreset,
        sanitizePermissionInput,
        toggleSort,
        getSortIcon,
        handleTableDragEnter,
        handleTableDragOver,
        handleTableDragLeave,
        handleTableDrop,
        handleItemDragStart,
        handleItemDragEnd,
        handleFolderTargetDragOver,
        handleFolderTargetDragLeave,
        handleFolderTargetDrop,
        handleUploadDragOver,
        handleUploadDragLeave,
        handleUploadDrop,
        handleUploadChange,
        openFileInEditor,
        isEditableFile,
        deleteSelected,
        downloadSelected,
        submitCreateFolder,
        submitCreateFile,
        submitRename,
        submitPermissions,
        submitMove,
        submitZip,
        submitUnzip,
        submitUpload,
        submitSaveFile,
        pushToast,
        removeToast,
        formatBytes,
        iconClassForItem,
        nameClassForItem,
        typeLabelForItem,
        normalizePathValue: (value) => String(value || '').trim(),
        resolveDisplayPath: (path) => resolveDisplayPath(props.basePath, path),
    });
}
