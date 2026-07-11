<script setup>
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

defineProps({
    folders: {
        type: Array,
        default: () => [],
    },
    activeFolder: {
        type: String,
        default: 'INBOX',
    },
    loading: {
        type: Boolean,
        default: false,
    },
    isDark: {
        type: Boolean,
        default: false,
    },
    mailbox: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['compose', 'open-folder']);
</script>

<template>
    <aside
        :class="isDark
            ? 'flex min-h-[calc(100vh-8rem)] flex-col border border-slate-800 bg-slate-900 shadow-sm'
            : 'flex min-h-[calc(100vh-8rem)] flex-col rounded-[28px] border border-slate-200 bg-white shadow-sm'"
    >
        <div :class="isDark ? 'border-b border-slate-800 p-4' : 'border-b border-slate-200 p-4'">
            <button
                type="button"
                :class="isDark
                    ? 'flex w-full items-center justify-center gap-2 rounded-2xl bg-blue-500 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-400'
                    : 'flex w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-700'"
                @click="emit('compose')"
            >
                <span class="text-lg leading-none">+</span>
                Compose
            </button>
        </div>

        <div class="flex-1 space-y-1 overflow-auto px-2 pb-3">
            <button
                v-for="folder in folders"
                :key="folder.name"
                type="button"
                class="flex w-full items-center justify-between rounded-2xl px-3 py-2 text-left text-sm transition"
                :class="folder.name === activeFolder
                    ? (isDark ? 'bg-blue-950/50 font-semibold text-blue-200' : 'bg-blue-100 font-semibold text-blue-700')
                    : (isDark ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-700 hover:bg-slate-100')"
                :disabled="loading"
                @click="emit('open-folder', folder)"
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
</template>
