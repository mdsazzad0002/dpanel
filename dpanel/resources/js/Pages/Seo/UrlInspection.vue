<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import CheckList from '@/Pages/Seo/components/inspection/CheckList.vue';
import FaviconPanel from '@/Pages/Seo/components/inspection/FaviconPanel.vue';
import InspectionDetails from '@/Pages/Seo/components/inspection/InspectionDetails.vue';
import PagePreview from '@/Pages/Seo/components/inspection/PagePreview.vue';
import PwaPanel from '@/Pages/Seo/components/inspection/PwaPanel.vue';
import SearchPreviews from '@/Pages/Seo/components/inspection/SearchPreviews.vue';
import SocialPreviews from '@/Pages/Seo/components/inspection/SocialPreviews.vue';
import { badge } from '@/Pages/Seo/seoBadges';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    websites: { type: Array, default: () => [] },
    agents: { type: Array, default: () => [] },
    canManageWebsites: { type: Boolean, default: false },
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
// The website the shown report belongs to, when it was an internal inspection.
const inspectedSiteId = ref(null);

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
        const data = await requestJson(panelRoute('seo.url-inspection.run'), { body });
        // A 200 that is not a report (a login page after the session expired,
        // a cut-off body) parses to {} — say so instead of rendering it.
        if (!data?.totals) throw new Error(t('seo.inspect.noReport'));
        report.value = data;
        inspectedSiteId.value = body.source === 'internal' ? body.website_id : null;
        // Preview tabs only exist for HTML pages.
        if (!tabs.value.some((tb) => tb.key === tab.value)) tab.value = 'overview';
    } catch (e) {
        error.value = e.message || t('seo.inspect.failed');
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
    const totals = report.value?.totals;
    if (!totals) return null;
    if (totals.fail) return { ...badge.fail, title: totals.fail === 1 ? t('seo.inspect.problem', { count: 1 }) : t('seo.inspect.problems', { count: totals.fail }) };
    if (totals.warn) return { ...badge.warn, title: totals.warn === 1 ? t('seo.inspect.warning', { count: 1 }) : t('seo.inspect.warnings', { count: totals.warn }) };
    return { ...badge.pass, title: t('seo.inspect.allGood') };
});

// SSL and redirects are fixed on the website's manage page.
const manageHref = computed(() => (props.canManageWebsites && inspectedSiteId.value ? panelRoute('websites.manage', { id: inspectedSiteId.value }) : null));
const checkActions = computed(() => {
    const https = report.value?.sections.find((s) => s.key === 'http')?.checks.find((c) => c.key === 'https');
    if (!manageHref.value || !https || https.status === 'pass') return {};
    return { https: { label: t('seo.inspect.manage'), icon: 'bi-gear', href: manageHref.value } };
});

const sectionStatus = (key) => {
    const checks = report.value?.sections.find((s) => s.key === key)?.checks || [];
    if (checks.some((c) => c.status === 'fail')) return 'fail';
    if (checks.some((c) => c.status === 'warn')) return 'warn';
    return checks.length ? 'pass' : null;
};
const tabs = computed(() => [
    { key: 'overview', label: t('seo.inspect.tabs.overview'), icon: 'bi-clipboard-check' },
    { key: 'search', label: t('seo.inspect.tabs.search'), icon: 'bi-search', status: sectionStatus('content'), needs: 'preview' },
    { key: 'social', label: t('seo.inspect.tabs.social'), icon: 'bi-share', status: sectionStatus('social'), needs: 'preview' },
    { key: 'favicon', label: t('seo.inspect.tabs.favicon'), icon: 'bi-star', status: sectionStatus('favicon'), needs: 'preview' },
    { key: 'pwa', label: t('seo.inspect.tabs.pwa'), icon: 'bi-phone', status: sectionStatus('pwa'), needs: 'preview' },
    { key: 'page', label: t('seo.inspect.tabs.page'), icon: 'bi-window' },
    { key: 'details', label: t('seo.inspect.tabs.details'), icon: 'bi-code-square' },
].filter((tb) => !tb.needs || report.value?.[tb.needs]));

const statusText = computed(() => {
    const http = report.value?.http;
    if (!http) return '';
    return http.status ? `HTTP ${http.status}` : t('seo.inspect.noResponse');
});
</script>

<template>
    <Head :title="t('seo.inspect.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('seo.inspect.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('seo.inspect.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <form class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800" @submit.prevent="inspect">
                <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-sm dark:border-slate-700 dark:bg-slate-900" role="radiogroup" :aria-label="t('seo.inspect.source')">
                    <button
                        v-for="option in [{ value: 'internal', label: t('seo.inspect.internal'), icon: 'bi-hdd-stack' }, { value: 'external', label: t('seo.inspect.external'), icon: 'bi-globe2' }]"
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
                            <label class="mb-1 block text-sm font-medium">{{ t('seo.inspect.website') }}</label>
                            <SearchableSelect v-model="websiteId" :options="websiteOptions" :placeholder="t('seo.inspect.selectSite')" :search-placeholder="t('seo.inspect.searchDomains')" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ t('seo.inspect.path') }} <span class="font-normal text-slate-400">{{ t('seo.inspect.optional') }}</span></label>
                            <div class="flex rounded-lg border border-slate-300 text-sm focus-within:ring-1 focus-within:ring-blue-500 dark:border-slate-600 dark:bg-slate-900">
                                <span class="hidden max-w-[12rem] shrink-0 truncate border-r border-slate-200 bg-slate-50 px-2 py-2 text-slate-500 sm:block dark:border-slate-700 dark:bg-slate-800">{{ selectedSite ? `${selectedSite.enable_ssl ? 'https' : 'http'}://${selectedSite.domain}` : '' }}</span>
                                <input v-model="path" type="text" :placeholder="t('seo.inspect.pathPlaceholder')" class="min-w-0 flex-1 rounded-r-lg border-0 bg-transparent text-sm focus:ring-0" />
                            </div>
                        </div>
                    </template>
                    <div v-else>
                        <label class="mb-1 block text-sm font-medium">{{ t('seo.inspect.pageUrl') }}</label>
                        <input v-model="externalUrl" type="text" inputmode="url" placeholder="https://example.com/page" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ t('seo.inspect.fetchAs') }}</label>
                        <select v-model="agent" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                            <option v-for="a in agents" :key="a.value" :value="a.value">{{ a.label }}</option>
                        </select>
                    </div>
                    <button type="submit" :disabled="!canInspect || running" class="self-end rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50">
                        <i class="bi mr-1" :class="running ? 'bi-arrow-repeat inline-block animate-spin' : 'bi-binoculars'"></i>{{ running ? t('seo.inspect.inspecting') : t('seo.inspect.inspect') }}
                    </button>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('seo.inspect.crawlerNote') }}</p>
            </form>

            <div v-if="source === 'internal' && !websites.length" class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-600 dark:bg-slate-800">
                {{ t('seo.inspect.noSites') }}
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
                            · {{ t('seo.inspect.fetchedAs', { agent: report.agent }) }} · {{ new Date(report.checked_at).toLocaleString() }}
                        </p>
                    </div>
                    <Link v-if="manageHref" :href="manageHref" class="flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700">
                        <i class="bi bi-gear"></i>{{ t('seo.inspect.manage') }}
                    </Link>
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
                            <div v-for="part in [{ key: 'crawl', label: t('seo.inspect.crawl') }, { key: 'index', label: t('seo.inspect.indexing') }]" :key="part.key" class="flex gap-2">
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
                            v-for="tb in tabs"
                            :key="tb.key"
                            type="button"
                            role="tab"
                            :aria-selected="tab === tb.key"
                            @click="tab = tb.key"
                            class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition"
                            :class="tab === tb.key ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                        >
                            <i class="bi" :class="tb.icon"></i>{{ tb.label }}
                            <i v-if="tb.status && tb.status !== 'pass'" class="bi text-xs" :class="[badge[tb.status].icon, badge[tb.status].text]"></i>
                        </button>
                    </nav>
                </div>

                <div v-if="tab === 'overview'" class="space-y-4">
                    <CheckList v-for="section in report.sections" :key="section.key" :title="section.label" :checks="section.checks" :actions="checkActions" />
                </div>
                <SearchPreviews v-else-if="tab === 'search'" :report="report" />
                <SocialPreviews v-else-if="tab === 'social'" :report="report" />
                <FaviconPanel v-else-if="tab === 'favicon'" :report="report" />
                <PwaPanel v-else-if="tab === 'pwa'" :report="report" />
                <PagePreview v-else-if="tab === 'page'" :report="report" />
                <InspectionDetails v-else-if="tab === 'details'" :report="report" @inspect="inspectUrl" />
            </template>
        </div>
    </AuthenticatedLayout>
</template>
