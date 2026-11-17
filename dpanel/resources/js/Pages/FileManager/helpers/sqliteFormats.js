// Format conversions for the SQLite row editor. Each takes the field's
// current text and returns new text (or a Promise of it). Hashes run on the
// server (`server: true`) so they match what PHP apps produce.

import { i18n } from '@/i18n';

const t = (...args) => i18n.global.t(...args);

const pad = (n) => String(n).padStart(2, '0');
const sqlDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;

function toInteger(text) {
    const n = Number(String(text).trim());
    if (String(text).trim() === '' || !Number.isFinite(n)) throw new Error(t('fileManager.formats.errors.notNumber'));
    return String(Math.trunc(n));
}

function toReal(text) {
    const n = Number(String(text).trim());
    if (String(text).trim() === '' || !Number.isFinite(n)) throw new Error(t('fileManager.formats.errors.notNumber'));
    return String(n);
}

function base64Encode(text) {
    const bytes = new TextEncoder().encode(text);
    let binary = '';
    bytes.forEach((b) => { binary += String.fromCharCode(b); });
    return btoa(binary);
}

function base64Decode(text) {
    try {
        const binary = atob(String(text).trim());
        return new TextDecoder().decode(Uint8Array.from(binary, (c) => c.charCodeAt(0)));
    } catch {
        throw new Error(t('fileManager.formats.errors.notBase64'));
    }
}

function json(text, space) {
    try {
        return JSON.stringify(JSON.parse(text), null, space);
    } catch {
        throw new Error(t('fileManager.formats.errors.notJson'));
    }
}

function unixToDate(text) {
    let n = Number(String(text).trim());
    if (!Number.isFinite(n)) throw new Error(t('fileManager.formats.errors.notUnix'));
    if (n > 1e12) n /= 1000; // milliseconds
    return sqlDate(new Date(n * 1000));
}

function dateToUnix(text) {
    const d = new Date(String(text).trim().replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) throw new Error(t('fileManager.formats.errors.notDate'));
    return String(Math.floor(d.getTime() / 1000));
}

function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

// `type` also switches the field's "save as" type.
export const FORMAT_GROUPS = [
    {
        id: 'type',
        get label() { return t('fileManager.formats.groups.type'); },
        items: [
            { id: 'text', get label() { return t('fileManager.formats.items.text'); }, type: 'text', apply: (s) => s },
            { id: 'int', get label() { return t('fileManager.formats.items.int'); }, type: 'integer', apply: toInteger },
            { id: 'real', get label() { return t('fileManager.formats.items.real'); }, type: 'real', apply: toReal },
            { id: 'null', label: 'NULL', type: 'null', apply: (s) => s },
        ],
    },
    {
        id: 'hash',
        get label() { return t('fileManager.formats.groups.hash'); },
        items: [
            { id: 'md5', label: 'MD5', server: true },
            { id: 'sha1', label: 'SHA-1', server: true },
            { id: 'sha256', label: 'SHA-256', server: true },
            { id: 'sha512', label: 'SHA-512', server: true },
            { id: 'crc32', label: 'CRC32', server: true },
            { id: 'bcrypt', get label() { return t('fileManager.formats.items.bcrypt'); }, server: true },
            { id: 'argon2id', get label() { return t('fileManager.formats.items.argon2id'); }, server: true },
        ],
    },
    {
        id: 'text',
        get label() { return t('fileManager.formats.groups.text'); },
        items: [
            { id: 'upper', get label() { return t('fileManager.formats.items.upper'); }, apply: (s) => s.toUpperCase() },
            { id: 'lower', get label() { return t('fileManager.formats.items.lower'); }, apply: (s) => s.toLowerCase() },
            { id: 'trim', get label() { return t('fileManager.formats.items.trim'); }, apply: (s) => s.trim() },
            { id: 'slug', get label() { return t('fileManager.formats.items.slug'); }, apply: (s) => s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') },
            { id: 'empty', get label() { return t('fileManager.formats.items.empty'); }, type: 'text', apply: () => '' },
        ],
    },
    {
        id: 'encode',
        get label() { return t('fileManager.formats.groups.encode'); },
        items: [
            { id: 'b64e', get label() { return t('fileManager.formats.items.b64e'); }, apply: base64Encode },
            { id: 'b64d', get label() { return t('fileManager.formats.items.b64d'); }, apply: base64Decode },
            { id: 'urle', get label() { return t('fileManager.formats.items.urle'); }, apply: (s) => encodeURIComponent(s) },
            { id: 'urld', get label() { return t('fileManager.formats.items.urld'); }, apply: (s) => decodeURIComponent(s) },
            { id: 'jsonp', get label() { return t('fileManager.formats.items.jsonp'); }, apply: (s) => json(s, 2) },
            { id: 'jsonm', get label() { return t('fileManager.formats.items.jsonm'); }, apply: (s) => json(s) },
        ],
    },
    {
        id: 'dateId',
        get label() { return t('fileManager.formats.groups.dateId'); },
        items: [
            { id: 'now', get label() { return t('fileManager.formats.items.now'); }, type: 'text', apply: () => sqlDate(new Date()) },
            { id: 'nowunix', get label() { return t('fileManager.formats.items.nowunix'); }, type: 'integer', apply: () => String(Math.floor(Date.now() / 1000)) },
            { id: 'unix2date', get label() { return t('fileManager.formats.items.unix2date'); }, type: 'text', apply: unixToDate },
            { id: 'date2unix', get label() { return t('fileManager.formats.items.date2unix'); }, type: 'integer', apply: dateToUnix },
            { id: 'uuid', get label() { return t('fileManager.formats.items.uuid'); }, type: 'text', apply: uuid },
        ],
    },
];

export const FORMATS = Object.fromEntries(FORMAT_GROUPS.flatMap((g) => g.items).map((f) => [f.id, f]));

/** The "save as" type a stored value starts with. */
export function typeOf(value) {
    if (value === null) return 'null';
    if (typeof value === 'number') return Number.isInteger(value) ? 'integer' : 'real';
    return 'text';
}

/** The JSON value sent to drust for a field's text and type. */
export function valueFor(text, type) {
    if (type === 'null') return null;
    if (type === 'integer' || type === 'real') {
        const n = Number(String(text).trim());
        if (String(text).trim() === '' || !Number.isFinite(n)) throw new Error(t('fileManager.formats.errors.valueNotNumber', { value: text }));
        // Past 2^53 a JS number loses digits; send the text and let SQLite convert.
        if (type === 'integer' && !Number.isSafeInteger(Math.trunc(n))) return String(text).trim();
        return type === 'integer' ? Math.trunc(n) : n;
    }
    return String(text);
}
