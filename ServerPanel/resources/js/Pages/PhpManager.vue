<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    installedVersions: { type: Array, default: () => [] },
    defaultVersion: { type: String, default: '' },
    selectedVersion: { type: String, default: '' },
    availableExtensions: { type: Array, default: () => [] },
    extensionStates: { type: Object, default: () => ({}) },
    configValues: { type: Object, default: () => ({}) },
    configByVersion: { type: Object, default: () => ({}) },
    extensionStatesByVersion: { type: Object, default: () => ({}) },
});

const page = usePage();
const checkingInstalled = ref(false);
const checkError = ref('');
const detectedVersions = ref([]);
const syncingFromServer = ref(false);
const syncMessage = ref('');
const syncError = ref('');
const extensionOptions = ref([...props.availableExtensions]);
const extensionSyncing = ref(false);
const extensionSyncError = ref('');
const extensionServerSyncing = ref(false);
const versionSwitching = ref(false);
const versionSwitchError = ref('');
const configSaving = ref(false);
const configSaveMessage = ref('');
const configSaveError = ref('');
const applyingPayload = ref(false);
const defaultConfig = Object.freeze({
    memory_limit: '512M',
    upload_max_filesize: '2G',
    post_max_size: '2G',
    max_execution_time: 300,
    max_input_vars: 5000,
    display_errors: 'Off',
    log_errors: 'On',
    allow_url_fopen: 'On',
});

const clone = (value) => {
    try {
        return JSON.parse(JSON.stringify(value ?? {}));
    } catch (error) {
        return {};
    }
};

const normalizeConfigValues = (raw = {}) => ({
    memory_limit: String(raw.memory_limit ?? defaultConfig.memory_limit),
    upload_max_filesize: String(raw.upload_max_filesize ?? defaultConfig.upload_max_filesize),
    post_max_size: String(raw.post_max_size ?? defaultConfig.post_max_size),
    max_execution_time: Number(raw.max_execution_time ?? defaultConfig.max_execution_time),
    max_input_vars: Number(raw.max_input_vars ?? defaultConfig.max_input_vars),
    display_errors: String(raw.display_errors ?? defaultConfig.display_errors),
    log_errors: String(raw.log_errors ?? defaultConfig.log_errors),
    allow_url_fopen: String(raw.allow_url_fopen ?? defaultConfig.allow_url_fopen),
});

const configMatrix = ref(clone(props.configByVersion));
const extensionMatrix = ref(clone(props.extensionStatesByVersion));
const persistedConfigMatrix = ref(clone(props.configByVersion));
const initialSelectedVersion = props.selectedVersion || props.defaultVersion || (props.installedVersions[0] ?? '');

const versionsForm = useForm({
    installed_versions: [...props.installedVersions],
    current_version: props.defaultVersion || (props.installedVersions[0] ?? ''),
});

const extensionForm = useForm({
    version: initialSelectedVersion || versionsForm.current_version,
    extensions: extensionOptions.value.filter((extension) => props.extensionStates?.[extension]),
});

const initialConfigValues = normalizeConfigValues(props.configValues);
const configForm = useForm({
    version: initialSelectedVersion || versionsForm.current_version,
    memory_limit: initialConfigValues.memory_limit,
    upload_max_filesize: initialConfigValues.upload_max_filesize,
    post_max_size: initialConfigValues.post_max_size,
    max_execution_time: initialConfigValues.max_execution_time,
    max_input_vars: initialConfigValues.max_input_vars,
    display_errors: initialConfigValues.display_errors,
    log_errors: initialConfigValues.log_errors,
    allow_url_fopen: initialConfigValues.allow_url_fopen,
});

const checkInstalledVersions = async () => {
    checkingInstalled.value = true;
    checkError.value = '';

    try {
        const response = await window.axios.get(route('php.versions.check-installed'));
        detectedVersions.value = Array.isArray(response?.data?.installed_versions) ? response.data.installed_versions : [];
    } catch (error) {
        checkError.value = 'Failed to check installed versions from server.';
        detectedVersions.value = [];
    } finally {
        checkingInstalled.value = false;
    }
};

const loadFromServer = () => {
    syncFromServer();
};

const currentConfigValues = () => normalizeConfigValues({
    memory_limit: configForm.memory_limit,
    upload_max_filesize: configForm.upload_max_filesize,
    post_max_size: configForm.post_max_size,
    max_execution_time: configForm.max_execution_time,
    max_input_vars: configForm.max_input_vars,
    display_errors: configForm.display_errors,
    log_errors: configForm.log_errors,
    allow_url_fopen: configForm.allow_url_fopen,
});

const areConfigValuesEqual = (left, right) => (
    JSON.stringify(normalizeConfigValues(left)) === JSON.stringify(normalizeConfigValues(right))
);

const rememberVersionDraft = (version) => {
    const normalizedVersion = String(version ?? '').trim();
    if (!normalizedVersion) return;

    configMatrix.value[normalizedVersion] = currentConfigValues();

    if (extensionForm.version !== normalizedVersion) return;

    const selectedExtensions = new Set(extensionForm.extensions ?? []);
    extensionMatrix.value[normalizedVersion] = extensionOptions.value.reduce((carry, extension) => {
        carry[extension] = selectedExtensions.has(extension);
        return carry;
    }, {});
};

const applySelectedConfigToForm = (version, configValues) => {
    const normalized = normalizeConfigValues(configValues);
    applyingPayload.value = true;
    configForm.version = String(version || configForm.version || versionsForm.current_version);
    configForm.memory_limit = normalized.memory_limit;
    configForm.upload_max_filesize = normalized.upload_max_filesize;
    configForm.post_max_size = normalized.post_max_size;
    configForm.max_execution_time = normalized.max_execution_time;
    configForm.max_input_vars = normalized.max_input_vars;
    configForm.display_errors = normalized.display_errors;
    configForm.log_errors = normalized.log_errors;
    configForm.allow_url_fopen = normalized.allow_url_fopen;
    applyingPayload.value = false;
};

const applyManagerPayload = (payload = {}) => {
    const installedVersions = Array.isArray(payload.installedVersions) ? payload.installedVersions : [];
    const defaultVersion = String(payload.defaultVersion ?? installedVersions[0] ?? '');
    const selectedVersion = String(payload.selectedVersion ?? configForm.version ?? defaultVersion);
    const availableExtensions = Array.isArray(payload.availableExtensions) ? payload.availableExtensions : [];
    const extensionStates = payload.extensionStates ?? {};
    const configValues = payload.configValues ?? {};
    const allConfigs = payload.configByVersion ?? {};
    const allExtensionStates = payload.extensionStatesByVersion ?? {};

    versionsForm.installed_versions = installedVersions;
    versionsForm.current_version = defaultVersion;

    const serverConfigMatrix = clone(allConfigs);
    const serverExtensionMatrix = clone(allExtensionStates);
    persistedConfigMatrix.value = serverConfigMatrix;
    configMatrix.value = { ...serverConfigMatrix, ...configMatrix.value };
    extensionMatrix.value = { ...serverExtensionMatrix, ...extensionMatrix.value };
    extensionMatrix.value[selectedVersion] = {
        ...(extensionMatrix.value[selectedVersion] ?? {}),
        ...(extensionStates ?? {}),
    };

    extensionOptions.value = availableExtensions;
    applyingPayload.value = true;
    extensionForm.version = selectedVersion;
    extensionForm.extensions = availableExtensions.filter((extension) => Boolean(extensionMatrix.value?.[selectedVersion]?.[extension]));
    applyingPayload.value = false;

    const selectedConfigValues = configMatrix.value?.[selectedVersion] ?? configValues;
    applySelectedConfigToForm(selectedVersion, selectedConfigValues);
    configMatrix.value[selectedVersion] = normalizeConfigValues(selectedConfigValues);
};

const loadVersionPayload = async (version) => {
    if (!version) return;

    versionSwitching.value = true;
    versionSwitchError.value = '';

    try {
        const response = await window.axios.get(
            route('php.manager', { version }),
            { headers: { Accept: 'application/json' } },
        );
        const payload = response?.data?.data ?? {};
        applyManagerPayload(payload);
    } catch (error) {
        versionSwitchError.value = error?.response?.data?.message ?? `Failed to load PHP ${version} data.`;
    } finally {
        versionSwitching.value = false;
    }
};

const syncFromServer = async () => {
    syncingFromServer.value = true;
    syncError.value = '';
    syncMessage.value = '';

    try {
        const response = await window.axios.post(
            route('php.versions.refresh'),
            {},
            { headers: { Accept: 'application/json' } },
        );

        const payload = response?.data?.data ?? {};
        applyManagerPayload(payload);
        syncMessage.value = response?.data?.message ?? 'Synced from server.';
        await checkInstalledVersions();
    } catch (error) {
        syncError.value = error?.response?.data?.message ?? 'Failed to sync from server.';
    } finally {
        syncingFromServer.value = false;
    }
};

const saveVersions = () => {
    versionsForm.patch(route('php.versions.update'));
};

const setDefaultVersion = () => {
    if (!versionsForm.installed_versions.includes(versionsForm.current_version)) {
        versionsForm.installed_versions = [...new Set([...versionsForm.installed_versions, versionsForm.current_version])];
    }
    saveVersions();
};

const saveExtensions = async () => {
    extensionSyncing.value = true;
    extensionSyncError.value = '';

    try {
        await window.axios.patch(
            route('php.extensions.update'),
            {
                version: extensionForm.version,
                extensions: extensionForm.extensions,
            },
            { headers: { Accept: 'application/json' } },
        );

        const selectedExtensions = new Set(extensionForm.extensions ?? []);
        extensionMatrix.value[extensionForm.version] = extensionOptions.value.reduce((carry, extension) => {
            carry[extension] = selectedExtensions.has(extension);
            return carry;
        }, {});
    } catch (error) {
        extensionSyncError.value = error?.response?.data?.message ?? 'Failed to update extension.';
    } finally {
        extensionSyncing.value = false;
    }
};

const syncExtensionsFromServer = async () => {
    extensionServerSyncing.value = true;
    extensionSyncError.value = '';

    try {
        const response = await window.axios.post(
            route('php.extensions.sync'),
            { version: extensionForm.version },
            { headers: { Accept: 'application/json' } },
        );

        const payload = response?.data?.data ?? {};
        const availableExtensions = Array.isArray(payload.availableExtensions) ? payload.availableExtensions : [];
        const extensionStates = payload.extensionStates ?? {};
        const version = String(payload.version ?? extensionForm.version ?? versionsForm.current_version);

        extensionOptions.value = availableExtensions;
        extensionForm.version = version;
        extensionForm.extensions = availableExtensions.filter((extension) => Boolean(extensionStates?.[extension]));
        extensionMatrix.value[version] = availableExtensions.reduce((carry, extension) => {
            carry[extension] = Boolean(extensionStates?.[extension]);
            return carry;
        }, {});
    } catch (error) {
        extensionSyncError.value = error?.response?.data?.message ?? 'Failed to sync extensions from server.';
    } finally {
        extensionServerSyncing.value = false;
    }
};

const saveConfig = async () => {
    configSaveMessage.value = '';
    configSaveError.value = '';
    configForm.clearErrors();

    const version = String(configForm.version ?? '').trim();
    if (!version) return;

    const values = currentConfigValues();
    configMatrix.value[version] = values;

    const persistedValues = normalizeConfigValues(persistedConfigMatrix.value?.[version] ?? {});
    if (areConfigValuesEqual(values, persistedValues)) {
        configSaveMessage.value = `No PHP config changes detected for ${version}.`;
        return;
    }

    configSaving.value = true;
    try {
        const response = await window.axios.patch(
            route('php.config.update'),
            {
                version,
                ...values,
            },
            { headers: { Accept: 'application/json' } },
        );

        const payload = response?.data?.data ?? {};
        const savedVersion = String(payload.version ?? version);
        const savedConfigValues = normalizeConfigValues(payload.configValues ?? values);

        persistedConfigMatrix.value[savedVersion] = savedConfigValues;
        configMatrix.value[savedVersion] = savedConfigValues;
        applySelectedConfigToForm(savedVersion, savedConfigValues);
        configSaveMessage.value = response?.data?.message ?? `PHP config updated for ${savedVersion}.`;
    } catch (error) {
        const validationErrors = error?.response?.data?.errors ?? {};
        Object.entries(validationErrors).forEach(([field, messages]) => {
            if (Array.isArray(messages) && messages.length > 0) {
                configForm.setError(field, messages[0]);
            }
        });
        configSaveError.value = error?.response?.data?.message ?? 'Failed to update PHP config.';
    } finally {
        configSaving.value = false;
    }
};

const rightPreviewOutput = computed(() => {
    const values = currentConfigValues();
    const selectedVersion = configForm.version || '-';

    return [
        `; PHP ${selectedVersion} config output`,
        `memory_limit = ${values.memory_limit}`,
        `upload_max_filesize = ${values.upload_max_filesize}`,
        `post_max_size = ${values.post_max_size}`,
        `max_execution_time = ${values.max_execution_time}`,
        `max_input_vars = ${values.max_input_vars}`,
        `display_errors = ${values.display_errors}`,
        `log_errors = ${values.log_errors}`,
        `allow_url_fopen = ${values.allow_url_fopen}`,
    ].join('\n');
});

const configRows = computed(() => versionsForm.installed_versions.map((version) => {
    const values = normalizeConfigValues(configMatrix.value?.[version] ?? {});

    return {
        version,
        ...values,
    };
}));

watch(
    () => configForm.version,
    async (nextVersion, previousVersion) => {
        if (applyingPayload.value) return;
        if (!nextVersion || nextVersion === previousVersion) return;

        rememberVersionDraft(previousVersion);
        configSaveMessage.value = '';
        configSaveError.value = '';
        extensionForm.version = nextVersion;
        await loadVersionPayload(nextVersion);
    },
);

watch(
    [
        () => configForm.memory_limit,
        () => configForm.upload_max_filesize,
        () => configForm.post_max_size,
        () => configForm.max_execution_time,
        () => configForm.max_input_vars,
        () => configForm.display_errors,
        () => configForm.log_errors,
        () => configForm.allow_url_fopen,
    ],
    () => {
        if (applyingPayload.value) return;
        if (!configForm.version) return;
        configMatrix.value[configForm.version] = currentConfigValues();
    },
);

onMounted(() => {
    checkInstalledVersions();
});
</script>

<template>
    <Head title="PHP Manager" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">PHP Manager</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Merged management for versions, default runtime, extensions and php.ini config.</p>
            </div>
        </template>

        <div class="space-y-5">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 whitespace-pre-line">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 whitespace-pre-line">
                {{ page.props.flash.error }}
            </div>

            <div class="grid gap-6 lg:grid-cols-12">
                <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900 lg:col-span-6">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-base font-semibold">Version Management</h2>
                        <button type="button" :disabled="syncingFromServer" class="inline-flex items-center gap-2 rounded-md border border-blue-300 px-3 py-2 text-sm text-blue-700 hover:bg-blue-50 disabled:opacity-60 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20" @click="loadFromServer">
                            <svg v-if="syncingFromServer" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="4" />
                                <path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="4" />
                            </svg>
                            {{ syncingFromServer ? 'Syncing...' : 'Sync From Server' }}
                        </button>
                    </div>

                    <div class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-700">
                        Server detected: {{ detectedVersions.length ? detectedVersions.join(', ') : 'No versions detected' }}
                    </div>
                    <div v-if="checkError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        {{ checkError }}
                    </div>
                    <div v-if="syncMessage" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                        {{ syncMessage }}
                    </div>
                    <div v-if="syncError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        {{ syncError }}
                    </div>

                    <div>
                        <label class="mb-1 block text-sm">Default PHP Version (from DB)</label>
                        <div class="flex flex-wrap gap-2">
                            <select v-model="versionsForm.current_version" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm md:max-w-xs dark:border-slate-700 dark:bg-slate-800">
                                <option v-for="version in versionsForm.installed_versions" :key="version" :value="version">
                                    PHP {{ version }}
                                </option>
                            </select>
                            <button type="button" :disabled="versionsForm.processing || !versionsForm.current_version" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60" @click="setDefaultVersion">
                                Set Default
                            </button>
                        </div>
                    </div>
                </section>

                <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900 lg:col-span-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-medium">PHP Config (Live by Version)</h3>
                        <div class="flex items-center gap-2">
                            <select v-model="configForm.version" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option v-for="version in versionsForm.installed_versions" :key="`cfg-version-${version}`" :value="version">
                                    PHP {{ version }}
                                </option>
                            </select>
                            <span v-if="versionSwitching" class="text-xs text-blue-600">Loading version...</span>
                        </div>
                    </div>
                    <div v-if="versionSwitchError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        {{ versionSwitchError }}
                    </div>
                    <div v-if="configSaveMessage" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                        {{ configSaveMessage }}
                    </div>
                    <div v-if="configSaveError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        {{ configSaveError }}
                    </div>

                    <div class="grid gap-4 xl:grid-cols-[1.2fr_1fr]">
                        <div class="space-y-4">
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm">memory_limit</label>
                                    <input v-model="configForm.memory_limit" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    <p v-if="configForm.errors.memory_limit" class="mt-1 text-xs text-red-600">{{ configForm.errors.memory_limit }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm">upload_max_filesize</label>
                                    <input v-model="configForm.upload_max_filesize" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    <p v-if="configForm.errors.upload_max_filesize" class="mt-1 text-xs text-red-600">{{ configForm.errors.upload_max_filesize }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm">post_max_size</label>
                                    <input v-model="configForm.post_max_size" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    <p v-if="configForm.errors.post_max_size" class="mt-1 text-xs text-red-600">{{ configForm.errors.post_max_size }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm">max_execution_time</label>
                                    <input v-model.number="configForm.max_execution_time" type="number" min="1" max="3600" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    <p v-if="configForm.errors.max_execution_time" class="mt-1 text-xs text-red-600">{{ configForm.errors.max_execution_time }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm">max_input_vars</label>
                                    <input v-model.number="configForm.max_input_vars" type="number" min="100" max="50000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    <p v-if="configForm.errors.max_input_vars" class="mt-1 text-xs text-red-600">{{ configForm.errors.max_input_vars }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm">display_errors</label>
                                    <select v-model="configForm.display_errors" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                                        <option value="On">On</option>
                                        <option value="Off">Off</option>
                                    </select>
                                    <p v-if="configForm.errors.display_errors" class="mt-1 text-xs text-red-600">{{ configForm.errors.display_errors }}</p>
                                </div>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm">log_errors</label>
                                    <select v-model="configForm.log_errors" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                                        <option value="On">On</option>
                                        <option value="Off">Off</option>
                                    </select>
                                    <p v-if="configForm.errors.log_errors" class="mt-1 text-xs text-red-600">{{ configForm.errors.log_errors }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm">allow_url_fopen</label>
                                    <select v-model="configForm.allow_url_fopen" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                                        <option value="On">On</option>
                                        <option value="Off">Off</option>
                                    </select>
                                    <p v-if="configForm.errors.allow_url_fopen" class="mt-1 text-xs text-red-600">{{ configForm.errors.allow_url_fopen }}</p>
                                </div>
                            </div>
                            <p v-if="configForm.errors.version" class="text-xs text-red-600">{{ configForm.errors.version }}</p>
                            <button type="button" :disabled="configSaving || versionSwitching" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" @click="saveConfig">
                                {{ configSaving ? 'Saving...' : 'Save Config' }}
                            </button>
                        </div>

                        <aside class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-950/60">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Right Side Output</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Changes update instantly when you change version or values.</p>
                            <pre class="mt-3 max-h-[24rem] overflow-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100"><code>{{ rightPreviewOutput }}</code></pre>
                        </aside>
                    </div>
                </section>
            </div>

            <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-medium">Per Version Config Rows</h3>
                    <span class="text-xs text-slate-500">Each PHP version has separate row output.</span>
                </div>
                <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-slate-100 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-2">Version</th>
                                <th class="px-3 py-2">memory_limit</th>
                                <th class="px-3 py-2">upload_max_filesize</th>
                                <th class="px-3 py-2">post_max_size</th>
                                <th class="px-3 py-2">max_execution_time</th>
                                <th class="px-3 py-2">max_input_vars</th>
                                <th class="px-3 py-2">display_errors</th>
                                <th class="px-3 py-2">log_errors</th>
                                <th class="px-3 py-2">allow_url_fopen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in configRows" :key="`config-row-${row.version}`" class="border-t border-slate-200 dark:border-slate-800">
                                <td class="px-3 py-2 font-semibold">PHP {{ row.version }}</td>
                                <td class="px-3 py-2">{{ row.memory_limit }}</td>
                                <td class="px-3 py-2">{{ row.upload_max_filesize }}</td>
                                <td class="px-3 py-2">{{ row.post_max_size }}</td>
                                <td class="px-3 py-2">{{ row.max_execution_time }}</td>
                                <td class="px-3 py-2">{{ row.max_input_vars }}</td>
                                <td class="px-3 py-2">{{ row.display_errors }}</td>
                                <td class="px-3 py-2">{{ row.log_errors }}</td>
                                <td class="px-3 py-2">{{ row.allow_url_fopen }}</td>
                            </tr>
                            <tr v-if="configRows.length === 0">
                                <td colspan="9" class="px-3 py-4 text-center text-slate-500">No version rows available.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium">Extensions</h3>
                    <div class="inline-flex items-center gap-2">
                        <button type="button" :disabled="extensionServerSyncing" class="inline-flex items-center gap-2 rounded-md border border-blue-300 px-2 py-1 text-xs text-blue-700 hover:bg-blue-50 disabled:opacity-60 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20" @click="syncExtensionsFromServer">
                            <svg v-if="extensionServerSyncing" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="4" />
                                <path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="4" />
                            </svg>
                            {{ extensionServerSyncing ? 'Syncing...' : 'Sync Extensions' }}
                        </button>
                        <div class="inline-flex items-center gap-2 text-xs text-slate-500">
                            <svg v-if="extensionSyncing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="4" />
                                <path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="4" />
                            </svg>
                            <span>{{ extensionSyncing ? 'Saving extension...' : 'Single change saves automatically' }}</span>
                        </div>
                    </div>
                </div>
                <div v-if="extensionSyncError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                    {{ extensionSyncError }}
                </div>
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                    <label v-for="extension in extensionOptions" :key="extension" class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <span>{{ extension }}</span>
                        <input v-model="extensionForm.extensions" :value="extension" :disabled="extensionSyncing" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-60" @change="saveExtensions" />
                    </label>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
