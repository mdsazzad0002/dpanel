<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    wordpressVersions: {
        type: Array,
        default: () => ['latest'],
    },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const websiteState = ref({ ...props.website });
watch(
    () => props.website,
    (next) => {
        websiteState.value = { ...(next || {}) };
    },
    { deep: true, immediate: true },
);

const website = computed(() => websiteState.value || {});
const isWordPressDetected = computed(() => String(props.rootInspection?.detected_app ?? '').toLowerCase() === 'wordpress');

const normalizeVersion = (value) => {
    const normalized = String(value || 'latest').trim().toLowerCase();
    return normalized === '' ? 'latest' : normalized;
};

const normalizePrefix = (value) => {
    const normalized = String(value || '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9_]+/g, '_')
        .replace(/^_+|_+$/g, '');

    return normalized.slice(0, 32);
};

const availableWordPressVersions = computed(() => {
    const list = Array.isArray(props.wordpressVersions) ? props.wordpressVersions : [];
    const normalized = list
        .map((version) => normalizeVersion(version))
        .filter((version) => version === 'latest' || /^\d+\.\d+(\.\d+)?$/.test(version));

    return Array.from(new Set(['latest', ...normalized]));
});

const selectedWordPressVersion = ref('latest');
const suggestedDatabasePrefix = computed(() => {
    const stored = normalizePrefix(website.value?.wordpress_db_prefix || '');
    if (stored !== '') return stored;

    const domainPrefix = normalizePrefix(String(website.value?.domain || '').split('.')[0] || '');
    return domainPrefix !== '' ? domainPrefix : 'wp';
});
const databasePrefix = ref(suggestedDatabasePrefix.value);

const installBusy = ref(false);
const installFeedback = ref('');
const installFeedbackType = ref('success');

const installMessageClass = computed(() => (
    installFeedbackType.value === 'success'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
        : 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400'
));

const installWordPress = async () => {
    if (installBusy.value) return;

    const prefix = normalizePrefix(databasePrefix.value) || suggestedDatabasePrefix.value;
    const version = normalizeVersion(selectedWordPressVersion.value);

    installBusy.value = true;
    installFeedback.value = '';

    try {
        const response = await window.axios.post(
            panelRoute('websites.wordpress.install', { id: website.value.id }),
            {
                wordpress_version: version,
                database_prefix: prefix,
                return_to: 'wordpress',
            },
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        const payload = response?.data || {};
        if (payload.website) {
            websiteState.value = { ...payload.website };
            databasePrefix.value = normalizePrefix(payload.website.wordpress_db_prefix || prefix) || prefix;
        }

        if (payload.database_request) {
            websiteState.value = {
                ...websiteState.value,
                wordpress_db_prefix: normalizePrefix(payload.website?.wordpress_db_prefix || prefix) || prefix,
            };
        }

        installFeedbackType.value = 'success';
        installFeedback.value = payload.message || 'WordPress installed and configured successfully.';
    } catch (error) {
        installFeedbackType.value = 'error';
        installFeedback.value = error?.response?.data?.message
            || error?.response?.data?.error
            || 'WordPress installation failed.';
    } finally {
        installBusy.value = false;
    }
};
</script>

<template>
    <Head title="WordPress Installer" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">WordPress Installer</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Install WordPress with automatic database provisioning for {{ website.domain || '-' }}.
                    </p>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>
            <div v-if="installFeedback" class="rounded-md border px-4 py-3 text-sm" :class="installMessageClass">
                {{ installFeedback }}
            </div>

            <div class="flex justify-end">
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    <i class="bi bi-arrow-left mr-2"></i> Back to Manage
                </Link>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Domain</p>
                        <p class="mt-1 break-all text-sm font-semibold">{{ website.domain || '-' }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Root Path</p>
                        <p class="mt-1 break-all text-sm font-semibold">{{ website.root_path || '-' }}</p>
                    </div>
                </div>

                <div class="mt-3">
                    <span
                        class="rounded-full border px-3 py-1 text-xs font-medium"
                        :class="isWordPressDetected
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                            : 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'"
                    >
                        {{ isWordPressDetected ? 'WordPress detected' : 'Not Installed' }}
                    </span>
                </div>

                <div class="mt-5 grid gap-4 lg:grid-cols-[1fr_1fr_auto] lg:items-end">
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Database Prefix</label>
                        <input
                            v-model="databasePrefix"
                            type="text"
                            maxlength="32"
                            spellcheck="false"
                            placeholder="client"
                            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                        />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Used for the DB name, DB user, and WordPress table prefix. Example: `client` becomes `client_`.
                        </p>
                    </div>

                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">WordPress Version</label>
                        <select
                            v-model="selectedWordPressVersion"
                            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                        >
                            <option v-for="version in availableWordPressVersions" :key="version" :value="version">
                                {{ version === 'latest' ? 'Latest Stable' : version }}
                            </option>
                        </select>
                    </div>

                    <button
                        type="button"
                        class="rounded-md border px-4 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="installBusy || normalizePrefix(databasePrefix) === ''"
                        :class="installBusy
                            ? 'border-slate-300 text-slate-500 dark:border-slate-700 dark:text-slate-400'
                            : 'border-blue-300 text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20'"
                        @click="installWordPress"
                    >
                        {{ installBusy
                            ? 'Applying...'
                        : (isWordPressDetected ? 'Update Configuration' : 'Install WordPress') }}
                    </button>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
