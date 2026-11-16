<script setup>
import { computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import { CHANNEL_BADGES, RESULT_BADGES, accuracyText, coordinates, distanceKm, flag, formatTime, parseAgent, place } from '../loginEventFormat';

const props = defineProps({
    event: { type: Object, default: null },
});

const emit = defineEmits(['close', 'locate', 'filter-ip']);

const agent = computed(() => parseAgent(props.event?.user_agent));

const GEO_NOTES = {
    private: 'Private or local network address, so it has no place on the map.',
    pending: 'Not located yet. It fills in once the DB-IP database is downloaded.',
    unknown: 'The DB-IP database has no place for this address.',
};

// A device far from its IP's city often means a VPN or proxy (or a faked position).
const distance = computed(() => {
    const km = props.event ? distanceKm(props.event) : null;
    if (km == null) return '—';
    const text = km < 1 ? `${Math.round(km * 1000)} m` : `${km.toFixed(km < 100 ? 1 : 0)} km`;
    return km > 200 ? `${text}. Far from the IP's city: VPN, proxy or a faked position?` : text;
});

const rows = computed(() => {
    const e = props.event;
    if (!e) return [];
    const coords = e.latitude != null ? `${Number(e.latitude).toFixed(6)}, ${Number(e.longitude).toFixed(6)}` : '—';
    return [
        ['Time', `${formatTime(e.occurred_at)} (${e.occurred_at})`],
        ['Source', e.channel === 'ssh' ? 'SSH (Linux account)' : 'dPanel web login'],
        ['Result', RESULT_BADGES[e.result]?.label || e.result],
        ['Method', e.method || '—'],
        ['Reason', e.reason || '—'],
        ['Login name', e.username || '—'],
        ['Panel user', e.user ? `${e.user.name} <${e.user.email}> · ${e.user.role}` : '—'],
        ['Impersonated by', e.actor ? `${e.actor.name} <${e.actor.email}>` : '—'],
        ['IP address', e.ip_address],
        ['Country', e.country ? `${flag(e.country_code)} ${e.country} (${e.country_code})` : '—'],
        ['Region', e.region || '—'],
        ['City', e.city || '—'],
        ['IP latitude, longitude', coords],
        ['IP location status', GEO_NOTES[e.geo_status] || 'Located from the local DB-IP database (city-level, not exact).'],
        ['Device latitude, longitude', e.device_latitude != null ? `${e.device_latitude.toFixed(6)}, ${e.device_longitude.toFixed(6)}` : 'Not shared (only required when the panel is opened over HTTPS)'],
        ['Device accuracy', e.device_accuracy != null ? `${accuracyText(e.device_accuracy)} (browser estimate)` : '—'],
        ['Device vs IP distance', distance.value],
        ['Browser', agent.value?.browser || '—'],
        ['Operating system', agent.value?.os || '—'],
        ['Device', agent.value?.device || '—'],
        ['User agent', e.user_agent || '—'],
        ['Recorded', formatTime(e.created_at)],
    ];
});

const osmLink = computed(() => {
    const at = props.event ? coordinates(props.event) : null;
    if (!at) return '';
    const zoom = at.device ? 17 : 10;
    return `https://www.openstreetmap.org/?mlat=${at.latitude}&mlon=${at.longitude}#map=${zoom}/${at.latitude}/${at.longitude}`;
});
</script>

<template>
    <Modal :show="!!event" max-width="2xl" @close="emit('close')">
        <div v-if="event" class="p-6">
            <div class="mb-4 flex items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <span :class="CHANNEL_BADGES[event.channel]?.class" class="rounded px-1.5 py-0.5 text-xs font-medium">{{ CHANNEL_BADGES[event.channel]?.label }}</span>
                        <span :class="RESULT_BADGES[event.result]?.class" class="rounded px-2 py-0.5 text-xs">{{ RESULT_BADGES[event.result]?.label }}</span>
                    </div>
                    <h3 class="mt-2 text-lg font-semibold">{{ event.user?.name || event.username || 'Unknown user' }}</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ flag(event.country_code) }} {{ place(event) }}</p>
                </div>
                <button type="button" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200" title="Close" @click="emit('close')">
                    <i class="bi bi-x-lg" />
                </button>
            </div>

            <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                <div v-for="[label, value] in rows" :key="label" class="grid grid-cols-3 gap-3 py-2">
                    <dt class="text-slate-500 dark:text-slate-400">{{ label }}</dt>
                    <dd class="col-span-2 break-all" :class="label === 'User agent' || label === 'IP address' ? 'font-mono text-xs' : ''">{{ value }}</dd>
                </div>
            </dl>

            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('filter-ip', event.ip_address)">
                    All logins from this IP
                </button>
                <a v-if="osmLink" :href="osmLink" target="_blank" rel="noopener" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Open in OpenStreetMap
                </a>
                <button v-if="coordinates(event)" type="button" class="rounded-md bg-slate-900 px-3 py-2 text-xs text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900" @click="emit('locate', event)">
                    Show on map
                </button>
            </div>
        </div>
    </Modal>
</template>
