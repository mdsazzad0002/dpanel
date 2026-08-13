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
const searchResults = ref([]);
const searchLoading = ref(false);
const searchLoaded = ref(false);
const sidebarNavRef = ref(null);
const page = usePage();
const SIDEBAR_SCROLL_KEY = 'layout.sidebar.scrollTop.v1';
let searchDebounceTimer = null;
let searchRequestSeq = 0;

// AI-assisted search — a mode switch inside the same command palette rather
// than a separate surface, so keyword search stays instant/cheap (no LLM call
// per keystroke) and the AI Gateway is only hit on an explicit "Ask AI".
// aiMessages accumulates for as long as the palette stays open, so follow-up
// questions continue the same conversation (multi-turn), not a fresh one-shot.
const aiMode = ref(false);
const aiMessages = ref([]);
const aiInput = ref('');
const aiStreaming = ref(false);
const aiError = ref('');
const aiChatRef = ref(null);
const aiInputRef = ref(null);
let aiAbortController = null;

// Notification state — backed by NotificationController (app/Http/Controllers/NotificationController.php),
// polled periodically so background work (quick export, etc.) shows up without a manual refresh.
const notifications = ref([]);
const unreadNotificationsCount = ref(0);
const isNotificationsOpen = ref(false);
const notificationsRef = ref(null);
let notificationsPollTimer = null;

const fetchNotifications = async () => {
    try {
        const { data } = await window.axios.get(panelRoute('notifications.index'));
        notifications.value = data.notifications ?? [];
        unreadNotificationsCount.value = data.unread_count ?? 0;
    } catch {
        // Silent — the bell just won't update this cycle; next poll retries.
    }
};

const startNotificationsPolling = () => {
    fetchNotifications();
    notificationsPollTimer = window.setInterval(fetchNotifications, 30000);
};

const stopNotificationsPolling = () => {
    window.clearInterval(notificationsPollTimer);
};

const markAsRead = async (id) => {
    const notification = notifications.value.find(n => n.id === id);
    if (!notification || notification.read) return;
    notification.read = true;
    unreadNotificationsCount.value = Math.max(0, unreadNotificationsCount.value - 1);
    try {
        await window.axios.post(panelRoute('notifications.read', { id }));
    } catch {
        // Best-effort — next poll reconciles state if this failed.
    }
};

const markAllAsRead = async () => {
    notifications.value.forEach(n => n.read = true);
    unreadNotificationsCount.value = 0;
    try {
        await window.axios.post(panelRoute('notifications.read-all'));
    } catch {
        // Best-effort — next poll reconciles state if this failed.
    }
};

const clearNotifications = async () => {
    notifications.value = [];
    unreadNotificationsCount.value = 0;
    try {
        await window.axios.delete(panelRoute('notifications.clear'));
    } catch {
        // Best-effort — next poll reconciles state if this failed.
    }
};

const toggleNotifications = () => {
    isNotificationsOpen.value = !isNotificationsOpen.value;
    if (isNotificationsOpen.value) fetchNotifications();
};

const closeNotifications = (event) => {
    if (notificationsRef.value && !notificationsRef.value.contains(event.target)) {
        isNotificationsOpen.value = false;
    }
};

const getNotificationIcon = (notification) => {
    if (notification.status === 'completed') return 'bi-check-circle-fill text-emerald-500';
    if (notification.status === 'blocked' || notification.status === 'failed') return 'bi-x-circle-fill text-red-500';
    if (notification.status === 'processing') return 'bi-arrow-repeat text-amber-500';
    return 'bi-info-circle-fill text-blue-500';
};

const openNotification = (notification) => {
    markAsRead(notification.id);
    isNotificationsOpen.value = false;
    router.visit(panelRoute('notifications.show', { id: notification.id }));
};

const downloadNotification = (notification, event) => {
    event.stopPropagation();
    const url = notification.data?.download_url;
    if (!url) return;
    const link = document.createElement('a');
    link.href = url;
    document.body.appendChild(link);
    link.click();
    link.remove();
};

const copyNotificationLink = async (notification, event) => {
    event.stopPropagation();
    const url = notification.data?.download_url;
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
    } catch {
        // Clipboard permissions can be denied silently — nothing actionable to show here in the dropdown.
    }
};

const rolePanelConfig = {
    admin: { label: 'Admin', hint: 'Super admin panel', icon: 'SA', iconClass: 'bi bi-person-gear', routeName: 'admin.panel', routeParams: { role: 'admin' }, roles: ['admin'] },
    reseller: { label: 'Reseller', hint: 'Reseller panel', icon: 'RS', iconClass: 'bi bi-person-workspace', routeName: 'reseller.panel', routeParams: { role: 'reseller' }, roles: ['admin', 'reseller'] },
    general: { label: 'General User', hint: 'General user panel', icon: 'US', iconClass: 'bi bi-person', routeName: 'user.panel', routeParams: { role: 'general' }, roles: ['admin', 'reseller'] },
    general_user: { label: 'General User', hint: 'General user panel', icon: 'US', iconClass: 'bi bi-person', routeName: 'user.panel', routeParams: { role: 'general' }, roles: ['admin', 'reseller'] },
};

const userRoles = computed(() => page.props.auth?.roles ?? []);
// AI Gateway chat is admin|reseller only (see routes/web.php ai-gateway prefix
// group) — the "Ask AI" affordance in the search panel mirrors that gate.
const canUseAiSearch = computed(() => userRoles.value.includes('admin') || userRoles.value.includes('reseller'));
const userRoleLabel = computed(() => userRoles.value.join(', ') || 'No role');
const userPermissions = computed(() => page.props.auth?.permissions ?? []);
const panelToken = computed(() => page.props.panel?.token ?? '');
const appName = computed(() => page.props.app?.name ?? 'dPanel');
const appVersion = computed(() => page.props.app?.version ?? '1.0');
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
            { label: 'Manage Roles', hint: 'Edit existing roles', icon: 'MR', iconClass: 'bi bi-shield-check', routeName: 'roles.manage', activeRouteNames: ['roles.manage', 'roles.manage.edit', 'roles.create'], roles: ['admin'] },
        ]
        : [];

    return [
        ...roleChildren,
        { label: 'All Users', hint: 'Shared users panel', icon: 'MU', iconClass: 'bi bi-person-plus', routeName: 'users.manage', roles: ['admin', 'reseller'] },
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
            { label: 'Create Website', hint: 'Add a new website', icon: 'CW', iconClass: 'bi bi-plus-square', routeName: 'websites.create', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: 'List Websites', hint: 'View all websites', icon: 'LW', iconClass: 'bi bi-list-ul', routeName: 'websites.list', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
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
            { label: 'Create Email', hint: 'Add a mailbox', icon: 'CE', iconClass: 'bi bi-envelope-plus', routeName: 'emails.create', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
            { label: 'List Emails', hint: 'View all mailboxes', icon: 'LE', iconClass: 'bi bi-envelope-open', routeName: 'emails.list', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
        ],
    },
    { label: 'Resource Packages', hint: 'Manage user quotas', icon: 'PK', iconClass: 'bi bi-box-seam', routeName: 'packages.index', roles: ['admin', 'superadmin', 'reseller'], permissions: ['manage_packages'] },
    {
        id: 'database-management',
        label: 'Database Management',
        hint: 'Database operations and phpMyAdmin',
        icon: 'DM',
        iconClass: 'bi bi-database',
        color: 'amber',
        children: [
            { label: 'Create Database', hint: 'Create a new database', icon: 'CD', iconClass: 'bi bi-database-add', routeName: 'databases.create', roles: ['admin', 'reseller'], permissions: ['manage_databases'] },
            { label: 'List Databases', hint: 'View all databases', icon: 'LD', iconClass: 'bi bi-table', routeName: 'databases.list', roles: ['admin', 'reseller'], permissions: ['manage_databases'] },
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
            { label: 'Nameservers', hint: 'Manage NS records', icon: 'NS', iconClass: 'bi bi-signpost-split', routeName: 'dns.nameservers', roles: ['admin', 'reseller'], permissions: ['manage_dns'] },
            { label: 'DNS Zones', hint: 'Manage DNS zones', icon: 'DZ', iconClass: 'bi bi-bounding-box-circles', routeName: 'dns.zones', roles: ['admin', 'reseller', 'general', 'general_user'], permissions: ['manage_dns'] },
        ],
    },
    { label: 'PHP Management', hint: 'Versions, extensions and config', icon: 'PH', iconClass: 'bi bi-braces', routeName: 'php.manager', roles: ['admin', 'reseller'], permissions: ['manage_php'], color: 'indigo' },
    { label: 'Security', hint: 'Firewall, SSH and hardening', icon: 'SC', iconClass: 'bi bi-shield-lock', routeName: 'security.manager', roles: ['admin', 'reseller'], permissions: ['manage_security'], color: 'red' },
    { label: 'Backups', hint: 'Snapshots and restore', icon: 'BK', iconClass: 'bi bi-cloud-arrow-down', dynamicRouteNames: ['backups.index', 'monitoring.index'], permissions: ['manage_backups'], color: 'teal' },
    { label: 'Migrate', hint: 'Import cPanel accounts', icon: 'MG', iconClass: 'bi bi-box-arrow-in-down', routeName: 'migrations.index', roles: ['admin', 'reseller'], permissions: ['manage_migrations'], color: 'cyan' },
    { label: 'Trash Backup', hint: 'Deleted website archives', icon: 'TB', iconClass: 'bi bi-trash3', routeName: 'trash-backups.index', roles: ['admin', 'reseller', 'general', 'general_user'], permissions: ['manage_backups'], color: 'orange' },
    { label: 'Monitoring', hint: 'CPU, RAM, disk, logs', icon: 'MN', iconClass: 'bi bi-activity', routeName: 'monitoring.index', roles: ['admin', 'reseller'], permissions: ['view_monitoring'], color: 'orange' },
    {
        id: 'user-management',
        label: 'User Management',
        hint: 'Admin, reseller and user panels',
        icon: 'UM',
        iconClass: 'bi bi-people',
        color: 'pink',
        children: dynamicUserManagementChildren.value,
    },
    {
        id: 'ai-gateway',
        label: 'AI Gateway',
        hint: 'Providers, models and routing',
        icon: 'AI',
        iconClass: 'bi bi-robot',
        color: 'indigo',
        children: [
            { label: 'Dashboard', hint: 'Gateway overview', icon: 'GD', iconClass: 'bi bi-speedometer2', routeName: 'ai-gateway.dashboard', activeRouteNames: ['ai-gateway.dashboard'], roles: ['admin', 'reseller'] },
            { label: 'Chat', hint: 'Test providers live', icon: 'CH', iconClass: 'bi bi-chat-dots', routeName: 'ai-gateway.chat', activeRouteNames: ['ai-gateway.chat'], roles: ['admin', 'reseller'] },
            { label: 'Providers', hint: 'Claude, Codex, OpenAI, Gemini, Local', icon: 'PV', iconClass: 'bi bi-hdd-network', routeName: 'ai-gateway.providers.index', activeRouteNames: ['ai-gateway.providers.index', 'ai-gateway.providers.create', 'ai-gateway.providers.edit'], roles: ['admin', 'reseller'] },
            { label: 'Models', hint: 'Model catalog and pricing', icon: 'MD', iconClass: 'bi bi-cpu', routeName: 'ai-gateway.models.index', activeRouteNames: ['ai-gateway.models.index'], roles: ['admin', 'reseller'] },
            { label: 'API Keys', hint: 'External OpenAI/OpenRouter-compatible access', icon: 'AK', iconClass: 'bi bi-key', routeName: 'ai-gateway.api-keys.index', activeRouteNames: ['ai-gateway.api-keys.index'], roles: ['admin', 'reseller'] },
            { label: 'API Docs', hint: 'How to call the gateway from outside', icon: 'DC', iconClass: 'bi bi-file-earmark-code', routeName: 'ai-gateway.docs', activeRouteNames: ['ai-gateway.docs'], roles: ['admin', 'reseller'] },
        ],
    },
]);

const hasAccess = (item) => {
    if (item.permissions?.length || item.roles?.length) {
        const hasPermission = item.permissions?.some((permission) => userPermissions.value.includes(permission)) ?? false;
        const hasRole = item.roles?.some((role) => userRoles.value.includes(role)) ?? false;
        return hasPermission || hasRole;
    }

    return true;
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

const filteredSearchResults = computed(() => {
    const source = searchLoaded.value ? searchResults.value : panelSearchItems.value;
    return (Array.isArray(source) ? source : [])
        .filter((item) => item && typeof item.href === 'string' && item.href.length > 0)
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

const isItemActive = (item) => {
    const routeNames = item?.activeRouteNames?.length
        ? item.activeRouteNames
        : [resolveItemRouteName(item)];

    return routeNames.filter(Boolean).some((routeName) => route().current(routeName));
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
    resetAiChat();
};

const searchEndpoint = computed(() => (route().has('panel.search') ? route('panel.search', { token: panelToken.value }) : ''));

const fetchSearchSuggestions = async () => {
    const endpoint = searchEndpoint.value;
    if (!endpoint || !isSearchOpen.value) return;

    const seq = searchRequestSeq += 1;
    const params = new URLSearchParams({
        q: normalizeSearchText(searchQuery.value),
        limit: '12',
    });

    searchLoading.value = true;
    try {
        const response = await window.axios.get(`${endpoint}?${params.toString()}`, {
            headers: {
                Accept: 'application/json',
            },
        });
        if (seq !== searchRequestSeq) return;

        searchResults.value = Array.isArray(response?.data?.items) ? response.data.items : [];
        searchLoaded.value = true;
    } catch (error) {
        if (seq !== searchRequestSeq) return;
        searchResults.value = [];
        searchLoaded.value = true;
    } finally {
        if (seq === searchRequestSeq) {
            searchLoading.value = false;
        }
    }
};

const queueSearchSuggestions = (delay = 220) => {
    window.clearTimeout(searchDebounceTimer);
    searchDebounceTimer = window.setTimeout(() => {
        fetchSearchSuggestions();
    }, delay);
};

const scrollAiChatToBottom = () => {
    nextTick(() => {
        if (aiChatRef.value) aiChatRef.value.scrollTop = aiChatRef.value.scrollHeight;
    });
};

const resetAiChat = () => {
    aiAbortController?.abort();
    aiAbortController = null;
    aiMode.value = false;
    aiMessages.value = [];
    aiInput.value = '';
    aiError.value = '';
    aiStreaming.value = false;
};

const backToKeywordSearch = () => {
    aiMode.value = false;
};

// Mirrors Chat.vue's SSE parsing (AiGateway/Chat.vue) — same backend event
// protocol (delta/done/error), reused here via axios + onDownloadProgress
// since native EventSource can't POST a request body.
const sendAiMessage = async (text) => {
    const content = text.trim();
    if (!content || aiStreaming.value) return;

    aiMessages.value.push({ role: 'user', content });
    const assistantMessage = { role: 'assistant', content: '' };
    aiMessages.value.push(assistantMessage);
    scrollAiChatToBottom();

    const outgoingMessages = aiMessages.value.slice(0, -1).map((m) => ({ role: m.role, content: m.content }));

    aiStreaming.value = true;
    aiError.value = '';
    aiAbortController = new AbortController();

    let processedLength = 0;
    let sseBuffer = '';

    const handleStreamEvent = (eventName, payload) => {
        if (eventName === 'delta') {
            assistantMessage.content += payload.text;
            scrollAiChatToBottom();
        } else if (eventName === 'done') {
            assistantMessage.content = payload.content;
        } else if (eventName === 'error') {
            aiError.value = payload.message || 'AI request failed.';
            aiMessages.value = aiMessages.value.filter((m) => m !== assistantMessage);
        }
    };

    const onDownloadProgress = (progressEvent) => {
        const fullText = progressEvent.event?.target?.responseText ?? progressEvent.target?.responseText ?? '';
        sseBuffer += fullText.slice(processedLength);
        processedLength = fullText.length;

        let boundary;
        while ((boundary = sseBuffer.indexOf('\n\n')) !== -1) {
            const rawEvent = sseBuffer.slice(0, boundary);
            sseBuffer = sseBuffer.slice(boundary + 2);

            let eventName = 'message';
            let dataLine = '';
            for (const line of rawEvent.split('\n')) {
                if (line.startsWith('event:')) eventName = line.slice(6).trim();
                if (line.startsWith('data:')) dataLine += line.slice(5).trim();
            }
            if (!dataLine) continue;
            try {
                handleStreamEvent(eventName, JSON.parse(dataLine));
            } catch {
                // Boundary landed mid-chunk — the rest arrives on the next tick.
            }
        }
    };

    try {
        // Same endpoint the AI Gateway chat playground (Chat.vue) posts to —
        // context is the only thing that distinguishes this call: it picks the
        // command-palette persona/system-prompt and a separate usage-log tag,
        // not a different route.
        await window.axios.post(panelRoute('ai-gateway.chat.auto.stream'), {
            model: null,
            messages: outgoingMessages,
            context: 'panel_search',
        }, {
            signal: aiAbortController.signal,
            responseType: 'text',
            headers: { Accept: 'text/event-stream' },
            onDownloadProgress,
        });
    } catch (error) {
        if (error?.code !== 'ERR_CANCELED' && !aiError.value) {
            aiError.value = 'AI request failed.';
        }
    } finally {
        aiStreaming.value = false;
        // Disabling the input while streaming (see template) blurs it — bring
        // the cursor back so the user can keep typing without reclicking.
        nextTick(() => aiInputRef.value?.focus?.());
    }
};

const startAiChat = () => {
    const query = searchQuery.value.trim();
    if (!query || !canUseAiSearch.value) return;
    aiMode.value = true;
    nextTick(() => aiInputRef.value?.focus?.());
    sendAiMessage(query);
};

const sendAiFollowUp = () => {
    if (aiStreaming.value) return;
    const text = aiInput.value;
    aiInput.value = '';
    sendAiMessage(text);
    nextTick(() => aiInputRef.value?.focus?.());
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

    // While chatting, Enter/arrows are plain text-input behavior — the AI
    // input's own @keydown.enter handles sending, not this palette-navigation logic.
    if (aiMode.value) {
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
        if (!filteredSearchResults.value.length && canUseAiSearch.value && searchQuery.value.trim()) {
            startAiChat();
            return;
        }
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
            item.children.some((child) => isItemActive(child)),
    );

    if (expandedGroups.value.length === 0) {
        expandedGroups.value = activeGroup?.id ? [activeGroup.id] : [];
    }

    restoreSidebarScrollTop();
    document.addEventListener('keydown', handleSearchKeydown);
    document.addEventListener('click', closeNotifications);
    startNotificationsPolling();
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleSearchKeydown);
    document.removeEventListener('click', closeNotifications);
    window.clearTimeout(searchDebounceTimer);
    stopNotificationsPolling();
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
    if (isSearchOpen.value) {
        queueSearchSuggestions();
    }
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
        window.clearTimeout(searchDebounceTimer);
        return;
    }

    searchLoaded.value = false;
    queueSearchSuggestions(0);
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
                                    isItemActive(child)
                                        ? 'border-l-2 ' + getColor(item).border + ' ' + getColor(item).bgLight + ' ' + getColor(item).text
                                        : 'border-l-2 border-transparent text-slate-600 dark:text-slate-400',
                                    'flex items-center gap-3 rounded-lg px-3 py-2 transition-all duration-200 hover:bg-slate-100 dark:hover:bg-slate-800'
                                ]"
                                :data-sidebar-active="isItemActive(child) ? 'true' : null"
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
                            isItemActive(item)
                                ? 'border-l-2 ' + getColor(item).border + ' ' + getColor(item).bgLight + ' ' + getColor(item).text
                                : 'border-l-2 border-transparent text-slate-600 dark:text-slate-400',
                            sidebarCollapsed ? 'justify-center px-2' : 'px-3',
                            'flex items-center gap-3 rounded-xl py-2.5 transition-all duration-200 hover:bg-slate-100 dark:hover:bg-slate-800'
                        ]"
                        :data-sidebar-active="isItemActive(item) ? 'true' : null"
                        :title="sidebarCollapsed ? item.label : ''"
                    >
                        <span
                            :class="[
                                'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl transition-all duration-200',
                                isItemActive(item)
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
                    <span v-if="!sidebarCollapsed" class="text-xs text-slate-400 dark:text-slate-500">{{ appName }} v{{ appVersion }}</span>
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
                                                @click="openNotification(notification)"
                                                class="flex cursor-pointer items-start gap-3 border-b border-slate-100 px-4 py-3 transition-colors hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50"
                                                :class="{ 'bg-blue-50/50 dark:bg-blue-900/10': !notification.read }"
                                            >
                                                <span class="mt-0.5">
                                                    <i :class="['bi text-lg', getNotificationIcon(notification)]"></i>
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
                                                    <div v-if="notification.data?.download_url" class="mt-2 flex items-center gap-2">
                                                        <button
                                                            type="button"
                                                            class="rounded-md border border-slate-300 px-2 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700"
                                                            @click="copyNotificationLink(notification, $event)"
                                                        >
                                                            <i class="bi bi-clipboard mr-1"></i>Copy
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="rounded-md bg-violet-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-violet-700"
                                                            @click="downloadNotification(notification, $event)"
                                                        >
                                                            <i class="bi bi-download mr-1"></i>Download
                                                        </button>
                                                    </div>
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
                                <DropdownLink v-if="userRoles.includes('admin') || userRoles.includes('reseller') || userPermissions.includes('manage_websites')" :href="panelRoute('websites.create')">
                                    <i class="bi bi-globe mr-2"></i>New Website
                                </DropdownLink>
                                <DropdownLink v-if="userRoles.includes('admin') || userRoles.includes('reseller') || userPermissions.includes('manage_email')" :href="panelRoute('emails.create')">
                                    <i class="bi bi-envelope mr-2"></i>New Email
                                </DropdownLink>
                                <DropdownLink v-if="userRoles.includes('admin') || userRoles.includes('reseller')" :href="panelRoute('databases.create')">
                                    <i class="bi bi-database mr-2"></i>New Database
                                </DropdownLink>
                                <div class="border-t border-slate-200 dark:border-slate-700"></div>
                                <DropdownLink v-if="userRoles.includes('admin') || userRoles.includes('reseller')" :href="panelRoute('users.manage')">
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
                {{ appName }} v{{ appVersion }} - Websites, Mail, Databases and more
            </footer>
        </div>

        <!-- Search Modal -->
        <Modal :show="isSearchOpen" maxWidth="2xl" @close="closeSearch">
            <div v-if="!aiMode" class="border-b border-slate-200 px-4 py-4 dark:border-slate-800">
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

            <!-- AI chat header -->
            <div v-else class="flex items-center gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <button
                    type="button"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                    title="Back to search"
                    @click="backToKeywordSearch"
                >
                    <i class="bi bi-arrow-left"></i>
                </button>
                <div class="flex min-w-0 flex-1 items-center gap-2">
                    <i class="bi bi-stars text-violet-500"></i>
                    <span class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">Ask AI</span>
                </div>
                <button
                    type="button"
                    class="shrink-0 text-xs font-medium text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                    @click="resetAiChat"
                >
                    New chat
                </button>
            </div>

            <div v-if="!aiMode" ref="searchResultsRef" class="max-h-[60vh] overflow-y-auto p-3">
                <button
                    v-if="canUseAiSearch && searchQuery.trim()"
                    type="button"
                    class="mb-2 flex w-full items-center gap-3 rounded-2xl border border-violet-200 bg-violet-50 px-4 py-3 text-left transition-all hover:bg-violet-100 dark:border-violet-800/60 dark:bg-violet-950/20 dark:hover:bg-violet-950/40"
                    @click="startAiChat"
                >
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300">
                        <i class="bi bi-stars text-base"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-violet-900 dark:text-violet-200">Ask AI: "{{ searchQuery.trim() }}"</span>
                        <span class="block truncate text-xs text-violet-600/80 dark:text-violet-400/80">Get an AI-generated answer and keep chatting</span>
                    </span>
                </button>

                <div v-if="searchLoading && !filteredSearchResults.length" class="px-4 py-12 text-center text-sm text-slate-500 dark:text-slate-400">
                    Searching...
                </div>

                <template v-else-if="filteredSearchResults.length">
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

                <div v-else-if="searchLoaded" class="px-4 py-12 text-center text-sm text-slate-500 dark:text-slate-400">
                    No results found.
                </div>
            </div>

            <!-- AI chat body -->
            <template v-else>
                <div ref="aiChatRef" class="max-h-[50vh] min-h-[30vh] space-y-3 overflow-y-auto p-4">
                    <div v-for="(message, index) in aiMessages" :key="index" class="flex" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
                        <div
                            class="max-w-[85%] whitespace-pre-wrap rounded-2xl px-4 py-2 text-sm"
                            :class="message.role === 'user'
                                ? 'bg-violet-600 text-white'
                                : 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100'"
                        >
                            <span v-if="message.role === 'assistant' && !message.content && aiStreaming" class="inline-flex items-center gap-1 text-slate-400">
                                <i class="bi bi-three-dots animate-pulse"></i> Thinking…
                            </span>
                            <template v-else>{{ message.content }}</template>
                        </div>
                    </div>
                    <p v-if="aiError" class="text-center text-xs text-red-600">{{ aiError }}</p>
                </div>

                <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">
                    <div class="flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 dark:border-slate-700 dark:bg-slate-950/40">
                        <input
                            ref="aiInputRef"
                            v-model="aiInput"
                            type="text"
                            class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:ring-0 dark:text-slate-100"
                            placeholder="Ask a follow-up…"
                            @keydown.enter.exact.prevent="sendAiFollowUp"
                        />
                        <button
                            type="button"
                            :disabled="aiStreaming || !aiInput.trim()"
                            class="shrink-0 rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="sendAiFollowUp"
                        >
                            <i class="bi bi-send"></i>
                        </button>
                    </div>
                </div>
            </template>
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
