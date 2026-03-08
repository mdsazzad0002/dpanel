<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    websiteRequest: {
        type: Object,
        required: true,
    },
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
    domain: props.websiteRequest.domain ?? '',
    root_path: props.websiteRequest.root_path ?? '',
    php_version: props.websiteRequest.php_version ?? '8.3',
    app_installer: props.websiteRequest.app_installer ?? 'none',
    enable_ssl: !!props.websiteRequest.enable_ssl,
});

const normalizedDomain = computed(() => form.domain.trim().toLowerCase());
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

const deriveRootPath = (domain) => {
    const labels = domain.split('.').map((label) => label.trim()).filter(Boolean);
    if (labels.length < 2) return '';

    const { registrable, sub: subLabels } = splitDomainParts(labels);
    const ownerSeed = registrable.length <= 2 ? (registrable[0] || labels[0]) : registrable.join('_');
    const owner = sanitizeOwner(ownerSeed);
    const isMainDomain = subLabels.length === 0 || (subLabels.length === 1 && subLabels[0] === 'www');
    const siteDir = isMainDomain ? 'public_html' : sanitizeDir(subLabels.join('_'));

    return `${normalizedBaseDir.value}/${owner}/${siteDir}`;
};

const suggestedRootPath = computed(() => deriveRootPath(normalizedDomain.value));
const availablePhpVersions = computed(() => {
    const list = Array.isArray(props.phpVersions) ? props.phpVersions : [];
    const normalized = list
        .map((version) => String(version || '').trim())
        .filter((version) => /^\d+\.\d+$/.test(version));

    if (normalized.length === 0) {
        return ['8.4', '8.3', '8.2', '8.1', '8.0', '7.4'];
    }

    return normalized.includes(form.php_version)
        ? normalized
        : [form.php_version, ...normalized];
});

watch(
    normalizedDomain,
    (domain) => {
        form.root_path = deriveRootPath(domain);
    },
    { immediate: true },
);

const submit = () => {
    form.root_path = deriveRootPath(normalizedDomain.value);
    form.patch(route('websites.update', props.websiteRequest.id));
};
</script>

<template>
    <Head title="Edit Website Request" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Edit Website Request</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                     Main domain: <strong>{Basedir}/site/public_html</strong>. Subdomain: <strong>{BaseDir}/site/subdomain</strong>.
                </p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="route('websites.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to List
                </Link>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm">Domain</label>
                    <input v-model="form.domain" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
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
                </div>
                <div>
                    <label class="mb-1 block text-sm">App Installer</label>
                    <select v-model="form.app_installer" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="none">Starter Files (Default)</option>
                        <option value="wordpress">WordPress</option>
                    </select>
                    <p v-if="form.errors.app_installer" class="mt-1 text-xs text-red-600">{{ form.errors.app_installer }}</p>
                </div>
                <div class="flex items-center gap-2 pt-7">
                    <input id="enable_ssl" v-model="form.enable_ssl" type="checkbox" class="rounded border-slate-300" />
                    <label for="enable_ssl" class="text-sm">Enable SSL</label>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        Update Request
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
