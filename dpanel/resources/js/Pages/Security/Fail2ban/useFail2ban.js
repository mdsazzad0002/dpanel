import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { i18n } from '@/i18n';

const t = (...args) => i18n.global.t(...args);

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
            loadError.value = e.response?.data?.message || t('security.fail2ban.actions.loadStatusFailed');
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
            historyError.value = e.response?.data?.message || t('security.fail2ban.actions.loadHistoryFailed');
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
            message.value = { type: 'error', text: e.response?.data?.message || t('security.fail2ban.actions.requestFailed') };
        } finally {
            busy.value = '';
        }
    };

    const unban = (ip) => act(() => axios.post(panelRoute('security.fail2ban.unban'), { ip }), ip);
    const ban = (ip) => {
        if (!confirm(t(ip === clientIp ? 'security.fail2ban.actions.banConfirmSelf' : 'security.fail2ban.actions.banConfirm', { ip }))) return;
        act(() => axios.post(panelRoute('security.fail2ban.ban'), { ip }), ip);
    };
    const bulk = (action, ips) => {
        if (!ips.length) return;
        const count = t(ips.length === 1 ? 'security.fail2ban.actions.oneIp' : 'security.fail2ban.actions.manyIps', { count: ips.length });
        if (action === 'ban') {
            if (!confirm(t(ips.includes(clientIp) ? 'security.fail2ban.actions.bulkBanConfirmSelf' : 'security.fail2ban.actions.bulkBanConfirm', { count }))) return;
        }
        if (action === 'whitelist_add' && !confirm(t('security.fail2ban.actions.bulkWhitelistConfirm', { count }))) return;
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
            message.value = { type: 'error', text: e.response?.data?.message || t('security.fail2ban.actions.requestFailed') };
        } finally {
            busy.value = '';
        }
    };
    const deleteHistory = (ips) => {
        if (!ips.length) return;
        if (!confirm(t('security.fail2ban.actions.deleteHistoryConfirm', { count: t(ips.length === 1 ? 'security.fail2ban.actions.oneIp' : 'security.fail2ban.actions.manyIps', { count: ips.length }) }))) return;
        changeHistory('delete', { ips });
    };
    const clearHistory = () => {
        if (!confirm(t('security.fail2ban.actions.clearHistoryConfirm'))) return;
        changeHistory('clear');
    };
    const restoreHistory = () => changeHistory('restore');
    const savePolicy = (maxRetry) => act(() => axios.post(panelRoute('security.fail2ban.policy'), { max_retry: maxRetry }), 'policy');
    const whitelistAdd = (ip) => act(() => axios.post(panelRoute('security.fail2ban.whitelist.store'), { ip }), ip);
    const whitelistRemove = (ip) => {
        if (!confirm(t('security.fail2ban.actions.whitelistRemoveConfirm', { ip }))) return;
        act(() => axios.delete(panelRoute('security.fail2ban.whitelist.destroy'), { data: { ip } }), ip);
    };

    return {
        status, loading, loadError, busy, message, load,
        history, historyLoading, historyError, historyHidden, loadHistory,
        deleteHistory, clearHistory, restoreHistory,
        unban, ban, bulk, savePolicy, whitelistAdd, whitelistRemove,
    };
}
