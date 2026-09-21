<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';

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
        if (!confirm('No remote IPs are currently allowed. Adding this one will enable external MySQL access for this server and briefly restart MySQL for ALL hosted databases. Continue?')) return;
    } else if (!confirm(`Allow ${ip.value.trim()} to connect to MySQL on port ${props.port}?`)) {
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
            message.value = { type: 'success', text: 'IP added.' };
        } else {
            message.value = { type: 'error', text: data.message || 'Failed to add IP.' };
        }
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Request failed.' };
    } finally {
        adding.value = false;
    }
};

const removeIp = async (rule) => {
    if (!confirm(`Remove access for ${rule.ip_address}?`)) return;
    removingId.value = rule.id;
    message.value = null;
    try {
        const { data } = await axios.delete(panelRoute('databases.remote-access.destroy', { id: rule.id }));
        applyState(data);
        message.value = { type: data.firewall_warning ? 'error' : 'success', text: data.firewall_warning ? `Removed, but firewall cleanup warning: ${data.firewall_warning}` : 'IP removed.' };
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Request failed.' };
    } finally {
        removingId.value = null;
    }
};

const restrictWildcard = async () => {
    if (!confirm(`This removes the open "Anywhere" rule for port ${props.port}. Only IPs listed below will be able to connect afterward. Continue?`)) return;
    restricting.value = true;
    message.value = null;
    try {
        const { data } = await axios.post(panelRoute('databases.remote-access.restrict-wildcard'));
        applyState(data);
        message.value = { type: data.success ? 'success' : 'error', text: data.success ? 'Wildcard rule removed.' : (data.message || 'Failed to remove wildcard rule.') };
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Request failed.' };
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
    <Head title="Remote MySQL Access" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Remote MySQL Access</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Allow specific IP addresses to reach this server's shared MySQL/MariaDB instance on port {{ port }}.</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</p>
                        <p class="mt-1 text-sm">
                            Listening on <code>{{ state.bindAddress }}</code> &mdash;
                            <span :class="state.externalEnabled ? 'text-amber-600' : 'text-emerald-600'">{{ state.externalEnabled ? 'external access enabled' : 'local only' }}</span>
                        </p>
                    </div>
                    <button type="button" :disabled="refreshing" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="refresh">
                        {{ refreshing ? 'Refreshing…' : 'Refresh Status' }}
                    </button>
                </div>

                <div class="mt-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Live firewall rules for port {{ port }}</p>
                    <div class="mt-2 rounded-md bg-slate-100 p-3 font-mono text-xs dark:bg-slate-800">
                        <div v-for="(line, index) in state.liveFirewallLines" :key="index">{{ line }}</div>
                        <div v-if="state.liveFirewallLines.length === 0" class="text-slate-500">No rules found for this port.</div>
                    </div>
                </div>

                <div v-if="wildcardOpen" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    Port {{ port }} is currently open to <strong>Anywhere</strong>. Add the specific IPs you need below, then restrict access to only those IPs.
                    <button type="button" :disabled="restricting" class="mt-2 block rounded-md border border-amber-400 px-3 py-1.5 text-xs font-medium hover:bg-amber-100 disabled:opacity-60 dark:hover:bg-amber-900/40" @click="restrictWildcard">
                        {{ restricting ? 'Restricting…' : 'Restrict to listed IPs only' }}
                    </button>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">Add an allowed IP</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-[2fr_2fr_auto]">
                    <div>
                        <label class="mb-1 block text-sm">IP address or CIDR</label>
                        <input v-model="ip" type="text" placeholder="203.0.113.10 or 203.0.113.0/24" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Note (optional)</label>
                        <input v-model="note" type="text" placeholder="e.g. app server for katha24" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                    </div>
                    <div class="flex items-end">
                        <button type="button" :disabled="adding || !ip.trim()" class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40" @click="addIp">
                            {{ adding ? 'Adding…' : 'Add' }}
                        </button>
                    </div>
                </div>

                <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mt-4 rounded-md border px-4 py-3 text-sm">
                    {{ message.text }}
                </div>

                <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                    This only controls network access. MySQL user privileges (<code>GRANT ... TO 'user'@'host'</code>) must still be configured separately, e.g. via phpMyAdmin.
                </p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-base font-semibold">Allowed IPs</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-2">IP / CIDR</th>
                                <th class="px-3 py-2">Note</th>
                                <th class="px-3 py-2">Added</th>
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
                                        {{ removingId === rule.id ? 'Removing…' : 'Remove' }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="state.rules.length === 0">
                                <td colspan="4" class="px-3 py-4 text-center text-slate-500">No remote IPs allowed yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
