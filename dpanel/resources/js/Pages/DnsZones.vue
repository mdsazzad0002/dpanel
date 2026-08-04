<script setup>
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    zones: { type: Array, default: () => [] },
    recordsByZone: { type: Object, default: () => ({}) },
    zoneDomains: { type: Array, default: () => [] },
    dnsEngine: { type: String, default: 'powerdns' },
    dnsProviderLabel: { type: String, default: 'DNS registry' },
    authoritativeMode: { type: String, default: 'database' },
    dynamicUpdatesAllowed: { type: Boolean, default: true },
    transferUsers: { type: Array, default: () => [] },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params));

const zoneEditingId = ref(null);
const recordEditingId = ref(null);
const creatingRecordFor = ref('');
const selectedZone = ref('');
const zoneCanvasOpen = ref(false);
const recordSaving = ref(false);
const recordMessage = ref('');
const cloneRecordMap = (records) => JSON.parse(JSON.stringify(records || {}));
const localRecordsByZone = ref(cloneRecordMap(props.recordsByZone));

const deleteZoneForm = useForm({});
const deleteRecordForm = useForm({});
const transferForm = useForm({ owner_user_id: '' });

const zoneForm = useForm({
    domain: '',
    type: 'master',
    email: '',
    refresh: 3600,
    retry: 600,
    expire: 1209600,
    minimum_ttl: 3600,
    status: 'active',
});

const recordForm = useForm({
    record_id: '',
    powerdns_record_id: null,
    zone_domain: '',
    type: 'A',
    name: '',
    content: '',
    ttl: 3600,
    priority: null,
    status: 'active',
});

const zoneRecordMap = computed(() => localRecordsByZone.value);
const selectedZoneObject = computed(() => props.zones.find((zone) => zone.domain === selectedZone.value) || null);

const displayedDomains = computed(() => {
    const fromZones = Array.isArray(props.zones) ? props.zones.map((zone) => zone.domain) : [];
    return [...new Set([...fromZones, ...(props.zoneDomains || [])])];
});

const recordRows = computed(() => {
    if (!selectedZone.value) return [];
    return zoneRecordMap.value[selectedZone.value] || [];
});

const resetZoneForm = () => {
    zoneEditingId.value = null;
    zoneForm.reset();
    zoneForm.type = 'master';
    zoneForm.refresh = 3600;
    zoneForm.retry = 600;
    zoneForm.expire = 1209600;
    zoneForm.minimum_ttl = 3600;
    zoneForm.status = 'active';
    zoneCanvasOpen.value = false;
};

const startCreateZone = () => {
    resetZoneForm();
    zoneCanvasOpen.value = true;
};

const resetRecordForm = () => {
    recordEditingId.value = null;
    creatingRecordFor.value = '';
    recordForm.reset();
    recordForm.type = 'A';
    recordForm.ttl = 3600;
    recordForm.status = 'active';
    recordForm.priority = null;
    recordForm.record_id = '';
    recordForm.powerdns_record_id = null;
};

const editZone = (item) => {
    zoneEditingId.value = item.id;
    zoneForm.domain = item.domain ?? '';
    zoneForm.type = item.type ?? 'master';
    zoneForm.email = item.email ?? '';
    zoneForm.refresh = Number(item.refresh ?? 3600);
    zoneForm.retry = Number(item.retry ?? 600);
    zoneForm.expire = Number(item.expire ?? 1209600);
    zoneForm.minimum_ttl = Number(item.minimum_ttl ?? 3600);
    zoneForm.status = item.status ?? 'active';
    selectedZone.value = item.domain ?? selectedZone.value;
    recordForm.zone_domain = item.domain ?? recordForm.zone_domain;
    transferForm.owner_user_id = item.owner_user_id ?? '';
    zoneCanvasOpen.value = true;
};

const transferZone = () => {
    if (!zoneEditingId.value || !transferForm.owner_user_id) return;
    transferForm.patch(panelRoute('dns.zones.transfer', { id: zoneEditingId.value }), { onSuccess: () => { zoneCanvasOpen.value = false; } });
};

const fillRecordForm = (item) => {
    recordForm.record_id = item.id ?? '';
    recordForm.powerdns_record_id = item.powerdns_record_id ?? null;
    recordForm.zone_domain = item.zone_domain ?? selectedZone.value ?? '';
    recordForm.type = item.type ?? 'A';
    recordForm.name = item.name ?? '';
    recordForm.content = item.content ?? '';
    recordForm.ttl = Number(item.ttl ?? 3600);
    recordForm.priority = item.priority ?? null;
    recordForm.status = item.status ?? 'active';
};

const startCreateRecord = (domain) => {
    creatingRecordFor.value = domain;
    recordEditingId.value = null;
    recordForm.zone_domain = domain;
    recordForm.type = 'A';
    recordForm.name = '';
    recordForm.content = '';
    recordForm.ttl = 3600;
    recordForm.priority = null;
    recordForm.status = 'active';
    recordForm.record_id = '';
    recordForm.powerdns_record_id = null;
};

const startEditRecord = (item) => {
    recordEditingId.value = item.id;
    creatingRecordFor.value = '';
    fillRecordForm(item);
    selectedZone.value = item.zone_domain ?? selectedZone.value;
};

const submitZone = () => {
    if (zoneEditingId.value) {
        zoneForm.patch(panelRoute('dns.zones.update', { id: zoneEditingId.value }), { onSuccess: resetZoneForm });
        return;
    }
    zoneForm.post(panelRoute('dns.zones.store'), { onSuccess: resetZoneForm });
};

const submitRecord = async () => {
    if (recordEditingId.value) {
        recordSaving.value = true;
        recordMessage.value = '';
        recordForm.clearErrors();
        try {
            const response = await window.axios.patch(
                panelRoute('dns.records.update', { id: recordEditingId.value }),
                recordForm.data(),
                { headers: { Accept: 'application/json' } },
            );
            const records = localRecordsByZone.value[recordForm.zone_domain] || [];
            const index = records.findIndex((record) => String(record.id) === String(recordEditingId.value));
            if (index >= 0 && response.data?.record) {
                records[index] = { ...records[index], ...response.data.record, id: records[index].id };
            }
            recordMessage.value = response.data?.message || 'DNS record updated.';
            resetRecordForm();
        } catch (error) {
            const errors = error.response?.data?.errors || {};
            Object.entries(errors).forEach(([field, messages]) => recordForm.setError(field, Array.isArray(messages) ? messages[0] : messages));
            recordMessage.value = error.response?.data?.message || 'DNS record update failed.';
        } finally {
            recordSaving.value = false;
        }
        return;
    }

    recordForm.post(panelRoute('dns.records.store'), {
        onSuccess: resetRecordForm,
    });
};

const deleteZone = (id) => {
    if (!confirm('Delete this zone and its records?')) return;
    deleteZoneForm.delete(panelRoute('dns.zones.destroy', { id }), {
        onSuccess: () => { selectedZone.value = ''; },
    });
};

const deleteRecord = (id) => {
    if (!confirm('Delete this record?')) return;
    deleteRecordForm.delete(panelRoute('dns.records.destroy', { id }));
};

const recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV'];
const isInlineCreateRow = (domain) => creatingRecordFor.value === domain;
const isInlineEditRow = (record) => recordEditingId.value === record.id;

watch(
    () => props.recordsByZone,
    (records) => { localRecordsByZone.value = cloneRecordMap(records); },
    { deep: true },
);

watch(
    () => props.zones,
    (zones) => {
        const selectionExists = Array.isArray(zones) && zones.some((zone) => zone.domain === selectedZone.value);
        if (!selectionExists && Array.isArray(zones) && zones.length > 0) {
            selectedZone.value = zones[0].domain;
        } else if (!selectionExists) {
            selectedZone.value = '';
        }
    },
    { immediate: true, deep: true },
);

watch(
    selectedZone,
    (domain) => {
        if (!domain) return;

        const zone = props.zones.find((item) => item.domain === domain);
        if (zone) {
            zoneForm.domain = zone.domain ?? '';
            zoneForm.type = zone.type ?? 'master';
            zoneForm.email = zone.email ?? '';
            zoneForm.refresh = Number(zone.refresh ?? 3600);
            zoneForm.retry = Number(zone.retry ?? 600);
            zoneForm.expire = Number(zone.expire ?? 1209600);
            zoneForm.minimum_ttl = Number(zone.minimum_ttl ?? 3600);
            zoneForm.status = zone.status ?? 'active';
        }

        recordForm.zone_domain = domain;
    },
    { immediate: true },
);
</script>

<template>
    <Head title="DNS Zones" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex w-full items-center justify-between gap-3">
                <div>
                <h1 class="text-lg font-semibold">DNS Zones</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ dnsProviderLabel }} · {{ dnsEngine }} · {{ authoritativeMode }} {{ dynamicUpdatesAllowed ? '· dynamic updates on' : '· dynamic updates off' }}</p>
                </div>
                <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="startCreateZone">+ Create Zone</button>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>
            <div v-if="recordMessage" class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ recordMessage }}</div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Select Domain</h2>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Choose a domain to edit its zone and records inline.</p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs text-slate-600 dark:text-slate-300">
                        <span class="rounded-full border border-slate-200 px-3 py-1 dark:border-slate-700">{{ zones.length }} zones</span>
                        <span class="rounded-full border border-slate-200 px-3 py-1 dark:border-slate-700">{{ displayedDomains.length }} domains</span>
                    </div>
                </div>

                <div class="mt-4 max-w-xl">
                    <label class="mb-1 block text-sm">Domain</label>
                    <select v-model="selectedZone" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Select domain</option>
                        <option v-for="domain in displayedDomains" :key="`zone-select-${domain}`" :value="domain">{{ domain }}</option>
                    </select>
                </div>
            </div>

            <div v-if="selectedZoneObject" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold">{{ selectedZoneObject.domain }}</h2>
                            <span class="rounded-full border border-slate-200 px-2 py-0.5 text-[11px] font-medium capitalize text-slate-600 dark:border-slate-700 dark:text-slate-300">{{ selectedZoneObject.source }}</span>
                            <span v-if="selectedZoneObject.website_id" class="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 dark:border-blue-800 dark:bg-blue-500/10 dark:text-blue-300">Website linked</span>
                            <span v-else class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">DNS only</span>
                        </div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Created by {{ selectedZoneObject.creator_name }} · Owner {{ selectedZoneObject.owner_name }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="editZone(selectedZoneObject)">Edit Zone</button>
                        <Link :href="panelRoute('dns.cloudflare.review', { domain: selectedZoneObject.domain })" class="rounded-md border border-indigo-300 px-3 py-2 text-xs text-indigo-700 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-300">Review Cloudflare Sync</Link>
                        <button type="button" :disabled="deleteZoneForm.processing" class="rounded-md border border-red-300 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-950" @click="deleteZone(selectedZoneObject.zone_uuid || selectedZoneObject.id)">Delete Zone</button>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-px overflow-hidden rounded-md border border-slate-200 bg-slate-200 text-xs dark:border-slate-700 dark:bg-slate-700 sm:grid-cols-4">
                    <div class="bg-white px-3 py-2.5 dark:bg-slate-900"><span class="text-slate-400">DNSSEC</span><strong class="ml-2 font-medium" :class="selectedZoneObject.dnssec_enabled ? 'text-emerald-600' : 'text-slate-500'">{{ selectedZoneObject.dnssec_enabled ? 'Enabled' : 'Ready' }}</strong></div>
                    <div class="bg-white px-3 py-2.5 dark:bg-slate-900"><span class="text-slate-400">Proxy</span><strong class="ml-2 font-medium" :class="selectedZoneObject.proxy_enabled ? 'text-emerald-600' : 'text-slate-500'">{{ selectedZoneObject.proxy_enabled ? 'Enabled' : 'Ready' }}</strong></div>
                    <div class="bg-white px-3 py-2.5 dark:bg-slate-900"><span class="text-slate-400">Logs</span><strong class="ml-2 font-medium" :class="selectedZoneObject.logging_enabled ? 'text-emerald-600' : 'text-slate-500'">{{ selectedZoneObject.logging_enabled ? 'Enabled' : 'Ready' }}</strong></div>
                    <div class="bg-white px-3 py-2.5 dark:bg-slate-900"><span class="text-slate-400">Analytics</span><strong class="ml-2 font-medium" :class="selectedZoneObject.analytics_enabled ? 'text-emerald-600' : 'text-slate-500'">{{ selectedZoneObject.analytics_enabled ? 'Enabled' : 'Ready' }}</strong></div>
                </div>

                <div v-if="zoneCanvasOpen" class="fixed inset-0 z-40 bg-slate-950/40" @click="zoneCanvasOpen = false"></div>
                <form v-if="zoneCanvasOpen" class="fixed inset-y-0 right-0 z-50 grid w-full max-w-lg content-start gap-4 overflow-y-auto bg-white p-6 shadow-2xl dark:bg-slate-900" @submit.prevent="submitZone">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-4 dark:border-slate-700">
                        <div><h2 class="text-base font-semibold">{{ zoneEditingId ? 'Edit DNS Zone' : 'Create DNS Zone' }}</h2><p class="text-xs text-slate-500">Zone settings and ownership</p></div>
                        <button type="button" title="Close" class="h-9 w-9 rounded-md border border-slate-300 text-lg dark:border-slate-700" @click="zoneCanvasOpen = false">×</button>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Domain</label>
                        <input v-model="zoneForm.domain" type="text" placeholder="example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Type</label>
                        <select v-model="zoneForm.type" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="master">master</option>
                            <option value="slave">slave</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">SOA Email</label>
                        <input v-model="zoneForm.email" type="email" placeholder="hostmaster@example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Status</label>
                        <select v-model="zoneForm.status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="active">active</option>
                            <option value="disabled">disabled</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Refresh</label>
                        <input v-model.number="zoneForm.refresh" type="number" min="300" max="86400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Retry</label>
                        <input v-model.number="zoneForm.retry" type="number" min="60" max="86400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Expire</label>
                        <input v-model.number="zoneForm.expire" type="number" min="3600" max="2592000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Minimum TTL</label>
                        <input v-model.number="zoneForm.minimum_ttl" type="number" min="60" max="86400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="submit" :disabled="zoneForm.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                            {{ zoneEditingId ? 'Save Zone' : 'Create Zone' }}
                        </button>
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="zoneCanvasOpen = false">
                            Cancel
                        </button>
                    </div>
                    <div v-if="zoneEditingId && selectedZoneObject?.can_transfer" class="mt-2 border-t border-slate-200 pt-5 dark:border-slate-700">
                        <h3 class="text-sm font-semibold">Transfer ownership</h3>
                        <p class="mb-3 text-xs text-slate-500">Creator history remains unchanged after transfer.</p>
                        <select v-model="transferForm.owner_user_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option value="">Select user</option>
                            <option v-for="user in transferUsers" :key="user.id" :value="user.id">{{ user.name }} · {{ user.email }}</option>
                        </select>
                        <button type="button" :disabled="transferForm.processing || !transferForm.owner_user_id" class="mt-3 rounded-md border border-amber-400 px-4 py-2 text-sm font-medium text-amber-700 disabled:opacity-50 dark:text-amber-300" @click="transferZone">Transfer Zone</button>
                    </div>
                </form>
            </div>

            <div v-if="selectedZoneObject" class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">DNS Records</h2>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Add and edit records directly inside the table.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link :href="panelRoute('dns.cloudflare.review')" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900">Review All for Cloudflare</Link>
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="startCreateRecord(selectedZoneObject.domain)">
                            + Add record
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">Content</th>
                                <th class="px-4 py-3">TTL</th>
                                <th class="px-4 py-3">Priority</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="isInlineCreateRow(selectedZoneObject.domain)" class="border-t border-slate-200 bg-slate-50/70 dark:border-slate-800 dark:bg-slate-800/60">
                                <td class="px-4 py-3 align-top">
                                    <select v-model="recordForm.type" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                                        <option v-for="type in recordTypes" :key="`create-type-${type}`" :value="type">{{ type }}</option>
                                    </select>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <input v-model="recordForm.name" type="text" placeholder="@ or www" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <input v-model="recordForm.content" type="text" placeholder="IP, hostname, or text value" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <input v-model.number="recordForm.ttl" type="number" min="1" max="86400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <input v-model.number="recordForm.priority" type="number" min="0" max="65535" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <select v-model="recordForm.status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                                        <option value="active">active</option>
                                        <option value="disabled">disabled</option>
                                    </select>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" :disabled="recordForm.processing" class="rounded-md bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-60" @click="submitRecord">
                                            Add
                                        </button>
                                        <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="resetRecordForm">
                                            Cancel
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!isInlineCreateRow(selectedZoneObject.domain)">
                                <td colspan="7" class="px-4 py-3">
                                    <button type="button" class="w-full rounded-lg border border-dashed border-slate-300 px-4 py-3 text-left text-sm text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800/60" @click="startCreateRecord(selectedZoneObject.domain)">
                                        + Add a DNS record inline
                                    </button>
                                </td>
                            </tr>

                            <tr v-for="record in recordRows" :key="record.id" class="border-t border-slate-200 dark:border-slate-800">
                                <template v-if="isInlineEditRow(record)">
                                    <td class="px-4 py-3 align-top">
                                        <select v-model="recordForm.type" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                                            <option v-for="type in recordTypes" :key="`edit-type-${record.id}-${type}`" :value="type">{{ type }}</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <input v-model="recordForm.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <input v-model="recordForm.content" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <input v-model.number="recordForm.ttl" type="number" min="1" max="86400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <input v-model.number="recordForm.priority" type="number" min="0" max="65535" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <select v-model="recordForm.status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                                            <option value="active">active</option>
                                            <option value="disabled">disabled</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <button type="button" :disabled="recordSaving" class="rounded-md bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-60" @click="submitRecord">
                                                Save
                                            </button>
                                            <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="resetRecordForm">
                                                Cancel
                                            </button>
                                        </div>
                                    </td>
                                </template>
                                <template v-else>
                                    <td class="px-4 py-3">{{ record.type }}</td>
                                    <td class="px-4 py-3">{{ record.name }}</td>
                                    <td class="px-4 py-3 break-all">{{ record.content }}</td>
                                    <td class="px-4 py-3">{{ record.ttl }}</td>
                                    <td class="px-4 py-3">{{ record.priority ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ record.status }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="startEditRecord(record)">Edit</button>
                                            <button type="button" class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400" @click="deleteRecord(record.id)">Delete</button>
                                        </div>
                                    </td>
                                </template>
                            </tr>

                            <tr v-if="recordRows.length === 0 && !isInlineCreateRow(selectedZoneObject.domain)">
                                <td colspan="7" class="px-4 py-6 text-center text-slate-500">No records in this zone.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="zones.length === 0" class="rounded-xl border border-slate-200 bg-white p-6 text-center text-slate-500 dark:border-slate-800 dark:bg-slate-900">
                No DNS zones found.
            </div>
        </div>
    </AuthenticatedLayout>
</template>
