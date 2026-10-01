<script setup>
import { computed, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import YourIpCard from './components/YourIpCard.vue';
import BannedIpList from './components/BannedIpList.vue';
import WhitelistCard from './components/WhitelistCard.vue';
import SshPolicyCard from './components/SshPolicyCard.vue';
import SshLoginHistory from './components/SshLoginHistory.vue';

const props = defineProps({
    clientIp: { type: String, default: '' },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const status = ref({ installed: true, running: true, jails: [], whitelist: [], policy: null });
const loading = ref(true);
const loadError = ref('');
// The IP (or 'refresh') whose request is running, so only that button spins.
const busy = ref('');
const message = ref(null);

const bannedIn = (ip) => status.value.jails.filter((jail) => (jail.banned_ips || []).includes(ip)).map((jail) => jail.name);

const load = async () => {
    busy.value = 'refresh';
    loadError.value = '';
    try {
        const { data } = await axios.get(panelRoute('security.fail2ban.status'));
        status.value = data.data;
    } catch (e) {
        loadError.value = e.response?.data?.message || 'Could not load fail2ban status.';
    } finally {
        loading.value = false;
        busy.value = '';
    }
};

const history = ref([]);
const historyLoading = ref(true);
const historyError = ref('');

const loadHistory = async () => {
    historyLoading.value = true;
    historyError.value = '';
    try {
        const { data } = await axios.get(panelRoute('security.fail2ban.history'));
        history.value = data.data.events || [];
    } catch (e) {
        historyError.value = e.response?.data?.message || 'Could not load SSH login history.';
    } finally {
        historyLoading.value = false;
    }
};

const act = async (request, ip) => {
    busy.value = ip;
    message.value = null;
    try {
        const { data } = await request();
        status.value = data.data;
        message.value = { type: 'success', text: data.message };
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Request failed.' };
    } finally {
        busy.value = '';
    }
};

const unban = (ip) => act(() => axios.post(panelRoute('security.fail2ban.unban'), { ip }), ip);
const ban = (ip) => {
    const warning = ip === props.clientIp ? ' This is your own IP: you will lose SSH access until you unblock it here.' : '';
    if (!confirm(`Block ${ip} from SSH permanently?${warning}`)) return;
    act(() => axios.post(panelRoute('security.fail2ban.ban'), { ip }), ip);
};
const savePolicy = (maxRetry) => act(() => axios.post(panelRoute('security.fail2ban.policy'), { max_retry: maxRetry }), 'policy');
const whitelistAdd = (ip) => act(() => axios.post(panelRoute('security.fail2ban.whitelist.store'), { ip }), ip);
const whitelistRemove = (ip) => {
    if (!confirm(`Remove ${ip} from the whitelist? It can be blocked again after failed logins.`)) return;
    act(() => axios.delete(panelRoute('security.fail2ban.whitelist.destroy'), { data: { ip } }), ip);
};

onMounted(() => {
    load();
    loadHistory();
});
</script>

<template>
    <Head title="Fail2ban" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Fail2ban</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">IPs blocked after repeated failed logins. Unblock one, or whitelist it so it is never blocked again.</p>
                </div>
                <button type="button" :disabled="busy === 'refresh'" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="load(); loadHistory()">
                    {{ busy === 'refresh' ? 'Refreshing…' : 'Refresh' }}
                </button>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="loadError" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                {{ loadError }}
            </div>
            <div v-else-if="!loading && !status.installed" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                fail2ban is not installed on this server. Install it with <code>sudo dpanel chain install fail2ban</code>.
            </div>
            <div v-else-if="!loading && !status.running" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                fail2ban is installed but not running, so nothing is blocked. Start it with <code>sudo systemctl start fail2ban</code>.
            </div>

            <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="rounded-md border px-4 py-3 text-sm">
                {{ message.text }}
            </div>

            <YourIpCard
                :ip="props.clientIp"
                :banned-in="bannedIn(props.clientIp)"
                :whitelisted="status.whitelist.includes(props.clientIp)"
                :loading="loading"
                :busy="busy === props.clientIp"
                @unban="unban"
                @whitelist="whitelistAdd"
            />

            <BannedIpList
                :jails="status.jails"
                :whitelist="status.whitelist"
                :client-ip="props.clientIp"
                :loading="loading"
                :busy="busy"
                @unban="unban"
                @whitelist="whitelistAdd"
            />

            <SshPolicyCard
                v-if="status.running"
                :policy="status.policy"
                :busy="busy === 'policy'"
                @save="savePolicy"
            />

            <SshLoginHistory
                :events="history"
                :jails="status.jails"
                :whitelist="status.whitelist"
                :client-ip="props.clientIp"
                :loading="historyLoading"
                :error="historyError"
                :busy="busy"
                @unban="unban"
                @ban="ban"
                @refresh="loadHistory"
            />

            <WhitelistCard
                :entries="status.whitelist"
                :busy="busy"
                @add="whitelistAdd"
                @remove="whitelistRemove"
            />
        </div>
    </AuthenticatedLayout>
</template>
