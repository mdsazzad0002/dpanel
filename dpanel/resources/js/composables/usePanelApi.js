import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/** The panel's token-prefixed routes and a readable error from a failed request. */
export function usePanelApi(t) {
    const page = usePage();
    const token = computed(() => String(page.props.panel?.token || ''));
    const panelRoute = (name, params = {}) => (token.value ? route(name, { token: token.value, ...params }) : route(name, params));
    const errorMessage = (e, fallback) => {
        const data = e?.response?.data || {};
        if (e?.response?.status === 419) return t('common.sessionExpired');
        return data.message && !data.errors ? data.message : Object.values(data.errors || {}).flat().join(' ') || data.message || e?.message || fallback;
    };
    return { panelRoute, errorMessage };
}
