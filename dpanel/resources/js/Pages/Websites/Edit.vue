<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    websiteRequest: {
        type: Object,
        required: true,
    },
    serverBaseDir: {
        type: String,
        default: '/home',
    },
    defaultPhpVersion: {
        type: String,
        default: '',
    },
    phpVersions: {
        type: Array,
        default: () => ['8.0', '7.4'],
    },
    wordpressVersions: {
        type: Array,
        default: () => ['latest'],
    },
});

const form = useForm({
    domain: props.websiteRequest.domain ?? '',
    root_path: props.websiteRequest.root_path ?? '',
    start_directory: props.websiteRequest.start_directory || '',
    php_version: props.websiteRequest.php_version ?? props.defaultPhpVersion ?? '',
    enable_ssl: !!props.websiteRequest.enable_ssl,
});
const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const normalizedDomain = computed(() => form.domain.trim().toLowerCase());
const normalizedBaseDir = computed(() => String(props.serverBaseDir || '/home').replace(/\\/g, '/').replace(/\/+$/, ''));
const saving = ref(false);
const saveMessage = ref('');
const saveAlertType = ref('success');
const firstFormError = computed(() => Object.values(form.errors || {}).find((value) => String(value || '').trim() !== '') || '');
const normalizedStartDirectory = computed(() => {
    const value = String(form.start_directory || '').trim();
    return value === '' ? '' : value;
});
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

const deriveRootPath = (domain) => {
    const normalized = String(domain || '').trim().toLowerCase();
    if (!normalized) return '';

    const owner = deriveOwnerFromDomain(normalized);

    return `${normalizedBaseDir.value}/${owner}/public_html`;
};

const suggestedRootPath = computed(() => deriveRootPath(normalizedDomain.value));
const availablePhpVersions = computed(() => {
    const list = Array.isArray(props.phpVersions) ? props.phpVersions : [];
    const normalized = list
        .map((version) => String(version || '').trim())
        .filter((version) => /^\d+\.\d+$/.test(version));

    return normalized.length > 0 ? normalized : ['8.0', '7.4'];
});

watch(
    availablePhpVersions,
    (versions) => {
        if (!versions.includes(form.php_version)) {
            form.php_version = props.defaultPhpVersion && versions.includes(props.defaultPhpVersion)
                ? props.defaultPhpVersion
                : (versions[0] || '');
        }
    },
    { immediate: true },
);

watch(
    normalizedDomain,
    (domain) => {
        form.root_path = deriveRootPath(domain);
    },
    { immediate: true },
);

const submit = () => {
    form.root_path = deriveRootPath(normalizedDomain.value);
    saveMessage.value = '';
    saveAlertType.value = 'success';
    form.clearErrors();
    saving.value = true;

    window.axios.patch(panelRoute('websites.update', { id: props.websiteRequest.id }), {
        domain: form.domain,
        root_path: form.root_path,
        start_directory: normalizedStartDirectory.value,
        php_version: form.php_version,
        enable_ssl: form.enable_ssl,
        }, {
            headers: {
                Accept: 'application/json',
            },
        })
        .then((response) => {
            saveAlertType.value = 'success';
            saveMessage.value = String(response?.data?.message || 'Saved successfully.');
        })
        .catch((error) => {
            if (error?.response?.status === 422 && error.response.data?.errors) {
                Object.entries(error.response.data.errors).forEach(([key, messages]) => {
                    form.setError(key, Array.isArray(messages) ? String(messages[0] || '') : String(messages || ''));
                });
                saveAlertType.value = 'error';
                saveMessage.value = String(error?.response?.data?.message || 'Please fix the highlighted errors.');
                return;
            }

            saveAlertType.value = 'error';
            saveMessage.value = String(error?.response?.data?.message || 'Save failed.');
        })
        .finally(() => {
            saving.value = false;
        });
};
</script>

<template>
    <Head title="Edit Website Request" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Edit Website Request</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Base directory: <strong>{{ normalizedBaseDir }}</strong>. Laravel sites can use <code>public</code> as the start directory.
                </p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="saveMessage" class="flex items-start gap-3 rounded-xl border px-4 py-3 text-sm"
                 :class="saveAlertType === 'success'
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
                    : 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400'">
                <svg v-if="saveAlertType === 'success'" viewBox="0 0 24 24" class="mt-0.5 h-5 w-5 shrink-0 fill-current">
                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" />
                </svg>
                <svg v-else viewBox="0 0 24 24" class="mt-0.5 h-5 w-5 shrink-0 fill-current">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                </svg>
                <span>{{ saveMessage }}</span>
            </div>
            <div v-else-if="firstFormError" class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400">
                <svg viewBox="0 0 24 24" class="mt-0.5 h-5 w-5 shrink-0 fill-current">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
                </svg>
                <span>{{ firstFormError }}</span>
            </div>
            <div class="flex justify-end">
                <Link :href="panelRoute('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to List
                </Link>
                <Link :href="panelRoute('websites.manage', { id: websiteRequest.id })" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to Manage
                </Link>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm">Domain</label>
                    <input v-model="form.domain" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
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
                            {{ version }}
                        </option>
                    </select>
                </div>
                <div class="flex items-center gap-2 pt-7">
                    <input id="enable_ssl" v-model="form.enable_ssl" type="checkbox" class="rounded border-slate-300" />
                    <label for="enable_ssl" class="text-sm">Enable SSL</label>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="saving" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        {{ saving ? 'Saving...' : 'Update Request' }}
                    </button>
                    <p v-if="saveMessage" class="mt-2 text-sm text-emerald-600">{{ saveMessage }}</p>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
