<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const props = defineProps({
    firewall: { type: Object, default: () => ({}) },
    ssh: { type: Object, default: () => ({}) },
    telegram: { type: Object, default: () => ({}) },
});

const syncLoading = ref(false);
const syncMessage = ref('');
const syncError = ref('');
const firewallMessage = ref('');
const firewallError = ref('');
const sshMessage = ref('');
const sshError = ref('');
const telegramMessage = ref('');
const telegramError = ref('');

const firewallForm = useForm({
    enabled: Boolean(props.firewall.enabled ?? false),
    default_incoming: props.firewall.default_incoming ?? 'deny',
    default_outgoing: props.firewall.default_outgoing ?? 'allow',
    allowed_ports_text: Array.isArray(props.firewall.allowed_ports) ? props.firewall.allowed_ports.join(',') : '22,80,443',
});

const sshForm = useForm({
    port: Number(props.ssh.port ?? 22),
    password_authentication: props.ssh.password_authentication ?? 'Off',
    permit_root_login: props.ssh.permit_root_login ?? 'no',
    pubkey_authentication: props.ssh.pubkey_authentication ?? 'On',
});

const telegramForm = useForm({
    enabled: Boolean(props.telegram.enabled ?? false),
    bot_token: props.telegram.bot_token ?? '',
    chat_id: props.telegram.chat_id ?? '',
    message: props.telegram.message ?? 'Security alert from ServerPanel',
});

const parsePorts = (text) => {
    return String(text || '')
        .split(',')
        .map((item) => Number(String(item).trim()))
        .filter((port) => Number.isInteger(port) && port >= 1 && port <= 65535);
};

const syncFromServer = async () => {
    syncLoading.value = true;
    syncMessage.value = '';
    syncError.value = '';

    try {
        const response = await window.axios.post(panelRoute('security.sync'), {}, { headers: { Accept: 'application/json' } });
        const data = response?.data?.data ?? {};

        if (data.firewall) {
            firewallForm.enabled = Boolean(data.firewall.enabled ?? false);
            firewallForm.default_incoming = data.firewall.default_incoming ?? 'deny';
            firewallForm.default_outgoing = data.firewall.default_outgoing ?? 'allow';
            firewallForm.allowed_ports_text = Array.isArray(data.firewall.allowed_ports) ? data.firewall.allowed_ports.join(',') : '';
        }
        if (data.ssh) {
            sshForm.port = Number(data.ssh.port ?? 22);
            sshForm.password_authentication = data.ssh.password_authentication ?? 'Off';
            sshForm.permit_root_login = data.ssh.permit_root_login ?? 'no';
            sshForm.pubkey_authentication = data.ssh.pubkey_authentication ?? 'On';
        }

        syncMessage.value = response?.data?.message ?? 'Security synced from server.';
    } catch (error) {
        syncError.value = error?.response?.data?.message ?? 'Sync failed.';
    } finally {
        syncLoading.value = false;
    }
};

const saveFirewall = async () => {
    firewallMessage.value = '';
    firewallError.value = '';

    try {
        await window.axios.patch(
            panelRoute('security.firewall.update'),
            {
                enabled: firewallForm.enabled,
                default_incoming: firewallForm.default_incoming,
                default_outgoing: firewallForm.default_outgoing,
                allowed_ports: parsePorts(firewallForm.allowed_ports_text),
            },
            { headers: { Accept: 'application/json' } },
        );
        firewallMessage.value = 'Firewall settings saved.';
    } catch (error) {
        firewallError.value = error?.response?.data?.message ?? 'Failed to save firewall settings.';
    }
};

const saveSsh = async () => {
    sshMessage.value = '';
    sshError.value = '';

    try {
        await window.axios.patch(
            panelRoute('security.ssh.update'),
            {
                port: sshForm.port,
                password_authentication: sshForm.password_authentication,
                permit_root_login: sshForm.permit_root_login,
                pubkey_authentication: sshForm.pubkey_authentication,
            },
            { headers: { Accept: 'application/json' } },
        );
        sshMessage.value = 'SSH settings saved.';
    } catch (error) {
        sshError.value = error?.response?.data?.message ?? 'Failed to save SSH settings.';
    }
};

const saveTelegram = async () => {
    telegramMessage.value = '';
    telegramError.value = '';

    try {
        await window.axios.patch(
            panelRoute('security.telegram.update'),
            {
                enabled: telegramForm.enabled,
                bot_token: telegramForm.bot_token,
                chat_id: telegramForm.chat_id,
                message: telegramForm.message,
            },
            { headers: { Accept: 'application/json' } },
        );
        telegramMessage.value = 'Telegram settings saved.';
    } catch (error) {
        telegramError.value = error?.response?.data?.message ?? 'Failed to save Telegram settings.';
    }
};

const testTelegram = async () => {
    telegramMessage.value = '';
    telegramError.value = '';

    try {
        await window.axios.post(
            panelRoute('security.telegram.test'),
            {
                bot_token: telegramForm.bot_token,
                chat_id: telegramForm.chat_id,
                message: telegramForm.message,
            },
            { headers: { Accept: 'application/json' } },
        );
        telegramMessage.value = 'Telegram test message sent.';
    } catch (error) {
        telegramError.value = error?.response?.data?.message ?? 'Failed to send Telegram test message.';
    }
};
</script>

<template>
    <Head title="Security Manager" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Security Manager</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Manage firewall and SSH security defaults for Ubuntu servers.</p>
            </div>
        </template>

        <div class="space-y-5">
            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold">Server Sync</h2>
                    <button type="button" :disabled="syncLoading" class="inline-flex items-center gap-2 rounded-md border border-blue-300 px-3 py-2 text-sm text-blue-700 hover:bg-blue-50 disabled:opacity-60 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20" @click="syncFromServer">
                        <svg v-if="syncLoading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="4" />
                            <path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="4" />
                        </svg>
                        {{ syncLoading ? 'Syncing...' : 'Sync From Server' }}
                    </button>
                </div>
                <div v-if="syncMessage" class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                    {{ syncMessage }}
                </div>
                <div v-if="syncError" class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                    {{ syncError }}
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">Firewall</h2>
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input v-model="firewallForm.enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" />
                        Enable Firewall (UFW)
                    </label>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm">Default Incoming</label>
                            <select v-model="firewallForm.default_incoming" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="allow">allow</option>
                                <option value="deny">deny</option>
                                <option value="reject">reject</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Default Outgoing</label>
                            <select v-model="firewallForm.default_outgoing" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="allow">allow</option>
                                <option value="deny">deny</option>
                                <option value="reject">reject</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Allowed Ports (comma-separated)</label>
                        <input v-model="firewallForm.allowed_ports_text" type="text" placeholder="22,80,443" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="saveFirewall">
                        Save Firewall
                    </button>
                    <div v-if="firewallMessage" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                        {{ firewallMessage }}
                    </div>
                    <div v-if="firewallError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        {{ firewallError }}
                    </div>
                </section>

                <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">SSH</h2>
                    <div>
                        <label class="mb-1 block text-sm">SSH Port</label>
                        <input v-model.number="sshForm.port" type="number" min="1" max="65535" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm">Password Authentication</label>
                            <select v-model="sshForm.password_authentication" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="On">On</option>
                                <option value="Off">Off</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Pubkey Authentication</label>
                            <select v-model="sshForm.pubkey_authentication" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="On">On</option>
                                <option value="Off">Off</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Permit Root Login</label>
                        <select v-model="sshForm.permit_root_login" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="no">no</option>
                            <option value="prohibit-password">prohibit-password</option>
                            <option value="yes">yes</option>
                            <option value="forced-commands-only">forced-commands-only</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Use <code>no</code> to disable root SSH login completely.</p>
                    </div>
                    <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="saveSsh">
                        Save SSH
                    </button>
                    <div v-if="sshMessage" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                        {{ sshMessage }}
                    </div>
                    <div v-if="sshError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        {{ sshError }}
                    </div>
                </section>
            </div>

            <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold">Telegram Security Channel</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Connect a Telegram bot to send security or install alerts.</p>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input v-model="telegramForm.enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" />
                        Enabled
                    </label>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm">Bot Token</label>
                        <input v-model="telegramForm.bot_token" type="password" placeholder="123456789:AA..." class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Chat ID</label>
                        <input v-model="telegramForm.chat_id" type="text" placeholder="-1001234567890" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm">Message</label>
                    <textarea v-model="telegramForm.message" rows="3" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" placeholder="Security alert from ServerPanel"></textarea>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="saveTelegram">
                        Save Telegram
                    </button>
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="testTelegram">
                        Test Message
                    </button>
                </div>

                <div v-if="telegramMessage" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
                    {{ telegramMessage }}
                </div>
                <div v-if="telegramError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                    {{ telegramError }}
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
