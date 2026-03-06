<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link, usePage, useRemember } from '@inertiajs/vue3';

const sidebarOpen = useRemember(false, 'layout.sidebar.open');
const sidebarSearch = useRemember('', 'layout.sidebar.search');
const theme = useRemember('light', 'layout.theme');
const expandedGroups = useRemember([], 'layout.sidebar.expanded');
const sidebarScrollTop = useRemember(0, 'layout.sidebar.scrollTop');
const sidebarNavRef = ref(null);
const page = usePage();
const SIDEBAR_SCROLL_KEY = 'layout.sidebar.scrollTop.v1';

const menuItems = [
    { label: 'Dashboard', hint: 'Overview and stats', icon: 'DB', iconClass: 'bi bi-speedometer2', routeName: 'dashboard' },
    {
        id: 'user-management',
        label: 'User Management',
        hint: 'Admin, reseller and user panels',
        icon: 'UM',
        iconClass: 'bi bi-people',
        children: [
            { label: 'Admin', hint: 'Super admin panel', icon: 'SA', iconClass: 'bi bi-person-gear', routeName: 'admin.panel', roles: ['admin'] },
            { label: 'Create Role', hint: 'Add a new role', icon: 'CR', iconClass: 'bi bi-plus-square', routeName: 'roles.create', roles: ['admin'] },
            { label: 'Manage Roles', hint: 'Edit and delete roles', icon: 'MR', iconClass: 'bi bi-shield-check', routeName: 'roles.manage', roles: ['admin'] },
            { label: 'Reseller', hint: 'Reseller panel', icon: 'RS', iconClass: 'bi bi-person-workspace', routeName: 'reseller.panel', roles: ['reseller'] },
            { label: 'Individual User', hint: 'General user panel', icon: 'US', iconClass: 'bi bi-person', routeName: 'user.panel', roles: ['general_user'] },
            { label: 'All Users', hint: 'Create users and assign roles', icon: 'MU', iconClass: 'bi bi-person-plus', routeName: 'users.manage', roles: ['admin', 'reseller'] },
        ],
    },
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
    { label: 'Apache', hint: 'Service and vHost controls', icon: 'AP', iconClass: 'bi bi-hdd-network', routeName: 'apache.index', roles: ['admin', 'reseller'] },
    { label: 'Terminal', hint: 'Run server commands', icon: 'TM', iconClass: 'bi bi-terminal', routeName: 'terminal.index', roles: ['admin', 'reseller'] },
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
    {
        id: 'package-management',
        label: 'Package Management',
        hint: 'Package operations',
        icon: 'PK',
        iconClass: 'bi bi-box-seam',
        children: [
            { label: 'Create Package', hint: 'Create package plan', icon: 'CP', iconClass: 'bi bi-box2-heart', routeName: 'packages.create', roles: ['admin', 'reseller'] },
            { label: 'List Packages', hint: 'View package plans', icon: 'LP', iconClass: 'bi bi-collection', routeName: 'packages.list', roles: ['admin', 'reseller'] },
        ],
    },
    { label: 'Security', hint: 'Firewall, SSH and hardening', icon: 'SC', iconClass: 'bi bi-shield-lock', routeName: 'security.manager', roles: ['admin', 'reseller'] },
    { label: 'Backups', hint: 'Snapshots and restore', icon: 'BK', iconClass: 'bi bi-cloud-arrow-down', dynamicRouteNames: ['backups.index', 'monitoring.index'] },
    { label: 'Monitoring', hint: 'CPU, RAM, disk, logs', icon: 'MN', iconClass: 'bi bi-activity', routeName: 'monitoring.index', roles: ['admin', 'reseller'] },
];

const userRoles = computed(() => page.props.auth?.roles ?? []);
const userRoleLabel = computed(() => userRoles.value.join(', ') || 'No role');
const userPermissions = computed(() => page.props.auth?.permissions ?? []);

const hasAccess = (item) => {
    if (item.permissions?.length) {
        return item.permissions.some((permission) => userPermissions.value.includes(permission));
    }

    if (!item.roles) return true;
    return item.roles.some((role) => userRoles.value.includes(role));
};

const filteredMenu = computed(() => {
    const needle = sidebarSearch.value.trim().toLowerCase();

    return menuItems
        .map((item) => {
            if (!item.children) {
                return hasAccess(item) ? item : null;
            }

            const allowedChildren = item.children.filter((child) => hasAccess(child));
            if (!allowedChildren.length) return null;

            if (!needle) {
                return { ...item, children: allowedChildren };
            }

            const parentText = `${item.label} ${item.hint}`.toLowerCase();
            const matchingChildren = allowedChildren.filter((child) =>
                `${child.label} ${child.hint}`.toLowerCase().includes(needle),
            );

            if (matchingChildren.length || parentText.includes(needle)) {
                return {
                    ...item,
                    children: matchingChildren.length ? matchingChildren : allowedChildren,
                };
            }

            return null;
        })
        .filter(Boolean)
        .filter((item) => {
            if (!needle) return true;
            if (item.children) return true;
            return `${item.label} ${item.hint}`.toLowerCase().includes(needle);
        });
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

    const activeGroup = menuItems.find(
        (item) =>
            item.children &&
            item.id &&
            item.children.some((child) => child.routeName && route().current(child.routeName)),
    );

    if (expandedGroups.value.length === 0) {
        expandedGroups.value = activeGroup?.id ? [activeGroup.id] : [];
    }

    restoreSidebarScrollTop();
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
            class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-slate-200 bg-white p-4 transition-transform dark:border-slate-800 dark:bg-slate-900"
        >
            <div class="mb-4 flex items-center justify-between">
                <Link :href="route('dashboard')" class="flex items-center gap-2">
                    <div class="rounded-lg bg-blue-600 px-2 py-1 text-xs font-bold text-white">SI</div>
                    <div>
                        <p class="text-sm font-semibold">Server Panel</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">System Management</p>
                    </div>
                </Link>
                <button
                    class="rounded-md p-2 text-slate-500 hover:bg-slate-100 md:hidden dark:hover:bg-slate-800"
                    @click="sidebarOpen = false"
                >
                    <span class="sr-only">Close sidebar</span>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12M6 18L18 6" />
                    </svg>
                </button>
            </div>

            <div class="mb-4">
                <label for="sidebar-search" class="mb-2 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    Search
                </label>
                <input
                    id="sidebar-search"
                    v-model="sidebarSearch"
                    type="text"
                    placeholder="Search menu..."
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none ring-blue-500 focus:ring dark:border-slate-700 dark:bg-slate-800"
                />
            </div>

            <nav ref="sidebarNavRef" class="min-h-0 flex-1 space-y-2 overflow-y-auto pr-1" @scroll="updateSidebarScrollTop">
                <template v-for="item in filteredMenu" :key="item.id || item.label">
                    <div v-if="item.children" class="rounded-lg border border-transparent bg-slate-50 p-2 dark:bg-slate-800/40">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between rounded-lg px-2 py-2 text-left hover:bg-slate-100 dark:hover:bg-slate-800"
                            @click="toggleGroup(item.id)"
                        >
                            <span class="flex items-center gap-3">
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-slate-200 text-xs font-semibold dark:bg-slate-700">
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
                                :href="route(child.routeName)"
                                preserve-state
                                preserve-scroll
                                :class="route().current(child.routeName) ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-transparent hover:bg-slate-100 dark:hover:bg-slate-800'"
                                :data-sidebar-active="route().current(child.routeName) ? 'true' : null"
                                class="flex items-center gap-3 rounded-lg border px-3 py-2 transition-colors"
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
                        :href="route(resolveItemRouteName(item))"
                        preserve-state
                        preserve-scroll
                        :class="route().current(resolveItemRouteName(item)) ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-transparent hover:bg-slate-100 dark:hover:bg-slate-800'"
                        :data-sidebar-active="route().current(resolveItemRouteName(item)) ? 'true' : null"
                        class="flex items-center gap-3 rounded-lg border px-3 py-2 transition-colors"
                    >
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-slate-200 text-xs font-semibold dark:bg-slate-700">
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
                        class="flex w-full items-center gap-3 rounded-lg border border-transparent px-3 py-2 text-left transition-colors hover:bg-slate-100 dark:hover:bg-slate-800"
                    >
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-slate-200 text-xs font-semibold dark:bg-slate-700">
                            <i :class="['itc text-base', item.iconClass || 'bi bi-grid']"></i>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium">{{ item.label }}</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ item.hint }}</span>
                        </span>
                    </button>
                </template>
            </nav>

            <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs dark:border-slate-800 dark:bg-slate-800/40">
                <p class="mb-2 font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Your Roles</p>
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="role in userRoles"
                        :key="role"
                        class="rounded bg-slate-200 px-2 py-1 font-medium text-slate-700 dark:bg-slate-700 dark:text-slate-100"
                    >
                        {{ role }}
                    </span>
                    <span v-if="userRoles.length === 0" class="text-slate-500 dark:text-slate-400">No role</span>
                </div>
            </div>
        </aside>

        <div class="md:pl-72">
            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90 sm:px-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button
                            class="rounded-md p-2 text-slate-600 hover:bg-slate-100 md:hidden dark:text-slate-300 dark:hover:bg-slate-800"
                            @click="sidebarOpen = true"
                        >
                            <span class="sr-only">Open sidebar</span>
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>

                        <div>
                            <slot name="header">
                                <h1 class="text-lg font-semibold">Dashboard</h1>
                            </slot>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 px-3 py-2 text-xs font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                            @click="toggleTheme"
                        >
                            {{ theme === 'dark' ? 'Day Mode' : 'Night Mode' }}
                        </button>

                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium dark:border-slate-700 dark:bg-slate-900"
                                >
                                    {{ $page.props.auth.user.name }} ({{ userRoleLabel }})
                                    <svg class="ms-2 h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                        <path
                                            fill-rule="evenodd"
                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </button>
                            </template>

                            <template #content>
                                <DropdownLink :href="route('profile.edit')">
                                    Profile
                                </DropdownLink>
                                <DropdownLink :href="route('logout')" method="post" as="button">
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
                Server Panel v1.0 - Websites, Mail, Apache, Terminal and more
            </footer>
        </div>
    </div>
</template>


