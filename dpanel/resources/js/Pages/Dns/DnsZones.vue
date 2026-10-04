<script setup>
import { computed, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ZoneDrawer from '@/Pages/Dns/components/ZoneDrawer.vue';
import ZoneSettingsForm from '@/Pages/Dns/components/ZoneSettingsForm.vue';
import ConnectionBadge from '@/Pages/Dns/components/ConnectionBadge.vue';
import ZoneFindReplace from '@/Pages/Dns/components/ZoneFindReplace.vue';
import { Head, usePage } from '@inertiajs/vue3';

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

const search = ref('');
const openDomain = ref('');
const autoScan = ref(false);
const adding = ref(false);
// null: closed; '' : all zones; a zone id: that zone.
const replacing = ref(null);
const connections = ref({});
const checking = ref(false);

const openZone = computed(() => props.zones.find((zone) => zone.domain === openDomain.value) || null);
const records = (domain) => props.recordsByZone[domain] || [];
const rows = computed(() => {
    const term = search.value.trim().toLowerCase();
    return [...props.zones]
        .filter((zone) => !term || zone.domain.includes(term) || String(zone.owner_name || '').toLowerCase().includes(term))
        .sort((a, b) => a.domain.localeCompare(b.domain));
});
const connectedCount = computed(() => props.zones.filter((zone) => connections.value[zone.domain]?.status === 'connected').length);

const checkConnections = async () => {
    if (!props.zones.length) return;
    checking.value = true;
    try {
        const { data } = await window.axios.get(panelRoute('dns.zones.connection'), { headers: { Accept: 'application/json' } });
        connections.value = data.zones || {};
    } catch {
        // Badges stay "Unknown"; the zones still work.
    } finally {
        checking.value = false;
    }
};
onMounted(checkConnections);

const open = (domain, scan = false) => {
    autoScan.value = scan;
    openDomain.value = domain;
};

const created = ({ domains, scan }) => {
    adding.value = false;
    checkConnections();
    const first = domains.find((domain) => props.zones.some((zone) => zone.domain === domain));
    if (first) open(first, scan);
};
</script>

<template>
    <Head title="DNS Zones" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex w-full items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">DNS Zones</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ dnsProviderLabel }} · {{ dnsEngine }} · {{ authoritativeMode }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button v-if="zones.length" type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="replacing = ''">Find &amp; replace</button>
                    <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="adding = true">+ Add domains</button>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">{{ page.props.flash.error }}</div>

            <div v-if="zones.length" class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <div class="flex flex-wrap gap-2 text-xs text-slate-600 dark:text-slate-300">
                        <span class="rounded-full border border-slate-200 px-3 py-1 dark:border-slate-700">{{ zones.length }} zones</span>
                        <span class="rounded-full border border-slate-200 px-3 py-1 dark:border-slate-700">{{ checking ? 'Checking nameservers…' : `${connectedCount} connected` }}</span>
                    </div>
                    <input v-model="search" type="search" placeholder="Search domains" class="w-64 rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                            <tr>
                                <th class="px-5 py-3">Domain</th>
                                <th class="px-5 py-3">Nameservers</th>
                                <th class="px-5 py-3">Records</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3">Owner</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="zone in rows" :key="zone.domain" class="cursor-pointer border-t border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50" @click="open(zone.domain)">
                                <td class="px-5 py-3">
                                    <span class="font-medium text-blue-700 dark:text-blue-300">{{ zone.domain }}</span>
                                    <span v-if="zone.website_id" class="ml-2 rounded-full border border-blue-200 px-1.5 py-0.5 text-[10px] text-blue-700 dark:border-blue-800 dark:text-blue-300">website</span>
                                </td>
                                <td class="px-5 py-3"><ConnectionBadge :connection="connections[zone.domain]" :checking="checking" /></td>
                                <td class="px-5 py-3 tabular-nums">{{ records(zone.domain).length }}</td>
                                <td class="px-5 py-3 capitalize">{{ zone.status }}</td>
                                <td class="px-5 py-3 text-slate-500">{{ zone.owner_name }}</td>
                                <td class="px-5 py-3 text-right text-xs text-blue-600 dark:text-blue-300">Manage →</td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td colspan="6" class="px-5 py-8 text-center text-slate-500">No zone matches “{{ search }}”.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-else class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-700 dark:bg-slate-900">
                <h2 class="text-base font-semibold">No DNS zones yet</h2>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">Add a domain and its current records are found for you. Then point the domain's nameservers here.</p>
                <button type="button" class="mt-4 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="adding = true">+ Add your first domain</button>
            </div>
        </div>

        <ZoneSettingsForm v-if="adding" :panel-route="panelRoute" @close="adding = false" @created="created" />
        <ZoneDrawer
            v-if="openZone"
            :key="openZone.domain"
            :zone="openZone"
            :records="records(openZone.domain)"
            :connection="connections[openZone.domain]"
            :checking="checking"
            :transfer-users="transferUsers"
            :panel-route="panelRoute"
            :auto-scan="autoScan"
            @close="openDomain = ''"
            @recheck="checkConnections"
            @find-replace="replacing = openZone.zone_uuid || ''"
        />
        <ZoneFindReplace v-if="replacing !== null" :zones="zones" :zone-id="replacing" :panel-route="panelRoute" @close="replacing = null" />
    </AuthenticatedLayout>
</template>
