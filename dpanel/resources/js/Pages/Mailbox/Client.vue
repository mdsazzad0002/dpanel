<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

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

const mailboxRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

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
        <header :class="isDark ? 'sticky top-0 z-20 border-b border-slate-800 bg-slate-950/95 backdrop-blur' : 'sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur'">
            <div class="relative flex flex-wrap items-center justify-between gap-4 px-4 py-3 md:px-6">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-600 text-sm font-semibold text-white shadow-sm">
                        {{ mailbox.email?.slice(0, 1)?.toUpperCase() || 'M' }}
                    </div>
                    <div>
                        <p :class="isDark ? 'text-xs uppercase tracking-[0.24em] text-slate-400' : 'text-xs uppercase tracking-[0.24em] text-slate-500'">Mail Center</p>
                        <h1 :class="isDark ? 'text-lg font-semibold text-slate-100' : 'text-lg font-semibold text-slate-900'">Server Mail</h1>
                    </div>
                </div>

                <div :class="isDark
                    ? 'relative flex w-full max-w-2xl flex-1 items-center gap-3 rounded-full border border-slate-700 bg-slate-900  text-slate-300 shadow-inner'
                    : 'relative flex w-full max-w-2xl flex-1 items-center gap-3 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-slate-500 shadow-sm'">
                    <svg viewBox="0 0 24 24" :class="isDark ? 'h-4 w-4 shrink-0 fill-current text-slate-500' : 'h-4 w-4 shrink-0 fill-current text-slate-400'" aria-hidden="true">
                        <path d="M10 4a6 6 0 104.472 10.03l4.249 4.249 1.414-1.414-4.249-4.249A6 6 0 0010 4zm0 2a4 4 0 110 8 4 4 0 010-8z" />
                    </svg>
                    <input
                        v-model="searchQuery"
                        type="search"
                        :class="isDark ? 'min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-slate-500' : 'min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-slate-400'"
                        placeholder="Search mail, subject, sender"
                    >
                    <div class="relative shrink-0">
                        <button
                            type="button"
                            :class="isDark
                                ? 'flex items-center gap-2 rounded-full border border-slate-700 bg-slate-950/80 px-3 py-1.5 text-xs font-medium text-slate-200 transition hover:border-blue-500/50 hover:bg-slate-900'
                                : 'flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-blue-200 hover:bg-blue-50'"
                            @click="toggleFilterMenu"
                        >
                            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                                <path d="M3 5h18v2l-7 7v5l-4-2v-3L3 7V5z" />
                            </svg>
                            <span>{{ activeFilterLabel }}</span>
                            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current opacity-70" aria-hidden="true">
                                <path d="M7 10l5 5 5-5z" />
                            </svg>
                        </button>

                        <transition
                            enter-active-class="transition duration-150 ease-out"
                            enter-from-class="translate-y-2 opacity-0 scale-95"
                            enter-to-class="translate-y-0 opacity-100 scale-100"
                            leave-active-class="transition duration-120 ease-in"
                            leave-from-class="translate-y-0 opacity-100 scale-100"
                            leave-to-class="translate-y-2 opacity-0 scale-95"
                        >
                            <div
                                v-if="filterMenuOpen"
                                :class="isDark
                                    ? 'absolute right-0 top-[calc(100%+0.75rem)] z-30 w-64 rounded-3xl border border-slate-800 bg-slate-900 p-2 shadow-2xl'
                                    : 'absolute right-0 top-[calc(100%+0.75rem)] z-30 w-64 rounded-3xl border border-slate-200 bg-white p-2 shadow-2xl'"
                            >
                                <p :class="isDark ? 'px-3 pb-2 text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500' : 'px-3 pb-2 text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-400'">Filter messages</p>
                                <button
                                    v-for="option in filterOptions"
                                    :key="option.value"
                                    type="button"
                                    class="flex w-full items-center justify-between gap-3 rounded-2xl px-3 py-3 text-left transition"
                                    :class="messageFilter === option.value
                                        ? (isDark ? 'bg-blue-950/50 text-blue-200' : 'bg-blue-50 text-blue-700')
                                        : (isDark ? 'text-slate-200 hover:bg-slate-800' : 'text-slate-700 hover:bg-slate-100')"
                                    @click="setMessageFilter(option.value)"
                                >
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium">{{ option.label }}</span>
                                        <span :class="isDark ? 'mt-0.5 block text-xs text-slate-500' : 'mt-0.5 block text-xs text-slate-400'">{{ option.hint }}</span>
                                    </span>
                                    <svg v-if="messageFilter === option.value" viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current" aria-hidden="true">
                                        <path d="M9 16.2l-3.5-3.5L4 14.2 9 19l11-11-1.5-1.5z" />
                                    </svg>
                                </button>
                            </div>
                        </transition>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="panelToken ? route('emails.list', { token: panelToken }) : route('emails.list')"
                        :class="isDark
                            ? 'rounded-full border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 hover:bg-slate-800'
                            : 'rounded-full border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50'"
                    >
                        Mailboxes
                    </Link>
                    <button
                        type="button"
                        class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-full border border-slate-200 bg-slate-100 shadow-sm transition hover:scale-[1.02] hover:border-blue-300 dark:border-slate-700 dark:bg-slate-800"
                        @click="toggleAccountMenu"
                    >
                        <span v-if="mailbox.avatar_url" class="block h-full w-full bg-cover bg-center" :style="{ backgroundImage: `url(${mailbox.avatar_url})` }"></span>
                        <span v-else :class="isDark ? 'text-sm font-semibold text-slate-100' : 'text-sm font-semibold text-slate-700'">{{ mailbox.email?.slice(0, 1)?.toUpperCase() || 'M' }}</span>
                    </button>
                </div>

                <transition
                    enter-active-class="transition duration-150 ease-out"
                    enter-from-class="translate-y-2 opacity-0 scale-95"
                    enter-to-class="translate-y-0 opacity-100 scale-100"
                    leave-active-class="transition duration-120 ease-in"
                    leave-from-class="translate-y-0 opacity-100 scale-100"
                    leave-to-class="translate-y-2 opacity-0 scale-95"
                >
                    <div
                        v-if="accountMenuOpen"
                        :class="isDark
                            ? 'absolute right-4 top-[calc(100%+0.75rem)] z-40 w-[340px] rounded-[28px] border border-slate-800 bg-slate-900 p-4 shadow-2xl md:right-6'
                            : 'absolute right-4 top-[calc(100%+0.75rem)] z-40 w-[340px] rounded-[28px] border border-slate-200 bg-white p-4 shadow-2xl md:right-6'"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p :class="isDark ? 'text-xs uppercase tracking-[0.24em] text-slate-400' : 'text-xs uppercase tracking-[0.24em] text-slate-500'">Account</p>
                                <h2 :class="isDark ? 'mt-1 truncate text-lg font-semibold text-slate-100' : 'mt-1 truncate text-lg font-semibold text-slate-900'">{{ mailbox.email }}</h2>
                            </div>
                            <button type="button" :class="isDark ? 'rounded-full p-2 text-slate-300 hover:bg-slate-800' : 'rounded-full p-2 text-slate-600 hover:bg-slate-100'" @click="closeAccountMenu">
                                <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                    <path d="M18.3 5.7a1 1 0 00-1.4-1.4L12 9.17 7.1 4.3A1 1 0 105.7 5.7L10.59 10.6 5.7 15.5a1 1 0 101.4 1.4l4.9-4.89 4.9 4.89a1 1 0 001.4-1.4l-4.89-4.9 4.89-4.9z" />
                                </svg>
                            </button>
                        </div>

                        <div :class="isDark ? 'mt-4 rounded-[24px] border border-slate-800 bg-slate-950 p-4' : 'mt-4 rounded-[24px] border border-slate-200 bg-slate-50 p-4'">
                            <div class="flex items-center gap-4">
                                <div class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-full bg-blue-600 text-xl font-semibold text-white shadow-sm">
                                    <span v-if="mailbox.avatar_url" class="block h-full w-full bg-cover bg-center" :style="{ backgroundImage: `url(${mailbox.avatar_url})` }"></span>
                                    <span v-else>{{ mailbox.email?.slice(0, 1)?.toUpperCase() || 'M' }}</span>
                                </div>
                                <div class="min-w-0">
                                    <p :class="isDark ? 'text-sm font-semibold text-slate-100' : 'text-sm font-semibold text-slate-900'">Hi, {{ mailbox.email?.split('@')[0] || 'User' }}</p>
                                    <p :class="isDark ? 'mt-1 text-sm text-slate-400' : 'mt-1 text-sm text-slate-500'">{{ mailbox.domain || '-' }}</p>
                                </div>
                            </div>
                            <div :class="isDark ? 'mt-4 rounded-2xl bg-slate-900/70 px-4 py-3 text-sm text-slate-300' : 'mt-4 rounded-2xl bg-white px-4 py-3 text-sm text-slate-700'">
                                <div class="flex items-center justify-between gap-3">
                                    <span :class="isDark ? 'text-slate-400' : 'text-slate-500'">Quota used</span>
                                    <span class="font-medium">{{ mailbox.quota_mb }} MB</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 space-y-2">
                            <button
                                type="button"
                                :class="isDark
                                    ? 'flex w-full items-center justify-between gap-3 rounded-2xl border border-slate-800 bg-slate-950 px-4 py-3 text-left text-sm text-slate-200 hover:border-blue-500/60 hover:bg-blue-950/30'
                                    : 'flex w-full items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-left text-sm text-slate-700 hover:border-blue-200 hover:bg-blue-50'"
                                @click="toggleTheme"
                            >
                                <span>Theme</span>
                                <span :class="isDark ? 'text-xs text-slate-500' : 'text-xs text-slate-400'">{{ isDark ? 'Day Mode' : 'Night Mode' }}</span>
                            </button>

                            <button
                                v-for="item in relatedMailboxes"
                                :key="item.id"
                                type="button"
                                :class="isDark
                                    ? 'flex w-full items-center justify-between gap-3 rounded-2xl border border-slate-800 bg-slate-950 px-4 py-3 text-left text-sm text-slate-200 hover:border-blue-500/60 hover:bg-blue-950/30'
                                    : 'flex w-full items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-left text-sm text-slate-700 hover:border-blue-200 hover:bg-blue-50'"
                                @click="openMailbox(item.id)"
                            >
                                <span class="min-w-0 truncate">{{ item.email }}</span>
                                <span :class="isDark ? 'text-xs text-slate-500' : 'text-xs text-slate-400'">Switch</span>
                            </button>
                        </div>
                    </div>
                </transition>
            </div>
        </header>

        <div v-if="statusMessage" :class="isDark
            ? 'mx-4 mt-4 rounded-2xl border border-emerald-900/40 bg-emerald-950/30 px-4 py-3 text-sm text-emerald-200 md:mx-6'
            : 'mx-4 mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 md:mx-6'">
            {{ statusMessage }}
        </div>
        <main class="grid lg:grid-cols-[280px_minmax(0,1fr)]">
            <aside :class="isDark
                ? 'flex min-h-[calc(100vh-8rem)] flex-col border border-slate-800 bg-slate-900 shadow-sm'
                : 'flex min-h-[calc(100vh-8rem)] flex-col rounded-[28px] border border-slate-200 bg-white shadow-sm'">
                <div :class="isDark ? 'border-b border-slate-800 p-4' : 'border-b border-slate-200 p-4'">
                    <button type="button" :class="isDark
                        ? 'flex w-full items-center justify-center gap-2 rounded-2xl bg-blue-500 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-400'
                        : 'flex w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-700'" @click="startCompose">
                        <span class="text-lg leading-none">+</span>
                        Compose
                    </button>
                </div>

                <div class="flex-1 space-y-1 overflow-auto px-2 pb-3">
                    <button
                        v-for="folder in compactFolders"
                        :key="folder.name"
                        type="button"
                        class="flex w-full items-center justify-between rounded-2xl px-3 py-2 text-left text-sm transition"
                        :class="folder.name === activeFolder
                            ? (isDark ? 'bg-blue-950/50 font-semibold text-blue-200' : 'bg-blue-100 font-semibold text-blue-700')
                            : (isDark ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-700 hover:bg-slate-100')"
                        :disabled="loading"
                        @click="openFolder(folder)"
                    >
                        <span class="truncate">{{ folderLabel(folder.name) }}</span>
                        <span :class="isDark ? 'rounded-full bg-slate-800 px-2 py-0.5 text-xs text-slate-300' : 'rounded-full bg-slate-200 px-2 py-0.5 text-xs text-slate-600'">{{ folder.unread || 0 }}</span>
                    </button>
                </div>

                <div :class="isDark ? 'border-t border-slate-800 p-3' : 'border-t border-slate-200 p-3'">
                    <div :class="isDark ? 'rounded-2xl bg-slate-950 px-3 py-3 text-sm text-slate-300' : 'rounded-2xl bg-slate-50 px-3 py-3 text-sm text-slate-600'">
                        <div class="truncate font-medium" :class="isDark ? 'text-slate-100' : 'text-slate-900'">{{ mailbox.email }}</div>
                        <div class="mt-1 truncate text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ mailbox.domain || '-' }}</div>
                        <div class="mt-2 text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Quota: {{ mailbox.quota_mb }} MB</div>
                    </div>
                </div>
            </aside>

            <section :class="isDark
                ? 'relative flex min-h-[calc(100vh-4rem)] flex-col overflow-hidden border border-slate-800 bg-slate-900 shadow-sm'
                : 'relative flex min-h-[calc(100vh-8rem)] flex-col overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-sm'">
                <div :class="isDark ? 'border-b border-slate-800 px-4 py-3' : 'border-b border-slate-200 px-4 py-3'">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p :class="isDark ? 'text-xs font-medium uppercase tracking-[0.22em] text-slate-400' : 'text-xs font-medium uppercase tracking-[0.22em] text-slate-500'">Inbox</p>
                            <h2 :class="isDark ? 'mt-1 text-xl font-semibold text-slate-100' : 'mt-1 text-xl font-semibold text-slate-900'">{{ activeFolder }}</h2>
                        </div>
                        <div :class="isDark ? 'text-sm text-slate-400' : 'text-sm text-slate-500'">{{ filteredMessages.length }} messages</div>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-auto">
                    <div
                        v-for="item in filteredMessages"
                        :key="item.uid"
                        class="cursor-pointer border-b px-4 py-4 transition"
                        :class="currentMessage?.uid === item.uid
                            ? (isDark ? 'border-slate-800 bg-blue-950/30' : 'border-slate-100 bg-blue-50/70')
                            : (isDark ? 'border-slate-800 hover:bg-slate-800/70' : 'border-slate-100 hover:bg-slate-50')"
                        @click="openMessage(item.uid)"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p :class="isDark ? 'truncate text-sm font-semibold text-slate-100' : 'truncate text-sm font-semibold text-slate-900'">
                                        {{ item.from || 'Unknown sender' }}
                                    </p>
                                    <span v-if="!item.seen" class="h-2.5 w-2.5 rounded-full bg-blue-600"></span>
                                </div>
                                <p :class="isDark ? 'mt-1 truncate text-sm text-slate-300' : 'mt-1 truncate text-sm text-slate-700'">
                                    <span :class="isDark ? 'font-medium text-slate-100' : 'font-medium text-slate-900'">{{ item.subject || '(no subject)' }}</span>
                                    <span :class="isDark ? 'text-slate-400' : 'text-slate-500'"> - {{ item.snippet || 'No preview available.' }}</span>
                                </p>
                            </div>
                            <div :class="isDark ? 'shrink-0 text-right text-xs text-slate-400' : 'shrink-0 text-right text-xs text-slate-500'">
                                <div>{{ formatDate(item.date) }}</div>
                                <div class="mt-1">{{ Math.max(1, Math.round((item.size || 0) / 1024)) }} KB</div>
                            </div>
                        </div>
                    </div>

                    <div v-if="loading" :class="isDark ? 'px-6 py-12 text-center text-sm text-slate-400' : 'px-6 py-12 text-center text-sm text-slate-500'">
                        Loading messages...
                    </div>
                    <div v-else-if="filteredMessages.length === 0" :class="isDark ? 'px-6 py-12 text-center text-sm text-slate-400' : 'px-6 py-12 text-center text-sm text-slate-500'">
                        No messages in this folder.
                    </div>
                </div>

                <transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="translate-x-full opacity-0"
                    enter-to-class="translate-x-0 opacity-100"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="translate-x-0 opacity-100"
                    leave-to-class="translate-x-full opacity-0"
                >
                    <div v-if="currentMessage" :class="isDark
                        ? 'absolute inset-0 z-20 flex flex-col border-l border-slate-800 bg-slate-900 shadow-2xl lg:w-[48%] lg:left-auto lg:right-0'
                        : 'absolute inset-0 z-20 flex flex-col border-l border-slate-200 bg-white shadow-2xl lg:w-[48%] lg:left-auto lg:right-0'">
                        <div :class="isDark ? 'border-b border-slate-800 px-4 py-3' : 'border-b border-slate-200 px-4 py-3'">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p :class="isDark ? 'text-xs font-medium uppercase tracking-[0.22em] text-slate-400' : 'text-xs font-medium uppercase tracking-[0.22em] text-slate-500'">Preview</p>
                                    <h2 :class="isDark ? 'mt-1 truncate text-lg font-semibold text-slate-100' : 'mt-1 truncate text-lg font-semibold text-slate-900'">{{ currentMessage?.subject || 'Select a message' }}</h2>
                                </div>
                                <button type="button" :class="isDark
                                    ? 'rounded-full border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800'
                                    : 'rounded-full border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-100'" @click="closePreview">
                                    Close
                                </button>
                            </div>
                        </div>

                        <div class="min-h-0 flex-1 overflow-auto p-4">
                            <div class="space-y-4">
                                <div :class="isDark ? 'rounded-2xl border border-slate-800 bg-slate-950 p-4' : 'rounded-2xl border border-slate-200 bg-slate-50 p-4'">
                                    <div :class="isDark ? 'grid gap-2 text-sm text-slate-300' : 'grid gap-2 text-sm text-slate-700'">
                                        <div><span :class="isDark ? 'font-medium text-slate-400' : 'font-medium text-slate-500'">From:</span> {{ currentMessage.from || '-' }}</div>
                                        <div><span :class="isDark ? 'font-medium text-slate-400' : 'font-medium text-slate-500'">Date:</span> {{ formatDate(currentMessage.date) }}</div>
                                        <div><span :class="isDark ? 'font-medium text-slate-400' : 'font-medium text-slate-500'">Folder:</span> {{ activeFolder }}</div>
                                    </div>
                                    <div class="mt-4 flex flex-wrap gap-2">
                                        <button type="button" :class="isDark
                                            ? 'rounded-full border border-slate-700 px-3 py-2 text-xs font-medium text-slate-200 hover:bg-slate-800'
                                            : 'rounded-full border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-100'" @click="startCompose">Reply</button>
                                        <button
                                            type="button"
                                            :class="isDark
                                                ? 'rounded-full border border-rose-900/50 px-3 py-2 text-xs font-medium text-rose-200 hover:bg-rose-950/40'
                                                : 'rounded-full border border-rose-200 px-3 py-2 text-xs font-medium text-rose-700 hover:bg-rose-50'"
                                            :disabled="deletingUid === currentMessage.uid"
                                            @click="deleteMessage(currentMessage.uid)"
                                        >
                                            {{ deletingUid === currentMessage.uid ? 'Deleting...' : 'Delete' }}
                                        </button>
                                    </div>
                                </div>

                                <pre :class="isDark
                                    ? 'whitespace-pre-wrap rounded-2xl border border-slate-800 bg-slate-950 p-4 text-sm leading-6 text-slate-200'
                                    : 'whitespace-pre-wrap rounded-2xl border border-slate-200 bg-white p-4 text-sm leading-6 text-slate-800'">{{ currentMessage.text || currentMessage.raw_body || 'No body available.' }}</pre>
                            </div>
                        </div>
                    </div>
                </transition>

                <div v-if="!currentMessage" class="min-h-0 flex-1 overflow-auto p-4">
                    <div :class="isDark ? 'flex h-full items-center justify-center text-center text-slate-400' : 'flex h-full items-center justify-center text-center text-slate-500'">
                        <div>
                            <p :class="isDark ? 'text-sm font-medium text-slate-200' : 'text-sm font-medium text-slate-700'">No message selected</p>
                            <p class="mt-2 text-sm">Choose a message to preview it here.</p>
                        </div>
                    </div>
                </div>
            </section>

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

        <div class="fixed bottom-4 right-4 z-[60] w-[calc(100vw-2rem)] max-w-sm space-y-3">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                :class="toast.type === 'error'
                    ? 'rounded-2xl border border-rose-900/40 bg-rose-950/95 px-4 py-3 text-sm text-rose-100 shadow-2xl backdrop-blur'
                    : 'rounded-2xl border border-emerald-900/40 bg-emerald-950/95 px-4 py-3 text-sm text-emerald-100 shadow-2xl backdrop-blur'"
            >
                <div class="flex items-start justify-between gap-3">
                    <p class="pr-2 leading-5">{{ toast.message }}</p>
                    <button
                        type="button"
                        class="shrink-0 rounded-full p-1 text-current/80 hover:bg-white/10 hover:text-white"
                        @click="removeToast(toast.id)"
                        aria-label="Dismiss"
                    >
                        <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                            <path d="M18.3 5.7a1 1 0 00-1.4-1.4L12 9.17 7.1 4.3A1 1 0 105.7 5.7L10.59 10.6 5.7 15.5a1 1 0 101.4 1.4l4.9-4.89 4.9 4.89a1 1 0 001.4-1.4l-4.89-4.9 4.89-4.9z" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
