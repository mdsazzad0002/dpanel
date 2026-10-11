// Converts between what people type in the MongoDB shell and the relaxed
// Extended JSON the API speaks. Accepted input is JSON plus shell niceties:
// unquoted keys, 'single quotes', trailing commas, // comments, /regex/flags,
// ObjectId("…"), ISODate("…"), new Date("…"), NumberLong(…), NumberInt(…),
// NumberDecimal("…"), Timestamp(t, i), UUID("…"), BinData(n, "…"), MinKey, MaxKey.

export class MongoSyntaxError extends Error {}

export function parseMongo(text) {
    const src = String(text ?? '');
    let i = 0;

    const fail = (message) => {
        const line = src.slice(0, i).split('\n').length;
        throw new MongoSyntaxError(`${message} (line ${line})`);
    };

    const skip = () => {
        for (;;) {
            while (i < src.length && /\s/.test(src[i])) i++;
            if (src.startsWith('//', i)) {
                while (i < src.length && src[i] !== '\n') i++;
            } else if (src.startsWith('/*', i)) {
                const end = src.indexOf('*/', i + 2);
                i = end < 0 ? src.length : end + 2;
            } else {
                return;
            }
        }
    };

    const expect = (ch) => {
        skip();
        if (src[i] !== ch) fail(`Expected "${ch}"`);
        i++;
    };

    const string = () => {
        const quote = src[i++];
        let out = '';
        while (i < src.length && src[i] !== quote) {
            if (src[i] === '\\') {
                const next = src[++i];
                const map = { n: '\n', t: '\t', r: '\r', b: '\b', f: '\f', '0': '\0' };
                if (next === 'u') {
                    out += String.fromCharCode(parseInt(src.slice(i + 1, i + 5), 16));
                    i += 5;
                    continue;
                }
                out += map[next] ?? next;
                i++;
            } else {
                out += src[i++];
            }
        }
        if (src[i] !== quote) fail('Unterminated string');
        i++;
        return out;
    };

    const identifier = () => {
        const match = /^[A-Za-z_$][\w$.]*/.exec(src.slice(i));
        if (!match) fail(`Unexpected "${src[i] ?? 'end of input'}"`);
        i += match[0].length;
        return match[0];
    };

    const number = () => {
        const match = /^-?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/.exec(src.slice(i));
        if (!match) fail('Invalid number');
        i += match[0].length;
        const value = Number(match[0]);
        if (Number.isInteger(value) && !Number.isSafeInteger(value)) return { $numberLong: match[0] };
        return value;
    };

    const regex = () => {
        i++;
        let pattern = '';
        let inClass = false;
        while (i < src.length && (src[i] !== '/' || inClass)) {
            if (src[i] === '\\') pattern += src[i++];
            else if (src[i] === '[') inClass = true;
            else if (src[i] === ']') inClass = false;
            pattern += src[i++];
        }
        if (src[i] !== '/') fail('Unterminated regular expression');
        i++;
        const flags = /^[a-z]*/.exec(src.slice(i))[0];
        i += flags.length;
        return { $regularExpression: { pattern, options: flags.split('').sort().join('') } };
    };

    const args = () => {
        expect('(');
        const list = [];
        skip();
        while (src[i] !== ')') {
            list.push(value());
            skip();
            if (src[i] === ',') i++;
            else if (src[i] !== ')') fail('Expected "," or ")"');
            skip();
        }
        i++;
        return list;
    };

    const hexToBase64 = (hex) => {
        const clean = String(hex).replace(/-/g, '');
        if (!/^[0-9a-fA-F]{32}$/.test(clean)) fail('UUID needs 32 hex digits');
        const bytes = clean.match(/../g).map((pair) => parseInt(pair, 16));
        return btoa(String.fromCharCode(...bytes));
    };

    const isoDate = (arg) => {
        const date = arg === undefined ? new Date() : new Date(typeof arg === 'object' && arg?.$numberLong ? Number(arg.$numberLong) : arg);
        if (Number.isNaN(date.getTime())) fail('Invalid date');
        return { $date: date.toISOString() };
    };

    const helper = (name) => {
        switch (name) {
            case 'true': return true;
            case 'false': return false;
            case 'null':
            case 'undefined': return null;
            case 'MinKey': return { $minKey: 1 };
            case 'MaxKey': return { $maxKey: 1 };
            case 'new': skip(); return helper(identifier());
            default: break;
        }
        const list = args();
        switch (name) {
            case 'ObjectId':
                if (!/^[0-9a-fA-F]{24}$/.test(String(list[0] ?? ''))) fail('ObjectId needs 24 hex digits');
                return { $oid: String(list[0]).toLowerCase() };
            case 'ISODate':
            case 'Date':
                return isoDate(list[0]);
            case 'NumberLong':
                return { $numberLong: String(list[0] ?? 0) };
            case 'NumberInt':
                return { $numberInt: String(parseInt(list[0] ?? 0, 10)) };
            case 'NumberDecimal':
                return { $numberDecimal: String(list[0] ?? 0) };
            case 'Timestamp':
                return { $timestamp: { t: Number(list[0] ?? 0), i: Number(list[1] ?? 0) } };
            case 'UUID':
                return { $binary: { base64: hexToBase64(list[0]), subType: '04' } };
            case 'BinData':
                return { $binary: { base64: String(list[1] ?? ''), subType: Number(list[0] ?? 0).toString(16).padStart(2, '0') } };
            default:
                return fail(`Unknown helper ${name}()`);
        }
    };

    const object = () => {
        i++;
        const out = {};
        skip();
        while (src[i] !== '}') {
            if (i >= src.length) fail('Unterminated object');
            const key = src[i] === '"' || src[i] === "'" ? string() : identifier();
            expect(':');
            out[key] = value();
            skip();
            if (src[i] === ',') i++;
            else if (src[i] !== '}') fail('Expected "," or "}"');
            skip();
        }
        i++;
        return out;
    };

    const array = () => {
        i++;
        const out = [];
        skip();
        while (src[i] !== ']') {
            if (i >= src.length) fail('Unterminated array');
            out.push(value());
            skip();
            if (src[i] === ',') i++;
            else if (src[i] !== ']') fail('Expected "," or "]"');
            skip();
        }
        i++;
        return out;
    };

    function value() {
        skip();
        const ch = src[i];
        if (ch === '{') return object();
        if (ch === '[') return array();
        if (ch === '"' || ch === "'") return string();
        if (ch === '/') return regex();
        if (ch === '-' || ch === '.' || (ch >= '0' && ch <= '9')) return number();
        if (ch === undefined) return fail('Unexpected end of input');
        return helper(identifier());
    }

    skip();
    if (i >= src.length) return null;
    const result = value();
    skip();
    if (i < src.length) fail(`Unexpected "${src[i]}" after the value`);
    return result;
}

/** Parse, requiring an object (filters, documents, commands). Empty text gives {}. */
export function parseMongoObject(text, what = 'value') {
    const value = parseMongo(text);
    if (value === null) return {};
    if (typeof value !== 'object' || Array.isArray(value)) throw new MongoSyntaxError(`The ${what} must be an object like { … }.`);
    return value;
}

const indentText = (level) => '  '.repeat(level);
const quote = (s) => JSON.stringify(s);
const KEY_OK = /^[A-Za-z_$][\w$]*$/;

const base64ToHex = (b64) => Array.from(atob(b64), (c) => c.charCodeAt(0).toString(16).padStart(2, '0')).join('');

/** One Extended JSON wrapper as shell syntax, or null if `value` isn't one. */
function helperText(value) {
    const keys = Object.keys(value);
    if (keys.length === 1) {
        const [key] = keys;
        const inner = value[key];
        switch (key) {
            case '$oid': return `ObjectId(${quote(inner)})`;
            case '$date':
                if (typeof inner === 'string') return `ISODate(${quote(inner)})`;
                if (inner?.$numberLong) return `ISODate(${quote(new Date(Number(inner.$numberLong)).toISOString())})`;
                break;
            case '$numberLong': return `NumberLong(${quote(inner)})`;
            case '$numberInt': return `NumberInt(${inner})`;
            case '$numberDecimal': return `NumberDecimal(${quote(inner)})`;
            case '$numberDouble': return String(inner);
            case '$minKey': return 'MinKey';
            case '$maxKey': return 'MaxKey';
            case '$timestamp': return `Timestamp(${inner.t}, ${inner.i})`;
            case '$regularExpression': return `/${inner.pattern}/${inner.options || ''}`;
            case '$binary':
                if (inner.subType === '04') {
                    const hex = base64ToHex(inner.base64);
                    return `UUID(${quote(`${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`)})`;
                }
                return `BinData(${parseInt(inner.subType, 16)}, ${quote(inner.base64)})`;
            default: break;
        }
    }
    return null;
}

/** Extended JSON as readable, editable shell syntax (parseMongo reads it back). */
export function toShell(value, level = 0) {
    if (value === null || value === undefined) return 'null';
    if (Array.isArray(value)) {
        if (value.length === 0) return '[]';
        return `[\n${value.map((item) => indentText(level + 1) + toShell(item, level + 1)).join(',\n')}\n${indentText(level)}]`;
    }
    if (typeof value === 'object') {
        const helper = helperText(value);
        if (helper !== null) return helper;
        const keys = Object.keys(value);
        if (keys.length === 0) return '{}';
        return `{\n${keys.map((key) => `${indentText(level + 1)}${KEY_OK.test(key) ? key : quote(key)}: ${toShell(value[key], level + 1)}`).join(',\n')}\n${indentText(level)}}`;
    }
    if (typeof value === 'string') return quote(value);
    return String(value);
}

/** A one-line preview for table cells. */
export function preview(value, max = 60) {
    let text;
    if (value === undefined) return '';
    if (value !== null && typeof value === 'object' && !Array.isArray(value) && helperText(value) !== null) text = helperText(value);
    else if (Array.isArray(value)) text = `Array(${value.length})`;
    else if (value !== null && typeof value === 'object') text = `{ ${Object.keys(value).slice(0, 3).join(', ')}${Object.keys(value).length > 3 ? ', …' : ''} }`;
    else text = typeof value === 'string' ? value : String(value);
    return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}

const escapeHtml = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** Shell text with spans for keys, strings, numbers and helpers (escaped, safe for v-html). */
export function highlight(text) {
    const pattern = /("(?:[^"\\]|\\.)*")(\s*:)?|\b(ObjectId|ISODate|NumberLong|NumberInt|NumberDecimal|Timestamp|UUID|BinData|MinKey|MaxKey)\b|\b(true|false|null)\b|(-?\d+\.?\d*(?:[eE][+-]?\d+)?)|^(\s*)([A-Za-z_$][\w$]*)(?=:)/gm;
    let out = '';
    let last = 0;
    String(text).replace(pattern, (match, str, colon, helper, literal, num, lead, key, offset) => {
        out += escapeHtml(text.slice(last, offset));
        if (str !== undefined) out += colon ? `<span class="mj-key">${escapeHtml(str)}</span>${colon}` : `<span class="mj-str">${escapeHtml(str)}</span>`;
        else if (helper) out += `<span class="mj-fn">${helper}</span>`;
        else if (literal) out += `<span class="mj-lit">${literal}</span>`;
        else if (num !== undefined) out += `<span class="mj-num">${num}</span>`;
        else if (key) out += `${lead}<span class="mj-key">${escapeHtml(key)}</span>`;
        last = offset + match.length;
        return match;
    });
    return out + escapeHtml(text.slice(last));
}
