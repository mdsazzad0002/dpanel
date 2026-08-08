<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    metrics: {
        type: Object,
        default: () => ({}),
    },
    activities: {
        type: Array,
        default: () => [],
    },
    sslStatus: {
        type: Object,
        default: () => ({}),
    },
    autoRenewNotice: {
        type: String,
        default: '',
    },
    rootInspection: {
        type: Object,
        default: () => ({}),
    },
    databaseConnection: { type: Object, default: () => ({ available: false }) },
    ipRules: { type: Array, default: () => [] },
});
const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const csrfToken = computed(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const actionMessage = ref('');
const actionMessageType = ref('success');
const actionLoading = ref(false);
const statusCheckLoading = ref(false);
const sslActionLoading = ref(false);
const cacheClearLoading = ref(false);
const permissionFixLoading = ref(false);
const databaseConnectLoading = ref(false);
const dependencyInstallLoading = ref('');
const storageLinkLoading = ref('');
const ipRules = ref([...props.ipRules]);
const ipRuleAddress = ref('');
const ipRuleType = ref('ban');
const ipRuleLoading = ref(false);
const toasts = ref([]);
let toastSeq = 0;

const toNumber = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const formatDate = (value) => {
    if (!value) return '-';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    const now = new Date();
    const diffMs = now - parsed;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const formatCertificateDate = (value) => {
    if (!value) return '-';
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString();
};

const statusValue = computed(() => String(props.website?.status ?? 'unknown').toLowerCase());
const statusLabel = computed(() => {
    const value = statusValue.value;
    if (!value) return 'Unknown';
    return value.charAt(0).toUpperCase() + value.slice(1);
});

const statusDot = computed(() => {
    if (statusValue.value === 'live') return 'bg-emerald-500';
    if (statusValue.value === 'disabled') return 'bg-red-500';
    return 'bg-slate-400';
});

const statusDotFor = (status) => {
    const value = String(status ?? '').toLowerCase();
    if (value === 'live') return 'bg-emerald-500';
    if (value === 'disabled') return 'bg-red-500';
    return 'bg-slate-400';
};

const statusClass = computed(() => {
    if (statusValue.value === 'live') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
    }
    if (statusValue.value === 'disabled') {
        return 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400';
    }
    return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
});

const statusClassFor = (status) => {
    const value = String(status ?? '').toLowerCase();
    if (value === 'live') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
    }
    if (value === 'disabled') {
        return 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400';
    }
    return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
};

const sslEnabled = computed(() => Boolean(props.website?.enable_ssl));
const websiteSslStatus = computed(() => String(props.sslStatus?.status || 'unknown').toLowerCase());
const websiteSslLabel = computed(() => {
    const value = websiteSslStatus.value;
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : 'Unknown';
});
const websiteSslClass = computed(() => {
    if (websiteSslStatus.value === 'valid') return 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300';
    if (['expired', 'invalid', 'failed'].includes(websiteSslStatus.value)) return 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300';
    if (websiteSslStatus.value === 'unreachable') return 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
    return 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200';
});
const sslDaysRemaining = computed(() => {
    if (props.sslStatus?.days_remaining === null || props.sslStatus?.days_remaining === undefined || props.sslStatus?.days_remaining === '') return null;
    const value = Number(props.sslStatus?.days_remaining);
    return Number.isFinite(value) ? value : null;
});
const sslValidityLabel = computed(() => {
    const days = sslDaysRemaining.value;
    if (days === null) return '-';
    if (days < 0) return `Expired ${Math.abs(days)} day${Math.abs(days) === 1 ? '' : 's'} ago`;
    if (days === 0) return 'Expires today';
    return `${days} day${days === 1 ? '' : 's'}`;
});
const sslCompactValidityLabel = computed(() => {
    if (!sslEnabled.value) return 'Disabled';
    const days = sslDaysRemaining.value;
    if (days === null) return websiteSslLabel.value;
    if (days < 0) return `Expired ${Math.abs(days)}d ago`;
    if (days === 0) return 'Expires today';
    return `Valid ${days}d`;
});
const sslCompactValidityClass = computed(() => {
    if (!sslEnabled.value) return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
    const days = sslDaysRemaining.value;
    if (days === null) return websiteSslClass.value;
    if (days < 0) return 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400';
    if (days <= 30) return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
    return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
});
const scheme = computed(() => (sslEnabled.value ? 'https' : 'http'));
const detectedApp = computed(() => String(props.rootInspection?.detected_app || '').toLowerCase());
const canClearCache = computed(() => ['wordpress', 'laravel', 'codeigniter'].includes(detectedApp.value));
const isLaravelWebsite = computed(() => detectedApp.value === 'laravel');
const supportsDatabaseAutoConnect = computed(() => ['laravel', 'wordpress'].includes(detectedApp.value));
const storageLinked = computed(() => Boolean(props.rootInspection?.storage_linked));
const isSystemWebsite = computed(() => String(props.website.id) === '1');

const serviceLinks = computed(() => [
    { label: 'WordPress Installer', icon: 'bi-wordpress', color: 'blue', href: panelRoute('websites.wordpress.manager', { id: props.website.id }), description: 'Install and manage WordPress' },
    { label: 'Usage Details', icon: 'bi-graph-up', color: 'violet', href: panelRoute('websites.usage', { id: props.website.id }), description: 'Detailed usage history' },
    { label: 'Redis Cache', icon: 'bi-lightning', color: 'amber', href: panelRoute('websites.redis-cache.index', { id: props.website.id }), description: 'Per-website cache isolation' },
    { label: 'File Manager', icon: 'bi-folder2-open', color: 'indigo', href: panelRoute('websites.filemanager', { id: props.website.id }), description: 'Browse and edit files' },
    { label: 'Cron Jobs', icon: 'bi-clock-history', color: 'rose', href: panelRoute('websites.cronjobs.index', { id: props.website.id }), description: 'Scheduled tasks' },
    { label: 'Git Deployment', icon: 'bi-github', color: 'emerald', href: panelRoute('websites.git.index', { id: props.website.id }), description: 'Clone, pull, push & auto sync' },
    { label: 'SSH Key Generator', icon: 'bi-key', color: 'amber', href: panelRoute('websites.ssh-key.index', { id: props.website.id }), description: 'Create a GitHub deployment key' },
    { label: 'Website Terminal', icon: 'bi-terminal', color: 'emerald', href: panelRoute('websites.terminal.index', { id: props.website.id }), description: 'Open the isolated project shell' },
    { label: 'Alis API', icon: 'bi-code-slash', color: 'violet', href: panelRoute('websites.alias-api.index', { id: props.website.id }), description: 'Manage aliases and scoped API access' },
    { label: 'Email Accounts', icon: 'bi-envelope', color: 'pink', href: panelRoute('emails.list'), description: 'Mailbox services' },
    { label: 'Databases', icon: 'bi-database', color: 'orange', href: panelRoute('databases.list', { website: props.website.domain }), description: `Databases for ${props.website.domain}` },
    { label: 'DNS Zones', icon: 'bi-diagram-3', color: 'teal', href: panelRoute('dns.zones'), description: 'DNS entries' },
    { label: 'PHP Manager', icon: 'bi-braces', color: 'indigo', href: panelRoute('php.manager'), description: 'PHP versions & modules' },
].filter((item) => !isSystemWebsite.value || !['WordPress Installer', 'File Manager'].includes(item.label)));

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
};

const quickActions = computed(() => [
    { label: 'Edit Website', icon: 'bi-pencil-square', href: panelRoute('websites.edit', { id: props.website.id }), color: 'slate', method: 'get' },
    ...(canClearCache.value
        ? [{ label: `Clear ${{ wordpress: 'WordPress', laravel: 'Laravel', codeigniter: 'CodeIgniter' }[detectedApp.value]} Cache`, icon: 'bi-trash3', action: 'clearCache', color: 'red' }]
        : [{ label: 'Check Status', icon: 'bi-arrow-repeat', action: 'checkStatus', color: 'blue' }]),
    { label: 'File Manager', icon: 'bi-folder2-open', href: panelRoute('websites.filemanager', { id: props.website.id }), color: 'emerald', method: 'get' },
    { label: 'Back to List', icon: 'bi-arrow-left', href: panelRoute('websites.list'), color: 'slate', method: 'get' },
].filter((item) => !isSystemWebsite.value || ['Back to List'].includes(item.label)));

const quickActionColorClasses = {
    slate: 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-700',
    blue: 'border-blue-200 bg-blue-50/50 text-blue-700 hover:border-blue-300 hover:bg-blue-50 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-400 dark:hover:border-blue-700',
    red: 'border-red-200 bg-red-50/50 text-red-700 hover:border-red-300 hover:bg-red-50 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400 dark:hover:border-red-700',
    emerald: 'border-emerald-200 bg-emerald-50/50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400 dark:hover:border-emerald-700',
};

const metrics = computed(() => [
    { label: 'Connections', value: toNumber(props.metrics.connections_current), icon: 'bi-people', color: 'blue' },
    { label: 'Active Jobs', value: toNumber(props.metrics.jobs_pending), icon: 'bi-list-task', color: 'amber' },
    { label: 'Databases', value: toNumber(props.metrics.databases_count), icon: 'bi-database', color: 'violet' },
    { label: 'Disk Usage', value: `${toNumber(props.metrics.disk_used_mb).toFixed(1)} MB`, icon: 'bi-hdd', color: 'emerald', sub: `Files: ${toNumber(props.metrics.file_count)}` },
]);

const metricColorClasses = {
    blue: 'bg-blue-500/10 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
    amber: 'bg-amber-500/10 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
    violet: 'bg-violet-500/10 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400',
    emerald: 'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
};

const liveSiteUrl = computed(() => {
    const domain = String(props.website?.domain || '').trim();
    if (!domain) return '';
    return `${scheme.value}://${domain}`;
});

const copyToClipboard = (text) => {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    }
};

const removeToast = (id) => {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
};

const pushToast = (message, type = 'error') => {
    if (!message) return;

    const id = `${Date.now()}-${toastSeq += 1}`;
    toasts.value.push({
        id,
        message: String(message),
        type,
    });

    window.setTimeout(() => {
        removeToast(id);
    }, 3500);
};

const clearProjectCache = async () => {
    if (cacheClearLoading.value) {
        return;
    }

    actionMessage.value = '';
    cacheClearLoading.value = true;

    try {
        const response = await fetch(panelRoute('websites.project-cache.clear', { id: props.website.id }), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken.value,
            },
            body: JSON.stringify({}),
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw data;
        }

        actionMessageType.value = 'success';
        actionMessage.value = String(data.message || 'Project cache cleared successfully.');
        pushToast(actionMessage.value, 'success');
    } catch (error) {
        actionMessageType.value = 'error';
        actionMessage.value = String(error?.message || 'Project cache clear failed.');
        pushToast(actionMessage.value, 'error');
    } finally {
        cacheClearLoading.value = false;
    }
};

const addIpRule = async () => {
    if (ipRuleLoading.value || !ipRuleAddress.value.trim()) return;
    ipRuleLoading.value = true;
    try {
        const response = await fetch(panelRoute('websites.ip-rules.store', { id: props.website.id }), {
            method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken.value },
            body: JSON.stringify({ rule_type: ipRuleType.value, ip_address: ipRuleAddress.value.trim() }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || Object.values(data.errors || {})?.[0]?.[0] || 'Unable to add IP rule.');
        if (data.rule && !ipRules.value.some((item) => item.id === data.rule.id)) ipRules.value.unshift(data.rule);
        ipRuleAddress.value = '';
        pushToast(data.message || 'IP rule added successfully.', 'success');
    } catch (error) {
        pushToast(error?.message || 'Unable to add IP rule.', 'error');
    } finally {
        ipRuleLoading.value = false;
    }
};

const removeIpRule = async (ruleItem) => {
    if (ipRuleLoading.value) return;
    ipRuleLoading.value = true;
    try {
        const response = await fetch(panelRoute('websites.ip-rules.destroy', { id: props.website.id, rule: ruleItem.id }), {
            method: 'DELETE', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken.value },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'Unable to remove IP rule.');
        ipRules.value = ipRules.value.filter((item) => item.id !== ruleItem.id);
        pushToast(data.message || 'IP rule removed successfully.', 'success');
    } catch (error) {
        pushToast(error?.message || 'Unable to remove IP rule.', 'error');
    } finally {
        ipRuleLoading.value = false;
    }
};

const fixProjectPermissions = async () => {
    if (permissionFixLoading.value) return;
    permissionFixLoading.value = true;
    try {
        const response = await fetch(panelRoute('websites.project-permissions.fix', { id: props.website.id }), {
            method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken.value },
            body: JSON.stringify({}),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'Permission repair failed.');
        pushToast(data.message || 'Project permissions fixed successfully.', 'success');
    } catch (error) {
        pushToast(error?.message || 'Permission repair failed.', 'error');
    } finally {
        permissionFixLoading.value = false;
    }
};

const connectProjectDatabase = async () => {
    if (databaseConnectLoading.value || !props.databaseConnection?.available) return;
    databaseConnectLoading.value = true;
    try {
        const response = await fetch(panelRoute('websites.project-database.connect', { id: props.website.id }), {
            method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken.value },
            body: JSON.stringify({}),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'Database connection failed.');
        pushToast(data.message || 'Project database connected successfully.', 'success');
    } catch (error) {
        pushToast(error?.message || 'Database connection failed.', 'error');
    } finally {
        databaseConnectLoading.value = false;
    }
};

const installProjectDependencies = async (action) => {
    if (dependencyInstallLoading.value) return;
    dependencyInstallLoading.value = action;
    try {
        const response = await fetch(panelRoute('websites.project-dependencies.install', { id: props.website.id }), {
            method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken.value },
            body: JSON.stringify({ action }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'Dependency installation failed.');
        pushToast(data.message || 'Dependencies installed successfully.', 'success');
    } catch (error) {
        pushToast(error?.message || 'Dependency installation failed.', 'error');
    } finally {
        dependencyInstallLoading.value = '';
    }
};

const updateStorageLink = async (action) => {
    if (storageLinkLoading.value) return;
    storageLinkLoading.value = action;

    try {
        const response = await fetch(panelRoute('websites.project-storage-link.update', { id: props.website.id }), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken.value,
            },
            body: JSON.stringify({ action }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'Storage link action failed.');

        pushToast(data.message || 'Storage link updated successfully.', 'success');
        router.reload({ only: ['rootInspection'], preserveScroll: true });
    } catch (error) {
        pushToast(error?.message || 'Storage link action failed.', 'error');
    } finally {
        storageLinkLoading.value = '';
    }
};

const checkWebsiteStatus = async () => {
    if (statusCheckLoading.value) {
        return;
    }

    actionMessage.value = '';
    statusCheckLoading.value = true;

    try {
        const response = await fetch(panelRoute('websites.status.check', { id: props.website.id }), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken.value,
            },
            body: JSON.stringify({}),
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw data;
        }

        actionMessageType.value = data.status === 'live' ? 'success' : 'error';
        actionMessage.value = String(data.message || 'Website status checked.');
        pushToast(actionMessage.value, actionMessageType.value === 'success' ? 'success' : 'error');
        router.reload({ only: ['website', 'activities'], preserveScroll: true });
    } catch (error) {
        actionMessageType.value = 'error';
        actionMessage.value = String(error?.message || 'Website status check failed.');
        pushToast(actionMessage.value, 'error');
    } finally {
        statusCheckLoading.value = false;
    }
};

const refreshSslStatus = () => {
    if (sslActionLoading.value) return;
    sslActionLoading.value = true;
    router.reload({
        only: ['website', 'sslStatus', 'autoRenewNotice'],
        preserveScroll: true,
        onSuccess: () => pushToast('SSL status refreshed.', 'success'),
        onError: () => pushToast('SSL status refresh failed.', 'error'),
        onFinish: () => { sslActionLoading.value = false; },
    });
};

const issueWebsiteSsl = async () => {
    if (sslActionLoading.value) return;
    sslActionLoading.value = true;

    try {
        const response = await fetch(panelRoute('websites.ssl.issue', { id: props.website.id }), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken.value,
            },
            body: JSON.stringify({}),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'SSL issue failed.');
        pushToast(data.message || 'SSL certificate issued successfully.', 'success');
        router.reload({ only: ['website', 'sslStatus', 'autoRenewNotice'], preserveScroll: true });
    } catch (error) {
        pushToast(error?.message || 'SSL issue failed.', 'error');
    } finally {
        sslActionLoading.value = false;
    }
};
</script>

<template>

    <Head title="Manage Website" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Website Management</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Tools and configuration for {{ website.domain }}.
                </p>
            </div>
        </template>

        <div class="space-y-6">
            <div class="fixed bottom-4 right-4 z-50 flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-3">
                <TransitionGroup
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="translate-y-[-8px] opacity-0"
                    enter-to-class="translate-y-0 opacity-100"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="translate-y-0 opacity-100"
                    leave-to-class="translate-y-[-8px] opacity-0"
                >
                    <div
                        v-for="toast in toasts"
                        :key="toast.id"
                        class="pointer-events-auto flex items-start gap-3 rounded-2xl border bg-white px-4 py-3 shadow-lg dark:bg-slate-900"
                        :class="toast.type === 'success'
                            ? 'border-emerald-200 text-emerald-700 dark:border-emerald-800 dark:text-emerald-400'
                            : 'border-red-200 text-red-700 dark:border-red-800 dark:text-red-400'"
                    >
                        <div
                            class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl"
                            :class="toast.type === 'success' ? 'bg-emerald-500/10' : 'bg-red-500/10'"
                        >
                            <svg v-if="toast.type === 'success'" viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium leading-5 text-slate-900 dark:text-slate-100">
                                {{ toast.message }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1 text-slate-400 transition hover:text-slate-700 dark:hover:text-slate-200"
                            @click="removeToast(toast.id)"
                            aria-label="Dismiss notification"
                        >
                            <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                                <path d="M18.3 5.71 12 12.01l-6.3-6.3-1.41 1.41 6.3 6.3-6.3 6.29 1.41 1.41 6.3-6.3 6.29 6.3 1.41-1.41-6.3-6.29 6.3-6.3z" />
                            </svg>
                        </button>
                    </div>
                </TransitionGroup>
            </div>
            <!-- Flash Messages -->
            <div v-if="page.props.flash?.success"
                class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">
                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current">
                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" />
                </svg>
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error"
                class="flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400">
                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current">
                    <path
                        d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                </svg>
                {{ page.props.flash.error }}
            </div>
            <div v-if="actionMessage"
                :class="actionMessageType === 'success'
                    ? 'flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
                    : 'flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400'">
                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current">
                    <path v-if="actionMessageType !== 'success'"
                        d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                    <path v-else
                        d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14.2-4.6-4.6 1.4-1.4L11 13.4l5.2-5.2 1.4 1.4-6.6 6.6z" />
                </svg>
                <span>{{ actionMessage }}</span>
            </div>

            <!-- Hero Section -->
            <section
                class="relative grid gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(420px,1fr)]">
                <!-- Background decorations -->
                <div
                    class="hidden">
                </div>
                <div
                    class="hidden">
                </div>

                <div class="contents">
                    <div class="contents">
                        <!-- Left: Website Info -->
                        <div class="space-y-5 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50 lg:p-8">
                            <!-- Status Badges -->
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium"
                                    :class="statusClass">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="statusDot"></span>
                                    {{ statusLabel }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-400">
                                    <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current">
                                        <path
                                            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                                    </svg>
                                    PHP {{ website.php_version || '-' }}
                                </span>
                                <span v-if="sslEnabled"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">
                                    <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current">
                                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" />
                                    </svg>
                                    SSL
                                </span>
                            </div>

                            <!-- Domain -->
                            <div>
                                <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-50">{{
                                    website.domain || '-' }}</h2>
                                <p class="mt-1.5 flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
                                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current opacity-50">
                                        <path
                                            d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z" />
                                    </svg>
                                    <span class="font-medium text-slate-700 dark:text-slate-300">{{ website.root_path ||
                                        '-'
                                    }}</span>
                                </p>
                            </div>

                            <!-- URL Cards -->
                            <div class="grid gap-3">
                                <!-- Live Website -->
                                <div
                                    class="group rounded-xl border border-slate-200 bg-slate-50/50 p-3 transition dark:border-slate-700/80 dark:bg-slate-800/30">
                                    <div class="flex items-center justify-between">
                                        <p
                                            class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                            Live Website</p>
                                        <a v-if="liveSiteUrl" :href="liveSiteUrl" target="_blank"
                                            rel="noopener noreferrer"
                                            class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">
                                            Open
                                            <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current">
                                                <path
                                                    d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z" />
                                            </svg>
                                        </a>
                                    </div>
                                    <div class="mt-2 flex items-center gap-2">
                                        <input :value="liveSiteUrl" type="text" readonly
                                            class="min-w-0 flex-1 truncate rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-600 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-400" />
                                        <button v-if="liveSiteUrl" type="button"
                                            class="shrink-0 rounded-lg border border-slate-200 bg-white p-1.5 text-slate-400 transition hover:text-emerald-600 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-500 dark:hover:text-emerald-400"
                                            @click="copyToClipboard(liveSiteUrl)" title="Copy URL">
                                            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current">
                                                <path
                                                    d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-3 dark:border-slate-700/80 dark:bg-slate-800/30">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Project</p>
                                        <p class="mt-1.5 text-sm font-semibold capitalize text-slate-700 dark:text-slate-200">{{ detectedApp || 'Generic website' }}</p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-3 dark:border-slate-700/80 dark:bg-slate-800/30">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Database Connection</p>
                                        <p class="mt-1.5 truncate text-sm font-semibold" :class="databaseConnection.available ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">{{ databaseConnection.available ? databaseConnection.database_name : 'No active database' }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Quick Actions -->
                        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50 lg:p-8">
                            <p
                                class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                Quick Actions</p>
                            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <Link v-for="action in quickActions.filter((item) => !item.action && item.label !== 'Back to List')" :key="action.label"
                                    :href="action.href" :method="action.method" as="button" :class="[
                                        'flex w-full items-center gap-3 rounded-xl border px-3.5 py-2.5 text-left text-[13px] font-medium transition-all duration-150',
                                        quickActionColorClasses[action.color] || quickActionColorClasses.slate,
                                    ]">
                                    <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current opacity-70">
                                        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                                    </svg>
                                    {{ action.label }}
                                </Link>
                                <div v-if="isLaravelWebsite" class="grid gap-2" :class="storageLinked ? 'grid-cols-2' : 'grid-cols-1'">
                                    <button type="button" :disabled="Boolean(storageLinkLoading)"
                                        class="flex w-full items-center gap-2 rounded-xl border border-blue-200 bg-blue-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-blue-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-400 dark:hover:border-blue-700"
                                        @click="updateStorageLink(storageLinked ? 'refresh' : 'link')">
                                        <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current opacity-70"><path d="M3.9 12a5 5 0 0 1 1.46-3.54l3.1-3.1a5 5 0 0 1 7.07 7.07l-1.41 1.41-1.42-1.42 1.42-1.41a3 3 0 0 0-4.24-4.24l-3.1 3.1a3 3 0 0 0 4.24 4.24l.7-.7 1.42 1.42-.7.7A5 5 0 0 1 3.9 12zm6.97-2.83.7-.7a5 5 0 0 1 7.07 7.07l-3.1 3.1a5 5 0 0 1-7.07-7.07l1.41-1.41 1.42 1.42-1.42 1.41a3 3 0 0 0 4.24 4.24l3.1-3.1a3 3 0 0 0-4.24-4.24l-.7.7-1.42-1.42z" /></svg>
                                        {{ storageLinkLoading ? 'Processing...' : (storageLinked ? 'Refresh Storage Link' : 'Link Storage') }}
                                    </button>
                                    <button v-if="storageLinked" type="button" :disabled="Boolean(storageLinkLoading)"
                                        class="flex w-full items-center gap-2 rounded-xl border border-red-200 bg-red-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-red-700 transition hover:border-red-300 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400 dark:hover:border-red-700"
                                        @click="updateStorageLink('unlink')">
                                        <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current opacity-70"><path d="M17 7h-3V5h3a5 5 0 0 1 0 10h-3v-2h3a3 3 0 0 0 0-6zM7 7h3V5H7a5 5 0 0 0 0 10h3v-2H7a3 3 0 0 1 0-6zm1 4h8V9H8v2zm-5.29 8.88 17.17-17.17 1.41 1.41L4.12 21.29l-1.41-1.41z" /></svg>
                                        {{ storageLinkLoading === 'unlink' ? 'Unlinking...' : 'Unlink' }}
                                    </button>
                                </div>
                                <button type="button" :disabled="permissionFixLoading"
                                    class="flex w-full items-center gap-3 rounded-xl border border-amber-200 bg-amber-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-amber-700 transition hover:border-amber-300 hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400"
                                    @click="fixProjectPermissions">
                                    <i class="bi bi-wrench-adjustable-circle text-base"></i>
                                    {{ permissionFixLoading ? 'Fixing Permissions...' : 'Fix Permissions' }}
                                </button>


                                <button v-if="supportsDatabaseAutoConnect" type="button"
                                    :disabled="databaseConnectLoading || !databaseConnection.available"
                                    :title="databaseConnection.available ? `Connect ${databaseConnection.database_name}` : 'Create an active database for this domain first'"
                                    class="flex w-full items-center gap-3 rounded-xl border border-cyan-200 bg-cyan-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-cyan-700 transition hover:border-cyan-300 hover:bg-cyan-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-cyan-800 dark:bg-cyan-500/10 dark:text-cyan-400"
                                    @click="connectProjectDatabase">
                                    <i class="bi bi-database-check text-base"></i>
                                    {{ databaseConnectLoading ? 'Connecting Database...' : databaseConnection.available ? `Connect ${detectedApp === 'wordpress' ? 'WordPress' : 'Laravel'} Database` : 'Create Database First' }}
                                </button>
                                <button v-if="rootInspection.has_composer_json" type="button"
                                    :disabled="Boolean(dependencyInstallLoading)"
                                    class="flex w-full items-center gap-3 rounded-xl border border-violet-200 bg-violet-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-violet-700 transition hover:border-violet-300 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-violet-800 dark:bg-violet-500/10 dark:text-violet-400"
                                    @click="installProjectDependencies('composer_install')">
                                    <i class="bi bi-box-seam text-base"></i>
                                    {{ dependencyInstallLoading === 'composer_install' ? 'Installing Composer...' : 'Install Composer Dependencies' }}
                                </button>
                                <button v-if="rootInspection.has_package_json" type="button"
                                    :disabled="Boolean(dependencyInstallLoading)"
                                    class="flex w-full items-center gap-3 rounded-xl border border-red-200 bg-red-50/50 px-3.5 py-2.5 text-left text-[13px] font-medium text-red-700 transition hover:border-red-300 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400"
                                    @click="installProjectDependencies('npm_install')">
                                    <i class="bi bi-node-plus text-base"></i>
                                    {{ dependencyInstallLoading === 'npm_install' ? 'Installing & Building...' : 'Install & Build NPM' }}
                                </button>
                                <button v-for="action in quickActions.filter((item) => item.action === 'clearCache')"
                                    :key="action.label" type="button" :disabled="cacheClearLoading" :class="[
                                        'flex w-full items-center gap-3 rounded-xl border px-3.5 py-2.5 text-left text-[13px] font-medium transition-all duration-150 disabled:cursor-not-allowed disabled:opacity-60',
                                        quickActionColorClasses[action.color] || quickActionColorClasses.slate,
                                    ]" @click="clearProjectCache()">
                                    <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current opacity-70">
                                        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                                    </svg>
                                    {{ action.action === 'clearCache' && cacheClearLoading ? 'Clearing...' :
                                        action.label }}
                                </button>
                                <button v-for="action in quickActions.filter((item) => item.action === 'checkStatus')"
                                    :key="action.label" type="button" :disabled="statusCheckLoading" :class="[
                                        'flex w-full items-center gap-3 rounded-xl border px-3.5 py-2.5 text-left text-[13px] font-medium transition-all duration-150 disabled:cursor-not-allowed disabled:opacity-60',
                                        quickActionColorClasses[action.color] || quickActionColorClasses.slate,
                                    ]" @click="checkWebsiteStatus()">
                                    <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 fill-current opacity-70">
                                        <path
                                            d="M12 4a8 8 0 1 0 7.45 5H17l3.5-3.5L24 9h-2.55A10 10 0 1 1 12 2v2zm1 4h-2v5l4.25 2.52 1-1.72L13 11.9V8z" />
                                    </svg>
                                    {{ statusCheckLoading ? 'Checking...' : action.label }}
                                </button>
                    </div>
                    <div class="mt-2">
                                <Link :href="panelRoute('websites.list')" as="button"
                                    class="flex w-full items-center gap-3 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-left text-[13px] font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-700">
                                    <i class="bi bi-arrow-left text-base opacity-70"></i>
                                    Back to List
                                </Link>
                    </div>
                </div>
                    </div>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">IP Ban / Whitelist</h2>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Rules apply only to {{ website.domain }}. IPv4 and IPv6 are supported.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ ipRules.length }} rules</span>
                </div>
                <div class="mt-4 grid gap-2 sm:grid-cols-[140px_minmax(0,1fr)_auto]">
                    <select v-model="ipRuleType" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="ban">Ban IP</option>
                        <option value="allow">Whitelist IP</option>
                    </select>
                    <input v-model="ipRuleAddress" type="text" placeholder="203.0.113.10 or IPv6 address" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" @keyup.enter="addIpRule" />
                    <button type="button" :disabled="ipRuleLoading || !ipRuleAddress.trim()" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900" @click="addIpRule">
                        {{ ipRuleLoading ? 'Saving...' : 'Add Rule' }}
                    </button>
                </div>
                <p v-if="ipRuleType === 'allow'" class="mt-2 text-xs font-medium text-amber-600 dark:text-amber-400">Warning: after the first whitelist entry is added, every non-whitelisted IP is blocked.</p>
                <div v-if="ipRules.length" class="mt-4 grid gap-2 sm:grid-cols-2">
                    <div v-for="ruleItem in ipRules" :key="ruleItem.id" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700">
                        <div class="min-w-0">
                            <span class="mr-2 rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" :class="ruleItem.rule_type === 'allow' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400' : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'">{{ ruleItem.rule_type === 'allow' ? 'Whitelist' : 'Ban' }}</span>
                            <code class="break-all text-xs text-slate-700 dark:text-slate-200">{{ ruleItem.ip_address }}</code>
                        </div>
                        <button type="button" :disabled="ipRuleLoading" class="shrink-0 text-xs font-semibold text-red-600 hover:text-red-700 disabled:opacity-50" @click="removeIpRule(ruleItem)">Remove</button>
                    </div>
                </div>
                <p v-else class="mt-4 text-xs text-slate-400">No IP rules configured; all visitors are currently allowed.</p>
            </section>

            <!-- Metrics Cards -->




            <!-- Services + Activity -->
            <section class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(340px,1fr)]">
                <div class="contents">

                    <section class="order-1 grid gap-3 sm:grid-cols-2 xl:col-span-2 xl:grid-cols-4">
                        <div v-for="metric in metrics" :key="metric.label"
                            class="group rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm transition hover:shadow-md dark:border-slate-800/80 dark:bg-slate-900/50">
                            <div class="flex items-start justify-between">
                                <div>
                                    <p
                                        class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                        {{ metric.label }}</p>
                                    <p class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">{{
                                        metric.value }}</p>
                                    <p v-if="metric.sub" class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">{{
                                        metric.sub }}</p>
                                </div>
                                <div
                                    :class="['flex h-10 w-10 items-center justify-center rounded-xl transition', metricColorClasses[metric.color]]">
                                    <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current opacity-80">
                                        <path
                                            d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </section>


                    <!-- SSL Management -->
                    <section class="order-2 overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                            <div>
                                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">SSL Certificate</h2>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Issue, renew and inspect the certificate without leaving Manage.</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-1 text-[11px] font-semibold" :class="sslCompactValidityClass">
                                    <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" /></svg>
                                    <span>SSL</span>
                                    <span class="opacity-40">|</span>
                                    <span>{{ sslCompactValidityLabel }}</span>
                                </span>
                            </div>
                        </div>

                        <div class="p-5">
                            <div v-if="autoRenewNotice" class="mb-4 rounded-xl border border-blue-200 bg-blue-50 px-3 py-2.5 text-xs text-blue-700 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-300">
                                {{ autoRenewNotice }}
                            </div>
                            <p class="text-sm text-slate-600 dark:text-slate-300">{{ sslStatus.message || 'No certificate status available.' }}</p>
                            <p class="mt-1 text-[11px] text-slate-400">Checked: {{ formatCertificateDate(sslStatus.checked_at) }}</p>

                            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                    <p class="text-[11px] font-medium text-slate-400">Subject</p>
                                    <p class="mt-1 break-all text-xs font-semibold text-slate-700 dark:text-slate-200">{{ sslStatus.subject_cn || sslStatus.domain || website.domain || '-' }}</p>
                                </div>
                                <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                    <p class="text-[11px] font-medium text-slate-400">Issuer</p>
                                    <p class="mt-1 break-all text-xs font-semibold text-slate-700 dark:text-slate-200">{{ sslStatus.issuer_cn || '-' }}</p>
                                </div>
                                <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                    <p class="text-[11px] font-medium text-slate-400">Valid From</p>
                                    <p class="mt-1 text-xs font-semibold text-slate-700 dark:text-slate-200">{{ formatCertificateDate(sslStatus.valid_from) }}</p>
                                </div>
                                <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                    <p class="text-[11px] font-medium text-slate-400">Valid Until</p>
                                    <p class="mt-1 text-xs font-semibold text-slate-700 dark:text-slate-200">{{ formatCertificateDate(sslStatus.valid_to) }}</p>
                                </div>
                                <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                    <p class="text-[11px] font-medium text-slate-400">Days Remaining</p>
                                    <p class="mt-1 text-xs font-semibold" :class="sslDaysRemaining !== null && sslDaysRemaining < 0 ? 'text-red-600 dark:text-red-400' : sslDaysRemaining !== null && sslDaysRemaining <= 30 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">{{ sslValidityLabel }}</p>
                                </div>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <button type="button" :disabled="sslActionLoading"
                                    class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                    @click="refreshSslStatus">
                                    {{ sslActionLoading ? 'Checking...' : 'Check SSL Now' }}
                                </button>
                                <button type="button" :disabled="sslActionLoading"
                                    class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:opacity-60 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400"
                                    @click="issueWebsiteSsl">
                                    {{ sslActionLoading ? 'Processing...' : 'Issue / Renew SSL' }}
                                </button>
                            </div>
                        </div>
                    </section>

                    <!-- Services Grid -->
                    <div
                        class="order-4 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50 xl:col-span-2">
                        <div class="flex items-center justify-between">
                            <h2
                                class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Services</h2>
                            <span
                                class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{
                                    serviceLinks.length }} tools</span>
                        </div>
                        <div class="mt-4 grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            <Link v-for="service in serviceLinks" :key="service.label" :href="service.href"
                                class="group flex items-center gap-3 rounded-xl border border-slate-100 bg-white p-3 transition-all duration-150 hover:-translate-y-0.5 hover:border-slate-200 hover:shadow-md dark:border-slate-800 dark:bg-slate-800/50 dark:hover:border-slate-700 dark:hover:shadow-lg">
                                <div
                                    :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition', serviceColorClasses[service.color]]">
                                    <i :class="['bi text-base', service.icon]"></i>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="truncate text-[13px] font-semibold text-slate-800 group-hover:text-slate-950 dark:text-slate-200 dark:group-hover:text-white">
                                        {{ service.label }}</p>
                                    <p class="mt-0.5 truncate text-[11px] text-slate-400 dark:text-slate-500">{{
                                        service.description
                                    }}</p>
                                </div>
                                <svg viewBox="0 0 24 24"
                                    class="h-4 w-4 shrink-0 fill-current text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-500 dark:text-slate-600 dark:group-hover:text-slate-400">
                                    <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" />
                                </svg>
                            </Link>
                        </div>
                    </div>


                </div>

                <div class="order-3 min-w-0 space-y-4">
                    <!-- Activity Timeline -->
                    <div
                        class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Activity
                        </h2>
                        <div class="mt-4 space-y-0">
                            <div v-if="activities.length === 0" class="flex flex-col items-center py-6 text-center">
                                <div
                                    class="flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800">
                                    <svg viewBox="0 0 24 24" class="h-6 w-6 text-slate-400 dark:text-slate-500"
                                        fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <p class="mt-3 text-sm font-medium text-slate-500 dark:text-slate-400">No activity yet
                                </p>
                            </div>
                            <div v-for="(item, index) in activities" :key="item.label"
                                class="relative flex gap-3 pb-4 last:pb-0">
                                <!-- Timeline line -->
                                <div class="relative flex flex-col items-center">
                                    <div
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-500/10 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400">
                                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current">
                                            <circle cx="12" cy="12" r="4" />
                                        </svg>
                                    </div>
                                    <div v-if="index < activities.length - 1"
                                        class="mt-1 h-full w-px bg-slate-200 dark:bg-slate-700"></div>
                                </div>
                                <div class="min-w-0 flex-1 pt-0.5">
                                    <p
                                        class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                        {{ item.label }}</p>
                                    <p
                                        class="mt-0.5 truncate text-[13px] font-medium text-slate-700 dark:text-slate-300">
                                        {{
                                            item.label === 'Request Created' || item.label === 'Request Updated'
                                                ? formatDate(item.value)
                                                : (item.value || '-')
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </section>

        </div>
    </AuthenticatedLayout>
</template>
