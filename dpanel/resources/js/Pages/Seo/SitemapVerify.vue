<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import RobotsTxtModal from '@/Pages/Seo/components/RobotsTxtModal.vue';
import SitemapBatchBar from '@/Pages/Seo/components/SitemapBatchBar.vue';
import SitemapPanel from '@/Pages/Seo/components/SitemapPanel.vue';
import { badge } from '@/Pages/Seo/seoBadges';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import { Head } from '@inertiajs/vue3';
import { computed, provide, reactive, ref } from 'vue';

const props = defineProps({
    websites: { type: Array, default: () => [] },
});

const { panelRoute, requestJson } = usePanelApi();

// Internal: one of the user's websites. External: any public site or sitemap URL.
const source = ref('internal');
const websiteId = ref(props.websites[0]?.id || '');
const sitemapPath = ref('');
const externalUrl = ref('');
const canVerify = computed(() => (source.value === 'internal' ? !!websiteId.value : !!externalUrl.value.trim()));
const running = ref(false);
const error = ref('');
const report = ref(null);
// The target of the current report; sitemaps are opened against it even if the form changes.
let target = null;

const websiteOptions = computed(() => props.websites.map((w) => ({ value: w.id, label: w.domain })));

const verify = async () => {
    if (!canVerify.value || running.value) return;
    running.value = true;
    error.value = '';
    notice.value = '';
    report.value = null;
    closeDetails();
    batch.stop = true;
    Object.keys(files).forEach((url) => delete files[url]);
    target = source.value === 'internal'
        ? { source: 'internal', website_id: websiteId.value, sitemap_path: sitemapPath.value.trim() || null }
        : { source: 'external', url: externalUrl.value.trim() };
    try {
        report.value = await requestJson(panelRoute('seo.sitemap-verify.run'), { body: target });
    } catch (e) {
        error.value = e.message || 'Verification failed.';
    } finally {
        running.value = false;
    }
};

const sourceLabel = { robots: 'robots.txt', fallback: 'common path', custom: 'your path' };

const overall = computed(() => {
    const t = report.value?.totals;
    if (!t) return null;
    if (t.fail) return { ...badge.fail, title: `${t.fail} ${t.fail === 1 ? 'problem' : 'problems'} to fix` };
    if (t.warn) return { ...badge.warn, title: `Found, with ${t.warn} ${t.warn === 1 ? 'warning' : 'warnings'}` };
    return { ...badge.pass, title: 'Sitemap found' };
});

// Stacked detail panels. Each sitemap is fetched the first time it is opened
// and kept in `files`; `trail` holds one URL per open panel, from the sitemap
// opened on the main page down through the sub-sitemaps clicked inside it.
const files = reactive({});
const trail = ref([]);

const flagInfo = {
    invalid: { label: 'Invalid <loc>', level: 'fail' },
    foreign: { label: 'Other host', level: 'warn' },
    www: { label: 'www / non-www', level: 'warn' },
    scheme: { label: 'http/https', level: 'warn' },
    duplicate: { label: 'Duplicate', level: 'warn' },
    bad_lastmod: { label: 'Bad lastmod', level: 'warn' },
    future: { label: 'Future lastmod', level: 'warn' },
};
const canOpen = (entry) => entry.loc && !entry.flags.includes('foreign') && !entry.flags.includes('invalid');

const sleep = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

const load = async (url) => {
    if (files[url]?.data || files[url]?.loading) return;
    files[url] = { loading: true, data: null, error: '', pages: null, pagesLoading: false };
    try {
        // Opening is rate limited per minute; a long "Run all" waits and retries.
        for (let attempt = 0; ; attempt++) {
            try {
                files[url].data = await requestJson(panelRoute('seo.sitemap-verify.inspect'), { body: { ...target, sitemap_url: url } });
                break;
            } catch (e) {
                if (!/too many/i.test(e.message || '') || attempt >= 5 || batch.stop) throw e;
                await sleep(15000);
            }
        }
    } catch (e) {
        files[url].error = e.message || 'Could not open this sitemap.';
    } finally {
        files[url].loading = false;
    }
};

// "Run all checks": inspects a list of sitemaps, three at a time, and can be stopped.
// One run at a time; `key` says which list it belongs to (the main list or an index URL).
const batch = reactive({ running: false, stop: false, key: '' });
const runChecks = async (key, urls) => {
    if (batch.running) return;
    Object.assign(batch, { running: true, stop: false, key });
    const queue = urls.filter((url) => !files[url]?.data && !files[url]?.loading);
    queue.forEach((url) => { if (files[url]?.error) delete files[url]; });
    const worker = async () => {
        while (queue.length && !batch.stop) await load(queue.shift());
    };
    await Promise.all([worker(), worker(), worker()]);
    batch.running = false;
};
const stopChecks = () => { batch.stop = true; };
// Checks one sub-sitemap from its row without opening it; a failed one is retried.
const verifyOne = (url) => {
    if (files[url]?.error) delete files[url];
    load(url);
};

const statsFor = (urls) => {
    const stats = { total: urls.length, pass: 0, warn: 0, fail: 0, error: 0, pending: 0, loading: 0, urls: 0 };
    for (const url of urls) {
        const file = files[url];
        if (file?.loading) stats.loading++;
        else if (file?.error) stats.error++;
        else if (file?.data) {
            stats[file.data.status]++;
            stats.urls += file.data.urls || 0;
        } else stats.pending++;
    }
    return stats;
};
const foundUrls = computed(() => (report.value?.sitemaps || []).map((s) => s.url));
const openSitemap = (url) => {
    trail.value = [url];
    load(url);
};
// A child opens on top of the panel it was clicked in.
const openChild = (level, url) => {
    trail.value = [...trail.value.slice(0, level + 1), url];
    load(url);
};
const backTo = (level) => { trail.value = trail.value.slice(0, level + 1); };
const closeFrom = (level) => { trail.value = trail.value.slice(0, level); };
const closeDetails = () => { trail.value = []; };

const checkPages = async (url) => {
    const file = files[url];
    if (!file?.data?.sample?.length || file.pagesLoading) return;
    file.pagesLoading = true;
    try {
        file.pages = await requestJson(panelRoute('seo.sitemap-verify.pages'), { body: { ...target, urls: file.data.sample } });
    } catch (e) {
        file.pages = { error: e.message || 'Could not check the pages.' };
    } finally {
        file.pagesLoading = false;
    }
};

// robots.txt modal: view for any site, edit and fix for internal ones.
const robotsOpen = ref(false);
const robotsSaving = ref(false);
const robotsError = ref('');
const notice = ref('');
const isRobotsCheck = (check) => check.key.startsWith('robots');
const openRobots = () => {
    robotsError.value = '';
    robotsOpen.value = true;
};
const saveRobots = async (content) => {
    robotsSaving.value = true;
    robotsError.value = '';
    try {
        const result = await requestJson(panelRoute('seo.sitemap-verify.robots'), {
            method: 'PUT',
            body: { website_id: target.website_id, content },
        });
        robotsOpen.value = false;
        // Re-check against the live site so the report shows what crawlers now see.
        await verify();
        notice.value = result.message;
    } catch (e) {
        robotsError.value = e.message || 'robots.txt was not saved.';
    } finally {
        robotsSaving.value = false;
    }
};

const shortUrl = (url) => url.replace(/^https?:\/\//, '');
const size = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

provide('sitemapVerify', {
    files, batch, badge, flagInfo, canOpen, runChecks, stopChecks, verifyOne, statsFor, checkPages, size, shortUrl,
});
</script>

<template>
    <Head title="Sitemap Verify" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Sitemap Verify</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Finds your sitemaps through robots.txt, then lets you open each one and its sub-sitemaps.</p>
            </div>
        </template>

        <div class="space-y-4">
            <form class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800" @submit.prevent="verify">
                <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-sm dark:border-slate-700 dark:bg-slate-900" role="radiogroup" aria-label="Sitemap source">
                    <button
                        v-for="option in [{ value: 'internal', label: 'Internal website', icon: 'bi-hdd-stack' }, { value: 'external', label: 'External URL', icon: 'bi-globe2' }]"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="source === option.value"
                        @click="source = option.value"
                        class="rounded-md px-3 py-1.5 font-medium transition"
                        :class="source === option.value ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                    >
                        <i class="bi mr-1" :class="option.icon"></i>{{ option.label }}
                    </button>
                </div>

                <div class="grid gap-3" :class="source === 'internal' ? 'sm:grid-cols-[1fr_1fr_auto]' : 'sm:grid-cols-[1fr_auto]'">
                    <template v-if="source === 'internal'">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Website</label>
                            <SearchableSelect v-model="websiteId" :options="websiteOptions" placeholder="Select a website…" search-placeholder="Search domains…" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Sitemap path <span class="font-normal text-slate-400">(optional)</span></label>
                            <input v-model="sitemapPath" type="text" placeholder="Found automatically, e.g. /sitemap.xml" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                        </div>
                    </template>
                    <div v-else>
                        <label class="mb-1 block text-sm font-medium">Site or sitemap URL</label>
                        <input v-model="externalUrl" type="text" inputmode="url" placeholder="https://example.com or https://example.com/sitemap.xml" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">A site address finds its sitemap through robots.txt; a sitemap address is used directly.</p>
                    </div>
                    <button type="submit" :disabled="!canVerify || running" class="self-end rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                        <i class="bi mr-1" :class="running ? 'bi-arrow-repeat animate-spin inline-block' : 'bi-search'"></i>{{ running ? 'Checking…' : 'Verify' }}
                    </button>
                </div>
            </form>

            <div v-if="source === 'internal' && !websites.length" class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-600 dark:bg-slate-800">
                You have no websites yet. Create one, or switch to External URL to check any public site.
            </div>
            <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</div>
            <div v-if="notice" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ notice }}</div>

            <template v-if="report">
                <div class="flex flex-wrap items-center gap-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <i class="bi text-3xl" :class="[overall.icon, overall.text]"></i>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ overall.title }}</p>
                        <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ report.base_url }} · checked {{ new Date(report.checked_at).toLocaleString() }}</p>
                    </div>
                </div>

                <section v-if="report.sitemaps.length" class="rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                    <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold dark:border-slate-700">Sitemaps found <span class="font-normal text-slate-500">· click one to open it</span></h2>
                    <div v-if="foundUrls.length > 1" class="border-b border-slate-100 px-4 py-3 dark:border-slate-700">
                        <SitemapBatchBar :stats="statsFor(foundUrls)" :running="batch.running && batch.key === 'found'" :busy="batch.running" @run="runChecks('found', foundUrls)" @stop="stopChecks" />
                    </div>
                    <ul class="divide-y divide-slate-100 dark:divide-slate-700">
                        <li v-for="item in report.sitemaps" :key="item.url">
                            <button type="button" @click="openSitemap(item.url)" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50 dark:hover:bg-slate-700/50">
                                <i class="bi bi-file-earmark-code text-slate-400"></i>
                                <span class="min-w-0 flex-1 truncate font-mono text-sm text-blue-600 dark:text-blue-400">{{ item.url }}</span>
                                <span v-if="files[item.url]?.data" class="shrink-0 rounded px-1.5 py-0.5 text-xs font-medium" :class="badge[files[item.url].data.status].bg">{{ badge[files[item.url].data.status].label }}</span>
                                <i v-else-if="files[item.url]?.loading" class="bi bi-arrow-repeat inline-block shrink-0 animate-spin text-slate-400"></i>
                                <span v-else-if="files[item.url]?.error" class="shrink-0 rounded px-1.5 py-0.5 text-xs font-medium" :class="badge.fail.bg" :title="files[item.url].error">Failed</span>
                                <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600 dark:bg-slate-700 dark:text-slate-300">{{ sourceLabel[item.source] || item.source }}</span>
                                <i class="bi bi-chevron-right text-slate-400"></i>
                            </button>
                        </li>
                    </ul>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-700">
                        <h2 class="text-sm font-semibold">Checks</h2>
                        <button type="button" @click="openRobots" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-700">
                            <i class="bi mr-1" :class="report.robots?.file?.editable ? 'bi-pencil-square' : 'bi-file-earmark-text'"></i>{{ report.robots?.file?.editable ? 'Edit robots.txt' : 'View robots.txt' }}
                        </button>
                    </div>
                    <ul class="divide-y divide-slate-100 dark:divide-slate-700">
                        <li v-for="(check, i) in report.checks" :key="i">
                            <component
                                :is="isRobotsCheck(check) ? 'button' : 'div'"
                                :type="isRobotsCheck(check) ? 'button' : undefined"
                                class="flex w-full items-start gap-3 px-4 py-3 text-left"
                                :class="isRobotsCheck(check) ? 'hover:bg-slate-50 dark:hover:bg-slate-700/50' : ''"
                                @click="isRobotsCheck(check) && openRobots()"
                            >
                                <i class="bi mt-0.5" :class="[badge[check.status].icon, badge[check.status].text]"></i>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium">{{ check.label }}</p>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ check.detail }}</p>
                                </div>
                                <span v-if="isRobotsCheck(check) && check.status !== 'pass' && report.robots?.file?.editable" class="shrink-0 rounded-md bg-blue-600 px-2 py-0.5 text-xs font-medium text-white">Fix</span>
                                <i v-else-if="isRobotsCheck(check)" class="bi bi-chevron-right mt-0.5 text-slate-400"></i>
                            </component>
                        </li>
                    </ul>
                </section>
            </template>
        </div>

        <RobotsTxtModal
            :show="robotsOpen"
            :robots="report?.robots"
            :base-url="report?.base_url || ''"
            :sitemaps="(report?.sitemaps || []).map((s) => s.url)"
            :saving="robotsSaving"
            :save-error="robotsError"
            @close="robotsOpen = false"
            @save="saveRobots"
        />

        <!-- One panel per level; each child is 10% narrower so its parent shows behind it. -->
        <SitemapPanel
            v-for="(url, i) in trail"
            :key="`${i}:${url}`"
            :url="url"
            :path="trail.slice(0, i + 1)"
            :width="`${Math.max(70 - i * 10, 30)}vw`"
            :top="i === trail.length - 1"
            @open-child="(child) => openChild(i, child)"
            @back-to="(index) => backTo(index)"
            @close="closeFrom(i)"
        />
    </AuthenticatedLayout>
</template>
