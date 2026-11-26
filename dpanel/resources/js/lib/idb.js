/*
 * A very small IndexedDB key/value store — one object store, get/set/delete.
 *
 * Why not localStorage: every read and write there is synchronous and blocks
 * the main thread, and the quota is a few megabytes shared with everything
 * else. The panel keeps cached payloads here (translation bundles are ~470 KB
 * each), which localStorage would not hold.
 *
 * Why no library: this is the whole surface the panel needs, and a dependency
 * for it would be bigger than the code.
 *
 * Every call resolves rather than throwing: a browser in private mode, with
 * storage blocked, or out of quota simply behaves as an empty cache, and the
 * caller falls back to the network.
 */
const DB_NAME = 'dpanel';
const DB_VERSION = 1;
const STORE = 'kv';

let connection = null;

const open = () => {
    if (connection) return connection;

    connection = new Promise((resolve) => {
        if (typeof indexedDB === 'undefined') {
            resolve(null);

            return;
        }

        let request;
        try {
            request = indexedDB.open(DB_NAME, DB_VERSION);
        } catch {
            resolve(null);

            return;
        }

        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE);
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => resolve(null);
        request.onblocked = () => resolve(null);
    });

    return connection;
};

const transact = async (mode, run) => {
    const db = await open();
    if (!db) return null;

    return new Promise((resolve) => {
        let tx;
        try {
            tx = db.transaction(STORE, mode);
        } catch {
            resolve(null);

            return;
        }

        const request = run(tx.objectStore(STORE));
        tx.onabort = () => resolve(null);
        tx.onerror = () => resolve(null);
        if (!request) {
            tx.oncomplete = () => resolve(true);

            return;
        }
        request.onsuccess = () => resolve(request.result ?? null);
        request.onerror = () => resolve(null);
    });
};

/** The stored value, or null when it is missing or storage is unavailable. */
export const idbGet = (key) => transact('readonly', (store) => store.get(key));

/** Stores a structured-cloneable value; resolves true, or null when it could not. */
export const idbSet = (key, value) => transact('readwrite', (store) => store.put(value, key));

export const idbDelete = (key) => transact('readwrite', (store) => store.delete(key));

/** Every key in the store, for pruning entries a new build left behind. */
export const idbKeys = async () => {
    const keys = await transact('readonly', (store) => store.getAllKeys());

    return Array.isArray(keys) ? keys.filter((key) => typeof key === 'string') : [];
};
