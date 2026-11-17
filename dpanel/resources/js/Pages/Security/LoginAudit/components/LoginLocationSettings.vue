<script setup>
import { reactive, ref } from 'vue';
import { canReadLocation, readDeviceLocation } from '@/composables/deviceLocation';
import { accuracyText } from '../loginEventFormat';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    // { https: 'ask', http: 'off' }
    modes: { type: Object, required: true },
    // The protocol this page was opened on; the test runs here.
    scheme: { type: String, default: 'https' },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['save', 'show-on-map']);

const choice = reactive({ ...props.modes });
const testing = ref(false);
const test = ref(null);
const testError = ref('');
const browserReady = canReadLocation();

const SCHEMES = [
    { value: 'https', title: 'HTTPS', icon: 'bi-lock', get note() { return t('security.loginAudit.location.httpsNote'); } },
    { value: 'http', title: 'HTTP', icon: 'bi-unlock', get note() { return t('security.loginAudit.location.httpNote'); } },
];

const MODES = [
    { value: 'off', get title() { return t('security.loginAudit.location.modes.off.title'); }, get text() { return t('security.loginAudit.location.modes.off.text'); } },
    { value: 'ask', get title() { return t('security.loginAudit.location.modes.ask.title'); }, get text() { return t('security.loginAudit.location.modes.ask.text'); } },
    { value: 'required', get title() { return t('security.loginAudit.location.modes.required.title'); }, get text() { return t('security.loginAudit.location.modes.required.text'); } },
];

// A working test on this protocol is the only proof a browser gives a location here.
const proven = (scheme) => scheme === props.scheme && !!test.value;

const runTest = async () => {
    testing.value = true;
    testError.value = '';
    test.value = null;
    try {
        test.value = await readDeviceLocation();
    } catch (error) {
        testError.value = error.message;
    } finally {
        testing.value = false;
    }
};

const save = (scheme) => {
    const mode = choice[scheme];
    if (mode === 'required' && props.modes[scheme] !== 'required' && !proven(scheme)) {
        const why = scheme === 'http'
            ? t('security.loginAudit.location.whyHttp')
            : t('security.loginAudit.location.whyHttps');
        const ok = window.confirm(t('security.loginAudit.location.requireConfirm', { scheme: scheme.toUpperCase(), why, cmd: 'php artisan login-audit:location off' }));
        if (!ok) return;
    }
    emit('save', { scheme, mode });
};
</script>

<template>
    <div class="space-y-5 text-sm">
        <p class="text-xs text-slate-500 dark:text-slate-400">
            {{ t('security.loginAudit.location.intro') }}
        </p>

        <div class="space-y-2 rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-800/50">
            <p><i18n-t keypath="security.loginAudit.location.youAreOn" scope="global"><template #scheme><strong>{{ scheme.toUpperCase() }}</strong></template></i18n-t></p>
            <button
                type="button"
                :disabled="testing || !browserReady"
                class="rounded-md border border-slate-300 bg-white px-3 py-1.5 hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800"
                @click="runTest"
            >
                <i class="bi bi-crosshair" /> {{ testing ? t('security.loginAudit.location.reading') : t('security.loginAudit.location.testOn', { scheme: scheme.toUpperCase() }) }}
            </button>
            <div v-if="test" class="text-emerald-700 dark:text-emerald-400">
                <i class="bi bi-check-circle" />
                {{ t('security.loginAudit.location.works', { scheme: scheme.toUpperCase(), coords: `${test.device_latitude.toFixed(6)}, ${test.device_longitude.toFixed(6)} ${accuracyText(test.device_accuracy)}` }) }}
                <button type="button" class="ml-1 text-blue-600 hover:underline dark:text-blue-400" @click="emit('show-on-map', test)">{{ t('security.loginAudit.location.showOnMap') }}</button>
            </div>
            <p v-if="testError" class="text-red-600 dark:text-red-400"><i class="bi bi-x-circle" /> {{ testError }}</p>
        </div>

        <div v-for="item in SCHEMES" :key="item.value" class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
            <div class="mb-2 flex items-start justify-between gap-2">
                <div>
                    <span class="font-medium"><i :class="['bi', item.icon]" /> {{ item.title }}</span>
                    <span v-if="item.value === scheme" class="ml-1 rounded bg-blue-100 px-1.5 text-[10px] text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">{{ t('security.loginAudit.location.thisPage') }}</span>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ item.note }}</p>
                </div>
                <button
                    type="button"
                    :disabled="busy || choice[item.value] === modes[item.value]"
                    class="shrink-0 rounded-md bg-slate-900 px-4 py-1.5 text-xs text-white hover:bg-slate-700 disabled:opacity-40 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                    @click="save(item.value)"
                >
                    {{ busy ? t('security.loginAudit.location.saving') : t('common.save') }}
                </button>
            </div>
            <div class="space-y-1.5">
                <label
                    v-for="mode in MODES"
                    :key="mode.value"
                    class="flex cursor-pointer gap-2 rounded-md border p-2"
                    :class="choice[item.value] === mode.value ? 'border-blue-500 bg-blue-50 dark:border-blue-500 dark:bg-blue-950/40' : 'border-slate-200 dark:border-slate-700'"
                >
                    <input v-model="choice[item.value]" type="radio" :name="`location-${item.value}`" :value="mode.value" class="mt-0.5" />
                    <span>
                        <span class="font-medium">{{ mode.title }}</span>
                        <span v-if="modes[item.value] === mode.value" class="ml-1 rounded bg-slate-100 px-1.5 text-[10px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ t('security.loginAudit.location.current') }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ mode.text }}</span>
                    </span>
                </label>
            </div>
            <p
                v-if="choice[item.value] === 'required' && !proven(item.value)"
                class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
            >
                <i class="bi bi-exclamation-triangle" />
                {{ item.value === 'http' ? t('security.loginAudit.location.notTestedHttp', { scheme: item.value.toUpperCase() }) : t('security.loginAudit.location.notTested', { scheme: item.value.toUpperCase() }) }}
            </p>
        </div>

        <p class="text-xs text-slate-500 dark:text-slate-400">
            <i18n-t keypath="security.loginAudit.location.lockedOut" scope="global"><template #cmd><code class="rounded bg-slate-100 px-1 dark:bg-slate-800">php artisan login-audit:location off</code></template><template #flag><code class="rounded bg-slate-100 px-1 dark:bg-slate-800">--scheme=https</code></template></i18n-t>
        </p>
    </div>
</template>
