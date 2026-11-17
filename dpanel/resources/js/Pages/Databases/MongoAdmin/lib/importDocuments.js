import { parseMongo } from './mongoJson';
import { i18n } from '@/i18n';

const t = (...args) => i18n.global.t(...args);

// A JSON array, one document, or one document per line (mongoexport's default).
export function readImportFile(text) {
    try {
        const value = JSON.parse(text);
        return Array.isArray(value) ? value : [value];
    } catch {
        // fall through
    }
    try {
        const value = parseMongo(text);
        return Array.isArray(value) ? value : [value];
    } catch {
        // fall through
    }
    return text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean).map((line, index) => {
        try {
            return JSON.parse(line);
        } catch {
            throw new Error(t('databases.mongoAdmin.import.lineInvalid', { line: index + 1 }));
        }
    });
}

/**
 * Inserts the documents in small batches that stay under the panel's upload
 * limit. `progress(done, total)` is called after each batch. Resolves to the
 * number inserted; on failure throws with how far it got.
 */
export async function importDocuments(run, db, collection, docs, progress = () => {}) {
    if (!docs.length || docs.some((doc) => !doc || typeof doc !== 'object' || Array.isArray(doc))) {
        throw new Error(t('databases.mongoAdmin.import.notDocuments'));
    }
    let done = 0;
    let batch = [];
    let size = 0;
    const flush = async () => {
        if (!batch.length) return;
        await run({ action: 'insert', db, collection, documents: batch }, { key: 'import' });
        done += batch.length;
        progress(done, docs.length);
        batch = [];
        size = 0;
    };
    try {
        for (const doc of docs) {
            const bytes = JSON.stringify(doc).length;
            if (batch.length >= 1000 || size + bytes > 3_000_000) await flush();
            batch.push(doc);
            size += bytes;
        }
        await flush();
    } catch (e) {
        throw new Error(t('databases.mongoAdmin.import.stopped', { count: done.toLocaleString(), error: e.message }));
    }
    return done;
}
