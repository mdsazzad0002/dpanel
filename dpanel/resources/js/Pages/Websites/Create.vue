<script setup>
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const { t } = useI18n();

const props = defineProps({
    serverBaseDir: {
        type: String,
        default: '/home',
    },
    phpVersions: {
        type: Array,
        default: () => [],
    },
    defaultPhpVersion: {
        type: String,
        default: '',
    },
    aliasMode: {
        type: Boolean,
        default: false,
    },
    domainUsers: {
        type: Array,
        default: () => [],
    },
    aliases: {
        type: Array,
        default: () => [],
    },
});

const domainTypeOptions = computed(() => [
    { value: 'main', label: t('websites.create.mainDomain') },
    { value: 'sub', label: t('websites.create.subdomain') },
]);

const domainUserOptions = computed(() => [
    { value: '', label: t('websites.create.unassigned') },
    ...props.domainUsers.map((user) => ({
        value: user.id,
        label: `${user.name} (${user.email})${user.package_id ? ` · ${t('websites.create.packaged')}` : ''}`,
    })),
]);

const form = useForm({
    domain_type: props.aliasMode ? 'alis' : 'main',
    domain: '',
    subdomain_prefix: '',
    parent_domain: '',
    parent_id: '',
    start_directory: 'public',
    root_path: '',
    php_version: props.defaultPhpVersion || '',
    runtime: 'php',
    enable_ssl: true,
    manage_dns: false,
    include_www: true,
    assigned_user_id: '',
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const csrfToken = computed(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const parentDomainSearch = ref('');
const parentDomainOptions = ref([]);
const parentDomainLoading = ref(false);
const selectedParentRootPath = ref('');
const selectedParentStartDirectory = ref('');
const submitMessage = ref('');
const submitMessageType = ref('success');
const submitting = ref(false);
const aliasRows = ref([...props.aliases]);
const responseJson = ref(null);
const aliasActionId = ref('');
const editingAliasId = ref('');
const editingAliasDomain = ref('');
const addAliasResponseRow = (website, ssl = {}) => {
    const id = String(website?.id || '');
    if (!id || aliasRows.value.some((item) => String(item.id) === id)) return;
    aliasRows.value.unshift({
        ...website,
        parent_domain: normalizedParentDomain.value,
        ssl_status: ssl?.status || (website?.enable_ssl ? 'enabled' : 'disabled'),
    });
};
let parentDomainTimer = null;

const normalizedDomain = computed(() => form.domain.trim().toLowerCase());
const normalizedParentDomain = computed(() => form.parent_domain.trim().toLowerCase());
const normalizedSubdomainPrefix = computed(() => String(form.subdomain_prefix || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9-]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 63));

const normalizedBaseDir = computed(() => String(props.serverBaseDir || '/home').replace(/\\/g, '/').replace(/\/+$/, ''));
const sanitizeOwner = (value) => {
    let owner = value.toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^[_-]+|[_-]+$/g, '');
    if (!owner || /^\d/.test(owner)) owner = `site_${owner || 'default'}`;
    return owner.slice(0, 32);
};

const sanitizeDir = (value, fallback = 'public_html') => {
    const dir = value.toLowerCase().replace(/[^a-z0-9._-]+/g, '_').replace(/^[._-]+|[._-]+$/g, '');
    return dir ? dir.slice(0, 64) : fallback;
};

const deriveOwnerFromDomain = (domain) => {
    const normalized = String(domain || '').trim().toLowerCase();
    if (!normalized) return '';

    return sanitizeOwner(normalized);
};

const finalDomain = computed(() => {
    if (form.domain_type === 'sub') {
        const prefix = normalizedSubdomainPrefix.value;
        const parent = normalizedParentDomain.value;

        if (!prefix || !parent) {
            return '';
        }

        return `${prefix}.${parent}`;
    }

    return normalizedDomain.value;
});

const deriveRootPath = () => {
    const domain = finalDomain.value;
    if (!domain) return '';

    if (form.domain_type === 'alis') {
        if (selectedParentRootPath.value) {
            return selectedParentRootPath.value;
        }

        if (!normalizedParentDomain.value) {
            return '';
        }

        const parentOwner = deriveOwnerFromDomain(normalizedParentDomain.value);
        if (!parentOwner) {
            return '';
        }

        return `${normalizedBaseDir.value}/${parentOwner}/public_html`;
    }

    if (form.domain_type === 'sub') {
        const parentOwner = deriveOwnerFromDomain(normalizedParentDomain.value);
        const subDir = sanitizeDir(normalizedSubdomainPrefix.value, 'blog');

        if (!parentOwner || !subDir) {
            return '';
        }

        return `${normalizedBaseDir.value}/${parentOwner}/${subDir}`;
    }

    const domainOwner = deriveOwnerFromDomain(domain);
    if (!domainOwner) return '';

    return `${normalizedBaseDir.value}/${domainOwner}/public_html`;
};

const suggestedRootPath = computed(() => deriveRootPath());

// Mirrors SslLifecycleService::RESERVED_SSL_SUFFIXES — a public CA can never
// issue for these, so offering the checkbox just sets up a guaranteed failure.
const RESERVED_SSL_SUFFIXES = ['.localhost', '.local', '.test', '.example', '.invalid'];
const isReservedSslDomain = computed(() => {
    const domain = finalDomain.value;
    if (!domain) return false;

    return domain === 'localhost' || RESERVED_SSL_SUFFIXES.some((suffix) => domain.endsWith(suffix));
});

// An IP site gets a self-signed certificate: no public CA issues for private IPs.
const isIpDomain = computed(() => /^(\d{1,3}\.){3}\d{1,3}$/.test(finalDomain.value));

watch(isReservedSslDomain, (reserved) => {
    if (reserved) {
        form.enable_ssl = false;
    }
});
// Aliases suggested alongside a main domain. Only www for now; it is served,
// covered by SSL and given a DNS record when ticked.
const suggestedAliases = computed(() => {
    const domain = finalDomain.value;
    if (form.domain_type !== 'main' || !domain || isIpDomain.value || domain.startsWith('www.')) return [];

    return [{ key: 'include_www', domain: `www.${domain}` }];
});
const effectiveStartDirectory = computed(() => {
    if (form.domain_type === 'alis') {
        return form.parent_id ? selectedParentStartDirectory.value : form.start_directory;
    }

    return form.start_directory;
});
const availablePhpVersions = computed(() => {
    const list = Array.isArray(props.phpVersions) ? props.phpVersions : [];
    const normalized = list
        .map((version) => String(version || '').trim())
        .filter((version) => /^\d+\.\d+$/.test(version));

    return normalized.length > 0 ? normalized : ['8.0', '7.4'];
});
const phpVersionOptions = computed(() => availablePhpVersions.value.map((version) => ({ value: version, label: `PHP ${version}` })));

const validateBeforeSubmit = () => {
    const errors = {};

    if (form.domain_type === 'sub') {
        if (!normalizedParentDomain.value) {
            errors.parent_domain = t('websites.create.errParent');
        }

        if (!normalizedSubdomainPrefix.value) {
            errors.subdomain_prefix = t('websites.create.errPrefix');
        }
    }

    if (form.domain_type === 'alis' && !form.parent_id) {
        errors.parent_id = t('websites.create.errTarget');
    }

    if (!finalDomain.value) {
        errors.domain = form.domain_type === 'sub'
            ? t('websites.create.errGenerated')
            : t('websites.create.errDomain');
    }

    if (!props.aliasMode && !suggestedRootPath.value) {
        errors.root_path = t('websites.create.errRoot');
    }

    Object.entries(errors).forEach(([key, message]) => {
        form.setError(key, message);
    });

    return Object.keys(errors).length === 0;
};

watch([finalDomain, () => form.domain_type, normalizedParentDomain], () => {
    form.root_path = props.aliasMode ? '' : deriveRootPath();
}, { immediate: true });

watch(
    () => form.domain_type,
    async (type) => {
        selectedParentRootPath.value = '';
        if (type !== 'sub') {
            form.subdomain_prefix = '';
        }

        if (type === 'main') {
            form.parent_domain = '';
            selectedParentStartDirectory.value = '';
            return;
        }

        await fetchParentDomains(parentDomainSearch.value);
    },
);

watch(
    parentDomainSearch,
    (value) => {
        if (form.domain_type === 'main') {
            return;
        }

        if (parentDomainTimer) {
            clearTimeout(parentDomainTimer);
        }

        parentDomainTimer = setTimeout(() => {
            fetchParentDomains(value);
        }, 250);
    },
);

watch(
    availablePhpVersions,
    (versions) => {
        if (!form.php_version || !versions.includes(form.php_version)) {
            form.php_version = props.defaultPhpVersion && versions.includes(props.defaultPhpVersion)
                ? props.defaultPhpVersion
                : versions[0];
        }
    },
    { immediate: true },
);
const submit = async () => {
    submitMessage.value = '';
    form.clearErrors();

    if (!validateBeforeSubmit()) {
        submitMessageType.value = 'error';
        submitMessage.value = t('websites.create.errFix');
        return;
    }

    form.domain = finalDomain.value;
    form.root_path = deriveRootPath();
    if (form.domain_type === 'alis') {
        form.start_directory = effectiveStartDirectory.value;
    } else if (form.domain_type === 'sub') {
        form.subdomain_prefix = normalizedSubdomainPrefix.value;
        form.parent_domain = normalizedParentDomain.value;
    }

    submitting.value = true;
    try {
        const response = await window.axios.post(panelRoute('websites.store'), form.data(), {
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = response?.data || {};
        const createdWebsiteId = String(data?.website?.id || '').trim();
        if (data?.type === 'success' && createdWebsiteId) {
            if (props.aliasMode) {
                addAliasResponseRow(data.website, data.ssl);
                responseJson.value = data;
                submitMessageType.value = 'success';
                submitMessage.value = String(data.message || t('websites.create.aliasCreated'));
                form.domain = '';
                form.parent_id = '';
                form.parent_domain = '';
                parentDomainSearch.value = '';
                selectedParentRootPath.value = '';
                selectedParentStartDirectory.value = '';
                return;
            }
            window.location.assign(panelRoute('websites.manage', { id: createdWebsiteId }));
            return;
        }
        submitMessageType.value = String(data.type || 'success');
        const dns = data?.dns || {};
        const dnsNote = dns.skipped === 'external-nameservers'
            ? ' ' + t('websites.create.dnsSkipped')
            : dns.managed
                ? ' ' + t('websites.create.dnsManaged')
                : '';
        submitMessage.value = String(data.message || t('websites.create.created')) + dnsNote;

        form.reset();
        form.enable_ssl = true;
        form.manage_dns = false;
        form.include_www = true;
    } catch (error) {
        const data = error?.response?.data || {};
        if (props.aliasMode && data?.website?.id) {
            addAliasResponseRow(data.website, data.ssl);
            responseJson.value = data;
        }
        const errors = data?.errors || {};
        const entries = Object.entries(errors);

        if (entries.length > 0) {
            entries.forEach(([key, messages]) => {
                form.setError(key, Array.isArray(messages) ? String(messages[0] || '') : String(messages || ''));
            });
        } else {
            form.setError('error', String(data?.message || error?.message || t('websites.create.failed')));
        }

        submitMessageType.value = 'error';
        submitMessage.value = String(data?.message || t('websites.create.failed'));
    } finally {
        submitting.value = false;
    }
};

const beginAliasEdit = (alias) => {
    editingAliasId.value = String(alias.id);
    editingAliasDomain.value = String(alias.domain || '');
};

const saveAliasEdit = async (alias) => {
    const domain = editingAliasDomain.value.trim().toLowerCase();
    if (!domain || aliasActionId.value) return;
    aliasActionId.value = String(alias.id);
    try {
        const response = await window.axios.patch(panelRoute('websites.alias.update', { id: alias.id }), { domain });
        Object.assign(alias, response.data.website || {}, { domain });
        responseJson.value = response.data;
        submitMessageType.value = 'success';
        submitMessage.value = response.data.message || t('websites.create.aliasUpdated');
        editingAliasId.value = '';
    } catch (error) {
        responseJson.value = error?.response?.data || null;
        submitMessageType.value = 'error';
        submitMessage.value = error?.response?.data?.message || t('websites.create.aliasUpdateFailed');
    } finally {
        aliasActionId.value = '';
    }
};

const removeAlias = async (alias) => {
    if (aliasActionId.value || !window.confirm(t('websites.create.removeAliasConfirm', { domain: alias.domain }))) return;
    aliasActionId.value = String(alias.id);
    try {
        const response = await window.axios.delete(panelRoute('websites.destroy', { id: alias.id }), { headers: { Accept: 'application/json' } });
        aliasRows.value = aliasRows.value.filter((item) => String(item.id) !== String(alias.id));
        responseJson.value = response.data;
        submitMessageType.value = 'success';
        submitMessage.value = response.data.message || t('websites.create.aliasRemoved');
    } catch (error) {
        responseJson.value = error?.response?.data || null;
        submitMessageType.value = 'error';
        submitMessage.value = error?.response?.data?.message || t('websites.create.aliasRemoveFailed');
    } finally {
        aliasActionId.value = '';
    }
};

const issueAliasSsl = async (alias) => {
    if (aliasActionId.value) return;
    aliasActionId.value = String(alias.id);
    try {
        const response = await window.axios.post(panelRoute('websites.ssl.issue', { id: alias.id }), {}, { headers: { Accept: 'application/json' } });
        alias.enable_ssl = true;
        alias.ssl_status = 'valid';
        responseJson.value = response.data;
        submitMessageType.value = 'success';
        submitMessage.value = response.data.message || t('websites.hero.sslIssued');
    } catch (error) {
        responseJson.value = error?.response?.data || null;
        submitMessageType.value = 'error';
        submitMessage.value = error?.response?.data?.message || t('websites.hero.sslIssueFailed');
    } finally {
        aliasActionId.value = '';
    }
};

const fetchParentDomains = async (search = '') => {
    if (form.domain_type === 'main') {
        return;
    }

    parentDomainLoading.value = true;
    try {
        const response = await window.axios.get(panelRoute('websites.parent-domains.search'), {
            params: {
                q: search,
                limit: 10,
            },
        });
        const items = Array.isArray(response?.data?.data) ? response.data.data : [];
        parentDomainOptions.value = items
            .map((item) => ({
                id: String(item?.id || '').trim(),
                domain: String(item?.domain || '').trim().toLowerCase(),
                root_path: String(item?.root_path || '').trim(),
                start_directory: String(item?.start_directory || '').trim(),
            }))
            .filter((item) => item.domain.length > 0 && item.id.length > 0)
            .slice(0, 10);
    } catch (error) {
        parentDomainOptions.value = [];
    } finally {
        parentDomainLoading.value = false;
    }
};

const parentDomainSelectOptions = computed(() => parentDomainOptions.value.map((item) => ({ value: item.id, label: item.domain })));

const onParentDomainSearch = (value) => {
    parentDomainSearch.value = value;
};

const onSelectParentDomain = (id) => {
    const item = parentDomainOptions.value.find((option) => option.id === id);
    if (!item) return;
    form.parent_id = id;
    parentDomainSearch.value = item.domain;
    form.parent_domain = item.domain;
    selectedParentRootPath.value = item.root_path || '';
    selectedParentStartDirectory.value = item.start_directory || '';
};

onBeforeUnmount(() => {
    if (parentDomainTimer) {
        clearTimeout(parentDomainTimer);
    }
});
</script>

<template>
    <Head :title="t('websites.create.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('websites.create.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    <i18n-t keypath="websites.create.header"><template #dir><strong>{{ normalizedBaseDir }}</strong></template><template #domain><strong>{{ finalDomain || '-' }}</strong></template></i18n-t>
                </p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="panelRoute('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    {{ t('websites.create.listLink') }}
                </Link>
            </div>

            <div v-if="submitMessage" :class="submitMessageType === 'success'
                ? 'flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
                : 'flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400'">
                <svg viewBox="0 0 24 24" class="mt-0.5 h-5 w-5 shrink-0 fill-current">
                    <path v-if="submitMessageType !== 'success'" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                    <path v-else d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14.2-4.6-4.6 1.4-1.4L11 13.4l5.2-5.2 1.4 1.4-6.6 6.6z" />
                </svg>
                <span>{{ submitMessage }}<template v-if="form.errors.account"> {{ form.errors.account }}</template></span>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div v-if="!props.aliasMode">
                    <label class="mb-1 block text-sm">{{ t('websites.create.domainType') }}</label>
                    <SearchableSelect v-model="form.domain_type" :options="domainTypeOptions" />
                </div>
                <div v-else>
                    <label class="mb-1 block text-sm">{{ t('websites.create.domainType') }}</label>
                    <div class="rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {{ t('websites.create.aliasDomain') }}
                    </div>
                </div>
                <div v-if="!props.aliasMode">
                    <label class="mb-1 block text-sm">{{ t('websites.create.domainUser') }}</label>
                    <SearchableSelect
                        v-model="form.assigned_user_id"
                        :options="domainUserOptions"
                        :placeholder="t('websites.create.unassigned')"
                        :search-placeholder="t('websites.create.searchUsers')"
                    />
                    <p class="mt-1 text-xs text-slate-500">{{ t('websites.create.quotaHelp') }}</p>
                    <p v-if="form.errors.assigned_user_id" class="mt-1 text-xs text-red-600">{{ form.errors.assigned_user_id }}</p>
                </div>
                <div v-if="form.domain_type !== 'main'">
                    <label class="mb-1 block text-sm">{{ form.domain_type === 'sub' ? t('websites.create.parentDomain') : t('websites.create.aliasTarget') }}</label>
                    <SearchableSelect
                        v-model="form.parent_id"
                        remote
                        :options="parentDomainSelectOptions"
                        :loading="parentDomainLoading"
                        :model-label="normalizedParentDomain"
                        :placeholder="form.domain_type === 'sub' ? t('websites.create.searchParent') : t('websites.create.searchTarget')"
                        :search-placeholder="form.domain_type === 'sub' ? t('websites.create.searchParent') : t('websites.create.searchTarget')"
                        @search="onParentDomainSearch"
                        @update:model-value="onSelectParentDomain"
                    />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        <span v-if="form.domain_type === 'sub'">{{ t('websites.create.parentHelp') }}</span>
                        <span v-else>{{ t('websites.create.targetHelp') }}</span>
                    </p>
                    <p v-if="form.errors.parent_domain" class="mt-1 text-xs text-red-600">{{ form.errors.parent_domain }}</p>
                    <p v-if="form.errors.parent_id" class="mt-1 text-xs text-red-600">{{ form.errors.parent_id }}</p>
                </div>
                <div v-if="form.domain_type === 'sub'">
                    <label class="mb-1 block text-sm">{{ t('websites.create.prefix') }}</label>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="form.subdomain_prefix"
                            type="text"
                            placeholder="blog"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                        />
                        <span class="shrink-0 rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            .{{ normalizedParentDomain || 'example.com' }}
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        <i18n-t keypath="websites.create.example"><template #domain><strong>blog.example.com</strong></template></i18n-t>
                    </p>
                    <p v-if="form.errors.subdomain_prefix" class="mt-1 text-xs text-red-600">{{ form.errors.subdomain_prefix }}</p>
                </div>
                <div v-else>
                    <label class="mb-1 block text-sm">{{ form.domain_type === 'alis' ? t('websites.create.aliasDomain') : t('websites.create.domain') }}</label>
                    <input
                        v-model="form.domain"
                        type="text"
                        :placeholder="form.domain_type === 'alis' ? 'alias-example.com' : 'example.com'"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <p v-if="form.errors.domain" class="mt-1 text-xs text-red-600">{{ form.errors.domain }}</p>
                </div>
                <div v-if="form.domain_type === 'sub'">
                    <label class="mb-1 block text-sm">{{ t('websites.create.generated') }}</label>
                    <input
                        :value="finalDomain || 'blog.example.com'"
                        type="text"
                        readonly
                        class="w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <p v-if="form.errors.domain" class="mt-1 text-xs text-red-600">{{ form.errors.domain }}</p>
                </div>

                <div v-if="!props.aliasMode">
                    <label class="mb-1 block text-sm">{{ t('websites.create.startDir') }}</label>
                    <input
                        v-model="form.start_directory"
                        type="text"
                        placeholder="public"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ t('websites.create.startDirHelp') }}
                    </p>
                </div>
                <div v-if="!props.aliasMode">
                    <label class="mb-1 block text-sm">{{ t('websites.create.rootPath') }}</label>
                    <input :value="suggestedRootPath || `${normalizedBaseDir}/<auto>/public_html`" readonly type="text" class="w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.root_path" class="mt-1 text-xs text-red-600">{{ form.errors.root_path }}</p>
                </div>
                <div v-if="!props.aliasMode">
                    <label class="mb-1 block text-sm">{{ t('websites.create.phpVersion') }}</label>
                    <SearchableSelect
                        v-model="form.php_version"
                        :options="phpVersionOptions"
                        :placeholder="t('websites.create.selectPhp')"
                        :search-placeholder="t('websites.create.searchPhp')"
                    />
                    <p v-if="form.errors.php_version" class="mt-1 text-xs text-red-600">{{ form.errors.php_version }}</p>
                </div>
                <p v-if="!props.aliasMode" class="text-xs text-slate-500 md:col-span-2">
                    <i18n-t keypath="websites.create.phpOnly"><template #node><a :href="panelRoute('apps.node.index')" class="text-blue-600 hover:underline">Node.js Apps</a></template><template #python><a :href="panelRoute('apps.python.index')" class="text-blue-600 hover:underline">Python Apps</a></template><template #portShare><a :href="panelRoute('rules.port-shares.index')" class="text-blue-600 hover:underline">Rules → Port Share</a></template></i18n-t>
                </p>
                <div v-if="!props.aliasMode" class="pt-7">
                    <div class="flex items-center gap-2">
                        <input id="enable_ssl" v-model="form.enable_ssl" type="checkbox" :disabled="isReservedSslDomain" class="rounded border-slate-300" />
                        <label for="enable_ssl" class="text-sm">{{ t('websites.create.enableSsl') }}</label>
                    </div>
                    <p v-if="isReservedSslDomain" class="mt-1 text-xs text-slate-500">
                        {{ t('websites.create.sslLocal') }}
                    </p>
                    <p v-else-if="isIpDomain && form.enable_ssl" class="mt-1 text-xs text-slate-500">
                        {{ t('websites.create.sslIp', { domain: finalDomain }) }}
                    </p>
                </div>
                <div v-if="!props.aliasMode && suggestedAliases.length" class="md:col-span-2">
                    <p class="mb-1 text-sm font-medium">{{ t('websites.create.suggested') }}</p>
                    <div class="flex flex-wrap gap-x-5 gap-y-2">
                        <label v-for="alias in suggestedAliases" :key="alias.key" class="flex items-center gap-2 text-sm">
                            <input v-model="form[alias.key]" type="checkbox" class="rounded border-slate-300" />
                            {{ alias.domain }}
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ t('websites.create.suggestedHelp') }}
                    </p>
                </div>
                <div v-if="!props.aliasMode" class="flex items-start gap-2 pt-2 md:col-span-2">
                    <input id="manage_dns" v-model="form.manage_dns" type="checkbox" class="mt-1 rounded border-slate-300" />
                    <label for="manage_dns" class="text-sm leading-6">
                        <span class="font-medium">{{ t('websites.create.manageDns') }}</span>
                        <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">
                            {{ t('websites.create.manageDnsHelp') }}
                        </span>
                    </label>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="submitting" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        {{ submitting ? t('websites.create.creating') : t('websites.create.submit') }}
                    </button>
                </div>
            </form>

            <section v-if="props.aliasMode" class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <h2 class="font-semibold">{{ t('websites.create.aliases') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ t('websites.create.aliasesHelp') }}</p>
                </div>
                <div v-if="aliasRows.length" class="divide-y divide-slate-200 dark:divide-slate-800">
                    <div v-for="alias in aliasRows" :key="alias.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <div v-if="editingAliasId === String(alias.id)" class="flex max-w-lg gap-2">
                                <input v-model="editingAliasDomain" type="text" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" @keyup.enter="saveAliasEdit(alias)" />
                                <button type="button" class="rounded bg-blue-600 px-3 py-2 text-sm text-white" @click="saveAliasEdit(alias)">{{ t('common.save') }}</button>
                                <button type="button" class="rounded border px-3 py-2 text-sm dark:border-slate-700" @click="editingAliasId = ''">{{ t('common.cancel') }}</button>
                            </div>
                            <template v-else>
                                <p class="truncate font-medium">{{ alias.domain }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ t('websites.create.aliasMeta', { target: alias.parent_domain || '-', ssl: alias.ssl_status || (alias.enable_ssl ? t('websites.create.enabled') : t('websites.create.disabled')) }) }}</p>
                            </template>
                        </div>
                        <div v-if="editingAliasId !== String(alias.id)" class="flex flex-wrap gap-2">
                            <button type="button" :disabled="Boolean(aliasActionId)" class="rounded border px-3 py-1.5 text-sm dark:border-slate-700" @click="beginAliasEdit(alias)">{{ t('websites.create.edit') }}</button>
                            <button type="button" :disabled="Boolean(aliasActionId)" class="rounded border border-emerald-300 px-3 py-1.5 text-sm text-emerald-700 dark:border-emerald-800 dark:text-emerald-400" @click="issueAliasSsl(alias)">{{ t('websites.list.issueSsl') }}</button>
                            <button type="button" :disabled="Boolean(aliasActionId)" class="rounded border border-red-300 px-3 py-1.5 text-sm text-red-600 dark:border-red-900" @click="removeAlias(alias)">{{ t('websites.create.remove') }}</button>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-8 text-center text-sm text-slate-500">{{ t('websites.create.noAliases') }}</div>
            </section>

            <section v-if="props.aliasMode && responseJson" class="rounded-xl border border-slate-200 bg-slate-950 p-4 text-slate-100 dark:border-slate-800">
                <h2 class="mb-2 text-sm font-semibold">{{ t('websites.create.lastJson') }}</h2>
                <pre class="overflow-x-auto text-xs leading-5">{{ JSON.stringify(responseJson, null, 2) }}</pre>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
