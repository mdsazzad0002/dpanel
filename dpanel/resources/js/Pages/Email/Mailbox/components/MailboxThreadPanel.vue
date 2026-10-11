<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { folderLabel, isOutgoingFolder } from './mailboxFolders';
import MailAvatar from './MailAvatar.vue';

const props = defineProps({
    activeFolder: {
        type: String,
        default: 'INBOX',
    },
    filteredMessages: {
        type: Array,
        default: () => [],
    },
    currentMessage: {
        type: Object,
        default: null,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    messageLoading: {
        type: Boolean,
        default: false,
    },
    deletingUid: {
        type: [String, Number, null],
        default: null,
    },
    isDark: {
        type: Boolean,
        default: false,
    },
    hasSearchQuery: {
        type: Boolean,
        default: false,
    },
    page: {
        type: Number,
        default: 1,
    },
    totalPages: {
        type: Number,
        default: 1,
    },
    perPage: {
        type: Number,
        default: 50,
    },
    totalCount: {
        type: Number,
        default: 0,
    },
    selectedUids: {
        type: Array,
        default: () => [],
    },
    bulkWorking: {
        type: Boolean,
        default: false,
    },
    selfEmail: {
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
});

const emit = defineEmits(['open-message', 'close-preview', 'start-compose', 'delete-message', 'reply', 'reply-all', 'forward', 'toggle-read', 'change-page', 'toggle-select', 'select-all', 'bulk-action']);

const ui = computed(() => (props.isDark
    ? {
        surface: 'bg-slate-950',
        toolbar: 'border-slate-800 bg-slate-950',
        divider: 'border-slate-800',
        row: 'border-slate-800/70 hover:bg-slate-800/70',
        rowActive: 'bg-blue-500/15',
        rowSelected: 'bg-blue-500/20',
        rowUnread: 'bg-slate-900',
        rowRead: 'bg-slate-950',
        checkbox: 'border-slate-600 bg-slate-900 text-blue-500 focus:ring-blue-500/40 focus:ring-offset-slate-950',
        muted: 'text-slate-500',
        text: 'text-slate-400',
        strong: 'text-slate-100',
        body: 'text-slate-300',
        iconButton: 'text-slate-400 hover:bg-slate-800 hover:text-slate-100',
        hoverActions: 'bg-slate-900',
        panel: 'bg-slate-950 border-slate-800',
        canvas: 'bg-slate-900/60',
        card: 'border-slate-800 bg-slate-950 shadow-black/20',
        cardDivider: 'border-slate-800',
        pill: 'border-slate-700 text-slate-200 hover:bg-slate-800 hover:border-slate-600',
        chip: 'bg-slate-800 text-slate-300',
        skeleton: 'bg-slate-800',
    }
    : {
        surface: 'bg-white',
        toolbar: 'border-slate-200 bg-white',
        divider: 'border-slate-200',
        row: 'border-slate-200/70 hover:bg-slate-100',
        rowActive: 'bg-blue-100/70',
        rowSelected: 'bg-blue-100',
        rowUnread: 'bg-sky-50',
        rowRead: 'bg-white',
        checkbox: 'border-slate-300 text-blue-600 focus:ring-blue-500/40',
        muted: 'text-slate-500',
        text: 'text-slate-600',
        strong: 'text-slate-900',
        body: 'text-slate-800',
        iconButton: 'text-slate-500 hover:bg-slate-100 hover:text-slate-900',
        hoverActions: 'bg-slate-50',
        panel: 'bg-white border-slate-200',
        canvas: 'bg-slate-50',
        card: 'border-slate-200 bg-white shadow-slate-200/60',
        cardDivider: 'border-slate-100',
        pill: 'border-slate-300 text-slate-700 hover:bg-slate-50 hover:border-slate-400',
        chip: 'bg-slate-100 text-slate-600',
        skeleton: 'bg-slate-200',
    }));

const selectedSet = computed(() => new Set(props.selectedUids));
const selectedCount = computed(() => props.selectedUids.length);
const selectionMode = computed(() => selectedCount.value > 0);
const allSelected = computed(() => props.filteredMessages.length > 0 && props.filteredMessages.every((m) => selectedSet.value.has(m.uid)));
const someSelected = computed(() => selectionMode.value && !allSelected.value);
const selectedHaveUnread = computed(() => props.filteredMessages.some((m) => selectedSet.value.has(m.uid) && !m.seen));
const selectedHaveRead = computed(() => props.filteredMessages.some((m) => selectedSet.value.has(m.uid) && m.seen));

const rowClass = (item) => {
    if (selectedSet.value.has(item.uid)) return ui.value.rowSelected;
    if (props.currentMessage?.uid === item.uid) return ui.value.rowActive;

    return item.seen ? ui.value.rowRead : ui.value.rowUnread;
};

const rangeStart = computed(() => (props.totalCount === 0 ? 0 : (props.page - 1) * props.perPage + 1));
const rangeEnd = computed(() => Math.min(props.page * props.perPage, props.totalCount));

const currentIndex = computed(() => props.filteredMessages.findIndex((m) => m.uid === props.currentMessage?.uid));
const previousMessage = computed(() => (currentIndex.value > 0 ? props.filteredMessages[currentIndex.value - 1] : null));
const nextMessage = computed(() => (currentIndex.value >= 0 && currentIndex.value < props.filteredMessages.length - 1 ? props.filteredMessages[currentIndex.value + 1] : null));

const parseDate = (value) => {
    if (!value) return null;
    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? null : parsed;
};

const formatListDate = (value) => {
    const parsed = parseDate(value);
    if (!parsed) return value || '';
    const now = new Date();
    if (parsed.toDateString() === now.toDateString()) {
        return parsed.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    return parsed.toLocaleDateString([], { month: 'short', day: 'numeric', year: parsed.getFullYear() !== now.getFullYear() ? 'numeric' : undefined });
};

const formatFullDate = (value) => {
    const parsed = parseDate(value);
    if (!parsed) return value || '';

    return parsed.toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
};

const senderName = (from) => {
    if (!from) return 'Unknown';
    const match = from.match(/^"?(.+?)"?\s*</);

    return match ? match[1].trim() : from.replace(/<.*>/, '').trim() || 'Unknown';
};

const senderEmail = (from) => {
    if (!from) return '';
    const match = from.match(/<(.+?)>/);

    return match ? match[1] : from;
};

const senderInitials = (from) => {
    const parts = senderName(from).split(/[\s@._-]+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();

    return (parts[0] || '?').slice(0, 2).toUpperCase();
};

// Like Gmail: a row shows the other party — the recipient for mail this
// mailbox sent (Sent/Drafts/Outbox, or its own mail found in any other folder).
const showsRecipient = (item) => isOutgoingFolder(props.activeFolder)
    || (props.selfEmail !== '' && senderEmail(item?.from).trim().toLowerCase() === props.selfEmail.trim().toLowerCase());

const recipientList = (to) => String(to || '').split(',').map((part) => part.trim()).filter(Boolean);

const counterpartLabel = (item) => {
    if (!showsRecipient(item)) return senderName(item.from);
    const names = recipientList(item.to).map(senderName);

    return `To: ${names.length ? names.join(', ') : '(no recipient)'}`;
};

const counterpartAddress = (item) => (showsRecipient(item) ? recipientList(item.to)[0] || '' : item.from);

const avatarColors = ['bg-blue-600', 'bg-emerald-600', 'bg-violet-600', 'bg-amber-600', 'bg-rose-600', 'bg-cyan-600', 'bg-indigo-600', 'bg-teal-600', 'bg-orange-600', 'bg-fuchsia-600'];
const avatarColor = (from) => {
    const value = senderEmail(from) || String(from || '');
    let hash = 0;
    for (let i = 0; i < value.length; i += 1) hash = (hash * 31 + value.charCodeAt(i)) >>> 0;

    return avatarColors[hash % avatarColors.length];
};

const handleKeydown = (event) => {
    if (!props.currentMessage) return;
    const tag = String(event.target?.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || event.target?.isContentEditable) return;

    if (event.key === 'Escape') {
        emit('close-preview');
    }
    else if (event.key === 'k' && previousMessage.value) {
        emit('open-message', previousMessage.value.uid);
    }
    else if (event.key === 'j' && nextMessage.value) {
        emit('open-message', nextMessage.value.uid);
    }
};

onMounted(() => document.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', handleKeydown));

// HTML mail is shown in a sandboxed iframe (no scripts) so its own styles
// cannot leak into the panel; same-origin only lets us measure its height.
const htmlFrame = ref(null);
const htmlFrameHeight = ref(200);
let htmlFrameObserver = null;

const htmlDocument = computed(() => {
    const html = props.currentMessage?.html;
    if (!html) return '';
    return `<!doctype html><html><head><meta charset="utf-8">`
        + `<meta http-equiv="Content-Security-Policy" content="script-src 'none'; object-src 'none'; frame-src 'none'; form-action 'none'">`
        + `<base target="_blank">`
        + `<style>html,body{margin:0;padding:0;background:#fff;color:#1f2937;}body{padding:20px 24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;word-wrap:break-word;overflow-wrap:break-word;}img{max-width:100%;height:auto;}table{max-width:100%;}pre{white-space:pre-wrap;}a{color:#2563eb;}</style>`
        + `</head><body>${html}</body></html>`;
});

const htmlFrameReady = ref(false);
let htmlFrameWatchedDoc = null;
let htmlFramePoll = 0;

const resizeHtmlFrame = () => {
    const doc = htmlFrame.value?.contentDocument;
    if (!doc?.documentElement) return;
    // offsetHeight follows the content, so the frame can shrink as well as grow.
    htmlFrameHeight.value = Math.max(80, Math.ceil(doc.documentElement.offsetHeight));
};

// The iframe's load event waits for every image, which can take a while through
// the image proxy. Size the frame as soon as the HTML is parsed instead and let
// it grow as each image arrives.
const watchHtmlFrame = () => {
    const doc = htmlFrame.value?.contentDocument;
    if (!doc?.body || doc.URL !== 'about:srcdoc' || doc === htmlFrameWatchedDoc) return;

    htmlFrameWatchedDoc = doc;
    htmlFrameReady.value = true;
    resizeHtmlFrame();

    htmlFrameObserver?.disconnect();
    if (typeof ResizeObserver !== 'undefined') {
        htmlFrameObserver = new ResizeObserver(resizeHtmlFrame);
        htmlFrameObserver.observe(doc.documentElement);
    }
    // Image load/error events do not bubble, but they reach capturing listeners.
    doc.addEventListener('load', resizeHtmlFrame, true);
    doc.addEventListener('error', resizeHtmlFrame, true);
};

const pollHtmlFrame = () => {
    cancelAnimationFrame(htmlFramePoll);
    const startedAt = performance.now();
    const tick = () => {
        watchHtmlFrame();
        if (htmlFrameWatchedDoc === htmlFrame.value?.contentDocument || performance.now() - startedAt > 5000) return;
        htmlFramePoll = requestAnimationFrame(tick);
    };
    htmlFramePoll = requestAnimationFrame(tick);
};

const onHtmlFrameLoad = () => {
    watchHtmlFrame();
    resizeHtmlFrame();
};

// HTML / plain text views, plus the message source ("Show original") and its
// header analysis. The source is fetched only when one of those tabs opens.
const bodyView = ref('html');
const showsHtml = computed(() => bodyView.value === 'html' && htmlDocument.value !== '');
const showsBody = computed(() => bodyView.value === 'html' || bodyView.value === 'text');
const plainText = computed(() => props.currentMessage?.text || props.currentMessage?.raw_body || '');

const bodyTabs = computed(() => [
    ...(htmlDocument.value ? [{ id: 'html', label: 'HTML', icon: 'bi-filetype-html' }] : []),
    { id: 'text', label: 'Plain text', icon: 'bi-text-left' },
    ...(props.rawEndpoint ? [
        { id: 'analysis', label: 'Analysis', icon: 'bi-shield-check' },
        { id: 'raw', label: 'Original', icon: 'bi-code-slash' },
    ] : []),
]);
const activeTab = computed(() => (bodyView.value === 'html' && !htmlDocument.value ? 'text' : bodyView.value));

const messageKey = computed(() => (props.currentMessage ? `${props.currentMessage.folder || props.activeFolder}:${props.currentMessage.uid}` : ''));
const sourceCache = new Map();
const source = ref(null);
const sourceLoading = ref(false);
const sourceError = ref('');
const showAllHeaders = ref(false);
const copied = ref(false);

const loadSource = async () => {
    const key = messageKey.value;
    if (!key || !props.rawEndpoint) return;
    if (sourceCache.has(key)) {
        source.value = sourceCache.get(key);
        return;
    }
    sourceLoading.value = true;
    sourceError.value = '';
    try {
        const { data } = await window.axios.get(props.rawEndpoint, {
            params: { folder: props.currentMessage.folder || props.activeFolder, uid: props.currentMessage.uid },
            headers: { Accept: 'application/json' },
        });
        if (!data?.success) throw new Error(data?.message || 'Message source could not be loaded.');
        sourceCache.set(key, data);
        if (messageKey.value === key) source.value = data;
    } catch (error) {
        if (messageKey.value === key) sourceError.value = error?.response?.data?.message || error?.message || 'Message source could not be loaded.';
    } finally {
        sourceLoading.value = false;
    }
};

const selectTab = (id) => {
    bodyView.value = id;
    if (id === 'analysis' || id === 'raw') loadSource();
};

watch(messageKey, () => {
    bodyView.value = 'html';
    source.value = null;
    sourceError.value = '';
    showAllHeaders.value = false;
});

const downloadHref = computed(() => {
    if (!props.rawDownloadEndpoint || !props.currentMessage) return '';
    const params = new URLSearchParams({ folder: props.currentMessage.folder || props.activeFolder, uid: String(props.currentMessage.uid) });

    return `${props.rawDownloadEndpoint}${props.rawDownloadEndpoint.includes('?') ? '&' : '?'}${params}`;
});

const copySource = async () => {
    try {
        await navigator.clipboard.writeText(source.value?.raw || '');
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch {
        copied.value = false;
    }
};

const analysis = computed(() => source.value?.analysis || null);
const spamCheck = computed(() => analysis.value?.spam_check || null);
const spamStyle = computed(() => ({
    spam: {
        title: 'Likely spam or phishing',
        hint: 'Do not open links or attachments, and never reply with passwords or payment details.',
        icon: 'bi-shield-exclamation',
        box: 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-100',
        bar: 'bg-rose-500',
    },
    suspicious: {
        title: 'Suspicious',
        hint: 'Some spam signs. Check the sender before trusting links or attachments.',
        icon: 'bi-shield-fill-exclamation',
        box: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100',
        bar: 'bg-amber-500',
    },
    clean: {
        title: 'Looks clean',
        hint: 'No strong spam or phishing signs in this message.',
        icon: 'bi-shield-check',
        box: 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100',
        bar: 'bg-emerald-500',
    },
}[spamCheck.value?.verdict] || {}));

// Gmail-style verdict colours: pass green, fail red, anything else neutral.
const verdictClass = (result) => {
    const value = String(result || '').toLowerCase();
    if (value === 'pass') return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300';
    if (['fail', 'softfail', 'permerror', 'temperror'].includes(value)) return 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300';

    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
};
const verdictLabel = (result) => {
    const value = String(result || '').toLowerCase();
    if (value === 'none' || value === '') return 'Not checked';
    if (value === 'unverified') return 'Signed, not verified';

    return value.toUpperCase();
};

const formatBytes = (bytes) => {
    const value = Number(bytes || 0);
    if (value < 1024) return `${value} B`;
    if (value < 1048576) return `${(value / 1024).toFixed(1)} KB`;

    return `${(value / 1048576).toFixed(1)} MB`;
};

const formatDelay = (seconds) => {
    if (seconds === null || seconds === undefined) return '';
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;

    return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
};

const resetHtmlFrame = () => {
    htmlFrameObserver?.disconnect();
    htmlFrameWatchedDoc = null;
    htmlFrameReady.value = false;
    nextTick(pollHtmlFrame);
};

watch(htmlDocument, () => {
    htmlFrameHeight.value = 200;
    bodyView.value = 'html';
    resetHtmlFrame();
});
watch(showsHtml, (visible) => { if (visible) resetHtmlFrame(); });
onMounted(() => { if (showsHtml.value) resetHtmlFrame(); });
onBeforeUnmount(() => {
    htmlFrameObserver?.disconnect();
    cancelAnimationFrame(htmlFramePoll);
});
</script>

<template>
    <section :class="[ui.surface, 'flex h-full min-h-0 flex-col']">
        <!-- List toolbar -->
        <div :class="[ui.toolbar, 'flex h-11 shrink-0 items-center justify-between gap-3 border-b px-3 md:px-4']">
            <div class="flex min-w-0 items-center gap-1">
                <label :class="[ui.iconButton, 'flex h-8 w-8 cursor-pointer items-center justify-center rounded-md']" :title="allSelected ? 'Deselect all' : 'Select all'">
                    <input
                        type="checkbox"
                        :class="[ui.checkbox, 'h-4 w-4 cursor-pointer rounded']"
                        :checked="allSelected"
                        :indeterminate="someSelected"
                        :disabled="filteredMessages.length === 0"
                        aria-label="Select all messages on this page"
                        @change="emit('select-all', !allSelected)"
                    >
                </label>

                <template v-if="selectionMode">
                    <span :class="[ui.strong, 'ml-1 mr-2 whitespace-nowrap text-sm font-medium tabular-nums']">{{ selectedCount }} selected</span>
                    <button
                        v-if="selectedHaveUnread"
                        type="button"
                        :class="[ui.iconButton, 'flex h-8 items-center gap-1.5 rounded-md px-2 text-sm transition disabled:opacity-50']"
                        :disabled="bulkWorking"
                        title="Mark as read"
                        @click="emit('bulk-action', 'read')"
                    >
                        <i class="bi bi-envelope-open text-[15px]"></i><span class="hidden md:inline">Mark read</span>
                    </button>
                    <button
                        v-if="selectedHaveRead"
                        type="button"
                        :class="[ui.iconButton, 'flex h-8 items-center gap-1.5 rounded-md px-2 text-sm transition disabled:opacity-50']"
                        :disabled="bulkWorking"
                        title="Mark as unread"
                        @click="emit('bulk-action', 'unread')"
                    >
                        <i class="bi bi-envelope text-[15px]"></i><span class="hidden md:inline">Mark unread</span>
                    </button>
                    <button
                        type="button"
                        :class="[ui.iconButton, 'flex h-8 items-center gap-1.5 rounded-md px-2 text-sm transition hover:!text-rose-600 disabled:opacity-50']"
                        :disabled="bulkWorking"
                        title="Delete"
                        @click="emit('bulk-action', 'delete')"
                    >
                        <i :class="['bi text-[15px]', bulkWorking ? 'bi-arrow-repeat animate-spin' : 'bi-trash3']"></i><span class="hidden md:inline">Delete</span>
                    </button>
                    <button
                        type="button"
                        :class="[ui.iconButton, 'ml-1 flex h-8 w-8 items-center justify-center rounded-md transition']"
                        title="Clear selection"
                        aria-label="Clear selection"
                        @click="emit('select-all', false)"
                    >
                        <i class="bi bi-x-lg text-xs"></i>
                    </button>
                </template>
                <template v-else>
                    <span :class="[ui.strong, 'ml-1 truncate text-sm font-medium sm:hidden']">{{ folderLabel(activeFolder) }}</span>
                    <span v-if="hasSearchQuery" :class="[ui.chip, 'ml-1 rounded px-1.5 py-0.5 text-[11px] font-medium']">Search results</span>
                </template>
            </div>

            <div class="flex items-center gap-1">
                <span :class="[ui.muted, 'mr-1 text-xs tabular-nums', selectionMode ? 'hidden sm:inline' : '']">
                    {{ rangeStart }}–{{ rangeEnd }} of {{ totalCount }}
                </span>
                <button
                    type="button"
                    :class="[ui.iconButton, 'flex h-8 w-8 items-center justify-center rounded-md transition disabled:pointer-events-none disabled:opacity-30']"
                    :disabled="page <= 1"
                    aria-label="Newer"
                    title="Newer"
                    @click="emit('change-page', page - 1)"
                >
                    <i class="bi bi-chevron-left text-sm"></i>
                </button>
                <button
                    type="button"
                    :class="[ui.iconButton, 'flex h-8 w-8 items-center justify-center rounded-md transition disabled:pointer-events-none disabled:opacity-30']"
                    :disabled="page >= totalPages"
                    aria-label="Older"
                    title="Older"
                    @click="emit('change-page', page + 1)"
                >
                    <i class="bi bi-chevron-right text-sm"></i>
                </button>
            </div>
        </div>

        <!-- List -->
        <div class="min-h-0 flex-1 overflow-y-auto">
            <!-- Loading skeleton -->
            <div v-if="loading && filteredMessages.length === 0" aria-busy="true">
                <div v-for="n in 8" :key="n" :class="[ui.divider, 'flex items-center gap-3 border-b px-4 py-3']">
                    <div :class="[ui.skeleton, 'h-8 w-8 shrink-0 animate-pulse rounded-full']"></div>
                    <div class="flex-1 space-y-2">
                        <div :class="[ui.skeleton, 'h-3 w-1/4 animate-pulse rounded']"></div>
                        <div :class="[ui.skeleton, 'h-3 w-2/3 animate-pulse rounded']"></div>
                    </div>
                </div>
            </div>

            <!-- Empty -->
            <div v-else-if="filteredMessages.length === 0" class="flex h-full flex-col items-center justify-center px-6 py-16 text-center">
                <i :class="[ui.muted, 'bi text-4xl', hasSearchQuery ? 'bi-search' : 'bi-inbox']"></i>
                <p :class="[ui.strong, 'mt-3 text-sm font-medium']">
                    {{ hasSearchQuery ? 'No messages match your search' : `${folderLabel(activeFolder)} is empty` }}
                </p>
                <p :class="[ui.muted, 'mt-1 text-xs']">
                    {{ hasSearchQuery ? 'Try a different keyword or clear the filter.' : 'New messages will show up here.' }}
                </p>
            </div>

            <!-- Rows -->
            <ul v-else role="list">
                <li
                    v-for="item in filteredMessages"
                    :key="item.uid"
                    :class="[
                        'group relative flex cursor-pointer items-center gap-3 border-b px-3 py-2.5 transition-colors md:px-4',
                        ui.row,
                        rowClass(item),
                    ]"
                    :aria-current="currentMessage?.uid === item.uid ? 'true' : undefined"
                    @click="emit('open-message', item.uid)"
                >
                    <span v-if="!item.seen" class="absolute inset-y-0 left-0 w-[3px] bg-blue-600"></span>

                    <!-- Avatar turns into a checkbox on hover or while selecting -->
                    <span class="relative flex h-8 w-8 shrink-0 items-center justify-center" @click.stop="emit('toggle-select', item.uid)">
                        <MailAvatar
                            :email="senderEmail(counterpartAddress(item))"
                            :initials="senderInitials(counterpartAddress(item))"
                            :color-class="avatarColor(counterpartAddress(item))"
                            :endpoint="avatarEndpoint"
                            :class="[
                                'h-8 w-8 text-[11px] transition-opacity',
                                selectionMode || selectedSet.has(item.uid) ? 'opacity-0' : 'group-hover:opacity-0',
                            ]"
                        />
                        <input
                            type="checkbox"
                            :class="[
                                ui.checkbox,
                                'absolute h-4 w-4 cursor-pointer rounded transition-opacity',
                                selectionMode || selectedSet.has(item.uid) ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus:opacity-100',
                            ]"
                            :checked="selectedSet.has(item.uid)"
                            :aria-label="`Select message: ${counterpartLabel(item)}`"
                            @click.stop
                            @change="emit('toggle-select', item.uid)"
                        >
                    </span>

                    <!-- Wide: single line. Narrow: two lines. -->
                    <div class="min-w-0 flex-1 lg:flex lg:items-center lg:gap-4">
                        <div class="flex items-center justify-between gap-2 lg:w-52 lg:shrink-0">
                            <p :class="['truncate text-sm', item.seen ? ['font-normal', ui.text] : ['font-semibold', ui.strong]]" :title="showsRecipient(item) ? item.to : item.from">
                                {{ counterpartLabel(item) }}
                            </p>
                            <span :class="[item.seen ? ui.muted : 'font-semibold text-blue-600', 'shrink-0 text-xs tabular-nums lg:hidden']">
                                {{ formatListDate(item.date) }}
                            </span>
                        </div>
                        <p class="min-w-0 flex-1 truncate text-sm">
                            <span :class="item.seen ? ui.text : ['font-semibold', ui.strong]">{{ item.subject || '(no subject)' }}</span>
                            <span v-if="item.snippet" :class="ui.muted"> — {{ item.snippet }}</span>
                        </p>
                    </div>

                    <!-- Date (wide) / hover actions -->
                    <div class="relative hidden shrink-0 items-center justify-end lg:flex lg:w-24">
                        <span :class="[item.seen ? ui.muted : ['font-semibold', ui.strong], 'text-xs tabular-nums group-hover:invisible']">
                            {{ formatListDate(item.date) }}
                        </span>
                        <div :class="[ui.hoverActions, 'absolute right-0 hidden items-center gap-0.5 rounded-md group-hover:flex']">
                            <button
                                type="button"
                                :class="[ui.iconButton, 'flex h-8 w-8 items-center justify-center rounded-md']"
                                :title="item.seen ? 'Mark as unread' : 'Mark as read'"
                                :aria-label="item.seen ? 'Mark as unread' : 'Mark as read'"
                                @click.stop="emit('toggle-read', item)"
                            >
                                <i :class="['bi text-[15px]', item.seen ? 'bi-envelope' : 'bi-envelope-open']"></i>
                            </button>
                            <button
                                type="button"
                                :class="[ui.iconButton, 'flex h-8 w-8 items-center justify-center rounded-md hover:!text-rose-600']"
                                title="Delete"
                                aria-label="Delete"
                                :disabled="deletingUid === item.uid"
                                @click.stop="emit('delete-message', item.uid)"
                            >
                                <i :class="['bi text-[15px]', deletingUid === item.uid ? 'bi-hourglass-split' : 'bi-trash3']"></i>
                            </button>
                        </div>
                    </div>
                </li>
            </ul>
        </div>

        <!-- Reading offcanvas -->
        <transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150"
            leave-to-class="opacity-0"
        >
            <div
                v-if="currentMessage"
                class="fixed inset-0 z-40 bg-slate-950/40"
                aria-hidden="true"
                @click="emit('close-preview')"
            ></div>
        </transition>

        <transition
            enter-active-class="transition-transform duration-200 ease-out"
            enter-from-class="translate-x-full"
            leave-active-class="transition-transform duration-150 ease-in"
            leave-to-class="translate-x-full"
        >
            <aside
                v-if="currentMessage"
                role="dialog"
                aria-modal="true"
                :aria-label="currentMessage.subject || 'Message'"
                :aria-busy="messageLoading"
                :class="[ui.panel, 'fixed inset-y-0 right-0 z-50 flex w-full flex-col border-l shadow-2xl md:w-[85%] lg:w-[70%]']"
            >
                <!-- Toolbar -->
                <div :class="[ui.divider, 'flex h-14 shrink-0 items-center gap-1 border-b px-2 md:px-4']">
                    <button
                        type="button"
                        :class="[ui.iconButton, 'flex h-9 w-9 items-center justify-center rounded-md transition']"
                        title="Close (Esc)"
                        aria-label="Close"
                        @click="emit('close-preview')"
                    >
                        <i class="bi bi-arrow-left text-lg"></i>
                    </button>

                    <span :class="[ui.divider, 'mx-1 h-6 border-l']"></span>

                    <button type="button" :class="[ui.iconButton, 'flex h-9 items-center gap-2 rounded-md px-2.5 text-sm transition']" title="Reply" @click="emit('reply', currentMessage)">
                        <i class="bi bi-reply text-base"></i><span class="hidden sm:inline">Reply</span>
                    </button>
                    <button type="button" :class="[ui.iconButton, 'flex h-9 items-center gap-2 rounded-md px-2.5 text-sm transition']" title="Reply all" @click="emit('reply-all', currentMessage)">
                        <i class="bi bi-reply-all text-base"></i><span class="hidden md:inline">Reply all</span>
                    </button>
                    <button type="button" :class="[ui.iconButton, 'flex h-9 items-center gap-2 rounded-md px-2.5 text-sm transition']" title="Forward" @click="emit('forward', currentMessage)">
                        <i class="bi bi-forward text-base"></i><span class="hidden md:inline">Forward</span>
                    </button>

                    <span :class="[ui.divider, 'mx-1 h-6 border-l']"></span>

                    <button
                        type="button"
                        :class="[ui.iconButton, 'flex h-9 w-9 items-center justify-center rounded-md transition']"
                        :title="currentMessage.seen ? 'Mark as unread' : 'Mark as read'"
                        :aria-label="currentMessage.seen ? 'Mark as unread' : 'Mark as read'"
                        @click="emit('toggle-read', currentMessage)"
                    >
                        <i :class="['bi text-base', currentMessage.seen ? 'bi-envelope' : 'bi-envelope-open']"></i>
                    </button>
                    <button
                        type="button"
                        :class="[ui.iconButton, 'flex h-9 w-9 items-center justify-center rounded-md transition hover:!text-rose-600 disabled:opacity-50']"
                        title="Delete"
                        aria-label="Delete"
                        :disabled="deletingUid === currentMessage.uid"
                        @click="emit('delete-message', currentMessage.uid)"
                    >
                        <i :class="['bi text-base', deletingUid === currentMessage.uid ? 'bi-hourglass-split' : 'bi-trash3']"></i>
                    </button>

                    <div class="ml-auto flex items-center gap-1">
                        <span v-if="currentIndex >= 0" :class="[ui.muted, 'mr-1 hidden text-xs tabular-nums sm:inline']">
                            {{ currentIndex + 1 }} of {{ filteredMessages.length }}
                        </span>
                        <button
                            type="button"
                            :class="[ui.iconButton, 'flex h-9 w-9 items-center justify-center rounded-md transition disabled:pointer-events-none disabled:opacity-30']"
                            :disabled="!previousMessage"
                            title="Newer (k)"
                            aria-label="Newer message"
                            @click="previousMessage && emit('open-message', previousMessage.uid)"
                        >
                            <i class="bi bi-chevron-up text-sm"></i>
                        </button>
                        <button
                            type="button"
                            :class="[ui.iconButton, 'flex h-9 w-9 items-center justify-center rounded-md transition disabled:pointer-events-none disabled:opacity-30']"
                            :disabled="!nextMessage"
                            title="Older (j)"
                            aria-label="Older message"
                            @click="nextMessage && emit('open-message', nextMessage.uid)"
                        >
                            <i class="bi bi-chevron-down text-sm"></i>
                        </button>
                    </div>
                </div>

                <div class="h-0.5 w-full shrink-0 overflow-hidden">
                    <div v-if="messageLoading" class="h-full w-full animate-pulse bg-blue-600"></div>
                </div>

                <!-- Content -->
                <div :class="[ui.canvas, 'min-h-0 flex-1 overflow-y-auto transition-opacity', messageLoading ? 'opacity-60' : '']">
                    <div class="mx-auto w-full max-w-4xl px-3 py-5 md:px-8 md:py-8">
                        <!-- Subject -->
                        <div class="px-1">
                            <div class="mb-2 flex flex-wrap items-center gap-2">
                                <span :class="[ui.chip, 'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-medium']">
                                    <i class="bi bi-folder2"></i>{{ folderLabel(currentMessage.folder || activeFolder) }}
                                </span>
                            </div>
                            <h2 :class="[ui.strong, 'text-xl font-semibold leading-snug tracking-tight md:text-[26px]']">
                                {{ currentMessage.subject || '(no subject)' }}
                            </h2>
                        </div>

                        <!-- Message card -->
                        <article :class="[ui.card, 'mt-5 overflow-hidden rounded-xl border shadow-sm']">
                            <header :class="[ui.cardDivider, 'flex items-start gap-3 border-b px-4 py-4 md:px-6']">
                                <MailAvatar
                                    :email="senderEmail(currentMessage.from)"
                                    :initials="senderInitials(currentMessage.from)"
                                    :color-class="avatarColor(currentMessage.from)"
                                    :verified="Boolean(currentMessage.sender_verified)"
                                    :endpoint="avatarEndpoint"
                                    class="h-11 w-11 text-sm"
                                />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                                        <div class="min-w-0">
                                            <p :class="[ui.strong, 'truncate text-[15px] font-semibold']">
                                                {{ senderName(currentMessage.from) }}
                                                <i v-if="currentMessage.sender_verified" class="bi bi-patch-check-fill ml-0.5 align-[-1px] text-sm text-blue-600" title="Sender verified: DMARC or DKIM passed for this domain"></i>
                                            </p>
                                            <p v-if="senderEmail(currentMessage.from) && senderEmail(currentMessage.from) !== senderName(currentMessage.from)" :class="[ui.muted, 'truncate text-xs']">
                                                {{ senderEmail(currentMessage.from) }}
                                            </p>
                                        </div>
                                        <time :class="[ui.muted, 'shrink-0 pt-0.5 text-xs']" :title="currentMessage.date">{{ formatFullDate(currentMessage.date) }}</time>
                                    </div>
                                    <p v-if="currentMessage.to" :class="[ui.muted, 'mt-1.5 truncate text-xs']">
                                        <span class="font-medium">To:</span> {{ currentMessage.to }}
                                    </p>
                                </div>
                            </header>

                            <div :class="[ui.cardDivider, 'flex flex-wrap items-center gap-1 border-b px-4 py-2 md:px-6']" role="tablist" aria-label="Message view">
                                <button
                                    v-for="tab in bodyTabs"
                                    :key="tab.id"
                                    type="button"
                                    role="tab"
                                    :aria-selected="activeTab === tab.id"
                                    :class="[activeTab === tab.id ? ui.chip : [ui.muted, ui.iconButton], 'inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs font-medium transition']"
                                    @click="selectTab(tab.id)"
                                >
                                    <i :class="['bi', tab.icon]"></i> {{ tab.label }}
                                </button>
                            </div>

                            <template v-if="showsBody">
                                <div v-if="showsHtml" class="bg-white">
                                    <iframe
                                        ref="htmlFrame"
                                        :key="`${currentMessage.folder}:${currentMessage.uid}`"
                                        :srcdoc="htmlDocument"
                                        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                        referrerpolicy="no-referrer"
                                        title="Message body"
                                        :class="['block w-full border-0 transition-opacity duration-150', htmlFrameReady ? 'opacity-100' : 'opacity-0']"
                                        :style="{ height: `${htmlFrameHeight}px` }"
                                        @load="onHtmlFrameLoad"
                                    ></iframe>
                                </div>
                                <div v-else :class="[ui.body, 'whitespace-pre-wrap break-words px-4 py-6 font-sans text-[15px] leading-7 md:px-6']">{{ plainText || 'No body available.' }}</div>
                            </template>

                            <!-- Source loading / error -->
                            <div v-else-if="sourceLoading && !source" :class="[ui.muted, 'px-4 py-10 text-center text-sm md:px-6']">
                                <i class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i> Loading message source…
                            </div>
                            <div v-else-if="sourceError" class="px-4 py-6 text-sm text-rose-600 md:px-6">
                                {{ sourceError }}
                                <button type="button" class="ml-2 underline" @click="loadSource">Retry</button>
                            </div>

                            <!-- Analysis -->
                            <div v-else-if="activeTab === 'analysis' && analysis" :class="[ui.body, 'space-y-6 px-4 py-5 text-sm md:px-6']">
                                <div v-if="analysis.sandbox" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                                    <i class="bi bi-cone-striped mr-1"></i> Sent in sandbox mode: {{ analysis.sandbox }}
                                </div>

                                <section v-if="spamCheck" :class="[spamStyle.box, 'rounded-xl border p-4']" aria-label="Spam check">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <i :class="['bi text-2xl', spamStyle.icon]"></i>
                                        <div class="min-w-0 flex-1">
                                            <p class="font-semibold">{{ spamStyle.title }}</p>
                                            <p class="text-xs opacity-80">{{ spamStyle.hint }}</p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-2xl font-semibold tabular-nums">{{ spamCheck.score.toFixed(1) }}</p>
                                            <p class="text-[11px] opacity-80">spam score · 5.0 = spam</p>
                                        </div>
                                    </div>
                                    <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-black/10 dark:bg-white/10" role="meter" :aria-valuenow="spamCheck.score" aria-valuemin="0" aria-valuemax="10" aria-label="Spam score">
                                        <div :class="[spamStyle.bar, 'h-full rounded-full']" :style="{ width: `${Math.min(100, spamCheck.score * 10)}%` }"></div>
                                    </div>

                                    <ul v-if="spamCheck.rules.length" class="mt-3 space-y-1.5">
                                        <li v-for="rule in spamCheck.rules" :key="rule.name" class="flex gap-3 text-xs">
                                            <span class="w-10 shrink-0 text-right font-semibold tabular-nums">+{{ rule.points.toFixed(1) }}</span>
                                            <span class="min-w-0 break-words"><span class="font-mono text-[11px] opacity-70">{{ rule.name }}</span> {{ rule.description }}</span>
                                        </li>
                                    </ul>
                                    <p v-else class="mt-3 text-xs">No spam signs found.</p>

                                    <p v-if="spamCheck.links.count" class="mt-3 break-words text-xs opacity-80">
                                        <i class="bi bi-link-45deg"></i>
                                        {{ spamCheck.links.count }} {{ spamCheck.links.count === 1 ? 'link' : 'links' }} to {{ spamCheck.links.domains.join(', ') || 'unknown hosts' }}
                                    </p>
                                    <p v-if="spamCheck.attachments.length" class="mt-1 break-words text-xs">
                                        <i class="bi bi-paperclip"></i>
                                        <span v-for="(file, i) in spamCheck.attachments" :key="i" :class="file.risk === 'high' ? 'font-semibold text-rose-700 dark:text-rose-300' : ''">{{ file.name }}<template v-if="i < spamCheck.attachments.length - 1">, </template></span>
                                    </p>
                                </section>

                                <dl class="grid grid-cols-[minmax(7rem,auto)_1fr] gap-x-4 gap-y-2">
                                    <template v-for="row in [
                                        ['Message ID', analysis.summary.message_id],
                                        ['Created at', analysis.summary.date],
                                        ['From', analysis.summary.from],
                                        ['To', analysis.summary.to],
                                        ['Subject', analysis.summary.subject],
                                        ['Return-Path', analysis.summary.return_path],
                                        ['Reply-To', analysis.summary.reply_to],
                                        ['Sent by', analysis.provider],
                                        ['Mailer', analysis.summary.mailer],
                                    ].filter((r) => r[1])" :key="row[0]">
                                        <dt :class="[ui.muted, 'font-medium']">{{ row[0] }}</dt>
                                        <dd class="min-w-0 break-words">{{ row[1] }}</dd>
                                    </template>

                                    <dt :class="[ui.muted, 'font-medium']">SPF</dt>
                                    <dd class="min-w-0 break-words">
                                        <span :class="[verdictClass(analysis.auth.spf.result), 'mr-2 rounded px-1.5 py-0.5 text-xs font-semibold']">{{ verdictLabel(analysis.auth.spf.result) }}</span>
                                        <span :class="ui.muted">{{ analysis.auth.spf.detail }}</span>
                                    </dd>

                                    <dt :class="[ui.muted, 'font-medium']">DKIM</dt>
                                    <dd class="min-w-0 space-y-1 break-words">
                                        <p v-if="!analysis.auth.dkim.length">
                                            <span :class="[verdictClass('fail'), 'rounded px-1.5 py-0.5 text-xs font-semibold']">NOT SIGNED</span>
                                        </p>
                                        <p v-for="(sig, i) in analysis.auth.dkim" :key="i">
                                            <span :class="[verdictClass(sig.result), 'mr-2 rounded px-1.5 py-0.5 text-xs font-semibold']">{{ verdictLabel(sig.result) }}</span>
                                            <span>with domain {{ sig.domain || 'unknown' }}</span>
                                            <span v-if="sig.selector" :class="ui.muted"> (selector {{ sig.selector }})</span>
                                        </p>
                                    </dd>

                                    <dt :class="[ui.muted, 'font-medium']">DMARC</dt>
                                    <dd class="min-w-0 break-words">
                                        <span :class="[verdictClass(analysis.auth.dmarc.result), 'mr-2 rounded px-1.5 py-0.5 text-xs font-semibold']">{{ verdictLabel(analysis.auth.dmarc.result) }}</span>
                                        <span :class="ui.muted">{{ analysis.auth.dmarc.detail }}</span>
                                    </dd>

                                    <dt :class="[ui.muted, 'font-medium']">Alignment</dt>
                                    <dd class="min-w-0 break-words">
                                        SPF <i :class="['bi', analysis.alignment.spf ? 'bi-check-circle-fill text-emerald-600' : 'bi-x-circle text-slate-400']"></i>
                                        <span class="ml-3">DKIM</span> <i :class="['bi', analysis.alignment.dkim ? 'bi-check-circle-fill text-emerald-600' : 'bi-x-circle text-slate-400']"></i>
                                        <span :class="[ui.muted, 'ml-2 text-xs']">with the From domain</span>
                                    </dd>

                                    <template v-if="analysis.spam.flag || analysis.spam.score">
                                        <dt :class="[ui.muted, 'font-medium']">Spam check</dt>
                                        <dd class="min-w-0 break-words">
                                            <span :class="[verdictClass(analysis.spam.flag === 'yes' ? 'fail' : 'pass'), 'mr-2 rounded px-1.5 py-0.5 text-xs font-semibold']">{{ analysis.spam.flag === 'yes' ? 'SPAM' : 'CLEAN' }}</span>
                                            <span v-if="analysis.spam.score" :class="ui.muted">score {{ analysis.spam.score }}</span>
                                        </dd>
                                    </template>

                                    <template v-if="analysis.bulk.list_unsubscribe">
                                        <dt :class="[ui.muted, 'font-medium']">Unsubscribe</dt>
                                        <dd class="min-w-0 break-words">
                                            {{ analysis.bulk.one_click ? 'One-click (RFC 8058)' : 'Link only' }}
                                        </dd>
                                    </template>
                                </dl>

                                <div>
                                    <h3 :class="[ui.strong, 'mb-2 font-semibold']">Findings</h3>
                                    <ul v-if="analysis.warnings.length" class="space-y-1.5">
                                        <li v-for="(warning, i) in analysis.warnings" :key="i" class="flex gap-2 text-amber-700 dark:text-amber-300">
                                            <i class="bi bi-exclamation-triangle mt-0.5 shrink-0"></i><span>{{ warning }}</span>
                                        </li>
                                    </ul>
                                    <p v-else class="text-emerald-700 dark:text-emerald-400"><i class="bi bi-check-circle mr-1"></i> No problems found in the headers.</p>
                                </div>

                                <div v-if="analysis.hops.length">
                                    <h3 :class="[ui.strong, 'mb-2 font-semibold']">
                                        Delivery path
                                        <span :class="[ui.muted, 'ml-1 text-xs font-normal']">
                                            {{ analysis.hops.length }} {{ analysis.hops.length === 1 ? 'hop' : 'hops' }}<template v-if="analysis.total_delay !== null">, {{ formatDelay(analysis.total_delay) }} total</template>
                                        </span>
                                    </h3>
                                    <ol class="space-y-2">
                                        <li v-for="(hop, i) in analysis.hops" :key="i" :class="[ui.cardDivider, 'flex gap-3 rounded-lg border px-3 py-2']">
                                            <span :class="[ui.chip, 'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold']">{{ i + 1 }}</span>
                                            <div class="min-w-0 flex-1">
                                                <p class="break-words text-xs"><span :class="ui.muted">from</span> {{ hop.from || '—' }}</p>
                                                <p class="break-words text-xs"><span :class="ui.muted">by</span> {{ hop.by || '—' }}</p>
                                                <p :class="[ui.muted, 'mt-0.5 text-[11px]']">
                                                    <i :class="['bi mr-0.5', hop.tls ? 'bi-lock-fill text-emerald-600' : 'bi-unlock text-amber-600']" :title="hop.tls ? 'Encrypted (TLS)' : 'Not encrypted'"></i>
                                                    {{ hop.with || 'unknown protocol' }} · {{ hop.date }}
                                                </p>
                                            </div>
                                            <span v-if="hop.delay !== null" :class="['shrink-0 text-xs tabular-nums', hop.delay > 300 ? 'font-semibold text-amber-600' : ui.muted]">+{{ formatDelay(hop.delay) }}</span>
                                        </li>
                                    </ol>
                                </div>

                                <div>
                                    <button type="button" :class="[ui.strong, 'font-semibold']" :aria-expanded="showAllHeaders" @click="showAllHeaders = !showAllHeaders">
                                        <i :class="['bi mr-1 text-xs', showAllHeaders ? 'bi-chevron-down' : 'bi-chevron-right']"></i>All headers ({{ analysis.headers.length }})
                                    </button>
                                    <table v-if="showAllHeaders" class="mt-2 w-full table-fixed text-xs">
                                        <tbody>
                                            <tr v-for="(header, i) in analysis.headers" :key="i" :class="[ui.cardDivider, 'border-t align-top']">
                                                <td :class="[ui.muted, 'w-40 break-words py-1 pr-3 font-mono font-medium']">{{ header.name }}</td>
                                                <td class="break-words py-1 font-mono">{{ header.value }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Original (raw source) -->
                            <div v-else-if="activeTab === 'raw' && source" class="px-4 py-4 md:px-6">
                                <div class="mb-3 flex flex-wrap items-center gap-2">
                                    <span :class="[ui.muted, 'text-xs']">{{ formatBytes(source.size) }}</span>
                                    <span v-if="source.truncated" class="text-xs text-amber-600">Showing the first {{ formatBytes(source.raw.length) }}; download for the full message.</span>
                                    <div class="ml-auto flex gap-2">
                                        <button type="button" :class="[ui.pill, 'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition']" @click="copySource">
                                            <i :class="['bi', copied ? 'bi-check2' : 'bi-clipboard']"></i> {{ copied ? 'Copied' : 'Copy' }}
                                        </button>
                                        <a v-if="downloadHref" :href="downloadHref" :class="[ui.pill, 'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition']">
                                            <i class="bi bi-download"></i> Download .eml
                                        </a>
                                    </div>
                                </div>
                                <pre :class="[ui.canvas, ui.body, 'max-h-[70vh] overflow-auto whitespace-pre-wrap break-all rounded-lg p-3 font-mono text-xs leading-5']">{{ source.raw }}</pre>
                            </div>
                        </article>

                        <!-- Quick actions -->
                        <div class="mt-5 flex flex-wrap gap-2 px-1 pb-24">
                            <button type="button" :class="[ui.pill, 'inline-flex h-9 items-center gap-2 rounded-full border px-4 text-sm font-medium transition']" @click="emit('reply', currentMessage)">
                                <i class="bi bi-reply"></i> Reply
                            </button>
                            <button type="button" :class="[ui.pill, 'inline-flex h-9 items-center gap-2 rounded-full border px-4 text-sm font-medium transition']" @click="emit('reply-all', currentMessage)">
                                <i class="bi bi-reply-all"></i> Reply all
                            </button>
                            <button type="button" :class="[ui.pill, 'inline-flex h-9 items-center gap-2 rounded-full border px-4 text-sm font-medium transition']" @click="emit('forward', currentMessage)">
                                <i class="bi bi-forward"></i> Forward
                            </button>
                        </div>
                    </div>
                </div>
            </aside>
        </transition>
    </section>
</template>
