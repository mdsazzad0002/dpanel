<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import FtpTabs from '@/Pages/Ftp/components/FtpTabs.vue';
import TestSteps from '@/Pages/Ftp/components/TestSteps.vue';
import FtpSetupGuide from '@/Pages/Ftp/components/FtpSetupGuide.vue';
import { clients, securityLabel } from '@/Pages/Ftp/ftp';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
    } catch (exception) { error.value = exception?.message || t('ftp.connect.testFailed'); }
    finally { testing.value = false; }
};
const settings = computed(() => [
    { label: t('ftp.connect.protocol'), value: t('ftp.connect.protocolValue') },
    { label: t('ftp.connect.encryption'), value: props.connection.tls ? t('ftp.connect.requireTls') : t('ftp.connect.plainFtp') },
    { label: t('ftp.labels.host'), value: props.connection.host },
    { label: t('ftp.labels.port'), value: String(props.connection.port ?? '') },
    { label: t('ftp.connect.logonType'), value: t('ftp.connect.logonValue') },
    { label: t('ftp.connect.transferMode'), value: t('ftp.connect.passive') },
]);
</script>

<template>
    <Head :title="t('ftp.connect.headTitle')" />
    <AuthenticatedLayout>
        <template #header>
            <div><h1 class="text-lg font-semibold">{{ t('ftp.tabs.connect') }}</h1><p class="text-sm text-slate-500 dark:text-slate-400">{{ t('ftp.connect.subtitle') }}</p></div>
        </template>
        <div class="space-y-5">
            <FtpTabs active="connect" />
            <div class="grid gap-5 xl:grid-cols-2">
                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="font-semibold">{{ t('ftp.connect.testTitle') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ connection.tls ? t('ftp.connect.testHelpTls', { host: connection.host, port: connection.port }) : t('ftp.connect.testHelp', { host: connection.host, port: connection.port }) }}</p>
                        </div>
                        <button :disabled="testing" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="runTest">
                            <i class="bi" :class="testing ? 'bi-arrow-repeat animate-spin' : 'bi-plug'"></i>{{ testing ? t('ftp.connect.testing') : t('ftp.connect.runTest') }}
                        </button>
                    </div>
                    <div v-if="error" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400">{{ error }}</div>
                    <div class="mt-4"><TestSteps :result="result" /></div>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">{{ t('ftp.connect.clientSettings') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ t('ftp.connect.clientHelp', { security: securityLabel(connection) }) }}</p>
                    <dl class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                        <div v-for="item in settings" :key="item.label" class="flex justify-between gap-4 py-2 text-sm">
                            <dt class="text-slate-500">{{ item.label }}</dt><dd class="text-right font-mono">{{ item.value }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="font-semibold">{{ t('ftp.connect.guideTitle') }}</h2>
                <p class="mt-1 mb-4 text-sm text-slate-500">{{ t('ftp.connect.guideHelp') }}</p>
                <FtpSetupGuide :server="server" :connection="connection" :cause="result?.diagnosis?.cause || ''" />
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="font-semibold">{{ t('ftp.connect.download') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ t('ftp.connect.downloadHelp') }}</p>
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
