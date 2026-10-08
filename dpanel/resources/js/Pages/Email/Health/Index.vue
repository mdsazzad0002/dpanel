<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    mailHealth: { type: Object, required: true },
    outboundGate: { type: Object, default: () => ({}) },
    outboundGateEnabled: { type: Boolean, default: false },
    mailboxProblems: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const canClearLog = computed(() => (page.props.auth?.roles ?? []).some((role) => ['admin', 'superadmin'].includes(role)));
const clearingLog = ref(false);
const logSize = computed(() => props.mailHealth.diagnostics?.log_size_bytes);

const clearLog = () => {
    if (!confirm('Clear the mail log? The current log is archived as a .gz file first (the newest 5 archives are kept).')) return;
    clearingLog.value = true;
    const token = page.props.panel?.token;
    router.post(token ? route('mail-health.clear-log', { token }) : route('mail-health.clear-log'), {}, {
        preserveScroll: true,
        onFinish: () => { clearingLog.value = false; },
    });
};

// A plain link (not an Inertia visit) so the browser handles the file download.
const downloadLogUrl = computed(() => {
    const token = page.props.panel?.token;
    return token ? route('mail-health.download-log', { token }) : route('mail-health.download-log');
});

const panelRoute = (name, params = {}) => {
    const token = page.props.panel?.token;
    return route(name, token ? { token, ...params } : params);
};
// Kept locally so a check updates the page without reloading it.
const gateState = ref(props.outboundGate ?? {});
const gateEnabled = ref(props.outboundGateEnabled);
watch(() => props.outboundGate, (value) => { gateState.value = value ?? {}; });
watch(() => props.outboundGateEnabled, (value) => { gateEnabled.value = value; });
const gate = computed(() => gateState.value ?? {});
const gateIpRows = computed(() => [gate.value.facts?.ipv4, gate.value.facts?.ipv6].filter(Boolean));

const result = ref(null);
const showResult = (data) => {
    result.value = {
        level: data?.level ?? 'error',
        title: data?.title ?? 'Something went wrong',
        message: data?.message ?? '',
        notes: (data?.notes ?? []).filter(Boolean),
        rows: data?.gate?.facts ? [data.gate.facts.ipv4, data.gate.facts.ipv6].filter(Boolean) : [],
    };
};
const showRequestError = (error) => showResult({
    level: 'error',
    title: 'The check could not run',
    message: error?.response?.data?.message || error?.message || 'Request failed.',
});
const resultTone = computed(() => ({
    success: { icon: 'bi-check-circle-fill', text: 'text-emerald-600 dark:text-emerald-400', box: 'bg-emerald-50 dark:bg-emerald-950/40' },
    warning: { icon: 'bi-exclamation-triangle-fill', text: 'text-amber-600 dark:text-amber-400', box: 'bg-amber-50 dark:bg-amber-950/40' },
    error: { icon: 'bi-x-octagon-fill', text: 'text-red-600 dark:text-red-400', box: 'bg-red-50 dark:bg-red-950/40' },
}[result.value?.level] ?? { icon: 'bi-info-circle-fill', text: 'text-slate-600', box: 'bg-slate-50 dark:bg-slate-800' }));

const checkingOutbound = ref(false);
const runOutboundCheck = async () => {
    checkingOutbound.value = true;
    try {
        const { data } = await window.axios.post(panelRoute('mail-health.outbound-check'), {}, { headers: { Accept: 'application/json' } });
        if (data?.gate) gateState.value = data.gate;
        showResult(data);
    } catch (error) {
        showRequestError(error);
    } finally {
        checkingOutbound.value = false;
    }
};
const savingGate = ref(false);
const toggleGate = async () => {
    savingGate.value = true;
    try {
        const { data } = await window.axios.patch(panelRoute('mail-health.outbound-gate'), { enabled: !gateEnabled.value }, { headers: { Accept: 'application/json' } });
        gateEnabled.value = Boolean(data?.enabled);
        if (data?.gate) gateState.value = data.gate;
        showResult(data);
    } catch (error) {
        showRequestError(error);
    } finally {
        savingGate.value = false;
    }
};

const activeTab = ref('failures');
const statusFilter = ref('all');
const refreshing = ref(false);
const stats = computed(() => props.mailHealth.stats ?? {});
const failures = computed(() => {
    const rows = props.mailHealth.failures ?? [];
    return statusFilter.value === 'all' ? rows : rows.filter((row) => row.status === statusFilter.value);
});
const queue = computed(() => props.mailHealth.queue ?? { messages: [] });
const spamEvents = computed(() => props.mailHealth.spam_events ?? []);
const score = computed(() => props.mailHealth.health_score);
const scoreTone = computed(() => {
    if (score.value === null || score.value === undefined) return 'slate';
    if (score.value >= 85) return 'emerald';
    if (score.value >= 65) return 'amber';
    return 'red';
});
const scoreLabel = computed(() => {
    if (score.value === null || score.value === undefined) return 'No data';
    if (score.value >= 85) return 'Healthy';
    if (score.value >= 65) return 'Needs attention';
    return 'Action required';
});
const scoreTextClass = computed(() => ({
    slate: 'text-slate-600 dark:text-slate-400',
    emerald: 'text-emerald-600 dark:text-emerald-400',
    amber: 'text-amber-600 dark:text-amber-400',
    red: 'text-red-600 dark:text-red-400',
}[scoreTone.value]));
const scoreCircleClass = computed(() => ({
    slate: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    emerald: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300',
    amber: 'bg-amber-100 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300',
    red: 'bg-red-100 text-red-600 dark:bg-red-950/50 dark:text-red-300',
}[scoreTone.value]));
const toneTextClass = {
    emerald: 'text-emerald-500',
    amber: 'text-amber-500',
    red: 'text-red-500',
    rose: 'text-rose-500',
};

const refresh = () => {
    refreshing.value = true;
    router.reload({
        only: ['mailHealth', 'outboundGate', 'outboundGateEnabled', 'mailboxProblems'],
        preserveScroll: true,
        onFinish: () => { refreshing.value = false; },
    });
};

const formatBytes = (bytes) => {
    const value = Number(bytes || 0);
    if (value >= 1048576) return `${(value / 1048576).toFixed(1)} MB`;
    if (value >= 1024) return `${(value / 1024).toFixed(1)} KB`;
    return `${value} B`;
};

const statusClass = (status) => ({
    bounced: 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
    rejected: 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
    deferred: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
}[status] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300');
</script>

<template>
    <Head title="Mail Health" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Mail Health</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Delivery failures, queue health, spam signals, and suggested fixes</p>
                </div>
                <button type="button" :disabled="refreshing" class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="refresh">
                    <i class="bi bi-arrow-clockwise" :class="{ 'animate-spin': refreshing }"></i>
                    {{ refreshing ? 'Refreshing…' : 'Refresh' }}
                </button>
            </div>
        </template>

        <div class="space-y-5">
            <div v-if="flash.success" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">{{ flash.success }}</div>
            <div v-if="flash.error" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">{{ flash.error }}</div>

            <section
                v-if="gate.outbound === 'paused'"
                class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 gap-3">
                        <i class="bi bi-pause-circle mt-0.5"></i>
                        <div class="min-w-0">
                            <p class="font-semibold">Outbound mail is paused</p>
                            <p class="mt-1">{{ gate.reason }}</p>
                            <p class="mt-1">Mail to other servers waits in the queue and is sent automatically once the PTR is fixed, or as soon as you turn off "Hold mail if PTR is wrong" below. Ask your IP provider to set the PTR (reverse DNS) of the server IP to your mail hostname, and make sure that hostname's A record points back to the IP.</p>
                        </div>
                    </div>
                    <button v-if="canClearLog" type="button" :disabled="checkingOutbound" class="inline-flex items-center gap-1.5 rounded-md border border-red-300 px-2.5 py-1 text-xs font-medium hover:bg-red-100 disabled:opacity-60 dark:border-red-800 dark:hover:bg-red-900/40" @click="runOutboundCheck">
                        <i class="bi bi-arrow-repeat" :class="{ 'animate-spin': checkingOutbound }"></i>
                        {{ checkingOutbound ? 'Checking…' : 'Check again' }}
                    </button>
                </div>
            </section>

            <section v-if="mailboxProblems.length" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                <p class="font-semibold"><i class="bi bi-exclamation-triangle mr-1"></i>{{ mailboxProblems.length }} mailbox(es) cannot receive mail</p>
                <p class="mt-1">Dovecot cannot find these, so mail for them bounces. In <Link :href="panelRoute('emails.list')" class="font-medium underline hover:no-underline">Email Management</Link>, turn the mailbox off and on again: turning it on runs every check and shows which one fails.</p>
                <ul class="mt-2 space-y-1">
                    <li v-for="mailbox in mailboxProblems" :key="mailbox.id">
                        <span class="font-mono font-semibold">{{ mailbox.email }}</span>
                        <span class="block break-words text-xs opacity-80">{{ mailbox.health_error }}</span>
                    </li>
                </ul>
            </section>

            <div v-if="!mailHealth.diagnostics?.log_source" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                <div class="flex gap-3">
                    <i class="bi bi-exclamation-triangle mt-0.5"></i>
                    <div><p class="font-semibold">Mail logs are not readable</p><p class="mt-1">The panel reads the Postfix log through the execution service (<code>mail-log-tail.sh</code>). Make sure drust is running and its runtime scripts are up to date, or set <code>SERVERPANEL_MAIL_HEALTH_LOG_PATHS</code> to a log under <code>/var/log</code>. Queue data may still be available.</p></div>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                <section class="rounded-xl border border-slate-200 bg-white p-4 sm:col-span-2 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between gap-4">
                        <div><p class="text-sm text-slate-500">Health score</p><p class="mt-1 text-3xl font-bold" :class="scoreTextClass">{{ score ?? '—' }}<span v-if="score !== null" class="text-base font-medium">/100</span></p><p class="text-sm font-medium">{{ scoreLabel }}</p></div>
                        <div class="grid h-16 w-16 place-items-center rounded-full" :class="scoreCircleClass"><i class="bi bi-heart-pulse text-2xl"></i></div>
                    </div>
                </section>
                <section v-for="card in [
                    { label: 'Delivered', value: stats.sent ?? 0, icon: 'bi-check-circle', tone: 'emerald' },
                    { label: 'Deferred', value: stats.deferred ?? 0, icon: 'bi-clock-history', tone: 'amber' },
                    { label: 'Bounced', value: stats.bounced ?? 0, icon: 'bi-arrow-return-left', tone: 'red' },
                    { label: 'Rejected', value: stats.rejected ?? 0, icon: 'bi-shield-x', tone: 'rose' },
                ]" :key="card.label" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between"><span class="text-sm text-slate-500">{{ card.label }}</span><i class="bi" :class="[card.icon, toneTextClass[card.tone]]"></i></div>
                    <p class="mt-2 text-2xl font-bold">{{ card.value }}</p>
                </section>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                    <div><p class="text-xs uppercase tracking-wide text-slate-500">Delivery rate</p><p class="mt-1 text-lg font-semibold">{{ mailHealth.delivery_rate === null ? 'No data' : `${mailHealth.delivery_rate}%` }}</p></div>
                    <div><p class="text-xs uppercase tracking-wide text-slate-500">Queued messages</p><p class="mt-1 text-lg font-semibold">{{ queue.available ? queue.count : 'Unavailable' }}</p></div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Outbound</p>
                        <p class="mt-1 text-lg font-semibold capitalize" :class="gate.outbound === 'paused' ? 'text-red-600' : ''">{{ gate.outbound ?? 'unknown' }}</p>
                        <p class="text-xs text-slate-500">IPv6 {{ gate.ipv6 === 'allow' ? 'on' : gate.ipv6 === 'deny' ? 'off (IPv4 only)' : 'unknown' }}</p>
                        <p v-for="row in gateIpRows" :key="row.ip" class="truncate text-xs" :class="row.ok ? 'text-emerald-600' : 'text-red-600'" :title="row.message">
                            <i class="bi" :class="row.ok ? 'bi-check-circle' : 'bi-x-circle'"></i> {{ row.ip }} → {{ row.ptr || 'no PTR' }}
                        </p>
                        <label v-if="canClearLog" class="mt-2 flex cursor-pointer items-center gap-2 text-xs text-slate-600 dark:text-slate-300" title="When on, outbound mail waits in the queue while the server IP's PTR is wrong, instead of bouncing at Gmail.">
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="gateEnabled"
                                :disabled="savingGate"
                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition disabled:opacity-60"
                                :class="gateEnabled ? 'bg-cyan-600' : 'bg-slate-300 dark:bg-slate-600'"
                                @click="toggleGate"
                            >
                                <span class="inline-block h-4 w-4 rounded-full bg-white shadow transition" :class="gateEnabled ? 'translate-x-4' : 'translate-x-0.5'"></span>
                            </button>
                            Hold mail if PTR is wrong
                        </label>
                        <button v-if="canClearLog && gate.outbound !== 'paused'" type="button" :disabled="checkingOutbound" class="mt-2 inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="runOutboundCheck">
                            <i class="bi bi-arrow-repeat" :class="{ 'animate-spin': checkingOutbound }"></i>
                            {{ checkingOutbound ? 'Checking…' : 'Check PTR' }}
                        </button>
                    </div>
                    <div><p class="text-xs uppercase tracking-wide text-slate-500">Spam engine</p><p class="mt-1 text-lg font-semibold">{{ mailHealth.diagnostics?.spam_engine?.name ?? 'Not detected' }}</p></div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Log sample</p>
                        <p class="mt-1 text-lg font-semibold">{{ mailHealth.diagnostics?.lines_analyzed ?? 0 }} lines</p>
                        <p class="truncate text-xs text-slate-500">
                            {{ mailHealth.diagnostics?.log_source ?? 'No source' }}<span v-if="logSize !== null && logSize !== undefined"> · {{ formatBytes(logSize) }}</span>
                        </p>
                        <div v-if="canClearLog && logSize" class="mt-2 flex flex-wrap gap-2">
                            <a
                                :href="downloadLogUrl"
                                title="Downloads the most recent 20,000 mail log lines"
                                class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:border-cyan-300 hover:bg-cyan-50 hover:text-cyan-700 dark:border-slate-700 dark:text-slate-300 dark:hover:border-cyan-900 dark:hover:bg-cyan-950/40 dark:hover:text-cyan-300"
                            >
                                <i class="bi bi-download"></i>
                                Download log
                            </a>
                            <button
                                type="button"
                                :disabled="clearingLog"
                                class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:border-red-300 hover:bg-red-50 hover:text-red-700 disabled:opacity-60 dark:border-slate-700 dark:text-slate-300 dark:hover:border-red-900 dark:hover:bg-red-950/40 dark:hover:text-red-300"
                                @click="clearLog"
                            >
                                <i class="bi" :class="clearingLog ? 'bi-arrow-repeat animate-spin' : 'bi-trash3'"></i>
                                {{ clearingLog ? 'Clearing…' : 'Clear log' }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex gap-1 overflow-x-auto rounded-lg bg-slate-100 p-1 dark:bg-slate-800">
                <button v-for="tab in [
                    { id: 'failures', label: 'Failed emails', count: mailHealth.failures?.length ?? 0 },
                    { id: 'queue', label: 'Mail queue', count: queue.count ?? 0 },
                    { id: 'spam', label: 'Spam detector', count: spamEvents.length },
                ]" :key="tab.id" type="button" class="whitespace-nowrap rounded-md px-4 py-2 text-sm font-medium" :class="activeTab === tab.id ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white' : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'" @click="activeTab = tab.id">
                    {{ tab.label }} <span class="ml-1 rounded-full bg-slate-200 px-1.5 py-0.5 text-xs dark:bg-slate-700">{{ tab.count }}</span>
                </button>
            </div>

            <section v-if="activeTab === 'failures'" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold">Why delivery failed</h2>
                    <select v-model="statusFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                        <option value="all">All failures</option><option value="deferred">Deferred</option><option value="bounced">Bounced</option><option value="rejected">Rejected</option>
                    </select>
                </div>
                <article v-for="event in failures" :key="event.id" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize" :class="statusClass(event.status)">{{ event.status }}</span><span class="font-semibold">{{ event.diagnosis.label }}</span><span v-if="event.diagnosis.temporary" class="text-xs text-amber-600">Postfix may retry</span></div><p class="mt-1 text-sm text-slate-500">{{ event.timestamp || 'Time unavailable' }} · Queue {{ event.queue_id }}</p></div>
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-xs dark:bg-slate-800">{{ event.diagnosis.category.replaceAll('_', ' ') }}</span>
                    </div>
                    <div class="mt-3 grid gap-2 text-sm sm:grid-cols-2"><p><span class="text-slate-500">From:</span> {{ event.sender || 'Unknown' }}</p><p><span class="text-slate-500">To:</span> {{ event.recipient || 'Unknown' }}</p></div>
                    <details class="mt-3 rounded-lg bg-slate-50 p-3 dark:bg-slate-950/50"><summary class="cursor-pointer text-sm font-medium">Server response</summary><p class="mt-2 break-words font-mono text-xs text-slate-600 dark:text-slate-300">{{ event.reason }}</p></details>
                    <div class="mt-3 grid gap-3 md:grid-cols-2"><div class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950/40 dark:text-blue-200"><p class="font-semibold">What happened</p><p class="mt-1">{{ event.diagnosis.explanation }}</p></div><div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"><p class="font-semibold">Suggested fix</p><p class="mt-1">{{ event.diagnosis.suggestion }}</p></div></div>
                </article>
                <div v-if="failures.length === 0" class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500 dark:border-slate-700"><i class="bi bi-check-circle text-3xl text-emerald-500"></i><p class="mt-2 font-medium">No matching delivery failures found</p><p class="text-sm">This reflects the current readable log sample.</p></div>
            </section>

            <section v-else-if="activeTab === 'queue'" class="space-y-3">
                <div v-if="!queue.available" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"><p class="font-semibold">Postfix queue is unavailable</p><p class="mt-1">{{ queue.error }}</p></div>
                <article v-for="message in queue.messages" :key="message.queue_id" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><div class="flex flex-wrap justify-between gap-2"><div><span class="font-mono font-semibold">{{ message.queue_id }}</span><span class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs capitalize text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">{{ message.status_marker }}</span></div><span class="text-sm text-slate-500">{{ formatBytes(message.size_bytes) }}</span></div><p class="mt-2 text-sm"><span class="text-slate-500">From:</span> {{ message.sender || 'MAILER-DAEMON' }}</p><p class="mt-1 text-sm"><span class="text-slate-500">To:</span> {{ message.recipients?.join(', ') || 'Unavailable' }}</p></article>
                <div v-if="queue.available && queue.messages?.length === 0" class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500 dark:border-slate-700"><i class="bi bi-inbox text-3xl text-emerald-500"></i><p class="mt-2 font-medium">Mail queue is empty</p></div>
            </section>

            <section v-else class="space-y-3">
                <div v-if="!mailHealth.diagnostics?.spam_engine?.detected" class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200"><p class="font-semibold">No spam engine activity detected</p><p class="mt-1">Install/configure Rspamd or SpamAssassin and ensure its logs are readable to populate this section.</p></div>
                <article v-for="event in spamEvents" :key="event.id" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><div class="flex flex-wrap items-center justify-between gap-3"><div><span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-950/50 dark:text-red-300">{{ event.action }}</span><span class="ml-2 font-semibold">{{ event.engine }}</span></div><span v-if="event.score !== null" class="text-sm font-semibold">Score {{ event.score }}</span></div><div class="mt-3 grid gap-2 text-sm sm:grid-cols-2"><p><span class="text-slate-500">From:</span> {{ event.sender || 'Unknown' }}</p><p><span class="text-slate-500">To:</span> {{ event.recipient || 'Unknown' }}</p></div><p class="mt-2 text-xs text-slate-500">{{ event.timestamp || 'Time unavailable' }}<span v-if="event.queue_id"> · Queue {{ event.queue_id }}</span></p></article>
                <div v-if="mailHealth.diagnostics?.spam_engine?.detected && spamEvents.length === 0" class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500 dark:border-slate-700"><i class="bi bi-shield-check text-3xl text-emerald-500"></i><p class="mt-2 font-medium">No spam events in the current sample</p></div>
            </section>

            <p class="text-xs text-slate-500">{{ mailHealth.diagnostics?.scope_note }} Updated {{ mailHealth.generated_at }}.</p>
        </div>
        <Modal :show="result !== null" max-width="lg" @close="result = null">
            <div v-if="result" class="p-6">
                <div class="flex items-start gap-3">
                    <i class="bi text-2xl" :class="[resultTone.icon, resultTone.text]"></i>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ result.title }}</h2>
                        <p v-if="result.message" class="mt-1 break-words text-sm text-slate-600 dark:text-slate-300">{{ result.message }}</p>
                    </div>
                </div>
                <ul v-if="result.rows.length" class="mt-4 space-y-2">
                    <li v-for="row in result.rows" :key="row.ip" class="rounded-lg p-3 text-sm" :class="row.ok ? 'bg-emerald-50 dark:bg-emerald-950/40' : 'bg-red-50 dark:bg-red-950/40'">
                        <p class="font-mono font-semibold">
                            <i class="bi" :class="row.ok ? 'bi-check-circle text-emerald-600' : 'bi-x-circle text-red-600'"></i>
                            {{ row.ip }} → {{ row.ptr || 'no PTR' }}
                        </p>
                        <p v-if="!row.ok && row.message" class="mt-1 break-words text-xs text-slate-600 dark:text-slate-300">{{ row.message }}</p>
                        <p v-if="row.lookup_failed" class="mt-1 text-xs text-amber-600">DNS did not answer; nothing was changed for this address.</p>
                    </li>
                </ul>
                <div v-if="result.notes.length" class="mt-4 space-y-2 rounded-lg p-3 text-sm" :class="resultTone.box">
                    <p v-for="(note, index) in result.notes" :key="index" class="break-words text-slate-700 dark:text-slate-200">{{ note }}</p>
                </div>
                <div class="mt-6 flex justify-end">
                    <button type="button" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white" @click="result = null">Close</button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
