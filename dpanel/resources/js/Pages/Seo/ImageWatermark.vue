<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { detectMarks, growMask, inpaint } from '@/Pages/Seo/watermarkInpaint';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, reactive, ref } from 'vue';

const props = defineProps({
    maxBytes: { type: Number, default: 20 * 1024 * 1024 },
});

const { panelRoute, csrfToken } = usePanelApi();

const MAX_PIXELS = 40_000_000;
const file = ref(null);
const originalUrl = ref('');
const size = reactive({ width: 0, height: 0 });
const error = ref('');
const dragging = ref(false);

// Check: the file's metadata, read on the server.
const check = reactive({ loading: false, report: null, error: '' });
const runCheck = async (picked) => {
    Object.assign(check, { loading: true, report: null, error: '' });
    const form = new FormData();
    form.append('image', picked, picked.name);
    try {
        const response = await fetch(panelRoute('seo.image-watermark.check'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken.value },
            body: form,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'The image could not be checked.');
        if (file.value === picked) check.report = data;
    } catch (e) {
        if (file.value === picked) check.error = e.message;
    } finally {
        if (file.value === picked) check.loading = false;
    }
};

const VERDICTS = {
    ai: { label: 'AI label found', icon: 'bi-stars', tone: 'border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-900 dark:bg-violet-900/30 dark:text-violet-200' },
    provenance: { label: 'Content Credentials', icon: 'bi-patch-check', tone: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-900/30 dark:text-blue-200' },
    rights: { label: 'Ownership marked', icon: 'bi-c-circle', tone: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-900/30 dark:text-amber-200' },
    none: { label: 'Nothing found', icon: 'bi-check-circle', tone: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200' },
};
const KIND_ICONS = { ai: 'bi-stars text-violet-500', provenance: 'bi-patch-check text-blue-500', rights: 'bi-c-circle text-amber-500' };
const verdict = computed(() => (check.report ? VERDICTS[check.report.verdict] || VERDICTS.none : null));

// Remove: an image canvas and a red mask canvas stacked on top of it.
const imageCanvas = ref(null);
const maskCanvas = ref(null);
const tool = ref('brush'); // brush | eraser | box
const brushSize = ref(24);
const detect = ref(true);
const sensitivity = ref(55);
const grow = ref(2);
const method = ref('texture');
const working = ref(false);
const progress = ref(0);
const hasMask = ref(false);
const comparing = ref(false);
const history = [];
const canUndo = ref(false);
const edited = ref(false);
let original = null;

const reset = () => {
    if (originalUrl.value) URL.revokeObjectURL(originalUrl.value);
    originalUrl.value = '';
    history.length = 0;
    canUndo.value = false;
    edited.value = false;
    hasMask.value = false;
    original = null;
};
onBeforeUnmount(reset);

const pickFile = async (picked) => {
    if (!picked) return;
    if (!/^image\/(png|jpeg|webp|gif|avif|bmp)$/.test(picked.type) && !/\.(png|jpe?g|webp|gif|avif|bmp)$/i.test(picked.name)) {
        error.value = 'Choose an image: PNG, JPG, WebP, GIF or AVIF.';
        return;
    }
    if (picked.size > props.maxBytes) {
        error.value = `The image is larger than ${Math.round(props.maxBytes / 1024 / 1024)} MB.`;
        return;
    }
    error.value = '';
    reset();
    let bitmap;
    try {
        bitmap = await createImageBitmap(picked);
    } catch {
        error.value = 'Your browser could not open this image.';
        return;
    }
    if (bitmap.width * bitmap.height > MAX_PIXELS) {
        error.value = 'The image is over 40 megapixels; resize it first.';
        return;
    }
    file.value = picked;
    originalUrl.value = URL.createObjectURL(picked);
    Object.assign(size, { width: bitmap.width, height: bitmap.height });
    brushSize.value = Math.max(6, Math.round(Math.max(bitmap.width, bitmap.height) / 50));
    await nextTick();
    for (const canvas of [imageCanvas.value, maskCanvas.value]) {
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
    }
    const ctx = imageCanvas.value.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(bitmap, 0, 0);
    bitmap.close();
    original = ctx.getImageData(0, 0, size.width, size.height);
    runCheck(picked);
};
const onDrop = (event) => {
    dragging.value = false;
    pickFile(event.dataTransfer?.files?.[0]);
};

// Painting the mask.
const box = ref(null); // { x, y, w, h } in image pixels while dragging
let drawing = null;
const toImage = (event) => {
    const rect = maskCanvas.value.getBoundingClientRect();
    const clamp = (value, max) => Math.min(max, Math.max(0, Math.round(value)));
    return {
        x: clamp(((event.clientX - rect.left) * size.width) / rect.width, size.width),
        y: clamp(((event.clientY - rect.top) * size.height) / rect.height, size.height),
    };
};
const maskCtx = () => maskCanvas.value.getContext('2d', { willReadFrequently: true });
const stroke = (from, to) => {
    const ctx = maskCtx();
    ctx.globalCompositeOperation = tool.value === 'eraser' ? 'destination-out' : 'source-over';
    ctx.strokeStyle = '#ef4444';
    ctx.lineWidth = brushSize.value;
    ctx.lineCap = 'round';
    ctx.beginPath();
    ctx.moveTo(from.x, from.y);
    ctx.lineTo(to.x, to.y);
    ctx.stroke();
    ctx.globalCompositeOperation = 'source-over';
};
const onPointerDown = (event) => {
    if (working.value || comparing.value) return;
    event.target.setPointerCapture(event.pointerId);
    const point = toImage(event);
    drawing = { start: point, last: point };
    if (tool.value === 'box') box.value = { x: point.x, y: point.y, w: 0, h: 0 };
    else stroke(point, point);
};
const onPointerMove = (event) => {
    if (!drawing) return;
    const point = toImage(event);
    if (tool.value === 'box') {
        const { start } = drawing;
        box.value = { x: Math.min(start.x, point.x), y: Math.min(start.y, point.y), w: Math.abs(point.x - start.x), h: Math.abs(point.y - start.y) };
    } else {
        stroke(drawing.last, point);
    }
    drawing.last = point;
};
const onPointerUp = () => {
    if (!drawing) return;
    drawing = null;
    if (tool.value === 'box' && box.value && box.value.w > 2 && box.value.h > 2) {
        const rect = box.value;
        const ctx = maskCtx();
        if (detect.value) {
            const pixels = imageCanvas.value.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, size.width, size.height);
            const found = detectMarks(pixels, rect, sensitivity.value);
            const overlay = ctx.getImageData(rect.x, rect.y, rect.w, rect.h);
            for (let y = 0; y < rect.h; y++) {
                for (let x = 0; x < rect.w; x++) {
                    if (!found[(rect.y + y) * size.width + rect.x + x]) continue;
                    const o = (y * rect.w + x) * 4;
                    overlay.data.set([239, 68, 68, 255], o);
                }
            }
            ctx.putImageData(overlay, rect.x, rect.y);
        } else {
            ctx.fillStyle = '#ef4444';
            ctx.fillRect(rect.x, rect.y, rect.w, rect.h);
        }
    }
    box.value = null;
    hasMask.value = true;
};
const clearMask = () => {
    maskCtx().clearRect(0, 0, size.width, size.height);
    hasMask.value = false;
};
const boxStyle = computed(() => (box.value ? {
    left: `${(box.value.x / size.width) * 100}%`,
    top: `${(box.value.y / size.height) * 100}%`,
    width: `${(box.value.w / size.width) * 100}%`,
    height: `${(box.value.h / size.height) * 100}%`,
} : null));

const remove = async () => {
    const { width, height } = size;
    const alpha = maskCtx().getImageData(0, 0, width, height).data;
    let mask = new Uint8Array(width * height);
    let any = false;
    for (let i = 0; i < mask.length; i++) if (alpha[i * 4 + 3] > 0) { mask[i] = 1; any = true; }
    if (!any) return;
    mask = growMask(mask, width, height, grow.value);

    working.value = true;
    progress.value = 0;
    try {
        const ctx = imageCanvas.value.getContext('2d', { willReadFrequently: true });
        const pixels = ctx.getImageData(0, 0, width, height);
        history.push(ctx.getImageData(0, 0, width, height));
        if (history.length > 10) history.shift();
        await inpaint(pixels, mask, { method: method.value, onProgress: (p) => { progress.value = p; } });
        ctx.putImageData(pixels, 0, 0);
        clearMask();
        canUndo.value = true;
        edited.value = true;
    } catch (e) {
        error.value = e.message || 'The marks could not be removed.';
    } finally {
        working.value = false;
    }
};
const undo = () => {
    const previous = history.pop();
    if (!previous) return;
    imageCanvas.value.getContext('2d').putImageData(previous, 0, 0);
    canUndo.value = history.length > 0;
    edited.value = history.length > 0;
};
const restoreOriginal = () => {
    if (!original) return;
    imageCanvas.value.getContext('2d').putImageData(original, 0, 0);
    history.length = 0;
    canUndo.value = false;
    edited.value = false;
    clearMask();
};

const download = () => {
    const type = /^image\/(jpeg|webp)$/.test(file.value.type) ? file.value.type : 'image/png';
    const ext = { 'image/jpeg': 'jpg', 'image/webp': 'webp', 'image/png': 'png' }[type];
    imageCanvas.value.toBlob((blob) => {
        if (!blob) return;
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `${file.value.name.replace(/\.[^.]+$/, '')}-clean.${ext}`;
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    }, type, 0.95);
};

// Export the current pixels as a fresh file. Canvas encoding does not copy
// source EXIF, XMP, IPTC, PNG text chunks, or C2PA manifests.
const downloadCleanCopy = () => {
    const type = /^image\/(jpeg|webp)$/.test(file.value.type) ? file.value.type : 'image/png';
    const ext = { 'image/jpeg': 'jpg', 'image/webp': 'webp', 'image/png': 'png' }[type];
    imageCanvas.value.toBlob((blob) => {
        if (!blob) {
            error.value = 'A clean copy could not be created.';
            return;
        }
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `${file.value.name.replace(/\.[^.]+$/, '')}-metadata-clean.${ext}`;
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    }, type, 0.95);
};

const formatBytes = (bytes) => (bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);
const card = 'rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800';
const segment = 'grid gap-1 rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs dark:border-slate-700 dark:bg-slate-900';
const segmentButton = (active) => (active ? 'bg-white font-medium shadow-sm dark:bg-slate-700' : 'text-slate-500');
</script>

<template>
    <Head title="Image Watermark" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Image Watermark</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Check an image for watermarks, AI labels and ownership details, and remove visible marks such as logos, date stamps and text overlays.</p>
            </div>
        </template>

        <div class="grid items-start gap-4 xl:grid-cols-[24rem_1fr]">
            <!-- Upload, check result and tools -->
            <div class="space-y-4">
                <section :class="card" class="p-4">
                    <label
                        class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-5 text-center text-sm transition"
                        :class="dragging ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-slate-300 hover:border-blue-400 dark:border-slate-600'"
                        @dragover.prevent="dragging = true"
                        @dragleave.prevent="dragging = false"
                        @drop.prevent="onDrop"
                    >
                        <input type="file" accept="image/png,image/jpeg,image/webp,image/gif,image/avif,image/bmp" class="sr-only" @change="pickFile($event.target.files[0]); $event.target.value = ''" />
                        <i class="bi bi-cloud-arrow-up text-3xl text-slate-400"></i>
                        <span v-if="file" class="font-medium">{{ file.name }} <span class="font-normal text-slate-500">· {{ size.width }}×{{ size.height }} · {{ formatBytes(file.size) }}</span></span>
                        <span v-else class="font-medium">Drop an image here or click to choose</span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">PNG, JPG, WebP, GIF or AVIF, up to {{ Math.round(maxBytes / 1024 / 1024) }} MB. The original file is checked as-is.</span>
                    </label>
                </section>

                <section v-if="file" :class="card">
                    <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-700">
                        <h2 class="text-sm font-semibold">Watermark check</h2>
                    </div>
                    <div class="space-y-3 p-4">
                        <p v-if="check.loading" class="text-sm text-slate-500"><i class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>Reading the file's metadata…</p>
                        <p v-else-if="check.error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-900/30 dark:text-red-300">{{ check.error }}</p>
                        <template v-else-if="check.report">
                            <div class="rounded-lg border px-3 py-2 text-sm" :class="verdict.tone">
                                <p class="font-semibold"><i class="bi mr-1" :class="verdict.icon"></i>{{ verdict.label }}</p>
                                <p class="mt-0.5">{{ check.report.summary }}</p>
                            </div>
                            <ul v-if="check.report.findings.length" class="space-y-2">
                                <li v-for="(f, i) in check.report.findings" :key="i" class="flex gap-2 text-sm">
                                    <i class="bi mt-0.5" :class="KIND_ICONS[f.kind] || 'bi-info-circle text-slate-400'"></i>
                                    <div class="min-w-0">
                                        <p class="font-medium">{{ f.title }} <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-normal uppercase tracking-wide text-slate-500 dark:bg-slate-700 dark:text-slate-300">{{ f.source }}</span></p>
                                        <p class="break-words text-xs text-slate-500 dark:text-slate-400">{{ f.detail }}</p>
                                    </div>
                                </li>
                            </ul>
                            <details v-if="check.report.metadata.length" class="text-xs">
                                <summary class="cursor-pointer font-medium text-slate-600 dark:text-slate-300">All metadata read ({{ check.report.metadata.length }})</summary>
                                <dl class="mt-2 max-h-64 space-y-1.5 overflow-auto">
                                    <div v-for="(m, i) in check.report.metadata" :key="i">
                                        <dt class="font-mono text-slate-500">{{ m.source }} · {{ m.key }}</dt>
                                        <dd class="break-words font-mono">{{ m.value }}</dd>
                                    </div>
                                </dl>
                            </details>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                <i class="bi bi-info-circle mr-1"></i>This reads labels stored in the file. Invisible pixel watermarks such as SynthID are encoded into the image itself; this tool cannot reliably detect or remove them.
                            </p>
                            <button type="button" @click="downloadCleanCopy" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-700"><i class="bi bi-file-earmark-minus mr-1"></i>Download copy without file metadata</button>
                            <p class="text-xs text-slate-500 dark:text-slate-400">This removes embedded metadata and Content Credentials from the exported copy. It does not remove invisible pixel watermarks.</p>
                        </template>
                    </div>
                </section>

                <section v-if="file" :class="card" class="space-y-4 p-4">
                    <h2 class="text-sm font-semibold">Remove a visible mark</h2>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Select with</label>
                        <div :class="segment" class="grid-cols-3">
                            <button v-for="t in [{ v: 'brush', t: 'Brush', i: 'bi-brush' }, { v: 'box', t: 'Box', i: 'bi-bounding-box' }, { v: 'eraser', t: 'Eraser', i: 'bi-eraser' }]" :key="t.v" type="button" @click="tool = t.v" class="rounded-md px-2 py-1.5" :class="segmentButton(tool === t.v)"><i class="bi mr-1" :class="t.i"></i>{{ t.t }}</button>
                        </div>
                    </div>
                    <div v-if="tool !== 'box'">
                        <label class="flex justify-between text-xs font-medium"><span>Brush size</span><span class="text-slate-500">{{ brushSize }} px</span></label>
                        <input v-model.number="brushSize" type="range" min="2" :max="Math.max(40, Math.round(Math.max(size.width, size.height) / 8))" class="w-full" />
                    </div>
                    <template v-else>
                        <label class="flex items-start gap-2 text-sm"><input v-model="detect" type="checkbox" class="mt-0.5 rounded border-slate-300" /><span>Select only the mark inside the box <span class="block text-xs text-slate-500 dark:text-slate-400">Picks out text and logo strokes, leaving the background to rebuild from. Turn off to remove the whole box.</span></span></label>
                        <div v-if="detect">
                            <label class="flex justify-between text-xs font-medium"><span>Sensitivity</span><span class="text-slate-500">{{ sensitivity }}</span></label>
                            <input v-model.number="sensitivity" type="range" min="1" max="100" class="w-full" />
                            <p class="text-xs text-slate-500 dark:text-slate-400">Raise it for faint, see-through marks; lower it if background detail gets selected.</p>
                        </div>
                    </template>
                    <div>
                        <label class="flex justify-between text-xs font-medium"><span>Grow selection</span><span class="text-slate-500">{{ grow }} px</span></label>
                        <input v-model.number="grow" type="range" min="0" max="12" class="w-full" />
                        <p class="text-xs text-slate-500 dark:text-slate-400">Covers soft edges and shadows around the mark.</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Fill</label>
                        <div :class="segment" class="grid-cols-2">
                            <button type="button" @click="method = 'texture'" class="rounded-md px-2 py-1.5" :class="segmentButton(method === 'texture')">Match texture</button>
                            <button type="button" @click="method = 'smooth'" class="rounded-md px-2 py-1.5" :class="segmentButton(method === 'smooth')">Smooth</button>
                        </div>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ method === 'texture' ? 'Copies matching patches from around the mark; best on photos, grass, fabric and patterns.' : 'Blends the surrounding colors inward; fastest, best for thin text on skies, walls and gradients.' }}</p>
                    </div>
                    <p class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                        <i class="bi bi-shield-check mr-1"></i>Only remove marks from images you own or are licensed to edit.<template v-if="check.report && check.report.verdict !== 'none'"> This image's {{ check.report.verdict === 'ai' ? 'AI label' : check.report.verdict === 'provenance' ? 'Content Credentials' : 'copyright details' }} still apply after a visible mark is removed.</template>
                    </p>
                </section>
            </div>

            <!-- Editor -->
            <div class="space-y-4">
                <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-900/30 dark:text-red-300">{{ error }}</div>

                <div v-if="!file" class="rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm text-slate-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-400">
                    <i class="bi bi-eraser mb-2 block text-3xl"></i>
                    Add an image to see what watermarks and AI labels it carries, then paint over a visible mark to remove it.
                </div>

                <template v-else>
                    <div :class="card" class="flex flex-wrap items-center gap-2 p-3">
                        <button type="button" :disabled="!hasMask || working" @click="remove" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                            <i class="bi mr-1" :class="working ? 'bi-arrow-repeat inline-block animate-spin' : 'bi-magic'"></i>{{ working ? `Removing… ${Math.round(progress * 100)}%` : 'Remove selected' }}
                        </button>
                        <button type="button" :disabled="!hasMask || working" @click="clearMask" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">Clear selection</button>
                        <button type="button" :disabled="!canUndo || working" @click="undo" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700"><i class="bi bi-arrow-counterclockwise mr-1"></i>Undo</button>
                        <button type="button" :disabled="!edited || working" @click="restoreOriginal" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">Start over</button>
                        <button
                            type="button"
                            :disabled="!edited || working"
                            class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700"
                            @pointerdown="comparing = true"
                            @pointerup="comparing = false"
                            @pointerleave="comparing = false"
                        ><i class="bi bi-eye mr-1"></i>Hold to compare</button>
                        <span class="flex-1"></span>
                        <button type="button" :disabled="!edited || working" @click="download" class="rounded-md bg-emerald-600 px-4 py-2 text-sm text-white hover:bg-emerald-700 disabled:opacity-50"><i class="bi bi-download mr-1"></i>Download</button>
                    </div>

                    <div :class="card" class="p-3">
                        <div class="relative mx-auto w-fit max-w-full select-none" style="background: repeating-conic-gradient(#e2e8f0 0 25%, #fff 0 50%) 0 0 / 16px 16px">
                            <canvas ref="imageCanvas" class="block h-auto max-h-[75vh] max-w-full"></canvas>
                            <canvas
                                ref="maskCanvas"
                                class="absolute inset-0 h-full w-full touch-none opacity-50"
                                :class="working ? 'cursor-wait' : 'cursor-crosshair'"
                                @pointerdown="onPointerDown"
                                @pointermove="onPointerMove"
                                @pointerup="onPointerUp"
                                @pointercancel="onPointerUp"
                            ></canvas>
                            <div v-if="boxStyle" class="pointer-events-none absolute border-2 border-dashed border-blue-500 bg-blue-500/10" :style="boxStyle"></div>
                            <img v-if="comparing" :src="originalUrl" alt="Original" class="pointer-events-none absolute inset-0 h-full w-full" />
                        </div>
                        <p class="mt-2 text-center text-xs text-slate-500 dark:text-slate-400">
                            {{ tool === 'box' ? 'Drag a box around the mark.' : tool === 'eraser' ? 'Paint to shrink the red selection.' : 'Paint over the mark; it turns red.' }}
                            Run it again on what's left for a cleaner result. The download is a new file without the original's metadata.
                        </p>
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
