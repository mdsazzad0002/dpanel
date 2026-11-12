// Single source for the panel navigation: AuthenticatedLayout renders it as the
// sidebar, and the dashboard turns the same items into its tool sections, so a
// page added here shows up in both places.
//
// Labels and hints come from the nav.* translation keys (backlog U3). The menu
// is rebuilt inside computed()s, so it follows a language change. `section` and
// `id` stay English: they are identifiers (dashboard layout, exclusions).
import { i18n } from '@/i18n';

const t = (key) => i18n.global.t(key);

// The heading shown for a menu section.
export const sectionLabel = (name) => ({
    Websites: t('nav.sections.websites'),
    Email: t('nav.sections.email'),
    Databases: t('nav.sections.databases'),
    Domains: t('nav.sections.domains'),
    Backups: t('nav.sections.backups'),
    Apps: t('nav.sections.apps'),
    'Usage & Monitoring': t('nav.sections.usageMonitoring'),
    Security: t('nav.sections.security'),
    'Traffic & SEO': t('nav.sections.trafficSeo'),
    'Users & Billing': t('nav.sections.usersBilling'),
    System: t('nav.sections.system'),
    AI: t('nav.sections.ai'),
    General: t('nav.sections.general'),
}[name] ?? name);

export const rolePanelConfig = () => ({
    admin: { label: t('nav.admin'), hint: t('nav.adminHint'), icon: 'SA', iconClass: 'bi bi-person-gear', routeName: 'admin.panel', routeParams: { role: 'admin' }, roles: ['admin'] },
    reseller: { label: t('nav.reseller'), hint: t('nav.resellerHint'), icon: 'RS', iconClass: 'bi bi-person-workspace', routeName: 'reseller.panel', routeParams: { role: 'reseller' }, roles: ['admin', 'reseller'] },
    general: { label: t('nav.general'), hint: t('nav.generalHint'), icon: 'US', iconClass: 'bi bi-person', routeName: 'user.panel', routeParams: { role: 'general' }, roles: ['admin', 'reseller'] },
    general_user: { label: t('nav.general'), hint: t('nav.generalHint'), icon: 'US', iconClass: 'bi bi-person', routeName: 'user.panel', routeParams: { role: 'general' }, roles: ['admin', 'reseller'] },
});

const userManagementChildren = (userRoles) => {
    const roleChildren = ['admin', 'reseller', 'general']
        .map((role) => rolePanelConfig()[role])
        .filter(Boolean)
        .filter((item) => item.routeName && route().has(item.routeName));

    const adminRuleChildren = userRoles.includes('admin')
        ? [
            { label: t('nav.rolesManage'), hint: t('nav.rolesManageHint'), icon: 'MR', iconClass: 'bi bi-shield-check', routeName: 'roles.manage', activeRouteNames: ['roles.manage'], roles: ['admin'] },
        ]
        : [];

    return [
        ...roleChildren,
        { label: t('nav.usersManage'), hint: t('nav.usersManageHint'), icon: 'MU', iconClass: 'bi bi-person-plus', routeName: 'users.manage', roles: ['admin', 'reseller'] },
        ...adminRuleChildren,
    ];
};

export const buildPanelMenu = (userRoles = []) => [
    { label: t('nav.dashboard'), hint: t('nav.dashboardHint'), icon: 'DB', iconClass: 'bi bi-speedometer2', routeName: 'dashboard', color: 'blue' },
    {
        section: 'Websites',
        id: 'web-management',
        label: t('nav.webManagement'),
        hint: t('nav.webManagementHint'),
        icon: 'WM',
        iconClass: 'bi bi-globe2',
        color: 'emerald',
        children: [
            { label: t('nav.websitesList'), hint: t('nav.websitesListHint'), icon: 'LW', iconClass: 'bi bi-list-ul', routeName: 'websites.list', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: t('nav.websitesCreate'), hint: t('nav.websitesCreateHint'), icon: 'CW', iconClass: 'bi bi-plus-square', routeName: 'websites.create', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: t('nav.githubIndex'), hint: t('nav.githubIndexHint'), icon: 'GH', iconClass: 'bi bi-github', routeName: 'github.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        ],
    },
    { section: 'Websites', label: t('nav.wordpressIndex'), hint: t('nav.wordpressIndexHint'), icon: 'WP', iconClass: 'bi bi-wordpress', routeName: 'wordpress.index', color: 'blue', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
    {
        section: 'Websites',
        id: 'ftp-management',
        label: t('nav.ftpManagement'),
        hint: t('nav.ftpManagementHint'),
        icon: 'FT',
        iconClass: 'bi bi-hdd-network',
        color: 'cyan',
        children: [
            { label: t('nav.ftpIndex'), hint: t('nav.ftpIndexHint'), icon: 'LF', iconClass: 'bi bi-list-ul', routeName: 'ftp.index', activeRouteNames: ['ftp.index', 'ftp.show', 'websites.ftp.index'], roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: t('nav.ftpCreate'), hint: t('nav.ftpCreateHint'), icon: 'CF', iconClass: 'bi bi-person-plus', routeName: 'ftp.create', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: t('nav.ftpConnect'), hint: t('nav.ftpConnectHint'), icon: 'CC', iconClass: 'bi bi-plug', routeName: 'ftp.connect', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        ],
    },
    { section: 'Websites', label: t('nav.phpManager'), hint: t('nav.phpManagerHint'), icon: 'PH', iconClass: 'bi bi-braces', routeName: 'php.manager', roles: ['admin', 'reseller'], permissions: ['manage_php'], color: 'indigo' },
    {
        section: 'Email',
        id: 'email-management',
        label: t('nav.emailManagement'),
        hint: t('nav.emailManagementHint'),
        icon: 'EM',
        iconClass: 'bi bi-envelope',
        color: 'violet',
        children: [
            { label: t('nav.emailsList'), hint: t('nav.emailsListHint'), icon: 'LE', iconClass: 'bi bi-envelope-open', routeName: 'emails.list', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
            { label: t('nav.emailsCreate'), hint: t('nav.emailsCreateHint'), icon: 'CE', iconClass: 'bi bi-envelope-plus', routeName: 'emails.create', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
            { label: t('nav.emailsForwarders'), hint: t('nav.emailsForwardersHint'), icon: 'FC', iconClass: 'bi bi-forward', routeName: 'emails.forwarders', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
            { label: t('nav.emailsGuide'), hint: t('nav.emailsGuideHint'), icon: 'DG', iconClass: 'bi bi-diagram-3', routeName: 'emails.guide', activeRouteNames: ['emails.guide', 'emails.guide.dkim', 'emails.guide.export'], roles: ['admin', 'reseller'], permissions: ['manage_mail_dns'] },
            { label: t('nav.mailHealthIndex'), hint: t('nav.mailHealthIndexHint'), icon: 'MH', iconClass: 'bi bi-heart-pulse', routeName: 'mail-health.index', roles: ['admin', 'reseller'], permissions: ['view_mail_health'] },
            { label: t('nav.emailsMailSsl'), hint: t('nav.emailsMailSslHint'), icon: 'MS', iconClass: 'bi bi-shield-lock', routeName: 'emails.mail-ssl', roles: ['admin'], permissions: ['manage_mail_server'] },
            { label: t('nav.emailsSmtpRelay'), hint: t('nav.emailsSmtpRelayHint'), icon: 'SR', iconClass: 'bi bi-send', routeName: 'emails.smtp-relay', roles: ['admin'] },
            { label: t('nav.emailsSystemMail'), hint: t('nav.emailsSystemMailHint'), icon: 'SE', iconClass: 'bi bi-envelope-check', routeName: 'emails.system-mail', roles: ['admin'] },
        ],
    },
    {
        section: 'Databases',
        id: 'database-management',
        label: t('nav.databaseManagement'),
        hint: t('nav.databaseManagementHint'),
        icon: 'DM',
        iconClass: 'bi bi-database',
        color: 'amber',
        children: [
            { label: t('nav.databasesList'), hint: t('nav.databasesListHint'), icon: 'LD', iconClass: 'bi bi-table', routeName: 'databases.list', roles: ['admin', 'reseller'], permissions: ['manage_databases'] },
            { label: t('nav.databasesCreate'), hint: t('nav.databasesCreateHint'), icon: 'CD', iconClass: 'bi bi-database-add', routeName: 'databases.create', roles: ['admin', 'reseller'], permissions: ['manage_databases'] },
            { label: t('nav.databasesRemoteAccess'), hint: t('nav.databasesRemoteAccessHint'), icon: 'RA', iconClass: 'bi bi-globe2', routeName: 'databases.remote-access', roles: ['admin'] },
            { label: t('nav.databasesPostgresql'), hint: t('nav.databasesPostgresqlHint'), icon: 'PG', iconClass: 'bi bi-database-gear', routeName: 'databases.postgresql', roles: ['admin'] },
            { label: t('nav.databasesMongodb'), hint: t('nav.databasesMongodbHint'), icon: 'MO', iconClass: 'bi bi-database-gear', routeName: 'databases.mongodb', roles: ['admin'] },
        ],
    },
    { section: 'Databases', label: t('nav.selfConnectionManager'), hint: t('nav.selfConnectionManagerHint'), icon: 'DB', iconClass: 'bi bi-hdd-network', routeName: 'self-connection.manager', roles: ['admin'], color: 'rose' },
    {
        section: 'Domains',
        id: 'dns-management',
        label: t('nav.dnsManagement'),
        hint: t('nav.dnsManagementHint'),
        icon: 'DN',
        iconClass: 'bi bi-diagram-3',
        color: 'cyan',
        children: [
            { label: t('nav.dnsZones'), hint: t('nav.dnsZonesHint'), icon: 'DZ', iconClass: 'bi bi-bounding-box-circles', routeName: 'dns.zones', roles: ['admin', 'reseller', 'general', 'general_user'], permissions: ['manage_dns'] },
            { label: t('nav.dnsNameservers'), hint: t('nav.dnsNameserversHint'), icon: 'NS', iconClass: 'bi bi-signpost-split', routeName: 'dns.nameservers', roles: ['admin', 'reseller'], permissions: ['manage_dns'] },
            { label: t('nav.dnsTransfers'), hint: t('nav.dnsTransfersHint'), icon: 'ZT', iconClass: 'bi bi-arrow-left-right', routeName: 'dns.transfers', roles: ['admin'] },
        ],
    },
    { section: 'Backups', id: 'backups', label: t('nav.backupsIndex'), hint: t('nav.backupsIndexHint'), icon: 'BK', iconClass: 'bi bi-cloud-arrow-down', dynamicRouteNames: ['backups.index', 'monitoring.index'], permissions: ['manage_backups'], color: 'teal' },
    { section: 'Backups', id: 'trash-backup', label: t('nav.trashBackupsIndex'), hint: t('nav.trashBackupsIndexHint'), icon: 'TB', iconClass: 'bi bi-trash3', routeName: 'trash-backups.index', roles: ['admin', 'reseller', 'general', 'general_user'], permissions: ['manage_backups'], color: 'orange' },
    { section: 'Backups', label: t('nav.webTrashIndex'), hint: t('nav.webTrashIndexHint'), icon: 'WT', iconClass: 'bi bi-trash2', routeName: 'web-trash.index', roles: ['admin', 'reseller', 'general', 'general_user'], permissions: ['manage_websites'], color: 'red' },
    { section: 'Backups', label: t('nav.migrationsIndex'), hint: t('nav.migrationsIndexHint'), icon: 'MG', iconClass: 'bi bi-box-arrow-in-down', routeName: 'migrations.index', roles: ['admin', 'reseller'], permissions: ['manage_migrations'], color: 'cyan' },
    { section: 'Apps', label: t('nav.appsNodeIndex'), hint: t('nav.appsNodeIndexHint'), icon: 'NJ', iconClass: 'bi bi-hexagon', routeName: 'apps.node.index', color: 'emerald', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
    { section: 'Apps', label: t('nav.appsPythonIndex'), hint: t('nav.appsPythonIndexHint'), icon: 'PY', iconClass: 'bi bi-filetype-py', routeName: 'apps.python.index', color: 'cyan', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
    { section: 'Apps', label: t('nav.appsRustIndex'), hint: t('nav.appsRustIndexHint'), icon: 'RS', iconClass: 'bi bi-gear-wide-connected', routeName: 'apps.rust.index', color: 'orange', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
    {
        section: 'Apps',
        id: 'docker',
        label: t('nav.docker'),
        hint: t('nav.dockerHint'),
        // Optional add-on: always listed so its pages can show the setup steps;
        // the hint flags it until `sudo dpanel docker` has installed it.
        feature: 'docker',
        icon: 'DK',
        iconClass: 'bi bi-box-seam',
        color: 'cyan',
        children: [
            { label: t('nav.dockerOverview'), hint: t('nav.dockerOverviewHint'), icon: 'OV', iconClass: 'bi bi-speedometer2', routeName: 'docker.overview', roles: ['admin'] },
            { label: t('nav.dockerContainers'), hint: t('nav.dockerContainersHint'), icon: 'CT', iconClass: 'bi bi-boxes', routeName: 'docker.containers', roles: ['admin'] },
            { label: t('nav.dockerStacks'), hint: t('nav.dockerStacksHint'), icon: 'ST', iconClass: 'bi bi-stack', routeName: 'docker.stacks', roles: ['admin'] },
            { label: t('nav.dockerTemplates'), hint: t('nav.dockerTemplatesHint'), icon: 'AT', iconClass: 'bi bi-grid-3x3-gap', routeName: 'docker.templates', roles: ['admin'] },
            { label: t('nav.dockerImages'), hint: t('nav.dockerImagesHint'), icon: 'IM', iconClass: 'bi bi-layers', routeName: 'docker.images', roles: ['admin'] },
            { label: t('nav.dockerNetworks'), hint: t('nav.dockerNetworksHint'), icon: 'NW', iconClass: 'bi bi-diagram-3', routeName: 'docker.networks', roles: ['admin'] },
            { label: t('nav.dockerVolumes'), hint: t('nav.dockerVolumesHint'), icon: 'VL', iconClass: 'bi bi-hdd-stack', routeName: 'docker.volumes', roles: ['admin'] },
        ],
    },
    { section: 'Usage & Monitoring', label: t('nav.usageIndex'), hint: t('nav.usageIndexHint'), icon: 'UL', iconClass: 'bi bi-speedometer2', routeName: 'usage.index', color: 'rose' },
    { section: 'Usage & Monitoring', label: t('nav.bandwidthIndex'), hint: t('nav.bandwidthIndexHint'), icon: 'BW', iconClass: 'bi bi-bar-chart-line', routeName: 'bandwidth.index', activeRouteNames: ['bandwidth.index', 'dashboard.usage'], color: 'amber' },
    { section: 'Usage & Monitoring', label: t('nav.monitoringIndex'), hint: t('nav.monitoringIndexHint'), icon: 'MN', iconClass: 'bi bi-activity', routeName: 'monitoring.index', roles: ['admin', 'reseller'], permissions: ['view_monitoring'], color: 'orange' },
    { section: 'Security', label: t('nav.securityManager'), hint: t('nav.securityManagerHint'), icon: 'SC', iconClass: 'bi bi-shield-lock', routeName: 'security.manager', roles: ['admin', 'reseller'], permissions: ['manage_security'], color: 'red' },
    {
        section: 'Security',
        id: 'security-center',
        label: t('nav.securityCenter'),
        hint: t('nav.securityCenterHint'),
        icon: 'SS',
        iconClass: 'bi bi-shield-check',
        color: 'red',
        children: [
            { label: t('nav.securityCenterOverview'), hint: t('nav.securityCenterOverviewHint'), icon: 'OV', iconClass: 'bi bi-speedometer2', routeName: 'security.center', roles: ['admin'], permissions: ['view_security_center'] },
            { label: t('nav.securityCenterFindings'), hint: t('nav.securityCenterFindingsHint'), icon: 'FN', iconClass: 'bi bi-bug', routeName: 'security.center.findings', roles: ['admin'], permissions: ['view_security_center'] },
            { label: t('nav.securityCenterScans'), hint: t('nav.securityCenterScansHint'), icon: 'SH', iconClass: 'bi bi-clock-history', routeName: 'security.center.scans', roles: ['admin'], permissions: ['view_security_center'] },
            { label: t('nav.securityCenterRules'), hint: t('nav.securityCenterRulesHint'), icon: 'DR', iconClass: 'bi bi-list-check', routeName: 'security.center.rules', roles: ['admin'] },
        ],
    },
    {
        section: 'Security',
        id: 'fail2ban',
        label: t('nav.fail2ban'),
        hint: t('nav.fail2banHint'),
        icon: 'FB',
        iconClass: 'bi bi-unlock',
        color: 'red',
        children: [
            { label: t('nav.securityFail2ban'), hint: t('nav.securityFail2banHint'), icon: 'SL', iconClass: 'bi bi-clock-history', routeName: 'security.fail2ban', roles: ['admin'] },
            { label: t('nav.securityFail2banWhitelist'), hint: t('nav.securityFail2banWhitelistHint'), icon: 'WL', iconClass: 'bi bi-check2-circle', routeName: 'security.fail2ban.whitelist', roles: ['admin'] },
            { label: t('nav.securityFail2banBlocklist'), hint: t('nav.securityFail2banBlocklistHint'), icon: 'BL', iconClass: 'bi bi-slash-circle', routeName: 'security.fail2ban.blocklist', roles: ['admin'] },
        ],
    },
    { section: 'Security', label: t('nav.securityFirewallRules'), hint: t('nav.securityFirewallRulesHint'), icon: 'FW', iconClass: 'bi bi-bricks', routeName: 'security.firewall.rules', roles: ['admin'], color: 'red' },
    { section: 'Security', label: t('nav.securityLoginUsers'), hint: t('nav.securityLoginUsersHint'), icon: 'LU', iconClass: 'bi bi-person-lock', routeName: 'security.login-users', roles: ['admin'], userIds: [1], color: 'violet' },
    {
        section: 'Traffic & SEO',
        id: 'rules',
        label: t('nav.rules'),
        hint: t('nav.rulesHint'),
        icon: 'RL',
        iconClass: 'bi bi-signpost-2',
        color: 'amber',
        children: [
            { label: t('nav.rulesRedirectsIndex'), hint: t('nav.rulesRedirectsIndexHint'), icon: 'RR', iconClass: 'bi bi-arrow-return-right', routeName: 'rules.redirects.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: t('nav.rulesSiteProtectionIndex'), hint: t('nav.rulesSiteProtectionIndexHint'), icon: 'SP', iconClass: 'bi bi-shield-lock', routeName: 'rules.site-protection.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: t('nav.rulesPortSharesIndex'), hint: t('nav.rulesPortSharesIndexHint'), icon: 'PS', iconClass: 'bi bi-ethernet', routeName: 'rules.port-shares.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        ],
    },
    {
        section: 'Traffic & SEO',
        id: 'seo-tools',
        label: t('nav.seoTools'),
        hint: t('nav.seoToolsHint'),
        icon: 'SE',
        iconClass: 'bi bi-search',
        color: 'teal',
        children: [
            { label: t('nav.seoSitemapVerifyIndex'), hint: t('nav.seoSitemapVerifyIndexHint'), icon: 'SV', iconClass: 'bi bi-diagram-2', routeName: 'seo.sitemap-verify.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: t('nav.seoUrlInspectionIndex'), hint: t('nav.seoUrlInspectionIndexHint'), icon: 'UI', iconClass: 'bi bi-binoculars', routeName: 'seo.url-inspection.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: t('nav.seoIconGeneratorIndex'), hint: t('nav.seoIconGeneratorIndexHint'), icon: 'IG', iconClass: 'bi bi-images', routeName: 'seo.icon-generator.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
            { label: t('nav.seoImageWatermarkIndex'), hint: t('nav.seoImageWatermarkIndexHint'), icon: 'WM', iconClass: 'bi bi-eraser', routeName: 'seo.image-watermark.index', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        ],
    },
    {
        section: 'Users & Billing',
        id: 'user-management',
        label: t('nav.userManagement'),
        hint: t('nav.userManagementHint'),
        icon: 'UM',
        iconClass: 'bi bi-people',
        color: 'pink',
        children: userManagementChildren(userRoles),
    },
    { section: 'Users & Billing', label: t('nav.packagesIndex'), hint: t('nav.packagesIndexHint'), icon: 'PK', iconClass: 'bi bi-box-seam', routeName: 'packages.index', roles: ['admin', 'superadmin', 'reseller'], permissions: ['manage_packages'] },
    {
        section: 'Users & Billing',
        id: 'billing-system',
        label: t('nav.billingSystem'),
        hint: t('nav.billingSystemHint'),
        icon: 'BL',
        iconClass: 'bi bi-credit-card',
        color: 'emerald',
        roles: ['admin', 'superadmin'],
        children: [
            { label: t('nav.billingIndex'), hint: t('nav.billingIndexHint'), icon: 'BS', iconClass: 'bi bi-grid', routeName: 'billing.index', roles: ['admin', 'superadmin'] },
            { label: t('nav.billingWhmcs'), hint: t('nav.billingWhmcsHint'), icon: 'WH', iconClass: 'bi bi-arrow-left-right', routeName: 'billing.whmcs', roles: ['admin', 'superadmin'] },
        ],
    },
    { section: 'System', label: t('nav.systemIpAddressesIndex'), hint: t('nav.systemIpAddressesIndexHint'), icon: 'IP', iconClass: 'bi bi-diagram-3', routeName: 'system.ip-addresses.index', roles: ['admin', 'reseller'], color: 'sky' },
    { section: 'System', label: t('nav.systemUpdatesIndex'), hint: t('nav.systemUpdatesIndexHint'), icon: 'UP', iconClass: 'bi bi-cloud-arrow-down', routeName: 'system.updates.index', roles: ['admin'], color: 'emerald' },
    { section: 'System', label: t('nav.settingsBranding'), hint: t('nav.settingsBrandingHint'), icon: 'BR', iconClass: 'bi bi-palette', routeName: 'settings.branding', roles: ['admin', 'reseller'], color: 'violet' },
    { section: 'System', label: t('nav.systemExtensionsIndex'), hint: t('nav.systemExtensionsIndexHint'), icon: 'EX', iconClass: 'bi bi-puzzle', routeName: 'system.extensions.index', roles: ['admin'], color: 'violet' },
    {
        section: 'AI',
        id: 'ai-gateway',
        label: t('nav.aiGateway'),
        hint: t('nav.aiGatewayHint'),
        icon: 'AI',
        iconClass: 'bi bi-robot',
        color: 'indigo',
        children: [
            { label: t('nav.aiGatewayDashboard'), hint: t('nav.aiGatewayDashboardHint'), icon: 'GD', iconClass: 'bi bi-speedometer2', routeName: 'ai-gateway.dashboard', activeRouteNames: ['ai-gateway.dashboard'], roles: ['admin', 'reseller'] },
            { label: t('nav.aiGatewayChat'), hint: t('nav.aiGatewayChatHint'), icon: 'CH', iconClass: 'bi bi-chat-dots', routeName: 'ai-gateway.chat', activeRouteNames: ['ai-gateway.chat'], roles: ['admin', 'reseller'] },
            { label: t('nav.aiGatewayProvidersIndex'), hint: t('nav.aiGatewayProvidersIndexHint'), icon: 'PV', iconClass: 'bi bi-hdd-network', routeName: 'ai-gateway.providers.index', activeRouteNames: ['ai-gateway.providers.index', 'ai-gateway.providers.create', 'ai-gateway.providers.edit'], roles: ['admin', 'reseller'] },
            { label: t('nav.aiGatewayModelsIndex'), hint: t('nav.aiGatewayModelsIndexHint'), icon: 'MD', iconClass: 'bi bi-cpu', routeName: 'ai-gateway.models.index', activeRouteNames: ['ai-gateway.models.index'], roles: ['admin', 'reseller'] },
            { label: t('nav.aiGatewayVoiceIndex'), hint: t('nav.aiGatewayVoiceIndexHint'), icon: 'VT', iconClass: 'bi bi-mic', routeName: 'ai-gateway.voice.index', activeRouteNames: ['ai-gateway.voice.index'], roles: ['admin'] },
            { label: t('nav.aiGatewayLocalIndex'), hint: t('nav.aiGatewayLocalIndexHint'), icon: 'LM', iconClass: 'bi bi-motherboard', routeName: 'ai-gateway.local.index', activeRouteNames: ['ai-gateway.local.index'], roles: ['admin'] },
            { label: t('nav.aiGatewayApiKeysIndex'), hint: t('nav.aiGatewayApiKeysIndexHint'), icon: 'AK', iconClass: 'bi bi-key', routeName: 'ai-gateway.api-keys.index', activeRouteNames: ['ai-gateway.api-keys.index'], roles: ['admin', 'reseller'] },
            { label: t('nav.aiGatewayDocs'), hint: t('nav.aiGatewayDocsHint'), icon: 'DC', iconClass: 'bi bi-file-earmark-code', routeName: 'ai-gateway.docs', activeRouteNames: ['ai-gateway.docs'], roles: ['admin', 'reseller'] },
        ],
    },
    { section: 'AI', label: t('nav.mcpTokensIndex'), hint: t('nav.mcpTokensIndexHint'), icon: 'MC', iconClass: 'bi bi-plug', routeName: 'mcp.tokens.index', activeRouteNames: ['mcp.tokens.index', 'mcp.oauth.consent'], roles: ['admin'], color: 'violet' },
];

export const hasMenuAccess = (item, roles = [], permissions = []) => {
    if (item.permissions?.length || item.roles?.length) {
        const hasPermission = item.permissions?.some((permission) => permissions.includes(permission)) ?? false;
        const hasRole = item.roles?.some((role) => roles.includes(role)) ?? false;
        return hasPermission || hasRole;
    }

    return true;
};

// A route this OS or profile does not have (see PlatformCapabilities).
export const isClosedRoute = (routeName, closedRoutes = []) => closedRoutes.some((prefix) => routeName?.startsWith(prefix));

export const filterPanelMenu = (items, { roles = [], permissions = [], features = {}, userId = null, closedRoutes = [] } = {}) => items
    .map((item) => {
        if (isClosedRoute(item.routeName, closedRoutes)) return null;
        // userIds pins an item to specific panel accounts (e.g. only the primary user).
        if (item.userIds && !item.userIds.includes(Number(userId))) return null;
        if (item.feature && !features?.[item.feature]) {
            item = { ...item, hint: t('nav.notInstalled') };
        }
        if (!item.children) {
            return hasMenuAccess(item, roles, permissions) ? item : null;
        }

        const allowedChildren = item.children.filter((child) => hasMenuAccess(child, roles, permissions) && !isClosedRoute(child.routeName, closedRoutes));
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
