import { reactive } from 'vue';
import axios from 'axios';

/**
 * Model suggestions fetched live from each provider's own API (its real
 * account/model list). The driver's seed list is only a fallback for when
 * the live call fails. Cached per provider id for the page's lifetime.
 */
export function useRemoteModels({ providers, seed, panelRoute, failedMessage }) {
    const cache = reactive({});

    const localSeedFor = (providerId) => {
        const provider = providers().find((p) => p.id === Number(providerId));
        return provider ? (seed()?.[provider.driver] || []) : [];
    };

    const fetchFor = async (providerId) => {
        const id = Number(providerId);
        if (!id || cache[id]) return;

        cache[id] = { status: 'loading', models: [], source: null, message: null };
        try {
            const { data } = await axios.get(panelRoute('ai-gateway.models.remote', { provider: id }));
            cache[id] = { status: 'loaded', models: data.models || [], source: data.source, message: data.message || null };
        } catch (e) {
            cache[id] = { status: 'error', models: localSeedFor(id), source: 'seed', message: e.response?.data?.message || failedMessage() };
        }
    };

    const infoFor = (providerId) => {
        const id = Number(providerId);
        if (!id) return { models: [], source: null, message: null, loading: false };

        const cached = cache[id];
        if (!cached) return { models: localSeedFor(id), source: null, message: null, loading: false };

        return {
            models: cached.models.length ? cached.models : localSeedFor(id),
            source: cached.source,
            message: cached.message,
            loading: cached.status === 'loading',
        };
    };

    return { fetchFor, infoFor };
}
