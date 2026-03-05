<script setup>
import { onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    installedVersions: { type: Array, default: () => [] },
    defaultVersion: { type: String, default: '' },
    selectedVersion: { type: String, default: '' },
    availableExtensions: { type: Array, default: () => [] },
    extensionStates: { type: Object, default: () => ({}) },
    configValues: { type: Object, default: () => ({}) },
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

const versionsForm = useForm({
    installed_versions: [...props.installedVersions],
    current_version: props.defaultVersion || (props.installedVersions[0] ?? ''),
});

const extensionForm = useForm({
    version: props.selectedVersion || versionsForm.current_version,
    extensions: extensionOptions.value.filter((extension) => props.extensionStates?.[extension]),
});

const configForm = useForm({
    version: props.selectedVersion || versionsForm.current_version,
    memory_limit: props.configValues.memory_limit ?? '256M',
    upload_max_filesize: props.configValues.upload_max_filesize ?? '128M',
    post_max_size: props.configValues.post_max_size ?? '128M',
    max_execution_time: Number(props.configValues.max_execution_time ?? 300),
    max_input_vars: Number(props.configValues.max_input_vars ?? 3000),
    display_errors: props.configValues.display_errors ?? 'Off',
    log_errors: props.configValues.log_errors ?? 'On',
    allow_url_fopen: props.configValues.allow_url_fopen ?? 'On',
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
        const installedVersions = Array.isArray(payload.installedVersions) ? payload.installedVersions : [];
        const defaultVersion = String(payload.defaultVersion ?? installedVersions[0] ?? '');
        const selectedVersion = String(payload.selectedVersion ?? defaultVersion);
        const availableExtensions = Array.isArray(payload.availableExtensions) ? payload.availableExtensions : [];
        const extensionStates = payload.extensionStates ?? {};
        const configValues = payload.configValues ?? {};

        versionsForm.installed_versions = installedVersions;
        versionsForm.current_version = defaultVersion;

        extensionOptions.value = availableExtensions;
        extensionForm.version = selectedVersion;
        extensionForm.extensions = availableExtensions.filter((extension) => Boolean(extensionStates?.[extension]));

        configForm.version = selectedVersion;
        configForm.memory_limit = configValues.memory_limit ?? '256M';
        configForm.upload_max_filesize = configValues.upload_max_filesize ?? '128M';
        configForm.post_max_size = configValues.post_max_size ?? '128M';
        configForm.max_execution_time = Number(configValues.max_execution_time ?? 300);
        configForm.max_input_vars = Number(configValues.max_input_vars ?? 3000);
        configForm.display_errors = configValues.display_errors ?? 'Off';
        configForm.log_errors = configValues.log_errors ?? 'On';
        configForm.allow_url_fopen = configValues.allow_url_fopen ?? 'On';

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
    extensionForm.version = versionsForm.current_version;

    try {
        await window.axios.patch(
            route('php.extensions.update'),
            {
                version: extensionForm.version,
                extensions: extensionForm.extensions,
            },
            { headers: { Accept: 'application/json' } },
        );
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
            { version: versionsForm.current_version },
            { headers: { Accept: 'application/json' } },
        );

        const payload = response?.data?.data ?? {};
        const availableExtensions = Array.isArray(payload.availableExtensions) ? payload.availableExtensions : [];
        const extensionStates = payload.extensionStates ?? {};

        extensionOptions.value = availableExtensions;
        extensionForm.version = versionsForm.current_version;
        extensionForm.extensions = availableExtensions.filter((extension) => Boolean(extensionStates?.[extension]));
    } catch (error) {
        extensionSyncError.value = error?.response?.data?.message ?? 'Failed to sync extensions from server.';
    } finally {
        extensionServerSyncing.value = false;
    }
};

const saveConfig = () => {
    configForm.version = versionsForm.current_version;
    configForm.patch(route('php.config.update'));
};

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
                    <h3 class="font-medium">PHP Config (6)</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm">memory_limit</label>
                            <input v-model="configForm.memory_limit" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">upload_max_filesize</label>
                            <input v-model="configForm.upload_max_filesize" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">post_max_size</label>
                            <input v-model="configForm.post_max_size" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">max_execution_time</label>
                            <input v-model.number="configForm.max_execution_time" type="number" min="1" max="3600" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">max_input_vars</label>
                            <input v-model.number="configForm.max_input_vars" type="number" min="100" max="50000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">display_errors</label>
                            <select v-model="configForm.display_errors" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="On">On</option>
                                <option value="Off">Off</option>
                            </select>
                        </div>
                    </div>
                    <button type="button" :disabled="configForm.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" @click="saveConfig">
                        Save Config
                    </button>
                </section>
            </div>

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
