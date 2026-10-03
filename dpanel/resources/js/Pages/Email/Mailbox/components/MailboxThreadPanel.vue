<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { folderLabel, isOutgoingFolder } from './mailboxFolders';

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

// Messages with both parts can be switched between HTML and plain text.
const bodyView = ref('html');
const showsHtml = computed(() => bodyView.value === 'html' && htmlDocument.value !== '');
const plainText = computed(() => props.currentMessage?.text || props.currentMessage?.raw_body || '');

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
                        <span
                            :class="[
                                avatarColor(counterpartAddress(item)),
                                'flex h-8 w-8 items-center justify-center rounded-full text-[11px] font-semibold text-white transition-opacity',
                                selectionMode || selectedSet.has(item.uid) ? 'opacity-0' : 'group-hover:opacity-0',
                            ]"
                        >
                            {{ senderInitials(counterpartAddress(item)) }}
                        </span>
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
                                <span :class="[avatarColor(currentMessage.from), 'flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white']">
                                    {{ senderInitials(currentMessage.from) }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                                        <div class="min-w-0">
                                            <p :class="[ui.strong, 'truncate text-[15px] font-semibold']">{{ senderName(currentMessage.from) }}</p>
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

                            <div v-if="htmlDocument" :class="[ui.cardDivider, 'flex items-center gap-1 border-b px-4 py-2 md:px-6']" role="tablist" aria-label="Message format">
                                <button
                                    v-for="tab in [{ id: 'html', label: 'HTML', icon: 'bi-filetype-html' }, { id: 'text', label: 'Plain text', icon: 'bi-text-left' }]"
                                    :key="tab.id"
                                    type="button"
                                    role="tab"
                                    :aria-selected="bodyView === tab.id"
                                    :class="[bodyView === tab.id ? ui.chip : [ui.muted, ui.iconButton], 'inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs font-medium transition']"
                                    @click="bodyView = tab.id"
                                >
                                    <i :class="['bi', tab.icon]"></i> {{ tab.label }}
                                </button>
                            </div>

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
