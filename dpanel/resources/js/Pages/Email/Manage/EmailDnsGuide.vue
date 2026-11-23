<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';
import DnsCheckCell from './components/DnsCheckCell.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import MailHostnameCard from './components/MailHostnameCard.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    domains: { type: Array, default: () => [] }, selectedDomain: { type: String, default: '' },
    mailHost: { type: String, default: '' }, mailHostResolvesTo: { type: String, default: '' }, serverIp: { type: String, default: '' },
    dkimReady: { type: Boolean, default: false }, dkimConfiguredDomain: { type: String, default: '' },
    records: { type: Array, default: () => [] }, localZone: { type: String, default: '' },
    bimi: { type: Object, default: () => ({ hasLogo: false, logoUrl: null, https: false }) },
});
const page = usePage();
const panelToken = page.props.panel?.token;
const copied = ref('');
const dkimForm = useForm({ domain: '' });
const panelRoute = (name, params = {}) => panelToken ? route(name, { token: panelToken, ...params }) : route(name, params);
const domainOptions = computed(() => props.domains.map((domain) => ({ value: domain, label: domain })));
const selectDomain = (domain) => {
    if (!domain || domain === props.selectedDomain) return;
    router.get(panelRoute('emails.guide'), { domain }, { preserveState: false, replace: true });
};
const copyValue = async (value, key) => {
    if (!value) return;
    await navigator.clipboard.writeText(value);
    copied.value = key;
    window.setTimeout(() => { copied.value = ''; }, 1500);
};
const canManageMailServer = computed(() => (page.props.auth?.roles || []).includes('admin') || (page.props.auth?.permissions || []).includes('manage_mail_server'));
// The MX value and warnings come from the server; reload them after the hostname changes.
const reloadGuide = () => router.reload({ only: ['records', 'mailHost', 'mailHostResolvesTo', 'bimi'] });
const verifying = ref(false);
const verification = ref(null);
const checksByKey = computed(() => Object.fromEntries((verification.value?.checks || []).map((check) => [check.key, check])));
// The A/AAAA rows for a mail host inside this domain are checked as 'host'/'host6'.
const recordCheckKey = (record) => ({ A: 'host', AAAA: 'host6' }[record.type] || `${record.type} ${record.name}`);
const checkFor = (record) => checksByKey.value[recordCheckKey(record)] || null;
// Server-level checks without a record row of their own: reverse DNS for both
// address families, and a mail host that lives in another zone.
const extraMeta = {
    ptr: { type: 'PTR', where: 'ptrWhere', purpose: 'ptrPurpose' },
    ptr6: { type: 'PTR', where: 'ptrWhere', purpose: 'ptr6Purpose' },
    host: { type: 'A', where: 'hostWhere', purpose: 'hostPurpose' },
    host6: { type: 'AAAA', where: 'hostWhere', purpose: 'host6Purpose' },
};
const extraChecks = computed(() => (verification.value?.checks || []).filter((check) => ['ptr', 'ptr6'].includes(check.key)
    || (check.key === 'host' && !props.records.some((r) => r.type === 'A'))
    || (check.key === 'host6' && !props.records.some((r) => r.type === 'AAAA'))));
const rowClass = (check) => (check?.status === 'fail' ? 'bg-red-50/60 dark:bg-red-950/20' : check?.status === 'warn' ? 'bg-amber-50/60 dark:bg-amber-950/20' : '');
const verifyDns = async () => {
    if (!props.selectedDomain || verifying.value) return;
    verifying.value = true;
    try {
        const { data } = await axios.get(panelRoute('emails.guide.verify', { domain: props.selectedDomain }));
        verification.value = { checks: data.checks || [], error: '' };
    } catch (e) {
        verification.value = { checks: [], error: e.response?.data?.message || t('email.dnsGuide.checkFailed') };
    } finally {
        verifying.value = false;
    }
};
const zoneForm = useForm({ domain: '' });
// Replaces the existing MX, SPF, DKIM, DMARC and mail host A records in the local zone.
const applyToZone = () => {
    if (!props.selectedDomain || zoneForm.processing) return;
    if (!window.confirm(t('email.dnsGuide.applyConfirm', { zone: props.localZone }))) return;
    zoneForm.domain = props.selectedDomain;
    zoneForm.post(panelRoute('emails.guide.apply-zone'), { preserveScroll: true });
};
// BIMI: the panel hosts the logo, the guide adds the default._bimi record.
const bimiForm = useForm({ domain: '', logo: null });
const uploadBimi = (event) => {
    const file = event.target.files?.[0];
    if (!file || !props.selectedDomain) return;
    bimiForm.domain = props.selectedDomain;
    bimiForm.logo = file;
    bimiForm.post(panelRoute('emails.guide.bimi'), { preserveScroll: true, forceFormData: true, onFinish: () => { event.target.value = ''; } });
};
const removeBimi = () => {
    if (!window.confirm(t('email.dnsGuide.removeBimiConfirm', { domain: props.selectedDomain }))) return;
    router.delete(panelRoute('emails.guide.bimi.destroy'), { data: { domain: props.selectedDomain }, preserveScroll: true });
};
const generateDkim = (domain) => {
    if (!domain || dkimForm.processing) return;
    dkimForm.domain = domain;
    dkimForm.post(panelRoute('emails.guide.dkim'), { preserveScroll: true });
};
</script>

<template>
    <Head :title="t('email.dnsGuide.headTitle')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h1 class="text-lg font-semibold">{{ t('email.dnsGuide.title') }}</h1><p class="text-sm text-slate-500 dark:text-slate-400">{{ t('email.dnsGuide.subtitle') }}</p></div>
                <Link :href="panelRoute('emails.list')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"><i class="bi bi-arrow-left mr-1"></i> {{ t('email.dnsGuide.emailList') }}</Link>
            </div>
        </template>

        <div class="space-y-5">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>
            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <label class="mb-2 block text-sm font-medium">{{ t('email.dnsGuide.mailDomain') }}</label>
                <SearchableSelect
                    v-if="domains.length"
                    :model-value="selectedDomain"
                    :options="domainOptions"
                    :placeholder="t('email.dnsGuide.pickDomain')"
                    :search-placeholder="t('email.dnsGuide.searchDomains')"
                    class="max-w-lg"
                    @update:model-value="selectDomain"
                />
                <p v-else class="text-sm text-amber-700 dark:text-amber-300">{{ t('email.dnsGuide.noDomains') }}</p>
            </section>

            <div v-if="selectedDomain && mailHostResolvesTo" class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200"><i18n-t keypath="email.dnsGuide.hostWrong" scope="global"><template #host><code class="font-semibold">{{ mailHost }}</code></template><template #resolved><code>{{ mailHostResolvesTo }}</code></template><template #server><code>{{ serverIp }}</code></template><template #env><code class="font-semibold">dPanel_MAIL_HOSTNAME</code></template><template #file><code>.env</code></template></i18n-t></div>
            <div v-if="selectedDomain && !serverIp" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"><i18n-t keypath="email.dnsGuide.noServerIp" scope="global"><template #env><code class="font-semibold">dPanel_MAIL_SERVER_IP</code></template><template #file><code>.env</code></template></i18n-t></div>
            <div v-if="selectedDomain && !dkimReady" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"><span>{{ dkimConfiguredDomain ? t('email.dnsGuide.dkimMissingFor', { domain: selectedDomain, configured: dkimConfiguredDomain }) : t('email.dnsGuide.dkimMissing') }}</span><button :disabled="dkimForm.processing" class="rounded-md bg-amber-700 px-3 py-2 font-medium text-white hover:bg-amber-800 disabled:opacity-50" @click="generateDkim(selectedDomain)"><i class="bi bi-key mr-1"></i>{{ dkimForm.processing ? t('email.dnsGuide.generating') : t('email.dnsGuide.generateDkim') }}</button></div>

            <section v-if="records.length" class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-5 dark:border-slate-800"><div><h2 class="font-semibold">{{ t('email.dnsGuide.recordsTitle') }}</h2><p class="mt-1 text-sm text-slate-500">{{ t('email.dnsGuide.recordsHelp') }}</p></div><div class="flex flex-wrap gap-2"><button v-if="localZone" type="button" :disabled="zoneForm.processing" :title="t('email.dnsGuide.hostedHere', { zone: localZone })" class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60" @click="applyToZone"><i class="bi bi-lightning-charge mr-1"></i> {{ zoneForm.processing ? t('email.dnsGuide.adding') : t('email.dnsGuide.addToDns') }}</button><button type="button" :disabled="verifying" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" @click="verifyDns"><i class="bi bi-patch-check mr-1"></i> {{ verifying ? t('email.dnsGuide.checking') : t('email.dnsGuide.verify') }}</button><a :href="panelRoute('emails.guide.export', { domain: selectedDomain })" class="rounded-md bg-orange-600 px-3 py-2 text-sm font-medium text-white hover:bg-orange-700"><i class="bi bi-download mr-1"></i> {{ t('email.dnsGuide.download') }}</a></div></div>
                <div v-if="verification?.error" class="mx-5 mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ verification.error }}</div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800"><tr><th class="px-4 py-3">{{ t('email.dnsGuide.col.type') }}</th><th class="px-4 py-3">{{ t('email.dnsGuide.col.name') }}</th><th class="px-4 py-3">{{ t('email.dnsGuide.col.priority') }}</th><th class="px-4 py-3">{{ t('email.dnsGuide.col.content') }}</th><th class="px-4 py-3">{{ t('email.dnsGuide.col.purpose') }}</th><th class="px-4 py-3">{{ t('email.dnsGuide.col.check') }}</th></tr></thead>
                    <tbody><tr v-for="(record, index) in records" :key="`${record.type}-${record.name}`" :class="rowClass(checkFor(record))" class="border-t border-slate-200 align-top dark:border-slate-800">
                        <td class="px-4 py-3 font-semibold">{{ record.type }}</td><td class="px-4 py-3 font-mono text-xs">{{ record.name }}</td><td class="px-4 py-3">{{ record.priority ?? '—' }}</td>
                        <td class="min-w-72 px-4 py-3"><div v-if="record.value" class="flex items-start gap-2"><code class="break-all text-xs">{{ record.value }}</code><button class="shrink-0 rounded border px-2 py-1 text-xs dark:border-slate-700" @click="copyValue(record.value, index)">{{ copied === index ? t('email.dnsGuide.copied') : t('email.dnsGuide.copy') }}</button></div><span v-else class="font-medium text-amber-600">{{ t('email.dnsGuide.notConfigured') }}</span></td>
                        <td class="px-4 py-3 text-slate-500">{{ record.purpose }}<div class="mt-1 text-xs font-medium text-orange-600">{{ t('email.dnsGuide.dnsOnly') }}</div></td>
                        <td class="px-4 py-3"><DnsCheckCell :check="checkFor(record)" :checking="verifying" /></td>
                    </tr>
                    <tr v-for="check in extraChecks" :key="check.key" :class="rowClass(check)" class="border-t border-slate-200 align-top dark:border-slate-800">
                        <td class="px-4 py-3 font-semibold">{{ extraMeta[check.key]?.type }}</td>
                        <td colspan="3" class="px-4 py-3 text-sm">{{ check.label }}<div class="mt-1 text-xs text-slate-500">{{ t(`email.dnsGuide.${extraMeta[check.key]?.where}`) }}</div></td>
                        <td class="px-4 py-3 text-slate-500">{{ t(`email.dnsGuide.${extraMeta[check.key]?.purpose}`) }}</td>
                        <td class="px-4 py-3"><DnsCheckCell :check="check" :checking="verifying" /></td>
                    </tr></tbody>
                </table></div>
            </section>

            <section v-if="selectedDomain" class="rounded-xl border border-slate-200 bg-white p-5 text-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-start gap-4">
                    <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800">
                        <img v-if="bimi.logoUrl" :src="bimi.logoUrl" :alt="t('email.dnsGuide.logoAlt', { domain: selectedDomain })" class="h-full w-full object-contain" />
                        <i v-else class="bi bi-patch-check text-2xl text-slate-400"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold">{{ t('email.dnsGuide.bimiTitle') }}</h2>
                        <p class="mt-1 text-slate-600 dark:text-slate-300">
                            <i18n-t keypath="email.dnsGuide.bimiHelp" scope="global"><template #domain><strong>{{ selectedDomain }}</strong></template><template #record><code>default._bimi</code></template></i18n-t>
                        </p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-slate-500 dark:text-slate-400">
                            <li><i18n-t keypath="email.dnsGuide.bimiDmarc" scope="global"><template #policy><code>p=quarantine</code></template></i18n-t></li>
                            <li><i18n-t keypath="email.dnsGuide.bimiVmc" scope="global"><template #tag><code>a=</code></template></i18n-t></li>
                            <li>{{ t('email.dnsGuide.bimiConvert') }}</li>
                        </ul>
                        <p v-if="!bimi.https" class="mt-2 text-xs text-amber-700 dark:text-amber-300">{{ t('email.dnsGuide.bimiNoHttps') }}</p>
                        <p v-if="bimiForm.errors.logo" class="mt-2 text-xs text-red-600">{{ bimiForm.errors.logo }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-blue-600 px-3 py-2 text-white hover:bg-blue-700" :class="bimiForm.processing ? 'pointer-events-none opacity-60' : ''">
                                <i class="bi bi-upload"></i> {{ bimi.hasLogo ? t('email.dnsGuide.replaceLogo') : t('email.dnsGuide.uploadLogo') }}
                                <input type="file" accept="image/*,.svg,.png,.jpg,.jpeg,.webp,.gif,.bmp" class="sr-only" @change="uploadBimi" />
                            </label>
                            <button v-if="bimi.hasLogo" type="button" class="rounded-md border border-red-300 px-3 py-2 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400" @click="removeBimi">{{ t('email.dnsGuide.remove') }}</button>
                        </div>
                    </div>
                </div>
            </section>

            <MailHostnameCard v-if="canManageMailServer" :panel-route="panelRoute" @changed="reloadGuide" />

            <div v-if="selectedDomain" class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 class="font-semibold">Cloudflare</h2><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300"><li>{{ t('email.dnsGuide.cf.step1') }}</li><li><i18n-t keypath="email.dnsGuide.cf.step2" scope="global"><template #mail><strong>mail</strong></template><template #dnsOnly><strong>{{ t('email.dnsGuide.dnsOnly') }}</strong></template></i18n-t></li><li><i18n-t keypath="email.dnsGuide.cf.step3" scope="global"><template #host><code>{{ mailHost }}</code></template></i18n-t></li><li>{{ t('email.dnsGuide.cf.step4') }}</li></ol></section>
                <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 class="font-semibold">dPanel DNS</h2><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300"><li v-if="localZone"><i18n-t keypath="email.dnsGuide.dp.step0" scope="global"><template #button><strong>{{ t('email.dnsGuide.addToDns') }}</strong></template></i18n-t></li><li><i18n-t keypath="email.dnsGuide.dp.step1" scope="global"><template #zone><strong>{{ localZone || selectedDomain }}</strong></template></i18n-t></li><li>{{ t('email.dnsGuide.dp.step2') }}</li><li><i18n-t keypath="email.dnsGuide.dp.step3" scope="global"><template #host><code>{{ mailHost }}</code></template></i18n-t></li><li>{{ t('email.dnsGuide.dp.step4') }}</li></ol><Link :href="panelRoute('dns.zones')" class="mt-4 inline-flex rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">{{ t('email.dnsGuide.openZones') }}</Link></section>
            </div>

            <section v-if="selectedDomain" class="rounded-xl border border-slate-200 bg-white p-5 text-sm dark:border-slate-800 dark:bg-slate-900"><h2 class="font-semibold">{{ t('email.dnsGuide.after.title') }}</h2><p class="mt-2 text-slate-600 dark:text-slate-300"><i18n-t keypath="email.dnsGuide.after.body" scope="global"><template #none><code>p=none</code></template><template #quarantine><code>quarantine</code></template><template #reject><code>reject</code></template><template #host><code>{{ mailHost }}</code></template></i18n-t></p></section>
        </div>
    </AuthenticatedLayout>
</template>
