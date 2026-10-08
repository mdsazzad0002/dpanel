import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

/**
 * What every Docker page needs to talk to the panel: token-aware routes,
 * one busy key so only the clicked button spins, and the banner message.
 */
export function useDockerRequest() {
    const page = usePage();
    const panelToken = computed(() => String(page.props.panel?.token || ''));
    const panelRoute = (name, params = {}) => (
        panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
    );

    const busy = ref('');
    const message = ref(null);

    const errorText = (e) => {
        const errors = e.response?.data?.errors;
        if (errors) return Object.values(errors).flat().join(' ');
        return e.response?.data?.message || e.message || 'Request failed.';
    };

    /**
     * Runs a change and shows its outcome. Resolves to the response data on
     * success (so a form knows to reset) and to null on failure.
     */
    const act = async (request, key, onData) => {
        busy.value = key;
        message.value = null;
        try {
            const { data } = await request();
            if (data.data && onData) onData(data.data);
            message.value = { type: data.success === false ? 'error' : 'success', text: data.message };
            return data.success === false ? null : (data.data ?? {});
        } catch (e) {
            // A bulk action can partly fail and still carry fresh data.
            if (e.response?.data?.data && onData) onData(e.response.data.data);
            message.value = { type: 'error', text: errorText(e) };
            return null;
        } finally {
            busy.value = '';
        }
    };

    const get = (name, params) => axios.get(panelRoute(name, params));
    const post = (name, body = {}) => axios.post(panelRoute(name), body);

    return { panelRoute, busy, message, errorText, act, get, post, axios };
}

/** A strong random string for generated passwords (letters and digits only, so it is safe in YAML, URLs and .env). */
export function randomSecret(length = 24) {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    const bytes = new Uint32Array(length);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (b) => alphabet[b % alphabet.length]).join('');
}

/** Host ports containers already publish, read from `ps`'s "127.0.0.1:8080->80/tcp" text. */
export function usedHostPorts(containers = []) {
    const ports = new Set();
    for (const c of containers) {
        for (const match of String(c.ports || '').matchAll(/:(\d+)->/g)) ports.add(Number(match[1]));
    }
    return ports;
}

/** The preferred port, or the next one up that nothing publishes yet. */
export function freePort(preferred, used) {
    let port = Number(preferred) || 8080;
    while (used.has(port) && port < 65535) port += 1;
    used.add(port);
    return port;
}

/** Splits a command line like a shell would for quotes, without running one. */
export function splitCommand(text) {
    const args = [];
    let current = '';
    let quote = '';
    let started = false;
    for (const ch of String(text || '')) {
        if (quote) {
            if (ch === quote) quote = '';
            else current += ch;
        } else if (ch === '"' || ch === "'") {
            quote = ch;
            started = true;
        } else if (/\s/.test(ch)) {
            if (started || current) args.push(current);
            current = '';
            started = false;
        } else {
            current += ch;
            started = true;
        }
    }
    if (started || current) args.push(current);
    return args;
}

/** The reverse of splitCommand, for showing an argument list in one input. */
export function joinCommand(args = []) {
    return args.map((a) => (a === '' || /[\s"'\\]/.test(a) ? `'${a.replace(/'/g, `'"'"'`)}'` : a)).join(' ');
}
