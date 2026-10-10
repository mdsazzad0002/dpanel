<script setup>
import CheckList from '@/Pages/Seo/components/inspection/CheckList.vue';
import { computed } from 'vue';

// Link cards as each app draws them from the Open Graph and X tags.
const props = defineProps({
    report: { type: Object, required: true },
});

const p = computed(() => props.report.preview);
const s = computed(() => p.value.social);
const domain = computed(() => p.value.host.replace(/^www\./, ''));
const xImage = computed(() => s.value.twitter_image || s.value.image);
const largeX = computed(() => s.value.twitter_card === 'summary_large_image' && xImage.value);
const accent = computed(() => p.value.theme_color || '#94a3b8');
const checks = computed(() => props.report.sections.find((x) => x.key === 'social')?.checks || []);
const tags = computed(() => Object.entries({ ...props.report.meta?.og, ...props.report.meta?.twitter }));
const header = 'border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400';
</script>

<template>
    <div class="space-y-4">
        <div class="grid items-start gap-4 xl:grid-cols-2">
            <!-- Facebook -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 :class="header"><i class="bi bi-facebook mr-1"></i>Facebook</h3>
                <div class="bg-[#f0f2f5] p-5" style="font-family: Helvetica, Arial, sans-serif">
                    <div class="mx-auto max-w-[500px] overflow-hidden border border-[#dadde1] bg-white">
                        <div v-if="s.image" class="aspect-[1.91/1] bg-[#e4e6eb]"><img :src="s.image" alt="" class="h-full w-full object-cover" /></div>
                        <div class="bg-[#f0f2f5] px-3 py-2.5">
                            <p class="truncate text-[12px] uppercase text-[#65676b]">{{ domain }}</p>
                            <p class="line-clamp-2 text-[16px] font-semibold leading-5 text-[#050505]">{{ s.title }}</p>
                            <p class="truncate text-[14px] text-[#65676b]">{{ s.description }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- X -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 :class="header"><i class="bi bi-twitter-x mr-1"></i>X (Twitter) · {{ s.twitter_card }}</h3>
                <div class="bg-white p-5" style="font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif">
                    <div class="mx-auto max-w-[506px]">
                        <template v-if="largeX">
                            <div class="relative aspect-[1.91/1] overflow-hidden rounded-2xl border border-[#cfd9de] bg-[#f7f9f9]">
                                <img :src="xImage" alt="" class="h-full w-full object-cover" />
                                <span class="absolute bottom-3 left-3 max-w-[85%] truncate rounded bg-black/75 px-1.5 py-0.5 text-[13px] text-white">{{ s.twitter_title }}</span>
                            </div>
                            <p class="mt-1 text-[13px] text-[#536471]">From {{ domain }}</p>
                        </template>
                        <div v-else class="flex overflow-hidden rounded-2xl border border-[#cfd9de]">
                            <div class="flex w-[130px] shrink-0 items-center justify-center border-r border-[#cfd9de] bg-[#f7f9f9]" style="aspect-ratio: 1">
                                <img v-if="xImage" :src="xImage" alt="" class="h-full w-full object-cover" />
                                <i v-else class="bi bi-file-text text-3xl text-[#536471]"></i>
                            </div>
                            <div class="min-w-0 flex-1 px-3 py-3 text-[15px] leading-5">
                                <p class="truncate text-[#536471]">{{ domain }}</p>
                                <p class="truncate text-[#0f1419]">{{ s.twitter_title }}</p>
                                <p class="line-clamp-2 text-[#536471]">{{ s.twitter_description }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- LinkedIn -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 :class="header"><i class="bi bi-linkedin mr-1"></i>LinkedIn</h3>
                <div class="bg-[#f4f2ee] p-5" style="font-family: -apple-system, system-ui, 'Segoe UI', Roboto, sans-serif">
                    <div class="mx-auto max-w-[552px] overflow-hidden rounded-lg bg-white shadow-sm">
                        <div v-if="s.image" class="aspect-[1.91/1] bg-[#eef3f8]"><img :src="s.image" alt="" class="h-full w-full object-cover" /></div>
                        <div class="bg-[#eef3f8] px-3 py-2">
                            <p class="line-clamp-2 text-[14px] font-semibold text-black/90">{{ s.title }}</p>
                            <p class="truncate text-[12px] text-black/60">{{ domain }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- WhatsApp -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 :class="header"><i class="bi bi-whatsapp mr-1"></i>WhatsApp</h3>
                <div class="flex justify-end bg-[#efeae2] p-5" style="font-family: 'Segoe UI', Helvetica, Arial, sans-serif">
                    <div class="w-full max-w-[340px] rounded-lg bg-[#d9fdd3] p-1 shadow-sm">
                        <div class="overflow-hidden rounded-md bg-[#d1f4cc]">
                            <img v-if="s.image && (s.image_ratio || 0) > 1.3" :src="s.image" alt="" class="aspect-[1.91/1] w-full object-cover" />
                            <div class="flex gap-2 p-2">
                                <img v-if="s.image && (s.image_ratio || 0) <= 1.3" :src="s.image" alt="" class="h-16 w-16 shrink-0 rounded object-cover" />
                                <div class="min-w-0">
                                    <p class="line-clamp-2 text-[13px] font-semibold leading-4 text-[#111b21]">{{ s.title }}</p>
                                    <p class="line-clamp-2 text-[12px] leading-4 text-[#667781]">{{ s.description }}</p>
                                    <p class="truncate text-[12px] text-[#667781]">{{ domain }}</p>
                                </div>
                            </div>
                        </div>
                        <p class="break-all px-1.5 pt-1 text-[13px] text-[#027eb5]">{{ p.url }}</p>
                    </div>
                </div>
            </section>

            <!-- Discord -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 :class="header"><i class="bi bi-discord mr-1"></i>Discord</h3>
                <div class="bg-[#313338] p-5" style="font-family: 'gg sans', 'Noto Sans', Helvetica, Arial, sans-serif">
                    <div class="max-w-[432px] rounded border-l-4 bg-[#2b2d31] p-3" :style="{ borderLeftColor: accent }">
                        <p class="text-[12px] text-[#b5bac1]">{{ p.site_name }}</p>
                        <p class="mt-1 text-[16px] font-semibold text-[#00a8fc]">{{ s.title }}</p>
                        <p class="mt-1 line-clamp-3 text-[14px] text-[#dbdee1]">{{ s.description }}</p>
                        <img v-if="s.image" :src="s.image" alt="" class="mt-3 max-h-[220px] w-full rounded object-cover" />
                    </div>
                </div>
            </section>

            <!-- Slack -->
            <section class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
                <h3 :class="header"><i class="bi bi-slack mr-1"></i>Slack</h3>
                <div class="bg-white p-5" style="font-family: Lato, 'Slack-Lato', Helvetica, Arial, sans-serif">
                    <div class="max-w-[480px] border-l-4 border-[#dddddd] pl-3">
                        <p class="flex items-center gap-1.5 text-[15px] font-bold text-[#1d1c1d]">
                            <img v-if="p.favicon" :src="p.favicon" alt="" class="h-4 w-4 object-contain" />{{ p.site_name }}
                        </p>
                        <p class="text-[15px] font-bold text-[#1264a3]">{{ s.title }}</p>
                        <p class="line-clamp-3 text-[15px] text-[#1d1c1d]">{{ s.description }}</p>
                        <img v-if="s.image" :src="s.image" alt="" class="mt-2 max-h-[200px] max-w-[360px] rounded-lg border border-black/10 object-cover" />
                    </div>
                </div>
            </section>
        </div>

        <p v-if="!s.image && s.image_url" class="text-sm text-slate-500 dark:text-slate-400">The share image is too large to show here: <a :href="s.image_url" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline dark:text-blue-400">open it</a>.</p>
        <p class="text-xs text-slate-500 dark:text-slate-400">Apps cache link previews. After changing tags, refresh them with Facebook's Sharing Debugger or LinkedIn's Post Inspector.</p>

        <CheckList title="Open Graph & X card" :checks="checks" />

        <section v-if="tags.length" class="rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
            <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold dark:border-slate-700">Tags found</h2>
            <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-700">
                <div v-for="[name, value] in tags" :key="name" class="grid gap-1 px-4 py-2 sm:grid-cols-[14rem_1fr]">
                    <dt class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ name }}</dt>
                    <dd class="break-words">{{ value }}</dd>
                </div>
            </dl>
        </section>
    </div>
</template>
