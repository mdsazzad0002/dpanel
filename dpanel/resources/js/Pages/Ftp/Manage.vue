<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import FtpTabs from '@/Pages/Ftp/components/FtpTabs.vue';
import TestSteps from '@/Pages/Ftp/components/TestSteps.vue';
import FtpSetupGuide from '@/Pages/Ftp/components/FtpSetupGuide.vue';
import { clients, copyText, generatePassword, securityLabel } from '@/Pages/Ftp/ftp';

const props = defineProps({
    account: { type: Object, required: true },
    connection: { type: Object, default: () => ({}) },
    server: { type: Object, default: () => ({}) },
});
const { panelRoute, requestJson } = usePanelApi();

const message = ref('');
const error = ref('');
const copied = ref('');
const testPassword = ref('');
const testResult = ref(null);
const testing = ref(false);
const newPassword = ref(generatePassword());
const savingPassword = ref(false);
const deleting = ref(false);

const details = computed(() => [
    { key: 'host', label: 'Host', value: props.connection.host },
    { key: 'port', label: 'Port', value: String(props.connection.port ?? '') },
    { key: 'username', label: 'Username', value: props.account.username },
    { key: 'directory', label: 'Directory', value: props.account.directory },
]);

const report = (text, failed = false) => { message.value = failed ? '' : text; error.value = failed ? text : ''; };
const copy = async (key, value) => {
    if (await copyText(value)) {
        copied.value = key;
        window.setTimeout(() => { if (copied.value === key) copied.value = ''; }, 1500);
    }
};

const testLogin = async () => {
    if (testing.value || !testPassword.value) return;
    testing.value = true; testResult.value = null;
    try {
        testResult.value = await requestJson(panelRoute('ftp.test-login', { account: props.account.id }), { body: { password: testPassword.value } });
    } catch (exception) { report(exception?.message || 'Connection test failed.', true); }
    finally { testing.value = false; }
};
const changePassword = async () => {
    if (savingPassword.value) return;
    savingPassword.value = true; report('');
    try {
        const data = await requestJson(panelRoute('ftp.password', { account: props.account.id }), { method: 'PATCH', body: { password: newPassword.value } });
        report(`${data.message} Copy it now; it is not stored.`);
        testPassword.value = newPassword.value;
    } catch (exception) { report(exception?.message || 'Unable to update password.', true); }
    finally { savingPassword.value = false; }
};
const remove = async () => {
    if (deleting.value || !confirm(`Delete FTP account ${props.account.username}? Client access will stop immediately.`)) return;
    deleting.value = true; report('');
    try {
        await requestJson(panelRoute('ftp.destroy', { account: props.account.id }), { method: 'DELETE' });
        router.visit(panelRoute('ftp.index', { website: props.account.website_id }));
    } catch (exception) {
        report(exception?.message || 'Unable to delete FTP account.', true);
        deleting.value = false;
    }
};
</script>

<template>
    <Head :title="`Manage ${account.username}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-1">
                <h1 class="text-lg font-semibold">Manage <span class="font-mono">{{ account.username }}</span></h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    FTP access for
                    <Link :href="panelRoute('websites.manage', { id: account.website_id })" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ account.domain }}</Link>
                </p>
            </div>
        </template>
        <div class="space-y-5">
            <FtpTabs active="manage" :account="account" />
            <div v-if="message" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">{{ message }}</div>
            <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400">{{ error }}</div>

            <div class="grid gap-5 xl:grid-cols-2">
                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-semibold">Connection details</h2>
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold capitalize text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">{{ account.status || 'active' }}</span>
                    </div>
                    <dl class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                        <div v-for="item in details" :key="item.key" class="flex items-center justify-between gap-3 py-2.5">
                            <dt class="text-sm text-slate-500">{{ item.label }}</dt>
                            <dd class="flex min-w-0 items-center gap-2">
                                <span class="break-all text-right font-mono text-sm">{{ item.value }}</span>
                                <button type="button" class="shrink-0 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800" :title="`Copy ${item.label}`" @click="copy(item.key, item.value)">
                                    <i class="bi" :class="copied === item.key ? 'bi-check2 text-emerald-600' : 'bi-copy'"></i>
                                </button>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-sm text-slate-500">Security</dt><dd class="text-sm">{{ securityLabel(connection) }}</dd></div>
                        <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-sm text-slate-500">Transfer mode</dt><dd class="text-sm">Passive</dd></div>
                    </dl>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a :href="panelRoute('ftp.filezilla', { account: account.id })" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium dark:border-slate-700"><i class="bi bi-download"></i>FileZilla config</a>
                        <a :href="panelRoute('ftp.vscode', { account: account.id })" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium dark:border-slate-700"><i class="bi bi-code-square"></i>VS Code config</a>
                        <a v-for="client in clients" :key="client.name" :href="client.url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300"><i class="bi" :class="client.icon"></i>Get {{ client.name }}</a>
                    </div>
                    <div class="mt-3 space-y-1.5 text-xs text-slate-500">
                        <p><span class="font-semibold text-slate-600 dark:text-slate-300">FileZilla:</span> File → Import, choose the downloaded file. It asks for the password when you connect.</p>
                        <p>
                            <span class="font-semibold text-slate-600 dark:text-slate-300">VS Code:</span> install the
                            <a href="https://marketplace.visualstudio.com/items?itemName=Natizyskunk.sftp" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline dark:text-indigo-400">SFTP extension</a>,
                            save the file as <code>.vscode/sftp.json</code> in your project folder and put the password in its <code>"password"</code> field.
                            Then run "SFTP: Download Project" from the command palette. Keep <code>.vscode/sftp.json</code> out of git.
                        </p>
                    </div>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">Test login</h2>
                    <p class="mt-1 text-sm text-slate-500">Logs in as this account from the server and lists its directory. The password is only used for this test.</p>
                    <form class="mt-4 flex flex-col gap-2 sm:flex-row" @submit.prevent="testLogin">
                        <input v-model="testPassword" type="password" required autocomplete="off" placeholder="Account password" class="w-full min-w-0 rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-950" />
                        <button :disabled="testing || !testPassword" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                            <i class="bi" :class="testing ? 'bi-arrow-repeat animate-spin' : 'bi-plug'"></i>{{ testing ? 'Testing…' : 'Test connection' }}
                        </button>
                    </form>
                    <div class="mt-4"><TestSteps :result="testResult" /></div>
                    <div v-if="testResult?.diagnosis && testResult.diagnosis.cause !== 'data_channel'" class="mt-4">
                        <FtpSetupGuide :server="server" :connection="connection" :cause="testResult.diagnosis.cause" compact />
                        <Link :href="panelRoute('ftp.connect')" class="mt-2 inline-block text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">Full setup guide →</Link>
                    </div>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">Change password</h2>
                    <p class="mt-1 text-sm text-slate-500">Takes effect immediately. Connected clients must log in again.</p>
                    <form class="mt-4 flex flex-col gap-2 sm:flex-row" @submit.prevent="changePassword">
                        <div class="flex min-w-0 flex-1">
                            <input v-model="newPassword" required minlength="12" type="text" class="w-full min-w-0 rounded-l-lg border-slate-300 font-mono dark:border-slate-700 dark:bg-slate-950" />
                            <button type="button" class="rounded-r-lg border border-l-0 border-slate-300 px-3 text-xs dark:border-slate-700" @click="newPassword = generatePassword()">Generate</button>
                        </div>
                        <button type="button" class="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700" @click="copy('password', newPassword)"><i class="bi" :class="copied === 'password' ? 'bi-check2 text-emerald-600' : 'bi-copy'"></i></button>
                        <button :disabled="savingPassword" class="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{{ savingPassword ? 'Saving…' : 'Update password' }}</button>
                    </form>
                </section>

                <section class="rounded-xl border border-red-200 bg-white p-5 dark:border-red-900/60 dark:bg-slate-900">
                    <h2 class="font-semibold text-red-700 dark:text-red-400">Delete account</h2>
                    <p class="mt-1 text-sm text-slate-500">Removes FTP access. Files in {{ account.directory }} are not touched.</p>
                    <button :disabled="deleting" class="mt-4 inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 disabled:opacity-50 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400" @click="remove">
                        <i class="bi" :class="deleting ? 'bi-arrow-repeat animate-spin' : 'bi-trash'"></i>{{ deleting ? 'Deleting…' : 'Delete FTP account' }}
                    </button>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
