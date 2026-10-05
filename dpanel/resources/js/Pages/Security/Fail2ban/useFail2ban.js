import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

/**
 * Status, actions and SSH history shared by the SSH Login History,
 * Blocklist and Whitelist pages, so each page only lays out its own cards.
 */
export function useFail2ban(clientIp = '') {
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
    // Rows deleted from the view; the journal itself is never touched.
    const historyHidden = ref(0);

    const loadHistory = async () => {
        historyLoading.value = true;
        historyError.value = '';
        try {
            const { data } = await axios.get(panelRoute('security.fail2ban.history'));
            history.value = data.data.events || [];
            historyHidden.value = data.data.hidden || 0;
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
        const warning = ip === clientIp ? ' This is your own IP: you will lose SSH access until you unblock it here.' : '';
        if (!confirm(`Block ${ip} from SSH permanently?${warning}`)) return;
        act(() => axios.post(panelRoute('security.fail2ban.ban'), { ip }), ip);
    };
    const bulk = (action, ips) => {
        if (!ips.length) return;
        const count = `${ips.length} IP${ips.length === 1 ? '' : 's'}`;
        if (action === 'ban') {
            const warning = ips.includes(clientIp) ? ' Your own IP is in the selection: you will lose SSH access until you unblock it.' : '';
            if (!confirm(`Block ${count} from SSH permanently?${warning}`)) return;
        }
        if (action === 'whitelist_add' && !confirm(`Whitelist ${count}? fail2ban will never block them, and any current block is lifted.`)) return;
        act(() => axios.post(panelRoute('security.fail2ban.bulk'), { action, ips }), 'bulk');
    };
    // History changes return no fail2ban status, so they reload the history instead.
    const changeHistory = async (name, payload = {}) => {
        busy.value = 'history';
        message.value = null;
        try {
            const { data } = await axios.post(panelRoute(`security.fail2ban.history.${name}`), payload);
            message.value = { type: 'success', text: data.message };
            await loadHistory();
        } catch (e) {
            message.value = { type: 'error', text: e.response?.data?.message || 'Request failed.' };
        } finally {
            busy.value = '';
        }
    };
    const deleteHistory = (ips) => {
        if (!ips.length) return;
        if (!confirm(`Delete the login history of ${ips.length} IP${ips.length === 1 ? '' : 's'}? Blocks and the whitelist stay as they are, and new attempts from these IPs still show.`)) return;
        changeHistory('delete', { ips });
    };
    const clearHistory = () => {
        if (!confirm('Clear the whole SSH login history? Blocks and the whitelist stay as they are; only logins after now will show.')) return;
        changeHistory('clear');
    };
    const restoreHistory = () => changeHistory('restore');
    const savePolicy = (maxRetry) => act(() => axios.post(panelRoute('security.fail2ban.policy'), { max_retry: maxRetry }), 'policy');
    const whitelistAdd = (ip) => act(() => axios.post(panelRoute('security.fail2ban.whitelist.store'), { ip }), ip);
    const whitelistRemove = (ip) => {
        if (!confirm(`Remove ${ip} from the whitelist? It can be blocked again after failed logins.`)) return;
        act(() => axios.delete(panelRoute('security.fail2ban.whitelist.destroy'), { data: { ip } }), ip);
    };

    return {
        status, loading, loadError, busy, message, load,
        history, historyLoading, historyError, historyHidden, loadHistory,
        deleteHistory, clearHistory, restoreHistory,
        unban, ban, bulk, savePolicy, whitelistAdd, whitelistRemove,
    };
}
