import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Calls to the WordPress toolkit routes; errors come back as one message.
export function useWordpressApi() {
    const page = usePage();
    const token = computed(() => String(page.props.panel?.token || ''));
    const url = (name, params = {}) => (token.value ? route(name, { token: token.value, ...params }) : route(name, params));

    const message = (e, fallback) => {
        const data = e?.response?.data || {};
        return data.message || Object.values(data.errors || {}).flat().join(' ') || e?.message || fallback;
    };

    const get = async (name, params = {}) => (await window.axios.get(url(name, params))).data;
    const post = async (name, params = {}, body = {}) => (await window.axios.post(url(name, params), body)).data;
    const destroy = async (name, params = {}) => (await window.axios.delete(url(name, params))).data;

    return { get, post, destroy, message };
}
