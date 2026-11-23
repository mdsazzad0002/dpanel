<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    keys: { type: Array, default: () => [] },
    apiBaseUrl: { type: String, default: '' },
    limitDefaults: { type: Object, default: () => ({}) },
});

const keyList = ref([...props.keys]);
const toggleForm = useForm({});
const deleteForm = useForm({});

const nameInput = ref('');
const creating = ref(false);
const createError = ref('');
const newKey = ref(null);
const copied = ref(false);
const regenerating = ref(null);
const copiedPrefixId = ref(null);

const create = async () => {
    if (!nameInput.value.trim() || creating.value) return;

    creating.value = true;
    createError.value = '';
    copied.value = false;

    try {
        const { data } = await axios.post(panelRoute('ai-gateway.api-keys.store'), { name: nameInput.value });
        keyList.value.unshift(data.key);
        newKey.value = data.plain_key;
        nameInput.value = '';
    } catch (e) {
        createError.value = e.response?.data?.errors?.name?.[0] || e.response?.data?.message || t('aiGateway.apiKeys.createFailed');
    } finally {
        creating.value = false;
    }
};

const toggle = (k) => toggleForm.patch(panelRoute('ai-gateway.api-keys.toggle', { apiKey: k.id }), {
    preserveScroll: true,
    onSuccess: () => { k.is_active = !k.is_active; },
});

const remove = (k) => {
    if (!confirm(t('aiGateway.apiKeys.revokeConfirm', { name: k.name }))) return;
    deleteForm.delete(panelRoute('ai-gateway.api-keys.destroy', { apiKey: k.id }), {
        preserveScroll: true,
        onSuccess: () => { keyList.value = keyList.value.filter((x) => x.id !== k.id); },
    });
};

const regenerate = async (k) => {
    if (!confirm(t('aiGateway.apiKeys.regenerateConfirm', { name: k.name }))) return;

    regenerating.value = k.id;
    try {
        const { data } = await axios.post(panelRoute('ai-gateway.api-keys.regenerate', { apiKey: k.id }));
        const idx = keyList.value.findIndex((x) => x.id === k.id);
        if (idx !== -1) keyList.value.splice(idx, 1, data.key);
        newKey.value = data.plain_key;
        copied.value = false;
    } catch (e) {
        alert(e.response?.data?.message || t('aiGateway.apiKeys.regenerateFailed'));
    } finally {
        regenerating.value = null;
    }
};

const copyKey = async () => {
    try {
        await navigator.clipboard.writeText(newKey.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch (e) {
        // Clipboard API unavailable — the key is still selectable/visible in the box.
    }
};

const copyPrefix = async (k) => {
    try {
        await navigator.clipboard.writeText(k.key_prefix);
        copiedPrefixId.value = k.id;
        setTimeout(() => { copiedPrefixId.value = null; }, 2000);
    } catch (e) {
        // Clipboard API unavailable.
    }
};

// Per-key rate limits + IP allowlist editor (one row open at a time).
const editingLimits = ref(null);
const limitsForm = ref({ rate_limit_per_minute: '', ip_rate_limit_per_minute: '', allowed_ips: '' });
const limitsSaving = ref(false);
const limitsError = ref('');

const openLimits = (k) => {
    if (editingLimits.value === k.id) { editingLimits.value = null; return; }
    editingLimits.value = k.id;
    limitsError.value = '';
    limitsForm.value = {
        rate_limit_per_minute: k.rate_limit_per_minute ?? '',
        ip_rate_limit_per_minute: k.ip_rate_limit_per_minute ?? '',
        allowed_ips: k.allowed_ips || '',
    };
};

const blankToNull = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

const saveLimits = async (k) => {
    limitsSaving.value = true;
    limitsError.value = '';
    try {
        const { data } = await axios.patch(panelRoute('ai-gateway.api-keys.limits', { apiKey: k.id }), {
            rate_limit_per_minute: blankToNull(limitsForm.value.rate_limit_per_minute),
            ip_rate_limit_per_minute: blankToNull(limitsForm.value.ip_rate_limit_per_minute),
            allowed_ips: limitsForm.value.allowed_ips,
        });
        const idx = keyList.value.findIndex((x) => x.id === k.id);
        if (idx !== -1) keyList.value.splice(idx, 1, data.key);
        editingLimits.value = null;
    } catch (e) {
        const errs = e.response?.data?.errors;
        limitsError.value = (errs && Object.values(errs)[0]?.[0]) || e.response?.data?.message || t('aiGateway.apiKeys.limits.saveFailed');
    } finally {
        limitsSaving.value = false;
    }
};

const fmtLimit = (value, fallback) => {
    const n = value ?? fallback;
    return n === 0 ? '∞' : `${n}/min`;
};

const ipCount = (k) => (k.allowed_ips ? k.allowed_ips.split('\n').filter(Boolean).length : 0);

const fmtDate = (iso) => {
    if (!iso) return t('aiGateway.apiKeys.never');
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
};
</script>

<template>
    <Head :title="t('aiGateway.apiKeys.headTitle')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">{{ t('aiGateway.apiKeys.title') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('aiGateway.apiKeys.subtitle') }}</p>
                </div>
                <Link :href="panelRoute('ai-gateway.docs')" class="text-sm text-blue-600 hover:underline">{{ t('aiGateway.apiKeys.viewDocs') }} →</Link>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="newKey" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm dark:border-emerald-800 dark:bg-emerald-950">
                <p class="font-medium text-emerald-800 dark:text-emerald-300">{{ t('aiGateway.apiKeys.copyNow') }}</p>
                <div class="mt-2 flex items-center gap-2">
                    <code class="flex-1 select-all break-all rounded-md border border-emerald-300 bg-white px-3 py-2 text-xs dark:border-emerald-700 dark:bg-slate-900">{{ newKey }}</code>
                    <button @click="copyKey" class="shrink-0 rounded-md border border-emerald-300 px-3 py-2 text-xs hover:bg-emerald-100 dark:border-emerald-700 dark:hover:bg-emerald-900">{{ copied ? t('aiGateway.apiKeys.copied') : t('aiGateway.apiKeys.copy') }}</button>
                </div>
            </div>

            <form @submit.prevent="create" class="flex items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <div class="flex-1">
                    <label class="mb-1 block text-xs font-medium">{{ t('aiGateway.apiKeys.keyName') }}</label>
                    <input v-model="nameInput" type="text" :placeholder="t('aiGateway.apiKeys.keyNamePlaceholder')"
                        class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    <div v-if="createError" class="mt-0.5 text-xs text-red-600">{{ createError }}</div>
                </div>
                <button type="submit" :disabled="creating"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">{{ t('aiGateway.apiKeys.create') }}</button>
            </form>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.apiKeys.col.name') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.apiKeys.col.key') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.apiKeys.col.status') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.apiKeys.col.limits') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.apiKeys.col.lastUsed') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.apiKeys.col.created') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('aiGateway.apiKeys.col.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-if="!keyList.length">
                            <td colspan="7" class="px-4 py-6 text-center text-sm text-slate-400">{{ t('aiGateway.apiKeys.empty') }}</td>
                        </tr>
                        <template v-for="k in keyList" :key="k.id">
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                            <td class="px-4 py-3 font-medium">{{ k.name }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1.5">
                                    <code class="text-xs text-slate-400" :title="t('aiGateway.apiKeys.prefixHint')">{{ k.key_prefix }}</code>
                                    <button @click="copyPrefix(k)" :title="t('aiGateway.apiKeys.copyPrefix')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                                        <i class="bi" :class="copiedPrefixId === k.id ? 'bi-check2' : 'bi-clipboard'"></i>
                                    </button>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded px-2 py-0.5 text-xs" :class="k.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ k.is_active ? t('aiGateway.apiKeys.active') : t('aiGateway.apiKeys.disabled') }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">
                                <div :title="t('aiGateway.apiKeys.limits.perKey')">{{ fmtLimit(k.rate_limit_per_minute, limitDefaults.key_per_minute) }} {{ t('aiGateway.apiKeys.limits.total') }}</div>
                                <div :title="t('aiGateway.apiKeys.limits.perKeyIp')">{{ fmtLimit(k.ip_rate_limit_per_minute, limitDefaults.key_ip_per_minute) }} {{ t('aiGateway.apiKeys.limits.perIp') }}</div>
                                <div v-if="ipCount(k)" class="text-amber-600">{{ t('aiGateway.apiKeys.limits.ipsOnly', { n: ipCount(k) }) }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ fmtDate(k.last_used_at) }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ fmtDate(k.created_at) }}</td>
                            <td class="space-x-1 px-4 py-3 text-right">
                                <button @click="regenerate(k)" :disabled="regenerating === k.id" :title="t('aiGateway.apiKeys.regenerateHint')"
                                    class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600">{{ regenerating === k.id ? t('aiGateway.apiKeys.regenerating') : t('aiGateway.apiKeys.regenerate') }}</button>
                                <button @click="openLimits(k)" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600">{{ t('aiGateway.apiKeys.limits.edit') }}</button>
                                <button @click="toggle(k)" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600">{{ k.is_active ? t('aiGateway.apiKeys.disable') : t('aiGateway.apiKeys.enable') }}</button>
                                <button @click="remove(k)" class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700">{{ t('aiGateway.apiKeys.revoke') }}</button>
                            </td>
                        </tr>
                        <tr v-if="editingLimits === k.id" class="bg-slate-50 dark:bg-slate-900/40">
                            <td colspan="7" class="px-4 py-4">
                                <form @submit.prevent="saveLimits(k)" class="grid gap-3 md:grid-cols-3">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium">{{ t('aiGateway.apiKeys.limits.perKey') }}</label>
                                        <input v-model="limitsForm.rate_limit_per_minute" type="number" min="0" :placeholder="t('aiGateway.apiKeys.limits.defaultPlaceholder', { n: limitDefaults.key_per_minute })"
                                            class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium">{{ t('aiGateway.apiKeys.limits.perKeyIp') }}</label>
                                        <input v-model="limitsForm.ip_rate_limit_per_minute" type="number" min="0" :placeholder="t('aiGateway.apiKeys.limits.defaultPlaceholder', { n: limitDefaults.key_ip_per_minute })"
                                            class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900" />
                                    </div>
                                    <div class="md:row-span-2">
                                        <label class="mb-1 block text-xs font-medium">{{ t('aiGateway.apiKeys.limits.allowedIps') }}</label>
                                        <textarea v-model="limitsForm.allowed_ips" rows="3" placeholder="203.0.113.10&#10;198.51.100.0/24"
                                            class="w-full rounded-md border border-slate-300 px-2 py-1.5 font-mono text-xs dark:border-slate-600 dark:bg-slate-900"></textarea>
                                        <p class="mt-0.5 text-xs text-slate-400">{{ t('aiGateway.apiKeys.limits.allowedIpsHint') }}</p>
                                    </div>
                                    <p class="text-xs text-slate-400 md:col-span-2">{{ t('aiGateway.apiKeys.limits.hint', { ip: limitDefaults.ip_per_minute, bad: limitDefaults.bad_key_attempts }) }}</p>
                                    <div class="flex items-center gap-2 md:col-span-3">
                                        <button type="submit" :disabled="limitsSaving" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-50">{{ t('aiGateway.apiKeys.limits.save') }}</button>
                                        <button type="button" @click="editingLimits = null" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs dark:border-slate-600">{{ t('aiGateway.apiKeys.limits.cancel') }}</button>
                                        <span v-if="limitsError" class="text-xs text-red-600">{{ limitsError }}</span>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-slate-400">{{ t('aiGateway.apiKeys.baseUrl') }} <code class="rounded bg-slate-100 px-1.5 py-0.5 dark:bg-slate-900">{{ apiBaseUrl }}</code></p>
        </div>
    </AuthenticatedLayout>
</template>
