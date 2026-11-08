<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useWordpressApi } from '../useWordpressApi';

const props = defineProps({ site: { type: Object, required: true } });
const { t } = useI18n();
const api = useWordpressApi();

const staging = ref(null);
const targets = ref([]);
const choice = ref('');
const loading = ref(false);
const error = ref('');
let timer = null;

const busy = () => ['queued', 'copying', 'rewriting', 'testing', 'updating'].includes(staging.value?.stage);

const load = async () => {
    loading.value = true;
    try {
        const data = await api.get('wordpress.staging', { site: props.site.id });
        staging.value = data.staging;
        targets.value = data.targets || [];
        error.value = '';
    } catch (e) {
        error.value = api.message(e, t('wordpress.loadFailed'));
    } finally {
        loading.value = false;
    }
    window.clearTimeout(timer);
    if (busy()) timer = window.setTimeout(load, 5000);
};

const act = async (work) => {
    error.value = '';
    try {
        await work();
    } catch (e) {
        error.value = api.message(e, t('wordpress.stagingFailed'));
    }
    await load();
};

const link = () => act(() => api.post('wordpress.staging.link', { site: props.site.id }, { staging_website_id: choice.value }));
const unlink = () => window.confirm(t('wordpress.unlinkConfirm')) && act(() => api.destroy('wordpress.staging.unlink', { site: props.site.id }));
const copy = (direction) => {
    const text = direction === 'to_staging' ? t('wordpress.copyConfirm', { from: props.site.domain, to: staging.value.staging_domain }) : t('wordpress.deployConfirm', { from: staging.value.staging_domain, to: props.site.domain });
    if (window.confirm(text)) act(() => api.post('wordpress.staging.copy', { site: props.site.id }, { direction }));
};
const when = (value) => (value ? new Date(value).toLocaleString() : t('wordpress.never'));

onMounted(load);
onUnmounted(() => window.clearTimeout(timer));
</script>

<template>
    <div class="space-y-4 p-4">
        <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('wordpress.stagingHelp') }}</p>
        <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>

        <div v-if="!staging && !loading" class="flex flex-col gap-2 rounded-xl border border-slate-200 p-4 sm:flex-row sm:items-end dark:border-slate-800">
            <label class="flex-1 text-xs text-slate-500">{{ t('wordpress.stagingSite') }}
                <select v-model="choice" class="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option value="" disabled>{{ targets.length ? t('wordpress.pickSite') : t('wordpress.noFreeSite') }}</option>
                    <option v-for="target in targets" :key="target.id" :value="target.id">{{ target.domain }}</option>
                </select>
            </label>
            <button type="button" :disabled="!choice" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="link">{{ t('wordpress.useAsStaging') }}</button>
        </div>

        <div v-if="staging" class="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="font-semibold">{{ site.domain }}</span>
                <i class="bi bi-arrow-left-right text-slate-400"></i>
                <span class="font-semibold">{{ staging.staging_domain }}</span>
                <button type="button" :disabled="busy()" class="ml-auto text-xs text-red-600 disabled:opacity-50" @click="unlink">{{ t('wordpress.unlink') }}</button>
            </div>
            <p class="text-xs text-slate-500">{{ t('wordpress.lastCopy') }}: {{ when(staging.copied_at) }} · {{ t('wordpress.lastDeploy') }}: {{ when(staging.deployed_at) }}</p>
            <p v-if="busy()" class="text-sm text-blue-700 dark:text-blue-300"><i class="bi bi-arrow-repeat mr-1 animate-spin"></i>{{ t(`wordpress.stage.${staging.stage}`) }}</p>
            <p v-else-if="staging.stage === 'failed'" class="text-sm text-red-700 dark:text-red-300">{{ staging.message }}</p>
            <p v-else-if="staging.direction === 'safe_update' && staging.message" class="text-sm text-emerald-700 dark:text-emerald-300">{{ staging.message }}</p>
            <div class="flex flex-wrap gap-2">
                <button type="button" :disabled="busy()" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold disabled:opacity-50 dark:border-slate-700" @click="copy('to_staging')">{{ t('wordpress.copyToStaging') }}</button>
                <button type="button" :disabled="busy() || !staging.copied_at" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="copy('to_production')">{{ t('wordpress.deploy') }}</button>
            </div>
        </div>
    </div>
</template>
