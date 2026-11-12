<script setup>
import { useI18n } from 'vue-i18n';
import { inject, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import DockerRuntimeFields from '@/Pages/Websites/components/docker/DockerRuntimeFields.vue';

const { t } = useI18n();

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    website: {
        type: Object,
        required: true,
    },
    phpVersions: {
        type: Array,
        default: () => [],
    },
    // Admins only: { installed, env, server_ip }; null hides the Docker runtime.
    docker: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['update:modelValue']);

const pushToast = inject('pushToast');
const { panelRoute, requestJson } = usePanelApi();

const nodeVersionOptions = ['18', '20', '22'];
const pythonVersionOptions = ['3.8', '3.9', '3.10', '3.11', '3.12', '3.13', '3.14'];

const startDirectoryInput = ref('');
const phpVersionInput = ref('');
const runtimeInput = ref('php');
const nodeEntryFileInput = ref('');
const nodeStartCommandInput = ref('');
const nodeVersionInput = ref('20');
const pythonEntryFileInput = ref('');
const pythonStartCommandInput = ref('');
const pythonVersionInput = ref('3.10');
const pythonWorkersInput = ref(4);
const pythonModeInput = ref('production');
const pythonTimeoutInput = ref(30);
const dockerInput = ref({ source: 'image', stack: '', target_port: '', image: '', container_port: 80, mount_target: '', env: [], public: false });
const updateLoading = ref(false);

const close = () => emit('update:modelValue', false);

watch(() => props.modelValue, (isOpen) => {
    if (!isOpen) return;

    startDirectoryInput.value = String(props.website?.start_directory ?? '');
    phpVersionInput.value = String(props.website?.php_version || '');
    runtimeInput.value = String(props.website?.runtime || 'php');
    nodeEntryFileInput.value = String(props.website?.node_entry_file || 'server.js');
    nodeStartCommandInput.value = String(props.website?.node_start_command || '');
    nodeVersionInput.value = String(props.website?.node_version || '20');
    pythonEntryFileInput.value = String(props.website?.python_entry_file || 'app:app');
    pythonStartCommandInput.value = String(props.website?.python_start_command || '');
    pythonVersionInput.value = String(props.website?.python_version || '3.10');
    pythonWorkersInput.value = Number(props.website?.python_workers) || 4;
    pythonModeInput.value = props.website?.python_mode === 'development' ? 'development' : 'production';
    pythonTimeoutInput.value = Number(props.website?.python_timeout) || 30;
    const fronting = props.website?.docker_source === 'port';
    dockerInput.value = {
        source: fronting ? 'port' : 'image',
        stack: String(props.website?.docker_stack || ''),
        target_port: fronting ? Number(props.website?.docker_port) || '' : '',
        image: String(props.website?.docker_image || ''),
        container_port: Number(props.website?.docker_container_port) || 80,
        mount_target: String(props.website?.docker_mount_target || ''),
        env: (props.docker?.env || []).map((env) => ({ key: String(env.key || ''), value: String(env.value ?? '') })),
        public: Boolean(props.website?.docker_public),
    };
});

const saveRuntimeSettings = async () => {
    if (updateLoading.value) return;
    const startDirectory = String(startDirectoryInput.value || '').trim();
    const phpVersion = String(phpVersionInput.value || '').trim();
    const runtime = String(runtimeInput.value || 'php').trim();
    const nodeEntryFile = String(nodeEntryFileInput.value || '').trim();
    const pythonEntryFile = String(pythonEntryFileInput.value || '').trim();
    if (runtime === 'php' && !phpVersion) {
        pushToast?.(t('websites.runtime.selectPhp'), 'error');
        return;
    }
    if (runtime === 'node' && !nodeEntryFile) {
        pushToast?.(t('websites.runtime.nodeEntry'), 'error');
        return;
    }
    if (runtime === 'python' && !pythonEntryFile) {
        pushToast?.(t('websites.runtime.wsgi'), 'error');
        return;
    }
    const pythonWorkers = Number(pythonWorkersInput.value);
    if (runtime === 'python' && (!Number.isInteger(pythonWorkers) || pythonWorkers < 1 || pythonWorkers > 32)) {
        pushToast?.(t('websites.runtime.workers'), 'error');
        return;
    }
    const pythonTimeout = Number(pythonTimeoutInput.value);
    if (runtime === 'python' && (!Number.isInteger(pythonTimeout) || pythonTimeout < 10 || pythonTimeout > 300)) {
        pushToast?.(t('websites.runtime.timeout'), 'error');
        return;
    }
    const docker = dockerInput.value;
    const dockerPort = Number(docker.container_port);
    const fronting = docker.source === 'port';
    const targetPort = Number(docker.target_port);
    if (runtime === 'docker' && fronting && (!Number.isInteger(targetPort) || targetPort < 1 || targetPort > 65535)) {
        pushToast?.(t('websites.runtime.port'), 'error');
        return;
    }
    if (runtime === 'docker' && !fronting && !String(docker.image || '').trim()) {
        pushToast?.(t('websites.runtime.image'), 'error');
        return;
    }
    if (runtime === 'docker' && !fronting && (!Number.isInteger(dockerPort) || dockerPort < 1 || dockerPort > 65535)) {
        pushToast?.(t('websites.runtime.appPort'), 'error');
        return;
    }
    updateLoading.value = true;
    try {
        const data = await requestJson(panelRoute('websites.update', { id: props.website.id }), {
            method: 'PATCH',
            body: {
                start_directory: startDirectory,
                php_version: phpVersion,
                runtime,
                node_entry_file: nodeEntryFile,
                node_start_command: String(nodeStartCommandInput.value || '').trim(),
                node_version: String(nodeVersionInput.value || '').trim(),
                python_entry_file: pythonEntryFile,
                python_start_command: String(pythonStartCommandInput.value || '').trim(),
                python_version: String(pythonVersionInput.value || '').trim(),
                python_workers: pythonWorkers,
                python_mode: pythonModeInput.value,
                python_timeout: pythonTimeout,
                ...(runtime === 'docker' && fronting ? {
                    docker_source: 'port',
                    docker_stack: String(docker.stack || ''),
                    docker_target_port: targetPort,
                } : {}),
                ...(runtime === 'docker' && !fronting ? {
                    docker_source: 'image',
                    docker_image: String(docker.image).trim(),
                    docker_container_port: dockerPort,
                    docker_mount_target: String(docker.mount_target || '').trim(),
                    docker_env: docker.env
                        .filter((env) => String(env.key || '').trim())
                        .map((env) => ({ key: String(env.key).trim(), value: String(env.value ?? '') })),
                    docker_public: Boolean(docker.public),
                } : {}),
            },
        });
        pushToast?.(data.message || t('websites.runtime.saved'), 'success');
        close();
        router.reload({ only: ['website'], preserveScroll: true });
    } catch (error) {
        pushToast?.(error?.message || t('websites.runtime.saveFailed'), 'error');
        // A failed gateway refresh (502) still saves the settings, so show
        // what is stored now instead of the stale version.
        router.reload({ only: ['website'], preserveScroll: true });
    } finally {
        updateLoading.value = false;
    }
};
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" class="fixed inset-0 z-50" @keydown.esc="close">
            <div class="absolute inset-0 bg-slate-950/45" @click="close"></div>
            <aside class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-2xl dark:bg-slate-900" role="dialog" aria-modal="true" aria-labelledby="runtime-settings-title">
                <div class="flex items-start justify-between border-b border-slate-200 p-5 dark:border-slate-800">
                    <div>
                        <h2 id="runtime-settings-title" class="text-lg font-semibold text-slate-900 dark:text-white">{{ t('websites.runtime.title') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('websites.runtime.subtitle') }}</p>
                    </div>
                    <button type="button" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200" @click="close" :aria-label="t('websites.runtime.close')">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="saveRuntimeSettings">
                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-5">
                        <div>
                            <label for="start-directory" class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('websites.runtime.startDir') }}</label>
                            <input id="start-directory" v-model="startDirectoryInput" type="text" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" :placeholder="t('websites.runtime.startDirPlaceholder')" />
                            <i18n-t keypath="websites.runtime.startDirHelp" tag="p" class="mt-1.5 text-xs text-slate-500 dark:text-slate-400"><template #dir><code>public</code></template></i18n-t>
                        </div>
                        <div>
                            <label for="runtime" class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('websites.runtime.runtime') }}</label>
                            <select id="runtime" v-model="runtimeInput" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                                <option value="php">PHP</option>
                                <option v-if="docker && (docker.installed || website.runtime === 'docker')" value="docker">{{ t('websites.runtime.dockerOption') }}</option>
                            </select>
                            <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ t('websites.runtime.nodeHelp') }}</p>
                            <p v-if="runtimeInput === 'docker'" class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">{{ t('websites.runtime.toDocker') }}</p>
                            <p v-if="website.runtime === 'docker' && runtimeInput !== 'docker'" class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">{{ t('websites.runtime.fromDocker') }}</p>
                        </div>
                        <div v-if="runtimeInput === 'php'">
                            <label for="php-version" class="block text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('websites.runtime.phpVersion') }}</label>
                            <select id="php-version" v-model="phpVersionInput" required class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                                <option value="" disabled>{{ t('websites.runtime.selectPhpOption') }}</option>
                                <option v-for="version in phpVersions" :key="version" :value="version">PHP {{ version }}</option>
                            </select>
                        </div>
                        <DockerRuntimeFields v-else-if="runtimeInput === 'docker'" v-model="dockerInput" :root-path="String(website.root_path || '')" />
                    </div>
                    <div class="flex shrink-0 justify-end gap-3 border-t border-slate-200 p-5 dark:border-slate-800">
                        <button type="button" :disabled="updateLoading" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800" @click="close">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="updateLoading" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">{{ updateLoading ? t('websites.runtime.saving') : t('websites.runtime.save') }}</button>
                    </div>
                </form>
            </aside>
        </div>
    </Teleport>
</template>
