<script setup>
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePanelApi } from '@/composables/usePanelApi';

const props = defineProps({
    scopes: { type: Array, default: () => [] },
    maxDays: { type: Number, default: 3650 },
});
const emit = defineEmits(['created']);
const { t } = useI18n();
const { panelRoute, errorMessage } = usePanelApi(t);

const form = reactive({ name: '', scopes: [], expires_in_days: '90' });
const busy = ref(false);
const error = ref('');
const plain = ref('');
const copied = ref(false);

// websites:read, websites:write, … grouped by area for the checkboxes.
const areas = computed(() => {
    const grouped = {};
    props.scopes.forEach((scope) => {
        const [area] = scope.split(':');
        (grouped[area] ||= []).push(scope);
    });
    return grouped;
});
const areaLabels = computed(() => ({
    websites: t('apiTokens.areas.websites'),
    dns: t('apiTokens.areas.dns'),
    databases: t('apiTokens.areas.databases'),
    mail: t('apiTokens.areas.mail'),
}));

const create = async () => {
    busy.value = true;
    error.value = '';
    plain.value = '';
    copied.value = false;
    try {
        const { data } = await window.axios.post(panelRoute('settings.api-tokens.store'), {
            name: form.name.trim(),
            scopes: form.scopes,
            expires_in_days: form.expires_in_days === '' ? null : Number(form.expires_in_days),
        });
        plain.value = data.plain_token;
        emit('created', data.token);
        form.name = '';
        form.scopes = [];
    } catch (e) {
        error.value = errorMessage(e, t('apiTokens.createFailed'));
    } finally {
        busy.value = false;
    }
};

const copy = async () => {
    try {
        await navigator.clipboard.writeText(plain.value);
        copied.value = true;
    } catch {
        // The token stays on screen to copy by hand.
    }
};

const field = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
</script>

<template>
    <form class="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50" @submit.prevent="create">
        <h2 class="text-sm font-semibold">{{ t('apiTokens.create') }}</h2>
        <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>

        <div v-if="plain" class="space-y-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
            <p class="font-semibold">{{ t('apiTokens.shownOnce') }}</p>
            <div class="flex items-center gap-2">
                <code class="flex-1 break-all rounded-lg bg-white px-2 py-1 font-mono text-xs text-slate-800 dark:bg-slate-900 dark:text-slate-100">{{ plain }}</code>
                <button type="button" class="rounded-lg border border-emerald-300 px-3 py-1 text-xs font-semibold dark:border-emerald-800" @click="copy">{{ copied ? t('apiTokens.copied') : t('apiTokens.copy') }}</button>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block text-xs text-slate-500">{{ t('apiTokens.name') }}
                <input v-model="form.name" type="text" maxlength="100" required :placeholder="t('apiTokens.namePlaceholder')" :class="field" />
            </label>
            <label class="block text-xs text-slate-500">{{ t('apiTokens.expires') }}
                <select v-model="form.expires_in_days" :class="field">
                    <option value="30">{{ t('apiTokens.days', { days: 30 }) }}</option>
                    <option value="90">{{ t('apiTokens.days', { days: 90 }) }}</option>
                    <option value="365">{{ t('apiTokens.days', { days: 365 }) }}</option>
                    <option value="">{{ t('apiTokens.never') }}</option>
                </select>
            </label>
        </div>

        <fieldset>
            <legend class="text-xs text-slate-500">{{ t('apiTokens.scopes') }}</legend>
            <p v-if="!scopes.length" class="mt-1 text-sm text-slate-500">{{ t('apiTokens.noScopes') }}</p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <div v-for="(areaScopes, area) in areas" :key="area" class="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                    <p class="text-sm font-medium">{{ areaLabels[area] || area }}</p>
                    <label v-for="scope in areaScopes" :key="scope" class="mt-1 flex items-center gap-2 text-sm">
                        <input v-model="form.scopes" type="checkbox" :value="scope" class="rounded border-slate-300 dark:border-slate-700" />
                        <span>{{ scope.endsWith(':write') ? t('apiTokens.write') : t('apiTokens.read') }}</span>
                        <code class="text-xs text-slate-400">{{ scope }}</code>
                    </label>
                </div>
            </div>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ t('apiTokens.scopeHelp') }}</p>
        </fieldset>

        <div class="flex justify-end">
            <button type="submit" :disabled="busy || !form.name.trim() || !form.scopes.length" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">{{ t('apiTokens.createButton') }}</button>
        </div>
    </form>
</template>
