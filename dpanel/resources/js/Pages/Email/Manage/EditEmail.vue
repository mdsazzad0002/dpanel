<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AutoresponderCard from './components/AutoresponderCard.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const page = usePage();
const panelToken = page.props.panel?.token;

const props = defineProps({
    mailbox: {
        type: Object,
        required: true,
    },
    websiteDomains: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    domain: props.mailbox.domain ?? '',
    mailbox: props.mailbox.mailbox ?? '',
    password: '',
    quota_mb: Number(props.mailbox.quota_mb ?? 1024),
    forwarding_to: props.mailbox.forwarding_to ?? '',
    delivery_mode: props.mailbox.delivery_mode || 'live',
});

const domainOptions = computed(() => {
    const current = String(form.domain || '').trim();
    const list = Array.isArray(props.websiteDomains) ? [...props.websiteDomains] : [];

    return current && !list.includes(current) ? [current, ...list] : list;
});

const submit = () => {
    form.patch(panelToken ? route('emails.update', { token: panelToken, id: props.mailbox.id }) : route('emails.update', props.mailbox.id));
};
</script>

<template>
    <Head :title="t('email.edit.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('email.edit.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('email.edit.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end">
                <Link :href="panelToken ? route('emails.list', { token: panelToken }) : route('emails.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    {{ t('email.edit.back') }}
                </Link>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-2 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm">{{ t('email.form.domain') }}</label>
                    <select v-model="form.domain" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t('email.form.selectDomain') }}</option>
                        <option v-for="domain in domainOptions" :key="domain" :value="domain">{{ domain }}</option>
                    </select>
                    <p v-if="form.errors.domain" class="mt-1 text-xs text-red-600">{{ form.errors.domain }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">{{ t('email.form.mailbox') }}</label>
                    <input v-model="form.mailbox" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.mailbox" class="mt-1 text-xs text-red-600">{{ form.errors.mailbox }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">{{ t('email.form.password') }}</label>
                    <input v-model="form.password" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">{{ t('email.form.quota') }}</label>
                    <input v-model.number="form.quota_mb" type="number" min="1" max="102400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.quota_mb" class="mt-1 text-xs text-red-600">{{ form.errors.quota_mb }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">{{ t('email.form.forward') }}</label>
                    <input v-model="form.forwarding_to" type="email" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="form.errors.forwarding_to" class="mt-1 text-xs text-red-600">{{ form.errors.forwarding_to }}</p>
                </div>
                <div class="md:col-span-2">
                    <span class="mb-1 block text-sm">{{ t('email.form.mode') }}</span>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-2 rounded-md border p-3 text-sm" :class="form.delivery_mode === 'live' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/30' : 'border-slate-300 dark:border-slate-700'">
                            <input v-model="form.delivery_mode" type="radio" value="live" class="mt-0.5" />
                            <span><span class="font-medium">{{ t('email.form.live') }}</span><span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('email.form.liveHelp') }}</span></span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-2 rounded-md border p-3 text-sm" :class="form.delivery_mode === 'sandbox' ? 'border-amber-500 bg-amber-50 dark:bg-amber-950/30' : 'border-slate-300 dark:border-slate-700'">
                            <input v-model="form.delivery_mode" type="radio" value="sandbox" class="mt-0.5" />
                            <span><span class="font-medium">{{ t('email.form.sandbox') }}</span><span class="block text-xs text-slate-500 dark:text-slate-400">{{ t('email.form.sandboxHelp') }}</span></span>
                        </label>
                    </div>
                    <p v-if="form.errors.delivery_mode" class="mt-1 text-xs text-red-600">{{ form.errors.delivery_mode }}</p>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        {{ t('email.edit.submit') }}
                    </button>
                </div>
            </form>
            <AutoresponderCard :mailbox-id="String(props.mailbox.id)" />
        </div>
    </AuthenticatedLayout>
</template>
