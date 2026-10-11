<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    roles: { type: Array, default: () => [] },
    permissions: { type: Array, default: () => [] },
    panelRoute: { type: Function, required: true },
});

const rawToolCategories = [
    { name: 'Websites & Domains', icon: 'bi-globe2', color: 'blue', tools: [
        { label: 'Create Website', hint: 'Add a new site', icon: 'bi-plus-square', route: 'websites.create', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        { label: 'List Websites', hint: 'Manage hosted sites', icon: 'bi-window-stack', route: 'websites.list', roles: ['admin', 'reseller'], permissions: ['manage_websites'] },
        { label: 'DNS Zones', hint: 'Manage DNS records', icon: 'bi-diagram-3', route: 'dns.zones', permissions: ['manage_dns'] },
        { label: 'Nameservers', hint: 'Configure nameservers', icon: 'bi-signpost-split', route: 'dns.nameservers', roles: ['admin', 'reseller'], permissions: ['manage_dns'] },
    ] },
    { name: 'Email', icon: 'bi-envelope', color: 'violet', tools: [
        { label: 'Create Email', hint: 'Create a mailbox', icon: 'bi-envelope-plus', route: 'emails.create', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
        { label: 'Email Accounts', hint: 'Manage mailboxes', icon: 'bi-envelope-open', route: 'emails.list', roles: ['admin', 'reseller'], permissions: ['manage_email'] },
    ] },
    { name: 'Databases', icon: 'bi-database', color: 'amber', tools: [
        { label: 'Create Database', hint: 'Add a database', icon: 'bi-database-add', route: 'databases.create', roles: ['admin', 'reseller'], permissions: ['manage_databases'] },
        { label: 'List Databases', hint: 'Users and databases', icon: 'bi-table', route: 'databases.list', roles: ['admin', 'reseller'], permissions: ['manage_databases'] },
    ] },
    { name: 'Server & Files', icon: 'bi-hdd-stack', color: 'emerald', tools: [
        { label: 'PHP Manager', hint: 'Versions and settings', icon: 'bi-braces', route: 'php.manager', roles: ['admin', 'reseller'], permissions: ['manage_php'] },
        { label: 'Monitoring', hint: 'Resources and logs', icon: 'bi-activity', route: 'monitoring.index', roles: ['admin', 'reseller'], permissions: ['view_monitoring'] },
        { label: 'Backups', hint: 'Snapshots and restore', icon: 'bi-cloud-arrow-down', route: 'backups.index', roles: ['admin', 'reseller'], permissions: ['manage_backups'] },
        { label: 'Trash Backups', hint: 'Deleted site archives', icon: 'bi-trash3', route: 'trash-backups.index', permissions: ['manage_backups'] },
    ] },
    { name: 'Security & Account', icon: 'bi-shield-check', color: 'rose', tools: [
        { label: 'Security', hint: 'Firewall and SSH', icon: 'bi-shield-lock', route: 'security.manager', roles: ['admin', 'reseller'], permissions: ['manage_security'] },
        { label: 'Users', hint: 'Manage panel users', icon: 'bi-people', route: 'users.manage', roles: ['admin', 'reseller'] },
        { label: 'Packages', hint: 'Resource quotas', icon: 'bi-box-seam', route: 'packages.index', roles: ['admin', 'reseller', 'superadmin'], permissions: ['manage_packages'] },
        { label: 'My Profile', hint: 'Account and password', icon: 'bi-person-circle', route: 'profile.edit' },
    ] },
];

const categoryColors = {
    blue: 'bg-blue-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-300',
    violet: 'bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300',
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300',
    emerald: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300',
    rose: 'bg-rose-50 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300',
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
    <div class="grid gap-4 sm:grid-cols-2">
        <section
            v-for="category in toolCategories"
            :key="category.name"
            :class="['flex flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900', category.tools.length > 2 && 'sm:col-span-2']"
        >
            <header class="flex items-center gap-2.5 px-4 pb-1 pt-4">
                <span :class="['flex h-7 w-7 items-center justify-center rounded-lg text-sm', categoryColors[category.color]]"><i :class="['bi', category.icon]"></i></span>
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ category.name }}</h3>
            </header>
            <div :class="['grid flex-1 grid-cols-2 gap-2 p-3', category.tools.length > 2 && 'md:grid-cols-4 lg:grid-cols-2 xl:grid-cols-4']">
                <Link
                    v-for="tool in category.tools"
                    :key="tool.route"
                    :href="panelRoute(tool.route)"
                    class="group flex flex-col items-start gap-2 rounded-lg border border-transparent p-3 transition hover:border-slate-200 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:border-slate-700 dark:hover:bg-slate-800/60 sm:flex-row sm:items-center"
                >
                    <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition-transform group-hover:scale-105', categoryColors[category.color]]"><i :class="['bi text-lg', tool.icon]"></i></span>
                    <span class="min-w-0 max-w-full">
                        <span class="block truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{{ tool.label }}</span>
                        <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ tool.hint }}</span>
                    </span>
                </Link>
            </div>
        </section>
    </div>
</template>
