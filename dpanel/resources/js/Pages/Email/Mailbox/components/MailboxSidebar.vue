<script setup>
import { computed } from 'vue';
import { folderIcon, folderLabel, formatBytes } from './mailboxFolders';

const props = defineProps({
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

const emit = defineEmits(['compose', 'open-folder', 'close-mobile', 'toggle-collapse']);

const ui = computed(() => (props.isDark
    ? {
        aside: 'bg-slate-950 border-slate-800',
        divider: 'border-slate-800',
        muted: 'text-slate-500',
        text: 'text-slate-300',
        strong: 'text-slate-100',
        iconButton: 'text-slate-400 hover:bg-slate-800 hover:text-slate-100',
        item: 'text-slate-400 hover:bg-slate-900 hover:text-slate-100',
        itemActive: 'bg-blue-500/10 text-blue-300',
        iconActive: 'text-blue-400',
        icon: 'text-slate-500 group-hover:text-slate-300',
        track: 'bg-slate-800',
        card: 'border-slate-800 bg-slate-900/60',
    }
    : {
        aside: 'bg-white border-slate-200',
        divider: 'border-slate-200',
        muted: 'text-slate-500',
        text: 'text-slate-700',
        strong: 'text-slate-900',
        iconButton: 'text-slate-500 hover:bg-slate-100 hover:text-slate-900',
        item: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
        itemActive: 'bg-blue-50 text-blue-700',
        iconActive: 'text-blue-600',
        icon: 'text-slate-400 group-hover:text-slate-600',
        track: 'bg-slate-200',
        card: 'border-slate-200 bg-slate-50',
    }));

// The mobile drawer always shows the full sidebar.
const compact = computed(() => props.collapsed && !props.mobileOpen);

const quotaBytes = computed(() => (Number(props.mailbox.quota_mb) || 0) * 1024 * 1024);
const usedBytes = computed(() => Number(props.mailbox.used_bytes) || 0);
const usedPercent = computed(() => (quotaBytes.value > 0 ? Math.min(100, (usedBytes.value / quotaBytes.value) * 100) : 0));
const barColor = computed(() => {
    if (usedPercent.value >= 90) return 'bg-rose-500';
    if (usedPercent.value >= 75) return 'bg-amber-500';

    return 'bg-blue-600';
});
</script>

<template>
    <aside
        :class="[
            ui.aside,
            'flex h-screen flex-col border-r transition-[width,transform] duration-200',
            compact ? 'w-[72px]' : 'w-[260px]',
            'max-md:fixed max-md:inset-y-0 max-md:left-0 max-md:z-50 max-md:w-[280px] max-md:shadow-2xl',
            mobileOpen ? 'max-md:translate-x-0' : 'max-md:-translate-x-full',
        ]"
    >
        <!-- Brand -->
        <div :class="[ui.divider, 'flex h-14 shrink-0 items-center border-b', compact ? 'justify-center px-2' : 'justify-between px-4']">
            <div v-if="!compact" class="flex min-w-0 items-center gap-2.5">
                <img src="/sm_logo.png" alt="" class="h-7 w-7 shrink-0 object-contain" />
                <div class="min-w-0 leading-tight">
                    <p :class="[ui.strong, 'text-sm font-semibold']">dPanel Mail</p>
                    <p :class="[ui.muted, 'truncate text-[11px]']">{{ mailbox.domain || 'Webmail' }}</p>
                </div>
            </div>

            <button
                type="button"
                :class="[ui.iconButton, 'hidden h-8 w-8 items-center justify-center rounded-md transition md:flex']"
                :title="compact ? 'Expand sidebar' : 'Collapse sidebar'"
                :aria-label="compact ? 'Expand sidebar' : 'Collapse sidebar'"
                @click="emit('toggle-collapse')"
            >
                <i :class="['bi text-base', compact ? 'bi-layout-sidebar' : 'bi-layout-sidebar-inset']"></i>
            </button>

            <button
                type="button"
                :class="[ui.iconButton, 'flex h-8 w-8 items-center justify-center rounded-md transition md:hidden']"
                aria-label="Close menu"
                @click="emit('close-mobile')"
            >
                <i class="bi bi-x-lg text-sm"></i>
            </button>
        </div>

        <!-- Compose -->
        <div :class="compact ? 'flex justify-center px-2 pt-4 pb-3' : 'px-3 pt-4 pb-3'">
            <button
                type="button"
                :class="[
                    'flex items-center justify-center gap-2 rounded-lg bg-blue-600 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2',
                    isDark ? 'focus-visible:ring-offset-slate-950' : 'focus-visible:ring-offset-white',
                    compact ? 'h-10 w-10' : 'h-10 w-full px-4',
                ]"
                :title="compact ? 'Compose' : ''"
                aria-label="Compose"
                @click="emit('compose')"
            >
                <i class="bi bi-pencil-square text-[15px]"></i>
                <span v-if="!compact">Compose</span>
            </button>
        </div>

        <!-- Folders -->
        <nav class="flex-1 overflow-y-auto px-2 pb-3" aria-label="Folders">
            <p v-if="!compact" :class="[ui.muted, 'px-3 pb-1.5 pt-1 text-[11px] font-semibold uppercase tracking-wider']">Folders</p>

            <button
                v-for="folder in folders"
                :key="folder.name"
                type="button"
                :class="[
                    'group relative mb-0.5 flex w-full items-center gap-3 rounded-md text-left text-sm transition disabled:cursor-wait',
                    compact ? 'h-10 justify-center' : 'h-9 px-3',
                    folder.name === activeFolder ? [ui.itemActive, 'font-semibold'] : [ui.item, folder.unread > 0 ? 'font-semibold' : 'font-medium'],
                ]"
                :aria-current="folder.name === activeFolder ? 'page' : undefined"
                :disabled="loading"
                :title="compact ? `${folderLabel(folder.name)}${folder.unread ? ` (${folder.unread})` : ''}` : ''"
                @click="emit('open-folder', folder)"
            >
                <i :class="['bi shrink-0 text-[15px]', folderIcon(folder.name), folder.name === activeFolder ? ui.iconActive : ui.icon]"></i>

                <span v-if="!compact" class="flex-1 truncate">{{ folderLabel(folder.name) }}</span>

                <span
                    v-if="folder.unread > 0 && !compact"
                    :class="['text-xs tabular-nums', folder.name === activeFolder ? '' : ui.strong]"
                >
                    {{ folder.unread > 999 ? '999+' : folder.unread }}
                </span>

                <span
                    v-if="folder.unread > 0 && compact"
                    :class="['absolute right-3 top-2 h-2 w-2 rounded-full bg-blue-600 ring-2', isDark ? 'ring-slate-950' : 'ring-white']"
                ></span>
            </button>
        </nav>

        <!-- Storage -->
        <div :class="[ui.divider, 'border-t p-3']">
            <div
                v-if="!compact"
                :class="[ui.card, 'rounded-lg border px-3 py-2.5']"
                :title="'Based on messages in synced folders'"
            >
                <div class="flex items-center justify-between text-xs">
                    <span :class="[ui.text, 'flex items-center gap-1.5 font-medium']">
                        <i class="bi bi-hdd"></i>
                        Storage
                    </span>
                    <span :class="[ui.muted, 'tabular-nums']">{{ Math.round(usedPercent) }}%</span>
                </div>
                <div :class="[ui.track, 'mt-2 h-1.5 overflow-hidden rounded-full']">
                    <div :class="[barColor, 'h-full rounded-full transition-[width] duration-500']" :style="{ width: `${Math.max(usedPercent, usedBytes > 0 ? 2 : 0)}%` }"></div>
                </div>
                <p :class="[ui.muted, 'mt-1.5 text-[11px] tabular-nums']">
                    {{ formatBytes(usedBytes) }} of {{ mailbox.quota_mb ? `${mailbox.quota_mb} MB` : 'unlimited' }}
                </p>
            </div>

            <div v-else class="flex justify-center" :title="`Storage: ${formatBytes(usedBytes)} of ${mailbox.quota_mb} MB`">
                <div class="relative h-9 w-9">
                    <svg viewBox="0 0 36 36" class="h-9 w-9 -rotate-90">
                        <circle cx="18" cy="18" r="15" fill="none" stroke-width="3" :class="isDark ? 'stroke-slate-800' : 'stroke-slate-200'" />
                        <circle
                            cx="18" cy="18" r="15" fill="none" stroke-width="3" stroke-linecap="round"
                            :class="usedPercent >= 90 ? 'stroke-rose-500' : usedPercent >= 75 ? 'stroke-amber-500' : 'stroke-blue-600'"
                            :stroke-dasharray="`${(usedPercent / 100) * 94.25} 94.25`"
                        />
                    </svg>
                    <i :class="[ui.muted, 'bi bi-hdd absolute inset-0 flex items-center justify-center text-xs']"></i>
                </div>
            </div>
        </div>
    </aside>
</template>
