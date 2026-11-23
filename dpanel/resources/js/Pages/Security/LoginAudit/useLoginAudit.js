import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { i18n } from '@/i18n';

const t = (...args) => i18n.global.t(...args);

/** Filters, events, map points and actions for the Login Audit page. */
export function useLoginAudit(initial = {}) {
    const page = usePage();
    const panelToken = computed(() => String(page.props.panel?.token || ''));
    const panelRoute = (name, params = {}) => (
        panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
    );

    const filters = reactive({ channel: '', result: '', search: '', country: '', from: '', to: '' });
    const pageNumber = ref(1);

    const events = ref([]);
    const meta = ref({ page: 1, last_page: 1, total: 0 });
    const stats = ref({ total: 0, success: 0, failed: 0, failed_24h: 0, unique_ips: 0, countries: [] });
    const points = ref([]);
    const loading = ref(false);
    const error = ref('');
    const busy = ref('');
    const message = ref(null);

    const params = () => Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

    const load = async () => {
        loading.value = true;
        error.value = '';
        try {
            const [list, map] = await Promise.all([
                axios.get(panelRoute('security.login-audit.events'), { params: { ...params(), page: pageNumber.value } }),
                axios.get(panelRoute('security.login-audit.map'), { params: params() }),
            ]);
            events.value = list.data.data;
            meta.value = list.data.meta;
            stats.value = list.data.stats;
            points.value = map.data.data;
        } catch (e) {
            error.value = e.response?.data?.message || t('security.loginAudit.actions.loadFailed');
        } finally {
            loading.value = false;
        }
    };

    const applyFilters = () => {
        pageNumber.value = 1;
        load();
    };

    const goTo = (number) => {
        pageNumber.value = number;
        load();
    };

    const post = async (name, kind) => {
        busy.value = kind;
        message.value = null;
        try {
            const { data } = await axios.post(panelRoute(name));
            message.value = { type: 'success', text: data.message };
            return true;
        } catch (e) {
            message.value = { type: 'error', text: e.response?.data?.message || t('security.loginAudit.actions.requestFailed') };
            return false;
        } finally {
            busy.value = '';
        }
    };

    const syncNow = async () => {
        if (await post('security.login-audit.sync', 'sync')) load();
    };

    // DB-IP download: progress is polled while a download runs.
    const ACTIVE = ['queued', 'downloading', 'unpacking', 'locating'];
    const geoip = ref({ ...(initial.geoip || {}) });
    const geoipProgress = ref(initial.geoipProgress || null);
    const geoipRunning = computed(() => ACTIVE.includes(geoipProgress.value?.stage));
    let pollTimer = null;

    const pollGeoip = async () => {
        clearTimeout(pollTimer);
        try {
            const { data } = await axios.get(panelRoute('security.login-audit.geoip.status'));
            const wasRunning = geoipRunning.value;
            geoipProgress.value = data.progress;
            geoip.value = data.geoip;
            if (geoipRunning.value) {
                pollTimer = setTimeout(pollGeoip, 1000);
            } else if (wasRunning && data.progress?.stage === 'done') {
                message.value = { type: 'success', text: data.progress.message };
                load();
            } else if (wasRunning && data.progress?.stage === 'failed') {
                message.value = { type: 'error', text: data.progress.message };
            }
        } catch {
            pollTimer = setTimeout(pollGeoip, 3000);
        }
    };

    const downloadGeoip = async () => {
        busy.value = 'geoip';
        message.value = null;
        try {
            const { data } = await axios.post(panelRoute('security.login-audit.geoip'));
            geoipProgress.value = data.progress;
            pollGeoip();
        } catch (e) {
            message.value = { type: 'error', text: e.response?.data?.message || t('security.loginAudit.actions.requestFailed') };
        } finally {
            busy.value = '';
        }
    };

    if (geoipRunning.value) pollGeoip();
    onBeforeUnmount(() => clearTimeout(pollTimer));

    const saveLocationMode = async (payload) => {
        busy.value = 'location';
        message.value = null;
        try {
            const { data } = await axios.post(panelRoute('security.login-audit.location'), payload);
            message.value = { type: 'success', text: data.message };
            return data.modes;
        } catch (e) {
            message.value = { type: 'error', text: e.response?.data?.message || t('security.loginAudit.actions.saveFailed') };
            return null;
        } finally {
            busy.value = '';
        }
    };

    return {
        filters, events, meta, stats, points, loading, error, busy, message,
        load, applyFilters, goTo, syncNow, downloadGeoip, saveLocationMode,
        geoip, geoipProgress, geoipRunning,
    };
}
