import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function usePhpMyAdminTransport(props = {}) {
    const page = usePage();
    const panelToken = computed(() => String(page.props.panel?.token || props.panelToken || ''));
    const accessMode = computed(() => String(props.accessControl?.mode || 'scoped'));
    const csrfToken = computed(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');

    const panelRoute = (name, params = {}) => (
        panelToken.value
            ? route(name, {
                token: panelToken.value,
                ...params,
                ...(accessMode.value === 'global' ? { access: 'all' } : {}),
            })
            : route(name, {
                ...params,
                ...(accessMode.value === 'global' ? { access: 'all' } : {}),
            })
    );

    const safeJson = async (response) => response.json().catch(() => ({}));

    const toasts = ref([]);
    let toastSeq = 0;

    const removeToast = (id) => {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    };

    const pushToast = (message, type = 'error') => {
        if (!message) return;

        const id = `${Date.now()}-${toastSeq += 1}`;
        toasts.value.push({ id, message: String(message), type });

        window.setTimeout(() => {
            removeToast(id);
        }, 4500);
    };

    const requestJson = async (name, params = {}, init = {}) => {
        const response = await fetch(panelRoute(name, params), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(init.headers || {}),
            },
            ...init,
        });

        const data = await safeJson(response);
        return { response, data };
    };

    const requestBlob = async (name, params = {}, init = {}) => {
        const response = await fetch(panelRoute(name, params), {
            credentials: 'same-origin',
            headers: {
                Accept: '*/*',
                ...(init.headers || {}),
            },
            ...init,
        });

        if (!response.ok) {
            const data = await safeJson(response.clone());
            return { response, data, blob: null };
        }

        return { response, blob: await response.blob() };
    };

    const executeSql = async (sql, database = '') => {
        const { response, data } = await requestJson('phpmyadmin.execute', {}, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken.value,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ sql, database }),
        });

        if (!response.ok || !data?.ok) {
            throw new Error(data?.message || 'Query execution failed.');
        }

        return data;
    };

    return {
        panelToken,
        csrfToken,
        panelRoute,
        safeJson,
        requestJson,
        requestBlob,
        toasts,
        pushToast,
        removeToast,
        executeSql,
    };
}
