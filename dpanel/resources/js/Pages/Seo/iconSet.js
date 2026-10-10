// Builds a favicon / PWA / share-image set from one source image, entirely in
// the browser: canvas does the resizing (and SVG and any script for text),
// so the server only stores the finished files when asked to install them.

// Every generated image: who uses it and how it is drawn.
//   opaque: filled with the background color (iOS shows transparency as black)
//   maskable: logo kept inside the safe zone Android crops to a circle/squircle
export const IMAGES = [
    { name: 'favicon.ico', group: 'favicon', sizes: [16, 32, 48], width: 48, height: 48, use: 'Browsers, Bing and Yandex request it at the site root' },
    { name: 'favicon.svg', group: 'favicon', vectorOnly: true, use: 'Modern browsers; sharp at every size' },
    { name: 'favicon-16x16.png', group: 'favicon', width: 16, height: 16, use: 'Browser tabs' },
    { name: 'favicon-32x32.png', group: 'favicon', width: 32, height: 32, use: 'Tabs on high-DPI screens, taskbar, bookmarks' },
    { name: 'favicon-48x48.png', group: 'favicon', width: 48, height: 48, use: 'Google search results (minimum size)' },
    { name: 'favicon-96x96.png', group: 'favicon', width: 96, height: 96, use: 'Google search results (sharp), desktop shortcuts' },
    { name: 'apple-touch-icon.png', group: 'apple', width: 180, height: 180, opaque: true, use: 'iPhone and iPad home screen, Safari' },
    { name: 'android-chrome-192x192.png', group: 'pwa', width: 192, height: 192, use: 'Android home screen, PWA install prompt' },
    { name: 'android-chrome-512x512.png', group: 'pwa', width: 512, height: 512, use: 'PWA splash screen, app listings' },
    { name: 'maskable-icon-192x192.png', group: 'pwa', width: 192, height: 192, opaque: true, maskable: true, use: 'Android adaptive icon (cropped to circle or squircle)' },
    { name: 'maskable-icon-512x512.png', group: 'pwa', width: 512, height: 512, opaque: true, maskable: true, use: 'Android adaptive icon, large' },
    { name: 'mstile-150x150.png', group: 'windows', width: 150, height: 150, use: 'Windows Start menu tile' },
    { name: 'og-image.png', group: 'social', width: 1200, height: 630, use: 'Facebook, X, LinkedIn, WhatsApp link previews' },
];

export const GROUPS = {
    favicon: 'Favicons',
    apple: 'Apple touch icon',
    pwa: 'PWA (Android) + manifest',
    windows: 'Windows tile',
    social: 'Share image (Open Graph)',
};

const MASTER_SIZE = 2048;

const canvas = (width, height) => {
    const c = document.createElement('canvas');
    c.width = width;
    c.height = height;
    return c;
};

const loadImage = (url) => new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error('This file could not be read as an image.'));
    img.src = url;
});

/**
 * Reads the uploaded file into a large square-ish master canvas, trimmed of
 * transparent borders if asked. SVGs are given a size so every browser can
 * rasterize them.
 */
export async function loadSource(file, { trim = true } = {}) {
    const vector = file.type === 'image/svg+xml' || /\.svg$/i.test(file.name);
    let svgText = null;
    let url;
    if (vector) {
        svgText = await file.text();
        const doc = new DOMParser().parseFromString(svgText, 'image/svg+xml');
        const svg = doc.documentElement;
        if (svg.nodeName.toLowerCase() !== 'svg') throw new Error('This SVG file has no <svg> root element.');
        const box = (svg.getAttribute('viewBox') || '').split(/[\s,]+/).map(Number);
        const ratio = box.length === 4 && box[2] > 0 && box[3] > 0 ? box[2] / box[3] : 1;
        svg.setAttribute('width', String(ratio >= 1 ? MASTER_SIZE : Math.round(MASTER_SIZE * ratio)));
        svg.setAttribute('height', String(ratio >= 1 ? Math.round(MASTER_SIZE / ratio) : MASTER_SIZE));
        url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(svg)], { type: 'image/svg+xml' }));
    } else {
        url = URL.createObjectURL(file);
    }

    try {
        const img = await loadImage(url);
        const scale = Math.min(1, MASTER_SIZE / Math.max(img.naturalWidth, img.naturalHeight));
        const w = Math.max(1, Math.round(img.naturalWidth * scale));
        const h = Math.max(1, Math.round(img.naturalHeight * scale));
        let master = canvas(w, h);
        const ctx = master.getContext('2d');
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, w, h);
        if (trim) master = trimTransparent(master);

        return {
            master,
            vector,
            svgText,
            name: file.name,
            width: img.naturalWidth,
            height: img.naturalHeight,
            opaque: !hasTransparency(master),
        };
    } finally {
        URL.revokeObjectURL(url);
    }
}

function trimTransparent(source) {
    const { width, height } = source;
    const data = source.getContext('2d').getImageData(0, 0, width, height).data;
    let top = height; let left = width; let right = -1; let bottom = -1;
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            if (data[(y * width + x) * 4 + 3] > 8) {
                if (x < left) left = x;
                if (x > right) right = x;
                if (y < top) top = y;
                if (y > bottom) bottom = y;
            }
        }
    }
    if (right < 0 || (left === 0 && top === 0 && right === width - 1 && bottom === height - 1)) return source;
    const out = canvas(right - left + 1, bottom - top + 1);
    out.getContext('2d').drawImage(source, left, top, out.width, out.height, 0, 0, out.width, out.height);
    return out;
}

function hasTransparency(source) {
    const data = source.getContext('2d').getImageData(0, 0, source.width, source.height).data;
    for (let i = 3; i < data.length; i += 4 * 7) {
        if (data[i] < 250) return true;
    }
    return false;
}

/** Halves the image until it is close to the target, so small icons stay crisp. */
function stepDown(source, width, height) {
    let current = source;
    while (current.width / 2 >= width && current.height / 2 >= height) {
        const next = canvas(Math.round(current.width / 2), Math.round(current.height / 2));
        const ctx = next.getContext('2d');
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(current, 0, 0, next.width, next.height);
        current = next;
    }
    return current;
}

/** Draws the source centered inside a box, keeping its aspect ratio. */
function drawContained(ctx, source, x, y, w, h) {
    const r = Math.min(w / source.width, h / source.height);
    const dw = Math.max(1, Math.round(source.width * r));
    const dh = Math.max(1, Math.round(source.height * r));
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(stepDown(source, dw, dh), Math.round(x + (w - dw) / 2), Math.round(y + (h - dh) / 2), dw, dh);
}

/** One square icon. */
export function renderIcon(source, size, { background = null, padding = 0, radius = 0 } = {}) {
    const out = canvas(size, size);
    const ctx = out.getContext('2d');
    if (background) {
        ctx.fillStyle = background;
        ctx.beginPath();
        ctx.roundRect(0, 0, size, size, Math.round(size * radius));
        ctx.fill();
    }
    const inset = Math.round(size * padding);
    drawContained(ctx, source.master, inset, inset, size - inset * 2, size - inset * 2);
    return out;
}

const luminance = (hex) => {
    const value = /^#?([0-9a-f]{6})$/i.exec(hex || '')?.[1];
    if (!value) return 1;
    const [r, g, b] = [0, 2, 4].map((i) => {
        const c = parseInt(value.slice(i, i + 2), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
export const textColorOn = (hex) => (luminance(hex) > 0.4 ? '#0f172a' : '#ffffff');

const FONT = '"Noto Sans", "Noto Sans Bengali", "Segoe UI", system-ui, -apple-system, sans-serif';

function wrap(ctx, text, maxWidth, maxLines) {
    const words = (text || '').trim().split(/\s+/).filter(Boolean);
    const lines = [];
    let line = '';
    for (const word of words) {
        const next = line ? `${line} ${word}` : word;
        if (ctx.measureText(next).width <= maxWidth || !line) {
            line = next;
        } else {
            lines.push(line);
            line = word;
        }
    }
    if (line) lines.push(line);
    if (lines.length > maxLines) {
        lines.length = maxLines;
        let last = lines[maxLines - 1];
        while (last && ctx.measureText(`${last}…`).width > maxWidth) last = last.slice(0, -1);
        lines[maxLines - 1] = `${last.trimEnd()}…`;
    }
    return lines;
}

/** The 1200×630 share image. */
export function renderShareImage(source, { layout = 'logo-text', background = '#0f766e', title = '', subtitle = '', domain = '' } = {}) {
    const W = 1200;
    const H = 630;
    const out = canvas(W, H);
    const ctx = out.getContext('2d');
    const color = textColorOn(background);
    ctx.fillStyle = background;
    ctx.fillRect(0, 0, W, H);
    // A soft diagonal light, so a flat color does not look empty.
    const shine = ctx.createLinearGradient(0, 0, W, H);
    shine.addColorStop(0, color === '#ffffff' ? 'rgba(255,255,255,0.12)' : 'rgba(255,255,255,0.5)');
    shine.addColorStop(1, 'rgba(0,0,0,0.12)');
    ctx.fillStyle = shine;
    ctx.fillRect(0, 0, W, H);

    const hasText = layout !== 'logo' && (title || subtitle);
    if (layout === 'logo' || !hasText) {
        drawContained(ctx, source.master, (W - 360) / 2, (H - 360) / 2 - (domain ? 20 : 0), 360, 360);
    }
    if (hasText) {
        const withLogo = layout === 'logo-text';
        if (withLogo) drawContained(ctx, source.master, 90, (H - 300) / 2, 300, 300);
        const x = withLogo ? 450 : 100;
        const maxWidth = W - x - 90;
        ctx.textAlign = withLogo ? 'left' : 'center';
        const tx = withLogo ? x : W / 2;
        ctx.fillStyle = color;
        ctx.font = `700 64px ${FONT}`;
        const titleLines = wrap(ctx, title, maxWidth, 3);
        ctx.font = `400 32px ${FONT}`;
        const subLines = wrap(ctx, subtitle, maxWidth, 2);
        const blockHeight = titleLines.length * 76 + (subLines.length ? 24 + subLines.length * 44 : 0);
        let y = (H - blockHeight) / 2 + 58;
        ctx.font = `700 64px ${FONT}`;
        titleLines.forEach((line) => { ctx.fillText(line, tx, y); y += 76; });
        if (subLines.length) {
            y += 12;
            ctx.font = `400 32px ${FONT}`;
            ctx.globalAlpha = 0.85;
            subLines.forEach((line) => { ctx.fillText(line, tx, y); y += 44; });
            ctx.globalAlpha = 1;
        }
    }
    if (domain) {
        ctx.textAlign = 'center';
        ctx.font = `500 26px ${FONT}`;
        ctx.fillStyle = color;
        ctx.globalAlpha = 0.75;
        ctx.fillText(domain, W / 2, H - 40);
        ctx.globalAlpha = 1;
    }
    return out;
}

export const toBlob = (c) => new Promise((resolve) => { c.toBlob(resolve, 'image/png'); });

/** An .ico holding PNG images (supported by every current browser and Windows Vista+). */
export async function buildIco(canvases) {
    const pngs = await Promise.all(canvases.map(async (c) => new Uint8Array(await (await toBlob(c)).arrayBuffer())));
    const header = 6 + 16 * pngs.length;
    const out = new Uint8Array(header + pngs.reduce((sum, p) => sum + p.length, 0));
    const view = new DataView(out.buffer);
    view.setUint16(2, 1, true);
    view.setUint16(4, pngs.length, true);
    let offset = header;
    pngs.forEach((png, i) => {
        const entry = 6 + i * 16;
        const size = canvases[i].width;
        out[entry] = size >= 256 ? 0 : size;
        out[entry + 1] = size >= 256 ? 0 : size;
        view.setUint16(entry + 4, 1, true);
        view.setUint16(entry + 6, 32, true);
        view.setUint32(entry + 8, png.length, true);
        view.setUint32(entry + 12, offset, true);
        out.set(png, offset);
        offset += png.length;
    });
    return new Blob([out], { type: 'image/x-icon' });
}

/** Paths as the site will serve them: favicon.ico at the root, the rest in the folder. */
export const sitePath = (folder, name) => (name === 'favicon.ico' || !folder ? `/${name}` : `/${folder}/${name}`);

export function buildManifest(options, folder, hasMaskable) {
    const p = (name) => sitePath(folder, name);
    const icons = [
        { src: p('android-chrome-192x192.png'), sizes: '192x192', type: 'image/png', purpose: 'any' },
        { src: p('android-chrome-512x512.png'), sizes: '512x512', type: 'image/png', purpose: 'any' },
    ];
    if (hasMaskable) {
        icons.push(
            { src: p('maskable-icon-192x192.png'), sizes: '192x192', type: 'image/png', purpose: 'maskable' },
            { src: p('maskable-icon-512x512.png'), sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        );
    }
    const manifest = {
        name: options.name || 'My App',
        short_name: options.shortName || options.name || 'App',
        ...(options.description ? { description: options.description } : {}),
        id: options.startUrl || '/',
        start_url: options.startUrl || '/',
        scope: '/',
        display: options.display,
        theme_color: options.themeColor,
        background_color: options.backgroundColor,
        icons,
    };
    return JSON.stringify(manifest, null, 2);
}

export function buildBrowserconfig(options, folder) {
    return `<?xml version="1.0" encoding="utf-8"?>
<browserconfig>
  <msapplication>
    <tile>
      <square150x150logo src="${sitePath(folder, 'mstile-150x150.png')}"/>
      <TileColor>${options.themeColor}</TileColor>
    </tile>
  </msapplication>
</browserconfig>
`;
}

const escapeAttr = (value) => String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

/** The tags to paste into <head>, for the files actually included. */
export function buildHead(options, folder, names, siteUrl) {
    const p = (name) => sitePath(folder, name);
    const has = (name) => names.includes(name);
    const origin = (siteUrl || '').replace(/\/+$/, '');
    const lines = [];
    if (has('favicon.ico')) lines.push('<link rel="icon" href="/favicon.ico" sizes="48x48">');
    if (has('favicon.svg')) lines.push(`<link rel="icon" href="${p('favicon.svg')}" type="image/svg+xml">`);
    for (const size of [96, 32, 16]) {
        if (has(`favicon-${size}x${size}.png`)) lines.push(`<link rel="icon" type="image/png" sizes="${size}x${size}" href="${p(`favicon-${size}x${size}.png`)}">`);
    }
    if (has('apple-touch-icon.png')) {
        lines.push(`<link rel="apple-touch-icon" sizes="180x180" href="${p('apple-touch-icon.png')}">`);
        lines.push(`<meta name="apple-mobile-web-app-title" content="${escapeAttr(options.shortName || options.name)}">`);
    }
    if (has('site.webmanifest')) {
        lines.push(`<link rel="manifest" href="${p('site.webmanifest')}">`);
        lines.push(`<meta name="application-name" content="${escapeAttr(options.name)}">`);
    }
    lines.push(`<meta name="theme-color" content="${escapeAttr(options.themeColor)}">`);
    if (has('browserconfig.xml')) lines.push(`<meta name="msapplication-config" content="${p('browserconfig.xml')}">`);
    if (has('og-image.png')) {
        lines.push(`<meta property="og:image" content="${escapeAttr(origin || 'https://example.com')}${p('og-image.png')}">`);
        lines.push('<meta property="og:image:width" content="1200">');
        lines.push('<meta property="og:image:height" content="630">');
        if (options.og.title) lines.push(`<meta property="og:image:alt" content="${escapeAttr(options.og.title)}">`);
        lines.push('<meta name="twitter:card" content="summary_large_image">');
    }
    return `${lines.join('\n')}\n`;
}

// ZIP (stored, no compression: PNGs are already compressed).
const CRC_TABLE = (() => {
    const table = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        table[n] = c >>> 0;
    }
    return table;
})();
const crc32 = (bytes) => {
    let crc = 0xffffffff;
    for (let i = 0; i < bytes.length; i++) crc = CRC_TABLE[(crc ^ bytes[i]) & 0xff] ^ (crc >>> 8);
    return (crc ^ 0xffffffff) >>> 0;
};

/** @param {Array<{name: string, blob: Blob}>} entries */
export async function buildZip(entries) {
    const encoder = new TextEncoder();
    const now = new Date();
    const time = (now.getHours() << 11) | (now.getMinutes() << 5) | Math.floor(now.getSeconds() / 2);
    const date = ((now.getFullYear() - 1980) << 9) | ((now.getMonth() + 1) << 5) | now.getDate();
    const parts = [];
    const central = [];
    let offset = 0;
    for (const entry of entries) {
        const name = encoder.encode(entry.name);
        const data = new Uint8Array(await entry.blob.arrayBuffer());
        const crc = crc32(data);
        const local = new DataView(new ArrayBuffer(30));
        local.setUint32(0, 0x04034b50, true);
        local.setUint16(4, 20, true);
        local.setUint16(6, 0x0800, true);
        local.setUint16(10, time, true);
        local.setUint16(12, date, true);
        local.setUint32(14, crc, true);
        local.setUint32(18, data.length, true);
        local.setUint32(22, data.length, true);
        local.setUint16(26, name.length, true);
        parts.push(local.buffer, name, data);

        const dir = new DataView(new ArrayBuffer(46));
        dir.setUint32(0, 0x02014b50, true);
        dir.setUint16(4, 20, true);
        dir.setUint16(6, 20, true);
        dir.setUint16(8, 0x0800, true);
        dir.setUint16(12, time, true);
        dir.setUint16(14, date, true);
        dir.setUint32(16, crc, true);
        dir.setUint32(20, data.length, true);
        dir.setUint32(24, data.length, true);
        dir.setUint16(28, name.length, true);
        dir.setUint32(42, offset, true);
        central.push(dir.buffer, name);
        offset += 30 + name.length + data.length;
    }
    const centralSize = central.reduce((sum, part) => sum + part.byteLength, 0);
    const end = new DataView(new ArrayBuffer(22));
    end.setUint32(0, 0x06054b50, true);
    end.setUint16(8, entries.length, true);
    end.setUint16(10, entries.length, true);
    end.setUint32(12, centralSize, true);
    end.setUint32(16, offset, true);
    return new Blob([...parts, ...central, end.buffer], { type: 'application/zip' });
}
