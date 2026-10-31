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

    // Laravel refreshes the XSRF-TOKEN cookie on every response, so it stays
    // valid even after the session token rotates in another tab. It is only
    // checked when X-CSRF-TOKEN is absent, so send one or the other.
    const csrfHeaders = () => {
        const xsrfCookie = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

        return xsrfCookie
            ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie.slice('XSRF-TOKEN='.length)) }
            : { 'X-CSRF-TOKEN': csrfToken.value };
    };

    const requestJson = async (url, { method = 'POST', body = {} } = {}) => {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...csrfHeaders(),
            },
            // fetch() refuses a body on GET.
            body: method === 'GET' ? undefined : JSON.stringify(body),
        });
        // An expired session redirects to the login page, which fetch()
        // follows and answers 200 with HTML — that is not a success.
        if (response.redirected) {
            throw new Error('Your session has expired — reload the page and sign in again.');
        }
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw data instanceof Error ? data : new Error(data.message || 'Request failed.');
        }

        return data;
    };

    return { panelToken, panelRoute, csrfToken, csrfHeaders, requestJson };
}
