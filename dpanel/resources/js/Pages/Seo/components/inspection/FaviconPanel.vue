<script setup>
import CheckList from '@/Pages/Seo/components/inspection/CheckList.vue';
import { computed } from 'vue';

const props = defineProps({
    report: { type: Object, required: true },
});

const p = computed(() => props.report.preview);
const icons = computed(() => props.report.icons || []);
const tabIcon = computed(() => p.value.favicon_ico || p.value.favicon);
const touch = computed(() => icons.value.find((i) => i.rel.startsWith('apple-touch-icon') && i.preview)?.preview || p.value.favicon);
const checks = computed(() => props.report.sections.find((s) => s.key === 'favicon')?.checks || []);
const relLabel = { 'favicon.ico': '/favicon.ico (default)', 'shortcut icon': 'shortcut icon' };
const dims = (icon) => {
    if (icon.vector) return icon.width ? `SVG · ${icon.width}×${icon.height} viewBox` : 'SVG';
    if (icon.sizes?.length) return icon.sizes.map((s) => `${s}×${s}`).join(', ');
    return icon.width ? `${icon.width}×${icon.height}` : '—';
};
const size = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);
const header = 'border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400';
</script>

<template>
    <div class="space-y-4">
        <div class="grid items-start gap-4 lg:grid-cols-3">
            <!-- Browser tabs, light and dark: a favicon that disappears on one of them shows here. -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 lg:col-span-2">
                <h3 :class="header"><i class="bi bi-window mr-1"></i>Browser tab</h3>
                <div v-for="theme in [{ bar: '#dee1e6', tab: '#ffffff', text: '#3c4043' }, { bar: '#202124', tab: '#35363a', text: '#e8eaed' }]" :key="theme.bar" class="px-4 pt-3" :style="{ background: theme.bar }">
                    <div class="flex max-w-[260px] items-center gap-2 rounded-t-lg px-3 py-2" :style="{ background: theme.tab, color: theme.text }">
                        <img v-if="tabIcon" :src="tabIcon" alt="" class="h-4 w-4 shrink-0 object-contain" />
                        <i v-else class="bi bi-globe2 text-xs"></i>
                        <span class="truncate text-xs">{{ report.meta?.title || p.host }}</span>
                        <i class="bi bi-x ml-auto text-xs opacity-60"></i>
                    </div>
                </div>
            </section>

            <!-- Search result and home screen. -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 :class="header"><i class="bi bi-grid-3x3-gap mr-1"></i>Search &amp; home screen</h3>
                <div class="flex items-center justify-around gap-4 bg-white p-4">
                    <div class="text-center">
                        <span class="mx-auto flex h-[26px] w-[26px] items-center justify-center rounded-full border border-[#dadce0] bg-[#f1f3f4]">
                            <img v-if="p.favicon" :src="p.favicon" alt="" class="h-[18px] w-[18px] object-contain" />
                            <i v-else class="bi bi-globe2 text-xs text-[#5f6368]"></i>
                        </span>
                        <p class="mt-2 text-[11px] text-slate-500">Google result</p>
                    </div>
                    <div class="text-center">
                        <span class="mx-auto flex h-[60px] w-[60px] items-center justify-center overflow-hidden rounded-[14px] bg-slate-200">
                            <img v-if="touch" :src="touch" alt="" class="h-full w-full object-cover" />
                            <span v-else class="text-xl font-semibold text-slate-500">{{ (p.site_name || '?').charAt(0).toUpperCase() }}</span>
                        </span>
                        <p class="mt-2 text-[11px] text-slate-500">iOS home screen</p>
                    </div>
                </div>
            </section>
        </div>

        <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
            <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold dark:border-slate-700">Icons found</h2>
            <ul class="divide-y divide-slate-100 dark:divide-slate-700">
                <li v-for="icon in icons" :key="icon.url + icon.rel" class="flex items-center gap-4 px-4 py-3">
                    <!-- Checkerboard so transparent icons stay visible. -->
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg border border-slate-200 dark:border-slate-600" style="background: repeating-conic-gradient(#e2e8f0 0 25%, #fff 0 50%) 0 0 / 12px 12px">
                        <img v-if="icon.preview" :src="icon.preview" alt="" class="max-h-12 max-w-12 object-contain" />
                        <i v-else class="bi bi-image text-slate-400"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                            {{ relLabel[icon.rel] || icon.rel }}
                            <span v-if="icon.google" class="rounded bg-blue-50 px-1.5 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">Used by Google</span>
                            <span v-if="icon.error" class="rounded bg-red-50 px-1.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ icon.error }}</span>
                        </p>
                        <a :href="icon.url" target="_blank" rel="noopener noreferrer" class="block truncate font-mono text-xs text-blue-600 hover:underline dark:text-blue-400">{{ icon.url }}</a>
                        <p v-if="!icon.error" class="text-xs text-slate-500 dark:text-slate-400">
                            {{ dims(icon) }} · {{ icon.mime }} · {{ size(icon.bytes) }}<template v-if="icon.declared_sizes"> · declared {{ icon.declared_sizes }}</template><template v-if="icon.redirects"> · {{ icon.redirects }} redirect{{ icon.redirects > 1 ? 's' : '' }}</template>
                        </p>
                    </div>
                </li>
            </ul>
        </section>

        <CheckList title="Favicon checks" :checks="checks" />
    </div>
</template>
