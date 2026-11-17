<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { flag, formatTime } from '../loginEventFormat';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    points: { type: Array, default: () => [] },
    geoip: { type: Object, required: true },
    busy: { type: String, default: '' },
});

const emit = defineEmits(['download-geoip']);

const container = ref(null);
let map = null;
let layer = null;

const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// Mostly failed attempts read as red, mostly successful as green, a mix as amber.
const colour = (point) => {
    const failed = Number(point.failed);
    const success = Number(point.success);
    if (failed && !success) return '#dc2626';
    if (success && !failed) return '#059669';
    return failed > success ? '#ea580c' : '#d97706';
};

const popup = (point) => {
    const where = [point.city, point.region, point.country].filter(Boolean);
    return `
        <div style="min-width:180px;font-size:12px;line-height:1.5">
            <strong>${flag(point.country_code)} ${escape([...new Set(where)].join(', ') || t('security.loginAudit.map.unknownPlace'))}</strong><br>
            ${Number(point.latitude).toFixed(6)}, ${Number(point.longitude).toFixed(6)}<br>
            <em>${Number(point.device) ? t('security.loginAudit.map.deviceLoc') : t('security.loginAudit.map.ipLoc')}</em><br>
            <span style="color:#059669">${t('security.loginAudit.map.success', { count: point.success })}</span> ·
            <span style="color:#dc2626">${t('security.loginAudit.map.failed', { count: point.failed })}</span><br>
            ${t('security.loginAudit.map.channels', { panel: point.panel, ssh: point.ssh })} · ${Number(point.ips) === 1 ? t('security.loginAudit.map.ipOne') : t('security.loginAudit.map.ipMany', { count: point.ips })}<br>
            ${t('security.loginAudit.map.last', { time: escape(formatTime(point.last_seen)) })}
        </div>`;
};

// Points that arrived while the map sat in a hidden tab; drawn when it shows.
let pending = false;

const draw = () => {
    if (!map) return;
    if (!container.value?.offsetWidth) {
        pending = true;
        return;
    }
    pending = false;
    layer.clearLayers();
    const bounds = [];
    for (const point of props.points) {
        const latLng = [Number(point.latitude), Number(point.longitude)];
        bounds.push(latLng);
        L.circleMarker(latLng, {
            radius: Math.min(22, 6 + Math.log2(Number(point.total)) * 2.5),
            color: colour(point),
            fillColor: colour(point),
            fillOpacity: 0.45,
            weight: 2,
            ...(Number(point.device) ? { color: '#ffffff', weight: 3 } : {}),
        }).bindPopup(popup(point)).addTo(layer);
    }
    if (bounds.length) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 8 });
};

/** Centres the map on one login, from the table. */
const focus = (latitude, longitude) => {
    if (!map || latitude == null || longitude == null) return;
    container.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    map.flyTo([latitude, longitude], 9);
    layer.eachLayer((marker) => {
        const at = marker.getLatLng();
        if (Math.abs(at.lat - latitude) < 1e-4 && Math.abs(at.lng - longitude) < 1e-4) marker.openPopup();
    });
};

let testLayer = null;

/** The admin's own test reading: a dot plus the browser's accuracy circle. */
const showTest = (latitude, longitude, accuracy) => {
    if (!map) return;
    testLayer?.remove();
    testLayer = L.layerGroup([
        L.circle([latitude, longitude], { radius: Math.max(accuracy || 0, 5), color: '#2563eb', weight: 1, fillOpacity: 0.12 }),
        L.circleMarker([latitude, longitude], { radius: 6, color: '#ffffff', weight: 2, fillColor: '#2563eb', fillOpacity: 1 })
            .bindPopup(`<div style="font-size:12px"><strong>${t('security.loginAudit.map.testLocation')}</strong><br>${latitude.toFixed(6)}, ${longitude.toFixed(6)}<br>±${accuracy} m</div>`),
    ]).addTo(map);
    container.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    map.flyTo([latitude, longitude], accuracy > 2000 ? 11 : 16);
};

/** Leaflet measures its box once; call this when a hidden map (another tab) is shown. */
const refresh = () => {
    if (!map) return;
    map.invalidateSize();
    if (pending) draw();
};

defineExpose({ focus, showTest, refresh });

onMounted(() => {
    map = L.map(container.value, { worldCopyJump: true, scrollWheelZoom: false }).setView([20, 0], 2);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        // The panel page is same-origin only (its URL holds the session token),
        // and OSM refuses tiles without a Referer. Send the bare origin, never the path.
        referrerPolicy: 'strict-origin',
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors · <a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>',
    }).addTo(map);
    layer = L.layerGroup().addTo(map);
    draw();
});

onBeforeUnmount(() => {
    map?.remove();
    map = null;
});

watch(() => props.points, draw);
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-base font-semibold">{{ t('security.loginAudit.map.title') }}</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    {{ points.length === 1 ? t('security.loginAudit.map.placeOne') : t('security.loginAudit.map.placeMany', { count: points.length }) }} {{ t('security.loginAudit.map.legend') }}
                </p>
            </div>
            <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <span v-if="geoip.installed">{{ t('security.loginAudit.map.dbFrom', { date: new Date(geoip.updated_at).toLocaleDateString() }) }}</span>
                <button
                    type="button"
                    :disabled="busy === 'geoip'"
                    class="rounded-md border border-slate-300 px-3 py-1.5 hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800"
                    @click="emit('download-geoip')"
                >
                    {{ busy === 'geoip' ? t('security.loginAudit.map.starting') : (geoip.installed ? t('security.loginAudit.map.updateNow') : t('security.loginAudit.map.download')) }}
                </button>
            </div>
        </div>
        <div v-if="!geoip.installed" class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            {{ t('security.loginAudit.map.notDownloaded') }}
        </div>
        <div ref="container" class="relative z-0 h-[420px] w-full overflow-hidden rounded-lg" />
    </section>
</template>
