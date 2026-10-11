import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

/**
 * Shared state for the Mongo Admin page: where you are (server, database,
 * collection or document, kept in the URL like mongo-express), the database
 * list, and one `run(op)` that sends an operation through the panel.
 *
 * `scope` ({ id, name }) opens one database with its own login: every
 * request carries the id, and you never leave that database.
 */
export function useMongoAdmin(scope = null) {
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
            const { data } = await axios.post(panelRoute('databases.mongodb.admin.run'), scope ? { op, scope: scope.id } : { op });
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
        if (scope) params.set('scope', scope.id);
        if (filter && Object.keys(filter).length) params.set('filter', JSON.stringify(filter));
        return `${panelRoute('databases.mongodb.admin.export')}?${params}`;
    };

    // Where you are. `doc` is a document's _id as shell text, or 'new'.
    const readLocation = () => {
        const params = new URLSearchParams(window.location.search);
        return {
            db: scope ? scope.name : params.get('db') || '',
            collection: params.get('collection') || '',
            doc: params.get('doc') || '',
        };
    };
    const location = ref(readLocation());

    const go = (db = '', collection = '', doc = '') => {
        message.value = null;
        if (scope) db = scope.name;
        location.value = { db, collection: db ? collection : '', doc: db && collection ? doc : '' };
        const url = new URL(window.location.href);
        ['db', 'collection', 'doc'].forEach((key) => {
            if (location.value[key]) url.searchParams.set(key, location.value[key]);
            else url.searchParams.delete(key);
        });
        if (url.href !== window.location.href) window.history.pushState(window.history.state, '', url);
        window.scrollTo({ top: 0 });
    };
    const onPopState = () => { location.value = readLocation(); };

    const currentDatabase = computed(() => databases.value.find((db) => db.name === location.value.db) || null);

    return {
        panelRoute, databases, version, loading, unreachable, message, busy,
        run, attempt, refresh, exportUrl, location, go, onPopState, currentDatabase,
    };
}

export const formatBytes = (bytes) => {
    const n = toNumber(bytes);
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

/** A plain number from relaxed Extended JSON ({ $numberLong: "…" } and friends). */
export const toNumber = (value) => {
    if (value && typeof value === 'object') {
        value = value.$numberLong ?? value.$numberInt ?? value.$numberDouble ?? value.$numberDecimal ?? 0;
    }
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
};

export const formatCount = (value) => toNumber(value).toLocaleString();

export const formatDuration = (seconds) => {
    let s = Math.floor(toNumber(seconds));
    const days = Math.floor(s / 86400);
    s %= 86400;
    const hours = Math.floor(s / 3600);
    const minutes = Math.floor((s % 3600) / 60);
    return [days && `${days}d`, (days || hours) && `${hours}h`, `${minutes}m`].filter(Boolean).join(' ');
};

/** MongoDB's rules, checked before sending for a quicker message. */
export const validDatabaseName = (name) => /^[^/\\. "$\0]{1,63}$/.test(name);
export const validCollectionName = (name) => name.length > 0 && name.length <= 255 && !/[$\0]/.test(name) && !name.startsWith('system.');
