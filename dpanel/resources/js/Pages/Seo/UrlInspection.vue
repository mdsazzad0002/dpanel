<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import CheckList from '@/Pages/Seo/components/inspection/CheckList.vue';
import FaviconPanel from '@/Pages/Seo/components/inspection/FaviconPanel.vue';
import InspectionDetails from '@/Pages/Seo/components/inspection/InspectionDetails.vue';
import PwaPanel from '@/Pages/Seo/components/inspection/PwaPanel.vue';
import SearchPreviews from '@/Pages/Seo/components/inspection/SearchPreviews.vue';
import SocialPreviews from '@/Pages/Seo/components/inspection/SocialPreviews.vue';
import { badge } from '@/Pages/Seo/seoBadges';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    websites: { type: Array, default: () => [] },
    agents: { type: Array, default: () => [] },
});

const { panelRoute, requestJson } = usePanelApi();

// Internal: a page on one of the user's websites. External: any public URL.
const source = ref('internal');
const websiteId = ref(props.websites[0]?.id || '');
const path = ref('');
const externalUrl = ref('');
const agent = ref('default');
const canInspect = computed(() => (source.value === 'internal' ? !!websiteId.value : !!externalUrl.value.trim()));
const running = ref(false);
const error = ref('');
const report = ref(null);
const tab = ref('overview');

const websiteOptions = computed(() => props.websites.map((w) => ({ value: w.id, label: w.domain })));
const selectedSite = computed(() => props.websites.find((w) => w.id === websiteId.value));

const inspect = async () => {
    if (!canInspect.value || running.value) return;
    running.value = true;
    error.value = '';
    const body = source.value === 'internal'
        ? { source: 'internal', website_id: websiteId.value, path: path.value.trim() || null, agent: agent.value }
        : { source: 'external', url: externalUrl.value.trim(), agent: agent.value };
    try {
        report.value = await requestJson(panelRoute('seo.url-inspection.run'), { body });
        // Preview tabs only exist for HTML pages.
        if (!tabs.value.some((t) => t.key === tab.value)) tab.value = 'overview';
    } catch (e) {
        error.value = e.message || 'Inspection failed.';
    } finally {
        running.value = false;
    }
};

// A link in the report (a redirect target, a canonical) inspected next.
const inspectUrl = (url) => {
    source.value = 'external';
    externalUrl.value = url;
    inspect();
};

const overall = computed(() => {
    const t = report.value?.totals;
    if (!t) return null;
    if (t.fail) return { ...badge.fail, title: `${t.fail} ${t.fail === 1 ? 'problem' : 'problems'} to fix` };
    if (t.warn) return { ...badge.warn, title: `${t.warn} ${t.warn === 1 ? 'warning' : 'warnings'}` };
    return { ...badge.pass, title: 'Everything checks out' };
});

const sectionStatus = (key) => {
    const checks = report.value?.sections.find((s) => s.key === key)?.checks || [];
    if (checks.some((c) => c.status === 'fail')) return 'fail';
    if (checks.some((c) => c.status === 'warn')) return 'warn';
    return checks.length ? 'pass' : null;
};
const tabs = computed(() => [
    { key: 'overview', label: 'Overview', icon: 'bi-clipboard-check' },
    { key: 'search', label: 'Search previews', icon: 'bi-search', status: sectionStatus('content'), needs: 'preview' },
    { key: 'social', label: 'Share previews', icon: 'bi-share', status: sectionStatus('social'), needs: 'preview' },
    { key: 'favicon', label: 'Favicon', icon: 'bi-star', status: sectionStatus('favicon'), needs: 'preview' },
    { key: 'pwa', label: 'Web app', icon: 'bi-phone', status: sectionStatus('pwa'), needs: 'preview' },
    { key: 'details', label: 'Details', icon: 'bi-code-square' },
].filter((t) => !t.needs || report.value?.[t.needs]));

const statusText = computed(() => {
    const http = report.value?.http;
    if (!http) return '';
    return http.status ? `HTTP ${http.status}` : 'No response';
});
</script>

<template>
    <Head title="URL Inspection" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">URL Inspection</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">How Google, Bing and Yandex see a page, with search and share previews, favicon and web app checks.</p>
            </div>
        </template>

        <div class="space-y-4">
            <form class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800" @submit.prevent="inspect">
                <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-sm dark:border-slate-700 dark:bg-slate-900" role="radiogroup" aria-label="URL source">
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

                <div class="grid gap-3" :class="source === 'internal' ? 'lg:grid-cols-[1fr_1fr_14rem_auto]' : 'lg:grid-cols-[2fr_14rem_auto]'">
                    <template v-if="source === 'internal'">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Website</label>
                            <SearchableSelect v-model="websiteId" :options="websiteOptions" placeholder="Select a website…" search-placeholder="Search domains…" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Page path <span class="font-normal text-slate-400">(optional)</span></label>
                            <div class="flex rounded-lg border border-slate-300 text-sm focus-within:ring-1 focus-within:ring-blue-500 dark:border-slate-600 dark:bg-slate-900">
                                <span class="hidden max-w-[12rem] shrink-0 truncate border-r border-slate-200 bg-slate-50 px-2 py-2 text-slate-500 sm:block dark:border-slate-700 dark:bg-slate-800">{{ selectedSite ? `${selectedSite.enable_ssl ? 'https' : 'http'}://${selectedSite.domain}` : '' }}</span>
                                <input v-model="path" type="text" placeholder="/ (home page)" class="min-w-0 flex-1 rounded-r-lg border-0 bg-transparent text-sm focus:ring-0" />
                            </div>
                        </div>
                    </template>
                    <div v-else>
                        <label class="mb-1 block text-sm font-medium">Page URL</label>
                        <input v-model="externalUrl" type="text" inputmode="url" placeholder="https://example.com/page" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Fetch as</label>
                        <select v-model="agent" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                            <option v-for="a in agents" :key="a.value" :value="a.value">{{ a.label }}</option>
                        </select>
                    </div>
                    <button type="submit" :disabled="!canInspect || running" class="self-end rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                        <i class="bi mr-1" :class="running ? 'bi-arrow-repeat inline-block animate-spin' : 'bi-binoculars'"></i>{{ running ? 'Inspecting…' : 'Inspect' }}
                    </button>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">Fetching as a crawler shows whether the site serves bots something different. Some firewalls block requests that claim to be Googlebot but do not come from Google.</p>
            </form>

            <div v-if="source === 'internal' && !websites.length" class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-600 dark:bg-slate-800">
                You have no websites yet. Create one, or switch to External URL to inspect any public page.
            </div>
            <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-900/30 dark:text-red-300">{{ error }}</div>

            <template v-if="report">
                <div class="flex flex-wrap items-center gap-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                    <i class="bi text-3xl" :class="[overall.icon, overall.text]"></i>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ overall.title }}</p>
                        <a :href="report.final_url" target="_blank" rel="noopener noreferrer" class="block truncate text-sm text-blue-600 hover:underline dark:text-blue-400">{{ report.final_url }}</a>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ statusText }}<template v-if="report.http.time_ms"> · {{ report.http.time_ms }} ms</template>
                            · fetched as {{ report.agent }} · {{ new Date(report.checked_at).toLocaleString() }}
                        </p>
                    </div>
                    <div class="flex gap-2 text-xs">
                        <span v-for="key in ['pass', 'warn', 'fail']" :key="key" class="rounded px-2 py-1 font-medium" :class="badge[key].bg">
                            <i class="bi mr-1" :class="badge[key].icon"></i>{{ report.totals[key] }}
                        </span>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-3">
                    <div v-for="engine in report.engines" :key="engine.key" class="rounded-xl border bg-white p-4 dark:bg-slate-800" :class="engine.verdict === 'pass' ? 'border-emerald-200 dark:border-emerald-900' : engine.verdict === 'warn' ? 'border-amber-200 dark:border-amber-900' : 'border-red-200 dark:border-red-900'">
                        <div class="flex items-center gap-2">
                            <i class="bi text-xl" :class="[badge[engine.verdict].icon, badge[engine.verdict].text]"></i>
                            <p class="font-semibold">{{ engine.summary }}</p>
                        </div>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div v-for="part in [{ key: 'crawl', label: 'Crawl' }, { key: 'index', label: 'Indexing' }]" :key="part.key" class="flex gap-2">
                                <i class="bi mt-0.5" :class="[badge[engine[part.key].status].icon, badge[engine[part.key].status].text]"></i>
                                <div class="min-w-0">
                                    <dt class="font-medium">{{ part.label }}</dt>
                                    <dd class="break-words text-slate-500 dark:text-slate-400">{{ engine[part.key].detail }}</dd>
                                </div>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <nav class="flex min-w-max gap-1 border-b border-slate-200 dark:border-slate-700" role="tablist">
                        <button
                            v-for="t in tabs"
                            :key="t.key"
                            type="button"
                            role="tab"
                            :aria-selected="tab === t.key"
                            @click="tab = t.key"
                            class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition"
                            :class="tab === t.key ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                        >
                            <i class="bi" :class="t.icon"></i>{{ t.label }}
                            <i v-if="t.status && t.status !== 'pass'" class="bi text-xs" :class="[badge[t.status].icon, badge[t.status].text]"></i>
                        </button>
                    </nav>
                </div>

                <div v-if="tab === 'overview'" class="space-y-4">
                    <CheckList v-for="section in report.sections" :key="section.key" :title="section.label" :checks="section.checks" />
                </div>
                <SearchPreviews v-else-if="tab === 'search'" :report="report" />
                <SocialPreviews v-else-if="tab === 'social'" :report="report" />
                <FaviconPanel v-else-if="tab === 'favicon'" :report="report" />
                <PwaPanel v-else-if="tab === 'pwa'" :report="report" />
                <InspectionDetails v-else-if="tab === 'details'" :report="report" @inspect="inspectUrl" />
            </template>
        </div>
    </AuthenticatedLayout>
</template>
