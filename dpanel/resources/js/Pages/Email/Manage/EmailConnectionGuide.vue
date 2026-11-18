<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppSmtpSnippets from './AppSmtpSnippets.vue';

const { t } = useI18n();

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => panelToken ? route(name, { token: panelToken, ...params }) : route(name, params);
const props = defineProps({
    mailbox: { type: Object, required: true },
    mailHost: { type: String, required: true },
});
const sendForm = useForm({
    recipient: page.props.auth?.user?.email ?? '',
});
const sendConfiguration = () => {
    sendForm.post(panelRoute('emails.connect-device.send', { id: props.mailbox.id }), {
        preserveScroll: true,
    });
};
const copiedValue = ref('');
const copyValue = async (value) => {
    await navigator.clipboard?.writeText(String(value));
    copiedValue.value = String(value);
    window.setTimeout(() => { if (copiedValue.value === String(value)) copiedValue.value = ''; }, 1500);
};
const sections = computed(() => [
    { title: t('email.connectGuide.imapTitle'), subtitle: t('email.connectGuide.imapSubtitle'), icon: 'bi-inbox', theme: 'blue', rows: [{ label: t('email.connectGuide.server'), value: props.mailHost }, { label: t('email.connectGuide.port'), value: '993' }, { label: t('email.connectGuide.encryption'), value: 'SSL/TLS' }, { label: t('email.connectGuide.authentication'), value: t('email.connectGuide.normalPassword') }, { label: t('email.connectGuide.username'), value: props.mailbox.email }] },
    { title: t('email.connectGuide.smtpTitle'), subtitle: t('email.connectGuide.smtpSubtitle'), icon: 'bi-send', theme: 'violet', rows: [{ label: t('email.connectGuide.server'), value: props.mailHost }, { label: t('email.connectGuide.port'), value: '465' }, { label: t('email.connectGuide.encryption'), value: 'SSL/TLS' }, { label: t('email.connectGuide.alternative'), value: t('email.connectGuide.alternativeValue') }, { label: t('email.connectGuide.username'), value: props.mailbox.email }] },
]);
</script>

<template>
    <Head :title="t('email.connectGuide.headTitle', { email: mailbox.email })" />
    <AuthenticatedLayout>
        <template #header><div><h1 class="text-lg font-semibold">{{ t('email.connectGuide.title') }}</h1><p class="text-sm text-slate-500 dark:text-slate-400">{{ t('email.connectGuide.subtitle', { email: mailbox.email }) }}</p></div></template>
        <div class="space-y-4">
            <Link :href="panelRoute('emails.list')" class="inline-flex items-center text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400"><i class="bi bi-arrow-left mr-1"></i> {{ t('email.connectGuide.back') }}</Link>
            <div v-if="page.props.flash?.success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                {{ page.props.flash.error }}
            </div>
            <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-emerald-600 text-white"><i class="bi bi-envelope-check"></i></span>
                    <div>
                        <h2 class="font-semibold">{{ t('email.connectGuide.sendTitle') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('email.connectGuide.sendHelp') }}</p>
                    </div>
                </div>
                <form class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start" @submit.prevent="sendConfiguration">
                    <div class="min-w-0 flex-1">
                        <label for="configuration-recipient" class="sr-only">{{ t('email.connectGuide.recipient') }}</label>
                        <input
                            id="configuration-recipient"
                            v-model="sendForm.recipient"
                            type="email"
                            required
                            autocomplete="email"
                            placeholder="recipient@example.com"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                        />
                        <p v-if="sendForm.errors.recipient" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ sendForm.errors.recipient }}</p>
                    </div>
                    <button type="submit" :disabled="sendForm.processing" class="inline-flex items-center justify-center gap-2 rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60">
                        <i :class="sendForm.processing ? 'bi bi-arrow-repeat animate-spin' : 'bi bi-send'"></i>
                        {{ sendForm.processing ? t('email.connectGuide.sending') : t('email.connectGuide.send') }}
                    </button>
                </form>
            </section>
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-200 p-4 dark:border-slate-800"><h2 class="font-semibold">{{ t('email.connectGuide.guideTitle') }}</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('email.connectGuide.guideHelp') }}</p></div>
                <div class="grid gap-4 p-4 lg:grid-cols-2">
                    <article v-for="section in sections" :key="section.title" class="rounded-xl border p-4" :class="section.theme === 'blue' ? 'border-blue-200 bg-blue-50/60 dark:border-blue-900 dark:bg-blue-950/30' : 'border-violet-200 bg-violet-50/60 dark:border-violet-900 dark:bg-violet-950/30'">
                        <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-lg text-white" :class="section.theme === 'blue' ? 'bg-blue-600' : 'bg-violet-600'"><i class="bi" :class="section.icon"></i></span><div><h3 class="font-semibold">{{ section.title }}</h3><p class="text-xs opacity-75">{{ section.subtitle }}</p></div></div>
                        <dl class="mt-4 divide-y text-sm dark:divide-slate-700"><div v-for="row in section.rows" :key="row.label" class="flex items-center justify-between gap-4 py-2.5"><dt class="opacity-70">{{ row.label }}</dt><dd class="flex min-w-0 items-center gap-2 font-mono font-medium"><span class="truncate">{{ row.value }}</span><button type="button" class="hover:opacity-70" :title="t('email.connectGuide.copy', { label: row.label })" @click="copyValue(row.value)"><i :class="copiedValue === String(row.value) ? 'bi bi-check2' : 'bi bi-copy'"></i></button></dd></div></dl>
                    </article>
                </div>
                <div class="border-t border-slate-200 bg-slate-50 p-4 text-sm dark:border-slate-800 dark:bg-slate-950/50"><h3 class="font-semibold">{{ t('email.connectGuide.steps') }}</h3><ol class="mt-2 list-decimal space-y-1.5 pl-5 text-slate-600 dark:text-slate-300"><li><i18n-t keypath="email.connectGuide.step1" scope="global"><template #add><strong>{{ t('email.connectGuide.addAccount') }}</strong></template><template #imap><strong>IMAP</strong></template><template #manual><strong>{{ t('email.connectGuide.manualSetup') }}</strong></template></i18n-t></li><li><i18n-t keypath="email.connectGuide.step2" scope="global"><template #email><strong>{{ mailbox.email }}</strong></template></i18n-t></li><li>{{ t('email.connectGuide.step3') }}</li><li><i18n-t keypath="email.connectGuide.step4" scope="global"><template #host><span class="font-mono">{{ mailHost }}</span></template></i18n-t></li></ol></div>
            </section>
            <AppSmtpSnippets :email="mailbox.email" :host="mailHost" />
        </div>
    </AuthenticatedLayout>
</template>
