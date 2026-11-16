<script setup>
import { nextTick, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoginAuditStats from './components/LoginAuditStats.vue';
import LoginAuditFilters from './components/LoginAuditFilters.vue';
import LoginAuditMap from './components/LoginAuditMap.vue';
import LoginAuditTable from './components/LoginAuditTable.vue';
import LoginEventDetail from './components/LoginEventDetail.vue';
import LoginLocationSettings from './components/LoginLocationSettings.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import { useLoginAudit } from './useLoginAudit';
import { coordinates } from './loginEventFormat';

const props = defineProps({
    clientIp: { type: String, default: '' },
    sshSupported: { type: Boolean, default: false },
    geoip: { type: Object, required: true },
    retentionDays: { type: Number, default: 365 },
    locationModes: { type: Object, default: () => ({ https: 'ask', http: 'ask' }) },
    scheme: { type: String, default: 'https' },
});

const {
    filters, events, meta, stats, points, loading, error, busy, message,
    load, applyFilters, goTo, syncNow, downloadGeoip, saveLocationMode,
} = useLoginAudit();

const locationModes = ref({ ...props.locationModes });
const saveLocation = async (payload) => {
    const saved = await saveLocationMode(payload);
    if (saved) locationModes.value = saved;
};
const map = ref(null);
const selected = ref(null);

// Table or map; remembered per browser.
const TAB_KEY = 'login-audit.tab';
const readTab = () => {
    try {
        return localStorage.getItem(TAB_KEY) === 'map' ? 'map' : 'table';
    } catch {
        return 'table';
    }
};
const tab = ref(readTab());

/** Shows a tab; the map needs a size recount once it is visible. */
const openTab = async (name) => {
    tab.value = name;
    try {
        localStorage.setItem(TAB_KEY, name);
    } catch {
        // Storage blocked: the tab just is not remembered.
    }
    if (name === 'map') {
        await nextTick();
        map.value?.refresh();
    }
};

const settingsOpen = ref(false);

const showTest = async (reading) => {
    settingsOpen.value = false;
    await openTab('map');
    map.value?.showTest(reading.device_latitude, reading.device_longitude, reading.device_accuracy);
};

const locate = async (event) => {
    selected.value = null;
    const at = coordinates(event);
    if (!at) return;
    await openTab('map');
    map.value?.focus(at.latitude, at.longitude);
};

const filterCountry = (code) => {
    filters.country = code;
    applyFilters();
};

const filterIp = (ip) => {
    selected.value = null;
    filters.search = ip;
    applyFilters();
};

onMounted(load);
</script>

<template>
    <Head title="Login Audit" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Login Audit</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Every {{ sshSupported ? 'panel and SSH' : 'panel' }} login, failed attempt and logout, with the IP and where it came from. Kept for {{ retentionDays }} days.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                    @click="settingsOpen = true"
                >
                    <i class="bi bi-gear" /> Settings
                    <span class="ml-1 text-slate-500 dark:text-slate-400">HTTPS: {{ locationModes.https }} · HTTP: {{ locationModes.http }}</span>
                </button>
                <button
                    type="button"
                    :disabled="loading || busy === 'sync'"
                    class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800"
                    @click="syncNow"
                >
                    <i class="bi bi-arrow-clockwise" />
                    {{ busy === 'sync' ? 'Syncing…' : (sshSupported ? 'Sync SSH now' : 'Refresh') }}
                </button>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                {{ error }}
            </div>
            <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="rounded-md border px-4 py-3 text-sm">
                {{ message.text }}
            </div>

            <LoginAuditStats :stats="stats" @country="filterCountry" />

            <LoginAuditFilters :filters="filters" :ssh-supported="props.sshSupported" :loading="loading" @apply="applyFilters" />

            <div>
                <div role="tablist" class="mb-3 flex gap-1 border-b border-slate-200 dark:border-slate-800">
                    <button
                        v-for="item in [
                            { name: 'table', label: 'Login history', icon: 'bi-table', count: meta.total },
                            { name: 'map', label: 'Map', icon: 'bi-geo-alt', count: points.length },
                        ]"
                        :key="item.name"
                        type="button"
                        role="tab"
                        :aria-selected="tab === item.name"
                        class="-mb-px flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition-colors"
                        :class="tab === item.name
                            ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400'
                            : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'"
                        @click="openTab(item.name)"
                    >
                        <i :class="['bi', item.icon]" />
                        {{ item.label }}
                        <span class="rounded-full bg-slate-100 px-2 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ item.count.toLocaleString() }}</span>
                    </button>
                </div>

                <!-- v-show, not v-if: the map keeps its tiles and position between tabs. -->
                <LoginAuditTable
                    v-show="tab === 'table'"
                    :events="events"
                    :meta="meta"
                    :loading="loading"
                    :client-ip="props.clientIp"
                    @open="selected = $event"
                    @locate="locate"
                    @page="goTo"
                />
                <LoginAuditMap v-show="tab === 'map'" ref="map" :points="points" :geoip="props.geoip" :busy="busy" @download-geoip="downloadGeoip" />
            </div>
        </div>

        <LoginEventDetail :event="selected" @close="selected = null" @locate="locate" @filter-ip="filterIp" />

        <Offcanvas
            :show="settingsOpen"
            title="Device location at login"
            subtitle="Rules for HTTPS and HTTP"
            width="lg"
            @close="settingsOpen = false"
        >
            <LoginLocationSettings
                :key="JSON.stringify(locationModes)"
                :modes="locationModes"
                :scheme="props.scheme"
                :busy="busy === 'location'"
                @save="saveLocation"
                @show-on-map="showTest"
            />
        </Offcanvas>
    </AuthenticatedLayout>
</template>
