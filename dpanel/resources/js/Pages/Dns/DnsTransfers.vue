<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/composables/usePanelApi';

/**
 * DNS → Zone Transfers (backlog M7). Secondary nameservers listed here copy
 * every zone of this server (AXFR) and are notified when one changes. The
 * zones this server copies from another primary are listed below.
 */
const props = defineProps({
    secondaries: { type: Array, default: () => [] },
    maxSecondaries: { type: Number, default: 10 },
    primaryZones: { type: Number, default: 0 },
    secondaryZones: { type: Array, default: () => [] },
    nameservers: { type: Array, default: () => [] },
});

const { t } = useI18n();
const { panelRoute } = usePanelApi(t);
const page = usePage();
const flash = computed(() => page.props.flash || {});

const form = useForm({ address: '', label: '' });
const removing = useForm({});
const full = computed(() => props.secondaries.length >= props.maxSecondaries);

const add = () => form.post(panelRoute('dns.transfers.store'), { preserveScroll: true, onSuccess: () => form.reset() });
const remove = (secondary) => {
    if (!window.confirm(t('dnsTransfers.removeConfirm', { address: secondary.address }))) return;
    removing.delete(panelRoute('dns.transfers.destroy', { id: secondary.id }), { preserveScroll: true });
};

const field = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
const card = 'rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50';
</script>

<template>
    <Head :title="t('dnsTransfers.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('dnsTransfers.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('dnsTransfers.subtitle') }}</p>
            </div>
        </template>

        <div class="max-w-3xl space-y-5">
            <div v-if="flash.error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ flash.error }}</div>
            <div v-if="flash.success" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ flash.success }}</div>

            <section :class="card">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('dnsTransfers.secondariesTitle') }}</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('dnsTransfers.secondariesHelp', { count: primaryZones }) }}</p>

                <ul v-if="secondaries.length" class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                    <li v-for="secondary in secondaries" :key="secondary.id" class="flex items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <p class="font-mono text-sm text-slate-800 dark:text-slate-100">{{ secondary.address }}</p>
                            <p v-if="secondary.label" class="truncate text-xs text-slate-500">{{ secondary.label }}</p>
                        </div>
                        <button type="button" :disabled="removing.processing" class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-900 dark:text-red-400" @click="remove(secondary)">{{ t('dnsTransfers.remove') }}</button>
                    </li>
                </ul>
                <p v-else class="mt-3 text-sm text-slate-500">{{ t('dnsTransfers.none') }}</p>

                <form v-if="!full" class="mt-4 grid gap-3 sm:grid-cols-5" @submit.prevent="add">
                    <label class="text-xs text-slate-500 sm:col-span-2">{{ t('dnsTransfers.address') }}
                        <input v-model="form.address" type="text" required placeholder="203.0.113.53" :class="field" />
                    </label>
                    <label class="text-xs text-slate-500 sm:col-span-2">{{ t('dnsTransfers.label') }}
                        <input v-model="form.label" type="text" maxlength="100" placeholder="ns2" :class="field" />
                    </label>
                    <div class="flex items-end">
                        <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">{{ t('dnsTransfers.add') }}</button>
                    </div>
                    <p v-if="form.errors.address" class="text-xs text-red-600 sm:col-span-5">{{ form.errors.address }}</p>
                </form>

                <div class="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                    <p class="font-semibold">{{ t('dnsTransfers.stepsTitle') }}</p>
                    <ol class="mt-1 list-decimal space-y-1 pl-4">
                        <li>{{ t('dnsTransfers.step1') }}</li>
                        <li>{{ t('dnsTransfers.step2', { nameservers: nameservers.join(', ') || 'DNS_OUR_NAMESERVERS' }) }}</li>
                        <li>{{ t('dnsTransfers.step3') }}</li>
                    </ol>
                </div>
            </section>

            <section :class="card">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('dnsTransfers.copiedTitle') }}</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('dnsTransfers.copiedHelp') }}</p>
                <table v-if="secondaryZones.length" class="mt-3 w-full text-left text-sm">
                    <thead class="text-xs text-slate-400">
                        <tr><th class="py-1 font-medium">{{ t('dnsTransfers.zone') }}</th><th class="py-1 font-medium">{{ t('dnsTransfers.primaries') }}</th><th class="py-1 font-medium">{{ t('dnsTransfers.lastCheck') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="zone in secondaryZones" :key="zone.name">
                            <td class="py-1.5 text-slate-800 dark:text-slate-100">{{ zone.name }}</td>
                            <td class="py-1.5 font-mono text-xs">{{ zone.primaries }}</td>
                            <td class="py-1.5 text-xs text-slate-500">{{ zone.last_check ? new Date(zone.last_check).toLocaleString() : t('dnsTransfers.notYet') }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="mt-3 text-sm text-slate-500">{{ t('dnsTransfers.noCopied') }}</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
