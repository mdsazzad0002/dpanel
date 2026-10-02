<script setup>
import { reactive, ref } from 'vue';

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
    { value: 'off', label: 'Off', text: 'Every request goes to your server.' },
    { value: 'standard', label: 'Standard', text: 'Caches static files and anything your app marks cacheable with Cache-Control.' },
    { value: 'everything', label: 'Cache everything', text: 'Also caches HTML pages. Pages that set cookies, logged-in visitors and bypass paths are still skipped.' },
];
const ttlOptions = [
    [60, '1 minute'], [300, '5 minutes'], [1800, '30 minutes'], [3600, '1 hour'], [7200, '2 hours'],
    [14400, '4 hours'], [28800, '8 hours'], [86400, '1 day'], [604800, '1 week'], [2592000, '1 month'],
];
const browserTtlOptions = [[0, 'Respect what the site sends'], ...ttlOptions, [31536000, '1 year']];

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
        message.value = error.response?.data?.message || 'Saving failed.';
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h2 class="font-semibold">Caching</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Copies of your pages are kept in the server's memory and sent without running your app.</p>

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
                    <label class="mb-1 block text-sm font-medium">Edge cache TTL</label>
                    <select v-model.number="form.edge_ttl" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="[seconds, label] in ttlOptions" :key="seconds" :value="seconds">{{ label }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">How long a copy is kept when your app does not say (s-maxage wins when it does).</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Browser cache TTL</label>
                    <select v-model.number="form.browser_ttl" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option v-for="[seconds, label] in browserTtlOptions" :key="seconds" :value="seconds">{{ label }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">How long visitors' browsers keep cached responses. A purge cannot reach browsers.</p>
                    <p v-if="errors.browser_ttl" class="mt-1 text-xs text-red-600">{{ errors.browser_ttl }}</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-sm font-medium">Never cache these paths</label>
                        <button type="button" class="text-xs text-blue-600 hover:underline" @click="form.bypass_paths = defaults.bypass_paths">Use defaults</button>
                    </div>
                    <textarea v-model="form.bypass_paths" rows="7" spellcheck="false" class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"></textarea>
                    <p class="mt-1 text-xs text-slate-500">One path prefix per line, e.g. /wp-admin.</p>
                    <p v-if="errors.bypass_paths" class="mt-1 text-xs text-red-600">{{ errors.bypass_paths }}</p>
                </div>
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="text-sm font-medium">Skip the cache for visitors with these cookies</label>
                        <button type="button" class="text-xs text-blue-600 hover:underline" @click="form.bypass_cookies = defaults.bypass_cookies">Use defaults</button>
                    </div>
                    <textarea v-model="form.bypass_cookies" rows="7" spellcheck="false" class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"></textarea>
                    <p class="mt-1 text-xs text-slate-500">Cookie name prefixes, one per line, e.g. wordpress_logged_in_.</p>
                    <p v-if="errors.bypass_cookies" class="mt-1 text-xs text-red-600">{{ errors.bypass_cookies }}</p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-700">
                    <input v-model="form.serve_stale" type="checkbox" class="mt-0.5 rounded border-slate-300" />
                    <span><span class="font-medium">Keep the site up when the app fails</span><span class="mt-1 block text-xs text-slate-500">If your app returns a 5xx error, visitors get the last cached copy (kept up to a day).</span></span>
                </label>
                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-700">
                    <input v-model="form.ignore_query_string" type="checkbox" class="mt-0.5 rounded border-slate-300" />
                    <span><span class="font-medium">Ignore query strings</span><span class="mt-1 block text-xs text-slate-500">/page?utm_source=x and /page share one copy. Leave off if your pages change with ?parameters.</span></span>
                </label>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <button type="button" :disabled="saving" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900" @click="save">{{ saving ? 'Saving…' : 'Save settings' }}</button>
            <p v-if="message" class="text-sm text-slate-600 dark:text-slate-300">{{ message }}</p>
        </div>
    </section>
</template>
