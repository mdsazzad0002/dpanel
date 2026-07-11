<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import MailboxSidebar from './components/MailboxSidebar.vue';
import MailboxThreadPanel from './components/MailboxThreadPanel.vue';
import MailboxToastStack from './components/MailboxToastStack.vue';
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
    sendEndpoint: {
        type: String,
        required: true,
    },
    deleteEndpoint: {
        type: String,
        required: true,
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
const showCc = ref(false);
const showBcc = ref(false);
const theme = ref('light');
const isDark = computed(() => theme.value === 'dark');
const toasts = ref([]);
let toastSeq = 0;
const defaultFolderNames = ['INBOX', 'Sent', 'Drafts', 'Spam', 'Trash', 'Outbox', 'All Mail'];

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
    return messages.value.filter((item) => {
        const haystack = `${item.subject || ''} ${item.from || ''} ${item.snippet || ''}`.toLowerCase();
        const matchesSearch = !needle || haystack.includes(needle);
        const isUnread = !item.seen;
        const matchesFilter = messageFilter.value === 'all'
            || (messageFilter.value === 'unread' && isUnread)
            || (messageFilter.value === 'read' && !isUnread);
        return matchesSearch && matchesFilter;
    });
});

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
    await loadMailbox('INBOX');
};

const openMailbox = (id) => {
    accountMenuOpen.value = false;
    filterMenuOpen.value = false;
    router.visit(mailboxRoute('mailbox.open', { id }));
};

const loadMailbox = async (folder = activeFolder.value, uid = null) => {
    loading.value = true;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.get(props.loadEndpoint, {
            params: {
                folder,
                ...(uid ? { uid } : {}),
            },
            headers: { Accept: 'application/json' },
        });

        const data = response?.data || {};
        if (Array.isArray(data.folders) && data.folders.length > 0) {
            folders.value = mergeFolders(data.folders);
        }
        messages.value = Array.isArray(data.messages) ? data.messages : [];
        activeFolder.value = folder;
        activeMessage.value = data.messageData || null;

        if (!data.success) {
            pushToast(data.message || 'Mailbox could not be loaded.');
        }
        else if (!activeMessage.value && messages.value.length > 0 && !uid) {
            const firstUid = messages.value[0]?.uid;
            if (firstUid) {
                await loadMailbox(folder, firstUid);
            }
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

const openFolder = async (folder) => {
    filterMenuOpen.value = false;
    await loadMailbox(folder.name);
};

const openMessage = async (uid) => {
    await loadMailbox(activeFolder.value, uid);
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
    await loadMailbox(activeFolder.value, currentMessage.value?.uid || null);
};

const startCompose = () => {
    composeOpen.value = true;
    showCc.value = false;
    showBcc.value = false;
    statusMessage.value = '';
};

const closeCompose = () => {
    composeOpen.value = false;
    showCc.value = false;
    showBcc.value = false;
};

const submitSend = async () => {
    sending.value = true;
    statusMessage.value = '';

    try {
        const response = await window.axios.post(
            props.sendEndpoint,
            {
                to: composeTo.value,
                subject: composeSubject.value,
                body: composeBody.value,
                folder: activeFolder.value,
            },
            { headers: { Accept: 'application/json' } },
        );

        statusMessage.value = response?.data?.message || 'Message sent successfully.';
        composeOpen.value = false;
        composeBody.value = '';
        composeCc.value = '';
        composeBcc.value = '';
        showCc.value = false;
        showBcc.value = false;
        await loadMailbox(activeFolder.value, null);
    }
    catch (error) {
        pushToast(error?.response?.data?.message || error?.message || 'Message could not be sent.');
    }
    finally {
        sending.value = false;
    }
};

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

onMounted(() => {
    const savedTheme = localStorage.getItem('serverpanel-theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    theme.value = savedTheme ?? (prefersDark ? 'dark' : 'light');
    applyTheme(theme.value);

    loadMailbox(activeFolder.value);
});
</script>

<template>
    <Head :title="`Mailbox - ${mailbox.email}`" />

    <div :class="isDark ? 'min-h-screen bg-slate-950 text-slate-100' : 'min-h-screen bg-[#f6f8fc] text-slate-900'">
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
            @refresh-inbox="refreshInbox"
            @update:searchQuery="searchQuery = $event"
            @set-message-filter="setMessageFilter"
            @toggle-theme="toggleTheme"
            @open-mailbox="openMailbox"
        />

        <div v-if="statusMessage" :class="isDark
            ? 'mx-4 mt-4 rounded-2xl border border-emerald-900/40 bg-emerald-950/30 px-4 py-3 text-sm text-emerald-200 md:mx-6'
            : 'mx-4 mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 md:mx-6'">
            {{ statusMessage }}
        </div>
        <main class="grid lg:grid-cols-[280px_minmax(0,1fr)]">
            <MailboxSidebar
                :folders="compactFolders"
                :active-folder="activeFolder"
                :loading="loading"
                :is-dark="isDark"
                :mailbox="mailbox"
                @compose="startCompose"
                @open-folder="openFolder"
            />

            <MailboxThreadPanel
                :active-folder="activeFolder"
                :filtered-messages="filteredMessages"
                :current-message="currentMessage"
                :loading="loading"
                :deleting-uid="deletingUid"
                :is-dark="isDark"
                :has-search-query="hasSearchQuery"
                @open-message="openMessage"
                @close-preview="closePreview"
                @start-compose="startCompose"
                @delete-message="deleteMessage"
            />
        </main>

        <div v-if="composeOpen" class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/40 p-3 backdrop-blur-sm sm:items-center sm:p-4">
            <div :class="isDark
                ? 'w-full max-w-4xl overflow-hidden rounded-2xl border border-slate-800 bg-slate-900 shadow-2xl'
                : 'w-full max-w-4xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl'">
                <div :class="isDark ? 'flex items-center justify-between gap-3 border-b border-slate-800 bg-slate-950 px-4 py-3' : 'flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3'">
                    <div class="min-w-0">
                        <h3 :class="isDark ? 'truncate text-base font-medium text-slate-100' : 'truncate text-base font-medium text-slate-900'">New Message</h3>
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" :class="isDark ? 'rounded-full p-2 text-slate-300 hover:bg-slate-800' : 'rounded-full p-2 text-slate-600 hover:bg-slate-200'" aria-label="Minimize">
                            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                                <path d="M6 12h12v2H6z" />
                            </svg>
                        </button>
                        <button type="button" :class="isDark ? 'rounded-full p-2 text-slate-300 hover:bg-slate-800' : 'rounded-full p-2 text-slate-600 hover:bg-slate-200'" aria-label="Pop out">
                            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                                <path d="M14 3h7v7h-2V6.41l-9.29 9.3-1.42-1.42 9.3-9.29H14V3zM5 5h6v2H7v10h10v-4h2v6H5V5z" />
                            </svg>
                        </button>
                        <button type="button" :class="isDark ? 'rounded-full p-2 text-slate-300 hover:bg-slate-800' : 'rounded-full p-2 text-slate-600 hover:bg-slate-200'" @click="closeCompose" aria-label="Close">
                            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                                <path d="M18.3 5.7a1 1 0 00-1.4-1.4L12 9.17 7.1 4.3A1 1 0 105.7 5.7L10.59 10.6 5.7 15.5a1 1 0 101.4 1.4l4.9-4.89 4.9 4.89a1 1 0 001.4-1.4l-4.89-4.9 4.89-4.9z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <form @submit.prevent="submitSend">
                    <div :class="isDark ? 'border-b border-slate-800 px-4 py-2' : 'border-b border-slate-200 px-4 py-2'">
                        <div class="flex items-start gap-3 py-2">
                            <label class="min-w-[52px] pt-2 text-sm text-slate-500">To</label>
                            <div class="min-w-0 flex-1">
                                <input
                                    v-model="composeTo"
                                    type="email"
                                    :class="isDark
                                        ? 'w-full bg-transparent text-sm text-slate-100 outline-none placeholder:text-slate-500'
                                        : 'w-full bg-transparent text-sm text-slate-900 outline-none placeholder:text-slate-400'"
                                    placeholder="recipients"
                                >
                            </div>
                            <div class="flex items-center gap-3 pt-1 text-sm text-slate-500">
                                <button type="button" class="hover:text-slate-700 dark:hover:text-slate-200" @click="showCc = !showCc">Cc</button>
                                <button type="button" class="hover:text-slate-700 dark:hover:text-slate-200" @click="showBcc = !showBcc">Bcc</button>
                            </div>
                        </div>

                        <div v-if="showCc" class="flex items-start gap-3 border-t border-slate-200/70 py-2 dark:border-slate-800">
                            <label class="min-w-[52px] pt-2 text-sm text-slate-500">Cc</label>
                            <input
                                v-model="composeCc"
                                type="text"
                                :class="isDark
                                    ? 'min-w-0 flex-1 bg-transparent text-sm text-slate-100 outline-none placeholder:text-slate-500'
                                    : 'min-w-0 flex-1 bg-transparent text-sm text-slate-900 outline-none placeholder:text-slate-400'"
                                placeholder="carbon copy"
                            >
                        </div>

                        <div v-if="showBcc" class="flex items-start gap-3 border-t border-slate-200/70 py-2 dark:border-slate-800">
                            <label class="min-w-[52px] pt-2 text-sm text-slate-500">Bcc</label>
                            <input
                                v-model="composeBcc"
                                type="text"
                                :class="isDark
                                    ? 'min-w-0 flex-1 bg-transparent text-sm text-slate-100 outline-none placeholder:text-slate-500'
                                    : 'min-w-0 flex-1 bg-transparent text-sm text-slate-900 outline-none placeholder:text-slate-400'"
                                placeholder="blind copy"
                            >
                        </div>
                    </div>

                    <div :class="isDark ? 'border-b border-slate-800 px-4 py-2' : 'border-b border-slate-200 px-4 py-2'">
                        <div class="flex items-center gap-3 py-2">
                            <label class="min-w-[52px] text-sm text-slate-500">Subject</label>
                            <input
                                v-model="composeSubject"
                                type="text"
                                :class="isDark
                                    ? 'w-full bg-transparent text-sm text-slate-100 outline-none placeholder:text-slate-500'
                                    : 'w-full bg-transparent text-sm text-slate-900 outline-none placeholder:text-slate-400'"
                                placeholder="Subject"
                            >
                        </div>
                    </div>

                    <div class="p-3">
                        <textarea
                            v-model="composeBody"
                            rows="14"
                            :class="isDark
                                ? 'w-full resize-none rounded-2xl border border-slate-800 bg-slate-950 px-4 py-4 text-sm leading-6 text-slate-100 outline-none placeholder:text-slate-500 focus:border-blue-500'
                                : 'w-full resize-none rounded-2xl border border-slate-200 bg-white px-4 py-4 text-sm leading-6 text-slate-900 outline-none placeholder:text-slate-400 focus:border-blue-500'"
                            placeholder="Compose your message"
                        ></textarea>
                    </div>

                    <div :class="isDark ? 'flex items-center justify-between gap-3 border-t border-slate-800 px-4 py-3' : 'flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-3'">
                        <div class="flex items-center gap-2 text-slate-500">
                            <button type="button" :class="isDark ? 'rounded-full p-2 hover:bg-slate-800' : 'rounded-full p-2 hover:bg-slate-100'" aria-label="Formatting">
                                <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true"><path d="M5 5h14v2H5zm0 6h14v2H5zm0 6h10v2H5z" /></svg>
                            </button>
                            <button type="button" :class="isDark ? 'rounded-full p-2 hover:bg-slate-800' : 'rounded-full p-2 hover:bg-slate-100'" aria-label="Attach">
                                <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true"><path d="M16.5 6.5l-7.78 7.78a3 3 0 104.24 4.24l8.49-8.49a5 5 0 10-7.07-7.07l-8.84 8.84 1.42 1.42 8.84-8.84a3 3 0 114.24 4.24l-8.49 8.49a1 1 0 01-1.41-1.41l7.78-7.78-1.42-1.42z" /></svg>
                            </button>
                            <button type="button" :class="isDark ? 'rounded-full p-2 hover:bg-slate-800' : 'rounded-full p-2 hover:bg-slate-100'" aria-label="Insert link">
                                <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true"><path d="M3.9 12a5 5 0 015-5h3v2h-3a3 3 0 100 6h3v2h-3a5 5 0 01-5-5zm7.2 1h2.8v-2h-2.8v2zm3.1-6h-3v2h3a3 3 0 010 6h-3v2h3a5 5 0 000-10z" /></svg>
                            </button>
                        </div>

                        <div class="flex items-center gap-3">
                            <button type="button" :class="isDark
                                ? 'rounded-full border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 hover:bg-slate-800'
                                : 'rounded-full border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100'" @click="closeCompose">Cancel</button>
                            <button type="submit" :class="isDark
                                ? 'rounded-full bg-blue-500 px-5 py-2 text-sm font-medium text-white hover:bg-blue-400'
                                : 'rounded-full bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700'" :disabled="sending">
                                {{ sending ? 'Sending...' : 'Send' }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <MailboxToastStack
            :toasts="toasts"
            :is-dark="isDark"
            @dismiss="removeToast"
        />
    </div>
</template>
