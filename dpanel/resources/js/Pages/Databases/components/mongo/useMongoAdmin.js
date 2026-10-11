import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

/**
 * Shared state for the Mongo Admin page: the database tree, what is
 * selected, and one `run(op)` that sends an operation through the panel.
 */
export function useMongoAdmin() {
    const page = usePage();
    const panelToken = computed(() => String(page.props.panel?.token || ''));
    const panelRoute = (name, params = {}) => (
        panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
    );

    const databases = ref([]);
    const version = ref(null);
    const loading = ref(false);
    const unreachable = ref('');
    const message = ref(null);
    const busy = ref('');

    const errorText = (e) => e.response?.data?.message
        || (e.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(' ') : '')
        || e.message
        || 'Request failed.';

    /**
     * Runs one operation. Resolves to its data, or throws with a readable
     * message. `notify` shows the server's message as a success banner.
     */
    const run = async (op, { notify = false, key = op.action } = {}) => {
        busy.value = key;
        try {
            const { data } = await axios.post(panelRoute('databases.mongodb.admin.run'), { op });
            if (notify) message.value = { type: 'success', text: data.message };
            return data.data ?? {};
        } catch (e) {
            throw new Error(errorText(e));
        } finally {
            busy.value = '';
        }
    };

    /** Like run(), but shows a failure as the page banner instead of throwing. */
    const attempt = async (op, options = {}) => {
        try {
            return await run(op, options);
        } catch (e) {
            message.value = { type: 'error', text: e.message };
            return null;
        }
    };

    const refresh = async () => {
        loading.value = true;
        try {
            const data = await run({ action: 'overview' }, { key: 'overview' });
            databases.value = data.databases || [];
            version.value = data.version || null;
            unreachable.value = '';
        } catch (e) {
            unreachable.value = e.message;
        } finally {
            loading.value = false;
        }
    };

    const exportUrl = (db, collection, filter) => {
        const params = new URLSearchParams({ db, collection });
        if (filter && Object.keys(filter).length) params.set('filter', JSON.stringify(filter));
        return `${panelRoute('databases.mongodb.admin.export')}?${params}`;
    };

    return { panelRoute, databases, version, loading, unreachable, message, busy, run, attempt, refresh, exportUrl };
}

export const formatBytes = (bytes) => {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = n / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }
    return `${value.toFixed(value < 10 ? 1 : 0)} ${units[unit]}`;
};

/** MongoDB's rules, checked before sending for a quicker message. */
export const validDatabaseName = (name) => /^[^/\\. "$\0]{1,63}$/.test(name);
export const validCollectionName = (name) => name.length > 0 && name.length <= 255 && !/[$\0]/.test(name) && !name.startsWith('system.');
