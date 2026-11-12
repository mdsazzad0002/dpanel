<script setup>
import { useI18n } from 'vue-i18n';
import { reactive, ref } from 'vue';

const { t } = useI18n();

const props = defineProps({
    settings: { type: Object, required: true },
    defaults: { type: Object, required: true },
    saveUrl: { type: String, required: true },
});
const emit = defineEmits(['saved']);

const form = reactive({
    mode: props.settings.mode,
    edge_ttl: props.settings.edge_ttl,
    browser_ttl: props.settings.browser_ttl ?? 0,
    bypass_paths: props.settings.bypass_paths,
    bypass_cookies: props.settings.bypass_cookies,
    ignore_query_string: props.settings.ignore_query_string,
    serve_stale: props.settings.serve_stale,
});
const saving = ref(false);
const message = ref('');
const errors = ref({});

const modes = [
    { value: 'off', label: t('websites.edgeCache.off'), text: t('websites.edgeCache.offText') },
    { value: 'standard', label: t('websites.edgeCache.standard'), text: t('websites.edgeCache.standardText') },
    { value: 'everything', label: t('websites.edgeCache.cacheAll'), text: t('websites.edgeCache.cacheAllText') },
];
const ttlOptions = [
    [60, t('websites.edgeCache.minutes', { n: 1 }, 1)], [300, t('websites.edgeCache.minutes', { n: 5 }, 5)], [1800, t('websites.edgeCache.minutes', { n: 30 }, 30)], [3600, t('websites.edgeCache.hours', { n: 1 }, 1)], [7200, t('websites.edgeCache.hours', { n: 2 }, 2)],
    [14400, t('websites.edgeCache.hours', { n: 4 }, 4)], [28800, t('websites.edgeCache.hours', { n: 8 }, 8)], [86400, t('websites.edgeCache.day')], [604800, t('websites.edgeCache.week')], [2592000, t('websites.edgeCache.month')],
];
const browserTtlOptions = [[0, t('websites.edgeCache.respect')], ...ttlOptions, [31536000, t('websites.edgeCache.year')]];

const save = async () => {
    saving.value = true;
    message.value = '';
    errors.value = {};
    try {
        const { data } = await window.axios.put(props.saveUrl, form, { headers: { Accept: 'application/json' } });
        message.value = data.message;
        emit('saved', data.settings);
    } catch (error) {
        errors.value = Object.fromEntries(Object.entries(error.response?.data?.errors || {}).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]));
        message.value = error.response?.data?.message || t('websites.edgeCache.saveFailed');
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h2 class="font-semibold">{{ t('websites.edgeCache.caching') }}</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('websites.edgeCache.cachingHelp') }}</p>

        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            <label v-for="mode in modes" :key="mode.value" class="cursor-pointer rounded-xl border p-4 text-sm transition" :class="form.mode === mode.value ? 'border-blue-500 bg-blue-50 dark:border-blue-500 dark:bg-blue-500/10' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700'">
                <input v-model="form.mode" type="radio" :value="mode.value" class="sr-only" />
                <span class="block font-semibold">{{ mode.label }}</span>
                <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ mode.text }}</span>
            </label>
        </div>

        <div v-if="form.mode !== 'off'" class="mt-6 grid gap-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ t('websites.edgeCache.edgeTtl') }}</label>
                    <select v-model.number="form.edge_ttl" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="[seconds, label] in ttlOptions" :key="seconds" :value="seconds">{{ label }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">{{ t('websites.edgeCache.edgeTtlHelp') }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ t('websites.edgeCache.browserTtl') }}</label>
                    <select v-model.number="form.browser_ttl" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="[seconds, label] in browserTtlOptions" :key="seconds" :value="seconds">{{ label }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">{{ t('websites.edgeCache.browserTtlHelp') }}</p>
                    <p v-if="errors.browser_ttl" class="mt-1 text-xs text-red-600">{{ errors.browser_ttl }}</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-sm font-medium">{{ t('websites.edgeCache.bypassPaths') }}</label>
                        <button type="button" class="text-xs text-blue-600 hover:underline" @click="form.bypass_paths = defaults.bypass_paths">{{ t('websites.edgeCache.useDefaults') }}</button>
                    </div>
                    <textarea v-model="form.bypass_paths" rows="7" spellcheck="false" class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"></textarea>
                    <p class="mt-1 text-xs text-slate-500">{{ t('websites.edgeCache.bypassPathsHelp') }}</p>
                    <p v-if="errors.bypass_paths" class="mt-1 text-xs text-red-600">{{ errors.bypass_paths }}</p>
                </div>
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-sm font-medium">{{ t('websites.edgeCache.bypassCookies') }}</label>
                        <button type="button" class="text-xs text-blue-600 hover:underline" @click="form.bypass_cookies = defaults.bypass_cookies">{{ t('websites.edgeCache.useDefaults') }}</button>
                    </div>
                    <textarea v-model="form.bypass_cookies" rows="7" spellcheck="false" class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"></textarea>
                    <p class="mt-1 text-xs text-slate-500">{{ t('websites.edgeCache.bypassCookiesHelp') }}</p>
                    <p v-if="errors.bypass_cookies" class="mt-1 text-xs text-red-600">{{ errors.bypass_cookies }}</p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-700">
                    <input v-model="form.serve_stale" type="checkbox" class="mt-0.5 rounded border-slate-300" />
                    <span><span class="font-medium">{{ t('websites.edgeCache.serveStale') }}</span><span class="mt-1 block text-xs text-slate-500">{{ t('websites.edgeCache.serveStaleHelp') }}</span></span>
                </label>
                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-700">
                    <input v-model="form.ignore_query_string" type="checkbox" class="mt-0.5 rounded border-slate-300" />
                    <span><span class="font-medium">{{ t('websites.edgeCache.ignoreQuery') }}</span><span class="mt-1 block text-xs text-slate-500">{{ t('websites.edgeCache.ignoreQueryHelp') }}</span></span>
                </label>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <button type="button" :disabled="saving" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900" @click="save">{{ saving ? t('websites.edgeCache.saving') : t('websites.edgeCache.save') }}</button>
            <p v-if="message" class="text-sm text-slate-600 dark:text-slate-300">{{ message }}</p>
        </div>
    </section>
</template>
