// Single source for the panel navigation: AuthenticatedLayout renders it as the
// sidebar, and the dashboard turns the same items into its tool sections, so a
// page added here shows up in both places.

export const rolePanelConfig = {
    admin: { label: 'Admin', hint: 'Super admin panel', icon: 'SA', iconClass: 'bi bi-person-gear', routeName: 'admin.panel', routeParams: { role: 'admin' }, roles: ['admin'] },
    reseller: { label: 'Reseller', hint: 'Reseller panel', icon: 'RS', iconClass: 'bi bi-person-workspace', routeName: 'reseller.panel', routeParams: { role: 'reseller' }, roles: ['admin', 'reseller'] },
    general: { label: 'General User', hint: 'General user panel', icon: 'US', iconClass: 'bi bi-person', routeName: 'user.panel', routeParams: { role: 'general' }, roles: ['admin', 'reseller'] },
    general_user: { label: 'General User', hint: 'General user panel', icon: 'US', iconClass: 'bi bi-person', routeName: 'user.panel', routeParams: { role: 'general' }, roles: ['admin', 'reseller'] },
};

const userManagementChildren = (userRoles) => {
    const roleChildren = ['admin', 'reseller', 'general']
        .map((role) => rolePanelConfig[role])
        .filter(Boolean)
        .filter((item) => item.routeName && route().has(item.routeName));

    const adminRuleChildren = userRoles.includes('admin')
        ? [
            { label: 'Manage Roles', hint: 'View role permissions', icon: 'MR', iconClass: 'bi bi-shield-check', routeName: 'roles.manage', activeRouteNames: ['roles.manage'], roles: ['admin'] },
        ]
        : [];

    return [
        ...roleChildren,
        { label: 'All Users', hint: 'Shared users panel', icon: 'MU', iconClass: 'bi bi-person-plus', routeName: 'users.manage', roles: ['admin', 'reseller'] },
        ...adminRuleChildren,
    ];
};

export const buildPanelMenu = (userRoles = []) => [
    { label: 'Dashboard', hint: 'Overview and stats', icon: 'DB', iconClass: 'bi bi-speedometer2', routeName: 'dashboard', color: 'blue' },
    {
        section: 'Hosting',
        id: 'web-management',
        label: 'Web Management',
        hint: 'Website operations',
        icon: 'WM',
        iconClass: 'bi bi-globe2',
        color: 'emerald',
        children: [
            { label: 'Create Website', hint: 'Add a new website', icon: 'CW', iconClass: 'bi bi-plus-square', routeName: 'websites.create', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: 'List Websites', hint: 'View all websites', icon: 'LW', iconClass: 'bi bi-list-ul', routeName: 'websites.list', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: 'GitHub Accounts', hint: 'Connect GitHub for deployments', icon: 'GH', iconClass: 'bi bi-github', routeName: 'github.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        ],
    },
    { section: 'Hosting', label: 'Node.js Apps', hint: 'Run Node.js apps from your home directory', icon: 'NJ', iconClass: 'bi bi-hexagon', routeName: 'apps.node.index', color: 'emerald', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
    { section: 'Hosting', label: 'Python Apps', hint: 'Run Python apps from your home directory', icon: 'PY', iconClass: 'bi bi-filetype-py', routeName: 'apps.python.index', color: 'cyan', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
    { section: 'Hosting', label: 'PHP Management', hint: 'Versions, extensions and config', icon: 'PH', iconClass: 'bi bi-braces', routeName: 'php.manager', roles: ['admin', 'reseller'], permissions: ['manage_php'], color: 'indigo' },
    {
        section: 'Hosting',
        id: 'docker',
        label: 'Docker',
        hint: 'Containers, stacks and apps',
        // Optional add-on: always listed so its pages can show the setup steps;
        // the hint flags it until `sudo dpanel docker` has installed it.
        feature: 'docker',
        icon: 'DK',
        iconClass: 'bi bi-box-seam',
        color: 'cyan',
        children: [
            { label: 'Overview', hint: 'Status, disk use, clean-up', icon: 'OV', iconClass: 'bi bi-speedometer2', routeName: 'docker.overview', roles: ['admin'] },
            { label: 'Containers', hint: 'Run, inspect, console, logs', icon: 'CT', iconClass: 'bi bi-boxes', routeName: 'docker.containers', roles: ['admin'] },
            { label: 'Stacks', hint: 'docker-compose apps', icon: 'ST', iconClass: 'bi bi-stack', routeName: 'docker.stacks', roles: ['admin'] },
            { label: 'App templates', hint: 'One-click apps and stacks', icon: 'AT', iconClass: 'bi bi-grid-3x3-gap', routeName: 'docker.templates', roles: ['admin'] },
            { label: 'Images', hint: 'Pull and remove images', icon: 'IM', iconClass: 'bi bi-layers', routeName: 'docker.images', roles: ['admin'] },
            { label: 'Networks', hint: 'Connect containers by name', icon: 'NW', iconClass: 'bi bi-diagram-3', routeName: 'docker.networks', roles: ['admin'] },
            { label: 'Volumes', hint: 'Persistent data', icon: 'VL', iconClass: 'bi bi-hdd-stack', routeName: 'docker.volumes', roles: ['admin'] },
        ],
    },
    {
        section: 'Domains & Email',
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
    {
        section: 'Domains & Email',
        id: 'email-management',
        label: 'Email Management',
        hint: 'Mailbox operations',
        icon: 'EM',
        iconClass: 'bi bi-envelope',
        color: 'violet',
        children: [
            { label: 'Create Email', hint: 'Add a mailbox', icon: 'CE', iconClass: 'bi bi-envelope-plus', routeName: 'emails.create', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
            { label: 'List Emails', hint: 'View all mailboxes', icon: 'LE', iconClass: 'bi bi-envelope-open', routeName: 'emails.list', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
            { label: 'Mail DNS Guide', hint: 'Configure MX, SPF, DKIM and DMARC', icon: 'DG', iconClass: 'bi bi-diagram-3', routeName: 'emails.guide', activeRouteNames: ['emails.guide', 'emails.guide.dkim', 'emails.guide.export'], roles: ['admin', 'reseller'], permissions: ['manage_email'] },
            { label: 'Mail IPs & SSL', hint: 'Mail IPs, hostnames and SMTP/IMAP certificates', icon: 'MS', iconClass: 'bi bi-shield-lock', routeName: 'emails.mail-ssl', roles: ['admin'], permissions: ['manage_mail_server'] },
            { label: 'Mail Health', hint: 'Failures, queue and spam signals', icon: 'MH', iconClass: 'bi bi-heart-pulse', routeName: 'mail-health.index', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
        ],
    },
    {
        section: 'Traffic & SEO',
        id: 'rules',
        label: 'Rules',
        hint: 'Edge rules for your websites',
        icon: 'RL',
        iconClass: 'bi bi-signpost-2',
        color: 'amber',
        children: [
            { label: 'Redirect Rules', hint: 'HTTPS, www and domain redirects', icon: 'RR', iconClass: 'bi bi-arrow-return-right', routeName: 'rules.redirects.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: 'Port Share', hint: 'Serve a website path from a Node.js/Python app or local port', icon: 'PS', iconClass: 'bi bi-ethernet', routeName: 'rules.port-shares.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        ],
    },
    {
        section: 'Traffic & SEO',
        id: 'seo-tools',
        label: 'SEO Tools',
        hint: 'Check how search engines see your sites',
        icon: 'SE',
        iconClass: 'bi bi-search',
        color: 'teal',
        children: [
            { label: 'Sitemap Verify', hint: 'robots.txt, sitemaps and listed pages', icon: 'SV', iconClass: 'bi bi-diagram-2', routeName: 'seo.sitemap-verify.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: 'URL Inspection', hint: 'Indexing, search previews, favicon, share cards and PWA', icon: 'UI', iconClass: 'bi bi-binoculars', routeName: 'seo.url-inspection.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: 'Icon Generator', hint: 'Favicons, PWA icons, manifest and share image', icon: 'IG', iconClass: 'bi bi-images', routeName: 'seo.icon-generator.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        ],
    },
    {
        section: 'Data & Backups',
        id: 'database-management',
        label: 'Database Management',
        hint: 'Database operations and phpMyAdmin',
        icon: 'DM',
        iconClass: 'bi bi-database',
        color: 'amber',
        children: [
            { label: 'Create Database', hint: 'Create a new database', icon: 'CD', iconClass: 'bi bi-database-add', routeName: 'databases.create', roles: ['admin', 'reseller'], permissions: ['manage_databases'] },
            { label: 'List Databases', hint: 'View all databases', icon: 'LD', iconClass: 'bi bi-table', routeName: 'databases.list', roles: ['admin', 'reseller'], permissions: ['manage_databases'] },
            { label: 'Remote Access', hint: 'Allow specific IPs to reach MySQL', icon: 'RA', iconClass: 'bi bi-globe2', routeName: 'databases.remote-access', roles: ['admin'] },
            { label: 'PostgreSQL', hint: 'Turn PostgreSQL and pgAdmin on or off', icon: 'PG', iconClass: 'bi bi-database-gear', routeName: 'databases.postgresql', roles: ['admin'] },
            { label: 'MongoDB', hint: 'Turn MongoDB on or off and open Mongo Admin', icon: 'MO', iconClass: 'bi bi-database-gear', routeName: 'databases.mongodb', roles: ['admin'] },
        ],
    },
    { section: 'Data & Backups', label: 'Database & Redis', hint: 'Point dpanel at a remote DB/Redis', icon: 'DB', iconClass: 'bi bi-hdd-network', routeName: 'self-connection.manager', roles: ['admin'], color: 'rose' },
    { section: 'Data & Backups', label: 'Backups', hint: 'Snapshots and restore', icon: 'BK', iconClass: 'bi bi-cloud-arrow-down', dynamicRouteNames: ['backups.index', 'monitoring.index'], permissions: ['manage_backups'], color: 'teal' },
    { section: 'Data & Backups', label: 'Trash Backup', hint: 'Deleted website archives', icon: 'TB', iconClass: 'bi bi-trash3', routeName: 'trash-backups.index', roles: ['admin', 'reseller', 'general', 'general_user'], permissions: ['manage_backups'], color: 'orange' },
    { section: 'Data & Backups', label: 'Migrate', hint: 'Import cPanel accounts', icon: 'MG', iconClass: 'bi bi-box-arrow-in-down', routeName: 'migrations.index', roles: ['admin', 'reseller'], permissions: ['manage_migrations'], color: 'cyan' },
    {
        section: 'Security',
        id: 'security-center',
        label: 'Security Center',
        hint: 'Malware scans, findings and security score',
        icon: 'SS',
        iconClass: 'bi bi-shield-check',
        color: 'red',
        children: [
            { label: 'Overview', hint: 'Security score, scans and events', icon: 'OV', iconClass: 'bi bi-speedometer2', routeName: 'security.center', roles: ['admin', 'reseller', 'general', 'general_user'] },
            { label: 'Findings', hint: 'Every issue the scans found', icon: 'FN', iconClass: 'bi bi-bug', routeName: 'security.center.findings', roles: ['admin', 'reseller', 'general', 'general_user'] },
            { label: 'Scan History', hint: 'Every scan and its result', icon: 'SH', iconClass: 'bi bi-clock-history', routeName: 'security.center.scans', roles: ['admin', 'reseller', 'general', 'general_user'] },
            { label: 'Detection Rules', hint: 'Turn scanner rules on or off', icon: 'DR', iconClass: 'bi bi-list-check', routeName: 'security.center.rules', roles: ['admin'] },
        ],
    },
    { section: 'Security', label: 'Firewall & SSH', hint: 'Firewall, SSH and hardening', icon: 'SC', iconClass: 'bi bi-shield-lock', routeName: 'security.manager', roles: ['admin', 'reseller'], permissions: ['manage_security'], color: 'red' },
    {
        section: 'Security',
        id: 'fail2ban',
        label: 'Fail2ban',
        hint: 'SSH logins, blocked IPs and whitelist',
        icon: 'FB',
        iconClass: 'bi bi-unlock',
        color: 'red',
        children: [
            { label: 'SSH Login History', hint: 'Login attempts and block settings', icon: 'SL', iconClass: 'bi bi-clock-history', routeName: 'security.fail2ban', roles: ['admin'] },
            { label: 'Whitelist', hint: 'IPs that are never blocked', icon: 'WL', iconClass: 'bi bi-check2-circle', routeName: 'security.fail2ban.whitelist', roles: ['admin'] },
            { label: 'Blocklist', hint: 'Block a new IP or unblock one', icon: 'BL', iconClass: 'bi bi-slash-circle', routeName: 'security.fail2ban.blocklist', roles: ['admin'] },
        ],
    },
    { section: 'System', label: 'Monitoring', hint: 'CPU, RAM, disk, logs', icon: 'MN', iconClass: 'bi bi-activity', routeName: 'monitoring.index', roles: ['admin', 'reseller'], permissions: ['view_monitoring'], color: 'orange' },
    {
        section: 'System',
        id: 'user-management',
        label: 'User Management',
        hint: 'Admin, reseller and user panels',
        icon: 'UM',
        iconClass: 'bi bi-people',
        color: 'pink',
        children: userManagementChildren(userRoles),
    },
    { section: 'System', label: 'Resource Packages', hint: 'Manage user quotas', icon: 'PK', iconClass: 'bi bi-box-seam', routeName: 'packages.index', roles: ['admin', 'superadmin', 'reseller'], permissions: ['manage_packages'] },
    {
        section: 'System',
        id: 'billing-system',
        label: 'Billing System',
        hint: 'Billing integrations and guides',
        icon: 'BL',
        iconClass: 'bi bi-credit-card',
        color: 'emerald',
        roles: ['admin', 'superadmin'],
        children: [
            { label: 'Services', hint: 'Available billing services', icon: 'BS', iconClass: 'bi bi-grid', routeName: 'billing.index', roles: ['admin', 'superadmin'] },
            { label: 'Billing with WHMCS', hint: 'Setup and operations guide', icon: 'WH', iconClass: 'bi bi-arrow-left-right', routeName: 'billing.whmcs', roles: ['admin', 'superadmin'] },
        ],
    },
    {
        section: 'AI',
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
    {
        section: 'AI',
        id: 'chat-engine',
        label: 'AI Chat Engine',
        hint: 'Channels, conversations and broadcasts',
        icon: 'CE',
        iconClass: 'bi bi-chat-square-text',
        color: 'teal',
        children: [
            { label: 'Businesses', hint: 'Apps, knowledge and AI settings', icon: 'BZ', iconClass: 'bi bi-briefcase', routeName: 'chat-engine.businesses.index', activeRouteNames: ['chat-engine.businesses.index', 'chat-engine.businesses.create', 'chat-engine.businesses.edit'], roles: ['admin', 'reseller'], permissions: ['manage_chat_engine'] },
            { label: 'Channels', hint: 'Every connected app, across businesses', icon: 'CN', iconClass: 'bi bi-broadcast', routeName: 'chat-engine.channels.index', activeRouteNames: ['chat-engine.channels.index', 'chat-engine.channels.create'], roles: ['admin', 'reseller'], permissions: ['manage_chat_engine'] },
            { label: 'Facebook Apps', hint: 'Auto-connect Pages via Facebook Login', icon: 'FB', iconClass: 'bi bi-facebook', routeName: 'chat-engine.facebook-apps.index', activeRouteNames: ['chat-engine.facebook-apps.index'], roles: ['admin', 'reseller'], permissions: ['manage_chat_engine'] },
            { label: 'Facebook Posts', hint: 'Publish content to connected Pages', icon: 'FP', iconClass: 'bi bi-file-post', routeName: 'chat-engine.facebook-posts.index', activeRouteNames: ['chat-engine.facebook-posts.index'], roles: ['admin', 'reseller'], permissions: ['manage_chat_engine'] },
            { label: 'Conversations', hint: 'Inbox and AI auto-reply', icon: 'CV', iconClass: 'bi bi-chat-dots', routeName: 'chat-engine.conversations.index', activeRouteNames: ['chat-engine.conversations.index', 'chat-engine.conversations.show'], roles: ['admin', 'reseller'], permissions: ['manage_chat_engine'] },
            { label: 'Scheduled Messages', hint: 'Broadcasts and one-off sends', icon: 'SM', iconClass: 'bi bi-calendar2-event', routeName: 'chat-engine.scheduled-messages.index', activeRouteNames: ['chat-engine.scheduled-messages.index', 'chat-engine.scheduled-messages.create'], roles: ['admin', 'reseller'], permissions: ['manage_chat_engine'] },
        ],
    },
];

export const hasMenuAccess = (item, roles = [], permissions = []) => {
    if (item.permissions?.length || item.roles?.length) {
        const hasPermission = item.permissions?.some((permission) => permissions.includes(permission)) ?? false;
        const hasRole = item.roles?.some((role) => roles.includes(role)) ?? false;
        return hasPermission || hasRole;
    }

    return true;
};

export const filterPanelMenu = (items, { roles = [], permissions = [], features = {} } = {}) => items
    .map((item) => {
        if (item.feature && !features?.[item.feature]) {
            item = { ...item, hint: 'Not installed — open for setup steps' };
        }
        if (!item.children) {
            return hasMenuAccess(item, roles, permissions) ? item : null;
        }

        const allowedChildren = item.children.filter((child) => hasMenuAccess(child, roles, permissions));
        if (!allowedChildren.length) return null;

        return {
            ...item,
            children: allowedChildren,
        };
    })
    .filter(Boolean)
    // A section heading goes above the first visible item of each section,
    // so a section the user cannot see leaves no empty heading behind.
    .map((item, index, items) => ({
        ...item,
        sectionStart: item.section && item.section !== items[index - 1]?.section ? item.section : null,
    }));

export const resolveItemRouteName = (item) => {
    if (item?.routeName && route().has(item.routeName)) {
        return item.routeName;
    }

    if (Array.isArray(item?.dynamicRouteNames)) {
        return item.dynamicRouteNames.find((name) => route().has(name)) || null;
    }

    return null;
};
