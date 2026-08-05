<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';

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
    aliasWebsites: {
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
const storageLinkLoading = ref('');
const aliasSubmitting = ref(false);
const aliasActionLoading = ref('');
const aliasEditingId = ref('');
const aliasEditForm = useForm({
    domain: '',
});
const aliasApiOpen = ref(false);
const aliasApiLoading = ref(false);
const aliasApi = ref({ enabled: false, has_token: false, token_hint: '', challenge_token: '', endpoint: '' });
const aliasApiPlainToken = ref('');
const loadAliasApi = async () => {
    aliasApiOpen.value = true; aliasApiLoading.value = true;
    try { const response = await fetch(panelRoute('websites.alias-api.settings', { id: props.website.id }), { headers: { Accept: 'application/json' } }); aliasApi.value = await response.json(); }
    finally { aliasApiLoading.value = false; }
};
const rotateAliasApi = async () => {
    if (!confirm('Rotate the Alias API token? The previous token will stop working.')) return;
    const response = await fetch(panelRoute('websites.alias-api.rotate', { id: props.website.id }), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken.value } });
    const data = await response.json(); if (response.ok) { aliasApiPlainToken.value = data.token; await loadAliasApi(); }
};
const toggleAliasApi = async () => {
    const response = await fetch(panelRoute('websites.alias-api.toggle', { id: props.website.id }), { method: 'PATCH', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken.value }, body: JSON.stringify({ enabled: !aliasApi.value.enabled }) });
    if (response.ok) aliasApi.value.enabled = !aliasApi.value.enabled;
};
const toasts = ref([]);
let toastSeq = 0;
const aliasForm = useForm({
    domain_type: 'alis',
    domain: '',
    parent_id: '',
    start_directory: '',
    root_path: '',
    php_version: '',
    enable_ssl: false,
});

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

const sslStatusLabel = (status) => {
    const value = String(status ?? '').toLowerCase();
    if (value === 'valid') return 'SSL Active';
    if (value === 'issued') return 'SSL Active';
    if (value === 'renewed') return 'SSL Active';
    if (value === 'failed') return 'SSL Failed';
    if (value === 'disabled') return 'SSL Off';
    return 'SSL Unknown';
};

const sslStatusClass = (status) => {
    const value = String(status ?? '').toLowerCase();
    if (value === 'valid' || value === 'issued' || value === 'renewed') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
    }
    if (value === 'failed') {
        return 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400';
    }
    if (value === 'disabled') {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
    }
    return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
};

const aliasSslValidityLabel = (alias) => {
    if (!alias?.enable_ssl) return 'Disabled';
    if (alias?.ssl_days_remaining === null || alias?.ssl_days_remaining === undefined || alias?.ssl_days_remaining === '') {
        return sslStatusLabel(alias?.ssl_status);
    }
    const days = Number(alias.ssl_days_remaining);
    if (!Number.isFinite(days)) return sslStatusLabel(alias?.ssl_status);
    if (days < 0) return `Expired ${Math.abs(days)}d ago`;
    if (days === 0) return 'Expires today';
    return `Valid ${days}d`;
};

const aliasSslValidityClass = (alias) => {
    if (!alias?.enable_ssl || alias?.ssl_days_remaining === null || alias?.ssl_days_remaining === undefined || alias?.ssl_days_remaining === '') {
        return sslStatusClass(alias?.ssl_status);
    }
    const days = Number(alias.ssl_days_remaining);
    if (!Number.isFinite(days)) return sslStatusClass(alias?.ssl_status);
    if (days < 0) return 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400';
    if (days <= 30) return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
    return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
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
const canClearCache = computed(() => ['wordpress', 'laravel'].includes(detectedApp.value));
const isLaravelWebsite = computed(() => detectedApp.value === 'laravel');
const storageLinked = computed(() => Boolean(props.rootInspection?.storage_linked));
const isSystemWebsite = computed(() => String(props.website.id) === '1');

const serviceLinks = computed(() => [
    { label: 'WordPress Installer', icon: 'bi-wordpress', color: 'blue', href: panelRoute('websites.wordpress.manager', { id: props.website.id }), description: 'Install and manage WordPress' },
    { label: 'Usage Details', icon: 'bi-graph-up', color: 'violet', href: panelRoute('websites.usage', { id: props.website.id }), description: 'Detailed usage history' },
    { label: 'Redis Cache', icon: 'bi-lightning', color: 'amber', href: panelRoute('websites.redis-cache.index', { id: props.website.id }), description: 'Per-website cache isolation' },
    { label: 'File Manager', icon: 'bi-folder2-open', color: 'indigo', href: panelRoute('websites.filemanager', { id: props.website.id }), description: 'Browse and edit files' },
    { label: 'Cron Jobs', icon: 'bi-clock-history', color: 'rose', href: panelRoute('websites.cronjobs.index', { id: props.website.id }), description: 'Scheduled tasks' },
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
        ? [{ label: `Clear ${detectedApp.value === 'wordpress' ? 'WordPress' : 'Laravel'} Cache`, icon: 'bi-trash3', action: 'clearCache', color: 'red' }]
        : [{ label: 'Check Website Status', icon: 'bi-arrow-repeat', action: 'checkStatus', color: 'blue' }]),
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

const startAliasEdit = (alias) => {
    aliasEditingId.value = String(alias?.id || '');
    aliasEditForm.domain = String(alias?.domain || '');
};

const cancelAliasEdit = () => {
    aliasEditingId.value = '';
    aliasEditForm.reset();
};

const saveAliasEdit = async (alias) => {
    const aliasId = String(alias?.id || '').trim();
    if (!aliasId || aliasActionLoading.value) return;

    aliasActionLoading.value = `edit:${aliasId}`;

    try {
        const response = await window.axios.patch(panelRoute('websites.alias.update', { id: aliasId }), {
            domain: String(aliasEditForm.domain || '').trim(),
        }, {
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const responseType = String(response?.data?.type || 'success');
        pushToast(String(response?.data?.message || 'Alias updated successfully.'), responseType === 'warning' ? 'error' : 'success');
        aliasEditingId.value = '';
        router.reload({ only: ['aliasWebsites', 'metrics', 'activities'], preserveScroll: true });
    } catch (error) {
        const data = error?.response?.data || {};
        pushToast(String(data?.message || 'Alias update failed.'), 'error');
    } finally {
        aliasActionLoading.value = '';
    }
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

const aliasParentDomain = computed(() => String(props.website?.domain || '').trim().toLowerCase());
const aliasParentId = computed(() => String(props.website?.id || '').trim());

const submitAlias = () => {
    aliasForm.clearErrors();
    aliasForm.parent_id = aliasParentId.value;
    aliasForm.start_directory = String(props.website?.start_directory || 'public').trim() || 'public';
    aliasForm.root_path = String(props.website?.root_path || '').trim();
    aliasForm.php_version = String(props.website?.php_version || '').trim();
    aliasForm.enable_ssl = Boolean(props.website?.enable_ssl);
    aliasSubmitting.value = true;

    window.axios.post(panelRoute('websites.store'), aliasForm.data(), {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })
        .then(() => {
            pushToast('Alias website created successfully.', 'success');
            aliasForm.reset('domain');
            aliasForm.parent_id = '';
            router.reload({ only: ['aliasWebsites', 'metrics', 'activities'], preserveScroll: true });
        })
        .catch((error) => {
            const data = error?.response?.data || {};
            const errors = data?.errors || {};
            pushToast(data?.message || 'Alias website creation failed.', 'error');
            Object.entries(errors).forEach(([key, messages]) => {
                aliasForm.setError(key, Array.isArray(messages) ? String(messages[0] || '') : String(messages || ''));
            });
        })
        .finally(() => {
            aliasSubmitting.value = false;
        });
};

const runAliasAction = async (alias, action) => {
    const aliasId = String(alias?.id || '').trim();
    if (!aliasId || aliasActionLoading.value) return;

    aliasActionLoading.value = `${action}:${aliasId}`;

    try {
        const endpoint = panelRoute('websites.ssl.issue', { id: aliasId });

        const response = await fetch(endpoint, {
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

        actionMessageType.value = String(data.type || 'success');
        actionMessage.value = String(data.message || 'Action completed successfully.');
        pushToast(actionMessage.value, actionMessageType.value === 'success' ? 'success' : 'error');
    } catch (error) {
        actionMessageType.value = 'error';
        actionMessage.value = String(error?.message || 'Alias action failed.');
        pushToast(actionMessage.value, 'error');
    } finally {
        aliasActionLoading.value = '';
    }
};

const removeAlias = async (alias) => {
    const aliasId = String(alias?.id || '').trim();
    if (!aliasId || aliasActionLoading.value) return;

    if (!window.confirm(`Remove alias ${String(alias?.domain || aliasId)}?`)) {
        return;
    }

    aliasActionLoading.value = `remove:${aliasId}`;

    try {
        router.delete(panelRoute('websites.destroy', { id: aliasId }), {
            preserveScroll: true,
            onSuccess: () => {
                pushToast('Alias removed successfully.', 'success');
                router.reload({ only: ['aliasWebsites', 'metrics', 'activities'], preserveScroll: true });
            },
            onError: () => {
                pushToast('Alias remove failed.', 'error');
            },
            onFinish: () => {
                aliasActionLoading.value = '';
            },
        });
    } catch (error) {
        aliasActionLoading.value = '';
        pushToast(error?.message || 'Alias remove failed.', 'error');
    }
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
                class="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                <!-- Background decorations -->
                <div
                    class="pointer-events-none absolute -right-20 -top-20 h-60 w-60 rounded-full bg-gradient-to-br from-blue-400/10 to-indigo-400/10 blur-3xl dark:from-blue-500/5 dark:to-indigo-500/5">
                </div>
                <div
                    class="pointer-events-none absolute -left-16 bottom-0 h-40 w-40 rounded-full bg-gradient-to-tr from-cyan-400/10 to-blue-400/10 blur-3xl dark:from-cyan-500/5 dark:to-blue-500/5">
                </div>

                <div class="relative p-6 lg:p-8">
                    <div class="grid gap-6 lg:grid-cols-[1.5fr_1fr]">
                        <!-- Left: Website Info -->
                        <div class="space-y-5">
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
                            </div>
                        </div>

                        <!-- Right: Quick Actions -->
                        <div>
                            <p
                                class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                Quick Actions</p>
                            <div class="mt-3 space-y-2">
                                <Link v-for="action in quickActions.filter((item) => !item.action)" :key="action.label"
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
                        </div>
                    </div>
                </div>
            </section>

            <!-- Metrics Cards -->




            <!-- Services + Activity -->
            <section class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(340px,1fr)]">
                <div class="min-w-0 space-y-4">

                    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
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
                    <section class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
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
                        class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                        <div class="flex items-center justify-between">
                            <h2
                                class="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Services</h2>
                            <span
                                class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{
                                    serviceLinks.length }} tools</span>
                        </div>
                        <div class="mt-4 grid gap-2.5 sm:grid-cols-2 xl:grid-cols-3">
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

                <div class="min-w-0 space-y-4">

                    <section
                        class="w-full overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50 ">
                        <div
                            class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Alias Websites</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Add alias domains for {{
                                    website.domain }}
                                    without leaving this screen.</p>
                            </div>
                        </div>
                        <form
                            class="grid gap-3 border-b border-slate-200 px-5 py-4 md:grid-cols-[minmax(0,1fr)_auto] dark:border-slate-800"
                            @submit.prevent="submitAlias">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Alias
                                    domain</label>
                                <input v-model.trim="aliasForm.domain" type="text" placeholder="alias.example.com"
                                    class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:focus:border-cyan-500 dark:focus:ring-cyan-500/20" />
                                <p v-if="aliasForm.errors.domain" class="mt-1 text-xs text-red-600">{{
                                    aliasForm.errors.domain
                                }}</p>
                            </div>
                            <div class="flex items-end">
                                <button type="submit" :disabled="aliasSubmitting || !aliasParentDomain"
                                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-cyan-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-60 md:w-auto">
                                    {{ aliasSubmitting ? 'Creating...' : 'Create Alias' }}
                                </button>
                            </div>
                        </form>
                        <div v-if="aliasWebsites.length" class="space-y-3 p-5">
                            <div v-for="(alias, index) in aliasWebsites" :key="alias.id"
                                class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-800/60 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <span class="text-xs font-semibold text-slate-400">{{ index + 1 }}</span>
                                    <div class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{
                                            alias.domain || '-' }}</span>
                                        <div v-if="aliasEditingId !== alias.id" class="mt-1">
                                            <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] font-medium"
                                                :class="aliasSslValidityClass(alias)"
                                                :title="alias.ssl_expires_at ? `Expires ${formatCertificateDate(alias.ssl_expires_at)}` : 'Certificate expiry is unavailable'">
                                                <svg viewBox="0 0 24 24" class="h-3 w-3 fill-current"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" /></svg>
                                                <span>SSL</span>
                                                <span class="opacity-40">|</span>
                                                <span>{{ aliasSslValidityLabel(alias) }}</span>
                                            </span>
                                        </div>
                                        <div v-else class="mt-1">
                                            <label class="sr-only" :for="`alias-domain-${alias.id}`">Alias domain</label>
                                            <input
                                                :id="`alias-domain-${alias.id}`"
                                                v-model.trim="aliasEditForm.domain"
                                                type="text"
                                                class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-700 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:focus:border-cyan-500 dark:focus:ring-cyan-500/20"
                                            />
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2 sm:justify-end">
                                    <button
                                        v-if="aliasEditingId !== alias.id"
                                        type="button"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
                                        aria-label="Edit alias"
                                        title="Edit"
                                        @click="startAliasEdit(alias)"
                                    >
                                        <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                                            <path d="M3 17.25V21h3.75L17.8 9.95l-3.75-3.75L3 17.25zm18-11.5a1 1 0 0 0 0-1.4l-1.6-1.6a1 1 0 0 0-1.4 0l-1.4 1.4 3.75 3.75 1.65-1.55z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400 dark:hover:bg-emerald-500/20"
                                        :disabled="aliasActionLoading === `edit:${alias.id}`"
                                        aria-label="Save alias"
                                        title="Save"
                                        @click="saveAliasEdit(alias)"
                                    >
                                        <svg v-if="aliasActionLoading !== `edit:${alias.id}`" viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                                            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zM12 19a3 3 0 1 1 0-6 3 3 0 0 1 0 6zm3-10H5V5h10v4z" />
                                        </svg>
                                        <svg v-else viewBox="0 0 24 24" class="h-4 w-4 animate-spin fill-current">
                                            <path d="M12 4a8 8 0 1 0 7.45 5H17l3.5-3.5L24 9h-2.55A10 10 0 1 1 12 2v2zm1 4h-2v5l4.25 2.52 1-1.72L13 11.9V8z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="aliasEditingId === alias.id"
                                        type="button"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
                                        aria-label="Cancel edit"
                                        title="Cancel"
                                        @click="cancelAliasEdit()"
                                    >
                                        <svg viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                                            <path d="M12 10.586 6.707 5.293 5.293 6.707 10.586 12l-5.293 5.293 1.414 1.414L12 13.414l5.293 5.293 1.414-1.414L13.414 12l5.293-5.293-1.414-1.414L12 10.586z" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="aliasActionLoading === `ssl:${alias.id}`"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400 dark:hover:bg-emerald-500/20"
                                        aria-label="Sync SSL"
                                        title="SSL Sync"
                                        @click="runAliasAction(alias, 'ssl')"
                                    >
                                        <svg v-if="aliasActionLoading !== `ssl:${alias.id}`" viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                                            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 7a2 2 0 1 1 0 4 2 2 0 0 1 0-4zm-1 5h2v5h-2v-5z" />
                                        </svg>
                                        <svg v-else viewBox="0 0 24 24" class="h-4 w-4 animate-spin fill-current">
                                            <path d="M12 4a8 8 0 1 0 7.45 5H17l3.5-3.5L24 9h-2.55A10 10 0 1 1 12 2v2zm1 4h-2v5l4.25 2.52 1-1.72L13 11.9V8z" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="aliasActionLoading === `remove:${alias.id}`"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-red-200 bg-red-50 text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20"
                                        aria-label="Remove alias"
                                        title="Remove"
                                        @click="removeAlias(alias)"
                                    >
                                        <svg v-if="aliasActionLoading !== `remove:${alias.id}`" viewBox="0 0 24 24" class="h-4 w-4 fill-current">
                                            <path d="M9 3.75V5H4.5v2H19V5H14.5V3.75A1.75 1.75 0 0 0 12.75 2h-1.5A1.75 1.75 0 0 0 9.5 3.75V5h-1V3.75A1.75 1.75 0 0 0 6.75 2h-.5A1.75 1.75 0 0 0 4.5 3.75V5H3v2h18V5h-1.5V3.75A1.75 1.75 0 0 0 17.75 2h-.5A1.75 1.75 0 0 0 15.5 3.75V5h-1V3.75A1.75 1.75 0 0 0 12.75 2h-1.5A1.75 1.75 0 0 0 9.5 3.75zM5 9l1 11h12l1-11H5zm4 2h2v7H9v-7zm4 0h2v7h-2v-7z" />
                                        </svg>
                                        <svg v-else viewBox="0 0 24 24" class="h-4 w-4 animate-spin fill-current">
                                            <path d="M12 2v4l3-3-3-1z" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-else class="px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                            No alias websites yet.
                        </div>
                    </section>
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

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900/50">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div><h2 class="font-semibold">Alias API</h2><p class="mt-1 text-sm text-slate-500">Securely verify, add, issue SSL, or revoke alias domains through API.</p></div>
                    <Link :href="panelRoute('websites.alias-api.index', { id: website.id })" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Manage Alias API</Link>
                </div>
            </section>

            <Teleport to="body">
            <div v-if="aliasApiOpen" class="fixed inset-0 z-[100] flex justify-end">
                <button class="absolute inset-0 bg-slate-950/60" aria-label="Close" @click="aliasApiOpen = false"></button>
                <aside class="relative flex h-dvh w-full flex-col overflow-hidden bg-white shadow-2xl dark:bg-slate-950 lg:w-[70vw] lg:max-w-none">
                    <div class="z-10 flex shrink-0 items-center justify-between border-b border-slate-200 bg-white px-5 py-3 dark:border-slate-800 dark:bg-slate-950 sm:px-6">
                        <div><h2 class="text-lg font-semibold leading-tight">Alias API</h2><p class="text-xs text-slate-500">Admin/reseller access only</p></div>
                        <button class="rounded border px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-900" @click="aliasApiOpen = false">Close</button>
                    </div>
                    <div v-if="aliasApiLoading" class="flex flex-1 items-center justify-center text-sm text-slate-500">Loading…</div>
                    <div v-else class="flex-1 space-y-4 overflow-y-auto p-4 sm:p-6">
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 dark:border-slate-800">
                            <div><p class="text-sm">Status: <strong>{{ aliasApi.enabled ? 'Enabled' : 'Disabled' }}</strong></p><p v-if="aliasApi.token_hint" class="mt-0.5 text-xs text-slate-500">Token ending: {{ aliasApi.token_hint }}</p></div>
                            <div class="flex gap-2"><button class="rounded bg-indigo-600 px-3 py-2 text-sm text-white" @click="rotateAliasApi">{{ aliasApi.has_token ? 'Rotate token' : 'Create token' }}</button><button v-if="aliasApi.has_token" class="rounded border px-3 py-2 text-sm dark:border-slate-700" @click="toggleAliasApi">{{ aliasApi.enabled ? 'Disable' : 'Enable' }}</button></div>
                        </div>
                        <div v-if="aliasApiPlainToken" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"><strong>Copy now — shown once:</strong><code class="mt-2 block break-all">{{ aliasApiPlainToken }}</code></div>
                        <div class="grid gap-4 xl:grid-cols-2">
                            <div class="rounded-lg border p-4 text-sm dark:border-slate-800"><h3 class="font-semibold">API endpoint &amp; authentication</h3><code class="mt-2 block break-all rounded bg-slate-100 p-2 text-xs dark:bg-slate-900">POST {{ aliasApi.endpoint }}</code><p class="mt-3 text-xs text-slate-500">Send JSON and the token on every request:</p><pre class="mt-2 overflow-x-auto rounded bg-slate-950 p-3 text-xs leading-6 text-slate-100">Authorization: Bearer YOUR_TOKEN
Content-Type: application/json
Accept: application/json</pre></div>
                            <div class="rounded-lg border p-4 text-sm dark:border-slate-800"><h3 class="font-semibold">Safe execution flow</h3><ol class="mt-2 list-decimal space-y-1.5 pl-5 text-xs text-slate-600 dark:text-slate-300"><li>Authenticate the website-scoped token.</li><li>Validate action and domain syntax.</li><li>Confirm DNS IP match or HTTP challenge.</li><li>Create alias only after verification passes.</li><li>Issue SSL; rollback alias if SSL fails.</li></ol></div>
                        </div>
                        <div class="rounded-lg border p-4 text-sm dark:border-slate-800"><h3 class="font-semibold">Domain verification</h3><p class="mt-2">No verification mode is required in the request. The API first compares the alias IP with the parent website IP. If they do not match, it checks the HTTP challenge.</p><div class="mt-3 grid gap-2 lg:grid-cols-[1fr_1fr]"><code class="block break-all rounded bg-slate-100 p-2 text-xs dark:bg-slate-900">http://ALIAS/.well-known/dpanel-alias/{{ aliasApi.challenge_token }}</code><code class="block break-all rounded bg-slate-100 p-2 text-xs dark:bg-slate-900">Response body: {{ aliasApi.challenge_token }}</code></div><p class="mt-2 text-xs text-slate-500">The response must be HTTP 200 with the exact token and no redirect. Failed verification never creates an alias.</p></div>
                        <div class="rounded-lg border p-4 text-sm dark:border-slate-800"><h3 class="font-semibold">1. Add and verify</h3><p class="mt-2 text-xs text-slate-500">The API automatically checks matching server IP first, then HTTP challenge. Nothing is added unless one succeeds.</p><pre class="mt-2 overflow-x-auto rounded bg-slate-950 p-3 text-xs text-slate-100">curl -X POST {{ aliasApi.endpoint }} \
-H "Authorization: Bearer YOUR_TOKEN" \
-H "Content-Type: application/json" \
-d '{"action":"add","domain":"alias.example.com"}'</pre></div>
                        <div class="rounded-lg border p-4 text-sm dark:border-slate-800"><h3 class="font-semibold">2. Remove alias</h3><pre class="mt-2 overflow-x-auto rounded bg-slate-950 p-3 text-xs text-slate-100">{"action":"remove","domain":"alias.example.com"}</pre><p class="mt-2 text-xs text-slate-500">Only an alias scoped to this website can be removed.</p></div>
                        <div class="rounded-lg border p-4 text-sm dark:border-slate-800"><h3 class="font-semibold">3. Paginated alias list</h3><pre class="mt-2 overflow-x-auto rounded bg-slate-950 p-3 text-xs text-slate-100">{"action":"list","page":1,"per_page":25}</pre><p class="mt-2 text-xs text-slate-500">Every list is paginated. Default 25, maximum 100. Each item includes SSL status and expiry; JSON metadata includes total, last page and has-more.</p></div>
                        <div class="grid gap-4 xl:grid-cols-2">
                            <div class="rounded-lg border p-4 text-sm dark:border-slate-800"><h3 class="font-semibold">List response shape</h3><pre class="mt-2 overflow-x-auto rounded bg-slate-950 p-3 text-xs leading-5 text-slate-100">{
  "success": true,
  "data": [{
    "id": "...",
    "domain": "alias.example.com",
    "ssl_status": "valid",
    "ssl_expires_at": "..."
  }],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 5000,
    "last_page": 200,
    "has_more": true
  }
}</pre></div>
                            <div class="rounded-lg border p-4 text-sm dark:border-slate-800"><h3 class="font-semibold">Pagination pattern</h3><p class="mt-2 text-xs text-slate-500">Start at page 1 and request the next page only while <code>meta.has_more</code> is true. Keep <code>per_page</code> at 25–100; never assume all aliases fit in one response.</p><h3 class="mt-4 font-semibold">HTTP status codes</h3><dl class="mt-2 grid grid-cols-[4rem_1fr] gap-1.5 text-xs"><dt>200</dt><dd>List/remove succeeded</dd><dt>201</dt><dd>Alias and SSL created</dd><dt>401</dt><dd>Invalid or disabled token</dd><dt>404</dt><dd>Scoped alias not found</dd><dt>422</dt><dd>Validation, reachability, or SSL failure</dd><dt>429</dt><dd>Rate limit exceeded</dd></dl></div>
                        </div>
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100"><strong>Operational rules:</strong> store the token as a secret, rotate it if exposed, use HTTPS for API calls, do not retry 422 requests without fixing DNS/challenge, and use list pagination to confirm the final SSL state.</div>
                    </div>
                </aside>
            </div>
            </Teleport>
        </div>
    </AuthenticatedLayout>
</template>
