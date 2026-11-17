<script setup>
import { computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import { CHANNEL_BADGES, RESULT_BADGES, accuracyText, coordinates, distanceKm, flag, formatTime, parseAgent, place } from '../loginEventFormat';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    event: { type: Object, default: null },
});

const emit = defineEmits(['close', 'locate', 'filter-ip']);

const agent = computed(() => parseAgent(props.event?.user_agent));

const GEO_NOTES = {
    get private() { return t('security.loginAudit.detail.geo.private'); },
    get pending() { return t('security.loginAudit.detail.geo.pending'); },
    get unknown() { return t('security.loginAudit.detail.geo.unknown'); },
};

// A device far from its IP's city often means a VPN or proxy (or a faked position).
const distance = computed(() => {
    const km = props.event ? distanceKm(props.event) : null;
    if (km == null) return '—';
    const text = km < 1 ? `${Math.round(km * 1000)} m` : `${km.toFixed(km < 100 ? 1 : 0)} km`;
    return km > 200 ? t('security.loginAudit.detail.far', { distance: text }) : text;
});

const rows = computed(() => {
    const e = props.event;
    if (!e) return [];
    const coords = e.latitude != null ? `${Number(e.latitude).toFixed(6)}, ${Number(e.longitude).toFixed(6)}` : '—';
    return [
        [t('security.loginAudit.detail.rows.time'), `${formatTime(e.occurred_at)} (${e.occurred_at})`],
        [t('security.loginAudit.detail.rows.source'), e.channel === 'ssh' ? t('security.loginAudit.detail.sourceSsh') : t('security.loginAudit.detail.sourcePanel')],
        [t('security.loginAudit.detail.rows.result'), RESULT_BADGES[e.result] ? t(`security.loginAudit.results.${e.result}`) : e.result],
        [t('security.loginAudit.detail.rows.method'), e.method || '—'],
        [t('security.loginAudit.detail.rows.reason'), e.reason || '—'],
        [t('security.loginAudit.detail.rows.loginName'), e.username || '—'],
        [t('security.loginAudit.detail.rows.panelUser'), e.user ? `${e.user.name} <${e.user.email}> · ${e.user.role}` : '—'],
        [t('security.loginAudit.detail.rows.impersonatedBy'), e.actor ? `${e.actor.name} <${e.actor.email}>` : '—'],
        [t('security.loginAudit.detail.rows.ip'), e.ip_address, true],
        [t('security.loginAudit.detail.rows.country'), e.country ? `${flag(e.country_code)} ${e.country} (${e.country_code})` : '—'],
        [t('security.loginAudit.detail.rows.region'), e.region || '—'],
        [t('security.loginAudit.detail.rows.city'), e.city || '—'],
        [t('security.loginAudit.detail.rows.ipCoords'), coords],
        [t('security.loginAudit.detail.rows.ipStatus'), GEO_NOTES[e.geo_status] || t('security.loginAudit.detail.geo.located')],
        [t('security.loginAudit.detail.rows.deviceCoords'), e.device_latitude != null ? `${e.device_latitude.toFixed(6)}, ${e.device_longitude.toFixed(6)}` : t('security.loginAudit.detail.notShared')],
        [t('security.loginAudit.detail.rows.deviceAccuracy'), e.device_accuracy != null ? t('security.loginAudit.detail.estimate', { accuracy: accuracyText(e.device_accuracy) }) : '—'],
        [t('security.loginAudit.detail.rows.distance'), distance.value],
        [t('security.loginAudit.detail.rows.browser'), agent.value?.browser || '—'],
        [t('security.loginAudit.detail.rows.os'), agent.value?.os || '—'],
        [t('security.loginAudit.detail.rows.device'), agent.value?.device || '—'],
        [t('security.loginAudit.detail.rows.userAgent'), e.user_agent || '—', true],
        [t('security.loginAudit.detail.rows.recorded'), formatTime(e.created_at)],
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
                        <span :class="CHANNEL_BADGES[event.channel]?.class" class="rounded px-1.5 py-0.5 text-xs font-medium">{{ CHANNEL_BADGES[event.channel] ? t(`security.loginAudit.channels.${event.channel}`) : event.channel }}</span>
                        <span :class="RESULT_BADGES[event.result]?.class" class="rounded px-2 py-0.5 text-xs">{{ RESULT_BADGES[event.result] ? t(`security.loginAudit.results.${event.result}`) : event.result }}</span>
                    </div>
                    <h3 class="mt-2 text-lg font-semibold">{{ event.user?.name || event.username || t('security.loginAudit.detail.unknownUser') }}</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ flag(event.country_code) }} {{ place(event) }}</p>
                </div>
                <button type="button" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200" :title="t('common.close')" @click="emit('close')">
                    <i class="bi bi-x-lg" />
                </button>
            </div>

            <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                <div v-for="[label, value, mono] in rows" :key="label" class="grid grid-cols-3 gap-3 py-2">
                    <dt class="text-slate-500 dark:text-slate-400">{{ label }}</dt>
                    <dd class="col-span-2 break-all" :class="mono ? 'font-mono text-xs' : ''">{{ value }}</dd>
                </div>
            </dl>

            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('filter-ip', event.ip_address)">
                    {{ t('security.loginAudit.detail.allFromIp') }}
                </button>
                <a v-if="osmLink" :href="osmLink" target="_blank" rel="noopener" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    {{ t('security.loginAudit.detail.openOsm') }}
                </a>
                <button v-if="coordinates(event)" type="button" class="rounded-md bg-slate-900 px-3 py-2 text-xs text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900" @click="emit('locate', event)">
                    {{ t('security.loginAudit.detail.showOnMap') }}
                </button>
            </div>
        </div>
    </Modal>
</template>
