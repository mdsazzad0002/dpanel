<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
});

const { panelRoute } = usePanelApi();

const isSystemWebsite = computed(() => String(props.website.id) === '1');

const serviceLinks = computed(() => [
    { label: 'Export & Share', icon: 'bi-file-earmark-zip', color: 'violet', href: panelRoute('websites.quick-export.page', { id: props.website.id }), description: 'Download files/database separately, or share a one-time clone link for another server' },
    { label: 'Import & Clone', icon: 'bi-cloud-arrow-up', color: 'cyan', href: panelRoute('websites.import.index', { id: props.website.id }), description: 'Import files and database — upload directly, clone from another site on this server, or pull a share link' },
    { label: 'WordPress Installer', icon: 'bi-wordpress', color: 'blue', href: panelRoute('websites.wordpress.manager', { id: props.website.id }), description: 'Install and manage WordPress' },
    { label: 'Usage Details', icon: 'bi-graph-up', color: 'violet', href: panelRoute('websites.usage', { id: props.website.id }), description: 'Detailed usage history' },
    { label: 'Redis Cache', icon: 'bi-lightning', color: 'amber', href: panelRoute('websites.redis-cache.index', { id: props.website.id }), description: 'Per-website cache isolation' },
    { label: 'File Manager', icon: 'bi-folder2-open', color: 'indigo', href: panelRoute('websites.filemanager', { id: props.website.id }), description: 'Browse and edit files' },
    { label: 'FTP Accounts', icon: 'bi-hdd-network', color: 'cyan', href: panelRoute('websites.ftp.index', { id: props.website.id }), description: 'Create client FTP access' },
    { label: 'Cron Jobs', icon: 'bi-clock-history', color: 'rose', href: panelRoute('websites.cronjobs.index', { id: props.website.id }), description: 'Scheduled tasks' },
    { label: 'Git Deployment', icon: 'bi-github', color: 'emerald', href: panelRoute('websites.git.index', { id: props.website.id }), description: 'Clone, pull, push & auto sync' },
    { label: 'SSH Key Generator', icon: 'bi-key', color: 'amber', href: panelRoute('websites.ssh-key.index', { id: props.website.id }), description: 'Create a GitHub deployment key' },
    { label: 'Website Terminal', icon: 'bi-terminal', color: 'emerald', href: panelRoute('websites.terminal.index', { id: props.website.id }), description: 'Open the isolated project shell' },
    { label: 'Alis API', icon: 'bi-code-slash', color: 'violet', href: panelRoute('websites.alias-api.index', { id: props.website.id }), description: 'Manage aliases and scoped API access' },
    { label: 'AI Chat Widget', icon: 'bi-chat-dots', color: 'indigo', href: panelRoute('websites.chat-widget.index', { id: props.website.id }), description: 'Generate a paste-ready embed script' },
    { label: 'Email Accounts', icon: 'bi-envelope', color: 'pink', href: panelRoute('emails.list'), description: 'Mailbox services' },
    { label: 'Databases', icon: 'bi-database', color: 'orange', href: panelRoute('databases.list', { website: props.website.domain }), description: `Databases for ${props.website.domain}` },
    { label: 'DNS Zones', icon: 'bi-diagram-3', color: 'teal', href: panelRoute('dns.zones'), description: 'DNS entries' },
    { label: 'PHP Manager', icon: 'bi-braces', color: 'indigo', href: panelRoute('php.manager'), description: 'PHP versions & modules' },
    { label: 'IP Ban / Whitelist', icon: 'bi-shield-lock', color: 'red', href: panelRoute('websites.ip-rules.index', { id: props.website.id }), description: 'Control website IP access' },
// The system website is dpanel's own installation — hosting-management actions
// that overwrite files/git/database or grant separate account access don't
// apply to it and would risk breaking the panel itself, so hide them here.
].filter((item) => !isSystemWebsite.value || ![
    'WordPress Installer', 'File Manager', 'Import & Clone', 'FTP Accounts',
    'Cron Jobs', 'Git Deployment', 'SSH Key Generator', 'Website Terminal', 'Export & Share',
].includes(item.label)));

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
    <div class="order-1 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50 xl:row-span-2">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Services</h2>
            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ serviceLinks.length }} tools</span>
        </div>
        <div class="mt-4 grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
            <Link v-for="service in serviceLinks" :key="service.label" :href="service.href"
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
    </div>
</template>
