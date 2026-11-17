/**
 * Text transforms for the Transform workbench. Each one runs forward or, when
 * it has an inverse, backward, and carries its own option schema so a step can
 * be tuned (delimiter, casing, shift, …). Everything runs in the browser.
 *
 * A transform:
 *   { id, label, icon, options: [...], forward(s, o), inverse?(s, o) }
 * An option: { key, label, type: 'toggle'|'select'|'number', default, choices?, min?, max? }
 */

const utf8 = (s) => new TextEncoder().encode(s);
const fromUtf8 = (bytes) => new TextDecoder('utf-8', { fatal: true }).decode(bytes);
const bytesToBinary = (bytes) => Array.from(bytes, (b) => String.fromCharCode(b)).join('');
const binaryToBytes = (bin) => Uint8Array.from(bin, (c) => c.charCodeAt(0));

const DELIMS = { none: '', space: ' ', comma: ', ', colon: ':', dash: '-', newline: '\n' };
const splitRe = /[\s,:\-]+/;

function wrap(s, width) {
    const n = Number(width) || 0;
    if (n <= 0) return s;
    return s.replace(new RegExp(`(.{${n}})`, 'g'), '$1\n').replace(/\n$/, '');
}

function rot(s, shift) {
    return s.replace(/[a-z]/gi, (ch) => {
        const base = ch <= 'Z' ? 65 : 97;
        return String.fromCharCode(((ch.charCodeAt(0) - base + shift + 26) % 26) + base);
    });
}

const MORSE = {
    a: '.-', b: '-...', c: '-.-.', d: '-..', e: '.', f: '..-.', g: '--.', h: '....', i: '..', j: '.---',
    k: '-.-', l: '.-..', m: '--', n: '-.', o: '---', p: '.--.', q: '--.-', r: '.-.', s: '...', t: '-',
    u: '..-', v: '...-', w: '.--', x: '-..-', y: '-.--', z: '--..',
    0: '-----', 1: '.----', 2: '..---', 3: '...--', 4: '....-', 5: '.....', 6: '-....', 7: '--...', 8: '---..', 9: '----.',
    '.': '.-.-.-', ',': '--..--', '?': '..--..', '!': '-.-.--', '/': '-..-.', '@': '.--.-.', '-': '-....-', '(': '-.--.', ')': '-.--.-',
};
const MORSE_REV = Object.fromEntries(Object.entries(MORSE).map(([k, v]) => [v, k]));

export const TRANSFORMS = [
    {
        id: 'base64', label: 'Base64', icon: 'bi-file-binary',
        options: [
            { key: 'urlsafe', label: 'URL-safe alphabet', type: 'toggle', default: false },
            { key: 'padding', label: 'Keep = padding', type: 'toggle', default: true },
            { key: 'wrap', label: 'Wrap width (0 = off)', type: 'number', default: 0, min: 0, max: 200 },
        ],
        forward(s, o) {
            let out = btoa(bytesToBinary(utf8(s)));
            if (o.urlsafe) out = out.replace(/\+/g, '-').replace(/\//g, '_');
            if (!o.padding || o.urlsafe) out = out.replace(/=+$/, '');
            return wrap(out, o.wrap);
        },
        inverse(s) {
            let b = s.replace(/\s+/g, '').replace(/-/g, '+').replace(/_/g, '/');
            while (b.length % 4) b += '=';
            return fromUtf8(binaryToBytes(atob(b)));
        },
    },
    {
        id: 'hex', label: 'Hex', icon: 'bi-hexagon',
        options: [
            { key: 'upper', label: 'Uppercase', type: 'toggle', default: false },
            { key: 'delim', label: 'Separator', type: 'select', default: 'none', choices: ['none', 'space', 'colon', 'dash'] },
            { key: 'prefix', label: 'Add 0x prefix', type: 'toggle', default: false },
        ],
        forward(s, o) {
            let parts = Array.from(utf8(s), (b) => b.toString(16).padStart(2, '0'));
            if (o.upper) parts = parts.map((h) => h.toUpperCase());
            if (o.prefix) parts = parts.map((h) => '0x' + h);
            return parts.join(DELIMS[o.delim] ?? '');
        },
        inverse(s) {
            const clean = s.replace(/0x/gi, '').replace(/[\s,:\-]+/g, '');
            if (!/^([0-9a-f]{2})*$/i.test(clean)) throw new Error('hex');
            return fromUtf8(Uint8Array.from(clean.match(/../g) || [], (h) => parseInt(h, 16)));
        },
    },
    {
        id: 'binary', label: 'Binary', icon: 'bi-1-square',
        options: [{ key: 'delim', label: 'Separator', type: 'select', default: 'space', choices: ['space', 'none', 'comma'] }],
        forward(s, o) {
            return Array.from(utf8(s), (b) => b.toString(2).padStart(8, '0')).join(DELIMS[o.delim] ?? ' ');
        },
        inverse(s) {
            const parts = s.trim().split(splitRe).filter(Boolean);
            if (parts.some((p) => !/^[01]{1,8}$/.test(p))) throw new Error('binary');
            return fromUtf8(Uint8Array.from(parts, (p) => parseInt(p, 2)));
        },
    },
    {
        id: 'decimal', label: 'Decimal bytes', icon: 'bi-123',
        options: [{ key: 'delim', label: 'Separator', type: 'select', default: 'space', choices: ['space', 'comma', 'dash'] }],
        forward(s, o) {
            return Array.from(utf8(s), (b) => String(b)).join(DELIMS[o.delim] ?? ' ');
        },
        inverse(s) {
            const parts = s.trim().split(splitRe).filter(Boolean);
            if (parts.some((p) => !/^\d{1,3}$/.test(p) || Number(p) > 255)) throw new Error('decimal');
            return fromUtf8(Uint8Array.from(parts, (p) => Number(p)));
        },
    },
    {
        id: 'url', label: 'URL', icon: 'bi-link-45deg',
        options: [{ key: 'mode', label: 'Scope', type: 'select', default: 'component', choices: ['component', 'full'] }],
        forward(s, o) { return o.mode === 'full' ? encodeURI(s) : encodeURIComponent(s); },
        inverse(s, o) { return o.mode === 'full' ? decodeURI(s) : decodeURIComponent(s.replace(/\+/g, ' ')); },
    },
    {
        id: 'html', label: 'HTML entities', icon: 'bi-code-slash',
        options: [],
        forward(s) { return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); },
        inverse(s) { return new DOMParser().parseFromString(`<!doctype html><body>${s}`, 'text/html').body.textContent || ''; },
    },
    {
        id: 'case', label: 'Change case', icon: 'bi-type',
        options: [{ key: 'mode', label: 'Style', type: 'select', default: 'upper', choices: ['upper', 'lower', 'title', 'sentence', 'toggle', 'snake', 'kebab', 'camel'] }],
        forward(s, o) {
            switch (o.mode) {
                case 'lower': return s.toLowerCase();
                case 'title': return s.replace(/\b\w/g, (c) => c.toUpperCase());
                case 'sentence': return s.replace(/(^\s*\w|[.!?]\s+\w)/g, (c) => c.toUpperCase());
                case 'toggle': return s.replace(/[a-z]/gi, (c) => (c === c.toLowerCase() ? c.toUpperCase() : c.toLowerCase()));
                case 'snake': return s.trim().replace(/\s+/g, '_').toLowerCase();
                case 'kebab': return s.trim().replace(/\s+/g, '-').toLowerCase();
                case 'camel': return s.trim().toLowerCase().replace(/\s+(\w)/g, (_, c) => c.toUpperCase());
                default: return s.toUpperCase();
            }
        },
    },
    {
        id: 'reverse', label: 'Reverse', icon: 'bi-arrow-left-right',
        options: [{ key: 'unit', label: 'By', type: 'select', default: 'chars', choices: ['chars', 'words', 'lines'] }],
        forward(s, o) {
            if (o.unit === 'words') return s.split(/(\s+)/).reverse().join('');
            if (o.unit === 'lines') return s.split('\n').reverse().join('\n');
            return Array.from(s).reverse().join('');
        },
    },
    {
        id: 'caesar', label: 'Caesar shift', icon: 'bi-shift',
        options: [{ key: 'shift', label: 'Shift', type: 'number', default: 3, min: 1, max: 25 }],
        forward(s, o) { return rot(s, Number(o.shift) || 0); },
        inverse(s, o) { return rot(s, -(Number(o.shift) || 0)); },
    },
    {
        id: 'rot13', label: 'ROT13', icon: 'bi-arrow-repeat', options: [],
        forward(s) { return rot(s, 13); }, inverse(s) { return rot(s, 13); },
    },
    {
        id: 'rot47', label: 'ROT47', icon: 'bi-arrow-repeat', options: [],
        forward(s) { return s.replace(/[!-~]/g, (c) => String.fromCharCode(33 + ((c.charCodeAt(0) - 33 + 47) % 94))); },
        inverse(s) { return this.forward(s); },
    },
    {
        id: 'morse', label: 'Morse code', icon: 'bi-broadcast',
        options: [],
        forward(s) {
            return s.toLowerCase().split('').map((c) => (c === ' ' ? '/' : (MORSE[c] ?? ''))).filter((x) => x !== '').join(' ');
        },
        inverse(s) {
            return s.trim().split(/\s+/).map((code) => (code === '/' ? ' ' : (MORSE_REV[code] ?? ''))).join('');
        },
    },
    {
        id: 'whitespace', label: 'Whitespace', icon: 'bi-text-indent-left',
        options: [{ key: 'mode', label: 'Action', type: 'select', default: 'collapse', choices: ['collapse', 'stripAll', 'trimLines'] }],
        forward(s, o) {
            if (o.mode === 'stripAll') return s.replace(/\s+/g, '');
            if (o.mode === 'trimLines') return s.split('\n').map((l) => l.trim()).join('\n');
            return s.replace(/[^\S\n]+/g, ' ').replace(/ *\n */g, '\n').trim();
        },
    },
];

export const TRANSFORM_MAP = Object.fromEntries(TRANSFORMS.map((t) => [t.id, t]));

/** An option object filled with its defaults, for a fresh step. */
export function defaultOptions(transformId) {
    const t = TRANSFORM_MAP[transformId];
    const out = {};
    for (const opt of t?.options || []) out[opt.key] = opt.default;
    return out;
}

/**
 * Run a chain of steps over the input. Each step is { id, dir, opts }; `dir`
 * is 'forward' or 'inverse'. Returns per-step output and errors so the UI can
 * show where a chain broke without throwing the whole thing away.
 */
export function runChain(input, steps) {
    let value = input;
    const trail = [];
    for (const step of steps) {
        const transform = TRANSFORM_MAP[step.id];
        if (!transform) { trail.push({ ok: false, value: '', error: 'unknown' }); break; }
        const fn = step.dir === 'inverse' && transform.inverse ? transform.inverse : transform.forward;
        try {
            value = fn.call(transform, value, step.opts || {});
            trail.push({ ok: true, value });
        } catch {
            trail.push({ ok: false, value: '', error: 'failed' });
            return { output: '', trail, failed: true };
        }
    }
    return { output: value, trail, failed: false };
}
