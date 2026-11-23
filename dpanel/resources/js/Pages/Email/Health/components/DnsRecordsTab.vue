<script setup>
// Every record a working mail server needs, with the value to set, where it
// is set, and what public DNS answers now for the server-level ones.
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    guide: { type: Object, default: () => ({ host: '', ipv4: '', ipv6: '', rows: [] }) },
});

const page = usePage();
const panelRoute = (name, params = {}) => {
    const token = page.props.panel?.token;
    return route(name, token ? { token, ...params } : params);
};

const serverKeys = ['host', 'ptr', 'host6', 'ptr6'];
const rows = computed(() => props.guide?.rows ?? []);
const serverRows = computed(() => rows.value.filter((row) => serverKeys.includes(row.key)));
const domainRows = computed(() => rows.value.filter((row) => !serverKeys.includes(row.key)));

const badge = {
    pass: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    warn: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    fail: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    unknown: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};
const rowClass = (row) => (row.status === 'fail' ? 'bg-red-50/60 dark:bg-red-950/20' : row.status === 'warn' ? 'bg-amber-50/60 dark:bg-amber-950/20' : '');

const copied = ref('');
const copyValue = async (value, key) => {
    if (!value) return;
    await navigator.clipboard.writeText(value);
    copied.value = key;
    window.setTimeout(() => { copied.value = ''; }, 1500);
};
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold">{{ t('email.health.records.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ t('email.health.records.help') }}</p>
            </div>
            <Link :href="panelRoute('emails.guide')" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                <i class="bi bi-journal-code"></i> {{ t('email.health.records.openGuide') }}
            </Link>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div v-for="fact in [
                { label: t('email.health.records.mailHost'), value: guide.host, missing: t('email.health.records.hostUnknown') },
                { label: 'IPv4', value: guide.ipv4, missing: t('email.health.records.ipUnknown', { env: 'dPanel_MAIL_SERVER_IP' }) },
                { label: 'IPv6', value: guide.ipv6, missing: t('email.health.records.noIpv6') },
            ]" :key="fact.label" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-xs uppercase tracking-wide text-slate-500">{{ fact.label }}</p>
                <p v-if="fact.value" class="mt-1 break-all font-mono text-sm font-semibold">{{ fact.value }}</p>
                <p v-else class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ fact.missing }}</p>
            </div>
        </div>

        <div v-for="group in [
            { key: 'server', title: t('email.health.records.serverTitle'), help: t('email.health.records.serverHelp'), items: serverRows },
            { key: 'domain', title: t('email.health.records.domainTitle'), help: t('email.health.records.domainHelp'), items: domainRows },
        ]" :key="group.key" class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="border-b border-slate-200 p-4 dark:border-slate-800">
                <h3 class="font-semibold">{{ group.title }}</h3>
                <p class="mt-1 text-sm text-slate-500">{{ group.help }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">{{ t('email.health.records.col.type') }}</th>
                            <th class="px-4 py-3">{{ t('email.health.records.col.name') }}</th>
                            <th class="px-4 py-3">{{ t('email.health.records.col.value') }}</th>
                            <th class="px-4 py-3">{{ t('email.health.records.col.where') }}</th>
                            <th class="px-4 py-3">{{ t('email.health.records.col.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in group.items" :key="row.key" :class="rowClass(row)" class="border-t border-slate-200 align-top dark:border-slate-800">
                            <td class="px-4 py-3">
                                <span class="font-semibold">{{ row.type }}</span>
                                <span v-if="row.priority" class="ml-1 text-xs text-slate-500">{{ t('email.health.records.priority', { value: row.priority }) }}</span>
                                <div class="mt-1 text-xs" :class="row.required ? 'text-slate-500' : 'text-slate-400'">{{ row.required ? t('email.health.records.required') : t('email.health.records.optional') }}</div>
                            </td>
                            <td class="break-all px-4 py-3 font-mono text-xs">{{ row.name || '—' }}</td>
                            <td class="min-w-64 px-4 py-3">
                                <div v-if="row.value" class="flex items-start gap-2">
                                    <code class="break-all text-xs">{{ row.value }}</code>
                                    <button type="button" class="shrink-0 rounded border px-2 py-1 text-xs dark:border-slate-700" @click="copyValue(row.value, row.key)">{{ copied === row.key ? t('email.dnsGuide.copied') : t('email.dnsGuide.copy') }}</button>
                                </div>
                                <span v-else class="text-xs text-slate-500">—</span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">
                                {{ row.where }}
                                <div class="mt-1 text-xs">{{ row.purpose }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="row.checked" :class="badge[row.status]" class="inline-block rounded px-2 py-0.5 text-xs font-semibold">{{ t(`email.dnsCheck.status.${row.status}`) }}</span>
                                <span v-else :class="badge.unknown" class="inline-block rounded px-2 py-0.5 text-xs font-semibold">{{ t('email.health.records.notChecked') }}</span>
                                <p v-if="row.checked && row.found" class="mt-1 break-all font-mono text-xs text-slate-500">{{ t('email.dnsCheck.found', { value: row.found }) }}</p>
                                <p v-if="row.hint" class="mt-1 max-w-sm text-xs text-slate-700 dark:text-slate-300">{{ row.hint }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</template>
