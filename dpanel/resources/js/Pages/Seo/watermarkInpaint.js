// Watermark removal for the Image Watermark page. Everything runs on canvas
// pixel data in the browser: a mask (1 = remove) is filled either by
// exemplar patches copied from the surrounding image (texture), or by the
// Telea fast-marching method (smooth, best for thin marks on flat areas).

const yieldToUi = () => new Promise((resolve) => setTimeout(resolve, 0));

/** Grow a mask by r pixels (square dilation, run as two 1-D passes). */
export function growMask(mask, w, h, r) {
    if (r <= 0) return mask;
    const tmp = new Uint8Array(w * h);
    const out = new Uint8Array(w * h);
    for (let y = 0; y < h; y++) {
        let run = -1;
        for (let x = 0; x < w; x++) if (mask[y * w + x]) run = x + r; else if (x <= run) tmp[y * w + x] = 1;
        run = w + r;
        for (let x = w - 1; x >= 0; x--) {
            if (mask[y * w + x]) { tmp[y * w + x] = 1; run = x - r; } else if (x >= run) tmp[y * w + x] = 1;
        }
    }
    for (let x = 0; x < w; x++) {
        let run = -1;
        for (let y = 0; y < h; y++) if (tmp[y * w + x]) run = y + r; else if (y <= run) out[y * w + x] = 1;
        run = h + r;
        for (let y = h - 1; y >= 0; y--) {
            if (tmp[y * w + x]) { out[y * w + x] = 1; run = y - r; } else if (y >= run) out[y * w + x] = 1;
        }
    }
    return out;
}

/**
 * Pixels inside rect that stand out from their local background: the
 * strokes of a text or logo overlay. Returns a full-size mask.
 * sensitivity 1..100: higher catches fainter marks.
 */
export function detectMarks(imageData, rect, sensitivity = 50) {
    const { width: w, height: h, data } = imageData;
    const mask = new Uint8Array(w * h);
    const radius = Math.max(4, Math.round(Math.min(rect.w, rect.h) / 6));
    const x0 = Math.max(0, rect.x - radius); const x1 = Math.min(w, rect.x + rect.w + radius);
    const y0 = Math.max(0, rect.y - radius); const y1 = Math.min(h, rect.y + rect.h + radius);
    const cw = x1 - x0; const ch = y1 - y0;
    if (cw <= 0 || ch <= 0) return mask;

    // Luminance plus an integral image for box-blurred background.
    const lum = new Float32Array(cw * ch);
    const sum = new Float64Array((cw + 1) * (ch + 1));
    for (let y = 0; y < ch; y++) {
        let row = 0;
        for (let x = 0; x < cw; x++) {
            const i = ((y + y0) * w + x + x0) * 4;
            const l = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
            lum[y * cw + x] = l;
            row += l;
            sum[(y + 1) * (cw + 1) + x + 1] = sum[y * (cw + 1) + x + 1] + row;
        }
    }
    const threshold = 4 + (100 - sensitivity) * 0.5;
    for (let y = Math.max(0, rect.y - y0); y < Math.min(ch, rect.y + rect.h - y0); y++) {
        const ya = Math.max(0, y - radius); const yb = Math.min(ch, y + radius + 1);
        for (let x = Math.max(0, rect.x - x0); x < Math.min(cw, rect.x + rect.w - x0); x++) {
            const xa = Math.max(0, x - radius); const xb = Math.min(cw, x + radius + 1);
            const area = (yb - ya) * (xb - xa);
            const mean = (sum[yb * (cw + 1) + xb] - sum[ya * (cw + 1) + xb] - sum[yb * (cw + 1) + xa] + sum[ya * (cw + 1) + xa]) / area;
            if (Math.abs(lum[y * cw + x] - mean) > threshold) mask[(y + y0) * w + x + x0] = 1;
        }
    }
    return mask;
}

class MinHeap {
    constructor() { this.keys = []; this.items = []; }

    get size() { return this.items.length; }

    push(key, item) {
        const { keys, items } = this;
        let i = items.length;
        keys.push(key); items.push(item);
        while (i > 0) {
            const parent = (i - 1) >> 1;
            if (keys[parent] <= key) break;
            keys[i] = keys[parent]; items[i] = items[parent];
            i = parent;
        }
        keys[i] = key; items[i] = item;
    }

    pop() {
        const { keys, items } = this;
        const top = items[0];
        const lastKey = keys.pop(); const lastItem = items.pop();
        if (items.length) {
            let i = 0;
            for (;;) {
                let child = 2 * i + 1;
                if (child >= items.length) break;
                if (child + 1 < items.length && keys[child + 1] < keys[child]) child++;
                if (keys[child] >= lastKey) break;
                keys[i] = keys[child]; items[i] = items[child];
                i = child;
            }
            keys[i] = lastKey; items[i] = lastItem;
        }
        return top;
    }
}

/** Telea (2004) fast-marching inpainting, in place on RGBA px. */
export function inpaintTelea(px, mask, w, h, radius = 5) {
    const KNOWN = 0; const BAND = 1; const INSIDE = 2; const FAR = 1e6;
    const n = w * h;
    const flag = new Uint8Array(n);
    const T = new Float32Array(n);
    const heap = new MinHeap();
    for (let i = 0; i < n; i++) if (mask[i]) { flag[i] = INSIDE; T[i] = FAR; }
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const i = y * w + x;
            if (mask[i]) continue;
            if ((x > 0 && mask[i - 1]) || (x < w - 1 && mask[i + 1]) || (y > 0 && mask[i - w]) || (y < h - 1 && mask[i + w])) {
                flag[i] = BAND;
                heap.push(0, i);
            }
        }
    }

    const tOf = (i) => (i >= 0 && flag[i] !== INSIDE ? T[i] : FAR);
    const solve = (a, b) => {
        const ta = tOf(a); const tb = tOf(b);
        if (ta < FAR && tb < FAR) {
            const d = Math.abs(ta - tb);
            return d < 1 ? (ta + tb + Math.sqrt(2 - d * d)) / 2 : 1 + Math.min(ta, tb);
        }
        if (ta < FAR) return 1 + ta;
        if (tb < FAR) return 1 + tb;
        return FAR;
    };
    const r2 = radius * radius;
    const fill = (q) => {
        const qx = q % w; const qy = (q - qx) / w;
        const left = qx > 0 ? q - 1 : -1; const right = qx < w - 1 ? q + 1 : -1;
        const up = qy > 0 ? q - w : -1; const down = qy < h - 1 ? q + w : -1;
        const gradient = (a, b) => {
            const ta = tOf(a); const tb = tOf(b);
            if (ta < FAR && tb < FAR) return (tb - ta) / 2;
            if (tb < FAR) return tb - T[q];
            if (ta < FAR) return T[q] - ta;
            return 0;
        };
        let gx = gradient(left, right); let gy = gradient(up, down);
        const gl = Math.hypot(gx, gy) || 1;
        gx /= gl; gy /= gl;
        let r = 0; let g = 0; let b = 0; let a = 0; let total = 0;
        for (let ky = Math.max(0, qy - radius); ky <= Math.min(h - 1, qy + radius); ky++) {
            for (let kx = Math.max(0, qx - radius); kx <= Math.min(w - 1, qx + radius); kx++) {
                const k = ky * w + kx;
                if (k === q || flag[k] === INSIDE) continue;
                const rx = qx - kx; const ry = qy - ky;
                const d2 = rx * rx + ry * ry;
                if (d2 > r2) continue;
                const dir = Math.max(1e-6, Math.abs(rx * gx + ry * gy) / Math.sqrt(d2));
                const weight = (dir / d2) / (1 + Math.abs(T[k] - T[q]));
                const o = k * 4;
                r += px[o] * weight; g += px[o + 1] * weight; b += px[o + 2] * weight; a += px[o + 3] * weight;
                total += weight;
            }
        }
        if (total > 0) {
            const o = q * 4;
            px[o] = r / total; px[o + 1] = g / total; px[o + 2] = b / total; px[o + 3] = a / total;
        }
    };

    while (heap.size) {
        const p = heap.pop();
        if (flag[p] === KNOWN) continue;
        flag[p] = KNOWN;
        const px0 = p % w; const py0 = (p - px0) / w;
        const neighbors = [px0 > 0 ? p - 1 : -1, px0 < w - 1 ? p + 1 : -1, py0 > 0 ? p - w : -1, py0 < h - 1 ? p + w : -1];
        for (const q of neighbors) {
            if (q < 0 || flag[q] === KNOWN) continue;
            const qx = q % w; const qy = (q - qx) / w;
            const l = qx > 0 ? q - 1 : -1; const rr = qx < w - 1 ? q + 1 : -1;
            const u = qy > 0 ? q - w : -1; const d = qy < h - 1 ? q + w : -1;
            const t = Math.min(solve(l, u), solve(rr, u), solve(l, d), solve(rr, d));
            if (flag[q] === INSIDE) {
                flag[q] = BAND;
                T[q] = t;
                fill(q);
                heap.push(t, q);
            } else if (t < T[q]) {
                T[q] = t;
                heap.push(t, q);
            }
        }
    }
}

/**
 * Exemplar-based inpainting (Criminisi et al. 2004): fills the mask edge
 * inward, highest-confidence spot first, each time copying the best
 * matching patch from the untouched surroundings. In place on RGBA px.
 */
export async function inpaintExemplar(px, mask, w, h, { half = 4, search = 48, onProgress } = {}) {
    const n = w * h;
    const fillMask = Uint8Array.from(mask);
    const conf = new Float32Array(n);
    let remaining = 0;
    for (let i = 0; i < n; i++) if (fillMask[i]) remaining++; else conf[i] = 1;
    const total = remaining;
    if (!total) return;

    // A source patch must lie inside the image and hold no masked pixel.
    const integral = new Int32Array((w + 1) * (h + 1));
    for (let y = 0; y < h; y++) {
        let row = 0;
        for (let x = 0; x < w; x++) {
            row += mask[y * w + x];
            integral[(y + 1) * (w + 1) + x + 1] = integral[y * (w + 1) + x + 1] + row;
        }
    }
    const size = 2 * half + 1;
    const validSource = (cx, cy) => {
        const x0 = cx - half; const y0 = cy - half;
        if (x0 < 0 || y0 < 0 || x0 + size > w || y0 + size > h) return false;
        return integral[(y0 + size) * (w + 1) + x0 + size] - integral[y0 * (w + 1) + x0 + size] - integral[(y0 + size) * (w + 1) + x0] + integral[y0 * (w + 1) + x0] === 0;
    };
    const lum = (i) => 0.299 * px[i * 4] + 0.587 * px[i * 4 + 1] + 0.114 * px[i * 4 + 2];
    const isFill = (x, y) => (x < 0 || y < 0 || x >= w || y >= h ? 0 : fillMask[y * w + x]);

    let lastYield = performance.now();
    while (remaining > 0) {
        // Highest-priority pixel on the fill front.
        let best = -1; let bestPriority = -1; let bestConfidence = 0;
        for (let i = 0; i < n; i++) {
            if (!fillMask[i]) continue;
            const x = i % w; const y = (i - x) / w;
            if (!((x > 0 && !fillMask[i - 1]) || (x < w - 1 && !fillMask[i + 1]) || (y > 0 && !fillMask[i - w]) || (y < h - 1 && !fillMask[i + w]))) continue;
            let c = 0;
            for (let yy = Math.max(0, y - half); yy <= Math.min(h - 1, y + half); yy++) for (let xx = Math.max(0, x - half); xx <= Math.min(w - 1, x + half); xx++) c += conf[yy * w + xx];
            c /= size * size;
            // Data term: isophote (from known neighbours) against the front normal.
            let nx = isFill(x - 1, y) - isFill(x + 1, y); let ny = isFill(x, y - 1) - isFill(x, y + 1);
            const nl = Math.hypot(nx, ny) || 1; nx /= nl; ny /= nl;
            let gx = 0; let gy = 0;
            if (x > 0 && x < w - 1 && !fillMask[i - 1] && !fillMask[i + 1]) gx = (lum(i + 1) - lum(i - 1)) / 2;
            if (y > 0 && y < h - 1 && !fillMask[i - w] && !fillMask[i + w]) gy = (lum(i + w) - lum(i - w)) / 2;
            const data = Math.abs(-gy * nx + gx * ny) / 255 + 0.001;
            const priority = c * data;
            if (priority > bestPriority) { bestPriority = priority; best = i; bestConfidence = c; }
        }
        if (best < 0) break;
        const bx = best % w; const by = (best - bx) / w;

        // Known pixels of the target patch, compared against each candidate.
        const offsets = [];
        for (let dy = -half; dy <= half; dy++) {
            for (let dx = -half; dx <= half; dx++) {
                const x = bx + dx; const y = by + dy;
                if (x >= 0 && y >= 0 && x < w && y < h && !fillMask[y * w + x]) offsets.push(dx, dy, (y * w + x) * 4);
            }
        }
        const cost = (cx, cy, limit) => {
            let ssd = 0;
            for (let k = 0; k < offsets.length; k += 3) {
                const s = ((cy + offsets[k + 1]) * w + cx + offsets[k]) * 4; const t = offsets[k + 2];
                const dr = px[s] - px[t]; const dg = px[s + 1] - px[t + 1]; const db = px[s + 2] - px[t + 2];
                ssd += dr * dr + dg * dg + db * db;
                if (ssd >= limit) return ssd;
            }
            return ssd;
        };
        let source = -1; let sourceCost = Infinity;
        for (let radius = search; source < 0 && radius <= Math.max(w, h) * 2; radius *= 2) {
            // Coarse pass on a 2-pixel grid, then refine around the winner.
            for (let cy = Math.max(half, by - radius); cy <= Math.min(h - half - 1, by + radius); cy += 2) {
                for (let cx = Math.max(half, bx - radius); cx <= Math.min(w - half - 1, bx + radius); cx += 2) {
                    if (!validSource(cx, cy)) continue;
                    const c = cost(cx, cy, sourceCost);
                    if (c < sourceCost) { sourceCost = c; source = cy * w + cx; }
                }
            }
        }
        if (source < 0) {
            // Nothing clean to copy from (mask covers almost everything): fall back to a smooth fill.
            inpaintTelea(px, fillMask, w, h);
            break;
        }
        const sx0 = source % w; const sy0 = (source - sx0) / w;
        for (let cy = sy0 - 1; cy <= sy0 + 1; cy++) {
            for (let cx = sx0 - 1; cx <= sx0 + 1; cx++) {
                if (!validSource(cx, cy)) continue;
                const c = cost(cx, cy, sourceCost);
                if (c < sourceCost) { sourceCost = c; source = cy * w + cx; }
            }
        }
        const sx = source % w; const sy = (source - sx) / w;

        for (let dy = -half; dy <= half; dy++) {
            for (let dx = -half; dx <= half; dx++) {
                const x = bx + dx; const y = by + dy;
                if (x < 0 || y < 0 || x >= w || y >= h) continue;
                const t = y * w + x;
                if (!fillMask[t]) continue;
                const s = ((sy + dy) * w + sx + dx) * 4;
                px[t * 4] = px[s]; px[t * 4 + 1] = px[s + 1]; px[t * 4 + 2] = px[s + 2]; px[t * 4 + 3] = px[s + 3];
                fillMask[t] = 0;
                conf[t] = bestConfidence;
                remaining--;
            }
        }

        if (performance.now() - lastYield > 30) {
            onProgress?.(1 - remaining / total);
            await yieldToUi();
            lastYield = performance.now();
        }
    }
    onProgress?.(1);
}

/**
 * Remove the masked area from imageData (in place). Works on the mask's
 * bounding box plus a margin, so large photos stay fast.
 * method: 'texture' (exemplar patches) | 'smooth' (Telea).
 */
export async function inpaint(imageData, mask, { method = 'texture', onProgress } = {}) {
    const { width: w, height: h, data } = imageData;
    let minX = w; let minY = h; let maxX = -1; let maxY = -1;
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            if (!mask[y * w + x]) continue;
            if (x < minX) minX = x; if (x > maxX) maxX = x;
            if (y < minY) minY = y; if (y > maxY) maxY = y;
        }
    }
    if (maxX < 0) return false;

    const margin = method === 'texture' ? 96 : 8;
    const x0 = Math.max(0, minX - margin); const y0 = Math.max(0, minY - margin);
    const cw = Math.min(w, maxX + margin + 1) - x0; const ch = Math.min(h, maxY + margin + 1) - y0;
    const px = new Uint8ClampedArray(cw * ch * 4);
    const cropMask = new Uint8Array(cw * ch);
    for (let y = 0; y < ch; y++) {
        px.set(data.subarray(((y + y0) * w + x0) * 4, ((y + y0) * w + x0 + cw) * 4), y * cw * 4);
        cropMask.set(mask.subarray((y + y0) * w + x0, (y + y0) * w + x0 + cw), y * cw);
    }

    if (method === 'smooth') {
        await yieldToUi();
        inpaintTelea(px, cropMask, cw, ch, 5);
        onProgress?.(1);
    } else {
        await inpaintExemplar(px, cropMask, cw, ch, { onProgress });
    }

    for (let y = 0; y < ch; y++) {
        for (let x = 0; x < cw; x++) {
            if (!cropMask[y * cw + x]) continue;
            const s = (y * cw + x) * 4; const t = ((y + y0) * w + x + x0) * 4;
            data[t] = px[s]; data[t + 1] = px[s + 1]; data[t + 2] = px[s + 2]; data[t + 3] = px[s + 3];
        }
    }
    return true;
}
