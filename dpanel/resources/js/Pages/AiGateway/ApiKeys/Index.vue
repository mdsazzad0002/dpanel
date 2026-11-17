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
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.apiKeys.col.lastUsed') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.apiKeys.col.created') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('aiGateway.apiKeys.col.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-if="!keyList.length">
                            <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-400">{{ t('aiGateway.apiKeys.empty') }}</td>
                        </tr>
                        <tr v-for="k in keyList" :key="k.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
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
                            <td class="px-4 py-3 text-slate-500">{{ fmtDate(k.last_used_at) }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ fmtDate(k.created_at) }}</td>
                            <td class="space-x-1 px-4 py-3 text-right">
                                <button @click="regenerate(k)" :disabled="regenerating === k.id" :title="t('aiGateway.apiKeys.regenerateHint')"
                                    class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600">{{ regenerating === k.id ? t('aiGateway.apiKeys.regenerating') : t('aiGateway.apiKeys.regenerate') }}</button>
                                <button @click="toggle(k)" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600">{{ k.is_active ? t('aiGateway.apiKeys.disable') : t('aiGateway.apiKeys.enable') }}</button>
                                <button @click="remove(k)" class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700">{{ t('aiGateway.apiKeys.revoke') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-slate-400">{{ t('aiGateway.apiKeys.baseUrl') }} <code class="rounded bg-slate-100 px-1.5 py-0.5 dark:bg-slate-900">{{ apiBaseUrl }}</code></p>
        </div>
    </AuthenticatedLayout>
</template>
