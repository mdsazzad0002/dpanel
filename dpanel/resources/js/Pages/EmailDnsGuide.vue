<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineProps({
    domains: { type: Array, default: () => [] }, selectedDomain: { type: String, default: '' },
    mailHost: { type: String, default: '' }, serverIp: { type: String, default: '' },
    dkimReady: { type: Boolean, default: false }, dkimConfiguredDomain: { type: String, default: '' },
    records: { type: Array, default: () => [] },
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

            <div v-if="selectedDomain && !serverIp" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">Server IP is not configured. Set <code class="font-semibold">SERVERPANEL_MAIL_SERVER_IP</code> in <code>.env</code>; do not publish the A record until its real value appears here.</div>
            <div v-if="selectedDomain && !dkimReady" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"><span>DKIM public key is not configured<span v-if="dkimConfiguredDomain"> for {{ selectedDomain }} (configured: {{ dkimConfiguredDomain }})</span>.</span><button :disabled="dkimForm.processing" class="rounded-md bg-amber-700 px-3 py-2 font-medium text-white hover:bg-amber-800 disabled:opacity-50" @click="generateDkim(selectedDomain)"><i class="bi bi-key mr-1"></i>{{ dkimForm.processing ? 'Generating…' : 'Generate DKIM key' }}</button></div>

            <section v-if="records.length" class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-5 dark:border-slate-800"><div><h2 class="font-semibold">Records to add</h2><p class="mt-1 text-sm text-slate-500">Use the same values in Cloudflare DNS or dPanel DNS Zones. TTL: Auto/3600.</p></div><a :href="panelRoute('emails.guide.export', { domain: selectedDomain })" class="rounded-md bg-orange-600 px-3 py-2 text-sm font-medium text-white hover:bg-orange-700"><i class="bi bi-download mr-1"></i> Download Cloudflare TXT</a></div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800"><tr><th class="px-4 py-3">Type</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">Priority</th><th class="px-4 py-3">Content</th><th class="px-4 py-3">Purpose</th></tr></thead>
                    <tbody><tr v-for="(record, index) in records" :key="`${record.type}-${record.name}`" class="border-t border-slate-200 align-top dark:border-slate-800">
                        <td class="px-4 py-3 font-semibold">{{ record.type }}</td><td class="px-4 py-3 font-mono text-xs">{{ record.name }}</td><td class="px-4 py-3">{{ record.priority ?? '—' }}</td>
                        <td class="min-w-72 px-4 py-3"><div v-if="record.value" class="flex items-start gap-2"><code class="break-all text-xs">{{ record.value }}</code><button class="shrink-0 rounded border px-2 py-1 text-xs dark:border-slate-700" @click="copyValue(record.value, index)">{{ copied === index ? 'Copied' : 'Copy' }}</button></div><span v-else class="font-medium text-amber-600">Not configured—do not add yet</span></td>
                        <td class="px-4 py-3 text-slate-500">{{ record.purpose }}<div class="mt-1 text-xs font-medium text-orange-600">DNS only</div></td>
                    </tr></tbody>
                </table></div>
            </section>

            <div v-if="selectedDomain" class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 class="font-semibold">Cloudflare</h2><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300"><li>Open domain → DNS → Records and add every configured row above.</li><li>The <strong>mail</strong> A record must be <strong>DNS only</strong> (grey cloud), never Proxied.</li><li>MX target is <code>{{ mailHost }}</code>, priority 10. Never put an IP in MX.</li><li>Paste TXT values without adding quotes; Cloudflare handles them.</li></ol></section>
                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 class="font-semibold">dPanel DNS</h2><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300"><li>Open DNS Zones and select <strong>{{ selectedDomain }}</strong>.</li><li>Add the same rows above with TTL 3600.</li><li>For MX, enter priority 10 separately and content <code>{{ mailHost }}</code>.</li><li>Do not create duplicate SPF records; merge allowed senders into one SPF TXT record.</li></ol><Link :href="panelRoute('dns.zones')" class="mt-4 inline-flex rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">Open DNS Zones</Link></section>
            </div>

            <section v-if="selectedDomain" class="rounded-xl border border-slate-200 bg-white p-5 text-sm dark:border-slate-800 dark:bg-slate-900"><h2 class="font-semibold">After publishing</h2><p class="mt-2 text-slate-600 dark:text-slate-300">Verify MX, SPF, DKIM and DMARC after propagation. Keep DMARC at <code>p=none</code> while monitoring; move to <code>quarantine</code> or <code>reject</code> only after SPF and DKIM pass consistently. Ask the hosting provider to set reverse DNS/PTR for the server IP to <code>{{ mailHost }}</code>; PTR cannot be created in Cloudflare or dPanel DNS.</p></section>
        </div>
    </AuthenticatedLayout>
</template>
