<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/composables/usePanelApi';
import ForwarderForm from './components/ForwarderForm.vue';
import ForwarderTable from './components/ForwarderTable.vue';
import CatchAllList from './components/CatchAllList.vue';

const props = defineProps({
    domains: { type: Array, default: () => [] },
    forwarders: { type: Array, default: () => [] },
    catchAlls: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const { panelRoute, errorMessage } = usePanelApi(t);
const busy = ref(false);
const error = ref('');
const notice = ref('');

const send = async (request, fallback) => {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const { data } = await request();
        notice.value = data.message;
        router.reload({ only: ['forwarders', 'catchAlls'] });
        return true;
    } catch (e) {
        error.value = errorMessage(e, fallback);
        return false;
    } finally {
        busy.value = false;
    }
};

const add = (payload, done) => send(() => window.axios.post(panelRoute('emails.forwarders.store'), payload), t('mailForwarders.addFailed')).then((ok) => ok && done());
const remove = (forwarder) => {
    if (!window.confirm(t('mailForwarders.removeConfirm', { address: forwarder.address }))) return;
    send(() => window.axios.delete(panelRoute('emails.forwarders.destroy', { id: forwarder.id })), t('mailForwarders.removeFailed'));
};
const setCatchAll = (domain, target) => send(() => window.axios.put(panelRoute('emails.catch-all'), { domain, target: target || null }), t('mailForwarders.catchAllFailed'));
</script>

<template>
    <Head :title="t('mailForwarders.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('mailForwarders.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('mailForwarders.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-5">
            <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"><i class="bi bi-exclamation-triangle-fill mr-2"></i>{{ error }}</div>
            <div v-if="notice" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300"><i class="bi bi-check-circle-fill mr-2"></i>{{ notice }}</div>
            <p v-if="!props.domains.length" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">{{ t('mailForwarders.noDomains') }}</p>
            <template v-else>
                <ForwarderForm :domains="props.domains" :busy="busy" @submit="add" />
                <ForwarderTable :forwarders="props.forwarders" :busy="busy" @remove="remove" />
                <CatchAllList :domains="props.domains" :catch-alls="props.catchAlls" :busy="busy" @save="setCatchAll" />
            </template>
        </div>
    </AuthenticatedLayout>
</template>
