<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import MailboxSidebar from './components/MailboxSidebar.vue';
import MailboxThreadPanel from './components/MailboxThreadPanel.vue';
import MailboxToastStack from './components/MailboxToastStack.vue';
import MailboxComposeWindow from './components/MailboxComposeWindow.vue';
import MailboxTopbar from './components/MailboxTopbar.vue';
import ContactsPage from './components/ContactsPage.vue';
import { provideMailContacts } from './composables/useMailContacts';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
    rawEndpoint: {
        type: String,
        default: '',
    },
    rawDownloadEndpoint: {
        type: String,
        default: '',
    },
    avatarEndpoint: {
        type: String,
        default: '',
    },
    photoEndpoint: {
        type: String,
        default: '',
    },
    bulkEndpoint: {
        type: String,
        default: '',
    },
    contactsEndpoint: {
        type: String,
        default: '',
    },
    threadEndpoint: {
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

// The mailbox's own profile photo, served by the avatar endpoint like any sender's.
const photoVersion = ref(Number(props.mailbox.avatar_version || 0));
const mailboxView = computed(() => ({
    ...props.mailbox,
    avatar_url: photoVersion.value && props.avatarEndpoint
        ? `${props.avatarEndpoint}${props.avatarEndpoint.includes('?') ? '&' : '?'}${new URLSearchParams({ email: props.mailbox.email, v: String(photoVersion.value) })}`
        : '',
}));

const uploadPhoto = async (file) => {
    if (!props.photoEndpoint) return;
    const data = new FormData();
    data.append('photo', file);
    try {
        const response = await window.axios.post(props.photoEndpoint, data, { headers: { Accept: 'application/json' } });
        photoVersion.value = Date.now();
        pushToast(response?.data?.message || t('email.mailbox.client.photoUpdated'), 'success');
    }
    catch (error) {
        pushToast(error?.response?.data?.errors?.photo?.[0] || error?.response?.data?.message || t('email.mailbox.client.photoUploadFailed'));
    }
};

const removePhoto = async () => {
    if (!props.photoEndpoint) return;
    try {
        await window.axios.delete(props.photoEndpoint, { headers: { Accept: 'application/json' } });
        photoVersion.value = 0;
        pushToast(t('email.mailbox.client.photoRemoved'), 'success');
    }
    catch (error) {
        pushToast(error?.response?.data?.message || t('email.mailbox.client.photoRemoveFailed'));
    }
};

const contacts = provideMailContacts(props.contactsEndpoint);
// 'mail' shows the folder view, 'contacts' the address book page (?view=contacts).
const readView = () => {
    try {
        return new URL(window.location.href).searchParams.get('view') === 'contacts' ? 'contacts' : 'mail';
    }
    catch {
        return 'mail';
    }
};
const activeView = ref(readView());
const openContacts = () => {
    activeView.value = 'contacts';
    sidebarOpen.value = false;
};
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
// Inbox category (primary/promotions/updates/social); '' shows the whole Inbox.
const activeCategory = ref('');
const categoryCounts = ref({});
const activeMessage = ref(props.message);
const composeTo = ref(props.composeDefaults.to || '');
const composeSubject = ref(props.composeDefaults.subject || '');
const composeBody = ref('');
const composeHtml = ref('');
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

const currentPage = ref(1);
const perPage = ref(50);

function normalizeFolderName(value) {
    return String(value || '').trim().toLowerCase();
}

function normalizeFolderRecord(folder) {
    return {
        name: String(folder?.name || '').trim() || 'INBOX',
        unread: Number(folder?.unread || 0),
        exists: Number(folder?.exists || 0),
    };
}

// Exactly the folders the server has. The sidebar used to add Spam, Outbox,
// All Mail and others that may not exist, and opening one failed with
// "Mailbox doesn't exist". The server creates Sent/Drafts/Junk/Trash itself.
function mergeFolders(incoming = []) {
    const normalized = incoming.map(normalizeFolderRecord).filter((folder) => folder.name);
    const hasInbox = normalized.some((folder) => normalizeFolderName(folder.name) === 'inbox');

    return hasInbox ? normalized : [{ name: 'INBOX', unread: 0, exists: 0 }, ...normalized];
}

const folders = ref(mergeFolders(props.folders));

// A list holding only INBOX means the server could not list folders this
// time; keep the folders already shown (with the fresh Inbox counts) so
// Sent/Drafts/Spam/Trash do not disappear from the sidebar.
function applyFolders(incoming) {
    if (!Array.isArray(incoming) || incoming.length === 0) return;
    const next = mergeFolders(incoming);
    if (next.length === 1 && folders.value.length > 1) {
        const inbox = next[0];
        folders.value = folders.value.map((folder) => (normalizeFolderName(folder.name) === 'inbox' ? { ...folder, unread: inbox.unread, exists: inbox.exists } : folder));
        return;
    }
    folders.value = next;
}

const isInbox = (name) => normalizeFolderName(name) === 'inbox';
const categoryParam = (folder) => (activeCategory.value && isInbox(folder) ? { category: activeCategory.value } : {});

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

const filterOptions = computed(() => [
    { value: 'all', label: t('email.mailbox.client.filter.all'), hint: t('email.mailbox.client.filter.allHint') },
    { value: 'unread', label: t('email.mailbox.client.filter.unread'), hint: t('email.mailbox.client.filter.unreadHint') },
    { value: 'read', label: t('email.mailbox.client.filter.read'), hint: t('email.mailbox.client.filter.readHint') },
]);

const activeFilterLabel = computed(() => filterOptions.value.find((item) => item.value === messageFilter.value)?.label || t('email.mailbox.client.filter.all'));
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
    if (normalized === 'inbox') return t('email.mailbox.folders.inbox');
    if (normalized.includes('sent')) return t('email.mailbox.folders.sent');
    if (normalized.includes('draft')) return t('email.mailbox.folders.drafts');
    if (normalized.includes('spam') || normalized.includes('junk')) return t('email.mailbox.folders.spam');
    if (normalized.includes('trash') || normalized.includes('bin') || normalized.includes('deleted')) return t('email.mailbox.folders.trash');
    if (normalized === 'outbox') return t('email.mailbox.folders.outbox');
    if (normalized === 'all mail') return t('email.mailbox.folders.allShort');
    return name;
};
const activeFolderUnread = computed(() => (activeCategory.value && isInbox(activeFolder.value)
    ? Number(categoryCounts.value?.[activeCategory.value]?.unread) || 0
    : Number(folders.value.find((f) => f.name === activeFolder.value)?.unread) || 0));
const activeViewLabel = computed(() => (activeCategory.value && isInbox(activeFolder.value)
    ? t(`email.mailbox.categories.${activeCategory.value}`)
    : folderLabel(activeFolder.value)));
const folderOrder = ['inbox', 'sent', 'outbox', 'drafts', 'spam', 'junk', 'trash', 'all mail'];

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
    localStorage.setItem('dpanel-theme', theme.value);
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
    const requestedCategory = activeCategory.value;
    loading.value = !followUp;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.get(props.loadEndpoint, {
            params: {
                folder,
                ...categoryParam(folder),
                ...(uid ? { uid } : {}),
                ...(forceSync ? { refresh: 1 } : {}),
            },
            headers: { Accept: 'application/json' },
        });

        const data = response?.data || {};
        applyFolders(data.folders);
        if (data.categories && typeof data.categories === 'object') categoryCounts.value = data.categories;
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
            pushToast(data.message || t('email.mailbox.client.mailboxFailed'));
            // The folder is gone (deleted in another app): go back to the Inbox.
            if (data.folder_missing && normalizeFolderName(folder) !== 'inbox') {
                loadMailbox('INBOX', null, true);
            }
        }
        else if (data.cached && data.syncing && !followUp) {
            // The cached list is shown instantly; pick up the background sync result once.
            setTimeout(() => {
                if (activeFolder.value === folder && activeCategory.value === requestedCategory) {
                    loadMailbox(folder, null, false, true);
                }
            }, 3000);
        }
    }
    catch (error) {
        pushToast(error?.response?.data?.message || error?.message || t('email.mailbox.client.mailboxFailed'));
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
    const category = activeCategory.value;
    polling = true;
    try {
        const response = await window.axios.get(props.loadEndpoint, {
            params: { folder, ...categoryParam(folder) },
            headers: { Accept: 'application/json' },
        });
        const data = response?.data || {};
        if (!data.success || loading.value || activeFolder.value !== folder || activeCategory.value !== category) return;

        applyFolders(data.folders);
        if (data.categories && typeof data.categories === 'object') categoryCounts.value = data.categories;
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
    activeView.value = 'mail';
    filterMenuOpen.value = false;
    activeCategory.value = '';
    await loadMailbox(folder.name);
};

const openCategory = async (category) => {
    activeView.value = 'mail';
    filterMenuOpen.value = false;
    activeCategory.value = activeCategory.value === category ? '' : category;
    currentPage.value = 1;
    await loadMailbox('INBOX');
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
            pushToast(data.message || t('email.mailbox.message.loadFailed'));
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
            pushToast(error?.response?.data?.message || error?.message || t('email.mailbox.message.loadFailed'));
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
    composeHtml.value = '';
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
                html: composeHtml.value,
                folder: activeFolder.value,
            },
            { headers: { Accept: 'application/json' } },
        );

        pushToast(response?.data?.message || t('email.mailbox.composer.sent'), 'success');
        composeOpen.value = false;
        clearDraft();
        contacts.load(true);
        refreshInBackground();
    }
    catch (error) {
        pushToast(error?.response?.data?.message || error?.message || t('email.mailbox.composer.sendFailed'));
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
    if (action === 'delete' && !confirm(uids.length === 1 ? t('email.mailbox.client.deleteOne') : t('email.mailbox.client.deleteMany', { count: uids.length }))) return;

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

        pushToast(response?.data?.message || t('email.mailbox.client.done'), 'success');
    }
    catch (error) {
        pushToast(error?.response?.data?.message || error?.message || t('email.mailbox.client.actionFailed'));
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
    if (!confirm(t('email.mailbox.client.deleteThis'))) return;

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

        statusMessage.value = response?.data?.message || t('email.mailbox.client.deleted');
        await loadMailbox(activeFolder.value, null);
    }
    catch (error) {
        pushToast(error?.response?.data?.message || error?.message || t('email.mailbox.client.deleteFailed'));
    }
    finally {
        deletingUid.value = null;
    }
};

// "Send mail" from a contact card or the contacts list.
const composeToAddress = (address) => {
    if (composeOpen.value && (composeTo.value.trim() || composeBody.value.trim())) {
        composeTo.value = `${composeTo.value.replace(/[,\s]+$/, '')}, ${address}`;
        return;
    }
    clearDraft();
    composeTo.value = address;
    composeOpen.value = true;
};

// The inline reply's pop-out button moves the draft into the compose window.
const popOutReply = (draft) => {
    composeTo.value = draft.to;
    composeCc.value = draft.cc;
    composeBcc.value = draft.bcc;
    composeSubject.value = draft.subject;
    composeHtml.value = '';
    composeBody.value = draft.body;
    composeOpen.value = true;
};

const onReplySent = (notice) => {
    pushToast(notice, 'success');
    contacts.load(true);
    refreshInBackground();
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

        pushToast(seen ? t('email.mailbox.client.markedRead') : t('email.mailbox.client.markedUnread'), 'success');
    }
    catch (error) {
        pushToast(error?.response?.data?.message || t('email.mailbox.client.statusFailed'));
    }
};

onMounted(() => {
    const savedTheme = localStorage.getItem('dpanel-theme');
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

watch(activeView, (view) => {
    try {
        const url = new URL(window.location.href);
        if (view === 'contacts') url.searchParams.set('view', 'contacts');
        else url.searchParams.delete('view');
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
    <Head :title="t('email.mailbox.client.headTitle', { email: mailbox.email })" />

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
            :active-folder="activeView === 'mail' && !activeCategory ? activeFolder : ''"
            :categories="categoryCounts"
            :active-category="activeView === 'mail' && isInbox(activeFolder) ? activeCategory : ''"
            :contacts-active="activeView === 'contacts'"
            :loading="loading"
            :is-dark="isDark"
            :mailbox="mailbox"
            :collapsed="sidebarCollapsed"
            :mobile-open="sidebarOpen"
            class="fixed inset-y-0 left-0 z-50"
            @compose="startCompose"
            @open-folder="openFolder"
            @open-category="openCategory"
            @close-mobile="sidebarOpen = false"
            @toggle-collapse="toggleSidebar"
            @open-contacts="openContacts"
        />

        <!-- Main Content Area -->
        <div
            :class="[
                sidebarCollapsed ? 'md:ml-[72px]' : 'md:ml-[260px]',
                'flex h-screen min-w-0 flex-col transition-[margin] duration-200'
            ]"
        >
            <MailboxTopbar
                :mailbox="mailboxView"
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
                :active-folder-label="activeViewLabel"
                :unread-count="activeFolderUnread"
                :loading="loading"
                @refresh-inbox="refreshInbox"
                @update:searchQuery="searchQuery = $event"
                @set-message-filter="setMessageFilter"
                @toggle-theme="toggleTheme"
                @open-mailbox="openMailbox"
                @toggle-mobile-sidebar="toggleMobileSidebar"
                @upload-photo="uploadPhoto"
                @remove-photo="removePhoto"
                @open-contacts="openContacts"
            />

            <div
                v-if="mailbox.delivery_mode === 'sandbox'"
                role="status"
                :class="isDark
                    ? 'shrink-0 border-b border-amber-900/40 bg-amber-950/30 px-4 py-2 text-sm text-amber-200'
                    : 'shrink-0 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800'"
            >
                <i class="bi bi-cone-striped mr-1"></i>
                <span class="font-medium">{{ t('email.mailbox.client.sandboxTitle') }}</span>
                {{ t('email.mailbox.client.sandboxHelp') }}
            </div>

            <div v-if="statusMessage" :class="isDark
                ? 'shrink-0 border-b border-emerald-900/40 bg-emerald-950/30 px-4 py-2 text-sm text-emerald-200'
                : 'shrink-0 border-b border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800'">
                {{ statusMessage }}
            </div>

            <main class="min-h-0 flex-1">
                <ContactsPage
                    v-if="activeView === 'contacts'"
                    :is-dark="isDark"
                    :avatar-endpoint="avatarEndpoint"
                    @compose="composeToAddress"
                    @notify="(text, type) => pushToast(text, type)"
                />
                <MailboxThreadPanel
                    v-else
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
                    :raw-endpoint="rawEndpoint"
                    :raw-download-endpoint="rawDownloadEndpoint"
                    :avatar-endpoint="avatarEndpoint"
                    :send-endpoint="sendEndpoint"
                    :message-endpoint="messageEndpoint"
                    :thread-endpoint="threadEndpoint"
                    :sandbox="mailbox.delivery_mode === 'sandbox'"
                    @open-message="openMessage"
                    @close-preview="closePreview"
                    @start-compose="startCompose"
                    @delete-message="deleteMessage"
                    @compose-to="composeToAddress"
                    @pop-out-reply="popOutReply"
                    @reply-sent="onReplySent"
                    @notify="(text, type) => pushToast(text, type)"
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
            v-model:html="composeHtml"
            :is-dark="isDark"
            :sending="sending"
            :from-email="mailbox.email"
            :sandbox="mailbox.delivery_mode === 'sandbox'"
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
