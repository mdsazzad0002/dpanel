<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FirewallRuleForm from './components/FirewallRuleForm.vue';
import FirewallRuleTable from './components/FirewallRuleTable.vue';
import PendingChangeBanner from './components/PendingChangeBanner.vue';

const { t } = useI18n();
const page = usePage();
const token = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (token.value ? route(name, { token: token.value, ...params }) : route(name, params));

const status = ref({});
const rules = ref([]);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
// The change drust will undo unless it is confirmed: { token, seconds, message }.
const pending = ref(null);

const errorMessage = (e, fallback) => {
    const data = e?.response?.data || {};
    if (e?.response?.status === 419) return t('common.sessionExpired');
    return data.message || Object.values(data.errors || {}).flat().join(' ') || e?.message || fallback;
};

const load = async () => {
    loading.value = true;
    try {
        const { data } = await window.axios.get(panelRoute('security.firewall.rules.list'));
        status.value = data.status || {};
        rules.value = data.rules || [];
        error.value = '';
    } catch (e) {
        error.value = errorMessage(e, t('firewall.loadFailed'));
    } finally {
        loading.value = false;
    }
};

const afterChange = async (data) => {
    notice.value = '';
    pending.value = data.confirm_token ? { token: data.confirm_token, seconds: data.confirm_within_seconds || 60, message: data.message } : null;
    if (!pending.value) notice.value = data.message;
    await load();
};

const addRule = async (payload) => {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(panelRoute('security.firewall.rules.store'), payload);
        await afterChange(data);
    } catch (e) {
        error.value = errorMessage(e, t('firewall.addFailed'));
    } finally {
        busy.value = false;
    }
};

const removeRule = async (rule) => {
    if (!window.confirm(t('firewall.removeConfirm'))) return;
    busy.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.delete(panelRoute('security.firewall.rules.destroy', { rule: rule.id }));
        await afterChange(data);
    } catch (e) {
        error.value = errorMessage(e, t('firewall.removeFailed'));
    } finally {
        busy.value = false;
    }
};

const keepChange = async () => {
    if (!pending.value) return;
    try {
        const { data } = await window.axios.post(panelRoute('security.firewall.confirm'), { token: pending.value.token });
        notice.value = data.message;
    } catch (e) {
        error.value = errorMessage(e, t('firewall.confirmFailed'));
    } finally {
        pending.value = null;
        await load();
    }
};

const expired = async () => {
    pending.value = null;
    error.value = t('firewall.undone');
    await load();
};

onMounted(load);
</script>

<template>
    <Head :title="t('firewall.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('firewall.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('firewall.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-5">
            <PendingChangeBanner v-if="pending" :key="pending.token" :seconds="pending.seconds" :message="pending.message" @keep="keepChange" @expired="expired" />

            <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"><i class="bi bi-exclamation-triangle-fill mr-2"></i>{{ error }}</div>
            <div v-if="notice" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300"><i class="bi bi-check-circle-fill mr-2"></i>{{ notice }}</div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ t('firewall.backend') }}</p>
                    <p class="mt-1 text-lg font-bold text-slate-800 dark:text-slate-100">{{ status.backend || '—' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ t('firewall.state') }}</p>
                    <p class="mt-1 text-lg font-bold" :class="status.active ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">{{ status.active ? t('firewall.active') : t('firewall.inactive') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ t('firewall.rules') }}</p>
                    <p class="mt-1 text-lg font-bold text-slate-800 dark:text-slate-100">{{ rules.length }}</p>
                </div>
            </div>

            <FirewallRuleForm :busy="busy || !!pending" @submit="addRule" />
            <FirewallRuleTable :rules="rules" :loading="loading" :busy="busy || !!pending" @refresh="load" @remove="removeRule" />
        </div>
    </AuthenticatedLayout>
</template>
