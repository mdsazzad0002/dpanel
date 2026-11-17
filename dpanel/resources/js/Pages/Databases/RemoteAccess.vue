<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    rules: { type: Array, default: () => [] },
    bindAddress: { type: String, default: '127.0.0.1' },
    externalEnabled: { type: Boolean, default: false },
    liveFirewallLines: { type: Array, default: () => [] },
    port: { type: Number, default: 3306 },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const state = ref({
    rules: props.rules,
    bindAddress: props.bindAddress,
    externalEnabled: props.externalEnabled,
    liveFirewallLines: props.liveFirewallLines,
});

const ip = ref('');
const note = ref('');
const adding = ref(false);
const removingId = ref(null);
const restricting = ref(false);
const refreshing = ref(false);
const message = ref(null);

const wildcardOpen = computed(() => state.value.liveFirewallLines.some((line) => line.includes('Anywhere') || line.includes('0.0.0.0/0')));

const applyState = (data) => {
    if (data.rules) state.value.rules = data.rules;
    if (data.bindAddress !== undefined) state.value.bindAddress = data.bindAddress;
    if (data.externalEnabled !== undefined) state.value.externalEnabled = data.externalEnabled;
    if (data.liveFirewallLines) state.value.liveFirewallLines = data.liveFirewallLines;
};

const addIp = async () => {
    if (!ip.value.trim()) return;

    if (!state.value.externalEnabled) {
        if (!confirm(t('databases.remoteAccess.enableConfirm'))) return;
    } else if (!confirm(t('databases.remoteAccess.allowConfirm', { ip: ip.value.trim(), port: props.port }))) {
        return;
    }

    adding.value = true;
    message.value = null;
    try {
        const { data } = await axios.post(panelRoute('databases.remote-access.store'), {
            ip_address: ip.value.trim(),
            note: note.value.trim() || null,
        });
        applyState(data);
        if (data.success) {
            ip.value = '';
            note.value = '';
            message.value = { type: 'success', text: t('databases.remoteAccess.added') };
        } else {
            message.value = { type: 'error', text: data.message || t('databases.remoteAccess.addFailed') };
        }
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || t('databases.postgresql.requestFailed') };
    } finally {
        adding.value = false;
    }
};

const removeIp = async (rule) => {
    if (!confirm(t('databases.remoteAccess.removeConfirm', { ip: rule.ip_address }))) return;
    removingId.value = rule.id;
    message.value = null;
    try {
        const { data } = await axios.delete(panelRoute('databases.remote-access.destroy', { id: rule.id }));
        applyState(data);
        message.value = { type: data.firewall_warning ? 'error' : 'success', text: data.firewall_warning ? t('databases.remoteAccess.removedWarning', { warning: data.firewall_warning }) : t('databases.remoteAccess.removed') };
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || t('databases.postgresql.requestFailed') };
    } finally {
        removingId.value = null;
    }
};

const restrictWildcard = async () => {
    if (!confirm(t('databases.remoteAccess.restrictConfirm', { port: props.port }))) return;
    restricting.value = true;
    message.value = null;
    try {
        const { data } = await axios.post(panelRoute('databases.remote-access.restrict-wildcard'));
        applyState(data);
        message.value = { type: data.success ? 'success' : 'error', text: data.success ? t('databases.remoteAccess.wildcardRemoved') : (data.message || t('databases.remoteAccess.wildcardFailed')) };
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || t('databases.postgresql.requestFailed') };
    } finally {
        restricting.value = false;
    }
};

const refresh = async () => {
    refreshing.value = true;
    try {
        const { data } = await axios.post(panelRoute('databases.remote-access.refresh'));
        applyState(data);
    } finally {
        refreshing.value = false;
    }
};
</script>

<template>
    <Head :title="t('databases.remoteAccess.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('databases.remoteAccess.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('databases.remoteAccess.subtitle', { port }) }}</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('databases.remoteAccess.status') }}</p>
                        <p class="mt-1 text-sm">
                            <i18n-t keypath="databases.remoteAccess.listening" scope="global"><template #address><code>{{ state.bindAddress }}</code></template></i18n-t> &mdash;
                            <span :class="state.externalEnabled ? 'text-amber-600' : 'text-emerald-600'">{{ state.externalEnabled ? t('databases.remoteAccess.external') : t('databases.remoteAccess.localOnly') }}</span>
                        </p>
                    </div>
                    <button type="button" :disabled="refreshing" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="refresh">
                        {{ refreshing ? t('databases.postgresql.refreshing') : t('databases.postgresql.refreshStatus') }}
                    </button>
                </div>

                <div class="mt-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('databases.remoteAccess.liveRules', { port }) }}</p>
                    <div class="mt-2 rounded-md bg-slate-100 p-3 font-mono text-xs dark:bg-slate-800">
                        <div v-for="(line, index) in state.liveFirewallLines" :key="index">{{ line }}</div>
                        <div v-if="state.liveFirewallLines.length === 0" class="text-slate-500">{{ t('databases.remoteAccess.noRules') }}</div>
                    </div>
                </div>

                <div v-if="wildcardOpen" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    <i18n-t keypath="databases.remoteAccess.wildcardOpen" scope="global"><template #port>{{ port }}</template><template #anywhere><strong>Anywhere</strong></template></i18n-t>
                    <button type="button" :disabled="restricting" class="mt-2 block rounded-md border border-amber-400 px-3 py-1.5 text-xs font-medium hover:bg-amber-100 disabled:opacity-60 dark:hover:bg-amber-900/40" @click="restrictWildcard">
                        {{ restricting ? t('databases.remoteAccess.restricting') : t('databases.remoteAccess.restrict') }}
                    </button>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">{{ t('databases.remoteAccess.addTitle') }}</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-[2fr_2fr_auto]">
                    <div>
                        <label class="mb-1 block text-sm">{{ t('databases.remoteAccess.ipLabel') }}</label>
                        <input v-model="ip" type="text" :placeholder="t('databases.remoteAccess.ipPlaceholder')" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">{{ t('databases.remoteAccess.noteLabel') }}</label>
                        <input v-model="note" type="text" :placeholder="t('databases.remoteAccess.notePlaceholder')" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div class="flex items-end">
                        <button type="button" :disabled="adding || !ip.trim()" class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40" @click="addIp">
                            {{ adding ? t('databases.remoteAccess.adding') : t('databases.remoteAccess.add') }}
                        </button>
                    </div>
                </div>

                <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-4 rounded-md border px-4 py-3 text-sm">
                    {{ message.text }}
                </div>

                <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                    <i18n-t keypath="databases.remoteAccess.grantHelp" scope="global"><template #grant><code>GRANT ... TO 'user'@'host'</code></template></i18n-t>
                </p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">{{ t('databases.remoteAccess.allowed') }}</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-2">{{ t('databases.remoteAccess.col.ip') }}</th>
                                <th class="px-3 py-2">{{ t('databases.remoteAccess.col.note') }}</th>
                                <th class="px-3 py-2">{{ t('databases.remoteAccess.col.added') }}</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="rule in state.rules" :key="rule.id" class="border-t border-slate-200 dark:border-slate-800">
                                <td class="px-3 py-2 font-mono text-xs">{{ rule.ip_address }}</td>
                                <td class="px-3 py-2 text-xs">{{ rule.note || '—' }}</td>
                                <td class="px-3 py-2 text-xs">{{ rule.created_at }}</td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button" :disabled="removingId === rule.id" class="rounded border border-red-300 px-2.5 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-60 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-900/20" @click="removeIp(rule)">
                                        {{ removingId === rule.id ? t('databases.remoteAccess.removing') : t('databases.remoteAccess.remove') }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="state.rules.length === 0">
                                <td colspan="4" class="px-3 py-4 text-center text-slate-500">{{ t('databases.remoteAccess.empty') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
