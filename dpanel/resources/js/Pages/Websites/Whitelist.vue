<script setup>
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const { t } = useI18n();

const props = defineProps({ website: { type: Object, required: true }, rules: { type: Array, default: () => [] } });
const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params);
const rules = ref([...props.rules]);
const ipAddress = ref('');
const ruleType = ref('allow');
const loading = ref(false);
const message = ref('');

const headers = () => ({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' });
const addRule = async () => {
    if (!ipAddress.value.trim() || loading.value) return;
    loading.value = true;
    message.value = '';
    try {
        const response = await fetch(panelRoute('websites.ip-rules.store', { id: props.website.id }), { method: 'POST', headers: headers(), body: JSON.stringify({ rule_type: ruleType.value, ip_address: ipAddress.value.trim() }) });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || t('websites.ipRules.addFailed'));
        if (data.rule && !rules.value.some((rule) => rule.id === data.rule.id)) rules.value.unshift(data.rule);
        ipAddress.value = '';
        message.value = data.message;
    } catch (error) { message.value = error.message || t('websites.ipRules.addFailed'); } finally { loading.value = false; }
};
const removeRule = async (rule) => {
    if (loading.value) return;
    loading.value = true;
    try {
        const response = await fetch(panelRoute('websites.ip-rules.destroy', { id: props.website.id, rule: rule.id }), { method: 'DELETE', headers: headers() });
        if (!response.ok) throw new Error(t('websites.ipRules.removeFailed'));
        rules.value = rules.value.filter((item) => item.id !== rule.id);
    } finally { loading.value = false; }
};
</script>

<template>
    <Head :title="t('websites.ipRules.title')" />
    <AuthenticatedLayout>
        <template #header><div><h1 class="text-lg font-semibold">{{ t('websites.ipRules.title') }}</h1><p class="text-sm text-slate-500 dark:text-slate-400">{{ t('websites.ipRules.subtitle', { domain: website.domain }) }}</p></div></template>
        <section class="mx-auto max-w-4xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-start justify-between gap-4"><div><h2 class="font-semibold">{{ t('websites.ipRules.heading') }}</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('websites.ipRules.help') }}</p></div><Link :href="panelRoute('websites.manage', { id: website.id })" class="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700">{{ t('common.back') }}</Link></div>
            <form class="mt-6 grid gap-2 sm:grid-cols-[150px_minmax(0,1fr)_auto]" @submit.prevent="addRule"><select v-model="ruleType" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"><option value="allow">{{ t('websites.ipRules.allowIp') }}</option><option value="ban">{{ t('websites.ipRules.banIp') }}</option></select><input v-model="ipAddress" type="text" required :placeholder="t('websites.ipRules.placeholder')" class="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" /><button :disabled="loading" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900">{{ loading ? t('websites.ipRules.saving') : t('websites.ipRules.add') }}</button></form>
            <p v-if="message" class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ message }}</p>
            <div class="mt-6 divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700"><div v-for="rule in rules" :key="rule.id" class="flex items-center justify-between gap-4 px-4 py-3"><div class="flex items-center gap-2"><span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" :class="rule.rule_type === 'allow' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400' : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400'">{{ rule.rule_type === 'allow' ? t('websites.ipRules.allow') : t('websites.ipRules.ban') }}</span><code class="text-sm text-slate-800 dark:text-slate-200">{{ rule.ip_address }}</code></div><button :disabled="loading" type="button" class="text-sm font-semibold text-red-600 disabled:opacity-50" @click="removeRule(rule)">{{ t('websites.ipRules.remove') }}</button></div><p v-if="!rules.length" class="px-4 py-8 text-center text-sm text-slate-500">{{ t('websites.ipRules.empty') }}</p></div>
        </section>
    </AuthenticatedLayout>
</template>
