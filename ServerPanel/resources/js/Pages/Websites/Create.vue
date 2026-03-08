<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    serverBaseDir: {
        type: String,
        default: '/home',
    },
    phpVersions: {
        type: Array,
        default: () => ['8.4', '8.3', '8.2', '8.1', '8.0', '7.4'],
    },
});

const form = useForm({
    domain_type: 'main',
    domain: '',
    subdomain_prefix: '',
    parent_domain: '',
    root_path: '',
    php_version: '',
    app_installer: 'none',
    enable_ssl: true,
});
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

const compoundPublicSuffixes = new Set([
    'com.bd',
    'net.bd',
    'org.bd',
    'edu.bd',
    'gov.bd',
    'ac.bd',
    'com.au',
    'net.au',
    'org.au',
    'co.uk',
    'org.uk',
    'gov.uk',
    'ac.uk',
    'co.jp',
    'com.sg',
    'com.my',
    'co.nz',
]);

const splitDomainParts = (labels) => {
    if (labels.length < 2) return { registrable: labels, sub: [] };

    let suffixParts = 1;
    if (labels.length >= 3) {
        const lastTwo = `${labels[labels.length - 2]}.${labels[labels.length - 1]}`.toLowerCase();
        if (compoundPublicSuffixes.has(lastTwo)) suffixParts = 2;
    }

    const registrableLength = Math.min(labels.length, suffixParts + 1);
    return {
        registrable: labels.slice(-registrableLength),
        sub: labels.slice(0, -registrableLength),
    };
};

const deriveOwnerFromDomain = (domain) => {
    const labels = domain.split('.').map((label) => label.trim()).filter(Boolean);
    if (labels.length < 2) return '';

    const { registrable } = splitDomainParts(labels);
    const ownerSeed = registrable.length <= 2 ? (registrable[0] || labels[0]) : registrable.join('_');
    return sanitizeOwner(ownerSeed);
};

const deriveAddonDirectory = (domain) => {
    const labels = domain.split('.').map((label) => label.trim()).filter(Boolean);
    if (labels.length < 2) return 'public_html';

    const { registrable } = splitDomainParts(labels);
    const addonSeed = registrable.length <= 2 ? (registrable[0] || labels[0]) : registrable.join('_');
    return sanitizeDir(addonSeed);
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
        const subDir = sanitizeDir(normalizedSubdomainPrefix.value || 'subdomain', 'subdomain');
        return `${normalizedBaseDir.value}/${parentOwner}/${subDir}`;
    }

    if (form.domain_type === 'addon') {
        const parentOwner = deriveOwnerFromDomain(normalizedParentDomain.value) || domainOwner;
        const addonDir = deriveAddonDirectory(domain);
        return `${normalizedBaseDir.value}/${parentOwner}/${addonDir}`;
    }

    return `${normalizedBaseDir.value}/${domainOwner}/public_html`;
};

const suggestedRootPath = computed(() => deriveRootPath());
const availablePhpVersions = computed(() => {
    const list = Array.isArray(props.phpVersions) ? props.phpVersions : [];
    const normalized = list
        .map((version) => String(version || '').trim())
        .filter((version) => /^\d+\.\d+$/.test(version));

    return normalized.length > 0 ? normalized : ['8.4', '8.3', '8.2', '8.1', '8.0', '7.4'];
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
            form.php_version = versions[0];
        }
    },
    { immediate: true },
);

const submit = () => {
    form.domain = finalDomain.value;
    form.root_path = deriveRootPath();
    form.post(route('websites.store'));
};

const fetchParentDomains = async (search = '') => {
    if (form.domain_type === 'main') {
        return;
    }

    parentDomainLoading.value = true;
    try {
        const response = await window.axios.get(route('websites.parent-domains.search'), {
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
                <Link :href="route('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    List Website Requests
                </Link>
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
                    <label class="mb-1 block text-sm">Root Path</label>
                    <input :value="suggestedRootPath || `${normalizedBaseDir}/<auto>/public_html`" readonly type="text" class="w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.root_path" class="mt-1 text-xs text-red-600">{{ form.errors.root_path }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">PHP Version</label>
                    <select v-model="form.php_version" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="version in availablePhpVersions" :key="version" :value="version">{{ version }}</option>
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
