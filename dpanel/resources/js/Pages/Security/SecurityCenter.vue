<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ProtectionBreakdown from './components/ProtectionBreakdown.vue';
import ScanLauncher from './components/ScanLauncher.vue';
import SecurityTrendChart from './components/SecurityTrendChart.vue';

const props = defineProps({
    score: { type: Object, required: true },
    unscanned: { type: Object, default: () => ({}) },
    scans: { type: Array, default: () => [] },
    trend: { type: Object, required: true },
    websites: { type: Array, default: () => [] },
    scanTypes: { type: Object, default: () => ({}) },
    isAdmin: { type: Boolean, default: false },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const message = ref(null);

const activeScans = computed(() => props.scans.filter((scan) => ['queued', 'running'].includes(scan.status)));

const severityStyles = {
    critical: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    high: 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300',
    medium: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    low: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    info: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

const scoreColor = (score) => {
    if (score === null || score === undefined) return 'text-slate-400';
    if (score >= 90) return 'text-emerald-600 dark:text-emerald-400';
    if (score >= 75) return 'text-lime-600 dark:text-lime-400';
    if (score >= 50) return 'text-amber-600 dark:text-amber-400';
    return 'text-red-600 dark:text-red-400';
};

// Each running scan polls its own progress; the rest of the page reloads once it ends.
const scanQueued = () => router.reload({ only: ['scans'] });

// Snapshot the score, reload, and say what moved and where.
const scanFinished = (scan) => {
    if (scan.status === 'failed') {
        message.value = { type: 'error', text: `${scan.target} scan failed: ${scan.error || 'unknown error'}` };
        router.reload({ preserveScroll: true });
        return;
    }
    const before = { overall: props.score.overall, categories: Object.fromEntries(props.score.categories.map((c) => [c.key, c.score])) };
    router.reload({
        preserveScroll: true,
        onSuccess: () => {
            const changes = props.score.categories
                .filter((c) => c.score !== before.categories[c.key])
                .map((c) => ({ label: c.label, from: before.categories[c.key], to: c.score }));
            const scoreText = before.overall === props.score.overall
                ? `score stays ${props.score.overall ?? '—'}`
                : `score ${before.overall ?? '—'} → ${props.score.overall ?? '—'}`;
            message.value = {
                type: props.score.overall !== null && before.overall !== null && props.score.overall < before.overall ? 'error' : 'success',
                text: `${scan.target} scan finished: ${scan.threats_found} threat(s) found, ${scoreText}.`,
                changes,
            };
        },
    });
};
</script>

<template>
    <Head title="Security Center" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Security Center</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Malware, file integrity, permissions and server configuration in one score.</p>
                </div>
                <div class="flex gap-2">
                    <Link :href="panelRoute('security.center.findings')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        All findings
                    </Link>
                    <Link :href="panelRoute('security.center.scans')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        Scan history
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="rounded-md border px-4 py-3 text-sm">
                <p>{{ message.text }}</p>
                <ul v-if="message.changes?.length" class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <li v-for="change in message.changes" :key="change.label">
                        {{ change.label }}: {{ change.from ?? 'not scanned' }} → <span class="font-semibold">{{ change.to ?? 'not scanned' }}</span>
                    </li>
                </ul>
            </div>

            <ScanLauncher
                :websites="websites"
                :scan-types="scanTypes"
                :active-scans="activeScans"
                :is-admin="isAdmin"
                :panel-route="panelRoute"
                @queued="scanQueued"
                @finished="scanFinished"
                @message="message = $event"
            />

            <section class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                <div class="rounded-xl border border-slate-200 bg-white p-6 text-center dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Security Score</p>
                    <p class="mt-3 text-5xl font-bold" :class="scoreColor(score.overall)">
                        {{ score.overall ?? '—' }}<span class="text-xl text-slate-400"> / 100</span>
                    </p>
                    <p class="mt-2 text-sm font-semibold uppercase tracking-wide" :class="scoreColor(score.overall)">{{ score.grade }}</p>
                    <div class="mt-6 grid grid-cols-2 gap-2 text-left text-sm">
                        <Link
                            v-for="severity in ['critical', 'high', 'medium', 'low']"
                            :key="severity"
                            :href="panelRoute('security.center.findings', { severity })"
                            class="flex items-center justify-between rounded-md px-3 py-2"
                            :class="severityStyles[severity]"
                        >
                            <span class="capitalize">{{ severity }}</span>
                            <span class="font-semibold">{{ score.counts[severity] ?? 0 }}</span>
                        </Link>
                    </div>
                    <p v-if="score.overall === null" class="mt-4 text-xs text-slate-500">Run a scan to calculate the score.</p>
                </div>

                <SecurityTrendChart :trend="trend" />
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">Protection</h2>
                <ProtectionBreakdown
                    :categories="score.categories"
                    :unscanned="unscanned"
                    :websites="websites"
                    :active-scans="activeScans"
                    :is-admin="isAdmin"
                    :panel-route="panelRoute"
                    @message="message = $event"
                    @queued="scanQueued"
                />
                <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                    Click a row to see what it checks. Categories without data are left out of the overall score.
                </p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
