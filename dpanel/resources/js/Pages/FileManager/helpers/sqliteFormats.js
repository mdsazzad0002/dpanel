// Format conversions for the SQLite row editor. Each takes the field's
// current text and returns new text (or a Promise of it). Hashes run on the
// server (`server: true`) so they match what PHP apps produce.

const pad = (n) => String(n).padStart(2, '0');
const sqlDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;

function toInteger(text) {
    const n = Number(String(text).trim());
    if (String(text).trim() === '' || !Number.isFinite(n)) throw new Error('Not a number.');
    return String(Math.trunc(n));
}

function toReal(text) {
    const n = Number(String(text).trim());
    if (String(text).trim() === '' || !Number.isFinite(n)) throw new Error('Not a number.');
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
        throw new Error('Not valid Base64.');
    }
}

function json(text, space) {
    try {
        return JSON.stringify(JSON.parse(text), null, space);
    } catch {
        throw new Error('Not valid JSON.');
    }
}

function unixToDate(text) {
    let n = Number(String(text).trim());
    if (!Number.isFinite(n)) throw new Error('Not a Unix timestamp.');
    if (n > 1e12) n /= 1000; // milliseconds
    return sqlDate(new Date(n * 1000));
}

function dateToUnix(text) {
    const d = new Date(String(text).trim().replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) throw new Error('Not a date.');
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
        label: 'Type',
        items: [
            { id: 'text', label: 'Text', type: 'text', apply: (t) => t },
            { id: 'int', label: 'Integer', type: 'integer', apply: toInteger },
            { id: 'real', label: 'Real (decimal)', type: 'real', apply: toReal },
            { id: 'null', label: 'NULL', type: 'null', apply: (t) => t },
        ],
    },
    {
        label: 'Hash',
        items: [
            { id: 'md5', label: 'MD5', server: true },
            { id: 'sha1', label: 'SHA-1', server: true },
            { id: 'sha256', label: 'SHA-256', server: true },
            { id: 'sha512', label: 'SHA-512', server: true },
            { id: 'crc32', label: 'CRC32', server: true },
            { id: 'bcrypt', label: 'bcrypt (password)', server: true },
            { id: 'argon2id', label: 'Argon2id (password)', server: true },
        ],
    },
    {
        label: 'Text',
        items: [
            { id: 'upper', label: 'UPPERCASE', apply: (t) => t.toUpperCase() },
            { id: 'lower', label: 'lowercase', apply: (t) => t.toLowerCase() },
            { id: 'trim', label: 'Trim spaces', apply: (t) => t.trim() },
            { id: 'slug', label: 'Slug', apply: (t) => t.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') },
            { id: 'empty', label: 'Empty string', type: 'text', apply: () => '' },
        ],
    },
    {
        label: 'Encode',
        items: [
            { id: 'b64e', label: 'Base64 encode', apply: base64Encode },
            { id: 'b64d', label: 'Base64 decode', apply: base64Decode },
            { id: 'urle', label: 'URL encode', apply: (t) => encodeURIComponent(t) },
            { id: 'urld', label: 'URL decode', apply: (t) => decodeURIComponent(t) },
            { id: 'jsonp', label: 'JSON pretty', apply: (t) => json(t, 2) },
            { id: 'jsonm', label: 'JSON minify', apply: (t) => json(t) },
        ],
    },
    {
        label: 'Date & ID',
        items: [
            { id: 'now', label: 'Now (YYYY-MM-DD HH:MM:SS)', type: 'text', apply: () => sqlDate(new Date()) },
            { id: 'nowunix', label: 'Now (Unix timestamp)', type: 'integer', apply: () => String(Math.floor(Date.now() / 1000)) },
            { id: 'unix2date', label: 'Unix timestamp → date', type: 'text', apply: unixToDate },
            { id: 'date2unix', label: 'Date → Unix timestamp', type: 'integer', apply: dateToUnix },
            { id: 'uuid', label: 'New UUID v4', type: 'text', apply: uuid },
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
        if (String(text).trim() === '' || !Number.isFinite(n)) throw new Error(`"${text}" is not a number.`);
        // Past 2^53 a JS number loses digits; send the text and let SQLite convert.
        if (type === 'integer' && !Number.isSafeInteger(Math.trunc(n))) return String(text).trim();
        return type === 'integer' ? Math.trunc(n) : n;
    }
    return String(text);
}
