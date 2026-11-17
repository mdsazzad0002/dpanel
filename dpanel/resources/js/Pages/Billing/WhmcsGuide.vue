<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    status: { type: Object, required: true },
    clientFile: { type: String, required: true },
});

const page = usePage();
const copied = ref('');
const panelRoute = (name, params = {}) => route(name, { token: page.props.panel?.token, ...params });
const copy = async (value, key) => {
    await navigator.clipboard.writeText(value);
    copied.value = key;
    window.setTimeout(() => { copied.value = ''; }, 1500);
};

const envExample = `WHMCS_API_CLIENT_ID=whmcs-production
WHMCS_API_SECRET=generate-a-long-random-secret
WHMCS_ALLOWED_IPS=203.0.113.10
WHMCS_ALLOWED_DOMAINS=billing.example.com
WHMCS_TIMESTAMP_TOLERANCE=300
WHMCS_SSO_TTL=60`;

const projectDirectory = props.clientFile.replace('/integrations/whmcs/DPanelApiClient.php', '');
const baseSetupCommand = () => `cd ${projectDirectory} && sudo bash integrations/whmcs/configure.sh \\
  --client-id whmcs-production \\
  --allowed-ip 203.0.113.10 \\
  --allowed-domain billing.example.com`;
const setupCommand = ref(baseSetupCommand());

const generateSecret = () => {
    const bytes = new Uint8Array(32);
    window.crypto.getRandomValues(bytes);
    const secret = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    const commandWithoutSecret = setupCommand.value
        .replace(/\s*\\?\s*--secret\s+\S+/g, '')
        .replace(/\s*\\?\s*--rotate-secret\b/g, '')
        .trimEnd();
    setupCommand.value = `${commandWithoutSecret} \\
  --secret ${secret}`;
};

const operations = computed(() => [
    { label: t('billing.whmcs.ops.test'), icon: 'bi-plug' },
    { label: t('billing.whmcs.ops.sync'), icon: 'bi-arrow-repeat' },
    { label: t('billing.whmcs.ops.create'), icon: 'bi-person-plus' },
    { label: t('billing.whmcs.ops.change'), icon: 'bi-arrow-left-right' },
    { label: t('billing.whmcs.ops.suspend'), icon: 'bi-pause-circle' },
    { label: t('billing.whmcs.ops.unsuspend'), icon: 'bi-play-circle' },
    { label: t('billing.whmcs.ops.terminate'), icon: 'bi-x-circle' },
    { label: t('billing.whmcs.ops.login'), icon: 'bi-box-arrow-in-right' },
]);
</script>

<template>
    <Head :title="t('billing.whmcs.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('billing.whmcs.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('billing.whmcs.subtitle') }}</p>
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Link :href="panelRoute('billing.index')" class="text-sm text-blue-600 hover:underline">← {{ t('billing.whmcs.back') }}</Link>
                <span :class="status.ready ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'" class="rounded-full px-3 py-1 text-sm font-medium">
                    {{ status.ready ? t('billing.whmcs.ready') : t('billing.whmcs.incomplete') }}
                </span>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">{{ t('billing.whmcs.step1') }}</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-400"><i18n-t keypath="billing.whmcs.step1Help" scope="global"><template #file><code>.env</code></template></i18n-t></p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">Client ID<br><strong>{{ status.clientIdReady ? t('billing.whmcs.set') : t('billing.whmcs.missing') }}</strong></div>
                    <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">API secret<br><strong>{{ status.secretReady ? t('billing.whmcs.set') : t('billing.whmcs.missing') }}</strong></div>
                    <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">{{ t('billing.whmcs.allowedIps') }}<br><strong>{{ status.allowedIps.length ? status.allowedIps.join(', ') : t('billing.whmcs.missing') }}</strong></div>
                    <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">{{ t('billing.whmcs.allowedDomains') }}<br><strong>{{ status.allowedDomains.length ? status.allowedDomains.join(', ') : t('billing.whmcs.missing') }}</strong></div>
                </div>
                <div class="relative mt-4">
                    <pre class="overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100"><code>{{ envExample }}</code></pre>
                    <button class="absolute right-3 top-3 rounded bg-slate-700 px-2 py-1 text-xs text-white" @click="copy(envExample, 'env')">{{ copied === 'env' ? t('billing.whmcs.copied') : t('billing.whmcs.copy') }}</button>
                </div>
                <p class="mt-3 text-sm">{{ t('billing.whmcs.thenRun') }} <code class="rounded bg-slate-100 px-2 py-1 dark:bg-slate-800">php artisan config:cache</code></p>
                <div class="mt-5 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
                    <h3 class="text-sm font-semibold">{{ t('billing.whmcs.oneCommand') }}</h3>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ t('billing.whmcs.oneCommandHelp') }}</p>
                    <div class="mt-3">
                        <textarea v-model="setupCommand" rows="7" spellcheck="false" class="w-full rounded-lg border-0 bg-slate-950 p-4 font-mono text-xs leading-6 text-slate-100 focus:ring-2 focus:ring-blue-500"></textarea>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button type="button" class="rounded bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700" @click="generateSecret">
                                <i class="bi bi-key mr-1"></i> {{ t('billing.whmcs.generate') }}
                            </button>
                            <button type="button" class="rounded bg-slate-700 px-3 py-2 text-xs font-medium text-white hover:bg-slate-600" @click="copy(setupCommand, 'setup')">
                                <i class="bi bi-clipboard mr-1"></i> {{ copied === 'setup' ? t('billing.whmcs.copied') : t('billing.whmcs.copyCommand') }}
                            </button>
                            <button type="button" class="rounded border border-slate-300 px-3 py-2 text-xs font-medium hover:bg-white dark:border-slate-700 dark:hover:bg-slate-800" @click="setupCommand = baseSetupCommand()">{{ t('billing.whmcs.reset') }}</button>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-slate-600 dark:text-slate-400">{{ t('billing.whmcs.editHelp') }}</p>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">{{ t('billing.whmcs.operations') }}</h2>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span v-for="operation in operations" :key="operation.icon" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <i :class="['bi', operation.icon]" class="text-blue-600 dark:text-blue-400"></i>
                        {{ operation.label }}
                    </span>
                </div>
            </section>

            <section class="grid gap-6 md:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">{{ t('billing.whmcs.security') }}</h2>
                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-6 text-slate-600 dark:text-slate-400">
                        <li>{{ t('billing.whmcs.sec1') }}</li>
                        <li>{{ t('billing.whmcs.sec2') }}</li>
                        <li>{{ t('billing.whmcs.sec3', { seconds: status.timestampTolerance }) }}</li>
                        <li>{{ t('billing.whmcs.sec4', { seconds: status.ssoTtl }) }}</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">{{ t('billing.whmcs.trouble') }}</h2>
                    <dl class="mt-3 space-y-3 text-sm">
                        <div><dt class="font-medium">403 Forbidden</dt><dd class="text-slate-600 dark:text-slate-400">{{ t('billing.whmcs.e403') }}</dd></div>
                        <div><dt class="font-medium">401 Unauthorized</dt><dd class="text-slate-600 dark:text-slate-400">{{ t('billing.whmcs.e401') }}</dd></div>
                        <div><dt class="font-medium">409 Conflict</dt><dd class="text-slate-600 dark:text-slate-400">{{ t('billing.whmcs.e409') }}</dd></div>
                        <div><dt class="font-medium">422 Unprocessable</dt><dd class="text-slate-600 dark:text-slate-400">{{ t('billing.whmcs.e422') }}</dd></div>
                    </dl>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
