<script setup>
import { computed, ref } from 'vue';

// The fetched HTML rendered in a sandboxed frame. Without allow-same-origin the
// page gets an opaque origin, so even with scripts on it cannot reach the panel.
const props = defineProps({
    report: { type: Object, required: true },
});

const device = ref('desktop');
const scripts = ref(false);
const devices = [
    { key: 'desktop', label: 'Desktop', icon: 'bi-display', width: '100%' },
    { key: 'mobile', label: 'Mobile', icon: 'bi-phone', width: '390px' },
];
const width = computed(() => devices.find((d) => d.key === device.value).width);

const escapeAttr = (value) => value.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
// A <base> so relative styles, images and links load from the inspected site.
const srcdoc = computed(() => {
    const head = `<base href="${escapeAttr(props.report.final_url)}" target="_blank"><meta name="referrer" content="no-referrer">`;
    const html = props.report.html || '';
    return /<head[^>]*>/i.test(html) ? html.replace(/<head[^>]*>/i, (tag) => tag + head) : head + html;
});
const status = computed(() => props.report.http?.status ?? 0);
const isImage = computed(() => status.value === 200 && String(props.report.http?.type ?? '').startsWith('image/'));
const sandbox = computed(() => (scripts.value ? 'allow-scripts allow-popups' : 'allow-popups'));
const toggle = (active) => (active ? 'bg-white text-slate-800 shadow-sm dark:bg-slate-700 dark:text-slate-100' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200');
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-3">
            <div v-if="report.html" class="inline-flex rounded-lg bg-slate-100 p-1 dark:bg-slate-900">
                <button v-for="d in devices" :key="d.key" type="button" class="flex items-center gap-1.5 rounded-md px-3 py-1 text-sm font-medium" :class="toggle(device === d.key)" @click="device = d.key">
                    <i class="bi" :class="d.icon"></i>{{ d.label }}
                </button>
            </div>
            <label v-if="report.html" class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input v-model="scripts" type="checkbox" class="rounded border-slate-300 dark:border-slate-600 dark:bg-slate-900" />
                Run JavaScript
            </label>
            <a :href="report.final_url" target="_blank" rel="noopener noreferrer" class="ml-auto text-sm text-blue-600 hover:underline dark:text-blue-400">
                Open live page<i class="bi bi-box-arrow-up-right ml-1"></i>
            </a>
        </div>

        <p v-if="report.html && status !== 200" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
            <i class="bi bi-exclamation-triangle mr-1"></i>The server answered {{ status }}, so this is its error page, not the page itself.
        </p>
        <p v-if="report.html" class="text-xs text-slate-500 dark:text-slate-400">
            The HTML as fetched with the {{ report.agent }} user agent. Styles and images load from the site; with JavaScript off this is close to what a crawler reads before rendering.
        </p>

        <div v-if="report.html" class="flex justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-900">
            <iframe
                :key="`${device}-${scripts}`"
                :srcdoc="srcdoc"
                :sandbox="sandbox"
                :style="{ width }"
                title="Page preview"
                referrerpolicy="no-referrer"
                class="h-[75vh] max-w-full border-0 bg-white"
            ></iframe>
        </div>
        <div v-else-if="isImage" class="flex justify-center rounded-xl border border-slate-200 bg-slate-100 p-4 dark:border-slate-700 dark:bg-slate-900">
            <img :src="report.final_url" alt="Inspected image" referrerpolicy="no-referrer" class="max-h-[75vh] max-w-full object-contain" />
        </div>
        <div v-else class="rounded-xl border border-dashed border-slate-300 px-4 py-12 text-center dark:border-slate-700">
            <i class="bi bi-window-x text-3xl text-slate-300 dark:text-slate-600"></i>
            <p class="mt-2 text-sm font-medium text-slate-700 dark:text-slate-200">Nothing to preview</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                <template v-if="!status">The page could not be reached.</template>
                <template v-else>The server answered {{ status }} with {{ report.http?.type || 'no content type' }}, not an HTML page.</template>
            </p>
        </div>
    </div>
</template>
