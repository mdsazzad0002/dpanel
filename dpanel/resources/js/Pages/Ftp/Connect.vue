<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import FtpTabs from '@/Pages/Ftp/components/FtpTabs.vue';
import TestSteps from '@/Pages/Ftp/components/TestSteps.vue';
import FtpSetupGuide from '@/Pages/Ftp/components/FtpSetupGuide.vue';
import { clients, securityLabel } from '@/Pages/Ftp/ftp';

const props = defineProps({
    connection: { type: Object, default: () => ({}) },
    server: { type: Object, default: () => ({}) },
});
const { panelRoute, requestJson } = usePanelApi();

const testing = ref(false);
const result = ref(null);
const error = ref('');

const runTest = async () => {
    if (testing.value) return;
    testing.value = true; result.value = null; error.value = '';
    try {
        result.value = await requestJson(panelRoute('ftp.test'));
    } catch (exception) { error.value = exception?.message || 'Connection test failed.'; }
    finally { testing.value = false; }
};
const settings = [
    { label: 'Protocol', value: 'FTP - File Transfer Protocol' },
    { label: 'Encryption', value: props.connection.tls ? 'Require explicit FTP over TLS' : 'Only use plain FTP (insecure)' },
    { label: 'Host', value: props.connection.host },
    { label: 'Port', value: String(props.connection.port ?? '') },
    { label: 'Logon type', value: 'Normal or Ask for password' },
    { label: 'Transfer mode', value: 'Passive' },
];
</script>

<template>
    <Head title="Connect to FTP" />
    <AuthenticatedLayout>
        <template #header>
            <div><h1 class="text-lg font-semibold">Connect &amp; Clients</h1><p class="text-sm text-slate-500 dark:text-slate-400">Check the FTP server and set up a client.</p></div>
        </template>
        <div class="space-y-5">
            <FtpTabs active="connect" />
            <div class="grid gap-5 xl:grid-cols-2">
                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="font-semibold">Server connection test</h2>
                            <p class="mt-1 text-sm text-slate-500">Connects to {{ connection.host }}:{{ connection.port }} the way a client would{{ connection.tls ? ' and checks that TLS is offered' : '' }}. To test one account's login, open it from Accounts.</p>
                        </div>
                        <button :disabled="testing" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="runTest">
                            <i class="bi" :class="testing ? 'bi-arrow-repeat animate-spin' : 'bi-plug'"></i>{{ testing ? 'Testing…' : 'Run test' }}
                        </button>
                    </div>
                    <div v-if="error" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400">{{ error }}</div>
                    <div class="mt-4"><TestSteps :result="result" /></div>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">Client settings</h2>
                    <p class="mt-1 text-sm text-slate-500">Use these in any FTP client. Security: {{ securityLabel(connection) }}.</p>
                    <dl class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                        <div v-for="item in settings" :key="item.label" class="flex justify-between gap-4 py-2 text-sm">
                            <dt class="text-slate-500">{{ item.label }}</dt><dd class="text-right font-mono">{{ item.value }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="font-semibold">Setup guide &amp; troubleshooting</h2>
                <p class="mt-1 mb-4 text-sm text-slate-500">What this server needs so FTP clients can connect. Values are read from this server.</p>
                <FtpSetupGuide :server="server" :connection="connection" :cause="result?.diagnosis?.cause || ''" />
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="font-semibold">Download an FTP client</h2>
                <p class="mt-1 text-sm text-slate-500">Free clients that support FTP over TLS. Each account's Manage page also has a ready-made FileZilla config.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <a v-for="client in clients" :key="client.name" :href="client.url" target="_blank" rel="noopener noreferrer" class="group flex items-center gap-3 rounded-xl border border-slate-200 p-4 transition hover:border-indigo-400 hover:shadow-sm dark:border-slate-700">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-indigo-50 text-lg text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400"><i class="bi" :class="client.icon"></i></span>
                        <span class="min-w-0 flex-1"><span class="block font-semibold">{{ client.name }}</span><span class="block text-xs text-slate-500">{{ client.platforms }}</span></span>
                        <i class="bi bi-box-arrow-up-right text-slate-400 group-hover:text-indigo-600"></i>
                    </a>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
