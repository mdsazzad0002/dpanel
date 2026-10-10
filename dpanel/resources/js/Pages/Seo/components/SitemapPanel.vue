<script setup>
import Offcanvas from '@/Components/Offcanvas.vue';
import SitemapBatchBar from '@/Pages/Seo/components/SitemapBatchBar.vue';
import { computed, inject, onMounted, ref } from 'vue';

// One level of the stacked sitemap panels. Fetched files, the "Run all"
// batch and the helpers are shared by the page; the view mode and filters
// belong to this panel, so a parent keeps its own while a child is open.
const props = defineProps({
    url: { type: String, required: true },
    // Sitemaps from the first panel down to this one.
    path: { type: Array, default: () => [] },
    width: { type: String, default: '70vw' },
    top: { type: Boolean, default: true },
});
const emit = defineEmits(['open-child', 'back-to', 'close']);

const {
    files, batch, badge, flagInfo, canOpen, runChecks, stopChecks, verifyOne, statsFor, checkPages, size, shortUrl,
} = inject('sitemapVerify');

const file = computed(() => files[props.url]);
const sitemap = computed(() => file.value?.data || null);

// Mount closed, then open, so the slide-in plays; closing waits for the slide-out.
const visible = ref(false);
onMounted(() => { visible.value = true; });
const close = () => {
    visible.value = false;
    setTimeout(() => emit('close'), 150);
};

const view = ref('organized');
const entryFilter = ref('');
const flagFilter = ref('');
const PAGE_SIZE = 200;
const shown = ref(PAGE_SIZE);

const childUrls = computed(() => (sitemap.value?.type === 'index'
    ? [...new Set(sitemap.value.entries.filter(canOpen).map((e) => e.loc))]
    : []));

// Issue filters look at the entry itself; "st:" filters at a sub-sitemap's own check.
const matchesFilter = (entry) => {
    const filter = flagFilter.value;
    if (filter === 'any') return entry.flags.length > 0;
    if (!filter.startsWith('st:')) return entry.flags.includes(filter);
    const child = files[entry.loc];
    const status = child?.error ? 'fail' : child?.data?.status || (child?.loading ? 'loading' : 'unchecked');
    return status === filter.slice(3);
};
const filteredEntries = computed(() => {
    const entries = sitemap.value?.entries || [];
    const needle = entryFilter.value.trim().toLowerCase();
    return entries.filter((e) => (!needle || e.loc.toLowerCase().includes(needle)) && (!flagFilter.value || matchesFilter(e)));
});
const flagCounts = computed(() => {
    const counts = {};
    for (const e of sitemap.value?.entries || []) for (const f of e.flags) counts[f] = (counts[f] || 0) + 1;
    return counts;
});

const copied = ref(false);
const copyRaw = async () => {
    try {
        await navigator.clipboard.writeText(sitemap.value?.raw || '');
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch (e) { /* clipboard unavailable */ }
};
</script>

<template>
    <Offcanvas
        :show="visible"
        :max-width="width"
        :close-on-escape="top"
        :title="sitemap?.type === 'index' ? 'Sitemap index' : 'Sitemap'"
        :subtitle="url"
        @close="close"
    >
        <nav v-if="path.length > 1" class="mb-3 flex flex-wrap items-center gap-1 text-xs">
            <template v-for="(item, i) in path" :key="item + i">
                <i v-if="i" class="bi bi-chevron-right text-slate-400"></i>
                <button v-if="i < path.length - 1" type="button" @click="emit('back-to', i)" class="max-w-[14rem] truncate text-blue-600 hover:underline dark:text-blue-400">{{ shortUrl(item) }}</button>
                <span v-else class="max-w-[14rem] truncate font-medium">{{ shortUrl(item) }}</span>
            </template>
        </nav>


            <div v-if="file?.loading" class="py-16 text-center text-sm text-slate-500">
                <i class="bi bi-arrow-repeat inline-block animate-spin text-xl"></i>
                <p class="mt-2">Opening sitemap…</p>
            </div>
            <div v-else-if="file?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ file.error }}
                <button type="button" @click="verifyOne(url)" class="ml-2 font-medium underline">Try again</button>
            </div>

            <template v-else-if="sitemap">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="badge[sitemap.status].bg">{{ badge[sitemap.status].label }}</span>
                    <span class="text-sm text-slate-500 dark:text-slate-400">
                        <template v-if="sitemap.type === 'index'">{{ sitemap.children.toLocaleString() }} sub-sitemaps</template>
                        <template v-else-if="sitemap.type === 'urlset'">{{ sitemap.urls.toLocaleString() }} URLs</template>
                        <template v-if="sitemap.bytes"> · {{ size(sitemap.bytes) }}</template>
                        · HTTP {{ sitemap.http_status || '—' }}
                    </span>
                    <div class="ml-auto inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs dark:border-slate-700 dark:bg-slate-800" role="radiogroup" aria-label="View">
                        <button
                            v-for="option in [{ value: 'organized', label: 'Organized', icon: 'bi-table' }, { value: 'raw', label: 'Raw', icon: 'bi-code-slash' }]"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="view === option.value"
                            @click="view = option.value"
                            class="rounded-md px-2.5 py-1 font-medium"
                            :class="view === option.value ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 dark:text-slate-400'"
                        >
                            <i class="bi mr-1" :class="option.icon"></i>{{ option.label }}
                        </button>
                    </div>
                </div>

                <ul v-if="sitemap.issues.length" class="mb-4 space-y-1 rounded-lg bg-slate-50 p-3 dark:bg-slate-800">
                    <li v-for="(issue, i) in sitemap.issues" :key="i" class="flex items-start gap-2 text-sm">
                        <i class="bi mt-0.5" :class="[badge[issue.level].icon, badge[issue.level].text]"></i>
                        <span>{{ issue.message }}</span>
                    </li>
                </ul>

                <!-- Raw: the file as served (gzip already unpacked). -->
                <div v-if="view === 'raw'">
                    <div class="mb-2 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                        <span>{{ sitemap.raw_truncated ? 'First 512 KB of the file' : 'Whole file' }}</span>
                        <button type="button" @click="copyRaw" class="rounded border border-slate-300 px-2 py-1 hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-800">
                            <i class="bi" :class="copied ? 'bi-check2' : 'bi-clipboard'"></i> {{ copied ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <pre v-if="sitemap.raw" class="max-h-[70vh] overflow-auto rounded-lg bg-slate-900 p-4 font-mono text-xs leading-relaxed text-slate-100">{{ sitemap.raw }}</pre>
                    <p v-else class="py-10 text-center text-sm text-slate-500">The server returned no body.</p>
                </div>

                <!-- Organized: entries as a table; sub-sitemaps open in place. -->
                <div v-else-if="sitemap.type">
                    <SitemapBatchBar
                        v-if="childUrls.length"
                        class="mb-3"
                        label="sub-sitemaps"
                        :stats="statsFor(childUrls)"
                        :running="batch.running && batch.key === url"
                        :busy="batch.running"
                        @run="runChecks(url, childUrls)"
                        @stop="stopChecks"
                    />
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <input v-model="entryFilter" type="search" :placeholder="sitemap.type === 'index' ? 'Filter sub-sitemaps…' : 'Filter URLs…'" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                        <select v-model="flagFilter" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                            <option value="">All entries</option>
                            <option value="any">With issues</option>
                            <optgroup v-if="sitemap.type === 'index'" label="Check result">
                                <option value="st:pass">Passed</option>
                                <option value="st:warn">With warnings</option>
                                <option value="st:fail">With errors</option>
                                <option value="st:unchecked">Not checked</option>
                            </optgroup>
                            <option v-for="(count, flag) in flagCounts" :key="flag" :value="flag">{{ flagInfo[flag]?.label || flag }} ({{ count }})</option>
                        </select>
                        <button v-if="sitemap.type === 'urlset' && sitemap.sample.length" type="button" :disabled="file.pagesLoading" @click="checkPages(url)" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-800">
                            <i class="bi mr-1" :class="file.pagesLoading ? 'bi-arrow-repeat inline-block animate-spin' : 'bi-activity'"></i>Check {{ sitemap.sample.length }} sample pages
                        </button>
                    </div>

                    <div v-if="file.pages" class="mb-4 rounded-lg border border-slate-200 dark:border-slate-700">
                        <p v-if="file.pages.error" class="px-3 py-2 text-sm text-red-600">{{ file.pages.error }}</p>
                        <template v-else>
                            <p class="flex items-start gap-2 border-b border-slate-100 px-3 py-2 text-sm dark:border-slate-700">
                                <i class="bi mt-0.5" :class="[badge[file.pages.summary.status].icon, badge[file.pages.summary.status].text]"></i>
                                {{ file.pages.summary.detail }}
                            </p>
                            <ul class="divide-y divide-slate-100 text-xs dark:divide-slate-700">
                                <li v-for="page in file.pages.samples" :key="page.url" class="flex items-start gap-2 px-3 py-1.5">
                                    <i class="bi mt-0.5" :class="[badge[page.status].icon, badge[page.status].text]"></i>
                                    <a :href="page.url" target="_blank" rel="noopener noreferrer" class="min-w-0 flex-1 truncate font-mono hover:underline">{{ page.url }}</a>
                                    <span class="shrink-0 text-slate-500 dark:text-slate-400">{{ page.http_status || '—' }} · {{ page.note }}</span>
                                </li>
                            </ul>
                        </template>
                    </div>

                    <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">
                        {{ filteredEntries.length.toLocaleString() }} of {{ (sitemap.type === 'index' ? sitemap.children : sitemap.urls).toLocaleString() }}
                        {{ sitemap.type === 'index' ? 'sub-sitemaps · click one to open it' : 'URLs' }}
                        <template v-if="sitemap.entries_truncated"> · only the first {{ sitemap.entries.length.toLocaleString() }} are listed</template>
                    </p>

                    <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                <tr>
                                    <th class="px-3 py-2 font-medium">{{ sitemap.type === 'index' ? 'Sub-sitemap' : 'URL' }}</th>
                                    <th class="px-3 py-2 font-medium">Last modified</th>
                                    <template v-if="sitemap.type === 'urlset'">
                                        <th class="px-3 py-2 font-medium">Change freq.</th>
                                        <th class="px-3 py-2 font-medium">Priority</th>
                                    </template>
                                    <th class="px-3 py-2 font-medium">Issues</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                                <tr
                                    v-for="(entry, i) in filteredEntries.slice(0, shown)"
                                    :key="i"
                                    :class="sitemap.type === 'index' && canOpen(entry) ? 'cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800' : ''"
                                    @click="sitemap.type === 'index' && canOpen(entry) && emit('open-child', entry.loc)"
                                >
                                    <td class="max-w-md px-3 py-2">
                                        <span v-if="sitemap.type === 'index' && canOpen(entry)" class="flex items-center gap-2">
                                            <span class="truncate font-mono text-xs text-blue-600 dark:text-blue-400">{{ entry.loc }}</span>
                                            <span v-if="files[entry.loc]?.data" class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium" :class="badge[files[entry.loc].data.status].bg" :title="files[entry.loc].data.urls ? `${files[entry.loc].data.urls} URLs` : ''">
                                                {{ badge[files[entry.loc].data.status].label }}<template v-if="files[entry.loc].data.type === 'urlset'"> · {{ files[entry.loc].data.urls.toLocaleString() }}</template>
                                            </span>
                                            <i v-else-if="files[entry.loc]?.loading" class="bi bi-arrow-repeat inline-block shrink-0 animate-spin text-slate-400"></i>
                                            <span v-else-if="files[entry.loc]?.error" class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium" :class="badge.fail.bg" :title="files[entry.loc].error">Failed</span>
                                            <button
                                                v-if="!files[entry.loc]?.data && !files[entry.loc]?.loading"
                                                type="button"
                                                @click.stop="verifyOne(entry.loc)"
                                                class="shrink-0 rounded border border-slate-300 px-1.5 py-0.5 text-[11px] font-medium text-slate-600 hover:bg-white dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700"
                                            >
                                                {{ files[entry.loc]?.error ? 'Retry' : 'Verify' }}
                                            </button>
                                            <i class="bi bi-chevron-right ml-auto shrink-0 text-slate-400"></i>
                                        </span>
                                        <a v-else-if="entry.loc" :href="entry.loc" target="_blank" rel="noopener noreferrer" class="block truncate font-mono text-xs hover:underline" @click.stop>{{ entry.loc }}</a>
                                        <span v-else class="text-xs italic text-slate-400">empty &lt;loc&gt;</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-500 dark:text-slate-400">{{ entry.lastmod || '—' }}</td>
                                    <template v-if="sitemap.type === 'urlset'">
                                        <td class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">{{ entry.changefreq || '—' }}</td>
                                        <td class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">{{ entry.priority || '—' }}</td>
                                    </template>
                                    <td class="px-3 py-2">
                                        <span v-for="flag in entry.flags" :key="flag" class="mr-1 inline-block rounded px-1.5 py-0.5 text-xs" :class="badge[flagInfo[flag]?.level || 'warn'].bg">{{ flagInfo[flag]?.label || flag }}</span>
                                        <span v-if="!entry.flags.length" class="text-xs text-slate-400">—</span>
                                    </td>
                                </tr>
                                <tr v-if="!filteredEntries.length">
                                    <td colspan="5" class="px-3 py-6 text-center text-sm text-slate-500">No entries match.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button v-if="filteredEntries.length > shown" type="button" @click="shown += PAGE_SIZE" class="mt-3 w-full rounded-md border border-slate-300 py-2 text-sm hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-800">
                        Show {{ Math.min(PAGE_SIZE, filteredEntries.length - shown) }} more
                    </button>
                </div>
                <p v-else class="py-10 text-center text-sm text-slate-500">This file could not be read as a sitemap. Switch to Raw to see what the server sent.</p>
            </template>
    </Offcanvas>
</template>
