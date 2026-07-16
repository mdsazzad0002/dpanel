<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

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
    sidebarCollapsed: {
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
    'toggle-sidebar',
    'toggle-mobile-sidebar',
]);

const topbarRef = ref(null);
const searchInput = ref(null);
const accountMenuOpen = ref(false);
const filterMenuOpen = ref(false);

const hasSearchQuery = computed(() => props.searchQuery.trim().length > 0);

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
        :class="isDark
            ? 'sticky top-0 z-30 flex h-14 shrink-0 items-center border-b border-slate-800 bg-slate-950/95 backdrop-blur'
            : 'sticky top-0 z-30 flex h-14 shrink-0 items-center border-b border-slate-200 bg-white/95 backdrop-blur'"
    >
        <div class="relative flex w-full flex-wrap items-center justify-between gap-4 px-4 md:px-6">
            <!-- Left: Sidebar Toggle + Mail Info -->
            <div class="flex items-center gap-3">
                <!-- Mobile Sidebar Toggle -->
                <button
                    type="button"
                    :class="isDark
                        ? 'flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 md:hidden'
                        : 'flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700 md:hidden'"
                    @click="emit('toggle-mobile-sidebar')"
                    aria-label="Toggle sidebar"
                >
                    <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current">
                        <path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z" />
                    </svg>
                </button>

                <!-- Desktop Sidebar Collapse Toggle -->
                <button
                    type="button"
                    :class="isDark
                        ? 'hidden h-9 w-9 items-center justify-center rounded-lg border border-slate-700 bg-slate-800/50 text-slate-400 transition hover:border-slate-600 hover:bg-slate-800 hover:text-slate-200 md:flex'
                        : 'hidden h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700 md:flex'"
                    @click="emit('toggle-sidebar')"
                    :aria-label="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                >
                    <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                        <path v-if="sidebarCollapsed" d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z" />
                        <path v-else d="M3 6h18v2H3V6zm5 5h13v2H8v-2zm5 5h8v2h-8v-2z" />
                    </svg>
                </button>

                <!-- Mail Center Info -->
                <button
                    type="button"
                    class="flex items-center gap-2 text-left transition hover:opacity-90"
                    @click="emit('refresh-inbox')"
                    aria-label="Refresh inbox"
                >
                    <div>
                        <p :class="isDark ? 'text-[10px] uppercase tracking-[0.24em] text-slate-500' : 'text-[10px] uppercase tracking-[0.24em] text-slate-400'">Mail Center</p>
                        <h1 :class="isDark ? 'text-base font-semibold text-slate-100' : 'text-base font-semibold text-slate-900'">Inbox</h1>
                    </div>
                </button>
            </div>

            <!-- Center: Search Bar -->
            <div
                :class="isDark
                    ? 'relative flex w-full max-w-2xl flex-1 items-center gap-3 rounded-full border border-slate-700 bg-slate-900 text-slate-300 shadow-inner transition focus-within:border-blue-500/60 focus-within:ring-2 focus-within:ring-blue-500/20'
                    : 'relative flex w-full max-w-2xl flex-1 items-center gap-3 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-slate-500 shadow-sm transition focus-within:border-blue-400 focus-within:ring-2 focus-within:ring-blue-100'"
            >
                <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 opacity-50">
                    <path fill-rule="evenodd" d="M8.5 3a5.5 5.5 0 104.19 9.07l3.12 3.12a.75.75 0 101.06-1.06l-3.12-3.12A5.5 5.5 0 008.5 3zm-4 5.5a4 4 0 118 0 4 4 0 01-8 0z" clip-rule="evenodd" />
                </svg>
                <input
                    ref="searchInput"
                    :value="searchQuery"
                    type="search"
                    :class="isDark ? 'min-w-0 flex-1 border-none bg-transparent text-sm outline-none placeholder:text-slate-500' : 'min-w-0 flex-1 border-none bg-transparent text-sm outline-none placeholder:text-slate-400'"
                    placeholder="Search mail, subject, sender..."
                    aria-label="Search mail"
                    @input="emit('update:searchQuery', $event.target.value)"
                >
                <button
                    v-if="hasSearchQuery"
                    type="button"
                    :class="isDark
                        ? 'rounded-full px-2 py-1 text-xs font-medium text-slate-400 transition hover:bg-slate-800 hover:text-slate-200'
                        : 'rounded-full px-2 py-1 text-xs font-medium text-slate-400 transition hover:bg-slate-100 hover:text-slate-700'"
                    @click="clearSearch"
                >
                    Clear
                </button>
                <div :class="isDark ? 'hidden text-[11px] text-slate-500 md:block' : 'hidden text-[11px] text-slate-400 md:block'">
                    {{ filteredMessageCount }} / {{ totalMessageCount }}
                </div>
                <div class="relative shrink-0">
                    <button
                        type="button"
                        :class="isDark
                            ? 'flex items-center gap-2 rounded-full border border-slate-700 bg-slate-950/80 px-3 py-1.5 text-xs font-medium text-slate-200 transition hover:border-blue-500/50 hover:bg-slate-900'
                            : 'flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-blue-200 hover:bg-blue-50'"
                        @click="toggleFilterMenu"
                        aria-haspopup="menu"
                        :aria-expanded="filterMenuOpen"
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

            <!-- Right: Actions -->
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    :href="mailboxesHref"
                    :class="isDark
                        ? 'rounded-full border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 hover:bg-slate-800'
                        : 'rounded-full border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50'"
                >
                    Mailboxes
                </Link>

                <!-- Theme Toggle -->
                <button
                    type="button"
                    :class="isDark
                        ? 'flex h-9 w-9 items-center justify-center rounded-lg border border-slate-700 bg-slate-800/50 text-slate-400 transition hover:border-slate-600 hover:bg-slate-800 hover:text-slate-200'
                        : 'flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700'"
                    @click="emit('toggle-theme')"
                    :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                    :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                >
                    <svg v-if="isDark" viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                        <path d="M12 18a6 6 0 110-12 6 6 0 010 12zm0-2a4 4 0 100-8 4 4 0 000 8zM11 1h2v3h-2V1zm0 19h2v3h-2v-3zM3.515 4.929l1.414-1.414L7.05 5.636 5.636 7.05 3.515 4.93zM16.95 18.364l1.414-1.414 2.121 2.121-1.414 1.414-2.121-2.121zM1 11h3v2H1v-2zm19 0h3v2h-3v-2zM3.515 19.071l2.121-2.121 1.414 1.414-2.121 2.121-1.414-1.414zM16.95 5.636l2.121-2.121 1.414 1.414-2.121 2.121L16.95 5.636z" />
                    </svg>
                    <svg v-else viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                        <path d="M12 18a6 6 0 110-12 6 6 0 010 12zM9 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm0 17a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM4.222 4.222a1 1 0 011.414 0l.707.707A1 1 0 014.93 6.344l-.707-.708a1 1 0 010-1.414zm12.728 12.728a1 1 0 011.414 0l.707.707a1 1 0 01-1.414 1.414l-.707-.707a1 1 0 010-1.414zM4 12a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm15 0a1 1 0 011-1h1a1 1 0 110 2h-1a1 1 0 01-1-1zM4.222 19.778a1 1 0 010-1.414l.707-.707a1 1 0 011.414 1.414l-.707.707a1 1 0 01-1.414 0zM16.95 7.05a1 1 0 010-1.414l.707-.707A1 1 0 0119.071 6.344l-.707.707a1 1 0 01-1.414 0z" />
                    </svg>
                </button>

                <!-- Account Menu -->
                <div class="relative">
                    <button
                        type="button"
                        class="flex h-9 w-9 items-center justify-center overflow-hidden rounded-full border border-slate-200 bg-slate-100 shadow-sm transition hover:scale-[1.02] hover:border-blue-300 dark:border-slate-700 dark:bg-slate-800"
                        @click="toggleAccountMenu"
                    >
                        <span v-if="mailbox.avatar_url" class="block h-full w-full bg-cover bg-center" :style="{ backgroundImage: `url(${mailbox.avatar_url})` }"></span>
                        <span v-else :class="isDark ? 'text-sm font-semibold text-slate-100' : 'text-sm font-semibold text-slate-700'">{{ mailbox.email?.slice(0, 1)?.toUpperCase() || 'M' }}</span>
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
                            v-if="accountMenuOpen"
                            :class="isDark
                                ? 'absolute right-0 top-[calc(100%+0.75rem)] z-40 w-[300px] rounded-[24px] border border-slate-800 bg-slate-900 p-4 shadow-2xl'
                                : 'absolute right-0 top-[calc(100%+0.75rem)] z-40 w-[300px] rounded-[24px] border border-slate-200 bg-white p-4 shadow-2xl'"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p :class="isDark ? 'text-xs uppercase tracking-[0.24em] text-slate-400' : 'text-xs uppercase tracking-[0.24em] text-slate-500'">Account</p>
                                    <h2 :class="isDark ? 'mt-1 truncate text-base font-semibold text-slate-100' : 'mt-1 truncate text-base font-semibold text-slate-900'">{{ mailbox.email }}</h2>
                                </div>
                                <button type="button" :class="isDark ? 'rounded-full p-2 text-slate-300 hover:bg-slate-800' : 'rounded-full p-2 text-slate-600 hover:bg-slate-100'" @click="closeMenus">
                                    <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true">
                                        <path d="M18.3 5.7a1 1 0 00-1.4-1.4L12 9.17 7.1 4.3A1 1 0 105.7 5.7L10.59 10.6 5.7 15.5a1 1 0 101.4 1.4l4.9-4.89 4.9 4.89a1 1 0 001.4-1.4l-4.89-4.9 4.89-4.9z" />
                                    </svg>
                                </button>
                            </div>

                            <div :class="isDark ? 'mt-4 rounded-2xl border border-slate-800 bg-slate-950 p-3' : 'mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3'">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-full bg-blue-600 text-sm font-semibold text-white shadow-sm">
                                        <span v-if="mailbox.avatar_url" class="block h-full w-full bg-cover bg-center" :style="{ backgroundImage: `url(${mailbox.avatar_url})` }"></span>
                                        <span v-else>{{ mailbox.email?.slice(0, 1)?.toUpperCase() || 'M' }}</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p :class="isDark ? 'text-sm font-semibold text-slate-100' : 'text-sm font-semibold text-slate-900'">Hi, {{ mailbox.email?.split('@')[0] || 'User' }}</p>
                                        <p :class="isDark ? 'mt-0.5 text-xs text-slate-400' : 'mt-0.5 text-xs text-slate-500'">{{ mailbox.domain || '-' }}</p>
                                    </div>
                                </div>
                                <div :class="isDark ? 'mt-3 rounded-xl bg-slate-900/70 px-3 py-2 text-sm text-slate-300' : 'mt-3 rounded-xl bg-white px-3 py-2 text-sm text-slate-700'">
                                    <div class="flex items-center justify-between gap-3">
                                        <span :class="isDark ? 'text-slate-400' : 'text-slate-500'">Quota</span>
                                        <span class="font-medium">{{ mailbox.quota_mb }} MB</span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 space-y-1.5">
                                <button
                                    v-for="item in relatedMailboxes"
                                    :key="item.id"
                                    type="button"
                                    :class="isDark
                                        ? 'flex w-full items-center justify-between gap-3 rounded-xl border border-slate-800 bg-slate-950 px-3 py-2.5 text-left text-sm text-slate-200 hover:border-blue-500/60 hover:bg-blue-950/30'
                                        : 'flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-left text-sm text-slate-700 hover:border-blue-200 hover:bg-blue-50'"
                                    @click="emit('open-mailbox', item.id)"
                                >
                                    <span class="min-w-0 truncate">{{ item.email }}</span>
                                    <span :class="isDark ? 'text-xs text-slate-500' : 'text-xs text-slate-400'">Switch</span>
                                </button>
                            </div>

                            <div :class="isDark ? 'mt-3 border-t border-slate-800 pt-3' : 'mt-3 border-t border-slate-200 pt-3'">
                                <Link
                                    :href="logoutHref"
                                    method="post"
                                    as="button"
                                    class="flex w-full items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-left text-sm font-medium text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-200 dark:hover:bg-rose-950/50"
                                >
                                    <span>Log Out</span>
                                    <span class="text-xs opacity-70">Sign out</span>
                                </Link>
                            </div>
                        </div>
                    </transition>
                </div>
            </div>
        </div>
    </header>
</template>
