<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

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
const sections = [
    { title: 'Incoming mail — IMAP', subtitle: 'Keeps email synchronized across devices', icon: 'bi-inbox', theme: 'blue', rows: [{ label: 'Server', value: props.mailHost }, { label: 'Port', value: '993' }, { label: 'Encryption', value: 'SSL/TLS' }, { label: 'Authentication', value: 'Normal password' }, { label: 'Username', value: props.mailbox.email }] },
    { title: 'Outgoing mail — SMTP', subtitle: 'Authentication is required for sending', icon: 'bi-send', theme: 'violet', rows: [{ label: 'Server', value: props.mailHost }, { label: 'Port', value: '465' }, { label: 'Encryption', value: 'SSL/TLS' }, { label: 'Alternative', value: '587 with STARTTLS' }, { label: 'Username', value: props.mailbox.email }] },
];
</script>

<template>
    <Head :title="`Connect ${mailbox.email}`" />
    <AuthenticatedLayout>
        <template #header><div><h1 class="text-lg font-semibold">Connect Device</h1><p class="text-sm text-slate-500 dark:text-slate-400">Email client settings for {{ mailbox.email }}</p></div></template>
        <div class="space-y-4">
            <Link :href="panelRoute('emails.list')" class="inline-flex items-center text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400"><i class="bi bi-arrow-left mr-1"></i> Back to emails</Link>
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
                        <h2 class="font-semibold">Email this configuration</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Send the IMAP and SMTP settings and test outbound delivery. The mailbox password will not be included.</p>
                    </div>
                </div>
                <form class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start" @submit.prevent="sendConfiguration">
                    <div class="min-w-0 flex-1">
                        <label for="configuration-recipient" class="sr-only">Recipient email</label>
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
                        {{ sendForm.processing ? 'Sending…' : 'Send test & configuration' }}
                    </button>
                </form>
            </section>
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-200 p-4 dark:border-slate-800"><h2 class="font-semibold">Email client connection guide</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Use these settings in Outlook, Thunderbird, Apple Mail, Gmail, or a mobile mail app.</p></div>
                <div class="grid gap-4 p-4 lg:grid-cols-2">
                    <article v-for="section in sections" :key="section.title" class="rounded-xl border p-4" :class="section.theme === 'blue' ? 'border-blue-200 bg-blue-50/60 dark:border-blue-900 dark:bg-blue-950/30' : 'border-violet-200 bg-violet-50/60 dark:border-violet-900 dark:bg-violet-950/30'">
                        <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-lg text-white" :class="section.theme === 'blue' ? 'bg-blue-600' : 'bg-violet-600'"><i class="bi" :class="section.icon"></i></span><div><h3 class="font-semibold">{{ section.title }}</h3><p class="text-xs opacity-75">{{ section.subtitle }}</p></div></div>
                        <dl class="mt-4 divide-y text-sm dark:divide-slate-700"><div v-for="row in section.rows" :key="row.label" class="flex items-center justify-between gap-4 py-2.5"><dt class="opacity-70">{{ row.label }}</dt><dd class="flex min-w-0 items-center gap-2 font-mono font-medium"><span class="truncate">{{ row.value }}</span><button type="button" class="hover:opacity-70" :title="`Copy ${row.label}`" @click="copyValue(row.value)"><i :class="copiedValue === String(row.value) ? 'bi bi-check2' : 'bi bi-copy'"></i></button></dd></div></dl>
                    </article>
                </div>
                <div class="border-t border-slate-200 bg-slate-50 p-4 text-sm dark:border-slate-800 dark:bg-slate-950/50"><h3 class="font-semibold">Setup steps</h3><ol class="mt-2 list-decimal space-y-1.5 pl-5 text-slate-600 dark:text-slate-300"><li>Choose <strong>Add account</strong>, then <strong>IMAP</strong> or <strong>Manual setup</strong>.</li><li>Use <strong>{{ mailbox.email }}</strong> as the username for incoming and outgoing servers.</li><li>Use the mailbox password created in dPanel and enable SMTP authentication.</li><li>Accept only a valid TLS certificate matching <span class="font-mono">{{ mailHost }}</span>.</li></ol></div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
