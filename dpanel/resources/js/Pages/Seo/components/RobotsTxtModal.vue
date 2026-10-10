<script setup>
import Modal from '@/Components/Modal.vue';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    // discover()'s robots: { url, status, content, error, file: { editable, reason, path, exists } }
    robots: { type: Object, default: null },
    baseUrl: { type: String, default: '' },
    sitemaps: { type: Array, default: () => [] },
    saving: { type: Boolean, default: false },
    saveError: { type: String, default: '' },
});
const emit = defineEmits(['close', 'save']);

const text = ref('');
const original = ref('');
watch(() => [props.show, props.robots], () => {
    if (!props.show) return;
    original.value = props.robots?.content ?? '';
    text.value = original.value;
}, { immediate: true });

const editable = computed(() => !!props.robots?.file?.editable);
const served = computed(() => props.robots?.status === 200);
const dirty = computed(() => text.value !== original.value);
const host = computed(() => props.baseUrl.replace(/^https?:\/\//, '').toLowerCase());
const sameSite = (h) => h.replace(/^www\./, '') === host.value.replace(/^www\./, '');

const KNOWN = ['user-agent', 'allow', 'disallow', 'sitemap', 'crawl-delay', 'host', 'clean-param'];

// Same rules as the server's check, re-run on every keystroke; each fix
// rewrites the text, and the warning disappears once its cause is gone.
const findings = computed(() => {
    const out = [];
    const lines = text.value.split(/\r?\n/);
    const replaceLines = (indexes, replacement = null) => (current) => current.split(/\r?\n/)
        .flatMap((line, i) => (indexes.includes(i) ? (replacement === null ? [] : [replacement]) : [line]))
        .join('\n');

    if (!text.value.trim()) {
        const sitemapLines = (props.sitemaps.length ? props.sitemaps : [`${props.baseUrl}/sitemap.xml`]).map((u) => `Sitemap: ${u}`);
        out.push({
            level: served.value ? 'warn' : 'fail',
            message: served.value ? 'robots.txt is empty.' : `No robots.txt is served (${props.robots?.error || `HTTP ${props.robots?.status}`}).`,
            fix: { label: 'Create a standard robots.txt', apply: () => ['User-agent: *', 'Allow: /', '', ...sitemapLines].join('\n') },
        });
        return out;
    }

    let agents = [];
    let inRules = false;
    let seenAgent = false;
    const blockAll = [];
    const sitemapLines = [];
    lines.forEach((raw, i) => {
        const line = raw.replace(/#.*/, '').trim();
        if (!line) return;
        const at = `Line ${i + 1}`;
        const colon = line.indexOf(':');
        if (colon < 1) {
            out.push({ level: 'warn', line: i, message: `${at}: "${line}" is not a directive (expected "Field: value").`, fix: { label: 'Remove line', apply: replaceLines([i]) } });
            return;
        }
        const field = line.slice(0, colon).trim().toLowerCase();
        const value = line.slice(colon + 1).trim();
        if (!KNOWN.includes(field)) {
            out.push({ level: 'warn', line: i, message: `${at}: unknown directive "${field}"; crawlers ignore it.`, fix: { label: 'Remove line', apply: replaceLines([i]) } });
            return;
        }
        if (field === 'user-agent') {
            agents = inRules ? [value.toLowerCase()] : [...agents, value.toLowerCase()];
            inRules = false;
            seenAgent = true;
        } else if (field === 'sitemap') {
            sitemapLines.push(i);
            let url = null;
            try { url = new URL(value); } catch (e) { /* not absolute */ }
            if (!url || !/^https?:$/.test(url.protocol)) {
                out.push({ level: 'fail', line: i, message: `${at}: "${value}" is not a full URL; Sitemap needs https://…`, fix: { label: 'Remove line', apply: replaceLines([i]) } });
            } else if (!sameSite(url.hostname.toLowerCase())) {
                out.push({ level: 'warn', line: i, message: `${at}: the sitemap is on ${url.hostname}, another host. That only works if that host also trusts it.`, fix: { label: 'Remove line', manual: true, apply: replaceLines([i]) } });
            }
        } else if (['allow', 'disallow', 'crawl-delay', 'clean-param'].includes(field)) {
            if (!seenAgent) {
                out.push({ level: 'warn', line: i, message: `${at}: "${field}" comes before any User-agent line, so it applies to nobody.`, fix: { label: 'Add "User-agent: *" above', apply: (current) => { const l = current.split(/\r?\n/); l.splice(i, 0, 'User-agent: *'); return l.join('\n'); } } });
            }
            inRules = true;
            if (field === 'disallow' && value === '/' && agents.includes('*')) blockAll.push(i);
        }
    });

    if (blockAll.length) {
        out.unshift({ level: 'fail', message: '"User-agent: *" has "Disallow: /": search engines will not crawl any page.', fix: { label: 'Allow crawling', apply: replaceLines(blockAll, 'Allow: /') } });
    }
    if (!sitemapLines.length) {
        const urls = props.sitemaps.length ? props.sitemaps : [`${props.baseUrl}/sitemap.xml`];
        out.push({ level: 'warn', message: 'No Sitemap line, so crawlers only find your sitemap through Search Console.', fix: { label: `Add ${urls.length === 1 ? 'Sitemap line' : `${urls.length} Sitemap lines`}`, apply: (current) => `${current.replace(/\s+$/, '')}\n\n${urls.map((u) => `Sitemap: ${u}`).join('\n')}` } });
    }
    if (new Blob([text.value]).size > 512000) {
        out.push({ level: 'warn', message: 'Larger than 500 KiB; Google ignores everything after that.' });
    }
    return out;
});

const applyFix = (finding) => { text.value = finding.fix.apply(text.value); };
const applyAll = () => {
    // Re-evaluated after each fix, so line numbers stay right.
    for (let guard = 0; guard < 50; guard++) {
        const next = findings.value.find((f) => f.fix && !f.fix.manual);
        if (!next) break;
        applyFix(next);
    }
};
// Removing a cross-host Sitemap line may be wrong, so "Fix all" leaves it alone.
const fixable = computed(() => findings.value.filter((f) => f.fix && !f.fix.manual).length);
const lineCount = computed(() => text.value.split('\n').length);
const levelClass = { fail: 'bi-x-circle-fill text-red-600 dark:text-red-400', warn: 'bi-exclamation-triangle-fill text-amber-500' };

const close = () => {
    if (dirty.value && !confirm('Discard your unsaved changes to robots.txt?')) return;
    emit('close');
};
</script>

<template>
    <Modal :show="show" max-width="4xl" @close="close">
        <div class="flex max-h-[90vh] flex-col text-slate-900 dark:text-slate-100">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <div class="min-w-0">
                    <h2 class="font-semibold">robots.txt</h2>
                    <a :href="robots?.url" target="_blank" rel="noopener noreferrer" class="block truncate font-mono text-xs text-blue-600 hover:underline dark:text-blue-400">{{ robots?.url }}</a>
                </div>
                <button type="button" @click="close" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800">
                    <span class="sr-only">Close</span><i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="flex-1 space-y-3 overflow-y-auto px-5 py-4">
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    <template v-if="editable">
                        <i class="bi bi-pencil-square"></i>
                        Editable · {{ robots.file.exists ? 'saves to' : 'no file yet; saving creates' }} <span class="font-mono">{{ robots.file.path }}</span>
                        <template v-if="!robots.file.exists && served"> (it replaces the robots.txt your app generates now)</template>
                    </template>
                    <template v-else><i class="bi bi-lock"></i> Read-only · {{ robots?.file?.reason }}</template>
                </p>

                <!-- The textarea grows with its content and this box scrolls, so the line numbers stay aligned. -->
                <div class="flex max-h-[50vh] overflow-auto rounded-lg border border-slate-300 font-mono text-xs dark:border-slate-600">
                    <div class="sticky left-0 select-none self-start bg-slate-50 px-2 py-2 text-right leading-5 text-slate-400 dark:bg-slate-800" aria-hidden="true">
                        <div v-for="n in lineCount" :key="n">{{ n }}</div>
                    </div>
                    <textarea
                        v-model="text"
                        :readonly="!editable"
                        spellcheck="false"
                        wrap="off"
                        :rows="Math.max(lineCount, 8)"
                        class="min-w-0 flex-1 resize-none overflow-y-hidden border-0 bg-white px-3 py-2 font-mono text-xs leading-5 focus:ring-0 dark:bg-slate-900"
                        :placeholder="editable ? 'Empty: use the fix below to create a standard robots.txt.' : ''"
                    ></textarea>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-sm font-semibold">
                            {{ findings.length ? `${findings.length} ${findings.length === 1 ? 'issue' : 'issues'}` : 'No issues' }}
                        </h3>
                        <button v-if="editable && fixable > 1" type="button" @click="applyAll" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-800">
                            <i class="bi bi-magic mr-1"></i>Fix all ({{ fixable }})
                        </button>
                    </div>
                    <p v-if="!findings.length" class="flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400">
                        <i class="bi bi-check-circle-fill"></i> Crawlers can read this file and find your sitemap.
                    </p>
                    <ul class="space-y-2">
                        <li v-for="(finding, i) in findings" :key="i" class="flex items-start gap-3 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700">
                            <i class="bi mt-0.5" :class="levelClass[finding.level]"></i>
                            <span class="min-w-0 flex-1 text-sm">{{ finding.message }}</span>
                            <button
                                v-if="editable && finding.fix"
                                type="button"
                                @click="applyFix(finding)"
                                class="shrink-0 rounded-md bg-blue-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-blue-700"
                            >
                                {{ finding.fix.label }}
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <div v-if="editable" class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-5 py-3 dark:border-slate-700">
                <p v-if="saveError" class="mr-auto text-sm text-red-600">{{ saveError }}</p>
                <p v-else-if="dirty" class="mr-auto text-xs text-amber-600">Unsaved changes. Fixes only change the text until you save.</p>
                <button type="button" :disabled="!dirty || saving" @click="text = original" class="rounded-md border border-slate-300 px-3 py-2 text-sm disabled:opacity-50 dark:border-slate-600">Reset</button>
                <button type="button" :disabled="!dirty || saving" @click="emit('save', text)" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                    <i v-if="saving" class="bi bi-arrow-repeat mr-1 inline-block animate-spin"></i>Save robots.txt
                </button>
            </div>
        </div>
    </Modal>
</template>
