<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import Modal from '@/Components/Modal.vue';
import { Link, router, usePage, useRemember } from '@inertiajs/vue3';

const sidebarOpen = useRemember(false, 'layout.sidebar.open');
const theme = useRemember('light', 'layout.theme');
const expandedGroups = useRemember([], 'layout.sidebar.expanded');
const sidebarScrollTop = useRemember(0, 'layout.sidebar.scrollTop');
const searchQuery = useRemember('', 'layout.search.query');
const isSearchOpen = ref(false);
const searchInputRef = ref(null);
const searchResultsRef = ref(null);
const activeSearchIndex = ref(0);
const sidebarNavRef = ref(null);
const page = usePage();
const SIDEBAR_SCROLL_KEY = 'layout.sidebar.scrollTop.v1';

const rolePanelConfig = {
    admin: { label: 'Admin', hint: 'Super admin panel', icon: 'SA', iconClass: 'bi bi-person-gear', routeName: 'admin.panel', routeParams: { role: 'admin' }, roles: ['admin'] },
    reseller: { label: 'Reseller', hint: 'Reseller panel', icon: 'RS', iconClass: 'bi bi-person-workspace', routeName: 'reseller.panel', routeParams: { role: 'reseller' }, roles: ['admin', 'reseller'] },
    general: { label: 'General User', hint: 'General user panel', icon: 'US', iconClass: 'bi bi-person', routeName: 'user.panel', routeParams: { role: 'general' }, roles: ['admin', 'reseller', 'general', 'general_user'] },
    general_user: { label: 'General User', hint: 'General user panel', icon: 'US', iconClass: 'bi bi-person', routeName: 'user.panel', routeParams: { role: 'general' }, roles: ['admin', 'reseller', 'general', 'general_user'] },
};

const userRoles = computed(() => page.props.auth?.roles ?? []);
const userRoleLabel = computed(() => userRoles.value.join(', ') || 'No role');
const userPermissions = computed(() => page.props.auth?.permissions ?? []);
const panelToken = computed(() => page.props.panel?.token ?? '');
const panelSearchItems = computed(() => Array.isArray(page.props.panelSearch?.items) ? page.props.panelSearch.items : []);
const currentUser = computed(() => page.props.auth?.user ?? {});
const userName = computed(() => String(currentUser.value?.name ?? 'User'));
const userEmail = computed(() => String(currentUser.value?.email ?? ''));
const userInitials = computed(() => userName.value
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('') || 'U');

const panelRoute = (name, params = {}) => (panelToken.value
    ? route(name, { token: panelToken.value, ...params })
    : route(name, params));

const dynamicUserManagementChildren = computed(() => {
    const roleChildren = ['admin', 'reseller', 'general']
        .map((role) => rolePanelConfig[role])
        .filter(Boolean)
        .filter((item) => item.routeName && route().has(item.routeName));

    const adminRuleChildren = userRoles.value.includes('admin')
        ? [
            { label: 'Manage Roles', hint: 'Edit existing roles', icon: 'MR', iconClass: 'bi bi-shield-check', routeName: 'roles.manage', roles: ['admin'] },
        ]
        : [];

    return [
        ...roleChildren,
        { label: 'All Users', hint: 'Shared users panel', icon: 'MU', iconClass: 'bi bi-person-plus', routeName: 'users.manage', roles: ['admin', 'reseller', 'general', 'general_user'] },
        ...adminRuleChildren,
    ];
});

const menuItems = computed(() => [
    { label: 'Dashboard', hint: 'Overview and stats', icon: 'DB', iconClass: 'bi bi-speedometer2', routeName: 'dashboard' },
    {
        id: 'web-management',
        label: 'Web Management',
        hint: 'Website operations',
        icon: 'WM',
        iconClass: 'bi bi-globe2',
        children: [
            { label: 'Create Website', hint: 'Add a new website', icon: 'CW', iconClass: 'bi bi-plus-square', routeName: 'websites.create', roles: ['admin', 'reseller'] },
            { label: 'List Websites', hint: 'View all websites', icon: 'LW', iconClass: 'bi bi-list-ul', routeName: 'websites.list', roles: ['admin', 'reseller'] },
        ],
    },
    {
        id: 'email-management',
        label: 'Email Management',
        hint: 'Mailbox operations',
        icon: 'EM',
        iconClass: 'bi bi-envelope',
        children: [
            { label: 'Create Email', hint: 'Add a mailbox', icon: 'CE', iconClass: 'bi bi-envelope-plus', routeName: 'emails.create', roles: ['admin', 'reseller'] },
            { label: 'List Emails', hint: 'View all mailboxes', icon: 'LE', iconClass: 'bi bi-envelope-open', routeName: 'emails.list', roles: ['admin', 'reseller'] },
        ],
    },
    {
        id: 'database-management',
        label: 'Database Management',
        hint: 'Database operations and phpMyAdmin',
        icon: 'DM',
        iconClass: 'bi bi-database',
        children: [
            { label: 'Create Database', hint: 'Create a new database', icon: 'CD', iconClass: 'bi bi-database-add', routeName: 'databases.create', roles: ['admin', 'reseller'] },
            { label: 'List Databases', hint: 'View all databases', icon: 'LD', iconClass: 'bi bi-table', routeName: 'databases.list', roles: ['admin', 'reseller'] },
        ],
    },
    {
        id: 'dns-management',
        label: 'DNS Management',
        hint: 'DNS zones and nameservers',
        icon: 'DN',
        iconClass: 'bi bi-diagram-3',
        children: [
            { label: 'Nameservers', hint: 'Manage NS records', icon: 'NS', iconClass: 'bi bi-signpost-split', routeName: 'dns.nameservers', roles: ['admin', 'reseller'] },
            { label: 'DNS Zones', hint: 'Manage DNS zones', icon: 'DZ', iconClass: 'bi bi-bounding-box-circles', routeName: 'dns.zones', roles: ['admin', 'reseller'] },
            { label: 'DNS Records', hint: 'A, CNAME, MX, TXT records', icon: 'DR', iconClass: 'bi bi-journal-code', routeName: 'dns.records', roles: ['admin', 'reseller'] },
        ],
    },
    { label: 'PHP Management', hint: 'Versions, extensions and config', icon: 'PH', iconClass: 'bi bi-braces', routeName: 'php.manager', roles: ['admin', 'reseller'] },
    { label: 'Apache + Nginx Setup', hint: 'Web server stack and vHost controls', icon: 'AP', iconClass: 'bi bi-hdd-network', routeName: 'apache.index', roles: ['admin', 'reseller'] },
    { label: 'Security', hint: 'Firewall, SSH and hardening', icon: 'SC', iconClass: 'bi bi-shield-lock', routeName: 'security.manager', roles: ['admin', 'reseller'] },
    { label: 'Backups', hint: 'Snapshots and restore', icon: 'BK', iconClass: 'bi bi-cloud-arrow-down', dynamicRouteNames: ['backups.index', 'monitoring.index'] },
    { label: 'Monitoring', hint: 'CPU, RAM, disk, logs', icon: 'MN', iconClass: 'bi bi-activity', routeName: 'monitoring.index', roles: ['admin', 'reseller'] },
    {
        id: 'user-management',
        label: 'User Management',
        hint: 'Admin, reseller and user panels',
        icon: 'UM',
        iconClass: 'bi bi-people',
        children: dynamicUserManagementChildren.value,
    },
]);

const hasAccess = (item) => {
    if (item.permissions?.length) {
        return item.permissions.some((permission) => userPermissions.value.includes(permission));
    }

    if (!item.roles) return true;
    return item.roles.some((role) => userRoles.value.includes(role));
};

const visibleMenu = computed(() => menuItems.value
    .map((item) => {
        if (!item.children) {
            return hasAccess(item) ? item : null;
        }

        const allowedChildren = item.children.filter((child) => hasAccess(child));
        if (!allowedChildren.length) return null;

        return {
            ...item,
            children: allowedChildren,
        };
    })
    .filter(Boolean));

const normalizeSearchText = (value) => String(value ?? '').toLowerCase().trim();

const buildSearchText = (item) => normalizeSearchText([
    item?.label ?? '',
    item?.hint ?? '',
    item?.group ?? '',
    ...(Array.isArray(item?.keywords) ? item.keywords : []),
].join(' '));

const filteredSearchResults = computed(() => {
    const needle = normalizeSearchText(searchQuery.value);
    const items = panelSearchItems.value
        .filter((item) => item && typeof item.href === 'string' && item.href.length > 0);

    if (!needle) {
        return items.slice(0, 12);
    }

    return items
        .map((item) => ({
            ...item,
            __searchText: buildSearchText(item),
        }))
        .filter((item) => item.__searchText.includes(needle))
        .sort((a, b) => {
            const aStarts = a.__searchText.startsWith(needle) ? 0 : 1;
            const bStarts = b.__searchText.startsWith(needle) ? 0 : 1;

            if (aStarts !== bStarts) return aStarts - bStarts;
            return a.__searchText.localeCompare(b.__searchText);
        })
        .slice(0, 12);
});

const applyTheme = (mode) => {
    document.documentElement.classList.toggle('dark', mode === 'dark');
};

const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    localStorage.setItem('serverpanel-theme', theme.value);
    applyTheme(theme.value);
};

const toggleGroup = (groupId) => {
    if (expandedGroups.value[0] === groupId) {
        expandedGroups.value = [];
        return;
    }

    expandedGroups.value = [groupId];
};

const isGroupExpanded = (groupId) => expandedGroups.value.includes(groupId);

const resolveItemRouteName = (item) => {
    if (item?.routeName && route().has(item.routeName)) {
        return item.routeName;
    }

    if (Array.isArray(item?.dynamicRouteNames)) {
        return item.dynamicRouteNames.find((name) => route().has(name)) || null;
    }

    return null;
};

const openSearch = async () => {
    isSearchOpen.value = true;
    activeSearchIndex.value = 0;

    await nextTick();
    searchInputRef.value?.focus?.();
    searchInputRef.value?.select?.();
};

const closeSearch = () => {
    isSearchOpen.value = false;
};

const openSearchResult = (item) => {
    if (!item?.href) return;

    closeSearch();
    router.visit(item.href);
};

const moveSearchSelection = (direction) => {
    const results = filteredSearchResults.value;
    if (!results.length) return;

    const nextIndex = Math.min(
        results.length - 1,
        Math.max(0, activeSearchIndex.value + direction),
    );
    activeSearchIndex.value = nextIndex;
};

const handleSearchKeydown = (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        openSearch();
        return;
    }

    if (!isSearchOpen.value) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        moveSearchSelection(1);
        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        moveSearchSelection(-1);
        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        openSearchResult(filteredSearchResults.value[activeSearchIndex.value]);
    }
};

const updateSidebarScrollTop = () => {
    const nav = sidebarNavRef.value;
    if (!nav) return;
    const currentTop = nav.scrollTop;
    sidebarScrollTop.value = currentTop;
    sessionStorage.setItem(SIDEBAR_SCROLL_KEY, String(currentTop));
};

const restoreSidebarScrollTop = async () => {
    await nextTick();
    const nav = sidebarNavRef.value;
    if (!nav) return;
    const storedTop = Number(sessionStorage.getItem(SIDEBAR_SCROLL_KEY));
    const targetTop = Number.isFinite(storedTop) ? storedTop : Number(sidebarScrollTop.value || 0);
    requestAnimationFrame(() => {
        nav.scrollTop = targetTop;
    });
};

onMounted(() => {
    const savedTheme = localStorage.getItem('serverpanel-theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    theme.value = savedTheme ?? (prefersDark ? 'dark' : 'light');
    applyTheme(theme.value);

    const activeGroup = menuItems.value.find(
        (item) =>
            item.children &&
            item.id &&
            item.children.some((child) => child.routeName && route().current(child.routeName)),
    );

    if (expandedGroups.value.length === 0) {
        expandedGroups.value = activeGroup?.id ? [activeGroup.id] : [];
    }

    restoreSidebarScrollTop();
    document.addEventListener('keydown', handleSearchKeydown);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleSearchKeydown);
});

watch(sidebarOpen, (isOpen) => {
    if (isOpen) {
        restoreSidebarScrollTop();
    }
});

watch(
    () => page.url,
    () => {
        restoreSidebarScrollTop();
    },
);

watch(searchQuery, () => {
    activeSearchIndex.value = 0;
});

watch(filteredSearchResults, async (results) => {
    if (!results.length) {
        activeSearchIndex.value = 0;
        return;
    }

    if (activeSearchIndex.value >= results.length) {
        activeSearchIndex.value = results.length - 1;
    }

    await nextTick();
    const activeItem = searchResultsRef.value?.querySelector('[data-search-result-active="true"]');
    activeItem?.scrollIntoView?.({ block: 'nearest' });
});

watch(isSearchOpen, async (open) => {
    if (!open) {
        return;
    }

    await nextTick();
    searchInputRef.value?.focus?.();
    searchInputRef.value?.select?.();
});
</script>

<template>
    <div class="min-h-screen bg-slate-100 text-slate-900 transition-colors dark:bg-slate-950 dark:text-slate-100">
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-slate-900/60 md:hidden"
            @click="sidebarOpen = false"
        />

        <aside
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
            class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-slate-200 bg-gradient-to-b from-white via-slate-50 to-slate-100  transition-transform dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-950"
        >
            <div class=" flex items-start  gap-3 justify-center bg-white/80 dark:bg-slate-900/80">
                <Link :href="panelRoute('dashboard')" class="flex min-w-0 items-center gap-3">
                    <ApplicationLogo sizeClass="w-[240px]" class="shrink-0" />

                </Link>
                <button
                    class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 md:hidden dark:hover:bg-slate-800"
                    @click="sidebarOpen = false"
                >
                    <span class="sr-only">Close sidebar</span>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12M6 18L18 6" />
                    </svg>
                </button>
            </div>

            <nav ref="sidebarNavRef" class="min-h-0 flex-1 space-y-2 overflow-y-auto pr-1" @scroll="updateSidebarScrollTop">
                <template v-for="item in visibleMenu" :key="item.id || item.label">
                    <div v-if="item.children" class="rounded-2xl border border-transparent bg-white/60 p-2 shadow-sm dark:bg-slate-800/35">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between rounded-xl px-2 py-2 text-left transition hover:bg-slate-100 dark:hover:bg-slate-800"
                            @click="toggleGroup(item.id)"
                        >
                            <span class="flex items-center gap-3">
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-200 text-xs font-semibold dark:bg-slate-700">
                                    <i :class="['itc text-base', item.iconClass || 'bi bi-grid']"></i>
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium">{{ item.label }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ item.hint }}</span>
                                </span>
                            </span>
                            <svg class="h-4 w-4 transition-transform" :class="isGroupExpanded(item.id) ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.51a.75.75 0 01-1.08 0l-4.25-4.51a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                            </svg>
                        </button>

                        <div v-show="isGroupExpanded(item.id)" class="mt-2 space-y-1 pl-2">
                            <Link
                                v-for="child in item.children"
                                :key="child.label"
                                :href="panelRoute(child.routeName, child.routeParams ?? {})"
                                preserve-state
                                preserve-scroll
                                :class="route().current(child.routeName) ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-transparent hover:bg-slate-100 dark:hover:bg-slate-800'"
                                :data-sidebar-active="route().current(child.routeName) ? 'true' : null"
                                class="flex items-center gap-3 rounded-xl border px-3 py-2 transition-colors"
                            >
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-slate-200 text-[10px] font-semibold dark:bg-slate-700">
                                    <i :class="['itc text-sm', child.iconClass || 'bi bi-dot']"></i>
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium">{{ child.label }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ child.hint }}</span>
                                </span>
                            </Link>
                        </div>
                    </div>

                    <Link
                        v-else-if="resolveItemRouteName(item)"
                        :href="panelRoute(resolveItemRouteName(item))"
                        preserve-state
                        preserve-scroll
                        :class="route().current(resolveItemRouteName(item)) ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-transparent hover:bg-slate-100 dark:hover:bg-slate-800'"
                        :data-sidebar-active="route().current(resolveItemRouteName(item)) ? 'true' : null"
                        class="flex items-center gap-3 rounded-xl border px-3 py-2 transition-colors"
                    >
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-200 text-xs font-semibold dark:bg-slate-700">
                            <i :class="['itc text-base', item.iconClass || 'bi bi-grid']"></i>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium">{{ item.label }}</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ item.hint }}</span>
                        </span>
                    </Link>

                    <button
                        v-else
                        type="button"
                        class="flex w-full items-center gap-3 rounded-xl border border-transparent px-3 py-2 text-left transition-colors hover:bg-slate-100 dark:hover:bg-slate-800"
                    >
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-200 text-xs font-semibold dark:bg-slate-700">
                            <i :class="['itc text-base', item.iconClass || 'bi bi-grid']"></i>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium">{{ item.label }}</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ item.hint }}</span>
                        </span>
                    </button>
                </template>
            </nav>


        </aside>

        <div class="md:pl-72">
            <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 px-4 py-3 backdrop-blur-xl dark:border-slate-800/80 dark:bg-slate-900/90 sm:px-6">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <button
                            class="rounded-lg p-2 text-slate-600 transition hover:bg-slate-100 md:hidden dark:text-slate-300 dark:hover:bg-slate-800"
                            @click="sidebarOpen = true"
                        >
                            <span class="sr-only">Open sidebar</span>
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>

                        <Link :href="panelRoute('dashboard')" class="flex min-w-0 items-center gap-2 sm:gap-3">
                            <ApplicationLogo sizeClass="w-16 sm:w-20" class="shrink-0" />
                            <div class="min-w-0">
                                <p class="hidden text-xs font-medium uppercase tracking-[0.28em] text-slate-500 dark:text-slate-400 sm:block">
                                    dPanel
                                </p>
                                <p class="hidden text-sm font-semibold leading-tight text-slate-900 dark:text-slate-100 sm:block">
                                    Server Control Center
                                </p>
                            </div>
                        </Link>

                        <div class="min-w-0 border-l border-slate-200 pl-3 dark:border-slate-800">
                            <slot name="header">
                                <h1 class="truncate text-lg font-semibold">Dashboard</h1>
                            </slot>
                        </div>

                        <button
                            type="button"
                            class="hidden min-w-0 items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:bg-slate-50 hover:shadow-md dark:border-slate-700 dark:bg-slate-900/70 dark:hover:border-slate-600 dark:hover:bg-slate-800/80 lg:flex lg:w-[460px]"
                            @click="openSearch"
                        >
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-500 to-cyan-500 text-white shadow-sm">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M8.5 3a5.5 5.5 0 104.19 9.07l3.12 3.12a.75.75 0 101.06-1.06l-3.12-3.12A5.5 5.5 0 008.5 3zm-4 5.5a4 4 0 118 0 4 4 0 01-8 0z" clip-rule="evenodd" />
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-slate-900 dark:text-slate-100">
                                    Search pages, websites, settings
                                </span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    Jump anywhere with keyboard navigation
                                </span>
                            </span>
                            <span class="shrink-0 rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                Ctrl K
                            </span>
                        </button>

                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white p-2 text-slate-500 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-800/80 lg:hidden"
                            aria-label="Open search"
                            @click="openSearch"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.5 3a5.5 5.5 0 104.19 9.07l3.12 3.12a.75.75 0 101.06-1.06l-3.12-3.12A5.5 5.5 0 008.5 3zm-4 5.5a4 4 0 118 0 4 4 0 01-8 0z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-medium shadow-sm transition hover:-translate-y-0.5 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                            @click="toggleTheme"
                        >
                            {{ theme === 'dark' ? 'Day Mode' : 'Night Mode' }}
                        </button>

                        <Link
                            :href="panelRoute('profile.edit')"
                            class="hidden items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium shadow-sm transition hover:-translate-y-0.5 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800 md:inline-flex"
                        >
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-slate-900 to-slate-600 text-xs font-semibold text-white dark:from-slate-700 dark:to-slate-500">
                                {{ userInitials }}
                            </span>
                            <span class="hidden min-w-0 text-left lg:block">
                                <span class="block truncate text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    {{ userName }}
                                </span>
                                <span class="block truncate text-xs text-slate-500 dark:text-slate-400">
                                    {{ userRoleLabel }}
                                </span>
                            </span>
                        </Link>

                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium shadow-sm transition hover:-translate-y-0.5 dark:border-slate-700 dark:bg-slate-900"
                                >
                                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-cyan-500 text-xs font-semibold text-white shadow-sm">
                                        {{ userInitials }}
                                    </span>
                                    <span class="ms-3 hidden min-w-0 text-left sm:block">
                                        <span class="block truncate text-sm font-semibold text-slate-900 dark:text-slate-100">
                                            {{ userName }}
                                        </span>
                                        <span class="block truncate text-xs text-slate-500 dark:text-slate-400">
                                            {{ userEmail || userRoleLabel }}
                                        </span>
                                    </span>
                                    <svg class="ms-2 h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path
                                            fill-rule="evenodd"
                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </button>
                            </template>

                            <template #content>
                                <DropdownLink :href="panelRoute('profile.edit')">
                                    Profile
                                </DropdownLink>
                                <DropdownLink v-if="route().has('security.manager')" :href="panelRoute('security.manager')">
                                    Security
                                </DropdownLink>
                                <DropdownLink :href="panelRoute('logout')" method="post" as="button">
                                    Log Out
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>
                </div>
            </header>

            <main class="px-4 py-6 sm:px-6">
                <slot />
            </main>

            <footer class="border-t border-slate-200 px-4 py-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400 sm:px-6">
                Server Panel v1.0 - Websites, Mail, Apache + Nginx and more
            </footer>
        </div>

        <Modal :show="isSearchOpen" maxWidth="2xl" @close="closeSearch">
            <div class="border-b border-slate-200 px-4 py-4 dark:border-slate-800">
                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-950/40">
                    <svg class="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.5 3a5.5 5.5 0 104.19 9.07l3.12 3.12a.75.75 0 101.06-1.06l-3.12-3.12A5.5 5.5 0 008.5 3zm-4 5.5a4 4 0 118 0 4 4 0 01-8 0z" clip-rule="evenodd" />
                    </svg>

                    <input
                        ref="searchInputRef"
                        v-model="searchQuery"
                        type="text"
                        class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:ring-0 dark:text-slate-100"
                        placeholder="Search pages, websites, and settings"
                    />

                    <div class="hidden shrink-0 items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-400 sm:flex">
                        <span class="rounded-md border border-slate-200 bg-white px-2 py-1 dark:border-slate-700 dark:bg-slate-900">↑</span>
                        <span class="rounded-md border border-slate-200 bg-white px-2 py-1 dark:border-slate-700 dark:bg-slate-900">↓</span>
                        <span class="rounded-md border border-slate-200 bg-white px-2 py-1 dark:border-slate-700 dark:bg-slate-900">Enter</span>
                    </div>
                </div>
            </div>

            <div ref="searchResultsRef" class="max-h-[60vh] overflow-y-auto p-3">
                <template v-if="filteredSearchResults.length">
                    <button
                        v-for="(item, index) in filteredSearchResults"
                        :key="`${item.group}-${item.label}-${item.href}`"
                        type="button"
                        :data-search-result-active="index === activeSearchIndex ? 'true' : null"
                        class="flex w-full items-center gap-3 rounded-2xl border px-4 py-3 text-left transition"
                        :class="index === activeSearchIndex
                            ? 'border-blue-400 bg-blue-50 dark:border-blue-500/70 dark:bg-blue-950/30'
                            : 'border-transparent hover:bg-slate-50 dark:hover:bg-slate-800/60'"
                        @mouseenter="activeSearchIndex = index"
                        @click="openSearchResult(item)"
                    >
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300">
                            <i :class="['itc text-base', item.iconClass || 'bi bi-link-45deg']"></i>
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-slate-900 dark:text-slate-100">
                                {{ item.label }}
                            </span>
                            <span class="block truncate text-xs text-slate-500 dark:text-slate-400">
                                {{ item.hint || item.group || 'Open page' }}
                            </span>
                        </span>

                        <span
                            v-if="item.group"
                            class="shrink-0 rounded-lg border border-slate-200 px-2 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500 dark:border-slate-700 dark:text-slate-300"
                        >
                            {{ item.group }}
                        </span>
                    </button>
                </template>

                <div v-else class="px-4 py-12 text-center text-sm text-slate-500 dark:text-slate-400">
                    No results found.
                </div>
            </div>
        </Modal>
    </div>
</template>
