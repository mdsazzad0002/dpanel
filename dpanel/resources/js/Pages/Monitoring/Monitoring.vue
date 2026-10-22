<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    snapshot: {
        type: Object,
        default: () => ({}),
    },
});

const liveSnapshot = ref({ ...(props.snapshot || {}) });
const refreshError = ref('');
const clockNow = ref(new Date());
const chartHistory = ref([]);
const selectedMetric = ref('cpu');
const selectedWindow = ref(30);
const selectedDiskId = ref('');
const hoveredPoint = ref(null);
const pollingPaused = ref(false);
let refreshTimer = null;

const metricOptions = [
    { key: 'cpu', label: 'CPU', color: '#fb923c', icon: 'CPU' },
    { key: 'memory', label: 'Memory', color: '#38bdf8', icon: 'RAM' },
    { key: 'disk', label: 'Disk', color: '#a78bfa', icon: 'DSK' },
];

const selectedMetricOption = computed(() => metricOptions.find((metric) => metric.key === selectedMetric.value));
const visibleHistory = computed(() => chartHistory.value.slice(-selectedWindow.value));
const diskVolumes = computed(() => {
    if (Array.isArray(liveSnapshot.value.disks) && liveSnapshot.value.disks.length > 0) {
        return liveSnapshot.value.disks;
    }
    return [];
});
const activeDisk = computed(() => diskVolumes.value.find((disk) => disk.id === selectedDiskId.value)
    || diskVolumes.value.find((disk) => disk.mounts.length > 0)
    || diskVolumes.value[0]
    || null);
const activeDiskKey = computed(() => activeDisk.value?.id || '');
const metricValue = (sample, metric = selectedMetric.value) => {
    if (metric === 'disk') {
        return Number(sample.disks?.[activeDiskKey.value]) || 0;
    }
    return Number(sample[metric]) || 0;
};
const currentMetricValue = computed(() => {
    const sample = chartHistory.value.at(-1);
    return sample ? metricValue(sample) : 0;
});
const runningServices = computed(() => Object.values(liveSnapshot.value.services || {}).filter((status) => status === 'running').length);
const memoryPercent = computed(() => {
    const total = Number(liveSnapshot.value.memory_total_mb) || 0;
    const used = Number(liveSnapshot.value.memory_used_mb) || 0;
    return total > 0 ? Math.round((used / total) * 100) : 0;
});
const formatMemory = (mb) => {
    if (!mb || mb <= 0) return '0 MB';
    if (mb >= 1024) return `${Math.round(mb / 1024)} GB`;
    return `${Math.round(mb)} MB`;
};
const chartPoints = computed(() => visibleHistory.value.map((sample, index, samples) => {
    const value = Math.max(0, Math.min(100, metricValue(sample)));
    const x = samples.length < 2 ? 320 : 54 + (index / (samples.length - 1)) * 570;
    const y = 245 - (value / 100) * 200;

    return {
        ...sample,
        value,
        x,
        y,
    };
}));
const chartPath = computed(() => chartPoints.value
    .map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`)
    .join(' '));
const chartAreaPath = computed(() => chartPoints.value.length > 0
    ? `${chartPath.value} L ${chartPoints.value.at(-1).x} 246 L ${chartPoints.value[0].x} 246 Z`
    : '');
const gaugeOffset = computed(() => 251.2 * (1 - Math.max(0, Math.min(100, Number(liveSnapshot.value.cpu_load_percent) || 0)) / 100));

const serviceLabels = {
    edge: 'Edge',
    api_service: 'API Service',
};

const serviceLabel = (service) => serviceLabels[service]
    || String(service).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

const formatDiskSize = (sizeGb) => {
    const size = Number(sizeGb) || 0;
    if (size >= 1024) {
        return `${(size / 1024).toFixed(2)} TB`;
    }
    return `${size.toFixed(1)} GB`;
};

const diskUsageColor = (percent) => {
    if (percent >= 90) return '#fb7185';
    if (percent >= 75) return '#fbbf24';
    return '#22d3ee';
};

const recordSnapshot = (snapshot) => {
    const memoryTotal = Number(snapshot.memory_total_mb) || 0;
    const disks = Array.isArray(snapshot.disks) ? snapshot.disks : [];
    chartHistory.value.push({
        time: new Date(),
        cpu: Number(snapshot.cpu_load_percent) || 0,
        memory: memoryTotal > 0 ? (Number(snapshot.memory_used_mb) / memoryTotal) * 100 : 0,
        disks: Object.fromEntries(disks.map((disk) => [disk.id || disk.mountpoint, Number(disk.usage_percent) || 0])),
    });
    chartHistory.value = chartHistory.value.slice(-60);
};

const refreshSnapshot = async () => {
    try {
        const response = await fetch(route('monitoring.snapshot'), {
            method: 'GET',
            headers: {
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        if (payload && payload.snapshot && typeof payload.snapshot === 'object') {
            liveSnapshot.value = payload.snapshot;
            recordSnapshot(payload.snapshot);
            refreshError.value = '';
        }
    } catch (error) {
        refreshError.value = 'Live refresh failed.';
    }
};

onMounted(() => {
    recordSnapshot(liveSnapshot.value);
    refreshTimer = window.setInterval(() => {
        clockNow.value = new Date();
        if (!pollingPaused.value) {
            refreshSnapshot();
        }
    }, 1000);
});

onBeforeUnmount(() => {
    if (refreshTimer !== null) {
        window.clearInterval(refreshTimer);
        refreshTimer = null;
    }
});
</script>

<template>
    <Head title="Monitoring" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">System Command Center</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Live server telemetry · {{ clockNow.toLocaleDateString() }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 px-3 py-1.5 text-xs font-semibold dark:border-slate-700">
                        <span class="h-2 w-2 rounded-full" :class="pollingPaused ? 'bg-amber-400' : 'animate-pulse bg-emerald-400'"></span>
                        {{ pollingPaused ? 'PAUSED' : 'SYSTEM LIVE' }}
                    </span>
                    <button
                        type="button"
                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold transition hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                        @click="pollingPaused = !pollingPaused"
                    >
                        {{ pollingPaused ? 'Resume feed' : 'Pause feed' }}
                    </button>
                </div>
            </div>
        </template>

        <div class="monitoring-console space-y-5 overflow-hidden rounded-2xl p-4 text-slate-100 sm:p-6 lg:p-8">
            <div class="scanline-overlay"></div>
            <section class="console-hero relative overflow-hidden rounded-2xl border border-cyan-300/20 p-5 shadow-2xl sm:p-7">
                <div class="hero-grid pointer-events-none absolute inset-0 opacity-30"></div>
                <div class="pointer-events-none absolute -right-16 -top-28 h-72 w-72 rounded-full bg-cyan-400/10 blur-3xl"></div>
                <div class="pointer-events-none absolute -bottom-32 left-1/3 h-72 w-72 rounded-full bg-violet-500/10 blur-3xl"></div>
                <div class="relative grid items-center gap-6 lg:grid-cols-[1fr_auto]">
                    <div>
                        <p class="font-mono text-[11px] uppercase tracking-[0.32em] text-cyan-300">DPANEL // SYSTEM TELEMETRY</p>
                        <h2 class="mt-3 text-2xl font-black tracking-tight sm:text-4xl">Server <span class="text-cyan-300">Command</span> Deck</h2>
                        <p class="mt-2 max-w-xl text-sm text-slate-400">Live hardware telemetry, service status and resource pulse in one place.</p>
                        <div class="mt-4 flex flex-wrap gap-2 font-mono text-[10px] tracking-tight">
                            <span class="telemetry-badge rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-slate-300">LOCAL TIME <strong class="ml-1.5 text-white text-xs">{{ clockNow.toLocaleTimeString() }}</strong></span>
                            <span class="telemetry-badge rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-slate-300">CPU CLOCK <strong class="ml-1.5 text-cyan-300 text-xs">{{ liveSnapshot.cpu_clock_mhz ? `${liveSnapshot.cpu_clock_mhz} MHz` : 'N/A' }}</strong></span>
                            <span class="telemetry-badge rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-slate-300">CPU MAX <strong class="ml-1.5 text-cyan-300 text-xs">{{ liveSnapshot.cpu_max_mhz ? `${liveSnapshot.cpu_max_mhz} MHz` : 'N/A' }}</strong></span>
                            <span class="telemetry-badge rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-slate-300">CPU MIN <strong class="ml-1.5 text-cyan-300 text-xs">{{ liveSnapshot.cpu_min_mhz ? `${liveSnapshot.cpu_min_mhz} MHz` : 'N/A' }}</strong></span>
                            <span class="telemetry-badge rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-slate-300">BUS SPEED <strong class="ml-1.5 text-violet-300 text-xs">{{ liveSnapshot.bus_speed_mhz ? `${liveSnapshot.bus_speed_mhz} MHz` : 'N/A' }}</strong></span>
                            <span class="telemetry-badge rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-slate-300">MEM SPEED <strong class="ml-1.5 text-sky-300 text-xs">{{ liveSnapshot.memory_speed_mt_s ? `${liveSnapshot.memory_speed_mt_s} MT/s` : 'N/A' }}</strong></span>
                            <span class="telemetry-badge rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-slate-300">MEM CONFIG <strong class="ml-1.5 text-sky-300 text-xs">{{ liveSnapshot.memory_configured_speed_mt_s ? `${liveSnapshot.memory_configured_speed_mt_s} MT/s` : 'N/A' }}</strong></span>
                        </div>
                        <p v-if="refreshError" class="mt-3 text-xs text-amber-300">{{ refreshError }}</p>
                    </div>
                    <div class="cpu-gauge-wrap mx-auto">
                        <svg viewBox="0 0 120 120" class="h-40 w-40 drop-shadow-[0_0_22px_rgba(34,211,238,0.18)]" role="img" :aria-label="`CPU load ${liveSnapshot.cpu_load_percent ?? 0}%`">
                            <circle cx="60" cy="60" r="40" fill="none" stroke="rgba(148,163,184,0.15)" stroke-width="8" class="gauge-track" />
                            <circle cx="60" cy="60" r="40" fill="none" stroke="#22d3ee" stroke-width="8" stroke-linecap="round" stroke-dasharray="251.2" :stroke-dashoffset="gaugeOffset" class="gauge-progress" />
                            <text x="60" y="57" text-anchor="middle" class="fill-white font-mono text-2xl font-bold">{{ liveSnapshot.cpu_load_percent ?? 0 }}%</text>
                            <text x="60" y="74" text-anchor="middle" class="fill-slate-400 font-mono text-[8px] tracking-[0.2em]">CPU LOAD</text>
                        </svg>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <button
                    v-for="metric in metricOptions"
                    :key="metric.key"
                    type="button"
                    class="metric-card group relative overflow-hidden rounded-xl border p-4 text-left transition duration-300 hover:-translate-y-1 focus:outline-none focus:ring-2 focus:ring-cyan-300"
                    :class="selectedMetric === metric.key ? 'border-cyan-300/50 bg-cyan-300/[0.08]' : 'border-white/10 bg-white/[0.035] hover:border-white/20'"
                    :style="{ '--metric-color': metric.color }"
                    @click="selectedMetric = metric.key; hoveredPoint = null"
                >
                    <div class="flex items-start justify-between">
                        <span class="font-mono text-[10px] uppercase tracking-[0.22em] text-slate-400">{{ metric.label }} TELEMETRY</span>
                        <span class="font-mono text-[10px]" :style="{ color: metric.color }">{{ metric.icon }}</span>
                    </div>
                    <p class="mt-3 text-3xl font-black tabular-nums">{{ currentMetricValue.toFixed(1) }}<span class="ml-1 text-base font-semibold text-slate-400">%</span></p>
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-white/10">
                        <div class="h-full rounded-full transition-[width] duration-700" :style="{ width: `${Math.max(0, Math.min(100, currentMetricValue))}%`, backgroundColor: metric.color, boxShadow: `0 0 12px ${metric.color}` }"></div>
                    </div>
                    <p class="mt-2 text-[10px] text-slate-500">Click to inspect signal</p>
                </button>
                <div class="metric-card rounded-xl border border-white/10 bg-white/[0.035] p-4">
                    <div class="flex items-start justify-between">
                        <span class="font-mono text-[10px] uppercase tracking-[0.22em] text-slate-400">MEMORY</span>
                        <span class="font-mono text-[10px] text-sky-300">RAM</span>
                    </div>
                    <p class="mt-3 text-2xl font-black tabular-nums">{{ liveSnapshot.memory_used_mb ?? 0 }}<span class="ml-1 text-sm font-semibold text-slate-400">MB</span></p>
                    <p class="mt-2 text-[10px] text-slate-500">of {{ liveSnapshot.memory_total_mb ?? 0 }} MB total</p>
                </div>
                <div class="metric-card rounded-xl border border-white/10 bg-white/[0.035] p-4">
                    <div class="flex items-start justify-between">
                        <span class="font-mono text-[10px] uppercase tracking-[0.22em] text-slate-400">SELECTED VOLUME</span>
                        <span class="font-mono text-[10px] text-violet-300">DISK</span>
                    </div>
                    <p class="mt-3 truncate font-mono text-xs font-bold text-slate-300">{{ activeDisk?.device || 'No disk detected' }}</p>
                    <p class="mt-1 text-xl font-black tabular-nums">{{ formatDiskSize(activeDisk?.total_gb) }}</p>
                    <p class="mt-1 text-[10px] text-slate-500">physical disk capacity</p>
                </div>
            </section>

            <section v-if="diskVolumes.length > 0" class="console-panel rounded-2xl border border-white/10 p-4 sm:p-5">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="font-mono text-[10px] uppercase tracking-[0.25em] text-violet-300">PHYSICAL STORAGE // {{ diskVolumes.length }} DRIVE{{ diskVolumes.length === 1 ? '' : 'S' }}</p>
                        <h3 class="mt-1 text-lg font-bold">Physical disks</h3>
                        <p class="mt-1 text-xs text-slate-500">Loop devices and virtual mounts are excluded. Select a mounted drive to inspect filesystem usage.</p>
                    </div>
                    <span class="font-mono text-[10px] text-slate-500">{{ activeDisk?.media_type || 'DRIVE' }} · {{ activeDisk?.device || 'NO DEVICE' }}</span>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <button
                        v-for="disk in diskVolumes"
                        :key="disk.id"
                        type="button"
                        class="volume-card rounded-xl border p-4 text-left transition duration-200 hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-cyan-300"
                        :disabled="disk.mounts.length === 0"
                        :class="[
                            activeDiskKey === disk.id ? 'border-cyan-300/40 bg-cyan-300/[0.07]' : 'border-white/[0.09] bg-slate-950/35 hover:border-white/20',
                            disk.mounts.length === 0 ? 'cursor-default opacity-70' : 'cursor-pointer',
                        ]"
                        @click="selectedDiskId = disk.id; selectedMetric = 'disk'; hoveredPoint = null"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-sm font-bold text-slate-100">{{ disk.device }} · {{ disk.media_type }}</p>
                                <p class="mt-1 truncate text-xs text-slate-400" :title="disk.model">{{ disk.model }}</p>
                            </div>
                            <span v-if="disk.usage_percent !== null" class="rounded-md border border-white/10 px-2 py-1 font-mono text-[10px]" :style="{ color: diskUsageColor(disk.usage_percent) }">
                                {{ Number(disk.usage_percent).toFixed(1) }}%
                            </span>
                            <span v-else class="rounded-md border border-white/10 px-2 py-1 font-mono text-[10px] text-slate-500">NOT MOUNTED</span>
                        </div>
                        <div class="mt-4 h-2 overflow-hidden rounded-full bg-white/10">
                            <div class="h-full rounded-full transition-[width] duration-700" :style="{ width: `${Math.min(100, Math.max(0, Number(disk.usage_percent) || 0))}%`, backgroundColor: diskUsageColor(disk.usage_percent), boxShadow: `0 0 12px ${diskUsageColor(disk.usage_percent)}` }"></div>
                        </div>
                        <div class="mt-2 flex justify-between gap-2 font-mono text-[10px] text-slate-400">
                            <span>{{ formatDiskSize(disk.mounted_used_gb) }} mounted used</span>
                            <span>{{ formatDiskSize(disk.total_gb) }} physical</span>
                        </div>
                        <p class="mt-2 truncate font-mono text-[9px] text-slate-500">{{ disk.mounts.length ? disk.mounts.map((mount) => mount.mountpoint).join(' · ') : 'No mounted filesystem' }}</p>
                    </button>
                </div>
            </section>

            <section class="grid gap-5 xl:grid-cols-[1.65fr_1fr]">
                <div class="console-panel rounded-2xl border border-white/10 p-4 sm:p-5">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.25em] text-cyan-300">LIVE SIGNAL // {{ selectedMetricOption.label }}{{ selectedMetric === 'disk' ? ` · ${activeDisk?.device || 'N/A'}` : '' }}</p>
                            <h3 class="mt-1 text-lg font-bold">Resource waveform</h3>
                            <p class="mt-1 text-xs text-slate-500">Hover over the signal for a precise reading.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                v-for="seconds in [15, 30, 60]"
                                :key="seconds"
                                type="button"
                                class="rounded-md border px-2.5 py-1.5 font-mono text-[10px] transition"
                                :class="selectedWindow === seconds ? 'border-cyan-300/50 bg-cyan-300/10 text-cyan-200' : 'border-white/10 text-slate-500 hover:text-white'"
                                @click="selectedWindow = seconds; hoveredPoint = null"
                            >{{ seconds }}s</button>
                        </div>
                    </div>
                    <div class="chart-perspective relative mt-1">
                        <div v-if="hoveredPoint" class="absolute right-3 top-2 z-10 rounded-lg border border-cyan-300/30 bg-slate-950/90 px-3 py-2 font-mono text-[10px] shadow-xl backdrop-blur">
                            <p class="font-bold text-cyan-200">{{ selectedMetricOption.label }} {{ hoveredPoint.value.toFixed(1) }}%</p>
                            <p class="mt-1 text-[9px] text-slate-400">{{ hoveredPoint.time.toLocaleTimeString() }}</p>
                        </div>
                        <svg viewBox="0 0 640 280" class="chart-svg h-72 w-full overflow-visible" role="img" :aria-label="`${selectedMetricOption.label} usage history`" :style="{ '--chart-glow-color': selectedMetricOption.color }">
                            <defs>
                                <linearGradient id="signalFill" x1="0" x2="0" y1="0" y2="1">
                                    <stop offset="0%" :stop-color="selectedMetricOption.color" stop-opacity="0.34" />
                                    <stop offset="100%" :stop-color="selectedMetricOption.color" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            <line v-for="level in [48, 108, 168, 228]" :key="level" x1="48" :y1="level" x2="630" :y2="level" stroke="rgba(148,163,184,0.14)" stroke-dasharray="3 7" />
                            <text v-for="(level, index) in [100, 66, 33, 0]" :key="`label-${level}`" x="3" :y="[51, 108, 165, 228][index]" class="fill-slate-500 font-mono text-[8px]">{{ level }}%</text>
                            <path v-if="chartPoints.length > 1" :d="chartAreaPath" fill="url(#signalFill)" />
                            <path v-if="chartPoints.length > 1" :d="chartPath" fill="none" :stroke="selectedMetricOption.color" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="signal-line" />
                            <circle
                                v-for="point in chartPoints"
                                :key="point.time.getTime()"
                                :cx="point.x"
                                :cy="point.y"
                                r="4"
                                :fill="selectedMetricOption.color"
                                class="chart-point cursor-crosshair"
                                tabindex="0"
                                @mouseenter="hoveredPoint = point"
                                @focus="hoveredPoint = point"
                                @mouseleave="hoveredPoint = null"
                                @blur="hoveredPoint = null"
                            />
                            <text v-if="chartPoints.length === 0" x="320" y="140" text-anchor="middle" class="fill-slate-500 font-mono text-xs">Awaiting telemetry signal...</text>
                        </svg>
                        <div class="flex justify-between font-mono text-[8px] text-slate-500">
                            <span>{{ chartPoints[0]?.time.toLocaleTimeString() || '—' }}</span>
                            <span>{{ pollingPaused ? 'FEED PAUSED' : 'LIVE STREAM' }}</span>
                            <span>{{ chartPoints.at(-1)?.time.toLocaleTimeString() || '—' }}</span>
                        </div>
                    </div>
                    <div class="mt-3 space-y-2">
                        <div class="flex justify-between font-mono text-[10px] text-slate-400">
                            <span>MEMORY USAGE</span>
                            <span>{{ memoryPercent }}% ({{ formatMemory(Number(liveSnapshot.memory_used_mb) || 0) }} / {{ formatMemory(Number(liveSnapshot.memory_total_mb) || 0) }})</span>
                        </div>
                        <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-700/50">
                            <div
                                class="memory-bar h-full rounded-full"
                                :class="memoryPercent >= 90 ? 'bg-rose-400' : memoryPercent >= 75 ? 'bg-amber-400' : 'bg-sky-400'"
                                :style="{ width: `${Math.max(2, memoryPercent)}%` }"
                            ></div>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span v-if="selectedMetric === 'disk' && activeDisk" class="telemetry-badge rounded-md border border-white/10 bg-white/[0.03] px-2 py-1 font-mono text-[9px] text-slate-400">MOUNTED FREE <b class="ml-1 text-cyan-200">{{ formatDiskSize(activeDisk.mounted_free_gb) }}</b></span>
                        <span v-else class="telemetry-badge rounded-md border border-white/10 bg-white/[0.03] px-2 py-1 font-mono text-[9px] text-slate-400">CPU CLOCK <b class="ml-1 text-cyan-200">{{ liveSnapshot.cpu_clock_mhz ? `${liveSnapshot.cpu_clock_mhz} MHz` : 'N/A' }}</b></span>
                        <span class="telemetry-badge rounded-md border border-white/10 bg-white/[0.03] px-2 py-1 font-mono text-[9px] text-slate-400">BUS SPEED <b class="ml-1 text-violet-200">{{ liveSnapshot.bus_speed_mhz ? `${liveSnapshot.bus_speed_mhz} MHz` : 'N/A' }}</b></span>
                        <span class="telemetry-badge min-w-0 truncate rounded-md border border-white/10 bg-white/[0.03] px-2 py-1 font-mono text-[9px] text-slate-400" :title="liveSnapshot.cpu_model || ''">CPU <b class="ml-1 text-slate-200">{{ liveSnapshot.cpu_model || 'Model unavailable' }}</b></span>
                    </div>
                </div>

                <div class="console-panel rounded-2xl border border-white/10 p-4 sm:p-5">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.25em] text-violet-300">NETWORK // SERVICES</p>
                            <h3 class="mt-1 text-lg font-bold">System nodes</h3>
                        </div>
                        <span class="rounded-md border border-emerald-300/20 bg-emerald-300/10 px-2 py-1 font-mono text-[10px] text-emerald-300">{{ runningServices }} ACTIVE</span>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <div v-for="(status, service) in liveSnapshot.services || {}" :key="service" class="node-card flex min-w-0 items-center gap-2 rounded-lg border border-white/[0.08] bg-white/[0.025] px-3 py-2.5">
                            <span class="relative flex h-2 w-2 shrink-0">
                                <span v-if="status === 'running'" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-30"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full" :class="status === 'running' ? 'bg-emerald-400' : status === 'unknown' ? 'bg-slate-500' : 'bg-rose-400'"></span>
                            </span>
                            <span class="truncate text-xs font-medium text-slate-300">{{ serviceLabel(service) }}</span>
                            <span class="ml-auto font-mono text-[9px] uppercase" :class="status === 'running' ? 'text-emerald-300' : status === 'unknown' ? 'text-slate-500' : 'text-rose-300'">{{ status }}</span>
                        </div>
                    </div>
                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-white/[0.08] bg-slate-950/30 p-3">
                            <p class="font-mono text-[9px] uppercase tracking-widest text-slate-500">Websites</p>
                            <p class="mt-1 text-xl font-bold">{{ liveSnapshot.websites_total ?? 0 }}</p>
                        </div>
                        <div class="rounded-xl border border-white/[0.08] bg-slate-950/30 p-3">
                            <p class="font-mono text-[9px] uppercase tracking-widest text-slate-500">Mailboxes</p>
                            <p class="mt-1 text-xl font-bold">{{ liveSnapshot.mailboxes_total ?? 0 }}</p>
                        </div>
                        <div class="rounded-xl border border-white/[0.08] bg-slate-950/30 p-3">
                            <p class="font-mono text-[9px] uppercase tracking-widest text-slate-500">DB Requests</p>
                            <p class="mt-1 text-xl font-bold">{{ liveSnapshot.database_requests_total ?? 0 }}</p>
                        </div>
                        <div class="rounded-xl border border-white/[0.08] bg-slate-950/30 p-3">
                            <p class="font-mono text-[9px] uppercase tracking-widest text-slate-500">Cron Jobs</p>
                            <p class="mt-1 text-xl font-bold">{{ liveSnapshot.cron_jobs_total ?? 0 }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="console-panel overflow-hidden rounded-2xl border border-white/10">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/[0.08] px-4 py-4 sm:px-5">
                    <div>
                        <p class="font-mono text-[10px] uppercase tracking-[0.25em] text-amber-300">PROCESS RADAR</p>
                        <h3 class="mt-1 text-lg font-bold">Top processes</h3>
                    </div>
                    <span class="font-mono text-[10px] text-slate-500">UPDATED {{ liveSnapshot.updated_at ?? '—' }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-white/[0.025] font-mono text-[10px] uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-4 py-3 sm:px-5">PID</th>
                                <th class="px-4 py-3">Process</th>
                                <th class="px-4 py-3">CPU Load</th>
                                <th class="px-4 py-3 sm:px-5">Memory</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="proc in liveSnapshot.topProcesses || []" :key="`${proc.pid}-${proc.name}`" class="border-t border-white/[0.06] transition hover:bg-cyan-300/[0.035]">
                                <td class="px-4 py-3 font-mono text-xs text-slate-500 sm:px-5">{{ proc.pid }}</td>
                                <td class="px-4 py-3 font-medium text-slate-200">{{ proc.name }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-orange-300">{{ proc.cpu }}%</td>
                                <td class="px-4 py-3 font-mono text-xs text-sky-300 sm:px-5">{{ proc.mem }}%</td>
                            </tr>
                            <tr v-if="(liveSnapshot.topProcesses || []).length === 0">
                                <td colspan="4" class="px-4 py-8 text-center font-mono text-xs text-slate-500">No process data available.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.monitoring-console {
    background:
        radial-gradient(ellipse at 10% 0%, rgb(14 116 144 / 15%), transparent 38%),
        radial-gradient(ellipse at 95% 15%, rgb(109 40 217 / 12%), transparent 35%),
        radial-gradient(circle at 50% 40%, rgb(229 231 240 / 2%), transparent 60%),
        linear-gradient(145deg, #080f1d, #0b1222 52%, #0a1020);
    perspective: 1400px;
    position: relative;
    overflow: hidden;
}

.monitoring-console::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        radial-gradient(circle at 20% 30%, rgb(100 116 216 / 3%) 1px, transparent 2px),
        radial-gradient(circle at 80% 70%, rgb(245 158 11 / 3%) 1px, transparent 2px);
    pointer-events: none;
    z-index: 0;
    opacity: 0.5;
}

.scanline-overlay {
    position: absolute;
    inset: 0;
    background: repeating-linear-gradient(
        0deg,
        rgba(0, 0, 0, 0.03) 0px,
        rgba(0, 0, 0, 0.03) 1px,
        transparent 1px,
        transparent 4px
    );
    pointer-events: none;
    z-index: 1;
    opacity: 0.35;
}

.console-hero,
.console-panel,
.metric-card {
    background-color: rgb(15 23 42 / 75%);
    box-shadow: 0 20px 50px -35px rgb(0 0 0 / 90%), inset 0 1px rgb(255 255 255 / 4%);
    transform-style: preserve-3d;
    position: relative;
    z-index: 1;
}

.console-hero {
    background: linear-gradient(125deg, rgb(8 19 34 / 96%), rgb(12 23 43 / 93%) 55%, rgb(22 19 49 / 94%));
    border: 1px solid rgb(103 232 249 / 25%);
    box-shadow: 0 0 40px -10px rgb(103 232 249 / 10%), inset 0 1px rgb(255 255 255 / 4%);
}

.hero-grid {
    background-image: linear-gradient(rgb(125 211 252 / 7%) 1px, transparent 1px), linear-gradient(90deg, rgb(125 211 252 / 7%) 1px, transparent 1px);
    background-size: 34px 34px;
    mask-image: linear-gradient(to right, black, transparent 90%);
    transform: perspective(400px) rotateX(8deg) scale(1.08);
}

.console-panel {
    background: linear-gradient(145deg, rgb(15 23 42 / 93%), rgb(8 15 29 / 97%));
    border: 1px solid rgb(148 163 184 / 20%);
    transition: border-color 220ms ease, transform 220ms ease, box-shadow 220ms ease;
}

.console-panel:hover {
    border-color: rgb(103 232 249 / 25%);
    box-shadow: 0 24px 48px -34px rgb(34 211 238 / 25%), inset 0 1px rgb(255 255 255 / 5%);
    transform: translateY(-2px) rotateX(0.35deg);
}

.metric-card {
    backdrop-filter: blur(14px);
    border: 1px solid rgb(148 163 184 / 15%);
}

.metric-card:hover {
    box-shadow: 0 18px 40px -20px color-mix(in srgb, var(--metric-color, #22d3ee) 42%, transparent);
    border-color: rgb(103 232 249 / 30%);
}

.volume-card {
    box-shadow: inset 0 1px rgb(255 255 255 / 4%), 0 10px 30px -24px rgb(0 0 0 / 90%);
    transform-style: preserve-3d;
    border: 1px solid rgb(148 163 184 / 15%);
}

.volume-card:hover {
    box-shadow: inset 0 1px rgb(255 255 255 / 7%), 0 18px 34px -22px rgb(34 211 238 / 25%);
    border-color: rgb(103 232 249 / 25%);
}

.chart-perspective {
    perspective: 900px;
}

.chart-svg {
    filter: drop-shadow(0 10px 12px rgb(0 0 0 / 25%)) drop-shadow(0 0 8px color-mix(in srgb, var(--chart-glow-color, #22d3ee) 50%, transparent));
    transform: rotateX(7deg);
    transform-origin: center bottom;
}

.signal-line {
    filter: drop-shadow(0 0 3px color-mix(in srgb, var(--chart-glow-color, #22d3ee) 70%, transparent));
}

.chart-point {
    stroke: #0b1222;
    stroke-width: 2px;
    transition: r 120ms ease, filter 150ms ease;
    filter: drop-shadow(0 0 4px color-mix(in srgb, var(--chart-glow-color, #22d3ee) 60%, transparent));
}

.chart-point:hover,
.chart-point:focus {
    outline: none;
    r: 7px;
    stroke: white;
    filter: drop-shadow(0 0 6px color-mix(in srgb, var(--chart-glow-color, #22d3ee) 80%, transparent)) drop-shadow(0 0 12px white);
}

.gauge-progress {
    transform: rotate(-90deg);
    transform-origin: 60px 60px;
    transition: stroke-dashoffset 700ms cubic-bezier(0.16, 1, 0.3, 1);
    filter: drop-shadow(0 0 6px rgb(252 252 255 / 50%));
}

.gauge-track {
    filter: drop-shadow(0 0 4px rgb(148 163 184 / 40%));
}

.memory-bar {
    transition: width 700ms cubic-bezier(0.16, 1, 0.3, 1), background-color 500ms ease;
    filter: drop-shadow(0 0 6px currentColor);
}

.telemetry-badge {
    backdrop-filter: blur(10px);
    border: 1px solid rgb(255 255 255 / 8%);
    background: linear-gradient(135deg, rgb(255 255 255 / 6%), rgb(255 255 255 / 3%));
    box-shadow: 0 4px 16px -4px rgb(0 0 0 / 40%);
}

.telemetry-badge strong {
    text-shadow: 0 0 6px currentColor;
}

.node-card {
    transition: transform 160ms ease, border-color 160ms ease, background 160ms ease;
}

.node-card:hover {
    transform: translateZ(8px);
    border-color: rgb(103 232 249 / 24%);
    background: rgb(34 211 238 / 5%);
    box-shadow: 0 12px 24px -8px rgb(34 211 238 / 15%);
}

@media (prefers-reduced-motion: reduce) {
    *,\
    *::before,\
    *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
