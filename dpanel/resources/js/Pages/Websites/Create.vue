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
        default: () => ['latest', '8.0', '7.4'],
    },
    defaultPhpVersion: {
        type: String,
        default: 'latest',
    },
    wordpressVersions: {
        type: Array,
        default: () => ['latest'],
    },
});

const form = useForm({
    domain_type: 'main',
    domain: '',
    subdomain_prefix: '',
    parent_domain: '',
    start_directory: 'public',
    root_path: '',
    php_version: props.defaultPhpVersion || '',
    app_installer: 'none',
    wordpress_version: 'latest',
    enable_ssl: true,
});
const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const parentDomainSearch = ref('');
const parentDomainOptions = ref([]);
const parentDomainLoading = ref(false);
const parentDomainOpen = ref(false);
let parentDomainTimer = null;

const normalizedDomain = computed(() => form.domain.trim().toLowerCase());
const normalizedParentDomain = computed(() => form.parent_domain.trim().toLowerCase());
const normalizedSubdomainPrefix = computed(() => {
    const normalized = form.subdomain_prefix
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return normalized.slice(0, 63);
});
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
    if (form.domain_type === 'subdomain') {
        if (!normalizedSubdomainPrefix.value || !normalizedParentDomain.value) {
            return '';
        }

        return `${normalizedSubdomainPrefix.value}.${normalizedParentDomain.value}`;
    }

    return normalizedDomain.value;
});

const deriveRootPath = () => {
    const domain = finalDomain.value;
    if (!domain) return '';

    const domainOwner = deriveOwnerFromDomain(domain);
    if (!domainOwner) return '';

    if (form.domain_type === 'subdomain') {
        const parentOwner = deriveOwnerFromDomain(normalizedParentDomain.value) || domainOwner;
        const subDir = normalizedSubdomainPrefix.value || 'subdomain';
        return `${normalizedBaseDir.value}/${parentOwner}/public_html/${sanitizeDir(subDir, 'subdomain')}`;
    }

    return `${normalizedBaseDir.value}/${domainOwner}/public_html`;
};

const suggestedRootPath = computed(() => deriveRootPath());
const availablePhpVersions = computed(() => {
    const list = Array.isArray(props.phpVersions) ? props.phpVersions : [];
    const normalized = list
        .map((version) => String(version || '').trim())
        .filter((version) => version === 'latest' || /^\d+\.\d+$/.test(version));

    return normalized.length > 0 ? normalized : ['latest', '8.0', '7.4'];
});
const availableWordPressVersions = computed(() => {
    const list = Array.isArray(props.wordpressVersions) ? props.wordpressVersions : [];
    const normalized = list
        .map((version) => String(version || '').trim().toLowerCase())
        .filter((version) => version === 'latest' || /^\d+\.\d+(\.\d+)?$/.test(version));
    const deduped = Array.from(new Set(['latest', ...normalized]));

    return deduped;
});

watch([finalDomain, () => form.domain_type, normalizedParentDomain], () => {
    form.root_path = deriveRootPath();
}, { immediate: true });

watch(
    () => form.domain_type,
    async (type) => {
        if (type === 'main') {
            parentDomainOpen.value = false;
            return;
        }

        await fetchParentDomains(parentDomainSearch.value);
    },
);

watch(
    parentDomainSearch,
    (value) => {
        form.parent_domain = value;
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
watch(
    availableWordPressVersions,
    (versions) => {
        if (!form.wordpress_version || !versions.includes(form.wordpress_version)) {
            form.wordpress_version = 'latest';
        }
    },
    { immediate: true },
);

const submit = () => {
    form.domain = finalDomain.value;
    form.root_path = deriveRootPath();
    form.post(panelRoute('websites.store'));
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
            .map((item) => String(item?.domain || '').trim().toLowerCase())
            .filter((domain) => domain.length > 0)
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

const selectParentDomain = (domain) => {
    parentDomainSearch.value = domain;
    form.parent_domain = domain;
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

            <div v-if="form.errors.error" class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400">
                <svg viewBox="0 0 24 24" class="mt-0.5 h-5 w-5 shrink-0 fill-current">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                </svg>
                <span>{{ form.errors.error }}</span>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm">Domain Type</label>
                    <select v-model="form.domain_type" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="main">Main Domain</option>
                        <option value="subdomain">Subdomain</option>
                        <option value="addon">Addon Domain</option>
                    </select>
                </div>
                   <div v-if="form.domain_type !== 'main'">
                    <label class="mb-1 block text-sm">Parent/Main Domain</label>
                    <div class="relative">
                        <input
                            v-model="parentDomainSearch"
                            type="text"
                            placeholder="Search parent domain..."
                            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                            @focus="openParentDomainSearch"
                            @blur="closeParentDomainSearch"
                        />
                        <div v-if="parentDomainOpen" class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-md border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                            <div v-if="parentDomainLoading" class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">
                                Loading...
                            </div>
                            <button
                                v-for="domain in parentDomainOptions"
                                :key="domain"
                                type="button"
                                class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800"
                                @mousedown.prevent="selectParentDomain(domain)"
                            >
                                {{ domain }}
                            </button>
                            <div v-if="!parentDomainLoading && parentDomainOptions.length === 0" class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">
                                No domain found.
                            </div>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Searchable AJAX list (max 10). You can also type manually.
                    </p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">{{ form.domain_type === 'subdomain' ? 'Subdomain Prefix' : 'Domain' }}</label>
                    <input
                        v-if="form.domain_type === 'subdomain'"
                        v-model="form.subdomain_prefix"
                        type="text"
                        placeholder="blog"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <input
                        v-else
                        v-model="form.domain"
                        type="text"
                        :placeholder="form.domain_type === 'addon' ? 'addon-example.com' : 'example.com'"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <p v-if="form.errors.domain" class="mt-1 text-xs text-red-600">{{ form.errors.domain }}</p>
                </div>

                <div>
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
                <div>
                    <label class="mb-1 block text-sm">Resolved Root Path</label>
                    <input :value="suggestedRootPath || `${normalizedBaseDir}/<auto>/public_html`" readonly type="text" class="w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.root_path" class="mt-1 text-xs text-red-600">{{ form.errors.root_path }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">PHP Version</label>
                    <select v-model="form.php_version" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="version in availablePhpVersions" :key="version" :value="version">
                            {{ version === 'latest' ? 'Latest Stable' : version }}
                        </option>
                    </select>
                    <p v-if="form.errors.php_version" class="mt-1 text-xs text-red-600">{{ form.errors.php_version }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">App Installer</label>
                    <select v-model="form.app_installer" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="none">Starter Files (Default)</option>
                        <option value="wordpress">WordPress</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Install WordPress automatically after site creation.</p>
                    <p v-if="form.errors.app_installer" class="mt-1 text-xs text-red-600">{{ form.errors.app_installer }}</p>
                </div>
                <div v-if="form.app_installer === 'wordpress'">
                    <label class="mb-1 block text-sm">WordPress Version</label>
                    <select v-model="form.wordpress_version" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="version in availableWordPressVersions" :key="version" :value="version">
                            {{ version === 'latest' ? 'Latest Stable' : version }}
                        </option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Choose which WordPress version will be installed with one click.</p>
                    <p v-if="form.errors.wordpress_version" class="mt-1 text-xs text-red-600">{{ form.errors.wordpress_version }}</p>
                </div>
                <div class="flex items-center gap-2 pt-7">
                    <input id="enable_ssl" v-model="form.enable_ssl" type="checkbox" class="rounded border-slate-300" />
                    <label for="enable_ssl" class="text-sm">Enable SSL</label>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        Create Website Request
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
