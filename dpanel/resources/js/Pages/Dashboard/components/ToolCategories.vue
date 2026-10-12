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

const categoryIconColors = {
    emerald: 'text-emerald-500 dark:text-emerald-400',
    sky: 'text-sky-500 dark:text-sky-400',
    cyan: 'text-cyan-500 dark:text-cyan-400',
    teal: 'text-teal-500 dark:text-teal-400',
    indigo: 'text-indigo-500 dark:text-indigo-400',
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
    <!-- cPanel-style icon grid: fixed-width tiles, categories flow side by side and wrap. -->
    <section class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-900 sm:p-5">
        <div class="flex flex-wrap gap-x-10 gap-y-6">
            <div v-for="category in toolCategories" :key="category.name" class="min-w-0">
                <h3 class="mb-2.5 flex items-center gap-2 font-mono text-[11px] uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">
                    <i :class="['bi', category.icon, categoryIconColors[category.color]]"></i>
                    {{ category.name }}
                </h3>
                <div class="flex flex-wrap gap-1.5">
                    <Link
                        v-for="tool in category.tools"
                        :key="tool.route"
                        :href="panelRoute(tool.route)"
                        :title="tool.hint"
                        class="group flex w-[4.75rem] flex-col items-center gap-1.5 rounded-xl px-1 py-2.5 text-center transition hover:bg-emerald-400/[0.07] focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 sm:w-[5.25rem]"
                    >
                        <span :class="['flex h-11 w-11 items-center justify-center rounded-xl transition group-hover:-translate-y-0.5 group-hover:shadow-md group-hover:shadow-emerald-500/10', categoryColors[category.color]]">
                            <i :class="['bi text-lg', tool.icon]"></i>
                        </span>
                        <span class="line-clamp-2 text-[11px] font-medium leading-tight text-slate-700 group-hover:text-slate-950 dark:text-slate-300 dark:group-hover:text-white">{{ tool.label }}</span>
                    </Link>
                </div>
            </div>
        </div>
    </section>
</template>
