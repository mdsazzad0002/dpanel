<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
const copiedSnapshot = ref(false);
const previousCounters = ref(null);
const rates = ref({ rx: 0, tx: 0, lo: 0, disks: {} });
let refreshTimer = null;

const metricOptions = computed(() => [
    { key: 'cpu', label: 'CPU', title: t('monitoring.metric.cpu'), color: '#10b981', icon: 'bi-cpu' },
    { key: 'memory', label: t('monitoring.metric.memory'), title: t('monitoring.metric.memoryUsage'), color: '#06b6d4', icon: 'bi-memory' },
    { key: 'disk', label: t('monitoring.metric.disk'), title: t('monitoring.metric.diskUsage'), color: '#8b5cf6', icon: 'bi-device-hdd' },
]);

// Subtle pointer-driven 3D tilt; skipped for touch devices and reduced-motion users.
const vTilt = {
    mounted(el, binding) {
        if (typeof window === 'undefined'
            || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
            || window.matchMedia?.('(pointer: coarse)').matches) {
            return;
        }
        const maxTilt = Number(binding.value) || 4;
        const move = (event) => {
            const rect = el.getBoundingClientRect();
            const x = (event.clientX - rect.left) / rect.width;
            const y = (event.clientY - rect.top) / rect.height;
            el.style.setProperty('--rx', `${(0.5 - y) * maxTilt}deg`);
            el.style.setProperty('--ry', `${(x - 0.5) * maxTilt}deg`);
            el.style.setProperty('--mx', `${x * 100}%`);
            el.style.setProperty('--my', `${y * 100}%`);
        };
        const leave = () => {
            el.style.setProperty('--rx', '0deg');
            el.style.setProperty('--ry', '0deg');
        };
        el.addEventListener('pointermove', move);
        el.addEventListener('pointerleave', leave);
        el._tiltCleanup = () => {
            el.removeEventListener('pointermove', move);
            el.removeEventListener('pointerleave', leave);
        };
    },
    unmounted(el) {
        el._tiltCleanup?.();
    },
};

const selectedMetricOption = computed(() => metricOptions.value.find((metric) => metric.key === selectedMetric.value));
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
const latestMetricValue = (metric) => {
    const sample = chartHistory.value.at(-1);
    return sample ? metricValue(sample, metric) : 0;
};
// A service this machine does not have (Postfix on Windows) is listed but
// not counted.
const serviceEntries = computed(() => Object.entries(liveSnapshot.value.services || {}));
const installedServiceCount = computed(() => serviceEntries.value.filter(([, status]) => status !== 'not-installed').length);
const runningServices = computed(() => serviceEntries.value.filter(([, status]) => status === 'running').length);
const formatMemory = (mb) => {
    if (!mb || mb <= 0) return '0 MB';
    if (mb >= 1024) return `${Math.round(mb / 1024)} GB`;
    return `${Math.round(mb)} MB`;
};
const clampPercent = (value) => Math.max(0, Math.min(100, Number(value) || 0));
const formatBytes = (bytes) => {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = Math.max(0, Number(bytes) || 0);
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }
    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
};
const formatRate = (bytesPerSecond) => `${formatBytes(bytesPerSecond)}/s`;
const formatUptime = (seconds) => {
    const total = Number(seconds) || 0;
    if (total <= 0) return '—';
    const days = Math.floor(total / 86400);
    const hours = Math.floor((total % 86400) / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    return days > 0 ? `${days}d ${hours}h ${minutes}m` : `${hours}h ${minutes}m`;
};

// Network/disk counters are cumulative; rates come from the delta between consecutive server samples.
const computeRates = (snapshot) => {
    const at = Number(snapshot.sampled_at) || Date.now() / 1000;
    const net = (snapshot.network || []).reduce((sum, iface) => ({
        rx: sum.rx + (Number(iface.rx_bytes) || 0),
        tx: sum.tx + (Number(iface.tx_bytes) || 0),
    }), { rx: 0, tx: 0 });
    const lo = Number(snapshot.loopback?.rx_bytes) || 0;
    const disks = Object.fromEntries((snapshot.disks || []).filter((disk) => disk.io).map((disk) => [disk.id, disk.io]));
    const previous = previousCounters.value;
    previousCounters.value = { at, net, lo, disks };

    if (!previous || at <= previous.at) {
        return { rx: 0, tx: 0, lo: 0, disks: {} };
    }
    const elapsed = at - previous.at;
    const rate = (now, before) => Math.max(0, (now - before) / elapsed);

    return {
        rx: rate(net.rx, previous.net.rx),
        tx: rate(net.tx, previous.net.tx),
        lo: rate(lo, previous.lo),
        disks: Object.fromEntries(Object.entries(disks)
            .filter(([id]) => previous.disks[id])
            .map(([id, io]) => {
                const before = previous.disks[id];
                return [id, {
                    read: rate(io.read_bytes, before.read_bytes),
                    write: rate(io.write_bytes, before.write_bytes),
                    iops: rate(io.reads + io.writes, before.reads + before.writes),
                }];
            })),
    };
};

const networkInterfaces = computed(() => (liveSnapshot.value.network || []).map((iface) => iface.name));
const networkTotals = computed(() => (liveSnapshot.value.network || []).reduce((sum, iface) => ({
    rx: sum.rx + (Number(iface.rx_bytes) || 0),
    tx: sum.tx + (Number(iface.tx_bytes) || 0),
}), { rx: 0, tx: 0 }));
const networkSpark = computed(() => {
    const samples = chartHistory.value.slice(-selectedWindow.value);
    const peak = Math.max(1024, ...samples.map((sample) => Math.max(sample.rx || 0, sample.tx || 0, sample.lo || 0)));
    const line = (key) => samples
        .map((sample, index) => {
            const x = samples.length < 2 ? 150 : (index / (samples.length - 1)) * 300;
            const y = 76 - ((sample[key] || 0) / peak) * 70;
            return `${index === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`;
        })
        .join(' ');
    return { rx: line('rx'), tx: line('tx'), lo: line('lo'), peak, count: samples.length };
});
const connectionBreakdown = computed(() => liveSnapshot.value.connection_breakdown || []);
const connectionScopeTotals = computed(() => connectionBreakdown.value.reduce((sum, group) => ({
    local: sum.local + group.local,
    lan: sum.lan + group.lan,
    external: sum.external + group.external,
}), { local: 0, lan: 0, external: 0 }));
const runtimeBadges = {
    python: 'border-sky-400/30 bg-sky-400/10 text-sky-700 dark:text-sky-200',
    rust: 'border-orange-400/30 bg-orange-400/10 text-orange-700 dark:text-orange-200',
    node: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-700 dark:text-emerald-200',
};
const connectionStats = computed(() => {
    const stats = liveSnapshot.value.connections || {};
    return [
        { label: t('monitoring.conn.established'), value: stats.tcp_established },
        { label: t('monitoring.conn.timeWait'), value: stats.tcp_time_wait },
        { label: t('monitoring.conn.tcpInUse'), value: stats.tcp_in_use },
        { label: t('monitoring.conn.udpInUse'), value: stats.udp_in_use },
        { label: t('monitoring.conn.sockets'), value: stats.sockets_used },
    ];
});

const temperatureColor = (celsius) => {
    if (celsius >= 80) return '#f43f5e';
    if (celsius >= 65) return '#f59e0b';
    return '#10b981';
};
const temperatures = computed(() => (liveSnapshot.value.temperatures || []).map((sensor) => ({
    ...sensor,
    color: temperatureColor(sensor.celsius),
    fill: clampPercent((sensor.celsius / (sensor.critical || 100)) * 100),
})));
const swapPercent = computed(() => {
    const total = Number(liveSnapshot.value.swap_total_mb) || 0;
    return total > 0 ? ((Number(liveSnapshot.value.swap_used_mb) || 0) / total) * 100 : 0;
});
const loadAverage = computed(() => liveSnapshot.value.load_average || []);

const chartPoints = computed(() => visibleHistory.value.map((sample, index, samples) => {
    const value = clampPercent(metricValue(sample));
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
const chartStats = computed(() => {
    const values = chartPoints.value.map((point) => point.value);
    if (values.length === 0) {
        return { min: 0, avg: 0, max: 0 };
    }
    return {
        min: Math.min(...values),
        avg: values.reduce((sum, value) => sum + value, 0) / values.length,
        max: Math.max(...values),
    };
});

// 270° arc gauges: r=58 in a 160x160 viewBox, starting at 135°.
const GAUGE_RADIUS = 58;
const GAUGE_CIRCUMFERENCE = 2 * Math.PI * GAUGE_RADIUS;
const GAUGE_ARC = GAUGE_CIRCUMFERENCE * 0.75;
const gaugeTicks = Array.from({ length: 21 }, (_, index) => {
    const angle = ((135 + (index / 20) * 270) * Math.PI) / 180;
    const major = index % 5 === 0;
    const inner = major ? 66 : 68;
    return {
        major,
        percent: index * 5,
        x1: 80 + inner * Math.cos(angle),
        y1: 80 + inner * Math.sin(angle),
        x2: 80 + 72 * Math.cos(angle),
        y2: 80 + 72 * Math.sin(angle),
    };
});
const gaugeTip = (percent) => {
    const angle = ((135 + (clampPercent(percent) / 100) * 270) * Math.PI) / 180;
    return { x: 80 + GAUGE_RADIUS * Math.cos(angle), y: 80 + GAUGE_RADIUS * Math.sin(angle) };
};

const loadLevel = (percent) => {
    if (percent >= 90) return { label: t('monitoring.level.critical'), color: '#f43f5e', badge: 'border-rose-400/30 bg-rose-400/10 text-rose-700 dark:text-rose-200' };
    if (percent >= 75) return { label: t('monitoring.level.high'), color: '#f59e0b', badge: 'border-amber-400/30 bg-amber-400/10 text-amber-700 dark:text-amber-200' };
    return { label: t('monitoring.level.healthy'), color: '#10b981', badge: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-700 dark:text-emerald-200' };
};

const gauges = computed(() => metricOptions.value.map((metric) => {
    const value = metric.key === 'cpu'
        ? clampPercent(liveSnapshot.value.cpu_load_percent)
        : metric.key === 'memory'
            ? clampPercent(latestMetricValue('memory'))
            : clampPercent(activeDisk.value?.usage_percent);
    const detail = metric.key === 'cpu'
        ? (liveSnapshot.value.cpu_clock_mhz ? `${liveSnapshot.value.cpu_clock_mhz} MHz` : t('monitoring.clockNa'))
        : metric.key === 'memory'
            ? `${formatMemory(Number(liveSnapshot.value.memory_used_mb) || 0)} / ${formatMemory(Number(liveSnapshot.value.memory_total_mb) || 0)}`
            : `${activeDisk.value?.device || t('monitoring.noDrive')} · ${formatDiskSize(activeDisk.value?.total_gb)}`;

    return {
        ...metric,
        value,
        detail,
        level: loadLevel(value),
        tip: gaugeTip(value),
    };
}));

const healthScore = computed(() => {
    const peak = Math.max(...gauges.value.map((gauge) => gauge.value), 0);
    return Math.round(Math.max(0, Math.min(100, 100 - peak * 0.7)));
});
const healthLevel = computed(() => loadLevel(100 - healthScore.value));

const hardwareSpecs = computed(() => [
    { label: 'cpu.clock', value: liveSnapshot.value.cpu_clock_mhz, unit: 'MHz', max: liveSnapshot.value.cpu_max_mhz },
    { label: 'cpu.max', value: liveSnapshot.value.cpu_max_mhz, unit: 'MHz', max: liveSnapshot.value.cpu_max_mhz },
    { label: 'cpu.min', value: liveSnapshot.value.cpu_min_mhz, unit: 'MHz', max: liveSnapshot.value.cpu_max_mhz },
    { label: 'bus.speed', value: liveSnapshot.value.bus_speed_mhz, unit: 'MHz', max: null },
    { label: 'mem.speed', value: liveSnapshot.value.memory_speed_mt_s, unit: 'MT/s', max: liveSnapshot.value.memory_speed_mt_s },
    { label: 'mem.configured', value: liveSnapshot.value.memory_configured_speed_mt_s, unit: 'MT/s', max: liveSnapshot.value.memory_speed_mt_s },
].map((spec) => ({
    ...spec,
    fill: spec.value && spec.max ? clampPercent((Number(spec.value) / Number(spec.max)) * 100) : (spec.value ? 100 : 0),
})));

const resourceCounts = computed(() => [
    { label: t('monitoring.res.websites'), icon: 'bi-globe2', value: liveSnapshot.value.websites_total ?? 0, tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300' },
    { label: t('monitoring.res.mailboxes'), icon: 'bi-envelope', value: liveSnapshot.value.mailboxes_total ?? 0, tone: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-300' },
    { label: t('monitoring.res.db'), icon: 'bi-database', value: liveSnapshot.value.database_requests_total ?? 0, tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-300' },
    { label: t('monitoring.res.cron'), icon: 'bi-clock-history', value: liveSnapshot.value.cron_jobs_total ?? 0, tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-300' },
]);

const topProcesses = computed(() => liveSnapshot.value.topProcesses || []);

const serviceLabels = {
    edge: 'Edge',
    api_service: 'API Service',
};
const serviceIcons = {
    edge: 'bi-hdd-network',
    api_service: 'bi-gear-wide-connected',
    mariadb: 'bi-database',
    mysql: 'bi-database',
    redis: 'bi-lightning-charge',
    postfix: 'bi-send',
    dovecot: 'bi-inbox',
};

const serviceLabel = (service) => serviceLabels[service]
    || String(service).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

const serviceTone = (status) => {
    if (status === 'running') return { text: 'text-emerald-600 dark:text-emerald-300', dot: 'bg-emerald-500', icon: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300', ping: true };
    if (status === 'unknown' || status === 'not-installed') return { text: 'text-slate-500 dark:text-slate-400', dot: 'bg-slate-400', icon: 'bg-slate-500/10 text-slate-500 dark:text-slate-400', ping: false };
    return { text: 'text-rose-600 dark:text-rose-300', dot: 'bg-rose-500', icon: 'bg-rose-500/10 text-rose-600 dark:text-rose-300', ping: false };
};

function formatDiskSize(sizeGb) {
    const size = Number(sizeGb) || 0;
    if (size >= 1024) {
        return `${(size / 1024).toFixed(2)} TB`;
    }
    return `${size.toFixed(1)} GB`;
}

const diskUsageColor = (percent) => loadLevel(Number(percent) || 0).color;

const selectMetric = (key) => {
    selectedMetric.value = key;
    hoveredPoint.value = null;
};

const copySnapshot = async () => {
    try {
        await navigator.clipboard.writeText(JSON.stringify(liveSnapshot.value, null, 2));
        copiedSnapshot.value = true;
        window.setTimeout(() => {
            copiedSnapshot.value = false;
        }, 1500);
    } catch (error) {
        refreshError.value = t('monitoring.noClipboard');
    }
};

const recordSnapshot = (snapshot) => {
    const memoryTotal = Number(snapshot.memory_total_mb) || 0;
    const disks = Array.isArray(snapshot.disks) ? snapshot.disks : [];
    rates.value = computeRates(snapshot);
    chartHistory.value.push({
        time: new Date(),
        cpu: Number(snapshot.cpu_load_percent) || 0,
        memory: memoryTotal > 0 ? (Number(snapshot.memory_used_mb) / memoryTotal) * 100 : 0,
        disks: Object.fromEntries(disks.map((disk) => [disk.id || disk.mountpoint, Number(disk.usage_percent) || 0])),
        rx: rates.value.rx,
        tx: rates.value.tx,
        lo: rates.value.lo,
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
        refreshError.value = t('monitoring.refreshFailed');
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
    <Head :title="t('monitoring.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">{{ t('monitoring.title') }}</h1>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium"
                            :class="pollingPaused
                                ? 'border-amber-400/30 bg-amber-400/10 text-amber-700 dark:text-amber-200'
                                : 'border-emerald-400/30 bg-emerald-400/10 text-emerald-700 dark:text-emerald-200'"
                        >
                            <span class="relative flex h-1.5 w-1.5">
                                <span v-if="!pollingPaused" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-60"></span>
                                <span class="relative inline-flex h-1.5 w-1.5 rounded-full" :class="pollingPaused ? 'bg-amber-500' : 'bg-emerald-500'"></span>
                            </span>
                            {{ pollingPaused ? t('monitoring.paused') : t('monitoring.live') }}
                        </span>
                    </div>
                    <p class="truncate text-sm text-slate-500 dark:text-slate-400">
                        {{ t('monitoring.telemetry') }} · <span class="font-mono tabular-nums">{{ clockNow.toLocaleTimeString() }}</span>
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <button type="button" class="toolbar-btn" :title="t('monitoring.copyJsonHint')" @click="copySnapshot">
                        <i class="bi" :class="copiedSnapshot ? 'bi-check2 text-emerald-500' : 'bi-braces'"></i>
                        <span class="hidden sm:inline">{{ copiedSnapshot ? t('monitoring.copied') : t('monitoring.copyJson') }}</span>
                    </button>
                    <button type="button" class="toolbar-btn toolbar-btn--accent" @click="pollingPaused = !pollingPaused">
                        <i class="bi" :class="pollingPaused ? 'bi-play-fill' : 'bi-pause-fill'"></i>
                        {{ pollingPaused ? t('monitoring.resume') : t('monitoring.pause') }}
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <div v-if="refreshError" class="flex items-center gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                <i class="bi bi-exclamation-triangle"></i>{{ refreshError }}
            </div>

            <!-- Overview strip -->
            <section class="hero-card relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-slate-900 sm:p-6">
                <div class="hero-grid pointer-events-none absolute inset-0" aria-hidden="true"></div>
                <div class="hero-glow pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full" aria-hidden="true"></div>
                <div class="relative grid items-center gap-6 lg:grid-cols-[1fr_auto]">
                    <div class="min-w-0">
                        <p class="font-mono text-xs uppercase tracking-[0.22em] text-slate-500 dark:text-slate-400">{{ t('monitoring.overview') }}</p>
                        <h2 class="mt-2 truncate text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-50 sm:text-3xl" :title="liveSnapshot.hostname || ''">
                            {{ liveSnapshot.hostname || t('monitoring.server') }}
                        </h2>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 font-mono text-[11px] text-slate-500 dark:text-slate-400">
                            <span v-if="liveSnapshot.os_name"><i class="bi bi-pc-display mr-1"></i>{{ liveSnapshot.os_name }}</span>
                            <span v-if="liveSnapshot.kernel"><i class="bi bi-terminal mr-1"></i>{{ liveSnapshot.kernel }}</span>
                            <span v-if="liveSnapshot.cpu_model" class="max-w-full truncate" :title="liveSnapshot.cpu_model"><i class="bi bi-cpu mr-1"></i>{{ liveSnapshot.cpu_model }}<template v-if="liveSnapshot.cpu_cores"> · {{ t('monitoring.threads', { count: liveSnapshot.cpu_cores }) }}</template></span>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div class="stat-tile">
                                <span class="stat-label">{{ t('monitoring.uptime') }}</span>
                                <span class="stat-value">{{ formatUptime(liveSnapshot.uptime_seconds) }}</span>
                            </div>
                            <div class="stat-tile" :title="t('monitoring.loadHint')">
                                <span class="stat-label">{{ t('monitoring.load') }}</span>
                                <span class="stat-value font-mono text-base">{{ loadAverage.length ? loadAverage.map((value) => value.toFixed(2)).join(' ') : '—' }}</span>
                            </div>
                            <div class="stat-tile">
                                <span class="stat-label">{{ t('monitoring.processes') }}</span>
                                <span class="stat-value">{{ liveSnapshot.process_count ?? '—' }}</span>
                            </div>
                            <div class="stat-tile">
                                <span class="stat-label">{{ t('monitoring.services') }}</span>
                                <span class="stat-value">{{ runningServices }}<span class="text-slate-400">/{{ installedServiceCount }}</span></span>
                            </div>
                        </div>
                    </div>

                    <div class="health-ring mx-auto" :style="{ '--level': healthLevel.color, '--score': `${healthScore * 3.6}deg` }">
                        <div class="health-ring__inner">
                            <span class="font-mono text-[10px] uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">{{ t('monitoring.health') }}</span>
                            <span class="text-4xl font-bold tabular-nums text-slate-900 dark:text-white">{{ healthScore }}</span>
                            <span class="mt-0.5 rounded-full border px-2 py-0.5 text-[10px] font-medium" :class="healthLevel.badge">{{ healthLevel.label }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Gauges -->
            <section class="grid gap-4 md:grid-cols-3">
                <button
                    v-for="gauge in gauges"
                    :key="gauge.key"
                    v-tilt="5"
                    type="button"
                    class="gauge-card tilt relative rounded-2xl border bg-white p-5 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 dark:bg-slate-900"
                    :class="selectedMetric === gauge.key ? 'is-selected' : 'border-slate-200 dark:border-white/10'"
                    :style="{ '--accent': gauge.color }"
                    :aria-pressed="selectedMetric === gauge.key"
                    @click="selectMetric(gauge.key)"
                >
                    <span class="glare" aria-hidden="true"></span>
                    <div class="depth flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="metric-icon"><i class="bi" :class="gauge.icon"></i></span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900 dark:text-slate-50">{{ gauge.title }}</p>
                                <p class="truncate font-mono text-[11px] text-slate-500 dark:text-slate-400">{{ gauge.detail }}</p>
                            </div>
                        </div>
                        <span class="shrink-0 rounded-full border px-2 py-0.5 text-[11px] font-medium" :class="gauge.level.badge">{{ gauge.level.label }}</span>
                    </div>

                    <div class="depth-lg relative mx-auto mt-3 w-full max-w-[200px]">
                        <svg viewBox="0 0 160 160" class="w-full overflow-visible" role="img" :aria-label="`${gauge.title} ${gauge.value.toFixed(1)}%`">
                            <defs>
                                <linearGradient :id="`gauge-grad-${gauge.key}`" x1="0" x2="1" y1="1" y2="0">
                                    <stop offset="0%" :stop-color="gauge.color" stop-opacity="0.55" />
                                    <stop offset="100%" :stop-color="gauge.color" />
                                </linearGradient>
                            </defs>
                            <line
                                v-for="tick in gaugeTicks"
                                :key="tick.percent"
                                :x1="tick.x1" :y1="tick.y1" :x2="tick.x2" :y2="tick.y2"
                                :stroke="tick.percent <= gauge.value ? gauge.color : 'currentColor'"
                                :stroke-width="tick.major ? 2 : 1"
                                stroke-linecap="round"
                                class="text-slate-300 dark:text-slate-700"
                            />
                            <circle
                                cx="80" cy="80" :r="GAUGE_RADIUS"
                                fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round"
                                :stroke-dasharray="`${GAUGE_ARC} ${GAUGE_CIRCUMFERENCE}`"
                                class="gauge-rotate text-slate-100 dark:text-white/[0.06]"
                            />
                            <circle
                                cx="80" cy="80" :r="GAUGE_RADIUS"
                                fill="none" :stroke="`url(#gauge-grad-${gauge.key})`" stroke-width="10" stroke-linecap="round"
                                :stroke-dasharray="`${GAUGE_ARC} ${GAUGE_CIRCUMFERENCE}`"
                                :stroke-dashoffset="GAUGE_ARC * (1 - gauge.value / 100)"
                                class="gauge-rotate gauge-progress"
                            />
                            <circle :cx="gauge.tip.x" :cy="gauge.tip.y" r="5.5" :stroke="gauge.color" stroke-width="3" class="gauge-tip fill-white dark:fill-slate-900" />
                            <text x="80" y="84" text-anchor="middle" class="fill-slate-900 font-semibold tabular-nums dark:fill-white" style="font-size: 28px">{{ gauge.value.toFixed(1) }}</text>
                            <text x="80" y="102" text-anchor="middle" class="fill-slate-400 font-mono" style="font-size: 9px; letter-spacing: 0.2em">PERCENT</text>
                        </svg>
                    </div>

                    <div class="depth flex items-center justify-between text-[11px]">
                        <span :class="selectedMetric === gauge.key ? 'font-medium text-slate-700 dark:text-slate-200' : 'text-slate-400'">
                            <i class="bi" :class="selectedMetric === gauge.key ? 'bi-graph-up-arrow' : 'bi-cursor'"></i>
                            {{ selectedMetric === gauge.key ? t('monitoring.inChart') : t('monitoring.clickChart') }}
                        </span>
                        <span class="font-mono text-slate-400">{{ gauge.key }}</span>
                    </div>
                </button>
            </section>

            <!-- Network + thermals -->
            <section class="grid gap-5 xl:grid-cols-[1.65fr_1fr]">
                <div class="panel">
                    <header class="panel-header">
                        <div class="flex min-w-0 items-center gap-2">
                            <h3 class="panel-title">{{ t('monitoring.network') }}</h3>
                            <span class="truncate font-mono text-[11px] text-slate-400">{{ networkInterfaces.join(', ') || t('monitoring.noInterfaces') }}</span>
                        </div>
                        <span class="font-mono text-[11px] text-slate-400">{{ t('monitoring.peak', { rate: formatRate(networkSpark.peak) }) }}</span>
                    </header>
                    <div class="grid gap-5 p-4 sm:p-5 lg:grid-cols-[minmax(0,1fr)_14rem]">
                        <div class="min-w-0">
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                <div class="net-stat" style="--accent: #06b6d4">
                                    <span class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400"><i class="bi bi-arrow-down-circle-fill text-cyan-500"></i>{{ t('monitoring.download') }}</span>
                                    <span class="mt-1 block font-mono text-xl font-semibold tabular-nums text-slate-900 dark:text-slate-50">{{ formatRate(rates.rx) }}</span>
                                    <span class="font-mono text-[10px] text-slate-400">{{ t('monitoring.sinceBoot', { size: formatBytes(networkTotals.rx) }) }}</span>
                                </div>
                                <div class="net-stat" style="--accent: #10b981">
                                    <span class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400"><i class="bi bi-arrow-up-circle-fill text-emerald-500"></i>{{ t('monitoring.upload') }}</span>
                                    <span class="mt-1 block font-mono text-xl font-semibold tabular-nums text-slate-900 dark:text-slate-50">{{ formatRate(rates.tx) }}</span>
                                    <span class="font-mono text-[10px] text-slate-400">{{ t('monitoring.sinceBoot', { size: formatBytes(networkTotals.tx) }) }}</span>
                                </div>
                                <div class="net-stat col-span-2 sm:col-span-1" style="--accent: #8b5cf6" :title="t('monitoring.loopbackHint')">
                                    <span class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400"><i class="bi bi-arrow-repeat text-violet-500"></i>{{ t('monitoring.loopback') }}</span>
                                    <span class="mt-1 block font-mono text-xl font-semibold tabular-nums text-slate-900 dark:text-slate-50">{{ formatRate(rates.lo) }}</span>
                                    <span class="font-mono text-[10px] text-slate-400">{{ t('monitoring.sinceBoot', { size: formatBytes(liveSnapshot.loopback?.rx_bytes) }) }}</span>
                                </div>
                            </div>
                            <svg viewBox="0 0 300 80" preserveAspectRatio="none" class="mt-4 h-28 w-full overflow-visible" role="img" :aria-label="t('monitoring.netHistory')">
                                <g class="text-slate-200 dark:text-white/[0.07]">
                                    <line v-for="level in [6, 41, 76]" :key="level" x1="0" :y1="level" x2="300" :y2="level" stroke="currentColor" stroke-dasharray="3 6" vector-effect="non-scaling-stroke" />
                                </g>
                                <path v-if="networkSpark.count > 1" :d="networkSpark.lo" fill="none" stroke="#8b5cf6" stroke-width="1.5" stroke-dasharray="4 3" stroke-linejoin="round" vector-effect="non-scaling-stroke" class="net-line" style="--accent: #8b5cf6" />
                                <path v-if="networkSpark.count > 1" :d="networkSpark.rx" fill="none" stroke="#06b6d4" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" class="net-line" style="--accent: #06b6d4" />
                                <path v-if="networkSpark.count > 1" :d="networkSpark.tx" fill="none" stroke="#10b981" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" class="net-line" style="--accent: #10b981" />
                            </svg>
                            <div class="mt-1 flex justify-between font-mono text-[10px] text-slate-400">
                                <span>{{ t('monitoring.lastSeconds', { count: networkSpark.count }) }}</span>
                                <span><span class="text-cyan-500">━</span> rx <span class="ml-2 text-emerald-500">━</span> tx <span class="ml-2 text-violet-500">┅</span> {{ t('monitoring.scope.local') }}</span>
                            </div>
                        </div>
                        <dl class="divide-y divide-slate-100 self-start rounded-xl border border-slate-200 font-mono text-xs dark:divide-white/[0.06] dark:border-white/10">
                            <div v-for="stat in connectionStats" :key="stat.label" class="flex items-center justify-between gap-3 px-3 py-2">
                                <dt class="text-slate-500 dark:text-slate-400">{{ stat.label }}</dt>
                                <dd class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ stat.value ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="panel">
                    <header class="panel-header">
                        <h3 class="panel-title">{{ t('monitoring.thermals') }}</h3>
                        <i class="bi bi-thermometer-half text-slate-400"></i>
                    </header>
                    <ul v-if="temperatures.length" class="divide-y divide-slate-100 dark:divide-white/[0.06]">
                        <li v-for="sensor in temperatures" :key="sensor.id" class="px-4 py-2.5 sm:px-5">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="min-w-0 truncate text-sm text-slate-700 dark:text-slate-200">
                                    {{ sensor.label }} <span class="font-mono text-[10px] text-slate-400">{{ sensor.chip }}</span>
                                </span>
                                <span class="shrink-0 font-mono text-sm font-semibold tabular-nums" :style="{ color: sensor.color }">{{ sensor.celsius.toFixed(1) }}°C</span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/[0.06]">
                                <div class="temp-fill h-full rounded-full" :style="{ width: `${sensor.fill}%`, '--accent': sensor.color }"></div>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('monitoring.noSensors') }}</p>
                    <div class="border-t border-slate-200 px-4 py-3.5 dark:border-white/10 sm:px-5">
                        <div class="flex items-baseline justify-between font-mono text-xs">
                            <span class="text-slate-500 dark:text-slate-400"><i class="bi bi-arrow-left-right mr-1"></i>swap</span>
                            <span class="tabular-nums text-slate-700 dark:text-slate-200">
                                {{ formatMemory(Number(liveSnapshot.swap_used_mb) || 0) }} / {{ formatMemory(Number(liveSnapshot.swap_total_mb) || 0) }}
                                <span class="ml-1 font-semibold" :style="{ color: loadLevel(swapPercent).color }">{{ swapPercent.toFixed(0) }}%</span>
                            </span>
                        </div>
                        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/[0.06]">
                            <div class="temp-fill h-full rounded-full" :style="{ width: `${clampPercent(swapPercent)}%`, '--accent': loadLevel(swapPercent).color }"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Live connections by port -->
            <section class="panel">
                <header class="panel-header">
                    <div class="flex min-w-0 items-center gap-2">
                        <h3 class="panel-title">{{ t('monitoring.liveConnections') }}</h3>
                        <span class="font-mono text-[11px] text-slate-400">{{ t('monitoring.byPort') }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5 text-[11px] font-medium">
                        <span class="scope-chip scope-chip--local">{{ t('monitoring.localCount', { count: connectionScopeTotals.local }) }}</span>
                        <span class="scope-chip scope-chip--lan">{{ t('monitoring.lanCount', { count: connectionScopeTotals.lan }) }}</span>
                        <span class="scope-chip scope-chip--external">{{ t('monitoring.internetCount', { count: connectionScopeTotals.external }) }}</span>
                    </div>
                </header>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 font-mono text-[10px] uppercase tracking-wider text-slate-500 dark:bg-white/[0.02] dark:text-slate-400">
                            <tr>
                                <th class="px-4 py-2.5 sm:px-5">{{ t('monitoring.col.service') }}</th>
                                <th class="px-4 py-2.5">{{ t('monitoring.col.port') }}</th>
                                <th class="px-4 py-2.5">{{ t('monitoring.col.direction') }}</th>
                                <th class="min-w-[220px] px-4 py-2.5">{{ t('monitoring.col.peers') }}</th>
                                <th class="px-4 py-2.5 text-right sm:px-5">{{ t('monitoring.col.total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/[0.06]">
                            <tr v-for="group in connectionBreakdown" :key="`${group.direction}-${group.port}`" class="transition hover:bg-slate-50 dark:hover:bg-white/[0.03]">
                                <td class="px-4 py-2.5 sm:px-5">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <span class="truncate font-medium text-slate-800 dark:text-slate-100">{{ group.label }}</span>
                                        <span v-if="group.runtime" class="shrink-0 rounded-full border px-1.5 py-px font-mono text-[10px]" :class="runtimeBadges[group.runtime] || runtimeBadges.node">{{ group.runtime }}</span>
                                        <span v-if="group.runtime && !group.listening" class="shrink-0 rounded-full border border-rose-400/30 bg-rose-400/10 px-1.5 py-px font-mono text-[10px] text-rose-700 dark:text-rose-200" :title="t('monitoring.notListeningHint')">{{ t('monitoring.notListening') }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-xs text-slate-600 dark:text-slate-300">:{{ group.port }}</td>
                                <td class="px-4 py-2.5 font-mono text-[11px]">
                                    <span v-if="group.direction === 'inbound'" class="text-emerald-600 dark:text-emerald-300"><i class="bi bi-box-arrow-in-down-right"></i> {{ t('monitoring.in') }}</span>
                                    <span v-else class="text-sky-600 dark:text-sky-300"><i class="bi bi-box-arrow-up-right"></i> {{ t('monitoring.out') }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div v-if="group.total > 0" class="flex h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/[0.06]">
                                        <div class="bg-violet-500" :style="{ width: `${(group.local / group.total) * 100}%` }" :title="t('monitoring.localCount', { count: group.local })"></div>
                                        <div class="bg-amber-500" :style="{ width: `${(group.lan / group.total) * 100}%` }" :title="t('monitoring.lanCount', { count: group.lan })"></div>
                                        <div class="bg-cyan-500" :style="{ width: `${(group.external / group.total) * 100}%` }" :title="t('monitoring.internetCount', { count: group.external })"></div>
                                    </div>
                                    <div class="mt-1 flex gap-3 font-mono text-[10px] text-slate-400">
                                        <span v-if="group.local">{{ t('monitoring.localCount', { count: group.local }) }}</span>
                                        <span v-if="group.lan">{{ t('monitoring.lanCount', { count: group.lan }) }}</span>
                                        <span v-if="group.external">{{ t('monitoring.internetCount', { count: group.external }) }}</span>
                                        <span v-if="group.total === 0">{{ t('monitoring.idle') }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-right font-mono text-sm font-semibold tabular-nums text-slate-900 dark:text-slate-100 sm:px-5">{{ group.total }}</td>
                            </tr>
                            <tr v-if="connectionBreakdown.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('monitoring.noConnections') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Chart + services -->
            <section class="grid gap-5 xl:grid-cols-[1.65fr_1fr]">
                <div class="panel">
                    <header class="panel-header">
                        <div class="flex min-w-0 items-center gap-2">
                            <h3 class="panel-title">{{ t('monitoring.history') }}</h3>
                            <span class="font-mono text-[11px] text-slate-400">{{ selectedMetricOption.key }}{{ selectedMetric === 'disk' ? `:${activeDisk?.device || 'n/a'}` : '' }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="segmented">
                                <button
                                    v-for="metric in metricOptions"
                                    :key="metric.key"
                                    type="button"
                                    :class="{ 'is-active': selectedMetric === metric.key }"
                                    :style="{ '--accent': metric.color }"
                                    @click="selectMetric(metric.key)"
                                >{{ metric.label }}</button>
                            </div>
                            <div class="segmented">
                                <button
                                    v-for="seconds in [15, 30, 60]"
                                    :key="seconds"
                                    type="button"
                                    :class="{ 'is-active': selectedWindow === seconds }"
                                    style="--accent: #10b981"
                                    @click="selectedWindow = seconds; hoveredPoint = null"
                                >{{ seconds }}s</button>
                            </div>
                        </div>
                    </header>

                    <div class="px-4 pb-4 pt-3 sm:px-5">
                        <div class="grid grid-cols-4 gap-2 font-mono text-[11px]">
                            <div class="chart-stat" :style="{ '--accent': selectedMetricOption.color }">
                                <span class="text-slate-400">{{ hoveredPoint ? hoveredPoint.time.toLocaleTimeString() : t('monitoring.now') }}</span>
                                <span class="chart-stat__value" :style="{ color: selectedMetricOption.color }">{{ (hoveredPoint ? hoveredPoint.value : latestMetricValue(selectedMetric)).toFixed(1) }}%</span>
                            </div>
                            <div class="chart-stat">
                                <span class="text-slate-400">{{ t('monitoring.min') }}</span>
                                <span class="chart-stat__value">{{ chartStats.min.toFixed(1) }}%</span>
                            </div>
                            <div class="chart-stat">
                                <span class="text-slate-400">{{ t('monitoring.avg') }}</span>
                                <span class="chart-stat__value">{{ chartStats.avg.toFixed(1) }}%</span>
                            </div>
                            <div class="chart-stat">
                                <span class="text-slate-400">{{ t('monitoring.max') }}</span>
                                <span class="chart-stat__value">{{ chartStats.max.toFixed(1) }}%</span>
                            </div>
                        </div>

                        <svg viewBox="0 0 640 260" class="chart-svg mt-2 h-64 w-full overflow-visible" role="img" :aria-label="t('monitoring.usageHistory', { metric: selectedMetricOption.label })" :style="{ '--accent': selectedMetricOption.color }">
                            <defs>
                                <linearGradient id="signalFill" x1="0" x2="0" y1="0" y2="1">
                                    <stop offset="0%" :stop-color="selectedMetricOption.color" stop-opacity="0.3" />
                                    <stop offset="100%" :stop-color="selectedMetricOption.color" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            <g class="text-slate-200 dark:text-white/[0.07]">
                                <line v-for="level in [45, 95, 145, 195, 245]" :key="level" x1="48" :y1="level" x2="630" :y2="level" stroke="currentColor" stroke-dasharray="3 6" />
                            </g>
                            <text v-for="(level, index) in [100, 75, 50, 25, 0]" :key="`label-${level}`" x="40" :y="[48, 98, 148, 198, 248][index]" text-anchor="end" class="fill-slate-400 font-mono" style="font-size: 9px">{{ level }}%</text>
                            <path v-if="chartPoints.length > 1" :d="chartAreaPath" fill="url(#signalFill)" />
                            <path v-if="chartPoints.length > 1" :d="chartPath" fill="none" :stroke="selectedMetricOption.color" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="signal-line" />
                            <line v-if="hoveredPoint" :x1="hoveredPoint.x" y1="45" :x2="hoveredPoint.x" y2="245" :stroke="selectedMetricOption.color" stroke-opacity="0.4" stroke-dasharray="2 4" />
                            <circle
                                v-for="point in chartPoints"
                                :key="point.time.getTime()"
                                :cx="point.x"
                                :cy="point.y"
                                r="3"
                                :fill="selectedMetricOption.color"
                                class="chart-point cursor-crosshair stroke-white dark:stroke-slate-900"
                                tabindex="0"
                                @mouseenter="hoveredPoint = point"
                                @focus="hoveredPoint = point"
                                @mouseleave="hoveredPoint = null"
                                @blur="hoveredPoint = null"
                            />
                            <text v-if="chartPoints.length === 0" x="340" y="145" text-anchor="middle" class="fill-slate-400 font-mono text-xs">{{ t('monitoring.waiting') }}</text>
                        </svg>
                        <div class="flex justify-between pl-12 font-mono text-[10px] text-slate-400">
                            <span>{{ chartPoints[0]?.time.toLocaleTimeString() || '—' }}</span>
                            <span>{{ t('monitoring.samples', { count: chartPoints.length }) }}</span>
                            <span>{{ chartPoints.at(-1)?.time.toLocaleTimeString() || '—' }}</span>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <header class="panel-header">
                        <h3 class="panel-title">{{ t('monitoring.services') }}</h3>
                        <span class="shrink-0 rounded-full border border-emerald-400/30 bg-emerald-400/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-200">
                            {{ t('monitoring.runningCount', { running: runningServices, total: installedServiceCount }) }}
                        </span>
                    </header>
                    <ul class="grid grid-cols-1 gap-px bg-slate-100 sm:grid-cols-2 xl:grid-cols-1 dark:bg-white/[0.06]">
                        <li v-for="[service, status] in serviceEntries" :key="service" class="flex min-w-0 items-center gap-3 bg-white px-4 py-2.5 transition hover:bg-slate-50 dark:bg-slate-900 dark:hover:bg-white/[0.03] sm:px-5">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="serviceTone(status).icon">
                                <i class="bi" :class="serviceIcons[service] || 'bi-box'"></i>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ serviceLabel(service) }}</span>
                                <span class="block truncate font-mono text-[10px] text-slate-400">{{ service }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-1.5 text-xs font-medium capitalize" :class="serviceTone(status).text">
                                <span class="relative flex h-2 w-2">
                                    <span v-if="serviceTone(status).ping" class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60" :class="serviceTone(status).dot"></span>
                                    <span class="relative inline-flex h-2 w-2 rounded-full" :class="serviceTone(status).dot"></span>
                                </span>
                                {{ status === 'not-installed' ? 'not installed' : status }}
                            </span>
                        </li>
                        <li v-if="serviceEntries.length === 0" class="bg-white px-5 py-6 text-center text-sm text-slate-500 dark:bg-slate-900 dark:text-slate-400">{{ t('monitoring.noServices') }}</li>
                    </ul>
                    <div class="grid grid-cols-2 gap-3 border-t border-slate-200 p-4 dark:border-white/10 sm:p-5">
                        <div v-for="item in resourceCounts" :key="item.label" class="flex items-center gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="item.tone"><i class="bi" :class="item.icon"></i></span>
                            <span class="min-w-0">
                                <span class="block text-lg font-semibold leading-tight tabular-nums text-slate-900 dark:text-slate-50">{{ item.value }}</span>
                                <span class="block truncate text-[11px] text-slate-500 dark:text-slate-400">{{ item.label }}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Hardware + storage -->
            <section class="grid gap-5 xl:grid-cols-[1fr_1.65fr]">
                <div class="panel">
                    <header class="panel-header">
                        <h3 class="panel-title">{{ t('monitoring.hardware') }}</h3>
                        <i class="bi bi-motherboard text-slate-400"></i>
                    </header>
                    <dl class="divide-y divide-slate-100 font-mono text-xs dark:divide-white/[0.06]">
                        <div v-for="spec in hardwareSpecs" :key="spec.label" class="px-4 py-2.5 sm:px-5">
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="text-slate-500 dark:text-slate-400">{{ spec.label }}</dt>
                                <dd class="tabular-nums" :class="spec.value ? 'font-semibold text-slate-900 dark:text-slate-100' : 'text-slate-400'">
                                    {{ spec.value ? `${spec.value} ${spec.unit}` : t('monitoring.na') }}
                                </dd>
                            </div>
                            <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-slate-100 dark:bg-white/[0.06]">
                                <div class="bar-fill h-full rounded-full" :style="{ width: `${spec.fill}%` }"></div>
                            </div>
                        </div>
                    </dl>
                </div>

                <div class="panel">
                    <header class="panel-header">
                        <div class="flex min-w-0 items-center gap-2">
                            <h3 class="panel-title">{{ t('monitoring.disks') }}</h3>
                            <span class="font-mono text-[11px] text-slate-400">{{ diskVolumes.length }}</span>
                        </div>
                        <span class="truncate text-[11px] text-slate-400">{{ t('monitoring.loopExcluded') }}</span>
                    </header>
                    <div v-if="diskVolumes.length > 0" class="grid gap-3 p-4 sm:grid-cols-2 sm:p-5">
                        <button
                            v-for="disk in diskVolumes"
                            :key="disk.id"
                            v-tilt="5"
                            type="button"
                            class="drive-card tilt relative rounded-xl border bg-slate-50/60 p-4 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 dark:bg-white/[0.02]"
                            :disabled="disk.mounts.length === 0"
                            :class="[
                                activeDiskKey === disk.id ? 'is-selected' : 'border-slate-200 dark:border-white/10',
                                disk.mounts.length === 0 ? 'cursor-default opacity-60' : 'cursor-pointer',
                            ]"
                            :style="{ '--accent': diskUsageColor(disk.usage_percent) }"
                            @click="selectedDiskId = disk.id; selectMetric('disk')"
                        >
                            <div class="depth flex items-start justify-between gap-2">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-300">
                                        <i class="bi" :class="disk.media_type === 'HDD' ? 'bi-device-hdd' : 'bi-device-ssd'"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate font-mono text-sm font-semibold text-slate-900 dark:text-slate-100">{{ disk.device }} <span class="font-normal text-slate-400">{{ disk.media_type }}</span></p>
                                        <p class="truncate text-xs text-slate-500 dark:text-slate-400" :title="disk.model">{{ disk.model }}</p>
                                    </div>
                                </div>
                                <span v-if="disk.usage_percent !== null" class="shrink-0 font-mono text-sm font-semibold tabular-nums" :style="{ color: diskUsageColor(disk.usage_percent) }">
                                    {{ Number(disk.usage_percent).toFixed(1) }}%
                                </span>
                                <span v-else class="shrink-0 rounded-full border border-slate-300 px-2 py-0.5 text-[10px] text-slate-500 dark:border-white/10">{{ t('monitoring.unmounted') }}</span>
                            </div>
                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-200/70 dark:bg-white/[0.06]">
                                <div class="drive-fill h-full rounded-full" :style="{ width: `${clampPercent(disk.usage_percent)}%` }"></div>
                            </div>
                            <div class="mt-2 flex justify-between gap-2 font-mono text-[10px] text-slate-500 dark:text-slate-400">
                                <span>{{ t('monitoring.used', { size: formatDiskSize(disk.mounted_used_gb) }) }}</span>
                                <span>{{ t('monitoring.free', { size: formatDiskSize(disk.mounted_free_gb) }) }}</span>
                                <span>{{ formatDiskSize(disk.total_gb) }}</span>
                            </div>
                            <p class="mt-1.5 truncate font-mono text-[10px] text-slate-400">{{ disk.mounts.length ? disk.mounts.map((mount) => mount.mountpoint).join('  ') : t('monitoring.noMount') }}</p>
                            <div v-if="disk.io" class="mt-3 grid grid-cols-3 gap-2 border-t border-slate-200 pt-2.5 font-mono text-[10px] dark:border-white/10">
                                <span class="min-w-0">
                                    <span class="block text-slate-400">{{ t('monitoring.read') }}</span>
                                    <span class="block truncate font-semibold tabular-nums text-cyan-600 dark:text-cyan-300">{{ formatRate(rates.disks[disk.id]?.read) }}</span>
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-slate-400">{{ t('monitoring.write') }}</span>
                                    <span class="block truncate font-semibold tabular-nums text-amber-600 dark:text-amber-300">{{ formatRate(rates.disks[disk.id]?.write) }}</span>
                                </span>
                                <span class="min-w-0 text-right">
                                    <span class="block text-slate-400">iops</span>
                                    <span class="block font-semibold tabular-nums text-slate-700 dark:text-slate-200">{{ Math.round(rates.disks[disk.id]?.iops || 0) }}</span>
                                </span>
                            </div>
                        </button>
                    </div>
                    <p v-else class="px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('monitoring.noDrives') }}</p>
                </div>
            </section>

            <!-- Processes -->
            <section class="panel">
                <header class="panel-header">
                    <h3 class="panel-title">{{ t('monitoring.topProcesses') }}</h3>
                    <span class="font-mono text-[11px] text-slate-400">{{ liveSnapshot.updated_at ?? '—' }}</span>
                </header>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 font-mono text-[10px] uppercase tracking-wider text-slate-500 dark:bg-white/[0.02] dark:text-slate-400">
                            <tr>
                                <th class="w-12 px-4 py-2.5 sm:px-5">#</th>
                                <th class="px-4 py-2.5">PID</th>
                                <th class="px-4 py-2.5">{{ t('monitoring.col.command') }}</th>
                                <th class="min-w-[170px] px-4 py-2.5">CPU</th>
                                <th class="min-w-[170px] px-4 py-2.5 sm:px-5">{{ t('monitoring.metric.memory') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/[0.06]">
                            <tr v-for="(proc, index) in topProcesses" :key="`${proc.pid}-${proc.name}`" class="transition hover:bg-slate-50 dark:hover:bg-white/[0.03]">
                                <td class="px-4 py-2.5 sm:px-5">
                                    <span class="rank-badge" :class="{ 'is-top': index === 0 }">{{ index + 1 }}</span>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-xs text-slate-500 dark:text-slate-400">{{ proc.pid }}</td>
                                <td class="px-4 py-2.5 font-mono text-[13px] font-medium text-slate-800 dark:text-slate-100">{{ proc.name }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-white/[0.06]">
                                            <div class="h-full rounded-full bg-emerald-500 transition-[width] duration-700" :style="{ width: `${clampPercent(proc.cpu)}%` }"></div>
                                        </div>
                                        <span class="w-14 text-right font-mono text-xs tabular-nums text-slate-700 dark:text-slate-200">{{ proc.cpu }}%</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 sm:px-5">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-white/[0.06]">
                                            <div class="h-full rounded-full bg-cyan-500 transition-[width] duration-700" :style="{ width: `${clampPercent(proc.mem)}%` }"></div>
                                        </div>
                                        <span class="w-14 text-right font-mono text-xs tabular-nums text-slate-700 dark:text-slate-200">{{ proc.mem }}%</span>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="topProcesses.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('monitoring.noProcesses') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* ---------- Shared panel shell (matches dashboard cards) ---------- */
.panel {
    overflow: hidden;
    border-radius: 1rem;
    border: 1px solid rgb(226 232 240);
    background: white;
    box-shadow: 0 1px 2px rgb(15 23 42 / 4%);
}

.dark .panel {
    border-color: rgb(255 255 255 / 10%);
    background: rgb(15 23 42);
    box-shadow: none;
}

.panel-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
    border-bottom: 1px solid rgb(226 232 240);
}

.dark .panel-header {
    border-color: rgb(255 255 255 / 10%);
}

@media (min-width: 640px) {
    .panel-header {
        padding-inline: 1.25rem;
    }
}

.panel-title {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.22em;
    color: rgb(100 116 139);
}

.dark .panel-title {
    color: rgb(148 163 184);
}

/* ---------- Toolbar buttons ---------- */
.toolbar-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    height: 2rem;
    padding: 0 0.75rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: rgb(71 85 105);
    background: white;
    border: 1px solid rgb(226 232 240);
    border-radius: 0.5rem;
    transition: all 150ms ease;
}

.toolbar-btn:hover {
    color: rgb(15 23 42);
    border-color: rgb(203 213 225);
}

.dark .toolbar-btn {
    color: rgb(203 213 225);
    background: rgb(15 23 42);
    border-color: rgb(255 255 255 / 10%);
}

.dark .toolbar-btn:hover {
    color: white;
    border-color: rgb(255 255 255 / 20%);
}

.toolbar-btn--accent,
.dark .toolbar-btn--accent {
    color: rgb(2 6 23);
    font-weight: 600;
    border-color: transparent;
    background: linear-gradient(135deg, #34d399, #22d3ee);
    box-shadow: 0 6px 16px -8px rgb(16 185 129 / 70%);
}

.toolbar-btn--accent:hover,
.dark .toolbar-btn--accent:hover {
    color: rgb(2 6 23);
    border-color: transparent;
    filter: brightness(1.05);
    transform: translateY(-1px);
}

/* ---------- Overview ---------- */
.hero-grid {
    background-image:
        linear-gradient(rgb(16 185 129 / 7%) 1px, transparent 1px),
        linear-gradient(90deg, rgb(16 185 129 / 7%) 1px, transparent 1px);
    background-size: 28px 28px;
    mask-image: linear-gradient(100deg, black, transparent 70%);
}

.hero-glow {
    background: radial-gradient(circle, rgb(34 211 238 / 18%), transparent 70%);
    filter: blur(10px);
}

.stat-tile {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.6rem 0.75rem;
    border-radius: 0.75rem;
    border: 1px solid rgb(226 232 240);
    background: rgb(248 250 252 / 70%);
}

.dark .stat-tile {
    border-color: rgb(255 255 255 / 8%);
    background: rgb(255 255 255 / 3%);
}

.stat-label {
    font-family: ui-monospace, monospace;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.18em;
    color: rgb(100 116 139);
}

.stat-value {
    font-size: 1.125rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: rgb(15 23 42);
}

.dark .stat-value {
    color: rgb(248 250 252);
}

.health-ring {
    display: grid;
    place-items: center;
    width: 168px;
    height: 168px;
    border-radius: 9999px;
    background: conic-gradient(from 0deg, var(--level) var(--score), rgb(226 232 240) var(--score));
    box-shadow: 0 16px 40px -18px var(--level);
    transition: background 600ms ease;
}

.dark .health-ring {
    background: conic-gradient(from 0deg, var(--level) var(--score), rgb(255 255 255 / 7%) var(--score));
    box-shadow: 0 0 50px -16px var(--level);
}

.health-ring__inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 140px;
    height: 140px;
    border-radius: 9999px;
    background: white;
    box-shadow: inset 0 2px 8px rgb(15 23 42 / 8%);
}

.dark .health-ring__inner {
    background: rgb(15 23 42);
    box-shadow: inset 0 2px 12px rgb(0 0 0 / 50%);
}

/* ---------- 3D tilt ---------- */
.tilt {
    transform: perspective(1000px) rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg));
    transform-style: preserve-3d;
    transition: transform 200ms ease-out, box-shadow 200ms ease, border-color 200ms ease;
}

.depth {
    transform: translateZ(14px);
}

.depth-lg {
    transform: translateZ(28px);
}

.glare {
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    background: radial-gradient(circle at var(--mx, 50%) var(--my, 0%), color-mix(in srgb, var(--accent) 12%, transparent), transparent 55%);
    opacity: 0;
    transition: opacity 200ms ease;
}

.tilt:hover .glare {
    opacity: 1;
}

/* ---------- Gauge cards ---------- */
.gauge-card {
    box-shadow: 0 1px 2px rgb(15 23 42 / 4%);
}

.gauge-card:hover {
    box-shadow: 0 18px 36px -20px color-mix(in srgb, var(--accent) 55%, transparent);
}

.gauge-card.is-selected {
    border-color: color-mix(in srgb, var(--accent) 55%, transparent);
    box-shadow:
        0 0 0 3px color-mix(in srgb, var(--accent) 14%, transparent),
        0 18px 40px -20px color-mix(in srgb, var(--accent) 60%, transparent);
}

.metric-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    flex-shrink: 0;
    border-radius: 0.6rem;
    color: white;
    background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 75%, white), var(--accent));
    box-shadow: 0 6px 14px -6px var(--accent), inset 0 1px rgb(255 255 255 / 30%);
}

.gauge-rotate {
    transform: rotate(135deg);
    transform-origin: 80px 80px;
}

.gauge-progress {
    transition: stroke-dashoffset 700ms cubic-bezier(0.16, 1, 0.3, 1);
}

.dark .gauge-progress,
.dark .signal-line {
    filter: drop-shadow(0 0 6px var(--accent, #10b981));
}

.gauge-tip {
    transition: cx 700ms cubic-bezier(0.16, 1, 0.3, 1), cy 700ms cubic-bezier(0.16, 1, 0.3, 1);
}

/* ---------- Segmented controls ---------- */
.segmented {
    display: inline-flex;
    padding: 2px;
    gap: 2px;
    border-radius: 0.5rem;
    background: rgb(241 245 249);
}

.dark .segmented {
    background: rgb(255 255 255 / 5%);
}

.segmented button {
    padding: 0.3rem 0.6rem;
    font-size: 11px;
    font-weight: 500;
    color: rgb(100 116 139);
    border-radius: 0.375rem;
    transition: all 150ms ease;
}

.segmented button:hover {
    color: rgb(15 23 42);
}

.dark .segmented button:hover {
    color: white;
}

.segmented button.is-active {
    color: rgb(15 23 42);
    background: white;
    box-shadow: 0 1px 3px rgb(15 23 42 / 12%), inset 0 -2px 0 var(--accent);
}

.dark .segmented button.is-active {
    color: white;
    background: rgb(255 255 255 / 10%);
}

/* ---------- Chart ---------- */
.chart-stat {
    display: flex;
    flex-direction: column;
    padding: 0.4rem 0.6rem;
    border-radius: 0.5rem;
    background: rgb(248 250 252);
}

.dark .chart-stat {
    background: rgb(255 255 255 / 3%);
}

.chart-stat__value {
    font-size: 0.875rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: rgb(15 23 42);
}

.dark .chart-stat__value {
    color: rgb(241 245 249);
}

.chart-point {
    stroke-width: 2px;
    opacity: 0;
    transition: r 120ms ease, opacity 150ms ease;
}

.chart-svg:hover .chart-point {
    opacity: 1;
}

.chart-point:hover,
.chart-point:focus {
    outline: none;
    opacity: 1;
    r: 6px;
}

/* ---------- Bars ---------- */
.bar-fill {
    background: linear-gradient(90deg, #34d399, #22d3ee);
    transition: width 700ms cubic-bezier(0.16, 1, 0.3, 1);
}

.net-stat {
    padding: 0.75rem;
    border-radius: 0.75rem;
    border: 1px solid rgb(226 232 240);
    background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 8%, transparent), transparent 70%);
}

.dark .net-stat {
    border-color: rgb(255 255 255 / 8%);
}

.dark .net-line {
    filter: drop-shadow(0 0 4px var(--accent));
}

.scope-chip {
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
    border: 1px solid;
}

.scope-chip--local {
    color: rgb(109 40 217);
    border-color: rgb(139 92 246 / 30%);
    background: rgb(139 92 246 / 10%);
}

.scope-chip--lan {
    color: rgb(180 83 9);
    border-color: rgb(245 158 11 / 30%);
    background: rgb(245 158 11 / 10%);
}

.scope-chip--external {
    color: rgb(14 116 144);
    border-color: rgb(6 182 212 / 30%);
    background: rgb(6 182 212 / 10%);
}

.dark .scope-chip--local {
    color: rgb(221 214 254);
}

.dark .scope-chip--lan {
    color: rgb(253 230 138);
}

.dark .scope-chip--external {
    color: rgb(165 243 252);
}

.temp-fill {
    background: linear-gradient(90deg, color-mix(in srgb, var(--accent) 55%, white), var(--accent));
    transition: width 700ms cubic-bezier(0.16, 1, 0.3, 1);
}

.drive-card:hover {
    border-color: color-mix(in srgb, var(--accent) 40%, transparent);
    box-shadow: 0 14px 30px -18px color-mix(in srgb, var(--accent) 60%, transparent);
}

.drive-card.is-selected {
    border-color: color-mix(in srgb, var(--accent) 55%, transparent);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 12%, transparent);
}

.drive-fill {
    background: linear-gradient(90deg, color-mix(in srgb, var(--accent) 65%, white), var(--accent));
    transition: width 700ms cubic-bezier(0.16, 1, 0.3, 1);
}

.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    font: 600 11px/1 ui-monospace, monospace;
    color: rgb(100 116 139);
    border-radius: 0.4rem;
    background: rgb(241 245 249);
}

.dark .rank-badge {
    color: rgb(148 163 184);
    background: rgb(255 255 255 / 6%);
}

.rank-badge.is-top {
    color: rgb(2 6 23);
    background: linear-gradient(135deg, #34d399, #22d3ee);
}

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
