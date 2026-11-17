<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    categories: { type: Array, required: true },
    // category => ids of websites no completed scan has measured yet
    unscanned: { type: Object, default: () => ({}) },
    websites: { type: Array, default: () => [] },
    activeScans: { type: Array, default: () => [] },
    isAdmin: { type: Boolean, default: false },
    panelRoute: { type: Function, required: true },
});

const emit = defineEmits(['message', 'queued']);

const expanded = ref(null);
const busy = ref(null);

const scanLabels = computed(() => Object.fromEntries(['quick', 'full', 'malware', 'integrity', 'configuration'].map((key) => [key, t(`security.common.scanTypes.${key}`)])));

const scoreColor = (score) => {
    if (score >= 90) return 'text-emerald-600 dark:text-emerald-400';
    if (score >= 75) return 'text-lime-600 dark:text-lime-400';
    if (score >= 50) return 'text-amber-600 dark:text-amber-400';
    return 'text-red-600 dark:text-red-400';
};

const barColor = (score) => {
    if (score >= 90) return 'bg-emerald-500';
    if (score >= 75) return 'bg-lime-500';
    if (score >= 50) return 'bg-amber-500';
    return 'bg-red-500';
};

// Only what this user can actually check: planned categories have no scanner
// yet, server ones are admin-only, and website ones need a website.
const checkable = (category) => {
    if (category.planned) return false;
    if (category.server) return props.isAdmin;
    return props.websites.length > 0;
};
const visible = computed(() => {
    const rows = props.categories.filter(checkable);
    const total = rows.reduce((sum, category) => sum + category.weight, 0) || 1;
    // Rescale so the shown weights add up to 100%, as the overall score does.
    return rows.map((category) => ({ ...category, share: Math.round((category.weight / total) * 100) }));
});

const missing = (category) => props.unscanned[category.key] || [];
const serverScanRunning = () => props.activeScans.some((scan) => scan.website_id === null);
const canScanServer = (category) => category.server && props.isAdmin;
const canScanWebsites = (category) => !category.server && category.scan_type && props.websites.length > 0;

const status = (category) => {
    if (category.score !== null) return null;
    return t('security.protection.notScanned');
};

// One line saying why the row looks the way it does.
const summary = (category) => {
    if (category.score === null) {
        if (category.server) return t('security.protection.noServerScan');
        return t('security.protection.noWebsiteScan', { type: scanLabels.value[category.scan_type] });
    }
    const parts = [];
    parts.push(category.findings > 0 ? (category.findings === 1 ? t('security.protection.oneFinding') : t('security.protection.manyFindings', { count: category.findings })) : t('security.protection.noFindings'));
    if (!category.server && missing(category).length > 0) {
        parts.push(t('security.protection.notScannedOf', { count: missing(category).length, total: props.websites.length }));
    }
    return parts.join(' · ');
};

const scanButtonLabel = (category) => {
    if (category.server) return serverScanRunning() ? t('security.protection.scanning') : (category.score === null ? t('security.protection.scanServer') : t('security.protection.rescanServer'));
    const count = missing(category).length;
    if (count > 0) return count === 1 ? t('security.protection.scanOne') : t('security.protection.scanMany', { count });
    return t('security.protection.rescanAll');
};

const scan = async (category) => {
    busy.value = category.key;
    try {
        if (category.server) {
            await axios.post(props.panelRoute('security.center.scans.start'), { website_id: null, scan_type: 'configuration' });
            emit('message', { type: 'success', text: t('security.protection.serverQueued') });
        } else {
            const { data } = await axios.post(props.panelRoute('security.center.scans.category'), { category: category.key });
            emit('message', { type: 'success', text: `${data.message} ${t('security.protection.refreshesWhen')}` });
        }
        emit('queued');
    } catch (e) {
        emit('message', { type: 'error', text: e.response?.data?.message || t('security.scanLauncher.startFailed') });
    } finally {
        busy.value = null;
    }
};
</script>

<template>
    <div class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
        <div v-for="category in visible" :key="category.key" class="py-2.5 text-sm">
            <button
                type="button"
                class="grid w-full grid-cols-[8rem_minmax(0,1fr)_5rem] items-center gap-3 text-left"
                :aria-expanded="expanded === category.key"
                @click="expanded = expanded === category.key ? null : category.key"
            >
                <span>{{ category.label }} <span class="text-xs text-slate-400">{{ category.share }}%</span></span>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div v-if="category.score !== null" class="h-full rounded-full" :class="barColor(category.score)" :style="{ width: category.score + '%' }"></div>
                </div>
                <span v-if="category.score !== null" class="text-right font-semibold" :class="scoreColor(category.score)">{{ category.score }}</span>
                <span v-else class="text-right text-xs" :class="category.score === null ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400'">{{ status(category) }}</span>
            </button>

            <div class="mt-1 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs">
                <span class="text-slate-500 dark:text-slate-400">{{ summary(category) }}</span>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-if="category.findings > 0"
                        :href="panelRoute('security.center.findings', { category: category.key })"
                        class="text-blue-600 hover:underline dark:text-blue-300"
                    >{{ t('security.protection.viewFindings') }}</Link>
                    <Link
                        v-if="isAdmin && category.fix_route && (category.findings > 0 || category.score === null)"
                        :href="panelRoute(category.fix_route)"
                        class="text-blue-600 hover:underline dark:text-blue-300"
                    >{{ category.fix_label }}</Link>
                    <button
                        v-if="canScanServer(category) || canScanWebsites(category)"
                        type="button"
                        :disabled="busy !== null || (category.server && serverScanRunning())"
                        class="rounded border px-2 py-0.5 disabled:opacity-50"
                        :class="category.score === null || missing(category).length > 0
                            ? 'border-blue-600 bg-blue-600 text-white hover:bg-blue-700'
                            : 'border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800'"
                        @click="scan(category)"
                    >{{ busy === category.key ? t('security.protection.starting') : scanButtonLabel(category) }}</button>
                </div>
            </div>

            <div v-if="expanded === category.key" class="mt-2 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                <p>{{ category.checks }}</p>
                <p v-if="category.scan_type" class="mt-1 text-slate-500">{{ category.key === 'malware' ? t('security.protection.measuredByMalware', { type: scanLabels[category.scan_type] }) : t('security.protection.measuredBy', { type: scanLabels[category.scan_type] }) }}</p>
                <p v-if="category.findings > 0" class="mt-1 text-slate-500">{{ t('security.protection.eachFinding') }}</p>
            </div>
        </div>
        <p v-if="visible.length === 0" class="py-4 text-center text-sm text-slate-500">{{ t('security.protection.empty') }}</p>
    </div>
</template>
