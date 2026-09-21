import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Shared by the Manage page and its section components: builds routes that
 * carry the current cpsess token, and wraps fetch() with the JSON headers
 * every action endpoint on this page expects.
 */
export function usePanelApi() {
    const page = usePage();
    const panelToken = computed(() => String(page.props.panel?.token || ''));
    const panelRoute = (name, params = {}) => (
        panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
    );
    const csrfToken = computed(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');

    const requestJson = async (url, { method = 'POST', body = {} } = {}) => {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken.value,
            },
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw data instanceof Error ? data : new Error(data.message || 'Request failed.');
        }

        return data;
    };

    return { panelToken, panelRoute, csrfToken, requestJson };
}
