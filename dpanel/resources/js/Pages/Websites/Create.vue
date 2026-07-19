<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

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
});

const form = useForm({
    domain_type: props.aliasMode ? 'alis' : 'main',
    domain: '',
    subdomain_prefix: '',
    parent_domain: '',
    start_directory: 'public',
    root_path: '',
    php_version: props.defaultPhpVersion || '',
    enable_ssl: true,
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
const parentDomainOpen = ref(false);
const selectedParentRootPath = ref('');
const selectedParentStartDirectory = ref('');
const submitMessage = ref('');
const submitMessageType = ref('success');
const submitting = ref(false);
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
const effectiveStartDirectory = computed(() => {
    if (form.domain_type === 'alis') {
        return selectedParentStartDirectory.value || form.start_directory || 'public';
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

const validateBeforeSubmit = () => {
    const errors = {};

    if (form.domain_type === 'sub') {
        if (!normalizedParentDomain.value) {
            errors.parent_domain = 'Parent domain is required for subdomains.';
        }

        if (!normalizedSubdomainPrefix.value) {
            errors.subdomain_prefix = 'Subdomain prefix is required.';
        }
    }

    if (!finalDomain.value) {
        errors.domain = form.domain_type === 'sub'
            ? 'Generated subdomain is required.'
            : 'Domain is required.';
    }

    if (!suggestedRootPath.value) {
        errors.root_path = 'Resolved root path is required.';
    }

    Object.entries(errors).forEach(([key, message]) => {
        form.setError(key, message);
    });

    return Object.keys(errors).length === 0;
};

watch([finalDomain, () => form.domain_type, normalizedParentDomain], () => {
    form.root_path = deriveRootPath();
}, { immediate: true });

watch(
    () => form.domain_type,
    async (type) => {
        selectedParentRootPath.value = '';
        if (type !== 'sub') {
            form.subdomain_prefix = '';
        }

        if (type === 'main') {
            parentDomainOpen.value = false;
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
        submitMessage.value = 'Please fix the highlighted validation errors.';
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
        const website = data?.website || {};
        const websiteId = String(website?.id || '').trim();

        if (websiteId) {
            try {
                const syncResponse = await fetch(panelRoute('websites.vhost.sync', { id: websiteId }), {
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
                const syncData = await syncResponse.json().catch(() => ({}));
                if (!syncResponse.ok) {
                    throw { response: { data: syncData } };
                }
                submitMessageType.value = String(syncData.type || data.type || 'success');
                submitMessage.value = `${String(data.message || 'Website created successfully.')} ${String(syncData.message || 'Live vhost synced successfully.').trim()}`.trim();
            } catch (syncError) {
                const syncData = syncError?.response?.data || {};
                submitMessageType.value = 'error';
                submitMessage.value = `${String(data.message || 'Website created successfully.')} ${String(syncData.message || 'Live vhost sync failed.').trim()}`.trim();
            }
        } else {
            submitMessageType.value = String(data.type || 'success');
            submitMessage.value = String(data.message || 'Website created successfully.');
        }

        form.reset();
    } catch (error) {
        const data = error?.response?.data || {};
        const errors = data?.errors || {};
        const entries = Object.entries(errors);

        if (entries.length > 0) {
            entries.forEach(([key, messages]) => {
                form.setError(key, Array.isArray(messages) ? String(messages[0] || '') : String(messages || ''));
            });
        } else {
            form.setError('error', String(data?.message || error?.message || 'Website creation failed.'));
        }

        submitMessageType.value = 'error';
        submitMessage.value = String(data?.message || 'Website creation failed.');
    } finally {
        submitting.value = false;
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
                domain: String(item?.domain || '').trim().toLowerCase(),
                root_path: String(item?.root_path || '').trim(),
                start_directory: String(item?.start_directory || '').trim(),
            }))
            .filter((item) => item.domain.length > 0)
            .slice(0, 10);
    } catch (error) {
        parentDomainOptions.value = [];
    } finally {
        parentDomainLoading.value = false;
    }
};

const openParentDomainSearch = async () => {
    parentDomainOpen.value = true;
    await fetchParentDomains(parentDomainSearch.value);
};

const closeParentDomainSearch = () => {
    setTimeout(() => {
        parentDomainOpen.value = false;
    }, 120);
};

const selectParentDomain = (domain, rootPath, startDirectory) => {
    parentDomainSearch.value = domain;
    form.parent_domain = domain;
    selectedParentRootPath.value = rootPath || '';
    selectedParentStartDirectory.value = startDirectory || '';
    parentDomainOpen.value = false;
};

onBeforeUnmount(() => {
    if (parentDomainTimer) {
        clearTimeout(parentDomainTimer);
    }
});
</script>

<template>
    <Head title="Create Website" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Create Website</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Base directory: <strong>{{ normalizedBaseDir }}</strong>. Final domain: <strong>{{ finalDomain || '-' }}</strong>.
                </p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="panelRoute('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    List Website Requests
                </Link>
            </div>

            <div v-if="submitMessage" :class="submitMessageType === 'success'
                ? 'flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
                : 'flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400'">
                <svg viewBox="0 0 24 24" class="mt-0.5 h-5 w-5 shrink-0 fill-current">
                    <path v-if="submitMessageType !== 'success'" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                    <path v-else d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14.2-4.6-4.6 1.4-1.4L11 13.4l5.2-5.2 1.4 1.4-6.6 6.6z" />
                </svg>
                <span>{{ submitMessage }}</span>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div v-if="!props.aliasMode">
                    <label class="mb-1 block text-sm">Domain Type</label>
                    <select v-model="form.domain_type" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="main">Main domain</option>
                        <option value="sub">Subdomain</option>
                        <option value="alis">Alis Domain</option>
                    </select>
                </div>
                <div v-else>
                    <label class="mb-1 block text-sm">Domain Type</label>
                    <div class="rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        Alis Domain
                    </div>
                </div>
                <div v-if="form.domain_type !== 'main'">
                    <label class="mb-1 block text-sm">{{ form.domain_type === 'sub' ? 'Parent Domain' : 'Aliased Target Domain' }}</label>
                    <div class="relative">
                        <input
                            v-model="parentDomainSearch"
                            type="text"
                            :placeholder="form.domain_type === 'sub' ? 'Search parent domain...' : 'Search target domain...'"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                            @focus="openParentDomainSearch"
                            @blur="closeParentDomainSearch"
                        />
                        <div v-if="parentDomainOpen" class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-md border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                            <div v-if="parentDomainLoading" class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">
                                Loading...
                            </div>
                            <button
                                v-for="item in parentDomainOptions"
                                :key="item.domain"
                                type="button"
                                class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800"
                                @mousedown.prevent="selectParentDomain(item.domain, item.root_path, item.start_directory)"
                            >
                                {{ item.domain }}
                            </button>
                            <div v-if="!parentDomainLoading && parentDomainOptions.length === 0" class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">
                                No domain found.
                            </div>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        <span v-if="form.domain_type === 'sub'">Searchable AJAX list (max 10). Choose the parent domain that the subdomain will be attached to.</span>
                        <span v-else>Searchable AJAX list (max 10). This is the domain the alias will point to.</span>
                    </p>
                    <p v-if="form.errors.parent_domain" class="mt-1 text-xs text-red-600">{{ form.errors.parent_domain }}</p>
                </div>
                <div v-if="form.domain_type === 'sub'">
                    <label class="mb-1 block text-sm">Subdomain Prefix</label>
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
                        Example: <strong>blog.example.com</strong>
                    </p>
                    <p v-if="form.errors.subdomain_prefix" class="mt-1 text-xs text-red-600">{{ form.errors.subdomain_prefix }}</p>
                </div>
                <div v-else>
                    <label class="mb-1 block text-sm">Domain</label>
                    <input
                        v-model="form.domain"
                        type="text"
                        :placeholder="form.domain_type === 'alis' ? 'alias-example.com' : 'example.com'"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <p v-if="form.errors.domain" class="mt-1 text-xs text-red-600">{{ form.errors.domain }}</p>
                </div>
                <div v-if="form.domain_type === 'sub'">
                    <label class="mb-1 block text-sm">Generated Domain</label>
                    <input
                        :value="finalDomain || 'blog.example.com'"
                        type="text"
                        readonly
                        class="w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <p v-if="form.errors.domain" class="mt-1 text-xs text-red-600">{{ form.errors.domain }}</p>
                </div>

                <div v-if="!props.aliasMode">
                    <label class="mb-1 block text-sm">Start Directory Alias</label>
                    <input
                        v-model="form.start_directory"
                        type="text"
                        placeholder="public"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Stored as metadata only. It does not change the actual website path.
                    </p>
                </div>
                <div v-else>
                    <label class="mb-1 block text-sm">Aliased Start Directory</label>
                    <div class="rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {{ selectedParentStartDirectory || 'public' }}
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Alias websites inherit start directory from the selected parent website.
                    </p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Resolved Root Path</label>
                    <input :value="suggestedRootPath || `${normalizedBaseDir}/<auto>/public_html`" readonly type="text" class="w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.root_path" class="mt-1 text-xs text-red-600">{{ form.errors.root_path }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">PHP Version </label>
                    <select v-model="form.php_version" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="version in availablePhpVersions" :key="version" :value="version">
                            {{ version }}
                        </option>
                    </select>
                    <p v-if="form.errors.php_version" class="mt-1 text-xs text-red-600">{{ form.errors.php_version }}</p>
                </div>
                <div class="flex items-center gap-2 pt-7">
                    <input id="enable_ssl" v-model="form.enable_ssl" type="checkbox" class="rounded border-slate-300" />
                    <label for="enable_ssl" class="text-sm">Enable SSL</label>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="submitting" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        {{ submitting ? 'Creating...' : 'Create Website Request' }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
