<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    versions: {
        type: Array,
        default: () => [],
    },
    apiAvailable: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const extensionPanelOpen = ref(false);
const selectedVersion = ref('');
const extensions = ref([]);
const enabledExtensions = ref([]);
const loadingExtensions = ref(false);
const savingExtensions = ref(false);
const extensionError = ref('');
const extensionMessage = ref('');
const configPanelOpen = ref(false);
const configValues = ref({});
const loadingConfig = ref(false);
const savingConfig = ref(false);
const configError = ref('');
const configMessage = ref('');

const editConfig = async (version) => {
    selectedVersion.value = version;
    configPanelOpen.value = true;
    loadingConfig.value = true;
    configError.value = '';
    configMessage.value = '';
    try {
        const url = new URL(panelRoute('php.config.details'), window.location.origin);
        url.searchParams.set('version', version);
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const payload = await response.json();
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to load PHP config.');
        configValues.value = { ...(payload.data?.configValues || {}) };
    } catch (error) {
        configError.value = error?.message || 'Unable to load PHP config.';
    } finally {
        loadingConfig.value = false;
    }
};

const saveConfig = async () => {
    savingConfig.value = true;
    configError.value = '';
    configMessage.value = '';
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch(panelRoute('php.config.update'), {
            method: 'PATCH',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            credentials: 'same-origin',
            body: JSON.stringify({ version: selectedVersion.value, ...configValues.value }),
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Unable to save PHP config.');
        configMessage.value = payload.message || 'PHP config updated.';
    } catch (error) {
        configError.value = error?.message || 'Unable to save PHP config.';
    } finally {
        savingConfig.value = false;
    }
};

const closeExtensionPanel = () => {
    extensionPanelOpen.value = false;
};

const editExtensions = async (version) => {
    selectedVersion.value = version;
    extensions.value = [];
    enabledExtensions.value = [];
    extensionError.value = '';
    extensionMessage.value = '';
    loadingExtensions.value = true;
    extensionPanelOpen.value = true;

    try {
        const url = new URL(panelRoute('php.extensions.details'), window.location.origin);
        url.searchParams.set('version', version);
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Unable to load PHP extensions.');
        }

        const data = payload.data || {};
        extensions.value = Array.isArray(data.availableExtensions) ? data.availableExtensions : [];
        const states = data.extensionStates || {};
        enabledExtensions.value = extensions.value.filter((extension) => states[extension] !== false);
    } catch (error) {
        extensionError.value = error?.message || 'Unable to load PHP extensions.';
    } finally {
        loadingExtensions.value = false;
    }
};

const saveExtensions = async () => {
    savingExtensions.value = true;
    extensionError.value = '';
    extensionMessage.value = '';

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch(panelRoute('php.extensions.update'), {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                version: selectedVersion.value,
                extensions: enabledExtensions.value,
            }),
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Unable to save PHP extensions.');
        }

        extensionMessage.value = payload.message || 'PHP extensions updated.';
    } catch (error) {
        extensionError.value = error?.message || 'Unable to save PHP extensions.';
    } finally {
        savingExtensions.value = false;
    }
};
</script>

<template>
    <Head title="PHP Manager" />

    <AuthenticatedLayout>
        <template #header>
            <div class="space-y-1">
                <h1 class="text-lg font-semibold">PHP Manager</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">PHP versions and extensions reported by the Rust API.</p>
            </div>
        </template>

        <div class="space-y-5">
            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-6 py-4 dark:border-slate-800">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Available PHP Versions</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Select a version to inspect and edit its extensions.</p>
                    </div>
                    <span
                        class="rounded-full px-3 py-1 text-xs font-medium"
                        :class="apiAvailable
                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                            : 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'"
                    >
                        Rust API {{ apiAvailable ? 'Connected' : 'Unavailable' }}
                    </span>
                </div>

                <div v-if="versions.length" class="grid gap-3 p-6 sm:grid-cols-1  xl:grid-cols-2">
                    <div v-for="version in versions" :key="version" class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/60">
                        <div>
                            <p class="font-semibold text-slate-900 dark:text-white">PHP {{ version }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Server runtime</p>
                        </div>
                        <div class="flex flex-wrap justify-end gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 dark:hover:bg-emerald-900"
                                @click="editConfig(version)"
                            >
                                Edit Config
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-300 dark:hover:bg-blue-900"
                                @click="editExtensions(version)"
                            >
                                Edit Extensions
                            </button>
                        </div>
                    </div>
                </div>
                <div v-else class="px-6 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                    No PHP versions were reported by the Rust API.
                </div>
            </section>

        </div>

        <Teleport to="body">
            <div v-if="extensionPanelOpen" class="fixed inset-0 z-50">
                <button type="button" aria-label="Close extension editor" class="absolute inset-0 bg-slate-950/50" @click="closeExtensionPanel" />
                <aside class="absolute inset-y-0 right-0 flex w-full flex-col bg-white shadow-2xl sm:w-[70vw] dark:bg-slate-900">
                    <header class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                        <div>
                            <h2 class="font-semibold text-slate-900 dark:text-white">PHP {{ selectedVersion }} Extensions</h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Loaded from the Rust API</p>
                        </div>
                        <button type="button" class="rounded-lg px-3 py-2 text-xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" @click="closeExtensionPanel">×</button>
                    </header>

                    <div class="flex-1 overflow-y-auto p-5">
                        <div v-if="loadingExtensions" class="py-12 text-center text-sm text-slate-500">Loading extensions…</div>
                        <div v-else-if="extensionError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
                            {{ extensionError }}
                        </div>
                        <div v-else-if="extensions.length" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            <label
                                v-for="extension in extensions"
                                :key="extension"
                                class="flex items-center justify-between rounded-lg border border-slate-200 px-4 py-3 text-sm dark:border-slate-700"
                            >
                                <span class="font-medium text-slate-800 dark:text-slate-200">{{ extension }}</span>
                                <input v-model="enabledExtensions" :value="extension" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" />
                            </label>
                        </div>
                        <div v-else class="py-12 text-center text-sm text-slate-500">No extensions were reported for this version.</div>

                        <div v-if="extensionMessage" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">
                            {{ extensionMessage }}
                        </div>
                    </div>

                    <footer class="flex justify-end gap-3 border-t border-slate-200 px-5 py-4 dark:border-slate-800">
                        <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" @click="closeExtensionPanel">Cancel</button>
                        <button
                            type="button"
                            :disabled="loadingExtensions || savingExtensions || !extensions.length"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                            @click="saveExtensions"
                        >
                            {{ savingExtensions ? 'Saving…' : 'Save Extensions' }}
                        </button>
                    </footer>
                </aside>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="configPanelOpen" class="fixed inset-0 z-50">
                <button type="button" aria-label="Close config editor" class="absolute inset-0 bg-slate-950/50" @click="configPanelOpen = false" />
                <aside class="absolute inset-y-0 right-0 flex w-full flex-col bg-white shadow-2xl sm:w-[70vw] dark:bg-slate-900">
                    <header class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                        <div>
                            <h2 class="font-semibold text-slate-900 dark:text-white">PHP {{ selectedVersion }} Config</h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">PHP runtime configuration</p>
                        </div>
                        <button type="button" class="rounded-lg px-3 py-2 text-xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" @click="configPanelOpen = false">×</button>
                    </header>

                    <div class="flex-1 overflow-y-auto p-5">
                        <div v-if="loadingConfig" class="py-12 text-center text-sm text-slate-500">Loading configuration…</div>
                        <div v-else-if="configError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ configError }}</div>
                        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <label v-for="field in ['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'max_input_vars']" :key="field" class="text-sm">
                                <span class="mb-1 block font-medium text-slate-700 dark:text-slate-300">{{ field }}</span>
                                <input v-model="configValues[field]" :type="field.startsWith('max_') ? 'number' : 'text'" class="w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800" />
                            </label>
                            <label v-for="field in ['display_errors', 'log_errors', 'allow_url_fopen']" :key="field" class="text-sm">
                                <span class="mb-1 block font-medium text-slate-700 dark:text-slate-300">{{ field }}</span>
                                <select v-model="configValues[field]" class="w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800">
                                    <option value="On">On</option>
                                    <option value="Off">Off</option>
                                </select>
                            </label>
                        </div>
                        <div v-if="configMessage" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ configMessage }}</div>
                    </div>

                    <footer class="flex justify-end gap-3 border-t border-slate-200 px-5 py-4 dark:border-slate-800">
                        <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" @click="configPanelOpen = false">Cancel</button>
                        <button type="button" :disabled="loadingConfig || savingConfig" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50" @click="saveConfig">
                            {{ savingConfig ? 'Saving…' : 'Save Config' }}
                        </button>
                    </footer>
                </aside>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
