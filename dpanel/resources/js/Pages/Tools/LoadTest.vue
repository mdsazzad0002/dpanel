<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    domains: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ requests: 300, concurrency: 20, timeout: 8 }) },
});

const { panelRoute, requestJson } = usePanelApi();

const scheme = ref('https');
const domain = ref(props.domains[0] || '');
const path = ref('/');
const requests = ref(Math.min(50, props.limits.requests));
const concurrency = ref(Math.min(5, props.limits.concurrency));
const running = ref(false);
const error = ref('');
const result = ref(null);

const domainOptions = computed(() => props.domains.map((d) => ({ value: d, label: d })));
const canRun = computed(() => props.domains.includes(domain.value) && requests.value > 0 && concurrency.value > 0);
const statusRows = computed(() => (result.value ? Object.entries(result.value.statuses).sort((a, b) => a[0] - b[0]) : []));
const statusClass = (code) => {
    const n = Number(code);
    if (n >= 500) return 'text-red-600 dark:text-red-400';
    if (n >= 400) return 'text-amber-600 dark:text-amber-400';
    if (n >= 300) return 'text-sky-600 dark:text-sky-400';
    return 'text-emerald-600 dark:text-emerald-400';
};

const run = async () => {
    if (!canRun.value) return;
    running.value = true;
    error.value = '';
    result.value = null;
    try {
        result.value = await requestJson(panelRoute('tools.load-test.run'), {
            body: {
                url: `${scheme.value}://${domain.value}`,
                path: path.value,
                requests: Number(requests.value),
                concurrency: Number(concurrency.value),
            },
        });
    } catch (e) {
        error.value = e.message;
    } finally {
        running.value = false;
    }
};
</script>

<template>
    <Head :title="t('tools.loadTest.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-lg text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                    <i class="bi bi-speedometer2"></i>
                </span>
                <div>
                    <h1 class="text-lg font-semibold">{{ t('tools.loadTest.title') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('tools.loadTest.subtitle') }}</p>
                </div>
            </div>
        </template>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
            <form class="space-y-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800" @submit.prevent="run">
                <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                    <i class="bi bi-shield-lock mr-1"></i>{{ t('tools.loadTest.ownOnly') }}
                </div>

                <div v-if="!domains.length" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
                    {{ t('tools.loadTest.noDomains') }}
                </div>
                <template v-else>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ t('tools.loadTest.target') }}</label>
                        <div class="flex gap-2">
                            <select v-model="scheme" class="rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900">
                                <option value="https">https://</option>
                                <option value="http">http://</option>
                            </select>
                            <div class="flex-1">
                                <SearchableSelect
                                    v-model="domain"
                                    :options="domainOptions"
                                    :placeholder="t('tools.loadTest.selectDomain')"
                                    :search-placeholder="t('tools.loadTest.searchDomains')"
                                />
                            </div>
                        </div>
                    </div>
                    <div>
                        <label for="lt-path" class="mb-1 block text-sm font-medium">{{ t('tools.loadTest.path') }}</label>
                        <input id="lt-path" v-model="path" type="text" spellcheck="false" autocomplete="off" placeholder="/" class="w-full rounded-lg border-slate-300 font-mono text-sm dark:border-slate-600 dark:bg-slate-900" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="lt-req" class="mb-1 flex items-center justify-between text-sm font-medium">{{ t('tools.loadTest.requests') }} <span class="font-mono text-slate-400">{{ requests }}</span></label>
                            <input id="lt-req" v-model.number="requests" type="range" min="1" :max="limits.requests" class="w-full accent-amber-600" />
                        </div>
                        <div>
                            <label for="lt-con" class="mb-1 flex items-center justify-between text-sm font-medium">{{ t('tools.loadTest.concurrency') }} <span class="font-mono text-slate-400">{{ concurrency }}</span></label>
                            <input id="lt-con" v-model.number="concurrency" type="range" min="1" :max="limits.concurrency" class="w-full accent-amber-600" />
                        </div>
                    </div>
                    <p class="text-xs text-slate-400">{{ t('tools.loadTest.caps', { r: limits.requests, c: limits.concurrency, s: limits.timeout }) }}</p>

                    <button type="submit" :disabled="!canRun || running" class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-50">
                        <i class="bi" :class="running ? 'bi-arrow-repeat animate-spin' : 'bi-play-fill'"></i>
                        {{ running ? t('tools.loadTest.running') : t('tools.loadTest.run') }}
                    </button>
                    <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
                </template>
            </form>

            <section class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                <h2 class="mb-3 text-sm font-semibold">{{ t('tools.loadTest.results') }}</h2>
                <div v-if="!result" class="py-12 text-center text-sm text-slate-400">
                    <i class="bi bi-speedometer2 text-3xl"></i>
                    <p class="mt-2">{{ t('tools.loadTest.empty') }}</p>
                </div>
                <div v-else class="space-y-4">
                    <p v-if="result.capped" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                        <i class="bi bi-hourglass-split mr-1"></i>{{ t('tools.loadTest.cappedNote') }}
                    </p>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                            <div class="text-xs text-slate-500 dark:text-slate-400">{{ t('tools.loadTest.sent') }}</div>
                            <div class="text-xl font-semibold">{{ result.sent }}</div>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                            <div class="text-xs text-slate-500 dark:text-slate-400">{{ t('tools.loadTest.rps') }}</div>
                            <div class="text-xl font-semibold">{{ result.rps ?? '—' }}</div>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                            <div class="text-xs text-slate-500 dark:text-slate-400">{{ t('tools.loadTest.failures') }}</div>
                            <div class="text-xl font-semibold" :class="result.failures ? 'text-red-600 dark:text-red-400' : ''">{{ result.failures }}</div>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                            <div class="text-xs text-slate-500 dark:text-slate-400">{{ t('tools.loadTest.elapsed') }}</div>
                            <div class="text-xl font-semibold">{{ result.elapsedMs }} ms</div>
                        </div>
                    </div>

                    <div v-if="result.latency">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('tools.loadTest.latency') }}</h3>
                        <div class="grid grid-cols-5 gap-2 text-center text-sm">
                            <div v-for="k in ['min', 'p50', 'avg', 'p95', 'max']" :key="k" class="rounded-lg border border-slate-200 py-2 dark:border-slate-700">
                                <div class="text-[11px] uppercase text-slate-400">{{ k }}</div>
                                <div class="font-mono font-semibold">{{ result.latency[k] }}<span class="text-xs font-normal text-slate-400">ms</span></div>
                            </div>
                        </div>
                    </div>

                    <div v-if="statusRows.length">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ t('tools.loadTest.statusCodes') }}</h3>
                        <div class="flex flex-wrap gap-2">
                            <span v-for="[code, n] in statusRows" :key="code" class="rounded-full border border-slate-200 px-3 py-1 text-sm dark:border-slate-700">
                                <span class="font-mono font-semibold" :class="statusClass(code)">{{ code }}</span>
                                <span class="ml-1 text-slate-500 dark:text-slate-400">× {{ n }}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
