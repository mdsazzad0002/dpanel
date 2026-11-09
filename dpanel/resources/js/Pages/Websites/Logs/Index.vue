<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';
import LogLines from './components/LogLines.vue';

const props = defineProps({ website: { type: Object, required: true } });
const { t } = useI18n();
const { panelRoute } = usePanelApi();

const kind = ref('access');
const count = ref(200);
const filter = ref('');
const follow = ref(false);
const lines = ref([]);
const exists = ref(true);
const loading = ref(false);
const error = ref('');
let timer = null;

const shown = computed(() => {
    const needle = filter.value.trim().toLowerCase();
    return needle ? lines.value.filter((line) => line.toLowerCase().includes(needle)) : lines.value;
});

const load = async () => {
    loading.value = true;
    try {
        const { data } = await window.axios.get(panelRoute('websites.logs.lines', { id: props.website.id }), { params: { kind: kind.value, lines: count.value } });
        lines.value = data.lines || [];
        exists.value = !!data.exists;
        error.value = '';
    } catch (e) {
        error.value = e?.response?.data?.message || t('siteLogs.loadFailed');
    } finally {
        loading.value = false;
    }
};

watch([kind, count], load);
watch(follow, (on) => {
    window.clearInterval(timer);
    if (on) timer = window.setInterval(load, 5000);
});
onMounted(load);
onUnmounted(() => window.clearInterval(timer));
</script>

<template>
    <Head :title="t('siteLogs.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex w-full items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">{{ t('siteLogs.title') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('siteLogs.subtitle', { domain: website.domain }) }}</p>
                </div>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700">{{ t('common.back') }}</Link>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-4">
            <div class="flex flex-col gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm sm:flex-row sm:items-center dark:border-slate-800/80 dark:bg-slate-900/50">
                <div class="inline-flex rounded-xl border border-slate-200 p-1 dark:border-slate-700" role="tablist">
                    <button v-for="option in ['access', 'waf', 'php']" :key="option" type="button" role="tab" :aria-selected="kind === option"
                        class="rounded-lg px-3 py-1.5 text-sm font-semibold"
                        :class="kind === option ? 'bg-blue-600 text-white' : 'text-slate-600 dark:text-slate-300'"
                        @click="kind = option">
                        {{ t(`siteLogs.${option}`) }}
                    </button>
                </div>
                <input v-model="filter" type="search" :placeholder="t('siteLogs.filter')" class="flex-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                <select v-model.number="count" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" :aria-label="t('siteLogs.lines')">
                    <option v-for="n in [100, 200, 500, 1000, 2000]" :key="n" :value="n">{{ t('siteLogs.lastN', { n }) }}</option>
                </select>
                <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input v-model="follow" type="checkbox" class="rounded" /> {{ t('siteLogs.follow') }}
                </label>
                <button type="button" :disabled="loading" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300" @click="load">
                    <i class="bi bi-arrow-clockwise" :class="{ 'animate-spin': loading }"></i> {{ t('common.refresh') }}
                </button>
            </div>

            <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
            <LogLines :lines="shown" :empty-text="exists ? t('siteLogs.noMatch') : t({ access: 'siteLogs.noAccessLog', waf: 'siteLogs.noWafLog', php: 'siteLogs.noPhpLog' }[kind])" />
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t({ access: 'siteLogs.accessHelp', waf: 'siteLogs.wafHelp', php: 'siteLogs.phpHelp' }[kind]) }}</p>
        </div>
    </AuthenticatedLayout>
</template>
