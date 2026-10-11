<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    roles: { type: Array, default: () => [] },
    permissions: { type: Array, default: () => [] },
    panelRoute: { type: Function, required: true },
});

const rawToolCategories = [
    { name: 'Websites & Domains', icon: 'bi-globe2', color: 'emerald', tools: [
        { label: 'Create Website', hint: 'Add a new site', icon: 'bi-plus-square', route: 'websites.create', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        { label: 'List Websites', hint: 'Manage hosted sites', icon: 'bi-window-stack', route: 'websites.list', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        { label: 'DNS Zones', hint: 'Manage DNS records', icon: 'bi-diagram-3', route: 'dns.zones', permissions: ['manage_dns'] },
        { label: 'Nameservers', hint: 'Configure nameservers', icon: 'bi-signpost-split', route: 'dns.nameservers', roles: ['admin', 'reseller'], permissions: ['manage_dns'] },
    ] },
    { name: 'Email', icon: 'bi-envelope', color: 'sky', tools: [
        { label: 'Create Email', hint: 'Create a mailbox', icon: 'bi-envelope-plus', route: 'emails.create', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
        { label: 'Email Accounts', hint: 'Manage mailboxes', icon: 'bi-envelope-open', route: 'emails.list', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
    ] },
    { name: 'Databases', icon: 'bi-database', color: 'cyan', tools: [
        { label: 'Create Database', hint: 'Add a database', icon: 'bi-database-add', route: 'databases.create', roles: ['admin', 'reseller'], permissions: ['manage_databases'] },
        { label: 'List Databases', hint: 'Users and databases', icon: 'bi-table', route: 'databases.list', roles: ['admin', 'reseller'], permissions: ['manage_databases'] },
    ] },
    { name: 'Server & Files', icon: 'bi-hdd-stack', color: 'teal', tools: [
        { label: 'PHP Manager', hint: 'Versions and settings', icon: 'bi-braces', route: 'php.manager', roles: ['admin', 'reseller'], permissions: ['manage_php'] },
        { label: 'Monitoring', hint: 'Resources and logs', icon: 'bi-activity', route: 'monitoring.index', roles: ['admin', 'reseller'], permissions: ['view_monitoring'] },
        { label: 'Backups', hint: 'Snapshots and restore', icon: 'bi-cloud-arrow-down', route: 'backups.index', roles: ['admin', 'reseller'], permissions: ['manage_backups'] },
        { label: 'Trash Backups', hint: 'Deleted site archives', icon: 'bi-trash3', route: 'trash-backups.index', permissions: ['manage_backups'] },
    ] },
    { name: 'Security & Account', icon: 'bi-shield-check', color: 'indigo', tools: [
        { label: 'Security', hint: 'Firewall and SSH', icon: 'bi-shield-lock', route: 'security.manager', roles: ['admin', 'reseller'], permissions: ['manage_security'] },
        { label: 'Users', hint: 'Manage panel users', icon: 'bi-people', route: 'users.manage', roles: ['admin', 'reseller'] },
        { label: 'Packages', hint: 'Resource quotas', icon: 'bi-box-seam', route: 'packages.index', roles: ['admin', 'reseller', 'superadmin'], permissions: ['manage_packages'] },
        { label: 'My Profile', hint: 'Account and password', icon: 'bi-person-circle', route: 'profile.edit' },
    ] },
];

const categoryColors = {
    emerald: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300',
    sky: 'bg-sky-500/10 text-sky-600 dark:text-sky-300',
    cyan: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-300',
    teal: 'bg-teal-500/10 text-teal-600 dark:text-teal-300',
    indigo: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-300',
};

const toolCategories = computed(() => rawToolCategories.map((category) => ({
    ...category,
    tools: category.tools.filter((tool) => route().has(tool.route)
        && ((!tool.roles && !tool.permissions)
            || tool.roles?.some((role) => props.roles.includes(role))
            || tool.permissions?.some((permission) => props.permissions.includes(permission)))),
})).filter((category) => category.tools.length));
</script>

<template>
    <!--
        The sidebar takes 288px from md up, so md has less room than sm:
        column counts drop back at md and come back at lg.
    -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-1 lg:grid-cols-2">
        <section
            v-for="category in toolCategories"
            :key="category.name"
            :class="['flex min-w-0 flex-col rounded-2xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900', category.tools.length > 2 && 'sm:col-span-2 md:col-span-1 lg:col-span-2']"
        >
            <header class="flex items-center gap-2.5 px-4 pb-1 pt-4">
                <span :class="['flex h-7 w-7 items-center justify-center rounded-lg text-sm', categoryColors[category.color]]"><i :class="['bi', category.icon]"></i></span>
                <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-slate-50">{{ category.name }}</h3>
            </header>
            <div :class="['grid flex-1 grid-cols-2 gap-2 p-3', category.tools.length > 2 && 'sm:grid-cols-4 md:grid-cols-2 lg:grid-cols-4']">
                <Link
                    v-for="tool in category.tools"
                    :key="tool.route"
                    :href="panelRoute(tool.route)"
                    class="group flex min-w-0 flex-col items-start gap-2.5 rounded-xl border border-slate-100 p-3 transition hover:-translate-y-0.5 hover:border-emerald-400/40 hover:bg-emerald-400/[0.04] hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 dark:border-white/[0.06] dark:hover:border-emerald-400/30 dark:hover:bg-emerald-400/[0.06]"
                >
                    <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition-transform group-hover:scale-105', categoryColors[category.color]]"><i :class="['bi text-lg', tool.icon]"></i></span>
                    <span class="w-full min-w-0">
                        <span class="block truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{{ tool.label }}</span>
                        <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ tool.hint }}</span>
                    </span>
                </Link>
            </div>
        </section>
    </div>
</template>
