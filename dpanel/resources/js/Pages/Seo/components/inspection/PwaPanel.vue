<script setup>
import CheckList from '@/Pages/Seo/components/inspection/CheckList.vue';
import { computed } from 'vue';

const props = defineProps({
    report: { type: Object, required: true },
});

const app = computed(() => props.report.preview?.app);
const manifest = computed(() => props.report.manifest);
const checks = computed(() => props.report.sections.find((s) => s.key === 'pwa')?.checks || []);
const fields = computed(() => Object.entries(manifest.value?.data || {}).map(([k, v]) => [k, Array.isArray(v) ? v.join(', ') : String(v)]));
const isColor = (key) => key.endsWith('_color');
const header = 'border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400';
</script>

<template>
    <div class="space-y-4">
        <div v-if="!manifest" class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-400">
            <i class="bi bi-phone mb-2 block text-2xl"></i>
            This page links no web app manifest, so it cannot be installed as an app.
            Add <code class="rounded bg-slate-100 px-1 dark:bg-slate-700">&lt;link rel="manifest" href="/site.webmanifest"&gt;</code> to make it installable.
        </div>

        <div v-if="app" class="grid items-start gap-4 lg:grid-cols-3">
            <!-- Install prompt -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 :class="header"><i class="bi bi-download mr-1"></i>Install prompt</h3>
                <div class="flex justify-center bg-slate-100 p-5 dark:bg-slate-900">
                    <div class="w-full max-w-[300px] rounded-xl bg-white p-4 text-[#202124] shadow-md">
                        <div class="flex items-center gap-3">
                            <img v-if="app.icon" :src="app.icon" alt="" class="h-10 w-10 rounded-lg object-contain" />
                            <span v-else class="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-200 font-semibold text-slate-500">{{ app.name.charAt(0) }}</span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">Install {{ app.name }}?</p>
                                <p class="truncate text-xs text-[#5f6368]">{{ report.preview.host }}</p>
                            </div>
                        </div>
                        <div class="mt-4 flex justify-end gap-2 text-sm">
                            <span class="rounded-full px-3 py-1 text-[#1a73e8]">Cancel</span>
                            <span class="rounded-full bg-[#1a73e8] px-4 py-1 text-white">Install</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Home screen icon, as Android masks it -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 :class="header"><i class="bi bi-android2 mr-1"></i>Home screen</h3>
                <div class="flex items-center justify-center gap-6 bg-gradient-to-br from-indigo-500 to-teal-500 p-6">
                    <div v-for="shape in ['rounded-full', 'rounded-[22%]']" :key="shape" class="w-16 text-center">
                        <span class="mx-auto flex h-14 w-14 items-center justify-center overflow-hidden bg-white" :class="shape">
                            <img v-if="app.icon" :src="app.icon" alt="" class="h-full w-full object-cover" />
                            <span v-else class="text-lg font-semibold text-slate-500">{{ app.short_name.charAt(0) }}</span>
                        </span>
                        <p class="mt-1 truncate text-[11px] text-white drop-shadow">{{ app.short_name }}</p>
                    </div>
                </div>
            </section>

            <!-- Splash screen: background_color, icon and name -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 :class="header"><i class="bi bi-phone mr-1"></i>Splash screen</h3>
                <div class="flex justify-center bg-slate-100 p-4 dark:bg-slate-900">
                    <div class="flex h-[220px] w-[110px] flex-col overflow-hidden rounded-2xl border-4 border-slate-800 shadow" :style="{ background: app.background_color }">
                        <div class="h-3 shrink-0" :style="{ background: app.theme_color || app.background_color }"></div>
                        <div class="flex flex-1 flex-col items-center justify-center gap-2 px-2">
                            <img v-if="app.icon" :src="app.icon" alt="" class="h-12 w-12 object-contain" />
                            <p class="w-full truncate text-center text-[10px] font-medium" :style="{ color: app.theme_color || '#334155' }">{{ app.name }}</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <section v-if="manifest" class="rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-700">
                <h2 class="text-sm font-semibold">Manifest</h2>
                <a :href="manifest.url" target="_blank" rel="noopener noreferrer" class="truncate font-mono text-xs text-blue-600 hover:underline dark:text-blue-400">{{ manifest.url }}</a>
            </div>
            <dl v-if="fields.length" class="divide-y divide-slate-100 text-sm dark:divide-slate-700">
                <div v-for="[key, value] in fields" :key="key" class="grid gap-1 px-4 py-2 sm:grid-cols-[12rem_1fr]">
                    <dt class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ key }}</dt>
                    <dd class="flex items-center gap-2 break-words">
                        <span v-if="isColor(key)" class="h-4 w-4 shrink-0 rounded border border-slate-300" :style="{ background: value }"></span>{{ value }}
                    </dd>
                </div>
                <div v-if="manifest.icons?.length" class="grid gap-1 px-4 py-2 sm:grid-cols-[12rem_1fr]">
                    <dt class="font-mono text-xs text-slate-500 dark:text-slate-400">icons ({{ manifest.icon_count }})</dt>
                    <dd class="flex flex-wrap gap-3">
                        <div v-for="icon in manifest.icons" :key="icon.url" class="text-center">
                            <span class="flex h-16 w-16 items-center justify-center rounded-lg border border-slate-200 dark:border-slate-600" style="background: repeating-conic-gradient(#e2e8f0 0 25%, #fff 0 50%) 0 0 / 12px 12px">
                                <img v-if="icon.preview" :src="icon.preview" alt="" class="max-h-14 max-w-14 object-contain" />
                                <i v-else class="bi bi-x-circle text-red-500"></i>
                            </span>
                            <p class="mt-1 text-xs text-slate-500">{{ icon.sizes || '?' }}<template v-if="icon.purpose !== 'any'"> · {{ icon.purpose }}</template></p>
                        </div>
                    </dd>
                </div>
            </dl>
        </section>

        <CheckList title="Web app checks" :checks="checks" />
    </div>
</template>
