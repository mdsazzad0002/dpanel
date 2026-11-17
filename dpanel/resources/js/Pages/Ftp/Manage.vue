<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import FtpTabs from '@/Pages/Ftp/components/FtpTabs.vue';
import TestSteps from '@/Pages/Ftp/components/TestSteps.vue';
import FtpSetupGuide from '@/Pages/Ftp/components/FtpSetupGuide.vue';
import { clients, copyText, generatePassword, securityLabel } from '@/Pages/Ftp/ftp';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
    { key: 'host', label: t('ftp.labels.host'), value: props.connection.host },
    { key: 'port', label: t('ftp.labels.port'), value: String(props.connection.port ?? '') },
    { key: 'username', label: t('ftp.labels.username'), value: props.account.username },
    { key: 'directory', label: t('ftp.manage.directory'), value: props.account.directory },
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
    } catch (exception) { report(exception?.message || t('ftp.connect.testFailed'), true); }
    finally { testing.value = false; }
};
const changePassword = async () => {
    if (savingPassword.value) return;
    savingPassword.value = true; report('');
    try {
        const data = await requestJson(panelRoute('ftp.password', { account: props.account.id }), { method: 'PATCH', body: { password: newPassword.value } });
        report(`${data.message} ${t('ftp.manage.copyNow')}`);
        testPassword.value = newPassword.value;
    } catch (exception) { report(exception?.message || t('ftp.manage.pwFailed'), true); }
    finally { savingPassword.value = false; }
};
const remove = async () => {
    if (deleting.value || !confirm(t('ftp.index.deleteConfirm', { user: props.account.username }))) return;
    deleting.value = true; report('');
    try {
        await requestJson(panelRoute('ftp.destroy', { account: props.account.id }), { method: 'DELETE' });
        router.visit(panelRoute('ftp.index', { website: props.account.website_id }));
    } catch (exception) {
        report(exception?.message || t('ftp.index.deleteFailed'), true);
        deleting.value = false;
    }
};
</script>

<template>
    <Head :title="t('ftp.tabs.manage', { user: account.username })" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-1">
                <h1 class="text-lg font-semibold"><i18n-t keypath="ftp.tabs.manage" scope="global"><template #user><span class="font-mono">{{ account.username }}</span></template></i18n-t></h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    <i18n-t keypath="ftp.manage.accessFor" scope="global"><template #domain><Link :href="panelRoute('websites.manage', { id: account.website_id })" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ account.domain }}</Link></template></i18n-t>
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
                        <h2 class="font-semibold">{{ t('ftp.manage.details') }}</h2>
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold capitalize text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">{{ account.status || t('ftp.manage.active') }}</span>
                    </div>
                    <dl class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                        <div v-for="item in details" :key="item.key" class="flex items-center justify-between gap-3 py-2.5">
                            <dt class="text-sm text-slate-500">{{ item.label }}</dt>
                            <dd class="flex min-w-0 items-center gap-2">
                                <span class="break-all text-right font-mono text-sm">{{ item.value }}</span>
                                <button type="button" class="shrink-0 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800" :title="t('ftp.manage.copy', { label: item.label })" @click="copy(item.key, item.value)">
                                    <i class="bi" :class="copied === item.key ? 'bi-check2 text-emerald-600' : 'bi-copy'"></i>
                                </button>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-sm text-slate-500">{{ t('ftp.labels.security') }}</dt><dd class="text-sm">{{ securityLabel(connection) }}</dd></div>
                        <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-sm text-slate-500">{{ t('ftp.connect.transferMode') }}</dt><dd class="text-sm">{{ t('ftp.connect.passive') }}</dd></div>
                    </dl>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a :href="panelRoute('ftp.filezilla', { account: account.id })" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium dark:border-slate-700"><i class="bi bi-download"></i>{{ t('ftp.manage.filezilla') }}</a>
                        <a :href="panelRoute('ftp.vscode', { account: account.id })" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium dark:border-slate-700"><i class="bi bi-code-square"></i>{{ t('ftp.manage.vscode') }}</a>
                        <a v-for="client in clients" :key="client.name" :href="client.url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300"><i class="bi" :class="client.icon"></i>{{ t('ftp.manage.get', { name: client.name }) }}</a>
                    </div>
                    <div class="mt-3 space-y-1.5 text-xs text-slate-500">
                        <p><span class="font-semibold text-slate-600 dark:text-slate-300">FileZilla:</span> {{ t('ftp.manage.filezillaHelp') }}</p>
                        <p>
                            <span class="font-semibold text-slate-600 dark:text-slate-300">VS Code:</span> <i18n-t keypath="ftp.manage.vscodeHelp" scope="global"><template #ext><a href="https://marketplace.visualstudio.com/items?itemName=Natizyskunk.sftp" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ t('ftp.manage.sftpExt') }}</a></template><template #file><code>.vscode/sftp.json</code></template><template #field><code>"password"</code></template></i18n-t>
                        </p>
                    </div>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">{{ t('ftp.manage.testLogin') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ t('ftp.manage.testHelp') }}</p>
                    <form class="mt-4 flex flex-col gap-2 sm:flex-row" @submit.prevent="testLogin">
                        <input v-model="testPassword" type="password" required autocomplete="off" :placeholder="t('ftp.manage.pwPh')" class="w-full min-w-0 rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-950" />
                        <button :disabled="testing || !testPassword" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                            <i class="bi" :class="testing ? 'bi-arrow-repeat animate-spin' : 'bi-plug'"></i>{{ testing ? t('ftp.connect.testing') : t('ftp.manage.testConnection') }}
                        </button>
                    </form>
                    <div class="mt-4"><TestSteps :result="testResult" /></div>
                    <div v-if="testResult?.diagnosis && testResult.diagnosis.cause !== 'data_channel'" class="mt-4">
                        <FtpSetupGuide :server="server" :connection="connection" :cause="testResult.diagnosis.cause" compact />
                        <Link :href="panelRoute('ftp.connect')" class="mt-2 inline-block text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ t('ftp.manage.fullGuide') }}</Link>
                    </div>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">{{ t('ftp.manage.changePw') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ t('ftp.manage.changePwHelp') }}</p>
                    <form class="mt-4 flex flex-col gap-2 sm:flex-row" @submit.prevent="changePassword">
                        <div class="flex min-w-0 flex-1">
                            <input v-model="newPassword" required minlength="12" type="text" class="w-full min-w-0 rounded-l-lg border-slate-300 font-mono dark:border-slate-700 dark:bg-slate-950" />
                            <button type="button" class="rounded-r-lg border border-l-0 border-slate-300 px-3 text-xs dark:border-slate-700" @click="newPassword = generatePassword()">{{ t('ftp.create.generate') }}</button>
                        </div>
                        <button type="button" class="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700" @click="copy('password', newPassword)"><i class="bi" :class="copied === 'password' ? 'bi-check2 text-emerald-600' : 'bi-copy'"></i></button>
                        <button :disabled="savingPassword" class="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{{ savingPassword ? t('ftp.manage.saving') : t('ftp.manage.updatePw') }}</button>
                    </form>
                </section>

                <section class="rounded-xl border border-red-200 bg-white p-5 dark:border-red-900/60 dark:bg-slate-900">
                    <h2 class="font-semibold text-red-700 dark:text-red-400">{{ t('ftp.manage.deleteTitle') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ t('ftp.manage.deleteHelp', { dir: account.directory }) }}</p>
                    <button :disabled="deleting" class="mt-4 inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 disabled:opacity-50 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400" @click="remove">
                        <i class="bi" :class="deleting ? 'bi-arrow-repeat animate-spin' : 'bi-trash'"></i>{{ deleting ? t('ftp.index.deleting') : t('ftp.manage.deleteBtn') }}
                    </button>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
