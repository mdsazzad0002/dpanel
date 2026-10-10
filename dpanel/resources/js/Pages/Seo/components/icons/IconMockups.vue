<script setup>
import { computed } from 'vue';

// The generated icons where people will see them.
const props = defineProps({
    fileUrl: { type: Function, required: true },
    options: { type: Object, required: true },
    domain: { type: String, default: '' },
    groups: { type: Object, required: true },
});

const tab = computed(() => props.fileUrl('favicon-32x32.png'));
const google = computed(() => props.fileUrl('favicon-48x48.png') || tab.value);
const apple = computed(() => props.fileUrl('apple-touch-icon.png'));
const maskable = computed(() => props.fileUrl('maskable-icon-192x192.png'));
const splash = computed(() => props.fileUrl('android-chrome-512x512.png'));
const og = computed(() => props.fileUrl('og-image.png'));
const host = computed(() => props.domain || 'example.com');
const header = 'border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400';
</script>

<template>
    <div class="grid items-start gap-4 lg:grid-cols-2">
        <section v-if="groups.favicon" class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
            <h3 :class="header"><i class="bi bi-window mr-1"></i>Browser tab &amp; Google</h3>
            <div v-for="theme in [{ bar: '#dee1e6', tab: '#ffffff', text: '#3c4043' }, { bar: '#202124', tab: '#35363a', text: '#e8eaed' }]" :key="theme.bar" class="px-4 pt-3" :style="{ background: theme.bar }">
                <div class="flex max-w-[240px] items-center gap-2 rounded-t-lg px-3 py-2" :style="{ background: theme.tab, color: theme.text }">
                    <img v-if="tab" :src="tab" alt="" class="h-4 w-4 shrink-0" />
                    <span class="truncate text-xs">{{ options.name || 'My site' }}</span>
                    <i class="bi bi-x ml-auto text-xs opacity-60"></i>
                </div>
            </div>
            <div class="flex items-center gap-3 bg-white p-4" style="font-family: Arial, sans-serif">
                <span class="flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-full border border-[#dadce0] bg-[#f1f3f4]">
                    <img v-if="google" :src="google" alt="" class="h-[18px] w-[18px]" />
                </span>
                <div class="min-w-0 leading-tight">
                    <p class="truncate text-[14px] text-[#202124]">{{ options.name || 'My site' }}</p>
                    <p class="truncate text-[12px] text-[#4d5156]">https://{{ host }}</p>
                </div>
            </div>
        </section>

        <section v-if="groups.apple || groups.pwa" class="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
            <h3 :class="header"><i class="bi bi-phone mr-1"></i>Home screens</h3>
            <div class="flex flex-wrap items-end justify-around gap-4 bg-gradient-to-br from-indigo-500 to-teal-500 p-5">
                <div v-if="apple" class="w-16 text-center">
                    <img :src="apple" alt="" class="mx-auto h-14 w-14 rounded-[22%]" />
                    <p class="mt-1 truncate text-[11px] text-white drop-shadow">{{ options.shortName || options.name }}</p>
                    <p class="text-[10px] text-white/70">iOS</p>
                </div>
                <div v-for="shape in (maskable ? ['rounded-full', 'rounded-[30%]'] : [])" :key="shape" class="w-16 text-center">
                    <img :src="maskable" alt="" class="mx-auto h-14 w-14" :class="shape" />
                    <p class="mt-1 truncate text-[11px] text-white drop-shadow">{{ options.shortName || options.name }}</p>
                    <p class="text-[10px] text-white/70">Android</p>
                </div>
                <div v-if="splash" class="text-center">
                    <div class="flex h-[120px] w-[64px] flex-col items-center justify-center gap-1 overflow-hidden rounded-xl border-2 border-slate-800" :style="{ background: options.backgroundColor }">
                        <img :src="splash" alt="" class="h-8 w-8" />
                        <span class="w-full truncate px-1 text-[7px]" :style="{ color: options.themeColor }">{{ options.name }}</span>
                    </div>
                    <p class="mt-1 text-[10px] text-white/70">Splash</p>
                </div>
            </div>
        </section>

        <section v-if="og" class="overflow-hidden rounded-xl border border-slate-200 lg:col-span-2 dark:border-slate-700">
            <h3 :class="header"><i class="bi bi-share mr-1"></i>Link preview</h3>
            <div class="bg-[#f0f2f5] p-5" style="font-family: Helvetica, Arial, sans-serif">
                <div class="mx-auto max-w-[500px] overflow-hidden border border-[#dadde1] bg-white">
                    <img :src="og" alt="" class="aspect-[1.91/1] w-full object-cover" />
                    <div class="bg-[#f0f2f5] px-3 py-2.5">
                        <p class="truncate text-[12px] uppercase text-[#65676b]">{{ host }}</p>
                        <p class="line-clamp-2 text-[16px] font-semibold leading-5 text-[#050505]">{{ options.og.title || options.name || 'Page title' }}</p>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
