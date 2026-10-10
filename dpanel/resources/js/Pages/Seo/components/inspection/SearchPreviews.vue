<script setup>
import CheckList from '@/Pages/Seo/components/inspection/CheckList.vue';
import { computed } from 'vue';

// Approximate result snippets as each engine lays them out. Titles are cut by
// width like the real pages; descriptions by the usual character budget.
const props = defineProps({
    report: { type: Object, required: true },
});

const p = computed(() => props.report.preview);
const cut = (text, max) => {
    const value = (text || '').trim();
    if (value.length <= max) return value;
    const slice = value.slice(0, max);
    return `${slice.slice(0, Math.max(slice.lastIndexOf(' '), max - 15)).trimEnd()} …`;
};
const crumbs = computed(() => [p.value.host, ...(p.value.breadcrumb ? p.value.breadcrumb.split(' › ') : [])].join(' › '));
const displayUrl = computed(() => p.value.url.replace(/^https?:\/\//, '').replace(/\/$/, ''));
const checks = computed(() => props.report.sections.find((s) => s.key === 'content')?.checks || []);
</script>

<template>
    <div class="space-y-4">
        <p v-if="p.description_generated" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-900/30 dark:text-amber-300">
            <i class="bi bi-info-circle mr-1"></i>The page has no meta description, so the snippet below is taken from the page text. Search engines may pick different text.
        </p>

        <div class="grid items-start gap-4 xl:grid-cols-2">
            <!-- Google desktop -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400"><i class="bi bi-google mr-1"></i>Google · desktop</h3>
                <div class="overflow-x-auto bg-white p-5" style="font-family: Arial, sans-serif">
                    <div class="max-w-[600px]">
                        <div class="flex items-center gap-3">
                            <span class="flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-full border border-[#dadce0] bg-[#f1f3f4]">
                                <img v-if="p.favicon" :src="p.favicon" alt="" class="h-[18px] w-[18px] object-contain" />
                                <i v-else class="bi bi-globe2 text-xs text-[#5f6368]"></i>
                            </span>
                            <div class="min-w-0 leading-tight">
                                <p class="truncate text-[14px] text-[#202124]">{{ p.site_name }}</p>
                                <p class="truncate text-[12px] text-[#4d5156]">{{ p.url.startsWith('https') ? 'https://' : '' }}{{ crumbs }}</p>
                            </div>
                        </div>
                        <p class="mt-1.5 truncate text-[20px] leading-[26px] text-[#1a0dab] hover:underline">{{ p.title }}</p>
                        <p class="line-clamp-2 text-[14px] leading-[22px] text-[#4d5156]">{{ cut(p.description, 158) || 'No description available.' }}</p>
                    </div>
                </div>
            </section>

            <!-- Google mobile -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400"><i class="bi bi-phone mr-1"></i>Google · mobile</h3>
                <div class="flex justify-center bg-[#f1f3f4] p-5" style="font-family: Roboto, Arial, sans-serif">
                    <div class="w-full max-w-[360px] rounded-2xl bg-white p-4 shadow-sm">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-[#dadce0] bg-[#f1f3f4]">
                                <img v-if="p.favicon" :src="p.favicon" alt="" class="h-[18px] w-[18px] object-contain" />
                                <i v-else class="bi bi-globe2 text-xs text-[#5f6368]"></i>
                            </span>
                            <div class="min-w-0 leading-tight">
                                <p class="truncate text-[14px] text-[#202124]">{{ p.site_name }}</p>
                                <p class="truncate text-[12px] text-[#4d5156]">{{ crumbs }}</p>
                            </div>
                        </div>
                        <p class="mt-2 line-clamp-2 text-[18px] leading-6 text-[#1a0dab]">{{ p.title }}</p>
                        <p class="mt-1 line-clamp-3 text-[14px] leading-5 text-[#4d5156]">{{ cut(p.description, 120) }}</p>
                    </div>
                </div>
            </section>

            <!-- Bing -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400"><i class="bi bi-microsoft mr-1"></i>Bing</h3>
                <div class="overflow-x-auto bg-white p-5" style="font-family: 'Segoe UI', Arial, sans-serif">
                    <div class="max-w-[608px]">
                        <div class="flex items-center gap-2">
                            <span class="flex h-[18px] w-[18px] shrink-0 items-center justify-center overflow-hidden rounded-sm">
                                <img v-if="p.favicon_ico || p.favicon" :src="p.favicon_ico || p.favicon" alt="" class="h-4 w-4 object-contain" />
                                <i v-else class="bi bi-globe2 text-xs text-[#767676]"></i>
                            </span>
                            <div class="min-w-0 leading-tight">
                                <p class="truncate text-[14px] text-[#111]">{{ p.site_name }}</p>
                                <p class="truncate text-[13px] text-[#006d21]">{{ p.url }}</p>
                            </div>
                        </div>
                        <p class="mt-1 truncate text-[20px] leading-[26px] text-[#001ba0] hover:underline">{{ p.title }}</p>
                        <p class="line-clamp-3 text-[14px] leading-[22px] text-[#444]">{{ cut(p.description, 200) }}</p>
                    </div>
                </div>
            </section>

            <!-- Yandex -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400"><i class="bi bi-search mr-1"></i>Yandex</h3>
                <div class="overflow-x-auto bg-white p-5" style="font-family: 'YS Text', Arial, sans-serif">
                    <div class="max-w-[600px]">
                        <p class="flex items-center gap-2 text-[18px] leading-6 text-[#0c0c91] hover:text-[#f33]">
                            <img v-if="p.favicon_ico || p.favicon" :src="p.favicon_ico || p.favicon" alt="" class="h-4 w-4 shrink-0 object-contain" />
                            <i v-else class="bi bi-globe2 shrink-0 text-xs text-[#999]"></i>
                            <span class="truncate">{{ cut(p.title, 70) }}</span>
                        </p>
                        <p class="mt-0.5 truncate text-[14px] text-[#006000]">{{ displayUrl.split('/').join(' › ') }}</p>
                        <p class="mt-0.5 line-clamp-3 text-[14px] leading-5 text-[#333]">{{ cut(p.description, 240) }}</p>
                    </div>
                </div>
            </section>
        </div>

        <p class="text-xs text-slate-500 dark:text-slate-400">Previews are approximate. Engines can rewrite titles and snippets per search, and show the favicon only after crawling the home page.</p>

        <section v-if="report.meta?.headings?.length" class="rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
            <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold dark:border-slate-700">Heading outline</h2>
            <ul class="space-y-1 px-4 py-3 text-sm">
                <li v-for="(h, i) in report.meta.headings" :key="i" :style="{ paddingLeft: `${(h.level - 1) * 1.25}rem` }" class="flex gap-2">
                    <span class="w-7 shrink-0 rounded bg-slate-100 text-center text-xs font-semibold leading-5 text-slate-500 dark:bg-slate-700 dark:text-slate-300">H{{ h.level }}</span>
                    <span class="min-w-0 break-words">{{ h.text }}</span>
                </li>
            </ul>
        </section>

        <CheckList title="Title, snippet & content" :checks="checks" />
    </div>
</template>
