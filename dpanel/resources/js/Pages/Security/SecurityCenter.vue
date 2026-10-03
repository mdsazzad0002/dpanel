<script setup>
import { ref, computed, onBeforeUnmount, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import ProtectionBreakdown from './components/ProtectionBreakdown.vue';
import RecentEventsCard from './components/RecentEventsCard.vue';

const props = defineProps({
    score: { type: Object, required: true },
    unscanned: { type: Object, default: () => ({}) },
    recentFindings: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    scans: { type: Array, default: () => [] },
    websites: { type: Array, default: () => [] },
    scanTypes: { type: Object, default: () => ({}) },
    rules: { type: Array, default: () => [] },
    isAdmin: { type: Boolean, default: false },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const target = ref(props.isAdmin ? 'server' : (props.websites[0]?.id || ''));
const scanType = ref('quick');
const starting = ref(false);
const message = ref(null);
const rules = ref(props.rules.map((rule) => ({ ...rule })));
const showRules = ref(false);
const togglingRule = ref(null);

const isServerTarget = computed(() => target.value === 'server');
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

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');

const startScan = async () => {
    if (!target.value) return;
    starting.value = true;
    message.value = null;
    try {
        await axios.post(panelRoute('security.center.scans.start'), {
            website_id: isServerTarget.value ? null : target.value,
            scan_type: isServerTarget.value ? 'configuration' : scanType.value,
        });
        message.value = { type: 'success', text: 'Scan queued. This page refreshes when it finishes.' };
        router.reload({ only: ['scans', 'events'] });
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Could not start the scan.' };
    } finally {
        starting.value = false;
    }
};

const toggleRule = async (rule) => {
    togglingRule.value = rule.rule_id;
    try {
        const { data } = await axios.post(panelRoute('security.center.rules.toggle', { rule: rule.rule_id }), { enabled: !rule.enabled });
        rule.enabled = data.rule.enabled;
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Could not update the rule.' };
    } finally {
        togglingRule.value = null;
    }
};

// Poll while a scan is queued or running, then reload everything once.
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (activeScans.value.length === 0) return;
        router.reload({ preserveScroll: true });
    }, 5000);
});
onBeforeUnmount(() => clearInterval(timer));
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
                <Link :href="panelRoute('security.center.findings')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                    All findings
                </Link>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="rounded-md border px-4 py-3 text-sm">
                {{ message.text }}
            </div>

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

                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-base font-semibold">Protection</h2>
                    <ProtectionBreakdown
                        :categories="score.categories"
                        :unscanned="unscanned"
                        :websites="websites"
                        :active-scans="activeScans"
                        :is-admin="isAdmin"
                        :panel-route="panelRoute"
                        @message="message = $event"
                        @queued="router.reload({ only: ['scans', 'events'] })"
                    />
                    <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                        Click a row to see what it checks. Categories without data are left out of the overall score. WAF and update checks arrive in a later phase.
                    </p>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">Scan now</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-[2fr_2fr_auto] md:items-end">
                    <div>
                        <label class="mb-1 block text-sm">Target</label>
                        <select v-model="target" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                            <option v-if="isAdmin" value="server">Server configuration (SSH, firewall, ports, SSL, backups)</option>
                            <option v-for="website in websites" :key="website.id" :value="website.id">{{ website.domain }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Scan type</label>
                        <select v-model="scanType" :disabled="isServerTarget" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:opacity-50 dark:border-slate-700 dark:bg-slate-800">
                            <option v-for="(label, key) in scanTypes" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <button type="button" :disabled="starting || !target" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40" @click="startScan">
                        {{ starting ? 'Starting…' : 'Scan Now' }}
                    </button>
                </div>
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    The first scan of a website records file hashes; later scans report files that changed since the previous scan.
                </p>
            </section>

            <section class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold">Top open findings</h2>
                        <Link :href="panelRoute('security.center.findings')" class="text-xs text-blue-600 hover:underline dark:text-blue-300">View all</Link>
                    </div>
                    <ul class="mt-4 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <li v-for="finding in recentFindings" :key="finding.id" class="py-2">
                            <div class="flex items-start gap-2">
                                <span class="rounded-full px-2 py-0.5 text-xs capitalize" :class="severityStyles[finding.severity]">{{ finding.severity }}</span>
                                <div class="min-w-0">
                                    <p class="font-medium">{{ finding.title }}</p>
                                    <p class="truncate text-xs text-slate-500">
                                        {{ finding.website?.domain || 'Server' }}<span v-if="finding.file_path"> · {{ finding.file_path }}<span v-if="finding.line_number">:{{ finding.line_number }}</span></span>
                                    </p>
                                </div>
                            </div>
                        </li>
                        <li v-if="recentFindings.length === 0" class="py-4 text-center text-slate-500">No open findings.</li>
                    </ul>
                </div>

                <RecentEventsCard :events="events" :severity-styles="severityStyles" :panel-route="panelRoute" @deleted="router.reload({ only: ['events'] })" />
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">Scan history</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-2">Target</th>
                                <th class="px-3 py-2">Type</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Files</th>
                                <th class="px-3 py-2">Threats</th>
                                <th class="px-3 py-2">Risk</th>
                                <th class="px-3 py-2">Finished</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="scan in scans" :key="scan.id" class="border-t border-slate-200 align-top dark:border-slate-800">
                                <td class="px-3 py-2">{{ scan.target }}</td>
                                <td class="px-3 py-2 capitalize">{{ scan.scan_type }}</td>
                                <td class="px-3 py-2">
                                    <span class="capitalize" :class="{ 'text-red-600': scan.status === 'failed', 'text-emerald-600': scan.status === 'completed', 'text-amber-600': ['queued', 'running'].includes(scan.status) }">{{ scan.status }}</span>
                                    <p v-if="scan.error" class="max-w-xs text-xs text-red-600">{{ scan.error }}</p>
                                    <p v-else-if="scan.clamav?.error" class="max-w-xs text-xs text-amber-600">ClamAV: {{ scan.clamav.error }}</p>
                                    <p v-if="scan.truncated" class="text-xs text-amber-600">File limit reached; not every file was scanned.</p>
                                </td>
                                <td class="px-3 py-2">{{ scan.files_scanned }}</td>
                                <td class="px-3 py-2">{{ scan.threats_found }}</td>
                                <td class="px-3 py-2">{{ scan.risk_score ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs">{{ formatDate(scan.completed_at) }}</td>
                            </tr>
                            <tr v-if="scans.length === 0">
                                <td colspan="7" class="px-3 py-4 text-center text-slate-500">No scans yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="isAdmin" class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold">Detection rules</h2>
                    <button type="button" class="text-xs text-blue-600 hover:underline dark:text-blue-300" @click="showRules = !showRules">{{ showRules ? 'Hide' : 'Show' }} ({{ rules.length }})</button>
                </div>
                <div v-if="showRules" class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <tbody>
                            <tr v-for="rule in rules" :key="rule.rule_id" class="border-t border-slate-200 dark:border-slate-800">
                                <td class="px-3 py-2 font-mono text-xs">{{ rule.rule_id }}</td>
                                <td class="px-3 py-2">{{ rule.name }}</td>
                                <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs capitalize" :class="severityStyles[rule.severity]">{{ rule.severity }}</span></td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button" :disabled="togglingRule === rule.rule_id" class="rounded-md border px-2 py-1 text-xs disabled:opacity-50" :class="rule.enabled ? 'border-emerald-300 text-emerald-700 dark:border-emerald-700 dark:text-emerald-300' : 'border-slate-300 text-slate-500 dark:border-slate-700'" @click="toggleRule(rule)">
                                        {{ rule.enabled ? 'Enabled' : 'Disabled' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-xs text-slate-500">Disabled rules are skipped when scan results are saved.</p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
