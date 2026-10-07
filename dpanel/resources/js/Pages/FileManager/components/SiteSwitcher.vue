<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    fm: {
        type: Object,
        required: true,
    },
});

const currentId = computed(() => String(props.fm.props.website?.id || ''));
const sites = computed(() => (Array.isArray(props.fm.props.browseSites) ? props.fm.props.browseSites : []));

const open = ref(false);
const query = ref('');
const menu = ref(null);
const searchInput = ref(null);

const filteredSites = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) return sites.value;
    return sites.value.filter((site) => String(site.domain || '').toLowerCase().includes(needle));
});

const fileManagerUrl = (site) => props.fm.panelRoute('websites.filemanager', { id: site.id });

const toggle = async () => {
    open.value = !open.value;
    if (!open.value) return;
    query.value = '';
    await nextTick();
    searchInput.value?.focus();
};

const close = () => {
    open.value = false;
};

const switchTo = (site) => {
    close();
    if (!site || String(site.id) === currentId.value) return;
    router.get(fileManagerUrl(site));
};

const onDocumentPointerDown = (event) => {
    if (open.value && menu.value && !menu.value.contains(event.target)) close();
};

onMounted(() => document.addEventListener('pointerdown', onDocumentPointerDown));
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointerDown));
</script>

<template>
    <div v-if="sites.length" ref="menu" class="relative shrink-0" @keydown.esc="close">
        <button
            type="button"
            class="flex h-7 items-center gap-1 rounded-md px-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-sky-600 dark:hover:bg-slate-800 dark:hover:text-sky-400"
            :class="open ? 'bg-slate-100 text-sky-600 dark:bg-slate-800 dark:text-sky-400' : ''"
            title="Switch or browse websites"
            aria-label="Switch or browse websites"
            aria-haspopup="true"
            :aria-expanded="open"
            @click="toggle"
        >
            <i class="bi bi-arrow-left-right text-xs"></i>
            <i class="bi bi-chevron-down text-[9px] transition-transform" :class="open ? 'rotate-180' : ''"></i>
        </button>

        <div
            v-if="open"
            class="absolute left-0 top-full z-50 mt-2 flex w-72 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"
        >
            <div class="border-b border-slate-200 p-2 dark:border-slate-800">
                <div class="relative">
                    <i class="bi bi-search pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                    <input
                        ref="searchInput"
                        v-model="query"
                        type="text"
                        class="w-full rounded-lg border border-slate-200 bg-white py-1.5 pl-7 pr-2 text-xs outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-400/15 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                        placeholder="Search websites..."
                        @keydown.enter.prevent="switchTo(filteredSites[0])"
                    >
                </div>
            </div>
            <ul class="max-h-72 overflow-y-auto py-1">
                <li v-for="site in filteredSites" :key="site.id" class="group flex items-center">
                    <Link
                        :href="fileManagerUrl(site)"
                        class="flex min-w-0 flex-1 items-center gap-2 px-3 py-2 text-left text-xs transition hover:bg-slate-100 dark:hover:bg-slate-800"
                        :class="String(site.id) === currentId ? 'pointer-events-none' : ''"
                        :title="`Open ${site.domain} files in this tab`"
                        @click="close"
                    >
                        <i class="bi bi-folder2-open shrink-0 text-amber-500"></i>
                        <span class="min-w-0 flex-1 truncate font-medium text-slate-700 dark:text-slate-200">{{ site.domain }}</span>
                        <span v-if="String(site.id) === currentId" class="shrink-0 rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-600 dark:bg-sky-900/30 dark:text-sky-400">current</span>
                        <span v-else-if="site.type && site.type !== 'primary'" class="shrink-0 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ site.type }}</span>
                    </Link>
                    <a
                        :href="fileManagerUrl(site)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 opacity-0 transition hover:bg-slate-100 hover:text-sky-600 focus:opacity-100 group-hover:opacity-100 dark:hover:bg-slate-800 dark:hover:text-sky-400"
                        :title="`Open ${site.domain} files in a new tab`"
                        :aria-label="`Open ${site.domain} files in a new tab`"
                        @click="close"
                    >
                        <i class="bi bi-box-arrow-up-right text-[11px]"></i>
                    </a>
                    <a
                        v-if="site.url"
                        :href="site.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mr-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 opacity-0 transition hover:bg-emerald-50 hover:text-emerald-600 focus:opacity-100 group-hover:opacity-100 dark:hover:bg-emerald-900/20 dark:hover:text-emerald-400"
                        :title="`Browse ${site.url}`"
                        :aria-label="`Browse ${site.url}`"
                        @click="close"
                    >
                        <i class="bi bi-globe2 text-[11px]"></i>
                    </a>
                </li>
                <li v-if="!filteredSites.length" class="px-3 py-3 text-center text-xs text-slate-500 dark:text-slate-400">No websites match “{{ query }}”.</li>
            </ul>
        </div>
    </div>
</template>
