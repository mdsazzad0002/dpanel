<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatBytes, initialOf } from './mailboxFolders';

const props = defineProps({
    mailbox: {
        type: Object,
        required: true,
    },
    isDark: {
        type: Boolean,
        default: false,
    },
    searchQuery: {
        type: String,
        default: '',
    },
    activeFilterLabel: {
        type: String,
        default: 'All mail',
    },
    filterOptions: {
        type: Array,
        default: () => [],
    },
    messageFilter: {
        type: String,
        default: 'all',
    },
    filteredMessageCount: {
        type: Number,
        default: 0,
    },
    totalMessageCount: {
        type: Number,
        default: 0,
    },
    relatedMailboxes: {
        type: Array,
        default: () => [],
    },
    mailboxesHref: {
        type: String,
        required: true,
    },
    logoutHref: {
        type: String,
        required: true,
    },
    activeFolderLabel: {
        type: String,
        default: 'Inbox',
    },
    unreadCount: {
        type: Number,
        default: 0,
    },
    loading: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits([
    'refresh-inbox',
    'update:searchQuery',
    'set-message-filter',
    'toggle-theme',
    'open-mailbox',
    'toggle-mobile-sidebar',
]);

const topbarRef = ref(null);
const searchInput = ref(null);
const accountMenuOpen = ref(false);
const filterMenuOpen = ref(false);

const hasSearchQuery = computed(() => props.searchQuery.trim().length > 0);
const isFiltered = computed(() => props.messageFilter !== 'all');
const displayName = computed(() => {
    const local = String(props.mailbox.email || '').split('@')[0] || 'Mailbox';

    return local.charAt(0).toUpperCase() + local.slice(1);
});
const isMac = typeof navigator !== 'undefined' && /mac/i.test(navigator.platform || navigator.userAgent || '');

const ui = computed(() => (props.isDark
    ? {
        header: 'border-slate-800 bg-slate-950/90',
        muted: 'text-slate-500',
        text: 'text-slate-300',
        strong: 'text-slate-100',
        iconButton: 'text-slate-400 hover:bg-slate-800 hover:text-slate-100',
        search: 'bg-slate-900 text-slate-200 ring-slate-800 focus-within:bg-slate-900 focus-within:ring-blue-500/50',
        kbd: 'border-slate-700 bg-slate-800 text-slate-400',
        menu: 'border-slate-800 bg-slate-900 shadow-black/40',
        menuItem: 'text-slate-300 hover:bg-slate-800 hover:text-slate-100',
        menuItemActive: 'bg-blue-500/10 text-blue-300',
        divider: 'border-slate-800',
        vr: 'bg-slate-800',
        avatarRing: 'ring-slate-950',
    }
    : {
        header: 'border-slate-200 bg-white/90',
        muted: 'text-slate-500',
        text: 'text-slate-700',
        strong: 'text-slate-900',
        iconButton: 'text-slate-500 hover:bg-slate-100 hover:text-slate-900',
        search: 'bg-slate-100 text-slate-700 ring-transparent focus-within:bg-white focus-within:ring-blue-500/40 focus-within:shadow-sm',
        kbd: 'border-slate-200 bg-white text-slate-500',
        menu: 'border-slate-200 bg-white shadow-slate-900/10',
        menuItem: 'text-slate-700 hover:bg-slate-100 hover:text-slate-900',
        menuItemActive: 'bg-blue-50 text-blue-700',
        divider: 'border-slate-200',
        vr: 'bg-slate-200',
        avatarRing: 'ring-white',
    }));

const avatarColors = ['bg-blue-600', 'bg-violet-600', 'bg-emerald-600', 'bg-amber-600', 'bg-rose-600', 'bg-cyan-600', 'bg-indigo-600'];
const avatarColor = (email) => {
    const value = String(email || '');
    let hash = 0;
    for (let i = 0; i < value.length; i += 1) hash = (hash * 31 + value.charCodeAt(i)) >>> 0;

    return avatarColors[hash % avatarColors.length];
};

const closeMenus = () => {
    accountMenuOpen.value = false;
    filterMenuOpen.value = false;
};

const focusSearch = () => {
    searchInput.value?.focus?.();
};

const clearSearch = () => {
    emit('update:searchQuery', '');
    focusSearch();
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
    emit('set-message-filter', value);
    filterMenuOpen.value = false;
};

const openMailbox = (id) => {
    closeMenus();
    emit('open-mailbox', id);
};

const handleDocumentClick = (event) => {
    if (!topbarRef.value?.contains?.(event.target)) {
        closeMenus();
    }
};

const handleDocumentKeydown = (event) => {
    if (event.key === 'Escape') {
        closeMenus();
        return;
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        focusSearch();
    }
};

onMounted(() => {
    document.addEventListener('click', handleDocumentClick);
    document.addEventListener('keydown', handleDocumentKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleDocumentClick);
    document.removeEventListener('keydown', handleDocumentKeydown);
});
</script>

<template>
    <header
        ref="topbarRef"
        :class="[ui.header, 'sticky top-0 z-30 flex h-14 shrink-0 items-center border-b backdrop-blur-md']"
    >
        <div class="flex w-full items-center gap-3 px-3 md:gap-4 md:px-5">
            <!-- Left: menu + current folder -->
            <div class="flex min-w-0 items-center gap-2 md:w-56 md:shrink-0">
                <button
                    type="button"
                    :class="[ui.iconButton, 'flex h-9 w-9 shrink-0 items-center justify-center rounded-md transition md:hidden']"
                    aria-label="Open menu"
                    @click="emit('toggle-mobile-sidebar')"
                >
                    <i class="bi bi-list text-xl"></i>
                </button>

                <div class="hidden min-w-0 sm:block">
                    <h1 :class="[ui.strong, 'truncate text-[15px] font-semibold leading-tight']">{{ activeFolderLabel }}</h1>
                    <p :class="[ui.muted, 'truncate text-xs leading-tight tabular-nums']">
                        {{ totalMessageCount }} {{ totalMessageCount === 1 ? 'message' : 'messages' }}<template v-if="unreadCount > 0"> · {{ unreadCount }} unread</template>
                    </p>
                </div>
            </div>

            <!-- Center: search + filter -->
            <div class="flex min-w-0 flex-1 justify-center">
                <div :class="[ui.search, 'relative flex h-10 w-full max-w-2xl items-center gap-2 rounded-lg pl-3 pr-1.5 ring-1 transition']">
                    <i class="bi bi-search text-sm opacity-60"></i>
                    <input
                        ref="searchInput"
                        :value="searchQuery"
                        type="search"
                        class="h-full min-w-0 flex-1 border-none bg-transparent p-0 text-sm outline-none ring-0 placeholder:text-slate-400 focus:ring-0 [&::-webkit-search-cancel-button]:hidden"
                        placeholder="Search mail"
                        aria-label="Search mail"
                        @input="emit('update:searchQuery', $event.target.value)"
                    >

                    <span v-if="hasSearchQuery" :class="[ui.muted, 'hidden text-xs tabular-nums md:inline']">
                        {{ filteredMessageCount }} of {{ totalMessageCount }}
                    </span>
                    <button
                        v-if="hasSearchQuery"
                        type="button"
                        :class="[ui.iconButton, 'flex h-7 w-7 items-center justify-center rounded-md transition']"
                        aria-label="Clear search"
                        @click="clearSearch"
                    >
                        <i class="bi bi-x-lg text-xs"></i>
                    </button>
                    <kbd
                        v-else
                        :class="[ui.kbd, 'hidden items-center rounded border px-1.5 py-0.5 font-sans text-[11px] font-medium lg:inline-flex']"
                    >{{ isMac ? '⌘' : 'Ctrl' }} K</kbd>

                    <!-- Filter -->
                    <div class="relative">
                        <button
                            type="button"
                            :class="[
                                'flex h-7 items-center gap-1.5 rounded-md px-2 text-xs font-medium transition',
                                isFiltered ? (isDark ? 'bg-blue-500/15 text-blue-300' : 'bg-blue-100 text-blue-700') : ui.iconButton,
                            ]"
                            aria-haspopup="menu"
                            :aria-expanded="filterMenuOpen"
                            :title="`Filter: ${activeFilterLabel}`"
                            @click="toggleFilterMenu"
                        >
                            <i class="bi bi-sliders2 text-sm"></i>
                            <span class="hidden sm:inline">{{ isFiltered ? activeFilterLabel : 'Filter' }}</span>
                        </button>

                        <transition
                            enter-active-class="transition duration-100 ease-out"
                            enter-from-class="-translate-y-1 opacity-0"
                            leave-active-class="transition duration-75 ease-in"
                            leave-to-class="-translate-y-1 opacity-0"
                        >
                            <div
                                v-if="filterMenuOpen"
                                role="menu"
                                :class="[ui.menu, 'absolute right-0 top-[calc(100%+0.5rem)] z-40 w-60 rounded-lg border p-1 shadow-xl']"
                            >
                                <p :class="[ui.muted, 'px-2.5 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wider']">Show</p>
                                <button
                                    v-for="option in filterOptions"
                                    :key="option.value"
                                    type="button"
                                    role="menuitemradio"
                                    :aria-checked="messageFilter === option.value"
                                    :class="[messageFilter === option.value ? ui.menuItemActive : ui.menuItem, 'flex w-full items-center gap-3 rounded-md px-2.5 py-2 text-left transition']"
                                    @click="setMessageFilter(option.value)"
                                >
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium">{{ option.label }}</span>
                                        <span :class="[ui.muted, 'block text-xs']">{{ option.hint }}</span>
                                    </span>
                                    <i v-if="messageFilter === option.value" class="bi bi-check2 text-base"></i>
                                </button>
                            </div>
                        </transition>
                    </div>
                </div>
            </div>

            <!-- Right: actions + account -->
            <div class="flex shrink-0 items-center gap-1 md:w-56 md:justify-end">
                <button
                    type="button"
                    :class="[ui.iconButton, 'flex h-9 w-9 items-center justify-center rounded-md transition disabled:opacity-60']"
                    title="Refresh"
                    aria-label="Refresh"
                    :disabled="loading"
                    @click="emit('refresh-inbox')"
                >
                    <i :class="['bi bi-arrow-clockwise text-base', loading ? 'animate-spin' : '']"></i>
                </button>

                <button
                    type="button"
                    :class="[ui.iconButton, 'hidden h-9 w-9 items-center justify-center rounded-md transition sm:flex']"
                    :title="isDark ? 'Light mode' : 'Dark mode'"
                    :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                    @click="emit('toggle-theme')"
                >
                    <i :class="['bi text-base', isDark ? 'bi-sun' : 'bi-moon']"></i>
                </button>

                <span :class="[ui.vr, 'mx-1.5 hidden h-6 w-px sm:block']"></span>

                <!-- Account -->
                <div class="relative">
                    <button
                        type="button"
                        :class="[ui.iconButton, 'flex items-center gap-2 rounded-full p-0.5 transition lg:rounded-lg lg:py-1 lg:pl-1 lg:pr-2']"
                        aria-haspopup="menu"
                        :aria-expanded="accountMenuOpen"
                        aria-label="Account menu"
                        @click="toggleAccountMenu"
                    >
                        <span :class="[avatarColor(mailbox.email), 'relative flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full text-sm font-semibold text-white']">
                            <span v-if="mailbox.avatar_url" class="block h-full w-full bg-cover bg-center" :style="{ backgroundImage: `url(${mailbox.avatar_url})` }"></span>
                            <template v-else>{{ initialOf(mailbox.email) }}</template>
                        </span>
                        <span class="hidden min-w-0 text-left leading-tight lg:block">
                            <span :class="[ui.strong, 'block max-w-[120px] truncate text-[13px] font-medium']">{{ displayName }}</span>
                            <span :class="[ui.muted, 'block max-w-[120px] truncate text-[11px]']">{{ mailbox.domain }}</span>
                        </span>
                        <i :class="[ui.muted, 'bi bi-chevron-down hidden text-[10px] lg:block']"></i>
                    </button>

                    <transition
                        enter-active-class="transition duration-100 ease-out"
                        enter-from-class="-translate-y-1 opacity-0"
                        leave-active-class="transition duration-75 ease-in"
                        leave-to-class="-translate-y-1 opacity-0"
                    >
                        <div
                            v-if="accountMenuOpen"
                            role="menu"
                            :class="[ui.menu, 'absolute right-0 top-[calc(100%+0.5rem)] z-40 w-[calc(100vw-1.5rem)] max-w-[320px] overflow-hidden rounded-xl border shadow-xl']"
                        >
                            <!-- Identity -->
                            <div class="flex items-center gap-3 px-4 pb-3 pt-4">
                                <span :class="[avatarColor(mailbox.email), 'relative flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full text-base font-semibold text-white']">
                                    <span v-if="mailbox.avatar_url" class="block h-full w-full bg-cover bg-center" :style="{ backgroundImage: `url(${mailbox.avatar_url})` }"></span>
                                    <template v-else>{{ initialOf(mailbox.email) }}</template>
                                    <span
                                        :class="[ui.avatarRing, 'absolute bottom-0 right-0 h-3 w-3 rounded-full ring-2', mailbox.status === 'active' ? 'bg-emerald-500' : 'bg-slate-400']"
                                    ></span>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p :class="[ui.strong, 'truncate text-sm font-semibold']">{{ displayName }}</p>
                                    <p :class="[ui.muted, 'truncate text-xs']">{{ mailbox.email }}</p>
                                </div>
                            </div>

                            <div :class="[ui.divider, 'grid grid-cols-2 gap-px border-y text-xs', isDark ? 'bg-slate-800' : 'bg-slate-200']">
                                <div :class="[isDark ? 'bg-slate-900' : 'bg-white', 'px-4 py-2.5']">
                                    <p :class="ui.muted">Status</p>
                                    <p :class="[ui.strong, 'mt-0.5 flex items-center gap-1.5 font-medium capitalize']">
                                        <span :class="['h-1.5 w-1.5 rounded-full', mailbox.status === 'active' ? 'bg-emerald-500' : 'bg-slate-400']"></span>
                                        {{ mailbox.status || 'Unknown' }}
                                    </p>
                                </div>
                                <div :class="[isDark ? 'bg-slate-900' : 'bg-white', 'px-4 py-2.5']">
                                    <p :class="ui.muted">Storage</p>
                                    <p :class="[ui.strong, 'mt-0.5 font-medium tabular-nums']">
                                        {{ formatBytes(mailbox.used_bytes) }} / {{ mailbox.quota_mb ? `${mailbox.quota_mb} MB` : '∞' }}
                                    </p>
                                </div>
                            </div>

                            <!-- Switch account -->
                            <div v-if="relatedMailboxes.length" class="p-1.5">
                                <p :class="[ui.muted, 'px-2.5 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wider']">Switch account</p>
                                <div class="max-h-56 overflow-y-auto">
                                    <button
                                        v-for="item in relatedMailboxes"
                                        :key="item.id"
                                        type="button"
                                        role="menuitem"
                                        :class="[ui.menuItem, 'flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm transition']"
                                        @click="openMailbox(item.id)"
                                    >
                                        <span :class="[avatarColor(item.email), 'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white']">{{ initialOf(item.email) }}</span>
                                        <span class="min-w-0 flex-1 truncate">{{ item.email }}</span>
                                        <i :class="[ui.muted, 'bi bi-arrow-right-short text-base']"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div :class="[ui.divider, 'border-t p-1.5']">
                                <Link
                                    :href="mailboxesHref"
                                    role="menuitem"
                                    :class="[ui.menuItem, 'flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-sm transition']"
                                >
                                    <i :class="[ui.muted, 'bi bi-grid text-[15px]']"></i>
                                    Manage mailboxes
                                </Link>
                                <button
                                    type="button"
                                    role="menuitem"
                                    :class="[ui.menuItem, 'flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm transition']"
                                    @click="emit('toggle-theme')"
                                >
                                    <i :class="[ui.muted, 'bi text-[15px]', isDark ? 'bi-sun' : 'bi-moon']"></i>
                                    <span class="flex-1">{{ isDark ? 'Light mode' : 'Dark mode' }}</span>
                                </button>
                            </div>

                            <div :class="[ui.divider, 'border-t p-1.5']">
                                <Link
                                    :href="logoutHref"
                                    method="post"
                                    as="button"
                                    role="menuitem"
                                    :class="[isDark ? 'text-rose-300 hover:bg-rose-500/10' : 'text-rose-600 hover:bg-rose-50', 'flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm font-medium transition']"
                                >
                                    <i class="bi bi-box-arrow-right text-[15px]"></i>
                                    Sign out
                                </Link>
                            </div>
                        </div>
                    </transition>
                </div>
            </div>
        </div>
    </header>
</template>
