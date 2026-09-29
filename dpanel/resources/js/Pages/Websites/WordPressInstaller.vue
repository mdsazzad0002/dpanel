<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import WordpressSsoLogin from '@/Pages/Websites/SSOlogin/WordpressSsoLogin.vue';
import { Deferred, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import InstallerVersionPicker from '@/Components/Installer/InstallerVersionPicker.vue';
import InstallerDatabasePicker from '@/Components/Installer/InstallerDatabasePicker.vue';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    // [{ value, min_php, max_php, php_version }], "latest" first.
    wordpressVersions: {
        type: Array,
        default: () => [],
    },
    // Version already in the site root, if any (deferred).
    installedVersion: {
        type: String,
        default: null,
    },
    // { "6.8": { min_php, max_php }, ... } newest first.
    phpRanges: {
        type: Object,
        default: () => ({}),
    },
    rootInspection: {
        type: Object,
        default: () => null,
    },
    // This website's MariaDB databases — WordPress can't use PostgreSQL.
    databases: {
        type: Array,
        default: () => [],
    },
    newDatabase: {
        type: Object,
        default: () => null,
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

const versionList = computed(() => (Array.isArray(props.wordpressVersions) ? props.wordpressVersions : [])
    .filter((version) => version && typeof version === 'object'));

const selectedWordPressVersion = ref('latest');
const phpHint = (version) => {
    const range = `PHP ${version.min_php}–${version.max_php}`;
    return version.php_version ? `${range} · runs on ${version.php_version}` : `${range} · not installed`;
};
const wordpressVersionOptions = computed(() => versionList.value.map((version) => ({
    value: version.value,
    label: version.value === 'latest' ? 'Latest stable' : `WordPress ${version.value}`,
    hint: phpHint(version),
    disabled: !version.php_version,
})));

// PHP auto-fix: an existing install keeps its own version, so its range decides.
const branchRange = (version) => {
    const branch = String(version || '').split('.').slice(0, 2).join('.');
    return props.phpRanges[branch] || null;
};
const currentPhp = computed(() => String(website.value.php_version || ''));
const phpPlan = computed(() => {
    if (isWordPressDetected.value && props.installedVersion) {
        const range = branchRange(props.installedVersion);
        if (!range) return null;
        const fits = currentPhp.value
            && Number.parseFloat(currentPhp.value) >= Number.parseFloat(range.min_php)
            && Number.parseFloat(currentPhp.value) <= Number.parseFloat(range.max_php);
        return { subject: `Installed WordPress ${props.installedVersion}`, ...range, target: fits ? currentPhp.value : null, fits };
    }
    const meta = versionList.value.find((version) => version.value === selectedWordPressVersion.value);
    if (!meta) return null;
    return {
        subject: meta.value === 'latest' ? 'Latest WordPress' : `WordPress ${meta.value}`,
        min_php: meta.min_php,
        max_php: meta.max_php,
        target: meta.php_version,
        fits: meta.php_version === currentPhp.value,
    };
});
const phpBlocked = computed(() => !isWordPressDetected.value && phpPlan.value && !phpPlan.value.target);

const selectedDatabaseId = ref(props.databases[0]?.id || 'new');
const selectedDatabase = computed(() => props.databases.find((db) => db.id === selectedDatabaseId.value) || null);
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

// Ordered so the progress bar can compute "how far along" from the stage's
// index — 'queued' isn't in here since it maps to 0% (nothing has started).
const INSTALL_STAGES = ['downloading', 'creating_database', 'connecting_database', 'ready'];
const INSTALL_STAGE_LABELS = {
    queued: 'Queued…',
    downloading: 'Downloading WordPress…',
    creating_database: 'Creating database…',
    connecting_database: 'Connecting database…',
    ready: 'Done',
};

const installStage = ref('');
const installProgress = computed(() => {
    if (!installStage.value || installStage.value === 'failed') return 0;
    if (installStage.value === 'queued') return 5;

    const index = INSTALL_STAGES.indexOf(installStage.value);
    if (index === -1) return 5;

    return Math.round(((index + 1) / INSTALL_STAGES.length) * 100);
});
const installStageLabel = computed(() => INSTALL_STAGE_LABELS[installStage.value] || 'Working…');

let installPollTimer = null;
const stopInstallPoll = () => {
    window.clearInterval(installPollTimer);
    installPollTimer = null;
};
onUnmounted(stopInstallPoll);

const pollInstallStatus = async (installId, prefix) => {
    try {
        const response = await window.axios.get(
            panelRoute('websites.wordpress.install.status', { id: website.value.id, installId }),
            { headers: { Accept: 'application/json' } },
        );
        const payload = response?.data || {};
        if (!payload.success) return;

        installStage.value = payload.stage || installStage.value;

        if (payload.stage === 'ready') {
            stopInstallPoll();
            installBusy.value = false;

            if (payload.website) {
                websiteState.value = { ...websiteState.value, ...payload.website };
                databasePrefix.value = normalizePrefix(payload.website.wordpress_db_prefix || prefix) || prefix;
            }

            installFeedbackType.value = 'success';
            installFeedback.value = payload.message || 'WordPress installed and configured successfully.';
        } else if (payload.stage === 'failed') {
            stopInstallPoll();
            installBusy.value = false;
            installFeedbackType.value = 'error';
            installFeedback.value = payload.message || 'WordPress installation failed.';
        }
    } catch {
        // Network hiccup — next tick retries.
    }
};

const installWordPress = async () => {
    if (installBusy.value) return;

    const prefix = normalizePrefix(databasePrefix.value) || suggestedDatabasePrefix.value;
    const version = normalizeVersion(selectedWordPressVersion.value);

    installBusy.value = true;
    installFeedback.value = '';
    installStage.value = 'queued';

    try {
        const response = await window.axios.post(
            panelRoute('websites.wordpress.install', { id: website.value.id }),
            {
                wordpress_version: version,
                database_prefix: prefix,
                database_id: selectedDatabaseId.value,
                database_suffix: selectedDatabaseId.value === 'new' ? (props.newDatabase?.suffix || null) : null,
            },
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        const payload = response?.data || {};
        stopInstallPoll();
        installPollTimer = window.setInterval(() => pollInstallStatus(payload.install_id, prefix), 1500);
        pollInstallStatus(payload.install_id, prefix);
    } catch (error) {
        installBusy.value = false;
        installStage.value = 'failed';
        installFeedbackType.value = 'error';
        installFeedback.value = error?.response?.data?.message
            || error?.response?.data?.error
            || 'WordPress installation failed.';
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
                    <Deferred data="rootInspection">
                        <template #fallback>
                            <span class="inline-flex h-[26px] w-32 animate-pulse rounded-full border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800"></span>
                        </template>
                        <span
                            class="rounded-full border px-3 py-1 text-xs font-medium"
                            :class="isWordPressDetected
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                                : 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'"
                        >
                            {{ isWordPressDetected ? 'WordPress detected' : 'Not Installed' }}
                        </span>
                    </Deferred>
                </div>

                <div v-if="isWordPressDetected" class="mt-4">
                    <WordpressSsoLogin :website-id="website.id" />
                </div>

                <div class="mt-5">
                    <Deferred data="wordpressVersions">
                        <template #fallback>
                            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">WordPress Version</p>
                            <div class="mt-2 h-[62px] w-full animate-pulse rounded-lg border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800"></div>
                        </template>
                        <InstallerVersionPicker
                            v-model="selectedWordPressVersion"
                            label="WordPress Version"
                            name="wordpress_version"
                            :versions="wordpressVersionOptions"
                            :disabled="installBusy"
                            :max-cards="8"
                            accent="blue"
                        />
                    </Deferred>
                </div>

                <InstallerDatabasePicker
                    v-model="selectedDatabaseId"
                    class="mt-5"
                    :databases="databases"
                    :new-database="newDatabase"
                    :engines="['mariadb']"
                    existing-note="Reused as-is; WordPress tables use the prefix below"
                    password-file="wp-config.php"
                    :disabled="installBusy"
                    accent="blue"
                />

                <Deferred :data="['wordpressVersions', 'installedVersion']">
                    <template #fallback><span></span></template>
                    <div v-if="phpPlan" class="mt-5 flex items-start gap-2 rounded-lg border px-3 py-2 text-sm"
                        :class="phpPlan.fits
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400'
                            : (phpPlan.target || isWordPressDetected
                                ? 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400'
                                : 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400')">
                        <i class="bi bi-filetype-php mt-0.5"></i>
                        <span>
                            {{ phpPlan.subject }} supports <strong>PHP {{ phpPlan.min_php }}–{{ phpPlan.max_php }}</strong>.
                            <template v-if="phpPlan.fits">Current PHP <strong>{{ currentPhp }}</strong> is compatible.</template>
                            <template v-else-if="phpPlan.target">PHP will be switched automatically from <strong>{{ currentPhp || 'none' }}</strong> to <strong>{{ phpPlan.target }}</strong>.</template>
                            <template v-else-if="isWordPressDetected">Current PHP <strong>{{ currentPhp || 'none' }}</strong> is out of range; <em>Update Configuration</em> switches it to the newest compatible installed PHP.</template>
                            <template v-else>No compatible PHP is installed on this server — install one in PHP Manager or pick another version.</template>
                        </span>
                    </div>
                </Deferred>

                <div class="mt-5 grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div>
                        <label class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Table Prefix</label>
                        <input
                            v-model="databasePrefix"
                            type="text"
                            maxlength="32"
                            spellcheck="false"
                            placeholder="client"
                            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                        />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            WordPress table prefix. Example: `client` becomes `client_`.
                            <template v-if="selectedDatabase">Tables are created in <strong>{{ selectedDatabase.database_name }}</strong>; existing tables with another prefix are left alone.</template>
                        </p>
                    </div>

                    <button
                        type="button"
                        class="rounded-md border px-4 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="installBusy || normalizePrefix(databasePrefix) === '' || phpBlocked"
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

                <div v-if="installBusy" class="mt-5">
                    <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                        <span>{{ installStageLabel }}</span>
                        <span>{{ installProgress }}%</span>
                    </div>
                    <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div
                            class="h-full rounded-full bg-blue-500 transition-all duration-500 ease-out dark:bg-blue-400"
                            :style="{ width: installProgress + '%' }"
                        ></div>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
