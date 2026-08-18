<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    mailHealth: { type: Object, required: true },
});

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
        only: ['mailHealth'],
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
            <div v-if="!mailHealth.diagnostics?.log_source" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                <div class="flex gap-3">
                    <i class="bi bi-exclamation-triangle mt-0.5"></i>
                    <div><p class="font-semibold">Mail logs are not readable</p><p class="mt-1">Allow the PHP service user read-only access to the Postfix log, or configure <code>SERVERPANEL_MAIL_HEALTH_LOG_PATHS</code>. Queue data may still be available.</p></div>
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
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><p class="text-xs uppercase tracking-wide text-slate-500">Delivery rate</p><p class="mt-1 text-lg font-semibold">{{ mailHealth.delivery_rate === null ? 'No data' : `${mailHealth.delivery_rate}%` }}</p></div>
                    <div><p class="text-xs uppercase tracking-wide text-slate-500">Queued messages</p><p class="mt-1 text-lg font-semibold">{{ queue.available ? queue.count : 'Unavailable' }}</p></div>
                    <div><p class="text-xs uppercase tracking-wide text-slate-500">Spam engine</p><p class="mt-1 text-lg font-semibold">{{ mailHealth.diagnostics?.spam_engine?.name ?? 'Not detected' }}</p></div>
                    <div><p class="text-xs uppercase tracking-wide text-slate-500">Log sample</p><p class="mt-1 text-lg font-semibold">{{ mailHealth.diagnostics?.lines_analyzed ?? 0 }} lines</p><p class="truncate text-xs text-slate-500">{{ mailHealth.diagnostics?.log_source ?? 'No source' }}</p></div>
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
    </AuthenticatedLayout>
</template>
