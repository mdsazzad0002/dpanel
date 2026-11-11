<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * DNSSEC for one zone (backlog M6): sign it, and show the DS records the
 * owner gives their registrar. Hidden where the server cannot sign zones.
 */
const props = defineProps({
    url: { type: String, required: true },
});

const { t } = useI18n();
const supported = ref(true);
const loading = ref(true);
const saving = ref(false);
const enabled = ref(false);
const ds = ref([]);
const error = ref('');
const copied = ref('');

const apply = (data) => {
    supported.value = data?.supported !== false;
    enabled.value = Boolean(data?.enabled);
    ds.value = Array.isArray(data?.ds) ? data.ds : [];
};

const failure = (e) => {
    if (e?.response?.status === 501) {
        supported.value = false;
        return;
    }
    error.value = e?.response?.data?.message || t('dnssec.failed');
};

const load = async () => {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(props.url, { headers: { Accept: 'application/json' } });
        apply(data);
    } catch (e) {
        failure(e);
    } finally {
        loading.value = false;
    }
};

const toggle = async () => {
    const next = !enabled.value;
    if (!confirm(next ? t('dnssec.confirmEnable') : t('dnssec.confirmDisable'))) return;
    saving.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.put(props.url, { enabled: next }, { headers: { Accept: 'application/json' } });
        apply(data);
    } catch (e) {
        failure(e);
    } finally {
        saving.value = false;
    }
};

const copy = async (value) => {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = value;
        setTimeout(() => { if (copied.value === value) copied.value = ''; }, 1500);
    } catch {
        // Clipboard needs a secure context; the value stays selectable.
    }
};

onMounted(load);
</script>

<template>
    <section v-if="supported" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('dnssec.title') }}</h3>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                    <template v-if="loading">{{ t('dnssec.loading') }}</template>
                    <template v-else-if="enabled">{{ t('dnssec.on') }}</template>
                    <template v-else>{{ t('dnssec.off') }}</template>
                </p>
            </div>
            <button
                type="button"
                :disabled="loading || saving"
                :class="enabled ? 'border border-red-300 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-950' : 'bg-blue-600 text-white hover:bg-blue-700'"
                class="rounded-md px-3 py-2 text-xs font-medium disabled:opacity-60"
                @click="toggle"
            >
                {{ saving ? t('dnssec.saving') : (enabled ? t('dnssec.disable') : t('dnssec.enable')) }}
            </button>
        </div>
        <p v-if="error" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
        <div v-if="enabled && ds.length" class="mt-4 space-y-2">
            <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('dnssec.registrar') }}</p>
            <div v-for="line in ds" :key="line" class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-950">
                <code class="min-w-0 break-all text-xs">{{ line }}</code>
                <button type="button" class="shrink-0 rounded border border-slate-300 px-2 py-0.5 text-xs dark:border-slate-700" @click="copy(line)">{{ copied === line ? t('dnssec.copied') : t('dnssec.copy') }}</button>
            </div>
        </div>
    </section>
</template>
