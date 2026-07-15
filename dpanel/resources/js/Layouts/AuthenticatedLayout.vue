<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import Modal from '@/Components/Modal.vue';
import { Link, router, usePage, useRemember } from '@inertiajs/vue3';

const sidebarOpen = useRemember(false, 'layout.sidebar.open');
const sidebarCollapsed = useRemember(false, 'layout.sidebar.collapsed');
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

// Notification state
const notifications = ref([
    { id: 1, title: 'New website created', message: 'example.com has been added successfully', time: '5 min ago', read: false, type: 'success' },
    { id: 2, title: 'SSL Certificate expiring', message: 'domain.com SSL expires in 7 days', time: '1 hour ago', read: false, type: 'warning' },
    { id: 3, title: 'Backup completed', message: 'Daily backup finished successfully', time: '3 hours ago', read: true, type: 'info' },
    { id: 4, title: 'Security alert', message: 'Failed login attempt detected', time: '5 hours ago', read: true, type: 'danger' },
]);
const isNotificationsOpen = ref(false);
const notificationsRef = ref(null);

const unreadNotificationsCount = computed(() => notifications.value.filter(n => !n.read).length);

const markAsRead = (id) => {
    const notification = notifications.value.find(n => n.id === id);
    if (notification) {
        notification.read = true;
    }
};

const markAllAsRead = () => {
    notifications.value.forEach(n => n.read = true);
};

const clearNotifications = () => {
    notifications.value = [];
};

const toggleNotifications = () => {
    isNotificationsOpen.value = !isNotificationsOpen.value;
};

const closeNotifications = (event) => {
    if (notificationsRef.value && !notificationsRef.value.contains(event.target)) {
        isNotificationsOpen.value = false;
    }
};

const getNotificationIcon = (type) => {
    const icons = {
        success: 'bi-check-circle-fill text-emerald-500',
        warning: 'bi-exclamation-triangle-fill text-amber-500',
        info: 'bi-info-circle-fill text-blue-500',
        danger: 'bi-x-circle-fill text-red-500',
    };
    return icons[type] || icons.info;
};

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
    { label: 'Dashboard', hint: 'Overview and stats', icon: 'DB', iconClass: 'bi bi-speedometer2', routeName: 'dashboard', color: 'blue' },
    {
        id: 'web-management',
        label: 'Web Management',
        hint: 'Website operations',
        icon: 'WM',
        iconClass: 'bi bi-globe2',
        color: 'emerald',
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
        color: 'violet',
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
        color: 'amber',
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
        color: 'cyan',
        children: [
            { label: 'Nameservers', hint: 'Manage NS records', icon: 'NS', iconClass: 'bi bi-signpost-split', routeName: 'dns.nameservers', roles: ['admin', 'reseller'] },
            { label: 'DNS Zones', hint: 'Manage DNS zones', icon: 'DZ', iconClass: 'bi bi-bounding-box-circles', routeName: 'dns.zones', roles: ['admin', 'reseller'] },
            { label: 'DNS Records', hint: 'A, CNAME, MX, TXT records', icon: 'DR', iconClass: 'bi bi-journal-code', routeName: 'dns.records', roles: ['admin', 'reseller'] },
        ],
    },
    { label: 'PHP Management', hint: 'Versions, extensions and config', icon: 'PH', iconClass: 'bi bi-braces', routeName: 'php.manager', roles: ['admin', 'reseller'], color: 'indigo' },
    { label: 'Apache + Nginx Setup', hint: 'Web server stack and vHost controls', icon: 'AP', iconClass: 'bi bi-hdd-network', routeName: 'apache.index', roles: ['admin', 'reseller'], color: 'rose' },
    { label: 'Security', hint: 'Firewall, SSH and hardening', icon: 'SC', iconClass: 'bi bi-shield-lock', routeName: 'security.manager', roles: ['admin', 'reseller'], color: 'red' },
    { label: 'Backups', hint: 'Snapshots and restore', icon: 'BK', iconClass: 'bi bi-cloud-arrow-down', dynamicRouteNames: ['backups.index', 'monitoring.index'], color: 'teal' },
    { label: 'Monitoring', hint: 'CPU, RAM, disk, logs', icon: 'MN', iconClass: 'bi bi-activity', routeName: 'monitoring.index', roles: ['admin', 'reseller'], color: 'orange' },
    {
        id: 'user-management',
        label: 'User Management',
        hint: 'Admin, reseller and user panels',
        icon: 'UM',
        iconClass: 'bi bi-people',
        color: 'pink',
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

const colorClasses = {
    blue: {
        bg: 'bg-blue-100 dark:bg-blue-900/40',
        text: 'text-blue-600 dark:text-blue-400',
        border: 'border-blue-500',
        bgLight: 'bg-blue-50 dark:bg-blue-900/20',
    },
    emerald: {
        bg: 'bg-emerald-100 dark:bg-emerald-900/40',
        text: 'text-emerald-600 dark:text-emerald-400',
        border: 'border-emerald-500',
        bgLight: 'bg-emerald-50 dark:bg-emerald-900/20',
    },
    violet: {
        bg: 'bg-violet-100 dark:bg-violet-900/40',
        text: 'text-violet-600 dark:text-violet-400',
        border: 'border-violet-500',
        bgLight: 'bg-violet-50 dark:bg-violet-900/20',
    },
    amber: {
        bg: 'bg-amber-100 dark:bg-amber-900/40',
        text: 'text-amber-600 dark:text-amber-400',
        border: 'border-amber-500',
        bgLight: 'bg-amber-50 dark:bg-amber-900/20',
    },
    cyan: {
        bg: 'bg-cyan-100 dark:bg-cyan-900/40',
        text: 'text-cyan-600 dark:text-cyan-400',
        border: 'border-cyan-500',
        bgLight: 'bg-cyan-50 dark:bg-cyan-900/20',
    },
    indigo: {
        bg: 'bg-indigo-100 dark:bg-indigo-900/40',
        text: 'text-indigo-600 dark:text-indigo-400',
        border: 'border-indigo-500',
        bgLight: 'bg-indigo-50 dark:bg-indigo-900/20',
    },
    rose: {
        bg: 'bg-rose-100 dark:bg-rose-900/40',
        text: 'text-rose-600 dark:text-rose-400',
        border: 'border-rose-500',
        bgLight: 'bg-rose-50 dark:bg-rose-900/20',
    },
    red: {
        bg: 'bg-red-100 dark:bg-red-900/40',
        text: 'text-red-600 dark:text-red-400',
        border: 'border-red-500',
        bgLight: 'bg-red-50 dark:bg-red-900/20',
    },
    teal: {
        bg: 'bg-teal-100 dark:bg-teal-900/40',
        text: 'text-teal-600 dark:text-teal-400',
        border: 'border-teal-500',
        bgLight: 'bg-teal-50 dark:bg-teal-900/20',
    },
    orange: {
        bg: 'bg-orange-100 dark:bg-orange-900/40',
        text: 'text-orange-600 dark:text-orange-400',
        border: 'border-orange-500',
        bgLight: 'bg-orange-50 dark:bg-orange-900/20',
    },
    pink: {
        bg: 'bg-pink-100 dark:bg-pink-900/40',
        text: 'text-pink-600 dark:text-pink-400',
        border: 'border-pink-500',
        bgLight: 'bg-pink-50 dark:bg-pink-900/20',
    },
};

const getColor = (item) => colorClasses[item.color] || colorClasses.blue;

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

const toggleSidebar = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value;
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
    document.addEventListener('click', closeNotifications);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleSearchKeydown);
    document.removeEventListener('click', closeNotifications);
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
            class="fixed inset-0 z-40 bg-slate-900/60 backdrop-blur-sm md:hidden"
            @click="sidebarOpen = false"
        />

        <aside
            :class="[
                sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0',
                sidebarCollapsed ? 'md:w-20' : 'md:w-72'
            ]"
            class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-slate-200/80 bg-white transition-all duration-300 dark:border-slate-700/80 dark:bg-slate-900"
        >
            <!-- Logo -->
            <div :class="sidebarCollapsed ? 'justify-center' : 'justify-between'" class="flex h-16 items-center border-b border-slate-200/80 px-4 dark:border-slate-700/80">
                <Link :href="panelRoute('dashboard')" class="flex items-center gap-2">
                    <ApplicationLogo v-if="!sidebarCollapsed" sizeClass="w-[180px]" src="/dpanel_logo.png" class="shrink-0" />
                    <ApplicationLogo v-else sizeClass="w-[40px]" src="/sm_logo.png" class="shrink-0" />
                </Link>
                <button
                    class="rounded-lg p-2 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 md:hidden dark:hover:bg-slate-800 dark:hover:text-slate-300"
                    @click="sidebarOpen = false"
                >
                    <span class="sr-only">Close sidebar</span>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12M6 18L18 6" />
                    </svg>
                </button>
            </div>

            <!-- Navigation -->
            <nav ref="sidebarNavRef" :class="sidebarCollapsed ? 'px-2' : 'px-3'" class="mt-4 min-h-0 flex-1 space-y-1 overflow-y-auto pb-4 scrollbar-thin scrollbar-thumb-slate-200 dark:scrollbar-thumb-slate-700" @scroll="updateSidebarScrollTop">
                <template v-for="item in visibleMenu" :key="item.id || item.label">
                    <!-- Grouped Items -->
                    <div v-if="item.children" class="mb-2">
                        <button
                            type="button"
                            :class="[
                                sidebarCollapsed ? 'justify-center px-2' : 'px-3 justify-between',
                                'flex w-full items-center rounded-xl py-2.5 text-left transition-all duration-200 hover:bg-slate-100 dark:hover:bg-slate-800'
                            ]"
                            @click="toggleGroup(item.id)"
                            :title="sidebarCollapsed ? item.label : ''"
                        >
                            <span :class="sidebarCollapsed ? '' : 'flex items-center gap-3'" class="flex items-center gap-3">
                                <span
                                    :class="[
                                        'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl transition-all duration-200',
                                        getColor(item).bg,
                                        getColor(item).text
                                    ]"
                                >
                                    <i :class="['text-lg', item.iconClass || 'bi bi-grid']"></i>
                                </span>
                                <span v-if="!sidebarCollapsed" class="min-w-0">
                                    <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ item.label }}</span>
                                </span>
                            </span>
                            <svg
                                v-if="!sidebarCollapsed"
                                class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200"
                                :class="isGroupExpanded(item.id) ? 'rotate-180' : ''"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                            >
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.51a.75.75 0 01-1.08 0l-4.25-4.51a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                            </svg>
                        </button>

                        <div
                            v-show="isGroupExpanded(item.id) && !sidebarCollapsed"
                            class="mt-1 space-y-1 pl-5"
                        >
                            <Link
                                v-for="child in item.children"
                                :key="child.label"
                                :href="panelRoute(child.routeName, child.routeParams ?? {})"
                                preserve-state
                                preserve-scroll
                                :class="[
                                    route().current(child.routeName)
                                        ? 'border-l-2 ' + getColor(item).border + ' ' + getColor(item).bgLight + ' ' + getColor(item).text
                                        : 'border-l-2 border-transparent text-slate-600 dark:text-slate-400',
                                    'flex items-center gap-3 rounded-lg px-3 py-2 transition-all duration-200 hover:bg-slate-100 dark:hover:bg-slate-800'
                                ]"
                                :data-sidebar-active="route().current(child.routeName) ? 'true' : null"
                            >
                                <i :class="['text-sm', child.iconClass || 'bi bi-dot']"></i>
                                <span class="text-sm">{{ child.label }}</span>
                            </Link>
                        </div>
                    </div>

                    <!-- Single Items -->
                    <Link
                        v-else-if="resolveItemRouteName(item)"
                        :href="panelRoute(resolveItemRouteName(item))"
                        preserve-state
                        preserve-scroll
                        :class="[
                            route().current(resolveItemRouteName(item))
                                ? 'border-l-2 ' + getColor(item).border + ' ' + getColor(item).bgLight + ' ' + getColor(item).text
                                : 'border-l-2 border-transparent text-slate-600 dark:text-slate-400',
                            sidebarCollapsed ? 'justify-center px-2' : 'px-3',
                            'flex items-center gap-3 rounded-xl py-2.5 transition-all duration-200 hover:bg-slate-100 dark:hover:bg-slate-800'
                        ]"
                        :data-sidebar-active="route().current(resolveItemRouteName(item)) ? 'true' : null"
                        :title="sidebarCollapsed ? item.label : ''"
                    >
                        <span
                            :class="[
                                'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl transition-all duration-200',
                                route().current(resolveItemRouteName(item))
                                    ? getColor(item).bg + ' ' + getColor(item).text
                                    : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'
                            ]"
                        >
                            <i :class="['text-lg', item.iconClass || 'bi bi-grid']"></i>
                        </span>
                        <span v-if="!sidebarCollapsed" class="text-sm font-medium">{{ item.label }}</span>
                    </Link>

                    <!-- Button Items (no route) -->
                    <button
                        v-else
                        type="button"
                        :class="[
                            sidebarCollapsed ? 'justify-center px-2' : 'px-3',
                            'flex w-full items-center gap-3 rounded-xl border-l-2 border-transparent py-2.5 text-left text-slate-600 transition-all duration-200 hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-300'
                        ]"
                        :title="sidebarCollapsed ? item.label : ''"
                    >
                        <span
                            :class="[
                                'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500 transition-all duration-200 dark:bg-slate-800 dark:text-slate-400'
                            ]"
                        >
                            <i :class="['text-lg', item.iconClass || 'bi bi-grid']"></i>
                        </span>
                        <span v-if="!sidebarCollapsed" class="text-sm font-medium">{{ item.label }}</span>
                    </button>
                </template>
            </nav>

            <!-- Footer -->
            <div class="border-t border-slate-200/80 px-4 py-3 dark:border-slate-700/80">
                <div :class="sidebarCollapsed ? 'justify-center' : 'justify-between'" class="flex items-center">
                    <span v-if="!sidebarCollapsed" class="text-xs text-slate-400 dark:text-slate-500">dPanel v1.0</span>
                    <button
                        @click="toggleTheme"
                        class="rounded-lg p-2 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-300"
                        :aria-label="theme === 'dark' ? 'Switch to day mode' : 'Switch to night mode'"
                        :title="theme === 'dark' ? 'Switch to day mode' : 'Switch to night mode'"
                    >
                        <i :class="['text-base', theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill']"></i>
                    </button>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <div :class="sidebarCollapsed ? 'md:pl-20' : 'md:pl-72'" class="transition-all duration-300">
            <header class="sticky top-0 z-30 flex h-16 items-center border-b border-slate-200/80 bg-white/90 px-4 backdrop-blur-xl dark:border-slate-800/80 dark:bg-slate-900/90 sm:px-6">
                <div class="flex w-full items-center justify-between gap-4">
                    <!-- Left: Mobile Menu Button -->
                    <div class="flex items-center gap-1.5">
                        <!-- Mobile Menu Button -->
                        <button
                            class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-600 transition-colors hover:bg-slate-100 md:hidden dark:text-slate-300 dark:hover:bg-slate-800"
                            @click="sidebarOpen = true"
                        >
                            <span class="sr-only">Open sidebar</span>
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>

                        <!-- Sidebar Toggle (Desktop) -->
                        <button
                            type="button"
                            class="hidden h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition-all hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-800/80 md:inline-flex"
                            :aria-label="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                            :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                            @click="toggleSidebar"
                        >
                            <i :class="['bi text-xs', sidebarCollapsed ? 'bi-text-indent-left' : 'bi-text-indent-right']"></i>
                        </button>
                    </div>

                    <!-- Center: Search Bar -->
                    <div class="hidden max-w-xl flex-1 md:block">
                        <button
                            @click="openSearch"
                            class="flex h-9 w-full items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 text-xs text-slate-500 transition-all hover:border-slate-300 hover:bg-slate-100 hover:text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400 dark:hover:border-slate-600 dark:hover:bg-slate-700 dark:hover:text-slate-300"
                        >
                            <i class="bi bi-search text-xs"></i>
                            <span>Search websites, emails...</span>
                            <kbd class="ml-auto rounded border border-slate-300 bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-400 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-500">⌘K</kbd>
                        </button>
                    </div>

                    <!-- Right: Actions -->
                    <div class="flex items-center gap-1.5">
                        <!-- Mobile Search Button -->
                        <button
                            type="button"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition-all hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-800/80 md:hidden"
                            aria-label="Open search"
                            title="Open search"
                            @click="openSearch"
                        >
                            <i class="bi bi-search text-xs"></i>
                        </button>

                        <!-- Notification Bell -->
                        <div class="relative" ref="notificationsRef">
                            <button
                                type="button"
                                class="relative inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition-all hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-800/80"
                                aria-label="Notifications"
                                title="Notifications"
                                @click.stop="toggleNotifications"
                            >
                                <i class="bi bi-bell text-xs"></i>
                                <span
                                    v-if="unreadNotificationsCount > 0"
                                    class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white shadow-sm ring-2 ring-white dark:ring-slate-900"
                                >
                                    {{ unreadNotificationsCount > 9 ? '9+' : unreadNotificationsCount }}
                                </span>
                            </button>

                            <!-- Notifications Dropdown -->
                            <Transition
                                enter-active-class="transition ease-out duration-200"
                                enter-from-class="opacity-0 scale-95"
                                enter-to-class="opacity-100 scale-100"
                                leave-active-class="transition ease-in duration-150"
                                leave-from-class="opacity-100 scale-100"
                                leave-to-class="opacity-0 scale-95"
                            >
                                <div
                                    v-if="isNotificationsOpen"
                                    class="absolute right-0 top-full z-50 mt-2 w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"
                                >
                                    <!-- Header -->
                                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                                        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Notifications</h3>
                                        <div class="flex items-center gap-2">
                                            <button
                                                v-if="unreadNotificationsCount > 0"
                                                @click="markAllAsRead"
                                                class="text-xs text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                                            >
                                                Mark all read
                                            </button>
                                            <button
                                                v-if="notifications.length > 0"
                                                @click="clearNotifications"
                                                class="text-xs text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300"
                                            >
                                                Clear all
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Notifications List -->
                                    <div class="max-h-80 overflow-y-auto">
                                        <template v-if="notifications.length > 0">
                                            <div
                                                v-for="notification in notifications"
                                                :key="notification.id"
                                                @click="markAsRead(notification.id)"
                                                class="flex cursor-pointer items-start gap-3 border-b border-slate-100 px-4 py-3 transition-colors hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50"
                                                :class="{ 'bg-blue-50/50 dark:bg-blue-900/10': !notification.read }"
                                            >
                                                <span class="mt-0.5">
                                                    <i :class="['bi text-lg', getNotificationIcon(notification.type)]"></i>
                                                </span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-sm font-medium text-slate-900 dark:text-slate-100">
                                                        {{ notification.title }}
                                                    </p>
                                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                                        {{ notification.message }}
                                                    </p>
                                                    <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">
                                                        {{ notification.time }}
                                                    </p>
                                                </div>
                                                <span
                                                    v-if="!notification.read"
                                                    class="mt-2 h-2 w-2 shrink-0 rounded-full bg-blue-500"
                                                ></span>
                                            </div>
                                        </template>
                                        <div v-else class="px-4 py-8 text-center">
                                            <i class="bi bi-bell-slash text-3xl text-slate-300 dark:text-slate-600"></i>
                                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No notifications</p>
                                        </div>
                                    </div>

                                    <!-- Footer -->
                                    <div class="border-t border-slate-200 px-4 py-2 dark:border-slate-700">
                                        <button
                                            class="w-full text-center text-xs font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                                        >
                                            View all notifications
                                        </button>
                                    </div>
                                </div>
                            </Transition>
                        </div>

                        <!-- Quick Actions -->
                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition-all hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-800/80"
                                    aria-label="Quick actions"
                                    title="Quick actions"
                                >
                                    <i class="bi bi-plus-lg text-xs"></i>
                                </button>
                            </template>
                            <template #content>
                                <DropdownLink :href="panelRoute('websites.create')">
                                    <i class="bi bi-globe mr-2"></i>New Website
                                </DropdownLink>
                                <DropdownLink :href="panelRoute('emails.create')">
                                    <i class="bi bi-envelope mr-2"></i>New Email
                                </DropdownLink>
                                <DropdownLink :href="panelRoute('databases.create')">
                                    <i class="bi bi-database mr-2"></i>New Database
                                </DropdownLink>
                                <div class="border-t border-slate-200 dark:border-slate-700"></div>
                                <DropdownLink :href="panelRoute('users.manage')">
                                    <i class="bi bi-person-plus mr-2"></i>New User
                                </DropdownLink>
                            </template>
                        </Dropdown>

                        <!-- User Dropdown -->
                        <Dropdown align="right" width="64">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm font-medium text-slate-700 transition-all hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-slate-600 dark:hover:bg-slate-800"
                                >
                                    <span class="relative inline-flex h-7 w-7 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-indigo-600 text-[10px] font-semibold text-white shadow-sm ring-2 ring-white dark:ring-slate-900">
                                        {{ userInitials }}
                                        <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-400 dark:border-slate-900"></span>
                                    </span>
                                    <span class="hidden text-left sm:block">
                                        <span class="block text-xs font-semibold text-slate-900 dark:text-slate-100">{{ userName }}</span>
                                    </span>
                                    <i class="bi bi-chevron-down text-[10px] text-slate-400"></i>
                                </button>
                            </template>

                            <template #content>
                                <!-- Profile Header -->
                                <div class="border-b border-slate-200 px-4 py-4 dark:border-slate-700">
                                    <div class="flex items-center gap-3">
                                        <span class="relative inline-flex h-12 w-12 items-center justify-center rounded-full bg-gradient-to-br from-blue-600 to-indigo-600 text-sm font-bold text-white shadow-lg">
                                            {{ userInitials }}
                                            <span class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-2 border-white bg-emerald-400 dark:border-slate-900"></span>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ userName }}</p>
                                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ userEmail }}</p>
                                            <span class="mt-1.5 inline-flex items-center rounded-md bg-blue-100 px-2 py-0.5 text-[10px] font-medium text-blue-700 dark:bg-blue-900/50 dark:text-blue-300">
                                                {{ userRoleLabel }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Menu Items -->
                                <div class="py-2">
                                    <DropdownLink :href="panelRoute('profile.edit')">
                                        <i class="bi bi-person mr-3 text-slate-400"></i>
                                        <span>My Profile</span>
                                    </DropdownLink>
                                    <DropdownLink :href="panelRoute('profile.edit')">
                                        <i class="bi bi-gear mr-3 text-slate-400"></i>
                                        <span>Settings</span>
                                    </DropdownLink>
                                    <DropdownLink v-if="route().has('security.manager')" :href="panelRoute('security.manager')">
                                        <i class="bi bi-shield-lock mr-3 text-slate-400"></i>
                                        <span>Security</span>
                                    </DropdownLink>
                                </div>

                                <!-- Divider -->
                                <div class="border-t border-slate-200 dark:border-slate-700"></div>

                                <!-- Logout -->
                                <div class="py-2">
                                    <DropdownLink :href="panelRoute('logout')" method="post" as="button" class="text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">
                                        <i class="bi bi-box-arrow-right mr-3"></i>
                                        <span>Log Out</span>
                                    </DropdownLink>
                                </div>
                            </template>
                        </Dropdown>
                    </div>
                </div>
            </header>

            <main class="px-4 py-6 sm:px-6">
                <!-- Page Header with Breadcrumb -->
                <div class="mb-6">
                    <slot name="header">
                        <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                            <Link :href="panelRoute('dashboard')" class="hover:text-slate-700 dark:hover:text-slate-300">
                                <i class="bi bi-house-door"></i>
                            </Link>
                            <i class="bi bi-chevron-right text-xs"></i>
                            <span class="font-medium text-slate-900 dark:text-slate-100">Dashboard</span>
                        </div>
                        <h1 class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">Dashboard</h1>
                    </slot>
                </div>

                <slot />
            </main>

            <footer class="border-t border-slate-200 px-4 py-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400 sm:px-6">
                Server Panel v1.0 - Websites, Mail, Apache + Nginx and more
            </footer>
        </div>

        <!-- Search Modal -->
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
                        class="flex w-full items-center gap-3 rounded-2xl border px-4 py-3 text-left transition-all"
                        :class="index === activeSearchIndex
                            ? 'border-blue-400 bg-blue-50 dark:border-blue-500/70 dark:bg-blue-950/30'
                            : 'border-transparent hover:bg-slate-50 dark:hover:bg-slate-800/60'"
                        @mouseenter="activeSearchIndex = index"
                        @click="openSearchResult(item)"
                    >
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300">
                            <i :class="['text-base', item.iconClass || 'bi bi-link-45deg']"></i>
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

<style>
/* Custom scrollbar styling */
.scrollbar-thin::-webkit-scrollbar {
    width: 6px;
}

.scrollbar-thin::-webkit-scrollbar-track {
    background: transparent;
}

.scrollbar-thin::-webkit-scrollbar-thumb {
    background-color: rgba(156, 163, 175, 0.3);
    border-radius: 3px;
}

.scrollbar-thin::-webkit-scrollbar-thumb:hover {
    background-color: rgba(156, 163, 175, 0.5);
}

.dark .scrollbar-thin::-webkit-scrollbar-thumb {
    background-color: rgba(75, 85, 99, 0.3);
}

.dark .scrollbar-thin::-webkit-scrollbar-thumb:hover {
    background-color: rgba(75, 85, 99, 0.5);
}
</style>
