<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link, usePage } from '@inertiajs/vue3';
import { isClosedRoute } from '@/navigation/panelMenu';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
});

const { panelRoute } = usePanelApi();
const { t } = useI18n();
const page = usePage();

const isSystemWebsite = computed(() => String(props.website.id) === '1');

const serviceLinks = computed(() => [
    { label: t('websites.services.exportShare'), id: 'Export & Share', icon: 'bi-file-earmark-zip', color: 'violet', href: panelRoute('websites.quick-export.page', { id: props.website.id }), description: t('websites.services.exportShareDesc') },
    { label: t('websites.services.importClone'), id: 'Import & Clone', icon: 'bi-cloud-arrow-up', color: 'cyan', href: panelRoute('websites.import.index', { id: props.website.id }), description: t('websites.services.importCloneDesc') },
    { label: t('websites.services.pushToLive'), id: 'Push to Live', icon: 'bi-rocket-takeoff', color: 'indigo', href: panelRoute('websites.push-to-live.index', { id: props.website.id }), description: t('websites.services.pushToLiveDesc') },
    { label: t('websites.services.logs'), id: 'Logs', icon: 'bi-journal-text', color: 'teal', href: panelRoute('websites.logs.index', { id: props.website.id }), description: t('websites.services.logsDesc') },
    { label: t('websites.services.edgeCache'), id: 'Edge Cache', icon: 'bi-speedometer2', color: 'emerald', href: panelRoute('websites.edge-cache.index', { id: props.website.id }), description: t('websites.services.edgeCacheDesc') },
    { label: t('websites.services.redisCache'), id: 'Redis Cache', routeName: 'websites.redis-cache.index', icon: 'bi-lightning', color: 'amber', href: panelRoute('websites.redis-cache.index', { id: props.website.id }), description: t('websites.services.redisCacheDesc') },
    { label: t('websites.services.redisExplorer'), id: 'Redis Explorer', routeName: 'websites.redis-explorer.index', icon: 'bi-hdd-stack', color: 'red', href: panelRoute('websites.redis-explorer.index', { id: props.website.id }), description: t('websites.services.redisExplorerDesc') },
    { label: t('websites.services.fileManager'), id: 'File Manager', icon: 'bi-folder2-open', color: 'indigo', href: panelRoute('websites.filemanager', { id: props.website.id }), description: t('websites.services.fileManagerDesc') },
    { label: t('websites.services.ftpAccounts'), id: 'FTP Accounts', routeName: 'ftp.index', icon: 'bi-hdd-network', color: 'cyan', href: panelRoute('ftp.index', { website: props.website.id }), description: t('websites.services.ftpAccountsDesc') },
    { label: t('websites.services.cronJobs'), id: 'Cron Jobs', icon: 'bi-clock-history', color: 'rose', href: panelRoute('websites.cronjobs.index', { id: props.website.id }), description: t('websites.services.cronJobsDesc') },
    { label: t('websites.services.gitDeployment'), id: 'Git Deployment', icon: 'bi-github', color: 'emerald', href: panelRoute('websites.git.index', { id: props.website.id }), description: t('websites.services.gitDeploymentDesc') },
    { label: t('websites.services.sshKeyGenerator'), id: 'SSH Key Generator', icon: 'bi-key', color: 'amber', href: panelRoute('websites.ssh-key.index', { id: props.website.id }), description: t('websites.services.sshKeyGeneratorDesc') },
    { label: t('websites.services.websiteTerminal'), id: 'Website Terminal', routeName: 'websites.terminal.index', icon: 'bi-terminal', color: 'emerald', href: panelRoute('websites.terminal.index', { id: props.website.id }), description: t('websites.services.websiteTerminalDesc') },
    { label: t('websites.services.alisApi'), id: 'Alis API', icon: 'bi-code-slash', color: 'violet', href: panelRoute('websites.alias-api.index', { id: props.website.id }), description: t('websites.services.alisApiDesc') },
    { label: t('websites.services.emailAccounts'), id: 'Email Accounts', icon: 'bi-envelope', color: 'pink', href: panelRoute('emails.list'), description: t('websites.services.emailAccountsDesc') },
    { label: t('websites.services.databases'), id: 'Databases', icon: 'bi-database', color: 'orange', href: panelRoute('databases.list', { website: props.website.domain }), description: t('websites.services.databasesDesc', { domain: props.website.domain }) },
    { label: t('websites.services.dnsZones'), id: 'DNS Zones', icon: 'bi-diagram-3', color: 'teal', href: panelRoute('dns.zones'), description: t('websites.services.dnsZonesDesc') },
    { label: t('websites.services.phpInfo'), id: 'PHP Info', icon: 'bi-filetype-php', color: 'blue', href: panelRoute('websites.php-info.index', { id: props.website.id }), description: t('websites.services.phpInfoDesc') },
    { label: t('websites.services.phpManager'), id: 'PHP Manager', icon: 'bi-braces', color: 'indigo', href: panelRoute('php.manager'), description: t('websites.services.phpManagerDesc') },
    { label: t('websites.services.htaccessResolver'), id: '.htaccess Resolver', icon: 'bi-filetype-txt', color: 'teal', href: panelRoute('websites.htaccess.index', { id: props.website.id }), description: t('websites.services.htaccessResolverDesc') },
    { label: t('websites.services.ipBanWhitelist'), id: 'IP Ban / Whitelist', icon: 'bi-shield-lock', color: 'red', href: panelRoute('websites.ip-rules.index', { id: props.website.id }), description: t('websites.services.ipBanWhitelistDesc') },
    // App installers are shown last, in their own "Installers" section.
    { installer: true, label: t('websites.services.wordpressInstaller'), id: 'WordPress Installer', icon: 'bi-wordpress', color: 'blue', href: panelRoute('websites.wordpress.manager', { id: props.website.id }), description: t('websites.services.wordpressInstallerDesc') },
    { installer: true, label: t('websites.services.laravelInstaller'), id: 'Laravel Installer', icon: 'bi-box-seam', color: 'red', href: panelRoute('websites.laravel.installer', { id: props.website.id }), description: t('websites.services.laravelInstallerDesc') },
    { installer: true, label: t('websites.services.joomlaInstaller'), id: 'Joomla Installer', icon: 'bi-puzzle', color: 'orange', href: panelRoute('websites.apps.installer', { id: props.website.id, app: 'joomla' }), description: t('websites.services.joomlaInstallerDesc') },
    { installer: true, label: t('websites.services.drupalInstaller'), id: 'Drupal Installer', icon: 'bi-droplet', color: 'blue', href: panelRoute('websites.apps.installer', { id: props.website.id, app: 'drupal' }), description: t('websites.services.drupalInstallerDesc') },
    { installer: true, label: t('websites.services.codeigniterInstaller'), id: 'CodeIgniter Installer', icon: 'bi-fire', color: 'amber', href: panelRoute('websites.apps.installer', { id: props.website.id, app: 'codeigniter' }), description: t('websites.services.codeigniterInstallerDesc') },
    // Backlog U8 catalog apps; hidden on the system website like the installers above.
    ...[
        { app: 'nextcloud', icon: 'bi-cloud', color: 'blue' },
        { app: 'prestashop', icon: 'bi-cart', color: 'pink' },
        { app: 'opencart', icon: 'bi-bag', color: 'cyan' },
        { app: 'mediawiki', icon: 'bi-book', color: 'emerald' },
    ].map((item) => ({ installer: true, systemHidden: true, label: t(`apps.${item.app}.installer`), icon: item.icon, color: item.color, href: panelRoute('websites.apps.installer', { id: props.website.id, app: item.app }), description: t(`apps.${item.app}.card`) })),
    { installer: true, label: t('websites.services.whmcsInstaller'), id: 'WHMCS Installer', icon: 'bi-receipt', color: 'teal', href: panelRoute('websites.apps.installer', { id: props.website.id, app: 'whmcs' }), description: t('websites.services.whmcsInstallerDesc') },
// The system website is dpanel's own installation — hosting-management actions
// that overwrite files/git/database or grant separate account access don't
// apply to it and would risk breaking the panel itself, so hide them here.
].filter((item) => !isSystemWebsite.value || (!item.systemHidden && ![
    'WordPress Installer', 'Laravel Installer', 'Joomla Installer', 'Drupal Installer', 'CodeIgniter Installer', 'WHMCS Installer', 'File Manager', 'Import & Clone', 'FTP Accounts',
    'Cron Jobs', '.htaccess Resolver', 'Git Deployment', 'SSH Key Generator', 'Website Terminal', 'Export & Share', 'Push to Live',
].includes(item.id))).filter((item) => !isClosedRoute(item.routeName, page.props.platform?.closedRoutes)));

const sections = computed(() => [
    { title: t('websites.services.services'), items: serviceLinks.value.filter((item) => !item.installer), unit: 'tools' },
    { title: t('websites.services.installers'), items: serviceLinks.value.filter((item) => item.installer), unit: 'apps' },
].filter((section) => section.items.length > 0));

const serviceColorClasses = {
    blue: 'bg-blue-500/10 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
    emerald: 'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
    violet: 'bg-violet-500/10 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400',
    amber: 'bg-amber-500/10 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
    indigo: 'bg-indigo-500/10 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400',
    rose: 'bg-rose-500/10 text-rose-600 dark:bg-rose-500/15 dark:text-rose-400',
    pink: 'bg-pink-500/10 text-pink-600 dark:bg-pink-500/15 dark:text-pink-400',
    orange: 'bg-orange-500/10 text-orange-600 dark:bg-orange-500/15 dark:text-orange-400',
    teal: 'bg-teal-500/10 text-teal-600 dark:bg-teal-500/15 dark:text-teal-400',
    red: 'bg-red-500/10 text-red-600 dark:bg-red-500/15 dark:text-red-400',
    cyan: 'bg-cyan-500/10 text-cyan-600 dark:bg-cyan-500/15 dark:text-cyan-400',
};
</script>

<template>
    <div class="order-1 min-w-0 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm sm:p-5 dark:border-slate-800/80 dark:bg-slate-900/50 xl:row-span-2">
        <section v-for="(section, index) in sections" :key="section.title" :class="index > 0 ? 'mt-6 border-t border-slate-100 pt-5 dark:border-slate-800' : ''">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ section.title }}</h2>
            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ section.unit === 'apps' ? t('websites.services.appsCount', { count: section.items.length }) : t('websites.services.toolsCount', { count: section.items.length }) }}</span>
        </div>
        <div class="mt-4 grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
            <Link v-for="service in section.items" :key="service.label" :href="service.href"
                class="group flex items-center gap-3 rounded-xl border border-slate-100 bg-white p-3 transition-all duration-150 hover:-translate-y-0.5 hover:border-slate-200 hover:shadow-md dark:border-slate-800 dark:bg-slate-800/50 dark:hover:border-slate-700 dark:hover:shadow-lg">
                <div :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition', serviceColorClasses[service.color]]">
                    <i :class="['bi text-base', service.icon]"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[13px] font-semibold text-slate-800 group-hover:text-slate-950 dark:text-slate-200 dark:group-hover:text-white">{{ service.label }}</p>
                    <p class="mt-0.5 truncate text-[11px] text-slate-400 dark:text-slate-500">{{ service.description }}</p>
                </div>
                <svg viewBox="0 0 24 24"
                    class="h-4 w-4 shrink-0 fill-current text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-500 dark:text-slate-600 dark:group-hover:text-slate-400">
                    <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" />
                </svg>
            </Link>
        </div>
        </section>
    </div>
</template>
