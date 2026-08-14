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

const folderConfig = {
    inbox: { icon: 'bi-inbox', color: 'blue' },
    sent: { icon: 'bi-send-check', color: 'emerald' },
    drafts: { icon: 'bi-file-earmark-text', color: 'amber' },
    spam: { icon: 'bi-shield-exclamation', color: 'red' },
    trash: { icon: 'bi-trash3', color: 'rose' },
    outbox: { icon: 'bi-box-arrow-up', color: 'violet' },
    all: { icon: 'bi-envelope-stack', color: 'slate' },
};

const getFolderConfig = (name) => {
    const normalized = String(name || '').trim().toLowerCase();
    for (const [key, config] of Object.entries(folderConfig)) {
        if (normalized === key || normalized.includes(key)) {
            return config;
        }
    }
    return { icon: 'bi-folder2', color: 'slate' };
};

const colorClasses = {
    blue: { bg: 'bg-blue-500/10', text: 'text-blue-500', activeBg: 'bg-blue-500/15', activeText: 'text-blue-600 dark:text-blue-400' },
    emerald: { bg: 'bg-emerald-500/10', text: 'text-emerald-500', activeBg: 'bg-emerald-500/15', activeText: 'text-emerald-600 dark:text-emerald-400' },
    amber: { bg: 'bg-amber-500/10', text: 'text-amber-500', activeBg: 'bg-amber-500/15', activeText: 'text-amber-600 dark:text-amber-400' },
    red: { bg: 'bg-red-500/10', text: 'text-red-500', activeBg: 'bg-red-500/15', activeText: 'text-red-600 dark:text-red-400' },
    rose: { bg: 'bg-rose-500/10', text: 'text-rose-500', activeBg: 'bg-rose-500/15', activeText: 'text-rose-600 dark:text-rose-400' },
    violet: { bg: 'bg-violet-500/10', text: 'text-violet-500', activeBg: 'bg-violet-500/15', activeText: 'text-violet-600 dark:text-violet-400' },
    slate: { bg: 'bg-slate-500/10', text: 'text-slate-500', activeBg: 'bg-slate-500/15', activeText: 'text-slate-600 dark:text-slate-400' },
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
    collapsed: {
        type: Boolean,
        default: false,
    },
    mobileOpen: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['compose', 'open-folder', 'close-mobile']);
</script>

<template>
    <aside
        :class="[
            isDark ? 'bg-slate-950 border-slate-800/80' : 'bg-white border-slate-200/80',
            'flex h-screen flex-col border-r shadow-xl transition-all duration-300',
            collapsed ? 'w-[72px]' : 'w-[280px]',
            'max-md:fixed max-md:inset-y-0 max-md:left-0 max-md:z-50',
            mobileOpen ? 'max-md:translate-x-0' : 'max-md:-translate-x-full',
        ]"
    >
        <!-- Logo Header -->
        <div :class="isDark ? 'border-b border-slate-800/60' : 'border-b border-slate-100'" class="flex h-14 shrink-0 items-center justify-center px-4">
            <img v-if="collapsed" src="/sm_logo.png" alt="dPanel" class="h-7 w-auto" />
            <template v-else>
                <img src="/sm_logo.png" alt="dPanel" class="h-7 w-auto md:hidden" />
                <img src="/dpanel_logo.png" alt="dPanel" class="hidden h-[60px] w-auto md:block" />
            </template>
        </div>

        <!-- Compose Button -->
        <div class="px-3 pt-4 pb-2">
            <button
                type="button"
                :class="[
                    'group flex w-full items-center justify-center gap-2.5 rounded-xl text-sm font-semibold shadow-lg shadow-blue-500/20 transition-all duration-200',
                    isDark
                        ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white hover:from-blue-500 hover:to-blue-400 hover:shadow-blue-500/30'
                        : 'bg-gradient-to-r from-blue-600 to-blue-500 text-white hover:from-blue-500 hover:to-blue-400 hover:shadow-blue-500/30',
                    collapsed ? 'h-11 w-11 px-0' : 'h-11 px-4',
                ]"
                @click="emit('compose')"
                :title="collapsed ? 'Compose' : ''"
            >
                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current" :class="!collapsed ? 'rotate-0' : ''">
                    <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 000-1.41l-2.34-2.34a1 1 0 00-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" />
                </svg>
                <span v-if="!collapsed">Compose</span>
            </button>
        </div>

        <!-- Folder List -->
        <div class="flex-1 space-y-0.5 overflow-auto px-2 py-2">
            <button
                v-for="folder in folders"
                :key="folder.name"
                type="button"
                :class="[
                    'group relative flex w-full items-center gap-3 rounded-xl text-left text-[13px] font-medium transition-all duration-150',
                    collapsed ? 'justify-center px-2 py-2.5' : 'px-3 py-2.5',
                    folder.name === activeFolder
                        ? (isDark
                            ? 'bg-blue-500/10 text-blue-400 shadow-sm'
                            : 'bg-blue-50 text-blue-600 shadow-sm')
                        : (isDark
                            ? 'text-slate-400 hover:bg-slate-800/60 hover:text-slate-200'
                            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'),
                ]"
                :disabled="loading"
                :title="collapsed ? folderLabel(folder.name) : ''"
                @click="emit('open-folder', folder)"
            >
                <!-- Active Indicator -->
                <span
                    v-if="folder.name === activeFolder"
                    class="absolute left-0 top-1/2 h-5 w-[3px] -translate-y-1/2 rounded-r-full bg-blue-500"
                ></span>

                <i
                    :class="[
                        'bi shrink-0 text-[15px]',
                        getFolderConfig(folder.name).icon,
                        folder.name === activeFolder
                            ? (isDark ? 'text-blue-400' : 'text-blue-500')
                            : (isDark ? 'text-slate-500 group-hover:text-slate-300' : 'text-slate-400 group-hover:text-slate-600'),
                    ]"
                ></i>

                <span v-if="!collapsed" class="flex-1 truncate">{{ folderLabel(folder.name) }}</span>

                <!-- Unread Badge -->
                <span
                    v-if="folder.unread > 0 && !collapsed"
                    :class="[
                        'min-w-[22px] rounded-full px-1.5 py-0.5 text-center text-[11px] font-bold leading-none',
                        folder.name === activeFolder
                            ? (isDark ? 'bg-blue-500/20 text-blue-300' : 'bg-blue-500 text-white')
                            : (isDark ? 'bg-slate-700 text-slate-300' : 'bg-slate-200 text-slate-600'),
                    ]"
                >
                    {{ folder.unread > 99 ? '99+' : folder.unread }}
                </span>

                <!-- Collapsed Dot -->
                <span
                    v-if="folder.unread > 0 && collapsed"
                    class="absolute right-2 top-2 h-2 w-2 rounded-full bg-blue-500 ring-2 ring-slate-950 dark:ring-slate-950"
                ></span>
            </button>
        </div>

        <!-- Divider -->
        <div :class="isDark ? 'border-t border-slate-800/60' : 'border-t border-slate-100'" class="mx-3"></div>

        <!-- Mailbox Info Footer -->
        <div class="p-3">
            <div
                :class="[
                    isDark ? 'bg-slate-900/80 border border-slate-800/60' : 'bg-slate-50 border border-slate-100',
                    'rounded-xl px-3 py-3 transition-colors',
                ]"
            >
                <template v-if="!collapsed">
                    <div class="flex items-center gap-2.5">
                        <div
                            :class="[
                                'relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                isDark
                                    ? 'bg-gradient-to-br from-blue-600 to-indigo-600 text-white'
                                    : 'bg-gradient-to-br from-blue-500 to-indigo-500 text-white',
                            ]"
                        >
                            {{ mailbox.email?.slice(0, 1)?.toUpperCase() || 'M' }}
                            <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-400 dark:border-slate-900"></span>
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-[13px] font-semibold" :class="isDark ? 'text-slate-100' : 'text-slate-900'">{{ mailbox.email }}</div>
                            <div class="truncate text-[11px]" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ mailbox.domain || '-' }}</div>
                        </div>
                    </div>

                    <!-- Storage Bar -->
                    <div class="mt-3">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-medium" :class="isDark ? 'text-slate-500' : 'text-slate-400'">Storage</span>
                            <span class="text-[11px] font-semibold" :class="isDark ? 'text-slate-300' : 'text-slate-600'">{{ mailbox.quota_mb }} MB</span>
                        </div>
                        <div :class="isDark ? 'mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-800' : 'mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200'">
                            <div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-indigo-500 transition-all duration-500" style="width: 0%"></div>
                        </div>
                        <div class="mt-1 flex items-center justify-between">
                            <span class="text-[10px]" :class="isDark ? 'text-slate-600' : 'text-slate-400'">0 MB used</span>
                            <span class="text-[10px]" :class="isDark ? 'text-slate-600' : 'text-slate-400'">0%</span>
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div class="flex flex-col items-center gap-1">
                        <div
                            :class="[
                                'relative flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold',
                                isDark
                                    ? 'bg-gradient-to-br from-blue-600 to-indigo-600 text-white'
                                    : 'bg-gradient-to-br from-blue-500 to-indigo-500 text-white',
                            ]"
                        >
                            {{ mailbox.email?.slice(0, 1)?.toUpperCase() || 'M' }}
                            <span class="absolute -bottom-0.5 -right-0.5 h-2 w-2 rounded-full border-[1.5px] border-white bg-emerald-400 dark:border-slate-950"></span>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </aside>
</template>
