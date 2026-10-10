<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import IconMockups from '@/Pages/Seo/components/icons/IconMockups.vue';
import IconSizeMap from '@/Pages/Seo/components/icons/IconSizeMap.vue';
import {
    GROUPS, IMAGES, buildBrowserconfig, buildHead, buildIco, buildManifest, buildZip, loadSource, renderIcon, renderShareImage, sitePath, toBlob,
} from '@/Pages/Seo/iconSet';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import { Head, Link } from '@inertiajs/vue3';
import { computed, markRaw, onBeforeUnmount, reactive, ref, shallowRef, watch } from 'vue';

const props = defineProps({
    websites: { type: Array, default: () => [] },
});

const { panelRoute, requestJson, csrfToken } = usePanelApi();

// Source image, kept out of Vue's reactivity (it holds a large canvas).
const source = shallowRef(null);
const loadingSource = ref(false);
const error = ref('');
const dragging = ref(false);

const options = reactive({
    name: '',
    shortName: '',
    description: '',
    startUrl: '/',
    display: 'standalone',
    themeColor: '#0f766e',
    backgroundColor: '#ffffff',
    trim: true,
    faviconStyle: 'transparent', // transparent | square | rounded | circle
    faviconPadding: 0,
    applePadding: 12,
    maskablePadding: 20,
    folder: '',
    og: { layout: 'logo-text', background: '#0f766e', title: '', subtitle: '', showDomain: true },
});
const groups = reactive({ favicon: true, apple: true, pwa: true, windows: false, social: true });

const websiteId = ref(props.websites[0]?.id || '');
const website = computed(() => props.websites.find((w) => w.id === websiteId.value) || null);
const siteUrl = computed(() => (website.value ? `${website.value.enable_ssl ? 'https' : 'http'}://${website.value.domain}` : ''));
const domain = computed(() => website.value?.domain.replace(/^www\./, '') || '');
const websiteOptions = computed(() => [{ value: '', label: 'No website (download only)' }, ...props.websites.map((w) => ({ value: w.id, label: w.domain }))]);
const folderValid = computed(() => /^([a-z0-9][a-z0-9_-]*(\/[a-z0-9][a-z0-9_-]*)*)?$/.test(options.folder));

const pickFile = async (file) => {
    if (!file) return;
    if (!/^image\//.test(file.type) && !/\.(svg|png|jpe?g|webp|gif|avif|ico)$/i.test(file.name)) {
        error.value = 'Choose an image: SVG, PNG, JPG, WebP, GIF or AVIF.';
        return;
    }
    if (file.size > 20 * 1024 * 1024) {
        error.value = 'The image is larger than 20 MB.';
        return;
    }
    loadingSource.value = true;
    error.value = '';
    try {
        source.value = markRaw(await loadSource(file, { trim: options.trim }));
        sourceFile = file;
        if (!options.name) options.name = file.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    } catch (e) {
        error.value = e.message || 'The image could not be read.';
    } finally {
        loadingSource.value = false;
    }
};
let sourceFile = null;
const onDrop = (event) => {
    dragging.value = false;
    pickFile(event.dataTransfer?.files?.[0]);
};
// Trimming changes the master image itself.
watch(() => options.trim, () => { if (sourceFile) pickFile(sourceFile); });
watch(() => options.themeColor, (color, old) => { if (options.og.background === old) options.og.background = color; });
watch(() => options.name, (name, old) => {
    if (!options.og.title || options.og.title === old) options.og.title = name;
    if (!options.shortName || options.shortName === (old || '').slice(0, 12)) options.shortName = name.slice(0, 12);
});

// Generated files: { name, group, width, height, blob, url, use }.
const files = shallowRef([]);
const generating = ref(false);

const faviconBackground = computed(() => {
    const style = options.faviconStyle;
    return style === 'transparent' ? {} : { background: options.backgroundColor, radius: style === 'rounded' ? 0.22 : style === 'circle' ? 0.5 : 0 };
});

const textFiles = computed(() => {
    const out = [];
    if (groups.pwa) out.push({ name: 'site.webmanifest', group: 'pwa', type: 'application/manifest+json', content: buildManifest(options, options.folder, true), use: 'Web app manifest: name, colors and icons for installing the site' });
    if (groups.windows) out.push({ name: 'browserconfig.xml', group: 'windows', type: 'application/xml', content: buildBrowserconfig(options, options.folder), use: 'Windows tile settings' });
    return out;
});
const installable = computed(() => [...files.value.map((f) => f.name), ...textFiles.value.map((f) => f.name)]);
const headSnippet = computed(() => buildHead(options, options.folder, installable.value, siteUrl.value));

let generation = 0;
const generate = async () => {
    const src = source.value;
    if (!src) return;
    const run = ++generation;
    generating.value = true;
    const padding = options.faviconPadding / 100;
    const out = [];
    try {
        for (const image of IMAGES) {
            if (!groups[image.group] || (image.vectorOnly && !src.vector)) continue;
            let blob;
            if (image.name === 'favicon.ico') {
                blob = await buildIco(image.sizes.map((size) => renderIcon(src, size, { ...faviconBackground.value, padding })));
            } else if (image.name === 'favicon.svg') {
                blob = new Blob([src.svgText], { type: 'image/svg+xml' });
            } else if (image.name === 'og-image.png') {
                blob = await toBlob(renderShareImage(src, { ...options.og, domain: options.og.showDomain ? domain.value : '' }));
            } else if (image.maskable) {
                blob = await toBlob(renderIcon(src, image.width, { background: options.backgroundColor, padding: options.maskablePadding / 100 }));
            } else if (image.opaque) {
                blob = await toBlob(renderIcon(src, image.width, { background: options.backgroundColor, padding: options.applePadding / 100 }));
            } else if (image.name.startsWith('mstile')) {
                blob = await toBlob(renderIcon(src, image.width, { padding: 0.18 }));
            } else {
                blob = await toBlob(renderIcon(src, image.width, { ...faviconBackground.value, padding }));
            }
            if (run !== generation) return;
            out.push({ ...image, blob, url: URL.createObjectURL(blob) });
        }
        files.value.forEach((f) => URL.revokeObjectURL(f.url));
        files.value = out;
    } finally {
        if (run === generation) generating.value = false;
    }
};
let timer = null;
watch([source, () => JSON.stringify(options), () => JSON.stringify(groups), domain], () => {
    clearTimeout(timer);
    timer = setTimeout(generate, 250);
});
onBeforeUnmount(() => files.value.forEach((f) => URL.revokeObjectURL(f.url)));

const fileUrl = (name) => files.value.find((f) => f.name === name)?.url || null;
const totalBytes = computed(() => files.value.reduce((sum, f) => sum + f.blob.size, 0));

const bundle = () => [
    ...files.value.map((f) => ({ name: f.name, blob: f.blob })),
    ...textFiles.value.map((f) => ({ name: f.name, blob: new Blob([f.content], { type: f.type }) })),
];

const downloadZip = async () => {
    const readme = `Upload these files to your website${options.folder ? ` (favicon.ico to the site root, the rest to /${options.folder}/)` : "'s root folder"}, then paste head-snippet.html into the <head> of every page.\n`;
    const zip = await buildZip([
        ...bundle().map((f) => ({ name: f.name === 'favicon.ico' || !options.folder ? f.name : `${options.folder}/${f.name}`, blob: f.blob })),
        { name: 'head-snippet.html', blob: new Blob([headSnippet.value], { type: 'text/html' }) },
        { name: 'README.txt', blob: new Blob([readme], { type: 'text/plain' }) },
    ]);
    const link = document.createElement('a');
    link.href = URL.createObjectURL(zip);
    link.download = `${(options.shortName || options.name || 'icons').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'icons'}-icons.zip`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
};

const copied = ref('');
const copy = async (key, text) => {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        setTimeout(() => { if (copied.value === key) copied.value = ''; }, 1500);
    } catch {
        copied.value = '';
    }
};

// Install into the selected website: check first, confirm what gets replaced.
const install = reactive({ open: false, checking: false, saving: false, existing: [], reason: '', error: '', done: null });
const startInstall = async () => {
    Object.assign(install, { open: true, checking: true, existing: [], reason: '', error: '', done: null });
    try {
        const target = await requestJson(panelRoute('seo.icon-generator.target'), { body: { website_id: websiteId.value, folder: options.folder, names: installable.value } });
        install.reason = target.writable ? '' : target.reason;
        install.existing = target.existing;
    } catch (e) {
        install.error = e.message || 'The website could not be checked.';
    } finally {
        install.checking = false;
    }
};
const confirmInstall = async () => {
    install.saving = true;
    install.error = '';
    const form = new FormData();
    form.append('website_id', websiteId.value);
    form.append('folder', options.folder);
    bundle().forEach((f) => form.append(`files[${f.name}]`, f.blob, f.name));
    try {
        const response = await fetch(panelRoute('seo.icon-generator.install'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken.value },
            body: form,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'The icons were not installed.');
        install.done = data;
    } catch (e) {
        install.error = e.message;
    } finally {
        install.saving = false;
    }
};

const field = 'w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900';
const card = 'rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <Head title="Icon Generator" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Icon Generator</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">One image in; favicons, Apple and Android icons, web app manifest and share image out, as a ZIP or straight onto your website.</p>
            </div>
        </template>

        <div class="grid items-start gap-4 xl:grid-cols-[24rem_1fr]">
            <!-- Settings -->
            <div class="space-y-4">
                <section :class="card" class="p-4">
                    <label
                        class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-5 text-center text-sm transition"
                        :class="dragging ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-slate-300 hover:border-blue-400 dark:border-slate-600'"
                        @dragover.prevent="dragging = true"
                        @dragleave.prevent="dragging = false"
                        @drop.prevent="onDrop"
                    >
                        <input type="file" accept="image/svg+xml,image/png,image/jpeg,image/webp,image/gif,image/avif" class="sr-only" @change="pickFile($event.target.files[0]); $event.target.value = ''" />
                        <span v-if="source" class="flex h-24 w-24 items-center justify-center rounded-lg" style="background: repeating-conic-gradient(#e2e8f0 0 25%, #fff 0 50%) 0 0 / 12px 12px">
                            <img :src="fileUrl('android-chrome-512x512.png') || fileUrl('apple-touch-icon.png') || fileUrl('favicon-96x96.png') || ''" alt="" class="max-h-20 max-w-20 object-contain" />
                        </span>
                        <i v-else class="bi text-3xl text-slate-400" :class="loadingSource ? 'bi-arrow-repeat inline-block animate-spin' : 'bi-cloud-arrow-up'"></i>
                        <span v-if="source" class="font-medium">{{ source.name }} <span class="font-normal text-slate-500">· {{ source.width }}×{{ source.height }}{{ source.vector ? ' SVG' : '' }}</span></span>
                        <span v-else class="font-medium">Drop your logo here or click to choose</span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">SVG gives the sharpest result; otherwise a square PNG of at least 512×512.</span>
                    </label>
                    <p v-if="source && !source.vector && Math.min(source.width, source.height) < 512" class="mt-2 text-xs text-amber-600 dark:text-amber-400"><i class="bi bi-exclamation-triangle mr-1"></i>Smaller than 512 px; the large icons will look soft.</p>
                    <p v-if="source && Math.abs(source.width / source.height - 1) > 0.15" class="mt-2 text-xs text-slate-500 dark:text-slate-400"><i class="bi bi-info-circle mr-1"></i>Not square; it is centered with space around it.</p>
                    <label class="mt-3 flex items-center gap-2 text-sm"><input v-model="options.trim" type="checkbox" class="rounded border-slate-300" />Trim transparent borders</label>
                </section>

                <section :class="card" class="space-y-3 p-4">
                    <h2 class="text-sm font-semibold">App &amp; colors</h2>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2">
                            <label class="mb-1 block text-xs font-medium">Name</label>
                            <input v-model="options.name" type="text" maxlength="60" placeholder="My Shop" :class="field" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium">Short name <span class="font-normal text-slate-400">(≤ 12)</span></label>
                            <input v-model="options.shortName" type="text" maxlength="30" :class="field" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium">Display</label>
                            <select v-model="options.display" :class="field">
                                <option value="standalone">standalone</option>
                                <option value="minimal-ui">minimal-ui</option>
                                <option value="fullscreen">fullscreen</option>
                                <option value="browser">browser</option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="mb-1 block text-xs font-medium">Description <span class="font-normal text-slate-400">(optional)</span></label>
                            <input v-model="options.description" type="text" maxlength="160" :class="field" />
                        </div>
                        <div v-for="c in [{ key: 'themeColor', label: 'Theme color' }, { key: 'backgroundColor', label: 'Background' }]" :key="c.key">
                            <label class="mb-1 block text-xs font-medium">{{ c.label }}</label>
                            <div class="flex items-center gap-2">
                                <input v-model="options[c.key]" type="color" class="h-9 w-10 shrink-0 cursor-pointer rounded border border-slate-300 bg-transparent p-0.5 dark:border-slate-600" />
                                <input v-model="options[c.key]" type="text" maxlength="7" :class="field" class="font-mono" />
                            </div>
                        </div>
                    </div>
                </section>

                <section :class="card" class="space-y-3 p-4">
                    <h2 class="text-sm font-semibold">Icon style</h2>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Favicon background</label>
                        <div class="grid grid-cols-4 gap-1 rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs dark:border-slate-700 dark:bg-slate-900">
                            <button v-for="s in ['transparent', 'square', 'rounded', 'circle']" :key="s" type="button" @click="options.faviconStyle = s" class="rounded-md px-2 py-1.5 capitalize" :class="options.faviconStyle === s ? 'bg-white font-medium shadow-sm dark:bg-slate-700' : 'text-slate-500'">{{ s }}</button>
                        </div>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">A dark logo on a transparent background disappears on dark browser tabs; a background keeps it visible.</p>
                    </div>
                    <div v-for="s in [
                        { key: 'faviconPadding', label: 'Favicon padding', max: 25, hint: 'Small icons usually look best with little or no padding.' },
                        { key: 'applePadding', label: 'Apple icon padding', max: 30, hint: '' },
                        { key: 'maskablePadding', label: 'Maskable safe zone', max: 35, hint: 'Android crops adaptive icons to a circle; 20% keeps a square logo inside it.' },
                    ]" :key="s.key">
                        <label class="flex justify-between text-xs font-medium"><span>{{ s.label }}</span><span class="text-slate-500">{{ options[s.key] }}%</span></label>
                        <input v-model.number="options[s.key]" type="range" min="0" :max="s.max" class="w-full" />
                        <p v-if="s.hint" class="text-xs text-slate-500 dark:text-slate-400">{{ s.hint }}</p>
                    </div>
                </section>

                <section v-if="groups.social" :class="card" class="space-y-3 p-4">
                    <h2 class="text-sm font-semibold">Share image (1200×630)</h2>
                    <div class="grid grid-cols-3 gap-1 rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs dark:border-slate-700 dark:bg-slate-900">
                        <button v-for="l in [{ v: 'logo-text', t: 'Logo + text' }, { v: 'logo', t: 'Logo only' }, { v: 'text', t: 'Text only' }]" :key="l.v" type="button" @click="options.og.layout = l.v" class="rounded-md px-2 py-1.5" :class="options.og.layout === l.v ? 'bg-white font-medium shadow-sm dark:bg-slate-700' : 'text-slate-500'">{{ l.t }}</button>
                    </div>
                    <template v-if="options.og.layout !== 'logo'">
                        <input v-model="options.og.title" type="text" maxlength="90" placeholder="Title" :class="field" />
                        <input v-model="options.og.subtitle" type="text" maxlength="140" placeholder="Subtitle (optional)" :class="field" />
                    </template>
                    <div class="flex items-center gap-2">
                        <input v-model="options.og.background" type="color" class="h-9 w-10 shrink-0 cursor-pointer rounded border border-slate-300 bg-transparent p-0.5 dark:border-slate-600" />
                        <input v-model="options.og.background" type="text" maxlength="7" :class="field" class="font-mono" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input v-model="options.og.showDomain" type="checkbox" class="rounded border-slate-300" />Show the website domain</label>
                </section>

                <section :class="card" class="space-y-3 p-4">
                    <h2 class="text-sm font-semibold">Website</h2>
                    <SearchableSelect v-model="websiteId" :options="websiteOptions" placeholder="Select a website…" search-placeholder="Search domains…" />
                    <div>
                        <label class="mb-1 block text-xs font-medium">Folder <span class="font-normal text-slate-400">(empty = site root)</span></label>
                        <div class="flex items-center rounded-lg border border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                            <span class="px-2 text-slate-400">/</span>
                            <input v-model.trim="options.folder" type="text" placeholder="icons" class="min-w-0 flex-1 border-0 bg-transparent py-2 pl-0 text-sm focus:ring-0" />
                        </div>
                        <p v-if="!folderValid" class="mt-1 text-xs text-red-600">Lowercase letters, digits, - and _ only, e.g. icons or assets/icons.</p>
                        <p v-else class="mt-1 text-xs text-slate-500 dark:text-slate-400">favicon.ico always goes to the root; Bing and Yandex look for it there.</p>
                    </div>
                </section>
            </div>

            <!-- Output -->
            <div class="space-y-4">
                <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-900/30 dark:text-red-300">{{ error }}</div>

                <div v-if="!source" class="rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm text-slate-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-400">
                    <i class="bi bi-images mb-2 block text-3xl"></i>
                    Add a logo to see every icon size, how it looks on phones, tabs and search results, and download the set.
                </div>

                <template v-else>
                    <div :class="card" class="flex flex-wrap items-center gap-3 p-4">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ installable.length }} files <span class="font-normal text-slate-500">· {{ Math.max(1, Math.round(totalBytes / 1024)) }} KB</span></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Plus head-snippet.html with the tags to paste into your pages.</p>
                        </div>
                        <i v-if="generating" class="bi bi-arrow-repeat inline-block animate-spin text-slate-400"></i>
                        <button type="button" :disabled="generating || !files.length || !folderValid" @click="downloadZip" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">
                            <i class="bi bi-file-earmark-zip mr-1"></i>Download ZIP
                        </button>
                        <button type="button" :disabled="generating || !files.length || !websiteId || !folderValid" :title="websiteId ? '' : 'Select a website first'" @click="startInstall" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                            <i class="bi bi-cloud-upload mr-1"></i>Install on {{ website?.domain || 'website' }}
                        </button>
                    </div>

                    <IconMockups :file-url="fileUrl" :options="options" :domain="domain" :groups="groups" />

                    <IconSizeMap :files="files" :text-files="textFiles" :groups="groups" :group-labels="GROUPS" :images="IMAGES" :folder="options.folder" :site-path="sitePath" :vector="source.vector" @toggle="(g) => (groups[g] = !groups[g])" />

                    <section :class="card">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-700">
                            <h2 class="text-sm font-semibold">Paste into &lt;head&gt;</h2>
                            <button type="button" @click="copy('head', headSnippet)" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-700">
                                <i class="bi mr-1" :class="copied === 'head' ? 'bi-check2' : 'bi-clipboard'"></i>{{ copied === 'head' ? 'Copied' : 'Copy' }}
                            </button>
                        </div>
                        <pre class="overflow-x-auto px-4 py-3 font-mono text-xs leading-5">{{ headSnippet }}</pre>
                        <p v-if="groups.social && !siteUrl" class="border-t border-slate-100 px-4 py-2 text-xs text-amber-600 dark:border-slate-700 dark:text-amber-400">og:image must be a full URL; replace https://example.com with your site's address, or select a website.</p>
                    </section>

                    <section v-for="f in textFiles" :key="f.name" :class="card">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-700">
                            <h2 class="font-mono text-sm font-semibold">{{ f.name }}</h2>
                            <button type="button" @click="copy(f.name, f.content)" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-700">
                                <i class="bi mr-1" :class="copied === f.name ? 'bi-check2' : 'bi-clipboard'"></i>{{ copied === f.name ? 'Copied' : 'Copy' }}
                            </button>
                        </div>
                        <pre class="max-h-72 overflow-auto px-4 py-3 font-mono text-xs leading-5">{{ f.content }}</pre>
                    </section>
                </template>
            </div>
        </div>

        <Modal :show="install.open" max-width="lg" @close="install.open = false">
            <div class="space-y-4 p-6">
                <h2 class="text-lg font-semibold">Install on {{ website?.domain }}</h2>
                <p v-if="install.checking" class="text-sm text-slate-500"><i class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>Checking the website folder…</p>
                <template v-else-if="install.done">
                    <p class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-300">{{ install.done.message }}</p>
                    <p class="text-sm text-slate-600 dark:text-slate-300">Next, paste the &lt;head&gt; tags into your site's pages, then check the result.</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="copy('head', headSnippet)" class="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-600">{{ copied === 'head' ? 'Copied' : 'Copy head tags' }}</button>
                        <Link :href="panelRoute('seo.url-inspection.index')" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700">Open URL Inspection</Link>
                    </div>
                </template>
                <template v-else>
                    <p v-if="install.reason" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-900/30 dark:text-amber-300">{{ install.reason }} Download the ZIP instead.</p>
                    <template v-else>
                        <p class="text-sm text-slate-600 dark:text-slate-300">{{ installable.length }} files will be written to the website's public folder{{ options.folder ? ` (/${options.folder}/, favicon.ico in the root)` : '' }}.</p>
                        <div v-if="install.existing.length" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-900/30 dark:text-amber-300">
                            <p class="font-medium">These files already exist and will be replaced:</p>
                            <ul class="mt-1 list-inside list-disc font-mono text-xs">
                                <li v-for="path in install.existing" :key="path">{{ path }}</li>
                            </ul>
                        </div>
                    </template>
                    <p v-if="install.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-900/30 dark:text-red-300">{{ install.error }}</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="install.open = false" class="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-600">Cancel</button>
                        <button v-if="!install.reason" type="button" :disabled="install.saving" @click="confirmInstall" class="rounded-md px-4 py-2 text-sm text-white disabled:opacity-50" :class="install.existing.length ? 'bg-amber-600 hover:bg-amber-700' : 'bg-blue-600 hover:bg-blue-700'">
                            <i v-if="install.saving" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>{{ install.existing.length ? 'Replace and install' : 'Install' }}
                        </button>
                    </div>
                </template>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
