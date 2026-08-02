<script setup>
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    zones: { type: Array, default: () => [] },
    recordsByZone: { type: Object, default: () => ({}) },
    zoneDomains: { type: Array, default: () => [] },
    websiteDomains: { type: Array, default: () => [] },
    dnsEngine: { type: String, default: 'powerdns' },
    dnsProviderLabel: { type: String, default: 'DNS registry' },
    authoritativeMode: { type: String, default: 'database' },
    dynamicUpdatesAllowed: { type: Boolean, default: true },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params));

const zoneEditingId = ref(null);
const recordEditingId = ref(null);
const creatingRecordFor = ref('');
const selectedZone = ref('');

const deleteZoneForm = useForm({});
const deleteRecordForm = useForm({});
const syncAllForm = useForm({ domain: '' });
const syncZoneForm = useForm({ domain: '' });

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
    zone_domain: '',
    type: 'A',
    name: '',
    content: '',
    ttl: 3600,
    priority: null,
    status: 'active',
});

const zoneRecordMap = computed(() => props.recordsByZone || {});
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
};

const resetRecordForm = () => {
    recordEditingId.value = null;
    creatingRecordFor.value = '';
    recordForm.reset();
    recordForm.type = 'A';
    recordForm.ttl = 3600;
    recordForm.status = 'active';
    recordForm.priority = null;
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
};

const fillRecordForm = (item) => {
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

const submitRecord = () => {
    if (recordEditingId.value) {
        recordForm.patch(panelRoute('dns.records.update', { id: recordEditingId.value }), { onSuccess: resetRecordForm });
        return;
    }

    recordForm.post(panelRoute('dns.records.store'), {
        onSuccess: resetRecordForm,
    });
};

const deleteZone = (id) => {
    if (!confirm('Delete this zone and its records?')) return;
    deleteZoneForm.delete(panelRoute('dns.zones.destroy', { id }));
};

const deleteRecord = (id) => {
    if (!confirm('Delete this record?')) return;
    deleteRecordForm.delete(panelRoute('dns.records.destroy', { id }));
};

const syncAll = () => {
    syncAllForm.domain = '';
    syncAllForm.post(panelRoute('dns.cloudflare.sync'));
};

const syncZone = (domain) => {
    syncZoneForm.domain = domain;
    syncZoneForm.post(panelRoute('dns.cloudflare.sync'));
};

const recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV'];
const isInlineCreateRow = (domain) => creatingRecordFor.value === domain;
const isInlineEditRow = (record) => recordEditingId.value === record.id;

watch(
    () => props.zones,
    (zones) => {
        if (!selectedZone.value && Array.isArray(zones) && zones.length > 0) {
            selectedZone.value = zones[0].domain;
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
            <div>
                <h1 class="text-lg font-semibold">DNS Zones</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ dnsProviderLabel }} · {{ dnsEngine }} · {{ authoritativeMode }} {{ dynamicUpdatesAllowed ? '· dynamic updates on' : '· dynamic updates off' }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

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
                        <h2 class="text-base font-semibold">{{ selectedZoneObject.domain }}</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Inline edit this domain's zone settings.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="editZone(selectedZoneObject)">Load for Edit</button>
                        <button type="button" :disabled="syncZoneForm.processing" class="rounded-md border border-indigo-300 px-3 py-2 text-xs text-indigo-700 hover:bg-indigo-50 disabled:opacity-60 dark:border-indigo-700 dark:text-indigo-300" @click="syncZone(selectedZoneObject.domain)">Sync</button>
                    </div>
                </div>

                <form class="mt-4 grid gap-4 md:grid-cols-3" @submit.prevent="submitZone">
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
                    <div class="md:col-span-3 flex items-center gap-2">
                        <button type="submit" :disabled="zoneForm.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                            {{ zoneEditingId ? 'Save Zone' : 'Create Zone' }}
                        </button>
                        <button v-if="zoneEditingId" type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="resetZoneForm">
                            Cancel Edit
                        </button>
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
                        <button type="button" :disabled="syncAllForm.processing" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-60 dark:bg-white dark:text-slate-900" @click="syncAll">
                            Sync All
                        </button>
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
                                    <input v-model.number="recordForm.ttl" type="number" min="60" max="86400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
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
                                        <input v-model.number="recordForm.ttl" type="number" min="60" max="86400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
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
