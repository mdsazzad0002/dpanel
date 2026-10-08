<script setup>
import { inject, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import DockerRuntimeFields from '@/Pages/Websites/components/docker/DockerRuntimeFields.vue';

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
const pythonVersionOptions = ['3.8', '3.10', '3.12'];

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
const dockerInput = ref({ image: '', container_port: 80, mount_target: '', env: [], public: false });
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
    dockerInput.value = {
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
        pushToast?.('Select a PHP version.', 'error');
        return;
    }
    if (runtime === 'node' && !nodeEntryFile) {
        pushToast?.('Enter an entry file for the Node.js runtime.', 'error');
        return;
    }
    if (runtime === 'python' && !pythonEntryFile) {
        pushToast?.('Enter a WSGI app path for the Python runtime.', 'error');
        return;
    }
    const pythonWorkers = Number(pythonWorkersInput.value);
    if (runtime === 'python' && (!Number.isInteger(pythonWorkers) || pythonWorkers < 1 || pythonWorkers > 32)) {
        pushToast?.('Worker processes must be a whole number between 1 and 32.', 'error');
        return;
    }
    const pythonTimeout = Number(pythonTimeoutInput.value);
    if (runtime === 'python' && (!Number.isInteger(pythonTimeout) || pythonTimeout < 10 || pythonTimeout > 300)) {
        pushToast?.('Request timeout must be a whole number of seconds between 10 and 300.', 'error');
        return;
    }
    const docker = dockerInput.value;
    const dockerPort = Number(docker.container_port);
    if (runtime === 'docker' && !String(docker.image || '').trim()) {
        pushToast?.('Enter the Docker image to run.', 'error');
        return;
    }
    if (runtime === 'docker' && (!Number.isInteger(dockerPort) || dockerPort < 1 || dockerPort > 65535)) {
        pushToast?.('The app port must be a whole number between 1 and 65535.', 'error');
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
                ...(runtime === 'docker' ? {
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
        pushToast?.(data.message || 'Website settings updated successfully.', 'success');
        close();
        router.reload({ only: ['website'], preserveScroll: true });
    } catch (error) {
        pushToast?.(error?.message || 'Failed to update website settings.', 'error');
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
                        <h2 id="runtime-settings-title" class="text-lg font-semibold text-slate-900 dark:text-white">Runtime settings</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Update the web path and runtime together.</p>
                    </div>
                    <button type="button" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200" @click="close" aria-label="Close settings">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="saveRuntimeSettings">
                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-5">
                        <div>
                            <label for="start-directory" class="block text-sm font-medium text-slate-700 dark:text-slate-200">Start directory</label>
                            <input id="start-directory" v-model="startDirectoryInput" type="text" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" placeholder="Leave blank to use root path" />
                            <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">Leave blank to serve the root path directly, or enter a relative directory such as <code>public</code>.</p>
                        </div>
                        <div>
                            <label for="runtime" class="block text-sm font-medium text-slate-700 dark:text-slate-200">Runtime</label>
                            <select id="runtime" v-model="runtimeInput" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                                <option value="php">PHP</option>
                                <option value="node">Node.js (Next.js, Express, …)</option>
                                <option value="python">Python (Django, Flask, FastAPI, …)</option>
                                <option v-if="docker && (docker.installed || website.runtime === 'docker')" value="docker">Docker container (any image)</option>
                            </select>
                            <p v-if="runtimeInput === 'node'" class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">Switching to Node.js stops PHP handling for this domain; requests will be reverse-proxied to your Node process instead.</p>
                            <p v-if="runtimeInput === 'python'" class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">Switching to Python stops PHP handling for this domain; requests will be reverse-proxied to your gunicorn process instead.</p>
                            <p v-if="runtimeInput === 'docker'" class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">Switching to Docker stops PHP handling for this domain; requests will be reverse-proxied to the container instead. Saving again recreates the container with the new settings.</p>
                            <p v-if="website.runtime === 'docker' && runtimeInput !== 'docker'" class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">Leaving Docker removes this site's container. Files in the site folder stay.</p>
                        </div>
                        <div v-if="runtimeInput === 'php'">
                            <label for="php-version" class="block text-sm font-medium text-slate-700 dark:text-slate-200">PHP version</label>
                            <select id="php-version" v-model="phpVersionInput" required class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                                <option value="" disabled>Select a PHP version</option>
                                <option v-for="version in phpVersions" :key="version" :value="version">PHP {{ version }}</option>
                            </select>
                        </div>
                        <template v-else-if="runtimeInput === 'node'">
                            <div>
                                <label for="node-version" class="block text-sm font-medium text-slate-700 dark:text-slate-200">Node version</label>
                                <select id="node-version" v-model="nodeVersionInput" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                                    <option v-for="version in nodeVersionOptions" :key="version" :value="version">{{ version }}</option>
                                </select>
                            </div>
                            <div>
                                <label for="node-entry-file" class="block text-sm font-medium text-slate-700 dark:text-slate-200">Entry file</label>
                                <input id="node-entry-file" v-model="nodeEntryFileInput" type="text" required placeholder="server.js" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
                                <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">The file dPanel runs, relative to the project root. It must read the <code>PORT</code> env var and call <code>listen()</code> on it.</p>
                            </div>
                            <div>
                                <label for="node-start-command" class="block text-sm font-medium text-slate-700 dark:text-slate-200">Start command (optional)</label>
                                <input id="node-start-command" v-model="nodeStartCommandInput" type="text" placeholder="npm run start" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
                                <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">Overrides <code>node {{ nodeEntryFileInput || 'server.js' }}</code>, e.g. for <code>next start</code>.</p>
                            </div>
                        </template>
                        <template v-else-if="runtimeInput === 'python'">
                            <div>
                                <label for="python-version" class="block text-sm font-medium text-slate-700 dark:text-slate-200">Python version</label>
                                <select id="python-version" v-model="pythonVersionInput" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                                    <option v-for="version in pythonVersionOptions" :key="version" :value="version">{{ version }}</option>
                                </select>
                            </div>
                            <div>
                                <label for="python-entry-file" class="block text-sm font-medium text-slate-700 dark:text-slate-200">WSGI app path</label>
                                <input id="python-entry-file" v-model="pythonEntryFileInput" type="text" required placeholder="app:app" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
                                <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">The <code>module:variable</code> path to your WSGI app object, e.g. <code>app:app</code> for Flask or <code>myproject.wsgi:application</code> for Django.</p>
                            </div>
                            <div>
                                <label for="python-start-command" class="block text-sm font-medium text-slate-700 dark:text-slate-200">Start command (optional)</label>
                                <input id="python-start-command" v-model="pythonStartCommandInput" type="text" placeholder="gunicorn app:app" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
                                <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">Overrides <code>gunicorn {{ pythonEntryFileInput || 'app:app' }}</code>.</p>
                            </div>
                            <div>
                                <label for="python-mode" class="block text-sm font-medium text-slate-700 dark:text-slate-200">Mode</label>
                                <select id="python-mode" v-model="pythonModeInput" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                                    <option value="production">Production</option>
                                    <option value="development">Development (auto-reload on code change)</option>
                                </select>
                                <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">Development restarts the app by itself whenever a file changes and logs in debug detail; it uses more CPU, so switch live sites back to Production. Sets <code>APP_ENV</code> and <code>FLASK_DEBUG</code> to match.</p>
                            </div>
                            <div>
                                <label for="python-workers" class="block text-sm font-medium text-slate-700 dark:text-slate-200">Worker processes</label>
                                <input id="python-workers" v-model.number="pythonWorkersInput" type="number" min="1" max="32" step="1" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
                                <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">How many requests the app can handle at once (gunicorn <code>--workers</code>, also exported as <code>WEB_CONCURRENCY</code>). Default 4; applied on the next restart.</p>
                            </div>
                            <div>
                                <label for="python-timeout" class="block text-sm font-medium text-slate-700 dark:text-slate-200">Request timeout (seconds)</label>
                                <input id="python-timeout" v-model.number="pythonTimeoutInput" type="number" min="10" max="300" step="1" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
                                <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">How long one request may run before it is stopped (gunicorn <code>--timeout</code>). Default 30; raise it for slow reports or exports.</p>
                            </div>
                        </template>
                        <DockerRuntimeFields v-else-if="runtimeInput === 'docker'" v-model="dockerInput" :root-path="String(website.root_path || '')" />
                    </div>
                    <div class="flex shrink-0 justify-end gap-3 border-t border-slate-200 p-5 dark:border-slate-800">
                        <button type="button" :disabled="updateLoading" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800" @click="close">Cancel</button>
                        <button type="submit" :disabled="updateLoading" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">{{ updateLoading ? 'Saving...' : 'Save changes' }}</button>
                    </div>
                </form>
            </aside>
        </div>
    </Teleport>
</template>
