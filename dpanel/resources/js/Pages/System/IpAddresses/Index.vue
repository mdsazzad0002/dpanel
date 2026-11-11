<script setup>
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/composables/usePanelApi';
import DiscoveredAddresses from './components/DiscoveredAddresses.vue';
import PoolAddressCard from './components/PoolAddressCard.vue';

// System → IP Addresses (backlog U5): the pool of server addresses websites
// and mail may use. Admins manage it; a reseller gives their addresses to customers.
defineProps({
    isAdmin: { type: Boolean, default: false },
    addresses: { type: Array, default: () => [] },
    discovered: { type: Array, default: null },
    discoveryError: { type: String, default: null },
    owners: { type: Object, default: () => ({ resellers: [], users: [] }) },
});
const { t } = useI18n();
const { panelRoute } = usePanelApi(t);
const page = usePage();
const errors = computed(() => Object.values(page.props.errors || {}));

const send = (method, name, id, data = {}) => {
    router[method](panelRoute(name, id ? { id } : {}), data, { preserveScroll: true });
};
const add = (data) => send('post', 'system.ip-addresses.store', null, data);
const save = (row, data) => send('patch', 'system.ip-addresses.update', row.id, data);
const makeMain = (row) => {
    if (window.confirm(t('ipPool.confirmMain', { address: row.address }))) send('post', 'system.ip-addresses.main', row.id);
};
const remove = (row) => {
    if (window.confirm(t('ipPool.confirmRemove', { address: row.address }))) send('delete', 'system.ip-addresses.destroy', row.id);
};
</script>

<template>
    <Head :title="t('ipPool.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('ipPool.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ isAdmin ? t('ipPool.help') : t('ipPool.resellerHelp') }}</p>
            </div>
        </template>

        <div class="max-w-5xl space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ page.props.flash.success }}</div>
            <div v-for="message in errors" :key="message" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ message }}</div>

            <DiscoveredAddresses v-if="isAdmin" :discovered="discovered" :error="discoveryError" :owners="owners" @add="add" />

            <p v-if="!addresses.length" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700">
                {{ isAdmin ? t('ipPool.emptyAdmin') : t('ipPool.emptyReseller') }}
            </p>
            <PoolAddressCard
                v-for="row in addresses"
                :key="row.id"
                :row="row"
                :is-admin="isAdmin"
                :owners="owners"
                @save="(data) => save(row, data)"
                @main="makeMain(row)"
                @remove="remove(row)"
            />
        </div>
    </AuthenticatedLayout>
</template>
