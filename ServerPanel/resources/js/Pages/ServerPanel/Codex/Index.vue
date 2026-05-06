<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';

const props = defineProps({
    status: {
        type: Object,
        default: () => ({}),
    },
});

const runtimeStatus = ref({ ...props.status });
const loading = ref(false);
const result = ref(null);
const loginRequestId = ref(null);
const pastedSuccessUrl = ref('');
let loginPollTimer = null;

const callEndpoint = async (url, method = 'GET') => {
    loading.value = true;
    result.value = null;

    try {
        const response = method === 'POST'
            ? await axios.post(url, {})
            : await axios.get(url);

        const payload = response.data;
        result.value = payload;
        runtimeStatus.value = payload.status ?? runtimeStatus.value;
    } catch (error) {
        result.value = {
            success: false,
            message: 'Request failed.',
            error: String(error),
        };
    } finally {
        loading.value = false;
    }
};

const startCodexLogin = async () => {
    await callEndpoint(route('codex.login'), 'POST');
    if (!result.value?.success) return;

    loginRequestId.value = result.value.request_id || null;

    if (result.value.auth_url) {
        window.open(result.value.auth_url, '_blank');
    }

    if (loginRequestId.value) {
        if (loginPollTimer) {
            window.clearInterval(loginPollTimer);
        }

        loginPollTimer = window.setInterval(async () => {
            try {
                const statusResponse = await axios.get(route('codex.login.status', loginRequestId.value));
                const payload = statusResponse.data;
                result.value = {
                    ...result.value,
                    login_status: payload,
                    success: payload.authenticated || payload.running || result.value?.success,
                    message: payload.authenticated
                        ? 'Login complete. Codex authenticated successfully.'
                        : (payload.auth_url
                            ? 'Waiting for login completion in popup.'
                            : 'Waiting for login URL from Codex CLI...'),
                };

                if (payload.authenticated) {
                    window.clearInterval(loginPollTimer);
                    loginPollTimer = null;
                    runtimeStatus.value = payload.status ?? runtimeStatus.value;
                }
            } catch (error) {
                result.value = {
                    success: false,
                    message: 'Failed to poll login status.',
                    error: String(error),
                };
            }
        }, 2500);
    }
};

const submitSuccessUrl = async () => {
    loading.value = true;
    try {
        const response = await axios.post(route('codex.login.complete'), {
            success_url: pastedSuccessUrl.value,
        });
        result.value = response.data;
        runtimeStatus.value = response.data.status ?? runtimeStatus.value;
    } catch (error) {
        const apiMessage = error?.response?.data?.message;
        const validationErrors = error?.response?.data?.errors;
        result.value = {
            success: false,
            message: apiMessage || 'Failed to validate success URL.',
            error: validationErrors || String(error),
            response: error?.response?.data || null,
        };
    } finally {
        loading.value = false;
    }
};

const sendTestMessage = async () => {
    loading.value = true;
    try {
        const response = await axios.post(route('codex.test-message'), {
            message: 'Say hello from Codex test route.',
        });
        result.value = response.data;
        runtimeStatus.value = response.data.status ?? runtimeStatus.value;
    } catch (error) {
        const apiMessage = error?.response?.data?.message;
        result.value = {
            success: false,
            message: apiMessage || 'Failed to send test message.',
            error: error?.response?.data || String(error),
        };
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <Head title="Codex Login & Auth" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Codex Login & Auth</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Authenticate Codex CLI and verify runtime access.</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Current Status</h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Binary Exists</p>
                        <p class="mt-1 text-sm font-semibold">{{ runtimeStatus.binary_exists ? 'Yes' : 'No' }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">OpenAI Key Configured</p>
                        <p class="mt-1 text-sm font-semibold">{{ runtimeStatus.openai_key_configured ? 'Yes' : 'No' }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Binary Path</p>
                        <p class="mt-1 break-all text-xs">{{ runtimeStatus.binary_path || '-' }}</p>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Actions</h2>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                        :disabled="loading"
                        @click="callEndpoint(route('codex.auth'))"
                    >
                        Check Auth
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-blue-600 bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700"
                        :disabled="loading"
                        @click="startCodexLogin"
                    >
                        Open Login In New Tab
                    </button>
                </div>
                <div class="mt-4 space-y-2">
                    <button
                        type="button"
                        class="rounded-lg border border-indigo-600 bg-indigo-600 px-3 py-2 text-sm text-white hover:bg-indigo-700"
                        :disabled="loading"
                        @click="sendTestMessage"
                    >
                        Send Test Message
                    </button>
                </div>
                <div class="mt-4 space-y-2">
                    <label class="block text-xs text-slate-500 dark:text-slate-400">
                        Paste returned `http://localhost:1455/success?...` URL
                    </label>
                    <textarea
                        v-model="pastedSuccessUrl"
                        rows="3"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-800"
                        placeholder="http://localhost:1455/success?id_token=...&needs_setup=false..."
                    />
                    <button
                        type="button"
                        class="rounded-lg border border-emerald-600 bg-emerald-600 px-3 py-2 text-sm text-white hover:bg-emerald-700"
                        :disabled="loading || !pastedSuccessUrl.trim()"
                        @click="submitSuccessUrl"
                    >
                        Submit Success URL
                    </button>
                </div>
            </section>

            <section v-if="result" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Result</h2>
                <p class="mt-3 text-sm font-medium" :class="result.success ? 'text-emerald-600' : 'text-red-600'">
                    {{ result.message || (result.success ? 'Success' : 'Failed') }}
                </p>
                <pre class="mt-3 overflow-auto rounded-lg bg-slate-950 p-3 text-xs text-slate-100">{{ JSON.stringify(result, null, 2) }}</pre>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
