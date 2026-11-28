import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { EDITABLE_EXTENSIONS } from '../constants/editableExtensions';
import { NON_EDITABLE_EXTENSIONS } from '../constants/nonEditableExtensions';
import { formatBytes, resolveDisplayPath } from '../helpers/fileUtils';
import { matchName } from '../helpers/fuzzySearch';
import { iconClassForItem, nameClassForItem, typeLabelForItem } from '../helpers/fileIcons';
import { getPermissionPreview } from '../helpers/permissions';
import { previewKind } from '../helpers/previewTypes';
import { i18n } from '@/i18n';

const t = (...args) => i18n.global.t(...args);

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

    const websiteLabel = computed(() => String(props.website?.domain || props.website?.name || props.website?.id || t('fileManager.manager.website')));
    const ownerRootLabel = computed(() => {
        const parts = String(props.basePath || '').replace(/\\/g, '/').split('/').filter(Boolean);
        return parts.at(-1) || t('fileManager.manager.home');
    });
    const modalType = ref('');
    const trashItems = ref([]);
    const trashLoading = ref(false);
    const trashActionId = ref('');
    const trashSelectedPaths = ref([]);
    const trashBulkActionLoading = ref(false);
    const folderSizeLoadingPath = ref('');
    const sidebarOpen = ref(true);
    const sortBy = ref('name');
    const sortDir = ref('asc');
    // The quick-search window. Typing there asks the server, the way an
    // editor's "find in files" does; it is not a filter over this listing.
    const paletteOpen = ref(false);
    const paletteQuery = ref('');
    const paletteCursor = ref(0);
    const paletteMode = ref('names');
    const paletteScope = ref('root');
    const paletteOptions = ref({
        caseSensitive: false,
        wholeWord: false,
        include: '',
        includeIgnored: false,
    });
    const paletteLoading = ref(false);
    const paletteError = ref('');
    const paletteTruncated = ref(false);
    const paletteHits = ref([]);
    const recentPaths = ref([]);
    // The line a result asked the editor to jump to, by path.
    const pendingEditorLines = ref(new Map());
    const isMobile = ref(false);
    const pathInput = ref(resolveDisplayPath(props.basePath, props.currentPath));
    const activeItemPath = ref(props.selectedFile?.path || props.items?.[0]?.path || '');
    const localItems = ref(Array.isArray(props.items) ? props.items.map((item) => ({ ...item })) : []);
    const selectedPaths = ref([]);
    const selectionAnchorPath = ref('');
    const hiddenEnabled = ref(Boolean(props.showHidden));
    const uploadDragActive = ref(false);
    const uploadProgress = ref(0);
    const uploadTaskComplete = ref(false);
    const uploadInProgress = ref(false);
    const uploadCurrentFile = ref('');
    const tableDragActive = ref(false);
    const tableDragDepth = ref(0);
    const droppedUploadHint = ref('');
    const moveDragTargetPath = ref(null);
    const draggingItemPaths = ref([]);
    // Files open in the editor, in tab order. Each one carries its own
    // buffer, so switching tabs never costs the user unsaved work.
    const openFiles = ref([]);
    const activeFilePath = ref('');
    // Set just before a navigation that is meant to overwrite a buffer (the
    // "open full file" path), where the server's copy must win over what the
    // tab already holds.
    const replaceBufferPath = ref('');
    const unzipInProgress = ref(false);
    const cacheClearInProgress = ref(false);
    const treeOpenState = ref({});
    // The server fills children only along the folder on screen, so the tree
    // is kept here and branches are fetched as they are unfolded.
    const treeNodes = ref([]);
    const treeLoadingPaths = ref([]);
    const SIDEBAR_WIDTH_KEY = 'dpanel-filemanager-sidebar-width';
    const MIN_SIDEBAR_WIDTH = 180;
    const MAX_SIDEBAR_WIDTH = 560;
    const sidebarWidth = ref(readStoredSidebarWidth());
    const saveInProgress = ref(false);
    const renameInProgress = ref(false);
    const INTERNAL_MOVE_MIME = 'application/x-dpanel-item-paths';
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

        contextMenu.value = { ...CLOSED_CONTEXT_MENU };
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

    function openPath(path) {
        showFilesTab();

        // Keep an explicit path parameter when navigating to the account root.
        // An empty query value is dropped by the router, causing the controller
        // to reopen the website's default directory instead of /home/{owner}.
        //
        // The visit keeps this component mounted: the open file tabs belong to
        // it, and browsing a folder is not a reason to throw them away.
        router.get(
            panelRoute('websites.filemanager', fileManagerRouteParams({ path: path || '.' })),
            {},
            { preserveState: true },
        );
    }

    const parentPath = computed(() => {
        const segments = String(props.currentPath || '').split('/').filter(Boolean);
        segments.pop();
        return segments.join('/');
    });

    function goParent() {
        if (!props.currentPath) return;
        openPath(parentPath.value);
    }

    function joinRelativePath(from, relative) {
        const parts = String(from || '').split('/').filter(Boolean);
        for (const part of relative.split('/')) {
            if (part === '' || part === '.') continue;
            if (part === '..') {
                parts.pop();
                continue;
            }
            parts.push(part);
        }
        return parts.join('/');
    }

    function goFromPathInput() {
        const base = String(props.basePath || '').trim().replace(/\\/g, '/').replace(/\/+$/, '');
        const entered = String(pathInput.value || '').trim().replace(/\\/g, '/').replace(/\/+$/, '');
        let relative = entered;

        if (entered === base) {
            relative = '';
        } else if (entered.startsWith(`${base}/`)) {
            relative = entered.slice(base.length + 1);
        } else if (entered.startsWith('/')) {
            pushToast(t('fileManager.manager.pathInside', { base }));
            return;
        }

        if (relative === '.' || relative === '..' || relative.startsWith('./') || relative.startsWith('../')) {
            openPath(joinRelativePath(props.currentPath, relative));
            return;
        }

        openPath(relative.replace(/^\/+/, ''));
    }

    function toggleHidden() {
        hiddenEnabled.value = !hiddenEnabled.value;
        router.patch(
            panelRoute('websites.filemanager.settings', fileManagerRouteParams()),
            {
                show_hidden: hiddenEnabled.value,
                path: props.currentPath,
                root: fileManagerRoot.value,
                file_path: activeFilePath.value || '',
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
        // The code editor handles Escape and Ctrl/Cmd+S itself while it has
        // focus, and marks those events handled. Anything it handled must
        // not be acted on twice.
        if (event.defaultPrevented) return;

        if (event.key === 'Escape') {
            closeContextMenu();
            if (paletteOpen.value) closePalette();
            else if (modalType.value) closeModal();

            return;
        }

        const mod = event.ctrlKey || event.metaKey;

        // Quick search: one window over the whole site, asked of the
        // server, opened from anywhere. Shift searches file contents.
        if (mod && !event.altKey && ['p', 'P', 'k', 'K'].includes(event.key)) {
            if (modalType.value) return;

            event.preventDefault();
            paletteOpen.value ? closePalette() : openPalette(event.shiftKey ? 'content' : 'names');

            return;
        }

        // Find in files, on the shortcut editors use for it.
        if (mod && event.shiftKey && !event.altKey && (event.key === 'f' || event.key === 'F')) {
            if (modalType.value) return;

            event.preventDefault();
            openPalette('content');

            return;
        }

        // Focus may sit anywhere on the page — the tab strip, the toolbar —
        // and the browser's "save this page" dialog is never what the user
        // meant while a file is open.
        if ((event.key === 's' || event.key === 'S') && mod && !event.altKey) {
            if (!activeFile.value || modalType.value) return;

            event.preventDefault();
            submitSaveFile();
        }
    }

    /** Normalizes a tree the server sent into the shape the sidebar walks. */
    function cloneTree(nodes) {
        if (!Array.isArray(nodes)) return [];

        return nodes
            .filter((node) => node && typeof node === 'object' && node.path)
            .map((node) => ({
                name: String(node.name || node.path.split('/').pop() || ''),
                path: String(node.path),
                hasChildren: Boolean(node.hasChildren),
                children: cloneTree(node.children),
            }));
    }

    /**
     * Folds a freshly fetched branch into the tree. Every answer comes from
     * the root with only one branch filled in, so a node whose children are
     * empty in the answer keeps the children already on screen.
     */
    function mergeTree(current, incoming) {
        const byPath = new Map((current || []).map((node) => [node.path, node]));

        return incoming.map((node) => {
            const previous = byPath.get(node.path);

            return {
                ...node,
                children: node.children.length
                    ? mergeTree(previous?.children || [], node.children)
                    : (previous?.children || []),
            };
        });
    }

    function findTreeNode(nodes, path) {
        for (const node of nodes) {
            if (node.path === path) return node;
            if (path.startsWith(node.path + '/')) {
                const found = findTreeNode(node.children, path);
                if (found) return found;
            }
        }

        return null;
    }

    /** Unfolded by default along the folder being viewed, as VS Code does. */
    function isTreeNodeOpen(path) {
        const stored = treeOpenState.value[path];
        if (typeof stored === 'boolean') return stored;

        const current = String(props.currentPath || '');

        return current === path || current.startsWith(path + '/');
    }

    function toggleTreeNode(path) {
        const open = !isTreeNodeOpen(path);
        treeOpenState.value = { ...treeOpenState.value, [path]: open };
        if (!open) return;

        const node = findTreeNode(treeNodes.value, path);
        if (node?.hasChildren && !node.children.length) {
            fetchTreeBranch(path);
        }
    }

    function fetchTreeBranch(path) {
        if (treeLoadingPaths.value.includes(path)) return;

        treeLoadingPaths.value = [...treeLoadingPaths.value, path];
        window.axios
            .get(panelRoute('websites.filemanager.tree', fileManagerRouteParams({
                path,
                show_hidden: hiddenEnabled.value,
            })), { headers: { Accept: 'application/json' } })
            .then((response) => {
                treeNodes.value = mergeTree(treeNodes.value, cloneTree(response?.data?.tree));
            })
            .catch((error) => {
                // A branch that could not be read stays folded, rather than
                // sitting open and empty.
                treeOpenState.value = { ...treeOpenState.value, [path]: false };
                pushToast(error?.response?.data?.message || t('fileManager.sidebar.treeFailed'), 'error');
            })
            .finally(() => {
                treeLoadingPaths.value = treeLoadingPaths.value.filter((entry) => entry !== path);
            });
    }

    function isTreeNodeActive(path) {
        return String(props.currentPath || '') === path;
    }

    function readStoredSidebarWidth() {
        try {
            const stored = Number.parseInt(window.localStorage.getItem(SIDEBAR_WIDTH_KEY) || '', 10);
            if (Number.isFinite(stored)) return clampSidebarWidth(stored);
        } catch {
            // Blocked storage: the default width stands.
        }

        return 240;
    }

    function clampSidebarWidth(width) {
        return Math.min(MAX_SIDEBAR_WIDTH, Math.max(MIN_SIDEBAR_WIDTH, Math.round(width)));
    }

    function setSidebarWidth(width) {
        sidebarWidth.value = clampSidebarWidth(width);
    }

    /** Called when the drag ends, so a drag writes to storage once. */
    function persistSidebarWidth() {
        try {
            window.localStorage.setItem(SIDEBAR_WIDTH_KEY, String(sidebarWidth.value));
        } catch {
            // Blocked storage: the width lasts for this visit only.
        }
    }

    const CLOSED_CONTEXT_MENU = {
        visible: false,
        x: 0,
        y: 0,
        // 'item' is a right-click on a row or a sidebar folder; 'background'
        // is a right-click on empty space, which acts on the folder itself.
        kind: 'item',
        itemPath: '',
        itemType: '',
        itemName: '',
        count: 0,
    };

    const contextMenu = ref({ ...CLOSED_CONTEXT_MENU });

    /** Right-clicking a folder in the sidebar opens the same menu. */
    function openTreeContextMenu(node, event) {
        if (!node?.path) return;

        openContextMenu({ path: node.path, name: node.name, type: 'dir' }, event);
    }

    const contextItem = computed(() => {
        const menu = contextMenu.value;
        if (!menu.itemPath) return null;

        // A folder right-clicked in the sidebar is not in the listing on
        // screen, so the menu's own record of it stands in.
        return localItems.value.find((item) => item.path === menu.itemPath) || {
            path: menu.itemPath,
            name: menu.itemName,
            type: menu.itemType,
        };
    });
    const contextArchive = computed(() => contextItem.value?.type === 'file'
        && /\.(?:zip|tar|tar\.gz|tgz|gz)$/i.test(String(contextItem.value?.name || '')));

    function openContextMenu(item, event) {
        if (!selectedPaths.value.includes(item.path)) {
            selectedPaths.value = [item.path];
            activeItemPath.value = item.path;
        }

        // The menu measures itself once it is on screen; these are the raw
        // pointer coordinates.
        contextMenu.value = {
            visible: true,
            x: event.clientX,
            y: event.clientY,
            kind: 'item',
            itemPath: item.path,
            itemType: item.type,
            itemName: item.name,
            count: Math.max(1, selectedPaths.value.length),
        };
    }

    /** Right-clicking empty space in the listing acts on the folder shown. */
    function openBackgroundContextMenu(event) {
        contextMenu.value = {
            visible: true,
            x: event.clientX,
            y: event.clientY,
            kind: 'background',
            itemPath: '',
            itemType: 'dir',
            itemName: String(props.currentPath || '').split('/').filter(Boolean).pop() || ownerRootLabel.value,
            count: 0,
        };
    }

    function triggerBackgroundAction(action) {
        closeContextMenu();

        switch (action) {
            case 'create-folder':
                openModal('create-folder');
                break;
            case 'create-file':
                openModal('create-file');
                break;
            case 'upload':
                openModal('upload');
                break;
            case 'select-all':
                toggleSelectAll(true);
                break;
            case 'copy-path':
                copyToClipboard(resolveDisplayPath(props.basePath, props.currentPath));
                break;
            case 'copy-relative-path':
                copyToClipboard(String(props.currentPath || ''));
                break;
            case 'refresh':
                refreshListing();
                break;
        }
    }

    /** Re-reads the folder without touching the open tabs or the selection. */
    function refreshListing() {
        router.reload({ only: ['items', 'directoryTree'] });
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

    function openCopyForSelection() {
        const paths = selectedPathList.value.length
            ? selectedPathList.value
            : (singleSelectedItem.value ? [singleSelectedItem.value.path] : []);
        if (!paths.length) return;

        copyForm.item_path = paths[0];
        copyForm.item_paths = paths;
        copyForm.destination_path = props.currentPath;
        copyForm.new_name = paths.length === 1 ? String(paths[0]).split('/').pop() : '';
        openModal('copy');
    }

    function submitCopy() {
        copyForm.current_path = props.currentPath;
        copyForm.patch(panelRoute('websites.filemanager.item.copy', fileManagerRouteParams()), {
            onSuccess: () => {
                copyForm.destination_path = props.currentPath;
                copyForm.new_name = '';
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
        // The editor shares the drop target with the file list; dropping a
        // file on open code is not an upload.
        if (activeFilePath.value) return;
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

        if (activeFilePath.value) return;

        if (event.dataTransfer?.types?.includes(INTERNAL_MOVE_MIME)) return;

        const files = event.dataTransfer?.files;
        if (!files || files.length === 0) return;

        uploadForm.path = props.currentPath;
        uploadForm.uploads = Array.from(files);
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
        uploadForm.uploads = Array.from(files);
    }

    function handleUploadChange(event) {
        const files = event.target?.files;
        if (!files || files.length === 0) return;
        uploadForm.uploads = Array.from(files);
    }

    function isEditableFile(filename) {
        const name = String(filename || '').toLowerCase();
        const ext = name.split('.').pop() || '';

        if (NON_EDITABLE_EXTENSIONS.includes(ext)) return false;
        if (EDITABLE_EXTENSIONS.includes(ext)) return true;

        const basename = name.split('/').pop() || '';
        if (basename.startsWith('.env') || basename.startsWith('.htaccess')) return true;

        // Unknown extensions and extension-less config files are allowed to
        // reach the Rust reader. It performs the authoritative UTF-8/binary
        // check; only known binary formats are blocked client-side.
        return true;
    }

    const previewItem = ref(null);

    function openPreview(item) {
        if (item?.type === 'file' && previewKind(item.name)) previewItem.value = item;
    }

    function closePreview() {
        previewItem.value = null;
    }

    watch(() => props.currentPath, closePreview);

    const SQLITE_EXTENSIONS = ['sqlite', 'sqlite3', 'db', 'db3', 's3db', 'sl3'];

    // The file open in the read-only SQLite offcanvas.
    const sqliteItem = ref(null);

    function openSqlite(item) {
        if (item?.type === 'file' && isSqliteFile(item.name)) sqliteItem.value = item;
    }

    function closeSqlite() {
        sqliteItem.value = null;
    }

    watch(() => props.currentPath, closeSqlite);

    function isSqliteFile(filename) {
        const name = String(filename || '').toLowerCase();
        return name.includes('.') && SQLITE_EXTENSIONS.includes(name.split('.').pop());
    }

    function openFileInEditor(path) {
        const filename = path.split('/').pop() || '';

        if (isSqliteFile(filename)) {
            openSqlite(localItems.value.find((item) => item.path === path) || { path, name: filename, type: 'file' });
            return;
        }

        if (previewKind(filename)) {
            openPreview(localItems.value.find((item) => item.path === path) || { path, name: filename, type: 'file', size: null });
            return;
        }

        if (!isEditableFile(filename)) {
            pushToast(t('fileManager.manager.cannotEdit', { filename }), 'info');
            return;
        }

        if (openFiles.value.some((entry) => entry.path === path)) {
            activateTab(path);
            return;
        }

        // A partial visit: the directory listing and tree on screen are
        // already right, so the server only sends back the file itself.
        router.get(
            panelRoute('websites.filemanager', fileManagerRouteParams({
                path: props.currentPath,
                file_path: path,
            })),
            {},
            { preserveState: true, preserveScroll: true, only: ['selectedFile'] },
        );
    }

    const MAX_OPEN_FILES = 12;

    /** Adds the file the server just sent as a tab, or refreshes its tab. */
    function upsertOpenFile(file) {
        if (!file?.path) return;

        const content = file.content ?? '';
        const existing = openFiles.value.find((entry) => entry.path === file.path);

        if (existing) {
            // Reopening a file the user has edited keeps their buffer. The
            // one exception is "open full file", where the server's larger
            // copy is the whole point.
            const dirty = existing.content !== existing.original;
            if (replaceBufferPath.value === file.path || !dirty) {
                existing.content = content;
                existing.original = content;
            }
            existing.readonly = Boolean(file.readonly);
            existing.partial = Boolean(file.partial);
            existing.can_load_full = Boolean(file.can_load_full);
            existing.message = file.message || '';
            existing.size = file.size ?? null;
        } else {
            openFiles.value.push({
                path: file.path,
                name: file.name || file.path.split('/').pop() || 'untitled',
                content,
                original: content,
                readonly: Boolean(file.readonly),
                partial: Boolean(file.partial),
                can_load_full: Boolean(file.can_load_full),
                message: file.message || '',
                size: file.size ?? null,
                gotoLine: 0,
            });
            pruneOpenFiles(file.path);
        }

        replaceBufferPath.value = '';
        activeFilePath.value = file.path;
        rememberRecentPath(file.path);
        applyPendingLine(file.path);
    }

    /** File contents are held in memory; the oldest saved tab gives way. */
    function pruneOpenFiles(keepPath) {
        while (openFiles.value.length > MAX_OPEN_FILES) {
            const index = openFiles.value.findIndex((entry) => (
                entry.path !== keepPath && entry.content === entry.original
            ));
            if (index === -1) return;

            openFiles.value.splice(index, 1);
        }
    }

    function activateTab(path) {
        if (!openFiles.value.some((entry) => entry.path === path)) return;

        activeFilePath.value = path;
        applyPendingLine(path);
        syncEditorUrl(path);
    }

    /** The directory listing: tab zero, and what an empty path means. */
    function showFilesTab() {
        activeFilePath.value = '';
        syncEditorUrl('');
    }

    /** Keeps ?file_path= on the tab in front, so a reload reopens it. */
    function syncEditorUrl(path) {
        const url = new URL(window.location.href);
        if ((url.searchParams.get('file_path') || '') === path) return;

        if (path) {
            url.searchParams.set('file_path', path);
        } else {
            url.searchParams.delete('file_path');
        }
        url.searchParams.delete('full');
        window.history.replaceState(window.history.state, '', url.toString());
    }

    function isFileOpen(path) {
        return openFiles.value.some((entry) => entry.path === path);
    }

    function closeTab(path) {
        const index = openFiles.value.findIndex((entry) => entry.path === path);
        if (index === -1) return;

        const file = openFiles.value[index];
        if (file.content !== file.original
            && !window.confirm(t('fileManager.editor.confirmClose', { name: file.name }))) {
            return;
        }

        openFiles.value.splice(index, 1);
        if (activeFilePath.value !== path) return;

        // Fall to the tab that took this one's place, else the one before it.
        const next = openFiles.value[index] || openFiles.value[index - 1] || null;
        if (!next) {
            showFilesTab();
            return;
        }

        activeFilePath.value = next.path;
        syncEditorUrl(next.path);
    }

    function loadFullFile() {
        const file = activeFile.value;
        if (!file?.path || !file.partial || !file.can_load_full) return;

        const sizeLabel = `${(Number(file.size || 0) / (1024 * 1024)).toFixed(1)}MB`;
        if (!confirm(t('fileManager.manager.largeFileConfirm', { name: file.name, size: sizeLabel }))) return;

        replaceBufferPath.value = file.path;
        router.get(
            panelRoute('websites.filemanager', fileManagerRouteParams({
                path: props.currentPath,
                file_path: file.path,
                full: 1,
            })),
            {},
            { preserveState: true, preserveScroll: true, only: ['selectedFile'] },
        );
    }

    function downloadItem(item) {
        if (item?.type !== 'file') return;
        window.location.href = panelRoute('websites.filemanager.file.download', fileManagerRouteParams({ file_path: item.path }));
    }

    function downloadSelected() {
        downloadItem(singleSelectedItem.value);
    }

    function calculateFolderSize(item) {
        if (!item || item.type !== 'dir' || folderSizeLoadingPath.value === item.path) return;
        folderSizeLoadingPath.value = item.path;
        window.axios
            .get(panelRoute('websites.filemanager.folder-size', fileManagerRouteParams({ item_path: item.path })), {
                headers: { Accept: 'application/json' },
            })
            .then((response) => {
                const size = Number(response?.data?.size ?? 0);
                localItems.value = localItems.value.map((entry) => (entry.path === item.path ? { ...entry, size } : entry));
            })
            .catch((error) => {
                pushToast(error?.response?.data?.message || t('fileManager.manager.sizeFailed'), 'error');
            })
            .finally(() => {
                folderSizeLoadingPath.value = '';
            });
    }

    function deleteSelected() {
        const paths = selectedPaths.value.length ? selectedPaths.value : (singleSelectedItem.value ? [singleSelectedItem.value.path] : []);
        if (!paths.length) return;

        if (!confirm(t('fileManager.manager.trashConfirm', { count: paths.length }))) return;

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
        if (renameInProgress.value) return;

        const sourcePath = String(renameForm.item_path || '');
        const sourceItem = localItems.value.find((item) => item.path === sourcePath);
        const newName = String(renameForm.new_name || '').trim();
        if (!sourceItem || newName === '') return;
        if (newName === sourceItem.name) {
            closeModal();
            return;
        }

        renameForm.current_path = props.currentPath;
        renameInProgress.value = true;
        window.axios
            .patch(panelRoute('websites.filemanager.item.rename', fileManagerRouteParams()), {
                item_path: sourcePath,
                current_path: props.currentPath,
                new_name: newName,
            }, {
                headers: { Accept: 'application/json' },
            })
            .then((response) => {
                const parent = sourcePath.includes('/') ? sourcePath.slice(0, sourcePath.lastIndexOf('/')) : '';
                const targetPath = String(response?.data?.path || [parent, newName].filter(Boolean).join('/'));
                localItems.value = localItems.value.map((item) => (
                    item.path === sourcePath
                        ? { ...item, name: newName, path: targetPath, modified_at: new Date().toISOString() }
                        : item
                ));
                activeItemPath.value = activeItemPath.value === sourcePath ? targetPath : activeItemPath.value;
                selectedPaths.value = selectedPaths.value.map((path) => path === sourcePath ? targetPath : path);
                selectionAnchorPath.value = selectionAnchorPath.value === sourcePath ? targetPath : selectionAnchorPath.value;
                renameForm.new_name = '';
                closeModal();
                pushToast(response?.data?.message || t('fileManager.manager.renamed'), 'success');
            })
            .catch((error) => {
                pushToast(error?.response?.data?.message || t('fileManager.manager.renameFailed'), 'error');
            })
            .finally(() => {
                renameInProgress.value = false;
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

    function loadTrash() {
        trashLoading.value = true;
        trashSelectedPaths.value = [];
        window.axios
            .get(panelRoute('websites.filemanager.trash.index', fileManagerRouteParams()), {
                headers: { Accept: 'application/json' },
            })
            .then((response) => {
                trashItems.value = Array.isArray(response?.data?.items) ? response.data.items : [];
            })
            .catch((error) => {
                pushToast(error?.response?.data?.message || t('fileManager.manager.trashLoadFailed'), 'error');
            })
            .finally(() => {
                trashLoading.value = false;
            });
    }

    function openTrash() {
        openModal('trash');
        loadTrash();
    }

    function restoreTrashItem(trashPath) {
        if (trashActionId.value) return;
        trashActionId.value = trashPath;
        window.axios
            .post(panelRoute('websites.filemanager.trash.restore', fileManagerRouteParams()), { trash_path: trashPath }, {
                headers: { Accept: 'application/json' },
            })
            .then((response) => {
                pushToast(response?.data?.message || t('fileManager.manager.restored'), 'success');
                trashItems.value = trashItems.value.filter((item) => item.trash_path !== trashPath);
                router.reload({ only: ['items'] });
            })
            .catch((error) => {
                pushToast(error?.response?.data?.message || t('fileManager.manager.restoreFailed'), 'error');
            })
            .finally(() => {
                trashActionId.value = '';
            });
    }

    function destroyTrashItem(trashPath) {
        if (trashActionId.value) return;
        if (!confirm(t('fileManager.manager.purgeOneConfirm'))) return;
        trashActionId.value = trashPath;
        window.axios
            .delete(panelRoute('websites.filemanager.trash.destroy', fileManagerRouteParams()), {
                headers: { Accept: 'application/json' },
                data: { trash_path: trashPath },
            })
            .then((response) => {
                pushToast(response?.data?.message || t('fileManager.manager.purged'), 'success');
                trashItems.value = trashItems.value.filter((item) => item.trash_path !== trashPath);
                trashSelectedPaths.value = trashSelectedPaths.value.filter((path) => path !== trashPath);
            })
            .catch((error) => {
                pushToast(error?.response?.data?.message || t('fileManager.manager.deleteFailed'), 'error');
            })
            .finally(() => {
                trashActionId.value = '';
            });
    }

    function toggleTrashSelection(trashPath) {
        trashSelectedPaths.value = trashSelectedPaths.value.includes(trashPath)
            ? trashSelectedPaths.value.filter((path) => path !== trashPath)
            : [...trashSelectedPaths.value, trashPath];
    }

    function toggleSelectAllTrash() {
        trashSelectedPaths.value = trashSelectedPaths.value.length === trashItems.value.length
            ? []
            : trashItems.value.map((item) => item.trash_path);
    }

    function destroySelectedTrashItems() {
        if (trashBulkActionLoading.value || !trashSelectedPaths.value.length) return;
        const paths = trashSelectedPaths.value;
        if (!confirm(t('fileManager.manager.purgeManyConfirm', { count: paths.length }))) return;

        trashBulkActionLoading.value = true;
        window.axios
            .delete(panelRoute('websites.filemanager.trash.destroy', fileManagerRouteParams()), {
                headers: { Accept: 'application/json' },
                data: { trash_paths: paths },
            })
            .then((response) => {
                pushToast(response?.data?.message || t('fileManager.manager.purged'), 'success');
                const deleted = Array.isArray(response?.data?.deleted) ? response.data.deleted : paths;
                trashItems.value = trashItems.value.filter((item) => !deleted.includes(item.trash_path));
                trashSelectedPaths.value = trashSelectedPaths.value.filter((path) => !deleted.includes(path));
            })
            .catch((error) => {
                pushToast(error?.response?.data?.message || t('fileManager.manager.deleteSelectedFailed'), 'error');
            })
            .finally(() => {
                trashBulkActionLoading.value = false;
            });
    }

    function submitZip() {
        zipForm.current_path = props.currentPath;
        zipForm.post(panelRoute('websites.filemanager.zip', fileManagerRouteParams()), {
            onSuccess: () => {
                zipForm.zip_name = '';
                zipForm.archive_format = 'zip';
                selectedPaths.value = [];
                closeModal();
            },
        });
    }

    function updateArchiveFormat(format) {
        const suffix = format === 'tar.gz' ? '.tar.gz' : `.${format}`;
        const name = String(zipForm.zip_name || '');
        const baseName = name.replace(/\.(?:tar\.gz|zip|tar)$/i, '');
        zipForm.zip_name = `${baseName || 'archive'}${suffix}`;
    }

    function submitUnzip() {
        if (unzipInProgress.value || !unzipForm.zip_path) return;

        unzipForm.current_path = props.currentPath;
        unzipInProgress.value = true;
        window.axios
            .post(panelRoute('websites.filemanager.unzip', fileManagerRouteParams()), {
                zip_path: unzipForm.zip_path,
                current_path: unzipForm.current_path,
                destination_path: unzipForm.destination_path,
            }, {
                headers: {
                    Accept: 'application/json',
                },
            })
            .then((response) => {
                const message = response?.data?.message || t('fileManager.manager.extracted');
                pushToast(message, 'success');
                closeModal();
                router.get(panelRoute('websites.filemanager', fileManagerRouteParams({ path: props.currentPath })), {}, {
                    preserveScroll: true,
                    replace: true,
                });
            })
            .catch((error) => {
                const message = error?.response?.data?.message || t('fileManager.manager.extractFailed');
                pushToast(message, 'error');
            })
            .finally(() => {
                unzipInProgress.value = false;
            });
    }

    // Each file goes up in slices: the edge gateway caps a PHP request body at
    // 64 MiB, so a single multipart request fails for anything larger.
    async function postChunkWithRetry(url, form, onProgress) {
        for (let attempt = 1; ; attempt += 1) {
            try {
                return await window.axios.post(url, form, { onUploadProgress: onProgress });
            } catch (error) {
                const status = error?.response?.status;
                if (attempt >= 3 || (status && status < 500 && status !== 429)) throw error;
                await new Promise((resolve) => setTimeout(resolve, attempt * 2000));
            }
        }
    }

    async function uploadOneFile(file, onBytes) {
        const started = await window.axios.post(panelRoute('websites.filemanager.upload.start', fileManagerRouteParams()), {
            path: props.currentPath,
            name: file.name,
            size: file.size,
        });
        const uploadId = started.data.upload_id;
        const chunkSize = started.data.chunk_size;
        const total = Math.max(1, Math.ceil(file.size / chunkSize));
        try {
            for (let index = 0; index < total; index += 1) {
                const form = new FormData();
                form.append('index', String(index));
                form.append('chunk', file.slice(index * chunkSize, Math.min(file.size, (index + 1) * chunkSize)), `${index}.part`);
                const chunkStart = index * chunkSize;
                await postChunkWithRetry(
                    panelRoute('websites.filemanager.upload.chunk', fileManagerRouteParams({ uploadId })),
                    form,
                    (event) => onBytes(chunkStart + Math.min(event.loaded || 0, chunkSize)),
                );
            }
            await window.axios.post(panelRoute('websites.filemanager.upload.complete', fileManagerRouteParams({ uploadId })));
        } catch (error) {
            window.axios.delete(panelRoute('websites.filemanager.upload.cancel', fileManagerRouteParams({ uploadId }))).catch(() => {});
            throw error;
        }
    }

    async function submitUpload() {
        const files = Array.isArray(uploadForm.uploads) ? [...uploadForm.uploads] : [];
        if (uploadInProgress.value || files.length === 0) return;

        uploadInProgress.value = true;
        uploadProgress.value = 0;
        uploadTaskComplete.value = false;
        const totalBytes = files.reduce((sum, file) => sum + file.size, 0) || 1;
        let doneBytes = 0;
        let uploaded = 0;
        try {
            for (const file of files) {
                uploadCurrentFile.value = file.name;
                await uploadOneFile(file, (fileBytes) => {
                    uploadProgress.value = Math.min(99, Math.round(((doneBytes + fileBytes) / totalBytes) * 100));
                });
                doneBytes += file.size;
                uploaded += 1;
            }
            uploadTaskComplete.value = true;
            uploadProgress.value = 100;
            uploadForm.uploads = [];
            pushToast(uploaded > 1 ? t('fileManager.manager.uploadedMany', { count: uploaded }) : t('fileManager.manager.uploadedOne'), 'success');
        } catch (error) {
            const errors = error?.response?.data?.errors;
            const message = errors ? Object.values(errors).flat().join(' ') : (error?.response?.data?.message || t('fileManager.manager.uploadFailed'));
            pushToast(`${uploadCurrentFile.value}: ${message}`, 'error');
            uploadForm.uploads = files.slice(uploaded);
        } finally {
            uploadInProgress.value = false;
            uploadCurrentFile.value = '';
            if (uploaded > 0) {
                router.get(panelRoute('websites.filemanager', fileManagerRouteParams({ path: props.currentPath })), {}, {
                    preserveScroll: true,
                    preserveState: true,
                    replace: true,
                });
            }
        }
    }

    function clearProjectCache() {
        if (cacheClearInProgress.value || !props.hasProjectArtisan) return;

        cacheClearInProgress.value = true;
        window.axios
            .post(panelRoute('websites.project-cache.clear', { id: props.website.id }), {}, {
                headers: {
                    Accept: 'application/json',
                },
            })
            .then((response) => {
                pushToast(response?.data?.message || t('fileManager.manager.cacheCleared'), 'success');
            })
            .catch((error) => {
                const message = error?.response?.data?.message || t('fileManager.manager.cacheFailed');
                pushToast(message, 'error');
            })
            .finally(() => {
                cacheClearInProgress.value = false;
            });
    }

    function submitSaveFile() {
        const file = activeFile.value;
        if (saveInProgress.value || !file || file.readonly || file.content === file.original) return;

        const filePath = file.path;
        const content = file.content;

        saveInProgress.value = true;
        window.axios
            .patch(panelRoute('websites.filemanager.file.save', fileManagerRouteParams()), {
                file_path: filePath,
                content,
            }, {
                headers: {
                    Accept: 'application/json',
                },
            })
            .then((response) => {
                // Only what actually went to the server counts as saved: the
                // user may have typed on while the request was in flight.
                const entry = openFiles.value.find((item) => item.path === filePath);
                if (entry) entry.original = content;
                const message = response?.data?.message || t('fileManager.manager.saved');
                pushToast(message, 'success');
            })
            .catch((error) => {
                const message = error?.response?.data?.message || t('fileManager.manager.saveFailed');
                pushToast(message, 'error');
            })
            .finally(() => {
                saveInProgress.value = false;
            });
    }

    /**
     * execCommand is the fallback: the clipboard API needs a secure context,
     * and a panel reached over plain http would otherwise have no copy.
     */
    async function copyToClipboard(text) {
        const value = String(text || '');

        try {
            if (window.navigator?.clipboard?.writeText) {
                await window.navigator.clipboard.writeText(value);
            } else {
                const field = document.createElement('textarea');
                field.value = value;
                field.setAttribute('readonly', '');
                field.style.position = 'fixed';
                field.style.top = '-1000px';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                const copied = document.execCommand('copy');
                field.remove();
                if (!copied) throw new Error('copy rejected');
            }

            pushToast(t('fileManager.context.pathCopied', { path: value }), 'success');
        } catch {
            pushToast(t('fileManager.context.copyFailed'), 'error');
        }
    }

    function triggerContextAction(action) {
        const item = contextItem.value;
        closeContextMenu();
        if (!item) return;

        switch (action) {
            case 'open':
                item.type === 'dir' ? openPath(item.path) : openFileInEditor(item.path);
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
                // A folder picked from the sidebar carries no mode of its own.
                permissionForm.permissions = String(item.permissions || (item.type === 'dir' ? '755' : '644'));
                openModal('permissions');
                break;
            case 'move':
                moveForm.item_path = item.path;
                moveForm.item_paths = selectedPaths.value.length ? selectedPaths.value : [item.path];
                moveForm.destination_path = props.currentPath;
                openModal('move');
                break;
            case 'copy': {
                const copyPaths = selectedPaths.value.length ? selectedPaths.value : [item.path];
                copyForm.item_path = item.path;
                copyForm.item_paths = copyPaths;
                copyForm.destination_path = props.currentPath;
                copyForm.new_name = copyPaths.length === 1 ? item.name : '';
                openModal('copy');
                break;
            }
            case 'zip':
                zipForm.item_paths = selectedPaths.value.length ? selectedPaths.value : [item.path];
                zipForm.archive_format = 'zip';
                zipForm.zip_name = item.type === 'dir' ? `${item.name}.zip` : `${item.name.replace(/\.[^.]+$/, '')}.zip`;
                openModal('zip');
                break;
            case 'unzip':
                unzipForm.zip_path = item.path;
                unzipForm.destination_path = props.currentPath || '';
                openModal('unzip');
                break;
            case 'copy-path':
                copyToClipboard(resolveDisplayPath(props.basePath, item.path));
                break;
            case 'copy-relative-path':
                copyToClipboard(item.path);
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
        zipForm.archive_format = 'zip';
        const sourceItem = localItems.value.find((item) => item.path === paths[0]) || singleSelectedItem.value;
        zipForm.zip_name = sourceItem
            ? (sourceItem.type === 'dir'
                ? `${sourceItem.name}.zip`
                : `${sourceItem.name.replace(/\.[^.]+$/, '')}.zip`)
            : 'archive.zip';
        openModal('zip');
    }

    function openUnzipForSelection() {
        if (!isSupportedArchiveSelected.value || !singleSelectedItem.value) return;

        unzipForm.zip_path = singleSelectedItem.value.path;
        unzipForm.destination_path = props.currentPath || '';
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
    const deleteForm = useForm({ item_paths: [], current_path: props.currentPath });
    const uploadForm = useForm({ path: props.currentPath, uploads: [] });
    const permissionForm = useForm({ item_path: '', current_path: props.currentPath, permissions: '644', recursive: false });
    const renameForm = useForm({ item_path: '', current_path: props.currentPath, new_name: '' });
    const zipForm = useForm({ current_path: props.currentPath, item_paths: [], zip_name: '', archive_format: 'zip' });
    const unzipForm = useForm({ zip_path: '', current_path: props.currentPath, destination_path: '' });
    const moveForm = useForm({ item_path: '', item_paths: [], current_path: props.currentPath, destination_path: props.currentPath });
    const copyForm = useForm({ item_path: '', item_paths: [], current_path: props.currentPath, destination_path: props.currentPath, new_name: '' });

    const selectedCount = computed(() => selectedPaths.value.length);
    const selectedPathList = computed(() => Array.isArray(selectedPaths.value) ? selectedPaths.value : []);
    const isAllItemsSelected = computed(() => (
        filteredItems.value.length > 0 &&
        filteredItems.value.every((item) => selectedPathList.value.includes(item.path))
    ));
    const isItemSelected = (path) => selectedPathList.value.includes(path);
    const singleSelectedItem = computed(() => {
        if (selectedPaths.value.length !== 1) return null;
        return localItems.value.find((item) => item.path === selectedPaths.value[0]) || null;
    });
    const isSupportedArchiveSelected = computed(() => {
        if (!singleSelectedItem.value) return false;
        return singleSelectedItem.value.type === 'file'
            && /\.(?:zip|tar|tar\.gz|tgz|gz)$/i.test(String(singleSelectedItem.value.name || ''));
    });

    const quickActionsGroups = computed(() => ([
        {
            label: t('fileManager.quick.groups.create'),
            items: [
                { label: t('fileManager.quick.upload'), hint: t('fileManager.quick.uploadHint'), icon: 'bi-cloud-arrow-up', className: 'text-blue-500', action: () => openModal('upload'), visible: true },
                { label: t('fileManager.quick.newFile'), hint: t('fileManager.quick.newFileHint'), icon: 'bi-file-earmark-plus', className: 'text-emerald-500', action: () => openModal('create-file'), visible: true },
                { label: t('fileManager.quick.newFolder'), hint: t('fileManager.quick.newFolderHint'), icon: 'bi-folder-plus', className: 'text-amber-500', action: () => openModal('create-folder'), visible: true },
            ],
        },
        {
            label: t('fileManager.quick.groups.fileOps'),
            items: [
                { label: t('fileManager.quick.permissions'), hint: t('fileManager.quick.permissionsHint'), icon: 'bi-shield-lock', className: 'text-purple-500', action: () => openPermissionsForSelection(), visible: !!singleSelectedItem.value },
                { label: t('fileManager.quick.download'), hint: t('fileManager.quick.downloadHint'), icon: 'bi-download', className: 'text-slate-500', action: () => downloadSelected(), visible: !!singleSelectedItem.value && singleSelectedItem.value.type === 'file' },
                { label: t('fileManager.quick.zip'), hint: t('fileManager.quick.zipHint'), icon: 'bi-file-earmark-zip', className: 'text-amber-500', action: () => openZipForSelection(), visible: !!singleSelectedItem.value || selectedCount.value > 0 },
                { label: t('fileManager.quick.extract'), hint: t('fileManager.quick.extractHint'), icon: 'bi-file-earmark-arrow-up', className: 'text-green-500', action: () => openUnzipForSelection(), visible: isSupportedArchiveSelected.value },
                { label: cacheClearInProgress.value ? t('fileManager.quick.clearing') : t('fileManager.quick.clearCache'), hint: t('fileManager.quick.clearCacheHint'), icon: 'bi-lightning-charge', className: 'text-orange-500', action: () => clearProjectCache(), disabled: cacheClearInProgress.value, visible: !!props.hasProjectArtisan },
            ],
        },
        {
            label: t('fileManager.quick.groups.navigation'),
            items: [
                { label: t('fileManager.quick.upLevel'), hint: t('fileManager.quick.upLevelHint'), icon: 'bi-arrow-up', className: 'text-slate-500', action: () => goParent(), visible: !!props.currentPath },
                { label: hiddenEnabled.value ? t('fileManager.quick.hideHidden') : t('fileManager.quick.showHidden'), hint: t('fileManager.quick.hiddenHint'), icon: hiddenEnabled.value ? 'bi-eye-slash' : 'bi-eye', className: 'text-slate-500', action: () => toggleHidden(), visible: true },
                { label: t('fileManager.quick.trash'), hint: t('fileManager.quick.trashHint'), icon: 'bi-trash3', className: 'text-slate-500', action: () => openTrash(), visible: true },
            ],
        },
        {
            label: t('fileManager.quick.groups.danger'),
            items: [
                { label: t('fileManager.quick.delete'), hint: t('fileManager.quick.deleteHint'), icon: 'bi-trash', className: 'text-red-500', action: () => deleteSelected(), danger: true, visible: !!singleSelectedItem.value || selectedCount.value > 0 },
            ],
        },
    ]).map((group) => ({
        ...group,
        items: Array.isArray(group.items) ? group.items.filter((item) => item.visible) : [],
    })));

    /**
     * The quick-search window. The walk itself happens in drust, the only
     * part of the stack that can read a site owner's files, so the window
     * reaches the whole account and not just what has been browsed.
     */
    const paletteTerm = computed(() => paletteQuery.value.trim());
    const paletteContentMode = computed(() => paletteMode.value === 'content');

    /**
     * One flat list of rows the arrow keys walk. In file mode a row is a
     * file or folder; in text mode a row is a single matching line, the way
     * an editor's "find in files" lists them.
     */
    const paletteResults = computed(() => {
        const term = paletteTerm.value;

        if (!term) {
            // Nothing typed yet: what is open, then what was opened recently.
            const seen = new Set();
            const rows = [];

            for (const file of openFiles.value) {
                seen.add(file.path);
                rows.push({ path: file.path, name: file.name, type: 'file', group: 'open' });
            }
            for (const path of recentPaths.value) {
                if (seen.has(path)) continue;

                seen.add(path);
                rows.push({ path, name: path.split('/').pop() || path, type: 'file', group: 'recent' });
            }

            return rows.slice(0, 12);
        }

        const openPaths = new Set(openFiles.value.map((file) => file.path));

        if (paletteContentMode.value) {
            const rows = [];
            for (const hit of paletteHits.value) {
                for (const match of (hit.matches || [])) {
                    rows.push({
                        path: hit.path,
                        name: hit.name,
                        type: 'file',
                        group: 'text',
                        line: match.line,
                        column: match.column,
                        length: match.length,
                        text: match.text,
                    });
                }
            }

            return rows;
        }

        return paletteHits.value.map((hit) => ({
            path: hit.path,
            name: hit.name,
            type: hit.type,
            // The server ranked these; the ranges are worked out here so the
            // window can show which characters earned the match.
            ranges: matchName(hit.name, term)?.ranges || [],
            group: openPaths.has(hit.path) ? 'open' : (hit.type === 'dir' ? 'dir' : 'file'),
        }));
    });

    let paletteTimer = null;
    let paletteRequest = null;

    function schedulePaletteSearch() {
        if (paletteTimer) clearTimeout(paletteTimer);

        if (!paletteTerm.value) {
            paletteRequest?.abort();
            paletteHits.value = [];
            paletteLoading.value = false;
            paletteError.value = '';
            paletteTruncated.value = false;

            return;
        }

        paletteLoading.value = true;
        // Typing should not fire a walk per keystroke.
        paletteTimer = setTimeout(runPaletteSearch, 220);
    }

    function runPaletteSearch() {
        const term = paletteTerm.value;
        if (!term) return;

        paletteRequest?.abort();
        const controller = new AbortController();
        paletteRequest = controller;

        const options = paletteOptions.value;
        window.axios
            .get(panelRoute('websites.filemanager.search', fileManagerRouteParams({
                query: term,
                mode: paletteMode.value,
                path: paletteScope.value === 'folder' ? (props.currentPath || '') : '',
                include: options.include || '',
                case_sensitive: options.caseSensitive,
                whole_word: options.wholeWord,
                include_ignored: options.includeIgnored,
                show_hidden: hiddenEnabled.value,
            })), { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then((response) => {
                paletteHits.value = Array.isArray(response?.data?.results) ? response.data.results : [];
                paletteTruncated.value = Boolean(response?.data?.truncated);
                paletteError.value = '';
                paletteCursor.value = 0;
            })
            .catch((error) => {
                // A superseded keystroke is not a failure.
                if (controller.signal.aborted) return;

                paletteHits.value = [];
                paletteError.value = error?.response?.data?.message || t('fileManager.palette.failed');
            })
            .finally(() => {
                if (paletteRequest === controller) {
                    paletteRequest = null;
                    paletteLoading.value = false;
                }
            });
    }

    watch([paletteQuery, paletteMode, paletteScope, paletteOptions], () => {
        paletteCursor.value = 0;
        if (paletteOpen.value) schedulePaletteSearch();
    }, { deep: true });

    function openPalette(mode) {
        if (mode) paletteMode.value = mode;
        paletteQuery.value = '';
        paletteHits.value = [];
        paletteCursor.value = 0;
        paletteError.value = '';
        paletteTruncated.value = false;
        paletteOpen.value = true;
    }

    function closePalette() {
        paletteOpen.value = false;
        if (paletteTimer) clearTimeout(paletteTimer);
        paletteRequest?.abort();
        paletteLoading.value = false;
    }

    function setPaletteMode(mode) {
        paletteMode.value = mode === 'content' ? 'content' : 'names';
    }

    function movePaletteCursor(delta) {
        const total = paletteResults.value.length;
        if (!total) return;

        paletteCursor.value = (paletteCursor.value + delta + total) % total;
    }

    function runPaletteEntry(entry) {
        const target = entry || paletteResults.value[paletteCursor.value];
        if (!target) return;

        closePalette();
        if (target.type === 'dir') {
            openPath(target.path);

            return;
        }

        if (target.line) {
            // A text hit opens the file at the line it was found on.
            const lines = new Map(pendingEditorLines.value);
            lines.set(target.path, target.line);
            pendingEditorLines.value = lines;
        }

        rememberRecentPath(target.path);
        openFileInEditor(target.path);
    }

    /**
     * Moves a pending jump onto the tab itself. The editor clears it once it
     * has scrolled, so coming back to the tab later stays where the user
     * left off rather than snapping to an old search hit.
     */
    function applyPendingLine(path) {
        const line = pendingEditorLines.value.get(path);
        if (!line) return;

        const lines = new Map(pendingEditorLines.value);
        lines.delete(path);
        pendingEditorLines.value = lines;

        const entry = openFiles.value.find((file) => file.path === path);
        if (entry) entry.gotoLine = line;
    }

    function clearGotoLine(path) {
        const entry = openFiles.value.find((file) => file.path === path);
        if (entry) entry.gotoLine = 0;
    }

    function rememberRecentPath(path) {
        if (!path) return;

        recentPaths.value = [path, ...recentPaths.value.filter((entry) => entry !== path)].slice(0, 12);
    }

    const filteredItems = computed(() => {
        let list = Array.isArray(localItems.value)
            ? localItems.value.filter((item) => item && typeof item === 'object' && Boolean(item.path))
            : [];

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
        const walk = (nodes, level) => {
            for (const node of nodes) {
                const expanded = isTreeNodeOpen(node.path);
                rows.push({
                    name: node.name,
                    path: node.path,
                    hasChildren: node.hasChildren,
                    level,
                    expanded,
                    loading: treeLoadingPaths.value.includes(node.path),
                });
                if (expanded && node.children.length) {
                    walk(node.children, level + 1);
                }
            }
        };
        walk(treeNodes.value, 0);

        return rows;
    });

    const activeFile = computed(() => openFiles.value.find((entry) => entry.path === activeFilePath.value) || null);
    const editorContent = computed({
        get: () => activeFile.value?.content ?? '',
        set: (value) => {
            if (activeFile.value) activeFile.value.content = value ?? '';
        },
    });
    const editorPath = computed(() => activeFile.value?.path || '');
    const hasUnsavedChanges = computed(() => Boolean(activeFile.value) && activeFile.value.content !== activeFile.value.original);
    const unsavedPaths = computed(() => openFiles.value
        .filter((entry) => entry.content !== entry.original)
        .map((entry) => entry.path));
    const isTabDirty = (path) => unsavedPaths.value.includes(path);
    const editorFileName = computed(() => activeFile.value?.name || editorPath.value.split('/').pop() || 'untitled');
    const editorLineCount = computed(() => Math.max(1, String(editorContent.value || '').replace(/\n$/, '').split('\n').length));
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
            { key: 'owner', label: t('fileManager.manager.owner'), ...permissionPreview.value.owner },
            { key: 'group', label: t('fileManager.manager.group'), ...permissionPreview.value.group },
            { key: 'world', label: t('fileManager.manager.world'), ...permissionPreview.value.world },
        ];
    });
    const permissionCanSave = computed(() => permissionDigits.value.length === 3);
    const isBusy = computed(() =>
        createFolderForm.processing || createFileForm.processing || saveInProgress.value ||
        deleteForm.processing || uploadInProgress.value || permissionForm.processing ||
        renameInProgress.value || zipForm.processing || unzipForm.processing || moveForm.processing ||
        copyForm.processing
    );

    function handleEditorBeforeUnload(event) {
        if (unsavedPaths.value.length) {
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
        () => props.directoryTree,
        (value) => {
            treeNodes.value = mergeTree(treeNodes.value, cloneTree(value));
        },
        { immediate: true },
    );

    // Another site, or another root inside it, is another tree entirely.
    watch(
        () => [props.basePath, props.rootFolder],
        () => {
            treeNodes.value = cloneTree(props.directoryTree);
            treeOpenState.value = {};
        },
    );

    watch(
        () => props.showHidden,
        (value) => {
            hiddenEnabled.value = Boolean(value);
        },
    );

    // A file arriving from the server opens as a tab and takes focus. Its
    // absence says nothing: browsing a folder from inside the editor leaves
    // the open tabs exactly as they were.
    watch(
        () => props.selectedFile,
        (value) => {
            if (!value?.path) return;

            upsertOpenFile(value);
            activeItemPath.value = value.path;
        },
        { immediate: true },
    );

    watch(
        () => props.items,
        (list) => {
            localItems.value = Array.isArray(list) ? list.map((item) => ({ ...item })) : [];
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
                activeItemPath.value = activeFilePath.value || list[0].path;
            }

            if (contextMenu.value.visible) {
                const contextExists = list.some((item) => item.path === contextMenu.value.itemPath);
                if (!contextExists) {
                    closeContextMenu();
                }
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
        if (activeFilePath.value) {
            activeItemPath.value = activeFilePath.value;
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
        ownerRootLabel,
        basePath: computed(() => props.basePath || ''),
        currentPath: computed(() => props.currentPath || ''),
        // ui state
        modalType,
        sidebarOpen,
        sortBy,
        sortDir,
        isMobile,
        pathInput,
        activeItemPath,
        selectedPaths,
        selectionAnchorPath,
        hiddenEnabled,
        uploadDragActive,
        uploadProgress,
        uploadTaskComplete,
        uploadInProgress,
        uploadCurrentFile,
        unzipInProgress,
        cacheClearInProgress,
        tableDragActive,
        tableDragDepth,
        droppedUploadHint,
        moveDragTargetPath,
        draggingItemPaths,
        openFiles,
        activeFilePath,
        treeOpenState,
        treeLoadingPaths,
        sidebarWidth,
        saveInProgress,
        contextMenu,
        toasts,
        trashItems,
        trashLoading,
        trashActionId,
        trashSelectedPaths,
        trashBulkActionLoading,
        folderSizeLoadingPath,
        // forms
        createFolderForm,
        createFileForm,
        deleteForm,
        uploadForm,
        permissionForm,
        renameForm,
        renameInProgress,
        zipForm,
        unzipForm,
        moveForm,
        copyForm,
        // computed
        selectedCount,
        selectedPathList,
        isAllItemsSelected,
        isItemSelected,
        singleSelectedItem,
        isSupportedArchiveSelected,
        quickActionsGroups,
        filteredItems,
        paletteOpen,
        paletteQuery,
        paletteCursor,
        paletteMode,
        paletteScope,
        paletteOptions,
        paletteLoading,
        paletteError,
        paletteTruncated,
        paletteResults,
        openPalette,
        closePalette,
        setPaletteMode,
        movePaletteCursor,
        runPaletteEntry,
        clearGotoLine,
        treeRows,
        items: computed(() => localItems.value),
        directoryTree: computed(() => props.directoryTree || []),
        activeFile,
        editorContent,
        editorPath,
        contextItem,
        contextArchive,
        hasUnsavedChanges,
        unsavedPaths,
        isTabDirty,
        editorFileName,
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
        openPath,
        goParent,
        goFromPathInput,
        parentPath,
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
        openCopyForSelection,
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
        setSidebarWidth,
        persistSidebarWidth,
        openTreeContextMenu,
        openBackgroundContextMenu,
        triggerBackgroundAction,
        refreshListing,
        copyToClipboard,
        openFileInEditor,
        activateTab,
        showFilesTab,
        closeTab,
        isFileOpen,
        isSqliteFile,
        sqliteItem,
        openSqlite,
        closeSqlite,
        isEditableFile,
        previewItem,
        openPreview,
        closePreview,
        isPreviewable: (name) => !!previewKind(name),
        loadFullFile,
        deleteSelected,
        downloadSelected,
        downloadItem,
        openTrash,
        loadTrash,
        restoreTrashItem,
        destroyTrashItem,
        toggleTrashSelection,
        toggleSelectAllTrash,
        destroySelectedTrashItems,
        calculateFolderSize,
        submitCreateFolder,
        submitCreateFile,
        submitRename,
        submitPermissions,
        submitMove,
        submitCopy,
        submitZip,
        updateArchiveFormat,
        submitUnzip,
        submitUpload,
        clearProjectCache,
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
