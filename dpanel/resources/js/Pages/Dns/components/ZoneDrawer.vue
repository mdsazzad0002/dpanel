<script setup>
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import ZoneRecordsTable from '@/Pages/Dns/components/ZoneRecordsTable.vue';
import ZoneScanImport from '@/Pages/Dns/components/ZoneScanImport.vue';
import ZoneFileImport from '@/Pages/Dns/components/ZoneFileImport.vue';
import ZoneSettingsForm from '@/Pages/Dns/components/ZoneSettingsForm.vue';
import ConnectionBadge from '@/Pages/Dns/components/ConnectionBadge.vue';
import ZoneDnssec from '@/Pages/Dns/components/ZoneDnssec.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    zone: { type: Object, required: true },
    records: { type: Array, default: () => [] },
    connection: { type: Object, default: null },
    checking: { type: Boolean, default: false },
    transferUsers: { type: Array, default: () => [] },
    panelRoute: { type: Function, required: true },
    autoScan: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'recheck', 'find-replace']);

const panel = ref('');
const copied = ref('');
const zoneId = computed(() => props.zone.zone_uuid || props.zone.id);
const assigned = computed(() => props.connection?.assigned || []);

onMounted(() => {
    if (props.autoScan) panel.value = 'scan';
});

const copy = async (value) => {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = value;
        setTimeout(() => { if (copied.value === value) copied.value = ''; }, 1500);
    } catch {
        // Clipboard needs a secure context; the value stays selectable.
    }
};

const deleteZone = () => {
    if (!confirm(t('dns.zoneDrawer.deleteConfirm', { domain: props.zone.domain, count: props.records.length }))) return;
    router.delete(props.panelRoute('dns.zones.destroy', { id: zoneId.value }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <div class="fixed inset-0 z-40 bg-slate-950/40" @click="emit('close')"></div>
    <aside class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-slate-50 shadow-2xl dark:bg-slate-950 lg:w-[70vw]">
        <header class="border-b border-slate-200 bg-white px-6 py-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="truncate text-lg font-semibold">{{ zone.domain }}</h2>
                        <ConnectionBadge :connection="connection" :checking="checking" />
                        <span v-if="zone.status === 'disabled'" class="rounded-full bg-slate-200 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-700 dark:text-slate-300">{{ t('dns.zoneDrawer.disabled') }}</span>
                        <span v-if="zone.website_id" class="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-xs text-blue-700 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-300">{{ t('dns.zoneDrawer.websiteLinked') }}</span>
                    </div>
                    <p class="mt-0.5 text-xs text-slate-500">{{ t('dns.zoneDrawer.meta', { count: records.length, owner: zone.owner_name, creator: zone.creator_name }) }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="rounded-md bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700" @click="panel = 'scan'">{{ t('dns.zoneDrawer.scan') }}</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="panel = 'file'">{{ t('dns.zoneDrawer.importFile') }}</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('find-replace')">{{ t('dns.zoneDrawer.findReplace') }}</button>
                    <a :href="panelRoute('dns.zones.export', { id: zoneId })" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">{{ t('dns.zoneDrawer.export') }}</a>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="panel = 'settings'">{{ t('dns.zoneDrawer.settings') }}</button>
                    <button type="button" class="rounded-md border border-red-300 px-3 py-2 text-xs text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-950" @click="deleteZone">{{ t('dns.zoneDrawer.delete') }}</button>
                    <button type="button" :title="t('common.close')" class="ml-1 h-9 w-9 rounded-md border border-slate-300 text-lg dark:border-slate-700" @click="emit('close')">×</button>
                </div>
            </div>
        </header>

        <div class="flex-1 space-y-5 overflow-y-auto p-6">
            <section v-if="connection?.status !== 'connected'" class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-500/10">
                <h3 class="font-semibold text-amber-900 dark:text-amber-100">{{ t('dns.zoneDrawer.connect', { domain: zone.domain }) }}</h3>
                <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                    <template v-if="connection?.status === 'unregistered'">{{ t('dns.zoneDrawer.unregistered') }}</template>
                    <template v-else>{{ t('dns.zoneDrawer.replaceNs') }}</template>
                </p>
                <div v-if="assigned.length" class="mt-4 grid gap-2 sm:grid-cols-2">
                    <div v-for="ns in assigned" :key="ns" class="flex items-center justify-between gap-2 rounded-lg border border-amber-200 bg-white px-3 py-2 dark:border-amber-800 dark:bg-slate-900">
                        <code class="truncate text-sm">{{ ns }}</code>
                        <button type="button" class="shrink-0 rounded border border-slate-300 px-2 py-0.5 text-xs dark:border-slate-700" @click="copy(ns)">{{ copied === ns ? t('dns.zoneDrawer.copied') : t('dns.zoneDrawer.copy') }}</button>
                    </div>
                </div>
                <p v-else class="mt-3 text-sm text-amber-800 dark:text-amber-200"><i18n-t keypath="dns.zoneDrawer.noNs" scope="global"><template #env>DNS_OUR_NAMESERVERS</template></i18n-t></p>
                <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-amber-800 dark:text-amber-200">
                    <span v-if="connection?.current?.length">{{ t('dns.zoneDrawer.now', { list: connection.current.join(', ') }) }}</span>
                    <button type="button" :disabled="checking" class="rounded-md border border-amber-300 bg-white px-3 py-1.5 font-medium text-amber-900 disabled:opacity-60 dark:border-amber-700 dark:bg-transparent dark:text-amber-100" @click="emit('recheck')">{{ checking ? t('dns.zoneDrawer.checking') : t('dns.zoneDrawer.checkAgain') }}</button>
                </div>
            </section>
            <section v-else class="rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
                {{ t('dns.zoneDrawer.connected', { domain: zone.domain, list: connection.current.join(', ') }) }}
            </section>

            <ZoneDnssec :key="`dnssec-${zone.domain}`" :url="panelRoute('dns.zones.dnssec', { id: zoneId })" />

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('dns.zoneDrawer.records') }}</h3>
                <ZoneRecordsTable :key="zone.domain" :zone="zone" :records="records" :panel-route="panelRoute" />
            </section>
        </div>
    </aside>

    <ZoneScanImport
        v-if="panel === 'scan'"
        :zone="zone"
        :scan-url="panelRoute('dns.zones.scan', { id: zoneId })"
        :import-url="panelRoute('dns.zones.import-records', { id: zoneId })"
        @close="panel = ''"
    />
    <ZoneFileImport v-if="panel === 'file'" :zone="zone" :action="panelRoute('dns.zones.import', { id: zoneId })" @close="panel = ''" />
    <ZoneSettingsForm v-if="panel === 'settings'" :zone="zone" :transfer-users="transferUsers" :panel-route="panelRoute" @close="panel = ''" />
</template>
