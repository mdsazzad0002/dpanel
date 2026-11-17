<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    status: { type: Object, required: true },
    selectedId: { type: String, default: null },
    mailboxes: { type: Array, default: () => [] },
    suggestedEmail: { type: String, default: '' },
    testRecipient: { type: String, default: '' },
});

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (panelToken ? route(name, { token: panelToken, ...params }) : route(name, params));

const form = useForm({ mailbox_id: props.selectedId || '' });
const chosen = computed(() => props.mailboxes.find((mailbox) => mailbox.id === form.mailbox_id) || null);
const save = () => form.put(panelRoute('emails.system-mail.update'), { preserveScroll: true });
const remove = () => {
    if (!window.confirm(t('email.systemMail.removeConfirm'))) return;
    router.delete(panelRoute('emails.system-mail.destroy'), { preserveScroll: true });
};

const testTo = ref(props.testRecipient);
const testing = ref(false);
const testResult = ref(null);
const sendTest = async () => {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await window.axios.post(panelRoute('emails.system-mail.test'), { to: testTo.value }, { headers: { Accept: 'application/json' } });
        testResult.value = { ok: true, message: data.message };
    } catch (error) {
        testResult.value = { ok: false, message: error?.response?.data?.message || error?.message || t('email.systemMail.sendFailed') };
    } finally {
        testing.value = false;
    }
};
</script>

<template>
    <Head :title="t('email.systemMail.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('email.systemMail.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('email.systemMail.subtitle') }}</p>
            </div>
        </template>

        <div class="max-w-3xl space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <section
                :class="status.ready
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100'
                    : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-100'"
                class="flex items-start gap-3 rounded-xl border p-4 text-sm"
            >
                <i :class="['bi mt-0.5 text-xl', status.ready ? 'bi-check-circle-fill' : 'bi-envelope-exclamation']" aria-hidden="true"></i>
                <div>
                    <p class="font-semibold">
                        <template v-if="status.ready && status.source === 'system'">{{ t('email.systemMail.sentFrom', { email: status.email }) }}</template>
                        <template v-else-if="status.ready">{{ t('email.systemMail.usesEnv', { email: status.email }) }}</template>
                        <template v-else>{{ t('email.systemMail.notDelivered') }}</template>
                    </p>
                    <p v-if="status.problem" class="mt-1">{{ status.problem }}</p>
                    <p v-else-if="status.source === 'env'" class="mt-1">{{ t('email.systemMail.replaces') }}</p>
                </div>
            </section>

            <form class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="save">
                <div>
                    <label for="system-mailbox" class="mb-1 block text-sm font-medium">{{ t('email.systemMail.mailbox') }}</label>
                    <select id="system-mailbox" v-model="form.mailbox_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t('email.systemMail.select') }}</option>
                        <option v-for="mailbox in mailboxes" :key="mailbox.id" :value="mailbox.id" :disabled="Boolean(mailbox.problem)">
                            {{ mailbox.email }}{{ mailbox.problem ? t('email.systemMail.notUsable') : '' }}
                        </option>
                    </select>
                    <p v-if="chosen?.problem" class="mt-1 text-xs text-red-600">{{ chosen.problem }}</p>
                    <p v-if="form.errors.mailbox_id" class="mt-1 text-xs text-red-600">{{ form.errors.mailbox_id }}</p>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        <i18n-t keypath="email.systemMail.help" scope="global"><template #address><code>{{ suggestedEmail || 'noreply@your-panel-domain' }}</code></template><template #link><Link :href="panelRoute('emails.guide')" class="text-blue-600 hover:underline dark:text-blue-400">{{ t('email.systemMail.records') }}</Link></template></i18n-t>
                    </p>
                    <p v-if="mailboxes.length === 0" class="mt-2 text-sm">
                        {{ t('email.systemMail.noMailbox') }}
                        <Link :href="panelRoute('emails.create')" class="font-medium text-blue-600 hover:underline dark:text-blue-400">{{ suggestedEmail ? t('email.systemMail.createFirst', { email: suggestedEmail }) : t('email.systemMail.createOneFirst') }}</Link>.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" :disabled="form.processing || !form.mailbox_id || Boolean(chosen?.problem)" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        {{ t('email.systemMail.use') }}
                    </button>
                    <Link :href="panelRoute('emails.create')" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">{{ t('email.systemMail.create') }}</Link>
                    <button v-if="selectedId" type="button" class="rounded-md border border-red-300 px-4 py-2 text-sm text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400" @click="remove">{{ t('email.systemMail.remove') }}</button>
                </div>
            </form>

            <section class="space-y-3 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold">{{ t('email.systemMail.testTitle') }}</h2>
                <div class="flex flex-wrap gap-2">
                    <input v-model="testTo" type="email" placeholder="you@example.com" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <button type="button" :disabled="testing || !testTo || !status.ready" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="sendTest">
                        {{ testing ? t('email.systemMail.sending') : t('email.systemMail.sendTest') }}
                    </button>
                </div>
                <p v-if="testResult" :class="testResult.ok ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'" class="text-sm">{{ testResult.message }}</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
