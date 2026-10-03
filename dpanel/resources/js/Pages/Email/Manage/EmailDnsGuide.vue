<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';
import DnsCheckCell from './components/DnsCheckCell.vue';
import MailHostnameCard from './components/MailHostnameCard.vue';

const props = defineProps({
    domains: { type: Array, default: () => [] }, selectedDomain: { type: String, default: '' },
    mailHost: { type: String, default: '' }, mailHostResolvesTo: { type: String, default: '' }, serverIp: { type: String, default: '' },
    dkimReady: { type: Boolean, default: false }, dkimConfiguredDomain: { type: String, default: '' },
    records: { type: Array, default: () => [] }, localZone: { type: String, default: '' },
});
const page = usePage();
const panelToken = page.props.panel?.token;
const copied = ref('');
const dkimForm = useForm({ domain: '' });
const panelRoute = (name, params = {}) => panelToken ? route(name, { token: panelToken, ...params }) : route(name, params);
const selectDomain = (event) => router.get(panelRoute('emails.guide'), { domain: event.target.value }, { preserveState: false, replace: true });
const copyValue = async (value, key) => {
    if (!value) return;
    await navigator.clipboard.writeText(value);
    copied.value = key;
    window.setTimeout(() => { copied.value = ''; }, 1500);
};
const canManageMailServer = computed(() => (page.props.auth?.roles || []).includes('admin') || (page.props.auth?.permissions || []).includes('manage_mail_server'));
// The MX value and warnings come from the server; reload them after the hostname changes.
const reloadGuide = () => router.reload({ only: ['records', 'mailHost', 'mailHostResolvesTo'] });
const verifying = ref(false);
const verification = ref(null);
const checksByKey = computed(() => Object.fromEntries((verification.value?.checks || []).map((check) => [check.key, check])));
// The A row for a mail host inside this domain is checked as 'host'.
const checkFor = (record) => checksByKey.value[record.type === 'A' ? 'host' : `${record.type} ${record.name}`] || null;
// Server-level checks without a record row of their own (PTR, a mail host in another zone).
const extraChecks = computed(() => (verification.value?.checks || []).filter((check) => check.key === 'ptr' || (check.key === 'host' && !props.records.some((r) => r.type === 'A'))));
const rowClass = (check) => (check?.status === 'fail' ? 'bg-red-50/60 dark:bg-red-950/20' : check?.status === 'warn' ? 'bg-amber-50/60 dark:bg-amber-950/20' : '');
const verifyDns = async () => {
    if (!props.selectedDomain || verifying.value) return;
    verifying.value = true;
    try {
        const { data } = await axios.get(panelRoute('emails.guide.verify', { domain: props.selectedDomain }));
        verification.value = { checks: data.checks || [], error: '' };
    } catch (e) {
        verification.value = { checks: [], error: e.response?.data?.message || 'DNS check failed.' };
    } finally {
        verifying.value = false;
    }
};
const zoneForm = useForm({ domain: '' });
// Replaces the existing MX, SPF, DKIM, DMARC and mail host A records in the local zone.
const applyToZone = () => {
    if (!props.selectedDomain || zoneForm.processing) return;
    if (!window.confirm(`Add these records to the ${props.localZone} zone on this server? Existing MX, SPF, DKIM, DMARC and mail host records are replaced.`)) return;
    zoneForm.domain = props.selectedDomain;
    zoneForm.post(panelRoute('emails.guide.apply-zone'), { preserveScroll: true });
};
const generateDkim = (domain) => {
    if (!domain || dkimForm.processing) return;
    dkimForm.domain = domain;
    dkimForm.post(panelRoute('emails.guide.dkim'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Mail DNS Guide" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h1 class="text-lg font-semibold">Mail DNS Setup Guide</h1><p class="text-sm text-slate-500 dark:text-slate-400">Real records for Cloudflare or dPanel DNS.</p></div>
                <Link :href="panelRoute('emails.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"><i class="bi bi-arrow-left mr-1"></i> Email list</Link>
            </div>
        </template>

        <div class="space-y-5">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>
            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <label class="mb-2 block text-sm font-medium">Mail domain</label>
                <select v-if="domains.length" :value="selectedDomain" class="w-full max-w-lg rounded-md border-slate-300 dark:border-slate-700 dark:bg-slate-950" @change="selectDomain"><option v-for="domain in domains" :key="domain" :value="domain">{{ domain }}</option></select>
                <p v-else class="text-sm text-amber-700 dark:text-amber-300">No real website or mailbox domain exists yet. Create the domain first; this guide does not display demo records.</p>
            </section>

            <div v-if="selectedDomain && mailHostResolvesTo" class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200">The mail host <code class="font-semibold">{{ mailHost }}</code> points to <code>{{ mailHostResolvesTo }}</code>, not this server (<code>{{ serverIp }}</code>). Mail sent to it goes to the wrong machine. Point its A record here (DNS only, not proxied), or set <code class="font-semibold">SERVERPANEL_MAIL_HOSTNAME</code> in <code>.env</code> to a name that does.</div>
            <div v-if="selectedDomain && !serverIp" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">Server IP is not configured. Set <code class="font-semibold">SERVERPANEL_MAIL_SERVER_IP</code> in <code>.env</code>; do not publish the A record until its real value appears here.</div>
            <div v-if="selectedDomain && !dkimReady" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"><span>DKIM public key is not configured<span v-if="dkimConfiguredDomain"> for {{ selectedDomain }} (configured: {{ dkimConfiguredDomain }})</span>.</span><button :disabled="dkimForm.processing" class="rounded-md bg-amber-700 px-3 py-2 font-medium text-white hover:bg-amber-800 disabled:opacity-50" @click="generateDkim(selectedDomain)"><i class="bi bi-key mr-1"></i>{{ dkimForm.processing ? 'Generating…' : 'Generate DKIM key' }}</button></div>

            <section v-if="records.length" class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-5 dark:border-slate-800"><div><h2 class="font-semibold">Records to add</h2><p class="mt-1 text-sm text-slate-500">Use the same values in Cloudflare DNS or dPanel DNS Zones. TTL: Auto/3600.</p></div><div class="flex flex-wrap gap-2"><button v-if="localZone" type="button" :disabled="zoneForm.processing" :title="`This domain's DNS is hosted here (${localZone} zone)`" class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60" @click="applyToZone"><i class="bi bi-lightning-charge mr-1"></i> {{ zoneForm.processing ? 'Adding…' : 'Add to dPanel DNS' }}</button><button type="button" :disabled="verifying" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" @click="verifyDns"><i class="bi bi-patch-check mr-1"></i> {{ verifying ? 'Checking…' : 'Verify DNS' }}</button><a :href="panelRoute('emails.guide.export', { domain: selectedDomain })" class="rounded-md bg-orange-600 px-3 py-2 text-sm font-medium text-white hover:bg-orange-700"><i class="bi bi-download mr-1"></i> Download Cloudflare TXT</a></div></div>
                <div v-if="verification?.error" class="mx-5 mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ verification.error }}</div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800"><tr><th class="px-4 py-3">Type</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">Priority</th><th class="px-4 py-3">Content</th><th class="px-4 py-3">Purpose</th><th class="px-4 py-3">Check</th></tr></thead>
                    <tbody><tr v-for="(record, index) in records" :key="`${record.type}-${record.name}`" :class="rowClass(checkFor(record))" class="border-t border-slate-200 align-top dark:border-slate-800">
                        <td class="px-4 py-3 font-semibold">{{ record.type }}</td><td class="px-4 py-3 font-mono text-xs">{{ record.name }}</td><td class="px-4 py-3">{{ record.priority ?? '—' }}</td>
                        <td class="min-w-72 px-4 py-3"><div v-if="record.value" class="flex items-start gap-2"><code class="break-all text-xs">{{ record.value }}</code><button class="shrink-0 rounded border px-2 py-1 text-xs dark:border-slate-700" @click="copyValue(record.value, index)">{{ copied === index ? 'Copied' : 'Copy' }}</button></div><span v-else class="font-medium text-amber-600">Not configured—do not add yet</span></td>
                        <td class="px-4 py-3 text-slate-500">{{ record.purpose }}<div class="mt-1 text-xs font-medium text-orange-600">DNS only</div></td>
                        <td class="px-4 py-3"><DnsCheckCell :check="checkFor(record)" :checking="verifying" /></td>
                    </tr>
                    <tr v-for="check in extraChecks" :key="check.key" :class="rowClass(check)" class="border-t border-slate-200 align-top dark:border-slate-800">
                        <td class="px-4 py-3 font-semibold">{{ check.key === 'ptr' ? 'PTR' : 'A' }}</td>
                        <td colspan="3" class="px-4 py-3 text-sm">{{ check.label }}<div class="mt-1 text-xs text-slate-500">{{ check.key === 'ptr' ? 'Set by your server provider, not in DNS' : 'Set in the zone of the mail host' }}</div></td>
                        <td class="px-4 py-3 text-slate-500">{{ check.key === 'ptr' ? 'Gmail rejects mail when this does not resolve back to the server IP' : 'Mail host must point to this server' }}</td>
                        <td class="px-4 py-3"><DnsCheckCell :check="check" :checking="verifying" /></td>
                    </tr></tbody>
                </table></div>
            </section>

            <MailHostnameCard v-if="canManageMailServer" :panel-route="panelRoute" @changed="reloadGuide" />

            <div v-if="selectedDomain" class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 class="font-semibold">Cloudflare</h2><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300"><li>Open domain → DNS → Records and add every configured row above.</li><li>The <strong>mail</strong> A record must be <strong>DNS only</strong> (grey cloud), never Proxied.</li><li>MX target is <code>{{ mailHost }}</code>, priority 10. Never put an IP in MX.</li><li>Paste TXT values without adding quotes; Cloudflare handles them.</li></ol></section>
                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 class="font-semibold">dPanel DNS</h2><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300"><li v-if="localZone">This domain's zone is hosted here: use <strong>Add to dPanel DNS</strong> above to add every row in one click (existing mail records are replaced).</li><li>Open DNS Zones and select <strong>{{ localZone || selectedDomain }}</strong>.</li><li>Add the same rows above with TTL 3600.</li><li>For MX, enter priority 10 separately and content <code>{{ mailHost }}</code>.</li><li>Do not create duplicate SPF records; merge allowed senders into one SPF TXT record.</li></ol><Link :href="panelRoute('dns.zones')" class="mt-4 inline-flex rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">Open DNS Zones</Link></section>
            </div>

            <section v-if="selectedDomain" class="rounded-xl border border-slate-200 bg-white p-5 text-sm dark:border-slate-800 dark:bg-slate-900"><h2 class="font-semibold">After publishing</h2><p class="mt-2 text-slate-600 dark:text-slate-300">Verify MX, SPF, DKIM and DMARC after propagation. Keep DMARC at <code>p=none</code> while monitoring; move to <code>quarantine</code> or <code>reject</code> only after SPF and DKIM pass consistently. Ask the hosting provider to set reverse DNS/PTR for the server IP to <code>{{ mailHost }}</code>; PTR cannot be created in Cloudflare or dPanel DNS.</p></section>
        </div>
    </AuthenticatedLayout>
</template>
