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

const emit = defineEmits(['open-message', 'close-preview', 'start-compose', 'delete-message']);

const formatDate = (value) => {
    if (!value) return '-';
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
};
</script>

<template>
    <section
        :class="isDark
            ? 'relative flex min-h-[calc(100vh-4rem)] flex-col overflow-hidden border border-slate-800 bg-slate-900 shadow-sm'
            : 'relative flex min-h-[calc(100vh-8rem)] flex-col overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-sm'"
    >
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
                @click="emit('open-message', item.uid)"
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
                {{ hasSearchQuery ? 'No messages match your search.' : 'No messages in this folder.' }}
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
            <div
                v-if="currentMessage"
                :class="isDark
                    ? 'absolute inset-0 z-20 flex flex-col border-l border-slate-800 bg-slate-900 shadow-2xl lg:w-[48%] lg:left-auto lg:right-0'
                    : 'absolute inset-0 z-20 flex flex-col border-l border-slate-200 bg-white shadow-2xl lg:w-[48%] lg:left-auto lg:right-0'"
            >
                <div :class="isDark ? 'border-b border-slate-800 px-4 py-3' : 'border-b border-slate-200 px-4 py-3'">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p :class="isDark ? 'text-xs font-medium uppercase tracking-[0.22em] text-slate-400' : 'text-xs font-medium uppercase tracking-[0.22em] text-slate-500'">Preview</p>
                            <h2 :class="isDark ? 'mt-1 truncate text-lg font-semibold text-slate-100' : 'mt-1 truncate text-lg font-semibold text-slate-900'">{{ currentMessage?.subject || 'Select a message' }}</h2>
                        </div>
                        <button
                            type="button"
                            :class="isDark
                                ? 'rounded-full border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800'
                                : 'rounded-full border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-100'"
                            @click="emit('close-preview')"
                        >
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
                                <button
                                    type="button"
                                    :class="isDark
                                        ? 'rounded-full border border-slate-700 px-3 py-2 text-xs font-medium text-slate-200 hover:bg-slate-800'
                                        : 'rounded-full border border-slate-200 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-100'"
                                    @click="emit('start-compose')"
                                >
                                    Reply
                                </button>
                                <button
                                    type="button"
                                    :class="isDark
                                        ? 'rounded-full border border-rose-900/50 px-3 py-2 text-xs font-medium text-rose-200 hover:bg-rose-950/40'
                                        : 'rounded-full border border-rose-200 px-3 py-2 text-xs font-medium text-rose-700 hover:bg-rose-50'"
                                    :disabled="deletingUid === currentMessage.uid"
                                    @click="emit('delete-message', currentMessage.uid)"
                                >
                                    {{ deletingUid === currentMessage.uid ? 'Deleting...' : 'Delete' }}
                                </button>
                            </div>
                        </div>

                        <pre
                            :class="isDark
                                ? 'whitespace-pre-wrap rounded-2xl border border-slate-800 bg-slate-950 p-4 text-sm leading-6 text-slate-200'
                                : 'whitespace-pre-wrap rounded-2xl border border-slate-200 bg-white p-4 text-sm leading-6 text-slate-800'"
                        >{{ currentMessage.text || currentMessage.raw_body || 'No body available.' }}</pre>
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
</template>
