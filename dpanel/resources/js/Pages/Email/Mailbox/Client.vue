<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import MailboxSidebar from './components/MailboxSidebar.vue';
import MailboxThreadPanel from './components/MailboxThreadPanel.vue';
import MailboxToastStack from './components/MailboxToastStack.vue';
import MailboxComposeWindow from './components/MailboxComposeWindow.vue';
import MailboxTopbar from './components/MailboxTopbar.vue';

const page = usePage();
const panelToken = page.props.panel?.token;

const props = defineProps({
    mailbox: {
        type: Object,
        required: true,
    },
    relatedMailboxes: {
        type: Array,
        default: () => [],
    },
    selectedFolder: {
        type: String,
        default: 'INBOX',
    },
    folders: {
        type: Array,
        default: () => [],
    },
    messages: {
        type: Array,
        default: () => [],
    },
    message: {
        type: Object,
        default: null,
    },
    loadingError: {
        type: String,
        default: '',
    },
    loadEndpoint: {
        type: String,
        required: true,
    },
    messageEndpoint: {
        type: String,
        required: true,
    },
    sendEndpoint: {
        type: String,
        required: true,
    },
    deleteEndpoint: {
        type: String,
        required: true,
    },
    markReadEndpoint: {
        type: String,
        default: '',
    },
    bulkEndpoint: {
        type: String,
        default: '',
    },
    composeDefaults: {
        type: Object,
        default: () => ({
            to: '',
            subject: '',
        }),
    },
});

const composeOpen = ref(false);
const loading = ref(false);
const messageLoading = ref(false);
let requestedUid = null;
const sending = ref(false);
const deletingUid = ref(null);
const accountMenuOpen = ref(false);
const searchInput = ref(null);
const statusMessage = ref('');
const errorMessage = ref(props.loadingError || '');
const searchQuery = ref('');
const messageFilter = ref('all');
const filterMenuOpen = ref(false);
const messages = ref([...props.messages]);
const activeFolder = ref(props.selectedFolder || 'INBOX');
const activeMessage = ref(props.message);
const composeTo = ref(props.composeDefaults.to || '');
const composeSubject = ref(props.composeDefaults.subject || '');
const composeBody = ref('');
const composeCc = ref('');
const composeBcc = ref('');
const theme = ref('light');
const isDark = computed(() => theme.value === 'dark');
const toasts = ref([]);
let toastSeq = 0;
const readCollapsed = () => {
    try {
        return localStorage.getItem('mailbox-sidebar-collapsed') === '1';
    }
    catch {
        return false;
    }
};
const sidebarCollapsed = ref(readCollapsed());
const sidebarOpen = ref(false);

const toggleSidebar = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    try {
        localStorage.setItem('mailbox-sidebar-collapsed', sidebarCollapsed.value ? '1' : '0');
    }
    catch {
        // Preference only; ignore unavailable storage.
    }
};

const toggleMobileSidebar = () => {
    sidebarOpen.value = !sidebarOpen.value;
};
const defaultFolderNames = ['INBOX', 'Sent', 'Drafts', 'Spam', 'Trash', 'Outbox', 'All Mail'];

const currentPage = ref(1);
const perPage = ref(50);

function normalizeFolderName(value) {
    return String(value || '').trim().toLowerCase();
}

function buildDefaultFolders() {
    return defaultFolderNames.map((name) => ({
        name,
        unread: 0,
        exists: 0,
    }));
}

function normalizeFolderRecord(folder) {
    return {
        name: String(folder?.name || '').trim() || 'INBOX',
        unread: Number(folder?.unread || 0),
        exists: Number(folder?.exists || 0),
    };
}

function mergeFolders(incoming = []) {
    const normalized = incoming.map(normalizeFolderRecord).filter((folder) => folder.name);
    const byKey = new Map(normalized.map((folder) => [normalizeFolderName(folder.name), folder]));

    const base = buildDefaultFolders().map((folder) => {
        const found = byKey.get(normalizeFolderName(folder.name));
        return found ? { ...folder, ...found } : folder;
    });

    const extras = normalized.filter(
        (folder) => !defaultFolderNames.some((name) => normalizeFolderName(name) === normalizeFolderName(folder.name)),
    );

    return [...base, ...extras];
}

const folders = ref(mergeFolders(props.folders));

function removeToast(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

function pushToast(message, type = 'error') {
    if (!message) return;

    const id = `${Date.now()}-${toastSeq += 1}`;
    toasts.value.push({
        id,
        message,
        type,
    });

    window.setTimeout(() => {
        removeToast(id);
    }, 4500);
}

const filteredMessages = computed(() => {
    const needle = searchQuery.value.trim().toLowerCase();
    const all = messages.value.filter((item) => {
        const haystack = `${item.subject || ''} ${item.from || ''} ${item.to || ''} ${item.snippet || ''}`.toLowerCase();
        const matchesSearch = !needle || haystack.includes(needle);
        const isUnread = !item.seen;
        const matchesFilter = messageFilter.value === 'all'
            || (messageFilter.value === 'unread' && isUnread)
            || (messageFilter.value === 'read' && !isUnread);
        return matchesSearch && matchesFilter;
    });
    const start = (currentPage.value - 1) * perPage.value;
    return all.slice(start, start + perPage.value);
});

const totalFilteredMessages = computed(() => {
    const needle = searchQuery.value.trim().toLowerCase();
    return messages.value.filter((item) => {
        const haystack = `${item.subject || ''} ${item.from || ''} ${item.to || ''} ${item.snippet || ''}`.toLowerCase();
        const matchesSearch = !needle || haystack.includes(needle);
        const isUnread = !item.seen;
        const matchesFilter = messageFilter.value === 'all'
            || (messageFilter.value === 'unread' && isUnread)
            || (messageFilter.value === 'read' && !isUnread);
        return matchesSearch && matchesFilter;
    }).length;
});

const totalPages = computed(() => Math.max(1, Math.ceil(totalFilteredMessages.value / perPage.value)));

const currentMessage = computed(() => activeMessage.value || null);

const filterOptions = [
    { value: 'all', label: 'All mail', hint: 'Show every message' },
    { value: 'unread', label: 'Unread', hint: 'Only unread messages' },
    { value: 'read', label: 'Read', hint: 'Only opened messages' },
];

const activeFilterLabel = computed(() => filterOptions.find((item) => item.value === messageFilter.value)?.label || 'All mail');
const filteredMessageCount = computed(() => filteredMessages.value.length);
const totalMessageCount = computed(() => messages.value.length);
const hasSearchQuery = computed(() => searchQuery.value.trim().length > 0);
const relatedMailboxesToShow = computed(() => {
    const currentEmail = String(props.mailbox?.email || '').trim().toLowerCase();
    const currentId = props.mailbox?.id;

    return (props.relatedMailboxes || []).filter((item) => {
        const itemEmail = String(item.email || '').trim().toLowerCase();
        if (currentEmail && itemEmail && itemEmail === currentEmail) return false;
        if (currentId != null && item.id != null && String(item.id) === String(currentId)) return false;
        return true;
    });
});

const folderLabel = (name) => {
    const normalized = normalizeFolderName(name);
    if (normalized === 'inbox') return 'Inbox';
    if (normalized.includes('sent')) return 'Sent';
    if (normalized.includes('draft')) return 'Drafts';
    if (normalized.includes('spam') || normalized.includes('junk')) return 'Spam';
    if (normalized.includes('trash') || normalized.includes('bin') || normalized.includes('deleted')) return 'Trash';
    if (normalized === 'outbox') return 'Outbox';
    if (normalized === 'all mail') return 'All';
    return name;
};
const activeFolderUnread = computed(() => Number(folders.value.find((f) => f.name === activeFolder.value)?.unread) || 0);
const folderOrder = ['inbox', 'sent', 'outbox', 'drafts', 'spam', 'trash', 'all mail'];

const compactFolders = computed(() => {
    const items = [...folders.value];
    return items
        .map((folder, index) => ({
            folder,
            index,
            order: (() => {
                const key = normalizeFolderName(folder.name);
                const matchIndex = folderOrder.findIndex((name) => key === name || key.includes(name));
                return matchIndex === -1 ? 100 + index : matchIndex;
            })(),
        }))
        .sort((left, right) => left.order - right.order || left.index - right.index)
        .map((entry) => entry.folder);
});

const formatDate = (value) => {
    if (!value) return '-';
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
};

const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const mailboxRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const mailboxesHref = panelRoute('emails.list');
const logoutHref = panelRoute('logout');

const applyTheme = (mode) => {
    if (typeof document === 'undefined') return;
    document.documentElement.classList.toggle('dark', mode === 'dark');
};

const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    localStorage.setItem('serverpanel-theme', theme.value);
    applyTheme(theme.value);
};

const toggleAccountMenu = () => {
    filterMenuOpen.value = false;
    accountMenuOpen.value = !accountMenuOpen.value;
};

const toggleFilterMenu = () => {
    accountMenuOpen.value = false;
    filterMenuOpen.value = !filterMenuOpen.value;
};

const setMessageFilter = (value) => {
    messageFilter.value = value;
    filterMenuOpen.value = false;
};

const focusSearch = () => {
    searchInput.value?.focus?.();
};

const clearSearch = () => {
    searchQuery.value = '';
    focusSearch();
};

const refreshInbox = async () => {
    closeTopbarMenus();
    await loadMailbox('INBOX', null, true);
};

const openMailbox = (id) => {
    accountMenuOpen.value = false;
    filterMenuOpen.value = false;
    router.visit(mailboxRoute('mailbox.open', { id }));
};

const loadMailbox = async (folder = activeFolder.value, uid = null, forceSync = false, followUp = false) => {
    loading.value = !followUp;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.get(props.loadEndpoint, {
            params: {
                folder,
                ...(uid ? { uid } : {}),
                ...(forceSync ? { refresh: 1 } : {}),
            },
            headers: { Accept: 'application/json' },
        });

        const data = response?.data || {};
        if (Array.isArray(data.folders) && data.folders.length > 0) {
            folders.value = mergeFolders(data.folders);
        }
        const previousFolder = activeFolder.value;
        messages.value = Array.isArray(data.messages) ? data.messages : [];
        activeFolder.value = folder;
        if (uid) {
            activeMessage.value = data.messageData || null;
        }
        else if (activeMessage.value && (previousFolder !== folder || !messages.value.some((m) => m.uid === activeMessage.value.uid))) {
            // A list refresh keeps the open message unless it is gone.
            activeMessage.value = null;
        }

        if (!data.success) {
            pushToast(data.message || 'Mailbox could not be loaded.');
        }
        else if (data.cached && data.syncing && !followUp) {
            // The cached list is shown instantly; pick up the background sync result once.
            setTimeout(() => {
                if (activeFolder.value === folder) {
                    loadMailbox(folder, null, false, true);
                }
            }, 3000);
        }
    }
    catch (error) {
        pushToast(error?.response?.data?.message || error?.message || 'Mailbox could not be loaded.');
        messages.value = [];
        activeMessage.value = null;
    }
    finally {
        loading.value = false;
    }
};

// Pull-based background refresh: each cached load also queues a server-side
// IMAP sync, so the next poll picks up new mail. No spinner, no auto-open,
// and a failed poll never clears the list.
const POLL_INTERVAL_MS = 30000;
let pollTimer = null;
let polling = false;

const pollMailbox = async () => {
    if (polling || loading.value || document.hidden) return;

    const folder = activeFolder.value;
    polling = true;
    try {
        const response = await window.axios.get(props.loadEndpoint, {
            params: { folder },
            headers: { Accept: 'application/json' },
        });
        const data = response?.data || {};
        if (!data.success || loading.value || activeFolder.value !== folder) return;

        if (Array.isArray(data.folders) && data.folders.length > 0) {
            folders.value = mergeFolders(data.folders);
        }
        if (Array.isArray(data.messages)) {
            messages.value = data.messages;
        }
    }
    catch {
        // Next poll retries.
    }
    finally {
        polling = false;
    }
};

// Poll now to queue a sync, then once more to pick up its result.
const refreshInBackground = () => {
    pollMailbox();
    setTimeout(pollMailbox, 3000);
};

const handleVisibilityChange = () => {
    if (!document.hidden) pollMailbox();
};

const openFolder = async (folder) => {
    filterMenuOpen.value = false;
    await loadMailbox(folder.name);
};

// Opening a message fetches only that message; the list and folders stay as they are.
const openMessage = async (uid) => {
    if (!uid || activeMessage.value?.uid === uid) return;

    const folder = activeFolder.value;
    requestedUid = uid;
    messageLoading.value = true;

    try {
        const response = await window.axios.get(props.messageEndpoint, {
            params: { folder, uid },
            headers: { Accept: 'application/json' },
        });
        if (requestedUid !== uid || activeFolder.value !== folder) return;

        const data = response?.data || {};
        if (!data.success || !data.messageData) {
            pushToast(data.message || 'Message could not be loaded.');
            return;
        }

        activeMessage.value = data.messageData;
        const item = messages.value.find((m) => m.uid === uid);
        if (item && !item.seen) {
            item.seen = true;
            const currentFolder = folders.value.find((f) => f.name === folder);
            if (currentFolder && currentFolder.unread > 0) currentFolder.unread -= 1;
        }
    }
    catch (error) {
        if (requestedUid === uid) {
            pushToast(error?.response?.data?.message || error?.message || 'Message could not be loaded.');
        }
    }
    finally {
        if (requestedUid === uid) messageLoading.value = false;
    }
};

const closePreview = () => {
    activeMessage.value = null;
};

const closeAccountMenu = () => {
    accountMenuOpen.value = false;
};

const closeFilterMenu = () => {
    filterMenuOpen.value = false;
};

const closeTopbarMenus = () => {
    closeAccountMenu();
    closeFilterMenu();
};

const handleDocumentClick = (event) => {
    const header = event?.target?.closest?.('header');
    if (!header) {
        closeTopbarMenus();
    }
};

const handleDocumentKeydown = (event) => {
    if (event.key === 'Escape') {
        closeTopbarMenus();
        return;
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        focusSearch();
    }
};

const refreshMailbox = async () => {
    await loadMailbox(activeFolder.value, null, true);
};

const startCompose = () => {
    composeOpen.value = true;
    statusMessage.value = '';
};

const closeCompose = () => {
    composeOpen.value = false;
};

const clearDraft = () => {
    composeTo.value = '';
    composeCc.value = '';
    composeBcc.value = '';
    composeSubject.value = '';
    composeBody.value = '';
};

const discardCompose = () => {
    clearDraft();
    composeOpen.value = false;
};

const submitSend = async () => {
    sending.value = true;
    statusMessage.value = '';

    try {
        const response = await window.axios.post(
            props.sendEndpoint,
            {
                to: composeTo.value,
                cc: composeCc.value,
                bcc: composeBcc.value,
                subject: composeSubject.value,
                body: composeBody.value,
                folder: activeFolder.value,
            },
            { headers: { Accept: 'application/json' } },
        );

        pushToast(response?.data?.message || 'Message sent successfully.', 'success');
        composeOpen.value = false;
        clearDraft();
        refreshInBackground();
    }
    catch (error) {
        pushToast(error?.response?.data?.message || error?.message || 'Message could not be sent.');
    }
    finally {
        sending.value = false;
    }
};

const selectedUids = ref([]);
const bulkWorking = ref(false);

const toggleSelect = (uid) => {
    selectedUids.value = selectedUids.value.includes(uid)
        ? selectedUids.value.filter((item) => item !== uid)
        : [...selectedUids.value, uid];
};

const selectAll = (checked) => {
    selectedUids.value = checked ? filteredMessages.value.map((item) => item.uid) : [];
};

const bulkAction = async (action) => {
    const uids = [...selectedUids.value];
    if (!uids.length || !props.bulkEndpoint || bulkWorking.value) return;
    if (action === 'delete' && !confirm(`Delete ${uids.length} ${uids.length === 1 ? 'message' : 'messages'}? This cannot be undone.`)) return;

    const folder = activeFolder.value;
    bulkWorking.value = true;

    try {
        const response = await window.axios.post(
            props.bulkEndpoint,
            { folder, action, uids },
            { headers: { Accept: 'application/json' } },
        );

        if (action === 'delete') {
            if (uids.includes(activeMessage.value?.uid)) activeMessage.value = null;
            selectedUids.value = [];
            await loadMailbox(folder, null);
        }
        else {
            const seen = action === 'read';
            let unreadDelta = 0;
            messages.value.forEach((item) => {
                if (uids.includes(item.uid) && item.seen !== seen) {
                    unreadDelta += seen ? -1 : 1;
                    item.seen = seen;
                }
            });
            const currentFolder = folders.value.find((f) => f.name === folder);
            if (currentFolder) currentFolder.unread = Math.max(0, (Number(currentFolder.unread) || 0) + unreadDelta);
            if (activeMessage.value && uids.includes(activeMessage.value.uid)) {
                activeMessage.value = { ...activeMessage.value, seen };
            }
            selectedUids.value = [];
        }

        pushToast(response?.data?.message || 'Done.', 'success');
    }
    catch (error) {
        pushToast(error?.response?.data?.message || error?.message || 'Action failed.');
    }
    finally {
        bulkWorking.value = false;
    }
};

// Selection only makes sense for rows that are still visible.
watch([activeFolder, currentPage, searchQuery, messageFilter], () => {
    selectedUids.value = [];
});
watch(messages, () => {
    const visible = new Set(messages.value.map((item) => item.uid));
    if (selectedUids.value.some((uid) => !visible.has(uid))) {
        selectedUids.value = selectedUids.value.filter((uid) => visible.has(uid));
    }
});

const deleteMessage = async (uid) => {
    if (!confirm('Delete this message?')) return;

    deletingUid.value = uid;
    statusMessage.value = '';

    try {
        const response = await window.axios.post(
            props.deleteEndpoint,
            {
                folder: activeFolder.value,
                uid,
            },
            { headers: { Accept: 'application/json' } },
        );

        statusMessage.value = response?.data?.message || 'Message deleted.';
        await loadMailbox(activeFolder.value, null);
    }
    catch (error) {
        pushToast(error?.response?.data?.message || error?.message || 'Message could not be deleted.');
    }
    finally {
        deletingUid.value = null;
    }
};

const addressOf = (value) => {
    const match = String(value || '').match(/<(.+?)>/);

    return (match ? match[1] : String(value || '')).trim().toLowerCase();
};
const selfAddress = computed(() => String(props.mailbox?.email || '').trim().toLowerCase());
const isFromSelf = (message) => selfAddress.value !== '' && addressOf(message?.from) === selfAddress.value;
const withoutSelf = (list) => String(list || '')
    .split(',')
    .map((item) => item.trim())
    .filter((item) => item && addressOf(item) !== selfAddress.value)
    .join(', ');

// Replying to your own sent mail goes back to its recipients, not to yourself.
const replyToMessage = (message) => {
    if (!message) return;
    composeTo.value = isFromSelf(message) ? (withoutSelf(message.to) || message.to || '') : (message.from || '');
    composeSubject.value = `Re: ${(message.subject || '').replace(/^Re:\s*/i, '')}`;
    composeBody.value = '';
    composeCc.value = '';
    composeBcc.value = '';
    composeOpen.value = true;
};

const replyAllToMessage = (message) => {
    if (!message) return;
    const fromSelf = isFromSelf(message);
    composeTo.value = fromSelf ? (withoutSelf(message.to) || message.to || '') : (message.from || '');
    composeSubject.value = `Re: ${(message.subject || '').replace(/^Re:\s*/i, '')}`;
    composeBody.value = '';
    composeCc.value = fromSelf ? '' : withoutSelf(message.to);
    composeBcc.value = '';
    composeOpen.value = true;
};

const forwardMessage = (message) => {
    if (!message) return;
    composeTo.value = '';
    composeSubject.value = `Fwd: ${(message.subject || '').replace(/^Fwd:\s*/i, '')}`;
    composeBody.value = `\n\n--- Forwarded message ---\nFrom: ${message.from || ''}\nDate: ${message.date || ''}\nSubject: ${message.subject || ''}\n\n${message.text || message.raw_body || ''}`;
    composeCc.value = '';
    composeBcc.value = '';
    composeOpen.value = true;
};

const toggleRead = async (message) => {
    if (!message || !props.markReadEndpoint) return;

    // Capture first: `message` may be the list item itself, which is mutated below.
    const uid = message.uid;
    const seen = !message.seen;
    const folder = activeFolder.value;

    try {
        await window.axios.post(
            props.markReadEndpoint,
            { folder, uid, seen },
            { headers: { Accept: 'application/json' } },
        );

        const msg = messages.value.find((m) => m.uid === uid);
        if (msg && msg.seen !== seen) {
            msg.seen = seen;
            const currentFolder = folders.value.find((f) => f.name === folder);
            if (currentFolder) currentFolder.unread = Math.max(0, (Number(currentFolder.unread) || 0) + (seen ? -1 : 1));
        }
        if (activeMessage.value?.uid === uid) {
            activeMessage.value = { ...activeMessage.value, seen };
        }

        pushToast(seen ? 'Marked as read.' : 'Marked as unread.', 'success');
    }
    catch (error) {
        pushToast(error?.response?.data?.message || 'Could not update message status.');
    }
};

onMounted(() => {
    const savedTheme = localStorage.getItem('serverpanel-theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    theme.value = savedTheme ?? (prefersDark ? 'dark' : 'light');
    applyTheme(theme.value);

    loadMailbox(activeFolder.value);
    pollTimer = window.setInterval(pollMailbox, POLL_INTERVAL_MS);
    document.addEventListener('visibilitychange', handleVisibilityChange);
});

onBeforeUnmount(() => {
    window.clearInterval(pollTimer);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
});

// Keep the folder in the URL so reload and back/forward return to it.
// Inertia's history state is passed through unchanged.
watch(activeFolder, (folder) => {
    try {
        const url = new URL(window.location.href);
        if (!folder || folder === 'INBOX') url.searchParams.delete('folder');
        else url.searchParams.set('folder', folder);
        if (url.href !== window.location.href) window.history.replaceState(window.history.state, '', url);
    }
    catch {
        // URL sync is a convenience only.
    }
});

watch(() => searchQuery.value, () => {
    currentPage.value = 1;
});

watch(() => messageFilter.value, () => {
    currentPage.value = 1;
});
</script>

<template>
    <Head :title="`Mailbox - ${mailbox.email}`" />

    <div :class="isDark ? 'min-h-screen bg-slate-950 text-slate-100' : 'min-h-screen bg-[#f6f8fc] text-slate-900'">
        <!-- Mobile Sidebar Overlay -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm md:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Fixed Sidebar -->
        <MailboxSidebar
            :folders="compactFolders"
            :active-folder="activeFolder"
            :loading="loading"
            :is-dark="isDark"
            :mailbox="mailbox"
            :collapsed="sidebarCollapsed"
            :mobile-open="sidebarOpen"
            class="fixed inset-y-0 left-0 z-50"
            @compose="startCompose"
            @open-folder="openFolder"
            @close-mobile="sidebarOpen = false"
            @toggle-collapse="toggleSidebar"
        />

        <!-- Main Content Area -->
        <div
            :class="[
                sidebarCollapsed ? 'md:ml-[72px]' : 'md:ml-[260px]',
                'flex h-screen min-w-0 flex-col transition-[margin] duration-200'
            ]"
        >
            <MailboxTopbar
                :mailbox="mailbox"
                :is-dark="isDark"
                :search-query="searchQuery"
                :active-filter-label="activeFilterLabel"
                :filter-options="filterOptions"
                :message-filter="messageFilter"
                :filtered-message-count="filteredMessageCount"
                :total-message-count="totalMessageCount"
                :related-mailboxes="relatedMailboxesToShow"
                :mailboxes-href="mailboxesHref"
                :logout-href="logoutHref"
                :active-folder-label="folderLabel(activeFolder)"
                :unread-count="activeFolderUnread"
                :loading="loading"
                @refresh-inbox="refreshInbox"
                @update:searchQuery="searchQuery = $event"
                @set-message-filter="setMessageFilter"
                @toggle-theme="toggleTheme"
                @open-mailbox="openMailbox"
                @toggle-mobile-sidebar="toggleMobileSidebar"
            />

            <div v-if="statusMessage" :class="isDark
                ? 'shrink-0 border-b border-emerald-900/40 bg-emerald-950/30 px-4 py-2 text-sm text-emerald-200'
                : 'shrink-0 border-b border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800'">
                {{ statusMessage }}
            </div>

            <main class="min-h-0 flex-1">
                <MailboxThreadPanel
                    :active-folder="activeFolder"
                    :filtered-messages="filteredMessages"
                    :current-message="currentMessage"
                    :loading="loading"
                    :message-loading="messageLoading"
                    :deleting-uid="deletingUid"
                    :is-dark="isDark"
                    :has-search-query="hasSearchQuery"
                    :page="currentPage"
                    :total-pages="totalPages"
                    :per-page="perPage"
                    :total-count="totalFilteredMessages"
                    :selected-uids="selectedUids"
                    :bulk-working="bulkWorking"
                    :self-email="mailbox.email"
                    @open-message="openMessage"
                    @close-preview="closePreview"
                    @start-compose="startCompose"
                    @delete-message="deleteMessage"
                    @reply="replyToMessage"
                    @reply-all="replyAllToMessage"
                    @forward="forwardMessage"
                    @toggle-read="toggleRead"
                    @change-page="currentPage = $event"
                    @toggle-select="toggleSelect"
                    @select-all="selectAll"
                    @bulk-action="bulkAction"
                />

            </main>
        </div>

        <MailboxComposeWindow
            v-model:open="composeOpen"
            v-model:to="composeTo"
            v-model:cc="composeCc"
            v-model:bcc="composeBcc"
            v-model:subject="composeSubject"
            v-model:body="composeBody"
            :is-dark="isDark"
            :sending="sending"
            :from-email="mailbox.email"
            @send="submitSend"
            @discard="discardCompose"
        />

        <MailboxToastStack
            :toasts="toasts"
            :is-dark="isDark"
            @dismiss="removeToast"
        />
    </div>
</template>
