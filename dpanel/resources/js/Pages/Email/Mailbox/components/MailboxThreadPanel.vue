<script setup>
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
});

const emit = defineEmits(['open-message', 'close-preview', 'start-compose', 'delete-message', 'reply', 'reply-all', 'forward', 'toggle-read']);

const formatDate = (value) => {
    if (!value) return '-';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    const now = new Date();
    const diffMs = now - parsed;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: parsed.getFullYear() !== now.getFullYear() ? 'numeric' : undefined });
};

const formatFullDate = (value) => {
    if (!value) return '-';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    return parsed.toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
};

const senderInitials = (from) => {
    if (!from) return '?';
    const name = from.replace(/<.*>/, '').trim();
    const parts = name.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return name.slice(0, 2).toUpperCase();
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

const avatarColors = [
    'from-blue-500 to-blue-600',
    'from-emerald-500 to-emerald-600',
    'from-violet-500 to-violet-600',
    'from-amber-500 to-amber-600',
    'from-rose-500 to-rose-600',
    'from-cyan-500 to-cyan-600',
    'from-indigo-500 to-indigo-600',
    'from-pink-500 to-pink-600',
    'from-teal-500 to-teal-600',
    'from-orange-500 to-orange-600',
];

const senderAvatarColor = (from) => {
    if (!from) return avatarColors[0];
    let hash = 0;
    for (let i = 0; i < from.length; i++) {
        hash = from.charCodeAt(i) + ((hash << 5) - hash);
    }
    return avatarColors[Math.abs(hash) % avatarColors.length];
};

const folderLabel = (name) => {
    const normalized = String(name || '').trim().toLowerCase();
    if (normalized === 'inbox') return 'Inbox';
    if (normalized.includes('sent')) return 'Sent';
    if (normalized.includes('draft')) return 'Drafts';
    if (normalized.includes('spam') || normalized.includes('junk')) return 'Spam';
    if (normalized.includes('trash') || normalized.includes('bin') || normalized.includes('deleted')) return 'Trash';
    if (normalized === 'outbox') return 'Outbox';
    if (normalized === 'all mail') return 'All';
    return name;
};

const formatSize = (bytes) => {
    const kb = Math.max(1, Math.round((bytes || 0) / 1024));
    if (kb >= 1024) return `${(kb / 1024).toFixed(1)} MB`;
    return `${kb} KB`;
};
</script>

<template>
    <section
        :class="[
            isDark
                ? 'relative flex min-h-[calc(100vh-8rem)] flex-col overflow-hidden border border-slate-800/60 bg-slate-900/50'
                : 'relative flex min-h-[calc(100vh-8rem)] flex-col overflow-hidden border border-slate-200/80 bg-white',
            'rounded-2xl shadow-sm'
        ]"
    >
        <!-- Message List Header -->
        <div :class="isDark ? 'border-b border-slate-800/60' : 'border-b border-slate-100'" class="px-5 py-3.5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <h2 :class="isDark ? 'text-base font-semibold text-slate-100' : 'text-base font-semibold text-slate-900'">{{ folderLabel(activeFolder) }}</h2>
                    <span
                        v-if="filteredMessages.length > 0"
                        :class="isDark ? 'rounded-full bg-slate-800 px-2 py-0.5 text-[11px] font-medium text-slate-400' : 'rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500'"
                    >
                        {{ filteredMessages.length }}
                    </span>
                </div>
                <div v-if="filteredMessages.length > 0" :class="isDark ? 'text-xs text-slate-500' : 'text-xs text-slate-400'">
                    {{ filteredMessages.filter(m => !m.seen).length }} unread
                </div>
            </div>
        </div>

        <!-- Message List -->
        <div class="min-h-0 flex-1 overflow-auto">
            <!-- Loading -->
            <div v-if="loading" class="flex flex-col items-center justify-center py-16">
                <div :class="isDark ? 'h-8 w-8 animate-spin rounded-full border-2 border-slate-700 border-t-blue-500' : 'h-8 w-8 animate-spin rounded-full border-2 border-slate-200 border-t-blue-500'"></div>
                <p :class="isDark ? 'mt-3 text-sm text-slate-400' : 'mt-3 text-sm text-slate-500'">Loading messages...</p>
            </div>

            <!-- Empty State -->
            <div v-else-if="filteredMessages.length === 0" class="flex flex-col items-center justify-center py-16 px-6">
                <div :class="isDark ? 'flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-800/50' : 'flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100'">
                    <svg viewBox="0 0 24 24" :class="isDark ? 'h-8 w-8 text-slate-500' : 'h-8 w-8 text-slate-400'" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                    </svg>
                </div>
                <p :class="isDark ? 'mt-4 text-sm font-medium text-slate-200' : 'mt-4 text-sm font-medium text-slate-700'">
                    {{ hasSearchQuery ? 'No messages match your search' : 'No messages here' }}
                </p>
                <p :class="isDark ? 'mt-1 text-xs text-slate-500' : 'mt-1 text-xs text-slate-400'">
                    {{ hasSearchQuery ? 'Try a different search term' : 'Messages will appear here' }}
                </p>
            </div>

            <!-- Messages -->
            <template v-else>
                <div
                    v-for="item in filteredMessages"
                    :key="item.uid"
                    class="group relative cursor-pointer border-b px-5 py-3.5 transition-all duration-150"
                    :class="currentMessage?.uid === item.uid
                        ? (isDark ? 'border-blue-500/20 bg-blue-500/5' : 'border-blue-200 bg-blue-50')
                        : (isDark ? 'border-slate-800/40 hover:bg-slate-800/30' : 'border-slate-100 hover:bg-slate-50/80')"
                    @click="emit('open-message', item.uid)"
                >
                    <div class="flex items-start gap-3.5">
                        <!-- Avatar -->
                        <div
                            :class="[
                                'relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-xs font-bold text-white shadow-sm',
                                senderAvatarColor(item.from),
                            ]"
                        >
                            {{ senderInitials(item.from) }}
                            <!-- Online dot for unread -->
                            <span
                                v-if="!item.seen"
                                class="absolute -right-0.5 -top-0.5 h-3 w-3 rounded-full border-2 border-white bg-blue-500 dark:border-slate-900"
                            ></span>
                        </div>

                        <!-- Content -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <p :class="[
                                    'truncate text-[13px]',
                                    !item.seen
                                        ? (isDark ? 'font-bold text-slate-50' : 'font-bold text-slate-900')
                                        : (isDark ? 'font-medium text-slate-300' : 'font-medium text-slate-700')
                                ]">
                                    {{ senderName(item.from) }}
                                </p>
                                <div class="flex items-center gap-2 shrink-0">
                                    <span v-if="!item.seen" class="h-2 w-2 rounded-full bg-blue-500"></span>
                                    <span :class="isDark ? 'text-[11px] text-slate-500' : 'text-[11px] text-slate-400'">
                                        {{ formatDate(item.date) }}
                                    </span>
                                </div>
                            </div>
                            <p :class="[
                                'mt-0.5 truncate text-[13px]',
                                !item.seen
                                    ? (isDark ? 'font-semibold text-slate-200' : 'font-semibold text-slate-800')
                                    : (isDark ? 'text-slate-400' : 'text-slate-600')
                            ]">
                                {{ item.subject || '(no subject)' }}
                            </p>
                            <p :class="[
                                'mt-0.5 truncate text-xs',
                                isDark ? 'text-slate-500' : 'text-slate-400'
                            ]">
                                {{ item.snippet || 'No preview available' }}
                            </p>
                        </div>

                        <!-- Size -->
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <span :class="isDark ? 'text-[10px] text-slate-600' : 'text-[10px] text-slate-400'">
                                {{ formatSize(item.size) }}
                            </span>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Message Preview Panel -->
        <transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-x-full opacity-0"
            enter-to-class="translate-x-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-x-0 opacity-100"
            leave-to-class="translate-x-full opacity-0"
        >
            <div
                v-if="currentMessage"
                :class="isDark
                    ? 'absolute inset-0 z-20 flex flex-col border-l border-slate-800/60 bg-slate-900/95 backdrop-blur-sm lg:w-[52%] lg:left-auto lg:right-0'
                    : 'absolute inset-0 z-20 flex flex-col border-l border-slate-200/80 bg-white/95 backdrop-blur-sm lg:w-[52%] lg:left-auto lg:right-0'"
            >
                <!-- Preview Header -->
                <div :class="isDark ? 'border-b border-slate-800/60' : 'border-b border-slate-100'" class="px-5 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <h3 :class="isDark ? 'truncate text-[15px] font-semibold text-slate-100' : 'truncate text-[15px] font-semibold text-slate-900'">
                            {{ currentMessage?.subject || 'No subject' }}
                        </h3>
                        <button
                            type="button"
                            :class="isDark
                                ? 'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-800 hover:text-slate-200'
                                : 'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-700'"
                            @click="emit('close-preview')"
                            title="Close preview"
                        >
                            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                                <path d="M18.3 5.7a1 1 0 00-1.4-1.4L12 9.17 7.1 4.3A1 1 0 105.7 5.7L10.59 10.6 5.7 15.5a1 1 0 101.4 1.4l4.9-4.89 4.9 4.89a1 1 0 001.4-1.4l-4.89-4.9 4.89-4.9z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Preview Meta + Actions -->
                <div :class="isDark ? 'border-b border-slate-800/60' : 'border-b border-slate-100'" class="px-5 py-4">
                    <div class="flex items-start gap-3">
                        <!-- Sender Avatar -->
                        <div
                            :class="[
                                'flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-sm font-bold text-white shadow-md',
                                senderAvatarColor(currentMessage.from),
                            ]"
                        >
                            {{ senderInitials(currentMessage.from) }}
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p :class="isDark ? 'text-sm font-semibold text-slate-100' : 'text-sm font-semibold text-slate-900'">
                                        {{ senderName(currentMessage.from) }}
                                    </p>
                                    <p v-if="senderEmail(currentMessage.from)" :class="isDark ? 'text-xs text-slate-500' : 'text-xs text-slate-400'">
                                        {{ senderEmail(currentMessage.from) }}
                                    </p>
                                </div>
                                <span :class="isDark ? 'text-xs text-slate-500' : 'text-xs text-slate-400'">
                                    {{ formatFullDate(currentMessage.date) }}
                                </span>
                            </div>
                            <p v-if="currentMessage.to" :class="isDark ? 'mt-1 text-xs text-slate-500' : 'mt-1 text-xs text-slate-400'">
                                To: {{ currentMessage.to }}
                            </p>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="mt-4 flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-medium transition-all',
                                isDark
                                    ? 'bg-slate-800 text-slate-300 hover:bg-slate-700 hover:text-white'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 hover:text-slate-900'
                            ]"
                            @click="emit('reply', currentMessage)"
                        >
                            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z" /></svg>
                            Reply
                        </button>
                        <button
                            type="button"
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-medium transition-all',
                                isDark
                                    ? 'bg-slate-800 text-slate-300 hover:bg-slate-700 hover:text-white'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 hover:text-slate-900'
                            ]"
                            @click="emit('reply-all', currentMessage)"
                        >
                            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M7 8V5l-7 7 7 7v-3l-4-4 4-4zm6 1V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z" /></svg>
                            Reply All
                        </button>
                        <button
                            type="button"
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-medium transition-all',
                                isDark
                                    ? 'bg-slate-800 text-slate-300 hover:bg-slate-700 hover:text-white'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 hover:text-slate-900'
                            ]"
                            @click="emit('forward', currentMessage)"
                        >
                            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M14 9V5l7 7-7 7v-4.1c-5 0-8.5 1.6-11 5.1 1-5 4-10 11-11z" /></svg>
                            Forward
                        </button>
                        <button
                            type="button"
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-medium transition-all',
                                isDark
                                    ? 'bg-slate-800 text-slate-300 hover:bg-slate-700 hover:text-white'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 hover:text-slate-900'
                            ]"
                            @click="emit('toggle-read', currentMessage)"
                        >
                            <svg v-if="currentMessage?.seen" viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" /></svg>
                            <svg v-else viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z" /></svg>
                            Mark as {{ currentMessage?.seen ? 'Unread' : 'Read' }}
                        </button>
                        <button
                            type="button"
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12px] font-medium transition-all',
                                isDark
                                    ? 'bg-red-500/10 text-red-400 hover:bg-red-500/20 hover:text-red-300'
                                    : 'bg-red-50 text-red-600 hover:bg-red-100 hover:text-red-700'
                            ]"
                            :disabled="deletingUid === currentMessage.uid"
                            @click="emit('delete-message', currentMessage.uid)"
                        >
                            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" /></svg>
                            {{ deletingUid === currentMessage.uid ? 'Deleting...' : 'Delete' }}
                        </button>
                    </div>
                </div>

                <!-- Message Body -->
                <div class="min-h-0 flex-1 overflow-auto p-5">
                    <div
                        :class="isDark
                            ? 'prose prose-invert prose-sm max-w-none text-slate-300'
                            : 'prose prose-sm max-w-none text-slate-700'"
                    >
                        <pre
                            :class="isDark
                                ? 'whitespace-pre-wrap font-sans text-[13px] leading-relaxed text-slate-300'
                                : 'whitespace-pre-wrap font-sans text-[13px] leading-relaxed text-slate-700'"
                        >{{ currentMessage.text || currentMessage.raw_body || 'No body available.' }}</pre>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Empty Preview State -->
        <div v-if="!currentMessage && !loading && filteredMessages.length > 0" class="hidden min-h-0 flex-1 overflow-auto lg:flex lg:items-center lg:justify-center">
            <div class="text-center">
                <div :class="isDark ? 'mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-800/50' : 'mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100'">
                    <svg viewBox="0 0 24 24" :class="isDark ? 'h-7 w-7 text-slate-500' : 'h-7 w-7 text-slate-400'" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                    </svg>
                </div>
                <p :class="isDark ? 'mt-3 text-sm font-medium text-slate-300' : 'mt-3 text-sm font-medium text-slate-600'">Select a message</p>
                <p :class="isDark ? 'mt-1 text-xs text-slate-500' : 'mt-1 text-xs text-slate-400'">Choose from the list to preview</p>
            </div>
        </div>
    </section>
</template>
