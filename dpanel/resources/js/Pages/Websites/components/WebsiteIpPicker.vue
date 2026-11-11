<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

/**
 * The IP address a website answers on (backlog U5). Only addresses of the
 * pool the owner may use are offered; saving rewrites the site's A/AAAA
 * records in the zone this server hosts. Hidden while the pool is empty.
 */
const props = defineProps({
    website: { type: Object, required: true },
});
const { t } = useI18n();
const { panelRoute, requestJson } = usePanelApi();

const state = ref(null);
const chosen = ref(null);
const saving = ref(false);
const error = ref('');
const result = ref(null);

const load = async () => {
    try {
        state.value = await requestJson(panelRoute('websites.ip.show', { id: props.website.id }), { method: 'GET' });
        chosen.value = state.value.current?.id ?? null;
    } catch (e) {
        error.value = e?.message || t('websiteIp.loadFailed');
    }
};

const save = async () => {
    saving.value = true;
    error.value = '';
    result.value = null;
    try {
        const data = await requestJson(panelRoute('websites.ip.update', { id: props.website.id }), { method: 'PUT', body: { ip_address_id: chosen.value } });
        result.value = data;
        state.value = { ...state.value, current: data.current };
    } catch (e) {
        error.value = e?.message || t('websiteIp.saveFailed');
    } finally {
        saving.value = false;
    }
};

onMounted(load);
</script>

<template>
    <section v-if="state && (state.choices.length || state.current)" class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <h2 class="text-sm font-semibold">{{ t('websiteIp.title') }}</h2>
        <p v-if="state.follows_parent" class="mt-1 text-sm text-slate-500">{{ t('websiteIp.followsParent', { address: state.current?.address || '-', parent: state.follows_parent }) }}</p>
        <template v-else>
            <p class="mt-1 text-sm text-slate-500">{{ t('websiteIp.help') }}</p>
            <form class="mt-3 flex flex-wrap items-center gap-2" @submit.prevent="save">
                <select v-model="chosen" class="rounded-md border-slate-300 text-sm font-mono dark:border-slate-700 dark:bg-slate-800">
                    <option v-for="choice in state.choices" :key="choice.id" :value="choice.id">
                        {{ choice.address }}{{ choice.is_main ? ' · ' + t('websiteIp.main') : '' }}{{ choice.mode === 'dedicated' ? ' · ' + t('websiteIp.dedicated') : '' }}{{ choice.label ? ' · ' + choice.label : '' }}
                    </option>
                </select>
                <button type="submit" :disabled="saving || chosen === state.current?.id" class="rounded-md bg-sky-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-sky-700 disabled:opacity-60">
                    {{ saving ? t('websiteIp.saving') : t('websiteIp.save') }}
                </button>
            </form>
        </template>
        <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>
        <div v-if="result" class="mt-2 text-sm text-emerald-700 dark:text-emerald-400">
            <p>{{ result.message }}</p>
            <ul v-if="result.dns.changed.length" class="mt-1 list-inside list-disc font-mono text-xs">
                <li v-for="line in result.dns.changed" :key="line">{{ line }}</li>
            </ul>
            <p v-else-if="result.dns.skipped" class="mt-1 text-amber-700 dark:text-amber-400">{{ result.dns.skipped }}</p>
        </div>
    </section>
</template>
